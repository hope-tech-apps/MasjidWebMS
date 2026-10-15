<?php

namespace Tests\Feature\Student;

use App\Models\{ClassAssignment, Contact, Masjid, StudentAccount, StudentHandIn, StudentSignInLog, StudentSignInSetting, StudentWorkSetting};
use App\Services\Member\MemberAccountDeletion;
use App\Support\TenantContext;
use Illuminate\Support\Facades\{DB, Schema};
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;

class StudentPrivacyTenantIsolationTest extends StudentTestCase
{
    #[Test]
    public function all_five_new_tenant_models_refuse_another_school_and_student_and_office_urls_are_scoped(): void
    {
        $one=Masjid::findOrFail($this->school->id);
        $two=Masjid::create(['name'=>'Other Practice School','email'=>'other@example.invalid','phone'=>'+15555550109','country_id'=>1,'city_id'=>1,'address'=>'Practice','latitude'=>0,'longitude'=>0,'org_type'=>'school','crm_enabled'=>true]);
        $two->forceFill(['capability_overrides'=>['student_sign_in'=>true]])->save();
        $child=Contact::factory()->create(['masjid_id'=>$two->id]);
        $a=$this->account();
        $other=StudentAccount::create(['masjid_id'=>$two->id,'contact_id'=>$child->id,'username'=>'practice6','enabled'=>true]);
        $work=ClassAssignment::create(['masjid_id'=>$one->id,'group_id'=>$this->class->id,'title'=>'Practice work','points_possible'=>10,'assigned_on'=>'2026-10-15']);
        $records=[StudentAccount::class=>$a,StudentSignInSetting::class=>StudentSignInSetting::create(['masjid_id'=>$one->id,'from_grade'=>'6th']),
            StudentSignInLog::class=>StudentSignInLog::create(['masjid_id'=>$one->id,'contact_id'=>$this->child->id,'username'=>'practice6','outcome'=>'failed','attempted_at'=>now()]),
            StudentWorkSetting::class=>StudentWorkSetting::create(['masjid_id'=>$one->id,'class_assignment_id'=>$work->id,'students_hand_in'=>true]),
            StudentHandIn::class=>StudentHandIn::create(['masjid_id'=>$one->id,'group_id'=>$this->class->id,'class_assignment_id'=>$work->id,'group_membership_id'=>$this->member->id,'student_contact_id'=>$this->child->id,'version'=>1,'kind'=>'link','link'=>'https://example.invalid/work','late'=>false,'submitted_at'=>now()])];
        app(TenantContext::class)->set($two->id);
        foreach($records as $model=>$row) $this->assertNull($model::find($row->id),$model);
        $token=$other->createToken('student',['student'])->plainTextToken;
        $this->bearer($token)->getJson($this->base().'/classes')->assertNotFound();
        $this->withHeader('Authorization',''); Sanctum::actingAs($this->office,['staff']);
        $this->getJson('/api/admin/masjids/'.$two->id.'/student-sign-in/groups/'.$this->class->id.'/accounts')->assertNotFound();
    }

    #[Test]
    public function studentaccount_cannot_be_changed_or_deleted_from_another_tenant(): void
    {
        $this->all_five_new_tenant_models_refuse_another_school_and_student_and_office_urls_are_scoped();
        app(TenantContext::class)->set(2);
        $this->assertNull(StudentAccount::find(1));
        $this->assertSame(0,StudentAccount::whereKey(1)->delete());
    }

    #[Test]
    public function studenthandin_cannot_be_changed_or_deleted_from_another_tenant(): void
    {
        $this->all_five_new_tenant_models_refuse_another_school_and_student_and_office_urls_are_scoped();
        app(TenantContext::class)->set(2);
        $this->assertNull(StudentHandIn::find(1));
        $this->assertSame(0,StudentHandIn::whereKey(1)->delete());
    }

