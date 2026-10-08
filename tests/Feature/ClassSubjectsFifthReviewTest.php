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

it('keeps report cards class staff wide in both states', function (bool $on) {
    if ($on) { ($this->activate)(); $this->staff->fresh()->update(['class_subject_ids' => []]); }
    $contact = \App\Models\Contact::factory()->create(['masjid_id' => $this->org->id, 'email' => null]);
    $member = \App\Models\GroupMembership::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'contact_id' => $contact->id, 'role' => 'member', 'grade_label' => 'Grade 1']);
    Sanctum::actingAs($this->teacher, ['staff']);
    $url = $this->base.'/members/'.$member->id.'/report-card?school_year=2026-2027&term=1';
    $subjects = $this->getJson($url)->assertOk()->json('data.subjects');
    foreach ($subjects as $subject) foreach ($subject['criteria'] as $criterion) {
        $this->putJson($url, ['marks' => [['id' => $criterion['id'], 'level' => 4]]])->assertOk();
        expect(\App\Models\ReportCardMark::findOrFail($criterion['id'])->level)->toBe(4);
    }
})->with([false, true]);

it('never translates known ids again even if holders and legacy values change off', function () {
    ($this->activate)(); $arabic = ClassSubject::where('name', 'Arabic')->firstOrFail(); $science = ClassSubject::where('name', 'Science')->firstOrFail();
    // Simulate an already-OFF school, independently of the new deliberate disable command.
    $this->org->fresh()->forceFill(['capability_overrides' => array_replace($this->org->fresh()->capability_overrides, ['class_subjects' => false])])->save();
    $arabic->update(['tool' => null]); $science->update(['tool' => 'arabic_letters']);
    $this->staff->fresh()->update(['subjects' => null]);
    $new = User::factory()->create(['type' => 'Teacher', 'phone' => '+15555550103']);
    $newStaff = GroupStaff::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'user_id' => $new->id, 'subjects' => ['arabic']]);
    ($this->activate)();
    expect($this->staff->fresh()->class_subject_ids)->toBe([$arabic->id]);
    expect($newStaff->fresh()->class_subject_ids)->toBe([$science->id]);
});

it('keeps office ids on reactivation instead of blocking', function () {
    ($this->activate)(); $science = ClassSubject::where('name', 'Science')->firstOrFail();
    $this->staff->fresh()->update(['class_subject_ids' => [$science->id]]);
    $this->org->fresh()->forceFill(['capability_overrides' => array_replace($this->org->fresh()->capability_overrides, ['class_subjects' => false])])->save();
    $this->artisan('class-subjects:initialize', ['--masjid' => $this->org->id, '--enable' => true])->assertSuccessful();
    expect($this->staff->fresh()->class_subject_ids)->toBe([$science->id]);
});

it('preserves absent subject on saved work edits', function (string $kind, bool $sameId) {
    $row = $kind === 'plan' ? ($this->plan)('Arabic') : ($this->work)('Arabic'); ($this->activate)();
    $before = $row->fresh(); Sanctum::actingAs($this->teacher, ['staff']);
    if ($kind === 'plan') $this->putJson($this->base.'/lesson-plans/'.$row->id, ['session_date' => '2026-10-08', 'body' => 'Edited'] + ($sameId ? ['class_subject_id' => $before->class_subject_id] : []))->assertOk();
    else $this->putJson($this->base.'/assignments/'.$row->id, ['title' => 'Edited', 'assigned_on' => '2026-10-08', 'scale' => 'points', 'points_possible' => 10] + ($sameId ? ['class_subject_id' => $before->class_subject_id] : []))->assertOk();
    expect($row->fresh()->subject)->toBe($before->subject);
    expect($row->fresh()->class_subject_id)->toBe($before->class_subject_id);
})->with(['plan', 'assignment'])->with([false, true]);

it('requires unrestricted assignment to make named work general on every writer', function (string $writer) {
    $plan = ($this->plan)('Arabic'); ($this->activate)(); $before = $plan->fresh();
    Sanctum::actingAs($this->teacher, ['staff']);
    if ($writer === 'model') expect(fn () => $plan->fresh()->update(['subject' => null]))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    else $this->putJson($this->base.'/lesson-plans/'.$plan->id, ['session_date' => '2026-10-08', 'body' => 'Edited', 'subject' => null])->assertForbidden();
    expect($plan->fresh()->class_subject_id)->toBe($before->class_subject_id);
    GroupStaff::withOfficeSubjectChoice(fn () => $this->staff->fresh()->update(['class_subject_ids' => null]));
    $this->putJson($this->base.'/lesson-plans/'.$plan->id, ['session_date' => '2026-10-08', 'body' => 'Edited', 'subject' => null])->assertOk();
    expect($plan->fresh()->class_subject_id)->toBeNull();
})->with(['model', 'request']);

