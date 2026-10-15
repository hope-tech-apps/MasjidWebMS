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
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

/*
 * One lesson plan per class, per day, per subject AND PER GRADE.
 *
 * A combined-grade class (Pre-K with Kindergarten, say) teaches the same
 * subject to each of its grades on the same day, from that grade's own page of
 * the pacing guide. Keyed by subject alone, the second grade's Mathematics plan
 * was refused when created, and the by-day address wrote it over the first
 * grade's plan with a 200. Both class-subject modes are run: the screen sends
 * the subject's id when they are on and its name when they are off, and the
 * server finds "the same plan" a different way in each.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

    $this->school = Masjid::create([
        'name' => 'Combined Grade School '.uniqid(), 'email' => uniqid().'@example.invalid',
        'phone' => '+1'.random_int(1000000000, 9999999999), 'country_id' => '1', 'city_id' => '1',
        'address' => '1 Practice St', 'latitude' => 0, 'longitude' => 0, 'crm_enabled' => true, 'org_type' => 'school',
    ]);
    $this->room = Group::factory()->create(['masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS, 'name' => 'Combined Grades']);
    $this->teacher = User::factory()->create(['type' => 'Teacher', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
    MasjidUser::create(['masjid_id' => $this->school->id, 'user_id' => $this->teacher->id, 'role' => 'teacher', 'is_default' => true]);
    $this->staff = GroupStaff::create(['masjid_id' => $this->school->id, 'group_id' => $this->room->id, 'user_id' => $this->teacher->id, 'role' => 'teacher']);

    foreach (['Pre-Kindergarten', 'Kindergarten'] as $grade) {
        $contact = Contact::factory()->create(['masjid_id' => $this->school->id]);
        GroupMembership::create(['masjid_id' => $this->school->id, 'group_id' => $this->room->id, 'contact_id' => $contact->id, 'role' => 'member', 'grade_label' => $grade]);
    }

    foreach (['Mathematics', 'Arabic'] as $position => $name) {
        SchoolSubject::create(['masjid_id' => $this->school->id, 'name' => $name, 'grade_labels' => null, 'position' => $position]);
    }

    $this->base = "/api/teacher/masjids/{$this->school->id}/groups/{$this->room->id}";
    $this->day = Carbon::now()->next(Carbon::MONDAY)->toDateString();
    $this->classSubjects = false;

    $this->turnOnClassSubjects = function (): void {
        $this->artisan('class-subjects:initialize', ['--masjid' => $this->school->id, '--enable' => true])->assertSuccessful();
        $this->school->refresh();
        $this->room->refresh();
        $this->staff->refresh();
        $this->classSubjects = true;

        expect(\App\Support\ClassSubjectMode::forGroup($this->room->id))->toBeTrue()
            ->and(ClassSubject::where('group_id', $this->room->id)->pluck('name')->sort()->values()->all())->toBe(['Arabic', 'Mathematics']);
    };

    // The subject as the day view sends it: the class subject's id when class
    // subjects are on (the screen drops the name), the name when they are off.
    $this->payload = function (string $subject, ?string $grade, string $body): array {
        $named = $this->classSubjects
            ? ['class_subject_id' => ClassSubject::where('group_id', $this->room->id)->where('name', $subject)->value('id')]
            : ['subject' => $subject];

        return ['session_date' => $this->day, 'grade_label' => $grade, 'body' => $body] + $named;
    };

    // A refusal fails with the server's own sentence, so the failure says why.
    $this->create = function (string $subject, ?string $grade, string $body): int {
        $saved = $this->postJson($this->base.'/lesson-plans', ($this->payload)($subject, $grade, $body));
        expect($saved->status())->toBe(200, "{$subject} for ".($grade ?? 'no grade').': '.$saved->json('message'));

        return (int) $saved->json('data.id');
    };

    $this->inMode = fn (bool $on) => $on ? ($this->turnOnClassSubjects)() : null;

    Sanctum::actingAs($this->teacher, ['staff']);
});

afterEach(function () { app(TenantContext::class)->forgetTenant(); });

dataset('class subject modes', ['class subjects off' => [false], 'class subjects on' => [true]]);

it('plans the same subject for each grade of a combined-grade class on one day', function (bool $on) {
    ($this->inMode)($on);

    $preK = ($this->create)('Mathematics', 'Pre-Kindergarten', 'Count to five.');
    $kg = ($this->create)('Mathematics', 'Kindergarten', 'Count to twenty.');

    expect($kg)->not->toBe($preK);

    $plans = collect($this->getJson($this->base."/lesson-plans?from={$this->day}&to={$this->day}")
        ->assertOk()->json('data.plans'))->keyBy('grade_label');

    expect($plans->keys()->sort()->values()->all())->toBe(['Kindergarten', 'Pre-Kindergarten'])
        ->and($plans->pluck('subject')->unique()->values()->all())->toBe(['Mathematics'])
        ->and($plans['Pre-Kindergarten']['id'])->toBe($preK)
        ->and($plans['Pre-Kindergarten']['body'])->toBe('Count to five.')
        ->and($plans['Kindergarten']['id'])->toBe($kg)
        ->and($plans['Kindergarten']['body'])->toBe('Count to twenty.');
})->with('class subject modes');

it('saves one grade\'s plan again, by id or by day, without touching the other grade\'s', function (bool $on) {
    ($this->inMode)($on);

    $preK = ($this->create)('Mathematics', 'Pre-Kindergarten', 'Count to five.');
    $kg = ($this->create)('Mathematics', 'Kindergarten', 'Count to twenty.');

    $this->putJson($this->base."/lesson-plans/{$kg}", ($this->payload)('Mathematics', 'Kindergarten', 'Count to thirty.'))
        ->assertOk()->assertJsonPath('data.id', $kg);
    $this->putJson($this->base.'/lesson-plans', ($this->payload)('Mathematics', 'Kindergarten', 'Count to forty.'))
        ->assertOk()->assertJsonPath('data.id', $kg);

    expect(LessonPlan::count())->toBe(2)
        ->and(LessonPlan::find($kg)->only(['grade_label', 'body']))->toBe(['grade_label' => 'Kindergarten', 'body' => 'Count to forty.'])
        ->and(LessonPlan::find($preK)->only(['grade_label', 'body']))->toBe(['grade_label' => 'Pre-Kindergarten', 'body' => 'Count to five.']);
})->with('class subject modes');

/**
 * The data loss behind the report. The by-day address looked for "the day's
 * Mathematics plan" without its grade, found the Pre-K one, and wrote the
 * Kindergarten plan over it with a 200: nothing told the teacher.
 */
