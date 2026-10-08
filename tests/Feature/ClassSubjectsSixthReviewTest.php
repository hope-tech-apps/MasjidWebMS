<?php

use App\Models\{ClassAssignment, ClassSubject, Group, GroupStaff, LessonPlan, Masjid, MasjidUser, SchoolSubject, User};
use App\Support\{CapabilityWriter, ClassSubjectInitializer, SubjectFence, TenantContext};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    $this->org = Masjid::create(['name' => 'Practice School', 'email' => uniqid().'@example.invalid', 'phone' => '+15555550100', 'country_id' => '1', 'city_id' => '1', 'address' => 'Practice', 'latitude' => 0, 'longitude' => 0, 'org_type' => 'school', 'crm_enabled' => true]);
    $this->group = Group::factory()->create(['masjid_id' => $this->org->id, 'kind' => 'class']);
    $this->teacher = User::factory()->create(['type' => 'Teacher', 'phone' => '+15555550101']);
    $this->office = User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+15555550102']);
    MasjidUser::create(['masjid_id' => $this->org->id, 'user_id' => $this->teacher->id, 'role' => 'teacher', 'is_default' => true]);
    $this->staff = GroupStaff::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'user_id' => $this->teacher->id, 'subjects' => ['arabic']]);
    foreach (['Arabic', 'Science', 'ELA', "Qur'an"] as $name) SchoolSubject::create(['masjid_id' => $this->org->id, 'name' => $name]);
    $this->base = "/api/teacher/masjids/{$this->org->id}/groups/{$this->group->id}";
    $this->activate = fn () => ClassSubjectInitializer::run($this->org, false, true);
    $this->work = fn ($subject) => ClassAssignment::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'title' => 'Practice', 'subject' => $subject, 'assigned_on' => '2026-10-08', 'scale' => 'points', 'points_possible' => 10]);
    $this->plan = fn ($subject) => LessonPlan::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'body' => 'Practice', 'subject' => $subject, 'session_date' => '2026-10-08']);
});

afterEach(fn () => app(TenantContext::class)->forgetTenant());

it('never remembers console response decisions across the real worker daemon reset', function (bool $otherSchool) {
    ($this->activate)();
    $off = $otherSchool ? Masjid::create(['name' => 'Other', 'email' => uniqid().'@example.invalid', 'phone' => '+15555550109', 'country_id' => '1', 'city_id' => '1', 'address' => 'Practice', 'latitude' => 0, 'longitude' => 0, 'org_type' => 'school']) : $this->org;
    Review6ModeJob::$results = [];
    config(['queue.connections.review6' => ['driver' => 'database', 'connection' => 'sqlite', 'table' => 'jobs', 'queue' => 'review6', 'retry_after' => 90, 'after_commit' => false]]);
    $queue = app('queue')->connection('review6');
    $queue->push(new Review6ModeJob($this->org->id, true, ! $otherSchool));
    $queue->push(new Review6ModeJob($off->id, false, false));
    $worker = app('queue.worker');
    $worker->setCache(app('cache')->store());
    $status = $worker->daemon('review6', 'review6', new \Illuminate\Queue\WorkerOptions(memory: 512, timeout: 0, sleep: 0, maxJobs: 2, stopWhenEmpty: true));
    expect($status)->toBe(0);
    expect(Review6ModeJob::$results)->toBe([true, false]);
    expect(request()->attributes->has('class_subjects_decisions'))->toBeFalse();
})->with([false, true]);

class Review6ModeJob implements \Illuminate\Contracts\Queue\ShouldQueue
{
    public static array $results = [];
    public function __construct(public int $orgId, public bool $remember, public bool $flip) {}
    public function handle(): void
    {
        if ($this->remember) \App\Support\ClassSubjectMode::rememberResponseMode($this->orgId, true);
        self::$results[] = \App\Support\ClassSubjectMode::responseEnabled($this->orgId);
        if ($this->flip) DB::table('masjids')->where('id', $this->orgId)->update(['capability_overrides' => json_encode(['class_subjects' => false])]);
    }
}

