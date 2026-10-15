<?php

namespace Tests\Feature;

use App\Models\{ClassSubject, Contact, GroupMembership, Masjid, ReportCard, SchoolTerm, TermGrade, TranscriptCourse, TranscriptFinal, TranscriptIssue, TranscriptPriorYear, TranscriptReceived, TranscriptSetting, TranscriptShareRequest, User};
use App\Services\Schools\Transcripts;
use App\Support\{LetterGrades, TenantContext};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Auth, DB, Storage};
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ParentAttendanceFixtures;
use Tests\TestCase;

class TranscriptsTest extends TestCase
{
    use RefreshDatabase, ParentAttendanceFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Carbon\Carbon::parse('2026-10-14 16:00:00', 'UTC'));
        Storage::fake('local');
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }
    protected function tearDown(): void { app(TenantContext::class)->forgetTenant(); parent::tearDown(); }
    private function enable(Masjid $school): void
    {
        $school->forceFill(['capability_overrides'=>['transcripts'=>true,'letter_grades'=>true,'class_subjects'=>true,'school_calendar'=>true,'school_calendar_terms'=>true], 'letter_grade_settings'=>['enabled'=>true,'from_grade'=>'9th','scale'=>LetterGrades::DEFAULT_SCALE]])->save();
    }
    private function makeMasjidWorld(string $suffix='a'): array
    {
        $school=$this->makeMasjid($suffix); $this->enable($school);
        $class=$this->classroom($school); $child=$this->enrol($class); $child->update(['grade_label'=>'9th']);
        $year=$this->year($school); $year->update(['label'=>'2026-2027']);
        $term=SchoolTerm::create(['masjid_id'=>$school->id,'school_year_id'=>$year->id,'name'=>'First term','starts_on'=>'2026-08-24','ends_on'=>'2026-10-31','position'=>1]);
        return [$school,$child,$term];
    }
    private function course($child,$term,string $name,?string $percentage,int $credit=100,string $level='regular',string $grade='9th'): ClassSubject
    {
        $subject=ClassSubject::create(['masjid_id'=>$child->masjid_id,'group_id'=>$child->group_id,'name'=>$name,'position'=>0]);
        TranscriptCourse::create(['masjid_id'=>$child->masjid_id,'class_subject_id'=>$subject->id,'credit_hundredths'=>$credit,'level'=>$level]);
        $card=ReportCard::firstOrCreate(['masjid_id'=>$child->masjid_id,'group_id'=>$child->group_id,'group_membership_id'=>$child->id,'type'=>'report_card','school_year'=>'2026-2027','term'=>$term->position],['grade_label'=>$grade]);
        $card->forceFill(['school_term_id'=>$term->id,'published_at'=>now()])->save();
        TermGrade::create(['masjid_id'=>$child->masjid_id,'contact_id'=>$child->contact_id,'class_subject_id'=>$subject->id,'school_term_id'=>$term->id,'report_card_id'=>$card->id,'subject'=>$name,'percentage'=>$percentage,'final_letter'=>'A','published_at'=>now()]);
        return $subject;
    }
    private function office(Masjid $school): User
    {
        Auth::forgetGuards(); app(TenantContext::class)->forgetTenant();
        $user=User::factory()->create(['type'=>'MasjidAdmin','name'=>'Practice Office','email'=>uniqid().'@example.invalid','phone'=>'+15555550123']);
        $school->forceFill(['user_id'=>$user->id])->save(); Sanctum::actingAs($user); return $user;
    }
    private function base(Masjid $school): string { return '/api/admin/masjids/'.$school->id.'/transcripts'; }

    #[Test]
    public function exact_credit_weighted_gpa_includes_failed_attempts_half_credit_honors_and_ap(): void
    {
        [$school,$child,$term]=$this->makeMasjidWorld();
        $this->course($child,$term,'Language','95',100,'honors');
        $this->course($child,$term,'Science','85',100,'ap');
        $this->course($child,$term,'Art','95',50);
        $this->course($child,$term,'History','40');
        $data=app(Transcripts::class)->live($school,$child->contact);
        // (4*1 + 3*1 + 4*.5 + 0*1) / 3.5 = 18/7; weighted = 21/7.
        $this->assertSame('2.57',$data['cumulative']['plain']);
        $this->assertSame('3.00',$data['cumulative']['weighted']);
        $this->assertSame('3.50',$data['cumulative']['attempted']);
        $this->assertSame('2.50',$data['cumulative']['earned']);
        $this->assertSame($data['cumulative'],$data['years'][0]['totals']);
        $this->assertSame('0.01',\App\Support\TranscriptMath::ratio(1,200));
    }

    #[Test]
    public function annual_letter_averages_only_published_percentages_and_override_has_staff_only_provenance(): void
    {
        [$school,$child,$term]=$this->makeMasjidWorld(); $subject=$this->course($child,$term,'Mathematics','80');
        $term2=SchoolTerm::create(['masjid_id'=>$school->id,'school_year_id'=>$term->school_year_id,'name'=>'Second term','starts_on'=>'2026-11-01','ends_on'=>'2027-01-31','position'=>2]);
        $this->course($child,$term2,'Other','90');
        $card=ReportCard::where('term',2)->first();
        TermGrade::create(['masjid_id'=>$school->id,'contact_id'=>$child->contact_id,'class_subject_id'=>$subject->id,'school_term_id'=>$term2->id,'report_card_id'=>$card->id,'subject'=>'Mathematics','percentage'=>'100','published_at'=>now()]);
        $service=app(Transcripts::class); $data=$service->live($school,$child->contact);
        $this->assertSame('A−',$data['years'][0]['courses'][0]['letter']);
        $actor=$this->office($school);
        $url=$this->base($school).'/students/'.$child->contact_id.'/finals';
        $payload=['class_subject_id'=>$subject->id,'school_year_id'=>$term->school_year_id,'letter'=>'B','reason'=>''];
        $this->putJson($url,$payload)->assertUnprocessable();
        $payload['reason']='Additional staff evidence'; $this->putJson($url,$payload)->assertOk();
        $final=TranscriptFinal::first(); $this->assertSame($actor->id,$final->overridden_by_user_id); $this->assertNotNull($final->overridden_at);
        $data=$service->live($school,$child->contact); $this->assertSame('B',$data['years'][0]['courses'][0]['letter']);
        $this->assertStringNotContainsString('Additional staff evidence',json_encode($data));
        $card->forceFill(['published_at'=>null])->save();
        $this->putJson($url,['class_subject_id'=>$subject->id,'school_year_id'=>$term->school_year_id,'letter'=>null,'reason'=>null])->assertOk();
        $this->assertSame('B−',$service->live($school,$child->contact)['years'][0]['courses'][0]['letter']);
    }

    #[Test]
    public function grade_nine_cut_uses_the_years_frozen_grade_and_prior_courses_count_but_file_alone_does_not(): void
    {
        [$school,$child,$term]=$this->makeMasjidWorld(); $this->course($child,$term,'Earlier work','95',100,'regular','8th');
        $child->update(['grade_label'=>'10th']);
        $service=app(Transcripts::class); $this->assertSame([],$service->live($school,$child->contact)['years']);
        TranscriptPriorYear::create(['masjid_id'=>$school->id,'contact_id'=>$child->contact_id,'school_name'=>'Practice Prior School','year_label'=>'2025-2026','grade_label'=>'9th','courses'=>[['name'=>'Art','letter'=>'A','credit_hundredths'=>50,'level'=>'regular']]]);
        TranscriptPriorYear::create(['masjid_id'=>$school->id,'contact_id'=>$child->contact_id,'school_name'=>'Practice Earlier School','year_label'=>'2024-2025','grade_label'=>'9th','courses'=>[]]);
        $data=$service->live($school,$child->contact);
        $this->assertSame('4.00',$data['cumulative']['plain']); $this->assertSame('0.50',$data['cumulative']['attempted']);
        $this->assertTrue($data['years'][0]['excluded_from_gpa']);
        $this->assertSame('From Practice Prior School',$data['years'][1]['origin']);
    }

    #[Test]
    public function official_json_and_pdf_are_frozen_and_unofficial_is_live(): void
    {
        [$school,$child,$term]=$this->makeMasjidWorld(); $this->course($child,$term,'Science','95'); $actor=$this->office($school);
        $base=$this->base($school).'/students/'.$child->contact_id;
        $issue=$this->postJson($base.'/issues')->assertCreated()->json('data');
        $row=TranscriptIssue::findOrFail($issue['id']); $bytes=Storage::disk('local')->get($row->pdf_path);
        $this->assertStringStartsWith('%PDF-',$bytes); $this->assertSame($actor->id,$row->issued_by_user_id);
        TermGrade::query()->update(['percentage'=>'40']); $child->update(['grade_label'=>'10th']);
        $this->assertSame('4.00',$row->fresh()->snapshot['cumulative']['plain']);
        $this->assertSame($bytes,Storage::disk('local')->get($row->pdf_path));
        $guardian=$this->guardian($child); Auth::forgetGuards(); $this->family($guardian);
        $url=str_replace('/attendance','/transcript',$this->familyUrl($child));
        $response=$this->getJson($url)->assertOk(); $response->assertJsonPath('data.designation','Unofficial')->assertJsonPath('data.cumulative.plain','0.00')->assertJsonPath('data.student.grade','10th')->assertJsonPath('data.years.0.grade','9th');
        $this->get($url.'/pdf')->assertOk()->assertHeader('Content-Type','application/pdf');
        $this->office($school); $next=$this->postJson($base.'/issues')->assertCreated()->json('data'); $this->assertNotSame($issue['serial'],$next['serial']);
    }

    #[Test]
    public function sharing_needs_family_and_receiving_office_consent_and_copies_only_an_issued_snapshot(): void
    {
        [$sender,$child,$term]=$this->makeMasjidWorld(); $this->course($child,$term,'Science','95');
        $child->contact->recordDateOfBirth('2010-03-04',null,'transcript practice fixture');
        [$receiver,$newChild]=$this->makeMasjidWorld('b'); $this->office($sender);
        $share=$this->postJson($this->base($sender).'/students/'.$child->contact_id.'/shares',['receiving_masjid_id'=>$receiver->id])->assertCreated()->json('data');
        $this->office($receiver); $this->getJson($this->base($receiver).'/inbox')->assertOk()->assertJsonCount(0,'data');
        $this->getJson($this->base($receiver).'/inbox/'.$share['id'])->assertNotFound();
        app(TenantContext::class)->forgetTenant(); $guardian=$this->guardian($child); $this->family($guardian);
        $url=str_replace('/attendance','/transcript/shares/'.$share['id'].'/consent',$this->familyUrl($child));
        $this->postJson($url,['consent'=>'1'])->assertOk();
        $this->office($receiver); $header=$this->getJson($this->base($receiver).'/inbox/'.$share['id'])->assertOk()->json('data');
        $this->assertSame(['id','sending_school','student_name','date_of_birth','status','requested_at','requested_at_display'],array_keys($header));
        $this->assertSame('2010-03-04',$header['date_of_birth']);
        $this->getJson($this->base($receiver).'/students/'.$child->contact_id)->assertNotFound();
        $this->postJson($this->base($receiver).'/inbox/'.$share['id'].'/accept',['contact_id'=>$newChild->contact_id])->assertCreated();
        $copy=TranscriptReceived::withoutMasjidScope()->firstOrFail(); $this->assertSame($receiver->id,$copy->masjid_id); $this->assertSame($newChild->contact_id,$copy->contact_id);
        $frozen=$copy->snapshot; $path=$copy->pdf_path;
        $this->assertSame('4.00',$frozen['cumulative']['plain']);
        $this->assertSame('2010-03-04',$frozen['student']['date_of_birth']);
        $this->assertSame($share['requested_by_user_id'],TranscriptIssue::withoutMasjidScope()->first()->issued_by_user_id);
        TermGrade::withoutMasjidScope()->where('masjid_id',$sender->id)->update(['percentage'=>'40']);
        $this->assertSame($frozen,$copy->fresh()->snapshot); Storage::disk('local')->assertExists($path);
        $this->get($this->base($receiver).'/received/'.$copy->id.'/pdf')->assertOk();
        $this->office($sender); $this->getJson($this->base($sender).'/received/'.$copy->id)->assertNotFound();
        $this->assertSame('accepted',TranscriptShareRequest::first()->status);
    }

    #[Test]
    public function paper_consent_decline_withdraw_unlinked_accept_and_foreign_request_are_guarded(): void
    {
        [$sender,$child,$term]=$this->makeMasjidWorld(); $this->course($child,$term,'Science','95');
        [$receiver]=$this->makeMasjidWorld('b'); $receiver->update(['phone'=>'+15555550103']); [$third]=$this->makeMasjidWorld('c'); $this->office($sender);
        $url=$this->base($sender).'/students/'.$child->contact_id.'/shares';
        $share=$this->postJson($url,['receiving_masjid_id'=>$receiver->id])->assertCreated()->json('data');
        $this->postJson($this->base($sender).'/shares/'.$share['id'].'/paper-consent',['evidence'=>'Paper authorization on file'])->assertOk();
        $this->office($third); $this->getJson($this->base($third).'/inbox/'.$share['id'])->assertNotFound();
        $this->postJson($this->base($third).'/inbox/'.$share['id'].'/accept')->assertNotFound();
        $this->office($receiver); $this->postJson($this->base($receiver).'/inbox/'.$share['id'].'/decline')->assertOk();
        $this->postJson($this->base($receiver).'/inbox/'.$share['id'].'/accept')->assertUnprocessable();
        $this->office($sender); $share2=$this->postJson($url,['receiving_masjid_id'=>$receiver->id])->assertCreated()->json('data');
        $this->postJson($this->base($sender).'/shares/'.$share2['id'].'/withdraw')->assertOk();
        $this->postJson($this->base($sender).'/shares/'.$share2['id'].'/paper-consent',['evidence'=>'Paper consent'])->assertUnprocessable();
        $share3=$this->postJson($url,['receiving_masjid_id'=>$receiver->id])->assertCreated()->json('data');
        $this->postJson($this->base($sender).'/shares/'.$share3['id'].'/paper-consent',['evidence'=>'Paper consent'])->assertOk();
        $this->office($receiver); $this->postJson($this->base($receiver).'/inbox/'.$share3['id'].'/accept',['contact_id'=>null])->assertCreated();
        $this->assertNull(TranscriptReceived::first()->contact_id);
    }

    #[Test]
    public function every_new_tenant_model_refuses_other_tenants_rows(): void
    {
        [$a,$child,$term]=$this->makeMasjidWorld(); $subject=$this->course($child,$term,'Science','95');
        $actor=$this->office($a); app(TenantContext::class)->set($a->id);
        $setting=TranscriptSetting::create(['points'=>['A'=>400],'honors_extra'=>50,'ap_extra'=>100,'failing_letters'=>['F']]);
        $final=TranscriptFinal::create(['contact_id'=>$child->contact_id,'class_subject_id'=>$subject->id,'school_year_id'=>$term->school_year_id,'letter'=>'A','reason'=>'Practice evidence']);
        $prior=TranscriptPriorYear::create(['contact_id'=>$child->contact_id,'school_name'=>'Practice','year_label'=>'2025-2026','grade_label'=>'9th','courses'=>[]]);
        $issue=app(Transcripts::class)->issue($a,$child->contact,$actor->id);
        app(TenantContext::class)->forgetTenant(); [$b]=$this->makeMasjidWorld('b');
        $share=TranscriptShareRequest::create(['masjid_id'=>$a->id,'contact_id'=>$child->contact_id,'receiving_masjid_id'=>$b->id,'student_name'=>'Practice Student','sending_school'=>'Practice School','status'=>'pending','requested_at'=>now(),'requested_by_user_id'=>$actor->id]);
        $received=TranscriptReceived::create(['masjid_id'=>$b->id,'origin_school'=>'Practice School','origin_serial'=>$issue->serial,'received_at'=>now(),'snapshot'=>$issue->snapshot,'pdf_path'=>'transcripts/practice.pdf','pdf_sha256'=>str_repeat('a',64),'source_request_id'=>$share->id]);
        foreach ([[TranscriptSetting::class,$setting->id,$a->id],[TranscriptCourse::class,TranscriptCourse::first()->id,$a->id],[TranscriptFinal::class,$final->id,$a->id],[TranscriptPriorYear::class,$prior->id,$a->id],[TranscriptIssue::class,$issue->id,$a->id],[TranscriptShareRequest::class,$share->id,$a->id],[TranscriptReceived::class,$received->id,$b->id]] as [$model,$id,$owner]) {
            app(TenantContext::class)->set($owner); $this->assertNotNull($model::find($id));
            app(TenantContext::class)->set($owner===$a->id?$b->id:$a->id); $this->assertNull($model::find($id));
        }
    }
    #[Test]
    public function switch_is_hidden_off_and_requires_letter_grades_and_receiving_switch(): void
    {
        $school=$this->makeMasjid();
        $this->assertArrayNotHasKey('transcripts',$school->capabilities);
        try { \App\Support\CapabilityWriter::apply($school,['transcripts'=>true],null); $this->fail('Missing prerequisite accepted'); }
        catch (\Illuminate\Validation\ValidationException $e) { $this->assertStringContainsString('Switch on letter grades',json_encode($e->errors())); }
        \App\Support\CapabilityWriter::apply($school,['transcripts'=>false],null);
        $this->assertArrayNotHasKey('transcripts',$school->fresh()->capability_overrides ?? []);
        $this->assertSame(0,DB::table('masjid_capability_changes')->where('capability','transcripts')->count());
        $this->enable($school);
        $this->office($school); $this->getJson($this->base($school))->assertOk();
        $this->assertArrayHasKey('transcripts',$school->fresh()->capabilities);
        try { \App\Support\CapabilityWriter::apply($school,['letter_grades'=>false],null); $this->fail('Dependency disabled'); } catch (\Illuminate\Validation\ValidationException) { $this->assertTrue(true); }
    }

    #[Test]
    public function complete_policy_and_exact_course_inputs_are_validated_atomically(): void
    {
        [$school,$child,$term]=$this->makeMasjidWorld(); $subject=$this->course($child,$term,'Science','95'); $this->office($school);
        $url=$this->base($school);
        foreach(['0.001','-1','1e1','NaN','100.01'] as $bad) $this->put($url.'/courses/'.$subject->id,['credit'=>$bad,'level'=>'honors'])->assertUnprocessable();
        $this->put($url.'/courses/'.$subject->id,['credit'=>'0.50','level'=>'ap'])->assertOk();
        $this->assertSame(50,TranscriptCourse::first()->credit_hundredths);
        $policy=['points'=>array_map(fn($v)=>\App\Support\TranscriptMath::decimal($v),Transcripts::POINTS),'honors_extra'=>'0.50','ap_extra'=>'1.00','failing_letters'=>['F']];
        $broken=$policy; unset($broken['points']['A']);
        $this->putJson($url.'/settings',$broken)->assertUnprocessable(); $this->assertNull(TranscriptSetting::first());
        $policy['points']['A']='3.75'; $policy['failing_letters']=['F','D−'];
        $this->putJson($url.'/settings',$policy)->assertOk();
        $this->assertSame(375,TranscriptSetting::first()->points['A']);
        $this->assertSame('4.75',app(Transcripts::class)->live($school,$child->contact)['cumulative']['weighted']);
    }

    #[Test]
    public function source_records_and_every_download_are_invisible_to_receiving_office(): void
    {
        [$a,$child,$term]=$this->makeMasjidWorld(); $subject=$this->course($child,$term,'Science','95'); $actor=$this->office($a);
        $issue=app(Transcripts::class)->issue($a,$child->contact,$actor->id);
        [$b]=$this->makeMasjidWorld('b'); $this->office($b);
        $base=$this->base($b);
        $this->getJson($base.'/students/'.$child->contact_id)->assertNotFound();
        $this->getJson($base.'/issues/'.$issue->id.'/pdf')->assertNotFound();
        $this->putJson($base.'/courses/'.$subject->id,['credit'=>'1.00','level'=>'regular'])->assertNotFound();
        $this->postJson($base.'/students/'.$child->contact_id.'/issues')->assertNotFound();
        $this->getJson($base.'/students/'.$child->contact_id.'/prior/1/pdf')->assertNotFound();
    }

    #[Test]
    public function transcripts_and_letters_can_be_disabled_together(): void
    {
        [$school]=$this->makeMasjidWorld();
        \App\Support\CapabilityWriter::apply($school,['transcripts'=>false,'letter_grades'=>false],null);
        $this->assertFalse($school->fresh()->hasCapability('transcripts'));
        $this->assertFalse($school->fresh()->hasCapability('letter_grades'));
    }

    #[Test]
    public function prior_pdf_uses_existing_private_class_file_and_counts_nothing_until_courses_are_entered(): void
    {
        [$school,$child]=$this->makeMasjidWorld(); $this->office($school);
        $body=['school_name'=>str_repeat('Practice Prior School ',20),'year_label'=>'2025-2026','grade_label'=>'9th','courses_json'=>'[]','membership_id'=>$child->id,'file'=>\Illuminate\Http\UploadedFile::fake()->createWithContent('prior.pdf',"%PDF-1.4\nPractice prior transcript\n%%EOF")];
        $url=$this->base($school).'/students/'.$child->contact_id.'/prior';
        $bad=$body; $path=tempnam(sys_get_temp_dir(),'prior-pdf-');file_put_contents($path,"%PDF-1.4\nPractice prior transcript\n%%EOF");
        $bad['file']=new \Illuminate\Http\UploadedFile($path,'prior.html','application/pdf',null,true);
        try {$this->post($url,$bad)->assertUnprocessable();}finally{unlink($path);}
        $response=$this->post($url,$body)->assertCreated(); $id=$response->json('data.id');
        $resource=\App\Models\GroupResource::findOrFail($response->json('data.group_resource_id'));
        $this->assertLessThanOrEqual(200,mb_strlen($resource->title));
        $this->assertSame('staff',$resource->visibility); $this->assertStringStartsWith('group-resources/'.$school->id.'/',$resource->path);
        $this->get($url.'/'.$id.'/pdf')->assertOk()->assertHeader('Cache-Control','no-store, private');
        $document=app(Transcripts::class)->live($school,$child->contact); $this->assertNull($document['cumulative']['plain']);
        $this->assertTrue($document['years'][0]['file_only']);
        $body['courses']=[['name'=>'Art','letter'=>'A','credit'=>'0.50','level'=>'honors']]; unset($body['courses_json'],$body['file']);
        $this->putJson($url.'/'.$id,$body)->assertOk();
        $this->assertSame(1,TranscriptPriorYear::count());
        $this->assertSame('4.50',app(Transcripts::class)->live($school,$child->contact)['cumulative']['weighted']);
    }

    #[Test]
    public function teacher_may_override_only_a_published_course_in_their_assigned_subjects(): void
    {
        [$school,$child,$term]=$this->makeMasjidWorld(); $science=$this->course($child,$term,'Science','95'); $other=$this->course($child,$term,'History','85');
        $teacher=User::factory()->create(['type'=>'Teacher','phone'=>'+15555550124','name'=>'Practice Teacher','email'=>'teacher@example.invalid']);
        \App\Models\MasjidUser::create(['masjid_id'=>$school->id,'user_id'=>$teacher->id,'role'=>'teacher','is_default'=>true]);
        \App\Models\GroupStaff::create(['masjid_id'=>$school->id,'group_id'=>$child->group_id,'user_id'=>$teacher->id,'role'=>'teacher','class_subject_ids'=>[$science->id],'assigned_at'=>now()]);
        Auth::forgetGuards(); app(TenantContext::class)->forgetTenant(); Sanctum::actingAs($teacher);
        $base='/api/teacher/masjids/'.$school->id.'/groups/'.$child->group_id;
        $response=$this->getJson($base.'/transcript-finals')->assertOk();
        $response->assertJsonCount(1,'data.students.0.years.0.courses');
        $this->assertStringNotContainsString('date_of_birth',$response->getContent());
        $body=['class_subject_id'=>$science->id,'school_year_id'=>$term->school_year_id,'letter'=>'B','reason'=>'Staff evidence'];
        $url=$base.'/members/'.$child->id.'/transcript-finals';
        $this->putJson($url,$body)->assertOk();
        $this->putJson($url,array_merge($body,['class_subject_id'=>$other->id]))->assertForbidden();
        $this->getJson($base)->assertOk()->assertJsonPath('data.transcripts_enabled',true);
    }

    #[Test]
    public function a_different_guardian_cannot_consent_or_view_another_childs_transcript(): void
    {
        [$school,$child,$term]=$this->makeMasjidWorld();$this->course($child,$term,'Science','95');
        $other=$this->enrol(\App\Models\Group::find($child->group_id));$guardian=$this->guardian($other);$this->family($guardian);
        $url=str_replace('/attendance','/transcript',$this->familyUrl($child));
        $this->getJson($url)->assertForbidden(); $this->getJson($url.'/pdf')->assertForbidden();
        $this->postJson($url.'/shares/1/consent',['consent'=>'1'])->assertForbidden();
        $ownGroup='/api/family/masjids/'.$school->id.'/groups/'.$child->group_id;
        $this->getJson($ownGroup)->assertOk()->assertJsonPath('data.transcripts_enabled',true);
    }

    #[Test]
    public function retained_prior_file_refuses_removal_before_its_private_bytes_are_deleted_even_when_switched_off(): void
    {
        [$school,$child]=$this->makeMasjidWorld(); $this->office($school);
        $file=\Illuminate\Http\UploadedFile::fake()->createWithContent('prior.pdf',"%PDF-1.4\nPractice transcript\n%%EOF");
        $resource=\App\Support\GroupResourceFiles::store(\App\Models\Group::find($child->group_id),$file,['title'=>'Prior transcript','visibility'=>'staff']);
        TranscriptPriorYear::create(['masjid_id'=>$school->id,'contact_id'=>$child->contact_id,'school_name'=>'Practice Prior School','year_label'=>'2025-2026','grade_label'=>'9th','courses'=>[],'group_resource_id'=>$resource->id]);
        $school->forceFill(['capability_overrides'=>array_merge($school->capability_overrides,['transcripts'=>false])])->save();
        try {$resource->delete();$this->fail('A retained transcript file was removed');}catch(\Illuminate\Validation\ValidationException){$this->assertTrue(true);}
        Storage::disk('local')->assertExists($resource->path);
    }

    #[Test]
    public function contact_merge_refuses_to_erase_transcript_identity_and_rollback_keeps_issued_records(): void
    {
        [$school,$child,$term]=$this->makeMasjidWorld();$this->course($child,$term,'Science','95');$actor=$this->office($school);
        $issue=app(Transcripts::class)->issue($school,$child->contact,$actor->id);
        $target=Contact::factory()->create(['masjid_id'=>$school->id,'email'=>null]);
        $this->postJson('/api/admin/masjids/'.$school->id.'/contacts/'.$child->contact_id.'/merge',['target_contact_id'=>$target->id])->assertUnprocessable();
        $migration=require database_path('migrations/2026_10_15_000100_create_transcripts.php');
        try{$migration->down();$this->fail('Rollback erased academic history');}catch(\RuntimeException $e){$this->assertStringContainsString('academic records',$e->getMessage());}
        $this->assertNotNull(TranscriptIssue::find($issue->id));Storage::disk('local')->assertExists($issue->pdf_path);
    }

    #[Test]
    public function a_share_copies_the_existing_official_issue_and_survives_source_file_removal_and_switch_off(): void
    {
        [$sender,$child,$term]=$this->makeMasjidWorld();$this->course($child,$term,'Science','95');$actor=$this->office($sender);
        $issue=app(Transcripts::class)->issue($sender,$child->contact,$actor->id);TermGrade::query()->update(['percentage'=>'40']);
        [$receiver,$newChild]=$this->makeMasjidWorld('b');$this->office($sender);
        $share=$this->postJson($this->base($sender).'/students/'.$child->contact_id.'/shares',['receiving_masjid_id'=>$receiver->id])->assertCreated()->json('data');
        $this->postJson($this->base($sender).'/shares/'.$share['id'].'/paper-consent',['evidence'=>'Signed paper consent'])->assertOk();
        $this->office($receiver);$url=$this->base($receiver).'/inbox/'.$share['id'].'/accept';
        $this->postJson($url,['contact_id'=>$child->contact_id])->assertNotFound();
        $this->assertSame(0,TranscriptReceived::count());
        $sender->forceFill(['capability_overrides'=>array_merge($sender->capability_overrides,['transcripts'=>false])])->save();
        $this->postJson($url,['contact_id'=>$newChild->contact_id])->assertUnprocessable();
        $this->enable($sender);
        $this->postJson($url,['contact_id'=>$newChild->contact_id])->assertCreated();
        $this->postJson($url,['contact_id'=>$newChild->contact_id])->assertUnprocessable();
        $copy=TranscriptReceived::first();$this->assertSame($issue->serial,$copy->origin_serial);$this->assertSame('4.00',$copy->snapshot['cumulative']['plain']);
        Storage::disk('local')->delete($issue->pdf_path);$sender->forceFill(['capability_overrides'=>array_merge($sender->capability_overrides,['transcripts'=>false])])->save();
        $this->get($this->base($receiver).'/received/'.$copy->id.'/pdf')->assertOk();
        $this->assertSame(1,TranscriptIssue::withoutMasjidScope()->count());
    }

    #[Test]
    public function the_unrounded_annual_average_selects_the_letter_before_display_rounding(): void
    {
        [$school,$child,$term]=$this->makeMasjidWorld();$subject=$this->course($child,$term,'Science','89.99');
        $next=SchoolTerm::create(['masjid_id'=>$school->id,'school_year_id'=>$term->school_year_id,'name'=>'Second term','starts_on'=>'2026-11-01','ends_on'=>'2027-01-31','position'=>2]);
        $this->course($child,$next,'History','90.00');$card=ReportCard::where('term',2)->first();
        TermGrade::create(['masjid_id'=>$school->id,'contact_id'=>$child->contact_id,'class_subject_id'=>$subject->id,'school_term_id'=>$next->id,'report_card_id'=>$card->id,'subject'=>'Science','percentage'=>'90.00','final_letter'=>'A−','published_at'=>now()]);
        $course=app(Transcripts::class)->live($school,$child->contact)['years'][0]['courses'][0];
        $this->assertSame('90.00',$course['average_percentage']);$this->assertSame('B+',$course['letter']);
    }

    #[Test]
    public function malformed_prior_course_json_shape_returns_validation_instead_of_a_server_error(): void
    {
        [$school,$child]=$this->makeMasjidWorld();$this->office($school);
        $this->postJson($this->base($school).'/students/'.$child->contact_id.'/prior',['school_name'=>'Practice School','year_label'=>'2025-2026','grade_label'=>'9th','courses_json'=>['unexpected'=>'array']])->assertUnprocessable();
    }

    #[Test]
    public function a_prior_year_may_hold_just_its_known_last_date_and_refuses_an_inverted_range(): void
    {
        [$school,$child]=$this->makeMasjidWorld();$this->office($school);
        $data=['school_name'=>'Practice Prior School','year_label'=>'2025-2026','grade_label'=>'9th','starts_on'=>null,'ends_on'=>'2026-06-01','courses'=>[['name'=>'Art','letter'=>'A','credit'=>'0.50','level'=>'regular']]];
        $url=$this->base($school).'/students/'.$child->contact_id.'/prior';
        $this->postJson($url,$data)->assertCreated();
        $data['starts_on']='2026-06-02';$this->postJson($url,$data)->assertUnprocessable();
    }

    #[Test]
    public function ungraded_courses_neither_attempt_credit_nor_require_it_to_issue_and_hide_credit_cells(): void
    {
        [$school,$child,$term]=$this->makeMasjidWorld();
        $this->course($child,$term,'Passing','95',100);
        $this->course($child,$term,'Failing','40',50);
        $levels=$this->course($child,$term,'Levels only',null,200);
        $missing=$this->course($child,$term,'No points work',null);
        TranscriptCourse::where('class_subject_id',$missing->id)->delete();
        $actor=$this->office($school); $service=app(Transcripts::class);
        $document=$service->live($school,$child->contact);
        $this->assertSame('1.50',$document['cumulative']['attempted']);
        $this->assertSame('1.00',$document['cumulative']['earned']);
        $this->assertSame('2.67',$document['cumulative']['plain']);
        $this->assertSame($document['cumulative'],$document['years'][0]['totals']);
        $issue=$service->issue($school,$child->contact,$actor->id);
        $html=view('pdf.transcript',['document'=>$issue->snapshot])->render();
        $this->assertMatchesRegularExpression('/Levels only<\/td>.*?Not graded<\/td><td>\s*<\/td><td>\s*<\/td>/s',$html);
        $this->assertStringNotContainsString('Not set',$html);
        TermGrade::where('class_subject_id',$missing->id)->update(['percentage'=>'90']);
        $this->postJson($this->base($school).'/students/'.$child->contact_id.'/issues')->assertUnprocessable();
    }

    #[Test]
    public function course_visibility_survives_form_encoding_and_excludes_graded_courses_from_all_copies(): void
    {
        [$school,$child,$term]=$this->makeMasjidWorld();
        $this->course($child,$term,'Included','95',50); $hidden=$this->course($child,$term,'Excluded','40',100);
        $actor=$this->office($school); $url=$this->base($school).'/courses/'.$hidden->id;
        $this->assertTrue(TranscriptCourse::where('class_subject_id',$hidden->id)->first()->on_transcript);
        $this->put($url,['credit'=>'','level'=>'regular','on_transcript'=>'false'])->assertOk();
        $service=app(Transcripts::class); $document=$service->live($school,$child->contact);
        $this->assertSame(['Included'],array_column($document['years'][0]['courses'],'name'));
        $this->assertSame('0.50',$document['cumulative']['attempted']);
        $staff=$service->live($school,$child->contact,true);
        $this->assertCount(2,$staff['years'][0]['courses']); $this->assertSame($document['cumulative'],$staff['cumulative']);
        $issue=$service->issue($school,$child->contact,$actor->id);
        $this->assertStringNotContainsString('Excluded',view('pdf.transcript',['document'=>$issue->snapshot])->render());
        $this->put($url,['credit'=>'','level'=>'regular','on_transcript'=>'true'])->assertOk();
        $this->postJson($this->base($school).'/students/'.$child->contact_id.'/issues')->assertUnprocessable();
        $this->put($url,['credit'=>'1.00','level'=>'regular','on_transcript'=>'nonsense'])->assertUnprocessable();
        $this->assertSame('0.50',$issue->fresh()->snapshot['cumulative']['attempted']);
    }

    #[Test]
    public function office_student_filter_uses_gradelevel_spellings_and_show_all_precedes_pagination(): void
    {
        [$school,$child]=$this->makeMasjidWorld(); $child->update(['grade_label'=>'Grade 10']);
        $class=\App\Models\Group::find($child->group_id);
        $younger=$this->enrol($class); $younger->update(['grade_label'=>'8th']);
        $unknown=$this->enrol($class); $unknown->update(['grade_label'=>'Unknown']);
        $older=$this->enrol($class); $older->update(['grade_label'=>'twelfth grade']);
        $this->office($school);
        $ids=$this->getJson($this->base($school))->assertOk()->json('data.students.data');
        $this->assertEqualsCanonicalizing([$child->contact_id,$older->contact_id],array_column($ids,'id'));
        $this->getJson($this->base($school).'?show_all=1')->assertOk()->assertJsonPath('data.students.total',4);
    }

    #[Test]
    public function attendance_uses_first_enrolment_or_roster_start_and_present_or_leaving_date(): void
    {
        [$school,$child]=$this->makeMasjidWorld(); $child->update(['joined_at'=>'2026-08-24']);
        $class=$this->classroom($school,'Previous class'); $old=$this->enrol($class,$child->contact);
        $old->forceFill(['joined_at'=>'2025-08-25','left_on'=>'2026-06-01'])->save();
        $document=app(Transcripts::class)->live($school,$child->contact);
        $this->assertSame('2025-08-25',$document['student']['attended_from']);
        $html=view('pdf.transcript',['document'=>$document])->render();
        $this->assertStringContainsString('Aug 25, 2025 to Present',$html);
        $child->forceFill(['left_on'=>'2026-10-14'])->save();
        $document=app(Transcripts::class)->live($school,$child->contact);
        $this->assertSame('2026-10-14',$document['student']['attended_to']);
        $child->update(['joined_at'=>null]); $old->delete();
        $document=app(Transcripts::class)->live($school,$child->contact);
        $this->assertSame('2026-10-14',$document['student']['attended_from']);
    }

    #[Test]
    public function stale_issue_detects_term_changes_overrides_and_cleared_overrides_then_resets_on_reissue(): void
    {
        [$school,$child,$term]=$this->makeMasjidWorld(); $subject=$this->course($child,$term,'Science','95'); $this->office($school);
        $url=$this->base($school).'/students/'.$child->contact_id;
        $this->postJson($url.'/issues')->assertCreated();
        $this->getJson($url)->assertOk()->assertJsonPath('data.latest_issue_stale',false);
        $this->travel(1)->minutes(); TermGrade::query()->update(['percentage'=>'85','updated_at'=>now()]);
        $this->getJson($url)->assertOk()->assertJsonPath('data.latest_issue_stale',true);
        $this->postJson($url.'/issues')->assertCreated();
        $this->getJson($url)->assertOk()->assertJsonPath('data.latest_issue_stale',false);
        $this->travel(1)->minutes();
        $body=['class_subject_id'=>$subject->id,'school_year_id'=>$term->school_year_id,'letter'=>'A','reason'=>'Additional evidence'];
        $this->putJson($url.'/finals',$body)->assertOk();
        $this->getJson($url)->assertOk()->assertJsonPath('data.latest_issue_stale',true);
        $this->postJson($url.'/issues')->assertCreated(); $this->travel(1)->minutes();
        $this->putJson($url.'/finals',array_merge($body,['letter'=>null]))->assertOk();
        $this->getJson($url)->assertOk()->assertJsonPath('data.latest_issue_stale',true);
    }

    #[Test]
    public function office_dates_and_names_use_school_clock_and_pdf_contains_only_issuing_office_identity(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-10-16 03:06:00','UTC'));
        [$school,$child,$term]=$this->makeMasjidWorld(); $child->update(['grade_label'=>'10th','joined_at'=>'2026-08-24']);
        $subject=$this->course($child,$term,'Science','95'); [$receiver]=$this->makeMasjidWorld('b');
        $this->assertSame('2026-10-15',app(Transcripts::class)->live($school,$this->enrol(\App\Models\Group::find($child->group_id))->contact)['student']['attended_from']);
        $actor=$this->office($school); $studentUrl=$this->base($school).'/students/'.$child->contact_id;
        $this->putJson($studentUrl.'/finals',['class_subject_id'=>$subject->id,'school_year_id'=>$term->school_year_id,'letter'=>'B','reason'=>'Private reason'])->assertOk();
        $issue=$this->postJson($studentUrl.'/issues')->assertCreated()->json('data.id');
        $share=$this->postJson($studentUrl.'/shares',['receiving_masjid_id'=>$receiver->id])->assertCreated()->json('data.id');
        $guardian=$this->guardian($child); $this->family($guardian);
        $familyUrl=str_replace('/attendance','/transcript',$this->familyUrl($child));
        $this->postJson($familyUrl.'/shares/'.$share.'/consent',['consent'=>'1'])->assertOk();
        $this->office($school);
        $data=$this->getJson($studentUrl)->assertOk()->json('data');
        $this->assertSame('Oct 15, 2026, 11:06 PM',$data['issues'][0]['issued_at_display']);
        $this->assertSame('Practice Office',$data['issues'][0]['issued_by_name']);
        $this->assertSame('Practice Office',$data['shares'][0]['requested_by_name']);
        $this->assertSame('Practice Guardian',$data['shares'][0]['consented_by_name']);
        $this->assertSame('Oct 15, 2026, 11:06 PM',$data['shares'][0]['consented_at_display']);
        $this->assertSame('Practice Office',$data['document']['years'][0]['courses'][0]['staff']['by_name']);
        $snapshot=TranscriptIssue::find($issue)->snapshot; $html=view('pdf.transcript',['document'=>$snapshot])->render();
        $this->assertStringContainsString('Issued Oct 15, 2026, 11:06 PM by Practice Office',$html);
        $this->assertStringContainsString('Grade 10',$html); $this->assertStringNotContainsString('Grade 10th',$html);
        $this->assertStringNotContainsString('Practice Guardian',$html); $this->assertStringNotContainsString('Private reason',$html);
        $this->office($receiver);
        $this->getJson($this->base($receiver).'/inbox/'.$share)->assertOk()->assertJsonPath('data.requested_at_display','Oct 15, 2026, 11:06 PM');
        $this->postJson($this->base($receiver).'/inbox/'.$share.'/accept')->assertCreated()->assertJsonPath('data.received_at_display','Oct 15, 2026, 11:06 PM')->assertJsonPath('data.accepted_by_name','Practice Office');
        $this->family($guardian);
        $this->getJson($familyUrl)->assertOk()->assertJsonPath('shares.0.status','accepted')->assertJsonPath('shares.0.receiving_school',$receiver->name)->assertJsonPath('shares.0.resolved_on_display','Oct 15, 2026');
    }


}
