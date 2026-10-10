<?php

use App\Models\{ClassSubject, Contact, CurriculumWeek, Group, GroupMembership, GroupStaff, LessonPlan, Masjid, MasjidUser, SchoolSubject, SubjectNote, SubjectPiece, SubjectPieceMark, User};
use App\Support\{CapabilityCatalogue, CapabilityWriter, ClassSubjectInitializer, PerformanceLevel, SchoolSettings, TenantContext};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Schema};
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    $this->org = Masjid::create(['name' => 'Practice School', 'email' => uniqid().'@example.invalid', 'phone' => '+15555550100', 'country_id' => '1', 'city_id' => '1', 'address' => 'Practice', 'latitude' => 0, 'longitude' => 0, 'org_type' => 'school', 'crm_enabled' => true]);
    $this->group = Group::factory()->create(['masjid_id' => $this->org->id, 'kind' => 'class']);
    $this->teacher = User::factory()->create(['type' => 'Teacher', 'name' => 'Practice Teacher', 'phone' => '+15555550101']);
    $this->office = User::factory()->create(['type' => 'SuperAdmin', 'name' => 'Practice Office', 'phone' => '+15555550102']);
    MasjidUser::create(['masjid_id' => $this->org->id, 'user_id' => $this->teacher->id, 'role' => 'teacher', 'is_default' => true]);
    $this->staff = GroupStaff::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'user_id' => $this->teacher->id, 'subjects' => null]);
    $this->student = function (string $grade = '1st', ?Group $group = null) {
        $group ??= $this->group;
        $contact = Contact::factory()->create(['masjid_id' => $group->masjid_id, 'first_name' => 'Practice', 'last_name' => 'Student']);
        return GroupMembership::create(['masjid_id' => $group->masjid_id, 'group_id' => $group->id, 'contact_id' => $contact->id, 'role' => 'member', 'grade_label' => $grade]);
    };
    $this->one = ($this->student)();
    $this->two = ($this->student)('Grade 2');
    foreach (['Science', 'Arabic'] as $name) SchoolSubject::create(['masjid_id' => $this->org->id, 'name' => $name]);
    $this->guide = function (int $week = 4, string $grade = 'Grade 1', string $subject = 'Science') {
        return CurriculumWeek::create(['masjid_id' => $this->org->id, 'grade_label' => $grade, 'subject' => $subject, 'week_no' => $week, 'quarter' => null, 'focus' => 'Practice focus '.$week, 'standard_code' => 'TEST.1', 'assessment_note' => 'Practice assessment']);
    };
    $this->entry = ($this->guide)();
    ($this->guide)(15, '2nd');
    ClassSubjectInitializer::run($this->org, false, true);
    $this->subject = ClassSubject::where('group_id', $this->group->id)->where('name', 'Science')->firstOrFail();
    $this->other = ClassSubject::where('group_id', $this->group->id)->where('name', 'Arabic')->firstOrFail();
    // The missing grant is deliberately stored directly to make pre-code tests red at the routes.
    $org = $this->org->fresh();
    $org->forceFill(['capability_overrides' => array_merge($org->capability_overrides, ['class_subject_work' => true])])->save();
    $this->base = "/api/teacher/masjids/{$org->id}/groups/{$this->group->id}/subjects/{$this->subject->id}";
    $this->plan = fn ($subject = null, $date = '2026-10-09') => LessonPlan::create(['masjid_id' => $org->id, 'group_id' => $this->group->id, 'class_subject_id' => ($subject ?? $this->subject)->id, 'subject' => ($subject ?? $this->subject)->name, 'session_date' => $date, 'objective' => 'Practice objective', 'title' => 'Practice title', 'body' => 'Practice activities']);
    // Existing ON callers load the mark versions before submitting; explicit versions stay untouched.
    $this->putMarks = function (string $url, array $payload) {
        $piece = isset($payload['piece_id']) ? SubjectPiece::find($payload['piece_id']) : SubjectPiece::where('class_subject_id', $this->subject->id)->where('source', $payload['source'])
            ->when($payload['source'] === 'guide', fn ($q) => $q->where('guide_subject', $payload['guide_subject'] ?? 'Science')->where('week_no', $payload['week_no'] ?? 4))
            ->when($payload['source'] === 'plan', fn ($q) => $q->where('lesson_plan_id', $payload['lesson_plan_id'] ?? null))->first();
        $versions = $piece ? SubjectPieceMark::where('subject_piece_id', $piece->id)->get()->keyBy('group_membership_id') : collect();
        foreach ($payload['marks'] as &$mark) {
            if (! array_key_exists('updated_at', $mark)) $mark['updated_at'] = $versions->get($mark['group_membership_id'])?->updated_at?->toISOString();
        }
        unset($mark);
        return $this->putJson($url, $payload);
    };
    $this->saveGuide = fn (array $marks, int $week = 4, string $grade = '1st') => ($this->putMarks)($this->base.'/marks', ['source' => 'guide', 'grade_label' => $grade, 'week_no' => $week, 'marks' => $marks]);
    $this->mark = fn ($member = null, $level = 3, $comment = null) => ['group_membership_id' => ($member ?? $this->one)->id, 'level' => $level, 'comment' => $comment];
    Sanctum::actingAs($this->teacher, ['staff']);
});

afterEach(fn () => app(TenantContext::class)->forgetTenant());

it('walk 12 writes lists edits and deletes student notes and whole class updates', function () {
    $student = $this->postJson($this->base.'/notes', ['group_membership_id' => $this->one->id, 'body' => 'Practice note'])->assertCreated()->json('data.id');
    $whole = $this->postJson($this->base.'/notes', ['body' => 'Practice update'])->assertCreated()->json('data.id');
    $this->getJson($this->base.'/notes')->assertOk()->assertJsonPath('data.0.id', $whole)->assertJsonPath('data.0.student_name', 'Whole class')->assertJsonPath('data.1.student_name', 'Practice Student')->assertJsonPath('data.0.author_name', 'Practice Teacher')->assertJsonStructure(['data' => [['created_at', 'updated_at']]]);
    $this->putJson($this->base.'/notes/'.$student, ['body' => 'Corrected'])->assertOk();
    $this->getJson($this->base.'/notes')->assertJsonPath('data.1.body', 'Corrected');
    $this->deleteJson($this->base.'/notes/'.$student)->assertOk();
    $this->deleteJson($this->base.'/notes/'.$whole)->assertOk();
    $this->getJson($this->base.'/notes')->assertJsonCount(0, 'data');
});

it('walk 13 fences every note verb by subject ID for limited teachers', function () {
    $note = $this->postJson($this->base.'/notes', ['body' => 'Private'])->assertCreated()->json('data.id');
    GroupStaff::withOfficeSubjectChoice(fn () => $this->staff->fresh()->update(['class_subject_ids' => [$this->other->id]]));
    $this->getJson($this->base.'/notes')->assertForbidden();
    $this->postJson($this->base.'/notes', ['body' => 'Wrong'])->assertForbidden();
    $this->putJson($this->base.'/notes/'.$note, ['body' => 'Wrong'])->assertForbidden();
    $this->deleteJson($this->base.'/notes/'.$note)->assertForbidden();
    expect(SubjectNote::findOrFail($note)->body)->toBe('Private');
});

it('walk 14 office reads the same subject notes and has no work writes', function () {
    $this->postJson($this->base.'/notes', ['body' => 'Practice'])->assertCreated();
    $expected = $this->getJson($this->base.'/notes')->assertOk()->json();
    Sanctum::actingAs($this->office);
    $url = str_replace('/teacher/', '/admin/', $this->base);
    expect($this->getJson($url.'/notes')->assertOk()->json())->toBe($expected);
    foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
        if (str_starts_with($route->uri(), 'api/admin') && str_contains($route->getActionName(), 'SubjectWorkController')) expect(array_diff($route->methods(), ['GET', 'HEAD']))->toBe([]);
    }
});

