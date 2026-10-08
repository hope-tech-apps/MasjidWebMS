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

it('has no preactivation writes and atomically links exact own keys on activation', function () {
    $work = ($this->work)('Arabic'); $plan = ($this->plan)('Arabic Language'); $orphan = ($this->work)('History');
    $this->artisan('class-subjects:initialize', ['--masjid' => $this->org->id, '--dry-run' => true])->assertSuccessful();
    expect(ClassSubject::count())->toBe(0);
    $this->artisan('class-subjects:initialize', ['--masjid' => $this->org->id])->assertFailed();
    expect(ClassSubject::count())->toBe(0);
    ($this->activate)();
    $arabic = ClassSubject::where('group_id', $this->group->id)->where('name', 'Arabic')->firstOrFail();
    expect($work->fresh()->class_subject_id)->toBe($arabic->id);
    expect($plan->fresh()->class_subject_id)->toBe($arabic->id);
    expect($orphan->fresh()->class_subject_id)->toBeNull();
});

it('fences saved work by ids across the assignment and link matrix', function (string $assignment, string $link) {
    $work = ($this->work)('Arabic'); $plan = ($this->plan)('Arabic');
    ($this->activate)();
    $subjects = ClassSubject::where('group_id', $this->group->id)->get()->keyBy('name');
    $arabic = $subjects['Arabic']; $science = $subjects['Science'];
    $other = Group::factory()->create(['masjid_id' => $this->org->id, 'kind' => 'class']);
    $foreign = ClassSubject::where('group_id', $other->id)->firstOrFail();
    $ids = match ($assignment) { 'all' => null, 'none' => [], 'one', 'hidden' => [$arabic->id], 'two' => [$arabic->id, $science->id], 'foreign' => [$foreign->id] };
    if ($assignment === 'hidden') $arabic->update(['hidden_at' => now()]);
    DB::table('group_staff')->where('id', $this->staff->id)->update(['class_subject_ids' => $ids === null ? null : json_encode($ids)]);
    $linked = match ($link) { 'assigned' => $arabic->id, 'other' => $science->id, 'null' => null };
    foreach (['class_assignments' => $work, 'lesson_plans' => $plan] as $table => $row) DB::table($table)->where('id', $row->id)->update(['class_subject_id' => $linked]);
    // Deliberately misleading text: it must have no influence on access.
    DB::table('class_assignments')->where('id', $work->id)->update(['subject' => 'Science', 'subject_key' => 'science']);
    DB::table('lesson_plans')->where('id', $plan->id)->update(['subject' => 'Science', 'subject_key' => 'science']);
    $contact = \App\Models\Contact::factory()->create(['masjid_id' => $this->org->id, 'email' => null]);
    $member = \App\Models\GroupMembership::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'contact_id' => $contact->id, 'role' => 'member']);
    $allowed = $ids === null || ($linked !== null && in_array($linked, $ids, true));
    Sanctum::actingAs($this->teacher, ['staff']);
    $this->getJson($this->base.'/assignments')->assertOk()->assertJsonCount($allowed ? 1 : 0, 'data');
    $this->getJson($this->base.'/assignments/'.$work->id)->assertStatus($allowed ? 200 : 404);
    $this->putJson($this->base.'/assignments/'.$work->id, ['title' => 'Practice edited', 'subject' => 'Science', 'assigned_on' => '2026-10-08', 'scale' => 'points', 'points_possible' => 10])->assertStatus($allowed ? 200 : 404);
    $this->putJson($this->base.'/assignments/'.$work->id.'/scores', ['scores' => [['membership_id' => $member->id, 'status' => 'scored', 'points_earned' => 5]]])->assertStatus($allowed ? 200 : 404);
    $this->getJson($this->base.'/lesson-plans?from=2026-10-08&to=2026-10-08')->assertOk()->assertJsonCount($allowed ? 1 : 0, 'data.plans');
    $this->putJson($this->base.'/lesson-plans/'.$plan->id, ['session_date' => '2026-10-08', 'subject' => 'Science', 'body' => 'Practice edited'])->assertStatus($allowed ? 200 : 404);
    $grades = $this->getJson($this->base.'/members/'.$member->id.'/grades')->assertOk();
    $grades->assertJsonCount($allowed ? 1 : 0, 'data.scores')->assertJsonPath('data.summary.recorded', $allowed ? 1 : 0);
    $this->deleteJson($this->base.'/assignments/'.$work->id)->assertStatus($allowed ? 200 : 404);
    $this->deleteJson($this->base.'/lesson-plans/'.$plan->id)->assertStatus($allowed ? 200 : 404);
})->with(['all', 'none', 'one', 'two', 'hidden', 'foreign'])->with(['assigned', 'other', 'null']);

