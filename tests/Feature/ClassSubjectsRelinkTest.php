<?php

use App\Models\{ClassAssignment, ClassSubject, Group, GroupStaff, LessonPlan, Masjid, MasjidUser, SchoolSubject, User};
use App\Support\{ClassSubjectInitializer, SubjectKey, TenantContext};
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Artisan, DB, Event};
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->org = Masjid::create(['name' => 'Practice School', 'email' => uniqid().'@example.invalid', 'phone' => '+15555550100', 'country_id' => '1', 'city_id' => '1', 'address' => 'Practice', 'latitude' => 0, 'longitude' => 0, 'org_type' => 'school', 'crm_enabled' => true]);
    $this->group = Group::factory()->create(['masjid_id' => $this->org->id, 'kind' => 'class', 'name' => 'Practice Class']);
    $this->plan = fn ($text, $group = null, $date = '2026-10-08') => LessonPlan::create(['masjid_id' => ($group ?? $this->group)->masjid_id, 'group_id' => ($group ?? $this->group)->id, 'subject' => $text, 'body' => 'Practice', 'session_date' => $date]);
    $this->work = fn ($text, $group = null) => ClassAssignment::create(['masjid_id' => ($group ?? $this->group)->masjid_id, 'group_id' => ($group ?? $this->group)->id, 'subject' => $text, 'title' => 'Practice', 'assigned_on' => '2026-10-08', 'scale' => 'points', 'points_possible' => 10]);
    $this->subject = fn ($text, $group = null, $hidden = false) => ClassSubject::create(['masjid_id' => ($group ?? $this->group)->masjid_id, 'group_id' => ($group ?? $this->group)->id, 'name' => $text, 'hidden_at' => $hidden ? now() : null]);
    $this->activate = fn () => ClassSubjectInitializer::run($this->org, false, true);
});

afterEach(fn () => app(TenantContext::class)->forgetTenant());

dataset('saved subject spellings', [
    'identical straight apostrophes' => ["Qur'an", "Qur'an"],
    'identical curly apostrophes' => ["Qur’an", "Qur’an"],
    'straight apostrophe' => ["Qur'an", 'Quran'],
    'left curly apostrophe' => ["Qur\u{2018}an", 'Quran'],
    'right curly apostrophe' => ["Qur\u{2019}an", 'Quran'],
    'turned comma' => ["Qur\u{02BB}an", 'Quran'],
    'modifier apostrophe' => ["Qur\u{02BC}an", 'Quran'],
    'right half ring' => ["Qur\u{02BE}an", 'Quran'],
    'left half ring' => ["Qur\u{02BF}an", 'Quran'],
    'ELA alias' => ['ELA', 'English Language Arts'],
    'Arabic alias' => ['Arabic Language', 'Arabic'],
    'whitespace and simple case' => ["  İSCIENCE\u{00A0}\tLAB  ", 'İscience Lab'],
]);

it('uses saved text in activation preview and fresh activation without rekeying either work table', function (string $text, string $name) {
    SchoolSubject::create(['masjid_id' => $this->org->id, 'name' => $name]);
    $plan = ($this->plan)($text); $work = ($this->work)($text);
    $before = [$plan->subject_key, $work->subject_key];
    expect($before)->toBe([LessonPlan::subjectKeyFor($text), SubjectKey::for($text)]);
    $preview = ClassSubjectInitializer::run($this->org, true);
    expect($preview[0]['saved_work_links'])->toBe([$name => 2]);
    expect($plan->fresh()->class_subject_id)->toBeNull();
    ($this->activate)();
    $subject = ClassSubject::where('group_id', $this->group->id)->sole();
    expect($plan->fresh()->class_subject_id)->toBe($subject->id);
    expect($work->fresh()->class_subject_id)->toBe($subject->id);
    expect([$plan->fresh()->subject_key, $work->fresh()->subject_key])->toBe($before);
})->with('saved subject spellings');

