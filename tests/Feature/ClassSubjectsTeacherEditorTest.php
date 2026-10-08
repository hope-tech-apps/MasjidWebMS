<?php

use App\Models\{ClassSubject, Group, GroupStaff, Masjid, MasjidUser, SchoolSubject, User};
use App\Support\{ClassSubjectInitializer, TenantContext};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Artisan, DB, Mail};
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);
beforeEach(function () {
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-08 12:00:00 UTC'));
    Mail::fake();
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    $this->org = Masjid::create(['name' => 'Editor School', 'email' => 'school@example.invalid', 'phone' => '+15555550100', 'country_id' => '1', 'city_id' => '1', 'address' => 'Practice', 'latitude' => 0, 'longitude' => 0, 'org_type' => 'school', 'crm_enabled' => true]);
    $this->group = Group::factory()->create(['masjid_id' => $this->org->id, 'name' => 'Grade 2', 'slug' => 'grade-2', 'description' => null, 'kind' => 'class']);
    $this->teacher = User::factory()->create(['type' => 'Teacher', 'name' => 'Practice Teacher', 'email' => 'teacher@example.invalid', 'phone' => '+15555550101', 'email_verified_at' => null]);
    $this->office = User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+15555550102']);
    MasjidUser::create(['masjid_id' => $this->org->id, 'user_id' => $this->teacher->id, 'role' => 'teacher']);
    $this->staff = GroupStaff::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'user_id' => $this->teacher->id, 'role' => 'teacher', 'subjects' => ['arabic'], 'assigned_at' => now()]);
    foreach (['Mathematics', 'Science', 'Arabic'] as $position => $name) SchoolSubject::create(['masjid_id' => $this->org->id, 'name' => $name, 'position' => $position]);
    $this->base = "/api/admin/masjids/{$this->org->id}";
    Sanctum::actingAs($this->office);
});
afterEach(fn () => app(TenantContext::class)->forgetTenant());

it('pins complete OFF teacher index/detail group index/detail and staff CSV before ON serializer additions', function () {
    $payloads = [];
    foreach (['teachers', 'teachers/'.$this->teacher->id, 'groups', 'groups/'.$this->group->id] as $path) {
        $payloads[$path] = $this->getJson($this->base.'/'.$path)->assertOk()->json();
    }
    $this->staff->delete(); // The legacy CSV has no user relation; pin its unchanged empty output.
    $payloads['staff_csv'] = $this->get($this->base.'/records/export?dataset=class_staff')->assertOk()->streamedContent();
    expect($payloads)->toBe(json_decode(file_get_contents(base_path('tests/fixtures/ClassSubjects/teacher-editor-off.json')), true));
});

it('serializes ON limits as current ordered names including hidden subjects at every office display boundary', function (string $kind) {
    ClassSubjectInitializer::run($this->org, false, true);
    $subjects = ClassSubject::where('group_id', $this->group->id)->orderBy('position')->get();
    $math = $subjects->firstWhere('name', 'Mathematics'); $science = $subjects->firstWhere('name', 'Science');
    $science->update(['hidden_at' => now(), 'name' => 'Natural Science']);
    $ids = match ($kind) { 'all' => null, 'none' => [], 'list' => [$science->id, $math->id], 'hidden' => [$science->id] };
    GroupStaff::withOfficeSubjectChoice(fn () => $this->staff->fresh()->update(['class_subject_ids' => $ids]));
    $names = $ids === null ? null : $subjects->whereIn('id', $ids)->map(fn ($s) => ['id' => (int) $s->id, 'name' => $s->name, 'position' => (int) $s->position, 'hidden_at' => $s->hidden_at?->toISOString()])->values()->all();
    $this->getJson($this->base.'/teachers')->assertOk()->assertJsonPath('data.0.classes.0.class_subject_ids', $ids)->assertJsonPath('data.0.classes.0.class_subject_names', $names);
    $this->getJson($this->base.'/groups')->assertOk()->assertJsonPath('data.data.0.teachers.0.class_subject_names', $names);
    $this->getJson($this->base.'/groups/'.$this->group->id)->assertOk()->assertJsonPath('data.teachers.0.class_subject_names', $names);
    $body = ['name' => $this->teacher->name, 'class_ids' => [$this->group->id], 'class_subject_ids' => [$this->group->id => $ids]];
    $this->putJson($this->base.'/teachers/'.$this->teacher->id, $body)->assertOk()->assertJsonPath('data.classes.0.class_subject_names', $names);
    $this->postJson($this->base.'/teachers', $body + ['email' => 'new@example.invalid'])->assertCreated()->assertJsonPath('data.classes.0.class_subject_names', $names);
    $csv = $this->get($this->base.'/records/export?dataset=class_staff')->assertOk()->streamedContent();
    $words = $ids === null ? 'All subjects' : ($ids === [] ? 'No subjects' : implode(', ', array_map(fn ($s) => $s['name'].($s['hidden_at'] ? ' (hidden)' : ''), $names)));
    expect($csv)->toContain('Subjects')->toContain($words);
})->with(['all', 'none', 'list', 'hidden']);

