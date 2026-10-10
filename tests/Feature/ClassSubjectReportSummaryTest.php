<?php

use App\Models\{ClassSubject, Contact, Group, GroupMembership, GroupStaff, LessonPlan, Masjid, MasjidUser, ReportCard, ReportCardMark, SchoolSubject, SchoolTerm, SchoolYear, SubjectPiece, SubjectPieceMark, User};
use App\Support\{CapabilityWriter, ClassSubjectInitializer, SubjectFence, TenantContext};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

class ReportSummaryOffMemoProbe
{
    public function handle($request, $next)
    {
        $response = $next($request);
        $before = count(DB::getQueryLog());
        expect(\App\Support\ClassSubjectMode::reportSummaryEnabled($request->route('masjid_id')))->toBeFalse();
        expect(count(DB::getQueryLog()))->toBe($before);
        return $response;
    }
}

uses(RefreshDatabase::class);

beforeEach(function () {
    fake()->seed(7419);
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    $this->travelTo(\Carbon\Carbon::parse('2026-10-09 16:00:00', 'UTC'));
    $this->org = Masjid::create(['name' => 'Practice School', 'email' => 'summary@example.invalid', 'phone' => '+15555550100', 'country_id' => '1', 'city_id' => '1', 'address' => 'Practice', 'latitude' => 0, 'longitude' => 0, 'org_type' => 'school', 'crm_enabled' => true, 'timezone' => 'America/New_York']);
    $this->group = Group::factory()->create(['masjid_id' => $this->org->id, 'kind' => 'class', 'name' => 'Practice class', 'slug' => 'practice']);
    $this->teacher = User::factory()->create(['type' => 'Teacher', 'name' => 'Practice Teacher', 'email' => 'teacher@example.invalid', 'phone' => '+15555550101']);
    $this->office = User::factory()->create(['type' => 'SuperAdmin', 'name' => 'Practice Office', 'email' => 'office@example.invalid', 'phone' => '+15555550102']);
    MasjidUser::create(['masjid_id' => $this->org->id, 'user_id' => $this->teacher->id, 'role' => 'teacher', 'is_default' => true]);
    $this->staff = GroupStaff::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'user_id' => $this->teacher->id, 'subjects' => ['arabic']]);
    foreach (['Arabic', 'Science', 'ELA', "Qur’an"] as $name) SchoolSubject::create(['masjid_id' => $this->org->id, 'name' => $name]);
    $contact = Contact::factory()->create(['masjid_id' => $this->org->id, 'first_name' => 'Practice', 'last_name' => 'Student', 'email' => null]);
    $this->student = GroupMembership::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'contact_id' => $contact->id, 'role' => 'member', 'grade_label' => '1st']);
    ClassSubjectInitializer::run($this->org, false, true);
    $this->arabic = ClassSubject::where('name', 'Arabic')->firstOrFail();
    $this->science = ClassSubject::where('name', 'Science')->firstOrFail();
    $this->org->refresh()->forceFill(['capability_overrides' => array_replace($this->org->capability_overrides, ['class_subject_work' => true])])->save();
    $this->url = "/api/teacher/masjids/{$this->org->id}/groups/{$this->group->id}/members/{$this->student->id}/report-card?school_year=2026-2027&term=1";
    Sanctum::actingAs($this->teacher, ['staff']);
    $this->enableSummary = function () { CapabilityWriter::apply($this->org->fresh(), ['class_subject_report_summary' => true], $this->office->id); };
    $this->markPiece = function ($subject, $source = 'own', $level = 4, $saved = '2026-10-09 16:00:00', $date = '2026-10-09', $removedPlan = false) {
        $plan = $source === 'plan' && ! $removedPlan ? LessonPlan::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'subject' => $subject->name, 'class_subject_id' => $subject->id, 'session_date' => $date, 'body' => 'Practice']) : null;
        $piece = SubjectPiece::create(['masjid_id' => $this->org->id, 'class_subject_id' => $subject->id, 'source' => $source, 'title' => $source === 'plan' ? $date.': Practice' : 'Practice', 'lesson_plan_id' => $plan?->id, 'guide_subject' => $source === 'guide' ? 'Practice guide' : null, 'grade_label' => $source === 'guide' ? '1st' : null, 'week_no' => $source === 'guide' ? SubjectPiece::count() + 1 : null]);
        $mark = SubjectPieceMark::create(['masjid_id' => $this->org->id, 'subject_piece_id' => $piece->id, 'group_membership_id' => $this->student->id, 'level' => $level, 'comment' => 'Practice comment']);
        DB::table('subject_piece_marks')->where('id', $mark->id)->update(['updated_at' => $saved]);
        return $mark;
    };
});

