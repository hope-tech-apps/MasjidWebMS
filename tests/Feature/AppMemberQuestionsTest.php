<?php

namespace Tests\Feature;

use App\Models\{Contact, Group, GroupMembership, Masjid, MemberQuestionOption, MemberQuestionAnswer, MemberQuestionHelp, AppVolunteerSetting, User, Service};
use App\Services\Member\MemberAccountDeletion;
use App\Support\{CapabilityWriter, TenantContext, MobileCache};
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache, DB, Storage};
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AppMemberQuestionsTest extends TestCase
{
    use RefreshDatabase;
    private Masjid $org;
    private Contact $member;
    private User $admin;
    protected function setUp(): void
    {
        parent::setUp(); $this->travelTo(\Carbon\Carbon::parse('2026-10-13 12:00:00 UTC')); Storage::fake('public'); Cache::flush();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create(['phone'=>'+15555550101','name'=>'Practice Admin','type'=>'MasjidAdmin']);
        $this->org = $this->makeMasjid('one', $this->admin->id);
        $this->member = $this->contact($this->org);
    }
    private function makeMasjid(string $name, ?int $owner = null): Masjid
    {
        return Masjid::create(['name'=>'Practice '.$name,'email'=>$name.'@example.invalid','phone'=>'+1555555'.str_pad((string)(Masjid::withTrashed()->count()+100),4,'0',STR_PAD_LEFT),'address'=>'Practice','latitude'=>0,'longitude'=>0,'country_id'=>1,'city_id'=>1,'crm_enabled'=>true,'user_id'=>$owner]);
    }
    private function contact(Masjid $org): Contact
    {
        app(TenantContext::class)->forgetTenant();
        $contact = Contact::withoutMasjidScope()->create(['masjid_id'=>$org->id,'first_name'=>'Practice','last_name'=>'Member','email'=>'member@example.invalid','login_email'=>'member@example.invalid','verified_at'=>now(),'signup_source'=>'app']);
        $contact->forceFill(['email'=>'member-'.$contact->id.'@example.invalid','login_email'=>'member-'.$contact->id.'@example.invalid','verified_at'=>now(),'signup_source'=>'app'])->save();
        return $contact;
    }
    private function enable(string $key = 'app_member_questions'): void
    {
        app(TenantContext::class)->forgetTenant(); CapabilityWriter::apply($this->org, [$key=>true], null); $this->org->refresh();
    }
    private function mobile(string $method = 'get', array $data = [], ?int $path = null)
    {
        auth()->forgetGuards(); app(TenantContext::class)->forgetTenant();
        $token = $this->member->createMemberToken()->plainTextToken;
        return $this->{$method.'Json'}('/api/mobile/masjids/'.($path ?? $this->org->id).'/member-questions', $method === 'get' ? ['Authorization'=>'Bearer '.$token] : $data, ...($method === 'get' ? [] : [['Authorization'=>'Bearer '.$token]]));
    }
    private function office(User $user = null): void { auth()->forgetGuards(); app(TenantContext::class)->forgetTenant(); Sanctum::actingAs($user ?? $this->admin); }
    private function answers(): array
    {
        return ['occupation_id'=>MemberQuestionOption::where('question','occupation')->where('is_other',true)->value('id'), 'occupation_text'=>'Independent maker', 'help_ids'=>[MemberQuestionOption::where('question','help')->where('is_other',true)->value('id')], 'help_text'=>'Can translate'];
    }
    #[Test]
    public function dark_grants_hide_everywhere_and_new_routes_404(): void
    {
        foreach (Masjid::ORG_TYPES as $type) {
            $this->org->forceFill(['org_type'=>$type])->save();
            foreach (['app_member_questions','app_volunteer_entry'] as $key) {
                $this->assertFalse($this->org->hasCapability($key)); $this->assertArrayNotHasKey($key, $this->org->capabilities);
            }
        }
        $this->mobile()->assertNotFound(); $this->mobile('put', [])->assertNotFound();
        $this->office();
        $this->getJson('/api/admin/masjids/'.$this->org->id.'/contacts/'.$this->member->id.'/from-app')->assertNotFound();
        $this->putJson('/api/admin/masjids/'.$this->org->id.'/app-volunteer', ['title'=>'Volunteer','url'=>'https://example.invalid/form'])->assertNotFound();
    }
    #[Test]
    public function off_switch_panel_omits_both_new_grants_and_all_new_routes_stay_dark_without_crm(): void
    {
        $this->office(User::factory()->create(['phone'=>'+15555550131','type'=>'SuperAdmin']));
        $panel=$this->getJson('/api/admin/masjids/'.$this->org->id.'/capabilities')->assertOk();
        $this->assertStringNotContainsString('app_member_questions',$panel->getContent());
        $this->assertStringNotContainsString('app_volunteer_entry',$panel->getContent());
        $this->org->forceFill(['crm_enabled'=>false])->save();
        $this->mobile()->assertNotFound();$this->mobile('put',[])->assertNotFound();
    }
    #[Test]
    public function off_directory_ignores_new_filter_parameters_with_identical_body_and_sql(): void
    {
        $this->office();$url='/api/admin/masjids/'.$this->org->id.'/contacts';$this->getJson($url)->assertOk();
        DB::enableQueryLog();DB::flushQueryLog();$base=$this->getJson($url)->assertOk()->getContent();$sql=array_column(DB::getQueryLog(),'query');DB::flushQueryLog();
        $this->assertSame($base,$this->getJson($url.'?occupation_id=999&help_id=999&interest_id=999')->assertOk()->getContent());
        $this->assertSame($sql,array_column(DB::getQueryLog(),'query'));DB::disableQueryLog();
    }
    #[Test]
    public function off_merge_reads_retained_answers_even_with_a_stale_organisation_snapshot(): void
    {
        $this->enable();$target=$this->contact($this->org);$stale=$this->org->fresh();
        $stale->forceFill(['capability_overrides'=>[]]);
        request()->attributes->set(\App\Support\ClassSubjectMode::HTTP,true);
        request()->attributes->set('class_subjects_rows',[$this->org->id=>$stale]);
        $answer=MemberQuestionAnswer::create(['masjid_id'=>$this->org->id,'contact_id'=>$this->member->id,'occupation_id'=>MemberQuestionOption::where('question','occupation')->value('id')]);
        CapabilityWriter::apply($this->org,['app_member_questions'=>false],null);
        \App\Support\MemberQuestions::merge($this->member,$target);
        $this->assertSame($target->id,$answer->fresh()->contact_id);
    }
    #[Test]
    public function answer_and_tab_writes_leave_the_capability_record_unchanged(): void
    {
        $this->enable();$this->enable('app_bottom_tabs');$overrides=$this->org->fresh()->capability_overrides;
        $this->mobile('put',$this->answers())->assertOk();
        $this->assertSame($overrides,$this->org->fresh()->capability_overrides);
        $this->office();$this->putJson('/api/admin/masjids/'.$this->org->id.'/app-bottom-tabs',['feature_ids'=>[]])->assertOk();
        $this->assertSame($overrides,$this->org->fresh()->capability_overrides);
        $this->assertSame($overrides,$this->org->fresh()->attributesToArray()['capability_overrides']);
    }

    #[Test]
    public function revocation_password_removal_and_office_soft_delete_keep_answers(): void
    {
        $this->enable();$data=$this->answers();$this->mobile('put',$data)->assertOk();
        $this->member->forceFill(['login_enabled_at'=>now(),'password'=>\Illuminate\Support\Facades\Hash::make('Practice password'),'password_set_at'=>now()])->save();
        app(\App\Services\Family\FamilyPasswordService::class)->clear($this->member);
        $this->assertNull($this->member->fresh()->password);
        $this->assertSame($data,\App\Support\MemberQuestions::answers($this->member));
        app(\App\Services\Family\FamilyAccessService::class)->revoke($this->member);
        $this->assertFalse($this->member->fresh()->memberAccessIsActive());
        $this->assertSame($data,\App\Support\MemberQuestions::answers($this->member));
        $this->office();$root='/api/admin/masjids/'.$this->org->id.'/contacts/'.$this->member->id;
        $this->getJson($root.'/from-app')->assertOk()->assertJsonPath('data.help_text',$data['help_text']);
        $this->deleteJson($root)->assertOk();$this->postJson($root.'/restore')->assertOk();
        $this->assertSame($data,\App\Support\MemberQuestions::answers($this->member));
    }

    private function guardian(Contact $contact): void
    {
        app(TenantContext::class)->set($this->org->id);
        $ward=Contact::factory()->create(['masjid_id'=>$this->org->id]);
        $group=Group::factory()->create(['masjid_id'=>$this->org->id,'kind'=>Group::KIND_CLASS]);
        GroupMembership::create(['group_id'=>$group->id,'contact_id'=>$ward->id,'role'=>'member']);
        GroupMembership::create(['group_id'=>$group->id,'contact_id'=>$contact->id,'role'=>'guardian','guardian_of_contact_id'=>$ward->id]);
    }

    #[Test]
    public function readdressing_and_releasing_a_login_address_keep_each_contacts_answers(): void
    {
        $this->enable();$data=$this->answers();$this->mobile('put',$data)->assertOk();$this->guardian($this->member);
        $access=app(\App\Services\Family\FamilyAccessService::class);
        $access->enable($this->member,'changed@example.invalid');
        $this->assertNull($this->member->fresh()->verified_at);
        $this->assertSame($data,\App\Support\MemberQuestions::answers($this->member));
        $access->revoke($this->member);
        $claimant=$this->contact($this->org);$this->guardian($claimant);
        $access->enable($claimant,'changed@example.invalid',reassignAddress:true);
        $this->assertNull($this->member->fresh()->login_email);
        $this->assertNull($this->member->fresh()->password);
        $this->assertSame($data,\App\Support\MemberQuestions::answers($this->member));
    }

    #[Test]
    public function permanent_contact_deletion_cascades_answers_and_selections_while_disclosure_is_off(): void
    {
        $this->enable();$this->mobile('put',$this->answers())->assertOk();
        CapabilityWriter::apply($this->org,['app_member_questions'=>false],null);
        $this->member->forceDelete();
        $this->assertSame(0,MemberQuestionAnswer::withoutMasjidScope()->count());
        $this->assertSame(0,MemberQuestionHelp::withoutMasjidScope()->count());
    }

    #[Test]
    public function account_deletion_clears_answers_with_disclosure_off_and_no_credentials_to_clear(): void
    {
        $this->enable();$this->mobile('put',$this->answers())->assertOk();
        $this->member->forceFill(['notes'=>'Office record','verified_at'=>null,'login_enabled_at'=>null,'password'=>null])->save();
        $this->assertSame(1,MemberQuestionAnswer::withoutMasjidScope()->count());
        CapabilityWriter::apply($this->org,['app_member_questions'=>false],null);
        $result=app(MemberAccountDeletion::class)->delete($this->member,'app');
        $this->assertSame('login_removed',$result['outcome']);
        $this->assertSame(0,MemberQuestionAnswer::withoutMasjidScope()->count());
        $this->assertSame(0,MemberQuestionHelp::withoutMasjidScope()->count());
    }

    #[Test]
    public function account_deletion_clears_answers_even_when_a_separate_family_login_is_kept(): void
    {
        $this->enable();$this->mobile('put',$this->answers())->assertOk();
        $this->member->forceFill(['login_enabled_at'=>now(),'email'=>'household@example.invalid'])->save();
        $result=app(MemberAccountDeletion::class)->delete($this->member,'web',null,'household@example.invalid');
        $this->assertSame('login_removed',$result['outcome']);$this->assertTrue($result['family_login_kept']);
        $this->assertNotNull($this->member->fresh()->login_enabled_at);
        $this->assertSame(0,MemberQuestionAnswer::withoutMasjidScope()->count());
        $this->assertSame(0,MemberQuestionHelp::withoutMasjidScope()->count());
    }

    #[Test]
    public function switching_on_together_with_the_calendar_switch_still_seeds_the_lists(): void
    {
        // A capabilities request that also names school_calendar_terms is handled by the calendar writer.
        \App\Support\CapabilityWriter::apply($this->org->fresh(), ['app_member_questions' => false], null);
        \Illuminate\Support\Facades\DB::table('member_question_options')->where('masjid_id', $this->org->id)->delete();
        \App\Support\CapabilityWriter::apply($this->org->fresh(), ['school_calendar_terms' => false, 'app_member_questions' => true], null);
        $this->assertTrue($this->org->fresh()->hasCapability('app_member_questions'));
        $this->assertGreaterThan(0, \Illuminate\Support\Facades\DB::table('member_question_options')->where('masjid_id', $this->org->id)->count());
    }

    #[Test]
    public function defaults_seed_once_and_super_only_can_rename_reorder_and_deactivate(): void
    {
        $this->enable(); $this->assertSame(13, MemberQuestionOption::where('question','occupation')->count());
        $this->assertSame(8, MemberQuestionOption::where('question','help')->count());
        $occupation = MemberQuestionOption::where('question','occupation')->orderBy('position')->get()->toArray();
        $occupation[0]['label'] = 'Learning'; $occupation[0]['active'] = false;
        $occupation = array_reverse($occupation);
        $body = ['occupation'=>$occupation, 'help'=>MemberQuestionOption::where('question','help')->orderBy('position')->get()->toArray()];
        $url = '/api/admin/masjids/'.$this->org->id.'/member-question-options';
        $this->office(); $this->putJson($url,$body)->assertForbidden();
        $this->office(User::factory()->create(['phone'=>'+15555550101','name'=>'Practice Admin','type'=>'SuperAdmin'])); $this->putJson($url,$body)->assertOk();
        $this->assertFalse(MemberQuestionOption::find($occupation[count($occupation) - 1]['id'])->active);
        CapabilityWriter::apply($this->org,['app_member_questions'=>false],null); $this->enable();
        $this->assertSame(18,MemberQuestionOption::count()); $this->assertSame('Learning',MemberQuestionOption::find($occupation[9]['id'])->label);
    }
    #[Test]
    public function member_replace_clear_validation_encryption_and_own_only(): void
    {
        $this->enable(); $data=$this->answers();
        $this->mobile('put',$data)->assertOk()->assertJsonPath('data.answers.occupation_text','Independent maker');
        $raw=DB::table('member_question_answers')->first();
        $this->assertNotSame('Independent maker',$raw->occupation_text); $this->assertNotSame('Can translate',$raw->help_text);
        $this->assertSame('text',\Illuminate\Support\Facades\Schema::getColumnType('member_question_answers','occupation_text'));
        $this->mobile()->assertOk()->assertJsonPath('data.answers.help_ids',$data['help_ids']);
        $other=$this->makeMasjid('two'); $otherMember=$this->contact($other);
        $this->mobile('put',$data,$other->id)->assertForbidden();
        foreach ([999999, MemberQuestionOption::where('question','help')->value('id')] as $bad) {
            $this->mobile('put',['occupation_id'=>$bad])->assertUnprocessable();
        }
        $option=MemberQuestionOption::find($data['occupation_id']); $option->update(['active'=>false]);
        $this->mobile('put',$data)->assertUnprocessable();
        $this->mobile('put',['occupation_id'=>MemberQuestionOption::where('question','occupation')->where('is_other',false)->value('id'),'occupation_text'=>'Not Other'])->assertUnprocessable();
        $this->mobile('put',array_replace($data,['occupation_text'=>str_repeat('a',121)]))->assertUnprocessable();
        $this->mobile('put',[])->assertOk()->assertJsonPath('data.answers.occupation_id',null);
        $this->assertSame(0,MemberQuestionAnswer::count());
        $this->member=$otherMember; $this->mobile('get',[],$other->id)->assertNotFound();
    }
    #[Test]
    public function students_children_family_and_staff_cannot_write_answers(): void
    {
        $this->enable();
        $class=Group::create(['masjid_id'=>$this->org->id,'name'=>'Practice class','slug'=>'practice','kind'=>Group::KIND_CLASS]);
        GroupMembership::create(['masjid_id'=>$this->org->id,'group_id'=>$class->id,'contact_id'=>$this->member->id,'role'=>'member']);
        $this->mobile('put',$this->answers())->assertForbidden();
        $this->assertSame(0,MemberQuestionAnswer::count());
        $this->office(); $this->putJson('/api/mobile/masjids/'.$this->org->id.'/member-questions',$this->answers())->assertUnauthorized();
    }
    #[Test]
    public function a_guardians_ward_outside_a_class_is_not_offered_questions_or_allowed_to_store_answers(): void
    {
        $this->enable();$parent=$this->contact($this->org);
        $group=Group::create(['masjid_id'=>$this->org->id,'name'=>'Practice circle','slug'=>'practice-circle','kind'=>'halaqa']);
        GroupMembership::create(['masjid_id'=>$this->org->id,'group_id'=>$group->id,'contact_id'=>$this->member->id,'role'=>'member']);
        GroupMembership::create(['masjid_id'=>$this->org->id,'group_id'=>$group->id,'contact_id'=>$parent->id,'role'=>'guardian','guardian_of_contact_id'=>$this->member->id]);
        $this->mobile()->assertForbidden();$this->mobile('put',$this->answers())->assertForbidden();
        $this->assertSame(0,MemberQuestionAnswer::count());
    }
    #[Test]
    public function volunteer_tab_uses_the_link_title_snapshot_when_a_rename_races_the_response(): void
    {
        $this->enable('app_volunteer_entry');$this->enable('app_bottom_tabs');$this->office();
        $this->putJson('/api/admin/masjids/'.$this->org->id.'/app-volunteer',['title'=>'Volunteer','url'=>'https://example.invalid/form'])->assertOk();
        $this->putJson('/api/admin/masjids/'.$this->org->id.'/app-bottom-tabs',['feature_ids'=>['volunteer']])->assertOk();
        $renamed=false;DB::listen(function($query)use(&$renamed){
            if(!$renamed && str_contains($query->sql,'select * from "app_volunteer_settings"')){$renamed=true;DB::table('app_volunteer_settings')->where('masjid_id',$this->org->id)->update(['title'=>'New title']);}
        });
        auth()->forgetGuards();app(TenantContext::class)->forgetTenant();
        $r=$this->getJson('/api/mobile/masjids/'.$this->org->id)->assertOk();
        $this->assertSame($r->json('data.volunteer.title'),$r->json('data.bottom_tabs.0.name'));
    }
    #[Test]
    public function known_minor_is_refused_before_the_eighteenth_birthday_in_the_organisation_timezone(): void
    {
        $this->enable();$this->org->forceFill(['timezone'=>'America/New_York'])->save();
        $this->travelTo(\Carbon\Carbon::parse('2026-10-13 00:30:00 UTC'));
        $this->member->forceFill(['date_of_birth'=>'2008-10-13'])->save();
        $this->mobile()->assertForbidden();$this->mobile('put',$this->answers())->assertForbidden();
        $this->assertSame(0,MemberQuestionAnswer::count());
        $this->travelTo(\Carbon\Carbon::parse('2026-10-13 12:00:00 UTC'));
        $this->mobile('put',$this->answers())->assertOk();
    }
    #[Test]
    public function public_carriers_are_only_additions_with_pinned_on_and_off_queries(): void
    {
        $urls=['/api/mobile/masjids/'.$this->org->id,'/api/v1/settings']; $base=[];
        foreach ($urls as $url) { Cache::flush(); DB::enableQueryLog(); DB::flushQueryLog(); $r=$this->getJson($url,['masjid-id'=>(string)$this->org->id])->assertOk(); $base[$url]=[$r->json(),array_column(DB::getQueryLog(),'query')]; DB::disableQueryLog(); }
        $this->enable();
        foreach ($urls as $url) {
            Cache::flush(); DB::enableQueryLog(); DB::flushQueryLog(); $r=$this->getJson($url,['masjid-id'=>(string)$this->org->id])->assertOk(); $sql=array_column(DB::getQueryLog(),'query'); DB::disableQueryLog();
            $data=$r->json(); $this->assertTrue($data['data']['onboarding']['questions']); $this->assertCount(13,$data['data']['onboarding']['occupation']); unset($data['data']['onboarding']);
            $this->assertSame(json_encode($base[$url][0]),json_encode($data));
            $this->assertCount(1,array_filter($sql,fn($q)=>str_contains($q,'member_question_options')));
            $this->assertSame($base[$url][1],array_values(array_filter($sql,fn($q)=>!str_contains($q,'member_question_options'))));
        }
    }
    #[Test]
    public function office_separate_read_and_three_filters_keep_directory_answers_private_and_queries_fixed(): void
    {
        $this->enable(); $data=$this->answers(); $this->mobile('put',$data)->assertOk();
        $service=Service::create(['masjid_id'=>$this->org->id,'title'=>'Practice service','description'=>'Practice','text'=>'Practice','summary'=>'Practice']);
        \App\Models\ContactServiceInterest::create(['masjid_id'=>$this->org->id,'contact_id'=>$this->member->id,'service_id'=>$service->id]);
        $this->office(); $root='/api/admin/masjids/'.$this->org->id.'/contacts';
        $this->getJson($root.'/'.$this->member->id.'/from-app')->assertOk()->assertJsonPath('data.occupation.label','Other')->assertJsonPath('data.interests.0.title','Practice service');
        foreach (['occupation_id'=>$data['occupation_id'],'help_id'=>$data['help_ids'][0],'interest_id'=>$service->id] as $filter=>$value) {
            $r=$this->getJson($root.'?'.$filter.'='.$value)->assertOk(); $this->assertSame([$this->member->id],array_column($r->json('data.data'),'id')); $this->assertStringNotContainsString('Independent maker',$r->getContent());
        }
        $counts=[];
        foreach ([10,60] as $size) {
            while (Contact::count()<$size) $this->contact($this->org);
            DB::enableQueryLog(); DB::flushQueryLog(); $this->getJson($root.'?per_page='.$size.'&help_id='.$data['help_ids'][0])->assertOk(); $counts[]=count(DB::getQueryLog()); DB::disableQueryLog();
        }
        file_put_contents(base_path('artifacts/app101-office-counts.json'),json_encode($counts));
        $this->assertSame([5,5],$counts);
        $this->office(User::factory()->create(['phone'=>'+15555550101','name'=>'Practice Admin','type'=>'Teacher'])); $this->getJson($root.'/'.$this->member->id.'/from-app')->assertUnauthorized();
    }
    #[Test]
    public function both_deletion_outcomes_clear_answers_in_the_transaction_and_soft_restore_keeps_them(): void
    {
        $this->enable(); $data=$this->answers(); $this->mobile('put',$data)->assertOk();
        app(TenantContext::class)->forgetTenant(); $this->member->delete(); $this->member->restore(); $this->assertSame(1,MemberQuestionAnswer::count());
        $this->member=$this->contact($this->org); $this->mobile('put',$data)->assertOk();
        $softKept=MemberQuestionAnswer::where('contact_id','!=',$this->member->id)->first(); $softKept?->delete();
        $result=app(MemberAccountDeletion::class)->delete($this->member,'app');
        $this->assertSame('erased',$result['outcome']); $this->assertSame(0,MemberQuestionAnswer::count()); $this->assertSame(0,MemberQuestionHelp::count());
        $this->member=$this->contact($this->org); $this->member->forceFill(['notes'=>'Office record'])->save(); $this->mobile('put',$data)->assertOk();
        $result=app(MemberAccountDeletion::class)->delete($this->member,'app');
        $this->assertSame('login_removed',$result['outcome']); $this->assertNotNull(Contact::withoutMasjidScope()->find($this->member->id)); $this->assertSame(0,MemberQuestionAnswer::count()); $this->assertSame(0,MemberQuestionHelp::count());
    }
    #[Test]
    public function public_deletion_of_a_family_only_survivor_clears_merged_answers_but_soft_restore_keeps_them(): void
    {
        $this->enable();$this->mobile('put',$this->answers())->assertOk();$source=$this->member;$target=$this->contact($this->org);
        $target->forceFill(['verified_at'=>null,'login_enabled_at'=>now(),'signup_source'=>null])->save();
        $this->office();$this->postJson('/api/admin/masjids/'.$this->org->id.'/contacts/'.$source->id.'/merge',['target_contact_id'=>$target->id])->assertOk();
        $this->assertSame($target->id,MemberQuestionAnswer::first()->contact_id);
        $target->delete();$target->restore();$this->assertSame(1,MemberQuestionAnswer::count());
        $result=app(MemberAccountDeletion::class)->delete($target,'page',null,$target->login_email);
        $this->assertSame('login_removed',$result['outcome']);$this->assertSame(0,MemberQuestionAnswer::count());$this->assertSame(0,MemberQuestionHelp::count());
    }
    #[Test]
    public function merge_moves_answers_only_when_the_survivor_has_none(): void
    {
        $this->enable(); $this->mobile('put',$this->answers())->assertOk();
        $survivor=$this->contact($this->org); app(TenantContext::class)->set($this->org->id);
        \App\Support\MemberQuestions::merge($this->member,$survivor);
        $this->assertSame($survivor->id,MemberQuestionAnswer::first()->contact_id);
        $this->mobile('put',$this->answers())->assertOk();
        \App\Support\MemberQuestions::merge($this->member,$survivor);
        $this->assertSame(1,MemberQuestionAnswer::count());
    }
    #[Test]
    public function volunteer_https_validation_carriers_and_reserved_tab_drop_when_unavailable(): void
    {
        $this->enable('app_volunteer_entry'); $this->enable('app_bottom_tabs'); $this->office();
        $url='/api/admin/masjids/'.$this->org->id.'/app-volunteer';
        foreach (['http://example.invalid','https:///bad','https://user:pass@example.invalid','https://example.invalid/'.str_repeat('x',500)] as $bad) $this->putJson($url,['title'=>'Volunteer','url'=>$bad])->assertUnprocessable();
        $this->put($url,['title'=>'Helping','url'=>'https://example.invalid/form'],['Accept'=>'application/json'])->assertOk();
        $this->putJson('/api/admin/masjids/'.$this->org->id.'/app-bottom-tabs',['feature_ids'=>['volunteer']])->assertOk();
        auth()->forgetGuards(); app(TenantContext::class)->forgetTenant();
        foreach (['/api/mobile/masjids/'.$this->org->id,'/api/v1/settings'] as $carrier) $this->getJson($carrier,['masjid-id'=>(string)$this->org->id])->assertOk()->assertJsonPath('data.volunteer.title','Helping')->assertJsonPath('data.bottom_tabs.0',['id'=>null,'key'=>'volunteer','name'=>'Helping']);
        $this->office(); $this->putJson($url,['title'=>'Volunteer','url'=>null])->assertOk();
        $this->getJson('/api/mobile/masjids/'.$this->org->id)->assertOk()->assertJsonMissingPath('data.volunteer')->assertJsonMissingPath('data.bottom_tabs');
        try { \App\Models\MobileAppFeature::create(['key'=>'volunteer','name'=>'Bad']); $this->fail('Reserved key accepted'); } catch (\Illuminate\Validation\ValidationException $e) { $this->assertNotEmpty($e->errors()); }
    }
    #[Test]
    public function everything_on_preserves_each_old_carrier_key_and_features_body(): void
    {
        $feature=\App\Models\MobileAppFeature::create(['key'=>'practice','name'=>'Practice']);
        $this->org->features()->attach($feature->id,['is_available'=>true]);
        $urls=['/api/mobile/masjids/'.$this->org->id,'/api/v1/settings','/api/mobile/masjids/'.$this->org->id.'/features'];
        $base=[];
        foreach($urls as $url) $base[$url]=$this->getJson($url,['masjid-id'=>(string)$this->org->id])->assertOk()->getContent();
        $this->enable();$this->enable('app_volunteer_entry');$this->enable('app_bottom_tabs');$this->office();
        $this->putJson('/api/admin/masjids/'.$this->org->id.'/app-volunteer',['title'=>'Volunteer','url'=>'https://example.invalid/form'])->assertOk();
        $this->putJson('/api/admin/masjids/'.$this->org->id.'/app-bottom-tabs',['feature_ids'=>[$feature->id,'volunteer']])->assertOk();
        auth()->forgetGuards();app(TenantContext::class)->forgetTenant();Cache::flush();
        foreach($urls as $url){
            $r=$this->getJson($url,['masjid-id'=>(string)$this->org->id])->assertOk();$body=$r->json();
            if (!str_ends_with($url,'/features')) {foreach(['onboarding','volunteer','bottom_tabs'] as $key){$this->assertArrayHasKey($key,$body['data']);unset($body['data'][$key]);}}
            $this->assertSame($base[$url],json_encode($body));
        }
    }
    #[Test]
    public function deleting_answers_rolls_back_when_later_contact_erasure_fails(): void
    {
        $this->enable();$this->mobile('put',$this->answers())->assertOk();
        $dispatcher=Contact::getEventDispatcher();Contact::setEventDispatcher(clone $dispatcher);
        Contact::forceDeleting(function(){
            $this->assertSame(0,MemberQuestionAnswer::withoutMasjidScope()->count());
            $this->assertSame(0,MemberQuestionHelp::withoutMasjidScope()->count());
            throw new \RuntimeException('Injected after answers deletion');
        });
        try { app(MemberAccountDeletion::class)->delete($this->member,'app');$this->fail('Deletion failure was swallowed'); }
        catch(\RuntimeException $e){$this->assertSame('Injected after answers deletion',$e->getMessage());}
        finally {Contact::setEventDispatcher($dispatcher);}
        $this->assertSame(1,MemberQuestionAnswer::withoutMasjidScope()->where('contact_id',$this->member->id)->count());
        $this->assertNotNull(Contact::withoutMasjidScope()->find($this->member->id));
        $this->assertSame(1,MemberQuestionHelp::withoutMasjidScope()->count());
    }
    #[Test]
    public function kept_contact_answer_erasure_rolls_back_with_a_failed_credential_clear(): void
    {
        $this->enable();$this->member->forceFill(['notes'=>'Office record'])->save();$this->mobile('put',$this->answers())->assertOk();
        $dispatcher=Contact::getEventDispatcher();Contact::setEventDispatcher(clone $dispatcher);
        Contact::updating(function($contact){if($contact->isDirty('verified_at')) {
            $this->assertSame(0,MemberQuestionAnswer::withoutMasjidScope()->count());
            $this->assertSame(0,MemberQuestionHelp::withoutMasjidScope()->count());
            throw new \RuntimeException('Injected after credential clear');
        }});
        try {app(MemberAccountDeletion::class)->delete($this->member,'app');$this->fail('Failure swallowed');}
        catch(\RuntimeException $e){$this->assertSame('Injected after credential clear',$e->getMessage());}
        finally {Contact::setEventDispatcher($dispatcher);}
        $this->assertSame(1,MemberQuestionAnswer::withoutMasjidScope()->where('contact_id',$this->member->id)->count());
        $this->assertNotNull($this->member->fresh()->verified_at);
        $this->assertSame(1,MemberQuestionHelp::withoutMasjidScope()->count());
    }
    #[Test]
    public function real_contact_merge_carries_answers_and_keeps_survivor_without_duplicates(): void
    {
        $this->enable();$this->mobile('put',$this->answers())->assertOk();$source=$this->member;$target=$this->contact($this->org);
        $this->office();$url='/api/admin/masjids/'.$this->org->id.'/contacts/'.$source->id.'/merge';
        $this->postJson($url,['target_contact_id'=>$target->id])->assertOk();
        $this->assertNull(Contact::withoutMasjidScope()->find($source->id));
        $this->assertSame($target->id,MemberQuestionAnswer::first()->contact_id);
        $this->member=$this->contact($this->org);$this->mobile('put',$this->answers())->assertOk();$source=$this->member;$this->office();
        $this->postJson('/api/admin/masjids/'.$this->org->id.'/contacts/'.$source->id.'/merge',['target_contact_id'=>$target->id])->assertOk();
        $this->assertSame(1,MemberQuestionAnswer::count());$this->assertSame(1,MemberQuestionHelp::count());
    }
    #[Test]
    public function office_from_app_permission_and_foreign_contact_fences(): void
    {
        $this->enable();$other=$this->makeMasjid('other');$foreign=$this->contact($other);$this->office();
        $root='/api/admin/masjids/'.$this->org->id.'/contacts';
        $this->getJson($root.'/'.$foreign->id.'/from-app')->assertNotFound();
        $this->admin->roles()->detach();app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();$this->admin=$this->admin->fresh();$this->office();
        $this->getJson($root.'/'.$this->member->id.'/from-app')->assertForbidden();
        $this->getJson($root.'/from-app-filters')->assertForbidden();
    }

    #[Test]
    public function all_new_models_are_tenant_scoped_and_stamp_foreign_input(): void
    {
        $this->enable(); $other=$this->makeMasjid('other'); app(TenantContext::class)->forgetTenant();
        $option=MemberQuestionOption::create(['masjid_id'=>$other->id,'question'=>'help','label'=>'Foreign','position'=>1,'active'=>true]);
        $answer=MemberQuestionAnswer::create(['masjid_id'=>$other->id,'contact_id'=>$this->contact($other)->id]);
        $help=MemberQuestionHelp::create(['masjid_id'=>$other->id,'answer_id'=>$answer->id,'option_id'=>$option->id]);
        $volunteer=AppVolunteerSetting::create(['masjid_id'=>$other->id,'title'=>'Volunteer','url'=>'https://example.invalid']);
        app(TenantContext::class)->set($this->org->id);
        $this->assertNull(MemberQuestionOption::find($option->id)); $this->assertNull(MemberQuestionAnswer::find($answer->id));
        $this->assertNull(MemberQuestionHelp::find($help->id)); $this->assertNull(AppVolunteerSetting::find($volunteer->id));
        $own=MemberQuestionOption::create(['masjid_id'=>$other->id,'question'=>'help','label'=>'Own','position'=>30,'active'=>true]); $this->assertSame($this->org->id,$own->masjid_id);
    }
}
