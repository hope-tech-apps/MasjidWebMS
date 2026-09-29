<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Masjids\SetPointsReportScheduleRequest;
use App\Models\Masjid;
use App\Models\MasjidPointsSetting;
use App\Support\PointsReportSchedule;
use App\Support\SchoolCalendar;
use App\Support\SchoolPointsWeek;
use App\Support\SchoolSettings;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * When a school's weekly points report goes out (T-003.3), for a SuperAdmin.
 *
 * SuperAdmin only, like the capability that switches the report on
 * (`points_weekly_report`): the same person decides that a school gets the report
 * and when. Neither a school's own administrators nor its teachers can move it.
 * GET checks in the controller (a 403 like MasjidsController::setCapability); PUT
 * checks in the FormRequest's authorize(), so a non-super never sees validation
 * output.
 *
 * GET says what the schedule IS, where each half comes from ('set' or 'default'),
 * whether the report is switched on, and the zone the time is read in, so nobody
 * has to work out from a config file why a report went out at 15:00.
 * PUT sets either half or both; null puts a half back on the default.
 */
class PointsReportScheduleController extends Controller
{
    public function show(string $masjid_id)
    {
        $this->authorizeSuper();

        return $this->respond(Masjid::findOrFail($masjid_id));
    }

    public function update(SetPointsReportScheduleRequest $request, string $masjid_id)
    {
        $masjid = Masjid::findOrFail($masjid_id);

        $row = MasjidPointsSetting::withoutMasjidScope()->firstOrNew(['masjid_id' => $masjid->id]);
        $row->masjid_id = $masjid->id;

        foreach (['report_weekday', 'report_time'] as $field) {
            // Only what was SENT changes; a half the request omits stays as it was.
            if ($request->exists($field)) {
                $row->{$field} = $request->validated($field);
            }
        }

        $row->save();

        // A schedule change moves when a school's families are emailed, so it is
        // on the record with who did it, at a level production keeps.
        Log::warning('Weekly points report schedule changed', [
            'masjid_id' => $masjid->id,
            'actor_user_id' => Auth::id(),
            'report_weekday' => $row->report_weekday,
            'report_time' => $row->report_time,
        ]);

        return $this->respond($masjid);
    }

    private function authorizeSuper(): void
    {
        if (Auth::user()?->type !== 'SuperAdmin') {
            abort(Response::HTTP_FORBIDDEN, 'Only a super admin can change when a school\'s weekly points report is sent.');
        }
    }

    private function respond(Masjid $masjid)
    {
        $schedule = PointsReportSchedule::for($masjid);

        return response()->json([
            'status' => 'success',
            'data' => [
                'masjid_id' => (int) $masjid->id,
                'enabled' => SchoolSettings::pointsWeeklyReport($masjid),
                'weekday' => $schedule['weekday'],
                'weekday_name' => SchoolCalendar::weekdayName($schedule['weekday']),
                'time' => $schedule['time'],
                'weekday_source' => $schedule['weekday_source'],
                'time_source' => $schedule['time_source'],
                'timezone' => SchoolPointsWeek::timezone((int) $masjid->id),
            ],
        ], Response::HTTP_OK);
    }
}
