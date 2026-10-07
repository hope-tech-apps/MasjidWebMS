<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Announcements\StoreAnnouncementRequest;
use App\Http\Requests\Admin\Announcements\UpdateAnnouncementRequest;
use App\Models\Announcement;
use App\Models\Masjid;
use App\Support\MobileCache;
use Illuminate\Support\Facades\DB;
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

    /** List only this organisation's archived announcements, newest archive first. */
    public function archived($masjid_id)
    {
        $announcements = Announcement::onlyTrashed()->where('masjid_id', $masjid_id)
            ->with('image')->orderByDesc('deleted_at')->orderBy('id')->paginate(9);

        return response()->json(['status' => 'success', 'data' => $announcements]);
    }

    /** Restore the same row and dates once; no broadcast is dispatched again. */
    public function restore($masjid_id, $announcement_id)
    {
        [$announcement, $restored] = DB::transaction(function () use ($masjid_id, $announcement_id) {
            $announcement = Announcement::withTrashed()->where('masjid_id', $masjid_id)
                ->lockForUpdate()->findOrFail($announcement_id);
            $restored = $announcement->trashed();
            if ($restored) {
                // Restore only deleted_at: retain the content's original timestamps and position.
                Announcement::withoutTimestamps(fn () => $announcement->restore());
            }

            return [$announcement, $restored];
        });

        if ($restored) {
            MobileCache::flushMasjid((int) $masjid_id, MobileCache::ANNOUNCEMENTS);
            // This endpoint also serves a date-filtered announcement fallback.
            MobileCache::flushMasjid((int) $masjid_id, MobileCache::SIGNAGE);
        }

        return response()->json(['status' => 'success', 'data' => $announcement->load('image')]);
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
        $announcement = Announcement::with('image')->where('masjid_id', $masjid_id)->findOrFail($announcement_id);
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
            $announcement = Announcement::where('masjid_id', $masjid_id)->findOrFail($announcement_id);

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
        $announcement = Announcement::where('masjid_id', $masjid_id)->findOrFail($announcement_id);
        $announcement->forceDelete();

        MobileCache::flushMasjid((int) $masjid_id, MobileCache::ANNOUNCEMENTS);

        return response()->json([
            'status' => 'success',
            'data' => $announcement
        ], Response::HTTP_OK);
    }

    public function moveToTrash($masjid_id, $announcement_id)
    {
        $announcement = Announcement::where('masjid_id', $masjid_id)->findOrFail($announcement_id);
        $announcement->delete();

        MobileCache::flushMasjid((int) $masjid_id, MobileCache::ANNOUNCEMENTS);

        return response()->json([
            'status' => 'success',
            'data' => $announcement
        ], Response::HTTP_OK);
    }
}
