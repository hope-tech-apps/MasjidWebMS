<?php

namespace App\Services\Schools;

use App\Models\{Contact, Group, GroupMembership, GroupStaff, Masjid, PeriodAttendanceRecord};
use App\Support\{PeriodAttendanceMode, SchoolCalendar, SchoolCalendarReaders, TimetableReader, StudentAllergies};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** One timetabled class/meeting register, using the day register's lock, revision and office rules. */
final class PeriodAttendance
{
    public function __construct(private AttendanceRegister $register, private TimetableReader $timetable) {}

    public function meetings(int $school, string $date): array
    {
        $calendar=SchoolCalendarReaders::for($school); $day=$calendar->schoolDay($date);
        if ($day['closed'] || ! $day['meeting_day']) return [];
        $year=$calendar->years()->first(fn ($y)=>$y->first_day->toDateString()<=$date && $y->last_day->toDateString()>=$date);
        return $year ? $this->timetable->meetings($school,(int)$year->id,$date,weekday:SchoolCalendar::day($date)->dayOfWeek) : [];
    }

    private function marks(int $school, int $meeting, string $date): \Illuminate\Support\Collection
    {
        return DB::table('period_attendance_records as marks')->leftJoin('users as marker','marker.id','=','marks.marked_by_user_id')
            ->where('marks.masjid_id',$school)->where('marks.meeting_id',$meeting)->whereDate('marks.session_date',$date)
            ->orderBy('marks.id')->get(['marks.*','marker.name','marker.type']);
    }

    /** Current roster plus that register's retained pupils; a departed child cannot acquire a new cell. */
    private function roster(Group $group, \Illuminate\Support\Collection $marks, bool $savedOnly=false): \Illuminate\Database\Eloquent\Builder
    {
        $roster=$group->memberships()->participants();
        if ($savedOnly) return $roster->whereIn('id',$marks->pluck('group_membership_id'))->getQuery();
        return $roster->where(fn($q)=>$q->whereNull('left_on')->orWhereIn('id',$marks->pluck('group_membership_id')))->getQuery();
    }

    private function resolve(int $school, int $meeting, string $date): array
    {
        abort_unless(PeriodAttendanceMode::held($school),404);
        $windows=PeriodAttendanceMode::school($school)->capability_overrides[PeriodAttendanceMode::WINDOWS] ?? [];
        abort_unless(collect($windows)->contains(fn($w)=>$date >= $w['from'] && ($w['to']===null || $date <= $w['to'])),404);
        $meetings=$this->meetings($school,$date); $live=collect($meetings)->firstWhere('id',$meeting);
        if (! $live && SchoolCalendarReaders::for($school)->schoolDay($date)['closed']) {
            $year=SchoolCalendarReaders::for($school)->years()->first(fn($y)=>$y->first_day->toDateString()<=$date && $y->last_day->toDateString()>=$date);
            if ($year) $live=collect($this->timetable->meetings($school,(int)$year->id,$date,weekday:SchoolCalendar::day($date)->dayOfWeek))->firstWhere('id',$meeting);
        }
        $marks=$this->marks($school,$meeting,$date); $saved=$marks->first();
        $row=$live ?? ($saved ? ['id'=>$meeting,'group_id'=>(int)$saved->group_id,'group_name'=>$saved->class_name,'period_id'=>(int)$saved->period_id,
            'period_name'=>$saved->period_name,'label'=>$saved->subject_name,'class_subject_id'=>$saved->class_subject_id,'starts_at'=>substr($saved->starts_at,0,5),'ends_at'=>substr($saved->ends_at,0,5),'teachers'=>array_map(fn($id)=>['id'=>$id],json_decode($saved->teacher_ids,true))] : null);
        if ($row && $saved) $row=array_replace($row, ['group_id'=>(int)$saved->group_id,'group_name'=>$saved->class_name,'period_id'=>(int)$saved->period_id,
            'period_name'=>$saved->period_name,'label'=>$saved->subject_name,'class_subject_id'=>$saved->class_subject_id,'starts_at'=>substr($saved->starts_at,0,5),'ends_at'=>substr($saved->ends_at,0,5)]);
        abort_unless($row,404);
        $group=Group::query()->where('masjid_id',$school)->where('kind','class')->findOrFail($row['group_id']);
        $teachers=array_column($row['teachers'],'id');
        if ($live) foreach ($meetings as $m) if (TimetableReader::together($live,$m)) $teachers=array_merge($teachers,array_column($m['teachers'],'id'));
        return [$row,$group,$marks,array_values(array_unique($teachers)),$live!==null];
    }