afterEach(fn () => app(TenantContext::class)->forgetTenant());

it('walk 25 shows this students subject counts without choosing a report level', function () {
    ($this->enableSummary)();
    foreach ([4, 4, 3, 2, null] as $level) ($this->markPiece)($this->arabic, 'own', $level);
    ($this->markPiece)($this->science, 'own', 1);
    $data = $this->getJson($this->url)->assertOk()->json('data');
    $sub = collect($data['subjects'])->firstWhere('subject', 'Arabic Language');
    expect($sub['work_summary'])->toMatchArray(['heading' => 'So far this year in Arabic', 'name' => 'Arabic', 'counts' => ['4' => 2, '3' => 1, '2' => 1, '1' => 0], 'class_subject_id' => $this->arabic->id, 'can_open' => true]);
    foreach ($sub['criteria'] as $line) expect($line['level'])->toBeNull();
    $this->putJson($this->url, ['marks' => [['id' => $sub['criteria'][0]['id'], 'level' => 1]]])->assertOk();
    expect(ReportCardMark::find($sub['criteria'][0]['id'])->level)->toBe(1);
    $science = collect($data['subjects'])->firstWhere('subject', 'Science');
    expect($science['work_summary']['can_open'])->toBeFalse()->and($science['can_fill'])->toBeFalse();
    expect(collect($data['subjects'])->firstWhere('subject', 'English Language Arts')['work_summary']['name'])->toBe('ELA');
    expect(collect($data['subjects'])->firstWhere('subject', "Qur'an")['work_summary']['name'])->toBe('Qur’an');
    expect(collect($data['subjects'])->firstWhere('subject', 'Mathematics'))->not->toHaveKey('work_summary');
});

