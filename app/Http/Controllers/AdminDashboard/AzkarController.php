<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Azkar\StoreAzkarRequest;
use App\Http\Requests\Admin\Azkar\UpdateAzkarRequest;
use App\Models\Azkar;
use App\Models\AzkarCategory;
use App\Models\LibraryAzkar;
use App\Support\MobileCache;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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
     * List curated library presets, searchable by free text (?search=). Read-only.
     */
    public function library(Request $request)
    {
        $presets = LibraryAzkar::query()
            ->searchLike($request->input('search'))
            ->orderBy('category')
            ->orderBy('id')
            ->paginate($request->input('per_page', 30));

        return response()->json([
            'status' => 'success',
            'data' => $presets,
        ], Response::HTTP_OK);
    }

    /**
     * Copy a chosen library preset into the live azkar collection as a normal,
     * editable/deletable row. The preset's freeform category tag (morning/evening)
     * is mapped to a matching AzkarCategory, auto-creating one if it doesn't exist,
     * so the copied zikr lands in a usable category.
     */
    public function addFromLibrary(Request $request)
    {
        try {
            $validated = $request->validate([
                'library_azkar_id' => 'required|integer|exists:library_azkar,id',
            ]);

            $preset = LibraryAzkar::findOrFail($validated['library_azkar_id']);

            $categoryId = null;
            if (!empty($preset->category)) {
                $category = AzkarCategory::firstOrCreate(
                    ['title' => Str::title($preset->category)]
                );
                $categoryId = $category->id;
            }

            $zikr = Azkar::create([
                'azkar_category_id' => $categoryId,
                'title' => $preset->title,
                'text' => $preset->text,
                'bless' => $preset->bless,
                'pronunciation' => $preset->pronunciation,
                'frequency' => $preset->frequency,
                'reference' => $preset->reference,
            ]);

            MobileCache::flushGlobal(MobileCache::AZKAR_ALL);
            MobileCache::flushGlobal(MobileCache::AZKAR_CATEGORIZED);

            return response()->json([
                'status' => 'success',
                'data' => $zikr,
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
