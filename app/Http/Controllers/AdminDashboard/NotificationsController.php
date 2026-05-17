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

            // Collect target device IDs while we have the Masjid context, then hand the
            // OneSignal HTTP call to a queued worker so the admin's request returns immediately.
            $targetedExternalIds = $masjid->mobileAppUsers->pluck('device_id')->filter()->values()->toArray();
            SendMasjidNotificationJob::dispatch($notification, $masjid, $targetedExternalIds);

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