it('uses the corrected matcher for explicit office attach', function (string $text, string $name) {
    $plan = ($this->plan)($text); $work = ($this->work)($text);
    ($this->activate)();
    expect($plan->fresh()->class_subject_id)->toBeNull();
    Sanctum::actingAs(User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+15555550102']));
    $id = $this->postJson("/api/admin/masjids/{$this->org->id}/groups/{$this->group->id}/subjects", ['name' => $name, 'attach_saved_work' => true])
        ->assertCreated()->json('data.id');
    expect($plan->fresh()->class_subject_id)->toBe($id);
    expect($work->fresh()->class_subject_id)->toBe($id);
})->with('saved subject spellings');

it('relinks previously examined text and ignores the stored key without changing snapshots', function (string $text, string $name) {
    $plan = ($this->plan)($text); $work = ($this->work)($text);
    ($this->activate)();
    $subject = ($this->subject)($name);
    // A stale/imported stored key must not decide attachment; stored OFF columns remain untouched.
    DB::table('class_assignments')->where('id', $work->id)->update(['subject_key' => 'stale import key', 'deleted_at' => now()]);
    $before = [$plan->fresh()->getAttributes(), $work->fresh()->getAttributes()];
    $this->artisan('class-subjects:relink', ['--masjid' => $this->org->id])->expectsOutputToContain("LINK {$name}: 2 items")->assertSuccessful();
    foreach ([$plan, $work] as $i => $row) {
        expect($row->fresh()->class_subject_id)->toBe($subject->id);
        $attributes = $row->fresh()->getAttributes();
        unset($attributes['class_subject_id'], $attributes['class_subject_link_checked_at']);
        unset($before[$i]['class_subject_id'], $before[$i]['class_subject_link_checked_at']);
        expect($attributes)->toBe($before[$i]);
    }
})->with('saved subject spellings');

it('repairs only unique same class links including hidden subjects and reports exact saved texts', function () {
    $other = Group::factory()->create(['masjid_id' => $this->org->id, 'kind' => 'class', 'name' => 'Other Class']);
    $quran = ($this->plan)("Qur'an"); $hiddenWork = ($this->work)("Qur’an");
    $ambiguous = ($this->plan)('ELA'); $missing = ($this->plan)('Geography');
    $punctuation = ($this->plan)('Qur`an'); $general = ($this->plan)(null); $generalWork = ($this->work)(null);
    $existing = ($this->plan)('Science');
    SchoolSubject::create(['masjid_id' => $this->org->id, 'name' => 'Science']);
    ($this->activate)();
    $originalLink = $existing->fresh()->class_subject_id;
    $hidden = ($this->subject)('Quran', null, true);
    ($this->subject)('Geography', $other);
    ($this->subject)('ELA');
    // Simulate pre-existing alias ambiguity, otherwise refused by today's model writer.
    DB::table('class_subjects')->insert(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'name' => 'English Language Arts', 'name_key' => 'english language arts', 'position' => 10]);
    $orphanBefore = array_map(fn ($row) => $row->fresh()->getAttributes(), [$ambiguous, $missing, $punctuation, $general, $generalWork]);
    $existingBefore = $existing->fresh()->getAttributes();
    $this->group->delete();
    $this->artisan('class-subjects:relink', ['--masjid' => $this->org->id])
        ->expectsOutputToContain('Class Practice Class')->expectsOutputToContain('LINK Quran: 2 items')
        ->expectsOutputToContain('UNLINKED "ELA": 1 items')->expectsOutputToContain('UNLINKED "Geography": 1 items')
        ->expectsOutputToContain('UNLINKED "Qur`an": 1 items')->expectsOutputToContain('UNLINKED null: 2 items')->assertSuccessful();
    expect($quran->fresh()->class_subject_id)->toBe($hidden->id);
    expect($hiddenWork->fresh()->class_subject_id)->toBe($hidden->id);
    expect($existing->fresh()->class_subject_id)->toBe($originalLink);
    expect($existing->fresh()->getAttributes())->toBe($existingBefore);
    expect(array_map(fn ($row) => $row->fresh()->getAttributes(), [$ambiguous, $missing, $punctuation, $general, $generalWork]))->toBe($orphanBefore);
    $after = DB::table('lesson_plans')->get()->toJson().DB::table('class_assignments')->get()->toJson();
    $this->artisan('class-subjects:relink', ['--masjid' => $this->org->id])->expectsOutputToContain('0 items linked')->assertSuccessful();
    expect(DB::table('lesson_plans')->get()->toJson().DB::table('class_assignments')->get()->toJson())->toBe($after);
});