it('ignores every legacy model and relationship edit on and audits a late off write', function (string $writer) {
    ($this->activate)(); $ids = $this->staff->fresh()->class_subject_ids;
    match ($writer) {
        'model' => $this->staff->fresh()->update(['subjects' => null]),
        'sync' => $this->group->staff()->syncWithoutDetaching([$this->teacher->id => ['subjects' => null]]),
        'pivot' => $this->group->staff()->findOrFail($this->teacher->id)->pivot->forceFill(['subjects' => null])->save(),
    };
    expect($this->staff->fresh()->subjects)->toBe(['arabic']);
    expect(SubjectFence::assignedIds($this->group->id, $this->teacher->id))->toBe($ids);
    DB::table('group_staff')->where('id', $this->staff->id)->update(['subjects' => null]);
    expect(SubjectFence::assignedIds($this->group->id, $this->teacher->id))->toBe($ids);
    $this->artisan('class-subjects:audit', ['--masjid' => $this->org->id])->expectsOutputToContain('Assignment #'.$this->staff->id)->assertSuccessful();
})->with(['model', 'sync', 'pivot']);

it('refuses implicit all on every new assignment writer', function (string $writer) {
    ($this->activate)(); $user = User::factory()->create(['type' => 'Teacher', 'phone' => '+15555550101']);
    expect(fn () => match ($writer) {
        'model' => GroupStaff::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'user_id' => $user->id]),
        'attach' => $this->group->staff()->attach($user->id, ['masjid_id' => $this->org->id]),
        'sync' => $this->group->staff()->syncWithoutDetaching([$user->id => ['masjid_id' => $this->org->id]]),
    })->toThrow(\Illuminate\Validation\ValidationException::class);
    expect(GroupStaff::where('user_id', $user->id)->exists())->toBeFalse();
})->with(['model', 'attach', 'sync']);

it('refuses legacy office input on and preserves ids through restoration and rerun', function () {
    ($this->activate)(); $ids = $this->staff->fresh()->class_subject_ids;
    Sanctum::actingAs($this->office);
    $this->putJson("/api/admin/masjids/{$this->org->id}/teachers/{$this->teacher->id}", ['name' => $this->teacher->name, 'class_ids' => [$this->group->id], 'class_subjects' => [$this->group->id => null]])->assertUnprocessable();
    $this->group->delete();
    DB::table('group_staff')->where('id', $this->staff->id)->update(['subjects' => null]);
    $this->group->restore();
    ($this->activate)();
    expect($this->staff->fresh()->class_subject_ids)->toBe($ids);
});

it('does not capture orphan work on rename add or merge but explicitly attaches on add', function () {
    $work = ($this->work)('History'); $plan = ($this->plan)('History');
    ($this->activate)(); $arabic = ClassSubject::where('name', 'Arabic')->firstOrFail();
    $arabic->update(['name' => 'History']);
    expect($work->fresh()->class_subject_id)->toBeNull();
    expect($plan->fresh()->class_subject_id)->toBeNull();
    $arabic->update(['name' => 'Arabic']);
    Sanctum::actingAs($this->office);
    $base = "/api/admin/masjids/{$this->org->id}/groups/{$this->group->id}/subjects";
    $id = $this->postJson($base, ['name' => 'History', 'attach_saved_work' => true])->assertCreated()->json('data.id');
    expect($work->fresh()->class_subject_id)->toBe($id);
    expect($plan->fresh()->class_subject_id)->toBe($id);
});

it('preserves office choices without retranslating after deliberate disable', function () {
    ($this->activate)(); $science = ClassSubject::where('name', 'Science')->firstOrFail();
    $this->staff->fresh()->update(['class_subject_ids' => [$science->id]]);
    \App\Support\ClassSubjectDisabler::run($this->org->fresh(), false, [$this->staff->id]);
    $before = DB::table('class_subjects')->get()->toJson();
    $this->artisan('class-subjects:initialize', ['--masjid' => $this->org->id, '--enable' => true])->assertSuccessful();
    expect($this->org->fresh()->hasCapability('class_subjects'))->toBeTrue();
    expect(DB::table('class_subjects')->get()->toJson())->toBe($before);
    expect($this->staff->fresh()->class_subject_ids)->toBe([$science->id]);
});

