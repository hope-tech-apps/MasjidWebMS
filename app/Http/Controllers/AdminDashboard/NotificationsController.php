<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Notifications\SendNotificationRequest;
use App\Jobs\SendMasjidNotificationJob;
use App\Models\Masjid;
use Symfony\Component\HttpFoundation\Response;

class NotificationsController extends Controller
{
    /** Recent outcomes on the existing Notifications screen, under its existing admin/tenant fences. */
    public function index($masjid_id)
    {
        $masjid = Masjid::findOrFail($masjid_id);
        $notes = $masjid->notifications()->orderByDesc('id')->limit(30)->get();
        $outcomes = \Illuminate\Support\Facades\Schema::hasTable('push_sends')
            ? \App\Models\PushSend::where('masjid_id', $masjid->id)->whereIn('notification_id', $notes->pluck('id'))->get()->keyBy('notification_id')
            : collect();
        foreach ($outcomes as $outcome) {
            try {
                app(\App\Services\PushOutcomeScheduler::class)->schedule($outcome->id);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Push outcome enqueue unavailable', ['push_send_id' => $outcome->id]);
            }
        }
        return response()->json(['status' => 'success', 'data' => $notes->map(function ($note) use ($outcomes) {
            $outcome = $outcomes[$note->id] ?? null;
            return [
                'id' => $note->id, 'title' => $note->title, 'created_at' => $note->created_at,
                'push_outcome' => $outcome?->toArray(),
                'push_outcome_text' => $outcome?->wording() ?? ($note->created_at->lt(now()->subMinutes(3))
                    ? 'Delivery could not be checked.' : 'Checking delivery…'),
            ];
        })]);
    }

    public function save(SendNotificationRequest $request, $masjid_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);

            $notification = $masjid->notifications()->create([
                'title' => $request->input('title'),
                'message' => $request->input('message'),
            ]);

            // Optional image — stored via Spatie media (collection "notifications"),
            // mirroring the Announcement pattern.
            if ($request->hasFile('image')) {
                $notification->addMediaFromRequest('image')->toMediaCollection('notifications');
            }

            // Target OneSignal SUBSCRIPTION IDs, not external_id aliases (aliases
            // don't resolve in OneSignal's notification API). Only devices that
            // have reported a subscription id (via the heartbeat) are targetable.
            // Hand the OneSignal HTTP call to a queued worker so the admin's
            // request returns immediately.
            $targetedSubscriptionIds = $masjid->mobileAppUsers()
                ->whereNotNull('onesignal_subscription_id')
                ->pluck('onesignal_subscription_id')
                ->filter()
                ->values()
                ->toArray();
            SendMasjidNotificationJob::dispatch($notification, $masjid, $targetedSubscriptionIds);

            return response()->json([
                'status' => 'success',
                'data' => array_merge($notification->toArray(), [
                    'push_outcome_text' => 'Waiting to send.',
                ])
            ], Response::HTTP_ACCEPTED);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'data' => \App\Support\Errors::publicMessage($e)
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
