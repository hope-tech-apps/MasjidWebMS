<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Masjid;
use App\Support\MobileCache;
use App\Support\MobileMedia;
use Illuminate\Support\Facades\Cache;

class AnnouncementsController extends Controller
{
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
                // Every row carries a NON-NULL image envelope, via the mobile
                // media boundary (App\Support\MobileMedia). An older build wants
                // a non-optional object with an `id`; a newer build FORCE-UNWRAPS
                // image.originalUrl! in its carousel itemBuilder, so a null URL
                // there blanks the whole Announcements tab (the 2026-08-28
                // featuresIcons-class crash, one collection over). A served
                // placeholder URL is safe for both: the old build draws it, the
                // new build's `!` never fires. The real photo still comes from the
                // media when it is present.
                return $announcements->map(function ($announcement) {
                    $row = $announcement->toArray();

                    $row['image'] = MobileMedia::envelope(
                        $announcement->image,
                        MobileMedia::imagePlaceholderUrl()
                    );

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