it('never writes one grade\'s plan over another\'s through the by-day address', function (bool $on) {
    ($this->inMode)($on);

    $preK = ($this->create)('Mathematics', 'Pre-Kindergarten', 'Count to five.');

    $kg = $this->putJson($this->base.'/lesson-plans', ($this->payload)('Mathematics', 'Kindergarten', 'Count to twenty.'))
        ->assertOk()->json('data.id');

    expect($kg)->not->toBe($preK, 'the Kindergarten save landed on the Pre-K plan')
        ->and(LessonPlan::find($preK)->only(['grade_label', 'body']))->toBe(['grade_label' => 'Pre-Kindergarten', 'body' => 'Count to five.'])
        ->and(LessonPlan::count())->toBe(2);
})->with('class subject modes');

/**
 * Plans saved before the grade mattered (and a class with no pacing guide)
 * carry no grade. Such a plan is its own slot: it still saves and corrects, a
 * second ungraded plan of its subject is still refused, and a graded plan of
 * that subject is neither blocked by it nor written over it.
 */
it('keeps a plan with no grade working and apart from the graded plans', function (bool $on) {
    ($this->inMode)($on);

    $none = ($this->create)('Arabic', null, 'Letter ba.');
    $preK = ($this->create)('Arabic', 'Pre-Kindergarten', 'Letter ta.');
    $kg = ($this->create)('Arabic', 'Kindergarten', 'Letter tha.');

    $this->putJson($this->base.'/lesson-plans', ($this->payload)('Arabic', null, 'Letter ba, again.'))
        ->assertOk()->assertJsonPath('data.id', $none);

    $this->postJson($this->base.'/lesson-plans', ($this->payload)('Arabic', null, 'A second ungraded plan.'))
        ->assertUnprocessable();

    expect(LessonPlan::count())->toBe(3)
        ->and(LessonPlan::find($none)->only(['grade_label', 'body']))->toBe(['grade_label' => null, 'body' => 'Letter ba, again.'])
        ->and(LessonPlan::find($preK)->body)->toBe('Letter ta.')
        ->and(LessonPlan::find($kg)->body)->toBe('Letter tha.');
})->with('class subject modes');

/**
 * Editing a plan by id may change its grade, as it may change its subject. Onto
 * a grade that already has that subject's plan that day it is refused with a
 * sentence naming both, never a 500 from the index and never an overwrite.
 */