it('uses inclusive dated term plan dates and local last saved days with every source', function () {
    ($this->enableSummary)();
    $card = $this->getJson($this->url)->assertOk()->json('data.id');
    $year = SchoolYear::create(['masjid_id' => $this->org->id, 'label' => '2026-2027', 'first_day' => '2026-08-01', 'last_day' => '2027-07-31']);
    $term = SchoolTerm::create(['masjid_id' => $this->org->id, 'school_year_id' => $year->id, 'name' => 'Practice term', 'position' => 1, 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-09']);
    ReportCard::find($card)->forceFill(['school_term_id' => $term->id])->save();
    $this->org->refresh()->forceFill(['capability_overrides' => array_replace($this->org->capability_overrides, ['school_calendar_terms' => true])])->save();
    foreach (['own', 'guide'] as $source) foreach (['2026-10-01 03:59:59' => 1, '2026-10-01 04:00:00' => 4, '2026-10-10 03:59:59' => 3, '2026-10-10 04:00:00' => 1] as $saved => $level) ($this->markPiece)($this->arabic, $source, $level, $saved);
    foreach ([false, true] as $removed) foreach (['2026-09-30' => 1, '2026-10-01' => 4, '2026-10-09' => 3, '2026-10-10' => 1] as $date => $level) ($this->markPiece)($this->arabic, 'plan', $level, '2026-11-01 12:00:00', $date, $removed);
    $sub = collect($this->getJson($this->url)->assertOk()->json('data.subjects'))->firstWhere('subject', 'Arabic Language');
    expect($sub['work_summary']['heading'])->toBe('This term in Arabic')->and($sub['work_summary']['counts'])->toBe(['4' => 4, '3' => 4, '2' => 0, '1' => 0]);
    // A stored term link is ineffective while dated terms are off.
    $this->org->refresh()->forceFill(['capability_overrides' => array_replace($this->org->capability_overrides, ['school_calendar_terms' => false])])->save();
    $sub = collect($this->getJson($this->url)->assertOk()->json('data.subjects'))->firstWhere('subject', 'Arabic Language');
    expect($sub['work_summary']['heading'])->toBe('So far this year in Arabic')->and($sub['work_summary']['counts']['1'])->toBe(8);
});

it('refuses a mixed save entirely but leaves unassigned lines behaviours and whole card actions open', function () {
    ($this->enableSummary)();
    $data = $this->getJson($this->url)->assertOk()->json('data');
    $subjects = collect($data['subjects'])->keyBy('subject');
    $owned = $subjects['Arabic Language']['criteria'][0]['id']; $closed = $subjects['Science']['criteria'][0]['id'];
    $this->putJson($this->url, ['teacher_comment' => 'Changed', 'marks' => [['id' => $owned, 'level' => 3, 'comment' => 'Changed'], ['id' => $closed, 'level' => 2, 'comment' => 'Changed']]])->assertForbidden()->assertJsonPath('message', 'You teach Arabic in this class. Science is filled in by its own teacher.');
    expect(ReportCardMark::find($owned)->level)->toBeNull()->and(ReportCardMark::find($closed)->comment)->toBeNull()->and(ReportCard::find($data['id'])->teacher_comment)->toBeNull();
    $this->putJson($this->url, ['teacher_comment' => 'Changed', 'marks' => [['id' => $owned, 'level' => 3], ['id' => $subjects['Mathematics']['criteria'][0]['id'], 'level' => 2], ['id' => $data['learning_behaviours'][0]['id'], 'level' => 4]]])->assertOk();
    $this->postJson(str_replace('/report-card?', '/report-card/publish?', $this->url))->assertOk();
    $this->deleteJson(str_replace('/report-card?', '/report-card/publish?', $this->url))->assertOk();
});

it('keeps hidden subjects closed even to their limited teacher and opens every line to an unlimited teacher and office', function () {
    ($this->enableSummary)(); $this->arabic->update(['hidden_at' => now()]);
    $sub = collect($this->getJson($this->url)->assertOk()->json('data.subjects'))->firstWhere('subject', 'Arabic Language');
    expect($sub)->not->toHaveKey('work_summary')->and($sub['can_fill'])->toBeFalse();
    $row = ['id' => $sub['criteria'][0]['id'], 'level' => 4, 'comment' => 'Changed'];
    $this->putJson($this->url, ['marks' => [$row]])->assertForbidden();
    GroupStaff::withOfficeSubjectChoice(fn () => $this->staff->fresh()->update(['class_subject_ids' => null]));
    $this->putJson($this->url, ['marks' => [$row]])->assertOk();
    Sanctum::actingAs($this->office, ['staff']);
    expect(app(\App\Services\Schools\ReportCardService::class)->saveMarks(ReportCard::first(), [$row]))->toBeTrue();
});

it('counts withdrawn students saved work and excludes other children and classes', function () {
    ($this->enableSummary)(); ($this->markPiece)($this->arabic);
    $data = $this->getJson($this->url)->assertOk()->json('data');
    $this->student->update(['left_on' => '2026-10-08']);
    $another = GroupMembership::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'contact_id' => Contact::factory()->create(['masjid_id' => $this->org->id])->id, 'role' => 'member']);
    $piece = SubjectPiece::first(); SubjectPieceMark::create(['masjid_id' => $this->org->id, 'subject_piece_id' => $piece->id, 'group_membership_id' => $another->id, 'level' => 4]);
    $sub = collect($this->getJson($this->url)->assertOk()->json('data.subjects'))->firstWhere('subject', 'Arabic Language');
    expect($sub['work_summary']['counts'])->toBe(['4' => 1, '3' => 0, '2' => 0, '1' => 0]);
});

