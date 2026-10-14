<?php

namespace Tests\Feature;

use App\Models\{Contact, GroupMembership, Masjid, SchoolTerm, User};
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ParentAttendanceFixtures;
use Tests\TestCase;

class FamilyAttendanceTest extends TestCase
{
    use RefreshDatabase, ParentAttendanceFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Carbon\Carbon::parse('2026-10-19 14:00:00', 'UTC'));
    }
    protected function tearDown(): void { app(TenantContext::class)->forgetTenant(); parent::tearDown(); }

    #[Test]
    public function marks_access_guard_hides_another_familys_child_and_another_tenant(): void
    {
        $a = $this->makeMasjid(); $b = $this->makeMasjid('b');
        $class = $this->classroom($a); $own = $this->enrol($class); $other = $this->enrol($class);
        $parent = $this->guardian($own); $this->guardian($other, Contact::factory()->create(['masjid_id' => $a->id]));
        $foreign = $this->enrol($this->classroom($b)); $this->mark($other); $this->mark($foreign); $this->family($parent);
        $this->getJson($this->familyUrl($own))->assertOk()->assertJsonCount(0, 'data.days');
        $this->getJson($this->familyUrl($other))->assertForbidden();
        $this->getJson(str_replace('/members/'.$own->id.'/', '/members/'.$foreign->id.'/', $this->familyUrl($own)))->assertNotFound();
        $this->getJson($this->familyUrl($foreign))->assertForbidden();
    }

    #[Test]
    public function a_childs_two_authorized_classes_are_newest_first_with_only_public_keys_and_no_missing_days(): void
    {
        $school = $this->makeMasjid(); $this->year($school);
        $first = $this->enrol($this->classroom($school, 'First Class')); $parent = $this->guardian($first);
        $second = $this->enrol($this->classroom($school, 'Second Class'), $first->contact); $this->guardian($second, $parent);
        // Same child in a third class without this guardian's standing must remain hidden.
        $hidden = $this->enrol($this->classroom($school, 'Hidden Class'), $first->contact); $this->mark($hidden);
        $old = $this->mark($first, '2026-10-16', 'late');
        $marker = User::factory()->create(['phone' => '+15555550103', 'name' => 'Practice Marker', 'email' => 'marker@example.invalid']); $old->forceFill(['marked_by_user_id' => $marker->id])->save();
        $this->mark($second, '2026-10-19', 'present'); $this->family($parent);
        $data = $this->getJson($this->familyUrl($first))->assertOk()->assertJsonCount(2, 'data.days')->json('data');
        $this->assertSame(['2026-10-19', '2026-10-16'], array_column($data['days'], 'date'));
        foreach ($data['days'] as $day) {
            $this->assertSame(['date', 'class', 'status'], array_keys($day));
            $this->assertSame(['id', 'name'], array_keys($day['class']));
        }
        $this->assertSame(['present' => 1, 'late' => 1, 'absent' => 0, 'excused' => 0], $data['totals']);
        $this->assertStringNotContainsString('Staff-only', json_encode($data));
        $this->assertStringNotContainsString('marked_', json_encode($data));
        $this->assertStringNotContainsString('Hidden Class', json_encode($data));
    }

    #[Test]
    public function current_dated_term_totals_and_year_fallback_use_inclusive_boundaries(): void
    {
        $school = $this->makeMasjid(); $year = $this->year($school);
        SchoolTerm::create(['masjid_id' => $school->id, 'school_year_id' => $year->id, 'position' => 1, 'name' => 'Quarter 1', 'starts_on' => '2026-08-24', 'ends_on' => '2026-10-23']);
        SchoolTerm::create(['masjid_id' => $school->id, 'school_year_id' => $year->id, 'position' => 2, 'name' => 'Quarter 2', 'starts_on' => '2026-10-24', 'ends_on' => '2027-01-15']);
        $child = $this->enrol($this->classroom($school)); $parent = $this->guardian($child);
        foreach (['2026-08-23' => 'absent', '2026-08-24' => 'present', '2026-10-19' => 'late'] as $date => $status) $this->mark($child, $date, $status);
        $this->family($parent);
        $this->getJson($this->familyUrl($child))->assertOk()->assertJsonPath('data.period.kind', 'term')->assertJsonPath('data.period.name', 'Quarter 1')->assertJsonPath('data.totals.absent', 0)->assertJsonPath('data.totals.present', 1)->assertJsonPath('data.totals.late', 1);
        app(TenantContext::class)->forgetTenant();
        $school->forceFill(['capability_overrides' => ['school_calendar' => true]])->save();
        $this->getJson($this->familyUrl($child))->assertOk()->assertJsonPath('data.period.kind', 'year')->assertJsonPath('data.period.from', '2026-08-24')->assertJsonPath('data.totals.absent', 0);
    }

    #[Test]
    public function dated_term_and_year_totals_include_all_four_marks_but_not_other_terms_or_years(): void
    {
        $school = $this->makeMasjid(); $year = $this->year($school);
        SchoolTerm::create(['masjid_id' => $school->id, 'school_year_id' => $year->id, 'position' => 1, 'name' => 'Quarter 1', 'starts_on' => '2026-08-24', 'ends_on' => '2026-10-23']);
        SchoolTerm::create(['masjid_id' => $school->id, 'school_year_id' => $year->id, 'position' => 2, 'name' => 'Quarter 2', 'starts_on' => '2026-10-24', 'ends_on' => '2027-01-15']);
        $child = $this->enrol($this->classroom($school)); $parent = $this->guardian($child);
        foreach (['2026-08-23' => 'absent', '2026-10-23' => 'present', '2026-10-24' => 'late', '2026-10-26' => 'absent', '2026-10-27' => 'excused'] as $date => $status) $this->mark($child, $date, $status);
        $this->travelTo(\Carbon\Carbon::parse('2026-10-27 14:00:00', 'UTC')); $this->family($parent);
        $this->getJson($this->familyUrl($child))->assertOk()->assertJsonPath('data.period.name', 'Quarter 2')->assertJsonPath('data.totals', ['present' => 0, 'late' => 1, 'absent' => 1, 'excused' => 1]);
        app(TenantContext::class)->forgetTenant(); $school->forceFill(['capability_overrides' => ['school_calendar' => true]])->save();
        $this->getJson($this->familyUrl($child))->assertOk()->assertJsonPath('data.period.kind', 'year')->assertJsonPath('data.totals', ['present' => 1, 'late' => 1, 'absent' => 1, 'excused' => 1]);
    }

    #[Test]
    public function no_calendar_uses_the_existing_school_year_and_no_registers_is_an_empty_record(): void
    {
        $school = $this->makeMasjid(); $child = $this->enrol($this->classroom($school)); $this->family($this->guardian($child));
        $this->getJson($this->familyUrl($child))->assertOk()->assertJsonPath('data.period.kind', 'year')->assertJsonPath('data.period.from', '2026-08-01')->assertJsonCount(0, 'data.days')->assertJsonPath('data.totals.absent', 0);
    }
}
