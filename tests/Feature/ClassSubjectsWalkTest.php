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

it('restores every unedited legacy value byte for byte and reuses ids on reactivation', function () {
    $this->staff->delete();
    Group::factory()->create(['masjid_id' => $this->org->id, 'kind' => 'class']);
    $rows = [];
    foreach ([null, '["arabic"]', '[ "quran", "arabic" ]', '[]'] as $raw) {
        $teacher = User::factory()->create(['type' => 'Teacher', 'phone' => '+15555550103']);
        $row = GroupStaff::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'user_id' => $teacher->id]);
        DB::table('group_staff')->where('id', $row->id)->update(['subjects' => $raw]);
        $rows[$row->id] = $raw;
    }
    ($this->activate)();
    $ids = DB::table('group_staff')->pluck('class_subject_ids', 'id')->all();
    $before = DB::table('group_staff')->get()->toJson();
    $report = \App\Support\ClassSubjectDisabler::run($this->org->fresh(), true);
    expect($report['blocked'])->toBe([]);
    \Illuminate\Support\Facades\Artisan::call('class-subjects:disable', ['--masjid' => $this->org->id, '--dry-run' => true]);
    file_put_contents(base_path('artifacts/walk-fixes-disable-fresh.log'), \Illuminate\Support\Facades\Artisan::output());
    expect(DB::table('group_staff')->get()->toJson())->toBe($before);
    $this->artisan('class-subjects:disable', ['--masjid' => $this->org->id, '--dry-run' => true])->expectsOutputToContain('0 blockers')->assertSuccessful();
    expect(\App\Support\ClassSubjectDisabler::run($this->org->fresh())['blocked'])->toBe([]);
    expect(DB::table('group_staff')->pluck('subjects', 'id')->all())->toBe($rows);
    ($this->activate)();
    expect(DB::table('group_staff')->pluck('class_subject_ids', 'id')->all())->toBe($ids);
});

it('requires acceptance only for restricted office edits and ON creations', function (string $kind, bool $all) {
    ($this->activate)();
    $ids = $all ? null : [ClassSubject::where('name', 'Science')->firstOrFail()->id];
    $row = GroupStaff::withOfficeSubjectChoice(function () use ($kind, $ids) {
        if ($kind === 'edited') { $row = $this->staff->fresh(); $row->update(['class_subject_ids' => $ids]); return $row; }
        return GroupStaff::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'user_id' => User::factory()->create(['type' => 'Teacher', 'phone' => '+15555550103'])->id, 'class_subject_ids' => $ids]);
    });
    $before = DB::table('group_staff')->get()->toJson();
    $report = \App\Support\ClassSubjectDisabler::run($this->org->fresh(), true);
    expect(count($report['blocked']))->toBe($all ? 0 : 1);
    if ($kind === 'edited' && ! $all) {
        \Illuminate\Support\Facades\Artisan::call('class-subjects:disable', ['--masjid' => $this->org->id, '--dry-run' => true]);
        file_put_contents(base_path('artifacts/walk-fixes-disable-edited.log'), \Illuminate\Support\Facades\Artisan::output());
    }
    expect(DB::table('group_staff')->get()->toJson())->toBe($before);
    expect(\App\Support\ClassSubjectDisabler::run($this->org->fresh(), true, [$row->id + 10000])['blocked'])->not->toBeEmpty();
    if ($all) expect(\App\Support\ClassSubjectDisabler::run($this->org->fresh(), true, [$row->id])['blocked'])->not->toBeEmpty();
    else expect(\App\Support\ClassSubjectDisabler::run($this->org->fresh())['blocked'])->not->toBeEmpty();
    expect(\App\Support\ClassSubjectDisabler::run($this->org->fresh(), false, $all ? [] : [$row->id])['blocked'])->toBe([]);
    expect($row->fresh()->subjects)->toBeNull();
})->with(['edited', 'created'])->with([false, true]);