    private function authorize(Request $request, int $school, Group $group, array $teachers, bool $office): void
    {
        // Admin routes already require manage contacts. The teacher door checks only this meeting or this class.
        if ($office) return;
        $user=(int)$request->user()->id;
        abort_unless(in_array($user,array_map('intval',$teachers),true) || GroupStaff::where('masjid_id',$school)->where('group_id',$group->id)->where('user_id',$user)->exists(),403,'You do not teach this period or lead this class.');
    }

    public function read(Request $request, int $school, int $meeting, string $date, bool $office=false): JsonResponse
    {
        [$row,$group]=$this->resolve($school,$meeting,$date);
        return DB::transaction(function () use ($request,$school,$meeting,$date,$office,$group) {
            $this->register->lockClass($school,(int)$group->id);
            [$row,$group,$marks,$teachers,$live]=$this->resolve($school,$meeting,$date);
            $this->authorize($request,$school,$group,$teachers,$office);
            $day=$this->register->officeDay($school,$date,$marks->isNotEmpty());
            $readOnly=!PeriodAttendanceMode::usesPeriods($school,$date);
            $students=$day['refusal']===null ? $this->roster($group,$marks, ! $live)->with('contact:id,first_name,last_name,'.Contact::AVATAR_COLUMNS)->get() : collect();
            $byStudent=$marks->keyBy('group_membership_id');
            $data=$day+['meeting'=>array_intersect_key($row,array_flip(['id','group_id','group_name','period_id','period_name','label','starts_at','ends_at'])),
                'read_only'=>$readOnly,'taken'=>$marks->isNotEmpty(),'students'=>$students->map(function($m) use ($byStudent) {
                    $mark=$byStudent->get($m->id);
                    return app(\App\Http\Controllers\Teacher\AttendanceController::class)->studentForRegister($m) + ['status'=>$mark?->status,'note'=>$mark?->note,'marked_by'=>$mark?AttendanceRegister::marker($mark->name,$mark->type):null,'marked_at'=>$mark?\Carbon\Carbon::parse($mark->updated_at)->toIso8601String():null];
                })->all()];
            $dayMarks=app(AttendanceReader::class)->dayQuery($school)->whereDate('session_date',$date)->whereIn('group_membership_id',$students->pluck('id'))->pluck('status','group_membership_id');
            foreach ($data['students'] as &$student) $student['day_status']=$dayMarks->get($student['membership_id']);
            unset($student);
            $data['students']=StudentAllergies::decorateTeacherRoster($school,(int)$group->id,$data['students']);
            return response()->json(['status'=>'success','data'=>$data])->header(AttendanceRegister::REVISION_HEADER,$this->register->revision($marks));
        });
    }

