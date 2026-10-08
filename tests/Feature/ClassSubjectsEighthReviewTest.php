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


it('keeps untranslated legacy authority through preview disable audit and the editor round trip', function (?string $raw) {
    ($this->activate)();
    DB::table('group_staff')->where('id', $this->staff->id)->update([
        'subjects' => $raw, 'class_subject_ids' => null, 'class_subjects_mapped_at' => null,
        'class_subject_ids_edited_at' => null, 'class_subjects_translated_from' => null,
    ]);
    expect(SubjectFence::assignedIds($this->group->id, $this->teacher->id))->toBe([]);
    $before = DB::table('group_staff')->where('id', $this->staff->id)->first();
    $this->artisan('class-subjects:audit', ['--masjid' => $this->org->id])
        ->expectsOutputToContain('No subject access is granted while ON')->assertSuccessful();
    ($this->activate)(); // An ON rerun must not translate a late writer.
    Sanctum::actingAs($this->office);
    $url = "/api/admin/masjids/{$this->org->id}/teachers/{$this->teacher->id}";
    $detail = $this->getJson($url)->assertOk()->json('data');
    expect($detail['class_subject_ids'][$this->group->id])->toBe([]);
    $this->putJson($url, ['name' => $this->teacher->name, 'class_ids' => [$this->group->id]])->assertOk();
    expect(SubjectFence::assignedIds($this->group->id, $this->teacher->id))->toBe([]);
    expect(DB::table('group_staff')->where('id', $this->staff->id)->first())->toEqual($before);
    $report = \App\Support\ClassSubjectDisabler::run($this->org->fresh(), true);
    expect($report['blocked'])->toBe([]);
    $assignment = collect($report['assignments'])->firstWhere('id', $this->staff->id);
    expect($assignment['ids'])->toBe([]);
    expect($assignment['raw_legacy'])->toBe($raw);
    expect($assignment['accepted'])->toBeFalse();
    $this->artisan('class-subjects:disable', ['--masjid' => $this->org->id, '--dry-run' => true])
        ->expectsOutputToContain('untranslated, legacy value kept')->assertSuccessful();
    expect(DB::table('group_staff')->where('id', $this->staff->id)->first())->toEqual($before);
    $writes = [];
    DB::listen(function ($query) use (&$writes) {
        if (preg_match('/^(update|insert|delete)/i', $query->sql) && str_contains($query->sql, 'group_staff')) $writes[] = $query->sql;
    });
    $this->artisan('class-subjects:disable', ['--masjid' => $this->org->id])
        ->expectsOutputToContain('untranslated, legacy value kept')->assertSuccessful();
    expect(DB::table('group_staff')->where('id', $this->staff->id)->value('subjects'))->toBe($raw);
    expect($writes)->toBe([]);
    ($this->activate)();
    expect(SubjectFence::assignedIds($this->group->id, $this->teacher->id))->toBe([]);
    expect(DB::table('group_staff')->where('id', $this->staff->id)->first())->toEqual($before);
})->with([null, '[]', '["arabic"]', '[ "quran", "arabic" ]']);

it('refuses a by day copy into a different identity and allows an explicit plan id', function (string $target) {
    $this->staff->update(['subjects' => null]);
    $source = ($this->plan)('ELA');
    $other = LessonPlan::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id,
        'subject' => $target === 'linked' ? 'Science' : 'Literacy', 'body' => 'Target activities', 'session_date' => '2026-10-09']);
    ($this->activate)();
    $ela = ClassSubject::where('name', 'ELA')->firstOrFail(); $ela->update(['name' => 'Literacy']);
    Sanctum::actingAs($this->teacher, ['staff']);
    $before = DB::table('lesson_plans')->where('id', $other->id)->first();
    $payload = ['session_date' => '2026-10-09', 'class_subject_id' => $ela->id, 'body' => 'Copied activities'];
    $this->putJson($this->base.'/lesson-plans', $payload)->assertConflict();
    expect(DB::table('lesson_plans')->where('id', $other->id)->first())->toEqual($before);
    $this->putJson($this->base.'/lesson-plans/'.$other->id, $payload)->assertOk()->assertJsonPath('data.id', $other->id);
    expect($other->fresh()->class_subject_id)->toBe($ela->id);
})->with(['linked', 'unlinked']);

