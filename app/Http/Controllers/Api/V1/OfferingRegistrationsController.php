<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Registrations\QuoteRegistrationRequest;
use App\Http\Requests\Api\V1\Registrations\RegisterForOfferingRequest;
use App\Models\Contact;
use App\Models\FeePlan;
use App\Models\Offering;
use App\Models\Registration;
use App\Services\Registrations\RegistrationException;
use App\Services\Registrations\RegistrationService;
use App\Services\Stripe\RegistrationCheckoutService;
use App\Support\Errors;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The PUBLIC registration endpoints — quote, register, checkout (T-006c,
 * docs/t006-registration-billing-design.md).
 *
 * These are unauthenticated writes, so they follow the public form-submission
 * idiom (FormSubmissionsController) defensively:
 *
 *  - The organization comes from the `masjid-id` header AND the offering must
 *    belong to it. Nothing here leans on the BelongsToMasjid global scope:
 *    /api/v1 never runs the tenant middleware, so an UNBOUND scope adds no
 *    filter and a caller could otherwise reach any tenant's rows. Every lookup
 *    filters masjid_id explicitly, and the uuid lookup goes through
 *    Registration::findByUuidForMasjid, which is masjid-filtered by
 *    construction (.claude/rules/registration-billing-data.md).
 *  - The same 404 whether an offering does not exist, belongs to another
 *    masjid, or is inactive — no probing for which offerings live where.
 *  - Intake answers are validated by the offering's STORED schema inside
 *    RegistrationService, never from the payload's shape, and undeclared keys
 *    are dropped before storage.
 *  - A honeypot catches naive bots; the named throttles catch the rest.
 *  - Validation failures return the legacy {status:'failed'} 422 field bag via
 *    the BaseFormRequest subclasses (and, for schema failures, the same shape
 *    the form endpoint returns).
 *
 * MONEY: pricing is decided SERVER-SIDE, always — the quote prices from the
 * immutable fee plan, and the charged amount is the registration's own
 * snapshot. A client can name a plan and a code; it can never name a price.
 * `checkout` hands back a Stripe-HOSTED URL and nothing more: the registration
 * stays pending until a signature-verified webhook says otherwise
 * (.claude/rules/stripe-payments.md).
 */
class OfferingRegistrationsController extends Controller
{
    public function __construct(
        private RegistrationService $registrations,
        private RegistrationCheckoutService $checkout,
    ) {
    }

    /**
     * POST /api/v1/offerings/{slug}/quote
     *
     * A server-priced preview. WRITES NOTHING — no registration, no form
     * response, no seat.
     */
    public function quote(QuoteRegistrationRequest $request, string $slug)
    {
        try {
            $masjidId = (int) $request->header('masjid-id');

            if ($masjidId <= 0) {
                return response()->api(400, 'A masjid must be specified.', null);
            }

            $offering = $this->findOffering($masjidId, $slug);

            if (! $offering) {
                return response()->api(404, 'This offering is not available.', null);
            }

            $feePlan = $this->findFeePlan($masjidId, $offering, (int) $request->input('fee_plan_id'));

            if (! $feePlan) {
                return response()->api(404, 'This fee plan is not available.', null);
            }

            $listTotal = $this->registrations->listTotalFor($feePlan);
            $adjustedTotal = $listTotal;

            // Quoting an EXISTING registration reports its snapshot, which is
            // where admin-granted aid actually lives. The lookup is
            // masjid-filtered, so another tenant's uuid simply does not resolve
            // and the quote falls back to list price.
            $uuid = $request->input('registration_uuid');

            if (is_string($uuid) && $uuid !== '') {
                $registration = Registration::findByUuidForMasjid($uuid, $masjidId);

                if ($registration && (int) $registration->offering_id === (int) $offering->id) {
                    $listTotal = (int) $registration->list_total_minor;
                    $adjustedTotal = (int) $registration->adjusted_total_minor;
                }
            }

            return response()->api(200, 'Quote generated.', [
                'offering' => [
                    'slug' => $offering->slug,
                    'name' => $offering->name,
                    'kind' => $offering->kind(),
                ],
                'fee_plan_id' => $feePlan->id,
                'fee_plan_kind' => $feePlan->kind,
                'currency' => $feePlan->currency,
                // Integer minor units throughout — never a float.
                'list_total_minor' => $listTotal,
                'adjusted_total_minor' => $adjustedTotal,
                'amount_due_minor' => $adjustedTotal,
                // 0 means the free-path carve-out: confirmed in-request, no
                // Stripe leg, never a $0 Checkout Session.
                'requires_payment' => $adjustedTotal > 0,
                // Codes are validated server-side; nothing a client sends can
                // reduce a price by itself. Aid is granted by an admin.
                'code_applied' => false,
            ]);
        } catch (RegistrationException $e) {
            return response()->api(422, $e->getMessage(), null);
        } catch (\Throwable $e) {
            return response()->api(500, Errors::publicMessage($e), null);
        }
    }

