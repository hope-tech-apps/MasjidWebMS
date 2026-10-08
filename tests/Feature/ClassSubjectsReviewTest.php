<?php

use App\Models\ClassSubject;
use App\Models\CurriculumWeek;
use App\Models\Group;
use App\Models\GroupStaff;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\SchoolSubject;
use App\Models\User;
use App\Support\CapabilityCatalogue;
use App\Support\CapabilityWriter;
use App\Support\ClassSubjectInitializer;
use App\Support\SubjectFence;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    $this->org = Masjid::create(['name' => 'Review School', 'email' => uniqid().'@example.invalid', 'phone' => '+1'.random_int(1000000000, 9999999999), 'country_id' => '1', 'city_id' => '1', 'address' => 'Practice', 'latitude' => 0, 'longitude' => 0, 'org_type' => 'school', 'crm_enabled' => true]);
    $this->group = Group::factory()->create(['masjid_id' => $this->org->id, 'kind' => 'class', 'name' => 'Review Class']);
    $this->teacher = User::factory()->create(['type' => 'Teacher', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
    MasjidUser::create(['masjid_id' => $this->org->id, 'user_id' => $this->teacher->id, 'role' => 'teacher']);
    $this->staff = GroupStaff::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'user_id' => $this->teacher->id, 'role' => 'teacher']);
    $this->office = User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
    foreach (['Arabic', 'ELA', 'Science'] as $name) SchoolSubject::create(['masjid_id' => $this->org->id, 'name' => $name]);
    $this->initialize = fn (bool $enable = true) => $this->artisan('class-subjects:initialize', ['--masjid' => $this->org->id, '--enable' => $enable])->assertSuccessful();
    $this->teacherUrl = "/api/admin/masjids/{$this->org->id}/teachers/{$this->teacher->id}";
    $this->subjectUrl = "/api/admin/masjids/{$this->org->id}/groups/{$this->group->id}/subjects";
    $this->body = ['name' => $this->teacher->name, 'class_ids' => [$this->group->id]];
    Sanctum::actingAs($this->office);
    Mail::fake();
});

afterEach(fn () => app(TenantContext::class)->forgetTenant());

it('R1 canonicalizes padded keys in both restriction maps without widening', function (string $field) {
    ($this->initialize)();
    $arabic = ClassSubject::where('tool', 'arabic_letters')->first();
    $value = $field === 'class_subject_ids' ? [$arabic->id] : ['arabic'];
    $this->putJson($this->teacherUrl, $this->body + [$field => ['0'.$this->group->id => $value]])->assertStatus($field === 'class_subject_ids' ? 200 : 422);
    expect($this->staff->fresh()->class_subject_ids)->toBe($field === 'class_subject_ids' ? [$arabic->id] : null);
})->with(['class_subject_ids', 'class_subjects']);

it('R1 refuses ambiguous or unparseable restriction keys', function (string $key, string $field) {
    ($this->initialize)();
    $value = $field === 'class_subject_ids' ? [ClassSubject::first()->id] : ['arabic'];
    $this->putJson($this->teacherUrl, $this->body + [$field => [$key => $value]])->assertUnprocessable();
})->with(['1x', '+1', '-1', '0', '1.0', '92233720368547758080'])->with(['class_subject_ids', 'class_subjects']);

it('R1 refuses duplicate canonical keys and unmatched restrictions for a new class', function () {
    ($this->initialize)();
    $id = ClassSubject::first()->id;
    $this->putJson($this->teacherUrl, $this->body + ['class_subject_ids' => [$this->group->id => null, '0'.$this->group->id => [$id]]])->assertUnprocessable();
    $other = Group::factory()->create(['masjid_id' => $this->org->id, 'kind' => 'class']);
    $this->postJson("/api/admin/masjids/{$this->org->id}/teachers", [
        'name' => 'Practice Teacher', 'email' => uniqid().'@example.invalid',
        'class_ids' => [$this->group->id, $other->id], 'class_subject_ids' => [$this->group->id => [$id]],
    ])->assertUnprocessable();
});

