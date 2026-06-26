<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ContactReasons\StoreContactReasonRequest;
use App\Http\Requests\Admin\ContactReasons\UpdateContactReasonRequest;
use App\Models\ContactReason;
use App\Models\Masjid;
use App\Support\MobileCache;
use Symfony\Component\HttpFoundation\Response;

class ContactReasonsController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index($masjid_id)
    {
        $masjid = Masjid::findOrFail($masjid_id);
        $contactReasons = ContactReason::where('masjid_id', $masjid->id)
            ->orderBy('order')
            ->paginate(9);

        return response()->json([
            'status' => 'success',
            'data' => $contactReasons
        ], Response::HTTP_OK);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreContactReasonRequest $request, $masjid_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);

            $contactReasonInputs = $request->safe()->only(['name', 'is_active', 'order']);
            $contactReasonInputs['masjid_id'] = $masjid->id;

            $contactReason = ContactReason::create($contactReasonInputs);

            MobileCache::flushContactReasons((int) $masjid_id);

            return response()->json([
                'status' => 'success',
                'data' => $contactReason
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
    public function show($masjid_id, $contact_reason_id)
    {
        $contactReason = ContactReason::findOrFail($contact_reason_id);

        return response()->json([
            'status' => 'success',
            'data' => $contactReason
        ], Response::HTTP_OK);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateContactReasonRequest $request, $masjid_id, $contact_reason_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);
            $contactReason = ContactReason::findOrFail($contact_reason_id);

            $contactReasonInputs = $request->safe()->only(['name', 'is_active', 'order']);
            $contactReasonInputs['masjid_id'] = $masjid->id;

            $contactReason->update($contactReasonInputs);

            MobileCache::flushContactReasons((int) $masjid_id);

            return response()->json([
                'status' => 'success',
                'data' => $contactReason
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
    public function destroy($masjid_id, $contact_reason_id)
    {
        $contactReason = ContactReason::findOrFail($contact_reason_id);
        $contactReason->forceDelete();

        MobileCache::flushContactReasons((int) $masjid_id);

        return response()->json([
            'status' => 'success',
            'data' => $contactReason
        ], Response::HTTP_OK);
    }
}
