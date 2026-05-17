<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Support\Errors;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Webhook endpoint Pusher calls after a successful broadcast.
 *
 * Security: requests are HMAC-signed by Pusher. We verify the
 * `X-Pusher-Signature` header against the raw body using the webhook
 * secret (set via PUSHER_WEBHOOK_SECRET env var). Without this check
 * anyone on the internet could POST to this endpoint and flip the
 * `is_broadcasted` flag on arbitrary Notification rows.
 *
 * If PUSHER_WEBHOOK_SECRET isn't set, every request is rejected — fail
 * closed rather than open.
 */
class PusherWebhookController extends Controller
{
    public function afterNotificationBroadcasted(Request $request)
    {
        try {
            if (!$this->verifyPusherSignature($request)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid signature.',
                ], Response::HTTP_UNAUTHORIZED);
            }

            $payload = $request->all();

            if (!isset($payload['events']) || !is_array($payload['events']) || empty($payload['events'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Events array missing or empty.',
                ], Response::HTTP_BAD_REQUEST);
            }

            $event = $payload['events'][0];
            if (($event['name'] ?? null) !== 'SendMasjidNotificationEvent') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unsupported event type.',
                ], Response::HTTP_BAD_REQUEST);
            }

            $notificationId = $event['data']['notification']['id'] ?? null;
            if (!$notificationId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Notification id missing.',
                ], Response::HTTP_BAD_REQUEST);
            }

            $notification = Notification::findOrFail($notificationId);
            $notification->update(['is_broadcasted' => true]);

            return response()->json([
                'status' => 'success',
                'message' => 'Notification broadcast confirmed.',
            ], Response::HTTP_OK);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Verify the Pusher webhook signature.
     * See https://pusher.com/docs/channels/server_api/webhooks/#authentication
     */
    private function verifyPusherSignature(Request $request): bool
    {
        $secret = config('broadcasting.connections.pusher.secret')
            ?? env('PUSHER_WEBHOOK_SECRET');

        if (!$secret) {
            // Fail closed — never accept an unsigned webhook in any environment.
            return false;
        }

        $signature = $request->header('X-Pusher-Signature');
        if (!$signature) {
            return false;
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $signature);
    }
}
