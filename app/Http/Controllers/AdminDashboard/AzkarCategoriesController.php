<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AzkarCategories\StoreAzkarCategoryRequest;
use App\Http\Requests\Admin\AzkarCategories\UpdateAzkarCategoryRequest;
use App\Models\AzkarCategory;
use App\Support\MobileCache;
use Symfony\Component\HttpFoundation\Response;

class AzkarCategoriesController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $categories = AzkarCategory::orderBy('order')->paginate(9);
        return response()->json([
            'status' => 'success',
            'data' => $categories
        ], Response::HTTP_OK);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreAzkarCategoryRequest $request)
    {
        try {
            $category = AzkarCategory::create($request->validated());

            $this->flushAzkarCaches();

            return response()->json([
                'status' => 'success',
                'data' => $category
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
    public function show($category_id)
    {
        $category = AzkarCategory::findOrFail($category_id);
        return response()->json([
            'status' => 'success',
            'data' => $category
        ], Response::HTTP_OK);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateAzkarCategoryRequest $request, $category_id)
    {
        try {
            $category = AzkarCategory::findOrFail($category_id);
            $category->update($request->validated());

            $this->flushAzkarCaches();

            return response()->json([
                'status' => 'success',
                'data' => $category
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
    public function destroy($category_id)
    {
        $category = AzkarCategory::findOrFail($category_id);
        $category->delete();

        $this->flushAzkarCaches();

        return response()->json([
            'status' => 'success',
            'data' => $category
        ], Response::HTTP_OK);
    }

    /**
     * Mutating a category changes the categorized listing and the eager-loaded
     * azkarCategory relation on the all/by-category reads, so flush those keys.
     */
    private function flushAzkarCaches(): void
    {
        MobileCache::flushGlobal(MobileCache::AZKAR_CATEGORIES);
        MobileCache::flushGlobal(MobileCache::AZKAR_CATEGORIZED);
        MobileCache::flushGlobal(MobileCache::AZKAR_ALL);
    }
}
