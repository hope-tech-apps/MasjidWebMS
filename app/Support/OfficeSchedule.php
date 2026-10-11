<?php

namespace App\Support;

/** Pure projection of bulk reference data and an as-of snapshot, with no per-person/class reads. */
final class OfficeSchedule
{
    /** Whole-school defaults advance to an open meeting day; explicit weekdays keep the chosen week. */
    public static function make(array $setup, array $meetings, array $closures, array $clashes, string $date, string $lens, ?int $id, ?int $weekday): array
    {
        $closureByDate=array_column($closures, 'reason', 'date');
        $displayDate=$date;
        if ($lens === 'school' && $weekday === null) {
            $displayDate=self::defaultDate($setup, $closureByDate, $date);
            $weekday=SchoolCalendar::day($displayDate)->dayOfWeek;
        }
        $start=SchoolCalendar::day($displayDate)->startOfWeek(1);
        $end=$start->addDays(6)->toDateString();
        $weekdays=$setup['weekdays'];
        $schoolDays=array_column($setup['days'], 'period_set_id', 'weekday');
        $overrides=[];
        foreach ($setup['class_days'] as $d) $overrides[$d['group_id']][$d['weekday']]=$d['period_set_id'];
        $sets=array_column($setup['sets'], 'periods', 'id');
        $days=[];
        for ($i=0; $i<7; $i++) {
            $day=$start->addDays($i); $iso=$day->toDateString(); $dw=$day->dayOfWeek;
            if (! in_array($dw, $weekdays, true)) continue;
            $state=$iso<$setup['year']['first_day'] || $iso>$setup['year']['last_day'] ? 'outside_year' : (array_key_exists($iso, $closureByDate) ? 'closure' : 'meetings');
            $rows=$state === 'meetings' ? array_values(array_filter($meetings, fn($m)=>$m['weekday']===$dw && match ($lens) {
                'teacher'=>in_array($id, array_column($m['teachers'], 'id'), true),
                'class'=>$m['group_id']===$id,
                'location'=>$m['resolved_room_id']===$id,
                default=>true,
            })) : [];
            $periods=$lens === 'class' ? ($sets[$overrides[$id][$dw]??$schoolDays[$dw]??null]??[]) : null;
            $days[]=['date'=>$iso, 'weekday'=>$dw, 'state'=>$state, 'reason'=>$closureByDate[$iso]??null, 'periods'=>$periods, 'meetings'=>array_map(fn($m)=>self::slot($m, $lens === 'teacher' ? $id : null), $rows)];
        }
        $placed=[];
        foreach ($meetings as $m) foreach ($m['teachers'] as $t) $placed[$t['id']]=true;
        $todayWeekday=SchoolCalendar::day($date)->dayOfWeek;
        // Week lenses keep their recurring snapshot's configured weekday selection.
        $ordered=$weekdays; usort($ordered, fn($a,$b)=>(($a-$todayWeekday+7)%7)<=>(($b-$todayWeekday+7)%7));
        $weekday??=$ordered[0]??$todayWeekday;
        $selected=null;
        foreach ($days as $day) if ($day['weekday']===$weekday) $selected=$day;
        $periodRows=[];
        if (in_array($weekday, $weekdays, true)) {
            foreach ($setup['classes'] as $g) {
                $sid=$overrides[$g['id']][$weekday]??$schoolDays[$weekday]??null;
                foreach ($sets[$sid]??[] as $p) $periodRows[$p['id']]=$p;
            }
            // Keep school blocks/time columns visible even before the first class is created.
            if ($setup['classes'] === []) foreach ($sets[$schoolDays[$weekday]??null]??[] as $p) $periodRows[$p['id']]=$p;
        }
        $dayMeetings=$selected['meetings']??[];
        $dayClashes=($selected['state']??'non_meeting') === 'meetings' ? array_values(array_filter($clashes, fn($c)=>$c['weekday']===$weekday)) : [];
        return ['as_of'=>$date, 'week_start'=>$start->toDateString(), 'week_end'=>$end, 'lens'=>$lens, 'selection_id'=>$id,
            'weekdays'=>$weekdays, 'days'=>$days, 'weekday'=>$weekday, 'day'=>$selected??['date'=>$start->addDays(($weekday+6)%7)->toDateString(), 'weekday'=>$weekday, 'state'=>'non_meeting', 'reason'=>null, 'meetings'=>[]],
            'columns'=>self::columns(array_values($periodRows)), 'meetings'=>$dayMeetings, 'clashes'=>$dayClashes,
            'teachers'=>$setup['teachers'], 'classes'=>array_map(fn($g)=>['id'=>$g['id'], 'name'=>$g['name']], $setup['classes']),
            'locations'=>array_map(fn($r)=>['id'=>$r['id'], 'name'=>$r['name']], $setup['rooms']),
            'placed_teacher_ids'=>array_map('intval', array_keys($placed)), 'may_edit'=>true,
            'as_of_state'=>array_key_exists($date, $closureByDate) ? 'closure' : (in_array($todayWeekday, $weekdays, true) ? 'meetings' : 'non_meeting'), 'as_of_reason'=>$closureByDate[$date]??null];
    }

    /** Search within the year, skipping closures; after its final open date use its last open meeting. */
    private static function defaultDate(array $setup, array $closures, string $date): string
    {
        $lastMeeting=null; $lastOpen=null;
        for ($day=SchoolCalendar::day($setup['year']['first_day']); $day->toDateString()<=$setup['year']['last_day']; $day=$day->addDay()) {
            if (! in_array($day->dayOfWeek, $setup['weekdays'], true)) continue;
            $lastMeeting=$day->toDateString();
            if (array_key_exists($lastMeeting, $closures)) continue;
            $lastOpen=$lastMeeting;
            if ($lastOpen >= $date) return $lastOpen;
        }
        return $lastOpen??$lastMeeting??$date;
    }

    private static function slot(array $m, ?int $teacher): array
    {
        return $m + ['href'=>null, 'co_teachers'=>array_values(array_filter($m['teachers'], fn($t)=>$t['id']!==$teacher))];
    }

    /** Split overlapping sets at every clock boundary; uncovered gaps never create columns. */
    private static function columns(array $periods): array
    {
        $bounds=[];
        foreach ($periods as $p) { $bounds[$p['starts_at']]=true; $bounds[$p['ends_at']]=true; }
        $times=array_keys($bounds); sort($times); $out=[];
        for ($i=0; $i<count($times)-1; $i++) {
            $first=$times[$i]; $last=$times[$i+1];
            $cover=array_values(array_filter($periods, fn($p)=>$p['starts_at']<=$first && $p['ends_at']>=$last));
            if ($cover === []) continue;
            $out[]=['starts_at'=>$first, 'ends_at'=>$last, 'names'=>array_values(array_unique(array_column($cover, 'name')))];
        }
        return $out;
    }
}
