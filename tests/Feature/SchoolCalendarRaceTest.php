<?php

namespace Tests\Feature;

use App\Http\Requests\Admin\SchoolCalendar\StoreSchoolClosureRequest;
use App\Http\Requests\Admin\SchoolCalendar\StoreSchoolYearRequest;
use App\Models\AttendanceRecord;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\SchoolClosure;
use App\Models\SchoolYear;
use App\Models\User;
use App\Support\SchoolCalendar;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The calendar's writes when two things happen at once.
 *
 * SQLite runs one connection and has no row locks, so a real race cannot be
 * staged here. What CAN be shown is the half that matters: a change landing
 * AFTER the request's validation and BEFORE the controller's write is still
 * refused, because the controller re-checks inside its transaction (under the
 * organisation's or the year's row lock on MySQL). Each test hooks
 * afterResolving on the FormRequest — which runs after Laravel's own
 * validation hook — and proves the change landed after validation.
 *
 * The unique-index backstop for a duplicate YEAR is not reachable here: the
 * re-check under the organisation lock finds the duplicate first. The closure
 * index backstop is reachable, and is pinned.
 */
class SchoolCalendarRaceTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $school;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->travelTo(Carbon::parse('2026-09-13 16:00:00'));

        $this->school = $this->makeMasjid();
        $this->school->forceFill(['capability_overrides' => ['school_calendar' => true]])->save();

        $admin = User::factory()->create(['type' => 'MasjidAdmin', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
        MasjidUser::create(['masjid_id' => $this->school->id, 'user_id' => $admin->id, 'role' => 'masjid-admin', 'is_default' => true]);
        Sanctum::actingAs($admin->fresh());
    }

    #[Test]
    public function an_overlapping_year_saved_after_the_check_is_refused_under_the_lock(): void
    {
        $afterValidation = $this->whenValidated(StoreSchoolYearRequest::class, fn () => SchoolYear::create([
            'label' => '2026–27', 'first_day' => '2026-10-11', 'last_day' => '2027-05-30',
        ]));

        $this->postJson($this->url('/years'), ['label' => 'Summer', 'first_day' => '2027-05-02', 'last_day' => '2027-08-29'])
            ->assertUnprocessable()
            ->assertJsonPath('data.first_day.0', 'These dates overlap the 2026–27 school year (Sunday, October 11, 2026 to Sunday, May 30, 2027).');

        $this->assertTrue($afterValidation(), 'the other year must land AFTER validation, or this proves nothing');
        $this->assertSame(1, SchoolYear::withoutMasjidScope()->count());
    }

    #[Test]
    public function a_closure_whose_year_moved_after_the_check_is_refused_against_the_locked_year(): void
    {
        $year = $this->makeYear();

        $afterValidation = $this->whenValidated(StoreSchoolClosureRequest::class, fn () => SchoolYear::whereKey($year->id)->update(['last_day' => '2026-11-15']));

        $this->postJson($this->url('/closures'), ['school_year_id' => $year->id, 'closed_on' => '2026-11-22', 'reason' => 'Snow day'])
            ->assertUnprocessable()
            ->assertJsonPath('data.closed_on.0', 'Sunday, November 22, 2026 is no longer inside the 2026–27 school year or on its meeting day. Reload the calendar and try again.');

        $this->assertTrue($afterValidation());
        $this->assertSame(0, SchoolClosure::withoutMasjidScope()->count());
    }

    #[Test]
    public function the_same_closure_arriving_twice_at_once_is_a_422_not_a_500(): void
    {
        $year = $this->makeYear();

        $afterValidation = $this->whenValidated(StoreSchoolClosureRequest::class, fn () => SchoolClosure::create([
            'school_year_id' => $year->id, 'closed_on' => '2026-11-22', 'reason' => 'Thanksgiving weekend',
        ]));

        $this->postJson($this->url('/closures'), ['school_year_id' => $year->id, 'closed_on' => '2026-11-22', 'reason' => 'Thanksgiving weekend'])
            ->assertUnprocessable()
            ->assertJsonPath('data.closed_on.0', 'Sunday, November 22, 2026 is already a no-school day.');

        $this->assertTrue($afterValidation());
        $this->assertSame(1, SchoolClosure::withoutMasjidScope()->count());
    }

    #[Test]
    public function the_same_year_or_closure_posted_twice_is_a_422(): void
    {
        $body = ['label' => '2026–27', 'first_day' => '2026-10-11', 'last_day' => '2027-05-30'];

        $yearId = $this->postJson($this->url('/years'), $body)->assertCreated()->json('data.years.0.id');
        $this->postJson($this->url('/years'), $body)->assertUnprocessable()->assertJsonPath(
            'data.first_day.0', 'These dates overlap the 2026–27 school year (Sunday, October 11, 2026 to Sunday, May 30, 2027).'
        );

        $closure = ['school_year_id' => $yearId, 'closed_on' => '2026-11-22', 'reason' => 'Thanksgiving weekend'];
        $this->postJson($this->url('/closures'), $closure)->assertCreated();
        $this->postJson($this->url('/closures'), $closure)->assertUnprocessable()
            ->assertJsonPath('data.closed_on.0', 'Sunday, November 22, 2026 is already a no-school day.');

        $this->assertSame(1, SchoolYear::withoutMasjidScope()->count());
        $this->assertSame(1, SchoolClosure::withoutMasjidScope()->count());
    }

    #[Test]
    public function a_closure_is_found_by_organisation_and_day_and_never_another_organisations(): void
    {
        $year = $this->makeYear();
        $other = $this->makeMasjid();
        $theirYear = $this->makeYear($other);

        app(TenantContext::class)->runWithout(function () use ($year, $theirYear) {
            SchoolClosure::create(['masjid_id' => $year->masjid_id, 'school_year_id' => $year->id, 'closed_on' => '2026-11-22', 'reason' => 'Ours']);
            SchoolClosure::create(['masjid_id' => $theirYear->masjid_id, 'school_year_id' => $theirYear->id, 'closed_on' => '2026-11-29', 'reason' => 'Theirs']);
        });

        $this->assertSame('Ours', SchoolCalendar::closureFor($this->school->id, '2026-11-22')?->reason);
        $this->assertSame('Ours', SchoolCalendar::closureFor($this->school->id, '2026-11-22', lock: true)?->reason);
        $this->assertNull(SchoolCalendar::closureFor($this->school->id, '2026-11-29'));
        $this->assertNull(SchoolCalendar::closureFor($this->school->id, '2026-11-21'));
        $this->assertNull(SchoolCalendar::closureFor($this->school->id, 'not a date'));
        $this->assertSame('Theirs', SchoolCalendar::closureFor($other->id, '2026-11-29')?->reason);
    }

    #[Test]
    public function another_schools_register_on_the_same_sunday_blocks_nothing_here(): void
    {
        $year = $this->makeYear();
        $other = $this->makeMasjid();
        $this->makeYear($other);

        // The other school took its register on the very Sunday this one closes,
        // and inside this school's year.
        app(TenantContext::class)->runWithout(function () use ($other) {
            $class = Group::factory()->create([
                'masjid_id' => $other->id, 'kind' => Group::KIND_CLASS,
                'name' => 'Their class', 'slug' => 'their-class-'.uniqid(),
            ]);
            $child = Contact::factory()->create(['masjid_id' => $other->id]);
            $membership = GroupMembership::create([
                'masjid_id' => $other->id, 'group_id' => $class->id,
                'contact_id' => $child->id, 'role' => GroupMembership::ROLE_MEMBER,
            ]);
            AttendanceRecord::create([
                'masjid_id' => $other->id, 'group_id' => $class->id,
                'group_membership_id' => $membership->id, 'session_date' => '2026-11-22', 'status' => 'present',
            ]);
        });

        $this->postJson($this->url('/closures'), ['school_year_id' => $year->id, 'closed_on' => '2026-11-22', 'reason' => 'Thanksgiving weekend'])
            ->assertCreated()
            ->assertJsonPath('data.years.0.closures.0.closed_on', '2026-11-22');

        // Nor does it hold this school's year: once the closure goes, the year deletes.
        $closureId = SchoolClosure::query()->where('masjid_id', $this->school->id)->value('id');
        $this->deleteJson($this->url("/closures/{$closureId}"))->assertOk();
        $this->deleteJson($this->url("/years/{$year->id}"))->assertOk()->assertJsonPath('data.years', []);

        // The other school's register and year are exactly as they were.
        $this->assertSame(1, AttendanceRecord::withoutMasjidScope()->where('masjid_id', $other->id)->count());
        $this->assertSame(1, SchoolYear::withoutMasjidScope()->where('masjid_id', $other->id)->count());
        $this->assertSame(0, SchoolClosure::withoutMasjidScope()->where('masjid_id', $other->id)->count());
    }

    // ------------------------------------------------------------- helpers

    /**
     * Run $change after the request's validation has passed. Returns a probe that
     * says whether it really ran after validation.
     */
    private function whenValidated(string $requestClass, callable $change): callable
    {
        $afterValidation = null;

        $this->app->afterResolving($requestClass, function ($request) use ($change, &$afterValidation) {
            $afterValidation = (fn () => $this->validator !== null)->call($request);
            $change();
        });

        return function () use (&$afterValidation): bool {
            return $afterValidation === true;
        };
    }

    private function url(string $path = ''): string
    {
        return "/api/admin/masjids/{$this->school->id}/school-calendar".$path;
    }

    private function makeYear(?Masjid $org = null): SchoolYear
    {
        $org ??= $this->school;

        return app(TenantContext::class)->runWithout(fn () => SchoolYear::create([
            'masjid_id' => $org->id, 'label' => '2026–27', 'first_day' => '2026-10-11', 'last_day' => '2027-05-30',
        ]));
    }

    private function makeMasjid(): Masjid
    {
        return Masjid::create([
            'name' => 'Race School '.uniqid(),
            'email' => 'race'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'crm_enabled' => true, 'org_type' => 'school',
        ]);
    }
}
