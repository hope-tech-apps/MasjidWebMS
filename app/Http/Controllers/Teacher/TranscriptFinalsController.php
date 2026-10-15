<?php

namespace App\Http\Controllers\Teacher;

use App\Models\{ClassSubject, Group, Masjid};
use App\Services\Schools\Transcripts;
use App\Support\{SubjectFence, TenantContext, TranscriptMode};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TranscriptFinalsController extends TeacherController
{
    public function index($masjid_id,$group_id)
    {
        $school=Masjid::findOrFail(app(TenantContext::class)->get()); abort_unless(TranscriptMode::enabled($school),404);
        $group=Group::findOrFail($group_id); $limits=SubjectFence::limitsForWithClassSubjects(auth()->user(),(int)$group->id);
        $subjects=ClassSubject::where('group_id',$group->id)->get()->filter(fn($s)=>SubjectFence::allowsWork($limits,(int)$s->id))->pluck('id')->all();
        $rows=[];
        foreach($group->memberships()->participants()->with('contact')->get() as $child) {
            $document=app(Transcripts::class)->live($school,$child->contact,true); $years=[];
            foreach($document['years'] as $year) {
                $courses=array_values(array_filter($year['courses'],fn($course)=>in_array($course['staff']['class_subject_id'] ?? null,$subjects,true)));
                if ($courses!==[]) $years[]=['year'=>$year['year'],'courses'=>$courses];
            }
            if ($years!==[]) $rows[]=['membership_id'=>$child->id,'name'=>trim($child->contact->first_name.' '.$child->contact->last_name),'years'=>$years];
        }
        return response()->json(['status'=>'success','data'=>['students'=>$rows,'scale'=>$school->letter_grade_settings['scale'] ?? \App\Support\LetterGrades::DEFAULT_SCALE,'method'=>Transcripts::METHOD]]);
    }
    public function save(Request $request,$masjid_id,$group_id,$membership_id)
    {
        $request->validate(['class_subject_id'=>['required','integer']]);
        $group=Group::findOrFail($group_id); $child=$group->memberships()->participants()->findOrFail($membership_id);
        $subject=ClassSubject::where('group_id',$group->id)->findOrFail($request->input('class_subject_id'));
        $limits=SubjectFence::limitsForWithClassSubjects(auth()->user(),(int)$group->id);
        if (!SubjectFence::allowsWork($limits,(int)$subject->id)) return response()->api(403,'This final letter is set by its own subject teacher.');
        return DB::transaction(function () use($request,$child) {
            $school=Masjid::whereKey(app(TenantContext::class)->get())->lockForUpdate()->firstOrFail(); TranscriptMode::assertEnabled($school);
            $final=app(\App\Http\Controllers\AdminDashboard\TranscriptsController::class)->saveFinal($request,$school,$child->contact);
            return response()->json(['status'=>'success','data'=>$final]);
        });
    }
}
