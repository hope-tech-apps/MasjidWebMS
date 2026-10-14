<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Models\{ClassSubject, Group, GroupStaff, Masjid, MasjidUser, SchoolYear, TimetableClassDay, TimetableClassRoom, TimetableDay, TimetableMeeting, TimetableMeetingTeacher, TimetablePeriod, TimetablePeriodSet, TimetableRoom, User};
use App\Support\{SchoolDateAuthority, SchoolSettings, TimetableReader, OfficeSchedule, TeacherSchedule};
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
        $bounds=$year ? 'Pick a date between '.$year->first_day->format('F j, Y').' and '.$year->last_day->format('F j, Y').'.' : 'Pick a valid date.';
        $rules='nullable|date_format:Y-m-d'.($year ? '|after_or_equal:'.$year->first_day->toDateString().'|before_or_equal:'.$year->last_day->toDateString() : '');
        $data=$r->validate([$key=>$rules],[$key.'.date_format'=>$bounds,$key.'.after_or_equal'=>$bounds,$key.'.before_or_equal'=>$bounds]);
        $date=$data[$key]??$this->today((int)($year?->masjid_id??$r->route('masjid_id')));
        if ($year && ($date<$year->first_day->toDateString() || $date>$year->last_day->toDateString())) $this->refuse($key,$bounds);
        return $date;
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
        return $this->result($this->setupData((int)$masjid_id, $this->year($year_id)));
    }
    private function setupData(int $masjid_id, SchoolYear $year): array
    {
        $year_id=$year->id; $sets=TimetablePeriodSet::where('school_year_id',$year_id)->orderBy('id')->get();
        $periods=TimetablePeriod::whereIn('period_set_id',$sets->pluck('id'))->orderBy('position')->orderBy('id')->get()->groupBy('period_set_id');
        $staff=GroupStaff::whereIn('group_id',Group::where('kind','class')->select('id'))->get()->groupBy('group_id');
        $subjects=ClassSubject::orderBy('position')->get()->groupBy('group_id');
        $usual=TimetableClassRoom::get()->keyBy('group_id');
        // A teacher HERE is a login of either scoped kind whose row at this school is a teacher's: one
        // login can run lunch at a masjid and teach here (MasjidUser::kindFor). The role is what narrows it.
        $teachers=User::whereIn('type',\App\Support\OrganisationAccess::SCOPED_TYPES)->whereIn('id',MasjidUser::where('masjid_id',$masjid_id)->where('role','teacher')->select('user_id'))->orderBy('name')->get(['id','name']);
        $valid=$teachers->pluck('id')->all();
        $classes=Group::where('kind','class')->where('is_active',true)->orderBy('name')->get(['id','name'])->map(function ($g) use ($staff,$subjects,$usual,$valid) {
            $gs=($staff[$g->id]??collect())->filter(fn($s)=>in_array($s->user_id,$valid,true));
            return ['id'=>$g->id,'name'=>$g->name,'room_id'=>$usual[$g->id]->room_id??null,'teachers'=>$gs->pluck('user_id')->all(),'subjects'=>($subjects[$g->id]??collect())->filter(fn($s)=>$s->hidden_at===null)->map(fn($s)=>['id'=>$s->id,'name'=>$s->name,'teacher_ids'=>$gs->filter(fn($t)=>$t->class_subject_ids===null||in_array($s->id,$t->class_subject_ids,true))->pluck('user_id')->all()])->values()->all()];
        })->all();
        return ['today'=>$this->today((int)$masjid_id),'year'=>['id'=>$year->id,'label'=>$year->label,'first_day'=>$year->first_day->toDateString(),'last_day'=>$year->last_day->toDateString()], 'weekdays'=>$this->weekdays($year),'sets'=>$sets->map(fn($s)=>['id'=>$s->id,'name'=>$s->name,'periods'=>($periods[$s->id]??collect())->map(fn($p)=>$this->period($p))->all()])->all(),'days'=>TimetableDay::where('school_year_id',$year_id)->get()->all(),'class_days'=>TimetableClassDay::where('school_year_id',$year_id)->get()->all(),'rooms'=>TimetableRoom::orderBy('name')->get()->all(),'classes'=>$classes,'teachers'=>$teachers->all(),'class_subjects_enabled'=>SchoolSettings::classSubjects(Masjid::findOrFail($masjid_id))];
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
            $removed=$existing->keys()->diff($keep)->all(); $count=TimetableMeeting::whereIn('period_id',$removed)->count(); if($count) $this->refuse('periods',"These period rows have {$count} meeting".($count===1?'':'s').". Keep them to preserve the timetable.");
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
            if($count) $this->refuse('room',"This location has {$count} meeting".($count===1?'':'s').". Keep it to preserve the timetable.");
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
        $closure=$year->closures->first(fn($c)=>$c->closed_on->toDateString()===$date);
        $weekdays=$this->weekdays($year);
        $state=$closure ? 'closure' : (in_array(\App\Support\SchoolCalendar::day($date)->dayOfWeek,$weekdays,true) ? 'meetings' : 'non_meeting');
        $payload=['state'=>$state,'reason'=>$closure?->reason,'as_of'=>$date,'group_id'=>$g->id,'group_name'=>$g->name,'days'=>array_map(function ($day) use($days,$overrides,$periods) { $sid=$overrides[$day]->period_set_id??$days[$day]->period_set_id??null; return ['weekday'=>$day,'period_set_id'=>$sid,'periods'=>($periods[$sid]??collect())->map(fn($p)=>$this->period($p))->all()]; },$weekdays), 'meetings'=>$this->reader->meetings((int)$masjid_id,(int)$year_id,$date,'class',$g->id)];
        if ($r->boolean('include_clashes')) $payload['clashes']=$this->reader->clashes((int)$masjid_id,(int)$year_id,$date);
        return $this->result($payload);
    }
    public function clashes(Request $r,$masjid_id,$year_id): JsonResponse
    {
        $year=$this->year($year_id); return $this->result($this->reader->clashes((int)$masjid_id,(int)$year_id,$this->date($r,'as_of',$year)));
    }
    /** Read-only office lenses; selections resolve only from this school's bulk reference data. */
    public function teacherSchedule(Request $r, $masjid_id, $year_id, $teacher_id): JsonResponse { return $this->schedule($r, $masjid_id, $year_id, 'teacher', (int)$teacher_id); }
    public function classSchedule(Request $r, $masjid_id, $year_id, $group_id): JsonResponse { return $this->schedule($r, $masjid_id, $year_id, 'class', (int)$group_id); }
    public function locationSchedule(Request $r, $masjid_id, $year_id, $room_id): JsonResponse { return $this->schedule($r, $masjid_id, $year_id, 'location', (int)$room_id); }
    public function schoolSchedule(Request $r, $masjid_id, $year_id): JsonResponse { return $this->schedule($r, $masjid_id, $year_id, 'school'); }
    private function schedule(Request $r, $school, $yearId, string $lens, ?int $id = null): JsonResponse
    {
        $year=$this->year($yearId); $date=$this->date($r, 'as_of', $year);
        $data=$this->setupData((int)$school, $year);
        $choices=['teacher'=>'teachers', 'class'=>'classes', 'location'=>'rooms'];
        if ($lens !== 'school') abort_unless(in_array($id, array_column($data[$choices[$lens]], 'id'), true), 404);
        $input=$r->validate(['weekday'=>'nullable|integer|min:0|max:6']);
        $meetings=$this->reader->meetings((int)$school, (int)$yearId, $date);
        $clashes=$lens === 'school' ? $this->reader->compare($meetings, $this->reader->rosters((int)$school, (int)$yearId), $date) : [];
        $closures=$year->closures->map(fn($c)=>['date'=>$c->closed_on->toDateString(), 'reason'=>$c->reason])->all();
        $payload=OfficeSchedule::make($data, $meetings, $closures, $clashes, $date, $lens, $id, isset($input['weekday']) ? (int)$input['weekday'] : null);
        if ($lens === 'teacher') {
            // Use exactly the teacher portal's dated days, with office read-only cards instead of teacher links.
            $teacherWeek=app(TeacherSchedule::class)->read((int)$school, $id, $date);
            $payload['days']=array_map(function ($day) {
                $day['meetings']=array_map(fn($m)=>array_replace($m, ['href'=>null]+(isset($m['joint']) ? ['joint'=>array_map(fn($c)=>array_replace($c, ['href'=>null]), $m['joint'])] : [])), $day['meetings']);
                return $day;
            }, array_values(array_filter($teacherWeek['days'], fn($day)=>$day['meeting_day'])));
        }
        $payload=app(\App\Services\Schools\PeriodAttendance::class)->scheduleLinks($payload,(int)$school,true);
        return $this->result($payload)->header('Cache-Control', 'no-store');
    }
    private function meetingData(Request $r,SchoolYear $year,Masjid $org,?TimetableMeeting $old=null): array
    {
        $data=$r->validate(['group_id'=>'required|integer','weekday'=>'required|integer|min:0|max:6','period_id'=>'required|integer','kind'=>['required',Rule::in(['subject','activity','class'])],'class_subject_id'=>'nullable|integer','activity_name'=>'nullable|string|max:60','room_id'=>'nullable|integer','teacher_ids'=>'present|array','teacher_ids.*'=>'integer|distinct','effective_from'=>'required|date_format:Y-m-d','effective_until'=>'nullable|date_format:Y-m-d','confirm_clashes'=>'sometimes|boolean','clash_fingerprint'=>'nullable|string|size:64','joint_with'=>'sometimes|array','joint_with.*'=>'integer|distinct']);
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
            $valid=User::whereIn('type',\App\Support\OrganisationAccess::SCOPED_TYPES)->whereIn('id',MasjidUser::where('masjid_id',$org->id)->where('role','teacher')->select('user_id'))->whereIn('id',$data['teacher_ids'])->pluck('id')->all();
            if(count($valid)!==count($data['teacher_ids'])) abort(404);
        }
        if($data['kind']!=='activity' && $data['teacher_ids']===[]) $this->refuse('teacher_ids','Choose at least one teacher for this meeting.');
        $room=$data['room_id']??null; if($room!==null) TimetableRoom::where('active',true)->findOrFail($room);
        return ['masjid_id'=>$org->id,'school_year_id'=>$year->id,'group_id'=>$g->id,'weekday'=>$data['weekday'],'period_id'=>$p->id,'kind'=>$data['kind'],'class_subject_id'=>$data['kind']==='subject'?$sub:null,'activity_name'=>$data['kind']==='activity'?$activity:null,'room_id'=>$room,'effective_from'=>$data['effective_from'],'effective_until'=>$until,'teacher_ids'=>$data['teacher_ids'],'confirm_clashes'=>$data['confirm_clashes']??false,'clash_fingerprint'=>$data['clash_fingerprint']??null]+$this->jointWith($data);
    }
    /** Present only when the office names lessons to take this one with, so every other submission is fingerprinted as before. */
    private function jointWith(array $data): array
    {
        $ids=array_values(array_map('intval',$data['joint_with']??[])); sort($ids);
        return $ids===[]?[]:['joint_with'=>$ids];
    }
    private function candidate(array $data,SchoolYear $year): array
    {
        unset($data['confirm_clashes'], $data['clash_fingerprint'], $data['joint_with']);
        $p=TimetablePeriod::findOrFail($data['period_id']); $g=$this->group($data['group_id']); $rid=$data['room_id']??TimetableClassRoom::where('group_id',$g->id)->value('room_id');
        return $data+['id'=>0,'group_name'=>$g->name,'label'=>$data['kind']==='subject'?ClassSubject::findOrFail($data['class_subject_id'])->name:($data['kind']==='activity'?$data['activity_name']:$g->name),'starts_at'=>substr($p->starts_at,0,5),'ends_at'=>substr($p->ends_at,0,5),'resolved_room_id'=>$rid===null?null:(int)$rid,'room_name'=>$rid===null?null:TimetableRoom::findOrFail($rid)->name,'year_last_day'=>$year->last_day->toDateString(),'teachers'=>User::whereIn('id',$data['teacher_ids'])->orderBy('name')->get(['id','name'])->toArray()];
    }
    private function saveMeeting(array $data,SchoolYear $year,?TimetableMeeting $old=null,bool $copy=false): array
    {
        $all=$this->reader->meetings($year->masjid_id,$year->id,null);
        $replace=$old!==null && $data['effective_from']<=$old->effective_from && ! $this->hasPastSchoolDay($old,$year);
        $inPlace=$old!==null && $data['effective_from']<=$old->effective_from && ! $replace;
        $candidate=$this->candidate($data,$year);
        // A dated change that keeps its weekday and period is still the same joint lesson; a lesson that moves is not.
        $kept=$old!==null && (int)$old->weekday===$data['weekday'] && (int)$old->period_id===$data['period_id'];
        $with=$this->jointLessons($candidate,$data['joint_with']??[],$all,'joint_with');
        $named=$with===[]?[]:[...array_column($with,'id'),...$this->laterVersions([$candidate,...$with],$all)];
        // The clashes below are worked out with these lessons already together, so only what remains is reported.
        $mates=$this->jointMates($candidate,$year,$kept?$old->joint_key:null,$named,$all);
        if($mates!==[]) $candidate['joint']=$mates;
        $others=[];
        foreach($all as $m) {
            if($old && $m['id']===$old->id) { if($inPlace || $replace) continue; $m['effective_until']=\Carbon\CarbonImmutable::parse($data['effective_from'])->subDay()->toDateString(); }
            if($m['group_id']===$candidate['group_id'] && $m['weekday']===$candidate['weekday'] && $m['period_id']===$candidate['period_id'] && max($m['effective_from'],$candidate['effective_from'])<=min($m['effective_until']??$m['year_last_day'],$candidate['effective_until']??$candidate['year_last_day'])) $this->refuse('meeting','This class already has a meeting in this weekday and period on overlapping dates.');
            $others[]=$m;
        }
        $clashes=array_values(array_filter($this->reader->compare([...$others,$candidate],$this->reader->rosters($year->masjid_id,$year->id)),fn($c)=>in_array(0,array_column($c['meetings'],'id'),true)));
        $fingerprint=\App\Support\TimetableClashConfirmation::fingerprint($data+['changed_meeting_id'=>$old?->id],$clashes);
        if (!$copy && ($clashes || ($data['confirm_clashes'] && $data['clash_fingerprint'] !== null)) && (! $data['confirm_clashes'] || ! hash_equals($fingerprint, $data['clash_fingerprint'] ?? ''))) return ['clashes'=>$clashes,'clash_fingerprint'=>$fingerprint];
        $previousTeachers=$inPlace?array_column(collect($all)->firstWhere('id',$old->id)['teachers']??[],'id'):[];
        $teachers=$data['teacher_ids']; unset($data['teacher_ids'],$data['confirm_clashes'],$data['clash_fingerprint'],$data['joint_with']);
        $key=$kept?$old->joint_key:null;
        if($named!==[]) $key=$this->shareJointKey($year,$key,$named);
        if($replace) $old->delete();
        if($old && !$inPlace && !$replace) { $old->effective_until=\Carbon\CarbonImmutable::parse($data['effective_from'])->subDay()->toDateString(); $old->save(); }
        // An ordinary lesson never writes the key, so it still saves in the minutes of a release before the column exists.
        $row=$inPlace?$old:new TimetableMeeting(); $row->fill($data); if($key!==null || $row->joint_key!==null) $row->joint_key=$key; $row->save();
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
    /** Lessons named to be taken together with this one: this year's lessons of other classes, same weekday and clocks, dates that meet. */
    private function jointLessons(array $lesson,array $ids,array $all,string $field): array
    {
        $rows=array_values(array_filter($all,fn($m)=>in_array($m['id'],$ids,true))); abort_unless(count($rows)===count($ids),404);
        foreach($rows as $m) {
            if($m['group_id']===$lesson['group_id']) $this->refuse($field,'Choose a lesson of another class.');
            if($m['weekday']!==$lesson['weekday'] || $m['starts_at']!==$lesson['starts_at'] || $m['ends_at']!==$lesson['ends_at']) $this->refuse($field,'Classes can only take a lesson together on the same weekday at the same time.');
            if(max($m['effective_from'],$lesson['effective_from'])>min($m['effective_until']??$m['year_last_day'],$lesson['effective_until']??$lesson['year_last_day'])) $this->refuse($field,'These lessons are never in effect on the same dates.');
        }
        return $rows;
    }
    /** A lesson changed from a later date is a second stored row of the same class, weekday and period: what the office says of a lesson holds for those rows too. */
    private function laterVersions(array $lessons,array $all): array
    {
        $ids=[];
        foreach($lessons as $l) foreach($all as $m) if($m['group_id']===$l['group_id'] && $m['weekday']===$l['weekday'] && $m['period_id']===$l['period_id'] && $m['effective_from']>$l['effective_from']) $ids[]=$m['id'];
        return array_values(array_unique($ids));
    }
    /** The other classes' lessons that will carry this lesson's key once it is saved, kept by the reader's own rule: the review then reports exactly the clashes a read reports afterwards. */
    private function jointMates(array $lesson,SchoolYear $year,?string $own,array $ids,array $all): array
    {
        if($own===null && $ids===[]) return [];
        $keys=TimetableMeeting::where('school_year_id',$year->id)->whereNotNull('joint_key')->pluck('joint_key','id')->all();
        $carried=array_filter([$own,...array_map(fn($id)=>$keys[$id]??null,$ids)]);
        $mates=array_values(array_filter($all,fn($m)=>(in_array($m['id'],$ids,true) || in_array($keys[$m['id']]??null,$carried,true)) && TimetableReader::sameLesson($lesson,$m)));
        usort($mates,fn($a,$b)=>[$a['group_name'],$a['id']]<=>[$b['group_name'],$b['id']]);
        return array_map(fn($m)=>array_intersect_key($m,array_flip(['id','group_id','group_name','effective_from','effective_until'])),$mates);
    }
    /** One key for a lesson and the lessons named with it. A joint lesson any of them was already part of becomes this one. */
    private function shareJointKey(SchoolYear $year,?string $own,array $ids): string
    {
        $keys=TimetableMeeting::where('school_year_id',$year->id)->whereIn('id',$ids)->whereNotNull('joint_key')->orderBy('id')->pluck('joint_key')->unique()->values()->all();
        $key=$own??$keys[0]??(string) \Illuminate\Support\Str::uuid();
        TimetableMeeting::where('school_year_id',$year->id)->where(fn($q)=>$q->whereIn('id',$ids)->orWhereIn('joint_key',$keys))->update(['joint_key'=>$key]);
        return $key;
    }
    private function jointData(int $school,SchoolYear $year,int $id): array
    {
        $all=$this->reader->meetings($school,$year->id,null); $m=collect($all)->firstWhere('id',$id); abort_if($m===null,404);
        $brief=fn($row)=>array_intersect_key($row,array_flip(['id','group_id','group_name','label','weekday','starts_at','ends_at','effective_from','effective_until']));
        $with=array_column($m['joint']??[],'id');
        $same=array_values(array_filter($all,fn($o)=>$o['group_id']!==$m['group_id'] && $o['weekday']===$m['weekday'] && $o['starts_at']===$m['starts_at'] && $o['ends_at']===$m['ends_at']
            && max($o['effective_from'],$m['effective_from'])<=min($o['effective_until']??$o['year_last_day'],$m['effective_until']??$m['year_last_day'])));
        usort($same,fn($a,$b)=>[$a['group_name'],$a['id']]<=>[$b['group_name'],$b['id']]);
        return ['meeting'=>$brief($m),'joint'=>array_values(array_map($brief,array_filter($same,fn($o)=>in_array($o['id'],$with,true)))),'candidates'=>array_values(array_map($brief,array_filter($same,fn($o)=>!in_array($o['id'],$with,true))))];
    }
    /** A lesson, the other classes' lessons it is taken together with, and those at its weekday and clocks it could be. */
    public function jointLesson($masjid_id,$year_id,$meeting_id): JsonResponse
    {
        return $this->result($this->jointData((int)$masjid_id,$this->year($year_id),(int)$meeting_id));
    }
    /** Undated, like a class's usual location: the stored lessons, and their later dated versions, are one lesson wherever their dates meet. */
    public function joinLessons(Request $r,$masjid_id,$year_id,$meeting_id): JsonResponse
    {
        return $this->write($masjid_id,$year_id,function($year) use($r,$meeting_id) {
            $m=TimetableMeeting::where('school_year_id',$year->id)->findOrFail($meeting_id);
            $d=$r->validate(['meeting_ids'=>'required|array|min:1','meeting_ids.*'=>'integer|distinct']);
            $all=$this->reader->meetings($year->masjid_id,$year->id,null); $lesson=collect($all)->firstWhere('id',$m->id); abort_if($lesson===null,404);
            $with=$this->jointLessons($lesson,array_map('intval',$d['meeting_ids']),$all,'meeting_ids');
            $this->shareJointKey($year,$m->joint_key,[$m->id,...array_column($with,'id'),...$this->laterVersions([$lesson,...$with],$all)]);
            return $this->result($this->jointData($year->masjid_id,$year,$m->id));
        });
    }
    /** Takes this class's lesson out, with its later dated versions, as joining took them in. The others stay together; a class left alone reads as an ordinary lesson. */
    public function leaveJointLesson($masjid_id,$year_id,$meeting_id): JsonResponse
    {
        return $this->write($masjid_id,$year_id,function($year) use($meeting_id) {
            $m=TimetableMeeting::where('school_year_id',$year->id)->findOrFail($meeting_id);
            if($m->joint_key!==null) TimetableMeeting::where('school_year_id',$year->id)->where('joint_key',$m->joint_key)->where('group_id',$m->group_id)->where('weekday',$m->weekday)->where('period_id',$m->period_id)->whereDate('effective_from','>=',substr($m->effective_from,0,10))->update(['joint_key'=>null]);
            return $this->result($this->jointData($year->masjid_id,$year,$m->id));
        });
    }
    /** History follows the stored weekday, even when the calendar's current weekdays have changed. */
    private function hasPastSchoolDay(TimetableMeeting $meeting, SchoolYear $year): bool
    {
        $authority=SchoolDateAuthority::for($year->masjid_id);
        $last=min($meeting->effective_until??$year->last_day->toDateString(), \Carbon\CarbonImmutable::parse($authority->today())->subDay()->toDateString());
        $first=\App\Support\SchoolCalendar::day(max($meeting->effective_from,$year->first_day->toDateString()));
        $closures=$year->closures->keyBy(fn($c)=>$c->closed_on->toDateString());
        $day=$first->addDays(((int)$meeting->weekday-$first->dayOfWeek+7)%7);
        for (; $day->toDateString()<=$last; $day=$day->addWeek()) {
            if (! $closures->has($day->toDateString())) return true;
        }
        return false;
    }
    public function destroyMeeting(Request $r,$masjid_id,$year_id,$meeting_id): JsonResponse
    {
        return $this->write($masjid_id,$year_id,function($year) use($r,$meeting_id) {
            $m=TimetableMeeting::where('school_year_id',$year->id)->findOrFail($meeting_id); $data=$r->validate(['effective_from'=>'required|date_format:Y-m-d']); $date=$data['effective_from'];
            if($date<$year->first_day->toDateString() || $date>$year->last_day->toDateString()) $this->refuse('effective_from','Choose a date within this school year.');
            if($date<=$m->effective_from && ! $this->hasPastSchoolDay($m,$year)) $m->delete();
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
