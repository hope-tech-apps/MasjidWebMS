<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Iqama\SaveIqamaSettingsRequest;
use App\Models\Masjid;
use App\Support\MobileCache;
use App\Support\PrayerPushes;
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

    public function save(SaveIqamaSettingsRequest $request, $masjid_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);
            $iqamaTime = $masjid->iqamaTimeSettings;

            // The five offsets are live in BOTH modes. On Specific Time Ranges, any
            // prayer with no range covering the day is adhan + its offset (the rule in
            // tests/fixtures/iqama-resolution.json, implemented by the website, iOS, tvOS
            // and Android). The admin screen used to send no offsets in that mode, and
            // this used `?? 0`, so every save there silently put Fajr and Maghrib iqama
            // ON the adhan for a masjid that runs fixed Dhuhr/Asr/Isha with relative
            // Fajr/Maghrib. An offset the request does not carry keeps its stored value.
            $offset = function (string $salah) use ($request, $iqamaTime): int {
                if ($request->filled($salah)) {
                    return (int) $request->input($salah);
                }

                return $iqamaTime ? (int) $iqamaTime->{$salah} : 0;
            };

            $data = [
                'iqama_type' => $request->input('iqama_type'),
                'show_iqama_times' => $request->boolean('show_iqama_times', true),
                'fajr' => $offset('fajr'),
                'dhuhr' => $offset('dhuhr'),
                'asr' => $offset('asr'),
                'maghrib' => $offset('maghrib'),
                'isha' => $offset('isha'),
            ];

            if ($iqamaTime) {
                $iqamaTime->update($data);
            } else {
                $iqamaTime = $masjid->iqamaTimeSettings()->create(array_merge($data, [
                    'masjid_id' => $masjid->id,
                ]));
            }

            // Handle time ranges if iqama_type is specific_time_ranges
            if ($request->input('iqama_type') === 'specific_time_ranges' && $request->has('time_ranges')) {
                $iqamaTime->timeRanges()->delete();

                foreach ($request->input('time_ranges', []) as $range) {
                    // Normalize time format to H:i (drop seconds if present)
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

            $iqamaTime->load('timeRanges');

            MobileCache::flushMasjid((int) $masjid_id, MobileCache::PRAYERS_SETTINGS);

            // Wake this masjid's devices in the background to re-pull the new
            // iqama times and re-arm their local notification schedule, so the
            // change reaches users without them reopening the app. Silent push,
            // queued, and fail-soft — must never block or break the save.
            //
            // Not for an organisation whose Prayer times are switched off: only a
            // SuperAdmin can save here then, and that save must not wake its phones
            // (App\Support\PrayerPushes).
            if (PrayerPushes::allowedFor($masjid)) {
                try {
                    $subscriptionIds = $masjid->mobileAppUsers()
                        ->whereNotNull('onesignal_subscription_id')
                        ->pluck('onesignal_subscription_id')
                        ->filter()
                        ->values()
                        ->toArray();

                    if (!empty($subscriptionIds)) {
                        \App\Jobs\SendPrayerSyncJob::dispatch((int) $masjid_id, $subscriptionIds);
                    }
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning(
                        'Failed to queue prayer-sync push: ' . $e->getMessage()
                    );
                }
            }

            return response()->json([
                'status' => 'success',
                'data' => $iqamaTime
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'data' => \App\Support\Errors::publicMessage($e)
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
