<?php

namespace Tests\Feature;

use App\Models\{Contact, Form, FormResponse, Group, GroupMembership, GroupStaff, Masjid, MasjidUser, StudentAllergy, User};
use App\Support\{StudentAllergies, TenantContext};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\{DataProvider, Test};
use Tests\TestCase;

class StudentAllergiesTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $school;
    private Group $class;
    private Contact $child;
    private Contact $parent;
    private GroupMembership $member;
    private User $office;
    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        \App\Support\StudentAge::forget();
        GroupMembership::forgetConsentCarryReady();
        fake()->seed(3815);
        $this->travelTo(\Carbon\Carbon::parse('2026-10-11 16:00:00', 'UTC'));
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->school = Masjid::create(['name'=>'Practice School','email'=>'practice@example.invalid','phone'=>'+15555550100','country_id'=>'1','city_id'=>'1','address'=>'Practice','latitude'=>0,'longitude'=>0,'org_type'=>'school','crm_enabled'=>true]);
        $this->class = Group::factory()->create(['masjid_id'=>$this->school->id,'kind'=>'class','name'=>'Practice Class','slug'=>'practice-class']);
        $this->child = Contact::factory()->create(['masjid_id'=>$this->school->id,'first_name'=>'Robin','last_name'=>'Willow','email'=>'robin@example.invalid','phone'=>'+15555550103']);
        $this->parent = Contact::factory()->create(['masjid_id'=>$this->school->id,'first_name'=>'Casey','last_name'=>'Willow','email'=>'casey@example.invalid','phone'=>'+15555550104']);
        $this->member = GroupMembership::create(['masjid_id'=>$this->school->id,'group_id'=>$this->class->id,'contact_id'=>$this->child->id,'role'=>'member']);
        $this->guardian($this->child);
        $this->office = User::factory()->create(['type'=>'SuperAdmin','name'=>'Practice Office','email'=>'office@example.invalid','phone'=>'+15555550101']);
        $this->teacher = User::factory()->create(['type'=>'Teacher','name'=>'Practice Teacher','email'=>'teacher@example.invalid','phone'=>'+15555550102']);
        MasjidUser::create(['masjid_id'=>$this->school->id,'user_id'=>$this->teacher->id,'role'=>'teacher','is_default'=>true]);
        GroupStaff::create(['masjid_id'=>$this->school->id,'group_id'=>$this->class->id,'user_id'=>$this->teacher->id,'subjects'=>['arabic']]);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->forgetTenant();
        \App\Support\StudentAge::forget();
        GroupMembership::forgetConsentCarryReady();
        parent::tearDown();
    }

    private function guardian(Contact $child): void
    {
        GroupMembership::create(['masjid_id'=>$this->school->id,'group_id'=>$this->class->id,'contact_id'=>$this->parent->id,'role'=>'guardian','guardian_of_contact_id'=>$child->id,'provenance'=>'confirmed']);
    }

    private function enabled(bool $on = true): void
    {
        $this->school->forceFill(['capability_overrides'=>['student_allergies'=>$on]])->save();
    }

    private function rowUrl(): string { return "/api/admin/masjids/{$this->school->id}/groups/{$this->class->id}/members/{$this->member->id}/allergies"; }
    private function officeRoster(): string { return "/api/admin/masjids/{$this->school->id}/groups/{$this->class->id}/members"; }
    private function teacherClass(): string { return "/api/teacher/masjids/{$this->school->id}/groups/{$this->class->id}"; }

    private function form(bool $repeat = true): Form
    {
        return Form::factory()->create(['masjid_id'=>$this->school->id,'schema'=>['sections'=>[['id'=>'children','repeatable'=>$repeat,'fields'=>[['name'=>$repeat?'firstName':'studentFirstName','label'=>'First name','type'=>'text'],['name'=>$repeat?'lastName':'studentLastName','label'=>'Last name','type'=>'text'],['name'=>'allergies','label'=>'Allergies or special needs','type'=>'textarea']]]]]]);
    }

    private function response(Form $form, array $children, string $status = 'new', ?string $email = 'casey@example.invalid'): FormResponse
    {
        return FormResponse::create(['masjid_id'=>$this->school->id,'form_id'=>$form->id,'respondent_email'=>$email,'respondent_phone'=>'+15555550104','status'=>$status,'data'=>['children'=>$children],'submitted_at'=>now()]);
    }

    #[Test]
    public function allergies_off_literal_sql_and_payload_match_main(): void
    {
        $actual=[];
        foreach (['office-roster'=>[$this->office,$this->officeRoster()], 'student-details'=>[$this->office,str_replace('/allergies','/birth-date',$this->rowUrl())], 'teacher-roster'=>[$this->teacher,$this->teacherClass()], 'teacher-attendance'=>[$this->teacher,$this->teacherClass().'/attendance?date=2026-10-11']] as $name=>[$user,$url]) {
            Sanctum::actingAs($user,['staff']);
            DB::flushQueryLog(); DB::enableQueryLog();
            $r=$this->getJson($url)->assertOk();
            $actual[$name]=['sql'=>array_column(DB::getQueryLog(),'query'),'payload'=>$r->json()];
            DB::disableQueryLog();
        }
        $this->assertSame(json_decode(file_get_contents(base_path('tests/fixtures/student-allergies-off-52daab37.json')), true), $actual);
    }

    public static function noneAnswers(): array
    {
        $out=[];
        foreach (['','none','no','n/a','na','nil','nothing','no allergies','none known','-','--','.'] as $answer) {
            foreach ([$answer, '  '.strtoupper($answer).'  ', '('.strtoupper($answer).')!'] as $variant) $out[]=[$variant];
        }
        return $out;
    }

    #[Test, DataProvider('noneAnswers')]
    public function allergies_none_answers_are_empty(string $answer): void
    {
        $this->assertNull(StudentAllergies::normalise($answer));
    }

    #[Test]
    public function allergies_other_words_are_preserved_and_trimmed(): void
    {
        foreach (['Peanuts!', 'No nuts', 'none known, except dairy', 'N/A medication', 'A.B.'] as $answer) $this->assertSame($answer,StudentAllergies::normalise('  '.$answer.'  '));
        $this->assertSame('Peanuts', StudentAllergies::normalise("\u{00a0}Peanuts\u{00a0}"));
    }

    #[Test]
    public function allergies_off_routes_are_404_even_for_super_and_catalogue_is_hidden(): void
    {
        Sanctum::actingAs($this->office,['staff']);
        $this->getJson($this->rowUrl())->assertNotFound();
        $this->putJson($this->rowUrl(),['allergies'=>'Peanuts'])->assertNotFound();
        $this->postJson("/api/admin/masjids/{$this->school->id}/students/copy-allergies",['form_id'=>1,'field'=>'allergies'])->assertNotFound();
        $this->getJson("/api/admin/masjids/{$this->school->id}/groups/{$this->class->id}/roster.csv")->assertNotFound();
        $this->assertFalse($this->school->hasCapability('student_allergies'));
    }

    #[Test]
    public function allergies_office_edits_reads_and_clears_only_students(): void
    {
        $this->enabled(); Sanctum::actingAs($this->office,['staff']);
        $this->putJson($this->rowUrl(),['allergies'=>'  Peanuts!  '])->assertOk()->assertJsonPath('data.allergies','Peanuts!')->assertJsonPath('data.source','office');
        $this->getJson($this->rowUrl())->assertOk()->assertJsonPath('data.allergies','Peanuts!');
        $this->assertAllergyRecord(['contact_id'=>$this->child->id,'changed_by_user_id'=>$this->office->id]);
        $this->getJson($this->officeRoster())->assertOk()->assertJsonPath('data.1.student_allergy.allergies','Peanuts!');
        $this->putJson($this->rowUrl(),['allergies'=>str_repeat('x',501)])->assertUnprocessable();
        $this->putJson($this->rowUrl(),['allergies'=>'(NONE)!'])->assertOk()->assertJsonPath('data.allergies',null);
        $this->assertAllergyRecord(['contact_id'=>$this->child->id,'allergies'=>null,'source'=>'office']);
    }

    #[Test]
    public function allergies_copy_exact_name_guardian_preview_idempotence_cancelled_unmatched_and_twins(): void
    {
        $this->enabled(); $form=$this->form();
        $this->response($form,[['firstName'=>'  ROBIN ','lastName'=>'Willow','allergies'=>'Peanuts'],['firstName'=>'Absent','lastName'=>'Child','allergies'=>'Dairy'],['firstName'=>'Robin','lastName'=>'Willow','allergies'=>'none']]);
        $this->response($form,[['firstName'=>'Robin','lastName'=>'Willow','allergies'=>'Wrong']], 'cancelled');
        Sanctum::actingAs($this->office,['staff']);
        $url="/api/admin/masjids/{$this->school->id}/students/copy-allergies";
        $preview=$this->postJson($url,['form_id'=>$form->id,'field'=>'allergies','dry_run'=>true])->assertOk();
        $preview->assertJsonPath('data.counts.children_read',3)->assertJsonPath('data.counts.with_allergy',2)->assertJsonPath('data.counts.matched',2)->assertJsonPath('data.counts.unmatched',1);
        $this->assertDatabaseCount('student_allergies',0);
        $this->postJson($url,['form_id'=>$form->id,'field'=>'allergies'])->assertOk()->assertJsonPath('data.counts.written',1);
        $this->postJson($url,['form_id'=>$form->id,'field'=>'allergies'])->assertOk()->assertJsonPath('data.counts.already_set',1)->assertJsonPath('data.counts.written',0);
        $this->putJson($this->rowUrl(),['allergies'=>null])->assertOk();
        $this->postJson($url,['form_id'=>$form->id,'field'=>'allergies'])->assertOk()->assertJsonPath('data.counts.office_wins',1)->assertJsonPath('data.counts.written',0);
        app(TenantContext::class)->forgetTenant();
        $twin=Contact::factory()->create(['masjid_id'=>$this->school->id,'first_name'=>'Robin','last_name'=>'Willow','email'=>null]);
        GroupMembership::create(['masjid_id'=>$this->school->id,'group_id'=>$this->class->id,'contact_id'=>$twin->id,'role'=>'member']); $this->guardian($twin);
        $this->postJson($url,['form_id'=>$form->id,'field'=>'allergies','dry_run'=>true])->assertOk()->assertJsonPath('data.counts.ambiguous',2);
    }

    #[Test]
    public function allergies_copy_phone_fallback_flat_form_and_command_dry_run(): void
    {
        $this->enabled(); $form=$this->form(false);
        FormResponse::create(['masjid_id'=>$this->school->id,'form_id'=>$form->id,'respondent_email'=>null,'respondent_phone'=>'+1 (555) 555-0104','status'=>'new','submitted_at'=>now(),'data'=>['studentFirstName'=>'Robin','studentLastName'=>'Willow','allergies'=>'Dairy']]);
        $this->artisan('students:copy-allergies',['--masjid'=>$this->school->id,'--form'=>$form->id,'--field'=>'allergies','--dry-run'=>true])->assertSuccessful();
        $this->assertDatabaseCount('student_allergies',0);
        $this->artisan('students:copy-allergies',['--masjid'=>$this->school->id,'--form'=>$form->id,'--field'=>'allergies'])->assertSuccessful();
        $this->assertAllergyRecord(['contact_id'=>$this->child->id,'allergies'=>'Dairy','source'=>'form']);
    }

    #[Test]
    public function allergies_teacher_access_is_class_assignment_including_limited_and_current_students(): void
    {
        $this->enabled(); Sanctum::actingAs($this->office,['staff']); $this->putJson($this->rowUrl(),['allergies'=>'Peanuts sentinel'])->assertOk();
        Sanctum::actingAs($this->teacher,['staff']);
        $this->getJson($this->teacherClass())->assertOk()->assertJsonPath('data.students.0.student_allergy.allergies','Peanuts sentinel');
        $this->getJson($this->teacherClass().'/attendance')->assertOk()->assertJsonPath('data.students.0.student_allergy.allergies','Peanuts sentinel');
        $this->getJson("/api/teacher/masjids/{$this->school->id}/groups")->assertOk()->assertJsonPath('data.0.students.0.student_allergy.allergies','Peanuts sentinel');
        app(TenantContext::class)->forgetTenant();
        $other=Group::factory()->create(['masjid_id'=>$this->school->id,'kind'=>'class']);
        $this->getJson("/api/teacher/masjids/{$this->school->id}/groups/{$other->id}")->assertForbidden();
        $this->member->forceFill(['left_on'=>'2026-10-11'])->save();
        $this->getJson($this->teacherClass())->assertOk()->assertJsonPath('data.students',[]);
        $this->assertDatabaseCount('student_allergies',1);
        app(TenantContext::class)->forgetTenant();
        GroupMembership::create(['masjid_id'=>$this->school->id,'group_id'=>$other->id,'contact_id'=>$this->child->id,'role'=>'member']);
        $newTeacher=User::factory()->create(['type'=>'Teacher','phone'=>'+15555550205']);
        MasjidUser::create(['masjid_id'=>$this->school->id,'user_id'=>$newTeacher->id,'role'=>'teacher']);
        GroupStaff::create(['masjid_id'=>$this->school->id,'group_id'=>$other->id,'user_id'=>$newTeacher->id]);
        Sanctum::actingAs($newTeacher,['staff']);
        $this->getJson("/api/teacher/masjids/{$this->school->id}/groups/{$other->id}")->assertOk()->assertJsonPath('data.students.0.student_allergy.allergies','Peanuts sentinel');
    }

    #[Test]
    public function allergies_new_routes_fence_tenant_group_membership_and_form(): void
    {
        $this->enabled(); Sanctum::actingAs($this->office,['staff']);
        $other=Masjid::create(['name'=>'Second Practice School','email'=>'second@example.invalid','phone'=>'+15555550200','country_id'=>'1','city_id'=>'1','address'=>'Practice','latitude'=>0,'longitude'=>0,'org_type'=>'school','crm_enabled'=>true]);
        $contact=Contact::factory()->create(['masjid_id'=>$other->id]);
        $group=Group::factory()->create(['masjid_id'=>$other->id,'kind'=>'class']);
        $member=GroupMembership::create(['masjid_id'=>$other->id,'group_id'=>$group->id,'contact_id'=>$contact->id,'role'=>'member']);
        $form=Form::factory()->create(['masjid_id'=>$other->id]);
        foreach (['GET','PUT'] as $verb) {
            $this->json($verb,str_replace("/members/{$this->member->id}","/members/{$member->id}",$this->rowUrl()),['allergies'=>'Wrong'])->assertNotFound();
            $this->json($verb,str_replace("/groups/{$this->class->id}","/groups/{$group->id}",$this->rowUrl()),['allergies'=>'Wrong'])->assertNotFound();
        }
        $this->postJson("/api/admin/masjids/{$this->school->id}/students/copy-allergies",['form_id'=>$form->id,'field'=>'allergies'])->assertNotFound();
        $this->getJson("/api/admin/masjids/{$this->school->id}/groups/{$group->id}/roster.csv")->assertNotFound();
        $this->assertDatabaseCount('student_allergies',0);
    }

    #[Test]
    public function allergies_contact_force_delete_and_merge_cascade_without_reassigning_health_record(): void
    {
        $this->enabled(); Sanctum::actingAs($this->office,['staff']); $this->putJson($this->rowUrl(),['allergies'=>'Peanuts'])->assertOk();
        app(TenantContext::class)->forgetTenant(); $other=Contact::factory()->create(['masjid_id'=>$this->school->id]);
        $this->postJson("/api/admin/masjids/{$this->school->id}/contacts/{$this->child->id}/merge",['target_contact_id'=>$other->id])->assertOk();
        $this->assertDatabaseCount('student_allergies',0);
        StudentAllergy::create(['masjid_id'=>$this->school->id,'contact_id'=>$other->id,'allergies'=>'Dairy','source'=>'office']);
        $this->child=$other;
        $this->child->delete(); $this->assertDatabaseCount('student_allergies',1);
        $this->child->forceDelete(); $this->assertDatabaseCount('student_allergies',0);
    }
    #[Test]
    public function allergies_reads_add_one_query_at_three_and_thirty_students_and_zero_off(): void
    {
        $evidence=[];
        foreach ([3,30] as $size) {
            app(TenantContext::class)->forgetTenant();
            while (GroupMembership::where('group_id',$this->class->id)->where('role','member')->count() < $size) {
                $child=Contact::factory()->create(['masjid_id'=>$this->school->id]);
                GroupMembership::create(['masjid_id'=>$this->school->id,'group_id'=>$this->class->id,'contact_id'=>$child->id,'role'=>'member']);
            }
            foreach (['office'=>[$this->office,$this->officeRoster()], 'teacher'=>[$this->teacher,$this->teacherClass()], 'register'=>[$this->teacher,$this->teacherClass().'/attendance']] as $name=>[$user,$url]) {
                $logs=[];
                foreach ([false,true] as $on) {
                    $this->enabled($on); Sanctum::actingAs($user,['staff']);
                    $this->getJson($url)->assertOk(); // warm only pre-existing schema and last_seen memos
                    DB::flushQueryLog(); DB::enableQueryLog();
                    $this->getJson($url)->assertOk();
                    $logs[(int)$on]=array_column(DB::getQueryLog(),'query'); DB::disableQueryLog();
                }
                $off=$logs[0]; $on=$logs[1];
                $notes=array_values(array_filter($on,fn($sql)=>str_contains($sql,'"student_allergies"')));
                $this->assertCount(1,$notes,"$name at $size");
                $this->assertSame($off,array_values(array_filter($on,fn($sql)=>!str_contains($sql,'"student_allergies"'))));
                $this->assertCount(count($off)+1,$on);
                $evidence[$size][$name]=['off'=>count($off),'on'=>count($on),'notes'=>count($notes)];
            }
        }
        file_put_contents('/private/tmp/student-allergies-query-counts.json',json_encode($evidence,JSON_PRETTY_PRINT));
    }

    #[Test]
    public function allergies_multi_school_teacher_sees_only_assigned_classes_and_school(): void
    {
        $this->enabled();
        StudentAllergy::create(['masjid_id'=>$this->school->id,'contact_id'=>$this->child->id,'allergies'=>'Peanuts sentinel','source'=>'office']);
        $second=Masjid::create(['name'=>'Second Practice School','email'=>'second@example.invalid','phone'=>'+15555550200','country_id'=>'1','city_id'=>'1','address'=>'Practice','latitude'=>0,'longitude'=>0,'org_type'=>'school','crm_enabled'=>true]);
        $second->forceFill(['capability_overrides'=>['student_allergies'=>true]])->save();
        $group=Group::factory()->create(['masjid_id'=>$second->id,'kind'=>'class']);
        $child=Contact::factory()->create(['masjid_id'=>$second->id]);
        GroupMembership::create(['masjid_id'=>$second->id,'group_id'=>$group->id,'contact_id'=>$child->id,'role'=>'member']);
        StudentAllergy::create(['masjid_id'=>$second->id,'contact_id'=>$child->id,'allergies'=>'Dairy sentinel','source'=>'office']);
        MasjidUser::create(['masjid_id'=>$second->id,'user_id'=>$this->teacher->id,'role'=>'teacher','is_default'=>false]);
        Sanctum::actingAs($this->teacher,['staff']);
        $this->getJson("/api/teacher/masjids/{$second->id}/groups")->assertOk()->assertJsonPath('data',[]);
        $this->getJson("/api/teacher/masjids/{$second->id}/groups/{$group->id}")->assertForbidden();
        $this->getJson("/api/teacher/masjids/{$second->id}/groups/{$this->class->id}")->assertNotFound();
        app(TenantContext::class)->forgetTenant();
        GroupStaff::create(['masjid_id'=>$second->id,'group_id'=>$group->id,'user_id'=>$this->teacher->id,'subjects'=>['arabic']]);
        $this->getJson("/api/teacher/masjids/{$second->id}/groups")->assertOk()->assertJsonPath('data.0.students.0.student_allergy.allergies','Dairy sentinel');
        $this->assertStringNotContainsString('Peanuts sentinel',$this->getJson("/api/teacher/masjids/{$second->id}/groups/{$group->id}/attendance")->assertOk()->getContent());
        $this->getJson($this->teacherClass())->assertOk()->assertJsonPath('data.students.0.student_allergy.allergies','Peanuts sentinel');
    }

    #[Test]
    public function allergies_family_child_mode_mobile_device_public_and_other_exports_walk_is_clean(): void
    {
        $this->enabled();
        StudentAllergy::create(['masjid_id'=>$this->school->id,'contact_id'=>$this->child->id,'allergies'=>'PRIVATE_HEALTH_SENTINEL_Peanuts','source'=>'office']);
        $this->parent->forceFill(['login_email'=>'casey.family@example.invalid','login_enabled_at'=>now()])->save();
        $this->child->forceFill(['login_email'=>'robin.member@example.invalid','verified_at'=>now()])->save();
        $device=\App\Models\MobileAppUser::create(['masjid_id'=>$this->school->id,'contact_id'=>$this->child->id,'device_id'=>'practice-device','user_agent'=>'Practice']);
        config(['member_portal.enabled'=>true,'member_portal.masjid_ids'=>[]]);
        $params=['masjid_id'=>$this->school->id,'group_id'=>$this->class->id,'membership_id'=>$this->member->id,'device_id'=>'practice-device'];
        $familyToken=$this->parent->createFamilyToken()->plainTextToken;
        $childToken=$this->parent->createStudentHandoffToken($this->member->id)->plainTextToken;
        $memberToken=$this->child->createMemberToken()->plainTextToken;
        $counts=['family'=>0,'child'=>0,'member'=>0];
        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            if (!in_array('GET',$route->methods(),true) || preg_match('#/(pdf|download|document|playback)$|/attachments/#',$route->uri())) continue;
            $realm=null;
            if (str_starts_with($route->uri(),'api/family/masjids/{masjid_id}')) $realm=str_contains($route->uri(),'/student/')?'child':'family';
            if (str_starts_with($route->getActionName(),'App\\Http\\Controllers\\Mobile\\Member\\')) $realm='member';
            if ($realm===null) continue;
            $uri=preg_replace_callback('/\{(\w+)\??\}/',fn($m)=>(string)($params[$m[1]]??1),$route->uri());
            \Illuminate\Support\Facades\Auth::forgetGuards(); app(TenantContext::class)->forgetTenant();
            $token=['family'=>$familyToken,'child'=>$childToken,'member'=>$memberToken][$realm];
            $response=$this->withHeader('Authorization','Bearer '.$token)->get('/'.$uri,['Accept'=>'application/json']);
            $this->assertLessThan(500,$response->status(),$uri);
            $body=$response->baseResponse instanceof \Symfony\Component\HttpFoundation\StreamedResponse ? $response->streamedContent() : (string)$response->getContent();
            $this->assertAllergyClean($body,$uri); $counts[$realm]++;
        }
        $this->assertGreaterThan(10,$counts['family']); $this->assertGreaterThan(0,$counts['child']); $this->assertGreaterThan(0,$counts['member']);
        \Illuminate\Support\Facades\Auth::forgetGuards(); app(TenantContext::class)->forgetTenant();
        $this->withHeader('Authorization','Bearer '.$familyToken)->getJson("/api/family/masjids/{$this->school->id}/groups/{$this->class->id}")->assertOk();
        \Illuminate\Support\Facades\Auth::forgetGuards();
        $this->withHeader('Authorization','Bearer '.$childToken)->getJson("/api/family/masjids/{$this->school->id}/groups/{$this->class->id}/members/{$this->member->id}/student/me")->assertOk();
        \Illuminate\Support\Facades\Auth::forgetGuards(); app(TenantContext::class)->forgetTenant();
        $this->withHeader('Authorization','');
        foreach ([$this->postJson('/api/mobile/user',['device_id'=>'practice-device','masjid_id'=>$this->school->id])->assertOk(),$this->putJson('/api/mobile/user',['device_id'=>'practice-device','masjid_id'=>$this->school->id])->assertOk(),$this->getJson('/api/mobile/user/masjid?device_id=practice-device')->assertOk(),$this->getJson("/api/mobile/masjids/{$this->school->id}")->assertOk()] as $response) {
            $this->assertLessThan(500,$response->status()); $this->assertAllergyClean((string)$response->getContent(),'device/public');
        }
        $this->assertAllergyClean($this->child->fresh()->toJson(),'Contact');
        Sanctum::actingAs($this->office,['staff']);
        $this->class->staff()->detach();
        $datasets=(new \ReflectionClassConstant(\App\Http\Controllers\AdminDashboard\SchoolRecordsExportController::class,'DATASETS'))->getValue();
        foreach ($datasets as $dataset) $this->assertAllergyClean($this->get("/api/admin/masjids/{$this->school->id}/records/export?dataset={$dataset}")->assertOk()->streamedContent(),$dataset);
        $csv=$this->get("/api/admin/masjids/{$this->school->id}/groups/{$this->class->id}/roster.csv")->assertOk()->streamedContent();
        $this->assertStringContainsString('Allergies',$csv); $this->assertStringContainsString('PRIVATE_HEALTH_SENTINEL_Peanuts',$csv);
        file_put_contents('/private/tmp/student-allergies-privacy-walk.json',json_encode($counts));
    }

    private function assertAllergyClean(string $body,string $what): void
    {
        foreach (['PRIVATE_HEALTH_SENTINEL_Peanuts','student_allergy','"allergies"'] as $forbidden) $this->assertStringNotContainsString($forbidden,$body,$what);
    }

    #[Test]
    public function allergies_copy_newest_nonempty_answer_wins_and_repeated_runs_do_not_rewrite(): void
    {
        $this->enabled(); $form=$this->form();
        $this->response($form,[['firstName'=>'Robin','lastName'=>'Willow','allergies'=>'Old answer']]);
        $latest=$this->response($form,[['firstName'=>'Robin','lastName'=>'Willow','allergies'=>'Latest answer']]);
        Sanctum::actingAs($this->office,['staff']);
        $url="/api/admin/masjids/{$this->school->id}/students/copy-allergies";
        $this->postJson($url,['form_id'=>$form->id,'field'=>'allergies'])->assertOk()->assertJsonPath('data.counts.written',1);
        $this->travel(1)->days();
        $this->postJson($url,['form_id'=>$form->id,'field'=>'allergies'])->assertOk()->assertJsonPath('data.counts.written',0);
        $this->assertAllergyRecord(['contact_id'=>$this->child->id,'allergies'=>'Latest answer','form_response_id'=>$latest->id,'updated_at'=>'2026-10-11 16:00:00']);
    }

    #[Test]
    public function allergies_office_tenant_and_student_role_fences_apply_to_every_new_route(): void
    {
        $this->enabled();
        $other=Masjid::create(['name'=>'Second Practice School','email'=>'second@example.invalid','phone'=>'+15555550200','country_id'=>'1','city_id'=>'1','address'=>'Practice','latitude'=>0,'longitude'=>0,'org_type'=>'school','crm_enabled'=>true]);
        $other->forceFill(['capability_overrides'=>['student_allergies'=>true]])->save();
        $admin=User::factory()->create(['type'=>'MasjidAdmin','phone'=>'+15555550201']);
        $this->school->forceFill(['user_id'=>$admin->id])->save();
        MasjidUser::create(['masjid_id'=>$this->school->id,'user_id'=>$admin->id,'role'=>'masjid-admin','is_default'=>true]);
        Sanctum::actingAs($admin,['staff']);
        foreach (['GET'=>['students/allergy-forms',"groups/{$this->class->id}/roster.csv","groups/{$this->class->id}/members/{$this->member->id}/allergies"], 'POST'=>['students/copy-allergies'], 'PUT'=>["groups/{$this->class->id}/members/{$this->member->id}/allergies"]] as $method=>$paths) {
            foreach ($paths as $path) $this->json($method,"/api/admin/masjids/{$other->id}/{$path}",['form_id'=>1,'field'=>'allergies','allergies'=>'Wrong'])->assertForbidden();
        }
        app(TenantContext::class)->forgetTenant();
        $form=$this->form();
        $otherForm=Form::factory()->create(['masjid_id'=>$other->id,'name'=>'Second Practice Enrollment']);
        $this->getJson("/api/admin/masjids/{$this->school->id}/students/allergy-forms")->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.id',$form->id);
        $guardian=GroupMembership::withoutMasjidScope()->where('role','guardian')->where('guardian_of_contact_id',$this->child->id)->firstOrFail();
        foreach (['GET','PUT'] as $method) $this->json($method,str_replace("/members/{$this->member->id}","/members/{$guardian->id}",$this->rowUrl()),['allergies'=>'Wrong'])->assertUnprocessable();
        Sanctum::actingAs($this->teacher,['staff']);
        $this->putJson($this->rowUrl(),['allergies'=>'Teacher write'])->assertUnauthorized();
        $this->assertDatabaseCount('student_allergies',0);
    }

    #[Test]
    public function allergies_timetable_only_teacher_does_not_gain_class_health_access(): void
    {
        $this->enabled();
        StudentAllergy::create(['masjid_id'=>$this->school->id,'contact_id'=>$this->child->id,'allergies'=>'Peanuts','source'=>'office']);
        $year=\App\Models\SchoolYear::create(['masjid_id'=>$this->school->id,'label'=>'Practice year','first_day'=>'2026-09-01','last_day'=>'2027-06-30']);
        $set=\App\Models\TimetablePeriodSet::create(['masjid_id'=>$this->school->id,'school_year_id'=>$year->id,'name'=>'Practice set']);
        $period=\App\Models\TimetablePeriod::create(['masjid_id'=>$this->school->id,'period_set_id'=>$set->id,'name'=>'Practice period','starts_at'=>'09:00','ends_at'=>'10:00','kind'=>'teaching','position'=>1]);
        $meeting=\App\Models\TimetableMeeting::create(['masjid_id'=>$this->school->id,'school_year_id'=>$year->id,'group_id'=>$this->class->id,'period_id'=>$period->id,'weekday'=>0,'kind'=>'class','effective_from'=>'2026-09-01']);
        \App\Models\TimetableMeetingTeacher::create(['masjid_id'=>$this->school->id,'meeting_id'=>$meeting->id,'user_id'=>$this->teacher->id]);
        $this->class->staff()->detach($this->teacher->id);
        Sanctum::actingAs($this->teacher,['staff']);
        $this->getJson($this->teacherClass())->assertForbidden();
        $this->getJson($this->teacherClass().'/attendance')->assertForbidden();
        $this->getJson("/api/teacher/masjids/{$this->school->id}/groups")->assertOk()->assertJsonPath('data',[]);
    }

    #[Test]
    public function allergies_a_later_none_form_answer_clears_a_copied_flag_but_preserves_office_edits(): void
    {
        $this->enabled(); $form=$this->form();
        $this->response($form,[['firstName'=>'Robin','lastName'=>'Willow','allergies'=>'Peanuts']]);
        Sanctum::actingAs($this->office,['staff']);
        $url="/api/admin/masjids/{$this->school->id}/students/copy-allergies";
        $this->postJson($url,['form_id'=>$form->id,'field'=>'allergies'])->assertOk();
        $latest=$this->response($form,[['firstName'=>'Robin','lastName'=>'Willow','allergies'=>'(NONE)!']]);
        $this->postJson($url,['form_id'=>$form->id,'field'=>'allergies'])->assertOk()->assertJsonPath('data.counts.written',1);
        $this->assertAllergyRecord(['contact_id'=>$this->child->id,'allergies'=>null,'source'=>'form','form_response_id'=>$latest->id]);
        $this->postJson($url,['form_id'=>$form->id,'field'=>'allergies'])->assertOk()->assertJsonPath('data.counts.written',0);
        $this->putJson($this->rowUrl(),['allergies'=>'Office answer'])->assertOk();
        $this->postJson($url,['form_id'=>$form->id,'field'=>'allergies'])->assertOk()->assertJsonPath('data.counts.office_wins',1);
        $this->assertAllergyRecord(['contact_id'=>$this->child->id,'allergies'=>'Office answer','source'=>'office']);
    }

    #[Test]
    public function allergies_storage_is_encrypted_and_serialising_a_contact_never_loads_it(): void
    {
        $this->enabled(); Sanctum::actingAs($this->office,['staff']);
        $this->putJson($this->rowUrl(),['allergies'=>'Private peanuts note'])->assertOk();
        $raw=DB::table('student_allergies')->where('contact_id',$this->child->id)->value('allergies');
        $this->assertStringNotContainsString('Private peanuts note',$raw);
        $this->assertSame('Private peanuts note',StudentAllergy::where('contact_id',$this->child->id)->firstOrFail()->allergies);
        DB::flushQueryLog(); DB::enableQueryLog(); $body=$this->child->fresh()->toJson();
        $this->assertStringNotContainsString('student_allergies',implode(' ',array_column(DB::getQueryLog(),'query'))); DB::disableQueryLog();
        $this->assertStringNotContainsString('Private peanuts note',$body); $this->assertStringNotContainsString('allerg',$body);
        $this->assertSame(['student_allergies'=>['contact_id']],\App\Services\Member\MemberAccountDeletion::CASCADE_RECORDS);
    }

    private function assertAllergyRecord(array $expected): void
    {
        $hasAnswer=array_key_exists('allergies',$expected);
        $answer=$expected['allergies']??null; unset($expected['allergies']);
        $this->assertDatabaseHas('student_allergies',$expected);
        if ($hasAnswer) $this->assertSame($answer,StudentAllergy::withoutMasjidScope()->where('contact_id',$expected['contact_id'])->firstOrFail()->allergies);
    }

    #[Test]
    public function allergies_copy_does_not_fall_back_to_phone_when_a_different_email_is_present(): void
    {
        $this->enabled(); $form=$this->form();
        $this->response($form,[['firstName'=>'Robin','lastName'=>'Willow','allergies'=>'Peanuts']], 'new', 'different@example.invalid');
        Sanctum::actingAs($this->office,['staff']);
        $url="/api/admin/masjids/{$this->school->id}/students/copy-allergies";
        $this->postJson($url,['form_id'=>$form->id,'field'=>'allergies'])->assertOk()->assertJsonPath('data.counts.unmatched',1)->assertJsonPath('data.counts.written',0);
        $this->postJson($url,['form_id'=>$form->id,'field'=>'nonexistent'])->assertUnprocessable();
        $this->assertDatabaseCount('student_allergies',0);
        $this->enabled(false);
        $this->artisan('students:copy-allergies',['--masjid'=>$this->school->id,'--form'=>$form->id,'--field'=>'allergies'])->assertFailed();
    }

    #[Test]
    public function allergies_limited_teacher_class_subject_mode_still_reads_whole_class_health(): void
    {
        $this->school->forceFill(['capability_overrides'=>['student_allergies'=>true,'class_subjects'=>true]])->save();
        StudentAllergy::create(['masjid_id'=>$this->school->id,'contact_id'=>$this->child->id,'allergies'=>'Peanuts','source'=>'office']);
        Sanctum::actingAs($this->teacher,['staff']);
        $this->getJson($this->teacherClass())->assertOk()->assertJsonPath('data.students.0.student_allergy.allergies','Peanuts');
        $this->getJson($this->teacherClass().'/attendance')->assertOk()->assertJsonPath('data.students.0.student_allergy.allergies','Peanuts');
    }

}