    public function save(Request $request, int $school, int $meeting, bool $office=false): JsonResponse
    {
        $request->validate(['session_date'=>['required','date_format:Y-m-d']]);
        $date=$request->input('session_date');
        // Authority is answered before mark validation, including the unauthorised writer's 403.
        [$row,$group,$marks,$teachers]=$this->resolve($school,$meeting,(string)$date);
        $this->authorize($request,$school,$group,$teachers,$office);
        $values=$request->validate(['session_date'=>['required','date_format:Y-m-d'],'marks'=>['required','array','min:1','max:200'],
            'marks.*.membership_id'=>['required','integer','distinct'],'marks.*.status'=>['required',\Illuminate\Validation\Rule::in(\App\Models\AttendanceRecord::STATUSES)],'marks.*.note'=>['nullable','string','max:500']]);
        return DB::transaction(function () use ($request,$school,$meeting,$date,$office,$group,$values) {
            $schoolRow=$this->register->lockSchool($school);
            $this->register->lockClass($school,(int)$group->id);
            [$row,$group,$marks,$teachers,$live]=$this->resolve($school,$meeting,$date);
            $this->authorize($request,$school,$group,$teachers,$office);
            if (! PeriodAttendanceMode::usesPeriods($school,$date,$schoolRow)) throw ValidationException::withMessages(['session_date'=>'This period register is read-only. Take the day register when period attendance is switched off.']);
            $day=$this->register->officeDay($school,$date,$marks->isNotEmpty());
            if ($day['refusal']) throw ValidationException::withMessages(['session_date'=>$day['refusal']]);
            $closure=SchoolCalendar::closureFor($school,$date,lock:true);
            if ($closure) throw ValidationException::withMessages(['session_date'=>SchoolCalendar::noRegisterMessage($closure)]);
            $allowed=$this->roster($group,$marks, ! $live)->get()->keyBy('id');
            foreach ($values['marks'] as $m) if (! $allowed->has($m['membership_id'])) throw ValidationException::withMessages(['marks'=>'That register names someone who is not a current student in this class. Reload it and mark the rest.']);
            $conflict=$this->register->conflict($request,$marks,array_column($values['marks'],'membership_id'),$school);
            if ($conflict) return $conflict;
            $meetings=$this->meetings($school,$date);
            $allRosters=DB::table('group_memberships')->where('masjid_id',$school)->where('role','member')->whereNull('left_on')->get(['contact_id','group_id']);
            foreach ($values['marks'] as $m) {
                $member=$allowed->get($m['membership_id']);
                $previous=DB::table('period_attendance_records')->where('masjid_id',$school)->where('student_contact_id',$member->contact_id)->whereDate('session_date',$date)
                    ->orderBy('first_scheduled_starts_at')->orderBy('first_scheduled_meeting_id')->first(['first_scheduled_meeting_id','first_scheduled_starts_at']);
                $classes=$allRosters->where('contact_id',$member->contact_id)->pluck('group_id')->all();
                $first=$previous ? ['id'=>(int)$previous->first_scheduled_meeting_id,'starts_at'=>$previous->first_scheduled_starts_at]
                    : collect($meetings)->filter(fn($t)=>in_array($t['group_id'],$classes))->sortBy(fn($t)=>[$t['starts_at'],$t['id']])->first();
                $existing=$marks->firstWhere('group_membership_id',$member->id);
                $snapshot=$existing?[]:['group_id'=>$group->id,'group_membership_id'=>$member->id,'class_name'=>$row['group_name'],'period_id'=>$row['period_id'],'period_name'=>$row['period_name'],
                    'starts_at'=>$row['starts_at'],'ends_at'=>$row['ends_at'],'class_subject_id'=>$row['class_subject_id'],'subject_name'=>$row['label'],'teacher_ids'=>$teachers,'first_scheduled_meeting_id'=>$first['id'],'first_scheduled_starts_at'=>$first['starts_at']];
                PeriodAttendanceRecord::updateOrCreate(['masjid_id'=>$school,'session_date'=>\Carbon\Carbon::parse($date)->startOfDay(),'meeting_id'=>$meeting,'student_contact_id'=>$member->contact_id],
                    $snapshot+['status'=>$m['status'],'note'=>$m['note']??null,'marked_by_user_id'=>$request->user()->id]);
            }
            // A newly taken period supersedes a same-day resumed day register after re-enabling.
            $overrides=$schoolRow->capability_overrides;
            $changed=false;
            foreach ($values['marks'] as $mark) {
                $member=(string)$mark['membership_id']; $dates=$overrides[PeriodAttendanceMode::DAILY_DATES][$member]??[];
                if (! in_array($date,$dates,true)) continue;
                $overrides[PeriodAttendanceMode::DAILY_DATES][$member]=array_values(array_diff($dates,[$date])); $changed=true;
            }
            if ($changed) { $schoolRow->forceFill(['capability_overrides'=>$overrides])->save(); \App\Support\ClassSubjectMode::forget($school); }
            return $this->read($request,$school,$meeting,$date,$office);
        });
    }

    /** Contact merge must move office records before its source contact is force-deleted. */
    public function mergeContacts(Contact $source, Contact $target): void
    {
        abort_unless((int)$source->masjid_id === (int)$target->masjid_id,404);
        $school=$this->register->lockSchool((int)$source->masjid_id, true);
        if (! PeriodAttendanceMode::held((int)$school->id,$school)) return;
        $collision=DB::table('period_attendance_records as source')->join('period_attendance_records as target',fn($j)=>$j->on('source.masjid_id','=','target.masjid_id')->on('source.session_date','=','target.session_date')->on('source.meeting_id','=','target.meeting_id'))
            ->where('source.masjid_id',$school->id)->where('source.student_contact_id',$source->id)->where('target.student_contact_id',$target->id)->exists();
        if ($collision) throw ValidationException::withMessages(['target_contact_id'=>'These pupil records both have marks for the same period. Keep them separate so neither register is lost.']);
        DB::table('period_attendance_records')->where('masjid_id',$school->id)->where('student_contact_id',$source->id)->update(['student_contact_id'=>$target->id]);
    }

    /** Add navigation only on enabled dates; no extra timetable reads or schedule derivation. */
    public function scheduleLinks(array $page, int $school, bool $office=false): array
    {
        $org=PeriodAttendanceMode::school($school);
        if (! PeriodAttendanceMode::enabled($org)) return $page;
        $link=fn($m,$date)=>$office ? '/masjid/groups/'.$m['group_id'].'?tab=attendance&date='.$date.'&meeting='.$m['id'] : '/teacher/period-registers/'.$m['id'].'?date='.$date;
        $day=function ($day) use ($school,$org,$link,$office) {
            if (! PeriodAttendanceMode::usesPeriods($school,$day['date'],$org) || ($day['closed']??false) || ($day['state']??null)==='closure') return $day;
            foreach ($day['meetings'] as &$m) {
                $m['period_register']=$link($m,$day['date']);
                if (! $office) $m['class_attendance']='/teacher/period-registers/classes/'.$m['group_id'].'?date='.$day['date'];
                foreach ($m['joint']??[] as $i=>$j) $m['joint'][$i]['period_register']=$link($j,$day['date']);
            }
            unset($m); return $day;
        };
        $page['days']=array_map($day,$page['days']);
        foreach (['today','day'] as $key) if (isset($page[$key])) $page[$key]=$day($page[$key]);
        if ($office && isset($page['meetings'])) $page['meetings']=$page['day']['meetings'];
        return $page;
    }

