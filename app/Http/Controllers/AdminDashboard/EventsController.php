<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Masjid;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
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
    public function store(Request $request, $masjid_id)
    {
        try {

            $masjid = Masjid::findOrFail($masjid_id);

            $validator = Validator::make($request->all(), [
                'title' => 'required|string',
                'details' => 'required|string',
                'place' => 'required|string',
                'start' => 'required|date_format:Y-m-d H:i',
                'end' => 'required|date_format:Y-m-d H:i|after:start',
                'link' => 'string'
            ]);

            if ($validator->fails()) {

                return response()->json([
                    'status' => 'failed',
                    'data' => $validator->errors()
                ], Response::HTTP_INTERNAL_SERVER_ERROR);

            } else if ($validator->passes()) {

                $eventInputs = $request->only(['title', 'details', 'place', 'start', 'end', 'link']);
                $eventInputs['masjid_id'] = $masjid->id;

                $event = Event::create($eventInputs);

                return response()->json([
                    'status' => 'success',
                    'data' => $event
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
    public function show($masjid_id, $event_id)
    {
        // Get Event through Masjid
        // $masjid = Masjid::findOrFail($masjid_id);
        // $event = $masjid->events->findOrFail($event_id);

        // Get Event by ID
        $event = Event::findOrFail($event_id);
        return response()->json([
            'status' => 'success',
            'data' => $event
        ], Response::HTTP_OK);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $masjid_id, $event_id)
    {
        try {

            $event = Event::findOrFail($event_id);

            $validator = Validator::make($request->all(), [
                'title' => 'required|string',
                'details' => 'required|string',
                'place' => 'required|string',
                'start' => 'required|date_format:Y-m-d H:i',
                'end' => 'required|date_format:Y-m-d H:i|after:start',
                'link' => 'string'
            ]);

            if ($validator->fails()) {

                return response()->json([
                    'status' => 'failed',
                    'data' => $validator->errors()
                ], Response::HTTP_INTERNAL_SERVER_ERROR);

            } else if ($validator->passes()) {

                $eventInputs = $request->only(['title', 'details', 'place', 'start', 'end', 'link']);

                $event->update($eventInputs);

                return response()->json([
                    'status' => 'success',
                    'data' => $event
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
    public function destroy($masjid_id, $event_id)
    {
        //
        $event = Event::findOrFail($event_id);
        $event->forceDelete();
        return response()->json([
            'status' => 'success',
            'data' => $event
        ], Response::HTTP_OK);
    }

}