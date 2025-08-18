<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Events\SendMasjidNotificationEvent;
use App\Http\Controllers\Controller;
use App\Models\Masjid;
use App\Services\OnesignalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

class NotificationsController extends Controller
{
    public function save(Request $request, $masjid_id)
    {
        try {

            $masjid = Masjid::findOrFail($masjid_id);
            $validator = Validator::make($request->all(), [
                'title' => 'required|string',
                'message' => 'required|string'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'failed',
                    'data' => $validator->errors()
                ], Response::HTTP_BAD_REQUEST);
            }

            if ($validator->passes()) {
                $notification = $masjid->notifications()->create([
                    'title' => $request['title'],
                    'message' => $request['message']
                ]);

                // $event = event(new SendMasjidNotificationEvent($notification));
                $onesignal = new OnesignalService();
                // $broadcastResponse = $onesignal->notifyAll($masjid, $notification);
                $targetedExternalIds = $masjid->mobileAppUsers->pluck('device_id')->toArray();
                $broadcastResponse = $onesignal->notifyAllOfMasjid($masjid, $notification, $targetedExternalIds);
                

                if(isset($broadcastResponse['id']) && $broadcastResponse['id']) {
                    $notification->onesignal_message_id = $broadcastResponse['id'];
                    $notification->update();
                    return response()->json([
                        'status' => 'success',
                        'data' => $notification
                    ], Response::HTTP_OK);
                } else {
                    $notification->delete();
                    return response()->json([
                        'status' => 'failed',
                        'data' => $broadcastResponse
                    ], Response::HTTP_OK);
                }
            }

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'data' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