it('uses one grouped marks read and fixed whole card queries for 1 and 8 subjects and 0 and 200 marks', function () {
    ($this->enableSummary)();
    ClassSubject::where('id', '<>', $this->arabic->id)->delete();
    $card = ReportCard::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'group_membership_id' => $this->student->id, 'school_year' => '2026-2027', 'term' => 1, 'type' => 'report_card', 'grade_label' => '1st']);
    $card->forceFill(['published_at' => now()])->save();
    $counts = [];
    foreach ([[1, 0], [8, 0], [1, 200], [8, 200]] as [$subjects, $marks]) {
        for ($i = ClassSubject::count(); $i < $subjects; $i++) ClassSubject::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'name' => 'Practice '.$i]);
        ReportCardMark::where('report_card_id', $card->id)->delete();
        foreach (ClassSubject::limit($subjects)->get() as $subject) ReportCardMark::create(['masjid_id' => $this->org->id, 'report_card_id' => $card->id, 'kind' => 'academic', 'subject' => $subject->name, 'criterion' => 'Practice']);
        for ($i = SubjectPieceMark::count(); $i < $marks; $i++) ($this->markPiece)($this->arabic);
        app('auth')->forgetGuards(); Sanctum::actingAs($this->teacher->fresh(), ['staff']);
        DB::flushQueryLog(); DB::enableQueryLog(); $this->getJson($this->url)->assertOk(); $queries = DB::getQueryLog(); DB::disableQueryLog();
        $counts[] = count($queries);
        $grouped = array_filter($queries, fn ($q) => str_contains($q['query'], 'subject_piece_marks') && str_contains($q['query'], 'group by'));
        expect(count($grouped))->toBe(1);
    }
    expect(array_unique($counts))->toHaveCount(1);
    file_put_contents(base_path('artifacts/subject-report-summary-query-counts.json'), json_encode($counts));
});

it('refuses enabling the summary without subject work and stays dark by default', function () {
    $org = $this->org->fresh(); $org->forceFill(['capability_overrides' => array_replace($org->capability_overrides, ['class_subject_work' => false])])->save();
    expect(fn () => ($this->enableSummary)())->toThrow(\Illuminate\Validation\ValidationException::class, 'Switch on subject notes and marks before enabling report-card subject summaries.');
    expect($org->fresh()->getCapabilitiesAttribute())->not->toHaveKey('class_subject_report_summary');
});

it('summary OFF pins literal base SQL and payloads for list open save office serialization family and PDF data', function () {
    fake()->seed(7419);
    $card = $this->getJson($this->url)->assertOk()->json('data');
    $model = ReportCard::find($card['id']);
    // Publication makes family data readable and avoids changing baseline timestamps.
    $model->forceFill(['published_at' => now()])->save();
    $parent = Contact::factory()->create(['masjid_id' => $this->org->id, 'email' => 'parent@example.invalid', 'login_enabled_at' => now()]);
    GroupMembership::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'contact_id' => $parent->id, 'role' => 'guardian', 'guardian_of_contact_id' => $this->student->contact_id]);
    $actual = [];
    $take = function ($key, $read) use (&$actual) {
        DB::flushQueryLog(); DB::enableQueryLog();
        $payload = $read();
        $actual[$key] = ['sql' => array_column(DB::getQueryLog(), 'query'), 'payload' => json_decode(json_encode($payload), true)];
        DB::disableQueryLog();
    };
    $take('teacher-list', fn () => $this->getJson(str_replace('/members/'.$this->student->id.'/report-card?', '/report-cards?', $this->url))->assertOk()->json());
    $take('teacher-open', fn () => $this->getJson($this->url)->assertOk()->json());
    $model->forceFill(['published_at' => null])->save();
    $take('teacher-save', fn () => $this->putJson($this->url, ['marks' => [['id' => $card['subjects'][0]['criteria'][0]['id'], 'level' => 3, 'comment' => 'Practice']], 'teacher_comment' => 'Practice'])->assertOk()->json());
    Sanctum::actingAs($this->office, ['staff']);
    // Base has no office route: pin its existing staff serializer under an office principal.
    request()->attributes->set(\App\Support\ClassSubjectMode::HTTP, true);
    \App\Support\ClassSubjectMode::rememberLoaded($this->org->fresh());
    $take('office-card-serializer', fn () => (new ReflectionMethod(\App\Http\Controllers\Teacher\ReportCardController::class, 'card'))->invoke(app(\App\Http\Controllers\Teacher\ReportCardController::class), $model->fresh(), $this->student->load('contact')));
    \App\Support\ClassSubjectMode::clear(request());
    $model->forceFill(['published_at' => now()])->save();
    app('auth')->forgetGuards();
    $token = $parent->createToken('family', ['family'])->plainTextToken;
    $this->withToken($token);
    $familyUrl = "/api/family/masjids/{$this->org->id}/groups/{$this->group->id}/members/{$this->student->id}/report-cards/{$model->id}";
    $take('family-published', fn () => $this->getJson($familyUrl)->assertOk()->json());
    $take('pdf-data', fn () => (new ReflectionMethod(\App\Services\Schools\ReportCardPdfService::class, 'data'))->invoke(app(\App\Services\Schools\ReportCardPdfService::class), $model->fresh(), $this->student->fresh()->load('contact')));
    expect($actual)->toBe(json_decode(file_get_contents(base_path('tests/fixtures/subject-report-summary-off-8b6d8f09.json')), true));
});

