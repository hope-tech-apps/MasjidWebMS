<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Models\Tasbih;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
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
    public function store(Request $request)
    {
        try {
            
            $validator = Validator::make($request->all(), [
                'text' => 'required|array',
                'text.*' => 'required|string',
                'pronunciation' => 'required|string',
                'reference' => 'nullable|string'
            ]);

            if($validator->fails()) {
                return response()->json([
                    'status' => 'failed',
                    'data' => $validator->errors()
                ], Response::HTTP_BAD_REQUEST);
            }

            if($validator->passes()) {
                $tasbih = Tasbih::create($request->only('text', 'pronunciation', 'reference'));
                return response()->json([
                    'status' => 'success',
                    'data' => $tasbih
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
    public function update(Request $request, $tasbih_id)
    {
        try {

            $tasbih = Tasbih::findOrFail($tasbih_id);
            
            $validator = Validator::make($request->all(), [
                'text' => 'required|array',
                'text.*' => 'required|string',
                'pronunciation' => 'required|string',
                'reference' => 'nullable|string'
            ]);

            if($validator->fails()) {
                return response()->json([
                    'status' => 'failed',
                    'data' => $validator->errors()
                ], Response::HTTP_BAD_REQUEST);
            }

            if($validator->passes()) {
                $tasbih->update($request->only('text', 'pronunciation', 'reference'));
                return response()->json([
                    'status' => 'success',
                    'data' => $tasbih
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
    public function destroy($tasbih_id)
    {
        $tasbih = Tasbih::findOrFail($tasbih_id);
        $tasbih->delete();
        return response()->json([
            'status' => 'success',
            'data' => $tasbih
        ], Response::HTTP_OK);
    }

}
