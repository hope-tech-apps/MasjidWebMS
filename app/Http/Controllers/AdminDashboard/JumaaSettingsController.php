<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Jumaa\SaveJumaaSettingsRequest;
use App\Models\Masjid;
use App\Support\MobileCache;
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

    public function save(SaveJumaaSettingsRequest $request, $masjid_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);
            $jumaaSettings = $masjid->jumaaSettings;

            $payload = $request->safe()->only(['iqama', 'athans']);

            // Normalize shifts to the canonical shape so every stored/returned
            // entry always carries all four keys (empties become null). Stored
            // null when empty so apps fall back to `athans` (no regression).
            $shifts = collect($request->validated()['shifts'] ?? [])
                ->map(fn ($shift) => [
                    'time' => $shift['time'] ?? null,
                    'khateeb_name' => filled($shift['khateeb_name'] ?? null) ? $shift['khateeb_name'] : null,
                    'khateeb_title' => filled($shift['khateeb_title'] ?? null) ? $shift['khateeb_title'] : null,
                    'khutbah_title' => filled($shift['khutbah_title'] ?? null) ? $shift['khutbah_title'] : null,
                ])
                ->values()
                ->all();
            $payload['shifts'] = count($shifts) ? $shifts : null;

            if ($jumaaSettings) {
                // Reset athans before update so a missing value clears the field.
                // `shifts` is always present in $payload, so it clears on its own.
                $jumaaSettings->athans = null;
                $jumaaSettings->update($payload);
            } else {
                $jumaaSettings = $masjid->jumaaSettings()->create($payload);
            }

            MobileCache::flushMasjid((int) $masjid_id, MobileCache::PRAYERS_SETTINGS);

            return response()->json([
                'status' => 'success',
                'data' => $jumaaSettings
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'data' => \App\Support\Errors::publicMessage($e)
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