it('matches linked by day work by id after a rename instead of its saved or displayed text', function () {
    $this->staff->update(['subjects' => null]);
    $linked = ($this->plan)('ELA');
    $unlinked = ($this->plan)('Literacy');
    ($this->activate)();
    $ela = ClassSubject::where('name', 'ELA')->firstOrFail(); $ela->update(['name' => 'Literacy']);
    Sanctum::actingAs($this->teacher, ['staff']);
    $before = DB::table('lesson_plans')->where('id', $unlinked->id)->first();
    $this->putJson($this->base.'/lesson-plans', ['session_date' => '2026-10-08', 'class_subject_id' => $ela->id, 'body' => 'Updated'])
        ->assertOk()->assertJsonPath('data.id', $linked->id);
    expect(DB::table('lesson_plans')->where('id', $unlinked->id)->first())->toEqual($before);
});


it('gives equal displayed summary names distinct identities only ON', function (bool $on) {
    $first = ($this->work)('Arabic'); $second = ($this->work)('Literacy');
    ($this->activate)();
    $subject = ClassSubject::where('name', 'Arabic')->firstOrFail(); $subject->update(['name' => 'Literacy']);
    $contact = \App\Models\Contact::factory()->create(['masjid_id' => $this->org->id]);
    $member = \App\Models\GroupMembership::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'contact_id' => $contact->id, 'role' => 'member']);
    foreach ([[$first, 10], [$second, 0]] as [$work, $earned]) \App\Models\AssignmentScore::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'class_assignment_id' => $work->id, 'group_membership_id' => $member->id, 'status' => 'scored', 'points_earned' => $earned]);
    if (! $on) \App\Support\ClassSubjectDisabler::run($this->org->fresh());
    app(TenantContext::class)->set($this->org->id);
    $rows = \App\Support\GradeRecord::summaryFor($member->id)['by_subject'];
    expect(array_column($rows, 'subject'))->toBe($on ? ['Literacy', 'Literacy'] : ['Arabic', 'Literacy']);
    expect(array_column($rows, 'percent'))->toBe([100.0, 0.0]);
    expect(array_column($rows, 'subject_identity'))->toBe($on ? [json_encode(['id', $subject->id]), json_encode(['text', 'literacy'])] : []);
})->with([true, false]);


it('keeps retained untranslated rows closed without preventing fresh OFF rows from translating', function () {
    ($this->activate)();
    DB::table('group_staff')->where('id', $this->staff->id)->update([
        'subjects' => null, 'class_subject_ids' => null, 'class_subjects_mapped_at' => null,
        'class_subject_ids_edited_at' => null, 'class_subjects_translated_from' => null,
    ]);
    \App\Support\ClassSubjectDisabler::run($this->org->fresh());
    $fresh = GroupStaff::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id,
        'user_id' => User::factory()->create(['type' => 'Teacher', 'phone' => '+15555550103'])->id, 'subjects' => ['arabic']]);
    $preview = ClassSubjectInitializer::run($this->org->fresh(), true);
    $retained = collect($preview[0]['assignments'])->firstWhere('teacher_id', $this->teacher->id);
    expect($retained['will_map'])->toBeFalse(); expect($retained['names'])->toBe([]);
    ($this->activate)();
    expect(SubjectFence::assignedIds($this->group->id, $this->teacher->id))->toBe([]);
    expect(SubjectFence::assignedIds($this->group->id, $fresh->user_id))->toBe([ClassSubject::where('name', 'Arabic')->firstOrFail()->id]);
    // A deliberate office choice repairs the retained row and becomes its first authority fact.
    GroupStaff::withOfficeSubjectChoice(fn () => $this->staff->fresh()->update(['class_subject_ids' => null]));
    expect(SubjectFence::assignedIds($this->group->id, $this->teacher->id))->toBeNull();
    \App\Support\ClassSubjectDisabler::run($this->org->fresh());
    ($this->activate)();
    expect(SubjectFence::assignedIds($this->group->id, $this->teacher->id))->toBeNull();
});
