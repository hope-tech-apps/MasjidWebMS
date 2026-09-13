<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Contact;
use App\Models\Form;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\SchoolClosure;
use App\Models\SchoolYear;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The office's school calendar over HTTP.
 *
 * What these pin: the calendar is a capability nobody has until it is switched
 * on; typos in dates are refused rather than stored; nothing the register, a
 * form answer or a no-school day depends on can be silently stranded; and
 * another organisation's calendar is out of reach.
 */
class SchoolCalendarAdminTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $school;
    private User $admin;

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

        $this->school = $this->makeOrg('school');
        $this->grant($this->school);
        $this->admin = $this->adminOf($this->school);

        Sanctum::actingAs($this->admin);
    }

    #[Test]
    public function the_calendar_round_trips_and_every_write_answers_with_all_of_it(): void
    {
        $created = $this->postJson($this->url('/years'), [
            'label' => '2026–27', 'first_day' => '2026-10-11', 'last_day' => '2027-05-30',
        ])->assertCreated();

        $created->assertJsonPath('data.timezone', 'America/New_York');
        $created->assertJsonPath('data.today', '2026-09-13');
        $created->assertJsonPath('data.years.0.label', '2026–27');
        $created->assertJsonPath('data.years.0.meeting_weekday', 0);
        $created->assertJsonCount(34, 'data.years.0.meeting_days');
        $created->assertJsonPath('data.years.0.meeting_days.0', '2026-10-11');
        $created->assertJsonPath('data.years.0.closures', []);
        $yearId = $created->json('data.years.0.id');

        $closed = $this->postJson($this->url('/closures'), [
            'school_year_id' => $yearId, 'closed_on' => '2026-11-22', 'reason' => 'Thanksgiving weekend',
        ])->assertCreated();
        $closed->assertJsonPath('data.years.0.closures.0.closed_on', '2026-11-22');
        $closureId = $closed->json('data.years.0.closures.0.id');

        $this->putJson($this->url("/closures/{$closureId}"), ['reason' => 'Thanksgiving'])
            ->assertOk()->assertJsonPath('data.years.0.closures.0.reason', 'Thanksgiving');

        $this->putJson($this->url("/years/{$yearId}"), [
            'label' => '2026–27 revised', 'first_day' => '2026-10-11', 'last_day' => '2027-06-06',
        ])->assertOk()->assertJsonPath('data.years.0.last_day', '2027-06-06');

        $this->getJson($this->url())->assertOk()->assertJsonPath('data.years.0.label', '2026–27 revised');

        $this->deleteJson($this->url("/closures/{$closureId}"))->assertOk()->assertJsonPath('data.years.0.closures', []);
        $this->deleteJson($this->url("/years/{$yearId}"))->assertOk()->assertJsonPath('data.years', []);

        $this->assertSame(0, SchoolYear::withoutMasjidScope()->count());
    }

    #[Test]
    public function an_organisation_with_no_calendar_reads_an_empty_one(): void
    {
        $this->getJson($this->url())->assertOk()
            ->assertJsonPath('data.years', [])
            ->assertJsonPath('data.today', '2026-09-13');
    }

    #[Test]
    public function a_year_whose_dates_are_a_typo_is_refused(): void
    {
        // Saturday: the last day is off the weekday the year starts on.
        $this->postJson($this->url('/years'), ['label' => 'Y', 'first_day' => '2026-10-11', 'last_day' => '2027-05-29'])
            ->assertUnprocessable()
            ->assertJsonPath('data.last_day.0', 'The last day must be a Sunday, the day the year starts on (Sunday, October 11, 2026).');

        $this->postJson($this->url('/years'), ['label' => 'Y', 'first_day' => '2026-10-11', 'last_day' => '2026-10-04'])
            ->assertUnprocessable()->assertJsonPath('data.last_day.0', 'The last day cannot be before the first day.');

        $this->postJson($this->url('/years'), ['label' => 'Y', 'first_day' => '2026-10-11', 'last_day' => '2027-10-24'])
            ->assertUnprocessable()->assertJsonPath('data.last_day.0', 'A school year cannot run longer than a year.');

        $this->postJson($this->url('/years'), ['label' => 'Y', 'first_day' => '11/10/2026', 'last_day' => '2027-05-30'])
            ->assertUnprocessable();

        $this->assertSame(0, SchoolYear::withoutMasjidScope()->count());
    }

    #[Test]
    public function years_in_one_organisation_cannot_overlap(): void
    {
        $year = $this->makeYear();

        $this->postJson($this->url('/years'), ['label' => 'Summer', 'first_day' => '2027-05-02', 'last_day' => '2027-08-29'])
            ->assertUnprocessable()
            ->assertJsonPath('data.first_day.0', 'These dates overlap the 2026–27 school year (Sunday, October 11, 2026 to Sunday, May 30, 2027).');

        // The next year, starting after this one ends, is fine...
        $this->postJson($this->url('/years'), ['label' => '2027–28', 'first_day' => '2027-09-12', 'last_day' => '2028-05-28'])
            ->assertCreated();

        // ...and a year never overlaps itself on an edit.
        $this->putJson($this->url("/years/{$year->id}"), ['label' => '2026–27', 'first_day' => '2026-10-11', 'last_day' => '2027-05-30'])
            ->assertOk();
    }

    #[Test]
    public function a_closure_outside_its_year_off_its_weekday_or_twice_is_refused(): void
    {
        $year = $this->makeYear();
        $foreignYear = $this->makeYear($this->makeOrg('school'));

        $post = fn (array $body) => $this->postJson($this->url('/closures'), $body + ['school_year_id' => $year->id, 'reason' => 'Closed']);

        $post(['closed_on' => '2027-06-06'])->assertUnprocessable()->assertJsonPath(
            'data.closed_on.0',
            'Sunday, June 6, 2027 is outside the 2026–27 school year (Sunday, October 11, 2026 to Sunday, May 30, 2027).'
        );
        $post(['closed_on' => '2026-11-23'])->assertUnprocessable()
            ->assertJsonPath('data.closed_on.0', 'Monday, November 23, 2026 is not a Sunday, the day this school meets.');

        $post(['closed_on' => '2026-11-22'])->assertCreated();
        $post(['closed_on' => '2026-11-22'])->assertUnprocessable()
            ->assertJsonPath('data.closed_on.0', 'Sunday, November 22, 2026 is already a no-school day.');

        // Another organisation's year reads as a year that does not exist.
        $this->postJson($this->url('/closures'), ['school_year_id' => $foreignYear->id, 'closed_on' => '2026-11-29', 'reason' => 'x'])
            ->assertUnprocessable()->assertJsonPath('data.school_year_id.0', 'That school year does not exist.');

        $this->assertSame(1, SchoolClosure::withoutMasjidScope()->count());
    }

    #[Test]
    public function a_closure_over_a_register_already_taken_is_refused_with_the_count(): void
    {
        $year = $this->makeYear();
        $this->markRegister('2026-11-22', 2);

        $this->postJson($this->url('/closures'), [
            'school_year_id' => $year->id, 'closed_on' => '2026-11-22', 'reason' => 'Snow day',
        ])->assertUnprocessable()->assertJsonPath(
            'data.closed_on.0',
            'A register was already taken on Sunday, November 22, 2026 (2 attendance marks), so it cannot become a no-school day. Clear those marks first if there really was no school.'
        );

        $this->assertSame(0, SchoolClosure::withoutMasjidScope()->count());
        $this->assertSame(2, AttendanceRecord::withoutMasjidScope()->count());
    }

    #[Test]
    public function a_year_edit_that_would_strand_a_no_school_day_names_it(): void
    {
        $year = $this->makeYear();
        $this->closeDay($year, '2027-05-23');

        $this->putJson($this->url("/years/{$year->id}"), ['label' => '2026–27', 'first_day' => '2026-10-11', 'last_day' => '2027-05-16'])
            ->assertUnprocessable()->assertJsonPath(
                'data.last_day.0',
                'These dates would leave a no-school day outside the school year or off its meeting day: Sunday, May 23, 2027. Remove it first, or keep the year around it.'
            );

        // Moving the whole year to Saturdays strands it too.
        $this->putJson($this->url("/years/{$year->id}"), ['label' => '2026–27', 'first_day' => '2026-10-10', 'last_day' => '2027-05-29'])
            ->assertUnprocessable()->assertJsonPath('data.first_day.0', fn (string $m) => str_contains($m, 'Sunday, May 23, 2027'));

        $this->assertSame('2027-05-30', $year->fresh()->last_day->toDateString());
    }

    #[Test]
    public function a_year_still_in_use_cannot_be_deleted_and_says_by_what(): void
    {
        $withClosure = $this->makeYear();
        $this->closeDay($withClosure, '2026-11-22');

        $withMarks = $this->makeYear(null, '2027-09-12', '2028-05-28');
        $this->markRegister('2027-09-19', 1);

        $withAnswers = $this->makeYear(null, '2028-09-10', '2029-05-27');
        $form = Form::create([
            'masjid_id' => $this->school->id, 'slug' => 'cleaning-'.uniqid(), 'name' => 'Registration',
            'schema' => ['sections' => [['id' => 'family', 'title' => 'Family', 'fields' => [
                ['name' => 'parentName', 'label' => 'Parent', 'type' => 'text', 'required' => true],
                ['name' => 'cleaning', 'label' => 'Cleaning Sundays', 'type' => 'checkboxGroup', 'optionsSource' => 'school_meeting_days'],
            ]]]],
            'settings' => ['identity' => ['name' => 'parentName']],
        ]);
        $this->postJson("/api/v1/forms/{$form->id}/responses", [
            'data' => ['parentName' => 'Amal Yusuf', 'cleaning' => ['2028-09-17']],
        ], ['masjid-id' => (string) $this->school->id])->assertOk();

        $this->deleteJson($this->url("/years/{$withClosure->id}"))->assertUnprocessable()
            ->assertJsonPath('data.year.0', 'This school year cannot be deleted while it has 1 no-school day.');
        $this->deleteJson($this->url("/years/{$withMarks->id}"))->assertUnprocessable()
            ->assertJsonPath('data.year.0', 'This school year cannot be deleted while it has 1 attendance mark inside its dates.');
        $this->deleteJson($this->url("/years/{$withAnswers->id}"))->assertUnprocessable()
            ->assertJsonPath('data.year.0', 'This school year cannot be deleted while it has 1 form answer naming its days.');

        $this->assertSame(3, SchoolYear::withoutMasjidScope()->count());
    }

    #[Test]
    public function another_organisations_calendar_is_out_of_reach(): void
    {
        $other = $this->makeOrg('school');
        $this->grant($other);
        $foreignYear = $this->makeYear($other);
        $foreignClosure = $this->closeDay($foreignYear, '2026-11-22');

        $body = ['label' => 'Hijacked', 'first_day' => '2026-10-11', 'last_day' => '2027-05-30'];

        $this->putJson($this->url("/years/{$foreignYear->id}"), $body)->assertNotFound();
        $this->deleteJson($this->url("/years/{$foreignYear->id}"))->assertNotFound();
        $this->putJson($this->url("/closures/{$foreignClosure->id}"), ['reason' => 'Hijacked'])->assertNotFound();
        $this->deleteJson($this->url("/closures/{$foreignClosure->id}"))->assertNotFound();

        // Naming the other organisation in the URL is refused at the tenant boundary.
        $this->getJson("/api/admin/masjids/{$other->id}/school-calendar")->assertForbidden();

        app(TenantContext::class)->forgetTenant();
        $this->assertSame('2026–27', SchoolYear::find($foreignYear->id)->label);
        $this->assertSame('Thanksgiving weekend', SchoolClosure::find($foreignClosure->id)->reason);
    }

    #[Test]
    public function the_calendar_is_a_capability_switched_on_per_organisation(): void
    {
        $masjid = $this->makeOrg('masjid');
        Sanctum::actingAs($this->adminOf($masjid));
        $this->getJson("/api/admin/masjids/{$masjid->id}/school-calendar")->assertForbidden();

        // A school does not have it by default either (Al-Razi keeps its screens).
        $school = $this->makeOrg('school');
        Sanctum::actingAs($this->adminOf($school));
        $this->getJson("/api/admin/masjids/{$school->id}/school-calendar")->assertForbidden();

        $this->grant($school);
        $this->getJson("/api/admin/masjids/{$school->id}/school-calendar")->assertOk();

        // The platform operator is never locked out.
        $ungranted = $this->makeOrg('school');
        Sanctum::actingAs(User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+15550000001']));
        $this->getJson("/api/admin/masjids/{$ungranted->id}/school-calendar")->assertOk()->assertJsonPath('data.years', []);
    }

    #[Test]
    public function the_index_names_fit_mysqls_identifier_limit(): void
    {
        foreach (['school_years', 'school_closures'] as $table) {
            foreach (Schema::getIndexes($table) as $index) {
                $this->assertLessThanOrEqual(64, strlen($index['name']), "{$table}.{$index['name']}");
            }
        }

        $names = collect(Schema::getIndexes('school_closures'))->pluck('name')->all();
        $this->assertContains('school_closure_day_unique', $names);
        $this->assertContains('school_closure_org_day_idx', $names);
        $this->assertContains('school_year_org_start_unique', collect(Schema::getIndexes('school_years'))->pluck('name')->all());
    }

    // ------------------------------------------------------------- helpers

    private function url(string $path = ''): string
    {
        return "/api/admin/masjids/{$this->school->id}/school-calendar".$path;
    }

    private function makeYear(?Masjid $org = null, string $first = '2026-10-11', string $last = '2027-05-30'): SchoolYear
    {
        $org ??= $this->school;

        return app(TenantContext::class)->runWithout(fn () => SchoolYear::create([
            'masjid_id' => $org->id, 'label' => '2026–27', 'first_day' => $first, 'last_day' => $last,
        ]));
    }

    private function closeDay(SchoolYear $year, string $day): SchoolClosure
    {
        return app(TenantContext::class)->runWithout(fn () => SchoolClosure::create([
            'masjid_id' => $year->masjid_id, 'school_year_id' => $year->id,
            'closed_on' => $day, 'reason' => 'Thanksgiving weekend',
        ]));
    }

    private function markRegister(string $day, int $children): void
    {
        app(TenantContext::class)->runWithout(function () use ($day, $children) {
            $class = Group::factory()->create([
                'masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS,
                'name' => 'Class '.uniqid(), 'slug' => 'class-'.uniqid(),
            ]);

            foreach (range(1, $children) as $i) {
                $child = Contact::factory()->create(['masjid_id' => $this->school->id]);
                $membership = GroupMembership::create([
                    'masjid_id' => $this->school->id, 'group_id' => $class->id,
                    'contact_id' => $child->id, 'role' => GroupMembership::ROLE_MEMBER,
                ]);

                AttendanceRecord::create([
                    'masjid_id' => $this->school->id, 'group_id' => $class->id,
                    'group_membership_id' => $membership->id, 'session_date' => $day, 'status' => 'present',
                ]);
            }
        });
    }

    private function makeOrg(string $orgType): Masjid
    {
        return Masjid::create([
            'name' => 'Calendar Org '.uniqid(),
            'email' => 'cal'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'crm_enabled' => true, 'org_type' => $orgType,
        ]);
    }

    private function grant(Masjid $org): void
    {
        $overrides = $org->capability_overrides ?? [];
        $overrides['school_calendar'] = true;
        $org->forceFill(['capability_overrides' => $overrides])->save();
    }

    private function adminOf(Masjid $org): User
    {
        $user = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1'.random_int(1000000000, 9999999999),
        ]);

        MasjidUser::create([
            'masjid_id' => $org->id, 'user_id' => $user->id,
            'role' => 'masjid-admin', 'is_default' => true,
        ]);

        return $user->fresh();
    }
}
