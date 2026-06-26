<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AnnouncementResource;
use App\Models\Announcement;
use App\Support\MobileCache;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class AnnouncementsController extends Controller
{
    /**
     * Display a paginated listing of announcements
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $masjidId = (int) $request->header('masjid-id');
        $perPage = $request->input('per_page', 3);
        $filterActive = $request->input('filter_active', false);
        $page = (int) $request->input('page', 1);

        // Versioned variant key includes per_page + filter_active + page + (when
        // filtering) today's date, so the active-window result rolls over daily.
        $today = Carbon::now()->format('Y-m-d');
        $variant = "pp{$perPage}_p{$page}_fa" . ($filterActive ? "1_{$today}" : '0');

        $payload = Cache::remember(
            MobileCache::masjidVariantKey($masjidId, MobileCache::V1_ANNOUNCEMENTS, $variant),
            MobileCache::TTL_SHORT,
            function () use ($perPage, $filterActive, $today) {
                $query = Announcement::filterByMasjid()
                    ->with('image')
                    ->latest();

                if ($filterActive) {
                    $query->where('start_date', '<=', $today)
                          ->where('end_date', '>=', $today);
                }

                $announcements = $query->paginate($perPage);

                return [
                    'items' => AnnouncementResource::collection($announcements->items())->resolve(),
                    'pagination' => [
                        'current_page' => $announcements->currentPage(),
                        'last_page' => $announcements->lastPage(),
                        'per_page' => $announcements->perPage(),
                        'total' => $announcements->total(),
                        'from' => $announcements->firstItem(),
                        'to' => $announcements->lastItem(),
                    ],
                ];
            }
        );

        return response()->api(200, __('api.success'), $payload);
    }

    /**
     * Display the specified announcement
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $masjidId = (int) request()->header('masjid-id');

        $announcement = Cache::remember(
            MobileCache::masjidVariantKey($masjidId, MobileCache::V1_ANNOUNCEMENTS, "show_{$id}"),
            MobileCache::TTL_SHORT,
            fn() => (new AnnouncementResource(
                Announcement::filterByMasjid()
                    ->with('image')
                    ->findOrFail($id)
            ))->resolve()
        );

        return response()->api(200, __('api.success'), $announcement);
    }
}