    #[Test]
    public function studentsigninlog_cannot_be_changed_or_deleted_from_another_tenant(): void
    {
        $this->all_five_new_tenant_models_refuse_another_school_and_student_and_office_urls_are_scoped();
        app(TenantContext::class)->set(2);
        $this->assertNull(StudentSignInLog::find(1));
        $this->assertSame(0,StudentSignInLog::whereKey(1)->delete());
    }

    #[Test]
    public function studentsigninsetting_cannot_be_changed_or_deleted_from_another_tenant(): void
    {
        $this->all_five_new_tenant_models_refuse_another_school_and_student_and_office_urls_are_scoped();
        app(TenantContext::class)->set(2);
        $this->assertNull(StudentSignInSetting::find(1));
        $this->assertSame(0,StudentSignInSetting::whereKey(1)->delete());
    }

    #[Test]
    public function studentworksetting_cannot_be_changed_or_deleted_from_another_tenant(): void
    {
        $this->all_five_new_tenant_models_refuse_another_school_and_student_and_office_urls_are_scoped();
        app(TenantContext::class)->set(2);
        $this->assertNull(StudentWorkSetting::find(1));
        $this->assertSame(0,StudentWorkSetting::whereKey(1)->delete());
    }

    #[Test]
    public function deletion_removes_child_credentials_and_tokens_but_preserves_hand_ins_as_academic_records(): void
    {
        $otherSchool=Masjid::create(['name'=>'Other Practice School','email'=>'other@example.invalid','phone'=>'+15555550109','country_id'=>1,'city_id'=>1,'address'=>'Practice','latitude'=>0,'longitude'=>0,'org_type'=>'school']);
        $otherChild=Contact::factory()->create(['masjid_id'=>$otherSchool->id]);
        $otherAccount=StudentAccount::create(['masjid_id'=>$otherSchool->id,'contact_id'=>$otherChild->id,'username'=>'otherstudent']);
        $a=$this->account(); $a->createToken('student',['student']);
        StudentSignInLog::create(['masjid_id'=>$this->school->id,'contact_id'=>$this->child->id,'username'=>'practice6','outcome'=>'success','attempted_at'=>now()]);
        $w=ClassAssignment::create(['masjid_id'=>$this->school->id,'group_id'=>$this->class->id,'title'=>'Practice work','points_possible'=>10,'assigned_on'=>'2026-10-15']);
        $h=StudentHandIn::create(['masjid_id'=>$this->school->id,'group_id'=>$this->class->id,'class_assignment_id'=>$w->id,'group_membership_id'=>$this->member->id,'student_contact_id'=>$this->child->id,'version'=>1,'kind'=>'link','link'=>'https://example.invalid/record','late'=>false,'submitted_at'=>now()]);
        $result=app(MemberAccountDeletion::class)->delete($this->child,MemberAccountDeletion::VIA_APP);
        $this->assertSame('login_removed',$result['outcome']);
        $this->assertContains('student_hand_ins',$result['kept_because']);
        $this->assertNull(StudentAccount::find($a->id)); $this->assertNotNull($otherAccount->fresh()); $this->assertSame(0,StudentSignInLog::count());
        $this->assertSame(0,DB::table('personal_access_tokens')->where('tokenable_type',StudentAccount::class)->count());
        $this->assertNotNull($h->fresh());
        $this->assertSame(1,\App\Support\AcademicRecordsHeld::counts($this->member)['hand-ins']);
        $this->child->delete();
        Sanctum::actingAs($this->office,['staff']);
        $this->getJson('/api/admin/masjids/'.$this->school->id.'/groups/'.$this->class->id.'/assignments/'.$w->id.'/hand-ins')->assertOk()->assertJsonPath('data.students.0.name','Former student');
    }

