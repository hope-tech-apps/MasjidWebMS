<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Teacher\AttendanceController;
use App\Http\Requests\AdminDashboard\SaveOfficeAttendanceRequest;
use App\Models\{AttendanceRecord, Group, Masjid};
use App\Services\Schools\AttendanceRegister;
use App\Support\{SchoolCalendarReaders, StudentAllergies};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Validation\ValidationException;

/** Admin + tenant + CRM + manage contacts; never impersonates a teacher. */
class OfficeAttendanceController extends Controller
{
    public function __construct(private AttendanceRegister $register) {}

    public function index(Request $request, $masjid_id, $group_id): JsonResponse
    {
        $group = $this->classroom($group_id);
        $request->validate(['date' => ['sometimes', 'date_format:Y-m-d']]);
        $date = $request->query('date', SchoolCalendarReaders::for((int) $group->masjid_id)->today());
        $response = $this->register->read(app(AttendanceController::class), new Request(['date' => $date]), $masjid_id, $group_id);
        $payload = $response->getData(true);
        $day = $this->register->officeDay((int) $group->masjid_id, $date, $payload['data']['taken']);
        $payload['data'] = $day + $payload['data'];
        if ($day['refusal'] !== null) {
            $payload['data']['students'] = [];
        } else {
            $marks = AttendanceRecord::query()->where('group_id', $group->id)->whereDate('session_date', $date)
                ->with('markedBy:id,name,type')->get()->keyBy('group_membership_id');
            foreach ($payload['data']['students'] as &$student) {
                $mark = $marks->get($student['membership_id']);
                $student['marked_by'] = $mark ? AttendanceRegister::marker($mark->markedBy?->name, $mark->markedBy?->type) : null;
                $student['marked_at'] = $mark?->updated_at?->toIso8601String();
            }
            unset($student);
            $payload['data']['students'] = StudentAllergies::decorateTeacherRoster((int) $group->masjid_id, (int) $group->id, $payload['data']['students']);
        }
        return $response->setData($payload);
    }

    public function save(SaveOfficeAttendanceRequest $request, $masjid_id, $group_id): JsonResponse
    {
        $group = $this->classroom($group_id);
        $taken = AttendanceRecord::query()->where('group_id', $group->id)->whereDate('session_date', $request->validated('session_date'))->exists();
        $day = $this->register->officeDay((int) $group->masjid_id, $request->validated('session_date'), $taken);
        if ($day['refusal'] !== null) throw ValidationException::withMessages(['session_date' => $day['refusal']]);
        $response = $this->register->save(app(AttendanceController::class), $request, $masjid_id, $group_id);
        if ($response->getStatusCode() !== 200) return $response;
        return $this->index(new Request(['date' => $request->validated('session_date')]), $masjid_id, $group_id);
    }

    public function overview(Request $request, $masjid_id): JsonResponse
    {
        abort_unless(Masjid::findOrFail($masjid_id)->isSchool(), 404);
        $request->validate(['date' => ['sometimes', 'date_format:Y-m-d']]);
        $date = $request->query('date', SchoolCalendarReaders::for((int) $masjid_id)->today());
        return response()->json(['status' => 'success', 'data' => $this->register->officeDay((int) $masjid_id, $date) + [
            'classes' => $this->register->overview((int) $masjid_id, $date),
        ]]);
    }

    private function classroom($id): Group
    {
        $group = Group::query()->where('kind', Group::KIND_CLASS)->findOrFail($id);
        abort_unless(Masjid::findOrFail($group->masjid_id)->isSchool(), 404);
        return $group;
    }
}
