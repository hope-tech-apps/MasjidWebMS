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
 *     merge rule below the only defensible one — and it is enforced on the
 *     ordinary edit path too, by `Contact::booted()`: changing a contact's
 *     phone number clears the four consent columns, because the record would
 *     otherwise assert that somebody agreed to be texted at a number they never
 *     gave.
 *
 *  5. **A consent record that already stands is never rewritten.** `grant()`
 *     REFUSES a second grant instead of overwriting the first. The reasoning is
 *     on the method.
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
     * Record consent for one contact — for the FIRST time, and only that.
     *
     * ## Why a second grant is REFUSED rather than written more carefully
     *
     * This method used to `forceFill` all five columns unconditionally, so
     * pressing "Record consent" on a member who had already consented replaced
     * the original date, the original source and the original evidence — and
     * blanked the evidence entirely when the form's optional box was left empty,
     * which is the normal way that button gets pressed. Nothing appended a
     * prior-value row anywhere (unlike family login, which writes
     * `contact_login_events`), and the panel is the only place in the
     * application that can write these columns. So the consent that was actually
     * given simply ceased to exist, and with it the proof that consent PREDATED
     * every message already sent — which is the one thing the record is for.
     *
     * Three ways out were available and only one of them is honest:
     *
     *  - Overwrite more politely (keep the earliest `sms_consent_at`, never null
     *     an existing evidence string). Rejected: it still silently replaces the
     *     source and the evidence with a staff member's later recollection, and
     *     it produces a record whose date says one act and whose provenance says
     *     another. A record that mixes two acts is evidence of neither.
     *  - Append the prior triple to a trail before overwriting. Rejected HERE,
     *     not on principle: a trail is the right shape for a fact that legitimately
     *     changes over time, and consent is not one. There is nothing a second
     *     grant can lawfully add — permission to text this number already exists,
     *     so the only effect the write can have is on the evidence, and every
     *     possible effect there is destructive. It would also mean a new table, a
     *     screen to read it on, and a migration, bought to make an operation
     *     safe that should not happen at all.
     *  - Refuse. Which is what this does, in the shape rule 2 already uses for
     *     the suppressed case: a `RuntimeException` the controller answers as a
     *     422 carrying the sentence verbatim. The standing record is left byte
     *     for byte as it was recorded.
     *
     * The refusal is not a dead end, because the two things staff might actually
     * be trying to do both still work:
     *
     *  - The member has asked to stop → "Record opt-out", which is a different
     *    verb and always available.
     *  - Consent is for a DIFFERENT number → save the new number on the contact
     *    first. `Contact::booted()` clears the consent claim when the number
     *    changes (rule 4), and the fresh grant is then the first grant for that
     *    number, which is exactly what it is.
     *
     * What cannot be done through this door is editing history, and that is the
     * point.
     *
     * NOTE for callers that run on every submission rather than on a deliberate
     * act — `LunchSmsOptIn` is the one that exists — they must ask
     * `hasSmsConsent()` first and skip the call. A returning customer ticking
     * the same box for the second week is not making a new claim, and treating
     * it as one is what re-stamped their record with the current disclosure
     * wording instead of the sentence they were actually shown.
     *
     * @throws RuntimeException when the number is unusable, suppressed, or
     *                          already carries a consent record.
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

        if ($contact->hasSmsConsent()) {
            throw new RuntimeException(sprintf(
                'Consent for this member is already on record — recorded %s (%s), and it is the '
                . 'record that proves consent came before every message already sent. Recording it '
                . 'again would replace that date, source and evidence, so it is refused. '
                . 'If they have asked to stop, record an opt-out instead. If this is consent for a '
                . 'different number, save the new number on the member first — changing the number '
                . 'clears the old record, because consent belongs to a number.',
                $contact->sms_consent_at?->toFormattedDateString() ?? 'earlier',
                $contact->sms_consent_source,
            ));
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
     *
     * ## Why this returns an object rather than the contact
     *
     * The durable write is CONDITIONAL and always was — `smsNumber()` returns
     * null for a number `PhoneNumber` refuses to guess at, and the suppression
     * list is keyed on E.164, so there is nothing to key a row on. That branch
     * used to be silent: the method returned the contact, the endpoint answered
     * 200, and the screen told the operator the number was on a permanent
     * do-not-text list that had never heard of it.
     *
     * The withdrawal itself is NEVER conditional — the columns are cleared
     * first, unconditionally, because a person's "stop texting me" must not be
     * refused over the formatting of their phone number. What is reported back
     * is whether it also became durable. See SmsWithdrawal.
     */
    public function withdraw(Contact $contact, string $reason = SmsSuppression::REASON_MANUAL): SmsWithdrawal
    {
        $now = Carbon::now();

        $contact->forceFill([
            'sms_opt_in' => false,
            'sms_opted_out_at' => $now,
        ])->save();

        $suppression = null;

        if ($number = $contact->smsNumber()) {
            $suppression = $this->suppress((int) $contact->masjid_id, $number, reason: $reason);
        }

        return new SmsWithdrawal($contact, $suppression);
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
