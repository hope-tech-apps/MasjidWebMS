<?php

namespace App\Support;

/** Pure projection of bulk reference data and an as-of snapshot, with no per-person/class reads. */
final class OfficeSchedule
{
    /** Whole-school defaults advance to an open day and place each meeting in one school-period column. */
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
            // A teacher's or a location's day shows a lesson two classes take together once, with every class's teachers; a class's own day keeps its row.
            if (in_array($lens, ['teacher','location'], true)) $rows=TimetableReader::asOneLesson($rows);
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
            $periodRows=$sets[$schoolDays[$weekday]??null]??[];
            // Without a school scale, retain whole rows from distinct class sets, never time slices.
            if ($periodRows === []) {
                foreach ($setup['classes'] as $g) {
                    foreach ($sets[$overrides[$g['id']][$weekday]??null]??[] as $p) $periodRows[$p['id']]=$p;
                }
                $periodRows=array_values($periodRows);
                usort($periodRows, fn($a,$b)=>[$a['starts_at'],$a['ends_at'],$a['id']]<=>[$b['starts_at'],$b['ends_at'],$b['id']]);
            }
        }
        $columns=self::columns($periodRows);
        $dayMeetings=$selected['meetings']??[];
        $other=false;
        foreach ($dayMeetings as &$meeting) {
            $meeting['column_key']=self::columnFor($meeting, $columns);
            if ($meeting['column_key'] === 'other') $other=true;
        }
        unset($meeting);
        if ($other) $columns[]=['key'=>'other', 'starts_at'=>null, 'ends_at'=>null, 'names'=>['Other times'], 'period_ids'=>[]];
        $dayClashes=($selected['state']??'non_meeting') === 'meetings' ? array_values(array_filter($clashes, fn($c)=>$c['weekday']===$weekday)) : [];
        return ['as_of'=>$date, 'week_start'=>$start->toDateString(), 'week_end'=>$end, 'lens'=>$lens, 'selection_id'=>$id,
            'weekdays'=>$weekdays, 'days'=>$days, 'weekday'=>$weekday, 'day'=>$selected??['date'=>$start->addDays(($weekday+6)%7)->toDateString(), 'weekday'=>$weekday, 'state'=>'non_meeting', 'reason'=>null, 'meetings'=>[]],
            'columns'=>$columns, 'meetings'=>$dayMeetings, 'clashes'=>$dayClashes,
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

    /** Equal clocks share a heading; every full teaching row and block remains a column. */
    private static function columns(array $periods): array
    {
        $out=[]; $byClock=[];
        foreach ($periods as $p) {
            $clock=$p['starts_at'].'-'.$p['ends_at'];
            if (! isset($byClock[$clock])) {
                $byClock[$clock]=count($out);
                $out[]=['key'=>'period:'.$p['id'], 'starts_at'=>$p['starts_at'], 'ends_at'=>$p['ends_at'], 'names'=>[], 'period_ids'=>[]];
            }
            $index=$byClock[$clock];
            $out[$index]['period_ids'][]=$p['id'];
            if (! in_array($p['name'], $out[$index]['names'], true)) $out[$index]['names'][]=$p['name'];
        }
        return $out;
    }

    /** Own row, then containing start, then greatest overlap. Equal overlaps keep the earlier column. */
    private static function columnFor(array $meeting, array $columns): string
    {
        foreach ($columns as $column) if (in_array($meeting['period_id'], $column['period_ids'], true)) return $column['key'];
        foreach ($columns as $column) if ($meeting['starts_at'] >= $column['starts_at'] && $meeting['starts_at'] < $column['ends_at']) return $column['key'];
        $key='other'; $greatest=0;
        foreach ($columns as $column) {
            $overlap=self::minutes(min($meeting['ends_at'], $column['ends_at']))-self::minutes(max($meeting['starts_at'], $column['starts_at']));
            if ($overlap > $greatest) { $greatest=$overlap; $key=$column['key']; }
        }
        return $key;
    }

    private static function minutes(string $time): int
    {
        return (int) substr($time,0,2)*60+(int) substr($time,3,2);
    }
}
