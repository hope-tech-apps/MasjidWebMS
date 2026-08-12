<?php

namespace App\Services\Sms;

use App\Models\Contact;
use App\Models\SmsSuppression;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Every write to SMS consent and suppression goes through here (T-009).
 *
 * Consent is the single fact this feature is legally built on, so it has exactly
 * one door. Four rules are enforced in this class and nowhere else, which is
 * what makes them checkable:
 *
 *  1. **The timestamp is server time.** `sms_consent_at` is never taken from a
 *     request. A consent date a client can set is a consent date a client can
 *     backdate, and the date is half of what makes the record evidence.
 *
 *  2. **A suppressed number cannot be re-consented by staff.** Only the
 *     subscriber can undo their own STOP, by texting START back to the same
 *     number. An admin who can tick a box to re-enable someone who opted out has
 *     been handed the exact button that turns a TCPA obligation into a TCPA
 *     claim — so `grant()` refuses, with a message that says who CAN undo it.
 *
 *  3. **An opt-out is written to the durable list, not just to the contact.**
 *     Withdrawal — by keyword or recorded by an admin — always writes
 *     `sms_suppressions`, which has no foreign key to `contacts` and therefore
 *     survives the merge that force-deletes the row, the re-import that
 *     recreates it, and the admin who deletes and re-adds a person next month.
 *
 *  4. **Consent belongs to a NUMBER, not to a name.** That is what makes the
 *     merge rule below the only defensible one.
 *
 * ## Merge: the surviving record takes the MORE RESTRICTIVE state
 *
 * `ContactsController::merge` `forceDelete()`s the absorbed contact, so anything
 * held only on that row is gone. Consent must therefore be reconciled onto the
 * survivor before the delete — but "transplant the source's consent" is wrong,
 * and the reason is the fourth rule above: consent was given for the source's
 * PHONE NUMBER. Moving it onto a survivor who carries a different number would
 * manufacture permission to text a number nobody ever agreed to be texted at,
 * which is the single most damaging thing this file could do.
 *
 * So:
 *
 *  - **Different numbers (or either missing):** nothing transplants. The
 *    survivor keeps its own consent state exactly as it was. The source's
 *    opt-out does not need to move either — it already lives in the suppression
 *    list, keyed to a number the survivor does not have.
 *  - **Same number:** the survivor takes the more restrictive of the two. An
 *    opt-out on either side wins and clears the opt-in, keeping the EARLIER
 *    opt-out date. Only if neither side opted out, and the survivor has no
 *    consent of its own, does the source's consent record move across — with its
 *    ORIGINAL timestamp, source and evidence, because a merge is not a new act
 *    of consent and re-stamping it "now" would fabricate provenance.
 *  - **Always:** the survivor is re-checked against the suppression list, so a
 *    merge can never produce a messageable record for a suppressed number.
 *
 * ## Tenant scoping
 *
 * Several of these methods run from the inbound webhook, which is UNBOUND by
 * design (routes/api.php, exactly like the Stripe webhook). They therefore take
 * an explicit `$masjidId` and query with `withoutMasjidScope()` plus that
 * predicate — the same thing StripeWebhookController does for donations. Under a
 * BOUND request the predicate is identical to what the scope would have applied,
 * so there is one code path rather than two.
 */
class SmsConsentService
{
    /**
     * Record consent for one contact.
     *
     * @throws RuntimeException when the number is unusable or suppressed.
     */
    public function grant(Contact $contact, string $source, ?string $evidence = null): Contact
    {
        if (! in_array($source, Contact::SMS_CONSENT_SOURCES, true)) {
            throw new RuntimeException('Unknown consent source.');
        }

        $number = $contact->smsNumber();

        if ($number === null) {
            throw new RuntimeException(
                'This contact has no usable phone number, so consent to text them cannot be recorded. '
                . 'Save a full number including area code first.'
            );
        }

        if ($this->isSuppressed((int) $contact->masjid_id, $number)) {
            throw new RuntimeException(
                'This number has opted out of text messages and cannot be opted back in by staff. '
                . 'Only the subscriber can undo it, by texting START to the number they received messages from.'
            );
        }

        $contact->forceFill([
            'sms_opt_in' => true,
            // Server time, always. See rule 1.
            'sms_consent_at' => Carbon::now(),
            'sms_consent_source' => $source,
            'sms_consent_evidence' => $evidence,
            'sms_opted_out_at' => null,
        ])->save();

        return $contact;
    }

