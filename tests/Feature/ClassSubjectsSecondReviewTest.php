<?php

use App\Models\ClassSubject;
use App\Models\ClassAssignment;
use App\Models\Group;
use App\Models\GroupStaff;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\SchoolSubject;
use App\Models\User;
use App\Support\ClassSubjectInitializer;
use App\Support\SubjectFence;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    $this->org = Masjid::create(['name' => 'Second Review School', 'email' => 'school@example.invalid', 'phone' => '+15555550102', 'country_id' => '1', 'city_id' => '1', 'address' => 'Practice', 'latitude' => 0, 'longitude' => 0, 'org_type' => 'school', 'crm_enabled' => true]);
    $this->group = Group::factory()->create(['masjid_id' => $this->org->id, 'kind' => 'class']);
    $this->teacher = User::factory()->create(['type' => 'Teacher', 'phone' => '+15555550105']);
    MasjidUser::create(['masjid_id' => $this->org->id, 'user_id' => $this->teacher->id, 'role' => 'teacher']);
    $this->staff = GroupStaff::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'user_id' => $this->teacher->id, 'role' => 'teacher']);
    $this->office = User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+15555550106']);
    foreach (['Arabic', 'Science', 'Quran'] as $name) SchoolSubject::create(['masjid_id' => $this->org->id, 'name' => $name]);
});

afterEach(fn () => app(TenantContext::class)->forgetTenant());

it('refuses ON kind changes with restrictions or saved subject work', function (string $reason, bool $http) {
    ClassSubjectInitializer::run($this->org, false, true);
    if ($reason === 'restricted') $this->staff->forceFill(['class_subject_ids' => [ClassSubject::where('group_id', $this->group->id)->where('name', 'Arabic')->value('id')]])->save();
    else ClassAssignment::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'title' => 'Practice work', 'subject' => 'Science', 'type' => 'classwork', 'assigned_on' => '2026-10-08', 'points_possible' => 10]);
    if ($http) {
        Sanctum::actingAs($this->office);
        $this->putJson("/api/admin/masjids/{$this->org->id}/groups/{$this->group->id}", ['kind' => 'general'])->assertStatus(422)->assertJsonFragment(['Keep this group as a class while it has subject restrictions or saved subject work.']);
    } else {
        expect(fn () => $this->group->forceFill(['kind' => 'general'])->save())->toThrow(\Illuminate\Validation\ValidationException::class);
    }
    expect($this->group->fresh()->kind)->toBe('class');
})->with(['restricted', 'work'])->with([true, false]);

it('keeps ON assignment authority for every nonclass kind even after a bypassed conversion', function (string $kind) {
    ClassSubjectInitializer::run($this->org, false, true);
    $arabic = ClassSubject::where('group_id', $this->group->id)->where('name', 'Arabic')->firstOrFail();
    $this->staff->forceFill(['class_subject_ids' => [$arabic->id]])->save();
    DB::table('groups')->where('id', $this->group->id)->update(['kind' => $kind]);
    $work = ClassAssignment::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'title' => 'Practice work', 'subject' => 'Science', 'type' => 'classwork', 'assigned_on' => '2026-10-08', 'points_possible' => 10]);
    Sanctum::actingAs($this->teacher);
    $url = "/api/teacher/masjids/{$this->org->id}/groups/{$this->group->id}";
    $this->getJson($url.'/assignments/'.$work->id)->assertNotFound();
    $this->getJson($url.'/hifz')->assertForbidden();
    expect(SubjectFence::limitsFor($this->teacher, $this->group->id))->not->toBeNull();
    expect(SubjectFence::allows(SubjectFence::limitsFor($this->teacher, $this->group->id), 'science'))->toBeFalse();
})->with(['general', 'halaqa', 'team', 'program', 'unknown-future-kind']);

it('does not use nonunique locking range reads in initializer discovery', function () {
    $source = file_get_contents(app_path('Support/ClassSubjectInitializer.php'));
    expect($source)->not->toMatch('/GroupStaff::where\([^;]+?->lockForUpdate\(\)->get\(\)/s');
    expect($source)->not->toMatch('/ClassSubject::where\([^;]+?->lockForUpdate\(\)->get\(\)/s');
});

