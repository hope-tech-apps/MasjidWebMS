<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PhotoGalleryResource;
use App\Models\Masjid;
use App\Support\MobileCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class PhotoGalleryController extends Controller
{
    /**
     * Display a paginated listing of gallery photos
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        try {
            $masjidId = (int) $request->header('masjid-id');
            $perPage = $request->query('per_page', 10);
            $page = (int) $request->query('page', 1);

            $payload = Cache::remember(
                MobileCache::masjidVariantKey($masjidId, MobileCache::V1_GALLERY, "pp{$perPage}_p{$page}"),
                MobileCache::TTL_MEDIUM,
                function () use ($masjidId, $perPage) {
                    $masjid = Masjid::with('gallery')->findOrFail($masjidId);
                    $gallery = $masjid->gallery()->paginate($perPage);

                    return [
                        'items' => PhotoGalleryResource::collection($gallery->items())->resolve(),
                        'pagination' => [
                            'current_page' => $gallery->currentPage(),
                            'last_page' => $gallery->lastPage(),
                            'per_page' => $gallery->perPage(),
                            'total' => $gallery->total(),
                            'from' => $gallery->firstItem(),
                            'to' => $gallery->lastItem(),
                        ],
                    ];
                }
            );

            return response()->api(200, __('api.success'), $payload);

        } catch (\Exception $e) {
            return response()->api(500, \App\Support\Errors::publicMessage($e), null);
        }
    }

    /**
     * Display a single gallery photo
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        try {
            $masjid = Masjid::findOrFail(request()->header('masjid-id'));

            $photo = $masjid->gallery()->findOrFail($id);

            return response()->api(200, __('api.success'), $photo);

        } catch (\Exception $e) {
            return response()->api(500, \App\Support\Errors::publicMessage($e), null);
        }
    }
}