    /**
     * Withdraw consent for one contact, and suppress the number durably.
     *
     * Used by the admin endpoint for a withdrawal made in person or by phone.
     * The suppression row is the point: clearing the columns alone would let a
     * re-import undo the withdrawal.
     */
    public function withdraw(Contact $contact, string $reason = SmsSuppression::REASON_MANUAL): Contact
    {
        $now = Carbon::now();

        $contact->forceFill([
            'sms_opt_in' => false,
            'sms_opted_out_at' => $now,
        ])->save();

        if ($number = $contact->smsNumber()) {
            $this->suppress((int) $contact->masjid_id, $number, reason: $reason);
        }

        return $contact;
    }

    /**
     * Suppress a number for a tenant — the durable opt-out.
     *
     * Idempotent by design: the provider retries inbound webhooks, and a second
     * STOP must update the one row rather than fight the unique index. A
     * previously RELEASED row is re-suppressed rather than duplicated, so the
     * history stays on a single row.
     */
    public function suppress(
        int $masjidId,
        string $e164,
        string $reason = SmsSuppression::REASON_STOP_KEYWORD,
        ?string $keyword = null,
        ?string $providerMessageId = null,
    ): SmsSuppression {
        $suppression = SmsSuppression::withoutMasjidScope()
            ->where('masjid_id', $masjidId)
            ->where('phone_e164', $e164)
            ->first();

        $attributes = [
            'reason' => $reason,
            'keyword' => $keyword,
            'provider_message_id' => $providerMessageId,
            'suppressed_at' => Carbon::now(),
            // Re-suppressing clears any earlier release; the row keeps carrying
            // the whole story rather than being deleted and rewritten.
            'released_at' => null,
            'released_keyword' => null,
        ];

        if ($suppression) {
            $suppression->forceFill($attributes)->save();
        } else {
            $suppression = SmsSuppression::create(array_merge($attributes, [
                'masjid_id' => $masjidId,
                'phone_e164' => $e164,
            ]));
        }

        // Mirror onto every contact of this tenant carrying that number, so the
        // directory shows the state without a join. The suppression row above is
        // the authority; this is the copy.
        $this->contactsWithNumber($masjidId, $e164)->each(function (Contact $contact) {
            $contact->forceFill([
                'sms_opt_in' => false,
                'sms_opted_out_at' => Carbon::now(),
            ])->save();
        });

        return $suppression;
    }

    /**
     * Release a suppression because the SUBSCRIBER asked to resume.
     *
     * Only ever called from the inbound keyword path. The row is kept and
     * stamped rather than deleted — the record that an opt-out existed and was
     * withdrawn by the person themselves is the evidence, and deleting it would
     * also let a later re-STOP write a second contradictory row.
     *
     * Consent is re-recorded with the `sms_reply_start` source, because texting
     * START to an organisation's own registered number from your own handset IS
     * express written consent, given in the subscriber's hand and traceable to a
     * message in the provider's console. Contacts that never had consent are
     * granted it here for the same reason; nothing else in this application can
     * write that source.
     */
    public function release(int $masjidId, string $e164, ?string $keyword = null): ?SmsSuppression
    {
        $now = Carbon::now();

        $suppression = SmsSuppression::withoutMasjidScope()
            ->where('masjid_id', $masjidId)
            ->where('phone_e164', $e164)
            ->first();

        $suppression?->forceFill([
            'released_at' => $now,
            'released_keyword' => $keyword,
        ])->save();

        $this->contactsWithNumber($masjidId, $e164)->each(function (Contact $contact) use ($now) {
            $contact->forceFill([
                'sms_opt_in' => true,
                'sms_consent_at' => $now,
                'sms_consent_source' => 'sms_reply_start',
                'sms_consent_evidence' => 'Inbound START keyword from the subscriber\'s own handset.',
                'sms_opted_out_at' => null,
            ])->save();
        });

        return $suppression;
    }

