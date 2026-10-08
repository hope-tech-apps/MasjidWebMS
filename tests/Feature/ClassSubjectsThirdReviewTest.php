<?php

use App\Models\{ClassAssignment, ClassSubject, Group, GroupStaff, LessonPlan, Masjid, MasjidUser, SchoolSubject, User};
use App\Support\{ClassSubjectInitializer, SubjectFence, TenantContext};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Mail};
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    $this->org = Masjid::create(['name' => 'Practice School', 'email' => uniqid().'@example.invalid', 'phone' => '+1'.random_int(1000000000, 9999999999), 'country_id' => '1', 'city_id' => '1', 'address' => 'Practice', 'latitude' => 0, 'longitude' => 0, 'org_type' => 'school', 'crm_enabled' => true]);
    $this->group = Group::factory()->create(['masjid_id' => $this->org->id, 'kind' => 'class', 'name' => 'Practice Class']);
    $this->teacher = User::factory()->create(['type' => 'Teacher', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
    MasjidUser::create(['masjid_id' => $this->org->id, 'user_id' => $this->teacher->id, 'role' => 'teacher']);
    $this->staff = GroupStaff::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'user_id' => $this->teacher->id, 'role' => 'teacher']);
    $this->office = User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
    SchoolSubject::create(['masjid_id' => $this->org->id, 'name' => 'Arabic']);
    $this->initialize = fn () => $this->artisan('class-subjects:initialize', ['--masjid' => $this->org->id, '--enable' => true])->assertSuccessful();
    $this->teacherUrl = "/api/admin/masjids/{$this->org->id}/teachers/{$this->teacher->id}";
    $this->subjectUrl = "/api/admin/masjids/{$this->org->id}/groups/{$this->group->id}/subjects";
    $this->body = ['name' => $this->teacher->name, 'class_ids' => [$this->group->id]];
    $this->stale = function () {
        ($this->initialize)();
        DB::table('group_staff')->where('id', $this->staff->id)->update(['subjects' => '["arabic"]']);
        $this->staff->refresh();
    };
    $this->work = function (string $kind, string $name = 'Science') {
        $fields = ['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'subject' => $name, 'title' => 'Practice'];
        return $kind === 'grades' ? ClassAssignment::create($fields + ['assigned_on' => '2026-10-01', 'scale' => 'points', 'points_possible' => 10])
            : LessonPlan::create($fields + ['session_date' => '2026-10-01', 'body' => 'Practice']);
    };
    Sanctum::actingAs($this->office);
    Mail::fake();
});

afterEach(fn () => app(TenantContext::class)->forgetTenant());

it('R3-1 marks stale assignments and withholds their ids on every office response', function () {
    ($this->stale)();
    $data = $this->getJson($this->teacherUrl)->assertOk()->json('data');
    expect((array) $data['class_subject_ids'])->not->toHaveKey($this->group->id);
    expect($data['class_subject_attention'][$this->group->id])->toBeTrue();
    $class = $this->getJson("/api/admin/masjids/{$this->org->id}/teachers")->assertOk()->json('data.0.classes.0');
    expect($class)->not->toHaveKey('class_subject_ids')->toHaveKey('class_subject_assignment_needs_attention', true);
});

it('R3-1 refuses an unchanged stale round trip without explicit resolution', function (?array $ids) {
    ($this->stale)();
    $this->putJson($this->teacherUrl, $this->body + ['class_subjects' => [$this->group->id => ['arabic']], 'class_subject_ids' => [$this->group->id => $ids]])->assertUnprocessable();
    expect(ClassSubjectInitializer::needsMapping($this->staff->fresh()))->toBeTrue();
    expect(SubjectFence::mayWeighClass($this->teacher, $this->group->id))->toBeFalse();
})->with([[null], [[]]]);

it('R3-1 requires a deliberate wider choice and compares with stored legacy before payload edits', function (bool $editLegacy) {
    ($this->stale)();
    $payload = $this->body + ['class_subject_ids' => [$this->group->id => null], 'class_subject_resolutions' => [$this->group->id => 'confirm_legacy']];
    if ($editLegacy) $payload['class_subjects'] = [$this->group->id => null];
    $this->putJson($this->teacherUrl, $payload)->assertUnprocessable();
    $payload['class_subject_resolutions'][$this->group->id] = 'allow_more';
    $this->putJson($this->teacherUrl, $payload)->assertOk();
    expect(ClassSubjectInitializer::needsMapping($this->staff->fresh()))->toBeFalse();
})->with([false, true]);