it('supplies the workers required group insert columns from the actual migration schema', function () {
    $source = file_get_contents(base_path('tests/Support/classSubjectWorker.php'));
    preg_match('/Group::create\(\[(.*?)\]\)/s', $source, $match);
    preg_match_all("/'([a-z_]+)'\\s*=>/", $match[1], $keys);
    // masjid_id is supplied by BelongsToMasjid under the worker's bound context.
    $given = [...$keys[1], 'masjid_id'];
    $required = collect(\Illuminate\Support\Facades\Schema::getColumns('groups'))
        ->filter(fn ($column) => ! $column['nullable'] && $column['default'] === null && ! ($column['auto_increment'] ?? false))
        ->pluck('name')->all();
    expect(array_diff($required, $given))->toBe([]);
});

it('prints worker failure identifiers without values or raw SQL', function () {
    $sql = 'insert into `groups` (`name`, `kind`) values (?, ?)';
    $previous = new PDOException("Field 'slug' doesn't have a default value");
    $previous->errorInfo = ['HY000', 1364, "Field 'slug' doesn't have a default value"];
    $exception = new \Illuminate\Database\QueryException('class_subject_worker', $sql, ['private teacher name', 'class'], $previous);
    expect(\Tests\Support\ClassSubjectWorkerFailure::identifiers($exception))->toBe(['sqlstate' => 'HY000', 'driver_code' => 1364, 'table' => 'groups', 'column' => 'slug']);
});

it('supplies required migration columns on every feature insert and records the audited schema', function () {
    \Illuminate\Support\Facades\Mail::fake();
    Sanctum::actingAs($this->office);
    DB::flushQueryLog(); DB::enableQueryLog();
    try {
        ClassSubjectInitializer::run($this->org, false, true);
        $group = Group::factory()->create(['masjid_id' => $this->org->id, 'kind' => 'class']);
        $this->postJson("/api/admin/masjids/{$this->org->id}/teachers", ['name' => 'Practice Teacher', 'email' => 'new@example.invalid', 'phone' => '+15555550107', 'class_ids' => [$group->id]])->assertCreated();
        $this->postJson("/api/admin/masjids/{$this->org->id}/groups/{$group->id}/subjects", ['name' => 'Practice Subject', 'tool' => null, 'guide_subject' => null])->assertCreated();
        $contact = \App\Models\Contact::factory()->create(['masjid_id' => $this->org->id]);
        \App\Models\GroupMembership::create(['masjid_id' => $this->org->id, 'group_id' => $group->id, 'contact_id' => $contact->id, 'role' => 'member']);
        SchoolSubject::create(['masjid_id' => $this->org->id, 'name' => 'Practice Subject']);
        $queries = DB::getQueryLog();
    } finally { DB::disableQueryLog(); }
    $required = [];
    foreach (['groups', 'masjids', 'users', 'group_staff', 'school_subjects', 'class_subjects', 'masjid_user', 'group_memberships', 'contacts', 'masjid_capability_changes', 'account_invite_tokens', 'password_reset_tokens', 'model_has_roles'] as $table) {
        $required[$table] = collect(\Illuminate\Support\Facades\Schema::getColumns($table))
            ->filter(fn ($column) => ! $column['nullable'] && $column['default'] === null && ! ($column['auto_increment'] ?? false))
            ->pluck('name')->all();
    }
    $inserts = [];
    foreach ($queries as $query) {
        if (! preg_match('/\Ainsert(?: or ignore)? into ["`]?([a-z_]+)["`]?\s*\(([^)]*)\)/i', $query['query'], $match)) continue;
        $columns = array_map(fn ($column) => trim($column, ' "`'), explode(',', $match[2]));
        expect($required)->toHaveKey($match[1]);
        expect(array_values(array_diff($required[$match[1]], $columns)), $match[1])->toBe([]);
        $inserts[$match[1]] = array_values(array_unique([...($inserts[$match[1]] ?? []), ...$columns]));
    }
    foreach (['class_subjects', 'groups', 'group_staff', 'users', 'masjid_user', 'masjid_capability_changes'] as $table) expect($inserts)->toHaveKey($table);
    file_put_contents(base_path('artifacts/review2-insert-audit.json'), json_encode(['driver' => DB::getDriverName(), 'required' => $required, 'observed_insert_columns' => $inserts], JSON_PRETTY_PRINT)."\n");
});