    /** Is this number suppressed for this tenant right now? */
    public function isSuppressed(int $masjidId, string $e164): bool
    {
        return SmsSuppression::withoutMasjidScope()
            ->where('masjid_id', $masjidId)
            ->where('phone_e164', $e164)
            ->whereNull('released_at')
            ->exists();
    }

    /**
     * Which of these numbers are suppressed for this tenant.
     *
     * One query for a whole audience rather than one per recipient — the
     * audience resolver calls this with every candidate number at once.
     *
     * @param  array<int, string>  $numbers  E.164.
     * @return array<int, string>  the suppressed subset, E.164.
     */
    public function suppressedAmong(int $masjidId, array $numbers): array
    {
        if ($numbers === []) {
            return [];
        }

        return SmsSuppression::withoutMasjidScope()
            ->where('masjid_id', $masjidId)
            ->whereIn('phone_e164', array_values(array_unique($numbers)))
            ->whereNull('released_at')
            ->pluck('phone_e164')
            ->all();
    }

    /**
     * Reconcile SMS consent when one contact is merged into another, BEFORE the
     * source row is force-deleted. See the long note on this class for why the
     * rule is "more restrictive, and only when the numbers match".
     */
    public function reconcileOnMerge(Contact $source, Contact $target): void
    {
        $sourceNumber = $source->smsNumber();
        $targetNumber = $target->smsNumber();
        $sameNumber = $sourceNumber !== null && $sourceNumber === $targetNumber;

        if ($sameNumber) {
            $sourceOptOut = $source->sms_opted_out_at;
            $targetOptOut = $target->sms_opted_out_at;

            if ($sourceOptOut !== null || $targetOptOut !== null) {
                // The restrictive side wins, and keeps the EARLIER date: the
                // moment the person first said stop is the moment that matters.
                $earliest = collect([$sourceOptOut, $targetOptOut])
                    ->filter()
                    ->sort()
                    ->first();

                $target->forceFill([
                    'sms_opt_in' => false,
                    'sms_opted_out_at' => $earliest,
                ])->save();
            } elseif (! $target->hasSmsConsent() && $source->hasSmsConsent()) {
                // Original provenance moves across untouched. A merge is not a
                // new act of consent, so re-stamping it "now" would invent one.
                $target->forceFill([
                    'sms_opt_in' => true,
                    'sms_consent_at' => $source->sms_consent_at,
                    'sms_consent_source' => $source->sms_consent_source,
                    'sms_consent_evidence' => $source->sms_consent_evidence,
                    'sms_opted_out_at' => null,
                ])->save();
            }
        }

        // Whatever happened above, the survivor is re-checked against the
        // durable list: a merge must never be able to produce a messageable
        // record for a number that said STOP.
        if ($targetNumber !== null && $this->isSuppressed((int) $target->masjid_id, $targetNumber)) {
            $target->forceFill([
                'sms_opt_in' => false,
                'sms_opted_out_at' => $target->sms_opted_out_at ?? Carbon::now(),
            ])->save();
        }
    }

    /**
     * Contacts of this tenant whose number normalises to `$e164`.
     *
     * The stored `phone` is whatever a human typed, so the comparison cannot be
     * done in SQL. The last seven digits narrow the scan on both drivers
     * (.claude/rules/migrations.md — no dialect-specific SQL), and the exact
     * E.164 comparison then happens in PHP, where the same normaliser that wrote
     * the suppression key is the one doing the matching.
     *
     * @return Collection<int, Contact>
     */
    private function contactsWithNumber(int $masjidId, string $e164): Collection
    {
        return Contact::withoutMasjidScope()
            ->where('masjid_id', $masjidId)
            ->whereNotNull('phone')
            ->where('phone', 'like', '%' . PhoneNumber::matchFragment($e164) . '%')
            ->get()
            ->filter(fn (Contact $contact) => $contact->smsNumber() === $e164)
            ->values();
    }
}