it('rejects unnecessary and foreign acceptance and generic disable', function () {
    ($this->activate)();
    expect(\App\Support\ClassSubjectDisabler::run($this->org->fresh(), true, [$this->staff->id])['blocked'])->not->toBeEmpty();
    expect(fn () => CapabilityWriter::apply($this->org->fresh(), ['class_subjects' => false], null))->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('automatically takes an implied tool only when free but rejects an explicit duplicate', function () {
    ($this->activate)();
    ClassSubject::where('name', 'ELA')->firstOrFail()->update(['name' => 'Reading']);
    Sanctum::actingAs($this->office);
    $url = "/api/admin/masjids/{$this->org->id}/groups/{$this->group->id}/subjects";
    $this->postJson($url, ['name' => 'English Language Arts'])->assertCreated()->assertJsonPath('data.tool', null);
    $this->postJson($url, ['name' => 'Writing', 'tool' => 'english_letters'])->assertUnprocessable();
});

it('reports pacing losses only for guides in this class and no losses for unchanged rows', function () {
    $this->group->forceFill(['subject_seed_grades' => ['Grade 1']])->save();
    \App\Models\CurriculumWeek::create(['masjid_id' => $this->org->id, 'grade_label' => 'Unrelated grade', 'subject' => 'Science', 'week_no' => 1, 'focus' => 'Practice']);
    $report = ClassSubjectInitializer::run($this->org, true);
    expect(implode("\n", $report[0]['losses']))->not->toContain('pacing guide');
    ($this->activate)();
    expect(ClassSubjectInitializer::run($this->org->fresh(), true)[0]['losses'])->toBe([]);
});

it('shows linked work with current names in office teacher and family payloads while retaining saved text', function (bool $on, bool $linked) {
    $work = ($this->work)('ELA'); $plan = ($this->plan)('ELA');
    $this->staff->update(['subjects' => null]);
    ($this->activate)();
    $ela = ClassSubject::where('name', 'ELA')->firstOrFail();
    $ela->update(['name' => 'English', 'hidden_at' => now()]);
    ClassSubject::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'name' => 'ELA']);
    if (! $linked) foreach (['class_assignments' => $work, 'lesson_plans' => $plan] as $table => $row) DB::table($table)->where('id', $row->id)->update(['class_subject_id' => null]);
    if (! $on) \App\Support\ClassSubjectDisabler::run($this->org->fresh());
    $expected = $on && $linked ? 'English' : 'ELA';
    $contact = \App\Models\Contact::factory()->create(['masjid_id' => $this->org->id]);
    $member = \App\Models\GroupMembership::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'contact_id' => $contact->id, 'role' => 'member']);
    \App\Models\AssignmentScore::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'class_assignment_id' => $work->id, 'group_membership_id' => $member->id, 'status' => 'scored', 'points_earned' => 10]);
    foreach ([$this->office, $this->teacher] as $user) {
        Sanctum::actingAs($user, ['staff']);
        $base = $user === $this->office ? str_replace('/teacher/', '/admin/', $this->base) : $this->base;
        $this->getJson($base.'/assignments')->assertOk()->assertJsonPath('data.0.subject', $expected);
        $this->getJson($base.'/assignments/'.$work->id)->assertOk()->assertJsonPath('data.subject', $expected);
        $this->getJson($base.'/members/'.$member->id.'/grades')->assertOk()->assertJsonPath('data.scores.0.assignment.subject', $expected);
        $this->getJson($base.'/lesson-plans?from=2026-10-08&to=2026-10-08')->assertOk()->assertJsonPath('data.plans.0.subject', $expected);
        if ($on && $user === $this->teacher) $this->putJson($base.'/lesson-plans/'.$plan->id, ['session_date' => '2026-10-08', 'body' => 'Updated activities'])->assertOk()->assertJsonPath('data.subject', $expected);
    }
    $parent = \App\Models\Contact::factory()->create(['masjid_id' => $this->org->id, 'login_email' => uniqid().'@example.invalid', 'login_enabled_at' => now()]);
    \App\Models\GroupMembership::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'contact_id' => $parent->id, 'role' => 'guardian', 'guardian_of_contact_id' => $contact->id, 'confirmed_at' => now()]);
    \Illuminate\Support\Facades\Auth::forgetGuards(); app(TenantContext::class)->forgetTenant();
    $this->withHeader('Authorization', 'Bearer '.$parent->createFamilyToken()->plainTextToken)
        ->getJson(str_replace('/teacher/', '/family/', $this->base).'/members/'.$member->id.'/grades')->assertOk()->assertJsonPath('data.scores.0.assignment.subject', $expected);
    expect($work->fresh()->subject)->toBe('ELA');
    expect($plan->fresh()->subject)->toBe('ELA');
})->with([true, false])->with([true, false]);

