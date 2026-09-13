<?php

namespace App\Services\Sms;

use App\Models\Contact;
use App\Models\SmsSuppression;

/**
 * What a recorded withdrawal actually managed to write (T-009).
 *
 * A withdrawal has TWO halves and only one of them is guaranteed. The contact's
 * own columns are always cleared — a person asking to stop is recorded whatever
 * shape their phone number is in, because refusing to hear "stop" over a data
 * quality problem is not an option. The DURABLE half is the
 * `sms_suppressions` row, and that one is keyed on an E.164 number, so it can
 * only be written when `PhoneNumber::e164()` can resolve the number at all: a
 * seven-digit local number, "613-555-0142 ext 4" and a bare non-NANP
 * international number all resolve to null and are refused rather than guessed
 * at (see PhoneNumber).
 *
 * Before this object existed, `withdraw()` returned the contact either way and
 * the endpoint answered 200 either way, so the SPA told the operator "this
 * number will not receive text messages from this organization again" for a
 * withdrawal that lived only on a row that a merge force-deletes, a re-import
 * recreates and a delete-and-re-add loses. That is precisely the failure rule 3
 * of SmsConsentService exists to prevent, announced to the person recording it
 * as a success.
 *
 * So the two halves are reported separately, and `remedy()` is the sentence the
 * operator has to act on — not a paraphrase invented in the SPA, and not a
 * silence.
 */
final class SmsWithdrawal
{
    public function __construct(
        public readonly Contact $contact,
        public readonly ?SmsSuppression $suppression,
    ) {
    }

    /** Did the withdrawal reach the list that outlives this contact row? */
    public function isDurable(): bool
    {
        return $this->suppression !== null;
    }

    /**
     * What the operator must still do, or null when there is nothing left to do.
     *
     * It names the cause (the number could not be read) and the act that fixes
     * it, because "not added to the do-not-text list" on its own reads like a
     * platform fault rather than like a field somebody can correct in ten
     * seconds.
     */
    public function remedy(): ?string
    {
        if ($this->isDurable()) {
            return null;
        }

        return 'The withdrawal is recorded on this member, but their phone number could not be read '
            . 'as a real number, so it could NOT be added to the organization\'s permanent '
            . 'do-not-text list — which means it would not survive this record being merged, '
            . 're-imported, or deleted and re-added. Save a full number including area code, then '
            . 'record the opt-out again.';
    }
}
