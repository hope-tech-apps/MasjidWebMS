<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Enums\HighLatitudeRule;
use App\Enums\Madhab;
use App\Enums\PrayerCalculationMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PrayerCalculation\SavePrayerCalculationSettingsRequest;
use App\Models\Masjid;
use App\Support\MobileCache;
use Symfony\Component\HttpFoundation\Response;

class PrayerCalculationSettingsController extends Controller
{
    public function index($masjid_id)
    {
        $masjid = Masjid::findOrFail($masjid_id);
        $settings = $masjid->prayerCalculationSettings;

        return response()->json([
            'status' => 'success',
            'data' => $settings
        ], Response::HTTP_OK);
    }

    public function save(SavePrayerCalculationSettingsRequest $request, $masjid_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);
            $settings = $masjid->prayerCalculationSettings;

            $data = $request->safe()->only(['method', 'madhab', 'high_latitude_rule']);

            if ($settings) {
                $settings->update($data);
            } else {
                $settings = $masjid->prayerCalculationSettings()->create($data);
            }

            // Mobile clients consume calc params via /prayers/settings — invalidate so
            // they see the new method on next sync.
            MobileCache::flushMasjid((int) $masjid_id, MobileCache::PRAYERS_SETTINGS);
            // prayer_calculation also rides along in the V1 /settings payload.
            MobileCache::flushSettings((int) $masjid_id);

            return response()->json([
                'status' => 'success',
                'data' => $settings
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'data' => \App\Support\Errors::publicMessage($e)
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function getOptions()
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'methods' => collect(PrayerCalculationMethod::cases())->map(fn($case) => [
                    'value' => $case->value,
                    'label' => $case->label()
                ]),
                'madhabs' => collect(Madhab::cases())->map(fn($case) => [
                    'value' => $case->value,
                    'label' => $case->label()
                ]),
                'high_latitude_rules' => collect(HighLatitudeRule::cases())->map(fn($case) => [
                    'value' => $case->value,
                    'label' => $case->label()
                ])
            ]
        ], Response::HTTP_OK);
    }
}
