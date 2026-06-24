<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Notifications\SendNotificationRequest;
use App\Jobs\SendMasjidNotificationJob;
use App\Models\Masjid;
use Symfony\Component\HttpFoundation\Response;

class NotificationsController extends Controller
{
    public function save(SendNotificationRequest $request, $masjid_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);

            $notification = $masjid->notifications()->create([
                'title' => $request->input('title'),
                'message' => $request->input('message'),
            ]);

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
                'data' => $notification
            ], Response::HTTP_ACCEPTED);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'data' => \App\Support\Errors::publicMessage($e)
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