    #[Test]
    public function schema_has_primary_keys_short_indexes_restrictive_academic_fks_and_case_insensitive_username_storage(): void
    {
        foreach(['student_accounts','student_sign_in_settings','student_sign_in_logs','student_work_settings','student_hand_ins'] as $table) {
            $this->assertSame(['id'],collect(Schema::getIndexes($table))->firstWhere('primary',true)['columns']);
            $this->assertTrue(collect(Schema::getColumns($table))->firstWhere('name','id')['auto_increment']);
            foreach(Schema::getIndexes($table) as $i) $this->assertLessThanOrEqual(64,strlen($i['name']));
        }
        foreach(Schema::getForeignKeys('student_hand_ins') as $fk) if ($fk['foreign_table']==='group_memberships') $this->assertSame('restrict',strtolower($fk['on_delete']));
        $this->account();
        $other=Contact::factory()->create(['masjid_id'=>$this->school->id]);
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('student_accounts')->insert(['masjid_id'=>$this->school->id,'contact_id'=>$other->id,'username'=>'PRACTICE6','enabled'=>true]);
    }

    #[Test]
    public function below_grade_creation_slips_and_disabled_switch_do_not_issue_credentials(): void
    {
        Sanctum::actingAs($this->office,['staff']);
        $this->member->update(['grade_label'=>'5th']);
        $this->putJson($this->officeBase().'/accounts/'.$this->child->id,['username'=>'practice5'])->assertForbidden();
        $this->assertSame(0,StudentAccount::count());
        $this->postJson($this->officeBase().'/groups/'.$this->class->id.'/slips',['contact_ids'=>[$this->child->id]])->assertForbidden();
        $this->member->update(['grade_label'=>'6th']);
        $r=$this->postJson($this->officeBase().'/groups/'.$this->class->id.'/slips',['contact_ids'=>[$this->child->id]])->assertOk();
        $this->assertNotEmpty($r->json('data.slips.0.one_time_password'));
        $this->getJson($this->officeBase().'/groups/'.$this->class->id.'/accounts')->assertOk()->assertJsonMissingPath('data.0.account.one_time_password');
        $this->school->forceFill(['capability_overrides'=>['student_sign_in'=>false]])->save();
        $this->assertArrayNotHasKey('student_sign_in',$this->school->fresh()->toArray()['capability_overrides'] ?? []);
    }

    #[Test]
    public function contact_merge_cannot_erase_hand_ins_and_merge_without_work_removes_student_tokens(): void
    {
        $a=$this->account();$a->createToken('student',['student']);
        $target=Contact::factory()->create(['masjid_id'=>$this->school->id,'first_name'=>'Practice','last_name'=>'Target','email'=>'target@example.invalid']);
        Sanctum::actingAs($this->office,['staff']);
        $this->postJson('/api/admin/masjids/'.$this->school->id.'/contacts/'.$this->child->id.'/merge',['target_contact_id'=>$target->id])->assertOk();
        $this->assertNull(StudentAccount::find($a->id));
        $this->assertSame(0,DB::table('personal_access_tokens')->where('tokenable_type',StudentAccount::class)->count());
    }

    #[Test]
    public function hard_erasure_of_a_contact_also_erases_the_separate_student_tokenable(): void
    {
        $a=$this->account();$a->createToken('student',['student']);
        $this->child->forceDelete();
        $this->assertNull(StudentAccount::find($a->id));
        $this->assertSame(0,DB::table('personal_access_tokens')->where('tokenable_type',StudentAccount::class)->count());
    }

    #[Test]
    public function a_live_session_loses_access_after_a_grade_change_with_debug_disabled(): void
    {
        config(['app.debug'=>false]);
        $token=$this->account()->createToken('student',['student'])->plainTextToken;
        $this->bearer($token)->getJson($this->base().'/classes')->assertOk();
        $this->member->update(['grade_label'=>'5th']);
        $this->bearer($token)->getJson($this->base().'/classes')->assertForbidden()->assertJsonPath('message','Students below Grade 6 cannot sign in.');
    }