it('walk 15 groups guide entries by grade keys with only current students of that grade', function () {
    ($this->guide)(1); ($this->guide)(9, 'Grade 3');
    $data = $this->getJson($this->base.'/work')->assertOk()->json('data');
    expect($data['levels'])->toBe(PerformanceLevel::key());
    expect(array_column($data['curriculum'][0]['students'], 'id'))->toBe([$this->one->id]);
    expect(array_column($data['curriculum'][1]['students'], 'id'))->toBe([$this->two->id]);
    expect(array_column($data['curriculum'][0]['entries'], 'week_no'))->toBe([1, 4]);
    expect($data['curriculum'][0]['opening_week_no'])->toBe(1);
    $this->getJson($this->base.'/work?grade_label=1st&week_no=4')->assertOk()->assertJsonPath('data.curriculum.0.selected_week_no', 4);
    $this->getJson($this->base.'/work?grade_label=1st&week_no=99')->assertUnprocessable();
    $this->subject->update(['guide_subject' => null]);
    $this->getJson($this->base.'/work')->assertOk()->assertJsonCount(0, 'data.curriculum');
});

it('walk 16 shows only linked plans newest first with date and objective fallbacks', function () {
    $first = ($this->plan)(null, '2026-10-08');
    $new = ($this->plan)(); $new->update(['objective' => null]);
    ($this->plan)($this->other);
    $this->getJson($this->base.'/work')->assertOk()->assertJsonCount(2, 'data.lesson_plans')->assertJsonPath('data.lesson_plans.0.title', '2026-10-09: Practice title')->assertJsonPath('data.lesson_plans.1.title', '2026-10-08: Practice objective');
    $new->update(['title' => null]);
    $this->getJson($this->base.'/work')->assertJsonPath('data.lesson_plans.0.title', '2026-10-09: Practice activities');
    expect(SubjectPiece::count())->toBe(0);
});

it('walk 17 creates edits and deletes own pieces with an exact mark confirmation count', function () {
    $this->postJson($this->base.'/pieces', ['title' => ''])->assertUnprocessable();
    $piece = $this->postJson($this->base.'/pieces', ['title' => 'Practice piece', 'detail' => 'Practice detail'])->assertCreated()->json('data.id');
    $this->putJson($this->base.'/pieces/'.$piece, ['title' => 'Corrected', 'detail' => null])->assertOk();
    ($this->putMarks)($this->base.'/marks', ['source' => 'own', 'piece_id' => $piece, 'marks' => [($this->mark)()]])->assertOk();
    $this->getJson($this->base.'/work')->assertJsonPath('data.own_pieces.0.title', 'Corrected')->assertJsonPath('data.own_pieces.0.mark_count', 1);
    $this->deleteJson($this->base.'/pieces/'.$piece, ['mark_count' => 0])->assertStatus(409)->assertJsonPath('mark_count', 1);
    expect(SubjectPiece::count())->toBe(1);
    $this->deleteJson($this->base.'/pieces/'.$piece, ['mark_count' => 1])->assertOk();
    expect(SubjectPiece::count())->toBe(0); expect(SubjectPieceMark::count())->toBe(0);
});

it('walk 18 saves every level and comment updates clears and never invents an unmarked row', function () {
    foreach ([4, 3, 2, 1] as $level) {
        ($this->saveGuide)([($this->mark)(null, $level, 'Practice comment')])->assertOk();
        $this->getJson($this->base.'/work')->assertJsonPath('data.curriculum.0.entries.0.marks.0.level', $level)->assertJsonPath('data.curriculum.0.entries.0.marks.0.comment', 'Practice comment');
    }
    expect(SubjectPieceMark::count())->toBe(1);
    ($this->saveGuide)([($this->mark)(null, null, 'Comment only')])->assertOk();
    expect(SubjectPieceMark::first()->level)->toBeNull();
    ($this->saveGuide)([($this->mark)(null, null, '  ')])->assertOk();
    expect(SubjectPieceMark::count())->toBe(0);
    ($this->saveGuide)([($this->mark)(null, 0)])->assertUnprocessable();
    ($this->saveGuide)([($this->mark)(null, 5)])->assertUnprocessable();
});

it('walk 19 copies guide words on first meaningful save and keeps them after reimport', function () {
    ($this->saveGuide)([($this->mark)(null, null, null)])->assertOk();
    expect(SubjectPiece::count())->toBe(0);
    ($this->saveGuide)([($this->mark)()])->assertOk();
    $piece = SubjectPiece::firstOrFail();
    expect($piece->title)->toBe('Practice focus 4'); expect($piece->detail)->toBe('Practice assessment'); expect($piece->standard_code)->toBe('TEST.1'); expect($piece->quarter)->toBeNull(); expect($piece->week_no)->toBe(4);
    $this->entry->update(['focus' => 'New focus', 'assessment_note' => 'New assessment', 'standard_code' => 'TEST.2', 'grade_label' => 'first']);
    $this->getJson($this->base.'/work')->assertJsonPath('data.curriculum.0.entries.0.title', 'Practice focus 4')->assertJsonPath('data.curriculum.0.entries.0.detail', 'Practice assessment')->assertJsonPath('data.curriculum.0.entries.0.standard_code', 'TEST.1');
    ($this->saveGuide)([($this->mark)(null, 4)])->assertOk();
    expect(SubjectPiece::count())->toBe(1);
});

it('copies plan words and retains marked pieces after plan deletion', function () {
    $plan = ($this->plan)();
    $save = fn () => ($this->putMarks)($this->base.'/marks', ['source' => 'plan', 'lesson_plan_id' => $plan->id, 'marks' => [($this->mark)()]]);
    $save()->assertOk(); $plan->update(['objective' => 'New objective']);
    $this->getJson($this->base.'/work')->assertJsonPath('data.lesson_plans.0.title', '2026-10-09: Practice objective');
    $plan->delete();
    expect(SubjectPiece::first()->lesson_plan_id)->toBeNull();
    $this->getJson($this->base.'/work')->assertJsonPath('data.lesson_plans.0.title', '2026-10-09: Practice objective');
    ($this->putMarks)($this->base.'/marks', ['source' => 'plan', 'piece_id' => SubjectPiece::first()->id, 'marks' => [($this->mark)(null, 2)]])->assertOk();
});

it('opens on the most recently marked entry never on a calendar-derived number', function () {
    ($this->guide)(1); ($this->guide)(12);
    ($this->saveGuide)([($this->mark)()], 12)->assertOk();
    $this->travel(2)->minutes();
    ($this->saveGuide)([($this->mark)()], 4)->assertOk();
    $this->getJson($this->base.'/work')->assertJsonPath('data.curriculum.0.opening_week_no', 4)->assertJsonPath('data.curriculum.1.opening_week_no', 15);
});

it('keeps withdrawn history listed but refuses new notes and all mark edits for withdrawn students', function () {
    $this->postJson($this->base.'/notes', ['group_membership_id' => $this->one->id, 'body' => 'Kept'])->assertCreated();
    ($this->saveGuide)([($this->mark)()])->assertOk();
    DB::table('group_memberships')->where('id', $this->one->id)->update(['left_on' => '2026-10-09']);
    $this->getJson($this->base.'/notes')->assertJsonPath('data.0.body', 'Kept')->assertJsonPath('data.0.student_name', 'Practice Student');
    $this->postJson($this->base.'/notes', ['group_membership_id' => $this->one->id, 'body' => 'Wrong'])->assertUnprocessable();
    ($this->saveGuide)([($this->mark)()])->assertUnprocessable();
    expect(SubjectPieceMark::count())->toBe(1);
    $this->getJson($this->base.'/work')->assertJsonCount(1, 'data.students')->assertJsonCount(1, 'data.curriculum');
});

