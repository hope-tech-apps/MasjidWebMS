<?php

namespace App\Services\Schools;

use App\Models\{AttendanceRecord, Masjid};
use App\Support\{SchoolCalendarReaders, SchoolSettings};
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** The single day-mark seam for all staff, family, report, mail and export readers. */
final class AttendanceReader
{
    /** Read recorded cells only. Notes, markers and correction timestamps never leave the register. */
    public function marks(int $school, ?array $memberships = null, ?string $date = null): Collection
    {
        $query = (\App\Support\PeriodAttendanceMode::held($school)
            ? DB::query()->fromSub($this->dayQuery($school)->toBase(), 'mark')
            : DB::table('attendance_records as mark'))
            ->join('group_memberships as member', function ($join) {
                $join->on('member.id', '=', 'mark.group_membership_id')->on('member.group_id', '=', 'mark.group_id')->on('member.masjid_id', '=', 'mark.masjid_id');
            })
            ->join('groups as class', function ($join) { $join->on('class.id', '=', 'mark.group_id')->on('class.masjid_id', '=', 'mark.masjid_id'); })
            ->join('contacts as child', function ($join) { $join->on('child.id', '=', 'member.contact_id')->on('child.masjid_id', '=', 'mark.masjid_id'); })
            ->where('mark.masjid_id', $school)->where('member.role', 'member')->where('class.kind', 'class')
            ->whereNull('class.deleted_at')->whereNull('child.deleted_at')->whereIn('mark.status', AttendanceRecord::STATUSES);
        if ($memberships !== null) $query->whereIn('mark.group_membership_id', $memberships);
        if ($date !== null) $query->whereDate('mark.session_date', $date)->whereNull('member.left_on');
        return $query->orderByDesc('mark.session_date')->orderBy('class.name')->orderBy('class.id')->orderBy('child.id')
            ->get(['mark.session_date', 'mark.status', 'class.id as class_id', 'class.name as class_name', 'member.id as membership_id', 'child.id as student_id', 'child.first_name'])
            ->map(fn ($m) => ['date' => substr($m->session_date, 0, 10), 'class' => ['id' => (int) $m->class_id, 'name' => $m->class_name],
                'status' => $m->status, 'membership_id' => (int) $m->membership_id, 'student_id' => (int) $m->student_id, 'first_name' => $m->first_name]);
    }

    /**
     * A SELECT-only projection shaped like the day table. No taken cell means no row.
     * SQL groups all taken meetings for a child before assigning the result to their class/day cells.
     * Calendar retention may include superseded day cells: they still prevent a closure or a year deletion.
     * The first scheduled meeting ID/clock is frozen with the first saved cell, even if its meeting is deleted.
     */
    public function dayQuery(?int $school = null, ?Masjid $loaded = null, bool $includeRetainedDayCells = false): \Illuminate\Database\Eloquent\Builder
    {
        $school ??= app(\App\Support\TenantContext::class)->get();
        $query = AttendanceRecord::query();
        if ($school === null || ! \App\Support\PeriodAttendanceMode::held($school, $loaded)) return $query;
        return $this->routeQuery($query, $school, $loaded, $includeRetainedDayCells);
    }