it('checks every saved subject writer against chosen ids atomically', function (string $writer) {
    ($this->activate)(); $arabic = ClassSubject::where('name', 'Arabic')->firstOrFail(); $science = ClassSubject::where('name', 'Science')->firstOrFail();
    Sanctum::actingAs($this->teacher, ['staff']);
    $body = ['title' => 'Practice', 'subject' => 'Arabic', 'assigned_on' => '2026-10-08', 'scale' => 'points', 'points_possible' => 10];
    $planBody = ['session_date' => '2026-10-08', 'subject' => 'Arabic', 'body' => 'Practice'];
    if ($writer === 'grade-create' || $writer === 'grade-update') {
        if ($writer === 'grade-create') $id = $this->postJson($this->base.'/assignments', $body)->assertCreated()->json('data.id');
        else $id = ($this->work)('Arabic')->id;
        expect(ClassAssignment::findOrFail($id)->class_subject_id)->toBe($arabic->id);
        $this->putJson($this->base.'/assignments/'.$id, array_replace($body, ['subject' => 'Science']))->assertForbidden();
        $this->putJson($this->base.'/assignments/'.$id, $body + ['class_subject_id' => $science->id])->assertForbidden();
    } elseif ($writer === 'plan-create' || $writer === 'plan-update' || $writer === 'plan-upsert') {
        $id = $writer === 'plan-update' ? ($this->plan)('Arabic')->id : $this->{($writer === 'plan-upsert' ? 'putJson' : 'postJson')}($this->base.'/lesson-plans', $planBody)->assertOk()->json('data.id');
        expect(LessonPlan::findOrFail($id)->class_subject_id)->toBe($arabic->id);
        $this->putJson($this->base.'/lesson-plans/'.$id, array_replace($planBody, ['subject' => 'Science']))->assertForbidden();
        $this->putJson($this->base.'/lesson-plans/'.$id, $planBody + ['class_subject_id' => $science->id])->assertForbidden();
    } else {
        $model = $writer === 'grade-model' ? ($this->work)('Arabic') : ($this->plan)('Arabic');
        expect(fn () => $model->update(['subject' => 'Science']))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        expect($model->fresh()->class_subject_id)->toBe($arabic->id);
        expect(fn () => $model->fresh()->update(['class_subject_id' => $science->id]))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    }
})->with(['grade-create', 'grade-update', 'grade-model', 'plan-create', 'plan-update', 'plan-upsert', 'plan-model']);

it('keeps general lesson plans shared even with no subjects assigned', function () {
    $plan = ($this->plan)(null); ($this->activate)(); $this->staff->fresh()->update(['class_subject_ids' => []]);
    Sanctum::actingAs($this->teacher, ['staff']);
    $this->getJson($this->base.'/lesson-plans?from=2026-10-08&to=2026-10-08')->assertOk()->assertJsonCount(1, 'data.plans');
    $this->putJson($this->base.'/lesson-plans/'.$plan->id, ['session_date' => '2026-10-08', 'body' => 'Shared activities'])->assertOk();
    $this->deleteJson($this->base.'/lesson-plans/'.$plan->id)->assertOk();
});

it('keeps unmatched work unmatched after reactivation and links only fresh off work', function () {
    $old = ($this->work)('History'); ($this->activate)();
    ClassSubject::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'name' => 'History']);
    $disable = \App\Support\ClassSubjectDisabler::run($this->org->fresh(), true);
    $accept = array_column(array_filter($disable['assignments'], fn ($row) => ! $row['expressible']), 'id');
    expect(\App\Support\ClassSubjectDisabler::run($this->org->fresh(), false, $accept)['blocked'])->toBe([]);
    $fresh = ($this->work)('History'); ($this->activate)();
    expect($old->fresh()->class_subject_id)->toBeNull();
    expect($fresh->fresh()->class_subject_id)->toBe(ClassSubject::where('name', 'History')->value('id'));
});

