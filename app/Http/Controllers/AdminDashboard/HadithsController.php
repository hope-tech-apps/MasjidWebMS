<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Enums\HadithStrength;
use App\Http\Controllers\Controller;
use App\Models\Hadith;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Validation\Rule;

class HadithsController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $hadithList = Hadith::paginate(9);
        return response()->json([
            'status' => 'success',
            'data' => $hadithList
        ], Response::HTTP_OK);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'title' => 'required|string',
                'isnad' => 'required|string',
                'matn' => 'required|string',
                'description' => 'required|string',
                'strength' => ['required', 'string', Rule::in(HadithStrength::getValues())],
                'muhaddith_ar' => 'required|string',
                'muhaddith_en' => 'required|string',
                'references' => 'required|array',
                'references.*' => 'array',
                'show_date' => ['required', 'date', 'unique:hadiths,show_date', function ($attribute, $value, $fail) {
                    $today = date('Y-m-d'); // Get today's date in 'Y-m-d' format
                    if ($value <= $today) {
                        $fail('The ' . $attribute . ' must be a date after today.');
                    }
                },]
            ]);

            if($validator->fails()) {
                return response()->json([
                    'status' => 'failed',
                    'data' => $validator->errors()
                ], Response::HTTP_BAD_REQUEST);
            }

            if($validator->passes()) {
                $hadith = Hadith::create([
                    'title' => $request->title,
                    'isnad' => $request->isnad,
                    'matn' => $request->matn,
                    'description' => $request->description,
                    'strength' => HadithStrength::from($request->strength)->toJson(),
                    'muhaddith' => [
                        'ar' => $request->muhaddith_ar,
                        'en' => $request->muhaddith_en
                    ],
                    'references' => $request->references,
                    'show_date' => $request->show_date
                ]);

                return response()->json([
                    'status' => 'success',
                    'data' => $hadith
                ], Response::HTTP_CREATED);
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
    public function show($hadith_id)
    {
        $hadith = Hadith::findOrFail($hadith_id);
        return response()->json([
            'status' => 'success',
            'data' => $hadith
        ], Response::HTTP_OK);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $hadith_id)
    {
        try {

            $hadith = Hadith::findOrFail($hadith_id);

            $validator = Validator::make($request->all(), [
                'title' => 'required|string',
                'isnad' => 'required|string',
                'matn' => 'required|string',
                'description' => 'required|string',
                'strength' => ['required', 'string', Rule::in(HadithStrength::getValues())],
                'muhaddith_ar' => 'required|string',
                'muhaddith_en' => 'required|string',
                'references' => 'required|array',
                'references.*' => 'array',
                'show_date' => 'required|date|unique:hadiths,show_date,' . $hadith_id
            ]);

            if($validator->fails()) {
                return response()->json([
                    'status' => 'failed',
                    'data' => $validator->errors()
                ], Response::HTTP_BAD_REQUEST);
            }

            if($validator->passes()) {

                $hadith->update([
                    'title' => $request->title,
                    'isnad' => $request->isnad,
                    'matn' => $request->matn,
                    'description' => $request->description,
                    'strength' => HadithStrength::from($request->strength)->toJson(),
                    'muhaddith' => [
                        'ar' => $request->muhaddith_ar,
                        'en' => $request->muhaddith_en
                    ],
                    'references' => $request->references,
                    'show_date' => $request->show_date
                ]);

                return response()->json([
                    'status' => 'success',
                    'data' => $hadith
                ], Response::HTTP_CREATED);

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
    public function destroy(string $hadith_id)
    {
        $hadith = Hadith::findOrFail($hadith_id);
        $hadith->delete();
        return response()->json([
            'status' => 'success',
            'data' => $hadith
        ], Response::HTTP_OK);
    }
    
}
