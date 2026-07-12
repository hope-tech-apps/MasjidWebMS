<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Masjid;
use App\Models\Notification;
use Illuminate\Http\Request;

class NotificationsController extends Controller
{
    /**
     * Paginated notification history for a masjid, newest first.
     *
     * Backs the in-app "notifications inbox". Not cached: results vary by page,
     * so it follows the uncached paginated pattern (see HadithsController@index)
     * rather than the cached single-resource endpoints. Read/unread state is
     * tracked client-side — the mobile device model is anonymous, so there is no
     * per-user identity to attribute a "read" to server-side — so this endpoint
     * only exposes the broadcast history; the app compares ids against a locally
     * stored last-seen id to compute the unread badge.
     *
     * Only the columns the inbox renders are selected; `onesignal_message_id`
     * (an internal delivery id) is intentionally withheld from the mobile payload.
     */
    public function index(Request $request, $masjid_id)
    {
        $masjid = Masjid::findOrFail($masjid_id);
        $perPage = (int) $request->input('per_page', 20);

        $notifications = Notification::where('masjid_id', $masjid->id)
            ->latest()
            ->paginate($perPage, ['id', 'masjid_id', 'title', 'message', 'created_at']);

        return response()->json([
            'status' => 'success',
            'data' => [
                'items' => $notifications->items(),
                'pagination' => [
                    'current_page' => $notifications->currentPage(),
                    'last_page' => $notifications->lastPage(),
                    'per_page' => $notifications->perPage(),
                    'total' => $notifications->total(),
                    'from' => $notifications->firstItem(),
                    'to' => $notifications->lastItem(),
                ]
            ]
        ]);
    }
}
