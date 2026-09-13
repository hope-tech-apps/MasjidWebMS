<?php

namespace App\Http\Requests\Admin\Registrations;

use App\Http\Requests\BaseFormRequest;
use App\Models\Offering;
use App\Support\TenantContext;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Validator;
use Symfony\Component\HttpFoundation\Response;

/**
 * Record a registration an administrator is entering BY HAND (T-041i): a family
 * paying at the desk, a phone call, a household with no email address.
 *
 * ------------------------------------------------------------------ WHAT IS IN
 *
 *   fee_plan_id        which plan they are on. The plan decides the price; this
 *                      request never carries one.
 *   payer_contact_id   an existing contact — XOR —
 *   payer{...}         a name (+ optional email/phone) to create one from
 *   registrants[]      who the registration is FOR, each either `contact_id`
 *                      (an existing person) or `name` (+ optional email/phone).
 *                      Empty means the payer is registering themselves.
 *   data{}             the offering's intake-form answers
 *   note               why this was entered by hand, for the office to read
 *
 * ------------------------------------------------------ WHAT IS DELIBERATELY OUT
 *
 * EVERY MONEY FIELD, and this is the boundary the whole feature turns on. There
 * is no `amount`, no `amount_minor`, no `currency`, no `payment_status`, no
 * `paid`, no `paid_via`, no `payment_method`, and none will be added:
 *
 *  - A PRICE IS THE SERVER'S. `RegistrationService::listTotalFor()` snapshots it
 *    from the immutable fee plan the moment the row is written. A client that
 *    could name a price could name a different one from the plan the roster then
 *    shows (.claude/rules/registration-billing-data.md).
 *  - PAID MEANS A VERIFIED WEBHOOK SAID SO. The two honest routes to a paid
 *    hand-entered registration are a FREE plan (confirmed synchronously by the
 *    declared free-path carve-out) and a paid plan waived to zero with Grant
 *    aid (which routes through `confirm()`). Anything else — cash in a drawer,
 *    a Zelle transfer, a card run on the org's own terminal — is money this
 *    application did not see move, and a column asserting otherwise would put
 *    the roster permanently out of step with the account that holds the funds.
 *    `meal_orders.paid_via` is NOT the precedent to copy here: a lunch order is
 *    a $12 cash box reconciled the same afternoon, a registration is a Stripe
 *    Checkout Session on a connected account.
 *
 * Also out: `masjid_id`, `source`, `entered_by_user_id`, `status`. The tenant
 * comes from the bound TenantContext, the seat state from the service's capacity
 * lock, and the provenance pair from the authenticated user through
 * `Registration::enteredByStaff()` — never from a body.
 *
 * ------------------------------------------------------------- WHY 422, NOT 404
 *
 * `fee_plan_id` and every `contact_id` are BODY fields, so a foreign or
 * soft-deleted id is a 422 naming the field that carried it, exactly as
 * `OfferingFormRequest::ownedRule` decided for `intake_form_id` and `group_id`.
 * The 404 belongs to the ROUTE ids (`{masjid_id}/{offering_id}`), which the
 * controller resolves through the tenant scope. Either way nothing cross-tenant
 * is ever written; the two differ only in which surface names the mistake.
 */
class StoreRegistrationRequest extends BaseFormRequest
{
    /**
     * How many people one hand-entered registration may name.
     *
     * Not a judgement about family size — a bound on how many contact rows and
     * guardian edges a single request can create. The public endpoint is
     * throttled at 8/hr; this one is behind a permission gate instead, so the
     * ceiling is the thing that stops a mis-scripted import writing a thousand
     * children under one payer in one call.
     */
    private const MAX_REGISTRANTS = 50;

    /**
     * A foreign or missing offering is a 404 BEFORE any rule runs.
     *
     * Without this the field rules fire first and answer 422 "that fee plan does
     * not belong to this offering" — which is a different statement, and a worse
     * one: it implies the offering in the URL resolved. Every other verb on this
     * route (`index`, `show`, `promote`, `cancel`) answers 404 for another
     * organisation's offering, and a create that answered something else would
     * be the one surface saying whether a program exists.
     *
     * The scoped `find()` is the check: `tenant` has bound TenantContext and
     * BelongsToMasjid filters the query, so another organisation's id is simply
     * a miss (.claude/rules/tenant-scoping.md).
     */
    protected function prepareForValidation(): void
    {
        if (Offering::find($this->route('offering_id')) === null) {
            abort(Response::HTTP_NOT_FOUND);
        }
    }