it('R3 resolves ids only inside their assignment class and never treats unresolved ids as all', function () {
    ($this->initialize)();
    $other = Group::factory()->create(['masjid_id' => $this->org->id, 'kind' => 'class']);
    $foreign = ClassSubject::where('group_id', $other->id)->where('name', 'Science')->first();
    DB::table('group_staff')->where('id', $this->staff->id)->update(['class_subject_ids' => json_encode([$foreign->id])]);
    expect(SubjectFence::allows(SubjectFence::limitsFor($this->teacher, $this->group->id), 'science'))->toBeFalse();
    expect(SubjectFence::assignedIds($this->group->id, $this->teacher->id))->toBe([]);
    DB::table('group_staff')->where('id', $this->staff->id)->update(['class_subject_ids' => '[999999]']);
    expect(SubjectFence::assignedIds($this->group->id, $this->teacher->id))->toBe([]);
    expect(SubjectFence::mayWeighClass($this->teacher, $this->group->id))->toBeFalse();
});

it('R3 keeps only owned saved work when every assigned subject is hidden and no work when gone', function () {
    ($this->initialize)();
    $arabic = ClassSubject::where('tool', 'arabic_letters')->first();
    $this->staff->update(['class_subject_ids' => [$arabic->id]]);
    $arabic->update(['hidden_at' => now()]);
    Sanctum::actingAs($this->teacher, ['staff']);
    $url = "/api/teacher/masjids/{$this->org->id}/groups/{$this->group->id}";
    $this->getJson($url.'/subjects')->assertOk()->assertJsonCount(0, 'data');
    expect(SubjectFence::allows(SubjectFence::limitsFor($this->teacher, $this->group->id), 'arabic'))->toBeTrue();
    expect(SubjectFence::allows(SubjectFence::limitsFor($this->teacher, $this->group->id), 'science'))->toBeFalse();
    $arabic->delete();
    expect(SubjectFence::assignedIds($this->group->id, $this->teacher->id))->toBe([]);
    $this->postJson($url.'/assignments', ['title' => 'Practice', 'subject' => 'Science', 'assigned_on' => '2026-10-01', 'scale' => 'points', 'points_possible' => 10])->assertForbidden();
});

it('R4 separates curriculum links from saved work when guide links deliberately overlap', function () {
    CurriculumWeek::create(['masjid_id' => $this->org->id, 'subject' => 'Science', 'grade_label' => 'Grade 1', 'week_no' => 1, 'focus' => 'Practice']);
    ($this->initialize)();
    $arabic = ClassSubject::where('tool', 'arabic_letters')->first();
    $this->staff->update(['class_subject_ids' => [$arabic->id]]);
    $this->putJson($this->subjectUrl.'/'.$arabic->id, ['guide_subject' => 'Science'])->assertOk();
    $arabic->update(['guide_subject' => 'Science']);
    expect(SubjectFence::allows(SubjectFence::limitsFor($this->teacher, $this->group->id), 'science'))->toBeFalse();
    expect($arabic->matchingKeys())->not->toContain('science');
    ClassSubject::where('name', 'Science')->delete();
    $this->postJson($this->subjectUrl, ['name' => 'Science', 'tool' => null])->assertCreated();
});

it('R5 ignores unknown new assignment fields while OFF on invite and update', function (mixed $value) {
    $this->putJson($this->teacherUrl, $this->body + ['class_subject_ids' => $value])->assertOk();
    $this->postJson("/api/admin/masjids/{$this->org->id}/teachers", [
        'name' => 'Practice Teacher', 'email' => uniqid().'@example.invalid', 'class_ids' => [$this->group->id], 'class_subject_ids' => $value,
    ])->assertCreated();
})->with([[null], [['bad' => ['bad']]], ['bad'], [[]]]);

it('R6 refuses every destructive down on feature wide populated state', function (string $state) {
    if ($state === 'subjects') ClassSubject::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'name' => 'Practice']);
    elseif ($state === 'groups') $this->group->forceFill(['subject_seed_grades' => ['1st']])->save();
    else DB::table('group_staff')->where('id', $this->staff->id)->update(['class_subjects_mapped_at' => now()]);
    foreach (['000300_add_class_subject_assignments_to_group_staff', '000200_add_class_subject_initialization_to_groups', '000100_create_class_subjects_table'] as $suffix) {
        expect(fn () => (require database_path('migrations/2026_10_08_'.$suffix.'.php'))->down())->toThrow(RuntimeException::class, 'Refusing');
        expect(Schema::hasColumn('group_staff', 'class_subject_ids'))->toBeTrue();
        expect(Schema::hasColumn('groups', 'class_subjects_initialized_at'))->toBeTrue();
        expect(Schema::hasTable('class_subjects'))->toBeTrue();
    }
})->with(['subjects', 'groups', 'staff']);

