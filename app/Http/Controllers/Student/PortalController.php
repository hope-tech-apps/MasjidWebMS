<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\{ClassAssignment, GroupStaff, StudentHandIn, StudentWorkSetting};
use App\Services\Student\{StudentAccess, StudentHandIns};
use App\Support\{SchoolCalendar, SchoolCalendarPayload, SchoolCalendarReaderPayload, SchoolCalendarReaders, SchoolCalendarRequestMode, SchoolDateAuthority, SchoolSettings, TimetableReader};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class PortalController extends Controller
{
    public function me(Request $request)
    {
        return response()->api(200,'',['username'=>$request->user()->username]);
    }
    public function classes(Request $request)
    {
        $a=$request->user();
        $members=StudentAccess::memberships($a->masjid_id,$a->contact_id)->with('group')->get();
        $teachers=\Illuminate\Support\Facades\DB::table('group_staff as s')->join('users as u','u.id','=','s.user_id')->where('s.masjid_id',$a->masjid_id)->whereIn('s.group_id',$members->pluck('group_id'))->get(['s.group_id','u.name'])->groupBy('group_id');
        return response()->api(200,'',$members->map(fn($m)=>['membership_id'=>(int)$m->id,'name'=>$m->group->name,
            'teachers'=>($teachers->get($m->group_id) ?? collect())->map(fn($s)=>$s->name)->filter()->unique()->values()->all()])->values());
    }
    private function member(Request $request, int $id)
    {
        return StudentAccess::memberships($request->user()->masjid_id,$request->user()->contact_id)->with('group')->findOrFail($id);
    }
    private function workFor($member, int $id): ClassAssignment
    {
        return ClassAssignment::where('masjid_id',$member->masjid_id)->where('group_id',$member->group_id)->findOrFail($id);
    }
    public function work(Request $request, int $masjid_id, int $membership_id)
    {
        $m=$this->member($request,$membership_id);
        $work=ClassAssignment::where('masjid_id',$m->masjid_id)->where('group_id',$m->group_id)->orderByDesc('assigned_on')->orderByDesc('id')->get();
        $handedIn=StudentHandIn::where('masjid_id',$m->masjid_id)->where('group_membership_id',$m->id)->whereIn('class_assignment_id',$work->pluck('id'))->distinct()->pluck('class_assignment_id')->all();
        $settings=StudentWorkSetting::where('masjid_id',$m->masjid_id)->whereIn('class_assignment_id',$work->pluck('id'))->get()->keyBy('class_assignment_id');
        return response()->api(200,'',$work->map(fn ($a) => ['id'=>(int)$a->id,'title'=>$a->title,'subject'=>$a->subject,'assigned_on'=>$a->assigned_on?->toDateString(),
            'due_on'=>$settings->get($a->id)?->due_on?->toDateString(),'students_hand_in'=>(bool)$settings->get($a->id)?->students_hand_in,'has_hand_in'=>in_array($a->id,$handedIn)])->values());
    }
    public function marks(Request $request, int $masjid_id, int $membership_id)
    {
        return response()->api(200,'',app(\App\Http\Controllers\Family\GradesController::class)->studentRead($this->member($request,$membership_id)));
    }
    public function calendar(Request $request)
    {
        $id=$request->user()->masjid_id;
        return response()->api(200,'',SchoolCalendarRequestMode::enabled($id) ? SchoolCalendarReaderPayload::reader(SchoolCalendarReaders::for($id)) : SchoolCalendarPayload::reader(SchoolCalendar::for($id)));
    }
    public function timetable(Request $request)
    {
        $data=Validator::make($request->query(),['week'=>['sometimes','date_format:Y-m-d']])->validate();
        $id=$request->user()->masjid_id; $calendar=SchoolDateAuthority::for($id);
        $start=SchoolCalendar::day($data['week'] ?? $calendar->today())->startOfWeek(1);
        $members=StudentAccess::memberships($id,$request->user()->contact_id)->get();
        $days=[];
        $org=\App\Models\Masjid::find($id);
        for($i=0;$i<7;$i++) {
            $date=$start->addDays($i)->toDateString(); $year=$calendar->years()->first(fn($y)=>$y->first_day->toDateString()<=$date && $y->last_day->toDateString()>=$date);
            $rows=[]; $closure=$year?->closures->first(fn($c)=>$c->closed_on->toDateString()===$date); $reason=$closure?->reason;
            $weekday=SchoolCalendar::day($date)->dayOfWeek;
            $meets=$year && in_array($weekday,SchoolCalendarRequestMode::enabled($id)?SchoolDateAuthority::weekdays($year):[$year->meetingWeekday()],true);
            if ($meets && $closure===null && SchoolSettings::timetable($org)) foreach($members as $m) {
                if (\App\Models\GroupMembership::membershipStart($m->getAttributes()) > $date || ($m->left_on !== null && $m->left_on->toDateString() <= $date)) continue;
                // Narrow the reader itself to this student's class, then expose only lesson facts.
                $versions=app(TimetableReader::class)->meetings($id,$year->id,$date,'class',$m->group_id,$weekday);
                foreach($versions as $v) $rows[]=array_intersect_key($v,array_flip(['id','group_name','kind','label','starts_at','ends_at','room_name']))
                    + ['teachers'=>array_column($v['teachers'],'name')];
            }
            $days[]=['date'=>$date,'closed'=>$closure!==null,'reason'=>$reason,'meetings'=>$rows];
        }
        return response()->api(200,'',['week_start'=>$start->toDateString(),'timezone'=>$calendar->timezone(),'days'=>$days]);
    }
    public function history(Request $request, int $masjid_id, int $membership_id, int $assignment_id)
    {
        $m=$this->member($request,$membership_id); return response()->api(200,'',StudentHandIns::history($this->workFor($m,$assignment_id),$m));
    }
    public function submit(Request $request, int $masjid_id, int $membership_id, int $assignment_id)
    {
        $m=$this->member($request,$membership_id); return StudentHandIns::submit($request,$this->workFor($m,$assignment_id),$m);
    }
    public function download(Request $request, int $masjid_id, int $membership_id, int $assignment_id, int $hand_in_id)
    {
        $m=$this->member($request,$membership_id); $a=$this->workFor($m,$assignment_id);
        return StudentHandIns::download(StudentHandIn::where('masjid_id',$m->masjid_id)->where('class_assignment_id',$a->id)->where('group_membership_id',$m->id)->findOrFail($hand_in_id));
    }
}
