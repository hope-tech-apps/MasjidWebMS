<?php

namespace App\Services\Broadcast;

use App\Models\Contact;

/**
 * Who an SMS broadcast may actually be sent to, and who was left out and why
 * (T-009).
 *
 * The counts are not decoration. The whole point of filtering on CONSENT rather
 * than on `phone IS NOT NULL` is that the two numbers differ enormously — a
 * directory of 800 contacts with phone numbers might contain 40 people who have
 * actually agreed to receive texts. An admin who selects the SMS channel and
 * sees "sent to 40" with no explanation will assume the other 760 failed.
 *
 * So every excluded contact is counted into exactly one bucket, and the delivery
 * note says which. "612 have a phone number but no recorded consent" is the
 * sentence that turns an apparent bug into an accurate description of the
 * organisation's own consent coverage — and, usefully, into a reason to go
 * collect consent properly.
 *
 * No number and no name is carried anywhere in this object's reporting; the
 * counts are aggregate by construction.
 */
final class SmsAudience
{
    /**
     * @param  array<int, array{contact: Contact, number: string}>  $recipients
     */
    public function __construct(
        public readonly array $recipients,
        public readonly int $withoutConsent = 0,
        public readonly int $suppressed = 0,
        public readonly int $unusableNumber = 0,
        public readonly int $withoutPhone = 0,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->recipients === [];
    }

    public function count(): int
    {
        return count($this->recipients);
    }

    /** Everyone the audience selected, messageable or not. */
    public function considered(): int
    {
        return $this->count() + $this->withoutConsent + $this->suppressed
            + $this->unusableNumber + $this->withoutPhone;
    }

    /**
     * The sentence shown when nobody could be texted. It is a SKIP, not a
     * failure — T-008's distinction — but a skip whose reason must be specific
     * enough to act on.
     */
    public function skipNote(): string
    {
        if ($this->considered() === 0) {
            return 'The selected audience contains nobody, so there was nothing to send.';
        }

        return 'Nobody in the selected audience can be texted, so nothing was sent. '
            . $this->exclusionSummary();
    }

    /** The same accounting, appended to a successful send's note. */
    public function exclusionSummary(): string
    {
        $parts = [];

        if ($this->withoutConsent > 0) {
            $parts[] = $this->withoutConsent . ' had no recorded consent to receive text messages';
        }

        if ($this->suppressed > 0) {
            $parts[] = $this->suppressed . ' had opted out';
        }

        if ($this->unusableNumber > 0) {
            $parts[] = $this->unusableNumber . ' had a phone number that could not be read as a real number';
        }

        if ($this->withoutPhone > 0) {
            $parts[] = $this->withoutPhone . ' had no phone number on file';
        }

        if ($parts === []) {
            return '';
        }

        return 'Of ' . $this->considered() . ' contact(s) in the audience, ' . implode('; ', $parts) . '.';
    }
}
