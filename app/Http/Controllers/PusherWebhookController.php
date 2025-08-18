<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PusherWebhookController extends Controller
{
    public function afterNotificationBroadcasted(Request $request)
    {
        try {
            $payload = $request->all();
            if (isset($payload['events'])) {
                if ($payload['events'][0]) {
                    if ($payload['events'][0]['name'] === 'SendMasjidNotificationEvent') {
                        $notification = Notification::findOrFail($payload['events'][0]['data']['notification']['id']);
                        if ($notification) {
                            $notification->update(['is_broadcasted' => true]);
                            return response()->json([
                                'status' => 'failed',
                                'data' => 'Notification broadcasted status changed successfully.'
                            ], Response::HTTP_OK);
                        }
                    } else {
                        return response()->json([
                            'status' => 'failed',
                            'data' => 'Event [0] is not of type SendMasjidNotificationEvent.'
                        ], Response::HTTP_EXPECTATION_FAILED);
                    }
                } else {
                    return response()->json([
                        'status' => 'failed',
                        'data' => 'Event [0] not defined.'
                    ], Response::HTTP_EXPECTATION_FAILED);
                }
            } else {
                return response()->json([
                    'status' => 'failed',
                    'data' => 'Events array not defined.'
                ], Response::HTTP_EXPECTATION_FAILED);
            }
            return response()->json(['status' => 'success']);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'data' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
