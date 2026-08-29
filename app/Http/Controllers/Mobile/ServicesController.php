<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Masjid;
use App\Models\Service;
use App\Support\MobileCache;
use App\Support\MobileMedia;
use Illuminate\Support\Facades\Cache;

class ServicesController extends Controller
{
    public function index($masjid_id)
    {
        $services = Cache::remember(
            MobileCache::masjidKey((int) $masjid_id, MobileCache::SERVICES),
            MobileCache::TTL_MEDIUM,
            function () use ($masjid_id) {
                $masjid = Masjid::findOrFail($masjid_id);

                return Service::where('masjid_id', $masjid->id)
                    ->with('image', 'icon')
                    ->get()
                    ->map(function ($service) {
                        $row = $service->toArray();

                        // The services list force-unwraps icon.originalUrl! and
                        // the detail screen image.originalUrl!; a service saved
                        // without one crashes that screen (the featuresIcons
                        // class). Guarantee both are non-null envelopes.
                        $row['icon'] = MobileMedia::envelope(
                            $service->icon,
                            MobileMedia::imagePlaceholderUrl()
                        );
                        $row['image'] = MobileMedia::envelope(
                            $service->image,
                            MobileMedia::imagePlaceholderUrl()
                        );

                        return $row;
                    })
                    ->values();
            }
        );

        return response()->json([
            'status' => 'success',
            'data' => $services,
        ]);
    }
}