it('previews relinking with no writes transactions or locks and isolates the requested tenant', function () {
    $plan = ($this->plan)("Qur'an"); $curly = ($this->plan)("Qur’an"); $general = ($this->plan)(null);
    $missing = ($this->work)('History'); ($this->activate)(); ($this->subject)('Quran', null, true);
    $foreign = Masjid::create(['name' => 'Other School', 'email' => uniqid().'@example.invalid', 'phone' => '+15555550101', 'country_id' => '1', 'city_id' => '1', 'address' => 'Practice', 'latitude' => 0, 'longitude' => 0, 'org_type' => 'school']);
    $foreignGroup = Group::factory()->create(['masjid_id' => $foreign->id, 'kind' => 'class']);
    $foreignPlan = ($this->plan)("Qur'an", $foreignGroup); ($this->subject)('Quran', $foreignGroup);
    DB::table('masjids')->where('id', $foreign->id)->update(['capability_overrides' => json_encode(['class_subjects' => true])]);
    $tables = ['masjids', 'groups', 'class_subjects', 'lesson_plans', 'class_assignments', 'masjid_capability_changes'];
    $snapshot = fn () => array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(), $tables);
    $before = $snapshot(); $queries = []; $transactions = []; $active = true;
    app(TenantContext::class)->set($foreign->id);
    Event::listen(TransactionBeginning::class, function () use (&$active, &$transactions) { if ($active) $transactions[] = true; });
    DB::listen(function ($query) use (&$active, &$queries) { if ($active) $queries[] = $query->sql; });
    $connection = DB::connection(); $grammar = $connection->getQueryGrammar();
    // SQLite normally erases lock clauses. Refuse lock requests before that erasure.
    $connection->setQueryGrammar(new class($connection) extends \Illuminate\Database\Query\Grammars\SQLiteGrammar {
        protected function compileLock(\Illuminate\Database\Query\Builder $query, $value)
        {
            throw new RuntimeException('Dry run requested a locking read.');
        }
    });
    try {
        expect(fn () => DB::table('masjids')->lockForUpdate()->toSql())->toThrow(RuntimeException::class, 'Dry run requested a locking read.');
        expect(Artisan::call('class-subjects:relink', ['--masjid' => $this->org->id, '--dry-run' => true]))->toBe(0);
        $output = Artisan::output();
    } finally { $active = false; $connection->setQueryGrammar($grammar); }
    expect($transactions)->toBe([]);
    expect($queries)->not->toBeEmpty();
    foreach ($queries as $sql) expect(strtolower($sql))->toStartWith('select')->not->toMatch('/for update|lock in share mode|for share/i');
    expect($snapshot())->toBe($before);
    expect($output)->toContain('DRY RUN', 'LINK Quran: 2 items', 'UNLINKED "History": 1 items', 'UNLINKED null: 1 items', 'No changes saved.');
    expect(app(TenantContext::class)->get())->toBe($foreign->id);
    file_put_contents(base_path('artifacts/relink-dry-run-sample.log'), $output);
    file_put_contents(base_path('artifacts/relink-dry-run-sql.log'), implode("\n", $queries)."\n");
    $this->artisan('class-subjects:relink', ['--masjid' => $this->org->id])->assertSuccessful();
    expect(LessonPlan::withoutMasjidScope()->findOrFail($plan->id)->class_subject_id)->not->toBeNull();
    expect(LessonPlan::withoutMasjidScope()->findOrFail($foreignPlan->id)->class_subject_id)->toBeNull();
});

it('restores older apostrophe plans to the subject limited teachers reads', function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    $teacher = User::factory()->create(['type' => 'Teacher', 'phone' => '+15555550102']);
    MasjidUser::create(['masjid_id' => $this->org->id, 'user_id' => $teacher->id, 'role' => 'teacher', 'is_default' => true]);
    GroupStaff::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'user_id' => $teacher->id, 'subjects' => ['quran']]);
    SchoolSubject::create(['masjid_id' => $this->org->id, 'name' => "Qur'an"]);
    $plan = ($this->plan)("Qur'an"); ($this->activate)();
    $subjectId = $plan->fresh()->class_subject_id;
    DB::table('lesson_plans')->where('id', $plan->id)->update(['class_subject_id' => null]);
    Sanctum::actingAs($teacher, ['staff']);
    $url = "/api/teacher/masjids/{$this->org->id}/groups/{$this->group->id}/lesson-plans?from=2026-10-08&to=2026-10-08";
    $this->getJson($url)->assertOk()->assertJsonCount(0, 'data.plans');
    $this->artisan('class-subjects:relink', ['--masjid' => $this->org->id])->assertSuccessful();
    $this->getJson($url)->assertOk()->assertJsonCount(1, 'data.plans')->assertJsonPath('data.plans.0.id', $plan->id);
    expect($plan->fresh()->class_subject_id)->toBe($subjectId);
});

it('refuses OFF schools and non schools without writes in both modes', function (string $kind, bool $dryRun) {
    $plan = ($this->plan)("Qur'an");
    if ($kind !== 'school') DB::table('masjids')->where('id', $this->org->id)->update(['org_type' => $kind, 'capability_overrides' => json_encode(['class_subjects' => true])]);
    $before = DB::table('lesson_plans')->get()->toJson();
    $this->artisan('class-subjects:relink', ['--masjid' => $this->org->id, '--dry-run' => $dryRun])->assertFailed();
    expect(DB::table('lesson_plans')->get()->toJson())->toBe($before);
})->with(['school', 'masjid', 'community'])->with([false, true]);

it('requires one valid existing school id', function ($id) {
    $options = $id === null ? [] : ['--masjid' => $id];
    $this->artisan('class-subjects:relink', $options)->assertFailed();
})->with([null, '', '0', '-1', 'abc', '1,2', '999999']);
