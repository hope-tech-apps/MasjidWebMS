<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Azkar\StoreAzkarRequest;
use App\Http\Requests\Admin\Azkar\UpdateAzkarRequest;
use App\Models\Azkar;
use App\Models\AzkarCategory;
use App\Support\MobileCache;
use Symfony\Component\HttpFoundation\Response;

class AzkarController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $azkar = Azkar::paginate(9);
        return response()->json([
            'status' => 'success',
            'data' => $azkar
        ]);
    }

    public function categories()
    {
        $categories = AzkarCategory::all();
        return response()->json([
            'status' => 'success',
            'data' => $categories
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreAzkarRequest $request)
    {
        try {
            $zikr = Azkar::create($request->validated());

            MobileCache::flushGlobal(MobileCache::AZKAR_ALL);
            MobileCache::flushGlobal(MobileCache::AZKAR_CATEGORIZED);

            return response()->json([
                'status' => 'success',
                'data' => $zikr
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'data' => \App\Support\Errors::publicMessage($e)
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show($zikr_id)
    {
        $zikr = Azkar::findOrFail($zikr_id);
        return response()->json([
            'status' => 'success',
            'data' => $zikr
        ], Response::HTTP_OK);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateAzkarRequest $request, $zikr_id)
    {
        try {
            $zikr = Azkar::findOrFail($zikr_id);
            $zikr->update($request->validated());

            MobileCache::flushGlobal(MobileCache::AZKAR_ALL);
            MobileCache::flushGlobal(MobileCache::AZKAR_CATEGORIZED);

            return response()->json([
                'status' => 'success',
                'data' => $zikr
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'data' => \App\Support\Errors::publicMessage($e)
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($zikr_id)
    {
        $zikr = Azkar::findOrFail($zikr_id);
        $zikr->delete();

        MobileCache::flushGlobal(MobileCache::AZKAR_ALL);
        MobileCache::flushGlobal(MobileCache::AZKAR_CATEGORIZED);

        return response()->json([
            'status' => 'success',
            'data' => $zikr
        ], Response::HTTP_OK);
    }
}