it('bounds no class curriculum to the union of class guide links', function (bool $on) {
    foreach (['Arabic', 'Science'] as $subject) \App\Models\CurriculumWeek::create(['masjid_id' => $this->org->id, 'grade_label' => 'Grade 1', 'subject' => $subject, 'week_no' => 1, 'focus' => 'Practice']);
    $this->staff->update(['subjects' => null]); if ($on) {
        ($this->activate)(); ClassSubject::where('name', 'Science')->firstOrFail()->update(['guide_subject' => null]);
    }
    Sanctum::actingAs($this->teacher, ['staff']);
    $data = $this->getJson("/api/teacher/masjids/{$this->org->id}/curriculum?grade=Grade%201&subject=Science")->assertOk()->json('data');
    expect($data['subjects'])->toContain('Arabic');
    if ($on) expect($data['subjects'])->not->toContain('Science'); else expect($data['subjects'])->toContain('Science');
})->with([false, true]);

it('omits office subject metadata for teachers only', function () {
    ($this->activate)(); Sanctum::actingAs($this->teacher, ['staff']);
    $this->getJson($this->base.'/subjects')->assertOk()->assertJsonMissingPath('meta.guide_subjects')->assertJsonMissingPath('meta.tools');
    Sanctum::actingAs($this->office);
    $this->getJson("/api/admin/masjids/{$this->org->id}/groups/{$this->group->id}/subjects")->assertOk()->assertJsonStructure(['meta' => ['guide_subjects', 'tools']]);
});

it('serializes off rows with literal origin zero query counts', function (string $kind, int $count) {
    $rows = collect(range(1, $count))->map(function ($i) use ($kind) {
        return $kind === 'assignment' ? ($this->work)('Arabic') : LessonPlan::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'body' => 'Practice', 'session_date' => '2026-10-08', 'subject' => 'Subject '.$i]);
    });
    if ($kind === 'plan') $rows->each->load('attachments.groupResource');
    app(TenantContext::class)->set($this->org->id);
    $class = $kind === 'assignment' ? \App\Http\Controllers\Teacher\GradebookController::class : \App\Http\Controllers\Teacher\LessonPlanController::class;
    $controller = app($class); $method = new ReflectionMethod($controller, $kind); $method->setAccessible(true);
    // Origin serializers perform no SQL with eager-loaded plan attachments.
    DB::flushQueryLog(); DB::enableQueryLog();
    try { foreach ($rows as $row) $method->invoke($controller, $row); $queries = DB::getQueryLog(); }
    finally { DB::disableQueryLog(); }
    expect(count($queries))->toBe(0);
})->with(['assignment', 'plan'])->with([1, 50]);

it('initialization dry run never opens a transaction or issues a lock or write', function () {
    $connections = []; $transactions = []; $active = true;
    \Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\TransactionBeginning::class, function () use (&$transactions, &$active) { if ($active) $transactions[] = true; });
    DB::listen(function ($query) use (&$connections, &$active) { if ($active) $connections[] = $query->sql; });
    try { $this->artisan('class-subjects:initialize', ['--masjid' => $this->org->id, '--dry-run' => true])->assertSuccessful(); }
    finally { $active = false; }
    expect($transactions)->toBe([]);
    foreach ($connections as $sql) expect($sql)->toStartWith('select')->not->toMatch('/for update|lock in share mode/i');
    file_put_contents(base_path('artifacts/review5-initialize-dry-run-sql.log'), implode("\n", $connections)."\n");
});

it('does not ledger hidden noops and hides historical feature changes off', function () {
    CapabilityWriter::apply($this->org, ['class_subjects' => false], null);
    expect(\App\Models\MasjidCapabilityChange::where('masjid_id', $this->org->id)->where('capability', 'class_subjects')->count())->toBe(0);
    ($this->activate)();
    $this->org->fresh()->forceFill(['capability_overrides' => array_replace($this->org->fresh()->capability_overrides, ['class_subjects' => false])])->save();
    Sanctum::actingAs($this->office);
    $history = $this->getJson("/api/admin/masjids/{$this->org->id}/capabilities")->assertOk()->json('data.history');
    expect(array_column($history, 'capability'))->not->toContain('class_subjects');
});

