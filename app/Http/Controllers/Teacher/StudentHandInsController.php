<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\{ClassAssignment, Group, GroupMembership, Masjid, StudentHandIn, StudentWorkSetting};
use App\Services\Student\StudentHandIns;
use App\Support\{ClassSubjectMode, SubjectFence};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Validator};

final class StudentHandInsController extends Controller
{
    private function work(Request $request, int $groupId, int $workId): ClassAssignment
    {
        $g=Group::findOrFail($groupId);
        $a=ClassAssignment::where('masjid_id',$g->masjid_id)->where('group_id',$g->id)->findOrFail($workId);
        if ($request->user()->type === 'Teacher') {
            $limits=SubjectFence::limitsFor($request->user(),$g->id);
            $allowed=ClassSubjectMode::enabled($g->masjid_id) ? SubjectFence::allowsWork($limits,$a->class_subject_id) : SubjectFence::allows($limits,$a->subject_key);
            abort_unless($allowed,404);
        }
        return $a;
    }
    public function index(Request $request, int $masjid_id, int $group_id, int $assignment_id)
    {
        $a=$this->work($request,$group_id,$assignment_id);
        $setting=StudentWorkSetting::where('masjid_id',$masjid_id)->where('class_assignment_id',$a->id)->first();
        $history=StudentHandIn::where('masjid_id',$masjid_id)->where('class_assignment_id',$a->id)->orderByDesc('version')->get()->groupBy('group_membership_id');
        $members=GroupMembership::where('masjid_id',$masjid_id)->where('group_id',$group_id)->whereIn('id',$history->keys())->with('contact:id,first_name,last_name')->get();
        return response()->api(200,'',['students_hand_in'=>(bool)$setting?->students_hand_in,'due_on'=>$setting?->due_on?->toDateString(),
            'students'=>$members->map(fn($m)=>['membership_id'=>(int)$m->id,'name'=>$m->contact ? trim($m->contact->first_name.' '.$m->contact->last_name) : 'Former student',
                'marked'=>StudentHandIns::marked($a,$m),'history'=>$history[$m->id]->map([StudentHandIns::class,'payload'])->all()])->values()]);
    }
    public function configure(Request $request, int $masjid_id, int $group_id, int $assignment_id)
    {
        $data=Validator::make($request->all(),['students_hand_in'=>['required','boolean'],'due_on'=>['nullable','date_format:Y-m-d']])->validate();
        return DB::transaction(function () use ($request,$masjid_id,$group_id,$assignment_id,$data) {
            Masjid::whereKey($masjid_id)->lockForUpdate()->firstOrFail();
            $a=$this->work($request,$group_id,$assignment_id); ClassAssignment::whereKey($a->id)->lockForUpdate()->firstOrFail();
            StudentWorkSetting::updateOrCreate(['masjid_id'=>$masjid_id,'class_assignment_id'=>$a->id],$data);
            return response()->api(200,'Hand-in settings saved.');
        });
    }
    public function download(Request $request, int $masjid_id, int $group_id, int $assignment_id, int $hand_in_id)
    {
        $a=$this->work($request,$group_id,$assignment_id);
        return StudentHandIns::download(StudentHandIn::where('masjid_id',$masjid_id)->where('class_assignment_id',$a->id)->findOrFail($hand_in_id));
    }
}
