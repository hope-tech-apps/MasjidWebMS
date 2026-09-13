<?php

namespace Tests\Feature;

use App\Models\Masjid;
use App\Models\SchoolClosure;
use App\Models\SchoolYear;
use App\Support\SchoolCalendar;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The model-layer boundary around a school's calendar.
 *
 * `BelongsToMasjid` on school_years and school_closures is the only thing keeping
 * one school's dates out of another's queries (.claude/rules/tenant-scoping.md).
 * A leak here is not private data — dates and reasons — but it would put one
 * school's no-school days on another school's register and forms.
 */
class SchoolCalendarTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $tenant;
    private Masjid $schoolA;
    private Masjid $schoolB;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->tenant = app(TenantContext::class);
        $this->tenant->forgetTenant();

        $this->schoolA = $this->makeMasjid();
        $this->schoolB = $this->makeMasjid();
    }

    #[Test]
    public function the_scope_hides_another_schools_years_and_closures(): void
    {
        [$mineYear, $mineClosure] = $this->calendarFor($this->schoolA);
        [$foreignYear, $foreignClosure] = $this->calendarFor($this->schoolB);

        $this->tenant->set($this->schoolA->id);

        $this->assertSame(1, SchoolYear::count());
        $this->assertNull(SchoolYear::find($foreignYear->id));
        $this->assertNotNull(SchoolYear::find($mineYear->id));

        $this->assertSame(1, SchoolClosure::count());
        $this->assertNull(SchoolClosure::find($foreignClosure->id));
        $this->assertNotNull(SchoolClosure::find($mineClosure->id));

        // The calendar class keeps the scope on: bound to A, asking for B's
        // calendar gets nothing rather than B's days.
        $this->assertCount(0, SchoolCalendar::for($this->schoolB->id)->years());
    }

    #[Test]
    public function another_schools_calendar_cannot_be_updated_or_deleted(): void
    {
        [$foreignYear, $foreignClosure] = $this->calendarFor($this->schoolB);

        $this->tenant->set($this->schoolA->id);

        $this->assertSame(0, SchoolYear::where('id', $foreignYear->id)->update(['label' => 'Hijacked']));
        $this->assertSame(0, SchoolClosure::where('id', $foreignClosure->id)->update(['reason' => 'Hijacked']));
        $this->assertSame(0, SchoolClosure::where('id', $foreignClosure->id)->delete());
        $this->assertSame(0, SchoolYear::where('id', $foreignYear->id)->delete());

        $this->tenant->forgetTenant();
        $this->assertSame('2026–27', SchoolYear::find($foreignYear->id)->label);
        $this->assertSame('Thanksgiving weekend', SchoolClosure::find($foreignClosure->id)->reason);
    }

    #[Test]
    public function create_stamps_the_bound_tenant_over_a_client_supplied_masjid_id(): void
    {
        $this->tenant->set($this->schoolA->id);

        $year = SchoolYear::create([
            'masjid_id' => $this->schoolB->id,
            'label' => '2026–27', 'first_day' => '2026-10-11', 'last_day' => '2027-05-30',
        ]);
        $closure = SchoolClosure::create([
            'masjid_id' => $this->schoolB->id,
            'school_year_id' => $year->id, 'closed_on' => '2026-11-22', 'reason' => 'Thanksgiving weekend',
        ]);

        $this->assertSame($this->schoolA->id, (int) $year->masjid_id);
        $this->assertSame($this->schoolA->id, (int) $closure->masjid_id);

        $this->tenant->set($this->schoolB->id);
        $this->assertNull(SchoolYear::find($year->id));
        $this->assertNull(SchoolClosure::find($closure->id));
    }

    /** @return array{0:SchoolYear,1:SchoolClosure} seeded UNBOUND, so the hook stamps nothing over it */
    private function calendarFor(Masjid $school): array
    {
        return $this->tenant->runWithout(function () use ($school) {
            $year = SchoolYear::create([
                'masjid_id' => $school->id,
                'label' => '2026–27', 'first_day' => '2026-10-11', 'last_day' => '2027-05-30',
            ]);

            $closure = SchoolClosure::create([
                'masjid_id' => $school->id, 'school_year_id' => $year->id,
                'closed_on' => '2026-11-22', 'reason' => 'Thanksgiving weekend',
            ]);

            return [$year, $closure];
        });
    }

    private function makeMasjid(): Masjid
    {
        return Masjid::create([
            'name' => 'Test School '.uniqid(),
            'email' => 'school-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'org_type' => 'school',
        ]);
    }
}
