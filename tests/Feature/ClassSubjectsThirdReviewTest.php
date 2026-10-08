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
    $this->work = function (string $kind, string $name = 'Science') {
        $fields = ['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'subject' => $name, 'title' => 'Practice'];
        return $kind === 'grades' ? ClassAssignment::create($fields + ['assigned_on' => '2026-10-01', 'scale' => 'points', 'points_possible' => 10])
            : LessonPlan::create($fields + ['session_date' => '2026-10-01', 'body' => 'Practice']);
    };
    Sanctum::actingAs($this->office);
    Mail::fake();
});

afterEach(fn () => app(TenantContext::class)->forgetTenant());


it('R3-1 round trips ids after legacy drift without any reconfirmation state', function () {
    $this->staff->update(['subjects' => ['arabic']]); ($this->initialize)(); $ids = $this->staff->fresh()->class_subject_ids;
    DB::table('group_staff')->where('id', $this->staff->id)->update(['subjects' => null]);
    $data = $this->getJson($this->teacherUrl)->assertOk()->json('data');
    expect($data['class_subject_ids'][$this->group->id])->toBe($ids);
    expect($data)->not->toHaveKey('class_subject_attention');
    $this->putJson($this->teacherUrl, $this->body + ['class_subject_ids' => [$this->group->id => $ids]])->assertOk();
    expect(SubjectFence::assignedIds($this->group->id, $this->teacher->id))->toBe($ids);
    $this->putJson($this->teacherUrl, $this->body + ['class_subjects' => [$this->group->id => null]])->assertUnprocessable();
});

it('R3-2 names aliases guides and obsolete history never capture saved work', function (string $kind, string $edit) {
    $work = ($this->work)($kind, 'Science'); ($this->initialize)(); $arabic = ClassSubject::where('name', 'Arabic')->firstOrFail();
    $this->staff->fresh()->update(['class_subject_ids' => [$arabic->id]]);
    match ($edit) {
        'rename' => $arabic->update(['name' => 'Science']),
        'history' => $arabic->forceFill(['previous_name_keys' => ['science']])->save(),
        'add' => ClassSubject::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'name' => 'Science']),
        'merge' => (function () { SchoolSubject::create(['masjid_id' => $this->org->id, 'name' => 'Science']); $this->postJson($this->subjectUrl.'/add-for-current-grades')->assertOk(); })(),
    };
    expect($work->fresh()->class_subject_id)->toBeNull();
    Sanctum::actingAs($this->teacher);
    $base = "/api/teacher/masjids/{$this->org->id}/groups/{$this->group->id}";
    if ($kind === 'grades') $this->getJson($base.'/assignments/'.$work->id)->assertNotFound();
    else $this->deleteJson($base.'/lesson-plans/'.$work->id)->assertNotFound();
})->with(['grades', 'plans'])->with(['rename', 'history', 'add', 'merge']);

it('R3-3 preserves explicitly supplied restrictions at every pivot creation writer', function (string $writer, bool $empty) {
    ($this->initialize)(); $ids = $empty ? [] : [ClassSubject::where('name', 'Arabic')->firstOrFail()->id];
    $user = User::factory()->create(['type' => 'Teacher', 'phone' => '+15555550104']);
    $fields = ['masjid_id' => $this->org->id, 'subjects' => null, 'class_subject_ids' => $ids];
    match ($writer) {
        'model' => GroupStaff::create($fields + ['group_id' => $this->group->id, 'user_id' => $user->id]),
        'attach' => $this->group->staff()->attach($user->id, $fields),
        'sync' => $this->group->staff()->syncWithoutDetaching([$user->id => $fields]),
        'reverse' => $user->groupsLed()->attach($this->group->id, $fields),
    };
    expect(SubjectFence::assignedIds($this->group->id, $user->id))->toBe($ids);
})->with(['model', 'attach', 'sync', 'reverse'])->with([true, false]);

it('cached and partial pivots cannot erase a concurrently changed restriction', function (string $writer) {
    ($this->initialize)(); $cached = $this->staff->fresh();
    $arabic = ClassSubject::where('name', 'Arabic')->firstOrFail(); $ids = [$arabic->id];
    $this->staff->fresh()->update(['class_subject_ids' => $ids]);
    match ($writer) {
        'cached' => $cached->update(['subjects' => null]),
        'partial' => $this->group->staff()->findOrFail($this->teacher->id)->pivot->forceFill(['subjects' => null])->save(),
        'update-pivot' => $this->group->staff()->updateExistingPivot($this->teacher->id, ['subjects' => null]),
        'reverse-sync' => $this->teacher->groupsLed()->syncWithoutDetaching([$this->group->id => ['subjects' => null]]),
    };
    expect($this->staff->fresh()->class_subject_ids)->toBe($ids);
    expect($this->staff->fresh()->class_subject_ids_edited_at)->not->toBeNull();
})->with(['cached', 'partial', 'update-pivot', 'reverse-sync']);

it('office must explicitly supply ids for every new class assignment', function (bool $existingLogin) {
    ($this->initialize)();
    $other = Group::factory()->create(['masjid_id' => $this->org->id, 'kind' => 'class']);
    $email = $existingLogin ? User::factory()->create(['type' => 'Teacher', 'phone' => '+15555550105'])->email : uniqid().'@example.invalid';
    $before = User::count();
    $this->postJson("/api/admin/masjids/{$this->org->id}/teachers", ['name' => $this->teacher->name, 'email' => $email, 'class_ids' => [$other->id]])->assertUnprocessable();
    expect(GroupStaff::where('group_id', $other->id)->exists())->toBeFalse();
    expect(User::count())->toBe($before);
    $this->postJson("/api/admin/masjids/{$this->org->id}/teachers", ['name' => $this->teacher->name, 'email' => $email, 'class_ids' => [$other->id], 'class_subject_ids' => [$other->id => null]])->assertCreated();
    expect(GroupStaff::where('group_id', $other->id)->firstOrFail()->class_subject_ids)->toBeNull();
})->with([true, false]);

it('refuses moving subject ids across classes even through a cached model', function () {
    ($this->initialize)(); $subject = ClassSubject::firstOrFail();
    $other = Group::factory()->create(['masjid_id' => $this->org->id, 'kind' => 'class']);
    expect(fn () => $subject->update(['group_id' => $other->id]))->toThrow(ValidationException::class);
    expect($subject->fresh()->group_id)->toBe($this->group->id);
});

it('uses exact stored keys at activation without a SQL collation expansion', function (string $kind) {
    $work = ($this->work)($kind, 'Arabíc'); ($this->initialize)();
    expect($work->fresh()->class_subject_id)->toBeNull();
})->with(['grades', 'plans']);

it('links hidden current subjects but never uses their previous keys at activation', function () {
    $work = ($this->work)('grades', 'Arabic');
    $subject = ClassSubject::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'name' => 'Reading', 'hidden_at' => now()]);
    $subject->forceFill(['previous_name_keys' => ['arabic']])->save();
    ($this->initialize)(); expect($work->fresh()->class_subject_id)->toBeNull();
});

it('refuses empty subject names through the model', function () {
    expect(fn () => ClassSubject::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'name' => '  ']))->toThrow(ValidationException::class);
});

it('retains linked deleted gradebook work and child attachments without extra owners', function () {
    $work = ($this->work)('grades', 'Arabic'); $work->delete(); ($this->initialize)();
    expect(ClassAssignment::withTrashed()->findOrFail($work->id)->class_subject_id)->toBe(ClassSubject::where('name', 'Arabic')->value('id'));
});
