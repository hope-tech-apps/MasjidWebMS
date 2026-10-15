<?php

namespace Tests\Feature\Student;

use App\Models\{Contact, Group, GroupMembership, Masjid, StudentAccount, StudentSignInLog, User};
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Auth, Hash, Route};
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

abstract class StudentTestCase extends TestCase
{
    use RefreshDatabase;

    protected Masjid $school;
    protected Contact $child;
    protected Group $class;
    protected GroupMembership $member;
    protected User $office;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Carbon\Carbon::parse('2026-10-15 16:00:00 UTC'));
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->school = Masjid::create(['name'=>'Practice School','email'=>'office@example.invalid','phone'=>'+15555550100','country_id'=>1,'city_id'=>1,'address'=>'Practice','latitude'=>0,'longitude'=>0,'org_type'=>'school','crm_enabled'=>true,'capability_overrides'=>['student_sign_in'=>true]]);
        $this->school->forceFill(['capability_overrides'=>['student_sign_in'=>true]])->save();
        $this->child = Contact::factory()->create(['masjid_id'=>$this->school->id,'first_name'=>'Practice','last_name'=>'Student','email'=>'child@example.invalid']);
        $this->class = Group::factory()->create(['masjid_id'=>$this->school->id,'kind'=>'class','name'=>'Practice Class']);
        $this->member = GroupMembership::create(['masjid_id'=>$this->school->id,'group_id'=>$this->class->id,'contact_id'=>$this->child->id,'role'=>'member','grade_label'=>'Grade 6']);
        $this->office = User::factory()->create(['type'=>'SuperAdmin','phone'=>'+15555550101','name'=>'Practice Office','email'=>'office@example.invalid']);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->forgetTenant();
        parent::tearDown();
    }

    protected function base(): string { return '/api/student/masjids/'.$this->school->id; }
    protected function officeBase(): string { return '/api/admin/masjids/'.$this->school->id.'/student-sign-in'; }
    protected function account(bool $change = false): StudentAccount
    {
        return StudentAccount::create(['masjid_id'=>$this->school->id,'contact_id'=>$this->child->id,'username'=>'practice6','password'=>Hash::make('PracticePass42'),'enabled'=>true,'must_change_password'=>$change]);
    }
    protected function bearer(string $token): static
    {
        Auth::forgetGuards(); app(TenantContext::class)->forgetTenant();
        return $this->withHeader('Authorization','Bearer '.$token);
    }
    protected function login(string $username = 'Practice6', string $password = 'PracticePass42')
    {
        Auth::forgetGuards(); app(TenantContext::class)->forgetTenant();
        return $this->withHeader('Authorization','')->postJson($this->base().'/auth/sign-in',compact('username','password'));
    }

}
