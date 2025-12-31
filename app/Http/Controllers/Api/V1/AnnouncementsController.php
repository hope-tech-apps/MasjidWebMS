<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AnnouncementResource;
use App\Models\Announcement;
use Carbon\Carbon;
use Illuminate\Http\Request;

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
        $perPage = $request->input('per_page', 3);
        $filterActive = $request->input('filter_active', false);

        $query = Announcement::filterByMasjid()
            ->with('image')
            ->latest();

        // Filter active announcements (within date range)
        if ($filterActive) {
            $today = Carbon::now()->format('Y-m-d');
            $query->where('start_date', '<=', $today)
                  ->where('end_date', '>=', $today);
        }

        $announcements = $query->paginate($perPage);

        return response()->api(200, __('api.success'), [
            'items' => AnnouncementResource::collection($announcements->items()),
            'pagination' => [
                'current_page' => $announcements->currentPage(),
                'last_page' => $announcements->lastPage(),
                'per_page' => $announcements->perPage(),
                'total' => $announcements->total(),
                'from' => $announcements->firstItem(),
                'to' => $announcements->lastItem(),
            ]
        ]);
    }

    /**
     * Display the specified announcement
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $announcement = Announcement::filterByMasjid()
            ->with('image')
            ->findOrFail($id);

        return response()->api(200, __('api.success'), new AnnouncementResource($announcement));
    }
}

