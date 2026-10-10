<?php

use App\Models\ClassSubject;
use App\Models\Contact;
use App\Models\CurriculumWeek;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupStaff;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\SchoolSubject;
use App\Models\User;
use App\Support\CapabilityWriter;
use App\Support\SchoolSettings;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    $this->school = Masjid::create([
        'name' => 'Practice School '.uniqid(), 'email' => uniqid().'@example.invalid',
        'phone' => '+1'.random_int(1000000000, 9999999999), 'country_id' => '1', 'city_id' => '1',
        'address' => '1 Practice St', 'latitude' => 0, 'longitude' => 0, 'crm_enabled' => true, 'org_type' => 'school',
    ]);
    $this->room = Group::factory()->create(['masjid_id' => $this->school->id, 'kind' => 'class', 'name' => 'Practice Class']);
    $this->teacher = User::factory()->create(['type' => 'Teacher', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
    MasjidUser::create(['masjid_id' => $this->school->id, 'user_id' => $this->teacher->id, 'role' => 'teacher', 'is_default' => true]);
    $this->staff = GroupStaff::create(['masjid_id' => $this->school->id, 'group_id' => $this->room->id, 'user_id' => $this->teacher->id, 'role' => 'teacher']);
    $this->office = User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
    $this->base = "/api/admin/masjids/{$this->school->id}/groups/{$this->room->id}/subjects";
    $this->teacherBase = "/api/teacher/masjids/{$this->school->id}/groups/{$this->room->id}";
    $this->catalogue = function (string $name, ?array $grades = null, int $position = 0) {
        return SchoolSubject::create(['masjid_id' => $this->school->id, 'name' => $name, 'grade_labels' => $grades, 'position' => $position]);
    };
    $this->guide = function (string $name, string $grade = 'Grade 1') {
        return CurriculumWeek::create(['masjid_id' => $this->school->id, 'subject' => $name, 'grade_label' => $grade, 'week_no' => 1, 'quarter' => 1, 'focus' => 'Practice focus']);
    };
    $this->child = function (?string $grade) {
        $contact = Contact::factory()->create(['masjid_id' => $this->school->id]);
        return GroupMembership::create(['masjid_id' => $this->school->id, 'group_id' => $this->room->id, 'contact_id' => $contact->id, 'role' => 'member', 'grade_label' => $grade]);
    };
    $this->enable = function () {
        $this->artisan('class-subjects:initialize', ['--masjid' => $this->school->id, '--enable' => true])->assertSuccessful();
        $this->school->refresh();
        $this->room->refresh();
        $this->staff->refresh();
    };
});

afterEach(function () { app(TenantContext::class)->forgetTenant(); });

it('defaults off and keeps raw feature columns out of every legacy model payload', function () {
    expect(SchoolSettings::classSubjects($this->school))->toBeFalse();
    ($this->catalogue)('Arabic');
    Sanctum::actingAs($this->teacher, ['staff']);
    $before = $this->getJson($this->teacherBase)->assertOk()->json();
    $this->artisan('class-subjects:initialize', ['--masjid' => $this->school->id, '--dry-run' => true])->assertSuccessful();
    expect($this->getJson($this->teacherBase)->assertOk()->json())->toBe($before);
    expect($this->room->fresh()->toArray())->not->toHaveKeys(['subject_seed_grades', 'class_subjects_initialized_at']);
    expect($this->staff->fresh()->toArray())->not->toHaveKeys(['class_subject_ids', 'class_subjects_mapped_at']);
    $this->getJson($this->teacherBase.'/subjects')->assertForbidden();
    Sanctum::actingAs($this->office);
    $this->getJson($this->base)->assertForbidden();
});

it('seeds a normalized grade union, catalogue order, missing guide subjects and aliases', function (array $grades, array $expected) {
    ($this->catalogue)('Arabic', ['1st'], 0);
    ($this->catalogue)('ELA', ['Grade 2'], 1);
    ($this->catalogue)('Science', ['9th-11th'], 2);
    ($this->guide)('Arabic Language');
    ($this->guide)('Mathematics');
    ($this->guide)('English Language Arts', '2nd');
    foreach ($grades as $grade) ($this->child)($grade);
    ($this->enable)();
    expect(ClassSubject::orderBy('position')->pluck('name')->all())->toBe($expected);
})->with([
    'one' => [['Grade 1'], ['Arabic', 'Mathematics']],
    'union' => [['1', '2nd'], ['Arabic', 'ELA', 'Mathematics']],
    'unknown' => [[null], ['Arabic', 'ELA', 'Science', 'Mathematics']],
    'empty' => [[], ['Arabic', 'ELA', 'Science', 'Mathematics']],
    'literal range' => [['10th'], []],
]);

it('preselects guide spellings and default tools while skipping redundant joint columns', function () {
    foreach (["Qur’an", 'Arabic', 'ELA', 'Islamic Studies'] as $name) ($this->catalogue)($name);
    foreach (["Qur'an", 'Arabic Language', 'English Language Arts', "Qur'an & Islamic Studies"] as $name) ($this->guide)($name);
    ($this->enable)();
    expect(ClassSubject::count())->toBe(4);
    expect(ClassSubject::where('name', 'Arabic')->first()->toArray())->toMatchArray(['tool' => 'arabic_letters', 'guide_subject' => 'Arabic Language']);
    expect(ClassSubject::where('name', 'ELA')->first()->tool)->toBe('english_letters');
    expect(ClassSubject::where('name_key', 'quran')->first()->tool)->toBe('hifdh');
    expect(ClassSubject::where('name', "Qur'an & Islamic Studies")->exists())->toBeFalse();
});

