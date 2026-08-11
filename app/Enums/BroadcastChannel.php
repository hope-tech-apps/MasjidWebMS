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
 * ## SMS is NOT a case here, on purpose
 *
 * No SMS provider is installed or configured anywhere in this application:
 * `composer.json` requires none, `config/services.php` defines none, and
 * `.env.example` mentions none. A `Sms` case would therefore be a button that
 * silently does nothing, or worse, one that reports "sent". An admin who
 * believes a snowstorm cancellation went out by text when it did not is far
 * worse off than one who can see the channel does not exist yet.
 *
 * What adding it actually takes is written down in
 * .claude/rules/broadcasts.md — provider + credentials, a per-tenant sender
 * identity, and a consent/opt-out column on `contacts` that does not exist
 * today. The seam is ready: add the case, add a driver, register it. Nothing
 * else in the composer changes.
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
            self::PUSH, self::EMAIL => true,
        };
    }

    /**
     * Whether this channel reads the CRM contact directory to find recipients.
     *
     * Only email does today. Push targets DEVICES (`mobile_app_users`), which
     * carry no contact link at all — that asymmetry is why the two channels
     * cannot share an audience, and why it is checked rather than assumed.
     */
    public function readsContacts(): bool
    {
        return $this === self::EMAIL;
    }
}
