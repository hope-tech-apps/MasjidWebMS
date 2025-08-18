<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Models\IqamaTimeSetting;
use App\Models\Masjid;
use App\Models\MasjidMobileAppFeature;
use App\Models\MobileAppFeature;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

class MasjidsController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $masjids = Masjid::with('logo', 'admin.avatar', 'country', 'city')->get();
        return response()->json([
            'status' => 'success',
            'data' => $masjids
        ], Response::HTTP_OK);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        try {

            $validator = Validator::make($request->all(), [
                'name' => 'required|string',
                'email' => 'required|email|unique:masjids,email',
                'phone' => 'required|string|regex:/^\+?[0-9 ]+$/',
                'logo' => 'required|image|mimes:jpeg,png,jpg,gif,svg',
                'longitude' => 'required|numeric|min:-180|max:180',
                'latitude' => 'required|numeric|min:-90|max:90',
                'address' => 'required|string',
                'user_id' => ['nullable', 'exists:users,id', 'unique:masjids,user_id', function ($attribute, $value, $fail) {
                    $user = \App\Models\User::where('id', $value)
                        ->where('type', 'MasjidAdmin')
                        ->first();

                    if (!$user) {
                        $fail('The selected user is not of a Masjid Admin type.');
                    }
                }],
                'country_id' => 'required|exists:countries,id',
                'city_id' => ['required', 'exists:cities,id', function ($attribute, $value, $fail) use ($request) {
                    $city = \App\Models\City::where('id', $value)
                        ->where('country_id', $request['country_id'])
                        ->exists();
                    if (!$city) {
                        $fail('The selected city does not belong to the given country.');
                    }
                }],
                // 'created_by' => 'nullable|exists:users,id'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'data' => $validator->errors()
                ], Response::HTTP_BAD_REQUEST);
            }

            if ($validator->passes()) {

                $request['created_by'] = Auth::user()->id;
                $masjid = Masjid::create($request->only(
                    'name',
                    'email',
                    'phone',
                    'longitude',
                    'latitude',
                    'address',
                    'user_id',
                    'country_id',
                    'city_id',
                    'created_by'
                ));

                if ($masjid) {
                    // Store logo
                    $masjid->addMediaFromRequest('logo')->toMediaCollection('logos');

                    // Assign masjid mobile app features
                    $features = MobileAppFeature::all();
                    foreach ($features as $feature) {
                        MasjidMobileAppFeature::create([
                            'masjid_id' => $masjid->id,
                            'feature_id' => $feature->id,
                            'is_available' => true
                        ]);
                    }

                    // Assign masjid Iqama time settings
                    IqamaTimeSetting::create([
                        'masjid_id' => $masjid->id,
                        'fajr' => 20,
                        'dhuhr' => 10,
                        'asr' => 10,
                        'maghrib' => 10,
                        'isha' => 10
                    ]);
                }

                return response()->json([
                    'status' => 'success',
                    'data' => $masjid
                ], Response::HTTP_OK);
            }
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'data' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $masjid = Masjid::with('admin.avatar', 'logo', 'country', 'city')->findOrFail($id);
        return response()->json([
            'status' => 'success',
            'data' => $masjid
        ], Response::HTTP_OK);
    }

    public function gallery($masjid_id)
    {
        $masjid = Masjid::with('gallery')->findOrFail($masjid_id);
        return response()->json([
            'status' => 'success',
            'data' => $masjid
        ], Response::HTTP_OK);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $masjid_id)
    {
        try {

            $masjid = Masjid::findOrFail($masjid_id);

            $validator = Validator::make($request->all(), [
                'name' => 'required|string',
                'email' => 'required|email',
                'phone' => 'required|string|regex:/^\+?[0-9 ]+$/',
                'logo' => 'image|mimes:jpeg,png,jpg,gif,svg',
                'longitude' => 'required|numeric|min:-180|max:180',
                'latitude' => 'required|numeric|min:-90|max:90',
                'address' => 'required|string',
                'user_id' => ['nullable', 'exists:users,id', function ($attribute, $value, $fail) {
                    $user = \App\Models\User::where('id', $value)
                        ->where('type', 'MasjidAdmin')
                        ->first();

                    if (!$user) {
                        $fail('The selected user is not of a Masjid Admin type.');
                    }
                }],
                'country_id' => 'required|exists:countries,id',
                'city_id' => ['required', 'exists:cities,id', function ($attribute, $value, $fail) use ($request) {
                    $city = \App\Models\City::where('id', $value)
                        ->where('country_id', $request->input('country_id'))
                        ->exists();
                    if (!$city) {
                        $fail('The selected city does not belong to the given country.');
                    }
                }],
                // 'updated_by' => 'nullable|exists:users,id'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'data' => $validator->errors()
                ], Response::HTTP_BAD_REQUEST);
            }

            if ($validator->passes()) {

                $request['updated_by'] = Auth::user()->id;
                $request['user_id'] = $request['user_id'] ?? null;

                $masjid->update($request->only(
                    'name',
                    'email',
                    'phone',
                    'longitude',
                    'latitude',
                    'address',
                    'user_id',
                    'country_id',
                    'city_id',
                    'updated_by'
                ));

                if ($masjid && $request['logo']) {
                    $masjid->clearMediaCollection('logos');
                    $masjid->addMediaFromRequest('logo')->toMediaCollection('logos');
                }

                return response()->json([
                    'status' => 'success',
                    'data' => $masjid
                ], Response::HTTP_OK);
            }
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'data' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($masjid_id)
    {
        $masjid = Masjid::findOrFail($masjid_id);
        $masjid->forceDelete();
        return response()->json([
            'status' => 'success',
            'data' => $masjid
        ], Response::HTTP_OK);
    }

    public function moveToTrash($masjid_id)
    {
        $masjid = Masjid::findOrFail($masjid_id);
        $masjid->deleted_by = Auth::user()->id;
        $masjid->save();
        $masjid->delete();
        return response()->json([
            'status' => 'success',
            'data' => $masjid
        ], Response::HTTP_OK);
    }
}
