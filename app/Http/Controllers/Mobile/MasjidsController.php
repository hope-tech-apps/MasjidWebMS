<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Masjid;
use App\Support\MobileCache;
use Illuminate\Support\Facades\Cache;

class MasjidsController extends Controller
{
    public function index()
    {
        $masjids = Cache::remember(
            MobileCache::globalKey(MobileCache::MASJIDS_LIST),
            MobileCache::TTL_DAY,
            fn() => Masjid::with('logo')->get()
        );

        return response()->json([
            'status' => 'success',
            'data' => $masjids
        ]);
    }

    public function show($masjid_id)
    {
        $masjid = Cache::remember(
            MobileCache::masjidKey((int) $masjid_id, MobileCache::SHOW),
            MobileCache::TTL_MEDIUM,
            fn() => Masjid::with(
                'logo',
                'donationLink.image',
                'masjidAbout.aboutImage',
                'masjidAbout.missionIcon',
                'masjidAbout.visionIcon',
                'socialMediaLinks'
            )->findOrFail($masjid_id)
        );

        return response()->json([
            'status' => 'success',
            'data' => $masjid
        ]);
    }

    public function gallery($masjid_id)
    {
        $gallery = Cache::remember(
            MobileCache::masjidKey((int) $masjid_id, MobileCache::GALLERY),
            MobileCache::TTL_MEDIUM,
            fn() => Masjid::with('gallery')->findOrFail($masjid_id)->gallery->toArray()
        );

        return response()->json([
            'status' => 'success',
            'data' => $gallery
        ]);
    }

    public function about($masjid_id)
    {
        // Wrap in [value => ...] because Cache::remember refuses to cache null —
        // a masjid with no MasjidAbout record would otherwise re-query every call.
        $wrapped = Cache::remember(
            MobileCache::masjidKey((int) $masjid_id, MobileCache::ABOUT),
            MobileCache::TTL_MEDIUM,
            fn() => ['value' => Masjid::with(
                'masjidAbout.aboutImage',
                'masjidAbout.missionIcon',
                'masjidAbout.visionIcon'
            )->findOrFail($masjid_id)->masjidAbout],
        );

        return response()->json([
            'status' => 'success',
            'data' => $wrapped['value']
        ]);
    }

    public function donationLink($masjid_id)
    {
        // Same null-cache workaround as ::about above.
        $wrapped = Cache::remember(
            MobileCache::masjidKey((int) $masjid_id, MobileCache::DONATION_LINK),
            MobileCache::TTL_MEDIUM,
            fn() => ['value' => Masjid::with('donationLink.image')->findOrFail($masjid_id)->donationLink],
        );

        return response()->json([
            'status' => 'success',
            'data' => $wrapped['value']
        ]);
    }
}