    /** Retain the caller's predicates, ordering and model casts while replacing only its day source. */
    public function routeQuery(\Illuminate\Database\Eloquent\Builder $query, ?int $school = null, ?Masjid $loaded = null, bool $includeRetainedDayCells = false): \Illuminate\Database\Eloquent\Builder
    {
        $school ??= app(\App\Support\TenantContext::class)->get();
        if ($school === null || ! \App\Support\PeriodAttendanceMode::held($school, $loaded)) return $query;
        $org = $loaded ?? \App\Support\PeriodAttendanceMode::school($school);
        $resumed = $org->capability_overrides[\App\Support\PeriodAttendanceMode::DAILY_DATES] ?? [];
        $periods = DB::table('period_attendance_records as p')->where('p.masjid_id', $school);
        $displayPeriods = clone $periods;
        if (! $includeRetainedDayCells) foreach ($resumed as $member => $dates) $displayPeriods->where(fn ($q) => $q->where('p.group_membership_id','!=',(int)$member)->orWhereNotIn(DB::raw('DATE(p.session_date)'),$dates));
        $childDays = DB::query()->fromSub(clone $periods,'p')->groupBy('p.masjid_id','p.student_contact_id','p.session_date')
            ->selectRaw("p.masjid_id, p.student_contact_id, p.session_date, CASE
                WHEN SUM(CASE WHEN p.status IN ('present','late') THEN 1 ELSE 0 END) > 0 THEN
                    CASE WHEN SUM(CASE WHEN SUBSTR(p.starts_at,1,5) = (SELECT MIN(SUBSTR(first_period.first_scheduled_starts_at,1,5)) FROM period_attendance_records first_period WHERE first_period.masjid_id=p.masjid_id AND first_period.student_contact_id=p.student_contact_id AND first_period.session_date=p.session_date) AND p.status = 'late' THEN 1 ELSE 0 END) > 0 THEN 'late' ELSE 'present' END
                WHEN SUM(CASE WHEN p.status = 'absent' THEN 1 ELSE 0 END) = COUNT(*) THEN 'absent'
                ELSE 'excused' END as status");
        $derived = DB::query()->fromSub(clone $displayPeriods,'p')->joinSub($childDays,'day',fn ($j) => $j->on('day.masjid_id','=','p.masjid_id')->on('day.student_contact_id','=','p.student_contact_id')->on('day.session_date','=','p.session_date'))
            ->groupBy('p.masjid_id','p.group_id','p.group_membership_id','p.session_date','day.status')
            ->selectRaw('-MIN(p.id) as id, p.masjid_id, p.group_id, p.group_membership_id, (SELECT latest.marked_by_user_id FROM period_attendance_records latest WHERE latest.masjid_id=p.masjid_id AND latest.group_membership_id=p.group_membership_id AND latest.session_date=p.session_date ORDER BY latest.updated_at DESC, latest.id DESC LIMIT 1) as marked_by_user_id, p.session_date, day.status, NULL as note, MIN(p.created_at) as created_at, MAX(p.updated_at) as updated_at');
        $daily = DB::table('attendance_records as d')->where('d.masjid_id',$school)
            ->when(! $includeRetainedDayCells, fn($dailyQuery)=>$dailyQuery->whereNotExists(fn ($q) => $q->selectRaw('1')->fromSub(clone $displayPeriods,'p')->whereColumn('p.group_membership_id','d.group_membership_id')->whereColumn('p.session_date','d.session_date')))
            ->select('d.id','d.masjid_id','d.group_id','d.group_membership_id','d.marked_by_user_id','d.session_date','d.status','d.note','d.created_at','d.updated_at');
        if (! $includeRetainedDayCells && \App\Support\PeriodAttendanceMode::enabled($org)) {
            $daily->where(function ($q) use ($org,$resumed) {
                $q->where(function ($outside) use ($org) {
                    foreach ($org->capability_overrides[\App\Support\PeriodAttendanceMode::WINDOWS] ?? [] as $window) $outside->where(function ($interval) use ($window) {
                        $interval->whereDate('d.session_date','<',$window['from']);
                        if ($window['to'] !== null) $interval->orWhereDate('d.session_date','>',$window['to']);
                    });
                });
                foreach ($resumed as $member=>$dates) $q->orWhere(fn($dailyDate)=>$dailyDate->where('d.group_membership_id',(int)$member)->whereIn(DB::raw('DATE(d.session_date)'),$dates));
            });
        }
        $query->getQuery()->fromSub($daily->unionAll($derived),'attendance_records');
        return $query;
    }

    /** Staff-safe day counts; late also counts as present on report cards and teacher summaries. */
    public function counts(int $membership, ?string $from = null, ?string $to = null, ?int $school = null): array
    {
        $counts = $this->dayQuery($school)->where('group_membership_id',$membership)
            ->when($from,fn ($q)=>$q->whereDate('session_date','>=',$from))->when($to,fn ($q)=>$q->whereDate('session_date','<=',$to))
            ->groupBy('status')->selectRaw('status, COUNT(*) as n')->pluck('n','status');
        return ['recorded'=>(int)$counts->sum(), 'present'=>(int)$counts->get('present',0)+(int)$counts->get('late',0),
            'late'=>(int)$counts->get('late',0),'absent'=>(int)$counts->get('absent',0),'excused'=>(int)$counts->get('excused',0)];
    }

    /** Public period lines have no note, staff identity, timestamp or authority snapshot. */
    public function periodLines(int $school, array $memberships): Collection
    {
        if (! \App\Support\PeriodAttendanceMode::held($school)) return collect();
        return DB::table('period_attendance_records')->where('masjid_id',$school)->whereIn('group_membership_id',$memberships)
            ->orderBy('session_date','desc')->orderBy('starts_at')->orderBy('meeting_id')
            ->get(['session_date','group_membership_id','meeting_id','class_name','period_name','subject_name','starts_at','ends_at','status'])
            ->map(fn ($p)=>['date'=>substr($p->session_date,0,10),'membership_id'=>(int)$p->group_membership_id,
                'meeting_id'=>(int)$p->meeting_id,'class_name'=>$p->class_name,'period'=>$p->period_name,'subject'=>$p->subject_name,
                'starts_at'=>substr($p->starts_at,0,5),'ends_at'=>substr($p->ends_at,0,5),'status'=>$p->status]);
    }

    public function teacherSummary(int $school, int $class, int $membership, callable $student): \Illuminate\Http\JsonResponse
    {
        $member=\App\Models\Group::where('masjid_id',$school)->findOrFail($class)->memberships()->participants()->with('contact')->findOrFail($membership);
        $limit=(int)config('groups.records_page_size',200);
        $records=$this->dayQuery($school)->where('group_membership_id',$member->id)->orderByDesc('session_date')->limit($limit)->get();
        $counts=$this->counts($member->id,school:$school);
        return response()->json(['status'=>'success','data'=>['student'=>$student($member),'summary'=>$counts,
            'records'=>$records->map(fn($r)=>['session_date'=>$r->session_date->toDateString(),'status'=>$r->status,'note'=>$r->note])->values(),
            'records_shown'=>$records->count(),'records_truncated'=>$counts['recorded']>$records->count()]]);
    }

    /** Current dated term, otherwise the configured year or the existing August-to-July convention. */
    public function period(int $school): array
    {
        $calendar = SchoolCalendarReaders::for($school); $today = $calendar->today();
        $year = $calendar->years()->first(fn ($y) => $y->first_day->toDateString() <= $today && $y->last_day->toDateString() >= $today);
        if ($year && SchoolSettings::calendarTerms(Masjid::find($school))) {
            $term = $year->terms->first(fn ($t) => $t->starts_on->toDateString() <= $today && $t->ends_on->toDateString() >= $today);
            if ($term) return ['kind' => 'term', 'name' => $term->name, 'from' => $term->starts_on->toDateString(), 'to' => $term->ends_on->toDateString()];
        }
        if ($year) return ['kind' => 'year', 'name' => null, 'from' => $year->first_day->toDateString(), 'to' => $year->last_day->toDateString()];
        $start = (int) substr($today, 0, 4) - ((int) substr($today, 5, 2) < 8 ? 1 : 0);
        return ['kind' => 'year', 'name' => null, 'from' => $start.'-08-01', 'to' => ($start + 1).'-07-31'];
    }

    /** Totals count recorded class/day cells, with late separate from present. */
    public function family(int $school, array $memberships): array
    {
        $marks = $this->marks($school, $memberships); $period = $this->period($school); $today = SchoolCalendarReaders::for($school)->today();
        $totals = array_fill_keys(['present', 'late', 'absent', 'excused'], 0);
        foreach ($marks as $mark) if ($mark['date'] >= $period['from'] && $mark['date'] <= min($period['to'], $today)) $totals[$mark['status']]++;
        $lines = $this->periodLines($school, $memberships)->groupBy(fn ($p) => $p['date'].':'.$p['membership_id']);
        return ['period' => $period, 'totals' => $totals, 'days' => $marks->map(function ($m) use ($lines) {
            $day = ['date'=>$m['date'],'class'=>$m['class'],'status'=>$m['status']];
            $periods = $lines->get($m['date'].':'.$m['membership_id']);
            if ($periods) $day['periods'] = $periods->map(fn ($p)=>array_diff_key($p,array_flip(['date','membership_id'])))->values()->all();
            return $day;
        })->values()->all()];
    }
}
