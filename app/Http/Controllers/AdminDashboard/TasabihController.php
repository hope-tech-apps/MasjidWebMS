<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Tasabih\StoreTasbihRequest;
use App\Http\Requests\Admin\Tasabih\UpdateTasbihRequest;
use App\Models\LibraryTasbeeh;
use App\Models\Tasbih;
use App\Support\MobileCache;
use Illuminate\Http\Request;
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
     * List curated library presets, searchable by free text (?search=). Read-only.
     */
    public function library(Request $request)
    {
        $presets = LibraryTasbeeh::query()
            ->searchLike($request->input('search'))
            ->orderBy('id')
            ->paginate($request->input('per_page', 30));

        return response()->json([
            'status' => 'success',
            'data' => $presets,
        ], Response::HTTP_OK);
    }

    /**
     * Copy a chosen library preset into the live tasabih collection as a normal,
     * editable/deletable row.
     */
    public function addFromLibrary(Request $request)
    {
        try {
            $validated = $request->validate([
                'library_tasbeeh_id' => 'required|integer|exists:library_tasbeehs,id',
            ]);

            $preset = LibraryTasbeeh::findOrFail($validated['library_tasbeeh_id']);

            $tasbih = Tasbih::create([
                'text' => $preset->text,
                'pronunciation' => $preset->pronunciation,
                'reference' => $preset->reference,
            ]);

            MobileCache::flushGlobal(MobileCache::TASABIH_ALL);

            return response()->json([
                'status' => 'success',
                'data' => $tasbih,
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'data' => \App\Support\Errors::publicMessage($e)
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
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
