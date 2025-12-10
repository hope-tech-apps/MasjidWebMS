<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Models\Masjid;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
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

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, $masjid_id)
    {
        try {

            $masjid = Masjid::findOrFail($masjid_id);

            $validator = Validator::make($request->all(), [
                'title' => 'required|string',
                'description' => 'required|string',
                'text' => 'required|string',
                'image' => 'required|image|mimes:jpeg,png,jpg,gif,svg',
                'icon' => 'required|image|mimes:png,svg,ico,bmb'
            ]);

            if ($validator->fails()) {

                return response()->json([
                    'status' => 'failed',
                    'data' => $validator->errors()
                ], Response::HTTP_INTERNAL_SERVER_ERROR);

            } else if ($validator->passes()) {

                $serviceInputs = $request->only(['title', 'description', 'text']);
                $serviceInputs['masjid_id'] = $masjid->id;

                $service = Service::create($serviceInputs);
                if ($request->hasFile('image')) {
                    $service->addMediaFromRequest('image')->toMediaCollection('services');
                }
                if ($request->hasFile('icon')) {
                    $service->addMediaFromRequest('icon')->toMediaCollection('servicesIcons');
                }

                return response()->json([
                    'status' => 'success',
                    'data' => $service->load('image', 'icon')
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
    public function show($masjid_id, $service_id)
    {
        $service = Service::with('image', 'icon')->findOrFail($service_id);
        return response()->json([
            'status' => 'success',
            'data' => $service
        ], Response::HTTP_OK);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $masjid_id, $service_id)
    {
        try {

            $masjid = Masjid::findOrFail($masjid_id);
            $service = Service::findOrFail($service_id);

            $validator = Validator::make($request->all(), [
                'title' => 'required|string',
                'description' => 'required|string',
                'text' => 'required|string',
                'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg',
                'icon' => 'nullable|image|mimes:png,gif,svg,ico,icns,bmb'
            ]);

            if ($validator->fails()) {

                return response()->json([
                    'status' => 'failed',
                    'data' => $validator->errors()
                ], Response::HTTP_INTERNAL_SERVER_ERROR);

            } else if ($validator->passes()) {

                $serviceInputs = $request->only(['title', 'description', 'text']);
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

                return response()->json([
                    'status' => 'success',
                    'data' => $service->load('image', 'icon')
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
    public function destroy($masjid_id, $service_id)
    {
        $service = Service::findOrFail($service_id);
        $service->forceDelete();
        return response()->json([
            'status' => 'success',
            'data' => $service
        ], Response::HTTP_OK);
    }

    public function moveToTrash($masjid_id, $service_id)
    {
        $service = Service::findOrFail($service_id);
        $service->delete();
        return response()->json([
            'status' => 'success',
            'data' => $service
        ], Response::HTTP_OK);
    }
}
