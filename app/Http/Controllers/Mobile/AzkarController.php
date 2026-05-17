<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Azkar;
use App\Models\AzkarCategory;
use App\Support\MobileCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class AzkarController extends Controller
{
    public function index(Request $request)
    {
        try {
            $request->validate(['category_id' => 'nullable|numeric|exists:azkar_categories,id']);

            if ($request->has('category_id')) {
                $categoryId = (int) $request->input('category_id');
                $azkar = Cache::remember(
                    MobileCache::globalKey(MobileCache::AZKAR_BY_CATEGORY, $categoryId),
                    MobileCache::TTL_LONG,
                    fn() => Azkar::where('azkar_category_id', $categoryId)->with('azkarCategory')->get()
                );
            } else {
                $azkar = Cache::remember(
                    MobileCache::globalKey(MobileCache::AZKAR_ALL),
                    MobileCache::TTL_LONG,
                    fn() => Azkar::with('azkarCategory')->get()
                );
            }

            return response()->json([
                'status' => 'success',
                'data' => $azkar
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => \App\Support\Errors::publicMessage($e)
            ]);
        }
    }

    public function azkarCategorized()
    {
        $azkarCategories = Cache::remember(
            MobileCache::globalKey(MobileCache::AZKAR_CATEGORIZED),
            MobileCache::TTL_LONG,
            fn() => AzkarCategory::with('azkar')->get()
        );

        return response()->json([
            'status' => 'success',
            'data' => $azkarCategories
        ]);
    }
}
