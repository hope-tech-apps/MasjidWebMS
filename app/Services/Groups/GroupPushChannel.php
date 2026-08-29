<?php

namespace App\Services\Groups;

use App\Enums\GroupNotificationEvent;
use App\Models\Group;
use App\Models\Masjid;
use App\Support\NudgeRecipient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The push half of a class notification — and, today, the seam where push is
 * NOT YET possible per recipient.
 *
 * OneSignal is addressed only by SUBSCRIPTION ID, and subscription ids live on
 * `mobile_app_users` (a device), which carries no contact_id and no user_id.
 * A school PARENT (a family-login Contact) and a TEACHER (a User) therefore have
 * no push address at all today, so this degrades to a no-op SKIP — the resolver
 * still names the right people, only the transport is absent, and email reaches
 * them regardless.
 *
 * Two things this must NEVER do, both of which the design review flagged:
 *   - construct OnesignalService inline — its constructor THROWS on missing
 *     shared config, which under the test sync-queue would turn a successful
 *     post into a 500;
 *   - reuse the masjid-wide PushChannel / SendMasjidNotificationJob — those write
 *     a per-masjid device broadcast, which would push "Grade 3 has an update" to
 *     every device in the whole organisation.
 *
 * TO ADD PER-RECIPIENT PUSH LATER (a localized change here only): register each
 * signed-in family/teacher device's subscription id against the Contact/User
 * (a new `contact_push_subscriptions` / `user_push_subscriptions` pivot, written
 * by an authenticated mobile endpoint mirroring MobileAppUsersController::
 * heartbeat), then here pluck the subscription ids belonging to exactly THESE
 * recipients and dispatch a SendMasjidNotificationJob-style job through
 * OnesignalService WITH $masjid (subscription ids are app-scoped). Store the
 * subscription id, never OneSignal external_id = contact id (aliases do not
 * resolve in the notification API).
 */
class GroupPushChannel
{
    /**
     * @param  Collection<int,NudgeRecipient>  $recipients
     */
    public function deliver(
        Masjid $masjid,
        Group $group,
        GroupNotificationEvent $event,
        Collection $recipients,
        string $kind,
    ): void {
        if ($recipients->isEmpty()) {
            return;
        }

        // No Contact/User -> onesignal_subscription_id mapping exists yet, so
        // there is nothing to target. Recorded, not errored, so this stays a
        // pure email-only v1 without a masjid-wide leak.
        Log::info('GroupPushChannel: per-recipient push not yet available — email-only', [
            'masjid_id' => $masjid->id,
            'group_id' => $group->id,
            'event' => $event->value,
            'recipients' => $recipients->count(),
        ]);
    }
}