it('blocks an unmapped legacy restriction and reports the class and missing subject without writing', function () {
    ($this->catalogue)('Science');
    $this->staff->update(['subjects' => ['arabic']]);
    $this->artisan('class-subjects:initialize', ['--masjid' => $this->school->id, '--enable' => true])
        ->expectsOutputToContain('Class Practice Class: legacy subject arabic cannot be mapped')->assertFailed();
    expect(ClassSubject::count())->toBe(0);
    expect($this->staff->fresh()->subjects)->toBe(['arabic']);
    expect(SchoolSettings::classSubjects($this->school->fresh()))->toBeFalse();
});

it('dry runs without writes, maps legacy restrictions, and preserves all data on repeat and off then on', function () {
    ($this->catalogue)('Arabic');
    $this->staff->update(['subjects' => ['arabic']]);
    $this->artisan('class-subjects:initialize', ['--masjid' => $this->school->id, '--dry-run' => true, '--enable' => true])->assertSuccessful();
    expect(ClassSubject::count())->toBe(0);
    ($this->enable)();
    $subject = ClassSubject::first();
    expect($this->staff->class_subject_ids)->toBe([$subject->id]);
    $subject->update(['name' => 'Language Practice', 'hidden_at' => now(), 'position' => 8]);
    $snapshot = DB::table('class_subjects')->get()->toJson();
    $assignments = DB::table('group_staff')->get()->toJson();
    $disable = \App\Support\ClassSubjectDisabler::run($this->school->fresh(), true);
    $accept = array_column(array_filter($disable['assignments'], fn ($row) => ! $row['expressible']), 'id');
    expect(\App\Support\ClassSubjectDisabler::run($this->school->fresh(), false, $accept)['blocked'])->toBe([]);
    expect($this->staff->fresh()->subjects)->toBe(['arabic']);
    $assignments = DB::table('group_staff')->get()->toJson();
    ($this->catalogue)('New Science');
    ($this->enable)();
    $this->artisan('class-subjects:initialize', ['--masjid' => $this->school->id, '--dry-run' => true])->assertSuccessful();
    expect(DB::table('class_subjects')->get()->toJson())->toBe($snapshot);
    expect(DB::table('group_staff')->get()->toJson())->toBe($assignments);
});

it('refuses both toggle endpoints before initialization in plain words', function (bool $bulk) {
    Sanctum::actingAs($this->office);
    $url = "/api/admin/masjids/{$this->school->id}/capabilities";
    $this->patchJson($url.($bulk ? '' : '/class_subjects'), $bulk ? ['capabilities' => ['class_subjects' => true]] : ['enabled' => true])
        ->assertUnprocessable()->assertSee('initializ');
    expect(SchoolSettings::classSubjects($this->school->fresh()))->toBeFalse();
})->with([false, true]);

it('seeds new empty classes while on, only once, without inferring a grade from the name', function () {
    ($this->catalogue)('Arabic', ['Grade 1']);
    ($this->catalogue)('Science', ['Grade 5']);
    ($this->enable)();
    Sanctum::actingAs($this->office);
    $id = $this->postJson("/api/admin/masjids/{$this->school->id}/groups", ['kind' => 'class', 'name' => 'Grade 1'])
        ->assertCreated()->json('data.id');
    $new = Group::findOrFail($id);
    expect(ClassSubject::where('group_id', $new->id)->pluck('name')->all())->toBe(['Arabic', 'Science']);
    expect($new->fresh()->class_subjects_initialized_at)->not->toBeNull();
});

it('offers office CRUD, rename history, reorder, hide and restore and reserves a hidden tool', function () {
    ($this->catalogue)('Arabic');
    ($this->guide)('Arabic Language');
    ($this->enable)();
    Sanctum::actingAs($this->office);
    $arabic = $this->getJson($this->base)->assertOk()->json('data.0.id');
    $extra = $this->postJson($this->base, ['name' => 'Health'])->assertCreated()->json('data.id');
    $this->putJson($this->base.'/'.$arabic, ['name' => 'Arabic Practice', 'guide_subject' => 'Arabic Language'])->assertOk()->assertJsonPath('data.tool', 'arabic_letters');
    $this->putJson($this->base.'/reorder', ['subject_ids' => [$extra, $arabic]])->assertOk();
    $this->deleteJson($this->base.'/'.$arabic)->assertOk();
    $this->putJson($this->base.'/'.$extra, ['tool' => 'arabic_letters'])->assertUnprocessable()->assertSee('already');
    $this->putJson($this->base.'/'.$arabic.'/restore')->assertOk()->assertJsonPath('data.hidden_at', null);
    expect(ClassSubject::find($arabic)->previous_name_keys)->toBeNull();
    expect($this->getJson($this->base)->json('data.0.id'))->toBe($extra);
    $this->putJson($this->base.'/'.$extra, ['guide_subject' => 'Invented guide'])->assertUnprocessable();
    $this->postJson($this->base, ['name' => '  Arabic Practice  '])->assertUnprocessable();
});

