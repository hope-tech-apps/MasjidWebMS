<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Masjid;
use App\Support\MobileCache;
use Illuminate\Support\Facades\Cache;

class AnnouncementsController extends Controller
{
    /**
     * Stands in for a missing image so an older client's non-optional `Gallery`
     * still decodes. `id` is the only property that decoder requires; the null
     * URLs are what stop any client drawing a broken picture.
     */
    private const NO_IMAGE = [
        'id' => 0,
        'original_url' => null,
        'preview_url' => null,
    ];

    public function index($masjid_id)
    {
        $announcements = Cache::remember(
            MobileCache::masjidKey((int) $masjid_id, MobileCache::ANNOUNCEMENTS),
            MobileCache::TTL_SHORT,
            function () use ($masjid_id) {
                $masjid = Masjid::findOrFail($masjid_id);

                $announcements = Announcement::where('masjid_id', $masjid->id)
                    ->with('image')
                    ->get();

                // AN IMAGELESS ANNOUNCEMENT IS SERVED, NOT DROPPED.
                //
                // This used to partition the list and discard every row without an
                // image, to protect an iOS build that declared `image` as a
                // non-optional `Gallery`: one bad row failed the whole decode and
                // blanked the tab. The comment said it "should never fire, the
                // admin form requires an image".
                //
                // Measured on 2026-08-21: it was firing for EVERY announcement on
                // the platform. The `media` table is empty, so all 12 of
                // Burlington's live announcements and all 3 of MEC's were being
                // withheld — the phone tab was blank and the lobby board read
                // "No announcements" while ten were current. A photograph of the
                // screen on the masjid wall is what surfaced it.
                //
                // The clients were never the obstacle. `MasjidKit`'s model now
                // reads `public let image: Gallery?`, and the tvOS carousel keeps
                // any slide with EITHER an image or text:
                //
                //     items.filter { $0.image?.originalUrl != nil || $0.carouselText != nil }
                //
                // So a text-only notice renders correctly on the board today.
                //
                // The stub below is for the OLDER build that is still in the field
                // and still wants a non-optional object. `Gallery` requires only
                // `id`; every other property is optional, so this decodes on that
                // build AND on the current one, while the null URLs mean no client
                // ever tries to draw a picture that does not exist. Returning
                // `null` outright would be cleaner and would blank the tab for
                // anyone who has not updated — the exact failure being fixed.
                return $announcements->map(function ($announcement) {
                    $row = $announcement->toArray();

                    $row['image'] = $row['image'] ?? self::NO_IMAGE;

                    return $row;
                })->values();
            }
        );

        return response()->json([
            'status' => 'success',
            'data' => $announcements
        ]);
    }
}