it('refuses moving a plan onto a grade that already has that subject that day', function (bool $on) {
    ($this->inMode)($on);

    $preK = ($this->create)('Mathematics', 'Pre-Kindergarten', 'Count to five.');
    $kg = ($this->create)('Mathematics', 'Kindergarten', 'Count to twenty.');

    $refused = $this->putJson($this->base."/lesson-plans/{$preK}", ($this->payload)('Mathematics', 'Kindergarten', 'Would replace it.'))
        ->assertUnprocessable();

    expect($refused->json('message'))->toContain('Mathematics')->toContain('Kindergarten')
        ->and(LessonPlan::find($preK)->only(['grade_label', 'body']))->toBe(['grade_label' => 'Pre-Kindergarten', 'body' => 'Count to five.'])
        ->and(LessonPlan::find($kg)->only(['grade_label', 'body']))->toBe(['grade_label' => 'Kindergarten', 'body' => 'Count to twenty.']);
})->with('class subject modes');

/**
 * The backstop under the controller's checks: what the unique index admits and
 * refuses when two saves race past them.
 */
it('lets the unique index hold one plan per subject per grade, not one per subject', function () {
    $plan = fn (?string $grade) => LessonPlan::create([
        'masjid_id' => $this->school->id, 'group_id' => $this->room->id, 'session_date' => $this->day,
        'subject' => 'Mathematics', 'grade_label' => $grade, 'body' => 'Practice plan',
    ]);

    // Each grade's plan, and the ungraded one, is its own row; only a second
    // plan for a grade that already has this subject is refused.
    $plan('Pre-Kindergarten');
    $plan('Kindergarten');
    $plan(null);

    expect(LessonPlan::count())->toBe(3)
        ->and(fn () => $plan('Kindergarten'))->toThrow(UniqueConstraintViolationException::class);
});

/**
 * The sentence a refusal gives names the subject, the grade and the day: in a
 * combined-grade class "this day already has a Mathematics plan" is true of the
 * other grade's plan too, and reads as a refusal to plan the subject at all.
 */
it('says which subject, grade and day already has its plan', function (bool $on) {
    ($this->inMode)($on);

    ($this->create)('Mathematics', 'Kindergarten', 'Count to twenty.');
    ($this->create)('Arabic', 'Kindergarten', 'Letter ba.');
    $day = \App\Support\SchoolCalendar::label($this->day);

    $this->postJson($this->base.'/lesson-plans', ($this->payload)('Mathematics', 'Kindergarten', 'Again.'))
        ->assertUnprocessable()
        ->assertJsonPath('message', "There is already a Mathematics plan for Kindergarten on {$day}. Open it to change it.")
        ->assertJsonPath('data.subject.0', "There is already a Mathematics plan for Kindergarten on {$day}. Open it to change it.");
    $this->postJson($this->base.'/lesson-plans', ($this->payload)('Arabic', 'Kindergarten', 'Again.'))
        ->assertUnprocessable()
        ->assertJsonPath('message', "There is already an Arabic plan for Kindergarten on {$day}. Open it to change it.");

    expect(LessonPlan::count())->toBe(2);
})->with('class subject modes');

/**
 * Two saves of the same grade's plan that both pass the controller's check:
 * the second meets the unique index, and is answered with the same sentence as
 * a 422, never a 500 and never a second row.
 */
