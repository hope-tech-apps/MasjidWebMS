<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Events\StoreEventRequest;
use App\Http\Requests\Admin\Events\UpdateEventRequest;
use App\Models\Event;
use App\Models\Masjid;
use App\Support\MobileCache;
use Symfony\Component\HttpFoundation\Response;

class EventsController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index($masjid_id)
    {
        $masjid = Masjid::findOrFail($masjid_id);
        $events = Event::where('masjid_id', $masjid->id)->paginate(9);
        return response()->json([
            'status' => 'success',
            'data' => $events
        ], Response::HTTP_OK);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreEventRequest $request, $masjid_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);

            $eventInputs = $request->safe()->only(['title', 'details', 'place', 'start', 'end', 'link']);
            $eventInputs['masjid_id'] = $masjid->id;

            $event = Event::create($eventInputs);

            MobileCache::flushMasjid((int) $masjid_id, MobileCache::EVENTS);

            return response()->json([
                'status' => 'success',
                'data' => $event
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
    public function show($masjid_id, $event_id)
    {
        $event = Event::findOrFail($event_id);
        return response()->json([
            'status' => 'success',
            'data' => $event
        ], Response::HTTP_OK);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateEventRequest $request, $masjid_id, $event_id)
    {
        try {
            $event = Event::findOrFail($event_id);

            $eventInputs = $request->safe()->only(['title', 'details', 'place', 'start', 'end', 'link']);
            $event->update($eventInputs);

            MobileCache::flushMasjid((int) $masjid_id, MobileCache::EVENTS);

            return response()->json([
                'status' => 'success',
                'data' => $event
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
    public function destroy($masjid_id, $event_id)
    {
        $event = Event::findOrFail($event_id);
        $event->forceDelete();

        MobileCache::flushMasjid((int) $masjid_id, MobileCache::EVENTS);

        return response()->json([
            'status' => 'success',
            'data' => $event
        ], Response::HTTP_OK);
    }
}