it('owns and clears the memo across repeated HTTP kernel requests and organisations', function () {
    ($this->activate)();
    $off = Masjid::create(['name' => 'Other', 'email' => uniqid().'@example.invalid', 'phone' => '+15555550109', 'country_id' => '1', 'city_id' => '1', 'address' => 'Practice', 'latitude' => 0, 'longitude' => 0, 'org_type' => 'school']);
    $routes = app('router')->getRoutes();
    app('router')->setRoutes(new \Illuminate\Routing\RouteCollection());
    \Illuminate\Support\Facades\Route::get('/review6/mode', function () use ($off) {
        $first = \App\Support\ClassSubjectMode::enabled($this->org->id);
        \App\Support\ClassSubjectMode::rememberResponseMode($this->org->id, $first);
        return response()->json([$first, \App\Support\ClassSubjectMode::responseEnabled($off->id)]);
    });
    foreach ($routes as $route) app('router')->getRoutes()->add($route);
    $this->getJson('/review6/mode')->assertExactJson([true, false]);
    expect(request()->attributes->has('class_subjects_decisions'))->toBeFalse();
    DB::table('masjids')->where('id', $this->org->id)->update(['capability_overrides' => json_encode(['class_subjects' => false])]);
    $this->getJson('/review6/mode')->assertExactJson([false, false]);
});

it('keeps explicit joint school subjects and matches guide aliases when seeding', function (string $case) {
    SchoolSubject::where('masjid_id', $this->org->id)->delete();
    [$names, $guide, $skipped] = match ($case) {
        'explicit' => [['Reading', 'Writing', 'Reading and Writing'], 'Reading and Writing', false],
        'canonical' => [["Qur'an", 'Islamic Studies'], "Qur'an & Islamic Studies", true],
        'alias' => [["Qur'an", 'Arabic Language'], "Qur'an & Arabic", true],
        'missing part' => [['Health'], 'Health & PE', false],
    };
    $this->staff->update(['subjects' => null]);
    foreach ($names as $name) SchoolSubject::create(['masjid_id' => $this->org->id, 'name' => $name]);
    \App\Models\CurriculumWeek::create(['masjid_id' => $this->org->id, 'grade_label' => 'Grade 1', 'subject' => $guide, 'week_no' => 1, 'focus' => 'Practice']);
    $preview = ClassSubjectInitializer::run($this->org, true);
    expect(array_column($preview[0]['combined_columns'], 'name'))->toBe($skipped ? [$guide] : []);
    ($this->activate)();
    $expected = array_values(array_unique($skipped ? $names : [...$names, $guide]));
    expect(ClassSubject::orderBy('position')->pluck('name')->all())->toBe($expected);
})->with(['explicit', 'canonical', 'alias', 'missing part']);

it('retains all 25 newest capability changes including subject history on and off', function (bool $on) {
    ($this->activate)();
    foreach (range(1, 25) as $i) \App\Models\MasjidCapabilityChange::create(['masjid_id' => $this->org->id, 'capability' => 'web_pages', 'enabled_before' => false, 'enabled_after' => true, 'created_at' => now()->addSeconds($i)]);
    $last = \App\Models\MasjidCapabilityChange::create(['masjid_id' => $this->org->id, 'capability' => 'class_subjects', 'enabled_before' => true, 'enabled_after' => false, 'created_at' => now()->addSeconds(26)]);
    if (! $on) $this->org->fresh()->forceFill(['capability_overrides' => ['class_subjects' => false]])->save();
    Sanctum::actingAs($this->office);
    $history = $this->getJson("/api/admin/masjids/{$this->org->id}/capabilities")->assertOk()->json('data.history');
    expect($history)->toHaveCount(25);
    expect($history[0]['id'])->toBe($last->id);
    expect(array_column($history, 'capability'))->toContain('class_subjects');
})->with([false, true]);

