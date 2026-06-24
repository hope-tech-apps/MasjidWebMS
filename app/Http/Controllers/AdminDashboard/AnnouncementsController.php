<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Announcements\StoreAnnouncementRequest;
use App\Http\Requests\Admin\Announcements\UpdateAnnouncementRequest;
use App\Models\Announcement;
use App\Models\Masjid;
use App\Support\MobileCache;
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
    public function store(StoreAnnouncementRequest $request, $masjid_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);

            $announcementInputs = $request->safe()->only(['title', 'summary', 'details', 'text', 'start_date', 'end_date']);
            $announcementInputs['masjid_id'] = $masjid->id;

            $announcement = Announcement::create($announcementInputs);
            if ($request->hasFile('image')) {
                $announcement->addMediaFromRequest('image')->toMediaCollection('announcements');
            }

            MobileCache::flushMasjid((int) $masjid_id, MobileCache::ANNOUNCEMENTS);

            return response()->json([
                'status' => 'success',
                'data' => $announcement->load('image')
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'success',
                'data' => \App\Support\Errors::publicMessage($e)
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show($masjid_id, $announcement_id)
    {
        $announcement = Announcement::with('image')->findOrFail($announcement_id);
        return response()->json([
            'status' => 'success',
            'data' => $announcement
        ], Response::HTTP_OK);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateAnnouncementRequest $request, $masjid_id, $announcement_id)
    {
        try {
            $announcement = Announcement::findOrFail($announcement_id);

            $announcementInputs = $request->safe()->only(['title', 'summary', 'details', 'text', 'start_date', 'end_date']);
            $announcement->update($announcementInputs);

            if ($request->hasFile('image')) {
                $announcement->clearMediaCollection('announcements');
                $announcement->addMediaFromRequest('image')->toMediaCollection('announcements');
            }

            MobileCache::flushMasjid((int) $masjid_id, MobileCache::ANNOUNCEMENTS);

            return response()->json([
                'status' => 'success',
                'data' => $announcement->load('image')
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'success',
                'data' => \App\Support\Errors::publicMessage($e)
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($masjid_id, $announcement_id)
    {
        $announcement = Announcement::findOrFail($announcement_id);
        $announcement->forceDelete();

        MobileCache::flushMasjid((int) $masjid_id, MobileCache::ANNOUNCEMENTS);

        return response()->json([
            'status' => 'success',
            'data' => $announcement
        ], Response::HTTP_OK);
    }

    public function moveToTrash($masjid_id, $announcement_id)
    {
        $announcement = Announcement::findOrFail($announcement_id);
        $announcement->delete();

        MobileCache::flushMasjid((int) $masjid_id, MobileCache::ANNOUNCEMENTS);

        return response()->json([
            'status' => 'success',
            'data' => $announcement
        ], Response::HTTP_OK);
    }
}