it('R3-1 permits explicit narrow resolution and command remapping only from current legacy', function (bool $command) {
    ($this->stale)();
    $arabic = ClassSubject::first()->id;
    if ($command) ($this->initialize)();
    else $this->putJson($this->teacherUrl, $this->body + ['class_subject_ids' => [$this->group->id => [$arabic]], 'class_subject_resolutions' => [$this->group->id => 'confirm_legacy']])->assertOk();
    expect($this->staff->fresh()->class_subject_ids)->toBe([$arabic]);
    expect(ClassSubjectInitializer::needsMapping($this->staff->fresh()))->toBeFalse();
})->with([false, true]);

it('R3-1 refuses model baseline reconfirmation without intent but allows ordinary legacy changes to remain stale', function () {
    ($this->stale)();
    $row = $this->staff->fresh()->forceFill(['class_subject_legacy_snapshot' => ['arabic']]);
    expect(fn () => $row->save())->toThrow(ValidationException::class);
});

it('R3-1 refuses invite reuse of an existing stale assignment rather than replacing it', function () {
    ($this->stale)();
    $this->postJson("/api/admin/masjids/{$this->org->id}/teachers", ['name' => $this->teacher->name, 'email' => $this->teacher->email, 'class_ids' => [$this->group->id], 'class_subject_ids' => [$this->group->id => null]])->assertUnprocessable();
    expect(ClassSubjectInitializer::needsMapping($this->staff->fresh()))->toBeTrue();
});

it('R3-2 refuses orphan work capture on rename and direct previous names for every work category', function (string $kind, string $writer) {
    ($this->work)($kind);
    ($this->initialize)();
    $arabic = ClassSubject::first();
    if ($writer === 'http') $this->putJson($this->subjectUrl.'/'.$arabic->id, ['name' => 'Science'])->assertUnprocessable()->assertJsonFragment(['Saved work uses subject key "science". This subject cannot claim it.']);
    else expect(fn () => $arabic->forceFill($writer === 'rename' ? ['name' => 'Science'] : ['previous_name_keys' => ['science']])->save())->toThrow(ValidationException::class);
    expect($arabic->fresh()->matchingKeys())->not->toContain('science');
})->with(['grades', 'plans'])->with(['http', 'rename', 'previous']);

it('R3-2 protects fixed aliases and retained soft-deleted work', function (string $kind) {
    $work = ($this->work)($kind, 'English Language Arts');
    if ($kind === 'grades') $work->delete();
    ($this->initialize)();
    $this->putJson($this->subjectUrl.'/'.ClassSubject::first()->id, ['name' => 'ELA'])->assertUnprocessable();
})->with(['grades', 'plans']);

it('R3-2 requires explicit orphan attachment on add and reports orphans and seed attachments', function () {
    ($this->work)('grades');
    ($this->work)('plans');
    $report = ClassSubjectInitializer::run($this->org, true);
    expect($report[0]['orphaned_work'])->toBe(['science' => 2]);
    $this->artisan('class-subjects:initialize', ['--masjid' => $this->org->id, '--dry-run' => true])->expectsOutput('  saved work under a subject that is not in this class\'s list: science, 2 items')->assertSuccessful();
    ($this->initialize)();
    $this->postJson($this->subjectUrl, ['name' => 'Science'])->assertUnprocessable();
    $this->postJson($this->subjectUrl, ['name' => 'Science', 'attach_saved_work' => true])->assertCreated()->assertJsonPath('attached_saved_work.science', 2);
    expect(ClassSubjectInitializer::run($this->org, true)[0]['orphaned_work'])->toBe([]);
});

it('R3-2 reports seed attachment and preserves existing ownership after ordinary rename', function () {
    ($this->work)('grades', 'Arabic');
    $report = ClassSubjectInitializer::run($this->org, true);
    expect($report[0]['creates'][0]['attaches_saved_work'])->toBe(['arabic' => 1]);
    ($this->initialize)();
    $this->putJson($this->subjectUrl.'/'.ClassSubject::first()->id, ['name' => 'Language'])->assertOk();
    expect(ClassSubject::first()->matchingKeys())->toContain('arabic');
});

