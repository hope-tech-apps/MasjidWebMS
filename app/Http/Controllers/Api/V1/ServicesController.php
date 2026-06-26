<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ServiceResource;
use App\Models\Service;
use App\Support\MobileCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ServicesController extends Controller
{
    /**
     * Display a paginated listing of services
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $masjidId = (int) $request->header('masjid-id');
        $perPage = $request->input('per_page', 3);
        $page = (int) $request->input('page', 1);

        // Versioned per-page variant key: flushServices() bumps the version so
        // all cached pages for this masjid are invalidated on any service edit.
        $payload = Cache::remember(
            MobileCache::masjidVariantKey($masjidId, MobileCache::V1_SERVICES, "pp{$perPage}_p{$page}"),
            MobileCache::TTL_MEDIUM,
            function () use ($perPage) {
                $services = Service::filterByMasjid()
                    ->with('image', 'icon')
                    ->latest()
                    ->paginate($perPage);

                return [
                    'items' => ServiceResource::collection($services->items())->resolve(),
                    'pagination' => [
                        'current_page' => $services->currentPage(),
                        'last_page' => $services->lastPage(),
                        'per_page' => $services->perPage(),
                        'total' => $services->total(),
                        'from' => $services->firstItem(),
                        'to' => $services->lastItem(),
                    ],
                ];
            }
        );

        return response()->api(200, __('api.success'), $payload);
    }

    /**
     * Display the specified service
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $masjidId = (int) request()->header('masjid-id');

        $service = Cache::remember(
            MobileCache::masjidVariantKey($masjidId, MobileCache::V1_SERVICES, "show_{$id}"),
            MobileCache::TTL_MEDIUM,
            fn() => (new ServiceResource(
                Service::filterByMasjid()
                    ->with('image', 'icon')
                    ->findOrFail($id)
            ))->resolve()
        );

        return response()->api(200, __('api.success'), $service);
    }
}