it('R7 initializes a converted class through office and model save paths', function (bool $http) {
    $general = Group::factory()->create(['masjid_id' => $this->org->id, 'kind' => 'general']);
    ($this->initialize)();
    if ($http) $this->putJson("/api/admin/masjids/{$this->org->id}/groups/{$general->id}", ['kind' => 'class', 'name' => 'Converted Class'])->assertOk();
    else $general->update(['kind' => 'class']);
    expect($general->fresh()->class_subjects_initialized_at)->not->toBeNull();
    expect(ClassSubject::where('group_id', $general->id)->count())->toBe(3);
    expect(ClassSubjectInitializer::ready($this->org->fresh()))->toBeTrue();
})->with([true, false]);

it('R7 and R11 translate archived assignments at activation and preserve them on restoration', function () {
    $this->group->delete();
    ($this->initialize)();
    expect(ClassSubject::where('group_id', $this->group->id)->count())->toBe(3);
    expect(ClassSubjectInitializer::ready($this->org->fresh()))->toBeTrue();
    $this->group->restore();
    expect($this->group->fresh()->class_subjects_initialized_at)->not->toBeNull();
    expect(ClassSubject::where('group_id', $this->group->id)->count())->toBe(3);
    expect(ClassSubjectInitializer::ready($this->org->fresh()))->toBeTrue();
});

it('R8 resolves current renamed names to their guide on both curriculum endpoints', function () {
    CurriculumWeek::create(['masjid_id' => $this->org->id, 'subject' => 'English Language Arts', 'grade_label' => 'Grade 1', 'week_no' => 1, 'focus' => 'Reading practice', 'standard_code' => 'RI.1']);
    ($this->initialize)();
    $ela = ClassSubject::where('name', 'ELA')->first(); $ela->update(['name' => 'Reading']);
    $this->staff->update(['class_subject_ids' => [$ela->id]]);
    Sanctum::actingAs($this->teacher, ['staff']);
    $url = "/api/teacher/masjids/{$this->org->id}/curriculum";
    foreach (['Reading'] as $name) {
        $params = '?'.http_build_query(['group_id' => $this->group->id, 'grade' => 'Grade 1', 'subject' => $name, 'week' => 1]);
        $this->getJson($url.$params)->assertOk()->assertJsonCount(1, 'data.weeks')->assertJsonPath('data.cell.subject', 'English Language Arts');
        $this->getJson($url.'/standards'.$params.'&q=reading')->assertOk()->assertJsonPath('data.matches.0.in_scope', true);
    }
});

it('R9 preserves empty override arrays with and without a dry run', function () {
    $this->org->forceFill(['capability_overrides' => []])->save();
    expect($this->org->fresh()->toArray()['capability_overrides'])->toBe([]);
    $this->artisan('class-subjects:initialize', ['--masjid' => $this->org->id, '--dry-run' => true])->assertSuccessful();
    expect($this->org->fresh()->toArray()['capability_overrides'])->toBe([]);
});

it('R10 omits the switch from all OFF catalogues and lists it on an enabled organization', function () {
    expect(collect(CapabilityCatalogue::forOrgType('school'))->pluck('entries')->flatten(1)->pluck('key')->all())->not->toContain('class_subjects');
    $url = "/api/admin/masjids/{$this->org->id}/capabilities";
    expect(collect($this->getJson($url)->assertOk()->json('data.groups'))->pluck('entries')->flatten(1)->pluck('key')->all())->not->toContain('class_subjects');
    ($this->initialize)();
    expect(collect($this->getJson($url)->assertOk()->json('data.groups'))->pluck('entries')->flatten(1)->pluck('key')->all())->toContain('class_subjects');
});

