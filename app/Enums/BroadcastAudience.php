<?php

namespace App\Enums;

/**
 * Who a broadcast's addressable channels reach (T-008).
 *
 * ## Two cases, because the data supports two cases
 *
 * The temptation with a composer is a segment builder — "parents of grade 3",
 * "donors above $500", "everyone who came to Taraweeh". None of that is
 * derivable here without inventing it:
 *
 *   - `mobile_app_users` (the push audience) has `device_id` and
 *     `onesignal_subscription_id` and NO contact_id. A device cannot be resolved
 *     to a person, so push is all-devices-or-nothing. That is a schema fact, not
 *     a limitation of this class.
 *   - `contacts` (the email audience) is a directory of people with emails. An
 *     explicit set of contact ids is therefore real and checkable; anything
 *     smarter would be guessing.
 *
 * Groups (App\Models\Group) do relate contacts, and a `group` case is the
 * natural third one — it is deliberately NOT added here as a side effect of this
 * slice, because group audiences carry their own guardian-consent rules
 * (.claude/rules/groups.md) that deserve their own task rather than a quiet
 * inheritance.
 */
enum BroadcastAudience: string
{
    /**
     * Everyone this channel can reach: every subscribed device for push, every
     * non-placeholder contact holding an email address for email.
     */
    case EVERYONE = 'everyone';

    /** An explicit set of contact ids, snapshotted onto the broadcast. */
    case CONTACTS = 'contacts';

    /** @return array<int, string> Values, for validation rules. */
    public static function values(): array
    {
        return array_map(fn (self $a) => $a->value, self::cases());
    }
}
