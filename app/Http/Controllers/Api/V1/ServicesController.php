<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ServiceResource;
use App\Models\Service;
use Illuminate\Http\Request;

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
        $perPage = $request->input('per_page', 3);

        $services = Service::filterByMasjid()
            ->with('image', 'icon')
            ->latest()
            ->paginate($perPage);

        return response()->api(200, __('api.success'), [
            'items' => ServiceResource::collection($services->items()),
            'pagination' => [
                'current_page' => $services->currentPage(),
                'last_page' => $services->lastPage(),
                'per_page' => $services->perPage(),
                'total' => $services->total(),
                'from' => $services->firstItem(),
                'to' => $services->lastItem(),
            ]
        ]);
    }

    /**
     * Display the specified service
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $service = Service::filterByMasjid()
            ->with('image', 'icon')
            ->findOrFail($id);

        return response()->api(200, __('api.success'), new ServiceResource($service));
    }
}