it('refuses wrong grade wrong class guardian and duplicate membership rows atomically', function () {
    ($this->saveGuide)([($this->mark)(), ($this->mark)($this->two)])->assertUnprocessable();
    expect(SubjectPiece::count())->toBe(0);
    ($this->saveGuide)([($this->mark)(), ($this->mark)()])->assertUnprocessable();
    $group = Group::factory()->create(['masjid_id' => $this->org->id]);
    $member = ($this->student)('1st', $group);
    ($this->saveGuide)([($this->mark)($member)])->assertNotFound();
    $this->postJson($this->base.'/notes', ['group_membership_id' => $member->id, 'body' => 'Wrong'])->assertNotFound();
    DB::table('group_memberships')->where('id', $this->one->id)->update(['role' => 'guardian']);
    $this->postJson($this->base.'/notes', ['group_membership_id' => $this->one->id, 'body' => 'Wrong'])->assertUnprocessable();
});

it('recovers a competing first guide insert through its unique key', function () {
    $injected = false;
    DB::listen(function ($query) use (&$injected) {
        if ($injected || ! str_contains($query->sql, 'from "curriculum_weeks"') || ! str_contains($query->sql, '"week_no" =')) return;
        $injected = true;
        // Simulate a competing commit after our lookup but before the insertion's
        // savepoint. A duplicate rollback cannot roll back this winner as well.
        DB::table('subject_pieces')->insert(['masjid_id' => $this->org->id,
            'class_subject_id' => $this->subject->id, 'source' => 'guide', 'guide_subject' => 'Science', 'title' => 'Winning snapshot',
            'grade_label' => 'Grade 1', 'week_no' => 4, 'created_at' => now(), 'updated_at' => now()]);
    });
    ($this->saveGuide)([($this->mark)()])->assertOk();
    expect($injected)->toBeTrue(); expect(SubjectPiece::count())->toBe(1); expect(SubjectPieceMark::count())->toBe(1);
    expect(SubjectPiece::first()->title)->toBe('Winning snapshot');
});

it('refuses sharing at every new teacher write boundary', function (string $shape) {
    $piece = $this->postJson($this->base.'/pieces', ['title' => 'Practice'])->assertCreated()->json('data.id');
    $note = $this->postJson($this->base.'/notes', ['body' => 'Practice'])->assertCreated()->json('data.id');
    $response = match ($shape) {
        'note-create' => $this->postJson($this->base.'/notes', ['body' => 'Wrong', 'shared_with_family' => true]),
        'note-edit' => $this->putJson($this->base.'/notes/'.$note, ['body' => 'Wrong', 'shared_with_family' => 'true']),
        'note-delete' => $this->deleteJson($this->base.'/notes/'.$note, ['shared_with_family' => true]),
        'piece-create' => $this->postJson($this->base.'/pieces', ['title' => 'Wrong', 'shared_with_family' => true]),
        'piece-edit' => $this->putJson($this->base.'/pieces/'.$piece, ['title' => 'Wrong', 'shared_with_family' => true]),
        'piece-delete' => $this->deleteJson($this->base.'/pieces/'.$piece, ['mark_count' => 0, 'shared_with_family' => true]),
        'marks' => ($this->putMarks)($this->base.'/marks', ['source' => 'own', 'piece_id' => $piece, 'shared_with_family' => true, 'marks' => [($this->mark)()]]),
        'mark-row' => ($this->putMarks)($this->base.'/marks', ['source' => 'own', 'piece_id' => $piece, 'marks' => [array_merge(($this->mark)(), ['shared_with_family' => true])]]),
    };
    $response->assertUnprocessable();
    expect(SubjectPieceMark::count())->toBe(0);
    expect(SubjectNote::first()->body)->toBe('Practice');
})->with(['note-create', 'note-edit', 'note-delete', 'piece-create', 'piece-edit', 'piece-delete', 'marks', 'mark-row']);

it('gates every route while work is OFF even for the office and when class subjects is OFF', function (bool $subjectsOff) {
    $org = $this->org->fresh(); $overrides = $org->capability_overrides;
    $overrides[$subjectsOff ? 'class_subjects' : 'class_subject_work'] = false;
    $org->forceFill(['capability_overrides' => $overrides])->save();
    foreach (['/work', '/notes'] as $path) $this->getJson($this->base.$path)->assertNotFound();
    $this->postJson($this->base.'/notes', ['body' => 'Wrong'])->assertNotFound();
    $this->putJson($this->base.'/notes/999', ['body' => 'Wrong'])->assertNotFound();
    $this->deleteJson($this->base.'/notes/999')->assertNotFound();
    $this->postJson($this->base.'/pieces', ['title' => 'Wrong'])->assertNotFound();
    $this->putJson($this->base.'/pieces/999', ['title' => 'Wrong'])->assertNotFound();
    $this->deleteJson($this->base.'/pieces/999', ['mark_count' => 0])->assertNotFound();
    ($this->saveGuide)([($this->mark)()])->assertNotFound();
    Sanctum::actingAs($this->office);
    foreach (['/work', '/notes'] as $path) $this->getJson(str_replace('/teacher/', '/admin/', $this->base).$path)->assertNotFound();
})->with([false, true]);

it('fences hidden subjects and every piece and mark verb for limited teachers', function (bool $hidden) {
    $piece = $this->postJson($this->base.'/pieces', ['title' => 'Kept'])->assertCreated()->json('data.id');
    if ($hidden) $this->subject->update(['hidden_at' => now()]);
    else GroupStaff::withOfficeSubjectChoice(fn () => $this->staff->fresh()->update(['class_subject_ids' => [$this->other->id]]));
    $status = $hidden ? 404 : 403;
    $this->getJson($this->base.'/work')->assertStatus($status);
    $this->getJson($this->base.'/notes')->assertStatus($status);
    $this->postJson($this->base.'/notes', ['body' => 'Wrong'])->assertStatus($status);
    $this->postJson($this->base.'/pieces', ['title' => 'Wrong'])->assertStatus($status);
    $this->putJson($this->base.'/pieces/'.$piece, ['title' => 'Wrong'])->assertStatus($status);
    $this->deleteJson($this->base.'/pieces/'.$piece, ['mark_count' => 0])->assertStatus($status);
    ($this->saveGuide)([($this->mark)()])->assertStatus($status);
})->with([false, true]);

it('has a fixed page query count for 1 and 30 students and 1 and 40 guide entries', function () {
    DB::table('group_memberships')->where('id', $this->two->id)->delete();
    CurriculumWeek::where('grade_label', '2nd')->delete();
    $read = function () {
        $this->teacher->unsetRelations();
        Sanctum::actingAs($this->teacher, ['staff']);
        DB::flushQueryLog(); DB::enableQueryLog();
        $this->getJson($this->base.'/work')->assertOk();
        $queries = array_column(DB::getQueryLog(), 'query'); DB::disableQueryLog();
        return $queries;
    };
    $small = $read();
    for ($i = 0; $i < 29; $i++) ($this->student)();
    for ($i = 5; $i < 44; $i++) ($this->guide)($i);
    $large = $read();
    file_put_contents(base_path('artifacts/subject-work-query-debug.json'), json_encode(['small' => $small, 'large' => $large], JSON_PRETTY_PRINT));
    expect(count($small))->toBe(18);
    expect(count($large))->toBe(18);
    ($this->saveGuide)([($this->mark)()])->assertOk();
    expect(count($read()))->toBe(count($small));
    for ($i = 1; $i <= 40; $i++) ($this->guide)($i, 'Grade 1', 'Joint studies');
    $this->subject->update(['guide_subjects' => ['Science', 'Joint studies']]);
    expect(count($read()))->toBe(18);
    $this->getJson($this->base.'/work')->assertJsonCount(80, 'data.curriculum.0.entries');
    file_put_contents(base_path('artifacts/subject-work-query-count.json'), json_encode(['small' => count($small), 'large' => count($large), 'sql' => $large], JSON_PRETTY_PRINT)."\n");
});

