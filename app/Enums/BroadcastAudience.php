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

    /**
     * Everyone who asked to hear about one SERVICE.
     *
     * The third case the docblock above said the data did not support — and the
     * two things that were missing now exist. `contact_service_interests`
     * records a member's own opt-in, and `mobile_app_users.contact_id` gives a
     * device an identity, which is precisely the fix .claude/rules/broadcasts.md
     * named ("fix it, if ever, by giving devices an identity") rather than
     * widening push to every handset and calling it narrow.
     *
     * Unlike CONTACTS this audience is NOT snapshotted onto the broadcast as a
     * list of people. Only the service id is stored; the recipients are resolved
     * at SEND time, because an interest is an opt-in and the SMS work already
     * settled that opt-ins are read at the moment of sending. A member who
     * withdrew their interest five minutes before dispatch must not receive the
     * message — honouring a withdrawal is the entire reason to store one.
     *
     * This is still not the `group` audience, which remains deliberately absent:
     * groups carry guardian-consent rules (.claude/rules/groups.md) that an
     * interest toggle does not.
     */
    case SERVICE = 'service';

    /**
     * Does resolving this audience read the contact directory?
     *
     * A predicate rather than a comparison, matching
     * BroadcastChannel::readsContacts(), so the authorization gate keeps working
     * when a fourth audience arrives instead of silently admitting it.
     *
     * SERVICE reads `contacts` even though its recipients are DEVICES: the
     * selection runs through a person's interests. An admin who may not view the
     * directory should not be able to slice a send by what the directory knows,
     * even though this audience never discloses an individual contact.
     */
    public function readsContacts(): bool
    {
        return $this === self::CONTACTS || $this === self::SERVICE;
    }

    /** @return array<int, string> Values, for validation rules. */
    public static function values(): array
    {
        return array_map(fn (self $a) => $a->value, self::cases());
    }
}
