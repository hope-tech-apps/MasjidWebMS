<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Enums\HighLatitudeRule;
use App\Enums\Madhab;
use App\Enums\PrayerCalculationMethod;
use App\Http\Controllers\Controller;
use App\Models\Masjid;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
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

    public function save(Request $request, $masjid_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);

            $validator = Validator::make($request->all(), [
                'method' => 'required|string',
                'madhab' => 'required|string',
                'high_latitude_rule' => 'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'failed',
                    'data' => $validator->errors()
                ], Response::HTTP_BAD_REQUEST);
            }

            // Validate enum values
            $methodEnum = PrayerCalculationMethod::tryFrom($request->method);
            $madhabEnum = Madhab::tryFrom($request->madhab);
            $highLatitudeRuleEnum = HighLatitudeRule::tryFrom($request->high_latitude_rule);

            if (!$methodEnum || !$madhabEnum || !$highLatitudeRuleEnum) {
                return response()->json([
                    'status' => 'failed',
                    'data' => ['message' => 'Invalid enum values provided']
                ], Response::HTTP_BAD_REQUEST);
            }

            $settings = $masjid->prayerCalculationSettings;

            $data = [
                'method' => $request->method,
                'madhab' => $request->madhab,
                'high_latitude_rule' => $request->high_latitude_rule,
            ];

            if ($settings) {
                $settings->update($data);
            } else {
                $settings = $masjid->prayerCalculationSettings()->create($data);
            }

            return response()->json([
                'status' => 'success',
                'data' => $settings
            ], Response::HTTP_OK);

        } catch(\Exception $e) {
            return response()->json([
                'status' => 'error',
                'data' => $e->getMessage()
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