it('R3-3 preserves supplied restrictions without a timestamp through every Eloquent pivot writer', function (string $writer, string $shape) {
    ($this->initialize)();
    $user = User::factory()->create(['phone' => '+1'.random_int(1000000000, 9999999999)]);
    $ids = match ($shape) { 'none' => [], 'one' => [ClassSubject::first()->id], default => null };
    $fields = ['masjid_id' => $this->org->id, 'role' => 'teacher', 'class_subject_ids' => $ids];
    if ($writer === 'create') GroupStaff::create($fields + ['group_id' => $this->group->id, 'user_id' => $user->id]);
    elseif ($writer === 'attach') $this->group->staff()->attach($user->id, $fields);
    elseif ($writer === 'sync') $this->group->staff()->syncWithoutDetaching([$user->id => $fields]);
    else $user->groupsLed()->attach($this->group->id, $fields);
    $row = GroupStaff::where('user_id', $user->id)->firstOrFail();
    expect($row->class_subject_ids)->toBe($ids);
    expect(ClassSubjectInitializer::needsMapping($row))->toBeFalse();
})->with(['create', 'attach', 'sync', 'reverse'])->with(['none', 'one', 'all']);

it('R3-3 maps legacy only when own ids were absent', function () {
    ($this->initialize)();
    $row = GroupStaff::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'user_id' => User::factory()->create(['phone' => '+1'.random_int(1000000000, 9999999999)])->id, 'role' => 'teacher', 'subjects' => ['arabic']]);
    expect($row->class_subject_ids)->toBe([ClassSubject::first()->id]);
});

it('R3-2 compares work ownership with the persisted row rather than a cached subject model', function () {
    ($this->initialize)();
    $subject = ClassSubject::first();
    $subject->update(['name' => 'Science']);
    $cached = $subject->fresh();
    $subject->fresh()->update(['name' => 'Language']);
    $subject->fresh()->forceFill(['previous_name_keys' => []])->save();
    ($this->work)('grades');
    expect(fn () => $cached->forceFill(['previous_name_keys' => ['science']])->save())->toThrow(ValidationException::class);
    expect($subject->fresh()->matchingKeys())->not->toContain('science');
});

it('R3-3 maps an absent restriction even when an internal timestamp was supplied', function () {
    ($this->initialize)();
    $row = GroupStaff::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'user_id' => User::factory()->create(['phone' => '+1'.random_int(1000000000, 9999999999)])->id, 'role' => 'teacher', 'subjects' => ['arabic'], 'class_subjects_mapped_at' => now()]);
    expect($row->class_subject_ids)->toBe([ClassSubject::first()->id]);
});

it('R3-1 protects a model cached before a late legacy change', function () {
    ($this->initialize)();
    $cached = $this->staff->fresh();
    DB::table('group_staff')->where('id', $cached->id)->update(['subjects' => '["arabic"]']);
    expect(fn () => $cached->forceFill(['class_subjects_mapped_at' => now()->addSecond()])->save())->toThrow(ValidationException::class);
    expect(fn () => $cached->resolveClassSubjectAssignment(['class_subject_ids' => null, 'class_subject_legacy_snapshot' => ['arabic']], 'confirm_legacy'))->toThrow(ValidationException::class);
});

it('R3-1 guards existing custom pivot writes, including a pivot hydrated without its private columns', function (string $writer) {
    ($this->stale)();
    $fields = ['class_subject_ids' => null, 'class_subjects_mapped_at' => now(), 'class_subject_legacy_snapshot' => ['arabic']];
    $save = match ($writer) {
        'sync' => fn () => $this->group->staff()->syncWithoutDetaching([$this->teacher->id => $fields]),
        'update' => fn () => $this->group->staff()->updateExistingPivot($this->teacher->id, $fields),
        default => fn () => $this->group->staff()->firstOrFail()->pivot->forceFill($fields)->save(),
    };
    expect($save)->toThrow(ValidationException::class);
    expect(ClassSubjectInitializer::needsMapping($this->staff->fresh()))->toBeTrue();
})->with(['sync', 'update', 'hydrated']);

it('R3-1 ignores the new resolution field on existing OFF endpoints', function (string $endpoint, mixed $value) {
    $body = $this->body + ['class_subject_resolutions' => $value];
    if ($endpoint === 'update') $this->putJson($this->teacherUrl, $body)->assertOk();
    else $this->postJson("/api/admin/masjids/{$this->org->id}/teachers", $body + ['email' => uniqid().'@example.invalid'])->assertCreated();
    expect($this->staff->fresh()->class_subjects_mapped_at)->toBeNull();
})->with(['update', 'invite'])->with([[null], ['not a map'], [['bad' => 'not a resolution']]]);

it('R3-2 uses the lesson-plan fence key rather than its older storage key', function () {
    ($this->work)('plans', 'Sci’ence');
    ($this->initialize)();
    $this->putJson($this->subjectUrl.'/'.ClassSubject::first()->id, ['name' => 'Science'])->assertUnprocessable();
    expect(ClassSubjectInitializer::run($this->org, true)[0]['orphaned_work'])->toBe(['science' => 1]);
});

