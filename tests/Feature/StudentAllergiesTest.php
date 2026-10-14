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
        // Pin exposed fixture text: seeded optional Faker output depends on suite order.
        $this->class = Group::factory()->create(['masjid_id'=>$this->school->id,'kind'=>'class','name'=>'Practice Class','slug'=>'practice-class','description'=>'Quisquam suscipit recusandae animi et amet ut.']);
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
        $expected=json_decode(file_get_contents(base_path('tests/fixtures/student-allergies-off-52daab37.json')), true);
        // The revision wrapper adds these two reads; the frozen allergy fixture
        // and every original reader statement/payload stay literal.
        array_splice($expected['teacher-attendance']['sql'], 3, 0, ['select * from "groups" where "groups"."id" = ? and "masjid_id" = ? and "groups"."deleted_at" is null and "groups"."masjid_id" = ? limit 1']);
        array_splice($expected['teacher-attendance']['sql'], -1, 0, ['select "marks".*, "marker"."name", "marker"."type" from "attendance_records" as "marks" left join "users" as "marker" on "marker"."id" = "marks"."marked_by_user_id" where "marks"."masjid_id" = ? and "marks"."group_id" = ? and strftime(\'%Y-%m-%d\', "marks"."session_date") = cast(? as text) order by "marks"."id" asc']);
        $this->assertSame($expected, $actual);
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
    public function allergies_contact_force_delete_and_merge_preserves_health_record(): void
    {
        $this->enabled(); Sanctum::actingAs($this->office,['staff']); $this->putJson($this->rowUrl(),['allergies'=>'Peanuts'])->assertOk();
        app(TenantContext::class)->forgetTenant(); $other=Contact::factory()->create(['masjid_id'=>$this->school->id]);
        $this->postJson("/api/admin/masjids/{$this->school->id}/contacts/{$this->child->id}/merge",['target_contact_id'=>$other->id])->assertOk();
        $this->assertAllergyRecord(['contact_id'=>$other->id,'allergies'=>'Peanuts','changed_by_user_id'=>$this->office->id]);
        StudentAllergies::edit($other, 'Dairy', $this->office->id);
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
        file_put_contents(sys_get_temp_dir().'/student-allergies-query-counts.json',json_encode($evidence,JSON_PRETTY_PRINT));
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
        $params=$this->seedPrivacyRecords();
        $intakeYear = \App\Models\SchoolYear::create(['masjid_id'=>$this->school->id,'label'=>'Practice intake year','first_day'=>'2027-08-01','last_day'=>'2028-06-30']);
        $intake = \App\Models\SchoolReenrolment::create(['masjid_id'=>$this->school->id,'school_year_id'=>$intakeYear->id,'opens_on'=>'2026-01-01','closes_on'=>'2026-12-31']);
        $params['reenrolment_id'] = $intake->id;
        $familyToken=$this->parent->createFamilyToken()->plainTextToken;
        $childToken=$this->parent->createStudentHandoffToken($this->member->id)->plainTextToken;
        $memberToken=$this->child->createMemberToken()->plainTextToken;
        $counts=['family'=>0,'child'=>0,'member'=>0];
        $walk=[];
        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            if (!in_array('GET',$route->methods(),true)) continue;
            $realm=null;
            if ($route->uri()==='api/family/me') $realm='family';
            if (str_starts_with($route->uri(),'api/family/masjids/{masjid_id}')) $realm=str_contains($route->uri(),'/student/')?'child':'family';
            if (str_starts_with($route->getActionName(),'App\\Http\\Controllers\\Mobile\\Member\\')) $realm='member';
            if ($realm===null) continue;
            if (str_contains($route->uri(),'/messages/')) $params['attachment_id']=$params['message_attachment_id'];
            if (str_contains($route->uri(),'/receipts/')) $params['id']=$params['gift_uuid'];
            $uri=preg_replace_callback('/\{(\w+)\??\}/',fn($m)=>(string)($params[$m[1]]??throw new \RuntimeException('Unseeded privacy route parameter: '.$m[1])),$route->uri());
            \Illuminate\Support\Facades\Auth::forgetGuards(); app(TenantContext::class)->forgetTenant();
            $token=['family'=>$familyToken,'child'=>$childToken,'member'=>$memberToken][$realm];
            $response=$this->withHeader('Authorization','Bearer '.$token)->get('/'.$uri,['Accept'=>'application/json']);
            // The new optional member route is dark; still inspect its refusal for health data.
            $this->assertSame(str_contains($uri,'/reenrolment/') ? 403 : (str_ends_with($uri,'/member-questions') ? 404 : 200),$response->getStatusCode(),$uri);
            $body=($response->baseResponse instanceof \Symfony\Component\HttpFoundation\StreamedResponse || $response->baseResponse instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse) ? $response->streamedContent() : (string)$response->getContent();
            $this->assertNotSame('', $body, $uri);
            if (str_ends_with($uri,'/pdf')) {
                $this->assertStringStartsWith('%PDF-', $body, $uri);
                // PDF text may be compressed: inspect decoded Flate streams as well as the bytes.
                preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $body, $streams);
                foreach ($streams[1] as $stream) {
                    $decoded = @gzuncompress($stream);
                    if ($decoded !== false) $this->assertAllergyClean($decoded, $uri.' decoded stream');
                }
            }
            $this->assertAllergyClean($body,$uri); $counts[$realm]++;
            $walk[]=['route'=>$uri,'status'=>$response->getStatusCode(),'bytes'=>strlen($body)];
        }
        $this->assertGreaterThan(10,$counts['family']); $this->assertGreaterThan(0,$counts['child']); $this->assertGreaterThan(0,$counts['member']);
        \Illuminate\Support\Facades\Auth::forgetGuards(); app(TenantContext::class)->forgetTenant();
        $this->withHeader('Authorization','Bearer '.$familyToken)->getJson("/api/family/masjids/{$this->school->id}/groups/{$this->class->id}")->assertOk();
        \Illuminate\Support\Facades\Auth::forgetGuards();
        $this->withHeader('Authorization','Bearer '.$childToken)->getJson("/api/family/masjids/{$this->school->id}/groups/{$this->class->id}/members/{$this->member->id}/student/me")->assertOk();
        \Illuminate\Support\Facades\Auth::forgetGuards(); app(TenantContext::class)->forgetTenant();
        $this->withHeader('Authorization','');
        foreach ([$this->postJson('/api/mobile/user',['device_id'=>'practice-device','masjid_id'=>$this->school->id])->assertOk(),$this->putJson('/api/mobile/user',['device_id'=>'practice-device','masjid_id'=>$this->school->id])->assertOk(),$this->getJson('/api/mobile/user/masjid?device_id=practice-device')->assertOk(),$this->getJson("/api/mobile/masjids/{$this->school->id}")->assertOk()] as $response) {
            $response->assertOk(); $this->assertAllergyClean((string)$response->getContent(),'device/public');
        }
        $this->assertAllergyClean($this->child->fresh()->toJson(),'Contact');
        Sanctum::actingAs($this->office,['staff']);
        $this->class->staff()->detach();
        $datasets=(new \ReflectionClassConstant(\App\Http\Controllers\AdminDashboard\SchoolRecordsExportController::class,'DATASETS'))->getValue();
        foreach ($datasets as $dataset) $this->assertAllergyClean($this->get("/api/admin/masjids/{$this->school->id}/records/export?dataset={$dataset}")->assertOk()->streamedContent(),$dataset);
        $csv=$this->get("/api/admin/masjids/{$this->school->id}/groups/{$this->class->id}/roster.csv")->assertOk()->streamedContent();
        $this->assertStringContainsString('Allergies',$csv); $this->assertStringContainsString('PRIVATE_HEALTH_SENTINEL_Peanuts',$csv);
        file_put_contents(sys_get_temp_dir().'/student-allergies-privacy-walk.json',json_encode(['counts'=>$counts,'routes'=>$walk],JSON_PRETTY_PRINT));
    }

    /** Real child-scoped records for every successful family/member detail and download. */
    private function seedPrivacyRecords(): array
    {
        $this->school->forceFill(['capability_overrides'=>['student_allergies'=>true,'class_subjects'=>true,'class_subject_work'=>true,'class_subject_sharing'=>true,'class_store'=>true]])->save();
        GroupMembership::where('role','guardian')->where('guardian_of_contact_id',$this->child->id)->update(['consent_scope'=>'media','consent_granted_at'=>now()]);
        $post=\App\Models\GroupPost::factory()->create(['masjid_id'=>$this->school->id,'group_id'=>$this->class->id,'author_user_id'=>$this->teacher->id,'title'=>'Practice story','body'=>'Practice words']);
        $thread=\App\Models\GroupThread::factory()->create(['masjid_id'=>$this->school->id,'group_id'=>$this->class->id,'scope'=>'participant','about_membership_id'=>$this->member->id,'subject'=>'Practice conversation']);
        $message=\App\Models\GroupMessage::create(['masjid_id'=>$this->school->id,'group_thread_id'=>$thread->id,'author_user_id'=>$this->teacher->id,'body'=>'Practice message']);
        \Illuminate\Support\Facades\Storage::fake('local');
        $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jD1sAAAAASUVORK5CYII=');
        \Illuminate\Support\Facades\Storage::disk('local')->put('practice/story.png',$png);
        \Illuminate\Support\Facades\Storage::disk('local')->put('practice/message.png',$png);
        \Illuminate\Support\Facades\Storage::disk('local')->put('practice/handout.txt','Practice handout');
        $postAttachment=\App\Models\GroupPostAttachment::create(['masjid_id'=>$this->school->id,'group_post_id'=>$post->id,'original_name'=>'story.png','mime_type'=>'image/png','size_bytes'=>strlen($png),'disk'=>'local','path'=>'practice/story.png']);
        $messageAttachment=\App\Models\GroupMessageAttachment::create(['masjid_id'=>$this->school->id,'group_message_id'=>$message->id,'original_name'=>'message.png','mime_type'=>'image/png','size_bytes'=>strlen($png),'disk'=>'local','path'=>'practice/message.png']);
        $resource=\App\Models\GroupResource::create(['masjid_id'=>$this->school->id,'group_id'=>$this->class->id,'uploaded_by_user_id'=>$this->teacher->id,'title'=>'Practice handout','visibility'=>'families','original_name'=>'handout.txt','mime_type'=>'text/plain','size_bytes'=>16,'disk'=>'local','path'=>'practice/handout.txt']);
        $card=\App\Models\ReportCard::create(['masjid_id'=>$this->school->id,'group_id'=>$this->class->id,'group_membership_id'=>$this->member->id,'type'=>'report_card','school_year'=>'2026-2027','term'=>1,'grade_label'=>'1st','teacher_comment'=>'Practice progress']);
        $card->forceFill(['published_at'=>now(),'published_by_user_id'=>$this->teacher->id])->save();
        $subject=\App\Models\ClassSubject::create(['masjid_id'=>$this->school->id,'group_id'=>$this->class->id,'name'=>'Science']);
        $piece=\App\Models\SubjectPiece::create(['masjid_id'=>$this->school->id,'class_subject_id'=>$subject->id,'source'=>'own','title'=>'Practice piece']);
        $mark=\App\Models\SubjectPieceMark::create(['masjid_id'=>$this->school->id,'subject_piece_id'=>$piece->id,'group_membership_id'=>$this->member->id,'level'=>3]);
        $mark->forceFill(['shared_with_family'=>true])->save();
        $note=\App\Models\SubjectNote::create(['masjid_id'=>$this->school->id,'class_subject_id'=>$subject->id,'group_membership_id'=>$this->member->id,'body'=>'Practice feedback']);
        $note->forceFill(['shared_with_family'=>true])->save();
        $order=\App\Models\MealOrder::factory()->paid()->create(['masjid_id'=>$this->school->id,'contact_id'=>$this->child->id,'customer_name'=>'Robin Willow','customer_email'=>$this->child->login_email]);
        $fund=\App\Models\Fund::factory()->create(['masjid_id'=>$this->school->id,'name'=>'Practice fund']);
        $gift=\App\Models\Donation::factory()->succeeded()->create(['masjid_id'=>$this->school->id,'contact_id'=>$this->child->id,'fund_id'=>$fund->id]);
        \App\Models\DonationReceipt::create(['masjid_id'=>$this->school->id,'donation_id'=>$gift->id,'serial_number'=>1,'issue_date'=>'2026-10-11','gross_amount'=>5000,'advantage_amount'=>0,'eligible_amount'=>5000,'currency'=>'usd','jurisdiction'=>'US','status'=>'issued']);
        return ['masjid_id'=>$this->school->id,'group_id'=>$this->class->id,'membership_id'=>$this->member->id,'device_id'=>'practice-device','post_id'=>$post->id,'thread_id'=>$thread->id,'message_id'=>$message->id,'attachment_id'=>$postAttachment->id,'message_attachment_id'=>$messageAttachment->id,'resource_id'=>$resource->id,'report_card_id'=>$card->id,'subject_id'=>$subject->id,'mark_id'=>$mark->id,'note_id'=>$note->id,'source'=>'meal','id'=>$order->id,'gift_uuid'=>$gift->uuid];
    }

    private function assertAllergyClean(string $body,string $what): void
    {
        foreach (['private_health_sentinel_peanuts','student_allergy','allerg'] as $forbidden) $this->assertStringNotContainsString($forbidden,strtolower($body),$what);
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

    #[Test]
    public function allergies_review_merge_moves_combines_refuses_and_runs_without_switch(): void
    {
        Sanctum::actingAs($this->office,['staff']);
        $form=$this->form(); $response=$this->response($form,[]);
        $source=StudentAllergy::create(['masjid_id'=>$this->school->id,'contact_id'=>$this->child->id,'allergies'=>'Peanuts','source'=>'form','form_response_id'=>$response->id]);
        $target=Contact::factory()->create(['masjid_id'=>$this->school->id]);
        $url="/api/admin/masjids/{$this->school->id}/contacts/{$this->child->id}/merge";
        $this->postJson($url,['target_contact_id'=>$target->id])->assertOk();
        $this->assertAllergyRecord(['contact_id'=>$target->id,'allergies'=>'Peanuts','source'=>'form','form_response_id'=>$response->id,'changed_by_user_id'=>$this->office->id]);
        app(TenantContext::class)->forgetTenant();
        foreach (['  Peanuts  ', 'Dairy', str_repeat('x',500)] as $text) {
            $absorbed=Contact::factory()->create(['masjid_id'=>$this->school->id]);
            StudentAllergy::create(['masjid_id'=>$this->school->id,'contact_id'=>$absorbed->id,'allergies'=>$text,'source'=>'form']);
            $r=$this->postJson("/api/admin/masjids/{$this->school->id}/contacts/{$absorbed->id}/merge",['target_contact_id'=>$target->id]);
            if (mb_strlen($text)===500) {
                $r->assertUnprocessable()->assertSee('Both records have allergy notes too long to combine. Shorten one, then merge.');
                $this->assertNotNull($absorbed->fresh());
                $this->assertAllergyRecord(['contact_id'=>$absorbed->id,'allergies'=>$text]);
            } else {
                $r->assertOk();
                $this->assertAllergyRecord(['contact_id'=>$target->id,'allergies'=>$text==='Dairy'?'Peanuts / Dairy':'Peanuts','source'=>$text==='Dairy'?'office':'form']);
            }
            app(TenantContext::class)->forgetTenant();
        }
    }

    #[Test]
    public function allergies_review_unused_school_merge_adds_no_health_query(): void
    {
        Sanctum::actingAs($this->office,['staff']);
        $target=Contact::factory()->create(['masjid_id'=>$this->school->id]);
        DB::flushQueryLog(); DB::enableQueryLog();
        $this->postJson("/api/admin/masjids/{$this->school->id}/contacts/{$this->child->id}/merge",['target_contact_id'=>$target->id])->assertOk();
        $queries=array_column(DB::getQueryLog(),'query'); DB::disableQueryLog();
        file_put_contents(sys_get_temp_dir().'/allergy-unused-merge-sql.json',json_encode($queries,JSON_PRETTY_PRINT));
        $this->assertSame([],array_values(array_filter($queries,fn($q)=>str_contains($q,'student_allerg'))));
    }

    #[Test]
    public function allergies_review_blank_answers_never_clear_or_supersede(): void
    {
        $form=$this->form();
        $real=$this->response($form,[['firstName'=>'Robin','lastName'=>'Willow','allergies'=>'Peanuts']]);
        foreach ([[],['allergies'=>null],['allergies'=>'  ']] as $blank) $this->response($form,[array_merge(['firstName'=>'Robin','lastName'=>'Willow'],$blank)]);
        $r=app(StudentAllergies::class)->copy($form,'allergies',false,$this->office->id);
        $this->enabled();
        $this->artisan('students:copy-allergies',['--masjid'=>$this->school->id,'--form'=>$form->id,'--field'=>'allergies','--dry-run'=>true])->expectsOutputToContain('left unchanged: blank answer')->assertSuccessful();
        $this->assertSame(3,$r['counts']['blank_answer']);
        $this->assertSame('left unchanged: blank answer',$r['children'][0]['outcome']);
        $this->assertAllergyRecord(['contact_id'=>$this->child->id,'allergies'=>'Peanuts','form_response_id'=>$real->id]);
    }

    #[Test]
    public function allergies_review_unreadable_record_is_distinct_and_register_saves(): void
    {
        $this->enabled();
        StudentAllergy::create(['masjid_id'=>$this->school->id,'contact_id'=>$this->child->id,'allergies'=>'Peanuts','source'=>'form']);
        DB::table('student_allergies')->where('contact_id',$this->child->id)->update(['allergies'=>'bad ciphertext']);
        \Illuminate\Support\Facades\Log::spy();
        Sanctum::actingAs($this->office,['staff']);
        $this->getJson($this->officeRoster())->assertOk()->assertJsonPath('data.1.student_allergy.unavailable',true);
        $this->getJson($this->rowUrl())->assertOk()->assertJsonPath('data.unavailable',true);
        Sanctum::actingAs($this->teacher,['staff']);
        $this->getJson($this->teacherClass())->assertOk()->assertJsonPath('data.students.0.student_allergy.unavailable',true);
        $this->getJson($this->teacherClass().'/attendance')->assertOk()->assertJsonPath('data.students.0.student_allergy.unavailable',true);
        $this->putJson($this->teacherClass().'/attendance',['session_date'=>'2026-10-11','marks'=>[['membership_id'=>$this->member->id,'status'=>'present']]])->assertOk()->assertJsonPath('data.students.0.student_allergy.unavailable',true);
        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->with('Student allergy note unavailable', ['masjid_id'=>$this->school->id,'contact_id'=>$this->child->id,'student_allergy_id'=>1])->atLeast()->once();
    }

    #[Test]
    public function allergies_review_repository_forms_choose_child_fields(): void
    {
        $this->enabled(); Sanctum::actingAs($this->office,['staff']);
        foreach (['biss-2026.json'=>true,'alrazi-website-registration.json'=>false] as $file=>$repeat) {
            $json=json_decode(file_get_contents(database_path('forms/'.$file)),true);
            $form=Form::factory()->create(['masjid_id'=>$this->school->id,'schema'=>$json['schema']]);
            $entry=[$repeat?'firstName':'studentFirstName'=>'Robin',$repeat?'lastName':'studentLastName'=>'Willow','allergies'=>'Peanuts'];
            FormResponse::create(['masjid_id'=>$this->school->id,'form_id'=>$form->id,'respondent_email'=>$this->parent->email,'status'=>'new','submitted_at'=>now(),'data'=>$repeat?['children'=>[$entry]]:$entry]);
            $this->getJson("/api/admin/masjids/{$this->school->id}/students/allergy-forms")->assertOk()->assertJsonFragment(['id'=>$form->id]);
            app(StudentAllergies::class)->copy($form,'allergies',false,$this->office->id);
            $this->assertAllergyRecord(['contact_id'=>$this->child->id,'allergies'=>'Peanuts']);
        }
    }

    #[Test]
    public function allergies_review_registration_link_precedes_household_and_is_tenant_fenced(): void
    {
        $form=$this->form();
        $response=$this->response($form,[['firstName'=>'Robin','lastName'=>'Willow','allergies'=>'Peanuts']], 'new', null);
        $response->update(['respondent_phone'=>null]);
        $registration=\App\Models\Registration::factory()->create(['masjid_id'=>$this->school->id,'form_response_id'=>$response->id]);
        \App\Models\Registrant::create(['masjid_id'=>$this->school->id,'registration_id'=>$registration->id,'contact_id'=>$this->child->id]);
        $result=app(StudentAllergies::class)->copy($form,'allergies',false,$this->office->id);
        $this->assertSame(1,$result['counts']['written']);
        $this->assertAllergyRecord(['contact_id'=>$this->child->id,'allergies'=>'Peanuts']);
        StudentAllergy::query()->delete();
        $other=Masjid::create(['name'=>'Other Practice','email'=>'other@example.invalid','phone'=>'+15555550900','country_id'=>'1','city_id'=>'1','address'=>'Practice','latitude'=>0,'longitude'=>0]);
        $registration->update(['masjid_id'=>$other->id]);
        $result=app(StudentAllergies::class)->copy($form,'allergies',false,$this->office->id);
        $this->assertSame(1,$result['counts']['unmatched']);
    }

    #[Test]
    public function allergies_review_symbols_digits_and_other_letters_are_kept(): void
    {
        foreach (['🥜','🥜 🥛','🥜none','none🥜','$','+none','^none','none~','`none','none€','none1','noneñ','—none—','！none！'] as $answer) $this->assertSame($answer,StudentAllergies::normalise($answer));
    }

    #[Test]
    public function allergies_review_long_form_answer_is_flagged_without_truncation(): void
    {
        $form=$this->form();
        $this->response($form,[['firstName'=>'Robin','lastName'=>'Willow','allergies'=>str_repeat('x',500).' Peanuts']]);
        $result=app(StudentAllergies::class)->copy($form,'allergies',false,$this->office->id);
        $this->assertSame(1,$result['counts']['written']);
        $this->assertNotNull(StudentAllergy::where('contact_id',$this->child->id)->first());
        $this->assertSame(str_repeat('x',500).' Peanuts', StudentAllergy::first()->allergies);
    }

    #[Test]
    public function allergies_review_form_text_capacity_and_oversize_warning_guarantee_a_flag(): void
    {
        $this->enabled(); $form=$this->form();
        $answer=str_repeat('🥜',8000);
        $response=$this->response($form,[['firstName'=>'Robin','lastName'=>'Willow','allergies'=>$answer]]);
        app(StudentAllergies::class)->copy($form,'allergies',false,$this->office->id);
        $this->assertAllergyRecord(['contact_id'=>$this->child->id,'allergies'=>$answer]);
        $this->assertSame('text',\Illuminate\Support\Facades\Schema::getColumnType('student_allergies','allergies'));
        $bytes=strlen(DB::table('student_allergies')->value('allergies'));
        $this->assertLessThanOrEqual(65535,$bytes);
        file_put_contents(sys_get_temp_dir().'/allergy-text-capacity.json',json_encode(['characters'=>8000,'plaintext_bytes'=>strlen($answer),'encrypted_bytes'=>$bytes]));
        $this->response($form,[['firstName'=>'Robin','lastName'=>'Willow','allergies'=>$answer.'🥛']]);
        $preview=app(StudentAllergies::class)->copy($form,'allergies',true,$this->office->id);
        $this->assertSame(1,$preview['counts']['too_long']);
        $this->assertAllergyRecord(['contact_id'=>$this->child->id,'allergies'=>$answer]);
        $result=app(StudentAllergies::class)->copy($form,'allergies',false,$this->office->id);
        $this->assertSame('too long: enter by hand',$result['children'][0]['outcome']);
        $this->assertAllergyRecord(['contact_id'=>$this->child->id,'allergies'=>'See enrolment form: answer too long','source'=>'form']);
        Sanctum::actingAs($this->teacher,['staff']);
        $this->getJson($this->teacherClass())->assertOk()->assertJsonPath('data.students.0.student_allergy.allergies','See enrolment form: answer too long');
        Sanctum::actingAs($this->office,['staff']);
        $this->put($this->rowUrl(),['allergies'=>str_repeat('x',501)],['Accept'=>'application/json'])->assertUnprocessable()->assertSee('501 characters');
    }

    #[Test]
    public function allergies_review_linked_children_require_unique_child_association(): void
    {
        $form=$this->form();
        $other=Contact::factory()->create(['masjid_id'=>$this->school->id,'first_name'=>'Alex','last_name'=>'Willow']);
        GroupMembership::create(['masjid_id'=>$this->school->id,'group_id'=>$this->class->id,'contact_id'=>$other->id,'role'=>'member']);
        $response=$this->response($form,[['firstName'=>'Robin','lastName'=>'Willow','allergies'=>'Peanuts'],['firstName'=>'Alex','lastName'=>'Willow','allergies'=>'Dairy']], 'new', null);
        $response->update(['respondent_phone'=>null]);
        $registration=\App\Models\Registration::factory()->create(['masjid_id'=>$this->school->id,'form_response_id'=>$response->id]);
        foreach ([$this->child,$other] as $child) \App\Models\Registrant::create(['masjid_id'=>$this->school->id,'registration_id'=>$registration->id,'contact_id'=>$child->id]);
        $r=app(StudentAllergies::class)->copy($form,'allergies',false,$this->office->id);
        $this->assertSame(2,$r['counts']['written']);
        $this->assertAllergyRecord(['contact_id'=>$this->child->id,'allergies'=>'Peanuts']);
        $this->assertAllergyRecord(['contact_id'=>$other->id,'allergies'=>'Dairy']);
        StudentAllergy::query()->delete();
        $other->update(['first_name'=>'Robin']);
        $r=app(StudentAllergies::class)->copy($form,'allergies',false,$this->office->id);
        $this->assertSame(1,$r['counts']['ambiguous']);
        $this->assertSame(0,$r['counts']['written']);
    }

    #[Test]
    public function allergies_review_last_note_erasure_restores_query_free_merge(): void
    {
        StudentAllergies::edit($this->child,'Peanuts',$this->office->id);
        $this->child->forceDelete();
        $this->assertFalse((bool)$this->school->fresh()->getAttribute('has_student_allergy_records'));
        $this->child=Contact::factory()->create(['masjid_id'=>$this->school->id]);
        $this->allergies_review_unused_school_merge_adds_no_health_query();
    }

    #[Test]
    public function allergies_decorate_both_reader_modes_without_flagging_legacy_leaders_or_other_group_kinds(): void
    {
        StudentAllergy::create(['masjid_id'=>$this->school->id,'contact_id'=>$this->child->id,'allergies'=>'Peanuts','source'=>'office']);
        $leaderContact=Contact::factory()->create(['masjid_id'=>$this->school->id]);
        $leader=GroupMembership::create(['masjid_id'=>$this->school->id,'group_id'=>$this->class->id,'contact_id'=>$leaderContact->id,'role'=>'leader']);
        StudentAllergy::create(['masjid_id'=>$this->school->id,'contact_id'=>$leaderContact->id,'allergies'=>'Leader private note','source'=>'office']);
        $unflagged=Contact::factory()->create(['masjid_id'=>$this->school->id]);
        $unflaggedMember=GroupMembership::create(['masjid_id'=>$this->school->id,'group_id'=>$this->class->id,'contact_id'=>$unflagged->id,'role'=>'member']);
        $year=\App\Models\SchoolYear::create(['masjid_id'=>$this->school->id,'label'=>'Practice year','first_day'=>'2026-09-01','last_day'=>'2027-06-30','meeting_weekdays'=>[0]]);
        Sanctum::actingAs($this->teacher,['staff']);
        foreach ([false,true] as $configured) {
            $this->school->forceFill(['capability_overrides'=>['student_allergies'=>true,'class_subjects'=>$configured,'school_calendar'=>true,'school_calendar_terms'=>$configured]])->save();
            foreach ([$this->teacherClass(),$this->teacherClass().'/attendance?date=2026-10-11'] as $url) {
                $response=$this->getJson($url)->assertOk();
                $rows=collect($response->json('data.students'))->keyBy('membership_id');
                $this->assertSame('Peanuts',$rows[$this->member->id]['student_allergy']['allergies']);
                $this->assertArrayNotHasKey('student_allergy',$rows[$leader->id]);
                $this->assertArrayHasKey('student_allergy',$rows[$unflaggedMember->id]);
                $this->assertNull($rows[$unflaggedMember->id]['student_allergy']);
            }
            $this->putJson($this->teacherClass().'/attendance',['session_date'=>'2026-10-11','marks'=>[['membership_id'=>$this->member->id,'status'=>'present']]], ['X-Attendance-Revision'=>$response->headers->get('X-Attendance-Revision')])->assertOk()->assertJsonPath('data.students.0.student_allergy.allergies','Peanuts');
        }
        $this->class->forceFill(['kind'=>'general'])->save();
        foreach ([$this->teacherClass(),$this->teacherClass().'/attendance?date=2026-10-11'] as $url) {
            $rows=$this->getJson($url)->assertOk()->json('data.students');
            foreach ($rows as $row) $this->assertArrayNotHasKey('student_allergy',$row);
        }
    }

}
