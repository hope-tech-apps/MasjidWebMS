<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Models\{ClassSubject, Group, GroupStaff, Masjid, MasjidUser, SchoolYear, TimetableClassDay, TimetableClassRoom, TimetableDay, TimetableMeeting, TimetableMeetingTeacher, TimetablePeriod, TimetablePeriodSet, TimetableRoom, User};
use App\Support\{SchoolDateAuthority, SchoolSettings, TimetableReader};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\{Rule, ValidationException};

/** Office only, behind admin + tenant + the dark timetable grant. Same roles as calendar. */
class SchoolTimetableController extends Controller
{
    public function __construct(private TimetableReader $reader) {}

    private function result(mixed $data, int $status = 200): JsonResponse { return response()->json(['status'=>'success','data'=>$data],$status); }
    private function refuse(string $key, string $message): never { throw ValidationException::withMessages([$key=>[$message]]); }
    private function year($id): SchoolYear { return SchoolYear::findOrFail($id); }
    private function group($id): Group { return Group::where('kind','class')->where('is_active',true)->findOrFail($id); }
    private function today(int $school): string { return SchoolDateAuthority::for($school)->today(); }
    private function weekdays(SchoolYear $year): array
    {
        return SchoolSettings::calendarTerms(Masjid::findOrFail($year->masjid_id)) ? SchoolDateAuthority::weekdays($year) : [$year->meetingWeekday()];
    }
    private function date(Request $r, string $key = 'as_of', ?SchoolYear $year = null): string
    {
        $data=$r->validate([$key=>'nullable|date_format:Y-m-d']);
        return $data[$key]??$this->today((int)($year?->masjid_id??$r->route('masjid_id')));
    }
    private function write($school, $yearId, callable $fn): mixed
    {
        return DB::transaction(function () use ($school,$yearId,$fn) {
            $org=Masjid::whereKey($school)->lockForUpdate()->firstOrFail();
            abort_unless(SchoolSettings::timetable($org),404);
            $year=SchoolYear::whereKey($yearId)->lockForUpdate()->firstOrFail();
            return $fn($year,$org);
        });
    }

