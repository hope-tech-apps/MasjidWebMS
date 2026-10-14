<?php

namespace App\Http\Controllers\Teacher;

use App\Support\StudentAllergies;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Decorate the frozen register reader; inherited save() returns this same projection. */
class StudentAllergyAttendanceController extends AttendanceController
{
    public function index(Request $request, $masjid_id, $group_id): JsonResponse
    {
        $response = parent::index($request, $masjid_id, $group_id);
        $payload = $response->getData(true);
        if (\App\Support\PeriodAttendanceMode::held((int)$masjid_id) && ! ($payload['data']['period_mode'] ?? false)) {
            $payload['data']['retained_periods'] = app(\App\Services\Schools\PeriodAttendance::class)->retained((int)$masjid_id, (int)$group_id, $payload['data']['session_date']);
        }
        $payload['data']['students'] = StudentAllergies::decorateTeacherRoster(
            (int) $masjid_id, (int) $group_id, $payload['data']['students']
        );
        $response->setData($payload);

        return $response;
    }
}