it('serves no summary or permission metadata to family or PDF with summary ON', function () {
    ($this->enableSummary)(); ($this->markPiece)($this->arabic);
    $id = $this->getJson($this->url)->assertOk()->json('data.id');
    $card = ReportCard::find($id); $card->forceFill(['published_at' => now()])->save();
    $parent = Contact::factory()->create(['masjid_id' => $this->org->id, 'email' => 'parent@example.invalid', 'login_enabled_at' => now()]);
    GroupMembership::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'contact_id' => $parent->id, 'role' => 'guardian', 'guardian_of_contact_id' => $this->student->contact_id]);
    app('auth')->forgetGuards(); app(TenantContext::class)->forgetTenant(); $this->withToken($parent->createFamilyToken()->plainTextToken);
    DB::flushQueryLog(); DB::enableQueryLog();
    $family = $this->getJson("/api/family/masjids/{$this->org->id}/groups/{$this->group->id}/members/{$this->student->id}/report-cards/{$id}")->assertOk()->json();
    $pdf = (new ReflectionMethod(\App\Services\Schools\ReportCardPdfService::class, 'data'))->invoke(app(\App\Services\Schools\ReportCardPdfService::class), $card->fresh(), $this->student->fresh()->load('contact'));
    foreach ([$family, $pdf] as $data) expect(json_encode($data))->not->toContain('work_summary', 'can_fill', 'can_open', 'Nothing marked yet', 'This term in', 'So far this year in');
    expect(collect(DB::getQueryLog())->pluck('query')->implode(' '))->not->toContain('subject_piece_marks');
    DB::disableQueryLog();
});

it('does not use previous names or curriculum links and falls back without a dated term link', function () {
    ($this->enableSummary)(); ($this->markPiece)($this->arabic);
    $this->arabic->update(['name' => 'Language practice', 'guide_subject' => 'Arabic Language']);
    $this->org->refresh()->forceFill(['capability_overrides' => array_replace($this->org->capability_overrides, ['school_calendar_terms' => true])])->save();
    $sub = collect($this->getJson($this->url)->assertOk()->json('data.subjects'))->firstWhere('subject', 'Arabic Language');
    expect($sub)->not->toHaveKey('work_summary')->and($sub['can_fill'])->toBeTrue();
    $science = collect($this->getJson($this->url)->assertOk()->json('data.subjects'))->firstWhere('subject', 'Science');
    expect($science['work_summary']['heading'])->toBe('So far this year in Science')->and($science['work_summary']['counts'])->toBe(['4' => 0, '3' => 0, '2' => 0, '1' => 0]);
});

it('makes empty ID assignments restrictive and rejects comment only writes to closed lines', function () {
    ($this->enableSummary)(); $this->staff->fresh()->update(['class_subject_ids' => []]);
    $data = $this->getJson($this->url)->assertOk()->json('data');
    foreach ($data['subjects'] as $sub) {
        if (! isset($sub['work_summary'])) continue;
        expect($sub['can_fill'])->toBeFalse()->and($sub['work_summary']['can_open'])->toBeFalse();
        $this->putJson($this->url, ['marks' => [['id' => $sub['criteria'][0]['id'], 'comment' => 'Changed']]])->assertForbidden();
        expect(ReportCardMark::find($sub['criteria'][0]['id'])->comment)->toBeNull();
    }
    $this->putJson($this->url, ['teacher_comment' => 'Shared overall comment', 'marks' => [['id' => $data['learning_behaviours'][0]['id'], 'level' => 2]]])->assertOk();
});

