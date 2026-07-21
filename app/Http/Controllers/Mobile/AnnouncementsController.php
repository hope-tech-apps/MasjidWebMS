<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Masjid;
use App\Support\MobileCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

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

                // The iOS client declares `image` non-optional and decodes the list
                // in one pass, so a single imageless row throws and the whole
                // announcements tab renders empty — every other announcement
                // disappears with it. Dropping the one bad row keeps the feed alive.
                //
                // This should never fire: the admin form requires an image and so
                // does the assistant. It is here because the blast radius of being
                // wrong is the entire tab, for every user, until an App Store
                // release. Logged loudly so a hidden announcement is not silent.
                [$usable, $broken] = $announcements->partition(fn ($a) => $a->image !== null);

                if ($broken->isNotEmpty()) {
                    Log::warning('Announcements hidden from mobile feed: no image', [
                        'masjid_id' => $masjid->id,
                        'announcement_ids' => $broken->pluck('id')->all(),
                    ]);
                }

                return $usable->values();
            }
        );

        return response()->json([
            'status' => 'success',
            'data' => $announcements
        ]);
    }
}
