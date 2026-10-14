<?php

namespace App\Services\Schools;

use App\Models\{AttendanceRecord, Masjid};
use App\Support\{SchoolCalendarReaders, SchoolSettings};
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** The daily-mark seam shared by the family read and absence emails; replace here for period attendance. */
final class AttendanceReader
{
    /** Read recorded cells only. Notes, markers and correction timestamps never leave the register. */
    public function marks(int $school, ?array $memberships = null, ?string $date = null): Collection
    {
        $query = DB::table('attendance_records as mark')
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
        return ['period' => $period, 'totals' => $totals, 'days' => $marks->map(fn ($m) => ['date' => $m['date'], 'class' => $m['class'], 'status' => $m['status']])->values()->all()];
    }
}