it('office page matches the teacher payload', function () {
    ($this->plan)(); ($this->saveGuide)([($this->mark)()])->assertOk();
    $expected = $this->getJson($this->base.'/work')->assertOk()->json('data');
    Sanctum::actingAs($this->office);
    expect($this->getJson(str_replace('/teacher/', '/admin/', $this->base).'/work')->assertOk()->json('data'))->toBe($expected);
});

it('defines a hidden default OFF grant and refuses enabling without class subjects', function () {
    expect(config('capabilities.class_subject_work.kind'))->toBe('grant');
    expect(config('capabilities.class_subject_work.group'))->toBe('school');
    expect(config('capabilities.class_subject_work.defaults'))->toBe(['masjid' => false, 'school' => false, 'community' => false]);
    expect(config('capabilities.class_subject_work.listed_when_off'))->toBeFalse();
    foreach (Masjid::ORG_TYPES as $type) expect(collect(CapabilityCatalogue::forOrgType($type))->pluck('entries')->flatten(1)->pluck('key')->all())->not->toContain('class_subject_work');
    $org = Masjid::create(['name' => 'Other Practice', 'email' => uniqid().'@example.invalid', 'phone' => '+15555550108', 'country_id' => '1', 'city_id' => '1', 'address' => 'Practice', 'latitude' => 0, 'longitude' => 0, 'org_type' => 'school']);
    expect(SchoolSettings::classSubjectWork($org))->toBeFalse();
    expect(fn () => CapabilityWriter::apply($org, ['class_subject_work' => true], null))->toThrow(\Illuminate\Validation\ValidationException::class);
    expect(fn () => CapabilityWriter::apply($org, ['class_subject_work' => true, 'school_calendar_terms' => false], null))->toThrow(\Illuminate\Validation\ValidationException::class);
    expect(fn () => CapabilityWriter::applyAtCreation($org, ['class_subject_work' => true], null))->toThrow(\Illuminate\Validation\ValidationException::class);
    CapabilityWriter::apply($this->org->fresh(), ['class_subject_work' => false], null);
    $off = $this->org->fresh();
    expect($off->capabilities)->not->toHaveKey('class_subject_work');
    expect($off->append(Masjid::ADMIN_APPENDS)->toArray()['capability_overrides'])->not->toHaveKey('class_subject_work');
    Sanctum::actingAs($this->office);
    $keys = collect($this->getJson("/api/admin/masjids/{$off->id}/capabilities")->assertOk()->json('data.groups'))->pluck('entries')->flatten(1)->pluck('key')->all();
    expect($keys)->not->toContain('class_subject_work');
    CapabilityWriter::apply($off, ['class_subject_work' => true], null);
    expect(SchoolSettings::classSubjectWork($off->fresh()))->toBeTrue();
});

it('disable retains notes pieces marks and reports the counts in its dry run', function () {
    $this->postJson($this->base.'/notes', ['body' => 'Kept'])->assertCreated();
    ($this->saveGuide)([($this->mark)()])->assertOk();
    $this->artisan('class-subjects:disable', ['--masjid' => $this->org->id, '--dry-run' => true])->expectsOutputToContain('Retained subject work: 1 notes; 1 pieces; 1 marks.')->assertSuccessful();
    \App\Support\ClassSubjectDisabler::run($this->org->fresh());
    expect(SubjectNote::count())->toBe(1); expect(SubjectPiece::count())->toBe(1); expect(SubjectPieceMark::count())->toBe(1);
    expect(SchoolSettings::classSubjectWork($this->org->fresh()))->toBeFalse();
    ClassSubjectInitializer::run($this->org->fresh(), false, true);
    expect(SubjectNote::count())->toBe(1); expect(SubjectPiece::count())->toBe(1); expect(SubjectPieceMark::count())->toBe(1);
});

it('uses portable primary keys short explicit indexes foreign keys and text columns', function () {
    foreach (['subject_pieces', 'subject_piece_marks', 'subject_notes'] as $table) {
        expect(Schema::hasColumn($table, 'id'))->toBeTrue();
        expect(collect(Schema::getIndexes($table))->contains(fn ($index) => $index['primary'] && $index['columns'] === ['id']))->toBeTrue();
        foreach ([...Schema::getIndexes($table), ...Schema::getForeignKeys($table)] as $index) expect(strlen($index['name']))->toBeLessThan(64);
    }
    foreach (['subject_pieces' => ['detail'], 'subject_piece_marks' => ['comment'], 'subject_notes' => ['body']] as $table => $columns) foreach ($columns as $column) expect(Schema::getColumnType($table, $column))->toBe('text');
    $files = glob(database_path('migrations/*create_subject_*'));
    expect($files)->toHaveCount(3);
    foreach ($files as $file) {
        $code = file_get_contents($file);
        expect($code)->not->toContain('->constrained(');
        preg_match_all('/->(?:index|unique|foreign)\([^;]+?\)/s', $code, $matches);
        expect($matches[0])->not->toBeEmpty();
    }
});

it('SubjectWorkTenantIsolation returns 404 for foreign ids at every route', function () {
    $foreignOrg = Masjid::create(['name' => 'Foreign Practice', 'email' => uniqid().'@example.invalid', 'phone' => '+15555550109', 'country_id' => '1', 'city_id' => '1', 'address' => 'Practice', 'latitude' => 0, 'longitude' => 0, 'org_type' => 'school']);
    $foreignGroup = Group::factory()->create(['masjid_id' => $foreignOrg->id, 'kind' => 'class']);
    $foreignSubject = ClassSubject::create(['masjid_id' => $foreignOrg->id, 'group_id' => $foreignGroup->id, 'name' => 'Science']);
    $member = ($this->student)('1st', $foreignGroup);
    $piece = SubjectPiece::create(['masjid_id' => $foreignOrg->id, 'class_subject_id' => $foreignSubject->id, 'source' => 'own', 'title' => 'Foreign']);
    $note = SubjectNote::create(['masjid_id' => $foreignOrg->id, 'class_subject_id' => $foreignSubject->id, 'body' => 'Foreign']);
    $plan = LessonPlan::create(['masjid_id' => $foreignOrg->id, 'group_id' => $foreignGroup->id, 'body' => 'Foreign', 'session_date' => '2026-10-09']);
    $url = str_replace('/subjects/'.$this->subject->id, '/subjects/'.$foreignSubject->id, $this->base);
    $this->getJson($url.'/work')->assertNotFound(); $this->getJson($url.'/notes')->assertNotFound();
    $this->postJson($url.'/notes', ['body' => 'Wrong'])->assertNotFound();
    $this->putJson($url.'/notes/'.$note->id, ['body' => 'Wrong'])->assertNotFound();
    $this->deleteJson($url.'/notes/'.$note->id)->assertNotFound();
    $this->postJson($url.'/pieces', ['title' => 'Wrong'])->assertNotFound();
    $this->putJson($url.'/pieces/'.$piece->id, ['title' => 'Wrong'])->assertNotFound();
    $this->deleteJson($url.'/pieces/'.$piece->id, ['mark_count' => 0])->assertNotFound();
    ($this->putMarks)($url.'/marks', ['source' => 'own', 'piece_id' => $piece->id, 'marks' => []])->assertNotFound();
    $this->putJson($this->base.'/notes/'.$note->id, ['body' => 'Wrong'])->assertNotFound();
    $this->deleteJson($this->base.'/notes/'.$note->id)->assertNotFound();
    $this->postJson($this->base.'/notes', ['group_membership_id' => $member->id, 'body' => 'Wrong'])->assertNotFound();
    $this->putJson($this->base.'/pieces/'.$piece->id, ['title' => 'Wrong'])->assertNotFound();
    $this->deleteJson($this->base.'/pieces/'.$piece->id, ['mark_count' => 0])->assertNotFound();
    ($this->putMarks)($this->base.'/marks', ['source' => 'own', 'piece_id' => $piece->id, 'marks' => [($this->mark)()]])->assertNotFound();
    ($this->putMarks)($this->base.'/marks', ['source' => 'plan', 'lesson_plan_id' => $plan->id, 'marks' => [($this->mark)()]])->assertNotFound();
    ($this->saveGuide)([($this->mark)($member)])->assertNotFound();
    Sanctum::actingAs($this->office);
    $admin = str_replace('/teacher/', '/admin/', $url);
    $this->getJson($admin.'/work')->assertNotFound(); $this->getJson($admin.'/notes')->assertNotFound();
});