it('resolves id only saved work before create update and by day collisions', function (string $path, bool $name) {
    ($this->plan)(null);
    $row = $path === 'update' ? LessonPlan::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'body' => 'Other day', 'session_date' => '2026-10-09']) : null;
    ($this->activate)();
    $arabic = ClassSubject::where('name', 'Arabic')->firstOrFail();
    Sanctum::actingAs($this->teacher, ['staff']);
    $body = ['session_date' => '2026-10-08', 'body' => 'Named', 'class_subject_id' => $arabic->id] + ($name ? ['subject' => 'Arabic'] : []);
    $response = $path === 'create' ? $this->postJson($this->base.'/lesson-plans', $body) : $this->putJson($this->base.'/lesson-plans'.($row ? '/'.$row->id : ''), $body);
    if ($response->status() !== 200) file_put_contents(base_path('artifacts/review6-plan-response.json'), $response->getContent());
    $response->assertOk()->assertJsonPath('data.subject', 'Arabic')->assertJsonPath('data.class_subject_id', $arabic->id);
    expect(LessonPlan::whereDate('session_date', '2026-10-08')->count())->toBe(2);
})->with(['create', 'update', 'by day'])->with([false, true]);

it('groups ON marks by identity using current headings with snapshot null fallbacks', function (bool $reuse) {
    $first = ($this->work)('Arabic');
    ($this->activate)();
    $arabic = ClassSubject::where('name', 'Arabic')->firstOrFail();
    $arabic->update(['name' => 'Renamed']);
    if ($reuse) ClassSubject::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'name' => 'Arabic']);
    $second = ($this->work)($reuse ? 'Arabic' : 'Renamed');
    $contact = \App\Models\Contact::factory()->create(['masjid_id' => $this->org->id]);
    $member = \App\Models\GroupMembership::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'contact_id' => $contact->id, 'role' => 'member']);
    foreach ([[$first, 10], [$second, 0]] as [$work, $earned]) \App\Models\AssignmentScore::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'class_assignment_id' => $work->id, 'group_membership_id' => $member->id, 'status' => 'scored', 'points_earned' => $earned]);
    $summary = \App\Support\GradeRecord::summaryForClassSubjects($member->id);
    expect(array_column($summary['by_subject'], 'subject'))->toBe($reuse ? ['Arabic', 'Renamed'] : ['Renamed']);
    expect(array_column($summary['by_subject'], 'percent'))->toBe($reuse ? [0.0, 100.0] : [50.0]);
    expect($first->fresh()->subject)->toBe('Arabic');
})->with([false, true]);

it('warns when all school positions tie and seeds in entry id order', function () {
    SchoolSubject::where('masjid_id', $this->org->id)->delete();
    $this->staff->update(['subjects' => null]);
    foreach (['Writing', 'Reading'] as $name) SchoolSubject::create(['masjid_id' => $this->org->id, 'name' => $name, 'position' => 0]);
    $this->artisan('class-subjects:initialize', ['--masjid' => $this->org->id, '--dry-run' => true])->expectsOutputToContain('subject list has no order set')->assertSuccessful();
    ($this->activate)();
    expect(ClassSubject::orderBy('position')->pluck('name')->all())->toBe(['Writing', 'Reading']);
});

