<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\HadithCategories\StoreHadithCategoryRequest;
use App\Http\Requests\Admin\HadithCategories\UpdateHadithCategoryRequest;
use App\Models\HadithCategory;
use App\Support\MobileCache;
use Symfony\Component\HttpFoundation\Response;

class HadithCategoriesController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $categories = HadithCategory::orderBy('order')->paginate(9);
        return response()->json([
            'status' => 'success',
            'data' => $categories
        ], Response::HTTP_OK);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreHadithCategoryRequest $request)
    {
        try {
            $category = HadithCategory::create($request->validated());

            MobileCache::flushGlobal(MobileCache::HADITH_CATEGORIES);

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
        $category = HadithCategory::findOrFail($category_id);
        return response()->json([
            'status' => 'success',
            'data' => $category
        ], Response::HTTP_OK);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateHadithCategoryRequest $request, $category_id)
    {
        try {
            $category = HadithCategory::findOrFail($category_id);
            $category->update($request->validated());

            MobileCache::flushGlobal(MobileCache::HADITH_CATEGORIES);

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
        $category = HadithCategory::findOrFail($category_id);
        $category->delete();

        MobileCache::flushGlobal(MobileCache::HADITH_CATEGORIES);

        return response()->json([
            'status' => 'success',
            'data' => $category
        ], Response::HTTP_OK);
    }
}