it('answers a race to the same grade\'s plan with the refusal sentence, not a 500', function (bool $on) {
    ($this->inMode)($on);

    ($this->create)('Mathematics', 'Pre-Kindergarten', 'Count to five.');

    // The other save lands between this one's check and its INSERT.
    $raced = false;
    LessonPlan::creating(function (LessonPlan $plan) use (&$raced): void {
        if ($raced) return;
        $raced = true;
        \Illuminate\Support\Facades\DB::table('lesson_plans')->insert([
            'masjid_id' => $plan->masjid_id ?? $this->school->id, 'group_id' => $this->room->id,
            'session_date' => $plan->session_date, 'subject' => 'Mathematics', 'subject_key' => 'mathematics',
            'grade_label' => 'Kindergarten', 'grade_key' => 'kg', 'body' => 'The other save.',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    });

    $day = \App\Support\SchoolCalendar::label($this->day);
    $this->postJson($this->base.'/lesson-plans', ($this->payload)('Mathematics', 'Kindergarten', 'This save.'))
        ->assertUnprocessable()
        ->assertJsonPath('message', "There is already a Mathematics plan for Kindergarten on {$day}. Open it to change it.");

    // The simulated save shares this request's connection, so with class
    // subjects on it is rolled back with the refused save; what matters is that
    // the refused save wrote nothing and touched nothing.
    expect($raced)->toBeTrue()
        ->and(LessonPlan::where('body', 'This save.')->exists())->toBeFalse()
        ->and(LessonPlan::where('grade_key', 'prek')->pluck('body')->all())->toBe(['Count to five.']);
})->with('class subject modes');

/**
 * The by-day address an old screen uses renamed "the day's only plan" when the
 * subject sent differed. That still holds within a grade (class subjects off;
 * on, it has always asked for the plan by id), and never reaches across: a lone
 * plan for another grade is that grade's lesson, and a new plan goes beside it.
 */
it('renames the day\'s lone plan by day only within its own grade', function (bool $on) {
    ($this->inMode)($on);

    $preK = ($this->create)('Mathematics', 'Pre-Kindergarten', 'Count to five.');

    $kg = $this->putJson($this->base.'/lesson-plans', ($this->payload)('Arabic', 'Kindergarten', 'Letter ba.'))
        ->assertOk()->json('data.id');
    expect($kg)->not->toBe($preK)
        ->and(LessonPlan::find($preK)->only(['subject', 'grade_label', 'body']))
        ->toBe(['subject' => 'Mathematics', 'grade_label' => 'Pre-Kindergarten', 'body' => 'Count to five.']);

    LessonPlan::whereKey($kg)->delete();
    $same = $this->putJson($this->base.'/lesson-plans', ($this->payload)('Arabic', 'Pre-Kindergarten', 'Letter ta.'));

    if ($on) {
        $same->assertStatus(409);
        expect(LessonPlan::find($preK)->subject)->toBe('Mathematics');
    } else {
        $same->assertOk()->assertJsonPath('data.id', $preK);
        expect(LessonPlan::find($preK)->only(['subject', 'body']))->toBe(['subject' => 'Arabic', 'body' => 'Letter ta.']);
    }
})->with('class subject modes');

/**
 * With class subjects on, an old by-day save that names no subject edits the
 * day's lone plan. Only this grade's: another grade's lone plan is neither
 * moved to this grade nor republished as general.
 */
it('never hands an old by-day save with no subject another grade\'s plan', function () {
    ($this->inMode)(true);

    $preK = ($this->create)('Mathematics', 'Pre-Kindergarten', 'Count to five.');

    $this->putJson($this->base.'/lesson-plans', ['session_date' => $this->day, 'grade_label' => 'Kindergarten', 'body' => 'No subject named.'])
        ->assertStatus(409);
    $this->putJson($this->base.'/lesson-plans', ['session_date' => $this->day, 'grade_label' => 'Pre-Kindergarten', 'body' => 'Count to six.'])
        ->assertOk()->assertJsonPath('data.id', $preK);

    expect(LessonPlan::count())->toBe(1)
        ->and(LessonPlan::find($preK)->only(['grade_label', 'body']))->toBe(['grade_label' => 'Pre-Kindergarten', 'body' => 'Count to six.']);
});

/** One subject for two grades is two plans: removing "the day's plan" by date names neither. */
it('refuses removing a day by date while it holds one subject for two grades', function (bool $on) {
    ($this->inMode)($on);

    ($this->create)('Mathematics', 'Pre-Kindergarten', 'Count to five.');
    ($this->create)('Mathematics', 'Kindergarten', 'Count to twenty.');

    $this->deleteJson($this->base."/lesson-plans?date={$this->day}")
        ->assertStatus(409)
        ->assertJsonPath('data.date.0', 'This day has more than one plan. Remove them one at a time.');

    expect(LessonPlan::count())->toBe(2);
})->with('class subject modes');

/** The office reads the class's plans through the same list, each with its grade. */
it('lists both grades\' plans of a subject to the office', function () {
    ($this->create)('Mathematics', 'Pre-Kindergarten', 'Count to five.');
    ($this->create)('Mathematics', 'Kindergarten', 'Count to twenty.');

    $admin = User::factory()->create(['type' => 'MasjidAdmin', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
    $this->school->forceFill(['user_id' => $admin->id])->save();
    MasjidUser::create(['masjid_id' => $this->school->id, 'user_id' => $admin->id, 'role' => 'masjid-admin', 'is_default' => true]);
    \Illuminate\Support\Facades\Auth::forgetGuards();
    app(TenantContext::class)->forgetTenant();
    Sanctum::actingAs($admin, ['*']);

    $plans = collect($this->getJson("/api/admin/masjids/{$this->school->id}/groups/{$this->room->id}/lesson-plans?from={$this->day}&to={$this->day}")
        ->assertOk()->json('data.plans'));

    expect($plans->pluck('body', 'grade_label')->sortKeys()->all())
        ->toBe(['Kindergarten' => 'Count to twenty.', 'Pre-Kindergarten' => 'Count to five.'])
        ->and($plans->pluck('subject')->unique()->values()->all())->toBe(['Mathematics'])
        ->and($plans->first())->not->toHaveKey('grade_key');
});