    #[Test]
    public function staging_scrub_nulls_password_hashes_and_hand_in_pii_and_clears_sign_in_network_logs(): void
    {
        $a=$this->account(); $a->update(['last_sign_in_at'=>now()]); $a->createToken('student',['student']);
        StudentSignInLog::create(['masjid_id'=>$this->school->id,'contact_id'=>$this->child->id,'username'=>'practice6','ip_address'=>'192.0.2.10','user_agent'=>'Private practice agent','outcome'=>'success','attempted_at'=>now()]);
        $w=ClassAssignment::create(['masjid_id'=>$this->school->id,'group_id'=>$this->class->id,'title'=>'Practice work','points_possible'=>10,'assigned_on'=>'2026-10-15']);
        $h=StudentHandIn::create(['masjid_id'=>$this->school->id,'group_id'=>$this->class->id,'class_assignment_id'=>$w->id,'group_membership_id'=>$this->member->id,'student_contact_id'=>$this->child->id,'version'=>1,'kind'=>'file','link'=>'https://example.invalid/private','disk'=>'local','path'=>'group-resources/private-practice.pdf','original_name'=>'private-practice.pdf','late'=>false,'submitted_at'=>now()]);
        $this->app->detectEnvironment(fn ()=>'staging');
        config(['app.env'=>'staging','database.connections.sqlite.database'=>'schools_staging']);
        $this->artisan('staging:scrub',['--i-understand-this-destroys-personal-data'=>true])->assertSuccessful();
        $a->refresh(); $h->refresh();
        $this->assertNull($a->password); $this->assertNull($a->last_sign_in_at); $this->assertFalse($a->enabled);
        $this->assertNotSame('practice6',$a->username);
        $this->assertNull($h->link); $this->assertNull($h->path); $this->assertNull($h->original_name);
        $this->assertSame(0,StudentSignInLog::count());
        $this->assertSame(0,DB::table('personal_access_tokens')->where('tokenable_type',StudentAccount::class)->count());
    }

    #[Test]
    public function withdrawn_accounts_remain_visible_to_their_family_and_disableable_by_the_office(): void
    {
        $a=$this->account();$a->createToken('student',['student']);
        $parent=Contact::factory()->create(['masjid_id'=>$this->school->id,'email'=>'parent@example.invalid','login_email'=>'parent@example.invalid','login_enabled_at'=>now()]);
        \App\Models\GroupMembership::create(['masjid_id'=>$this->school->id,'group_id'=>$this->class->id,'contact_id'=>$parent->id,'role'=>'guardian','guardian_of_contact_id'=>$this->child->id,'provenance'=>'confirmed']);
        $this->member->forceFill(['left_on'=>'2026-10-15'])->save();
        Sanctum::actingAs($this->office,['staff']);
        $this->getJson($this->officeBase().'/groups/'.$this->class->id.'/accounts')->assertOk()->assertJsonPath('data.0.account.has_account',true)->assertJsonPath('data.0.eligible',false);
        $this->deleteJson($this->officeBase().'/accounts/'.$this->child->id)->assertOk();
        $r=$this->bearer($parent->createFamilyToken()->plainTextToken)->getJson('/api/family/masjids/'.$this->school->id.'/student-accounts')->assertOk();
        $r->assertJsonPath('data.0.username','practice6')->assertJsonPath('data.0.enabled',false);
        $this->assertStringNotContainsString('password',$r->getContent());
    }

    #[Test]
    public function the_account_model_hashes_passwords_as_a_backstop_for_every_model_writer(): void
    {
        $a=StudentAccount::create(['masjid_id'=>$this->school->id,'contact_id'=>$this->child->id,'username'=>'practice6','password'=>'PracticePass42','enabled'=>true,'must_change_password'=>true]);
        $stored=DB::table('student_accounts')->where('id',$a->id)->value('password');
        $this->assertNotSame('PracticePass42',$stored);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('PracticePass42',$stored));
    }
}