it('reports a merge count including zero and keeps the catalog intact', function () {
    ClassSubjectInitializer::run($this->org, false, true);
    $url = $this->base.'/groups/'.$this->group->id.'/subjects/add-for-current-grades';
    $this->postJson($url)->assertOk()->assertJsonPath('meta.subjects_added', 0);
    SchoolSubject::create(['masjid_id' => $this->org->id, 'name' => 'History', 'position' => 3]);
    $this->postJson($url)->assertOk()->assertJsonPath('meta.subjects_added', 1);
    $this->postJson($url)->assertOk()->assertJsonPath('meta.subjects_added', 0);
});

it('prints zero losses for an already ON school that changes no rows', function () {
    ClassSubjectInitializer::run($this->org, false, true);
    $before = DB::table('group_staff')->get()->toJson();
    Artisan::call('class-subjects:initialize', ['--masjid' => $this->org->id, '--dry-run' => true]);
    $output = Artisan::output(); file_put_contents(base_path('artifacts/teacher-editor-setup-rerun.log'), $output);
    expect($output)->toContain('0 subjects; 0 mappings; 0 losses')->not->toContain('LOSS Teacher');
    expect(DB::table('group_staff')->get()->toJson())->toBe($before);
});

it('places foreign subject and missing explicit new-class refusals under the class id', function () {
    ClassSubjectInitializer::run($this->org, false, true);
    $body = ['name' => $this->teacher->name, 'class_ids' => [$this->group->id]];
    $response = $this->putJson($this->base.'/teachers/'.$this->teacher->id, $body + ['class_subject_ids' => [$this->group->id => [999999]]])->assertUnprocessable();
    expect($response->json('data')['class_subject_ids.'.$this->group->id] ?? null)->toBe(['Choose subjects belonging to the named class in this school.']);
    $response = $this->postJson($this->base.'/teachers', $body + ['email' => 'new@example.invalid'])->assertUnprocessable();
    expect($response->json('data')['class_subject_ids.'.$this->group->id] ?? null)->toBe(['State the subjects for every new class explicitly; choose all subjects explicitly when intended.']);
});

it('keeps foreign-school subject names out of ON lists detail and staff export and batches catalogs', function () {
    ClassSubjectInitializer::run($this->org, false, true);
    $other = Masjid::create(['name' => 'Other School', 'email' => 'other@example.invalid', 'phone' => '+15555550109', 'country_id' => '1', 'city_id' => '1', 'address' => 'Practice', 'latitude' => 0, 'longitude' => 0, 'org_type' => 'school', 'crm_enabled' => true]);
    $foreign = Group::factory()->create(['masjid_id' => $other->id, 'kind' => 'class']);
    SchoolSubject::create(['masjid_id' => $other->id, 'name' => 'Foreign private subject']);
    ClassSubjectInitializer::run($other, false, true);
    $foreignId = ClassSubject::where('group_id', $foreign->id)->value('id');
    DB::table('group_staff')->where('id', $this->staff->id)->update(['class_subject_ids' => json_encode([$foreignId])]);
    foreach (['teachers', 'groups', 'groups/'.$this->group->id] as $path) {
        DB::enableQueryLog(); DB::flushQueryLog();
        $response = $this->getJson($this->base.'/'.$path)->assertOk();
        expect($response->getContent())->not->toContain('Foreign private subject');
        $queries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'from "class_subjects"'));
        expect($queries->count())->toBe($path === 'groups/'.$this->group->id ? 2 : 1);
        DB::disableQueryLog();
    }
    $this->getJson($this->base.'/groups/'.$foreign->id)->assertNotFound();
    $csv = $this->get($this->base.'/records/export?dataset=class_staff')->assertOk()->streamedContent();
    expect($csv)->not->toContain('Foreign private subject')->toContain('Subjects unavailable');
});