it('writes no feature mapping metadata while off through any normal assignment writer', function (string $writer) {
    $user = User::factory()->create(['type' => 'Teacher', 'phone' => '+15555550103']);
    $fields = ['masjid_id' => $this->org->id, 'subjects' => ['arabic'], 'class_subject_ids' => [], 'class_subjects_mapped_at' => now()];
    match ($writer) {
        'model' => GroupStaff::create($fields + ['group_id' => $this->group->id, 'user_id' => $user->id]),
        'attach' => $this->group->staff()->attach($user->id, $fields),
        'sync' => $this->group->staff()->syncWithoutDetaching([$user->id => $fields]),
    };
    $row = GroupStaff::where('user_id', $user->id)->firstOrFail();
    expect($row->subjects)->toBe(['arabic']);
    expect($row->class_subject_ids)->toBeNull();
    expect($row->class_subjects_mapped_at)->toBeNull();
})->with(['model', 'attach', 'sync']);

it('refuses assigning all through a model but accepts an explicit office all choice', function () {
    ($this->activate)();
    expect(fn () => $this->staff->fresh()->update(['class_subject_ids' => null]))->toThrow(\Illuminate\Validation\ValidationException::class);
    Sanctum::actingAs($this->office);
    $url = "/api/admin/masjids/{$this->org->id}/teachers/{$this->teacher->id}";
    $this->putJson($url, ['name' => $this->teacher->name, 'class_ids' => [$this->group->id], 'class_subject_ids' => [$this->group->id => null]])->assertOk();
    expect($this->staff->fresh()->class_subject_ids)->toBeNull();
    expect($this->staff->fresh()->subjects)->toBe(['arabic']);
});

it('preserves saved snapshots on rename and rejects deletion of a subject holding work', function () {
    $work = ($this->work)('Arabic'); $plan = ($this->plan)('Arabic'); ($this->activate)();
    $arabic = ClassSubject::where('name', 'Arabic')->firstOrFail(); $arabic->update(['name' => 'Reading']);
    expect($work->fresh()->subject)->toBe('Arabic'); expect($plan->fresh()->subject)->toBe('Arabic');
    expect($work->fresh()->class_subject_id)->toBe($arabic->id);
    expect(fn () => $arabic->delete())->toThrow(\Illuminate\Database\QueryException::class);
});

it('does not infer all from a late off staff insert and lets only an explicit office choice repair it', function () {
    ($this->activate)();
    $late = User::factory()->create(['type' => 'Teacher', 'phone' => '+15555550107']);
    MasjidUser::create(['masjid_id' => $this->org->id, 'user_id' => $late->id, 'role' => 'teacher']);
    // Equivalent to an already-dispatched OFF writer finishing after activation.
    DB::table('group_staff')->insert(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'user_id' => $late->id, 'subjects' => '["arabic"]']);
    expect(SubjectFence::assignedIds($this->group->id, $late->id))->toBe([]);
    expect(SubjectFence::mayWeighClass($late, $this->group->id))->toBeFalse();
    Sanctum::actingAs($late, ['staff']);
    $this->getJson($this->base.'/hifz')->assertForbidden();
    $this->getJson($this->base.'/subjects')->assertOk()->assertJsonCount(0, 'data');
    Sanctum::actingAs($this->office);
    $this->getJson("/api/admin/masjids/{$this->org->id}/teachers/{$late->id}")->assertOk()->assertJsonPath('data.class_subject_ids.'.$this->group->id, []);
    $this->putJson("/api/admin/masjids/{$this->org->id}/teachers/{$late->id}", ['name' => $late->name, 'class_ids' => [$this->group->id], 'class_subject_ids' => [$this->group->id => null]])->assertOk();
    expect(SubjectFence::assignedIds($this->group->id, $late->id))->toBeNull();
});

it('upserts the same linked plan after a rename using the chosen id', function () {
    $plan = ($this->plan)('Arabic'); ($this->activate)();
    $arabic = ClassSubject::where('name', 'Arabic')->firstOrFail();
    $arabic->update(['name' => 'Reading']);
    ($this->plan)(null);
    Sanctum::actingAs($this->teacher, ['staff']);
    $this->putJson($this->base.'/lesson-plans', ['session_date' => '2026-10-08', 'subject' => 'Reading', 'class_subject_id' => $arabic->id, 'body' => 'Practice corrected'])
        ->assertOk()->assertJsonPath('data.id', $plan->id);
    expect(LessonPlan::where('class_subject_id', $arabic->id)->count())->toBe(1);
});

