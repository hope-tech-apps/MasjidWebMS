<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Services\StoreServiceRequest;
use App\Http\Requests\Admin\Services\UpdateServiceRequest;
use App\Models\Masjid;
use App\Models\Service;
use App\Support\MobileCache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ServicesController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index($masjid_id)
    {
        $masjid = Masjid::findOrFail($masjid_id);
        $services = Service::where('masjid_id', $masjid->id)->with('image', 'icon')->paginate(9);
        return response()->json([
            'status' => 'success',
            'data' => $services
        ], Response::HTTP_OK);
    }

    /** List only this organisation's archived services, newest archive first. */
    public function archived($masjid_id)
    {
        $services = Service::onlyTrashed()->where('masjid_id', $masjid_id)
            ->with('image', 'icon')->orderByDesc('deleted_at')->orderBy('id')->paginate(9);

        return response()->json(['status' => 'success', 'data' => $services]);
    }

    /** Restore the same row and media once, deciding from a fresh locking read. */
    public function restore($masjid_id, $service_id)
    {
        [$service, $restored] = DB::transaction(function () use ($masjid_id, $service_id) {
            $service = Service::withTrashed()->where('masjid_id', $masjid_id)
                ->lockForUpdate()->findOrFail($service_id);
            $restored = $service->trashed();
            if ($restored) {
                // Restore only deleted_at: retain the content's original timestamps and position.
                Service::withoutTimestamps(fn () => $service->restore());
            }

            return [$service, $restored];
        });

        if ($restored) {
            MobileCache::flushMasjid((int) $masjid_id, MobileCache::SERVICES);
        }

        return response()->json(['status' => 'success', 'data' => $service->load('image', 'icon')]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreServiceRequest $request, $masjid_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);

            $serviceInputs = $request->safe()->only(['title', 'summary', 'description', 'text']);
            $serviceInputs['masjid_id'] = $masjid->id;

            $service = Service::create($serviceInputs);
            if ($request->hasFile('image')) {
                $service->addMediaFromRequest('image')->toMediaCollection('services');
            }
            if ($request->hasFile('icon')) {
                $service->addMediaFromRequest('icon')->toMediaCollection('servicesIcons');
            }

            MobileCache::flushMasjid((int) $masjid_id, MobileCache::SERVICES);

            return response()->json([
                'status' => 'success',
                'data' => $service->load('image', 'icon')
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
    public function show($masjid_id, $service_id)
    {
        $service = Service::with('image', 'icon')->where('masjid_id', $masjid_id)->findOrFail($service_id);
        return response()->json([
            'status' => 'success',
            'data' => $service
        ], Response::HTTP_OK);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateServiceRequest $request, $masjid_id, $service_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);
            $service = Service::where('masjid_id', $masjid_id)->findOrFail($service_id);

            $serviceInputs = $request->safe()->only(['title', 'summary', 'description', 'text']);
            $serviceInputs['masjid_id'] = $masjid->id;

            $service->update($serviceInputs);

            if ($request->hasFile('image')) {
                $service->clearMediaCollection('services');
                $service->addMediaFromRequest('image')->toMediaCollection('services');
            }
            if ($request->hasFile('icon')) {
                $service->clearMediaCollection('servicesIcons');
                $service->addMediaFromRequest('icon')->toMediaCollection('servicesIcons');
            }

            MobileCache::flushMasjid((int) $masjid_id, MobileCache::SERVICES);

            return response()->json([
                'status' => 'success',
                'data' => $service->load('image', 'icon')
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
    public function destroy($masjid_id, $service_id)
    {
        $service = Service::where('masjid_id', $masjid_id)->findOrFail($service_id);
        $service->forceDelete();

        MobileCache::flushMasjid((int) $masjid_id, MobileCache::SERVICES);

        return response()->json([
            'status' => 'success',
            'data' => $service
        ], Response::HTTP_OK);
    }

    public function moveToTrash($masjid_id, $service_id)
    {
        $service = Service::where('masjid_id', $masjid_id)->findOrFail($service_id);
        $service->delete();

        MobileCache::flushMasjid((int) $masjid_id, MobileCache::SERVICES);

        return response()->json([
            'status' => 'success',
            'data' => $service
        ], Response::HTTP_OK);
    }
}
