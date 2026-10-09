<?php

use App\Models\{Masjid, MasjidUser, User, Group, GroupStaff, Contact, GroupMembership, ReportCard, SchoolYear, SchoolTerm, SchoolClosure, LessonPlan};
use App\Support\{TenantContext, ClassSubjectInitializer, SchoolReportCardTermMatcher};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Artisan};
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);
beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    $this->travelTo(\Carbon\Carbon::parse('2026-10-12 12:00:00 UTC'));
    $this->org = Masjid::create(['name'=>'Practice School','email'=>'calendar@example.invalid','phone'=>'+15555550901','country_id'=>'1','city_id'=>'1','address'=>'Practice','latitude'=>0,'longitude'=>0,'org_type'=>'school','crm_enabled'=>true,'timezone'=>'America/New_York']);
    $this->org->forceFill(['capability_overrides'=>['school_calendar'=>true,'school_calendar_terms'=>true]])->save();
    $this->actor = User::factory()->create(['type'=>'SuperAdmin','phone'=>'+15555550902']);
    Sanctum::actingAs($this->actor, ['staff']);
    $this->office = '/api/admin/masjids/'.$this->org->id.'/school-calendar';
    $this->yearBody = ['label'=>'Day school','first_day'=>'2026-10-12','last_day'=>'2027-06-18','meeting_weekdays'=>[1,2,3,4,5],'term_system'=>'quarters'];
    $this->termBody = ['name'=>'Quarter 2','starts_on'=>'2026-10-20','ends_on'=>'2027-01-16','position'=>2];
    $this->year = SchoolYear::create(['masjid_id'=>$this->org->id]+$this->yearBody);
    $this->group = Group::factory()->create(['masjid_id'=>$this->org->id,'kind'=>'class']);
    $this->member = GroupMembership::create(['masjid_id'=>$this->org->id,'group_id'=>$this->group->id,'contact_id'=>Contact::factory()->create(['masjid_id'=>$this->org->id,'first_name'=>'Practice','last_name'=>'Student'])->id,'role'=>'member']);
    $this->card = ReportCard::create(['masjid_id'=>$this->org->id,'group_id'=>$this->group->id,'group_membership_id'=>$this->member->id,'type'=>'report_card','school_year'=>'2026-2027','term'=>2]);
});
afterEach(fn () => app(TenantContext::class)->forgetTenant());

it('saves year and terms atomically with indexed term field validation', function () {
    $body = $this->yearBody + ['terms'=>[$this->termBody]];
    $this->put($this->office.'/years/'.$this->year->id, $body, ['Accept'=>'application/json'])->assertOk()->assertJsonPath('data.years.0.terms.0.name', 'Quarter 2');
    $term = SchoolTerm::firstOrFail();
    $body['label'] = 'Changed';
    $body['terms'] = [['id'=>$term->id]+$this->termBody, ['name'=>'Wrong dates','starts_on'=>'2026-10-21','ends_on'=>'2026-10-22','position'=>3]];
    $before = $this->year->fresh()->getRawOriginal();
    $this->putJson($this->office.'/years/'.$this->year->id, $body)->assertUnprocessable()->assertJsonPath('data', ['terms.1.starts_on'=>['Wrong dates: Term dates must not overlap.']]);
    expect($this->year->fresh()->getRawOriginal())->toBe($before);
    expect(SchoolTerm::count())->toBe(1);
    $body['terms'] = [['id'=>$term->id,'name'=>'Revised','starts_on'=>'2026-10-20','ends_on'=>'2027-01-15','position'=>2]];
    $this->putJson($this->office.'/years/'.$this->year->id, $body)->assertOk();
    expect($term->fresh()->name)->toBe('Revised');
    $this->card->forceFill(['school_term_id'=>$term->id,'published_at'=>now()])->save();
    $this->getJson($this->office)->assertOk()->assertJsonPath('data.years.0.terms.0.report_card_count', 1);
    $before = $this->card->fresh()->getRawOriginal();
    $body['terms'] = [];
    $this->putJson($this->office.'/years/'.$this->year->id, $body)->assertOk();
    $before['school_term_id'] = null;
    expect($this->card->fresh()->getRawOriginal())->toBe($before);
    expect(SchoolTerm::count())->toBe(0);
});

it('creates terms together with a year and rejects invalid drafts without creating either', function () {
    $this->year->delete();
    $body = $this->yearBody + ['terms'=>[$this->termBody]];
    $body['terms'][0]['ends_on'] = '2027-07-01';
    $this->postJson($this->office.'/years', $body)->assertUnprocessable()->assertJsonValidationErrors('terms.0.ends_on', 'data');
    expect(SchoolYear::count())->toBe(0)->and(SchoolTerm::count())->toBe(0);
    $body['terms'] = [$this->termBody];
    $this->postJson($this->office.'/years', $body)->assertCreated()->assertJsonPath('data.years.0.terms.0.name','Quarter 2');
    foreach (['post','put','delete'] as $method) $this->{$method.'Json'}($this->office.'/years/1/terms'.($method==='post'?'':'/1'), $this->termBody)->assertNotFound();
});

