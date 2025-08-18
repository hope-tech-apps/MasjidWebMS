<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Masjid;
use App\Models\Prayer;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PrayersController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request, $masjid_id)
    {

        try {
            $masjid = Masjid::findOrFail($masjid_id);

            $request->validate([
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date'
            ]);

            if ($request['start_date']) {
                $rangeStartDate = Carbon::createFromFormat('Y-m-d', $request['start_date'])->addDays(-1);
            } else {
                $rangeStartDate = Carbon::now()->addDays(-1);
            }

            if ($request['end_date']) {
                $rangeEndDate = Carbon::createFromFormat('Y-m-d', $request['end_date']);
            } else {
                $rangeEndDate = $rangeStartDate->copy()->addDays(15);
            }

            if ($rangeStartDate > $rangeEndDate) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid date range.'
                ]);
            }

            // Store not-inserted prayers to the database
            $this->store($masjid_id, $rangeStartDate->copy()->addDay()->format('Y-m-d'), $rangeEndDate->copy()->format('Y-m-d'));

            $prayers = Prayer::where('masjid_id', $masjid->id)->whereBetween('date', [$rangeStartDate, $rangeEndDate])->orderBy('date')->get();

            return response()->json(
                [
                    'status' => 'success',
                    'data' => $prayers
                ],
                Response::HTTP_OK
            );
            
        } catch (\Exception $e) {
            return response()->json(
                [
                    'status' => 'error',
                    'message' => $e->getMessage()
                ],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store($masjid_id, $rangeStart, $rangeEnd)
    {
        try {

            $masjid = Masjid::findOrFail($masjid_id);

            $longitude = $masjid->longitude;
            $latitude = $masjid->latitude;

            $rangeStartDate = Carbon::createFromFormat('Y-m-d', $rangeStart);
            $rangeEndDate = Carbon::createFromFormat('Y-m-d', $rangeEnd);

            // // Try to get dates range in PHP and send it with node to the JS script
            // // Could be used later
            // $dateRange = [];
            // $loopDate = $rangeStartDate->copy();
            // while ($loopDate <= $rangeEndDate) {
            //     array_push($dateRange, $loopDate->copy()->format("Y-m-d"));
            //     $loopDate->addDay();
            // }

            // $storedDates = Prayer::where('masjid_id', $masjid->id)->whereIn('date', $dateRange)->pluck('date')->toArray();
            // $notStoredDates = array_diff($dateRange, $storedDates);

            // return $notStoredDates;

            if ($rangeStartDate > $rangeEndDate) {
                return null;
            }

            // Run the resources/js/FettchPrayerTimes.js script
            $prayerTimesFromJs = shell_exec(
                'node "' . base_path('resources/js/fetchPrayerTimes.js') . '" '
                    . $latitude . ' ' . $longitude . ' ' . $rangeStartDate . ' ' . $rangeEndDate
            );

            // Masjid iqama settings for set the prayers iqama times during data mapping
            $iqamaSettings = $masjid->iqamaTimeSettings;

            // Map prayers data from JS script with Prayer model schema
            $prayersToCreate = array_map(function ($item) use ($masjid, $iqamaSettings) {
                return [
                    'masjid_id' => $masjid->id,
                    'prayers_data' => json_encode($item),
                    'iqama_times_data' => json_encode([
                        'fajr' => Carbon::parse($item->fajr)->addMinutes($iqamaSettings->fajr)->format("H:i:s"),
                        'dhuhr' => Carbon::parse($item->dhuhr)->addMinutes($iqamaSettings->dhuhr)->format("H:i:s"),
                        'asr' => Carbon::parse($item->asr)->addMinutes($iqamaSettings->asr)->format("H:i:s"),
                        'maghrib' => Carbon::parse($item->maghrib)->addMinutes($iqamaSettings->maghrib)->format("H:i:s"),
                        'isha' => Carbon::parse($item->isha)->addMinutes($iqamaSettings->isha)->format("H:i:s")
                    ]),
                    'jumaa_data' => Carbon::parse($item->date)->isFriday() ? json_encode($masjid->jumaaSettings) : null,
                    'date' => Carbon::parse($item->date)->format("Y-m-d"),
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now()
                ];
            }, json_decode($prayerTimesFromJs));

            // Extract valid prayer time records (not replicated for same date and masjid)
            $validDataToCreate = [];
            foreach ($prayersToCreate as $record) {
                $oldRecord = Prayer::where('masjid_id', $record['masjid_id'])
                    ->where('date', $record['date'])
                    ->first();

                if (!$oldRecord) {
                    array_push($validDataToCreate, $record);
                } else {
                    $record['prayers_data'] = json_decode($record['prayers_data']);
                    $record['iqama_times_data'] = json_decode($record['iqama_times_data']);
                    $record['jumaa_data'] = json_decode($record['jumaa_data']);
                    $oldRecord->update($record);
                }
            }

            // Insert valid data (not replicated for same date and masjid)
            $inserted = 0;
            if ($validDataToCreate) {
                $inserted = Prayer::insert($validDataToCreate);
            }

            return $inserted;
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function prayersSettings($masjid_id)
    {
        $masjid = Masjid::findOrFail($masjid_id);
        $iqamaTimes = $masjid->iqamaTimeSettings;
        $jumaaTime = $masjid->jumaaSettings;
        return response()->json([
            'status' => 'success',
            'data' => ['iqama' => $iqamaTimes, 'jumaa' => $jumaaTime]
        ], Response::HTTP_OK);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