it('keeps both audited writers atomic and hides dormant summary overrides catalogue panel and history', function () {
    Sanctum::actingAs($this->office);
    $url = "/api/admin/masjids/{$this->org->id}/capabilities";
    CapabilityWriter::apply($this->org->fresh(), ['class_subject_report_summary' => false], $this->office->id);
    expect(\App\Models\MasjidCapabilityChange::where('capability', 'class_subject_report_summary')->count())->toBe(0);
    expect($this->getJson($url)->assertOk()->getContent())->not->toContain('class_subject_report_summary');
    $this->patchJson($url, ['capabilities' => ['class_subject_work' => false, 'class_subject_report_summary' => true]])->assertUnprocessable()->assertJsonPath('data.capability.0', 'Switch on subject notes and marks before enabling report-card subject summaries.');
    expect($this->org->fresh()->hasCapability('class_subject_work'))->toBeTrue();
    $this->patchJson($url, ['capabilities' => ['school_calendar_terms' => false, 'class_subject_work' => false, 'class_subject_report_summary' => true]])->assertUnprocessable();
    expect($this->org->fresh()->hasCapability('class_subject_work'))->toBeTrue();
    $this->patchJson($url.'/class_subject_report_summary', ['enabled' => '1'])->assertOk();
    expect($this->getJson($url)->assertOk()->getContent())->toContain('class_subject_report_summary');
    $this->patchJson($url.'/class_subject_report_summary', ['enabled' => '0'])->assertOk();
    expect($this->getJson($url)->assertOk()->getContent())->not->toContain('class_subject_report_summary');
    expect(json_encode($this->org->fresh()->attributesToArray()))->not->toContain('class_subject_report_summary');
    expect($this->getJson('/api/admin/studio/catalogue?org_type=school')->assertOk()->getContent())->not->toContain('class_subject_report_summary');
});

it('keeps every line open to an unlimited teacher and an office principal', function () {
    ($this->enableSummary)(); ($this->markPiece)($this->science);
    GroupStaff::withOfficeSubjectChoice(fn () => $this->staff->fresh()->update(['class_subject_ids' => null]));
    $data = $this->getJson($this->url)->assertOk()->json('data');
    $rows = [];
    foreach ($data['subjects'] as $subject) {
        expect($subject['can_fill'])->toBeTrue();
        if (isset($subject['work_summary'])) expect($subject['work_summary']['can_open'])->toBeTrue();
        foreach ($subject['criteria'] as $line) $rows[] = ['id' => $line['id'], 'level' => 4, 'comment' => 'Unlimited teacher'];
    }
    $this->putJson($this->url, ['marks' => $rows])->assertOk();
    $this->staff->fresh()->update(['class_subject_ids' => []]);
    Sanctum::actingAs($this->office, ['staff']);
    $card = ReportCard::find($data['id']);
    expect(app(\App\Services\Schools\ReportCardService::class)->saveMarks($card, array_map(fn ($r) => array_replace($r, ['level' => 2, 'comment' => 'Office']), $rows), true, 'Office comment'))->toBeTrue();
    expect($card->marks()->pluck('level')->unique()->values()->all())->toBe([2, null]);
    $reference = \App\Support\ReportCardSubjectWork::forCard($card, $card->marks()->get(), $this->office);
    foreach ($reference as $subject) {
        expect($subject['can_fill'])->toBeTrue();
        if (isset($subject['work_summary'])) expect($subject['work_summary']['can_open'])->toBeTrue();
    }
});

it('rolls back preparation as well as marks on a refused save', function () {
    ($this->enableSummary)();
    $data = $this->getJson($this->url)->assertOk()->json('data');
    $closed = collect($data['subjects'])->firstWhere('subject', 'Science')['criteria'][0]['id'];
    ReportCardMark::where('subject', 'Arabic Language')->delete();
    $before = DB::table('report_card_marks')->orderBy('id')->get()->toJson();
    $this->travelTo(\Carbon\Carbon::parse('2026-10-10 16:00:00', 'UTC'));
    $this->putJson($this->url, ['teacher_comment' => 'Changed', 'marks' => [['id' => $closed, 'level' => 2]]])->assertForbidden();
    expect(DB::table('report_card_marks')->orderBy('id')->get()->toJson())->toBe($before);
});