it('gives teachers only assigned visible subject GETs and refuses all management verbs', function () {
    foreach (['Arabic', 'ELA', "Qur'an"] as $name) ($this->catalogue)($name);
    $this->staff->update(['subjects' => ['arabic']]);
    ($this->enable)();
    $arabic = ClassSubject::where('tool', 'arabic_letters')->first();
    $other = ClassSubject::where('tool', 'hifdh')->first();
    Sanctum::actingAs($this->teacher, ['staff']);
    $this->getJson($this->teacherBase.'/subjects')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $arabic->id);
    $this->getJson($this->teacherBase.'/subjects/'.$arabic->id)->assertOk();
    $this->getJson($this->teacherBase.'/subjects/'.$other->id)->assertNotFound();
    foreach (['post', 'put', 'delete'] as $method) {
        foreach ([$this->base, $this->base.'/'.$arabic->id, $this->base.'/reorder', $this->base.'/'.$arabic->id.'/restore'] as $url) {
            $response = $this->{$method.'Json'}($url, ['name' => 'By teacher']);
            expect($response->status())->toBeIn([401, 403, 405]);
        }
    }
    $arabic->update(['hidden_at' => now()]);
    $this->getJson($this->teacherBase.'/subjects/'.$arabic->id)->assertNotFound();
});

it('misses another class or organisation subject id and rejects reorder foreign ids', function () {
    ($this->catalogue)('Arabic');
    ($this->enable)();
    $another = Group::factory()->create(['masjid_id' => $this->school->id, 'kind' => 'class']);
    $foreign = ClassSubject::where('group_id', $another->id)->first();
    Sanctum::actingAs($this->office);
    $this->getJson($this->base.'/'.$foreign->id)->assertNotFound();
    $this->putJson($this->base.'/'.$foreign->id, ['name' => 'Wrong'])->assertNotFound();
    $this->putJson($this->base.'/reorder', ['subject_ids' => [$foreign->id]])->assertUnprocessable();
    app(TenantContext::class)->forgetTenant();
    $org = $this->school->replicate();
    $org->name = 'Other Practice School '.uniqid();
    $org->email = uniqid().'@example.invalid'; $org->phone = '+1'.random_int(1000000000, 9999999999); $org->save();
    $room = Group::factory()->create(['masjid_id' => $org->id, 'kind' => 'class']);
    $foreign = ClassSubject::create(['masjid_id' => $org->id, 'group_id' => $room->id, 'name' => 'Foreign']);
    $this->getJson($this->base.'/'.$foreign->id)->assertNotFound();
    $this->postJson($this->base, ['name' => 'Local', 'masjid_id' => $org->id, 'group_id' => $room->id])->assertCreated()->assertJsonPath('data.group_id', $this->room->id);
});

it('fences every tool verb by its own holder including English independently of Arabic', function (string $tool, array $allowed, array $denied) {
    foreach (['Arabic', 'ELA', "Qur'an", 'Health'] as $name) ($this->catalogue)($name);
    ($this->enable)();
    $holder = ClassSubject::where('tool', $tool)->first();
    $this->staff->update(['class_subject_ids' => [$holder->id]]);
    $student = ($this->child)('Grade 1');
    Sanctum::actingAs($this->teacher, ['staff']);
    foreach ($allowed as [$method, $suffix, $body]) {
        $suffix = str_replace('{student}', (string) $student->id, $suffix);
        $response = $this->{$method.'Json'}($this->teacherBase.$suffix, $body);
        expect($response->status())->not->toBe(403);
    }
    foreach ($denied as [$method, $suffix, $body]) {
        $suffix = str_replace('{student}', (string) $student->id, $suffix);
        $this->{$method.'Json'}($this->teacherBase.$suffix, $body)->assertForbidden();
    }
})->with(function () {
    $arabic = [
        ['get', '/letters', []], ['get', '/members/{student}/letters', []],
        ['put', '/members/{student}/letters', ['alphabet' => 'arabic']],
        ['put', '/members/{student}/letters/master-all', ['alphabet' => 'arabic']],
        ['put', '/letters/stage', []], ['get', '/members/{student}/arabic-notes', []],
        ['put', '/members/{student}/arabic-notes', []], ['delete', '/members/{student}/arabic-notes/999999', []],
    ];
    $english = [
        ['get', '/letters?alphabet=english', []], ['get', '/members/{student}/letters?alphabet=english', []],
        ['put', '/members/{student}/letters', ['alphabet' => 'english']],
        ['put', '/members/{student}/letters/master-all', ['alphabet' => 'english']],
    ];
    $hifdh = [
        ['get', '/hifz', []], ['post', '/hifz', []], ['put', '/hifz/999999', []],
        ['post', '/hifz/999999/correct', []], ['delete', '/hifz/999999', []],
        ['get', '/members/{student}/hifz', []], ['get', '/members/{student}/hifz/progress', []],
    ];
    return [
        'Arabic' => ['arabic_letters', $arabic, [...$english, ...$hifdh]],
        'English' => ['english_letters', $english, [...$arabic, ...$hifdh]],
        'Hifdh' => ['hifdh', $hifdh, [...$arabic, ...$english]],
    ];
});