    public function index($masjid_id): JsonResponse
    {
        return $this->result(['today'=>$this->today((int)$masjid_id),'years'=>SchoolYear::orderByDesc('first_day')->get()->map(fn($y)=>['id'=>$y->id,'label'=>$y->label,'first_day'=>$y->first_day->toDateString(),'last_day'=>$y->last_day->toDateString()])->all()]);
    }
    public function setup($masjid_id, $year_id): JsonResponse
    {
        $year=$this->year($year_id); $sets=TimetablePeriodSet::where('school_year_id',$year_id)->orderBy('id')->get();
        $periods=TimetablePeriod::whereIn('period_set_id',$sets->pluck('id'))->orderBy('position')->orderBy('id')->get()->groupBy('period_set_id');
        $staff=GroupStaff::whereIn('group_id',Group::where('kind','class')->select('id'))->get()->groupBy('group_id');
        $subjects=ClassSubject::orderBy('position')->get()->groupBy('group_id');
        $usual=TimetableClassRoom::get()->keyBy('group_id');
        $teachers=User::where('type','Teacher')->whereIn('id',MasjidUser::where('masjid_id',$masjid_id)->where('role','teacher')->select('user_id'))->orderBy('name')->get(['id','name']);
        $valid=$teachers->pluck('id')->all();
        $classes=Group::where('kind','class')->where('is_active',true)->orderBy('name')->get(['id','name'])->map(function ($g) use ($staff,$subjects,$usual,$valid) {
            $gs=($staff[$g->id]??collect())->filter(fn($s)=>in_array($s->user_id,$valid,true));
            return ['id'=>$g->id,'name'=>$g->name,'room_id'=>$usual[$g->id]->room_id??null,'teachers'=>$gs->pluck('user_id')->all(),'subjects'=>($subjects[$g->id]??collect())->filter(fn($s)=>$s->hidden_at===null)->map(fn($s)=>['id'=>$s->id,'name'=>$s->name,'teacher_ids'=>$gs->filter(fn($t)=>$t->class_subject_ids===null||in_array($s->id,$t->class_subject_ids,true))->pluck('user_id')->all()])->values()->all()];
        })->all();
        return $this->result(['today'=>$this->today((int)$masjid_id),'year'=>['id'=>$year->id,'label'=>$year->label,'first_day'=>$year->first_day->toDateString(),'last_day'=>$year->last_day->toDateString()], 'weekdays'=>$this->weekdays($year),'sets'=>$sets->map(fn($s)=>['id'=>$s->id,'name'=>$s->name,'periods'=>($periods[$s->id]??collect())->map(fn($p)=>$this->period($p))->all()])->all(),'days'=>TimetableDay::where('school_year_id',$year_id)->get()->all(),'class_days'=>TimetableClassDay::where('school_year_id',$year_id)->get()->all(),'rooms'=>TimetableRoom::orderBy('name')->get()->all(),'classes'=>$classes,'teachers'=>$teachers->all(),'class_subjects_enabled'=>SchoolSettings::classSubjects(Masjid::findOrFail($masjid_id))]);
    }
    private function period(TimetablePeriod $p): array { return ['id'=>$p->id,'name'=>$p->name,'starts_at'=>substr($p->starts_at,0,5),'ends_at'=>substr($p->ends_at,0,5),'kind'=>$p->kind,'position'=>(int)$p->position]; }
    private function setResult(TimetablePeriodSet $s): array { return ['id'=>$s->id,'name'=>$s->name,'periods'=>TimetablePeriod::where('period_set_id',$s->id)->orderBy('position')->get()->map(fn($p)=>$this->period($p))->all()]; }
    public function storeSet(Request $r,$masjid_id,$year_id): JsonResponse { return $this->saveSet($r,$masjid_id,$year_id,null); }
    public function updateSet(Request $r,$masjid_id,$year_id,$set_id): JsonResponse { return $this->saveSet($r,$masjid_id,$year_id,$set_id); }
    private function saveSet(Request $r,$school,$yearId,$setId): JsonResponse
    {
        $set=$setId===null?null:TimetablePeriodSet::where('school_year_id',$yearId)->findOrFail($setId);
        $data=$r->validate(['name'=>'required|string|max:60','periods'=>'required|array','periods.*.id'=>'nullable|integer|distinct','periods.*.name'=>'required|string|max:60','periods.*.starts_at'=>'required|date_format:H:i','periods.*.ends_at'=>'required|date_format:H:i','periods.*.kind'=>['required',Rule::in(['teaching','block'])],'periods.*.position'=>'required|integer|min:1|max:999|distinct']);
        $rows=$data['periods']; $ordered=$rows; usort($ordered,fn($a,$b)=>strcmp($a['starts_at'],$b['starts_at'])); $end='';
        foreach($ordered as $p) { if($p['ends_at']<=$p['starts_at']) $this->refuse('periods','A period must end after it starts.'); if($p['starts_at']<$end) $this->refuse('periods','Period rows in a set cannot overlap.'); $end=$p['ends_at']; }
        return $this->write($school,$yearId,function ($year) use ($data,$rows,$set,$school) {
            $s=$set??new TimetablePeriodSet(['masjid_id'=>$school,'school_year_id'=>$year->id]); $s->name=trim($data['name']); if($s->name==='') $this->refuse('name','Choose a period set name.'); $s->save();
            $existing=TimetablePeriod::where('period_set_id',$s->id)->get()->keyBy('id'); $keep=[];
            foreach($rows as $row) {
                $p=isset($row['id'])?($existing[$row['id']]??null):new TimetablePeriod(['masjid_id'=>$school,'period_set_id'=>$s->id]); abort_if($p===null,404);
                if($p->exists && $row['kind']==='block' && TimetableMeeting::where('period_id',$p->id)->where('kind','subject')->exists()) $this->refuse('periods','A period with subject meetings must remain a teaching period.');
                unset($row['id']); $p->fill($row); $p->name=trim($p->name); if($p->name==='') $this->refuse('periods','Choose a period name.'); $p->save(); $keep[]=$p->id;
            }
            $removed=$existing->keys()->diff($keep)->all(); $count=TimetableMeeting::whereIn('period_id',$removed)->count(); if($count) $this->refuse('periods',"These period rows have {$count} meetings. Keep them to preserve the timetable.");
            TimetablePeriod::whereIn('id',$removed)->delete(); return $this->result($this->setResult($s),$set===null?201:200);
        });
    }
    public function destroySet($masjid_id,$year_id,$set_id): JsonResponse
    {
        return $this->write($masjid_id,$year_id,function () use($year_id,$set_id) {
            $s=TimetablePeriodSet::where('school_year_id',$year_id)->findOrFail($set_id);
            $count=TimetableMeeting::whereIn('period_id',TimetablePeriod::where('period_set_id',$s->id)->select('id'))->count();
            if($count) $this->refuse('set',"This period set has {$count} meeting".($count===1?'':'s').'. Remove its meetings first.');
            TimetableDay::where('period_set_id',$s->id)->delete(); TimetableClassDay::where('period_set_id',$s->id)->delete(); $s->delete(); return $this->result(null);
        });
    }
    public function days(Request $r,$masjid_id,$year_id): JsonResponse
    {
        return $this->write($masjid_id,$year_id,function ($year) use($r,$masjid_id) {
            $data=$r->validate(['group_id'=>'nullable|integer','days'=>'present|array','days.*.weekday'=>'required|integer|min:0|max:6|distinct','days.*.period_set_id'=>'present|nullable|integer']);
            $g=isset($data['group_id'])?$this->group($data['group_id']):null; $allowed=$this->weekdays($year);
            foreach($data['days'] as $day) {
                if(!in_array($day['weekday'],$allowed,true)) $this->refuse('days','Choose a weekday this school year meets on.');
                $sid=$day['period_set_id']; if($sid!==null) TimetablePeriodSet::where('school_year_id',$year->id)->findOrFail($sid);
                $model=$g?TimetableClassDay::class:TimetableDay::class; $key=['school_year_id'=>$year->id,'weekday'=>$day['weekday']]; if($g) $key['group_id']=$g->id;
                $old=$model::where($key)->first();
                // Sets are whole-year clock changes. Carry cells to the corresponding ordered row.
                $affected=TimetableMeeting::where('school_year_id',$year->id)->where('weekday',$day['weekday']);
                if($g) $affected->where('group_id',$g->id); else $affected->whereNotIn('group_id',TimetableClassDay::where('school_year_id',$year->id)->where('weekday',$day['weekday'])->select('group_id'));
                $resolved=$sid??($g?TimetableDay::where('school_year_id',$year->id)->where('weekday',$day['weekday'])->value('period_set_id'):null);
                $targets=TimetablePeriod::where('period_set_id',$resolved)->get()->keyBy('position');
                $sourcePeriods=TimetablePeriod::whereIn('id',(clone $affected)->select('period_id'))->get();
                foreach($sourcePeriods as $source) {
                    if($source->period_set_id==$resolved) continue;
                    $target=$targets[$source->position]??null;
                    if($target===null) $this->refuse('days','The new set needs a row at each position that has meetings.');
                    if($target->kind!=='teaching' && (clone $affected)->where('period_id',$source->id)->where('kind','subject')->exists()) $this->refuse('days','The new set must keep subject meetings in teaching periods.');
                    (clone $affected)->where('period_id',$source->id)->update(['period_id'=>$target->id]);
                }
                if($sid===null) $old?->delete(); else $model::updateOrCreate($key,['masjid_id'=>$masjid_id,'period_set_id'=>$sid]);
            }
            return $this->result(null);
        });
    }
    public function storeRoom(Request $r,$masjid_id,$year_id): JsonResponse { return $this->saveRoom($r,$masjid_id,$year_id,null); }
    public function updateRoom(Request $r,$masjid_id,$year_id,$room_id): JsonResponse { return $this->saveRoom($r,$masjid_id,$year_id,$room_id); }
    private function saveRoom(Request $r,$school,$year,$id): JsonResponse
    {
        $room=$id===null?null:TimetableRoom::findOrFail($id);
        $data=$r->validate(['name'=>'required|string|max:60','capacity'=>'nullable|integer|min:1|max:100000','active'=>'sometimes|boolean']);
        return $this->write($school,$year,function () use($data,$room,$school) {
            $key=mb_strtolower(trim($data['name'])); if($key==='') $this->refuse('name','Choose a location name.');
            if(TimetableRoom::where('name_key',$key)->when($room,fn($q)=>$q->where('id','<>',$room->id))->exists()) $this->refuse('name','This school already has a location with that name.');
            $row=$room??new TimetableRoom(['masjid_id'=>$school]); $row->fill($data+['active'=>true,'capacity'=>null]); $row->name=trim($data['name']); $row->name_key=$key; $row->save(); return $this->result($row,$room===null?201:200);
        });
    }
    public function destroyRoom($masjid_id,$year_id,$room_id): JsonResponse
    {
        return $this->write($masjid_id,$year_id,function () use($room_id) {
            $room=TimetableRoom::findOrFail($room_id);
            $count=TimetableMeeting::where('room_id',$room_id)->orWhere(fn($q)=>$q->whereNull('room_id')->whereIn('group_id',TimetableClassRoom::where('room_id',$room_id)->select('group_id')))->count();
            if($count) $this->refuse('room',"This location has {$count} meetings. Keep it to preserve the timetable.");
            TimetableClassRoom::where('room_id',$room_id)->update(['room_id'=>null]); $room->delete(); return $this->result(null);
        });
    }
    public function classRoom(Request $r,$masjid_id,$year_id,$group_id): JsonResponse
    {
        return $this->write($masjid_id,$year_id,function () use($r,$group_id,$masjid_id) {
            $g=$this->group($group_id); $d=$r->validate(['room_id'=>'present|nullable|integer']);
            if($d['room_id']!==null) TimetableRoom::where('active',true)->findOrFail($d['room_id']);
            TimetableClassRoom::updateOrCreate(['group_id'=>$g->id],['masjid_id'=>$masjid_id,'room_id'=>$d['room_id']]); return $this->result(null);
        });
    }
    /** The class page uses the same usual-location writer without requiring a year or setup. */
    public function location($masjid_id, $group_id): JsonResponse
    {
        return $this->result($this->locationPayload($this->group($group_id)));
    }
    private function locationPayload(Group $g): array
    {
        $room = TimetableClassRoom::where('group_id', $g->id)->value('room_id');
        return ['group_id'=>$g->id,'group_name'=>$g->name,
            'location'=>$room === null ? null : TimetableRoom::findOrFail($room)->only(['id','name']),
            'locations'=>TimetableRoom::where('active',true)->orderBy('name')->get(['id','name'])->toArray()];
    }
    public function saveLocation(Request $r, $masjid_id, $group_id): JsonResponse
    {
        return DB::transaction(function () use ($r, $masjid_id, $group_id) {
            $org=Masjid::whereKey($masjid_id)->lockForUpdate()->firstOrFail();
            abort_unless(SchoolSettings::timetable($org),404);
            $g=$this->group($group_id);
            $d=$r->validate(['room_id'=>'sometimes|nullable|integer','name'=>'sometimes|nullable|string|max:60']);
            if (! array_key_exists('room_id',$d) && ! array_key_exists('name',$d)) $this->refuse('name','Choose a location, type a new name or clear the field.');
            $room=$d['room_id']??null;
            if ($room !== null) TimetableRoom::where('active',true)->findOrFail($room);
            elseif (($name=trim($d['name']??'')) !== '') {
                $key=mb_strtolower($name);
                $existing=TimetableRoom::where('name_key',$key)->first();
                if ($existing && ! $existing->active) $this->refuse('name','This location is inactive. Choose an active location or a new name.');
                $room=($existing??TimetableRoom::create(['masjid_id'=>$masjid_id,'name'=>$name,'name_key'=>$key,'active'=>true]))->id;
            }
            TimetableClassRoom::updateOrCreate(['group_id'=>$g->id],['masjid_id'=>$masjid_id,'room_id'=>$room]);
            return $this->result($this->locationPayload($g));
        });
    }