it('office reads the same references through read only routes while OFF remains a 404', function () {
    $id = $this->getJson($this->url)->assertOk()->json('data.id');
    $url = str_replace('/api/teacher/', '/api/admin/', $this->url);
    Sanctum::actingAs($this->office);
    $this->getJson($url)->assertNotFound();
    ($this->enableSummary)(); ($this->markPiece)($this->science);
    $before = DB::table('report_card_marks')->get()->toJson();
    $data = $this->getJson($url)->assertOk()->json('data');
    $science = collect($data['subjects'])->firstWhere('subject', 'Science');
    expect($science['work_summary']['counts']['4'])->toBe(1)->and($science['work_summary']['can_open'])->toBeTrue()->and($science['can_fill'])->toBeTrue();
    expect(DB::table('report_card_marks')->get()->toJson())->toBe($before);
    $this->getJson(str_replace('/members/'.$this->student->id.'/report-card?', '/report-cards?', $url))->assertOk()->assertJsonPath('data.students.0.report_card_id', $id);
    foreach (['PUT', 'POST', 'DELETE'] as $verb) $this->json($verb, $url, ['marks' => []])->assertStatus(405);
});

it('returns the subject refusal sentence with production debug disabled', function () {
    ($this->enableSummary)(); $data = $this->getJson($this->url)->assertOk()->json('data');
    $closed = collect($data['subjects'])->firstWhere('subject', 'Science')['criteria'][0]['id'];
    config(['app.debug' => false]);
    $this->putJson($this->url, ['marks' => [['id' => $closed, 'level' => 2]]])->assertForbidden()->assertJsonPath('message', 'You teach Arabic in this class. Science is filled in by its own teacher.');
});

it('reuses the memo without a switch query while work is ON and summary OFF', function () {
    foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
        if ($route->getActionName() === \App\Http\Controllers\Teacher\ReportCardController::class.'@show') $route->middleware(ReportSummaryOffMemoProbe::class);
    }
    DB::flushQueryLog(); DB::enableQueryLog(); $this->getJson($this->url)->assertOk(); DB::disableQueryLog();
});

it('isolates reference counts and office reads from another class and school', function () {
    ($this->enableSummary)(); ($this->markPiece)($this->arabic);
    $otherClass = Group::factory()->create(['masjid_id' => $this->org->id, 'kind' => 'class']);
    $otherSubject = ClassSubject::where('group_id', $otherClass->id)->where('name', 'Arabic')->firstOrFail();
    ($this->markPiece)($otherSubject);
    $anotherOrg = Masjid::create(['name' => 'Other Practice School', 'email' => 'other@example.invalid', 'phone' => '+15555550103', 'country_id' => '1', 'city_id' => '1', 'address' => 'Practice', 'latitude' => 0, 'longitude' => 0, 'org_type' => 'school', 'crm_enabled' => true]);
    $foreignClass = Group::factory()->create(['masjid_id' => $anotherOrg->id, 'kind' => 'class']);
    $foreignStudent = GroupMembership::create(['masjid_id' => $anotherOrg->id, 'group_id' => $foreignClass->id, 'role' => 'member', 'contact_id' => Contact::factory()->create(['masjid_id' => $anotherOrg->id])->id]);
    $sub = collect($this->getJson($this->url)->assertOk()->json('data.subjects'))->firstWhere('subject', 'Arabic Language');
    expect($sub['work_summary']['counts']['4'])->toBe(1);
    Sanctum::actingAs($this->office);
    $base = "/api/admin/masjids/{$this->org->id}/groups";
    $this->getJson($base."/{$foreignClass->id}/report-cards")->assertNotFound();
    $this->getJson($base."/{$this->group->id}/members/{$foreignStudent->id}/report-card")->assertNotFound();
    $this->getJson($base."/{$this->group->id}/members/{$this->student->id}/report-card?school_year=2024-2025&term=1")->assertNotFound();
    expect(ReportCard::count())->toBe(1);
});

it('review refuses the office reads for a group that is not a class', function () {
    ($this->enableSummary)();
    $this->getJson($this->url)->assertOk();
    $general = Group::factory()->create(['masjid_id' => $this->org->id, 'kind' => 'general']);
    $contact = Contact::factory()->create(['masjid_id' => $this->org->id]);
    $member = GroupMembership::create(['masjid_id' => $this->org->id, 'group_id' => $general->id, 'contact_id' => $contact->id, 'role' => 'member']);
    app(TenantContext::class)->forgetTenant(); app('auth')->forgetGuards();
    Sanctum::actingAs($this->office);
    $base = "/api/admin/masjids/{$this->org->id}/groups/{$general->id}";
    $this->getJson($base.'/report-cards')->assertNotFound();
    $this->getJson($base.'/members/'.$member->id.'/report-card')->assertNotFound();
});