it('validates alphabet before authorization while on and retains the shared Arabic rule off', function () {
    ($this->catalogue)('Arabic'); ($this->catalogue)('ELA');
    $this->staff->update(['subjects' => ['arabic']]);
    Sanctum::actingAs($this->teacher, ['staff']);
    $this->getJson($this->teacherBase.'/letters?alphabet=english')->assertOk();
    ($this->enable)();
    $this->getJson($this->teacherBase.'/letters?alphabet=english')->assertForbidden();
    $this->getJson($this->teacherBase.'/letters?alphabet=unknown')->assertUnprocessable();
    $this->putJson($this->teacherBase.'/members/999999/letters', ['alphabet' => 'unknown'])->assertUnprocessable();
});

it('preserves linked subject snapshots through rename and resolves guides for gradebook plans curriculum and weights', function () {
    ($this->catalogue)('ELA'); ($this->catalogue)('Science');
    ($this->guide)('English Language Arts'); ($this->guide)('Science');
    foreach (['ELA', 'English Language Arts', 'Science'] as $name) {
        \App\Models\ClassAssignment::create(['masjid_id' => $this->school->id, 'group_id' => $this->room->id, 'title' => $name, 'subject' => $name, 'assigned_on' => '2026-10-01', 'scale' => 'points', 'points_possible' => 10]);
        \App\Models\LessonPlan::create(['masjid_id' => $this->school->id, 'group_id' => $this->room->id, 'session_date' => '2026-10-01', 'subject' => $name, 'title' => $name, 'body' => 'Practice plan']);
    }
    ($this->enable)();
    $ela = ClassSubject::where('name', 'ELA')->first();
    $this->staff->update(['class_subject_ids' => [$ela->id]]);
    $ela->update(['name' => 'Reading']);
    Sanctum::actingAs($this->teacher, ['staff']);
    $this->getJson($this->teacherBase.'/assignments')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson($this->teacherBase.'/lesson-plans?from=2026-10-01&to=2026-10-01')->assertOk()->assertJsonCount(2, 'data.plans');
    $science = \App\Models\ClassAssignment::where('subject', 'Science')->first();
    $this->getJson($this->teacherBase.'/assignments/'.$science->id)->assertNotFound();
    $this->getJson("/api/teacher/masjids/{$this->school->id}/curriculum?group_id={$this->room->id}&grade=Grade%201")->assertOk()->assertJsonPath('data.subjects', ['Reading']);
    $this->putJson($this->teacherBase.'/grade-weights', ['clear' => true])->assertForbidden();
    $this->getJson($this->teacherBase)->assertOk()->assertJsonPath('data.my_class_subject_ids', [$ela->id])->assertJsonPath('data.my_subjects', null);
});

it('accepts own subject ids on teacher assignment writes and reads them back without changing legacy subjects', function () {
    ($this->catalogue)('Health'); ($this->enable)();
    $id = ClassSubject::first()->id;
    Sanctum::actingAs($this->office);
    $url = "/api/admin/masjids/{$this->school->id}/teachers/{$this->teacher->id}";
    $body = ['name' => $this->teacher->name, 'class_ids' => [$this->room->id], 'class_subject_ids' => [$this->room->id => [$id]]];
    $this->putJson($url, $body)->assertOk()->assertJsonPath('data.classes.0.class_subject_ids', [$id]);
    $this->getJson($url)->assertOk()->assertJsonPath('data.class_subject_ids.'.$this->room->id, [$id]);
    expect($this->staff->fresh()->subjects)->toBeNull();
    $this->putJson($url, ['name' => $this->teacher->name, 'class_ids' => [$this->room->id]])->assertOk();
    expect($this->staff->fresh()->class_subject_ids)->toBe([$id]);
    $body['class_subject_ids'][$this->room->id] = [999999];
    $this->putJson($url, $body)->assertUnprocessable();
});

it('keeps every touched OFF payload identical after initializing in the dark', function () {
    ($this->catalogue)('Arabic'); ($this->catalogue)('ELA');
    ($this->guide)('Arabic'); ($this->child)('Grade 1');
    $this->staff->update(['subjects' => ['arabic']]);
    Sanctum::actingAs($this->teacher, ['staff']);
    $urls = [$this->teacherBase, $this->teacherBase.'/assignments', $this->teacherBase.'/lesson-plans', $this->teacherBase.'/letters', $this->teacherBase.'/letters?alphabet=english', "/api/teacher/masjids/{$this->school->id}/curriculum?group_id={$this->room->id}&grade=Grade%201"];
    $snapshots = [];
    foreach ($urls as $url) $snapshots[$url] = $this->getJson($url)->assertOk()->json();
    Sanctum::actingAs($this->office);
    $urls = ["/api/admin/masjids/{$this->school->id}/groups", "/api/admin/masjids/{$this->school->id}/groups/{$this->room->id}", "/api/admin/masjids/{$this->school->id}/teachers", "/api/admin/masjids/{$this->school->id}/teachers/{$this->teacher->id}"];
    $officeSnapshots = [];
    foreach ($urls as $url) $officeSnapshots[$url] = $this->getJson($url)->assertOk()->json();
    $groupBefore = $this->room->fresh()->toArray(); $staffBefore = $this->staff->fresh()->toArray();
    $this->travel(1)->hours();
    $this->artisan('class-subjects:initialize', ['--masjid' => $this->school->id, '--dry-run' => true])->assertSuccessful();
    foreach ($officeSnapshots as $url => $payload) expect($this->getJson($url)->assertOk()->json())->toBe($payload);
    expect($this->room->fresh()->toArray())->toBe($groupBefore);
    expect($this->staff->fresh()->toArray())->toBe($staffBefore);
    Sanctum::actingAs($this->teacher, ['staff']);
    foreach ($snapshots as $url => $payload) expect($this->getJson($url)->assertOk()->json())->toBe($payload);
});