it('stores all subjects as null and prints that choice', function () {
    $this->staff->update(['subjects' => []]);
    $this->artisan('class-subjects:initialize', ['--masjid' => $this->org->id, '--dry-run' => true])->expectsOutputToContain('-> [all subjects]')->assertSuccessful();
    ($this->activate)(); expect($this->staff->fresh()->class_subject_ids)->toBeNull();
});

it('skips joint guide columns only when every part is already represented', function (string $separator) {
    SchoolSubject::create(['masjid_id' => $this->org->id, 'name' => 'Islamic Studies']);
    $joint = "Qur'an{$separator}Islamic Studies";
    foreach (['Grade 1', 'Grade 2'] as $grade) \App\Models\CurriculumWeek::create(['masjid_id' => $this->org->id, 'grade_label' => $grade, 'subject' => $joint, 'week_no' => 1, 'focus' => 'Practice']);
    expect(\Illuminate\Support\Facades\Artisan::call('class-subjects:initialize', ['--masjid' => $this->org->id, '--dry-run' => true]))->toBe(0);
    expect(\Illuminate\Support\Facades\Artisan::output())->toContain('curriculum columns that combine subjects on this class', 'Grade 1', 'Grade 2');
    ($this->activate)(); expect(ClassSubject::where('name', $joint)->exists())->toBeFalse();
    Sanctum::actingAs($this->office);
    $this->postJson("/api/admin/masjids/{$this->org->id}/groups/{$this->group->id}/subjects", ['name' => $joint])->assertCreated();
})->with([' & ', ' and ', ' / ', ', ']);

it('requires deliberate disable and precise acceptance of inexpressible restrictions', function () {
    ($this->activate)(); $science = ClassSubject::where('name', 'Science')->firstOrFail();
    expect(fn () => CapabilityWriter::apply($this->org->fresh(), ['class_subjects' => false], null))->toThrow(\Illuminate\Validation\ValidationException::class);
    $this->staff->fresh()->update(['class_subject_ids' => [$science->id]]);
    $this->artisan('class-subjects:disable', ['--masjid' => $this->org->id, '--dry-run' => true])->expectsOutputToContain('Assignment #'.$this->staff->id)->assertFailed();
    $this->artisan('class-subjects:disable', ['--masjid' => $this->org->id])->assertFailed();
    expect($this->org->fresh()->hasCapability('class_subjects'))->toBeTrue();
    $this->artisan('class-subjects:disable', ['--masjid' => $this->org->id, '--accept-unrestricted' => (string) $this->staff->id])->assertSuccessful();
    expect($this->org->fresh()->hasCapability('class_subjects'))->toBeFalse();
    expect($this->staff->fresh()->subjects)->toBeNull(); expect($this->staff->fresh()->class_subject_ids)->toBe([$science->id]);
    ($this->activate)(); expect($this->staff->fresh()->class_subject_ids)->toBe([$science->id]);
});

// Runs after the actual teacher.teaches fence and before the real note controller.
class Review5RevokeAfterFence
{
    public static int $staffId;
    public static array $laterStaffReads = [];
    public function handle($request, $next)
    {
        DB::table('group_staff')->where('id', self::$staffId)->update(['class_subject_ids' => '[]']);
        $active = true;
        DB::listen(function ($query) use (&$active) {
            if ($active && str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'group_staff')) self::$laterStaffReads[] = $query->sql;
        });
        try { return $next($request); } finally { $active = false; }
    }
}

