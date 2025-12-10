<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PhotoGalleryResource;
use App\Models\Masjid;
use Illuminate\Http\Request;

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
            $perPage = $request->query('per_page', 10);

            $masjid = Masjid::with('gallery')->findOrFail(request()->header('masjid-id'));

            $gallery = $masjid->gallery()->paginate($perPage);

            return response()->api(200, __('api.success'), [
                'items' =>PhotoGalleryResource::collection($gallery->items()),
                'pagination' => [
                    'current_page' => $gallery->currentPage(),
                    'last_page' => $gallery->lastPage(),
                    'per_page' => $gallery->perPage(),
                    'total' => $gallery->total(),
                    'from' => $gallery->firstItem(),
                    'to' => $gallery->lastItem(),
                ]
            ]);

        } catch (\Exception $e) {
            return response()->api(500, $e->getMessage(), null);
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
            return response()->api(500, $e->getMessage(), null);
        }
    }
}

