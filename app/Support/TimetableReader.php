<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/** Bulk date reader: two queries for every lens, three for computed clashes. */
final class TimetableReader
{
    public function meetings(int $school, int $year, ?string $date, string $lens = 'school', ?int $id = null, ?int $weekday = null): array
    {
        if (! in_array($lens, ['teacher','class','room','school'], true)) throw new \InvalidArgumentException('Unknown timetable view.');
        $q = DB::table('timetable_meetings as m')->join('school_years as y', 'y.id', '=', 'm.school_year_id')
            ->join('groups as g', 'g.id', '=', 'm.group_id')->join('timetable_periods as p', 'p.id', '=', 'm.period_id')
            ->leftJoin('class_subjects as s', 's.id', '=', 'm.class_subject_id')
            ->leftJoin('timetable_class_rooms as cr', function ($join) { $join->on('cr.group_id','=','m.group_id')->on('cr.masjid_id','=','m.masjid_id'); })
            ->leftJoin('timetable_rooms as r', 'r.id', '=', DB::raw('COALESCE(m.room_id, cr.room_id)'))
            ->where('m.masjid_id', $school)->where('m.school_year_id', $year)
            ->where('g.masjid_id',$school)->where('y.masjid_id',$school)->where('p.masjid_id',$school);
        if ($date !== null) $q->whereDate('m.effective_from','<=',$date)->where(fn($q)=>$q->whereNull('m.effective_until')->orWhereDate('m.effective_until','>=',$date))
            ->whereDate('y.first_day','<=',$date)->whereDate('y.last_day','>=',$date);
        if ($lens === 'class') $q->where('m.group_id',$id);
        if ($lens === 'room') $q->whereRaw('COALESCE(m.room_id, cr.room_id) = ?',[$id]);
        if ($lens === 'teacher') $q->whereExists(fn($q)=>$q->selectRaw('1')->from('timetable_meeting_teachers as mt')->whereColumn('mt.meeting_id','m.id')->where('mt.masjid_id',$school)->where('mt.user_id',$id));
        if ($weekday !== null) $q->where('m.weekday',$weekday);
        $rows = $q->select(['m.*','g.name as group_name','s.name as subject_name','p.name as period_name','p.starts_at','p.ends_at','r.name as room_name', 'y.last_day as year_last_day', DB::raw('COALESCE(m.room_id, cr.room_id) as resolved_room_id')])
            ->orderBy('m.weekday')->orderBy('p.starts_at')->orderBy('m.id')->get();
        $teachers = DB::table('timetable_meeting_teachers as mt')->join('users as u','u.id','=','mt.user_id')
            ->where('mt.masjid_id',$school)->whereIn('mt.meeting_id',$rows->pluck('id'))->select(['mt.meeting_id','u.id','u.name'])->orderBy('u.name')->orderBy('u.id')->get()->groupBy('meeting_id');
        return $rows->map(function ($row) use ($teachers) {
            $m = (array) $row;
            foreach (['id','masjid_id','school_year_id','group_id','period_id','weekday'] as $key) $m[$key]=(int)$m[$key];
            foreach (['room_id','resolved_room_id','class_subject_id'] as $key) $m[$key]=$m[$key]===null?null:(int)$m[$key];
            foreach (['effective_from','effective_until','year_last_day'] as $key) if ($m[$key]!==null) $m[$key]=substr($m[$key],0,10);
            foreach (['starts_at','ends_at'] as $key) $m[$key]=substr($m[$key],0,5);
            $m['label']=$m['kind']==='subject'?$m['subject_name']:($m['kind']==='activity'?$m['activity_name']:'Whole class');
            $m['teachers']=($teachers[$m['id']]??collect())->map(fn($t)=>['id'=>(int)$t->id,'name'=>$t->name])->all();
            unset($m['created_at'],$m['updated_at'],$m['masjid_id']);
            return $m;
        })->all();
    }

    public function rosters(int $school, int $year): array
    {
        return DB::table('group_memberships as gm')->join('groups as g','g.id','=','gm.group_id')
            ->join('contacts as c','c.id','=','gm.contact_id')->where('gm.masjid_id',$school)->where('g.masjid_id',$school)->where('c.masjid_id',$school)
            ->where('gm.role','member')->select(['gm.group_id','gm.contact_id','gm.created_at','gm.moved_on','gm.moved_from_group_id','gm.left_on','c.first_name','c.last_name'])->get()
            ->map(fn($r)=>(array)$r)->all();
    }

    public function clashes(int $school, int $year, string $date): array
    {
        return $this->compare($this->meetings($school,$year,$date),$this->rosters($school,$year),$date);
    }

    /** Used by the writer as well, before saving any row, against all intersecting dated versions. */
    public function compare(array $meetings, array $rosters, ?string $asOf = null): array
    {
        $students=[];
        foreach ($rosters as $r) $students[(int)$r['group_id']][]=$r;
        $out=[];
        for ($i=0;$i<count($meetings);$i++) for ($j=$i+1;$j<count($meetings);$j++) {
            $a=$meetings[$i]; $b=$meetings[$j];
            if ($a['group_id']===$b['group_id'] || $a['weekday']!==$b['weekday'] || $a['starts_at'] >= $b['ends_at'] || $b['starts_at'] >= $a['ends_at']) continue;
            $first=max($a['effective_from'],$b['effective_from']); $last=min($a['effective_until']??$a['year_last_day'],$b['effective_until']??$b['year_last_day']);
            if (! $this->containsWeekday($first,$last,$a['weekday'])) continue;
            $add=function ($kind,$id,$name,$from,$until) use (&$out,$a,$b) { $out[]=['kind'=>$kind,'id'=>(int)$id,'name'=>$name,'weekday'=>$a['weekday'],'effective_from'=>$from,'effective_until'=>$until,'meetings'=>[$a,$b]]; };
            $bt=array_column($b['teachers'],'name','id');
            foreach ($a['teachers'] as $t) if (isset($bt[$t['id']])) $add('teacher',$t['id'],$t['name'],$first,$last);
            if ($a['resolved_room_id']!==null && $a['resolved_room_id']===$b['resolved_room_id']) $add('room',$a['resolved_room_id'],$a['room_name'],$first,$last);
            foreach ($students[$a['group_id']]??[] as $ra) foreach ($students[$b['group_id']]??[] as $rb) {
                if ((int)$ra['contact_id']!==(int)$rb['contact_id']) continue;
                $startA=substr(($ra['moved_from_group_id']!==null?$ra['moved_on']:null)??$ra['created_at'],0,10);
                $startB=substr(($rb['moved_from_group_id']!==null?$rb['moved_on']:null)??$rb['created_at'],0,10);
                $sf=max($first,$startA,$startB); $sl=$last;
                foreach ([$ra,$rb] as $r) if ($r['left_on']!==null) $sl=min($sl,\Carbon\CarbonImmutable::parse($r['left_on'])->subDay()->toDateString());
                if ($asOf!==null && ($asOf<$sf || $asOf>$sl)) continue;
                if ($this->containsWeekday($sf,$sl,$a['weekday'])) $add('student',$ra['contact_id'],trim($ra['first_name'].' '.$ra['last_name']),$sf,$sl);
            }
        }
        return $out;
    }

    private function containsWeekday(string $first, string $last, int $weekday): bool
    {
        if ($first>$last) return false;
        $d=\Carbon\CarbonImmutable::parse($first); $d=$d->addDays(($weekday-$d->dayOfWeek+7)%7);
        return $d->toDateString()<=$last;
    }
}