it('lets an authorized tool write finish after revocation without reading another assignment', function () {
    ($this->activate)();
    $contact = \App\Models\Contact::factory()->create(['masjid_id' => $this->org->id, 'email' => null]);
    $member = \App\Models\GroupMembership::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'contact_id' => $contact->id, 'role' => 'member']);
    Review5RevokeAfterFence::$staffId = $this->staff->id; Review5RevokeAfterFence::$laterStaffReads = [];
    $route = collect(app('router')->getRoutes()->getRoutes())->first(fn ($r) => str_starts_with($r->uri(), 'api/teacher/') && str_ends_with($r->uri(), '/members/{membership_id}/arabic-notes') && in_array('PUT', $r->methods(), true));
    $route->middleware(Review5RevokeAfterFence::class);
    Sanctum::actingAs($this->teacher, ['staff']);
    $this->putJson($this->base.'/members/'.$member->id.'/arabic-notes', ['session_date' => now()->toDateString(), 'note' => 'Practice'])->assertOk();
    expect(Review5RevokeAfterFence::$laterStaffReads)->toBe([]);
    expect($this->staff->fresh()->class_subject_ids)->toBe([]);
    expect(\App\Models\ArabicDailyNote::where('group_membership_id', $member->id)->count())->toBe(1);
});

it('disables only exactly expressible id sets or individually accepted restrictions', function (string $choice) {
    ($this->activate)(); $subjects = ClassSubject::where('group_id', $this->group->id)->get()->keyBy('name');
    ClassSubject::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'name' => 'Islamic Studies']);
    $islamic = ClassSubject::where('name', 'Islamic Studies')->firstOrFail();
    $ids = match ($choice) {
        'all' => null, 'none' => [], 'arabic' => [$subjects['Arabic']->id], 'quran' => [$subjects["Qur'an"]->id],
        'islamic' => [$islamic->id], 'two' => [$subjects['Arabic']->id, $subjects["Qur'an"]->id],
        'science' => [$subjects['Science']->id], 'english' => [$subjects['ELA']->id], 'invalid' => [999999],
    };
    if ($choice === 'invalid') DB::table('group_staff')->where('id', $this->staff->id)->update(['class_subject_ids' => json_encode($ids)]);
    else GroupStaff::withOfficeSubjectChoice(fn () => $this->staff->fresh()->update(['class_subject_ids' => $ids]));
    $expressible = in_array($choice, ['all', 'arabic', 'quran', 'islamic', 'two'], true);
    $before = $this->staff->fresh()->getAttributes(); $beforeSubjects = ClassSubject::all()->toJson();
    $active = true; $transactions = []; $sql = [];
    \Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\TransactionBeginning::class, function () use (&$active, &$transactions) { if ($active) $transactions[] = true; });
    DB::listen(function ($query) use (&$active, &$sql) { if ($active) $sql[] = $query->sql; });
    try {
        $status = \Illuminate\Support\Facades\Artisan::call('class-subjects:disable', ['--masjid' => $this->org->id, '--dry-run' => true]);
        $output = \Illuminate\Support\Facades\Artisan::output();
    } finally { $active = false; }
    expect($status)->toBe($expressible ? 0 : 1); expect($transactions)->toBe([]);
    foreach ($sql as $statement) expect($statement)->toStartWith('select')->not->toMatch('/for update|lock in share mode/i');
    expect($this->staff->fresh()->getAttributes())->toBe($before);
    if ($choice === 'science') {
        file_put_contents(base_path('artifacts/review5-disable-dry-run-sample.log'), $output);
        file_put_contents(base_path('artifacts/review5-disable-dry-run-sql.log'), implode("\n", $sql)."\n");
    }
    if (! $expressible) {
        $this->artisan('class-subjects:disable', ['--masjid' => $this->org->id])->assertFailed();
        expect($this->org->fresh()->hasCapability('class_subjects'))->toBeTrue();
    }
    $this->artisan('class-subjects:disable', ['--masjid' => $this->org->id] + ($expressible ? [] : ['--accept-unrestricted' => (string) $this->staff->id]))->assertSuccessful();
    $expected = match ($choice) { 'arabic' => ['arabic'], 'quran' => ['quran'], 'islamic' => ['islamic_studies'], 'two' => ['quran', 'arabic'], default => null };
    expect($this->staff->fresh()->subjects)->toBe($expected);
    expect($this->staff->fresh()->class_subject_ids)->toBe($ids);
    expect(ClassSubject::all()->toJson())->toBe($beforeSubjects);
    ($this->activate)(); expect($this->staff->fresh()->class_subject_ids)->toBe($ids);
})->with(['all', 'none', 'arabic', 'quran', 'islamic', 'two', 'science', 'english', 'invalid']);

