<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Azkar;
use App\Models\AzkarCategory;
use Illuminate\Http\Request;

class AzkarController extends Controller
{
    public function index(Request $request)
    {
        try {
            $request->validate(['category_id' => 'nullable|numeric|exists:azkar_categories,id']);
            if ($request->has('category_id')) {
                $azkar = Azkar::where('azkar_category_id', $request['category_id'])->with('azkarCategory')->get();
            } else {
                $azkar = Azkar::with('azkarCategory')->get();
            }
            return response()->json([
                'status' => 'success',
                'data' => $azkar
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }

    public function azkarCategorized()
    {
        $azkarCategories = AzkarCategory::with('azkar')->get();
        return response()->json([
            'status' => 'success',
            'data' => $azkarCategories
        ]);
    }
}