it('does not let an invalid class or missing class parameter bypass curriculum restrictions while on', function () {
    ($this->catalogue)('Arabic'); ($this->catalogue)('ELA');
    ($this->guide)('Arabic'); ($this->guide)('English Language Arts');
    $this->staff->update(['subjects' => ['arabic']]); ($this->enable)();
    Sanctum::actingAs($this->teacher, ['staff']);
    $url = "/api/teacher/masjids/{$this->school->id}/curriculum?grade=Grade%201&subject=English%20Language%20Arts&week=1";
    $this->getJson($url)->assertOk()->assertJsonPath('data.cell', null);
    $this->getJson($url.'&group_id=999999')->assertNotFound();
    app(TenantContext::class)->forgetTenant();
    $other = Group::factory()->create(['masjid_id' => $this->school->id, 'kind' => 'class']);
    $this->getJson($url.'&group_id='.$other->id)->assertNotFound();
});

it('retains guide access to an assigned renamed holder and refuses every other gradebook and plan verb', function () {
    ($this->catalogue)('Health'); ($this->catalogue)('Science');
    ($this->guide)('Health'); ($this->guide)('Science');
    ($this->enable)();
    $health = ClassSubject::where('name', 'Health')->first();
    $this->staff->update(['class_subject_ids' => [$health->id]]);
    $student = ($this->child)('Grade 1');
    $work = \App\Models\ClassAssignment::create(['masjid_id' => $this->school->id, 'group_id' => $this->room->id, 'title' => 'Science', 'subject' => 'Science', 'points_possible' => 10, 'scale' => 'points', 'assigned_on' => '2026-10-01']);
    $plan = \App\Models\LessonPlan::create(['masjid_id' => $this->school->id, 'group_id' => $this->room->id, 'body' => 'Practice', 'subject' => 'Science', 'session_date' => '2026-10-01']);
    Sanctum::actingAs($this->teacher, ['staff']);
    $body = ['title' => 'Science', 'subject' => 'Science', 'points_possible' => 10, 'assigned_on' => '2026-10-01', 'scale' => 'points'];
    $this->postJson($this->teacherBase.'/assignments', $body)->assertForbidden();
    $this->putJson($this->teacherBase.'/assignments/'.$work->id, $body)->assertNotFound();
    $this->deleteJson($this->teacherBase.'/assignments/'.$work->id)->assertNotFound();
    $this->putJson($this->teacherBase.'/assignments/'.$work->id.'/scores', ['scores' => [['membership_id' => $student->id, 'status' => 'scored', 'points_earned' => 5]]])->assertNotFound();
    $body = ['session_date' => '2026-10-01', 'subject' => 'Science', 'body' => 'Practice'];
    $this->postJson($this->teacherBase.'/lesson-plans', $body)->assertForbidden();
    $this->putJson($this->teacherBase.'/lesson-plans/'.$plan->id, $body)->assertNotFound();
    $this->deleteJson($this->teacherBase.'/lesson-plans/'.$plan->id)->assertNotFound();
    $this->putJson($this->teacherBase.'/lesson-plans', $body)->assertForbidden();
    $this->deleteJson($this->teacherBase.'/lesson-plans?date=2026-10-01')->assertNotFound();
    $this->getJson($this->teacherBase.'/members/'.$student->id.'/grades')->assertOk()->assertJsonCount(0, 'data.scores');
    $url = "/api/teacher/masjids/{$this->school->id}/curriculum?group_id={$this->room->id}&grade=Grade%201&week=1";
    $this->getJson($url.'&subject=Science')->assertOk()->assertJsonPath('data.cell', null);
    $this->getJson($url.'&subject=Health')->assertOk()->assertJsonPath('data.cell.subject', 'Health')->assertJsonCount(0, 'data.cell.siblings');
});

it('refuses ON at provisioning before initialization and distinguishes an initialized empty school', function () {
    expect(fn () => CapabilityWriter::applyAtCreation($this->school, ['class_subjects' => true], $this->office->id))->toThrow(\Illuminate\Validation\ValidationException::class);
    $this->room->forceDelete();
    expect(\App\Support\ClassSubjectInitializer::ready($this->school))->toBeFalse();
    $this->artisan('class-subjects:initialize', ['--masjid' => $this->school->id, '--enable' => true])->assertSuccessful();
    expect(\App\Support\ClassSubjectInitializer::ready($this->school->fresh()))->toBeTrue();
});

it('resolves subject keys once for a curriculum read regardless of the number of guide rows', function () {
    ($this->catalogue)('Arabic'); ($this->catalogue)('ELA');
    for ($i = 1; $i <= 25; $i++) CurriculumWeek::create(['masjid_id' => $this->school->id, 'grade_label' => 'Grade 1', 'subject' => 'ELA', 'week_no' => $i, 'focus' => 'Practice']);
    $this->staff->update(['subjects' => ['arabic']]); ($this->enable)();
    Sanctum::actingAs($this->teacher, ['staff']);
    DB::enableQueryLog();
    $this->getJson("/api/teacher/masjids/{$this->school->id}/curriculum/standards?group_id={$this->room->id}&q=practice")->assertOk();
    $queries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'from "class_subjects"'));
    DB::disableQueryLog(); DB::flushQueryLog();
    expect($queries->count())->toBeLessThanOrEqual(2);
});