it('R12 adds subjects for current grades without replacing renamed hidden subjects tools or assignments', function () {
    ($this->initialize)();
    $arabic = ClassSubject::where('name', 'Arabic')->first(); $arabic->update(['name' => 'Language Practice', 'hidden_at' => now(), 'position' => 7]);
    $this->staff->update(['class_subject_ids' => [$arabic->id]]);
    SchoolSubject::create(['masjid_id' => $this->org->id, 'name' => 'Health']);
    $snapshot = DB::table('class_subjects')->get()->keyBy('id')->toArray();
    $this->postJson($this->subjectUrl.'/add-for-current-grades')->assertOk()->assertJsonCount(5, 'data');
    foreach ($snapshot as $id => $row) expect((array) DB::table('class_subjects')->find($id))->toBe((array) $row);
    expect($this->staff->fresh()->class_subject_ids)->toBe([$arabic->id]);
    $this->postJson($this->subjectUrl.'/add-for-current-grades')->assertOk()->assertJsonCount(5, 'data');
    Sanctum::actingAs($this->teacher, ['staff']);
    $this->postJson($this->subjectUrl.'/add-for-current-grades')->assertUnauthorized();
});

it('R13 reports each proposed row assignment loss blocker and school summary without teacher names', function () {
    $this->staff->update(['subjects' => ['arabic', 'quran', 'islamic_studies']]);
    $this->artisan('class-subjects:initialize', ['--masjid' => $this->org->id, '--dry-run' => true, '--enable' => true])
        ->expectsOutputToContain('CREATE Arabic | holds=arabic_letters | guide=none')
        ->expectsOutputToContain('Teacher #'.$this->teacher->id.': [arabic, quran, islamic_studies] -> [Arabic]')
        ->expectsOutputToContain('LOSS Teacher #'.$this->teacher->id.': English letters')
        ->expectsOutputToContain('legacy subject quran cannot be mapped')
        ->expectsOutputToContain('legacy subject islamic_studies cannot be mapped')
        ->expectsOutputToContain('School summary:')->assertFailed();
    expect(ClassSubject::count())->toBe(0);
});


it('R1 refuses unparseable map and restriction values before any assignment changes', function (string $field, mixed $value) {
    ($this->initialize)();
    $before = $this->staff->fresh()->getRawOriginal();
    $this->putJson($this->teacherUrl, $this->body + [$field => $value])->assertUnprocessable();
    expect($this->staff->fresh()->getRawOriginal())->toBe($before);
})->with(['class_subjects', 'class_subject_ids'])->with([[null], ['bad'], [['bad']], [[1 => 'bad']], [[1 => [null]]]]);

it('R3 rejects foreign organization and malformed stored ids on every resolver', function () {
    ($this->initialize)();
    $otherOrg = $this->org->replicate(); $otherOrg->name = 'Another School';
    $otherOrg->email = uniqid().'@example.invalid'; $otherOrg->phone = '+1'.random_int(1000000000, 9999999999); $otherOrg->save();
    $otherGroup = Group::factory()->create(['masjid_id' => $otherOrg->id, 'kind' => 'class']);
    $foreign = ClassSubject::create(['masjid_id' => $otherOrg->id, 'group_id' => $otherGroup->id, 'name' => 'Science']);
    // Replication carries the ON capability; the central model path seeds the class.
    foreach ([[$foreign->id], ['1x'], [true], 'bad'] as $ids) {
        DB::table('group_staff')->where('id', $this->staff->id)->update(['class_subject_ids' => json_encode($ids)]);
        expect(SubjectFence::assignedIds($this->group->id, $this->teacher->id))->toBe([]);
    }
    expect(SubjectFence::allows(SubjectFence::limitsForIds([$foreign->id], $this->group), 'science'))->toBeFalse();
});

it('R6 guards the fourth down and initialization markers even without subject rows', function () {
    ($this->initialize)();
    ClassSubject::query()->delete();
    GroupStaff::query()->update(['class_subjects_mapped_at' => null, 'class_subject_ids' => null, 'class_subject_legacy_snapshot' => null]);
    Group::query()->update(['class_subjects_initialized_at' => null]);
    foreach (glob(database_path('migrations/2026_10_08_000[1234]00_*')) as $file) {
        expect(fn () => (require $file)->down())->toThrow(RuntimeException::class, 'Refusing');
        expect(Schema::hasColumn('group_staff', 'class_subject_legacy_snapshot'))->toBeTrue();
    }
});

