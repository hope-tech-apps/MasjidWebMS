<?php

namespace App\Services\Schools;

use App\Http\Controllers\Teacher\AttendanceController;
use App\Http\Requests\Teacher\SaveAttendanceRequest;
use App\Models\Group;
use App\Support\{SchoolCalendar, SchoolCalendarReaders};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Both realms call the frozen reader and the same atomic register writer. */
class AttendanceRegister
{
    public function read(AttendanceController $reader, Request $request, $masjid_id, $group_id): JsonResponse
    {
        return $reader->readFrozen($request, $masjid_id, $group_id);
    }

    public function save(AttendanceController $writer, SaveAttendanceRequest $request, $masjid_id, $group_id): JsonResponse
    {
        return $writer->saveFrozen($request, $masjid_id, $group_id);
    }

    /** Office dates are school-local and restricted to the school's open meeting dates. */
    public function officeDay(int $school, string $date): array
    {
        $calendar = SchoolCalendarReaders::for($school);
        $day = $calendar->schoolDay($date);
        $refusal = null;
        if ($date > $calendar->today()) {
            $refusal = 'You cannot take a register for a day that has not happened yet.';
        } elseif ($day['closed']) {
            $closure = $calendar->years()->flatMap(fn ($y) => $y->closures)->first(fn ($c) => $c->closed_on->toDateString() === $date);
            $refusal = SchoolCalendar::noRegisterMessage($closure);
        } elseif ($day['has_calendar'] && ! $day['meeting_day']) {
            $refusal = $day['in_year']
                ? "This isn't one of the school's meeting days on the calendar, so there is no register to take."
                : 'This day is outside the school year on the calendar, so there is no register to take.';
        }
        return ['session_date' => $date, 'today' => $calendar->today(), 'timezone' => $calendar->timezone(), 'school_day' => $day, 'refusal' => $refusal];
    }

    /** One classes query, one marks/marker join, independent of class count. */
    public function overview(int $school, string $date): array
    {
        $classes = Group::query()->where('masjid_id', $school)->where('kind', Group::KIND_CLASS)->inDisplayOrder()->get(['id', 'name']);
        $marks = DB::table('attendance_records as marks')
            ->leftJoin('users as marker', 'marker.id', '=', 'marks.marked_by_user_id')
            ->where('marks.masjid_id', $school)->whereIn('marks.group_id', $classes->pluck('id'))
            ->whereDate('marks.session_date', $date)
            ->orderByDesc('marks.updated_at')->orderByDesc('marks.id')
            ->get(['marks.group_id', 'marks.updated_at', 'marker.name', 'marker.type'])
            ->unique('group_id')->keyBy('group_id');
        return $classes->map(function ($class) use ($marks) {
            $mark = $marks->get($class->id);
            return ['id' => (int) $class->id, 'name' => $class->name, 'taken' => $mark !== null,
                'marked_by' => $mark ? self::marker($mark->name, $mark->type) : null,
                'marked_at' => $mark ? Carbon::parse($mark->updated_at)->toIso8601String() : null];
        })->all();
    }

    public static function marker(?string $name, ?string $type): string
    {
        if ($name === null) return 'Former staff member';
        return $type === 'Teacher' ? $name : 'Office: '.$name;
    }
}
