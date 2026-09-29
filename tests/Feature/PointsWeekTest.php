<?php

namespace Tests\Feature;

use App\Models\BehaviorAward;
use App\Models\BehaviorSkill;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupStaff;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use App\Support\PointsWeek;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * T-003.2 — the opt-in weekly points reset.
 *
 * The reset is a VIEW boundary: a class chooses to read its points a week at a
 * time, the running history is kept and shown, and no award row is ever deleted,
 * revoked or edited by turning it on or off. Everything below is one of three
 * questions: where a week starts and ends (by instant, on the SCHOOL's clock, DST
 * included), who may flip the switch (a teacher of THAT class, in THAT school),
 * and whether a week's figures can ever include something the caller could not
 * already read (they cannot: `?week=` narrows the same audience-constrained
 * query).
 *
 * Al-Razi's clock is America/New_York. 2026-10-04 is a Sunday, EDT until the
 * clocks go back on 2026-11-01 and forward again on 2027-03-14.
 */
class PointsWeekTest extends TestCase
{
    use RefreshDatabase;

    private const ZONE = 'America/New_York';

    private Masjid $masjid;
    private User $admin;
    private User $teacher;
    private Group $group;
    private Contact $parent;
    private GroupMembership $child;
    private GroupMembership $sibling;
    private Contact $otherParent;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->masjid = Masjid::create([
            'name' => 'Al-Razi Test '.uniqid(), 'email' => 'school-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'crm_enabled' => true, 'org_type' => 'school',
            'timezone' => self::ZONE,
        ]);
        $this->admin = User::factory()->create(['type' => 'MasjidAdmin', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
        $this->masjid->user_id = $this->admin->id;
        $this->masjid->save();

        $this->group = Group::factory()->create([
            'masjid_id' => $this->masjid->id, 'kind' => Group::KIND_CLASS, 'name' => 'Grade 3',
        ]);

        $this->teacher = $this->teacherOf($this->masjid, $this->group);

        $childContact = Contact::factory()->create(['masjid_id' => $this->masjid->id, 'email' => null, 'first_name' => 'Amira']);
        $this->child = $this->membership($childContact, GroupMembership::ROLE_MEMBER);
        $siblingContact = Contact::factory()->create(['masjid_id' => $this->masjid->id, 'email' => null, 'first_name' => 'Yusuf']);
        $this->sibling = $this->membership($siblingContact, GroupMembership::ROLE_MEMBER);

        $this->parent = $this->guardianOf($childContact);
        $this->otherParent = $this->guardianOf($siblingContact);

        // The office may award without reading the record back; an admin who READS a class stands on its roster
        // (GroupAudience). This one does, as a leader, so the staff endpoints below answer.
        $leader = Contact::factory()->create(['masjid_id' => $this->masjid->id, 'email' => $this->admin->email]);
        $this->membership($leader, GroupMembership::ROLE_LEADER);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        \Illuminate\Support\Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------ fixtures

    private function teacherOf(Masjid $masjid, ?Group $group): User
    {
        $teacher = User::factory()->create(['type' => 'Teacher', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
        MasjidUser::create(['masjid_id' => $masjid->id, 'user_id' => $teacher->id, 'role' => 'teacher', 'is_default' => true]);

        if ($group !== null) {
            $group->staff()->attach($teacher->id, [
                'masjid_id' => $masjid->id, 'role' => GroupStaff::ROLE_TEACHER, 'assigned_at' => now(),
            ]);
        }

        return $teacher;
    }

    private function membership(Contact $contact, string $role, ?Contact $ward = null, ?Group $group = null): GroupMembership
    {
        return GroupMembership::create([
            'masjid_id' => $this->masjid->id, 'group_id' => ($group ?? $this->group)->id,
            'contact_id' => $contact->id, 'role' => $role, 'guardian_of_contact_id' => $ward?->id,
        ]);
    }

    private function guardianOf(Contact $ward): Contact
    {
        $parent = Contact::factory()->create(['masjid_id' => $this->masjid->id]);
        $parent->forceFill(['login_email' => 'parent-'.uniqid().'@test.local', 'login_enabled_at' => now()])->save();
        $this->membership($parent, GroupMembership::ROLE_GUARDIAN, $ward);

        return $parent;
    }

    /** An award that HAPPENED at `$local` on the school's clock ('Y-m-d H:i'). */
    private function awardAt(string $local, GroupMembership $m, int $points, string $polarity = BehaviorSkill::POLARITY_POSITIVE, string $label = 'Kindness'): BehaviorAward
    {
        return BehaviorAward::factory()->create([
            'masjid_id' => $this->masjid->id, 'group_id' => $this->group->id,
            'group_membership_id' => $m->id, 'skill_label' => $label,
            'skill_polarity' => $polarity, 'points' => $points,
            'awarded_at' => CarbonImmutable::parse($local, self::ZONE)->utc(),
        ]);
    }

    private function freeze(string $local): void
    {
        $at = CarbonImmutable::parse($local, self::ZONE);
        CarbonImmutable::setTestNow($at);
        \Illuminate\Support\Carbon::setTestNow($at);
    }

    private function teacherUrl(string $path, ?Masjid $masjid = null, ?Group $group = null): string
    {
        return '/api/teacher/masjids/'.($masjid ?? $this->masjid)->id.'/groups/'.($group ?? $this->group)->id.$path;
    }

    private function staffUrl(string $path): string
    {
        return "/api/admin/masjids/{$this->masjid->id}/groups/{$this->group->id}{$path}";
    }

    private function actAs(User $user): void
    {
        Auth::forgetGuards();
        app(TenantContext::class)->forgetTenant();
        Sanctum::actingAs($user, ['staff']);
    }

    private function asParent(Contact $contact): self
    {
        Auth::forgetGuards();
        app(TenantContext::class)->forgetTenant();

        return $this->withHeader('Authorization', 'Bearer '.$contact->createFamilyToken()->plainTextToken);
    }

    private function familyUrl(string $path): string
    {
        return "/api/family/masjids/{$this->masjid->id}/groups/{$this->group->id}{$path}";
    }

    // --------------------------------------------------- the week itself

    #[Test]
    public function a_week_runs_sunday_midnight_to_sunday_midnight_on_the_schools_clock(): void
    {
        // Friday 15:00 EDT, the Al-Razi report moment.
        $week = PointsWeek::containing(CarbonImmutable::parse('2026-10-09 15:00', self::ZONE), self::ZONE);

        $this->assertSame('2026-10-04', $week->startDate());
        $this->assertSame('2026-10-10', $week->lastDate());
        $this->assertSame('2026-10-04 04:00:00', $week->startUtc()->format('Y-m-d H:i:s'), 'Sunday 00:00 EDT is 04:00Z');
        $this->assertSame('2026-10-11 04:00:00', $week->endUtc()->format('Y-m-d H:i:s'));
        $this->assertSame(['start' => '2026-10-04', 'end' => '2026-10-10', 'previous' => '2026-09-27',
            'next' => '2026-10-11', 'timezone' => self::ZONE], $week->toArray());
    }

    #[Test]
    public function sunday_itself_starts_its_own_week_and_saturday_ends_the_one_before(): void
    {
        $this->assertSame('2026-10-04', PointsWeek::containing(CarbonImmutable::parse('2026-10-04 00:00', self::ZONE), self::ZONE)->startDate());
        $this->assertSame('2026-09-27', PointsWeek::containing(CarbonImmutable::parse('2026-10-03 23:59:59', self::ZONE), self::ZONE)->startDate());
    }

    #[Test]
    public function the_week_that_ends_daylight_saving_is_169_hours_and_the_one_that_starts_it_is_167(): void
    {
        // Clocks go back 2026-11-01 02:00 (Sunday). The week starting that day begins in EDT and ends in EST.
        $fall = PointsWeek::containing(CarbonImmutable::parse('2026-11-04 12:00', self::ZONE), self::ZONE);
        $this->assertSame('2026-11-01', $fall->startDate());
        $this->assertSame('2026-11-01 04:00:00', $fall->startUtc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-08 05:00:00', $fall->endUtc()->format('Y-m-d H:i:s'), 'Sunday 00:00 EST is 05:00Z, not start + 7 x 24h');
        $this->assertSame(169 * 3600, $fall->endUtc()->getTimestamp() - $fall->startUtc()->getTimestamp());

        // Clocks go forward 2027-03-14 02:00 (Sunday). That week starts in EST and ends in EDT.
        $spring = PointsWeek::containing(CarbonImmutable::parse('2027-03-16 12:00', self::ZONE), self::ZONE);
        $this->assertSame('2027-03-14', $spring->startDate());
        $this->assertSame('2027-03-14 05:00:00', $spring->startUtc()->format('Y-m-d H:i:s'));
        $this->assertSame('2027-03-21 04:00:00', $spring->endUtc()->format('Y-m-d H:i:s'));
        $this->assertSame(167 * 3600, $spring->endUtc()->getTimestamp() - $spring->startUtc()->getTimestamp());
    }

    #[Test]
    public function a_saturday_night_award_in_the_daylight_saving_week_stays_in_that_week(): void
    {
        // 23:30 EST Saturday 2026-11-07 is 04:30Z on the 8th. A week built as start + 7 x 24h would end at
        // 04:00Z and drop it; built in local time it ends at 05:00Z and keeps it.
        $late = $this->awardAt('2026-11-07 23:30', $this->child, 2);
        $nextWeek = $this->awardAt('2026-11-08 00:30', $this->child, 5);

        $fall = PointsWeek::containing(CarbonImmutable::parse('2026-11-04 12:00', self::ZONE), self::ZONE);
        $ids = BehaviorAward::withoutMasjidScope()->awardedWithin($fall->startUtc(), $fall->endUtc())->pluck('id')->all();

        $this->assertSame([$late->id], $ids);
        $this->assertNotContains($nextWeek->id, $ids);
    }

    #[Test]
    public function the_week_starts_on_a_named_day_not_an_assumed_one(): void
    {
        $monday = PointsWeek::containing(CarbonImmutable::parse('2026-10-09 15:00', self::ZONE), self::ZONE, 1);

        $this->assertSame('2026-10-05', $monday->startDate());
        $this->assertSame('2026-10-11', $monday->lastDate());
        $this->assertSame('2026-10-05', PointsWeek::startingOn('2026-10-11', self::ZONE, 1)->startDate(), 'Sunday is the LAST day of a Monday week');
    }

    #[Test]
    public function any_day_of_a_week_names_it_and_a_non_date_names_nothing(): void
    {
        foreach (['2026-10-04', '2026-10-07', '2026-10-10'] as $day) {
            $this->assertSame('2026-10-04', PointsWeek::startingOn($day, self::ZONE)->startDate(), $day);
        }

        foreach (['', 'soon', '2026-02-30', '2026-13-01', '10/04/2026', '2026-10-04 12:00'] as $bad) {
            $this->assertNull(PointsWeek::startingOn($bad, self::ZONE), "'{$bad}' is not a day");
        }
    }

    #[Test]
    public function the_reports_own_moment_is_named_inside_the_week_across_daylight_saving(): void
    {
        $fall = PointsWeek::startingOn('2026-11-01', self::ZONE);
        $spring = PointsWeek::startingOn('2027-03-14', self::ZONE);

        // Al-Razi, Friday 15:00: EST after the fall change, EDT after the spring one.
        $this->assertSame('2026-11-06 20:00:00', $fall->at(5, '15:00')->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2027-03-19 19:00:00', $spring->at(5, '15:00')->utc()->format('Y-m-d H:i:s'));
        // BISS, Sunday 18:00: the first day of its own week, after the change on the fall Sunday.
        $this->assertSame('2026-11-01 23:00:00', $fall->at(0, '18:00')->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2027-03-14 22:00:00', $spring->at(0, '18:00')->utc()->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function awards_are_placed_by_instant_so_a_friday_evening_eastern_award_is_not_moved_a_day(): void
    {
        // Saturday 2026-10-10 21:30 EDT is 01:30Z on the 11th. DATE(awarded_at) says the 11th, so a whereDate
        // range over the week's local dates ('2026-10-04'..'2026-10-10') DROPS it; the instant range keeps it.
        $evening = $this->awardAt('2026-10-10 21:30', $this->child, 3);

        $week = PointsWeek::startingOn('2026-10-04', self::ZONE);

        $this->assertTrue(BehaviorAward::withoutMasjidScope()->awardedWithin($week->startUtc(), $week->endUtc())->whereKey($evening->id)->exists());
        $this->assertFalse(
            BehaviorAward::withoutMasjidScope()->awardedBetween('2026-10-04', '2026-10-10')->whereKey($evening->id)->exists(),
            'the whereDate range this scope replaces: the row it would have lost'
        );
    }

    #[Test]
    public function the_range_is_half_open_so_an_award_on_the_boundary_belongs_to_exactly_one_week(): void
    {
        $boundary = $this->awardAt('2026-10-11 00:00', $this->child, 1);
        $before = PointsWeek::startingOn('2026-10-04', self::ZONE);
        $after = PointsWeek::startingOn('2026-10-11', self::ZONE);

        $in = fn (PointsWeek $w) => BehaviorAward::withoutMasjidScope()->awardedWithin($w->startUtc(), $w->endUtc())->whereKey($boundary->id)->exists();

        $this->assertFalse($in($before));
        $this->assertTrue($in($after));
    }

    // ------------------------------------------------- the column itself

    #[Test]
    public function a_class_reads_running_unless_it_says_weekly(): void
    {
        $group = new Group();

        $this->assertSame('running', $group->pointsPeriod());
        $this->assertFalse($group->usesWeeklyPoints());

        foreach ([null, '', 'daily', 'WEEKLY', 'sideways'] as $stored) {
            $this->assertSame('running', $group->forceFill(['points_period' => $stored])->pointsPeriod(), var_export($stored, true));
        }

        $this->assertTrue($group->forceFill(['points_period' => 'weekly'])->usesWeeklyPoints());
    }

    #[Test]
    public function the_column_is_a_short_nullable_string_with_no_default(): void
    {
        $this->assertTrue(Schema::hasColumn('groups', 'points_period'));
        $this->assertSame('varchar', Schema::getColumnType('groups', 'points_period'));

        // SQLite ignores a declared length, so the length is read off the migration itself.
        $migration = (string) file_get_contents(base_path('database/migrations/2026_10_02_100000_add_points_period_to_groups_table.php'));
        $this->assertMatchesRegularExpression("/string\\('points_period', 16\\)->nullable\\(\\)/", $migration);

        // A class that existed before the column reads exactly as it did.
        $this->assertNull(Group::withoutMasjidScope()->findOrFail($this->group->id)->points_period);
    }

    // ------------------------------------------------ the teacher's switch

    #[Test]
    public function a_teacher_opts_their_class_in_and_out_and_it_says_it_applies_to_every_teacher(): void
    {
        $this->actAs($this->teacher);

        $this->putJson($this->teacherUrl('/points-period'), ['points_period' => 'weekly'])
            ->assertOk()
            ->assertJsonPath('data.points_period', 'weekly')
            ->assertJsonPath('data.applies_to', 'every teacher of this class');

        $this->assertSame('weekly', Group::withoutMasjidScope()->find($this->group->id)->points_period);
        $this->getJson("/api/teacher/masjids/{$this->masjid->id}/groups/{$this->group->id}")
            ->assertOk()->assertJsonPath('data.points_period', 'weekly');

        $this->putJson($this->teacherUrl('/points-period'), ['points_period' => 'running'])
            ->assertOk()->assertJsonPath('data.points_period', 'running');
        $this->assertSame('running', Group::withoutMasjidScope()->find($this->group->id)->points_period);
    }

    #[Test]
    public function turning_the_reset_on_and_off_changes_no_award_and_no_running_total(): void
    {
        $this->freeze('2026-10-09 15:00');
        $this->awardAt('2026-10-09 10:00', $this->child, 3);
        $this->awardAt('2026-10-02 10:00', $this->child, 5);
        $gone = $this->awardAt('2026-10-01 10:00', $this->child, 7);
        $gone->delete(); // an earlier revocation: it must STAY revoked, not be revived by a toggle.

        $snapshot = fn () => BehaviorAward::withoutMasjidScope()->withTrashed()->orderBy('id')
            ->get(['id', 'group_membership_id', 'skill_polarity', 'points', 'awarded_at', 'deleted_at', 'updated_at'])->toArray();
        $before = $snapshot();

        $this->actAs($this->teacher);
        $running = fn () => $this->getJson($this->teacherUrl('/awards/totals'))->assertOk()->json('data.class.points');
        $this->assertSame(8, $running());

        $this->putJson($this->teacherUrl('/points-period'), ['points_period' => 'weekly'])->assertOk();
        $this->assertSame(8, $running(), 'the running figure is still served, unchanged, in a weekly class');
        $this->putJson($this->teacherUrl('/points-period'), ['points_period' => 'running'])->assertOk();

        $this->assertSame($before, $snapshot(), 'no award row was deleted, revoked, revived or touched');
    }

    #[Test]
    public function the_period_must_be_one_of_the_two_words_and_survives_a_form_encoded_body(): void
    {
        $this->actAs($this->teacher);

        foreach ([['points_period' => 'daily'], ['points_period' => ''], ['points_period' => ['weekly']], []] as $bad) {
            $this->putJson($this->teacherUrl('/points-period'), $bad)->assertStatus(422)->assertJsonPath('status', 'failed');
        }
        $this->assertNull(Group::withoutMasjidScope()->find($this->group->id)->points_period);

        // The SPA's axios posts form-encoded: the client's own encoding, not only postJson.
        $this->withHeaders(['Accept' => 'application/json'])
            ->put($this->teacherUrl('/points-period'), ['points_period' => 'weekly'])
            ->assertOk()->assertJsonPath('data.points_period', 'weekly');
    }

    #[Test]
    public function a_teacher_cannot_flip_the_switch_on_a_class_they_do_not_lead(): void
    {
        $other = Group::factory()->create(['masjid_id' => $this->masjid->id, 'kind' => Group::KIND_CLASS, 'name' => 'Grade 4']);

        $this->actAs($this->teacher);
        $this->putJson($this->teacherUrl('/points-period', null, $other), ['points_period' => 'weekly'])->assertForbidden();

        $this->assertNull(Group::withoutMasjidScope()->find($other->id)->points_period);
    }

    #[Test]
    public function a_teacher_of_another_school_cannot_reach_this_schools_class(): void
    {
        $school = Masjid::create([
            'name' => 'Other School '.uniqid(), 'email' => 'other-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '2 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'crm_enabled' => true, 'org_type' => 'school',
        ]);
        $ownClass = Group::factory()->create(['masjid_id' => $school->id, 'kind' => Group::KIND_CLASS, 'name' => 'Own']);
        $outsider = $this->teacherOf($school, $ownClass);

        $this->actAs($outsider);

        // This school's id in the URL, another school's teacher: the tenant middleware refuses.
        $this->putJson($this->teacherUrl('/points-period'), ['points_period' => 'weekly'])->assertForbidden();
        // Their own school's id with THIS school's class id: a miss, not a write.
        $status = $this->putJson("/api/teacher/masjids/{$school->id}/groups/{$this->group->id}/points-period", ['points_period' => 'weekly'])
            ->getStatusCode();
        $this->assertContains($status, [403, 404]);

        $this->assertNull(Group::withoutMasjidScope()->find($this->group->id)->points_period);
    }

    #[Test]
    public function a_parent_cannot_use_the_teachers_switch(): void
    {
        $this->asParent($this->parent)
            ->putJson($this->teacherUrl('/points-period'), ['points_period' => 'weekly'])
            ->assertStatus(401);

        $this->assertNull(Group::withoutMasjidScope()->find($this->group->id)->points_period);
    }

    #[Test]
    public function the_office_can_set_it_through_the_group_form_too(): void
    {
        $this->actAs($this->admin);

        $this->putJson("/api/admin/masjids/{$this->masjid->id}/groups/{$this->group->id}", ['points_period' => 'weekly'])->assertOk();
        $this->assertSame('weekly', Group::withoutMasjidScope()->find($this->group->id)->points_period);

        $this->putJson("/api/admin/masjids/{$this->masjid->id}/groups/{$this->group->id}", ['points_period' => 'monthly'])->assertStatus(422);
        $this->assertSame('weekly', Group::withoutMasjidScope()->find($this->group->id)->points_period);

        // Blank puts the class back on the default.
        $this->putJson("/api/admin/masjids/{$this->masjid->id}/groups/{$this->group->id}", ['points_period' => null])->assertOk();
        $this->assertSame('running', Group::withoutMasjidScope()->find($this->group->id)->pointsPeriod());
    }

    // ------------------------------------------- the teacher's week totals

    /** A child with a record spread over three weeks and a Saturday night. */
    private function seedRecord(): void
    {
        // Now is Friday 2026-10-09 15:00 EDT, inside the week of 2026-10-04.
        $this->freeze('2026-10-09 15:00');

        $this->awardAt('2026-10-06 10:00', $this->child, 3);                                                   // this week +3
        $this->awardAt('2026-10-08 10:00', $this->child, 1, BehaviorSkill::POLARITY_NEGATIVE, 'Late');         // this week -1
        $this->awardAt('2026-10-10 21:30', $this->child, 2);                                                   // this week, Saturday night, +2
        $this->awardAt('2026-10-02 12:00', $this->child, 5);                                                   // last week +5
        $this->awardAt('2026-10-11 00:30', $this->child, 4);                                                   // NEXT week +4
        $this->awardAt('2026-10-07 09:00', $this->child, 50)->delete();                                        // this week, REVOKED: counts nowhere
        $this->awardAt('2026-10-07 11:00', $this->sibling, 6);                                                 // this week, a classmate
    }

    #[Test]
    public function the_totals_serve_the_week_beside_the_running_figures(): void
    {
        $this->seedRecord();
        $this->actAs($this->teacher);

        $data = $this->getJson($this->teacherUrl('/awards/totals'))->assertOk()->json('data');
        $byId = collect($data['students'])->keyBy('membership_id');

        $this->assertSame(4, $byId[$this->child->id]['week_points'], '3 - 1 + 2, the negative subtracting');
        $this->assertSame(3, $byId[$this->child->id]['week_awards']);
        $this->assertSame(13, $byId[$this->child->id]['points'], 'running: 3 - 1 + 2 + 5 + 4');
        $this->assertSame(5, $byId[$this->child->id]['awards']);
        $this->assertSame(6, $byId[$this->sibling->id]['week_points']);

        $this->assertSame(10, $data['class']['week_points']);
        $this->assertSame(4, $data['class']['week_awards']);
        $this->assertSame(19, $data['class']['points']);

        $this->assertSame('2026-10-04', $data['week']['start']);
        $this->assertSame('2026-10-10', $data['week']['end']);
        $this->assertTrue($data['week']['is_current']);
        $this->assertSame('running', $data['points_period']);
    }

    #[Test]
    public function the_totals_take_a_week_and_stay_in_roster_order_with_no_rank(): void
    {
        $this->seedRecord();
        $this->actAs($this->teacher);

        $last = $this->getJson($this->teacherUrl('/awards/totals?week=2026-09-27'))->assertOk()->json('data');
        $this->assertSame(5, collect($last['students'])->keyBy('membership_id')[$this->child->id]['week_points']);
        $this->assertSame(0, collect($last['students'])->keyBy('membership_id')[$this->sibling->id]['week_points']);
        $this->assertFalse($last['week']['is_current']);
        $this->assertSame('2026-10-04', $last['week']['next']);
        $this->assertSame('2026-09-20', $last['week']['previous']);

        // The sibling has MORE this week (6 > 4) and is still listed second: roster order, never points order.
        $now = $this->getJson($this->teacherUrl('/awards/totals'))->json('data');
        $this->assertSame(
            [$this->child->id, $this->sibling->id],
            array_values(array_intersect(collect($now['students'])->pluck('membership_id')->all(), [$this->child->id, $this->sibling->id]))
        );
        foreach ($now['students'] as $row) {
            $this->assertArrayNotHasKey('rank', $row);
            $this->assertArrayNotHasKey('position', $row);
        }
    }

    #[Test]
    public function the_word_current_names_the_schools_week_and_a_non_date_is_refused_not_guessed(): void
    {
        $this->seedRecord();
        $this->actAs($this->teacher);

        $explicit = $this->getJson($this->teacherUrl('/awards/totals'))->json('data');
        $named = $this->getJson($this->teacherUrl('/awards/totals?week=current'))->assertOk()->json('data');
        $this->assertSame($explicit['week'], $named['week']);
        $this->assertSame($explicit['class'], $named['class']);

        foreach (['week=soon', 'week=2026-02-30', 'week[]=2026-10-04', 'week=2026-10-04%2012:00'] as $bad) {
            $this->getJson($this->teacherUrl("/awards/totals?{$bad}"))->assertStatus(422);
        }
        $this->actAs($this->admin);
        $this->getJson($this->staffUrl('/awards?week=nonsense'))->assertStatus(422);
    }

    #[Test]
    public function the_week_is_the_schools_week_not_the_servers_or_the_browsers(): void
    {
        // 02:00Z on Sunday 2026-10-11 is STILL Saturday night in New York: the week of the 4th, not the 11th.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-11 02:00:00', 'UTC'));
        \Illuminate\Support\Carbon::setTestNow(CarbonImmutable::parse('2026-10-11 02:00:00', 'UTC'));
        $this->actAs($this->teacher);

        $this->getJson($this->teacherUrl('/awards/totals'))->assertOk()->assertJsonPath('data.week.start', '2026-10-04');

        // 05:00Z the same Sunday is 01:00 EDT: the new week.
        \Illuminate\Support\Carbon::setTestNow(CarbonImmutable::parse('2026-10-11 05:00:00', 'UTC'));
        $this->getJson($this->teacherUrl('/awards/totals'))->assertOk()->assertJsonPath('data.week.start', '2026-10-11');
    }

    #[Test]
    public function the_listing_takes_a_week_by_instant_where_a_date_range_would_lose_the_saturday_night_award(): void
    {
        $this->seedRecord();
        $this->actAs($this->admin);

        $week = $this->getJson($this->staffUrl('/awards?week=2026-10-04'))->assertOk();
        $this->assertSame(4, $week->json('data.total'), 'four live awards this week (3, -1, 2 and the classmate 6)');
        $this->assertSame('2026-10-04', $week->json('meta.week.start'));

        $range = $this->getJson($this->staffUrl('/awards?from=2026-10-04&to=2026-10-10'))->assertOk();
        $this->assertSame(3, $range->json('data.total'), 'the date range still behaves as it always did: it lost the Saturday-night award');

        // No week, no `week` key: an old client sees the payload it always saw.
        $this->assertArrayNotHasKey('week', $this->getJson($this->staffUrl('/awards'))->json('meta'));
    }

    #[Test]
    public function the_staff_summary_takes_a_week_and_says_which(): void
    {
        $this->seedRecord();
        $this->actAs($this->admin);

        $r = $this->getJson($this->staffUrl("/members/{$this->child->id}/awards/summary?week=2026-10-06"))->assertOk();

        $this->assertSame(4, $r->json('data.totals.points'));
        $this->assertSame(3, $r->json('data.totals.awards'));
        $this->assertSame('2026-10-04', $r->json('data.week.start'));
        // Positives first, as the picker reads (T-003.1).
        $this->assertSame(['positive', 'negative'], array_values(array_unique(array_column($r->json('data.by_skill'), 'polarity'))));

        $all = $this->getJson($this->staffUrl("/members/{$this->child->id}/awards/summary"))->assertOk();
        $this->assertNull($all->json('data.week'));
        $this->assertSame(13, $all->json('data.totals.points'));
    }

    #[Test]
    public function only_the_classs_teachers_see_a_class_list_and_a_weekly_class_does_not_change_that(): void
    {
        $this->seedRecord();
        Group::withoutMasjidScope()->whereKey($this->group->id)->update(['points_period' => 'weekly']);

        // A teacher of the school who is not one of THIS class's teachers.
        $other = $this->teacherOf($this->masjid, null);
        $this->actAs($other);
        $this->getJson($this->teacherUrl('/awards/totals'))->assertForbidden();
    }

    // ------------------------------------------------ the family's week

    #[Test]
    public function a_parent_reads_their_own_childs_week_and_the_whole_record(): void
    {
        $this->seedRecord();

        $week = $this->asParent($this->parent)
            ->getJson($this->familyUrl("/members/{$this->child->id}/awards/summary?week=current"))->assertOk();

        $this->assertSame(4, $week->json('data.totals.points'));
        $this->assertSame('2026-10-04', $week->json('data.week.start'));
        $this->assertTrue($week->json('data.week.is_current'));

        $all = $this->asParent($this->parent)
            ->getJson($this->familyUrl("/members/{$this->child->id}/awards/summary"))->assertOk();
        $this->assertSame(13, $all->json('data.totals.points'));
        $this->assertNull($all->json('data.week'));

        $log = $this->asParent($this->parent)
            ->getJson($this->familyUrl("/members/{$this->child->id}/awards?week=2026-10-04"))->assertOk();
        $this->assertSame(3, $log->json('data.total'));
        $this->assertSame('2026-10-04', $log->json('meta.week.start'));
    }

    #[Test]
    public function a_week_can_never_widen_what_a_parent_may_read(): void
    {
        $this->seedRecord();

        // Another family's child, with and without a week: refused both ways, and never counted.
        foreach (['', '?week=current', '?week=2026-10-04'] as $query) {
            $this->asParent($this->parent)
                ->getJson($this->familyUrl("/members/{$this->sibling->id}/awards/summary{$query}"))->assertForbidden();
            $this->asParent($this->parent)
                ->getJson($this->familyUrl("/members/{$this->sibling->id}/awards{$query}"))->assertForbidden();
        }

        // And the figure a parent DOES get is theirs alone: the classmate's 6 is in nobody else's week.
        $this->asParent($this->parent)
            ->getJson($this->familyUrl("/members/{$this->child->id}/awards/summary?week=2026-10-04"))
            ->assertOk()->assertJsonPath('data.totals.points', 4);
    }

    #[Test]
    public function a_bad_week_is_a_422_for_a_parent_too_never_this_weeks_figures_under_a_wrong_label(): void
    {
        $this->seedRecord();

        $this->asParent($this->parent)
            ->getJson($this->familyUrl("/members/{$this->child->id}/awards/summary?week=last-friday"))->assertStatus(422);
    }

    #[Test]
    public function the_parent_portal_is_told_how_the_class_reads(): void
    {
        $this->asParent($this->parent)->getJson($this->familyUrl(''))->assertOk()->assertJsonPath('data.points_period', 'running');

        Group::withoutMasjidScope()->whereKey($this->group->id)->update(['points_period' => 'weekly']);

        $this->asParent($this->parent)->getJson($this->familyUrl(''))->assertOk()->assertJsonPath('data.points_period', 'weekly');
    }
}
