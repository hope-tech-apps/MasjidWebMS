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
    $this->saveGuide = fn (array $marks, int $week = 4, string $grade = '1st') => $this->putJson($this->base.'/marks', ['source' => 'guide', 'grade_label' => $grade, 'week_no' => $week, 'marks' => $marks]);
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
    $this->putJson($this->base.'/marks', ['source' => 'own', 'piece_id' => $piece, 'marks' => [($this->mark)()]])->assertOk();
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
    $save = fn () => $this->putJson($this->base.'/marks', ['source' => 'plan', 'lesson_plan_id' => $plan->id, 'marks' => [($this->mark)()]]);
    $save()->assertOk(); $plan->update(['objective' => 'New objective']);
    $this->getJson($this->base.'/work')->assertJsonPath('data.lesson_plans.0.title', '2026-10-09: Practice objective');
    $plan->delete();
    expect(SubjectPiece::first()->lesson_plan_id)->toBeNull();
    $this->getJson($this->base.'/work')->assertJsonPath('data.lesson_plans.0.title', '2026-10-09: Practice objective');
    $this->putJson($this->base.'/marks', ['source' => 'plan', 'piece_id' => SubjectPiece::first()->id, 'marks' => [($this->mark)(null, 2)]])->assertOk();
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
            'class_subject_id' => $this->subject->id, 'source' => 'guide', 'title' => 'Winning snapshot',
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
        'marks' => $this->putJson($this->base.'/marks', ['source' => 'own', 'piece_id' => $piece, 'shared_with_family' => true, 'marks' => [($this->mark)()]]),
        'mark-row' => $this->putJson($this->base.'/marks', ['source' => 'own', 'piece_id' => $piece, 'marks' => [array_merge(($this->mark)(), ['shared_with_family' => true])]]),
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
    $this->putJson($url.'/marks', ['source' => 'own', 'piece_id' => $piece->id, 'marks' => []])->assertNotFound();
    $this->putJson($this->base.'/notes/'.$note->id, ['body' => 'Wrong'])->assertNotFound();
    $this->deleteJson($this->base.'/notes/'.$note->id)->assertNotFound();
    $this->postJson($this->base.'/notes', ['group_membership_id' => $member->id, 'body' => 'Wrong'])->assertNotFound();
    $this->putJson($this->base.'/pieces/'.$piece->id, ['title' => 'Wrong'])->assertNotFound();
    $this->deleteJson($this->base.'/pieces/'.$piece->id, ['mark_count' => 0])->assertNotFound();
    $this->putJson($this->base.'/marks', ['source' => 'own', 'piece_id' => $piece->id, 'marks' => [($this->mark)()]])->assertNotFound();
    $this->putJson($this->base.'/marks', ['source' => 'plan', 'lesson_plan_id' => $plan->id, 'marks' => [($this->mark)()]])->assertNotFound();
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
    $this->putJson($this->base.'/marks', ['source' => 'guide', 'piece_id' => $piece->id, 'marks' => [($this->mark)(null, 4)]])->assertOk();
    $this->putJson($this->base.'/pieces/'.$piece->id, ['title' => 'Wrong'])->assertNotFound();
    $this->deleteJson($this->base.'/pieces/'.$piece->id, ['mark_count' => 1])->assertNotFound();
    $this->putJson($this->base.'/marks', ['source' => 'own', 'piece_id' => $piece->id, 'marks' => [($this->mark)()]])->assertNotFound();
});

it('confirms deletion against withdrawn marks and refuses missing confirmation', function () {
    $piece = $this->postJson($this->base.'/pieces', ['title' => 'Practice'])->assertCreated()->json('data.id');
    $this->putJson($this->base.'/marks', ['source' => 'own', 'piece_id' => $piece, 'marks' => [($this->mark)()]])->assertOk();
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
    $this->putJson($this->base.'/marks', ['source' => 'plan', 'lesson_plan_id' => $plan->id, 'marks' => [($this->mark)()]])->assertOk();
    expect($injected)->toBeTrue(); expect(SubjectPiece::count())->toBe(1); expect(SubjectPieceMark::count())->toBe(1);
    expect(SubjectPiece::first()->title)->toBe('2026-10-09: Winning plan');
});

it('refuses overlong copied words with validation instead of truncating on SQLite', function () {
    $this->entry->update(['focus' => str_repeat('x', 256)]);
    ($this->saveGuide)([($this->mark)()])->assertUnprocessable();
    expect(SubjectPiece::count())->toBe(0);
    $plan = ($this->plan)(); $plan->update(['objective' => str_repeat('x', 256)]);
    $this->putJson($this->base.'/marks', ['source' => 'plan', 'lesson_plan_id' => $plan->id, 'marks' => [($this->mark)()]])->assertUnprocessable();
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
