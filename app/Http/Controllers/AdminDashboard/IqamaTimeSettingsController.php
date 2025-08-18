<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Models\Masjid;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

class IqamaTimeSettingsController extends Controller
{
    public function index($masjid_id)
    {
        $masjid = Masjid::findOrFail($masjid_id);
        $iqamaTimes = $masjid->iqamaTimeSettings;
        return response()->json([
            'status' => 'success',
            'data' => $iqamaTimes
        ], Response::HTTP_OK);
    }

    public function save(Request $request, $masjid_id)
    {
        try {

            $masjid = Masjid::findOrFail($masjid_id);

            $validator = Validator::make($request->all(), [
                'fajr' => 'required|integer|min:1',
                'dhuhr' => 'required|integer|min:1',
                'asr' => 'required|integer|min:1',
                'maghrib' => 'required|integer|min:1',
                'isha' => 'required|integer|min:1'
            ]);

            if($validator->fails()) {
                return response()->json([
                    'status' => 'failed',
                    'data' => $validator->errors()
                ], Response::HTTP_BAD_REQUEST);
            } else if($validator->passes()) {
                $iqamaTime = $masjid->iqamaTimeSettings;
                if($iqamaTime) {
                    $iqamaTime->update([
                        'fajr' => $request['fajr'],
                        'dhuhr' => $request['dhuhr'],
                        'asr' => $request['asr'],
                        'maghrib' => $request['maghrib'],
                        'isha' => $request['isha']
                    ]);
                } else {
                    $iqamaTime = $masjid->iqamaTimeSettings()->create([
                        'masjid_id' => $masjid->id,
                        'fajr' => $request['fajr'],
                        'dhuhr' => $request['dhuhr'],
                        'asr' => $request['asr'],
                        'maghrib' => $request['maghrib'],
                        'isha' => $request['isha']
                    ]);
                }
                return response()->json([
                    'status' => 'success',
                    'data' => $iqamaTime
                ], Response::HTTP_OK);
            }

        } catch(\Exception $e) {
            return response()->json([
                'status' => 'error',
                'data' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
