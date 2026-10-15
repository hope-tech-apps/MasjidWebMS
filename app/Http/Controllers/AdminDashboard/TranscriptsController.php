<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Models\{ClassSubject, Contact, Group, GroupMembership, Masjid, TranscriptCourse, TranscriptFinal, TranscriptIssue, TranscriptPriorYear, TranscriptReceived, TranscriptSetting, TranscriptShareRequest, User};
use App\Services\Schools\{Transcripts, TranscriptPdf, TranscriptShares};
use App\Support\{GradeLevel, GroupResourceFiles, TenantContext, TranscriptMath as Math, TranscriptMode, SchoolAdmissionsMode};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Storage};
use Illuminate\Validation\{Rule, ValidationException};

class TranscriptsController extends Controller
{
    private function school(): Masjid
    {
        $school=Masjid::findOrFail(app(TenantContext::class)->get()); abort_unless(TranscriptMode::enabled($school),404); return $school;
    }
    private function student(int $id): Contact
    {
        $student=Contact::findOrFail($id);
        abort_unless(GroupMembership::where('contact_id',$id)->participants()->exists(),404); return $student;
    }
    private function ok($data,int $status=200) { return response()->json(['status'=>'success','data'=>$data],$status); }
    private function locked(callable $action)
    {
        return DB::transaction(function () use($action) { $school=Masjid::whereKey(app(TenantContext::class)->get())->lockForUpdate()->firstOrFail(); TranscriptMode::assertEnabled($school); return $action($school); });
    }
    public function index(Request $request)
    {
        $school=$this->school();
        $members=GroupMembership::participants()->get();
        $eligible=$request->boolean('show_all')?$members:$members->filter(fn($m)=>Transcripts::highSchool($m->grade_label));
        $students=Contact::whereIn('id',$eligible->pluck('contact_id'))
            ->when($request->filled('search'),fn($q)=>$q->where(fn($q)=>$q->where('first_name','like','%'.$request->input('search').'%')->orWhere('last_name','like','%'.$request->input('search').'%')))->orderBy('last_name')->orderBy('id')->paginate(50,['id','first_name','last_name']);
        $subjects=ClassSubject::whereIn('group_id',GroupMembership::participants()->get()->filter(fn($m)=>Transcripts::highSchool($m->grade_label))->pluck('group_id'))->orderBy('id')->get();
        return $this->ok(['students'=>$students,'policy'=>app(Transcripts::class)->policy($school),'scale'=>$school->letter_grade_settings['scale'] ?? \App\Support\LetterGrades::DEFAULT_SCALE,'method'=>Transcripts::METHOD,
            'subjects'=>$subjects->map(fn($s)=>['id'=>$s->id,'name'=>$s->name,'class'=>Group::find($s->group_id)?->name,'setting'=>TranscriptCourse::where('class_subject_id',$s->id)->first()]),
            'receiving_schools'=>Masjid::where('org_type','school')->where('id','<>',$school->id)->get()->filter(fn($s)=>TranscriptMode::enabled($s))->map(fn($s)=>['id'=>$s->id,'name'=>$s->name])->values(),
            'received'=>TranscriptReceived::orderByDesc('id')->get()->map(fn($row)=>$this->receivedHeader($row))]);
    }
    public function show($masjid_id,$contact_id)
    {
        $school=$this->school(); $student=$this->student((int)$contact_id);
        $issues=TranscriptIssue::where('contact_id',$student->id)->orderByDesc('id')->get();
        return $this->ok(['document'=>app(Transcripts::class)->live($school,$student,true),
            'latest_issue_stale'=>app(Transcripts::class)->issueIsStale($school,$student,$issues->first()),
            'issues'=>$issues->map(fn($issue)=>['id'=>$issue->id,'serial'=>$issue->serial,'issued_at'=>$issue->issued_at,'issued_at_display'=>SchoolAdmissionsMode::timestamp((int)$school->id,$issue->issued_at),'issued_by_name'=>$issue->snapshot['issued_by'] ?? $this->staffName($issue->issued_by_user_id)]),
            'shares'=>TranscriptShareRequest::where('contact_id',$student->id)->orderByDesc('id')->get()->map(fn($share)=>$this->shareHeader($share)),
            'received'=>TranscriptReceived::where('contact_id',$student->id)->orderByDesc('id')->get()->map(fn($row)=>$this->receivedHeader($row)),
            'memberships'=>GroupMembership::where('contact_id',$student->id)->participants()->get()->map(fn($m)=>['id'=>$m->id,'class'=>Group::find($m->group_id)?->name]),
            'prior_years'=>TranscriptPriorYear::where('contact_id',$student->id)->get()]);
    }
    public function settings(Request $request)
    {
        $data=$request->validate(['points'=>['required','array','min:1','max:40'],'honors_extra'=>['required'],'ap_extra'=>['required'],'failing_letters'=>['required','array','min:1','max:40'],'failing_letters.*'=>['required','string','distinct','max:20']]);
        return $this->locked(function($school) use($data) {
            $points=[];
            foreach($data['points'] as $letter=>$value) {
                if (!is_string($letter) || trim($letter)!==$letter || $letter==='' || mb_strlen($letter)>20) throw ValidationException::withMessages(['points'=>['Use unique, nonblank letter labels.']]);
                $points[$letter]=Math::hundredths($value,'points.'.$letter,1000);
            }
            $scale=array_column($school->letter_grade_settings['scale'] ?? \App\Support\LetterGrades::DEFAULT_SCALE,'letter');
            $used=TranscriptFinal::whereNotNull('letter')->pluck('letter')->all();
            foreach(TranscriptPriorYear::all() as $prior) $used=array_merge($used,array_column($prior->courses,'letter'));
            if (array_diff(array_merge($scale,$used,$data['failing_letters']),array_keys($points))) throw ValidationException::withMessages(['points'=>['Include every scale, recorded final and failing letter in the points table.']]);
            $row=TranscriptSetting::updateOrCreate(['masjid_id'=>$school->id],['points'=>$points,'honors_extra'=>Math::hundredths($data['honors_extra'],'honors_extra',1000),'ap_extra'=>Math::hundredths($data['ap_extra'],'ap_extra',1000),'failing_letters'=>$data['failing_letters']]);
            return $this->ok($row);
        });
    }
    public function course(Request $request,$masjid_id,$class_subject_id)
    {
        ClassSubject::findOrFail($class_subject_id);
        if ($request->has('on_transcript')) $request->merge(['on_transcript'=>filter_var($request->input('on_transcript'),FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE)]);
        $data=$request->validate(['credit'=>['nullable'],'level'=>['required',Rule::in(['regular','honors','ap'])],'on_transcript'=>['sometimes','required','boolean']]);
        $attributes=['credit_hundredths'=>isset($data['credit'])?Math::hundredths($data['credit'],'credit',10000):null,'level'=>$data['level']];
        if (array_key_exists('on_transcript',$data)) $attributes['on_transcript']=$data['on_transcript'];
        return $this->locked(fn($school)=>$this->ok(TranscriptCourse::updateOrCreate(['class_subject_id'=>$class_subject_id],$attributes)));
    }
    public function finalGrade(Request $request,$masjid_id,$contact_id)
    {
        $this->school(); $student=$this->student((int)$contact_id);
        return $this->locked(function($school) use($request,$student) { return $this->ok($this->saveFinal($request,$school,$student)); });
    }
    /** Shared with the teacher controller after its class and immutable subject authority checks. */
    public function saveFinal(Request $request,Masjid $school,Contact $student): TranscriptFinal
    {
        $data=$request->validate(['class_subject_id'=>['required','integer'],'school_year_id'=>['required','integer'],'letter'=>['nullable','string','max:20'],'reason'=>['nullable','string','max:4000']]);
        ClassSubject::findOrFail($data['class_subject_id']); \App\Models\SchoolYear::findOrFail($data['school_year_id']);
        $document=app(Transcripts::class)->live($school,$student,true); $exists=false;
        foreach($document['years'] as $year) foreach($year['courses'] as $course) if (($course['staff']['class_subject_id'] ?? null)===(int)$data['class_subject_id'] && ($course['staff']['school_year_id'] ?? null)===(int)$data['school_year_id']) $exists=true;
        abort_unless($exists,404);
        $letter=$data['letter'] ?? null; $reason=trim($data['reason'] ?? '');
        if ($letter!==null && ($reason==='' || !in_array($letter,array_column($school->letter_grade_settings['scale'] ?? \App\Support\LetterGrades::DEFAULT_SCALE,'letter'),true))) throw ValidationException::withMessages(['reason'=>['Choose a letter from the school scale and give a staff-only reason.']]);
        $row=TranscriptFinal::firstOrNew(['contact_id'=>$student->id,'class_subject_id'=>$data['class_subject_id'],'school_year_id'=>$data['school_year_id']]);
        if (!$row->exists || $row->letter!==$letter || $row->reason!==($letter===null?null:$reason)) $row->forceFill(['letter'=>$letter,'reason'=>$letter===null?null:$reason,'overridden_by_user_id'=>$letter===null?null:auth()->id(),'overridden_at'=>$letter===null?null:now()])->save();
        return $row;
    }
    public function prior(Request $request,$masjid_id,$contact_id,$prior_id=null)
    {
        $student=$this->student((int)$contact_id);
        $existing=$prior_id===null?null:TranscriptPriorYear::where('contact_id',$student->id)->findOrFail($prior_id);
        $request->validate(['courses_json'=>['sometimes','string']]);
        if ($request->has('courses_json')) {
            try { $request->merge(['courses'=>json_decode($request->input('courses_json'),true,512,JSON_THROW_ON_ERROR)]); }
            catch (\JsonException) { throw ValidationException::withMessages(['courses'=>['The prior courses could not be read.']]); }
        }
        $data=$request->validate(['school_name'=>['required','string','max:1000'],'year_label'=>['required','string','max:100'],'grade_label'=>['required','string','max:60'],'starts_on'=>['nullable','date_format:Y-m-d'],'ends_on'=>['nullable','date_format:Y-m-d','after_or_equal:starts_on'],
            'courses'=>['present','array','max:100'],'courses.*.name'=>['required','string','max:250'],'courses.*.letter'=>['required','string','max:20'],'courses.*.credit'=>['required'],'courses.*.level'=>['required',Rule::in(['regular','honors','ap'])],
            'file'=>['nullable','file','extensions:pdf','mimetypes:application/pdf','max:10240'],'membership_id'=>['required_with:file','integer']],['file.extensions'=>'Choose a transcript whose name ends in .pdf.']);
        return $this->locked(function($school) use($request,$student,$data,$existing) {
            if (!Transcripts::highSchool($data['grade_label'])) throw ValidationException::withMessages(['grade_label'=>['Prior transcript years must be grade 9 or above.']]);
            $policy=app(Transcripts::class)->policy($school); $courses=[];
            foreach($data['courses'] as $course) {
                if (!array_key_exists($course['letter'],$policy['points'])) throw ValidationException::withMessages(['courses'=>['Add this letter to the school points table first.']]);
                $courses[]=['name'=>$course['name'],'letter'=>$course['letter'],'credit_hundredths'=>Math::hundredths($course['credit'],'credit',10000),'level'=>$course['level']];
            }
            if ($courses===[] && !$request->hasFile('file') && !$existing?->group_resource_id) throw ValidationException::withMessages(['courses'=>['Enter courses or attach the prior transcript PDF.']]);
            $resource=null;
            try {
                if ($request->hasFile('file')) {
                    $member=GroupMembership::where('contact_id',$student->id)->participants()->findOrFail($data['membership_id']);
                    $group=Group::findOrFail($member->group_id);
                    $resource=GroupResourceFiles::store($group,$request->file('file'),['uploaded_by_user_id'=>auth()->id(),'title'=>\Illuminate\Support\Str::limit('Prior transcript: '.$data['school_name'],200,''),'visibility'=>'staff']);
                }
                $attributes=collect($data)->only(['school_name','year_label','grade_label','starts_on','ends_on'])->all()+['contact_id'=>$student->id,'courses'=>$courses,'group_resource_id'=>$resource?->id ?? $existing?->group_resource_id];
                if ($existing) { $row=TranscriptPriorYear::whereKey($existing->id)->lockForUpdate()->firstOrFail(); $row->fill($attributes)->save(); }
                else $row=TranscriptPriorYear::create($attributes+['entered_by_user_id'=>auth()->id()]);
                return $this->ok($row,$existing?200:201);
            } catch (\Throwable $e) { if ($resource) $resource->storage()->delete($resource->path); throw $e; }
        });
    }
    public function priorPdf($masjid_id,$contact_id,$prior_id)
    {
        $this->school(); $student=$this->student((int)$contact_id);
        $prior=TranscriptPriorYear::where('contact_id',$student->id)->findOrFail($prior_id);
        $resource=\App\Models\GroupResource::findOrFail($prior->group_resource_id);
        return $resource->storage()->download($resource->path,'prior-transcript.pdf',['Cache-Control'=>'private, no-store']);
    }
    public function issue($masjid_id,$contact_id)
    {
        $school=$this->school(); $student=$this->student((int)$contact_id);
        $issue=app(Transcripts::class)->issue($school,$student,(int)auth()->id());
        return $this->ok(['id'=>$issue->id,'serial'=>$issue->serial,'issued_at'=>$issue->issued_at],201);
    }
    public function issuePdf($masjid_id,$issue_id)
    {
        $this->school(); $issue=TranscriptIssue::findOrFail($issue_id); return $this->download($issue,'official-transcript.pdf');
    }
    public function share(Request $request,$masjid_id,$contact_id)
    {
        $school=$this->school(); $student=$this->student((int)$contact_id);
        $data=$request->validate(['receiving_masjid_id'=>['required','integer']]);
        return $this->ok(app(TranscriptShares::class)->request($school,$student,(int)$data['receiving_masjid_id'],(int)auth()->id()),201);
    }
    public function paper(Request $request,$masjid_id,$share_id)
    {
        $this->school(); $share=TranscriptShareRequest::findOrFail($share_id); $data=$request->validate(['evidence'=>['required','string','max:4000']]);
        return $this->ok(app(TranscriptShares::class)->consent($share,null,(int)auth()->id(),$data['evidence']));
    }
    public function withdraw($masjid_id,$share_id)
    {
        $this->school(); $share=TranscriptShareRequest::findOrFail($share_id); return $this->ok(app(TranscriptShares::class)->withdraw($share,(int)auth()->id()));
    }
    public function inbox(Request $request,$masjid_id,$share_id=null)
    {
        $school=$this->school(); $service=app(TranscriptShares::class); $query=$service->incoming((int)$school->id,$share_id===null?null:(int)$share_id);
        if ($share_id!==null) { $row=$query->first(); abort_unless($row,404); return $this->ok($service->header($row)); }
        return $this->ok($query->orderByDesc('id')->get()->map(fn($row)=>$service->header($row)));
    }
    public function accept(Request $request,$masjid_id,$share_id)
    {
        $school=$this->school(); $data=$request->validate(['contact_id'=>['nullable','integer']]);
        $copy=app(TranscriptShares::class)->resolve($school,(int)$share_id,(int)auth()->id(),true,isset($data['contact_id'])?(int)$data['contact_id']:null);
        return $this->ok($this->receivedHeader($copy),201);
    }
    public function decline($masjid_id,$share_id)
    {
        $school=$this->school(); app(TranscriptShares::class)->resolve($school,(int)$share_id,(int)auth()->id(),false,null); return $this->ok(['status'=>'declined']);
    }
    public function received($masjid_id,$received_id)
    {
        $this->school(); $copy=TranscriptReceived::findOrFail($received_id); return $this->ok($this->receivedHeader($copy)+['document'=>$copy->snapshot]);
    }
    public function receivedPdf($masjid_id,$received_id)
    {
        $this->school(); $copy=TranscriptReceived::findOrFail($received_id); return $this->download($copy,'received-transcript.pdf');
    }
    public function link(Request $request,$masjid_id,$received_id)
    {
        $data=$request->validate(['contact_id'=>['required','integer']]);
        return $this->locked(function() use($received_id,$data) {
            $student=$this->student((int)$data['contact_id']); $copy=TranscriptReceived::whereKey($received_id)->lockForUpdate()->firstOrFail();
            if ($copy->contact_id!==null) throw ValidationException::withMessages(['contact_id'=>['This received transcript is already linked to a student.']]);
            $copy->contact_id=$student->id; $copy->save(); return $this->ok($this->receivedHeader($copy));
        });
    }
    private function staffName(?int $id): ?string { return $id===null?null:User::withTrashed()->whereKey($id)->value('name'); }
    private function guardianName(?int $id): ?string
    {
        $contact=$id===null?null:Contact::withTrashed()->find($id);
        return $contact?trim($contact->first_name.' '.$contact->last_name):null;
    }
    private function shareHeader(TranscriptShareRequest $share): array
    {
        return $share->toArray()+['receiving_school'=>Masjid::whereKey($share->receiving_masjid_id)->value('name'),
            'requested_by_name'=>$this->staffName($share->requested_by_user_id),
            'consented_by_name'=>$this->guardianName($share->consented_by_contact_id) ?? $this->staffName($share->consented_by_user_id),
            'resolved_by_name'=>$this->guardianName($share->withdrawn_by_contact_id) ?? $this->staffName($share->resolved_by_user_id),
            'requested_at_display'=>SchoolAdmissionsMode::timestamp((int)$share->masjid_id,$share->requested_at),
            'consented_at_display'=>SchoolAdmissionsMode::timestamp((int)$share->masjid_id,$share->consented_at),
            'resolved_at_display'=>SchoolAdmissionsMode::timestamp((int)$share->masjid_id,$share->resolved_at)];
    }
    private function receivedHeader(TranscriptReceived $copy): array
    {
        return ['id'=>$copy->id,'contact_id'=>$copy->contact_id,'origin_school'=>$copy->origin_school,'origin_serial'=>$copy->origin_serial,'received_at'=>$copy->received_at,'received_at_display'=>SchoolAdmissionsMode::timestamp((int)$copy->masjid_id,$copy->received_at),'accepted_by_name'=>$this->staffName($copy->accepted_by_user_id),'student_name'=>$copy->snapshot['student']['name'],'read_only'=>true];
    }
    private function download($row,string $name)
    {
        $bytes=Storage::disk('local')->get($row->pdf_path);
        if (hash('sha256',$bytes)!==$row->pdf_sha256) return response()->api(409,'This transcript file could not be verified. Contact the office.');
        return response($bytes,200,['Content-Type'=>'application/pdf','Content-Disposition'=>'attachment; filename="'.$name.'"','Cache-Control'=>'private, no-store']);
    }
}
