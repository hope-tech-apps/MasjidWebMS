<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Masjids\StoreMasjidRequest;
use App\Http\Requests\Admin\Masjids\UpdateMasjidRequest;
use App\Models\IqamaTimeSetting;
use App\Models\Masjid;
use App\Models\MasjidMobileAppFeature;
use App\Models\MobileAppFeature;
use App\Models\PrayerCalculationSetting;
use App\Support\MobileCache;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class MasjidsController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $masjids = Masjid::with('logo', 'footer_logo', 'admin.avatar', 'country', 'city')->get();
        return response()->json([
            'status' => 'success',
            'data' => $masjids
        ], Response::HTTP_OK);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreMasjidRequest $request)
    {
        try {
            $payload = $request->safe()->only([
                'name', 'email', 'phone', 'longitude', 'latitude',
                'address', 'user_id', 'country_id', 'city_id',
            ]);
            $payload['created_by'] = Auth::id();

            $masjid = Masjid::create($payload);

            if ($masjid) {
                // Store logo
                $masjid->addMediaFromRequest('logo')->toMediaCollection('logos');

                // Store footer logo
                $masjid->addMediaFromRequest('footer_logo')->toMediaCollection('footer_logos');

                // Assign masjid mobile app features
                $features = MobileAppFeature::all();
                foreach ($features as $feature) {
                    MasjidMobileAppFeature::create([
                        'masjid_id' => $masjid->id,
                        'feature_id' => $feature->id,
                        'is_available' => true,
                    ]);
                }

                // Assign masjid Iqama time settings
                IqamaTimeSetting::create([
                    'masjid_id' => $masjid->id,
                    'fajr' => 20,
                    'dhuhr' => 10,
                    'asr' => 10,
                    'maghrib' => 10,
                    'isha' => 10,
                ]);

                // Assign masjid Prayer Calculation settings
                PrayerCalculationSetting::create([
                    'masjid_id' => $masjid->id,
                    'method' => 'MoonsightingCommittee',
                    'madhab' => 'Shafi',
                    'high_latitude_rule' => 'MiddleOfTheNight',
                ]);
            }

            MobileCache::flushGlobal(MobileCache::MASJIDS_LIST);

            return response()->json([
                'status' => 'success',
                'data' => $masjid
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'data' => \App\Support\Errors::publicMessage($e)
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $masjid = Masjid::with('admin.avatar', 'logo', 'footer_logo', 'country', 'city')->findOrFail($id);
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
    public function update(UpdateMasjidRequest $request, string $masjid_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);

            $payload = $request->safe()->only([
                'name', 'email', 'phone', 'longitude', 'latitude',
                'address', 'user_id', 'country_id', 'city_id',
            ]);
            $payload['user_id'] = $payload['user_id'] ?? null;
            $payload['updated_by'] = Auth::id();

            $masjid->update($payload);

            if ($request->hasFile('logo')) {
                $masjid->clearMediaCollection('logos');
                $masjid->addMediaFromRequest('logo')->toMediaCollection('logos');
            }

            if ($request->hasFile('footer_logo')) {
                $masjid->clearMediaCollection('footer_logos');
                $masjid->addMediaFromRequest('footer_logo')->toMediaCollection('footer_logos');
            }

            MobileCache::flushMasjidAll((int) $masjid_id);
            MobileCache::flushGlobal(MobileCache::MASJIDS_LIST);

            return response()->json([
                'status' => 'success',
                'data' => $masjid
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'data' => \App\Support\Errors::publicMessage($e)
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

        MobileCache::flushMasjidAll((int) $masjid_id);
        MobileCache::flushGlobal(MobileCache::MASJIDS_LIST);

        return response()->json([
            'status' => 'success',
            'data' => $masjid
        ], Response::HTTP_OK);
    }

    public function moveToTrash($masjid_id)
    {
        $masjid = Masjid::findOrFail($masjid_id);
        $masjid->deleted_by = Auth::id();
        $masjid->save();
        $masjid->delete();

        MobileCache::flushMasjidAll((int) $masjid_id);
        MobileCache::flushGlobal(MobileCache::MASJIDS_LIST);

        return response()->json([
            'status' => 'success',
            'data' => $masjid
        ], Response::HTTP_OK);
    }
}