it('makes dry run a read only preflight with no inserts updates or ledger side effects', function () {
    ($this->catalogue)('Arabic'); $this->staff->update(['subjects' => ['arabic']]);
    DB::enableQueryLog();
    $this->artisan('class-subjects:initialize', ['--masjid' => $this->school->id, '--dry-run' => true, '--enable' => true])->assertSuccessful();
    $writes = collect(DB::getQueryLog())->filter(fn ($q) => preg_match('/^(insert|update|delete)\s/i', $q['query']));
    DB::disableQueryLog(); DB::flushQueryLog();
    expect($writes->count())->toBe(0);
    expect(DB::table('masjid_capability_changes')->count())->toBe(0);
});

it('keeps initialization metadata and a disabled grant out of existing organization payloads', function () {
    $before = $this->school->fresh()->append(Masjid::ADMIN_APPENDS)->toArray();
    $this->travel(1)->hours();
    $this->artisan('class-subjects:initialize', ['--masjid' => $this->school->id, '--dry-run' => true])->assertSuccessful();
    $after = $this->school->fresh()->append(Masjid::ADMIN_APPENDS)->toArray();
    expect($after)->toBe($before);
    ($this->enable)();
    expect($this->school->fresh()->append(Masjid::ADMIN_APPENDS)->toArray()['capabilities']['class_subjects'])->toBeTrue();
    $disable = \App\Support\ClassSubjectDisabler::run($this->school->fresh(), true);
    $accept = array_column(array_filter($disable['assignments'], fn ($row) => ! $row['expressible']), 'id');
    expect(\App\Support\ClassSubjectDisabler::run($this->school->fresh(), false, $accept)['blocked'])->toBe([]);
    expect($this->school->fresh()->append(Masjid::ADMIN_APPENDS)->toArray()['capabilities'])->not->toHaveKey('class_subjects');
    expect($this->school->fresh()->toArray()['capability_overrides'])->toBeNull();
});

it('scrubs office names and historical keys while preserving ids assignments guides and tools', function () {
    ($this->catalogue)('Arabic'); ($this->guide)('Arabic Language'); ($this->enable)();
    $subject = ClassSubject::first(); $subject->update(['name' => 'Private classroom label']);
    $this->staff->update(['class_subject_ids' => [$subject->id]]);
    $this->app->detectEnvironment(fn () => 'staging');
    config(['app.env' => 'staging', 'database.connections.sqlite.database' => 'masjids_staging']);
    $this->artisan('staging:scrub', ['--i-understand-this-destroys-personal-data' => true])->assertSuccessful();
    $subject->refresh();
    expect($subject->name)->toBe('Subject '.$subject->id);
    expect($subject->name_key)->toBe('subject '.$subject->id);
    expect($subject->previous_name_keys)->toBeNull();
    expect($subject->tool)->toBe('arabic_letters');
    expect($subject->guide_subject)->toBe('Arabic Language');
    expect($this->staff->fresh()->class_subject_ids)->toBe([$subject->id]);
});