    public function rules(): array
    {
        return [
            // Ownership only. Whether the plan is still ON SALE is the service's
            // decision (`planInactive()`), because an inactive plan is a refusal
            // with a sentence an admin can act on, not a malformed field.
            'fee_plan_id' => ['required', 'integer', $this->planOfThisOffering()],

            // THE PAYER — one of the two keys, never both meaning different
            // people. An id is a decision the admin made by clicking a real
            // person; a name is a lookup. Same split as the offline donation's
            // `contact_id` / `donor_name`.
            'payer_contact_id' => ['nullable', 'integer', 'required_without:payer.name', $this->ownedContact()],
            'payer' => ['nullable', 'array'],
            'payer.name' => ['nullable', 'string', 'max:200', 'required_without:payer_contact_id'],
            'payer.email' => ['nullable', 'email', 'max:255'],
            'payer.phone' => ['nullable', 'string', 'max:50'],

            // WHO IT IS FOR. Empty means the payer is registering themselves —
            // `RegistrationService::normalizeRegistrants()` already means exactly
            // that, so an empty array is a real answer and not a missing one.
            'registrants' => ['nullable', 'array', 'max:' . self::MAX_REGISTRANTS],
            'registrants.*' => ['array'],
            'registrants.*.contact_id' => ['nullable', 'integer', $this->ownedContact()],
            'registrants.*.name' => ['nullable', 'string', 'max:200'],
            'registrants.*.email' => ['nullable', 'email', 'max:255'],
            'registrants.*.phone' => ['nullable', 'string', 'max:50'],

            // The intake answers. Shape-checked only here: the RULES come from
            // the offering's STORED schema inside RegistrationService, via
            // App\Support\FormSchema, which is the enforcement on both doors.
            // Restating any of them here would be a second, drifting copy.
            'data' => ['nullable', 'array'],

            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * The one rule the `rules()` array cannot express: each person named on this
     * registration — the PAYER and every registrant row — must name EXACTLY ONE
     * of `contact_id` / `name`.
     *
     * A row with neither is an empty row the admin left behind, and would
     * otherwise reach `createContact()` and write a contact called "Registrant".
     * A row with BOTH is the dangerous one: it reads as "this existing person,
     * but called that", and there is no honest way to resolve it — attaching the
     * id silently ignores a name the admin typed on purpose, and using the name
     * silently ignores the person they picked. Refuse and let them choose.
     *
     * THE PAYER IS CHECKED HERE TOO, and it used to be the hole in that rule.
     * `rules()` can only make the pair `required_without` each other, which
     * refuses NEITHER and permits BOTH — so a body carrying `payer_contact_id`
     * AND `payer.name` validated, and `RegistrationsController::resolvePayer()`
     * took the id branch and dropped the typed name on the floor with a 201 and
     * nothing on any screen saying a different person had been named. That is
     * worse for the payer than for a registrant row, not better: the payer is
     * the contact the seat is filed under AND the one end of every guardian edge
     * `writeRosterMemberships()` writes over the children on it, so resolving
     * the ambiguity silently attaches another family's child to whichever
     * person the id happened to point at. A scripted import, or a modal
     * regression that stops clearing the typed name after a pick, is all it
     * takes. Same XOR, same sentence, one door.
     *
     * Only the BOTH half is added for the payer: `required_without` already
     * answers the NEITHER half with the sentence in `messages()`, and asking it
     * twice would show the admin two errors for one empty field.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (filled($this->input('payer_contact_id')) && filled($this->input('payer.name'))) {
                $validator->errors()->add(
                    'payer.name',
                    'Choose an existing person or type a new name for this row, not both.'
                );
            }

            foreach ((array) $this->input('registrants', []) as $index => $person) {
                if (! is_array($person)) {
                    continue;
                }

                $hasId = filled($person['contact_id'] ?? null);
                $hasName = filled($person['name'] ?? null);

                if ($hasId === $hasName) {
                    $validator->errors()->add(
                        "registrants.{$index}.name",
                        $hasId
                            ? 'Choose an existing person or type a new name for this row, not both.'
                            : 'Name this person, or pick an existing one.'
                    );
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'fee_plan_id.exists' => 'That fee plan does not belong to this offering.',
            'payer_contact_id.exists' => 'That person does not belong to this organization.',
            'payer_contact_id.required_without' => 'Say who is paying: pick an existing person or type a name.',
            'payer.name.required_without' => 'Say who is paying: pick an existing person or type a name.',
            'registrants.*.contact_id.exists' => 'That person does not belong to this organization.',
            'registrants.max' => 'Enter at most ' . self::MAX_REGISTRANTS . ' people on one registration.',
        ];
    }

    /**
     * The tenant this request acts on — server-derived from TenantContext, which
     * `ResolveMasjidTenant` has already bound for this route. The route parameter
     * is only a fallback for the unbound case and cannot widen access: reaching
     * another masjid's route is already a 403 (.claude/rules/tenant-scoping.md).
     *
     * Spelled out here rather than inherited from `OfferingFormRequest`: that
     * class is the shared base for the OFFERING write requests and carries slug
     * uniqueness and an offering-shaped `messages()` map, none of which belongs
     * on a registration. Two short methods beat a base class that means two
     * different things.
     */
    private function tenantId(): int
    {
        return (int) (app(TenantContext::class)->get() ?? $this->route('masjid_id'));
    }

    /**
     * A fee plan of THIS organisation and THIS offering.
     *
     * Both clauses matter and the second is the easy one to lose: a plan of
     * another offering in the same organisation is the right tenant and the
     * wrong price. The service refuses it again behind this boundary
     * (`planMismatch()`); this is the surface that names the field.
     */
    private function planOfThisOffering(): Exists
    {
        return Rule::exists('fee_plans', 'id')
            ->where('masjid_id', $this->tenantId())
            ->where('offering_id', (int) $this->route('offering_id'));
    }

    /**
     * A contact of THIS organisation, and not one the office has deleted.
     *
     * Attaching an existing contact as a REGISTRANT is what the public endpoint
     * refuses outright, because a guardian edge over somebody who already exists
     * is an authorization grant. It is allowed here only because this route sits
     * behind `permission:manage contacts` inside the `crm` gate — so the id must
     * genuinely be one of this organisation's live people, checked against the
     * column rather than trusted from the payload.
     */
    private function ownedContact(): Exists
    {
        return Rule::exists('contacts', 'id')
            ->where('masjid_id', $this->tenantId())
            ->whereNull('deleted_at');
    }
}
