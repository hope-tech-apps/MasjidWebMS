<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Tasabih\StoreTasbihRequest;
use App\Http\Requests\Admin\Tasabih\UpdateTasbihRequest;
use App\Models\Tasbih;
use App\Support\MobileCache;
use Symfony\Component\HttpFoundation\Response;

class TasabihController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $azkar = Tasbih::paginate(9);
        return response()->json([
            'status' => 'success',
            'data' => $azkar
        ], Response::HTTP_OK);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreTasbihRequest $request)
    {
        try {
            $tasbih = Tasbih::create($request->validated());

            MobileCache::flushGlobal(MobileCache::TASABIH_ALL);

            return response()->json([
                'status' => 'success',
                'data' => $tasbih
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
    public function show($tasbih_id)
    {
        $tasbih = Tasbih::findOrFail($tasbih_id);
        return response()->json([
            'status' => 'success',
            'data' => $tasbih
        ], Response::HTTP_OK);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateTasbihRequest $request, $tasbih_id)
    {
        try {
            $tasbih = Tasbih::findOrFail($tasbih_id);
            $tasbih->update($request->validated());

            MobileCache::flushGlobal(MobileCache::TASABIH_ALL);

            return response()->json([
                'status' => 'success',
                'data' => $tasbih
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
    public function destroy($tasbih_id)
    {
        $tasbih = Tasbih::findOrFail($tasbih_id);
        $tasbih->delete();

        MobileCache::flushGlobal(MobileCache::TASABIH_ALL);

        return response()->json([
            'status' => 'success',
            'data' => $tasbih
        ], Response::HTTP_OK);
    }
}
