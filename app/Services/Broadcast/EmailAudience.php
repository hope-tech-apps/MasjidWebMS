<?php

namespace App\Services\Broadcast;

use App\Models\Contact;
use Illuminate\Support\Collection;

/**
 * Who a broadcast EMAIL may actually be sent to, and how many were left out
 * because they had unsubscribed (T-042c).
 *
 * The sibling of App\Services\Broadcast\SmsAudience, and it exists for the same
 * reason that one does: a shrinking recipient count with no explanation looks
 * like a bug. An admin who addressed "everyone", saw 412 last month and sees 394
 * today will read it as lost data unless the delivery note says "18 have
 * unsubscribed from this organization's emails". That sentence is also the only
 * place an organisation is ever told its unsubscribe rate, which is a thing it
 * should know.
 *
 * The count is aggregate by construction: no address and no name is carried into
 * any note this object produces.
 *
 * Deliberately thinner than SmsAudience. SMS has four exclusion buckets because
 * consent, an unreadable number and a missing number are genuinely different
 * facts about a person. Email has one, because email needs no consent record to
 * send — an address on the organisation's own contact list is the permission,
 * and the only thing that withdraws it is an unsubscribe. Contacts with no
 * address on file are still dropped in SQL and not counted here, exactly as
 * before this class existed; counting them would mean loading every contact of
 * an organisation into memory on every send, which is a change to make on
 * purpose rather than as a side effect of adding an opt-out.
 */
final class EmailAudience
{
    /**
     * @param  Collection<int, Contact>  $recipients
     */
    public function __construct(
        public readonly Collection $recipients,
        public readonly int $suppressed = 0,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->recipients->isEmpty();
    }

    public function count(): int
    {
        return $this->recipients->count();
    }

    /** Everyone the audience selected who had an address, mailable or not. */
    public function considered(): int
    {
        return $this->count() + $this->suppressed;
    }

    /**
     * The sentence shown when nobody could be emailed. It is a SKIP, not a
     * failure — .claude/rules/broadcasts.md's distinction — but a skip whose
     * reason has to be specific enough to act on. "Nobody has an email address"
     * and "everybody unsubscribed" call for completely different responses from
     * the organisation.
     */
    public function skipNote(): string
    {
        if ($this->suppressed === 0) {
            return 'Nobody in the selected audience has an email address on file, so there was nothing to send.';
        }

        return 'Nobody in the selected audience could be emailed, so nothing was sent. '
            . $this->exclusionSummary();
    }

    /** The same accounting, appended to a successful send's note. */
    public function exclusionSummary(): string
    {
        if ($this->suppressed === 0) {
            return '';
        }

        return 'Of ' . $this->considered() . ' contact(s) with an email address in the audience, '
            . $this->suppressed . ' have unsubscribed from this organization\'s emails and were not sent to.';
    }
}
