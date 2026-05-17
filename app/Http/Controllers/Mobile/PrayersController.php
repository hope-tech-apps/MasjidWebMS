<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Masjid;
use App\Models\Prayer;
use App\Support\MobileCache;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
                'end_date' => 'nullable|date',
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

            $prayers = Prayer::where('masjid_id', $masjid->id)
                ->whereBetween('date', [$rangeStartDate, $rangeEndDate])
                ->orderBy('date')
                ->get();

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
                    'message' => \App\Support\Errors::publicMessage($e)
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
            // Eager load iqamaTimeSettings + jumaaSettings so the JSON-building loop below
            // doesn't trigger a query per prayer day.
            $masjid = Masjid::with('iqamaTimeSettings', 'jumaaSettings')->findOrFail($masjid_id);

            $longitude = $masjid->longitude;
            $latitude = $masjid->latitude;

            $rangeStartDate = Carbon::createFromFormat('Y-m-d', $rangeStart);
            $rangeEndDate = Carbon::createFromFormat('Y-m-d', $rangeEnd);

            if ($rangeStartDate > $rangeEndDate) {
                return null;
            }

            // Run resources/js/fetchPrayerTimes.js via the Node binary.
            //
            // Security: every argument is escaped with escapeshellarg() so a
            // malicious masjid coord / date couldn't break out of the shell
            // word boundary and execute additional commands. Numeric inputs
            // are also coerced to strings of the expected shape via Carbon /
            // (float) casts.
            $latArg = escapeshellarg((string) (float) $latitude);
            $lonArg = escapeshellarg((string) (float) $longitude);
            $startArg = escapeshellarg($rangeStartDate->format('Y-m-d H:i:s'));
            $endArg = escapeshellarg($rangeEndDate->format('Y-m-d H:i:s'));
            $scriptPath = escapeshellarg(base_path('resources/js/fetchPrayerTimes.js'));

            $prayerTimesFromJs = shell_exec(
                "node {$scriptPath} {$latArg} {$lonArg} {$startArg} {$endArg}",
            );

            $iqamaSettings = $masjid->iqamaTimeSettings;
            $jumaaSettings = $masjid->jumaaSettings;

            // Map prayers data from JS script with Prayer model schema
            $prayersToCreate = array_map(function ($item) use ($masjid, $iqamaSettings, $jumaaSettings) {
                return [
                    'masjid_id' => $masjid->id,
                    'prayers_data' => json_encode($item),
                    'iqama_times_data' => json_encode([
                        'fajr' => Carbon::parse($item->fajr)->addMinutes($iqamaSettings->fajr)->format("H:i:s"),
                        'dhuhr' => Carbon::parse($item->dhuhr)->addMinutes($iqamaSettings->dhuhr)->format("H:i:s"),
                        'asr' => Carbon::parse($item->asr)->addMinutes($iqamaSettings->asr)->format("H:i:s"),
                        'maghrib' => Carbon::parse($item->maghrib)->addMinutes($iqamaSettings->maghrib)->format("H:i:s"),
                        'isha' => Carbon::parse($item->isha)->addMinutes($iqamaSettings->isha)->format("H:i:s"),
                    ]),
                    'jumaa_data' => Carbon::parse($item->date)->isFriday() ? json_encode($jumaaSettings) : null,
                    'date' => Carbon::parse($item->date)->format("Y-m-d"),
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
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
        $data = Cache::remember(
            MobileCache::masjidKey((int) $masjid_id, MobileCache::PRAYERS_SETTINGS),
            MobileCache::TTL_MEDIUM,
            function () use ($masjid_id) {
                // Single sync endpoint: clients call this at startup and re-call after admin
                // changes any calc/iqama/jumaa setting. Includes everything needed to drive
                // the local Adhan calculation + iqama display + per-day notification scheduling.
                $masjid = Masjid::with(
                    'iqamaTimeSettings.timeRanges',
                    'jumaaSettings',
                    'prayerCalculationSettings'
                )->findOrFail($masjid_id);

                return [
                    'iqama' => $masjid->iqamaTimeSettings,
                    'jumaa' => $masjid->jumaaSettings,
                    'calculation' => $masjid->prayerCalculationSettings ?: [
                        // Defaults match what MasjidsController::store seeds on new masjids.
                        'method' => 'MoonsightingCommittee',
                        'madhab' => 'Shafi',
                        'high_latitude_rule' => 'MiddleOfTheNight',
                    ],
                    'masjid' => [
                        'id' => $masjid->id,
                        'timezone' => $masjid->timezone,
                        'latitude' => (float) $masjid->latitude,
                        'longitude' => (float) $masjid->longitude,
                    ],
                ];
            }
        );

        return response()->json([
            'status' => 'success',
            'data' => $data
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