it('supports a student in seven one-subject classes with class-local teacher assignments', function () {
    SchoolSubject::query()->delete();
    SchoolSubject::create(['masjid_id' => $this->org->id, 'name' => 'Arabic']);
    ClassSubjectInitializer::run($this->org, false, true);
    $contact = \App\Models\Contact::factory()->create(['masjid_id' => $this->org->id]);
    $groups = []; $ids = []; $memberships = [];
    for ($i = 0; $i < 7; $i++) {
        $group = Group::factory()->create(['masjid_id' => $this->org->id, 'kind' => 'class']);
        $subject = ClassSubject::where('group_id', $group->id)->sole();
        $memberships[] = \App\Models\GroupMembership::create(['masjid_id' => $this->org->id, 'group_id' => $group->id, 'contact_id' => $contact->id, 'role' => 'member'])->id;
        $groups[] = $group; $ids[$group->id] = [$subject->id];
    }
    Sanctum::actingAs($this->office);
    $this->putJson("/api/admin/masjids/{$this->org->id}/teachers/{$this->teacher->id}", ['name' => $this->teacher->name, 'class_ids' => array_keys($ids), 'class_subject_ids' => $ids])->assertOk();
    Sanctum::actingAs($this->teacher);
    foreach ($groups as $group) {
        $this->getJson("/api/teacher/masjids/{$this->org->id}/groups/{$group->id}/subjects")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Arabic');
        expect(SubjectFence::assignedIds($group->id, $this->teacher->id))->toBe($ids[$group->id]);
    }
    expect(array_unique($memberships))->toHaveCount(7);
});

it('dispatches model creation using the school the original tenant hook will store', function (bool $enabled, string $model) {
    $other = Masjid::create(['name' => 'Other Practice School', 'email' => 'other@example.invalid', 'phone' => '+15555550108', 'country_id' => '1', 'city_id' => '1', 'address' => 'Practice', 'latitude' => 0, 'longitude' => 0, 'org_type' => 'school']);
    if ($enabled) ClassSubjectInitializer::run($this->org, false, true);
    else ClassSubjectInitializer::run($other, false, true);
    app(TenantContext::class)->set($this->org->id);
    $class = $model === 'staff' ? Group::factory()->create(['masjid_id' => $this->org->id, 'kind' => 'class']) : null;
    DB::flushQueryLog(); DB::enableQueryLog();
    try {
        if ($model === 'group') {
            $row = Group::create(['masjid_id' => $other->id, 'name' => 'Bound Practice Class', 'slug' => 'bound-practice-class', 'kind' => 'class']);
        } else {
            $row = GroupStaff::create(['masjid_id' => $other->id, 'group_id' => $class->id, 'user_id' => $this->teacher->id, 'role' => 'teacher']);
        }
        $queries = DB::getQueryLog();
    } finally { DB::disableQueryLog(); }
    expect($row->fresh()->masjid_id)->toBe($this->org->id);
    if ($model === 'group') expect($row->fresh()->class_subjects_initialized_at !== null)->toBe($enabled);
    else expect($row->fresh()->class_subjects_mapped_at !== null)->toBe($enabled);
    if (! $enabled) {
        expect(collect($queries)->filter(fn ($q) => str_contains(strtolower($q['query']), 'for update')))->toHaveCount(0);
        expect(collect($queries)->filter(fn ($q) => str_starts_with(strtolower($q['query']), 'select') && ! str_contains($q['query'], 'capability_overrides')))->toHaveCount(0);
    }
    if ($enabled) expect(ClassSubjectInitializer::ready($this->org->fresh()))->toBeTrue();
})->with([false, true])->with(['group', 'staff']);
