<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MasjidAbout\SaveMasjidAboutRequest;
use App\Models\Masjid;
use App\Support\MobileCache;
use Symfony\Component\HttpFoundation\Response;

class MasjidAboutUsController extends Controller
{
    public function index($masjid_id)
    {
        $masjid = Masjid::findOrFail($masjid_id);
        $about = $masjid->masjidAbout;
        if ($about) {
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

    public function save(SaveMasjidAboutRequest $request, $masjid_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);
            $about = $masjid->masjidAbout;

            $aboutInputs = $request->safe()->only(['about', 'mission', 'vision']);
            $aboutInputs['masjid_id'] = $masjid->id;

            if ($about) {
                $about->update($aboutInputs);
            } else {
                $about = $masjid->masjidAbout()->create($aboutInputs);
            }

            if ($request->hasFile('about_image')) {
                $about->clearMediaCollection('aboutImages');
                $about->addMediaFromRequest('about_image')->toMediaCollection('aboutImages');
            }
            if ($request->hasFile('mission_icon')) {
                $about->clearMediaCollection('missionIcons');
                $about->addMediaFromRequest('mission_icon')->toMediaCollection('missionIcons');
            }
            if ($request->hasFile('vision_icon')) {
                $about->clearMediaCollection('visionIcons');
                $about->addMediaFromRequest('vision_icon')->toMediaCollection('visionIcons');
            }

            // Flushes mobile (about, show) AND web V1 (/v1/settings, /v1/home, and every
            // cached page — the about_us + mission_vision sections bind to MasjidAbout
            // via SectionContentBinder at read time). flushAbout() handles all of it.
            MobileCache::flushAbout((int) $masjid_id);

            return response()->json([
                'status' => 'success',
                'data' => $about->load('aboutImage', 'missionIcon', 'visionIcon')
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => \App\Support\Errors::publicMessage($e)
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
