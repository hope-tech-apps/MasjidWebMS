<?php

use App\Models\ClassSubject;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupStaff;
use App\Models\LessonPlan;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\SchoolSubject;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/*
 * Saving an older UNLINKED plan by id while class subjects are on.
 *
 * An unlinked plan was written before class subjects: its subject is words
 * ("Science") that match none of the class's subjects, so it has no
 * class_subject_id. The day view saves it by id and sends neither `subject` nor
 * `class_subject_id`, so it keeps both. The clash check took "no subject id"
 * for "the general plan" and refused the save whenever the day also held a
 * plan with no subject, naming a clash the screen never showed.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

    $this->school = Masjid::create([
        'name' => 'Unlinked Plan School '.uniqid(), 'email' => uniqid().'@example.invalid',
        'phone' => '+1'.random_int(1000000000, 9999999999), 'country_id' => '1', 'city_id' => '1',
        'address' => '1 Practice St', 'latitude' => 0, 'longitude' => 0, 'crm_enabled' => true, 'org_type' => 'school',
    ]);
    $this->room = Group::factory()->create(['masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS, 'name' => 'Practice Class']);
    $this->teacher = User::factory()->create(['type' => 'Teacher', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
    MasjidUser::create(['masjid_id' => $this->school->id, 'user_id' => $this->teacher->id, 'role' => 'teacher', 'is_default' => true]);
    GroupStaff::create(['masjid_id' => $this->school->id, 'group_id' => $this->room->id, 'user_id' => $this->teacher->id, 'role' => 'teacher']);

    foreach (['Mathematics', 'Arabic'] as $position => $name) {
        SchoolSubject::create(['masjid_id' => $this->school->id, 'name' => $name, 'grade_labels' => null, 'position' => $position]);
    }

    $this->base = "/api/teacher/masjids/{$this->school->id}/groups/{$this->room->id}";
    $this->day = Carbon::now()->next(Carbon::MONDAY)->toDateString();

    $this->roster = function (array $grades): void {
        foreach ($grades as $grade) {
            $contact = Contact::factory()->create(['masjid_id' => $this->school->id]);
            GroupMembership::create(['masjid_id' => $this->school->id, 'group_id' => $this->room->id, 'contact_id' => $contact->id, 'role' => 'member', 'grade_label' => $grade]);
        }
    };

    $this->turnOn = function (): void {
        $this->artisan('class-subjects:initialize', ['--masjid' => $this->school->id, '--enable' => true])->assertSuccessful();
        expect(\App\Support\ClassSubjectMode::forGroup($this->room->id))->toBeTrue();
    };

    // Written straight to the table, as plans saved before class subjects were.
    $this->plan = fn (?string $subject, ?string $grade, string $body) => LessonPlan::create([
        'masjid_id' => $this->school->id, 'group_id' => $this->room->id, 'session_date' => $this->day,
        'subject' => $subject, 'grade_label' => $grade, 'body' => $body,
    ]);

    // What TeacherClass.vue's savePlan sends for an unlinked plan opened by id:
    // the whole form, with `subject` and `class_subject_id` both left out.
    $this->screenSave = fn (?string $grade, string $body) => [
        'session_date' => $this->day, 'grade_label' => $grade, 'title' => null, 'body' => $body,
        'curriculum_week_no' => null, 'learning_outcomes' => [], 'resource_ids' => [],
    ];

    Sanctum::actingAs($this->teacher, ['staff']);
});

afterEach(function () { app(TenantContext::class)->forgetTenant(); });

dataset('days holding a general plan', [
    'one-grade class, both plans graded' => [['Kindergarten'], 'Kindergarten', 'Kindergarten'],
    'one-grade class, general plan with no grade' => [['Kindergarten'], 'Kindergarten', null],
    'one-grade class, neither plan graded' => [['Kindergarten'], null, null],
    'combined-grade class, both plans for one grade' => [['Pre-Kindergarten', 'Kindergarten'], 'Kindergarten', 'Kindergarten'],
]);

it('saves an older unlinked plan by id on a day that also has a general plan', function (array $roster, ?string $scienceGrade, ?string $generalGrade) {
    ($this->roster)($roster);
    $science = ($this->plan)('Science', $scienceGrade, 'Seeds need water.');
    $general = ($this->plan)(null, $generalGrade, 'Morning circle.');
    ($this->turnOn)();

    expect(ClassSubject::where('group_id', $this->room->id)->where('name', 'Science')->exists())->toBeFalse()
        ->and($science->fresh()->class_subject_id)->toBeNull();
    $generalBefore = DB::table('lesson_plans')->where('id', $general->id)->first();

    $saved = $this->putJson($this->base.'/lesson-plans/'.$science->id, ($this->screenSave)($scienceGrade, 'Seeds need water and light.'));

    expect($saved->status())->toBe(200, (string) $saved->json('message'));
    $saved->assertJsonPath('data.id', $science->id);
    expect($science->fresh()->only(['subject', 'class_subject_id', 'grade_label', 'body']))
        ->toBe(['subject' => 'Science', 'class_subject_id' => null, 'grade_label' => $scienceGrade, 'body' => 'Seeds need water and light.'])
        ->and(DB::table('lesson_plans')->where('id', $general->id)->first())->toEqual($generalBefore)
        ->and(LessonPlan::count())->toBe(2);
})->with('days holding a general plan');

it('still refuses turning a subject plan into a second general plan for the day', function () {
    ($this->roster)(['Kindergarten']);
    ($this->plan)(null, 'Kindergarten', 'Morning circle.');
    ($this->turnOn)();
    $math = ClassSubject::where('group_id', $this->room->id)->where('name', 'Mathematics')->firstOrFail();
    $plan = LessonPlan::create(['masjid_id' => $this->school->id, 'group_id' => $this->room->id, 'session_date' => $this->day,
        'subject' => 'Mathematics', 'class_subject_id' => $math->id, 'grade_label' => 'Kindergarten', 'body' => 'Count to ten.']);
    $before = DB::table('lesson_plans')->orderBy('id')->get();

    // "General" chosen on the screen: both fields sent as null.
    $this->putJson($this->base.'/lesson-plans/'.$plan->id, ($this->screenSave)('Kindergarten', 'Count to ten.') + ['subject' => null, 'class_subject_id' => null])
        ->assertUnprocessable()
        ->assertJsonPath('message', fn (string $m) => str_contains($m, 'plan with no subject for Kindergarten'));

    expect(DB::table('lesson_plans')->orderBy('id')->get())->toEqual($before);
});

it('still refuses moving an unlinked plan onto a grade whose plan has the same subject words', function () {
    ($this->roster)(['Pre-Kindergarten', 'Kindergarten']);
    $science = ($this->plan)('Science', 'Pre-Kindergarten', 'Seeds need water.');
    ($this->plan)('Science', 'Kindergarten', 'Leaves turn to the sun.');
    ($this->plan)(null, 'Kindergarten', 'Morning circle.');
    ($this->turnOn)();
    $before = DB::table('lesson_plans')->orderBy('id')->get();

    $this->putJson($this->base.'/lesson-plans/'.$science->id, ($this->screenSave)('Kindergarten', 'Seeds need water.'))
        ->assertUnprocessable()
        ->assertJsonPath('message', fn (string $m) => str_contains($m, 'Science plan for Kindergarten'));

    expect(DB::table('lesson_plans')->orderBy('id')->get())->toEqual($before);
});
