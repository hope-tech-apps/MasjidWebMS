<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Models\Azkar;
use App\Models\AzkarCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
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
    public function store(Request $request)
    {
        try {
            
            $validator = Validator::make($request->all(), [
                'azkar_category_id' => 'nullable|integer|min:1|exists:azkar_categories,id',
                'title' => 'required|array',
                'title.*' => 'required|string',
                'text' => 'required|array',
                'text.*' => 'required|string',
                'bless' => 'nullable|array',
                'bless.*' => 'nullable|string',
                'pronunciation' => 'required|string',
                'frequency' => 'nullable|integer|min:0',
                'reference' => 'nullable|string'
            ]);

            if($validator->fails()) {
                return response()->json([
                    'status' => 'failed',
                    'data' => $validator->errors()
                ], Response::HTTP_BAD_REQUEST);
            }

            if($validator->passes()) {
                $zikr = Azkar::create($request->only(
                    'azkar_category_id', 'title', 'text', 'bless', 'pronunciation', 'frequency', 'reference'
                ));
                return response()->json([
                    'status' => 'success',
                    'data' => $zikr
                ], Response::HTTP_OK);
            }

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'data' => $e->getMessage()
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
    public function update(Request $request, $zikr_id)
    {
        try {

            $zikr = Azkar::findOrFail($zikr_id);
            
            $validator = Validator::make($request->all(), [
                'azkar_category_id' => 'nullable|integer|min:1|exists:azkar_categories,id',
                'title' => 'required|array',
                'title.*' => 'required|string',
                'text' => 'required|array',
                'text.*' => 'required|string',
                'bless' => 'nullable|array',
                'bless.*' => 'nullable|string',
                'pronunciation' => 'required|string',
                'frequency' => 'nullable|integer|min:0',
                'reference' => 'nullable|string'
            ]);

            if($validator->fails()) {
                return response()->json([
                    'status' => 'failed',
                    'data' => $validator->errors()
                ], Response::HTTP_BAD_REQUEST);
            }

            if($validator->passes()) {
                $zikr->update($request->only(
                    'azkar_category_id', 'title', 'text', 'bless', 'pronunciation', 'frequency', 'reference'
                ));
                return response()->json([
                    'status' => 'success',
                    'data' => $zikr
                ], Response::HTTP_OK);
            }

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'data' => $e->getMessage()
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
        return response()->json([
            'status' => 'success',
            'data' => $zikr
        ], Response::HTTP_OK);
    }

}
