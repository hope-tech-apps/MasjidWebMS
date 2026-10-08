<?php

use App\Models\{Group, GroupStaff, Masjid, MasjidUser, SchoolSubject, SchoolYear, User};
use App\Support\{CapabilityWriter, ClassSubjectInitializer, TenantContext};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// Dated terms and class subjects are separate switches. These pin a school
// that has one, the other, or both.

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-08 12:00:00 UTC'));
    $this->org = Masjid::create(['name' => 'Practice School', 'email' => uniqid().'@example.invalid', 'phone' => '+15555550100', 'country_id' => '1', 'city_id' => '1', 'address' => 'Practice', 'latitude' => 0, 'longitude' => 0, 'org_type' => 'school', 'crm_enabled' => true]);
    $this->group = Group::factory()->create(['masjid_id' => $this->org->id, 'kind' => 'class']);
    $this->teacher = User::factory()->create(['type' => 'Teacher', 'phone' => '+15555550101']);
    MasjidUser::create(['masjid_id' => $this->org->id, 'user_id' => $this->teacher->id, 'role' => 'teacher', 'is_default' => true]);
    GroupStaff::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'user_id' => $this->teacher->id, 'subjects' => null]);
    SchoolSubject::create(['masjid_id' => $this->org->id, 'name' => 'Arabic']);
    // Sundays and Wednesdays: the older calendar can only ever answer one weekday.
    SchoolYear::create(['masjid_id' => $this->org->id, 'label' => '2026-2027', 'first_day' => '2026-10-04', 'last_day' => '2026-12-20', 'meeting_weekdays' => [0, 3]]);
    $this->switchOn = function (array $keys): void {
        $org = $this->org->fresh();
        $org->forceFill(['capability_overrides' => array_replace((array) $org->capability_overrides, ['school_calendar' => true], array_fill_keys($keys, true))])->save();
    };
    $this->lessons = function (): array {
        app(TenantContext::class)->forgetTenant();
        app('auth')->forgetGuards();
        Sanctum::actingAs($this->teacher, ['staff']);

        return $this->getJson("/api/teacher/masjids/{$this->org->id}/groups/{$this->group->id}/lesson-plans?from=2026-10-05&to=2026-10-11")->assertOk()->json('data');
    };
});

afterEach(fn () => app(TenantContext::class)->forgetTenant());

it('gives a school with both switches the calendar week on the class-subject lesson list', function () {
    ($this->switchOn)(['school_calendar_terms']);
    $calendarOnly = ($this->lessons)()['meeting_weekdays'];
    expect($calendarOnly)->toBe([0, 3]);

    ClassSubjectInitializer::run($this->org->fresh(), false, true);
    expect($this->org->fresh()->hasCapability('class_subjects'))->toBeTrue();
    expect($this->org->fresh()->hasCapability('school_calendar_terms'))->toBeTrue();

    expect(($this->lessons)()['meeting_weekdays'])->toBe($calendarOnly);
});

it('leaves a class-subject school without dated terms on the older week', function () {
    ($this->switchOn)([]);
    $legacy = ($this->lessons)()['meeting_weekdays'];

    ClassSubjectInitializer::run($this->org->fresh(), false, true);

    expect(($this->lessons)()['meeting_weekdays'])->toBe($legacy)->not->toBe([0, 3]);
});

it('lists each switch in the capability map only where it is on', function (bool $subjects, bool $terms) {
    if ($terms) ($this->switchOn)(['school_calendar_terms']);
    if ($subjects) ClassSubjectInitializer::run($this->org->fresh(), false, true);

    $map = $this->org->fresh()->capabilities;

    expect(array_key_exists('class_subjects', $map))->toBe($subjects);
    expect(array_key_exists('school_calendar_terms', $map))->toBe($terms);
    if ($subjects) expect($map['class_subjects'])->toBeTrue();
    if ($terms) expect($map['school_calendar_terms'])->toBeTrue();
})->with([[false, false], [true, false], [false, true], [true, true]]);

it('reads the switches once when both are off', function () {
    DB::flushQueryLog(); DB::enableQueryLog();
    ($this->lessons)();
    $sql = array_column(DB::getQueryLog(), 'query'); DB::disableQueryLog();

    $switchReads = array_filter($sql, fn (string $statement): bool => str_contains($statement, '"capability_overrides"'));
    expect($switchReads)->toHaveCount(1);
});

it('still holds the class-subject guard when one request carries both switches', function () {
    $before = $this->org->fresh()->capability_overrides;

    expect(fn () => CapabilityWriter::apply($this->org->fresh(), ['school_calendar_terms' => true, 'class_subjects' => true], null))
        ->toThrow(ValidationException::class);

    expect($this->org->fresh()->capability_overrides)->toBe($before);
    expect($this->org->fresh()->hasCapability('class_subjects'))->toBeFalse();
    expect($this->org->fresh()->hasCapability('school_calendar_terms'))->toBeFalse();
});