it('previews 100 restricted teachers and 10000 work rows within bounded memory and queries', function () {
    $process = new \Symfony\Component\Process\Process([PHP_BINARY, '-d', 'memory_limit=128M', base_path('tests/Support/classSubjectPreviewMeasurement.php')], base_path());
    $process->setTimeout(60);
    $process->run();
    expect($process->getExitCode())->toBe(0, $process->getErrorOutput().$process->getOutput());
    $measurements = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    foreach ($measurements as $kind => $metrics) {
        file_put_contents(base_path('artifacts/review6-preview-'.$kind.'.json'), json_encode($metrics, JSON_PRETTY_PRINT)."\n");
        expect($metrics['staff'])->toBe(100);
        expect($metrics['work'])->toBe(10000);
        expect($metrics['losses'])->toBe(100);
        expect($metrics['peak_bytes'])->toBeLessThan(128 * 1024 * 1024);
        expect($metrics['extra_bytes'])->toBeLessThan(32 * 1024 * 1024);
        expect($metrics['queries'])->toBeLessThanOrEqual(20);
        expect($metrics['read_only'])->toBeTrue();
    }
});

it('requires acceptance for every edited restricted holder combination', function (bool $english) {
    $this->staff->update(['subjects' => null]);
    SchoolSubject::create(['masjid_id' => $this->org->id, 'name' => 'Islamic Studies']);
    ($this->activate)();
    if (! $english) ClassSubject::where('tool', 'english_letters')->firstOrFail()->update(['tool' => null]);
    $subjects = ClassSubject::orderBy('id')->get();
    $labels = ['hifdh', 'arabic_letters', 'english_letters', 'islamic studies', 'other'];
    $ids = [$subjects->firstWhere('tool', 'hifdh')->id, $subjects->firstWhere('tool', 'arabic_letters')->id,
        $subjects->firstWhere('name', 'ELA')->id, $subjects->firstWhere('name', 'Islamic Studies')->id, $subjects->firstWhere('name', 'Science')->id];
    $table = [];
    foreach (range(0, 31) as $mask) {
        $chosen = []; $names = [];
        foreach ($ids as $bit => $id) if ($mask & (1 << $bit)) { $chosen[] = $id; $names[] = $labels[$bit]; }
        DB::table('group_staff')->where('id', $this->staff->id)->update(['class_subject_ids' => json_encode($chosen), 'class_subject_ids_edited_at' => now()]);
        $report = \App\Support\ClassSubjectDisabler::run($this->org->fresh(), true);
        // Every explicit restricted office edit needs acceptance regardless of holders or guide links.
        $table[] = ['english_holder' => $english, 'choice' => $names, 'expressible' => $report['assignments'][0]['expressible']];
        expect($report['assignments'][0]['expressible'])->toBeFalse();
        expect($report['blocked'])->not->toBeEmpty();
    }
    DB::table('group_staff')->where('id', $this->staff->id)->update(['class_subject_ids' => null]);
    $all = \App\Support\ClassSubjectDisabler::run($this->org->fresh(), true)['assignments'][0];
    expect($all['expressible'])->toBeTrue(); // Edited NULL is unrestricted in either state.
    foreach ($subjects as $subject) $subject->update(['guide_subject' => $subject->name]);
    expect(\App\Support\ClassSubjectDisabler::run($this->org->fresh(), true)['assignments'][0]['expressible'])->toBeTrue();
    file_put_contents(base_path('artifacts/review6-equivalence-'.($english ? 'with' : 'without').'-english.json'), json_encode($table, JSON_PRETTY_PRINT)."\n");
})->with([false, true]);

it('checks family summary dispatch and ON null linked fallback groups', function () {
    $first = ($this->work)('Arabic'); ($this->activate)();
    $subject = ClassSubject::where('name', 'Arabic')->firstOrFail(); $subject->update(['name' => 'Renamed']);
    $contact = \App\Models\Contact::factory()->create(['masjid_id' => $this->org->id]);
    $member = \App\Models\GroupMembership::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'contact_id' => $contact->id, 'role' => 'member']);
    \App\Models\AssignmentScore::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'class_assignment_id' => $first->id, 'group_membership_id' => $member->id, 'status' => 'scored', 'points_earned' => 10]);
    // Family callers use the public dispatcher; linked work must get current headings.
    app(TenantContext::class)->set($this->org->id);
    expect(\App\Support\GradeRecord::summaryFor($member->id)['by_subject'][0]['subject'])->toBe('Renamed');
    $other = ($this->work)('Renamed');
    DB::table('class_assignments')->where('id', $other->id)->update(['class_subject_id' => null, 'subject' => 'Arabic', 'subject_key' => 'arabic']);
    \App\Models\AssignmentScore::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'class_assignment_id' => $other->id, 'group_membership_id' => $member->id, 'status' => 'scored', 'points_earned' => 0]);
    expect(array_column(\App\Support\GradeRecord::summaryForClassSubjects($member->id)['by_subject'], 'subject'))->toBe(['Arabic', 'Renamed']);
});