it('does not report losing a guide the legacy restriction already refused', function () {
    $this->group->forceFill(['subject_seed_grades' => ['Grade 1']])->save();
    foreach (["Qur'an", 'Science'] as $name) \App\Models\CurriculumWeek::create(['masjid_id' => $this->org->id, 'grade_label' => 'Grade 1', 'subject' => $name, 'week_no' => 1, 'focus' => 'Practice']);
    expect(implode("\n", ClassSubjectInitializer::run($this->org, true)[0]['losses']))->not->toContain('pacing guide');
});


it('keeps no op office choices eligible for exact restoration and preserves recorded NULL provenance', function () {
    $this->staff->update(['subjects' => null]);
    ($this->activate)();
    $row = $this->staff->fresh();
    expect($row->getRawOriginal('class_subjects_translated_from'))->toBe('null');
    GroupStaff::withOfficeSubjectChoice(fn () => $row->update(['class_subject_ids' => null]));
    expect($row->fresh()->class_subject_ids_edited_at)->toBeNull();
    expect(\App\Support\ClassSubjectDisabler::run($this->org->fresh(), true)['blocked'])->toBe([]);
});

it('reports a real combined guide loss once only in the class whose grade carries it', function () {
    SchoolSubject::create(['masjid_id' => $this->org->id, 'name' => 'Islamic Studies']);
    $this->staff->update(['subjects' => ['quran']]);
    $this->group->forceFill(['subject_seed_grades' => ['Grade 1']])->save();
    \App\Models\CurriculumWeek::create(['masjid_id' => $this->org->id, 'grade_label' => 'Grade 1', 'subject' => "Qur'an & Islamic Studies", 'week_no' => 1, 'focus' => 'Practice']);
    $losses = ClassSubjectInitializer::run($this->org, true)[0]['losses'];
    expect($losses)->toContain("LOSS Teacher #{$this->teacher->id}: pacing guide (Qur'an & Islamic Studies)");
    expect(count(array_filter($losses, fn ($line) => str_contains($line, 'pacing guide'))))->toBe(1);
});

it('exports current linked names including withdrawn work but saved names OFF and for unlinked rows', function (bool $on, bool $linked) {
    $work = ($this->work)('ELA'); $plan = ($this->plan)('ELA');
    ($this->activate)();
    ClassSubject::where('name', 'ELA')->firstOrFail()->update(['name' => 'English', 'hidden_at' => now()]);
    ClassSubject::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'name' => 'ELA']);
    $work->delete();
    if (! $linked) foreach (['class_assignments' => $work, 'lesson_plans' => $plan] as $table => $row) DB::table($table)->where('id', $row->id)->update(['class_subject_id' => null]);
    if (! $on) \App\Support\ClassSubjectDisabler::run($this->org->fresh());
    Sanctum::actingAs($this->office);
    foreach (['assignments' => 7, 'lesson_plans' => 3] as $dataset => $column) {
        $response = $this->getJson("/api/admin/masjids/{$this->org->id}/records/export?dataset={$dataset}")->assertOk();
        $rows = array_map('str_getcsv', preg_split('/\r?\n/', trim($response->streamedContent())));
        expect($rows[1][$column])->toBe($on && $linked ? 'English' : 'ELA');
    }
    expect(DB::table('class_assignments')->where('id', $work->id)->value('subject'))->toBe('ELA');
    expect($plan->fresh()->subject)->toBe('ELA');
})->with([true, false])->with([true, false]);
