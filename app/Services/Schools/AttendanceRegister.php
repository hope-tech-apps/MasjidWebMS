<?php

namespace App\Services\Schools;

use App\Http\Controllers\Teacher\AttendanceController;
use App\Http\Requests\Teacher\SaveAttendanceRequest;
use App\Models\Group;
use App\Support\{SchoolCalendar, SchoolCalendarReaders};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Both realms call the frozen reader and the same atomic register writer. */
class AttendanceRegister
{
    public const REVISION_HEADER = 'X-Attendance-Revision';

    /** Keep the frozen payload and revision in one locked snapshot. */
    public function read(AttendanceController $reader, Request $request, $masjid_id, $group_id): JsonResponse
    {
        return DB::transaction(function () use ($reader, $request, $masjid_id, $group_id) {
            $this->lockClass((int) $masjid_id, (int) $group_id);
            \App\Support\SchoolCalendarRequestMode::enabled((int)$masjid_id);
            if (\App\Support\PeriodAttendanceMode::enabled(\App\Support\PeriodAttendanceMode::school((int)$masjid_id))) {
                $date = $request->query('date') ?? SchoolCalendarReaders::for((int)$masjid_id)->today();
                if (\App\Support\PeriodAttendanceMode::usesPeriods((int)$masjid_id, $date)) return app(PeriodAttendance::class)->classDay($request, (int)$masjid_id, (int)$group_id, $date);
            }

            $response = $reader->readFrozen($request, $masjid_id, $group_id);
            $date = $response->getData(true)['data']['session_date'];
            $response->headers->set(self::REVISION_HEADER, $this->revision($this->marks((int) $masjid_id, (int) $group_id, $date)));
            return $response;
        });
    }

    /** Reject changed revisions before the atomic frozen writer touches a cell. */
    public function save(AttendanceController $writer, SaveAttendanceRequest $request, $masjid_id, $group_id): JsonResponse
    {
        return DB::transaction(function () use ($writer, $request, $masjid_id, $group_id) {
            // School then class matches switch/calendar writers and keeps the switch read fresh.
            $org = $this->lockSchool((int)$masjid_id);
            $this->lockClass((int) $masjid_id, (int) $group_id);
            $date = $request->validated('session_date');
            if (\App\Support\PeriodAttendanceMode::usesPeriods((int)$masjid_id, $date, $org)) {
                return response()->json(['status'=>'failed','message'=>'Choose a period to take or correct its register.'],422);
            }
            $marks = $this->marks((int) $masjid_id, (int) $group_id, $date);
            $conflict = $this->conflict($request, $marks, collect($request->validated('marks'))->pluck('membership_id')->all(), (int) $masjid_id);
            if ($conflict) return $conflict;
            $response = $writer->saveFrozen($request, $masjid_id, $group_id);
            if ($response->getStatusCode() === 200 && \App\Support\PeriodAttendanceMode::held((int)$masjid_id, $org) && DB::table('period_attendance_records')->where('masjid_id',$masjid_id)->where('group_id',$group_id)->whereDate('session_date',$date)->exists()) {
                $values = $org->capability_overrides ?? [];
                foreach ($request->validated('marks') as $mark) {
                    $member = (string)$mark['membership_id'];
                    $dates = $values[\App\Support\PeriodAttendanceMode::DAILY_DATES][$member] ?? [];
                    $values[\App\Support\PeriodAttendanceMode::DAILY_DATES][$member] = array_values(array_unique([...$dates, $date]));
                }
                $org->forceFill(['capability_overrides'=>$values])->save();
                \App\Support\ClassSubjectMode::forget((int)$masjid_id);
            }
            return $response;
        });
    }

    public function conflict(Request $request, Collection $marks, array $memberships, int $school): ?JsonResponse
    {
        $revision = $request->header(self::REVISION_HEADER);
        // A page loaded before registers carried a revision sends none. Re-reading the register inside
        // that page cannot help, so the sentence has to send the person to refresh the page itself.
        $pageOutOfDate = $revision === null || $revision === '';
        if ($pageOutOfDate) $revision = null;
        $overwrites = $marks->whereIn('group_membership_id', $memberships)->isNotEmpty();
        if (($revision === null && $overwrites) || ($revision !== null && ! hash_equals($this->revision($marks), $revision))) {
            $latest = $marks->sortByDesc(fn ($m) => [$m->updated_at, $m->id])->first();
            $marker = $latest ? self::marker($latest->name, $latest->type) : 'another staff member';
            $time = $latest ? Carbon::parse($latest->updated_at)->setTimezone(SchoolCalendarReaders::for($school)->timezone())->format('F j, Y, g:i A') : 'an earlier time';
            return response()->json(['status' => 'failed', 'message' => $pageOutOfDate
                ? 'This page is out of date. Refresh the page, then make your change.'
                : "This register was changed by {$marker} at {$time}. Reload it, then make your change."], 409);
        }
        return null;
    }


