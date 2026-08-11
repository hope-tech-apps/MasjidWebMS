<?php

namespace App\Services\Broadcast\Channels;

use App\Enums\BroadcastChannel;
use App\Jobs\SendMasjidNotificationJob;
use App\Models\Broadcast;
use App\Models\Masjid;
use App\Services\Broadcast\BroadcastAudienceResolver;
use App\Services\Broadcast\BroadcastChannelDriver;
use App\Services\Broadcast\ChannelResult;

/**
 * The push channel (T-008).
 *
 * Goes through the EXISTING push path end to end: an ordinary `notifications`
 * row (which is also the in-app notification inbox the mobile client reads at
 * /mobile/masjids/{id}/notifications) plus the existing
 * App\Jobs\SendMasjidNotificationJob, which owns the OneSignal call, its retries
 * and its backoff. This driver contains no HTTP, no credentials and no OneSignal
 * payload — duplicating any of that would mean two push implementations drifting
 * apart, and the subscription-id-not-alias lesson baked into the job would have
 * to be learned twice.
 *
 * ## What `sent` means here
 *
 * It means the message was accepted by the delivery pipeline — the notification
 * row exists and the job is queued. It does NOT claim a phone lit up: OneSignal
 * answers asynchronously, and its verdict lands on
 * `notifications.onesignal_message_id` (or, after exhausted retries, the job's
 * failed() handler removes the row). The delivery note says so rather than
 * implying a stronger guarantee than the channel can make.
 *
 * ## No devices is a SKIP
 *
 * The notification row is still created — it is the in-app inbox entry, and the
 * pre-existing endpoint creates it in this case too — but with nothing to target
 * there is no job to dispatch and no red error to show. The admin sees "0
 * devices are registered for push", which is the fact they need before assuming
 * anyone was reached.
 */
class PushChannel implements BroadcastChannelDriver
{
    public function __construct(private readonly BroadcastAudienceResolver $audience)
    {
    }

    public function channel(): BroadcastChannel
    {
        return BroadcastChannel::PUSH;
    }

    public function deliver(Broadcast $broadcast, Masjid $masjid): ChannelResult
    {
        // Same creation path as AdminDashboard\NotificationsController::save.
        // Notification is pre-CRM (no BelongsToMasjid), so the relation is what
        // stamps masjid_id.
        $notification = $masjid->notifications()->create([
            'title' => $broadcast->title,
            'message' => $broadcast->body,
        ]);

        // Rich push image: the job reads it back off the notification's own
        // media collection, so it has to be copied onto the notification rather
        // than passed as a URL.
        if ($path = $broadcast->imagePath()) {
            $notification->addMedia($path)
                ->preservingOriginal()
                ->toMediaCollection('notifications');
        }

        $subscriptionIds = $this->audience->pushSubscriptionIds($masjid);

        if ($subscriptionIds === []) {
            return ChannelResult::skipped(
                'No device has registered for push on this masjid, so there was nothing to send to. '
                . 'The message is still in the in-app notifications list.',
                referenceId: $notification->id,
            );
        }

        SendMasjidNotificationJob::dispatch($notification, $masjid, $subscriptionIds);

        return ChannelResult::sent(
            targetCount: count($subscriptionIds),
            referenceId: $notification->id,
            note: 'Handed to the OneSignal delivery job for ' . count($subscriptionIds)
                . ' registered device(s); OneSignal confirms asynchronously.',
        );
    }
}
