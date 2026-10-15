<?php

namespace Tests\Feature\Student;

use App\Models\{Contact, Group, GroupMembership, Masjid, StudentAccount, StudentSignInLog, User};
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Auth, Hash, Route};
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StudentSignInTest extends StudentTestCase
{
    #[Test]
    public function a_real_student_token_is_refused_on_every_registered_route_outside_its_realm(): void
    {
        $token=$this->account()->createToken('student',['student'])->plainTextToken;
        $this->bearer($token)->getJson($this->base().'/me')->assertOk();
        $count=0;
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(),'api/student/')) continue;
            $uri=preg_replace('/\{[^}]+\}/','1',$route->uri());
            foreach ($route->methods() as $method) {
                $response=$this->bearer($token)->json($method,'/'.$uri);
                $this->assertSame(403,$response->getStatusCode(),$method.' '.$uri);
                if ($method !== 'HEAD') $this->assertSame('Student sessions can only use the student portal.',$response->json('message'));
                $count++;
            }
        }
        $this->assertGreaterThan(500,$count);
    }

    #[Test]
    public function office_issues_hash_only_and_usernames_are_case_insensitively_unique_per_school(): void
    {
        Sanctum::actingAs($this->office,['staff']);
        $url=$this->officeBase().'/accounts/'.$this->child->id;
        $r=$this->putJson($url,['username'=>'Practice6'])->assertOk();
        $password=$r->json('data.one_time_password');
        $this->assertTrue(Hash::check($password,StudentAccount::first()->password));
        $this->assertSame('practice6',StudentAccount::first()->username);
        $this->assertStringNotContainsString($password,StudentAccount::first()->toJson());
        $other=Contact::factory()->create(['masjid_id'=>$this->school->id]);
        GroupMembership::create(['masjid_id'=>$this->school->id,'group_id'=>$this->class->id,'contact_id'=>$other->id,'role'=>'member','grade_label'=>'6th']);
        $this->putJson($this->officeBase().'/accounts/'.$other->id,['username'=>'PRACTICE6'])->assertStatus(422);
        app(TenantContext::class)->forgetTenant();
        $another=Masjid::create(array_merge($this->school->getAttributes(), ['id'=>null,'email'=>'other@example.invalid','phone'=>'+15555550109','name'=>'Other Practice School']));
        $c=Contact::factory()->create(['masjid_id'=>$another->id]);
        $a=StudentAccount::create(['masjid_id'=>$another->id,'contact_id'=>$c->id,'username'=>'PRACTICE6','password'=>Hash::make('PracticePass42')]);
        $this->assertSame('practice6',$a->username);
    }

    #[Test]
    public function first_sign_in_requires_a_strong_new_password_and_old_tokens_stop_immediately(): void
    {
        $account=$this->account(true);
        $r=$this->login()->assertOk(); $token=$r->json('data.token');
        $this->assertTrue($r->json('data.must_change_password'));
        $this->bearer($token)->getJson($this->base().'/classes')->assertStatus(403);
        $this->bearer($token)->putJson($this->base().'/password',['current_password'=>'PracticePass42','password'=>'short','password_confirmation'=>'short'])->assertStatus(422);
        $r=$this->bearer($token)->putJson($this->base().'/password',['current_password'=>'PracticePass42','password'=>'NewPractice43','password_confirmation'=>'NewPractice43'])->assertOk();
        $this->bearer($token)->getJson($this->base().'/me')->assertUnauthorized();
        $this->bearer($r->json('data.token'))->getJson($this->base().'/classes')->assertOk();
        $this->assertFalse($account->fresh()->must_change_password);
        $this->login('practice6','PracticePass42')->assertUnauthorized();
        $this->login('practice6','NewPractice43')->assertOk();
    }

    #[Test]
    public function reset_disable_switch_and_grade_are_enforced_on_live_tokens_and_sign_in(): void
    {
        $account=$this->account(); $old=$this->login()->assertOk()->json('data.token');
        $this->withHeader('Authorization',''); Sanctum::actingAs($this->office,['staff']);
        $password=$this->putJson($this->officeBase().'/accounts/'.$this->child->id,['username'=>'practice6'])->assertOk()->json('data.one_time_password');
        $this->bearer($old)->getJson($this->base().'/me')->assertUnauthorized();
        $this->login('practice6','PracticePass42')->assertUnauthorized();
        $new=$this->login('practice6',$password)->assertOk()->json('data.token');
        $this->withHeader('Authorization',''); Sanctum::actingAs($this->office,['staff']);
        $this->deleteJson($this->officeBase().'/accounts/'.$this->child->id)->assertOk();
        $this->bearer($new)->getJson($this->base().'/me')->assertUnauthorized();
        $this->login('practice6',$password)->assertUnauthorized();
        $account->refresh()->forceFill(['enabled'=>true,'must_change_password'=>false])->save();
        $this->member->update(['grade_label'=>'5th']);
        $this->login('practice6',$password)->assertForbidden()->assertJsonPath('message','Students below Grade 6 cannot sign in.');
        $this->member->update(['grade_label'=>'6th']);
        $this->travel(16)->minutes();
        $token=$this->login('practice6',$password)->assertOk()->json('data.token');
        $this->school->forceFill(['capability_overrides'=>['student_sign_in'=>false]])->save();
        $this->bearer($token)->getJson($this->base().'/me')->assertNotFound();
        $this->login('practice6',$password)->assertNotFound();
        Sanctum::actingAs($this->office,['staff']);
        $this->putJson($this->officeBase().'/accounts/'.$this->child->id,['username'=>'practice6'])->assertNotFound();
    }

    /** Walk finding: successes counted toward the limits, so a lab on one address and a child signing in often were locked out. */
    #[Test]
    public function only_failed_attempts_count_toward_the_sign_in_limits(): void
    {
        $this->account();
        for($i=0;$i<8;$i++) $this->login()->assertOk();              // signing in again and again never locks
        $this->login('practice6','WRONG_SECRET')->assertUnauthorized();
        $this->login()->assertOk();                                 // a success after a typo still works
        for($i=0;$i<60;$i++) $this->login()->assertOk();             // more than the per-address limit of successes
        for($i=0;$i<4;$i++) $this->login('practice6','WRONG_SECRET')->assertUnauthorized();
        $this->login()->assertOk();                                 // four typos then the right password: allowed, and the count clears
        for($i=0;$i<5;$i++) $this->login('practice6','WRONG_SECRET')->assertUnauthorized();
        $this->login()->assertStatus(429);                          // five failures in a row lock as before
    }

    #[Test]
    public function limits_each_username_and_ip_logs_every_attempt_without_password_and_prunes_after_ninety_days(): void
    {
        $this->account();
        for($i=0;$i<5;$i++) $this->login('practice6','WRONG_SECRET')->assertUnauthorized();
        $this->login()->assertStatus(429);
        $this->assertSame(6,StudentSignInLog::count());
        $this->assertStringNotContainsString('WRONG_SECRET',StudentSignInLog::all()->toJson());
        $this->travel(16)->minutes(); $this->login()->assertOk();
        for($i=0;$i<50;$i++) $this->login('unknown'.$i,'wrong')->assertUnauthorized();
        $this->login('another','wrong')->assertStatus(429);
        StudentSignInLog::query()->first()->update(['attempted_at'=>now()->subDays(91)]);
        $count=StudentSignInLog::count();
        $this->artisan('students:prune-sign-ins')->assertSuccessful();
        $this->assertSame($count-1,StudentSignInLog::count());
    }

    #[Test]
    public function idle_absolute_expiry_signout_and_wrong_realm_are_enforced(): void
    {
        $account=$this->account();
        $token=$this->login()->assertOk()->json('data.token');
        $this->travel(16)->minutes();
        $this->bearer($token)->getJson($this->base().'/me')->assertUnauthorized();
        $token=$this->login()->assertOk()->json('data.token');
        for($i=0;$i<11;$i++){ $this->travel(10)->minutes(); $this->bearer($token)->getJson($this->base().'/me')->assertOk(); }
        $this->travel(11)->minutes(); $this->bearer($token)->getJson($this->base().'/me')->assertUnauthorized();
        $token=$this->login()->assertOk()->json('data.token');
        $this->bearer($token)->postJson($this->base().'/sign-out')->assertOk();
        $this->bearer($token)->getJson($this->base().'/me')->assertUnauthorized();
        foreach([$this->office->createToken('staff',['staff'])->plainTextToken,$this->child->createToken('family',['family'])->plainTextToken] as $wrong) $this->bearer($wrong)->getJson($this->base().'/me')->assertUnauthorized();
        $this->actingAs($this->office,'web')->getJson($this->base().'/me')->assertUnauthorized();
    }

    #[Test]
    public function malformed_attempts_are_logged_and_partial_tokens_cannot_reuse_or_choose_weak_passwords(): void
    {
        $a=$this->account(true);
        foreach([[],['username'=>['invalid'],'password'=>'secret'],['username'=>'bad username','password'=>'secret']] as $body) {
            $this->postJson($this->base().'/auth/sign-in',$body)->assertUnauthorized();
        }
        $this->assertSame(3,StudentSignInLog::count());
        $token=$this->login()->assertOk()->json('data.token');
        foreach(['aaaaaaaaaa','1234567890','PracticePass42'] as $password) {
            $r=$this->bearer($token)->putJson($this->base().'/password',['current_password'=>'PracticePass42','password'=>$password,'password_confirmation'=>$password]);
            $this->assertSame(422,$r->getStatusCode());
        }
        $secret=explode('|',$token,2)[1];
        $this->bearer($secret)->getJson('/up')->assertForbidden();
        $this->school->forceFill(['capability_overrides'=>['student_sign_in'=>false]])->save();
        foreach(\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            if (!str_starts_with($route->uri(),'api/student/')) continue;
            $uri=preg_replace('/\\{[^}]+\\}/','1',$route->uri());
            foreach(array_diff($route->methods(),['HEAD']) as $method) $this->bearer($token)->json($method,'/'.$uri)->assertNotFound();
        }
    }
}