it('requires acceptance for edited restrictions even when legacy grants appear equivalent', function () {
    $this->staff->update(['subjects' => null]); ($this->activate)();
    $quran = ClassSubject::where('tool', 'hifdh')->firstOrFail();
    SchoolSubject::where('masjid_id', $this->org->id)->where('name', '!=', "Qur'an")->delete();
    $quran->update(['guide_subject' => $quran->name]);
    $ids = [$quran->id];
    foreach (["Qur'an & Islamic Studies", "Qur'an and Islamic Studies"] as $name) $ids[] = ClassSubject::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'name' => $name, 'guide_subject' => $name])->id;
    $this->staff->fresh()->update(['class_subject_ids' => $ids]);
    $report = \App\Support\ClassSubjectDisabler::run($this->org->fresh(), true);
    expect($report['assignments'][0]['expressible'])->toBeFalse();
    expect($report['assignments'][0]['legacy'])->toBeNull();
    $work = ($this->work)("Qur'an");
    DB::table('class_assignments')->where('id', $work->id)->update(['class_subject_id' => null]);
    expect(\App\Support\ClassSubjectDisabler::run($this->org->fresh(), true)['assignments'][0]['expressible'])->toBeFalse();
    DB::table('class_assignments')->where('id', $work->id)->update(['class_subject_id' => $quran->id]);
    \App\Models\CurriculumWeek::create(['masjid_id' => $this->org->id, 'grade_label' => 'Grade 1', 'subject' => 'Science', 'week_no' => 1, 'focus' => 'Practice']);
    expect(\App\Support\ClassSubjectDisabler::run($this->org->fresh(), true)['assignments'][0]['expressible'])->toBeFalse();
});

it('counts school subject usage by current linked identity and unlinked saved text only ON', function (bool $on) {
    $first = ($this->work)('Arabic'); ($this->activate)();
    ClassSubject::where('name', 'Science')->firstOrFail()->update(['name' => 'Other Science']);
    ClassSubject::where('name', 'Arabic')->firstOrFail()->update(['name' => 'Science']);
    ClassSubject::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'name' => 'Arabic']);
    ($this->work)('Arabic');
    $third = ($this->work)('Arabic');
    DB::table('class_assignments')->where('id', $third->id)->update(['class_subject_id' => null]);
    if (! $on) $this->org->fresh()->forceFill(['capability_overrides' => ['class_subjects' => false]])->save();
    Sanctum::actingAs($this->office);
    $data = collect($this->getJson("/api/admin/masjids/{$this->org->id}/school-subjects")->assertOk()->json('data'))->keyBy('name');
    expect($data['Arabic']['work_count'])->toBe($on ? 2 : 3);
    expect($data['Science']['work_count'])->toBe($on ? 1 : 0);
})->with([false, true]);