it('SubjectWorkTenantIsolation scopes reads updates deletes and stamps creation for all three models', function (string $model) {
    $org = Masjid::create(['name' => 'Foreign Practice', 'email' => uniqid().'@example.invalid', 'phone' => '+15555550109', 'country_id' => '1', 'city_id' => '1', 'address' => 'Practice', 'latitude' => 0, 'longitude' => 0, 'org_type' => 'school']);
    $group = Group::factory()->create(['masjid_id' => $org->id]);
    $subject = ClassSubject::create(['masjid_id' => $org->id, 'group_id' => $group->id, 'name' => 'Science']);
    $piece = SubjectPiece::create(['masjid_id' => $org->id, 'class_subject_id' => $subject->id, 'source' => 'own', 'title' => 'Foreign']);
    $member = ($this->student)('1st', $group);
    $attributes = match ($model) {
        SubjectPiece::class => ['class_subject_id' => $subject->id, 'source' => 'own', 'title' => 'Foreign'],
        SubjectPieceMark::class => ['subject_piece_id' => $piece->id, 'group_membership_id' => $member->id, 'level' => 3, 'comment' => 'Foreign'],
        SubjectNote::class => ['class_subject_id' => $subject->id, 'body' => 'Foreign'],
    };
    $foreign = $model::create(['masjid_id' => $org->id] + $attributes);
    $field = $model === SubjectPiece::class ? 'title' : ($model === SubjectNote::class ? 'body' : 'comment');
    app(TenantContext::class)->set($this->org->id);
    expect($model::find($foreign->id))->toBeNull();
    expect($model::whereKey($foreign->id)->update([$field => 'Wrong']))->toBe(0);
    expect($model::whereKey($foreign->id)->delete())->toBe(0);
    $localPiece = SubjectPiece::create(['masjid_id' => $org->id, 'class_subject_id' => $this->subject->id, 'source' => 'own', 'title' => 'Local']);
    $local = match ($model) {
        SubjectPiece::class => $localPiece,
        SubjectNote::class => SubjectNote::create(['masjid_id' => $org->id, 'class_subject_id' => $this->subject->id, 'body' => 'Local']),
        SubjectPieceMark::class => SubjectPieceMark::create(['masjid_id' => $org->id, 'subject_piece_id' => $localPiece->id, 'group_membership_id' => $this->one->id, 'level' => 3]),
    };
    expect((int) $local->masjid_id)->toBe($this->org->id);
    app(TenantContext::class)->forgetTenant();
    expect($foreign->fresh()->getAttribute($field))->toBe('Foreign');
})->with([SubjectPiece::class, SubjectPieceMark::class, SubjectNote::class]);

it('keeps the last marked opening after all marks are cleared and no rows remain', function () {
    ($this->guide)(1);
    ($this->saveGuide)([($this->mark)()])->assertOk();
    ($this->saveGuide)([($this->mark)(null, null, null)])->assertOk();
    $this->getJson($this->base.'/work')->assertJsonPath('data.curriculum.0.opening_week_no', 4);
    expect(SubjectPieceMark::count())->toBe(0);
});