it('lets ordinary school office staff manage subjects and assign a new teacher using class ids', function () {
    ($this->catalogue)('Health'); ($this->enable)();
    $admin = User::factory()->create(['type' => 'MasjidAdmin', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
    $this->school->forceFill(['user_id' => $admin->id])->save();
    Sanctum::actingAs($admin, ['staff']);
    $this->getJson($this->base)->assertOk();
    $id = $this->postJson($this->base, ['name' => 'Writing', 'tool' => null])->assertCreated()->json('data.id');
    \Illuminate\Support\Facades\Mail::fake();
    $response = $this->postJson("/api/admin/masjids/{$this->school->id}/teachers", [
        'name' => 'Practice Teacher', 'email' => uniqid().'@example.invalid', 'phone' => '+1'.random_int(1000000000, 9999999999),
        'class_ids' => [$this->room->id], 'class_subject_ids' => [$this->room->id => [$id]],
    ])->assertCreated()->assertJsonPath('data.classes.0.class_subject_ids', [$id]);
    $new = GroupStaff::where('user_id', $response->json('data.id'))->where('group_id', $this->room->id)->firstOrFail();
    expect($new->class_subject_ids_edited_at)->not->toBeNull();
    expect($new->class_subjects_mapped_at)->toBeNull();
    expect($new->subjects)->toBeNull();
    $this->getJson('/api/admin/masjids/999999/groups/'.$this->room->id.'/subjects')->assertForbidden();
});

it('rejects foreign class subject ids on assignment writes and preserves an explicit all subjects assignment', function () {
    ($this->catalogue)('Arabic'); ($this->enable)();
    $other = Group::factory()->create(['masjid_id' => $this->school->id, 'kind' => 'class']);
    $foreign = ClassSubject::where('group_id', $other->id)->first()->id;
    Sanctum::actingAs($this->office);
    $url = "/api/admin/masjids/{$this->school->id}/teachers/{$this->teacher->id}";
    $body = ['name' => $this->teacher->name, 'class_ids' => [$this->room->id], 'class_subject_ids' => [$this->room->id => [$foreign]]];
    $this->putJson($url, $body)->assertUnprocessable();
    $body['class_subject_ids'][$this->room->id] = null;
    $this->putJson($url, $body)->assertOk()->assertJsonPath('data.classes.0.class_subject_ids', null);
    expect($this->staff->fresh()->class_subject_ids)->toBeNull();
    $body['class_subject_ids'] = [$other->id => null];
    $this->putJson($url, $body)->assertUnprocessable();
});

it('preserves general lesson plans for a restricted subject teacher while on', function () {
    ($this->catalogue)('Health'); ($this->enable)();
    $this->staff->update(['class_subject_ids' => [ClassSubject::first()->id]]);
    Sanctum::actingAs($this->teacher, ['staff']);
    $this->postJson($this->teacherBase.'/lesson-plans', ['session_date' => '2026-10-02', 'body' => 'General activities'])->assertOk();
    $this->getJson($this->teacherBase.'/lesson-plans?from=2026-10-02&to=2026-10-02')->assertOk()->assertJsonCount(1, 'data.plans');
});

it('preserves existing Hifdh and both alphabet records and responses when enabled', function () {
    foreach (['Arabic', 'ELA', "Qur'an"] as $name) ($this->catalogue)($name);
    $student = ($this->child)('Grade 1');
    foreach (['arabic' => 'ba', 'english' => 'a'] as $alphabet => $drill) {
        \App\Models\ArabicLetterProgress::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->room->id,
            'group_membership_id' => $student->id, 'marked_by_user_id' => $this->teacher->id,
            'alphabet' => $alphabet, 'drill_id' => $drill, 'status' => 'learning', 'note' => 'Practice note',
        ]);
    }
    \App\Models\HifzEntry::factory()->create([
        'masjid_id' => $this->school->id, 'group_id' => $this->room->id,
        'group_membership_id' => $student->id, 'heard_by_user_id' => $this->teacher->id,
    ]);
    $tables = ['arabic_letter_progress', 'hifz_entries'];
    $records = [];
    foreach ($tables as $table) $records[$table] = DB::table($table)->get()->toJson();
    Sanctum::actingAs($this->teacher, ['staff']);
    $urls = ['/letters', '/letters?alphabet=english', '/members/'.$student->id.'/letters',
        '/members/'.$student->id.'/letters?alphabet=english', '/hifz', '/members/'.$student->id.'/hifz',
        '/members/'.$student->id.'/hifz/progress'];
    $responses = [];
    foreach ($urls as $url) $responses[$url] = $this->getJson($this->teacherBase.$url)->assertOk()->json();
    $this->artisan('class-subjects:initialize', ['--masjid' => $this->school->id, '--dry-run' => true, '--enable' => true])
        ->expectsOutputToContain('DRY RUN: '.$this->school->name)
        ->expectsOutputToContain('Class Practice Class: 3 subjects added; 1 assignments mapped.')
        ->expectsOutputToContain('Would enable class subjects. No changes saved.')->assertSuccessful();
    ($this->enable)();
    foreach ($records as $table => $rows) expect(DB::table($table)->get()->toJson())->toBe($rows);
    foreach ($responses as $url => $payload) expect($this->getJson($this->teacherBase.$url)->assertOk()->json())->toBe($payload);
});

it('Build C office can follow several valid guide subjects where work is on and teachers cannot write', function () {
    ($this->catalogue)('Science'); ($this->catalogue)('Arabic');
    ($this->guide)('Science'); ($this->guide)('Joint studies'); ($this->guide)('Joint studies', 'Grade 2'); ($this->guide)('Senior studies', 'Grade 5');
    ($this->child)('1'); ($this->child)('2nd');
    ($this->enable)();
    $org = $this->school->fresh(); $org->forceFill(['capability_overrides' => array_merge((array) $org->capability_overrides, ['class_subject_work' => true])])->save();
    Sanctum::actingAs($this->office);
    $list = $this->getJson($this->base)->assertOk();
    expect($list->json('meta.guide_subjects'))->toBe(['Joint studies', 'Science', 'Senior studies']);
    expect($list->json('meta.guide_subject_grades'))->toBe(['Joint studies' => ['Grade 1', 'Grade 2'], 'Science' => ['Grade 1'], 'Senior studies' => []]);
    $id = ClassSubject::where('name', 'Science')->firstOrFail()->id;
    $this->putJson($this->base.'/'.$id, ['guide_subjects' => ['Joint studies', 'Science']])->assertOk()->assertJsonPath('data.guide_subject', 'Joint studies')->assertJsonPath('data.guide_subjects', ['Joint studies', 'Science']);
    $arabic = ClassSubject::where('name', 'Arabic')->firstOrFail();
    $this->putJson($this->base.'/'.$arabic->id, ['guide_subjects' => ['Joint studies']])->assertOk();
    $this->putJson($this->base.'/'.$id, ['guide_subjects' => [], 'guide_subject' => 'Science'])->assertUnprocessable();
    $this->putJson($this->base.'/'.$id, ['guide_subjects' => ['Science', 'Science']])->assertUnprocessable();
    $this->putJson($this->base.'/'.$id, ['guide_subjects' => ['Foreign guide']])->assertUnprocessable()->assertSee("Choose a subject from this school's curriculum.", false);
    $this->putJson($this->base.'/'.$id, ['guide_subjects' => []])->assertOk()->assertJsonPath('data.guide_subject', null);
    // Old single-field clients replace the list too, and omission preserves it.
    $this->putJson($this->base.'/'.$id, ['guide_subject' => 'Science'])->assertOk()->assertJsonPath('data.guide_subjects', ['Science']);
    $this->putJson($this->base.'/'.$id, ['name' => 'Natural Science'])->assertOk()->assertJsonPath('data.guide_subjects', ['Science']);
    ($this->guide)('Fresh studies');
    $added = $this->postJson($this->base, ['name' => 'Fresh studies'])->assertCreated()->json('data');
    expect($added['guide_subjects'])->toBeNull(); expect($added['guide_subject'])->toBe('Fresh studies');
    Sanctum::actingAs($this->teacher, ['staff']);
    expect($this->getJson($this->teacherBase.'/subjects')->assertOk()->json('data.0'))->not->toHaveKey('guide_subjects');
    $this->putJson($this->base.'/'.$id, ['guide_subjects' => []])->assertUnauthorized();
});

