<?php

namespace App\Services\Lunch;

use App\Models\Contact;
use App\Models\MealMenu;
use App\Services\Sms\PhoneNumber;
use App\Services\Sms\SmsConsentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Turning a lunch customer's ticked box into a consent record that would survive
 * being read out in a complaint.
 *
 * ## The exact words are the evidence
 *
 * `sms_consent_evidence` stores the SENTENCE THE CUSTOMER AGREED TO, verbatim,
 * not a description of it. "Opted in on the lunch form" proves nothing a year
 * later when the disclosure wording has been edited twice; the sentence itself
 * is the record. SmsConsentService stamps the time server-side for the same
 * reason — a date the client can set is a date the client can backdate.
 *
 * ## Opting in must never cost someone their lunch
 *
 * Every failure here is swallowed and logged. The customer came to buy food; a
 * suppressed number, an unparseable phone, a unique-index race — none of those
 * are reasons to lose their order. `attempt()` therefore returns a bool for
 * tests and callers to assert on, and throws nothing.
 *
 * ## A suppressed number stays suppressed
 *
 * SmsConsentService::grant() refuses a number on the durable suppression list,
 * and this class does not catch-and-retry around that. Someone who texted STOP
 * has withdrawn; a checkbox on a web form is not them taking it back. Only
 * texting START to the organisation's own number is.
 */
class LunchSmsOptIn
{
    /**
     * The disclosure a customer agrees to, and the string stored as evidence.
     *
     * It is a CONSTANT, and the server reads it rather than the request body,
     * because consent evidence a client can supply is evidence of nothing. It
     * carries what US SMS marketing consent has to say on its face: who is
     * texting, what about, how often, that agreeing is not a condition of
     * buying anything, that rates apply, and how to stop.
     *
     * Changing this wording is changing what future subscribers agreed to.
     * Existing rows keep the sentence THEY were shown, which is the entire
     * reason it is stored per-contact rather than looked up at read time.
     */
    public const DISCLOSURE = 'I agree to receive recurring automated text messages '
        . 'about Jummah lunch ordering from this masjid at the number I provided. '
        . 'Consent is not a condition of purchase. Message frequency varies — '
        . 'about one text per week. Message and data rates may apply. '
        . 'Reply STOP to cancel or HELP for help.';

    public function __construct(private SmsConsentService $consent)
    {
    }

    /**
     * Record consent + the lunch interest for one order. Never throws.
     *
     * @param  string  $disclosure  the exact text shown beside the checkbox
     */
    public function attempt(MealMenu $menu, int $masjidId, ?string $rawPhone, ?string $name, string $disclosure): bool
    {
        if (! $menu->allow_sms_optin || $menu->notify_service_id === null) {
            return false;
        }

        $e164 = PhoneNumber::e164($rawPhone);

        if ($e164 === null) {
            return false;
        }

        try {
            return DB::transaction(function () use ($menu, $masjidId, $e164, $name, $disclosure) {
                $contact = $this->contactFor($masjidId, $e164, $name);

                // ONLY when there is no consent record yet. A returning customer
                // ticking the same box next week is not making a new claim, and
                // re-recording it would replace the date, the source and — the
                // part this class cares about most — the exact sentence they
                // agreed to with whatever DISCLOSURE says today. The docblock
                // above promises "existing rows keep the sentence THEY were
                // shown"; until this guard, every repeat order broke that
                // promise. `SmsConsentService::grant()` now refuses a second
                // grant outright, so this is also what keeps a repeat order from
                // failing and taking the service-interest row below with it.
                //
                // A SUPPRESSED number still reaches grant() and still throws,
                // which is the intended path: hasSmsConsent() is false for a
                // contact who opted out, and a ticked checkbox is not them
                // taking a STOP back.
                if (! $contact->hasSmsConsent()) {
                    $this->consent->grant($contact, 'web_form', $disclosure);
                }

                // The subscription itself. Idempotent: ordering three weeks
                // running must not make three rows, and the pair is what
                // BroadcastAudience::SERVICE resolves at send time.
                DB::table('contact_service_interests')->updateOrInsert(
                    [
                        'masjid_id' => $masjidId,
                        'contact_id' => $contact->id,
                        'service_id' => $menu->notify_service_id,
                    ],
                    ['updated_at' => now(), 'created_at' => now()],
                );

                return true;
            });
        } catch (\Throwable $e) {
            // A suppressed number lands here, and so does anything else. The
            // order is already saved and stays saved.
            //
            // WARNING, not info: production runs LOG_LEVEL=warning, and an
            // info-level line about a swallowed failure is a failure nobody can
            // see. This exact combination — catch everything, log below the
            // deployed level — hid a column-length rejection that silently
            // dropped every single opt-in.
            Log::warning('Lunch SMS opt-in not recorded.', [
                'masjid_id' => $masjidId,
                'reason' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * The contact this number already belongs to, or a new one for it.
     *
     * Matched on the NUMBER, because that is what consent attaches to — the same
     * rule SmsConsentService's merge logic turns on. A lunch customer who is
     * already in the directory is not duplicated; a new one is created carrying
     * `signup_source` so an admin can always tell where 200 new rows came from.
     *
     * The name is only ever used to FILL a blank. A returning customer who typed
     * "A. Rahman" this week must not overwrite the record staff curated.
     */
    private function contactFor(int $masjidId, string $e164, ?string $name): Contact
    {
        $fragment = PhoneNumber::matchFragment($e164);

        $contact = Contact::withoutMasjidScope()
            ->where('masjid_id', $masjidId)
            ->whereNotNull('phone')
            ->where('phone', 'like', '%' . $fragment)
            ->first();

        if ($contact) {
            return $contact;
        }

        [$first, $last] = $this->splitName($name);

        $contact = new Contact([
            'first_name' => $first,
            'last_name' => $last,
            'phone' => $e164,
        ]);
        $contact->masjid_id = $masjidId;
        $contact->signup_source = 'jummah_lunch';
        $contact->save();

        return $contact;
    }

    /** @return array{0:string,1:string} */
    private function splitName(?string $name): array
    {
        $name = trim((string) $name);

        if ($name === '') {
            // first_name is not nullable and a blank directory row helps nobody
            // find anyone; the number is the identity that matters here.
            return ['Lunch', 'customer'];
        }

        $parts = preg_split('/\s+/', $name, 2);

        return [$parts[0], $parts[1] ?? ''];
    }
}
