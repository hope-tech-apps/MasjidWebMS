<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Models\Masjid;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

class JumaaSettingsController extends Controller
{
    public function index($masjid_id)
    {
        $masjid = Masjid::findOrFail($masjid_id);
        $jumaaSettings = $masjid->jumaaSettings;
        return response()->json([
            'status' => 'success',
            'data' => $jumaaSettings
        ], Response::HTTP_OK);
    }

    public function save(Request $request, $masjid_id)
    {
        try {

            $masjid = Masjid::findOrFail($masjid_id);

            $validator = Validator::make($request->all(), [
                'iqama' => 'date_format:H:i',
                'athans' => 'array',
                'athans.*' => 'date_format:H:i|before:iqama'
            ]);

            if($validator->fails()) {
                return response()->json([
                    'status' => 'failed',
                    'data' => $validator->errors()
                ], Response::HTTP_BAD_REQUEST);
            } else if($validator->passes()) {
                $jumaaSettings = $masjid->jumaaSettings;
                if($jumaaSettings) {
                    $jumaaSettings->athans = null;
                    $jumaaSettings->update($request->only([
                        'iqama',
                        'athans'
                    ]));
                } else {
                    $jumaaSettings = $masjid->jumaaSettings()->create($request->only([
                        'iqama',
                        'athans'
                    ]));
                }
                return response()->json([
                    'status' => 'success',
                    'data' => $jumaaSettings
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