it('refuses missing foreign unnecessary or malformed unrestricted acceptance without writing', function (string $acceptance) {
    ($this->activate)(); $this->staff->fresh()->update(['class_subject_ids' => []]);
    $teacher = User::factory()->create(['type' => 'Teacher', 'phone' => '+15555550103']);
    $other = GroupStaff::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'user_id' => $teacher->id, 'class_subject_ids' => []]);
    $raw = match ($acceptance) { 'missing' => (string) $this->staff->id, 'foreign' => $this->staff->id.','.$other->id.',999999', 'malformed' => 'abc' };
    $snapshot = DB::table('group_staff')->get()->toJson();
    $this->artisan('class-subjects:disable', ['--masjid' => $this->org->id, '--accept-unrestricted' => $raw])->assertFailed();
    expect(DB::table('group_staff')->get()->toJson())->toBe($snapshot);
    expect($this->org->fresh()->hasCapability('class_subjects'))->toBeTrue();
    $this->artisan('class-subjects:disable', ['--masjid' => $this->org->id, '--accept-unrestricted' => $this->staff->id.','.$other->id])->assertSuccessful();
})->with(['missing', 'foreign', 'malformed']);

it('keeps a one subject all assignment local to that class in every curriculum request', function () {
    SchoolSubject::query()->where('name', '!=', 'Arabic')->delete();
    $this->staff->update(['subjects' => null]);
    ($this->activate)();
    $other = Group::factory()->create(['masjid_id' => $this->org->id, 'kind' => 'class']);
    ClassSubject::where('group_id', $other->id)->first()->update(['guide_subject' => null]);
    ClassSubject::create(['masjid_id' => $this->org->id, 'group_id' => $other->id, 'name' => 'Science', 'guide_subject' => 'Science']);
    GroupStaff::create(['masjid_id' => $this->org->id, 'group_id' => $other->id, 'user_id' => $this->teacher->id, 'class_subject_ids' => []]);
    ClassSubject::where('group_id', $this->group->id)->first()->update(['guide_subject' => 'Arabic']);
    foreach (['Arabic', 'Science'] as $subject) \App\Models\CurriculumWeek::create(['masjid_id' => $this->org->id, 'grade_label' => 'Grade 1', 'subject' => $subject, 'week_no' => 1, 'focus' => 'Practice']);
    Sanctum::actingAs($this->teacher, ['staff']);
    foreach (['', '&group_id='.$this->group->id, '&group_id='.$other->id] as $group) {
        $this->getJson("/api/teacher/masjids/{$this->org->id}/curriculum?grade=Grade%201&subject=Science&week=1".$group)->assertOk()->assertJsonPath('data.cell', null)->assertJsonCount(0, 'data.weeks');
    }
});

it('keeps off list branches at literal origin counts after one request dispatch', function (string $kind, int $count) {
    foreach (range(1, $count) as $i) {
        if ($kind === 'assignment') ($this->work)('Arabic');
        else LessonPlan::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'body' => 'Practice', 'subject' => 'Arabic', 'session_date' => \Carbon\Carbon::parse('2026-10-08')->addDays($i)]);
    }
    app(TenantContext::class)->set($this->org->id); Sanctum::actingAs($this->teacher, ['staff']);
    $class = $kind === 'assignment' ? \App\Http\Controllers\Teacher\GradebookController::class : \App\Http\Controllers\Teacher\LessonPlanController::class;
    $controller = app($class); $request = \Illuminate\Http\Request::create('/', 'GET', ['from' => '2026-10-08', 'to' => '2026-12-08']);
    DB::flushQueryLog(); DB::enableQueryLog();
    try { $response = $controller->index($request, $this->org->id, $this->group->id); $queries = DB::getQueryLog(); }
    finally { DB::disableQueryLog(); }
    // Measured by invoking literal origin/main controllers with literal origin SubjectFence/ClassSubjects.
    // The only extra statement is the initial feature dispatch, before the unchanged OFF branch.
    $origin = $kind === 'assignment' ? 8 : 7;
    expect(count($queries))->toBe($origin + 1);
    expect(collect($queries)->filter(fn ($q) => str_contains($q['query'], 'capability_overrides')))->toHaveCount(1);
    file_put_contents(base_path('artifacts/review5-final-list-counts.jsonl'), json_encode(['kind' => $kind, 'N' => $count, 'origin_branch' => $origin, 'current_branch' => count($queries) - 1, 'dispatch' => 1, 'serializer' => 0])."\n", FILE_APPEND);
})->with(['assignment', 'plan'])->with([1, 50]);

