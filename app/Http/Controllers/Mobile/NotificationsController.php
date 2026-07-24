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
     * Each item exposes the FULL message body plus an optional image_url (Spatie
     * media, collection "notifications"); the internal `onesignal_message_id` is
     * intentionally withheld from the mobile payload. The app truncates long
     * bodies in the list row and opens a detail screen for the full text + image.
     */
    public function index(Request $request, $masjid_id)
    {
        $masjid = Masjid::findOrFail($masjid_id);
        $perPage = (int) $request->input('per_page', 20);

        $notifications = Notification::where('masjid_id', $masjid->id)
            ->latest()
            ->paginate($perPage);

        $items = collect($notifications->items())->map(function (Notification $n) {
            return [
                'id' => $n->id,
                'title' => $n->title,
                'message' => $n->message,
                'image_url' => $n->getFirstMediaUrl('notifications') ?: null,
                'created_at' => $n->created_at,
            ];
        })->values();

        return response()->json([
            'status' => 'success',
            'data' => [
                'items' => $items,
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