it('counts filed cards in one grouped office query regardless of number of terms', function () {
    for ($i=1;$i<=8;$i++) SchoolTerm::create(['masjid_id'=>$this->org->id,'school_year_id'=>$this->year->id,'name'=>'Term '.$i,'starts_on'=>'2026-10-12','ends_on'=>'2026-10-12','position'=>$i]);
    DB::flushQueryLog(); DB::enableQueryLog();
    $r = $this->getJson($this->office)->assertOk();
    $log = array_column(DB::getQueryLog(), 'query'); DB::disableQueryLog();
    $counts = array_values(array_filter($log, fn ($sql) => str_contains($sql, 'report_cards')));
    expect($counts)->toHaveCount(1); expect(strtolower($counts[0]))->toContain('group by');
    expect(array_column($r->json('data.years.0.terms'), 'report_card_count'))->toBe(array_fill(0,8,0));
});

it('returns exact week dates and day notices without extra lesson queries in both subject modes', function (bool $subjects) {
    app(TenantContext::class)->forgetTenant();
    if ($subjects) ClassSubjectInitializer::run($this->org->fresh(), false, true);
    $teacher = User::factory()->create(['type'=>'Teacher','phone'=>'+15555550903']);
    MasjidUser::create(['masjid_id'=>$this->org->id,'user_id'=>$teacher->id,'role'=>'teacher','is_default'=>true]);
    GroupStaff::withOfficeSubjectChoice(fn () => GroupStaff::create(['masjid_id'=>$this->org->id,'group_id'=>$this->group->id,'user_id'=>$teacher->id,'role'=>GroupStaff::ROLE_TEACHER,'subjects'=>null,'class_subject_ids'=>null]));
    app('auth')->forgetGuards();
    Sanctum::actingAs($teacher,['staff']);
    SchoolClosure::create(['masjid_id'=>$this->org->id,'school_year_id'=>$this->year->id,'closed_on'=>'2026-10-12','reason'=>'Teacher planning day']);
    LessonPlan::create(['masjid_id'=>$this->org->id,'group_id'=>$this->group->id,'session_date'=>'2026-10-12','subject'=>'','subject_key'=>'','body'=>'Saved closed-day plan']);
    LessonPlan::create(['masjid_id'=>$this->org->id,'group_id'=>$this->group->id,'session_date'=>'2026-10-17','subject'=>'','subject_key'=>'','body'=>'Saved weekend plan']);
    DB::flushQueryLog(); DB::enableQueryLog();
    $r = $this->getJson('/api/teacher/masjids/'.$this->org->id.'/groups/'.$this->group->id.'/lesson-plans?from=2026-10-11&to=2026-10-17')->assertOk();
    $log = array_column(DB::getQueryLog(),'query'); DB::disableQueryLog();
    expect($r->json('data.week_dates'))->toBe(['2026-10-12','2026-10-13','2026-10-14','2026-10-15','2026-10-16','2026-10-17']);
    expect($r->json('data.day_notices.2026-10-12'))->toBe('No school on Monday, October 12, 2026 — Teacher planning day.');
    expect($r->json('data.day_notices.2026-10-11'))->toBe("This isn't one of the school's meeting days on the calendar.");
    expect(array_filter($log,fn ($sql)=>str_contains($sql,'from "school_years"')))->toHaveCount(1);
    expect(array_filter($log,fn ($sql)=>str_contains($sql,'from "school_closures"')))->toHaveCount(1);
    expect(array_filter($log,fn ($sql)=>str_contains($sql,'from "lesson_plans"')))->toHaveCount(1);
    foreach (['2026-10-13','2026-10-14','2026-10-15','2026-10-16'] as $date) SchoolClosure::create(['school_year_id'=>$this->year->id,'closed_on'=>$date,'reason'=>'']);
    LessonPlan::query()->delete();
    $closed = $this->getJson('/api/teacher/masjids/'.$this->org->id.'/groups/'.$this->group->id.'/lesson-plans?from=2026-10-11&to=2026-10-17')->assertOk();
    expect($closed->json('data.week_dates'))->toBe([]);
    expect($closed->json('data.day_notices.2026-10-13'))->toBe('No school on Tuesday, October 13, 2026.');

})->with([false,true]);