it('applies the same id assignment matrix to every held tool route', function (string $assignment, string $tool) {
    ($this->activate)();
    $holder = ClassSubject::where('tool', $tool)->firstOrFail();
    $otherSubject = ClassSubject::where('name', 'Science')->firstOrFail();
    $otherClass = Group::factory()->create(['masjid_id' => $this->org->id, 'kind' => 'class']);
    $foreign = ClassSubject::where('group_id', $otherClass->id)->firstOrFail();
    $ids = match ($assignment) { 'all' => null, 'none' => [], 'one', 'hidden' => [$holder->id], 'two' => [$holder->id, $otherSubject->id], 'foreign' => [$foreign->id] };
    if ($assignment === 'hidden') $holder->update(['hidden_at' => now()]);
    DB::table('group_staff')->where('id', $this->staff->id)->update(['class_subject_ids' => $ids === null ? null : json_encode($ids)]);
    $allowed = $ids === null || in_array($holder->id, $ids, true);
    $contact = \App\Models\Contact::factory()->create(['masjid_id' => $this->org->id, 'email' => null]);
    $member = \App\Models\GroupMembership::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'contact_id' => $contact->id, 'role' => 'member']);
    Sanctum::actingAs($this->teacher, ['staff']);
    $alphabet = $tool === 'english_letters' ? 'english' : 'arabic';
    $routes = $tool === 'hifdh' ? [
        ['getJson', '/hifz', []], ['postJson', '/hifz', []], ['putJson', '/hifz/999999', []],
        ['postJson', '/hifz/999999/correct', []], ['deleteJson', '/hifz/999999', []],
        ['getJson', '/members/'.$member->id.'/hifz', []], ['getJson', '/members/'.$member->id.'/hifz/progress', []],
    ] : [
        ['getJson', '/letters?alphabet='.$alphabet, []], ['getJson', '/members/'.$member->id.'/letters?alphabet='.$alphabet, []],
        ['putJson', '/members/'.$member->id.'/letters', ['alphabet' => $alphabet]],
        ['putJson', '/members/'.$member->id.'/letters/master-all', ['alphabet' => $alphabet]],
        ...($tool === 'arabic_letters' ? [['putJson', '/letters/stage', []], ['getJson', '/members/'.$member->id.'/arabic-notes', []], ['putJson', '/members/'.$member->id.'/arabic-notes', []]] : []),
    ];
    foreach ($routes as [$method, $path, $body]) {
        $response = $this->$method($this->base.$path, $body);
        if ($allowed) expect($response->status(), $path)->not->toBe(403);
        else $response->assertForbidden();
    }
})->with(['all', 'none', 'one', 'two', 'hidden', 'foreign'])->with(['hifdh', 'arabic_letters', 'english_letters']);

it('ignores unknown off id input even if activation commits during that dispatched save', function () {
    ($this->activate)(); $ids = $this->staff->fresh()->class_subject_ids;
    $disable = \App\Support\ClassSubjectDisabler::run($this->org->fresh(), true);
    $accept = array_column(array_filter($disable['assignments'], fn ($row) => ! $row['expressible']), 'id');
    expect(\App\Support\ClassSubjectDisabler::run($this->org->fresh(), false, $accept)['blocked'])->toBe([]);
    $events = GroupStaff::getEventDispatcher();
    GroupStaff::setEventDispatcher(clone $events);
    try {
        GroupStaff::saving(function () { ($this->activate)(); });
        $this->staff->fresh()->forceFill(['class_subject_ids' => null, 'subjects' => null])->save();
    } finally {
        GroupStaff::setEventDispatcher($events);
    }
    expect($this->org->fresh()->hasCapability('class_subjects'))->toBeTrue();
    expect($this->staff->fresh()->class_subject_ids)->toBe($ids);
    expect($this->staff->fresh()->subjects)->toBeNull();
    expect(SubjectFence::assignedIds($this->group->id, $this->teacher->id))->toBe($ids);
});

it('filters every grade arithmetic scale by saved ids rather than snapshots', function (string $scale) {
    $own = ($this->work)('Arabic'); $other = ($this->work)('Science');
    $own->update(['scale' => $scale]); $other->update(['scale' => $scale]);
    ($this->activate)();
    $contact = \App\Models\Contact::factory()->create(['masjid_id' => $this->org->id, 'email' => null]);
    $member = \App\Models\GroupMembership::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'contact_id' => $contact->id, 'role' => 'member']);
    $value = match ($scale) { 'points' => 5, 'levels' => 4, 'simple' => 3 };
    foreach ([$own->id => $value, $other->id => 1] as $id => $mark) \App\Models\AssignmentScore::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'class_assignment_id' => $id, 'group_membership_id' => $member->id, 'status' => 'scored', 'points_earned' => $mark]);
    $summary = \App\Support\GradeRecord::summaryForClassSubjects($member->id, $this->staff->fresh()->class_subject_ids);
    expect($summary['recorded'])->toBe(1);
    if ($scale === 'levels') expect($summary['levels']['counted'])->toBe(1)->and($summary['levels']['mean'])->toBe(4.0);
    elseif ($scale === 'simple') expect($summary['simple']['counted'])->toBe(1);
    else expect($summary['points_earned'])->toBe(5.0);
})->with(['points', 'levels', 'simple']);