    private function setFor(SchoolYear $year,int $group,int $day): ?int
    {
        return TimetableClassDay::where('school_year_id',$year->id)->where('group_id',$group)->where('weekday',$day)->value('period_set_id')??TimetableDay::where('school_year_id',$year->id)->where('weekday',$day)->value('period_set_id');
    }
    public function week(Request $r,$masjid_id,$year_id): JsonResponse
    {
        $year=$this->year($year_id); $d=$r->validate(['group_id'=>'required|integer']); $g=$this->group($d['group_id']); $date=$this->date($r,'as_of',$year);
        $days=TimetableDay::where('school_year_id',$year_id)->get()->keyBy('weekday'); $overrides=TimetableClassDay::where('school_year_id',$year_id)->where('group_id',$g->id)->get()->keyBy('weekday');
        $periods=TimetablePeriod::whereIn('period_set_id',TimetablePeriodSet::where('school_year_id',$year_id)->select('id'))->orderBy('position')->get()->groupBy('period_set_id');
        return $this->result(['as_of'=>$date,'group_id'=>$g->id,'group_name'=>$g->name,'days'=>array_map(function ($day) use($days,$overrides,$periods) { $sid=$overrides[$day]->period_set_id??$days[$day]->period_set_id??null; return ['weekday'=>$day,'period_set_id'=>$sid,'periods'=>($periods[$sid]??collect())->map(fn($p)=>$this->period($p))->all()]; },$this->weekdays($year)), 'meetings'=>$this->reader->meetings((int)$masjid_id,(int)$year_id,$date,'class',$g->id)]);
    }
    public function clashes(Request $r,$masjid_id,$year_id): JsonResponse
    {
        $year=$this->year($year_id); return $this->result($this->reader->clashes((int)$masjid_id,(int)$year_id,$this->date($r,'as_of',$year)));
    }
    private function meetingData(Request $r,SchoolYear $year,Masjid $org,?TimetableMeeting $old=null): array
    {
        $data=$r->validate(['group_id'=>'required|integer','weekday'=>'required|integer|min:0|max:6','period_id'=>'required|integer','kind'=>['required',Rule::in(['subject','activity','class'])],'class_subject_id'=>'nullable|integer','activity_name'=>'nullable|string|max:60','room_id'=>'nullable|integer','teacher_ids'=>'present|array','teacher_ids.*'=>'integer|distinct','effective_from'=>'required|date_format:Y-m-d','effective_until'=>'nullable|date_format:Y-m-d','confirm_clashes'=>'sometimes|boolean','clash_fingerprint'=>'nullable|string|size:64']);
        if ($old && $old->effective_until !== null && $data['effective_from'] > $old->effective_until) $this->refuse('effective_from','This meeting has already ended before that date. Choose the meeting live on that date.');
        $g=$this->group($data['group_id']); if($old && $old->group_id!=$g->id) $this->refuse('group_id','Change this meeting within its own class.');
        $p=TimetablePeriod::whereIn('period_set_id',TimetablePeriodSet::where('school_year_id',$year->id)->select('id'))->findOrFail($data['period_id']);
        if(!in_array($data['weekday'],$this->weekdays($year),true)) $this->refuse('weekday','Choose a weekday this school year meets on.');
        if($p->period_set_id!=$this->setFor($year,$g->id,$data['weekday'])) $this->refuse('period_id','Choose a period from this class weekday’s period set.');
        if($data['effective_from']<$year->first_day->toDateString() || $data['effective_from']>$year->last_day->toDateString()) $this->refuse('effective_from','Choose a date within this school year, never before its first day.');
        $until=$data['effective_until']??($old?->effective_until); if($until!==null && ($until<$data['effective_from'] || $until>$year->last_day->toDateString())) $this->refuse('effective_until','The last date must be on or after the first date, within this school year.');
        $sub=$data['class_subject_id']??null; $activity=trim($data['activity_name']??'');
        if($sub!==null) ClassSubject::where('group_id',$g->id)->findOrFail($sub);
        if($data['kind']==='subject') {
            if(!SchoolSettings::classSubjects($org)) $this->refuse('kind','Switch on class subjects before placing a subject.');
            if($sub===null || $activity!=='' || $p->kind!=='teaching') $this->refuse('kind','Place one subject in a teaching period.');
            if(ClassSubject::findOrFail($sub)->hidden_at!==null) $this->refuse('class_subject_id','Choose a visible class subject.');
        } elseif($data['kind']==='class') {
            if(SchoolSettings::classSubjects($org)) $this->refuse('kind','Choose a subject or activity when class subjects are on.');
            if($sub!==null || $activity!=='') $this->refuse('kind','A whole-class meeting cannot also name a subject or activity.');
        } elseif($activity==='' || $sub!==null) $this->refuse('activity_name','Name one activity, without a subject.');
        if($data['teacher_ids']!==[]) {
            $valid=User::where('type','Teacher')->whereIn('id',MasjidUser::where('masjid_id',$org->id)->where('role','teacher')->select('user_id'))->whereIn('id',$data['teacher_ids'])->pluck('id')->all();
            if(count($valid)!==count($data['teacher_ids'])) abort(404);
        }
        if($data['kind']!=='activity' && $data['teacher_ids']===[]) $this->refuse('teacher_ids','Choose at least one teacher for this meeting.');
        $room=$data['room_id']??null; if($room!==null) TimetableRoom::where('active',true)->findOrFail($room);
        return ['masjid_id'=>$org->id,'school_year_id'=>$year->id,'group_id'=>$g->id,'weekday'=>$data['weekday'],'period_id'=>$p->id,'kind'=>$data['kind'],'class_subject_id'=>$data['kind']==='subject'?$sub:null,'activity_name'=>$data['kind']==='activity'?$activity:null,'room_id'=>$room,'effective_from'=>$data['effective_from'],'effective_until'=>$until,'teacher_ids'=>$data['teacher_ids'],'confirm_clashes'=>$data['confirm_clashes']??false,'clash_fingerprint'=>$data['clash_fingerprint']??null];
    }
    private function candidate(array $data,SchoolYear $year): array
    {
        unset($data['confirm_clashes'], $data['clash_fingerprint']);
        $p=TimetablePeriod::findOrFail($data['period_id']); $g=$this->group($data['group_id']); $rid=$data['room_id']??TimetableClassRoom::where('group_id',$g->id)->value('room_id');
        return $data+['id'=>0,'group_name'=>$g->name,'label'=>$data['kind']==='subject'?ClassSubject::findOrFail($data['class_subject_id'])->name:($data['kind']==='activity'?$data['activity_name']:$g->name),'starts_at'=>substr($p->starts_at,0,5),'ends_at'=>substr($p->ends_at,0,5),'resolved_room_id'=>$rid===null?null:(int)$rid,'room_name'=>$rid===null?null:TimetableRoom::findOrFail($rid)->name,'year_last_day'=>$year->last_day->toDateString(),'teachers'=>User::whereIn('id',$data['teacher_ids'])->orderBy('name')->get(['id','name'])->toArray()];
    }
    private function saveMeeting(array $data,SchoolYear $year,?TimetableMeeting $old=null,bool $copy=false): array
    {
        $all=$this->reader->meetings($year->masjid_id,$year->id,null);
        $inPlace=$old!==null && $data['effective_from']<=$old->effective_from;
        $candidate=$this->candidate($data,$year);
        $others=[];
        foreach($all as $m) {
            if($old && $m['id']===$old->id) { if($inPlace) continue; $m['effective_until']=\Carbon\CarbonImmutable::parse($data['effective_from'])->subDay()->toDateString(); }
            if($m['group_id']===$candidate['group_id'] && $m['weekday']===$candidate['weekday'] && $m['period_id']===$candidate['period_id'] && max($m['effective_from'],$candidate['effective_from'])<=min($m['effective_until']??$m['year_last_day'],$candidate['effective_until']??$candidate['year_last_day'])) $this->refuse('meeting','This class already has a meeting in this weekday and period on overlapping dates.');
            $others[]=$m;
        }
        $clashes=array_values(array_filter($this->reader->compare([...$others,$candidate],$this->reader->rosters($year->masjid_id,$year->id)),fn($c)=>in_array(0,array_column($c['meetings'],'id'),true)));
        $fingerprint=\App\Support\TimetableClashConfirmation::fingerprint($data+['changed_meeting_id'=>$old?->id],$clashes);
        if (!$copy && ($clashes || ($data['confirm_clashes'] && $data['clash_fingerprint'] !== null)) && (! $data['confirm_clashes'] || ! hash_equals($fingerprint, $data['clash_fingerprint'] ?? ''))) return ['clashes'=>$clashes,'clash_fingerprint'=>$fingerprint];
        $previousTeachers=$inPlace?array_column(collect($all)->firstWhere('id',$old->id)['teachers']??[],'id'):[];
        $teachers=$data['teacher_ids']; unset($data['teacher_ids'],$data['confirm_clashes'],$data['clash_fingerprint']);
        if($old && !$inPlace) { $old->effective_until=\Carbon\CarbonImmutable::parse($data['effective_from'])->subDay()->toDateString(); $old->save(); }
        $row=$inPlace?$old:new TimetableMeeting(); $row->fill($data); $row->save();
        TimetableMeetingTeacher::where('meeting_id',$row->id)->delete(); foreach($teachers as $id) TimetableMeetingTeacher::create(['masjid_id'=>$year->masjid_id,'meeting_id'=>$row->id,'user_id'=>$id]);
        $row->setAttribute('group_name',$candidate['group_name']); $row->setAttribute('label',$candidate['label']);
        if ($previousTeachers) \App\Support\TimetableRetention::instance()->refreshAccounts($previousTeachers);
        return ['meeting'=>$row]+($copy?['copy_clashes'=>$clashes]:[]);
    }
    public function storeMeeting(Request $r,$masjid_id,$year_id): JsonResponse { return $this->meeting($r,$masjid_id,$year_id,null); }
    public function updateMeeting(Request $r,$masjid_id,$year_id,$meeting_id): JsonResponse { return $this->meeting($r,$masjid_id,$year_id,$meeting_id); }
    private function meeting(Request $r,$school,$year,$id): JsonResponse
    {
        return $this->write($school,$year,function($y,$org) use($r,$id) {
            $old=$id===null?null:TimetableMeeting::where('school_year_id',$y->id)->findOrFail($id);
            $saved=$this->saveMeeting($this->meetingData($r,$y,$org,$old),$y,$old);
            return isset($saved['clashes'])?response()->json(['status'=>'clashes','clashes'=>$saved['clashes'],'clash_fingerprint'=>$saved['clash_fingerprint']],409):$this->result($saved['meeting'],$id===null?201:200);
        });
    }
    public function destroyMeeting(Request $r,$masjid_id,$year_id,$meeting_id): JsonResponse
    {
        return $this->write($masjid_id,$year_id,function($year) use($r,$meeting_id) {
            $m=TimetableMeeting::where('school_year_id',$year->id)->findOrFail($meeting_id); $data=$r->validate(['effective_from'=>'required|date_format:Y-m-d']); $date=$data['effective_from'];
            if($date<$year->first_day->toDateString() || $date>$year->last_day->toDateString()) $this->refuse('effective_from','Choose a date within this school year.');
            if($date<=$m->effective_from && $m->effective_from>$this->today($year->masjid_id)) $m->delete();
            else { $until=\Carbon\CarbonImmutable::parse($date)->subDay()->toDateString(); if ($m->effective_until === null || $until < $m->effective_until) { $m->effective_until=$until; $m->save(); } }
            return $this->result(null);
        });
    }
    public function copyDay(Request $r,$masjid_id,$year_id): JsonResponse
    {
        return $this->write($masjid_id,$year_id,function($year,$org) use($r) {
            $d=$r->validate(['group_id'=>'required|integer','source_weekday'=>'required|integer|min:0|max:6','target_weekdays'=>'required|array|min:1','target_weekdays.*'=>'integer|min:0|max:6|distinct','as_of'=>'required|date_format:Y-m-d','effective_from'=>'required|date_format:Y-m-d','confirm_clashes'=>'sometimes|boolean','clash_fingerprint'=>'nullable|string|size:64']);
            $g=$this->group($d['group_id']); $source=$this->reader->meetings($year->masjid_id,$year->id,$d['as_of'],'class',$g->id,$d['source_weekday']);
            $warnings=[];
            // A nested transaction rolls back all tentative copies if any warning needs confirmation.
            try {
                DB::transaction(function () use($d,$year,$org,$r,$g,$source,&$warnings) {
                    foreach($d['target_weekdays'] as $day) {
                        if($day===$d['source_weekday'] || $this->setFor($year,$g->id,$day)!==$this->setFor($year,$g->id,$d['source_weekday'])) $this->refuse('target_weekdays','Copy to another weekday using the same period set.');
                        foreach($source as $m) {
                            $draft=new Request(); $draft->replace(['group_id'=>$g->id,'weekday'=>$day,'period_id'=>$m['period_id'],'kind'=>$m['kind'],'class_subject_id'=>$m['class_subject_id'],'activity_name'=>$m['activity_name'],'room_id'=>$m['room_id'],'teacher_ids'=>array_column($m['teachers'],'id'),'effective_from'=>$d['effective_from'],'confirm_clashes'=>$d['confirm_clashes']??false]);
                            $saved=$this->saveMeeting($this->meetingData($draft,$year,$org),$year,null,true); $warnings=[...$warnings,...$saved['copy_clashes']];
                        }
                    }
                    $fingerprint=\App\Support\TimetableClashConfirmation::fingerprint($d,$warnings);
                    if(($warnings || (($d['confirm_clashes']??false) && isset($d['clash_fingerprint']))) && (! ($d['confirm_clashes']??false) || ! hash_equals($fingerprint,$d['clash_fingerprint']??''))) throw new \RuntimeException('timetable-copy-clashes');
                });
            } catch(\RuntimeException $e) { if($e->getMessage()!=='timetable-copy-clashes') throw $e; return response()->json(['status'=>'clashes','clashes'=>$warnings,'clash_fingerprint'=>\App\Support\TimetableClashConfirmation::fingerprint($d,$warnings)],409); }
            return $this->result(null,201);
        });
    }
}
