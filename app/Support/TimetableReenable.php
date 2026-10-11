<?php

namespace App\Support;

use App\Models\{Group, Masjid, SchoolYear, TimetableClassDay, TimetableDay, TimetableMeeting, TimetablePeriod, ClassSubject};
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Reconcile kept ongoing meetings under the capability writer's organisation mutex. */
final class TimetableReenable
{
    public static function reconcile(Masjid $locked, array $changes): ?int
    {
        if (($changes['school_timetable'] ?? null) !== true || $locked->hasCapability('school_timetable')
            || ! $locked->getAttribute('has_timetable_records')) return null;
        $intended = clone $locked;
        $intended->capability_overrides = array_replace($locked->capability_overrides ?? [], $changes);
        $today = SchoolDateAuthority::for($locked->id)->today();
        $until = CarbonImmutable::parse($today)->subDay()->toDateString();
        $years = SchoolYear::where('masjid_id', $locked->id)->get()->keyBy('id');
        $groups = Group::withTrashed()->where('masjid_id', $locked->id)->get()->keyBy('id');
        $subjects = ClassSubject::where('masjid_id', $locked->id)->get()->keyBy('id');
        $periods = TimetablePeriod::where('masjid_id', $locked->id)->get()->keyBy('id');
        $days = TimetableDay::where('masjid_id', $locked->id)->get()->keyBy(fn ($d) => $d->school_year_id.':'.$d->weekday);
        $overrides = TimetableClassDay::where('masjid_id', $locked->id)->get()->keyBy(fn ($d) => $d->school_year_id.':'.$d->group_id.':'.$d->weekday);
        $teachers = DB::table('timetable_meeting_teachers as mt')->leftJoin('users as u', 'u.id', '=', 'mt.user_id')
            ->leftJoin('masjid_user as mu', fn ($j) => $j->on('mu.user_id', '=', 'mt.user_id')->on('mu.masjid_id', '=', 'mt.masjid_id'))
            ->where('mt.masjid_id', $locked->id)->select(['mt.meeting_id','u.type','u.deleted_at','mu.role'])->get()->groupBy('meeting_id');
        $end = [];
        foreach (TimetableMeeting::where('masjid_id', $locked->id)->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $today))->get() as $m) {
            $g = $groups[$m->group_id] ?? null; $y = $years[$m->school_year_id] ?? null; $p = $periods[$m->period_id] ?? null;
            $s = $subjects[$m->class_subject_id] ?? null;
            $set = $overrides[$m->school_year_id.':'.$m->group_id.':'.$m->weekday]->period_set_id
                ?? $days[$m->school_year_id.':'.$m->weekday]->period_set_id ?? null;
            $weekdays = $y ? (SchoolSettings::calendarTerms($intended) ? SchoolDateAuthority::weekdays($y) : [$y->meetingWeekday()]) : [];
            $bad = ! $g || $g->deleted_at !== null || ! $g->is_active || $g->kind !== 'class'
                || ! $y || ! in_array((int) $m->weekday, $weekdays, true) || ! $p || $p->period_set_id != $set
                || ($m->kind === 'subject' && (! SchoolSettings::classSubjects($intended) || ! $s || $s->hidden_at !== null || $p?->kind !== 'teaching'))
                || ($teachers[$m->id] ?? collect())->contains(fn ($t) => $t->type !== 'Teacher' || $t->deleted_at !== null || $t->role !== 'teacher');
            if ($bad) $end[] = $m->id;
        }
        TimetableMeeting::where('masjid_id', $locked->id)->whereIn('id', $end)->update(['effective_until'=>$until]);
        return count($end);
    }
}