it('never merges a NULL saved key resembling the linked grouping identity', function () {
    $first = ($this->work)('Arabic'); ($this->activate)();
    $id = $first->fresh()->class_subject_id;
    $other = ($this->work)('Arabic');
    DB::table('class_assignments')->where('id', $other->id)->update(['class_subject_id' => null, 'subject' => 'id:'.$id, 'subject_key' => 'id:'.$id]);
    $contact = \App\Models\Contact::factory()->create(['masjid_id' => $this->org->id]);
    $member = \App\Models\GroupMembership::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'contact_id' => $contact->id, 'role' => 'member']);
    foreach ([$first, $other] as $work) \App\Models\AssignmentScore::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'class_assignment_id' => $work->id, 'group_membership_id' => $member->id, 'status' => 'scored', 'points_earned' => 5]);
    expect(\App\Support\GradeRecord::summaryForClassSubjects($member->id)['by_subject'])->toHaveCount(2);
});

it('clears mode after exceptions and rechecks a reused HTTP controller after switching off', function () {
    $work = ($this->work)('Arabic'); ($this->activate)();
    Sanctum::actingAs($this->teacher, ['staff']);
    $this->getJson($this->base.'/assignments')->assertOk()->assertJsonPath('data.0.class_subject_id', $work->fresh()->class_subject_id);
    $this->org->fresh()->forceFill(['capability_overrides' => ['class_subjects' => false]])->save();
    $this->getJson($this->base.'/assignments')->assertOk()->assertJsonMissingPath('data.0.class_subject_id');
    $middleware = new \App\Http\Middleware\ClassSubjectHttpRequest;
    $request = request();
    expect(fn () => $middleware->handle($request, function () {
        \App\Support\ClassSubjectMode::rememberResponseMode($this->org->id, true);
        throw new RuntimeException('Fixture');
    }))->toThrow(RuntimeException::class);
    foreach (['class_subjects_http', 'class_subjects_decisions', 'class_subjects_rows'] as $key) expect($request->attributes->has($key))->toBeFalse();
    $middleware->handle($request, function () {
        expect(\App\Support\ClassSubjectMode::enabled($this->org->id))->toBeFalse();
        return response()->noContent();
    });
});

it('matches saved text while keeping exact orphan counts when SQL collation ignores trailing spaces', function () {
    ($this->work)('Arabic');
    $padded = ($this->work)('Arabic');
    DB::table('class_assignments')->where('id', $padded->id)->update(['subject_key' => 'arabic ']);
    ($this->work)('History');
    $orphan = ($this->work)('History');
    DB::table('class_assignments')->where('id', $orphan->id)->update(['subject_key' => 'history ']);
    // Simulate MySQL PAD SPACE equality, while retaining real SQLite SELECTs.
    DB::statement('ALTER TABLE class_assignments RENAME TO review6_assignment_rows');
    DB::statement('CREATE VIEW class_assignments AS SELECT masjid_id, group_id, subject, subject_key COLLATE RTRIM AS subject_key, class_subject_id, class_subject_link_checked_at FROM review6_assignment_rows');
    $preview = ClassSubjectInitializer::run($this->org, true)[0];
    expect($preview['saved_work_links']['Arabic'])->toBe(2);
    expect($preview['orphaned_work'])->toBe(['history' => 1, 'history ' => 1]);
});

it('restores unchanged provenance even when ON curriculum differs from OFF', function () {
    $this->staff->update(['subjects' => null]); ($this->activate)();
    Sanctum::actingAs($this->teacher, ['staff']);
    $url = "/api/teacher/masjids/{$this->org->id}/curriculum";
    $on = $this->getJson($url)->assertOk()->json('data.subjects');
    $overrides = $this->org->fresh()->capability_overrides;
    DB::table('masjids')->where('id', $this->org->id)->update(['capability_overrides' => json_encode(['class_subjects' => false])]);
    $off = $this->getJson($url)->assertOk()->json('data.subjects');
    expect($on)->not->toBe($off);
    DB::table('masjids')->where('id', $this->org->id)->update(['capability_overrides' => json_encode($overrides)]);
    expect(\App\Support\ClassSubjectDisabler::run($this->org->fresh(), true)['assignments'][0]['expressible'])->toBeTrue();
});