    /**
     * POST /api/v1/offerings/{slug}/register
     *
     * One transaction inside RegistrationService: schema validation → form
     * response → capacity lock → registration with snapshot totals → seat
     * reserved (or waitlisted), free totals confirmed synchronously. A PAID
     * registration then gets its hosted Checkout URL.
     */
    public function register(RegisterForOfferingRequest $request, string $slug)
    {
        try {
            $masjidId = (int) $request->header('masjid-id');

            if ($masjidId <= 0) {
                return response()->api(400, 'A masjid must be specified.', null);
            }

            $offering = $this->findOffering($masjidId, $slug);

            if (! $offering) {
                return response()->api(404, 'This offering is not available.', null);
            }

            // A bot filling every input trips this; a human never sees the
            // field. Report success so a scripted submitter gets no signal to
            // adapt to, while nothing is written.
            if (filled($request->input('website'))) {
                return response()->api(200, 'Thank you — your registration has been received.', [
                    'registration_uuid' => null,
                ]);
            }

            $feePlan = $this->findFeePlan($masjidId, $offering, (int) $request->input('fee_plan_id'));

            if (! $feePlan) {
                return response()->api(404, 'This fee plan is not available.', null);
            }

            // Contacts-first (.claude/rules/groups.md): people are resolved to
            // Contacts BEFORE the service runs — RegistrationService creates no
            // Contact rows of its own.
            $payer = $this->resolveContact($masjidId, [
                'name' => $request->input('payer.name'),
                'email' => $request->input('payer.email'),
                'phone' => $request->input('payer.phone'),
            ], 'Registrant');

            $registrants = [];

            foreach ((array) $request->input('registrants', []) as $person) {
                if (! is_array($person)) {
                    continue;
                }

                $registrants[] = $this->resolveContact($masjidId, $person, 'Registrant');
            }

            $registration = $this->registrations->register(
                $offering,
                $feePlan,
                $payer,
                (array) $request->input('data', []),
                $registrants
            );

            $checkoutUrl = null;

            // A paid, seat-holding registration gets its hosted session now.
            // A failure here is NOT fatal: the seat is committed and bounded by
            // checkout_expires_at, and the client can re-mint through the
            // checkout endpoint — losing Stripe for a moment must not lose the
            // registration.
            if ($registration->isPending() && (int) $registration->adjusted_total_minor > 0) {
                try {
                    $checkoutUrl = $this->checkout->checkout($registration)['checkout_url'];
                } catch (\Throwable $e) {
                    $checkoutUrl = null;
                }
            }

            return response()->api(200, $this->outcomeMessage($registration), [
                'registration_uuid' => $registration->uuid,
                'status' => $registration->status,
                'payment_status' => $registration->payment_status,
                'currency' => $feePlan->currency,
                'amount_due_minor' => (int) $registration->adjusted_total_minor,
                'checkout_url' => $checkoutUrl,
            ]);
        } catch (ValidationException $e) {
            // The intake answers failed the offering's own schema. Field bag
            // under `data`, matching how the SPA and the public site already
            // read 422s from BaseFormRequest.
            return response()->json([
                'status' => 'failed',
                'data' => $e->errors(),
            ], 422);
        } catch (RegistrationException $e) {
            return response()->api(422, $e->getMessage(), null);
        } catch (\Throwable $e) {
            return response()->api(500, Errors::publicMessage($e), null);
        }
    }