it('compiles the three new migrations with the MySQL grammar without connecting', function () {
    $connection = new \Illuminate\Database\MySqlConnection(fn () => throw new \RuntimeException('Must not connect'), 'practice', '', ['driver' => 'mysql', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_bin', 'prefix_indexes' => true]);
    $previous = Schema::getFacadeRoot();
    try {
        Schema::swap($connection->getSchemaBuilder());
        foreach (glob(database_path('migrations/*create_subject_*')) as $file) {
            $migration = require $file;
            $sql = $connection->pretend(fn () => $migration->up());
            expect($sql)->not->toBeEmpty();
            $create = $sql[0]['query'];
            expect($create)->toContain('`id` bigint unsigned not null auto_increment primary key');
            if (str_ends_with($file, 'create_subject_pieces_table.php')) {
                expect($create)->toContain('`guide_subject` varchar(64) null');
                expect(implode('\n', array_column($sql, 'query')))->toContain('(`class_subject_id`, `guide_subject`, `grade_label`, `week_no`)');
            }
            foreach ($sql as $statement) {
                preg_match_all('/(?:index|constraint|unique) `([^`]+)`/', $statement['query'], $names);
                foreach ($names[1] as $name) expect(strlen($name))->toBeLessThan(64);
            }
        }
    } finally {
        Schema::swap($previous);
    }
});

it('office membership admin can read hidden subject history behind the same work switch', function () {
    $this->postJson($this->base.'/notes', ['body' => 'Practice history'])->assertCreated();
    $this->subject->update(['hidden_at' => now()]);
    $this->office->forceFill(['type' => 'MasjidAdmin'])->save();
    $this->org->fresh()->forceFill(['user_id' => $this->office->id])->save();
    \Illuminate\Support\Facades\Auth::forgetGuards();
    Sanctum::actingAs($this->office->fresh());
    $base = str_replace('/teacher/', '/admin/', $this->base);
    $this->getJson($base.'/notes')->assertOk()->assertJsonPath('data.0.body', 'Practice history');
    $this->getJson($base.'/work')->assertOk()->assertJsonPath('data.subject.id', $this->subject->id);
});

it('uses saved guide and plan identities after source removal and refuses changing copied pieces', function () {
    ($this->saveGuide)([($this->mark)()])->assertOk();
    $piece = SubjectPiece::first();
    $this->entry->delete();
    $this->getJson($this->base.'/work')->assertOk()->assertJsonPath('data.curriculum.0.entries.0.title', 'Practice focus 4');
    ($this->putMarks)($this->base.'/marks', ['source' => 'guide', 'piece_id' => $piece->id, 'marks' => [($this->mark)(null, 4)]])->assertOk();
    $this->putJson($this->base.'/pieces/'.$piece->id, ['title' => 'Wrong'])->assertNotFound();
    $this->deleteJson($this->base.'/pieces/'.$piece->id, ['mark_count' => 1])->assertNotFound();
    ($this->putMarks)($this->base.'/marks', ['source' => 'own', 'piece_id' => $piece->id, 'marks' => [($this->mark)()]])->assertNotFound();
});

it('confirms deletion against withdrawn marks and refuses missing confirmation', function () {
    $piece = $this->postJson($this->base.'/pieces', ['title' => 'Practice'])->assertCreated()->json('data.id');
    ($this->putMarks)($this->base.'/marks', ['source' => 'own', 'piece_id' => $piece, 'marks' => [($this->mark)()]])->assertOk();
    DB::table('group_memberships')->where('id', $this->one->id)->update(['left_on' => '2026-10-09']);
    $this->getJson($this->base.'/work')->assertJsonPath('data.own_pieces.0.mark_count', 1)->assertJsonCount(0, 'data.own_pieces.0.marks');
    $this->deleteJson($this->base.'/pieces/'.$piece)->assertUnprocessable();
    $this->deleteJson($this->base.'/pieces/'.$piece, ['mark_count' => 0])->assertStatus(409)->assertJsonPath('mark_count', 1);
    $this->deleteJson($this->base.'/pieces/'.$piece, ['mark_count' => 1])->assertOk();
});

it('recovers a competing first plan insert and retains the winner snapshot', function () {
    $plan = ($this->plan)();
    $injected = false;
    DB::listen(function ($query) use (&$injected, $plan) {
        if ($injected || ! str_contains($query->sql, 'from "subject_pieces"') || ! str_contains($query->sql, '"lesson_plan_id" =')) return;
        $injected = true;
        DB::table('subject_pieces')->insert(['masjid_id' => $this->org->id, 'class_subject_id' => $this->subject->id,
            'source' => 'plan', 'title' => '2026-10-09: Winning plan', 'lesson_plan_id' => $plan->id, 'created_at' => now(), 'updated_at' => now()]);
    });
    ($this->putMarks)($this->base.'/marks', ['source' => 'plan', 'lesson_plan_id' => $plan->id, 'marks' => [($this->mark)()]])->assertOk();
    expect($injected)->toBeTrue(); expect(SubjectPiece::count())->toBe(1); expect(SubjectPieceMark::count())->toBe(1);
    expect(SubjectPiece::first()->title)->toBe('2026-10-09: Winning plan');
});

it('refuses overlong copied words with validation instead of truncating on SQLite', function () {
    $this->entry->update(['focus' => str_repeat('x', 256)]);
    ($this->saveGuide)([($this->mark)()])->assertUnprocessable();
    expect(SubjectPiece::count())->toBe(0);
    $plan = ($this->plan)(); $plan->update(['objective' => str_repeat('x', 256)]);
    ($this->putMarks)($this->base.'/marks', ['source' => 'plan', 'lesson_plan_id' => $plan->id, 'marks' => [($this->mark)()]])->assertUnprocessable();
    expect(SubjectPiece::count())->toBe(0);
});

it('adds the class work bootstrap flag only when both grants are on', function () {
    $url = "/api/teacher/masjids/{$this->org->id}/groups/{$this->group->id}";
    $this->getJson($url)->assertOk()->assertJsonPath('data.class_subject_work_enabled', true);
    CapabilityWriter::apply($this->org->fresh(), ['class_subject_work' => false], null);
    expect($this->getJson($url)->assertOk()->json('data'))->not->toHaveKey('class_subject_work_enabled');
});

it('starts work transactions with the organisation mutex before establishing a consistent read view', function () {
    $inside = false; $queries = [];
    \Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\TransactionBeginning::class, function () use (&$inside) { $inside = true; });
    DB::listen(function ($query) use (&$queries, &$inside) {
        if ($inside) $queries[] = $query->sql;
    });
    $this->postJson($this->base.'/notes', ['body' => 'Practice'])->assertCreated();
    expect($queries[0])->toContain('from "masjids"');
    expect($queries[1])->toContain('from "groups"');
    expect($queries[2])->toContain('from "class_subjects"');
});

it('keeps newest plan order for a subject renamed between two plans on the same date', function () {
    $old = ($this->plan)(); $old->update(['objective' => 'Zebra practice']);
    $this->subject->update(['name' => 'Natural Science']);
    $new = ($this->plan)(); $new->update(['objective' => 'Alpha practice']);
    $this->getJson($this->base.'/work')->assertOk()->assertJsonPath('data.lesson_plans.0.lesson_plan_id', $new->id);
});

it('review keeps a marked plan on the subject it was marked under after the plan is linked elsewhere', function () {
    $plan = ($this->plan)();
    ($this->putMarks)($this->base.'/marks', ['source' => 'plan', 'lesson_plan_id' => $plan->id, 'marks' => [($this->mark)($this->one, 4, 'Kept')]])->assertOk();
    $plan->forceFill(['class_subject_id' => $this->other->id, 'subject' => $this->other->name])->save();

    $plans = $this->getJson($this->base.'/work')->assertOk()->json('data.lesson_plans');
    expect($plans)->toHaveCount(1);
    expect($plans[0]['mark_count'])->toBe(1);
    expect(collect($plans[0]['marks'])->firstWhere('group_membership_id', $this->one->id)['comment'])->toBe('Kept');
    expect($plans[0]['moved_to'])->toBe($this->other->name);

    // The other subject lists the plan itself, unmarked: marks do not follow a relink.
    $otherBase = str_replace('/subjects/'.$this->subject->id, '/subjects/'.$this->other->id, $this->base);
    $there = $this->getJson($otherBase.'/work')->assertOk()->json('data.lesson_plans');
    expect($there)->toHaveCount(1);
    expect($there[0]['piece_id'])->toBeNull();
});

it('review refuses to turn a class with notes or pieces into a general group', function (string $kind) {
    if ($kind === 'note') $this->postJson($this->base.'/notes', ['body' => 'Practice note'])->assertSuccessful();
    else $this->postJson($this->base.'/pieces', ['title' => 'Practice piece'])->assertSuccessful();
    app(TenantContext::class)->forgetTenant(); app('auth')->forgetGuards();
    Sanctum::actingAs($this->office, ['staff']);

    $this->putJson("/api/admin/masjids/{$this->org->id}/groups/{$this->group->id}", ['name' => $this->group->name, 'kind' => 'general'])->assertStatus(422);
    expect($this->group->fresh()->kind)->toBe('class');
})->with(['note', 'piece']);

it('review saves marks in the same number of statements for one student and for thirty', function () {
    $members = collect(range(1, 30))->map(fn () => ($this->student)());
    $count = function (array $marks): int {
        DB::flushQueryLog(); DB::enableQueryLog();
        ($this->saveGuide)($marks)->assertOk();
        $n = count(DB::getQueryLog()); DB::disableQueryLog();
        return $n;
    };
    ($this->saveGuide)([($this->mark)()])->assertOk(); // the piece exists from here on
    $one = $count([($this->mark)($this->one, 2, 'Again')]);
    $thirty = $count($members->map(fn ($m) => ($this->mark)($m, 3, 'Practice'))->all());
    expect($thirty)->toBe($one);
    expect(SubjectPieceMark::count())->toBe(31);
    // Clearing goes through the same two statements and removes the rows.
    $cleared = $count($members->map(fn ($m) => ($this->mark)($m, null, null))->all());
    expect($cleared)->toBeLessThanOrEqual($one);
    expect(SubjectPieceMark::count())->toBe(1);
});

it('says when a marked curriculum entry was marked against earlier wording', function () {
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-09 15:00:00 UTC'));
    ($this->saveGuide)([($this->mark)()])->assertOk();
    $entry = fn () => collect($this->getJson($this->base.'/work')->assertOk()->json('data.curriculum.0.entries'))->firstWhere('week_no', 4);

    expect($entry()['wording_changed'])->toBeFalse();
    expect($entry()['marked_against_date'])->toBe('Oct 9, 2026');

    $this->entry->update(['focus' => 'Reworded focus']);
    $after = $entry();
    expect($after['wording_changed'])->toBeTrue();
    expect($after['title'])->toBe('Practice focus 4');

    // An entry nobody has marked carries the live words and no such line.
    ($this->guide)(5);
    $unmarked = collect($this->getJson($this->base.'/work')->json('data.curriculum.0.entries'))->firstWhere('week_no', 5);
    expect($unmarked)->not->toHaveKey('wording_changed');
});

it('Build C reads nullable ordered following lists with explicit empty overriding legacy', function () {
    expect($this->subject->followedGuideSubjects())->toBe(['Science']);
    $this->subject->update(['guide_subjects' => ['Science', 'Joint studies']]);
    expect($this->subject->fresh()->followedGuideSubjects())->toBe(['Science', 'Joint studies']);
    $this->subject->update(['guide_subjects' => []]);
    expect($this->subject->fresh()->followedGuideSubjects())->toBe([]);
    expect($this->subject->fresh()->guide_subject)->toBeNull();
});

it('Build C orders overlapping guide numbers by following order and uses guide grade labels', function () {
    ($this->guide)(1, 'Grade 1', 'Joint studies');
    ($this->guide)(4, 'Grade 1', 'Joint studies');
    ($this->guide)(2, 'Grade 2', 'Joint studies');
    $this->subject->update(['guide_subjects' => ['Joint studies', 'Science']]);
    $blocks = $this->getJson($this->base.'/work')->assertOk()->json('data.curriculum');
    expect($blocks[0]['grade_label'])->toBe('Grade 1');
    expect(array_column($blocks[0]['entries'], 'guide_subject'))->toBe(['Joint studies', 'Joint studies', 'Science']);
    expect(array_column($blocks[0]['entries'], 'week_no'))->toBe([1, 4, 4]);
    expect($blocks[0]['opening_guide_subject'])->toBe('Joint studies');
    $this->getJson($this->base.'/work?grade_label=1&week_no=4&guide_subject=Science')->assertOk()->assertJsonPath('data.curriculum.0.selected_guide_subject', 'Science');
    $this->getJson($this->base.'/work?grade_label=1&week_no=4')->assertUnprocessable();
    $this->getJson($this->base.'/work?grade_label=1&week_no=4&guide_subject=Unknown')->assertUnprocessable();
});

it('Build C marks each guide identity and retains unfollowed saved work with no first marks allowed', function () {
    ($this->guide)(4, 'Grade 1', 'Joint studies');
    ($this->guide)(5, 'Grade 1', 'Joint studies');
    $this->subject->update(['guide_subjects' => ['Science', 'Joint studies']]);
    $save = fn ($guide, $week = 4) => ($this->putMarks)($this->base.'/marks', ['source' => 'guide', 'guide_subject' => $guide, 'grade_label' => '1', 'week_no' => $week, 'marks' => [($this->mark)()]]);
    ($this->saveGuide)([($this->mark)()])->assertUnprocessable();
    $save('Science')->assertOk(); $this->travel(1)->seconds(); $save('Joint studies')->assertOk();
    expect(SubjectPiece::count())->toBe(2);
    expect(SubjectPiece::orderBy('id')->pluck('guide_subject')->all())->toBe(['Science', 'Joint studies']);
    $this->getJson($this->base.'/work')->assertJsonPath('data.curriculum.0.opening_guide_subject', 'Joint studies');
    $this->subject->update(['guide_subjects' => []]);
    $page = $this->getJson($this->base.'/work')->assertOk()->json('data');
    expect($page['curriculum'][0]['grade_label'])->toBe('1st');
    expect($page['curriculum'][0]['entries'])->toHaveCount(2);
    $save('Joint studies', 5)->assertUnprocessable();
    $save('Joint studies')->assertOk();
    $piece = SubjectPiece::where('guide_subject', 'Science')->firstOrFail();
    ($this->putMarks)($this->base.'/marks', ['source' => 'guide', 'piece_id' => $piece->id, 'marks' => [($this->mark)(null, 4)]])->assertOk();
    expect(SubjectPiece::count())->toBe(2);
});

it('Build C serves no-following advice only to the office', function () {
    $this->subject->update(['guide_subjects' => []]);
    $teacher = $this->getJson($this->base.'/work')->assertOk()->json('data');
    expect($teacher['curriculum'])->toBe([]);
    expect($teacher)->not->toHaveKey('curriculum_empty_message');
    Sanctum::actingAs($this->office);
    $this->getJson(str_replace('/teacher/', '/admin/', $this->base).'/work')->assertOk()->assertJsonPath('data.curriculum_empty_message', 'This subject follows no curriculum. Choose one under Class subjects.');
});

it('Build C has nullable JSON fallback and guide uniqueness including its subject', function () {
    $column = collect(Schema::getColumns('class_subjects'))->firstWhere('name', 'guide_subjects');
    expect($column['nullable'])->toBeTrue(); expect($column['default'])->toBeNull();
    expect($column['type_name'])->toBe('text'); // SQLite represents Blueprint JSON as text.
    expect(collect(Schema::getIndexes('subject_pieces'))->firstWhere('name', 'sw_piece_guide_unique')['columns'])->toBe(['class_subject_id', 'guide_subject', 'grade_label', 'week_no']);
    $code = file_get_contents(database_path('migrations/2026_10_09_230000_add_guide_subjects_to_class_subjects_table.php'));
    expect($code)->toContain('JSON NULL, ALGORITHM=INSTANT')->not->toContain('DEFAULT');
});

it('Build C includes grades served only by the joint guide and preserves first-mark words across list edits', function () {
    $third = ($this->student)('3');
    ($this->guide)(9, 'Grade 3', 'Joint studies');
    $this->subject->update(['guide_subjects' => ['Science', 'Joint studies']]);
    $blocks = $this->getJson($this->base.'/work')->assertOk()->json('data.curriculum');
    expect($blocks[2]['grade_label'])->toBe('Grade 3');
    expect(array_column($blocks[2]['entries'], 'guide_subject'))->toBe(['Joint studies']);
    $fields = ['source' => 'guide', 'guide_subject' => 'Joint studies', 'grade_label' => '3rd', 'week_no' => 9, 'marks' => [($this->mark)($third, 4, 'Kept words')]];
    ($this->putMarks)($this->base.'/marks', $fields)->assertOk();
    $piece = SubjectPiece::where('guide_subject', 'Joint studies')->firstOrFail();
    CurriculumWeek::where('subject', 'Joint studies')->delete();
    $this->subject->update(['guide_subjects' => ['Science']]);
    $after = $this->getJson($this->base.'/work')->assertOk()->json('data.curriculum.2');
    expect($after['grade_label'])->toBe('3');
    expect($after['entries'][0]['title'])->toBe('Practice focus 9');
    expect($after['entries'][0]['marks'][0]['comment'])->toBe('Kept words');
    expect($after['opening_guide_subject'])->toBe('Joint studies');
    ($this->putMarks)($this->base.'/marks', $fields)->assertOk();
    expect(SubjectPiece::where('guide_subject', 'Joint studies')->count())->toBe(1);
    expect($piece->fresh()->title)->toBe('Practice focus 9');
});

it('Build C emits only an instant nullable JSON addition on MySQL and restores fallback on SQLite rollback', function () {
    $migration = require database_path('migrations/2026_10_09_230000_add_guide_subjects_to_class_subjects_table.php');
    $connection = new \Illuminate\Database\MySqlConnection(fn () => throw new \RuntimeException('Must not connect'), 'practice', '', ['driver' => 'mysql']);
    $previous = DB::getFacadeRoot();
    try {
        DB::swap($connection);
        $sql = $connection->pretend(fn () => $migration->up());
        expect(array_column($sql, 'query'))->toBe(['ALTER TABLE `class_subjects` ADD COLUMN `guide_subjects` JSON NULL, ALGORITHM=INSTANT']);
    } finally { DB::swap($previous); }
    $migration->down();
    expect(Schema::hasColumn('class_subjects', 'guide_subjects'))->toBeFalse();
    $migration->up();
    expect($this->subject->fresh()->guide_subjects)->toBeNull();
    expect($this->subject->fresh()->followedGuideSubjects())->toBe(['Science']);
});


it('Build D preserves two editors saves for different students of the same piece and returns mark versions', function () {
    $second = ($this->student)();
    $first = ($this->mark)($this->one, 3, 'First teacher') + ['updated_at' => null];
    $other = ($this->mark)($second, 4, 'Second teacher') + ['updated_at' => null];
    $saved = ($this->saveGuide)([$first])->assertOk()->assertJsonStructure(['data' => ['piece_id', 'marks' => [['group_membership_id', 'updated_at']]]]);
    ($this->saveGuide)([$other])->assertOk()->assertJsonPath('data.piece_id', $saved->json('data.piece_id'));
    $marks = $this->getJson($this->base.'/work')->assertOk()->json('data.curriculum.0.entries.0.marks');
    expect(array_column($marks, 'comment', 'group_membership_id'))->toBe([$this->one->id => 'First teacher', $second->id => 'Second teacher']);
    expect($marks[0]['updated_at'])->toBe($saved->json('data.marks.0.updated_at'));
});

it('Build D rejects stale same student updates atomically including same second edits and null first saves', function () {
    $this->freezeTime();
    $second = ($this->student)();
    $first = ($this->mark)($this->one, 3, 'First') + ['updated_at' => null];
    $version = ($this->saveGuide)([$first])->assertOk()->json('data.marks.0.updated_at');
    expect($version)->not->toBeNull();
    $fresh = array_replace($first, ['comment' => 'Newer', 'updated_at' => $version]);
    $newVersion = ($this->saveGuide)([$fresh])->assertOk()->json('data.marks.0.updated_at');
    expect($newVersion)->not->toBe($version);
    foreach ([$version, null] as $stale) {
        ($this->saveGuide)([($this->mark)($second, 4, 'Must roll back') + ['updated_at' => null], array_replace($first, ['comment' => 'Stale', 'updated_at' => $stale])])
            ->assertStatus(409)->assertJsonPath('students.0.group_membership_id', $this->one->id)->assertJsonPath('students.0.name', 'Practice Student')->assertJsonCount(1, 'students');
        expect(SubjectPieceMark::count())->toBe(1); expect(SubjectPieceMark::first()->comment)->toBe('Newer');
    }
    ($this->saveGuide)([array_replace($first, ['level' => null, 'comment' => null, 'updated_at' => $newVersion])])->assertOk()->assertJsonPath('data.marks.0.updated_at', null);
    ($this->saveGuide)([array_replace($fresh, ['updated_at' => $newVersion])])->assertStatus(409);
    expect(SubjectPieceMark::count())->toBe(0);
});

it('Build D requires the loaded mark timestamp and reports every stale student without writing', function () {
    $this->putJson($this->base.'/marks', ['source' => 'guide', 'grade_label' => '1st', 'week_no' => 4, 'marks' => [($this->mark)()]])->assertUnprocessable()->assertJsonStructure(['data' => ['marks.0.updated_at']]);
    $second = ($this->student)();
    ($this->saveGuide)([($this->mark)() + ['updated_at' => null], ($this->mark)($second) + ['updated_at' => null]])->assertOk();
    ($this->saveGuide)([($this->mark)(null, 4) + ['updated_at' => null], ($this->mark)($second, null, null) + ['updated_at' => null]])->assertStatus(409)->assertJsonCount(2, 'students');
    expect(SubjectPieceMark::count())->toBe(2); expect(SubjectPieceMark::where('group_membership_id', $this->one->id)->first()->level)->toBe(3);
});

it('Build D compares trimmed wording with null and empty strings equivalent for every field', function () {
    $this->entry->update(['assessment_note' => null, 'standard_code' => null]);
    ($this->saveGuide)([($this->mark)() + ['updated_at' => null]])->assertOk();
    $piece = SubjectPiece::firstOrFail();
    $piece->update(['title' => '  Practice focus 4  ', 'detail' => '', 'standard_code' => '  ']);
    $entry = fn () => $this->getJson($this->base.'/work')->assertOk()->json('data.curriculum.0.entries.0');
    expect($entry()['wording_changed'])->toBeFalse();
    foreach (['title', 'detail', 'standard_code'] as $field) {
        $original = $piece->$field; $piece->update([$field => 'Real changed wording']);
        expect($entry()['wording_changed'])->toBeTrue(); $piece->update([$field => $original]);
    }
    $this->entry->update(['assessment_note' => '  ', 'standard_code' => '']);
    $piece->update(['detail' => null, 'standard_code' => null]);
    expect($entry()['wording_changed'])->toBeFalse();
});


it('Build D never reuses a cleared mark version when it is recreated after an own piece correction', function () {
    $this->freezeTime();
    $piece = $this->postJson($this->base.'/pieces', ['title' => 'Practice task'])->assertCreated()->json('data.id');
    $body = ['source' => 'own', 'piece_id' => $piece, 'marks' => [($this->mark)() + ['updated_at' => null]]];
    $old = $this->putJson($this->base.'/marks', $body)->assertOk()->json('data.marks.0.updated_at');
    $clear = $body; $clear['marks'][0] = array_replace($clear['marks'][0], ['level' => null, 'comment' => null, 'updated_at' => $old]);
    $this->putJson($this->base.'/marks', $clear)->assertOk();
    $this->putJson($this->base.'/pieces/'.$piece, ['title' => 'Corrected task'])->assertOk();
    $new = $this->putJson($this->base.'/marks', $body)->assertOk()->json('data.marks.0.updated_at');
    expect($new)->not->toBe($old);
    $stale = $body; $stale['marks'][0] = array_replace($stale['marks'][0], ['level' => 1, 'updated_at' => $old]);
    $this->putJson($this->base.'/marks', $stale)->assertStatus(409);
    expect(SubjectPieceMark::first()->level)->toBe(3);
});

it('final review does not tell a limited teacher the name of the subject a marked plan moved to', function () {
    $plan = ($this->plan)();
    $this->putJson($this->base.'/marks', ['source' => 'plan', 'lesson_plan_id' => $plan->id, 'marks' => [($this->mark)($this->one, 4, 'Kept') + ['updated_at' => null]]])->assertOk();
    $plan->forceFill(['class_subject_id' => $this->other->id, 'subject' => $this->other->name])->save();

    // Unlimited: told where it went.
    $piece = $this->getJson($this->base.'/work')->assertOk()->json('data.lesson_plans.0');
    expect($piece['moved_elsewhere'])->toBeTrue();
    expect($piece['moved_to'])->toBe($this->other->name);

    // Limited to the subject it was marked under: told it moved, not where.
    $this->staff->fresh()->update(['class_subject_ids' => [$this->subject->id]]);
    app(TenantContext::class)->forgetTenant(); app('auth')->forgetGuards(); Sanctum::actingAs($this->teacher->fresh(), ['staff']);
    $response = $this->getJson($this->base.'/work')->assertOk();
    $piece = $response->json('data.lesson_plans.0');
    expect($piece['moved_elsewhere'])->toBeTrue();
    expect($piece['moved_to'])->toBeNull();
    expect($response->getContent())->not->toContain('"'.$this->other->name.'"');
});

it('final review names the piece in a mark conflict so the page can reload it', function () {
    ($this->saveGuide)([($this->mark)($this->one, 3, 'First')])->assertOk();
    $piece = SubjectPiece::firstOrFail();
    $stale = ['group_membership_id' => $this->one->id, 'level' => 2, 'comment' => 'Stale', 'updated_at' => '2020-01-01T00:00:00.000000Z'];
    $this->putJson($this->base.'/marks', ['source' => 'guide', 'piece_id' => $piece->id, 'marks' => [$stale]])
        ->assertStatus(409)->assertJsonPath('piece_id', $piece->id);
    expect(SubjectPieceMark::firstOrFail()->comment)->toBe('First');
});
