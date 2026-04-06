<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Masjid;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

class AnnouncementsController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index($masjid_id)
    {
        $masjid = Masjid::findOrFail($masjid_id);
        $announcements = Announcement::where('masjid_id', $masjid->id)->with('image')->paginate(9);
        return response()->json([
            'status' => 'success',
            'data' => $announcements
        ], Response::HTTP_OK);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, $masjid_id)
    {
        try {

            $masjid = Masjid::findOrFail($masjid_id);

            $validator = Validator::make($request->all(), [
                'title' => 'required|string',
                'details' => 'required|string',
                'text' => 'required|string',
                'start_date' => 'required|date_format:Y-m-d',
                'end_date' => 'required|date_format:Y-m-d|after:start_date',
                'image' => 'required|image|mimes:jpeg,png,jpg,gif,svg|max:25600'
            ]);

            if ($validator->fails()) {

                return response()->json([
                    'status' => 'failed',
                    'data' => $validator->errors()
                ], Response::HTTP_INTERNAL_SERVER_ERROR);

            } else if ($validator->passes()) {

                $announcementInputs = $request->only(['title', 'details', 'text', 'start_date', 'end_date']);
                $announcementInputs['masjid_id'] = $masjid->id;

                $announcement = Announcement::create($announcementInputs);
                if ($request->hasFile('image')) {
                    $announcement->addMediaFromRequest('image')->toMediaCollection('announcements');
                }

                return response()->json([
                    'status' => 'success',
                    'data' => $announcement->load('image')
                ], Response::HTTP_OK);
            }
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'success',
                'data' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show($masjid_id, $announcement_id)
    {
        // Get Announcement through Masjid
        // $masjid = Masjid::findOrFail($masjid_id);
        // $announcement = $masjid->announcements->findOrFail($announcement_id)->load('image');

        // Get Announcement by ID
        $announcement = Announcement::with('image')->findOrFail($announcement_id);
        return response()->json([
            'status' => 'success',
            'data' => $announcement
        ], Response::HTTP_OK);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $masjid_id, $announcement_id)
    {
        try {

            $announcement = Announcement::findOrFail($announcement_id);

            $validator = Validator::make($request->all(), [
                'title' => 'required|string',
                'details' => 'required|string',
                'text' => 'required|string',
                'start_date' => 'required|date_format:Y-m-d',
                'end_date' => 'required|date_format:Y-m-d|after:start_date',
                'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:25600'
            ]);

            if ($validator->fails()) {

                return response()->json([
                    'status' => 'failed',
                    'data' => $validator->errors()
                ], Response::HTTP_INTERNAL_SERVER_ERROR);

            } else if ($validator->passes()) {

                $announcementInputs = $request->only(['title', 'details', 'text', 'start_date', 'end_date']);

                $announcement->update($announcementInputs);

                if ($request->hasFile('image')) {
                    $announcement->clearMediaCollection('announcements');
                    $announcement->addMediaFromRequest('image')->toMediaCollection('announcements');
                }

                return response()->json([
                    'status' => 'success',
                    'data' => $announcement->load('image')
                ], Response::HTTP_OK);

            }
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'success',
                'data' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($masjid_id, $announcement_id)
    {
        //
        $announcement = Announcement::findOrFail($announcement_id);
        $announcement->forceDelete();
        return response()->json([
            'status' => 'success',
            'data' => $announcement
        ], Response::HTTP_OK);
    }

    public function moveToTrash($masjid_id, $announcement_id)
    {
        //
        $announcement = Announcement::findOrFail($announcement_id);
        $announcement->delete();
        return response()->json([
            'status' => 'success',
            'data' => $announcement
        ], Response::HTTP_OK);
    }
}