    /**
     * POST /api/v1/registrations/{uuid}/checkout
     *
     * Re-mint the hosted Checkout Session for a registration whose first one
     * was abandoned (or never reached the client). Idempotency-keyed: the first
     * mint reuses the key minted at intake so a double-submit cannot open two
     * sessions; a genuine re-mint rotates it.
     *
     * Returns a URL and NOTHING else — no state is advanced here, ever.
     */
    public function checkout(Request $request, string $uuid)
    {
        try {
            $masjidId = (int) $request->header('masjid-id');

            if ($masjidId <= 0) {
                return response()->api(400, 'A masjid must be specified.', null);
            }

            // Masjid-filtered by construction: another tenant's uuid is a miss,
            // never a hit, so a registration can never be checked out against
            // another organization's connected account.
            $registration = Registration::findByUuidForMasjid($uuid, $masjidId);

            if (! $registration) {
                return response()->api(404, 'This registration is not available.', null);
            }

            $result = $this->checkout->checkout($registration);

            return response()->api(200, 'Checkout session created.', [
                'registration_uuid' => $registration->uuid,
                'status' => $registration->status,
                'payment_status' => $registration->payment_status,
                'amount_due_minor' => (int) $registration->adjusted_total_minor,
                'checkout_url' => $result['checkout_url'],
            ]);
        } catch (RegistrationException $e) {
            return response()->api(422, $e->getMessage(), null);
        } catch (\Throwable $e) {
            return response()->api(500, Errors::publicMessage($e), null);
        }
    }

    // ----------------------------------------------------------- resolution

    /**
     * The offering for this tenant, or null. Explicit masjid filter (the scope
     * is unbound here) and active-only, so a closed or foreign offering is the
     * same miss.
     */
    private function findOffering(int $masjidId, string $slug): ?Offering
    {
        return Offering::query()
            ->where('masjid_id', $masjidId)
            ->where('slug', $slug)
            ->where('is_active', true)
            ->first();
    }

    /** An ACTIVE plan of THIS offering and tenant, or null. */
    private function findFeePlan(int $masjidId, Offering $offering, int $feePlanId): ?FeePlan
    {
        if ($feePlanId <= 0) {
            return null;
        }

        return FeePlan::query()
            ->where('masjid_id', $masjidId)
            ->where('offering_id', $offering->id)
            ->whereKey($feePlanId)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Find-or-create the Contact for one person on this registration.
     *
     * Keyed on (masjid, email) when an email is given, so a family registering
     * again attaches to the people already in the CRM instead of duplicating
     * them. A registrant WITHOUT an email (a child on a guardian's form) gets a
     * fresh Contact — there is no safe key to dedupe a bare name on, and
     * merging is an admin action.
     *
     * Deliberately local rather than reaching for the donor-CRM resolver: this
     * is not a donation, and its defaults ("Registrant", not "Donor") belong to
     * this path. masjid_id is set/filtered explicitly — /api/v1 runs unbound.
     *
     * @param  array{name?:?string,email?:?string,phone?:?string}  $person
     */
    private function resolveContact(int $masjidId, array $person, string $fallbackFirst): Contact
    {
        $email = isset($person['email']) ? trim((string) $person['email']) : '';
        [$first, $last] = $this->splitName((string) ($person['name'] ?? ''), $fallbackFirst);
        $phone = isset($person['phone']) ? trim((string) $person['phone']) : '';

        if ($email !== '') {
            $existing = Contact::query()
                ->where('masjid_id', $masjidId)
                ->where('email', $email)
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        $contact = new Contact([
            'first_name' => $first,
            'last_name' => $last,
            'email' => $email !== '' ? $email : null,
            'phone' => $phone !== '' ? $phone : null,
        ]);

        // Explicit: unbound, so the creating hook stamps nothing.
        $contact->masjid_id = $masjidId;
        $contact->save();

        return $contact;
    }

    /**
     * Split a full name into [first, last]; last_name is NOT NULL on contacts,
     * so it degrades to an empty string rather than failing a registration over
     * a mononym.
     *
     * @return array{0:string,1:string}
     */
    private function splitName(string $name, string $fallbackFirst): array
    {
        $name = trim($name);

        if ($name === '') {
            return [$fallbackFirst, ''];
        }

        $parts = preg_split('/\s+/', $name) ?: [$name];
        $first = (string) array_shift($parts);

        return [mb_substr($first, 0, 255), mb_substr(implode(' ', $parts), 0, 255)];
    }

    /** What actually happened, in the registrant's terms. */
    private function outcomeMessage(Registration $registration): string
    {
        if ($registration->status === Registration::STATUS_WAITLISTED) {
            return 'This offering is full — you have been added to the waitlist.';
        }

        if ($registration->isConfirmed()) {
            return 'Thank you — your registration is confirmed.';
        }

        return 'Your place is held while you complete payment.';
    }
}
