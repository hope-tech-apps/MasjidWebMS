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

            // Whether this save SUPPLIES the Jumu'ah time (W2 S18). The screen has
            // had no iqama field since 1c92bbb5: it sends athans and shifts. So an
            // iqama in the request is a time someone gave. Athans or shifts on the
            // provisioning placeholder supply Jumu'ah, but not its invented 13:30
            // iqama, which is dropped rather than promoted to a time nobody gave
            // (the board would draw it and count down to it). A save that sends
            // none of them leaves the placeholder flagged. Rows that predate the
            // flag (every live organisation) keep their iqama untouched.
            $suppliesTimes = count($payload['athans'] ?? []) > 0 || count($shifts) > 0;
            if ($request->filled('iqama')) {
                $payload['is_default'] = false;
            } elseif ($jumaaSettings?->isPlaceholder() && $suppliesTimes) {
                $payload['iqama'] = null;
                $payload['is_default'] = false;
            }

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