it('R3-2 cannot transfer work ownership by moving a subject to another class', function () {
    ($this->initialize)();
    $subject = ClassSubject::first();
    $other = Group::factory()->create(['masjid_id' => $this->org->id, 'kind' => 'class']);
    ClassSubject::where('group_id', $other->id)->delete();
    ClassAssignment::create(['masjid_id' => $this->org->id, 'group_id' => $other->id, 'subject' => 'Arabic', 'title' => 'Practice', 'assigned_on' => '2026-10-01', 'scale' => 'points', 'points_possible' => 10]);
    expect(fn () => $subject->update(['group_id' => $other->id]))->toThrow(ValidationException::class);
});

it('R3-2 keeps a restricted Arabic teacher out of retained Science work after a refused rename or confirmed office addition', function () {
    $this->staff->update(['subjects' => ['arabic']]);
    $grade = ($this->work)('grades');
    ($this->work)('plans');
    ($this->initialize)();
    $this->putJson($this->subjectUrl.'/'.ClassSubject::first()->id, ['name' => 'Science'])->assertUnprocessable();
    $this->postJson($this->subjectUrl, ['name' => 'Science', 'attach_saved_work' => true])->assertCreated();
    Sanctum::actingAs($this->teacher, ['staff']);
    $base = "/api/teacher/masjids/{$this->org->id}/groups/{$this->group->id}";
    $this->getJson($base.'/assignments')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson($base.'/assignments/'.$grade->id)->assertNotFound();
    $this->getJson($base.'/lesson-plans?from=2026-10-01&to=2026-10-01')->assertOk()->assertJsonCount(0, 'data.plans');
});

it('R3-1 applies narrow resolution policy to setup-side and direct explicit resolution while OFF', function () {
    ($this->stale)();
    \App\Support\CapabilityWriter::apply($this->org->fresh(), ['class_subjects' => false], $this->office->id);
    expect(fn () => $this->staff->fresh()->resolveClassSubjectAssignment(['class_subject_ids' => null, 'class_subjects_mapped_at' => now(), 'class_subject_legacy_snapshot' => ['arabic']], 'confirm_legacy'))->toThrow(ValidationException::class);
    expect(ClassSubjectInitializer::needsMapping($this->staff->fresh()))->toBeTrue();
});

it('R3-2 refuses empty work keys so a direct previous-name entry cannot claim general gradebook work', function () {
    ($this->initialize)();
    ($this->work)('grades', '');
    $subject = ClassSubject::first();
    expect(fn () => $subject->forceFill(['previous_name_keys' => ['']])->save())->toThrow(ValidationException::class);
    expect(fn () => $subject->fresh()->update(['name' => '']))->toThrow(ValidationException::class);
});

it('R3-2 refuses newly owned detail keys even when SQL collations already match them on a list', function () {
    if (DB::connection()->getDriverName() !== 'sqlite') $this->markTestSkipped('SQLite collation simulation, not a MySQL execution.');
    ($this->initialize)();
    $subject = ClassSubject::first();
    $subject->update(['name' => 'Science']);
    // SQLite simulation of an accent-insensitive SQL comparison; this is not a MySQL execution.
    DB::connection()->getPdo()->sqliteCreateCollation('class_subject_test_ci', fn ($a, $b) => strcmp(str_replace('í', 'i', $a), str_replace('í', 'i', $b)));
    DB::statement('ALTER TABLE class_assignments RENAME TO class_assignments_before_collation_test');
    DB::statement('CREATE TABLE class_assignments (id INTEGER PRIMARY KEY, masjid_id INTEGER, group_id INTEGER, subject_key TEXT COLLATE class_subject_test_ci)');
    foreach (['science', 'scíence'] as $key) DB::table('class_assignments')->insert(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'subject_key' => $key]);
    expect(DB::table('class_assignments')->where('subject_key', 'science')->count())->toBe(2);
    $limits = SubjectFence::limitsForIds([$subject->id], $this->group);
    expect(SubjectFence::allows($limits, 'scíence'))->toBeFalse();
    expect(fn () => $subject->forceFill(['previous_name_keys' => ['scíence']])->save())->toThrow(ValidationException::class);
    $subject->fresh()->update(['name' => 'Language']);
    $new = new ClassSubject(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'name' => 'Scíence']);
    expect(fn () => $new->saveAttachingOrphanedWork())->toThrow(ValidationException::class);
});