    /** First locking statement on writes: an old page cannot write across a newly enabled switch. */
    public function lockSchool(int $id, bool $archived = false): \App\Models\Masjid
    {
        $school = \App\Models\Masjid::query()->when($archived, fn($q)=>$q->withTrashed())->whereKey($id)->lockForUpdate()->firstOrFail();
        \App\Support\ClassSubjectMode::forget($id);
        \App\Support\SchoolCalendarRequestMode::forget($id);
        \App\Support\ClassSubjectMode::rememberLoaded($school);
        \App\Support\SchoolCalendarRequestMode::remember($school);
        return $school;
    }

    public function lockClass(int $school, int $class): void
    {
        Group::query()->whereKey($class)->where('masjid_id', $school)->lockForUpdate()->firstOrFail();
    }

    private function marks(int $school, int $class, string $date): Collection
    {
        return DB::table('attendance_records as marks')
            ->leftJoin('users as marker', 'marker.id', '=', 'marks.marked_by_user_id')
            ->where('marks.masjid_id', $school)->where('marks.group_id', $class)->whereDate('marks.session_date', $date)
            ->orderBy('marks.id')->get(['marks.*', 'marker.name', 'marker.type']);
    }

    public function revision(Collection $marks): string
    {
        return hash('sha256', $marks->map(function ($mark) {
            $row = (array) $mark;
            unset($row['name'], $row['type']);
            return $row;
        })->toJson());
    }

    /** New office registers need an open meeting date; retained make-up marks remain correctable. */
    public function officeDay(int $school, string $date, bool $taken = false): array
    {
        $calendar = SchoolCalendarReaders::for($school);
        $day = $calendar->schoolDay($date);
        $refusal = null;
        if ($date > $calendar->today()) {
            $refusal = 'You cannot take a register for a day that has not happened yet.';
        } elseif ($day['closed']) {
            $closure = $calendar->years()->flatMap(fn ($y) => $y->closures)->first(fn ($c) => $c->closed_on->toDateString() === $date);
            $refusal = SchoolCalendar::noRegisterMessage($closure);
        } elseif (! $taken && $day['has_calendar'] && ! $day['meeting_day']) {
            $refusal = $day['in_year']
                ? "This isn't one of the school's meeting days on the calendar, so there is no register to take."
                : 'This day is outside the school year on the calendar, so there is no register to take.';
        }
        return ['session_date' => $date, 'today' => $calendar->today(), 'timezone' => $calendar->timezone(), 'school_day' => $day, 'refusal' => $refusal];
    }

    /** One classes query, one marks/marker join, independent of class count. */
    public function overview(int $school, string $date): array
    {
        $classes = Group::query()->join('masjids as school','school.id','=','groups.masjid_id')->where('groups.masjid_id', $school)->where('kind', Group::KIND_CLASS)->orderByRaw('groups.position IS NULL')->orderBy('groups.position')->orderBy('groups.name')->orderBy('groups.id')->get(['groups.id','groups.name','school.capability_overrides']);
        $org = new \App\Models\Masjid();
        $org->forceFill(['id'=>$school,'org_type'=>'school','capability_overrides'=>json_decode($classes->first()?->capability_overrides ?? 'null', true)]);
        $marks = (\App\Support\PeriodAttendanceMode::held($school, $org) ? DB::query()->fromSub(app(AttendanceReader::class)->dayQuery($school, $org)->toBase(), 'marks') : DB::table('attendance_records as marks'))
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
        // A mark by a teacher is their name; "Office:" is for an administrator's. A lunch-staff login can
        // only have marked a register as a teacher (the role an office gave it at a school), never as the office.
        return in_array($type, \App\Support\OrganisationAccess::SCOPED_TYPES, true) ? $name : 'Office: '.$name;
    }
}