it('R12 uses the current roster grades instead of the reserved seed and never hides or unassigns', function () {
    ($this->initialize)();
    $this->group->forceFill(['subject_seed_grades' => ['Grade 2']])->save();
    $contact = \App\Models\Contact::factory()->create(['masjid_id' => $this->org->id]);
    \App\Models\GroupMembership::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id,
        'contact_id' => $contact->id, 'role' => 'member', 'grade_label' => 'Grade 1', 'joined_at' => now()]);
    SchoolSubject::create(['masjid_id' => $this->org->id, 'name' => 'Health', 'grade_labels' => ['Grade 1']]);
    SchoolSubject::create(['masjid_id' => $this->org->id, 'name' => 'History', 'grade_labels' => ['Grade 2']]);
    $this->postJson($this->subjectUrl.'/add-for-current-grades')->assertOk();
    expect(ClassSubject::where('group_id', $this->group->id)->pluck('name')->all())->toContain('Health')->not->toContain('History');
    expect(ClassSubject::where('group_id', $this->group->id)->count())->toBe(4);
});

it('R13 reports every class and preserves no work on a real blocked initialization', function () {
    $other = Group::factory()->create(['masjid_id' => $this->org->id, 'kind' => 'class', 'name' => 'Second Practice Class']);
    $this->staff->update(['subjects' => ['quran']]);
    GroupStaff::create(['masjid_id' => $this->org->id, 'group_id' => $other->id, 'user_id' => $this->teacher->id, 'role' => 'teacher', 'subjects' => ['islamic_studies']]);
    $status = \Illuminate\Support\Facades\Artisan::call('class-subjects:initialize', ['--masjid' => $this->org->id, '--enable' => true]);
    $output = \Illuminate\Support\Facades\Artisan::output();
    expect($status)->toBe(1);
    expect($output)->toContain('Class Review Class:', 'Class Second Practice Class:',
        'legacy subject quran cannot be mapped', 'legacy subject islamic_studies cannot be mapped', '2 blocked mappings.');
    expect(ClassSubject::count())->toBe(0);
    expect($this->org->fresh()->hasCapability('class_subjects'))->toBeFalse();
    expect($this->group->fresh()->class_subjects_initialized_at)->toBeNull();
});


it('R13 produces a pasteable dry run with internal teacher ids only', function () {
    CurriculumWeek::create(['masjid_id' => $this->org->id, 'subject' => 'Arabic Language', 'grade_label' => 'Grade 1', 'week_no' => 1, 'focus' => 'Practice']);
    $this->staff->update(['subjects' => ['arabic', 'quran', 'islamic_studies']]);
    $status = \Illuminate\Support\Facades\Artisan::call('class-subjects:initialize', ['--masjid' => $this->org->id, '--dry-run' => true, '--enable' => true]);
    $output = \Illuminate\Support\Facades\Artisan::output();
    expect($status)->toBe(1);
    expect($output)->toContain('CREATE Arabic | holds=arabic_letters | guide=Arabic Language', 'Teacher #'.$this->teacher->id, 'LOSS', 'BLOCKED', 'School summary:');
    expect($output)->not->toContain($this->teacher->name, $this->teacher->email);
    file_put_contents(base_path('artifacts/review-dry-run-sample.log'), $output);
});


it('R1 refuses boolean and floating point ids instead of coercing a restriction', function (mixed $id) {
    ($this->initialize)();
    $this->putJson($this->teacherUrl, $this->body + ['class_subject_ids' => [$this->group->id => [$id]]], [], JSON_PRESERVE_ZERO_FRACTION)->assertUnprocessable();
})->with([[true], [1.0]]);

it('R3 treats an explicit empty own-subject list as no subjects at the edge and on reads', function (bool $http) {
    ($this->initialize)();
    if ($http) $this->putJson($this->teacherUrl, $this->body + ['class_subject_ids' => [$this->group->id => []]])->assertOk()->assertJsonPath('data.classes.0.class_subject_ids', []);
    else $this->staff->update(['class_subject_ids' => []]);
    expect(SubjectFence::assignedIds($this->group->id, $this->teacher->id))->toBe([]);
    expect(SubjectFence::allows(SubjectFence::limitsFor($this->teacher, $this->group->id), 'science'))->toBeFalse();
    expect(SubjectFence::mayWeighClass($this->teacher, $this->group->id))->toBeFalse();
    Sanctum::actingAs($this->teacher, ['staff']);
    $this->getJson("/api/teacher/masjids/{$this->org->id}/groups/{$this->group->id}/subjects")->assertOk()->assertJsonCount(0, 'data');
})->with([true, false]);
