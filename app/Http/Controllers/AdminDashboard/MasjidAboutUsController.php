<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Models\Masjid;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

class MasjidAboutUsController extends Controller
{
    public function index($masjid_id)
    {
        $masjid = Masjid::findOrFail($masjid_id);
        $about = $masjid->masjidAbout;
        if($about) {
            return response()->json([
                'status' => 'success',
                'data' => $about->load('aboutImage', 'missionIcon', 'visionIcon')
            ], Response::HTTP_OK);
        } else {
            return response()->json([
                'status' => 'success',
                'data' => []
            ], Response::HTTP_NOT_FOUND);
        }
    }

    public function save(Request $request, $masjid_id)
    {

        try {

            $masjid = Masjid::findOrFail($masjid_id);
            $about = $masjid->masjidAbout;
            $validationRules = [
                'about' => 'required|string',
                'mission' => 'required|string',
                'vision' => 'required|string',
                'about_image' => 'required|image|mimes:jpeg,png,jpg,gif,svg',
                'mission_icon' => 'required|image|mimes:png,svg,ico,bmb',
                'vision_icon' => 'required|image|mimes:png,svg,ico,bmb'
            ];

            if($about) {
                $validationRules = [
                    'about' => 'required|string',
                    'mission' => 'required|string',
                    'vision' => 'required|string',
                    'about_image' => 'image|mimes:jpeg,png,jpg,gif,svg',
                    'mission_icon' => 'image|mimes:png,svg,ico,bmb',
                    'vision_icon' => 'image|mimes:png,svg,ico,bmb'
                ];
            }

            $validator = Validator::make($request->all(), $validationRules);

            if($validator->fails()) {
                return response()->json([
                    'status' => 'failed',
                    'data' => $validator->errors()
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            } else if($validator->passes()) {

                $aboutInputs = $request->only(['about', 'mission', 'vision']);
                $aboutInputs['masjid_id'] = $masjid->id;

                if($about) {
                    $about->update($aboutInputs);
                } else {
                    $about = $masjid->masjidAbout()->create($aboutInputs);
                }

                if($request->hasFile('about_image')) {
                    $about->clearMediaCollection('aboutImages');
                    $about->addMediaFromRequest('about_image')->toMediaCollection('aboutImages');
                }
                if($request->hasFile('mission_icon')) {
                    $about->clearMediaCollection('missionIcons');
                    $about->addMediaFromRequest('mission_icon')->toMediaCollection('missionIcons');
                }
                if($request->hasFile('vision_icon')) {
                    $about->clearMediaCollection('visionIcons');
                    $about->addMediaFromRequest('vision_icon')->toMediaCollection('visionIcons');
                }

                return response()->json([
                    'status' => 'success',
                    'data' => $about->load('aboutImage', 'missionIcon', 'visionIcon')
                ], Response::HTTP_OK);
            }

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
        
}
