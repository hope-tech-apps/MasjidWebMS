<?php

namespace Tests\Feature;

use App\Models\{Masjid, MasjidUser, User, Group, GroupStaff, Contact, GroupMembership, ReportCard, SchoolYear, SchoolTerm};
use App\Services\Schools\ReportCardService;
use App\Support\{TenantContext, CapabilityWriter};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Schema};
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\{Test, DataProvider};
use Tests\TestCase;

class SchoolReportCardTermLinkTest extends TestCase
{
    use RefreshDatabase;
    private Masjid $school;
    private GroupMembership $member;
    private User $teacher;
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->travelTo(\Carbon\Carbon::parse('2026-10-08 16:00:00', 'UTC'));
        $this->school = $this->makeMasjid();
        $this->teacher = User::factory()->create(['type'=>'Teacher','phone'=>'+15550001012']);
        MasjidUser::create(['masjid_id'=>$this->school->id,'user_id'=>$this->teacher->id,'role'=>'teacher','is_default'=>true]);
        $this->member = $this->enrol($this->school);
        $this->member->group->staff()->attach($this->teacher->id,['masjid_id'=>$this->school->id,'role'=>GroupStaff::ROLE_TEACHER,'assigned_at'=>now()]);
        Sanctum::actingAs($this->teacher,['staff']);
    }
    private function makeMasjid(): Masjid
    {
        return Masjid::create(['name'=>'Test School '.uniqid(),'email'=>uniqid().'@example.invalid','phone'=>'+1'.random_int(1000000000,9999999999),'country_id'=>'1','city_id'=>'1','address'=>'1 Test St','latitude'=>0,'longitude'=>0,'org_type'=>'school','crm_enabled'=>true]);
    }
    private function enrol(Masjid $org): GroupMembership
    {
        return app(TenantContext::class)->runWithout(function () use ($org) {
            $group = Group::factory()->create(['masjid_id'=>$org->id,'name'=>'Test class','slug'=>'test-class-'.uniqid(),'kind'=>'class']);
            $child = Contact::factory()->create(['masjid_id'=>$org->id,'first_name'=>'Test','last_name'=>'Student']);
            return GroupMembership::create(['masjid_id'=>$org->id,'group_id'=>$group->id,'contact_id'=>$child->id,'role'=>'member','grade_label'=>'Pre-K']);
        });
    }
    private function year(string $label='2026-2027', string $system='quarters', ?Masjid $org=null, string $first='2026-10-11'): SchoolYear
    {
        return app(TenantContext::class)->runWithout(fn()=>SchoolYear::create(['masjid_id'=>($org??$this->school)->id,'label'=>$label,'first_day'=>$first,'last_day'=>'2027-06-27','meeting_weekdays'=>[0],'term_system'=>$system]));
    }
    private function term(SchoolYear $year, int $position=2): SchoolTerm
    {
        return app(TenantContext::class)->runWithout(fn()=>SchoolTerm::create(['masjid_id'=>$year->masjid_id,'school_year_id'=>$year->id,'name'=>'Term '.$position,'starts_on'=>'2026-10-11','ends_on'=>'2026-10-25','position'=>$position]));
    }
    private function card(string $year='2026-2027', int $term=2, ?GroupMembership $member=null): ReportCard
    {
        $member??=$this->member;
        return app(TenantContext::class)->runWithout(fn()=>ReportCard::create(['masjid_id'=>$member->masjid_id,'group_id'=>$member->group_id,'group_membership_id'=>$member->id,'type'=>'report_card','school_year'=>$year,'term'=>$term]));
    }
    private function enable(?Masjid $org=null): void { CapabilityWriter::apply($org??$this->school,['school_calendar_terms'=>true],$this->teacher->id); }

    public static function unmatched(): array
    {
        return [
            'missing year'=>['missing','2026-2027','no such year'],
            'abbreviated name uses dates'=>['abbreviated','2026-2027','year has no quarter 2'],
            'invalid stored text'=>['invalid','2026–27','no such year'],
            'duplicate label'=>['duplicate','2026-2027','two years match'],
            'missing quarter'=>['quarter','2026-2027','year has no quarter 2'],
            'semesters'=>['semesters','2026-2027','year is on semesters'],
            'trimesters'=>['trimesters','2026-2027','year is on trimesters'],
            'unset system'=>['unset','2026-2027','year has no quarter 2'],
        ];
    }
    #[Test, DataProvider('unmatched')]
    public function every_unmatched_pair_is_counted_with_its_reason(string $case,string $text,string $reason): void
    {
        if (!in_array($case,['missing','invalid'])) {
            $year=$this->year($case==='abbreviated'?'2026–27':'2026-2027',in_array($case,['semesters','trimesters'])?$case:'quarters');
            if ($case==='unset') $year->update(['term_system'=>null]);
            if ($case==='duplicate') $this->year(first:'2026-11-01');
        }
        $this->enable(); $card=$this->card($text); $second=$this->card($text,2,$this->enrol($this->school));
        $this->artisan('school-calendar:link-report-cards',['--masjid'=>$this->school->id,'--dry-run'=>true])
            ->expectsOutputToContain('school_year='.json_encode($text,JSON_UNESCAPED_UNICODE).' term=2 count=2 reason='.$reason)->assertSuccessful();
        $this->assertNull($card->fresh()->school_term_id);
    }
    #[Test]
    public function linking_is_idempotent_reports_ids_only_and_can_link_a_later_added_quarter(): void
    {
        $year=$this->year(); $term=$this->term($year); $this->enable();
        $card=$this->card(); $later=$this->card(term:3);
        $card->forceFill(['published_at'=>now(),'teacher_comment'=>'Stored comment','days_present'=>5,'days_late'=>1])->save();
        $issued=$card->fresh()->getRawOriginal();
        $this->artisan('school-calendar:link-report-cards',['--dry-run'=>true])->expectsOutputToContain("year=$year->id term=2 school_term=$term->id count=1 rule=name")->assertSuccessful();
        $this->assertNull($card->fresh()->school_term_id);
        $this->artisan('school-calendar:link-report-cards')->assertSuccessful();
        $this->assertSame($term->id,$card->fresh()->school_term_id);
        $issued['school_term_id']=$term->id; $this->assertSame($issued,$card->fresh()->getRawOriginal());
        $timestamps=$card->fresh()->getRawOriginal('updated_at'); $this->travel(1)->hours();
        $this->artisan('school-calendar:link-report-cards')->expectsOutputToContain('linked=0 unmatched=1')->assertSuccessful();
        $this->assertSame($timestamps,$card->fresh()->getRawOriginal('updated_at'));
        $third=$this->term($year,3);
        $this->artisan('school-calendar:link-report-cards')->assertSuccessful();
        $this->assertSame($third->id,$later->fresh()->school_term_id);
        $this->assertSame('Quarter 2, 2026-2027',$card->fresh()->periodLabel());
    }
    #[Test]
    public function creation_stamps_only_new_cards_and_deletion_keeps_issued_cards(): void
    {
        $old=$this->card(); $year=$this->year(); $term=$this->term($year); $this->enable();
        $service=app(ReportCardService::class);
        $this->assertNull($service->prepare($this->member,'report_card','2026-2027',2)->school_term_id);
        $new=$service->prepare($this->member,'progress','2026-2027',2);
        $this->assertSame($term->id,$new->school_term_id);
        $new->forceFill(['published_at'=>now(),'teacher_comment'=>'Stored comment'])->save();
        $before=$new->fresh()->getRawOriginal(); $term->delete();
        $after=$new->fresh()->getRawOriginal(); $before['school_term_id']=null;
        $this->assertSame($before,$after);
        $term=$this->term($year); $new->forceFill(['school_term_id'=>$term->id])->save();
        $year->delete(); $this->assertNull($new->fresh()->school_term_id); $this->assertSame('Stored comment',$new->fresh()->teacher_comment);
        $this->assertSame(2,ReportCard::count());
    }
    #[Test]
    public function creation_refuses_loose_ambiguous_or_nonquarter_links(): void
    {
        $year=$this->year(system:'semesters');$this->term($year);$this->enable();
        $card=app(ReportCardService::class)->prepare($this->member,'report_card','2026-2027',2);
        $this->assertNull($card->school_term_id);
        $year->update(['term_system'=>'quarters']);$this->year(first:'2026-11-01');
        $this->assertNull(app(ReportCardService::class)->prepare($this->member,'progress','2026-2027',2)->school_term_id);
    }
    #[Test]
    public function command_scopes_both_years_and_cards_and_leaves_off_schools_dormant(): void
    {
        $foreign=$this->makeMasjid();$member=$this->enrol($foreign);$fy=$this->year(org:$foreign);$ft=$this->term($fy);
        $fc=$this->card(member:$member);$local=$this->card();$this->enable();
        app(TenantContext::class)->set($foreign->id);
        $this->artisan('school-calendar:link-report-cards',['--masjid'=>$this->school->id])->assertSuccessful();
        $this->assertNull($local->fresh()->school_term_id);$this->assertNull(app(TenantContext::class)->runWithout(fn()=>$fc->fresh()->school_term_id));
        $this->artisan('school-calendar:link-report-cards')->assertSuccessful();
        $this->assertNull(app(TenantContext::class)->runWithout(fn()=>$fc->fresh()->school_term_id));
        $ly=$this->year(); $lt=$this->term($ly);
        $this->artisan('school-calendar:link-report-cards',['--masjid'=>$this->school->id])->assertSuccessful();
        $this->assertSame($lt->id,app(TenantContext::class)->runWithout(fn()=>$local->fresh()->school_term_id));
        $this->assertNull(app(TenantContext::class)->runWithout(fn()=>$fc->fresh()->school_term_id));
        $this->enable($foreign);
        $this->artisan('school-calendar:link-report-cards',['--masjid'=>$foreign->id])->assertSuccessful();
        $this->assertSame($ft->id,app(TenantContext::class)->runWithout(fn()=>$fc->fresh()->school_term_id));
        $this->artisan('school-calendar:link-report-cards',['--masjid'=>'bogus'])->assertFailed();
    }

    #[Test]
    public function rollback_checks_the_whole_feature_before_dropping_the_link_column(): void
    {
        $year=$this->year();$term=$this->term($year);$card=$this->card();
        $card->forceFill(['school_term_id'=>$term->id])->save();
        $migration=require base_path('database/migrations/2026_10_08_210000_add_report_card_school_term_link.php');
        try { $migration->down(); $this->fail('Rollback must refuse linked cards'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('in use',$e->getMessage()); }
        $this->assertTrue(Schema::hasColumn('report_cards','school_term_id'));
        $card->forceFill(['school_term_id'=>null])->save();
        try { $migration->down(); $this->fail('Rollback must refuse other feature data before DDL'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('in use',$e->getMessage()); }
        $this->assertTrue(Schema::hasColumn('report_cards','school_term_id'));
    }

    #[Test]
    public function dry_run_prints_a_complete_operational_report_without_people_or_writes(): void
    {
        $year=$this->year();$term=$this->term($year);$this->enable();$card=$this->card();$this->card(term:3);
        $before=DB::table('report_cards')->orderBy('id')->get()->toJson();
        $this->withoutMockingConsoleOutput();
        $status=\Illuminate\Support\Facades\Artisan::call('school-calendar:link-report-cards',['--masjid'=>$this->school->id,'--dry-run'=>true]);
        $output=\Illuminate\Support\Facades\Artisan::output();
        $this->assertSame(0,$status);
        $this->assertSame("organisation={$this->school->id} mode=dry-run linked=1 unmatched=1\nyear=$year->id term=2 school_term=$term->id count=1 rule=name\nschool_year=\"2026-2027\" term=3 count=1 reason=year has no quarter 3 rule=name\n",$output);
        $this->assertSame($before,DB::table('report_cards')->orderBy('id')->get()->toJson());
        if (getenv('CALENDAR_CAPTURE_LINK_REPORT')) file_put_contents(base_path('artifacts/report-card-link-dry-run.txt'),$output);
    }

    public static function deletionModes(): array { return [[true], [false]]; }

    #[Test, DataProvider('deletionModes')]
    public function office_deleting_a_term_or_its_year_retains_linked_cards(bool $on): void
    {
        $year=$this->year();$term=$this->term($year);$this->enable();$card=$this->card();
        $card->forceFill(['school_term_id'=>$term->id,'published_at'=>now()])->save();
        $admin=User::factory()->create(['type'=>'MasjidAdmin','phone'=>'+15550001013']);
        MasjidUser::create(['masjid_id'=>$this->school->id,'user_id'=>$admin->id,'role'=>'masjid-admin','is_default'=>true]);
        CapabilityWriter::apply($this->school,['school_calendar'=>true],$admin->id);
        Sanctum::actingAs($admin);
        $base='/api/admin/masjids/'.$this->school->id.'/school-calendar/years/'.$year->id;
        $this->putJson($base, ['label'=>$year->label,'first_day'=>$year->first_day->toDateString(),'last_day'=>$year->last_day->toDateString(),'meeting_weekdays'=>[0],'term_system'=>'quarters','terms'=>[]])->assertOk();
        $this->assertNull($card->fresh()->school_term_id);$this->assertNotNull($card->fresh()->published_at);
        $term=$this->term($year);$card->forceFill(['school_term_id'=>$term->id])->save();
        $before=$card->fresh()->getRawOriginal();
        if (! $on) CapabilityWriter::apply($this->school,['school_calendar_terms'=>false],$admin->id);
        $response=$this->deleteJson($base)->assertOk();
        $this->assertSame(['status'=>'success','data'=>['timezone'=>'America/New_York','today'=>'2026-10-08','years'=>[]]],$response->json());
        $before['school_term_id']=null; $this->assertSame($before,$card->fresh()->getRawOriginal());
        $this->assertNull($card->fresh()->school_term_id);$this->assertSame('Quarter 2, 2026-2027',$card->fresh()->periodLabel());
        $this->assertNotNull($card->fresh()->published_at);$this->assertSame(0,SchoolTerm::count());
    }

    #[Test]
    public function enabled_teacher_creation_has_the_exact_original_printed_payload_with_a_hidden_link(): void
    {
        $year=$this->year();$term=$this->term($year);$this->enable();
        $query='?term=2&school_year=2026-2027';
        $response=$this->getJson('/api/teacher/masjids/'.$this->school->id.'/groups/'.$this->member->group_id.'/members/'.$this->member->id.'/report-card'.$query)->assertOk();
        $expected=json_decode(file_get_contents(base_path('tests/fixtures/calendar-baseline/report-cards.json')),true)[hash('sha256',$query)];
        $expected['body']['data']['period_label'] = 'Term 2 (Oct 11, 2026 – Oct 25, 2026), 2026-2027';
        $this->assertSame($expected['body'],$response->json());
        $this->assertSame($term->id,ReportCard::firstOrFail()->school_term_id);
        $this->assertArrayNotHasKey('school_term_id',ReportCard::firstOrFail()->toArray());
    }

    public static function offShapes(): array
    {
        return [[''],['?term=2&school_year=2026-2027'],['?type=progress&term=2&school_year=2026-2027'],['?type=invalid&term=8&school_year=2026–27'],['?term=2&school_year=2026-2027&dormant=1']];
    }
    #[Test, DataProvider('offShapes')]
    public function off_real_teacher_requests_preserve_full_literal_response_and_rows(string $query): void
    {
        $year=$this->year();$term=$this->term($year);
        if (str_contains($query,'dormant')) {
            $card=$this->card();
            if (Schema::hasColumn('report_cards','school_term_id')) $card->forceFill(['school_term_id'=>$term->id])->save();
        }
        $queries=[];DB::listen(function ($q) use (&$queries) { $queries[]=$q->sql; });
        $response=$this->getJson('/api/teacher/masjids/'.$this->school->id.'/groups/'.$this->member->group_id.'/members/'.$this->member->id.'/report-card'.$query)->assertOk();
        $rows=DB::table('report_cards')->orderBy('id')->get()->map(function ($row) use ($query,$term) { $row=(array)$row;if (!array_key_exists('school_term_id',$row)) $row['school_term_id']=str_contains($query,'dormant')?$term->id:null;return $row; })->all();
        $actual=['status'=>$response->status(),'body'=>$response->json(),'cards'=>$rows,'marks'=>DB::table('report_card_marks')->orderBy('id')->get()->map(fn($r)=>(array)$r)->all()];
        $key=hash('sha256',$query);$path=base_path('tests/fixtures/calendar-baseline/report-cards.json');
        if (getenv('CALENDAR_CAPTURE_CARDS')) {
            $fixture=is_file($path)?json_decode(file_get_contents($path),true):[];$fixture[$key]=$actual;file_put_contents($path,json_encode($fixture,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n");
            $this->fail('Origin baseline capture is evidence generation, never a passing differential test.');
        }
        $this->assertSame(json_decode(file_get_contents($path),true)[$key],$actual);
        $this->assertNull(collect($queries)->first(fn($sql)=>str_contains(strtolower($sql),'school_term_id')),'OFF must issue no new-column query');
        $raw=DB::table('report_cards')->first();
        if (Schema::hasColumn('report_cards','school_term_id')) $this->assertSame(str_contains($query,'dormant')?$term->id:null,$raw->school_term_id);
    }
    #[Test]
    public function review_dry_run_uses_only_plain_selects_and_opens_no_transaction(): void
    {
        $year = $this->year(); $this->term($year); $this->enable(); $card = $this->card();
        $before = $card->fresh()->getRawOriginal();
        $begins = 0;
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\TransactionBeginning::class, function () use (&$begins) { $begins++; });
        DB::flushQueryLog(); DB::enableQueryLog();
        $this->artisan('school-calendar:link-report-cards', ['--masjid' => $this->school->id, '--dry-run' => true])->assertSuccessful();
        $queries = DB::getQueryLog(); DB::disableQueryLog();
        $this->assertSame(0, $begins, 'Dry run must not begin even a read transaction');
        foreach ($queries as $query) {
            $this->assertStringStartsWith('select ', strtolower($query['query']));
            $this->assertStringNotContainsString('for update', strtolower($query['query']));
            $this->assertStringNotContainsString('lock in share mode', strtolower($query['query']));
        }
        $this->assertSame($before, $card->fresh()->getRawOriginal());
    }

}