it('matches dates after exact names and reports the rule in dry run and write', function () {
    $term = SchoolTerm::create(['masjid_id'=>$this->org->id,'school_year_id'=>$this->year->id]+$this->termBody);
    expect(SchoolReportCardTermMatcher::match($this->org->id,'2026-2027',2)['rule'])->toBe('dates');
    foreach ([true,false] as $dry) {
        Artisan::call('school-calendar:link-report-cards',['--masjid'=>$this->org->id,'--dry-run'=>$dry]);
        expect(Artisan::output())->toContain('rule=dates');
        expect($this->card->fresh()->school_term_id)->toBe($dry?null:$term->id);
    }
    $this->year->update(['label'=>'2026-2027']);
    expect(SchoolReportCardTermMatcher::match($this->org->id,'2026-2027',2)['rule'])->toBe('name');
    SchoolYear::create(['masjid_id'=>$this->org->id,'first_day'=>'2026-10-13']+$this->yearBody);
    expect(SchoolReportCardTermMatcher::match($this->org->id,'2026-2027',2)['year']->id)->toBe($this->year->id);
    $this->year->update(['label'=>'Other']);
    expect(SchoolReportCardTermMatcher::match($this->org->id,'2026-2027',2)['reason'])->toBe('two years match');
});

it('offers school dated years and labels linked teacher reports with term dates', function (bool $subjects) {
    app(TenantContext::class)->forgetTenant();
    if ($subjects) ClassSubjectInitializer::run($this->org->fresh(), false, true);
    $term = SchoolTerm::create(['masjid_id'=>$this->org->id,'school_year_id'=>$this->year->id]+$this->termBody);
    $this->card->forceFill(['school_term_id'=>$term->id])->save();
    $teacher = User::factory()->create(['type'=>'Teacher','phone'=>'+15555550904']);
    MasjidUser::create(['masjid_id'=>$this->org->id,'user_id'=>$teacher->id,'role'=>'teacher','is_default'=>true]);
    GroupStaff::withOfficeSubjectChoice(fn () => GroupStaff::create(['masjid_id'=>$this->org->id,'group_id'=>$this->group->id,'user_id'=>$teacher->id,'role'=>GroupStaff::ROLE_TEACHER,'subjects'=>null,'class_subject_ids'=>null]));
    app('auth')->forgetGuards();
    Sanctum::actingAs($teacher,['staff']);
    $base = '/api/teacher/masjids/'.$this->org->id.'/groups/'.$this->group->id.'/report-cards';
    $this->getJson($base.'?school_year=2026-2027&term=2')->assertOk()->assertJsonPath('data.school_years',['2026-2027']);
    $this->getJson(str_replace('/report-cards','/members/'.$this->member->id.'/report-card',$base).'?school_year=2026-2027&term=2')->assertOk()->assertJsonPath('data.period_label','Quarter 2 (Oct 20, 2026 – Jan 16, 2027), 2026-2027');
})->with([false,true]);

it('changes year bounds and swaps term numbers without losing IDs or filed cards, and rolls back a write failure', function () {
    $first = SchoolTerm::create(['masjid_id'=>$this->org->id,'school_year_id'=>$this->year->id,'name'=>'First','starts_on'=>'2026-10-12','ends_on'=>'2026-10-16','position'=>1]);
    $second = SchoolTerm::create(['masjid_id'=>$this->org->id,'school_year_id'=>$this->year->id]+$this->termBody);
    $this->card->forceFill(['school_term_id'=>$first->id])->save();
    $body = array_replace($this->yearBody, ['label'=>'Shortened','last_day'=>'2027-01-29','terms'=>[
        ['id'=>$first->id,'name'=>'Later','starts_on'=>'2026-10-20','ends_on'=>'2027-01-16','position'=>2],
        ['id'=>$second->id,'name'=>'Earlier','starts_on'=>'2026-10-12','ends_on'=>'2026-10-16','position'=>1],
    ]]);
    $this->putJson($this->office.'/years/'.$this->year->id, $body)->assertOk();
    expect($first->fresh()->position)->toBe(2)->and($second->fresh()->position)->toBe(1);
    expect($this->card->fresh()->school_term_id)->toBe($first->id);
    expect(SchoolTerm::where('position',0)->count())->toBe(0);
    $before = DB::table('school_terms')->orderBy('id')->get()->toJson();
    $beforeYear = $this->year->fresh()->getRawOriginal();
    $body['label'] = 'Must roll back'; $body['terms'][0]['name'] = 'Changed'; $body['terms'][1]['name'] = 'Reject';
    $dispatcher = SchoolTerm::getEventDispatcher();
    SchoolTerm::setEventDispatcher(clone $dispatcher);
    SchoolTerm::updated(function ($term) { if ($term->name === 'Reject') throw new RuntimeException('Simulated term write failure'); });
    try {
        $this->withoutExceptionHandling();
        try { $this->putJson($this->office.'/years/'.$this->year->id, $body); $this->fail('Write should fail'); }
        catch (RuntimeException $e) { expect($e->getMessage())->toBe('Simulated term write failure'); }
    } finally { SchoolTerm::setEventDispatcher($dispatcher); }
    expect(DB::table('school_terms')->orderBy('id')->get()->toJson())->toBe($before);
    expect($this->year->fresh()->getRawOriginal())->toBe($beforeYear);
    expect($this->card->fresh()->school_term_id)->toBe($first->id);
});