it('keeps report card marks class staff wide with the switch on and no subjects assigned', function () {
    ($this->activate)(); $this->staff->fresh()->update(['class_subject_ids' => []]);
    $contact = \App\Models\Contact::factory()->create(['masjid_id' => $this->org->id, 'email' => null]);
    $member = \App\Models\GroupMembership::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'contact_id' => $contact->id, 'role' => 'member', 'grade_label' => 'Grade 1']);
    Sanctum::actingAs($this->teacher, ['staff']);
    $url = $this->base.'/members/'.$member->id.'/report-card?school_year=2026-2027&term=1';
    $card = $this->getJson($url)->assertOk()->json('data');
    $mark = $card['subjects'][0]['criteria'][0]['id'];
    $this->putJson($url, ['marks' => [['id' => $mark, 'level' => 4]]])->assertOk();
    expect(\App\Models\ReportCardMark::findOrFail($mark)->level)->toBe(4);
});

it('produces a complete dry run with blockers counts and no statements that write', function () {
    $this->group->update(['name' => 'Practice Class']);
    ($this->work)('Arabic'); ($this->plan)('Arabic Language'); ($this->work)('History');
    $other = Group::factory()->create(['masjid_id' => $this->org->id, 'kind' => 'class', 'name' => 'Practice Blocked Class']);
    ClassSubject::create(['masjid_id' => $this->org->id, 'group_id' => $other->id, 'name' => 'Science']);
    GroupStaff::create(['masjid_id' => $this->org->id, 'group_id' => $other->id, 'user_id' => $this->teacher->id, 'subjects' => ['quran']]);
    DB::enableQueryLog();
    try {
        $status = \Illuminate\Support\Facades\Artisan::call('class-subjects:initialize', ['--masjid' => $this->org->id, '--dry-run' => true]);
        $output = \Illuminate\Support\Facades\Artisan::output();
        $queries = DB::getQueryLog();
    } finally { DB::disableQueryLog(); }
    expect($status)->toBe(1);
    expect($output)->toContain('LINK Arabic: 2 items', 'history, 1 items', 'BLOCKED', 'School summary:', 'Teacher #'.$this->teacher->id);
    expect($output)->not->toContain($this->teacher->name, $this->teacher->email);
    foreach ($queries as $query) expect($query['query'])->not->toMatch('/\A(?:insert|update|delete|alter|create|drop)\b/i');
    file_put_contents(base_path('artifacts/convergence-dry-run-sample.log'), $output);
});

it('ignores feature ids and writes only legacy snapshots on saved work while off', function (string $kind) {
    $row = $kind === 'grades' ? ($this->work)('Arabic') : ($this->plan)('Arabic');
    $row->forceFill(['subject' => 'History', 'class_subject_id' => 999, 'class_subject_link_checked_at' => now()])->save();
    expect($row->fresh()->subject)->toBe('History');
    expect($row->fresh()->class_subject_id)->toBeNull();
    expect($row->fresh()->class_subject_link_checked_at)->toBeNull();
})->with(['grades', 'plans']);

it('preserves historical translated ids even without an audit snapshot', function () {
    $this->staff->update(['subjects' => null]);
    $subject = ClassSubject::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'name' => 'Science']);
    // An older version could have edited these IDs without recording this version's edit fact.
    DB::table('group_staff')->where('id', $this->staff->id)->update(['class_subject_ids' => json_encode([$subject->id]), 'class_subjects_mapped_at' => now()]);
    $this->artisan('class-subjects:initialize', ['--masjid' => $this->org->id, '--enable' => true])->assertSuccessful();
    expect($this->org->fresh()->hasCapability('class_subjects'))->toBeTrue();
    expect($this->staff->fresh()->class_subject_ids)->toBe([$subject->id]);
});