it('requires unrestricted standing to clear a linked plan even if its text snapshot is missing', function () {
    $plan = ($this->plan)('Arabic'); ($this->activate)();
    $id = $plan->fresh()->class_subject_id;
    DB::table('lesson_plans')->where('id', $plan->id)->update(['subject' => null]);
    Sanctum::actingAs($this->teacher, ['staff']);
    expect(fn () => $plan->fresh()->update(['class_subject_id' => null]))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($plan->fresh()->class_subject_id)->toBe($id);
});

it('uses one capability read across serialized class payloads on and off', function (bool $on, int $count) {
    $groups = collect([$this->group]);
    foreach (range(1, $count) as $i) {
        if ($i === 1) continue;
        $group = Group::factory()->create(['masjid_id' => $this->org->id, 'kind' => 'class']);
        GroupStaff::create(['masjid_id' => $this->org->id, 'group_id' => $group->id, 'user_id' => $this->teacher->id, 'subjects' => null]);
        $groups->push($group);
    }
    if ($on) ($this->activate)();
    app(TenantContext::class)->set($this->org->id); Sanctum::actingAs($this->teacher, ['staff']);
    $controller = app(\App\Http\Controllers\Teacher\GradebookController::class);
    $method = new ReflectionMethod($controller, 'classPayload'); $method->setAccessible(true);
    DB::flushQueryLog(); DB::enableQueryLog();
    try { foreach ($groups as $group) $method->invoke($controller, $group); $queries = DB::getQueryLog(); }
    finally { DB::disableQueryLog(); }
    expect(collect($queries)->filter(fn ($q) => str_contains($q['query'], 'capability_overrides')))->toHaveCount(1);
})->with([false, true])->with([1, 50]);

it('keeps an unchanged hidden subject choice when only the id and plan prose are sent', function (bool $stringId) {
    $plan = ($this->plan)('Arabic'); ($this->activate)();
    $id = $plan->fresh()->class_subject_id;
    ClassSubject::findOrFail($id)->update(['hidden_at' => now()]);
    Sanctum::actingAs($this->teacher, ['staff']);
    $this->putJson($this->base.'/lesson-plans/'.$plan->id, ['session_date' => '2026-10-08', 'body' => 'Edited', 'class_subject_id' => $stringId ? (string) $id : $id])->assertOk();
    expect($plan->fresh()->class_subject_id)->toBe($id);
    expect($plan->fresh()->subject)->toBe('Arabic');
})->with([false, true]);

it('preserves the sole permitted named plan on an old by day update without a subject', function () {
    $plan = ($this->plan)('Arabic'); ($this->activate)(); $id = $plan->fresh()->class_subject_id;
    Sanctum::actingAs($this->teacher, ['staff']);
    $this->putJson($this->base.'/lesson-plans', ['session_date' => '2026-10-08', 'body' => 'Edited'])->assertOk()->assertJsonPath('data.id', $plan->id);
    expect($plan->fresh()->class_subject_id)->toBe($id);
    expect($plan->fresh()->subject)->toBe('Arabic');
    expect(LessonPlan::count())->toBe(1);
});

it('requires a plan choice on a by day edit with several permitted named plans', function () {
    ($this->plan)('Arabic'); ($this->plan)('Science'); ($this->activate)();
    $this->staff->fresh()->update(['class_subject_ids' => ClassSubject::whereIn('name', ['Arabic', 'Science'])->pluck('id')->all()]);
    Sanctum::actingAs($this->teacher, ['staff']);
    $this->putJson($this->base.'/lesson-plans', ['session_date' => '2026-10-08', 'body' => 'Edited'])->assertConflict();
    expect(LessonPlan::count())->toBe(2);
});

it('can preserve a hidden same id plan through the old by day route', function () {
    $plan = ($this->plan)('Arabic'); ($this->activate)(); $id = $plan->fresh()->class_subject_id;
    ClassSubject::findOrFail($id)->update(['hidden_at' => now()]);
    Sanctum::actingAs($this->teacher, ['staff']);
    $this->putJson($this->base.'/lesson-plans', ['session_date' => '2026-10-08', 'body' => 'Edited', 'class_subject_id' => $id])->assertOk()->assertJsonPath('data.id', $plan->id);
    expect($plan->fresh()->class_subject_id)->toBe($id);
    expect($plan->fresh()->subject)->toBe('Arabic');
});