    public function retained(int $school, int $class, string $date): array
    {
        return DB::table('period_attendance_records')->where('masjid_id',$school)->where('group_id',$class)->whereDate('session_date',$date)
            ->orderBy('starts_at')->get(['meeting_id','period_name','subject_name'])->unique('meeting_id')
            ->map(fn($p)=>['id'=>(int)$p->meeting_id,'label'=>$p->period_name.' · '.$p->subject_name,'taken'=>true,'read_only'=>true])->values()->all();
    }

    /** A meeting teacher can open class Attendance without acquiring other class tools. */
    public function teacherClass(Request $request, int $school, int $class, string $date): JsonResponse
    {
        abort_unless(PeriodAttendanceMode::held($school),404);
        $uses=PeriodAttendanceMode::usesPeriods($school,$date);
        $response=$uses ? $this->classDay($request,$school,$class,$date) : response()->json(['status'=>'success','data'=>$this->register->officeDay($school,$date)+[
            'period_mode'=>true,'read_only'=>true,'registers'=>$this->retained($school,$class,$date),'taken'=>false,'students'=>[]]]);
        $payload=$response->getData(true); $allowed=[];
        $leader=GroupStaff::where('masjid_id',$school)->where('group_id',$class)->where('user_id',$request->user()->id)->exists();
        $closureTeacher=false;
        if ($payload['data']['school_day']['closed']) {
            $year=SchoolCalendarReaders::for($school)->years()->first(fn($y)=>$y->first_day->toDateString()<=$date && $y->last_day->toDateString()>=$date);
            $meetings=$year?$this->timetable->meetings($school,(int)$year->id,$date,weekday:SchoolCalendar::day($date)->dayOfWeek):[];
            foreach ($meetings as $m) if ($m['group_id']===$class) {
                foreach ($meetings as $partner) if (TimetableReader::together($m,$partner)) $m['teachers']=array_merge($m['teachers'],$partner['teachers']);
                if (in_array((int)$request->user()->id,array_column($m['teachers'],'id'),true)) $closureTeacher=true;
            }
        }
        foreach ($payload['data']['registers'] as $r) {
            [$row,$group,$marks,$teachers]=$this->resolve($school,$r['id'],$date);
            $user=(int)$request->user()->id;
            if (in_array($user,array_map('intval',$teachers),true) || $leader) $allowed[]=$r;
        }
        abort_unless($allowed!==[] || $leader || $closureTeacher,403,'You do not teach a period or lead this class.');
        $payload['data']['registers']=$allowed;
        return $response->setData($payload);
    }

    /** Class screen selects a meeting; its day rows are the same projection the school board reads. */
    public function classDay(Request $request, int $school, int $class, string $date): JsonResponse
    {
        $group=Group::where('masjid_id',$school)->findOrFail($class);
        $day=$this->register->officeDay($school,$date);
        $meetings=collect($this->meetings($school,$date))->where('group_id',$class)->values();
        $saved=DB::table('period_attendance_records')->where('masjid_id',$school)->where('group_id',$class)->whereDate('session_date',$date)->get();
        $savedMeetings=$saved->unique('meeting_id')->keyBy('meeting_id');
        $registers=$meetings->map(fn($m)=>['id'=>$m['id'],'label'=>$savedMeetings->has($m['id']) ? $savedMeetings[$m['id']]->period_name.' · '.$savedMeetings[$m['id']]->subject_name : $m['period_name'].' · '.$m['label'],'taken'=>$saved->contains('meeting_id',$m['id']),'read_only'=>false])->all();
        foreach ($saved->unique('meeting_id') as $p) if (! $meetings->contains('id',(int)$p->meeting_id)) $registers[]=['id'=>(int)$p->meeting_id,'label'=>$p->period_name.' · '.$p->subject_name,'taken'=>true,'read_only'=>false];
        return response()->json(['status'=>'success','data'=>$day+['period_mode'=>true,'registers'=>$registers,'taken'=>$saved->isNotEmpty(),'students'=>[]]]);
    }
}
