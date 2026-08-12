<?php

namespace App\Enums;

/**
 * The channels one composed broadcast can be fanned out to (T-008).
 *
 * A channel is three things and nothing more: this case, a driver class
 * implementing App\Services\Broadcast\BroadcastChannelDriver, and its entry in
 * App\Services\Broadcast\BroadcastDispatcher::DRIVERS. There is no per-channel
 * table and no per-channel endpoint — every case here delegates to a delivery
 * path that already existed before this slice and is left untouched by it.
 *
 * ## SMS arrived in T-009, and it cost four tables' worth of obligations
 *
 * This enum previously carried a long note explaining why SMS was NOT a case
 * here: no provider, no credentials, no per-tenant sender, and — the two that
 * mattered — no consent record and no opt-out that survives a deleted contact.
 * The case exists now because all five were built, not because the button was
 * wanted. `SMS` is the only channel that can refuse to send while perfectly
 * healthy: an organisation with no approved A2P 10DLC sender, or an audience
 * with no recorded consent, is told so rather than quietly served.
 *
 * Adding the sixth channel is still exactly three steps (see below). The
 * obligations that came with this one are documented in
 * .claude/rules/broadcasts.md and enforced in code, not in prose.
 */
enum BroadcastChannel: string
{
    /** The web/mobile announcements feed — creates a real `announcements` row. */
    case ANNOUNCEMENT = 'announcement';

    /** Push via OneSignal — creates a real `notifications` row + the existing job. */
    case PUSH = 'push';

    /** The tvOS signage board — a PULL channel, served from `broadcasts`. */
    case SIGNAGE = 'signage';

    /** Email to the CRM contact audience, through the existing mail path. */
    case EMAIL = 'email';

    /**
     * SMS to the CONSENTING part of the CRM contact audience (T-009).
     *
     * Not "contacts with a phone number" — contacts with a dated, sourced,
     * un-withdrawn opt-in whose number is not on the tenant's suppression list.
     * Requires an approved per-tenant A2P 10DLC sender; refuses clearly when
     * absent rather than borrowing a shared number.
     */
    case SMS = 'sms';

    /** @return array<int, string> Values, for validation rules. */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    /** Admin-facing label. */
    public function label(): string
    {
        return match ($this) {
            self::ANNOUNCEMENT => 'Announcements feed',
            self::PUSH => 'Push notification',
            self::SIGNAGE => 'TV signage',
            self::EMAIL => 'Email',
            self::SMS => 'Text message (SMS)',
        };
    }

    /**
     * Whether this channel addresses an audience of PEOPLE.
     *
     * False for signage: a board on a wall is fetched by a device, not sent to a
     * person, so an audience selection is meaningless for it and a target count
     * of zero there is correct rather than a failure.
     */
    public function isAddressable(): bool
    {
        return match ($this) {
            self::ANNOUNCEMENT, self::SIGNAGE => false,
            self::PUSH, self::EMAIL, self::SMS => true,
        };
    }

    /**
     * Whether this channel reads the CRM contact directory to find recipients.
     *
     * Email and SMS do. Push targets DEVICES (`mobile_app_users`), which carry
     * no contact link at all — that asymmetry is why the two channels cannot
     * share an audience, and why it is checked rather than assumed.
     *
     * SMS answering true here is load-bearing rather than incidental:
     * BroadcastsController::authorizeChannels loops over this predicate, so the
     * new channel INHERITED the up-front `crm_enabled` + `view contacts` 403
     * that email already had, without the controller changing. That is exactly
     * the reason T-008 wrote the check as a loop instead of an
     * `if ($channel === EMAIL)`.
     */
    public function readsContacts(): bool
    {
        return $this === self::EMAIL || $this === self::SMS;
    }
}