it('Build C coverage diagnostics are per-class visible-only and read-only', function () {
    ($this->catalogue)('Science'); ($this->catalogue)('Arabic');
    ($this->guide)('Science'); ($this->guide)('Joint studies'); ($this->guide)('Senior studies', 'Grade 5');
    ($this->child)('1'); ($this->enable)();
    $science = ClassSubject::where('name', 'Science')->firstOrFail();
    $science->update(['guide_subjects' => []]);
    ClassSubject::where('name', 'Joint studies')->firstOrFail()->update(['hidden_at' => now()]);
    DB::flushQueryLog(); DB::enableQueryLog();
    foreach (['class-subjects:initialize', 'class-subjects:audit'] as $command) {
        $args = ['--masjid' => $this->school->id]; if ($command === 'class-subjects:initialize') $args['--dry-run'] = true;
        $this->artisan($command, $args)->expectsOutputToContain('Curriculum coverage:')->expectsOutputToContain('follows no curriculum: Science')->expectsOutputToContain('not followed: Joint studies')->assertSuccessful();
    }
    $sql = implode("\n", array_column(DB::getQueryLog(), 'query')); DB::disableQueryLog();
    expect($sql)->not->toMatch('/\b(insert|update|delete|begin|savepoint)\b/i');
});

it('Build C office validates following names within its school and preserves choices on every refusal', function () {
    ($this->catalogue)('Science'); ($this->guide)('Science'); ($this->enable)();
    $org = $this->school->fresh(); $org->forceFill(['capability_overrides' => array_merge((array) $org->capability_overrides, ['class_subject_work' => true])])->save();
    $foreign = Masjid::create(['name' => 'Foreign practice', 'email' => uniqid().'@example.invalid', 'phone' => '+15555550200', 'country_id' => '1', 'city_id' => '1', 'address' => 'Practice', 'latitude' => 0, 'longitude' => 0, 'org_type' => 'school']);
    CurriculumWeek::create(['masjid_id' => $foreign->id, 'subject' => 'Foreign guide', 'grade_label' => 'Grade 1', 'week_no' => 1, 'focus' => 'Practice']);
    Sanctum::actingAs($this->office);
    $id = ClassSubject::where('name', 'Science')->firstOrFail()->id;
    $this->getJson($this->base)->assertOk()->assertJsonPath('meta.guide_subjects', ['Science']);
    foreach ([['Foreign guide'], ['Science', 'Foreign guide'], ['Science', 'Science'], [null], [str_repeat('x', 65)]] as $names) {
        $this->putJson($this->base.'/'.$id, ['guide_subjects' => $names])->assertUnprocessable()->assertSee("Choose a subject from this school's curriculum.", false);
        expect(ClassSubject::findOrFail($id)->followedGuideSubjects())->toBe(['Science']);
    }
    foreach (['Science', null, [1 => 'Science']] as $badList) $this->putJson($this->base.'/'.$id, ['guide_subjects' => $badList])->assertUnprocessable();
    $this->putJson($this->base.'/'.$id, ['guide_subjects' => ['Science'], 'guide_subject' => null])->assertUnprocessable();
    $this->putJson($this->base.'/'.$id, ['guide_subjects' => ['Science'], 'guide_subject' => 'Science'])->assertOk();
});

it('keeps the office manager exactly as it is while subject notes and marks are off', function () {
    ($this->catalogue)('Science'); ($this->guide)('Science'); ($this->guide)('Joint studies'); ($this->child)('1');
    ($this->enable)(); Sanctum::actingAs($this->office);
    $list = $this->getJson($this->base)->assertOk();
    // The picker's list and the tools, as before; no checklist data and no list on any subject.
    expect(array_keys($list->json('meta')))->toBe(['guide_subjects', 'tools']);
    foreach ($list->json('data') as $row) expect($row)->not->toHaveKey('guide_subjects');
    $id = ClassSubject::where('name', 'Science')->firstOrFail()->id;
    $this->putJson($this->base.'/'.$id, ['guide_subjects' => ['Joint studies']])->assertUnprocessable();
    expect(ClassSubject::findOrFail($id)->guide_subjects)->toBeNull();
    // The single picker still saves, as it always has.
    $this->putJson($this->base.'/'.$id, ['guide_subject' => 'Joint studies'])->assertOk()->assertJsonPath('data.guide_subject', 'Joint studies');
});
