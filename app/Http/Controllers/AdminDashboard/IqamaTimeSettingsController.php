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
        $iqamaTimes = $masjid->iqamaTimeSettings()->with('timeRanges')->first();
        return response()->json([
            'status' => 'success',
            'data' => $iqamaTimes
        ], Response::HTTP_OK);
    }

    public function save(Request $request, $masjid_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);

            // Validate iqama_type
            $validator = Validator::make($request->all(), [
                'iqama_type' => 'required|in:minutes_after_adhan,specific_time_ranges',
                'fajr' => 'required_if:iqama_type,minutes_after_adhan|nullable|integer|min:1',
                'dhuhr' => 'required_if:iqama_type,minutes_after_adhan|nullable|integer|min:1',
                'asr' => 'required_if:iqama_type,minutes_after_adhan|nullable|integer|min:1',
                'maghrib' => 'required_if:iqama_type,minutes_after_adhan|nullable|integer|min:1',
                'isha' => 'required_if:iqama_type,minutes_after_adhan|nullable|integer|min:1',
                'time_ranges' => 'required_if:iqama_type,specific_time_ranges|nullable|array',
                'time_ranges.*.salah' => 'required|in:fajr,dhuhr,asr,maghrib,isha',
                'time_ranges.*.start_date' => 'required|date',
                'time_ranges.*.end_date' => 'required|date|after_or_equal:time_ranges.*.start_date',
                'time_ranges.*.specific_time' => ['required', 'regex:/^([0-1]?[0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/'],
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'failed',
                    'data' => $validator->errors()
                ], Response::HTTP_BAD_REQUEST);
            }

            $iqamaTime = $masjid->iqamaTimeSettings;

            // Convert show_iqama_times to boolean
            $showIqamaTimes = true;
            if ($request->has('show_iqama_times')) {
                $showIqamaTimes = filter_var($request->show_iqama_times, FILTER_VALIDATE_BOOLEAN);
            }

            $data = [
                'iqama_type' => $request->iqama_type,
                'show_iqama_times' => $showIqamaTimes,
                'fajr' => $request->fajr ?? 0,
                'dhuhr' => $request->dhuhr ?? 0,
                'asr' => $request->asr ?? 0,
                'maghrib' => $request->maghrib ?? 0,
                'isha' => $request->isha ?? 0,
            ];

            if ($iqamaTime) {
                $iqamaTime->update($data);
            } else {
                $iqamaTime = $masjid->iqamaTimeSettings()->create(array_merge($data, [
                    'masjid_id' => $masjid->id,
                ]));
            }

            // Handle time ranges if iqama_type is specific_time_ranges
            if ($request->iqama_type === 'specific_time_ranges' && $request->has('time_ranges')) {
                // Delete existing time ranges
                $iqamaTime->timeRanges()->delete();

                // Create new time ranges
                foreach ($request->time_ranges as $range) {
                    // Normalize time format to H:i (remove seconds if present)
                    $specificTime = $range['specific_time'];
                    if (preg_match('/^(\d{1,2}):(\d{2}):(\d{2})$/', $specificTime, $matches)) {
                        $specificTime = $matches[1] . ':' . $matches[2];
                    }

                    $iqamaTime->timeRanges()->create([
                        'salah' => $range['salah'],
                        'start_date' => $range['start_date'],
                        'end_date' => $range['end_date'],
                        'specific_time' => $specificTime,
                    ]);
                }
            }

            // Load time ranges for response
            $iqamaTime->load('timeRanges');

            return response()->json([
                'status' => 'success',
                'data' => $iqamaTime
            ], Response::HTTP_OK);

        } catch(\Exception $e) {
            return response()->json([
                'status' => 'error',
                'data' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
