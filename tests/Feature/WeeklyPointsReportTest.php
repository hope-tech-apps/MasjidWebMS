<?php

namespace Tests\Feature;

use App\Mail\WeeklyPointsReportMail;
use App\Models\BehaviorAward;
use App\Models\BehaviorSkill;
use App\Models\BehaviorWeek;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupStaff;
use App\Models\Masjid;
use App\Models\MasjidPointsSetting;
use App\Models\MasjidUser;
use App\Models\SchoolClosure;
use App\Models\SchoolYear;
use App\Models\User;
use App\Support\PointsReportSchedule;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * T-003.3 — the Friday points report.
 *
 * `points:weekly-report` tells each family "your child's weekly report is ready"
 * (a notice and a link, never the numbers, owner B5) and each class's teachers that
 * their class summary is. What these tests pin, in order: WHEN it goes (the school's
 * clock, both daylight-saving directions, a catch-up window, once only), FOR WHICH WEEK
 * (the week holding the send time, up to that moment), TO WHOM (only a consented,
 * confirmed, CURRENT guardian of a CURRENT ward with a live login; the class's staff),
 * WHAT IT SAYS (nothing about the child), and that it is OFF unless a SuperAdmin says.
 *
 * Al-Razi's clock is America/New_York. Friday 2026-10-09 15:00 EDT is 19:00Z.
 */
class WeeklyPointsReportTest extends TestCase
{
    use RefreshDatabase;

    private const ZONE = 'America/New_York';

    private Masjid $masjid;
    private Group $group;
    private User $teacher;
    private Contact $amira;      // awarded this week
    private Contact $yusuf;      // no award unless a test gives one
    private GroupMembership $amiraMembership;
    private GroupMembership $yusufMembership;
    private Contact $huda;       // Amira's guardian: consented, current, live login
    private Contact $sara;       // Yusuf's guardian: consented, current, live login

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        app(TenantContext::class)->forgetTenant();

        $this->masjid = $this->school('Al-Razi Test', true);
        $this->group = Group::factory()->create([
            'masjid_id' => $this->masjid->id, 'kind' => Group::KIND_CLASS, 'name' => 'Grade 3',
        ]);

        $this->teacher = $this->staffTeacher($this->masjid, $this->group, 'teacher@school.test');

        $this->amira = $this->child('Amira');
        $this->yusuf = $this->child('Yusuf');
        $this->amiraMembership = $this->participant($this->amira);
        $this->yusufMembership = $this->participant($this->yusuf);

        $this->huda = $this->guardianOf($this->amira, 'huda@fam.test');
        $this->sara = $this->guardianOf($this->yusuf, 'sara@fam.test');

        Mail::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------ fixtures

    private function school(string $name, bool $enabled, ?string $timezone = self::ZONE): Masjid
    {
        $masjid = Masjid::create([
            'name' => $name.' '.uniqid(), 'email' => 'office-'.uniqid().'@school.test',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'crm_enabled' => true, 'org_type' => 'school',
            'timezone' => $timezone,
        ]);

        if ($enabled) {
            $masjid->forceFill(['capability_overrides' => ['points_weekly_report' => true]])->save();
        }

        return $masjid;
    }

    private function staffTeacher(Masjid $masjid, Group $group, string $email): User
    {
        $teacher = User::factory()->create([
            'type' => 'Teacher', 'email' => $email, 'phone' => '+1'.random_int(1000000000, 9999999999),
        ]);
        MasjidUser::create(['masjid_id' => $masjid->id, 'user_id' => $teacher->id, 'role' => 'teacher', 'is_default' => true]);
        $group->staff()->attach($teacher->id, [
            'masjid_id' => $masjid->id, 'role' => GroupStaff::ROLE_TEACHER, 'assigned_at' => now(),
        ]);

        return $teacher;
    }

    private function child(string $first, ?Masjid $masjid = null): Contact
    {
        return Contact::factory()->create([
            'masjid_id' => ($masjid ?? $this->masjid)->id, 'first_name' => $first, 'last_name' => 'Tester', 'email' => null,
        ]);
    }

    private function participant(Contact $contact, ?Group $group = null): GroupMembership
    {
        $group ??= $this->group;

        return GroupMembership::create([
            'masjid_id' => $group->masjid_id, 'group_id' => $group->id,
            'contact_id' => $contact->id, 'role' => GroupMembership::ROLE_MEMBER,
        ]);
    }

    /**
     * A guardian edge over `$ward`. Defaults to everything a recipient must have; each option
     * takes one thing away.
     *
     * @param  array{consented?:bool,scope?:string,login?:bool,revoked?:bool,provenance?:string,group?:Group}  $o
     */
    private function guardianOf(Contact $ward, string $email, array $o = []): Contact
    {
        $group = $o['group'] ?? $this->group;
        $guardian = Contact::factory()->create([
            'masjid_id' => $group->masjid_id, 'first_name' => 'Guardian', 'last_name' => 'Of', 'email' => null,
        ]);

        if ($o['login'] ?? true) {
            $guardian->forceFill([
                'login_email' => $email, 'login_enabled_at' => now(),
                'login_revoked_at' => ($o['revoked'] ?? false) ? now() : null,
            ])->save();
        }

        $consented = $o['consented'] ?? true;

        $edge = GroupMembership::create([
            'masjid_id' => $group->masjid_id, 'group_id' => $group->id,
            'contact_id' => $guardian->id, 'role' => GroupMembership::ROLE_GUARDIAN,
            'guardian_of_contact_id' => $ward->id,
            'consent_granted_at' => $consented ? now() : null,
            'consent_scope' => $consented ? ($o['scope'] ?? GroupMembership::CONSENT_FEED) : null,
        ]);

        if (isset($o['provenance'])) {
            $edge->forceFill(['provenance' => $o['provenance']])->save();
        }

        return $guardian;
    }

    /** An award that HAPPENED at `$local` on Al-Razi's clock ('Y-m-d H:i'). */
    private function award(string $local, GroupMembership $m, int $points = 2, string $polarity = BehaviorSkill::POLARITY_POSITIVE, string $label = 'Kindness', ?string $note = null): BehaviorAward
    {
        return BehaviorAward::factory()->create([
            'masjid_id' => $m->masjid_id, 'group_id' => $m->group_id, 'group_membership_id' => $m->id,
            'skill_label' => $label, 'skill_polarity' => $polarity, 'points' => $points, 'note' => $note,
            'awarded_at' => CarbonImmutable::parse($local, self::ZONE)->utc(),
        ]);
    }

    /** Freeze time at an instant on Al-Razi's clock ('Y-m-d H:i[:s]'). */
    private function now(string $local): void
    {
        $this->nowUtc(CarbonImmutable::parse($local, self::ZONE)->utc()->format('Y-m-d H:i:s'));
    }

    /** Freeze time at a UTC instant. */
    private function nowUtc(string $utc): void
    {
        $at = CarbonImmutable::parse($utc, 'UTC');
        Carbon::setTestNow($at);
        CarbonImmutable::setTestNow($at);
    }

    private function run_(array $args = []): string
    {
        Artisan::call('points:weekly-report', $args);

        return Artisan::output();
    }

    /** Where each notice of an audience went, sorted. @return list<string> */
    private function sentTo(string $audience): array
    {
        return collect(Mail::sent(WeeklyPointsReportMail::class))
            ->filter(fn (WeeklyPointsReportMail $m) => $m->audience === $audience)
            ->flatMap(fn (WeeklyPointsReportMail $m) => collect($m->to)->pluck('address'))
            ->sort()->values()->all();
    }

    private function families(): array
    {
        return $this->sentTo(WeeklyPointsReportMail::AUDIENCE_FAMILY);
    }

    private function teachers(): array
    {
        return $this->sentTo(WeeklyPointsReportMail::AUDIENCE_TEACHER);
    }

    private function claimed(): int
    {
        return DB::table('behavior_weeks')->whereNotNull('report_sent_at')->count();
    }

    /** The ordinary week: Amira has a live award on Tuesday 2026-10-06. Send moment Friday 2026-10-09 15:00 EDT. */
    private function ordinaryWeek(): void
    {
        $this->award('2026-10-06 10:00', $this->amiraMembership);
    }

    // ------------------------------------------------ the grant, and when

    #[Test]
    public function it_sends_nothing_unless_a_super_admin_switched_the_grant_on(): void
    {
        $this->masjid->forceFill(['capability_overrides' => null])->save();
        $this->ordinaryWeek();
        $this->now('2026-10-09 15:00');

        $out = $this->run_();

        Mail::assertNothingSent();
        $this->assertSame(0, DB::table('behavior_weeks')->count(), 'nothing is even claimed while it is off');
        $this->assertStringContainsString('0 class(es) sent', $out);
        $this->assertFalse((bool) config('capabilities.points_weekly_report.defaults.school'), 'off by default, schools included');
        $this->assertSame(['masjid' => false, 'school' => false, 'community' => false], config('capabilities.points_weekly_report.defaults'));
    }

    #[Test]
    public function a_switched_off_school_is_skipped_while_a_switched_on_one_is_sent(): void
    {
        $off = $this->school('Other School', false);
        $offGroup = Group::factory()->create(['masjid_id' => $off->id, 'kind' => Group::KIND_CLASS, 'name' => 'Off class']);
        $offChild = $this->child('Zaid', $off);
        $offMembership = $this->participant($offChild, $offGroup);
        $this->guardianOf($offChild, 'off@fam.test', ['group' => $offGroup]);
        $this->award('2026-10-06 10:00', $offMembership);
        $this->ordinaryWeek();
        $this->now('2026-10-09 15:00');

        $this->run_();

        $this->assertSame(['huda@fam.test'], $this->families());
    }

    #[Test]
    public function the_default_moment_is_friday_at_three_on_the_schools_own_clock(): void
    {
        $this->ordinaryWeek();

        $schedule = PointsReportSchedule::for($this->masjid);
        $this->assertSame(5, $schedule['weekday']);
        $this->assertSame('15:00', $schedule['time']);
        $this->assertSame(['default', 'default'], [$schedule['weekday_source'], $schedule['time_source']]);

        // One second early: nothing. On the moment: it goes.
        $this->now('2026-10-09 14:59:59');
        $this->run_();
        Mail::assertNothingSent();

        $this->now('2026-10-09 15:00:00');
        $this->run_();
        $this->assertSame(['huda@fam.test'], $this->families());
    }

    #[Test]
    public function the_moment_is_right_across_both_daylight_saving_changes_for_a_friday_school(): void
    {
        foreach ([
            // [awarded, one second before (UTC), on the moment (UTC), why]
            ['2026-11-03 10:00', '2026-11-06 19:59:59', '2026-11-06 20:00:00', 'Friday 15:00 EST, the week the clocks went back'],
            ['2027-03-16 10:00', '2027-03-19 18:59:59', '2027-03-19 19:00:00', 'Friday 15:00 EDT, the week the clocks went forward'],
            ['2026-10-06 10:00', '2026-10-09 18:59:59', '2026-10-09 19:00:00', 'Friday 15:00 EDT, an ordinary week'],
        ] as [$awarded, $before, $on, $why]) {
            Mail::fake();
            DB::table('behavior_weeks')->delete();
            BehaviorAward::withoutMasjidScope()->forceDelete();
            $this->award($awarded, $this->amiraMembership);

            $this->nowUtc($before);
            $this->run_();
            $this->assertSame([], $this->families(), "sent early: {$why}");

            $this->nowUtc($on);
            $this->run_();
            $this->assertSame(['huda@fam.test'], $this->families(), "not sent on time: {$why}");
        }
    }

    #[Test]
    public function a_sunday_school_gets_sunday_evening_including_the_daylight_saving_sundays(): void
    {
        MasjidPointsSetting::create(['masjid_id' => $this->masjid->id, 'report_weekday' => 0, 'report_time' => '18:00']);

        foreach ([
            // A Sunday award; the moment is Sunday 18:00 local, the first day of its own week.
            ['2026-10-11 10:00', '2026-10-11 21:59:59', '2026-10-11 22:00:00', 'an ordinary Sunday, 18:00 EDT'],
            ['2026-11-01 10:00', '2026-11-01 22:59:59', '2026-11-01 23:00:00', 'the Sunday the clocks went back, 18:00 EST'],
            ['2027-03-14 10:00', '2027-03-14 21:59:59', '2027-03-14 22:00:00', 'the Sunday the clocks went forward, 18:00 EDT'],
        ] as [$awarded, $before, $on, $why]) {
            Mail::fake();
            DB::table('behavior_weeks')->delete();
            BehaviorAward::withoutMasjidScope()->forceDelete();
            $this->award($awarded, $this->amiraMembership);

            $this->nowUtc($before);
            $this->run_();
            $this->assertSame([], $this->families(), "sent early: {$why}");

            $this->nowUtc($on);
            $this->run_();
            $this->assertSame(['huda@fam.test'], $this->families(), "not sent on time: {$why}");
        }
    }

    #[Test]
    public function a_school_can_set_only_a_time_and_keep_the_default_weekday(): void
    {
        MasjidPointsSetting::create(['masjid_id' => $this->masjid->id, 'report_weekday' => null, 'report_time' => '16:30']);
        $this->ordinaryWeek();

        $schedule = PointsReportSchedule::for($this->masjid);
        $this->assertSame([5, '16:30', 'default', 'set'], [$schedule['weekday'], $schedule['time'], $schedule['weekday_source'], $schedule['time_source']]);

        $this->now('2026-10-09 15:00');
        $this->run_();
        Mail::assertNothingSent();

        $this->now('2026-10-09 16:30');
        $this->run_();
        $this->assertSame(['huda@fam.test'], $this->families());
    }

    #[Test]
    public function an_unusable_stored_value_reads_as_the_default_instead_of_breaking_the_sweep(): void
    {
        DB::table('masjid_points_settings')->insert([
            'masjid_id' => $this->masjid->id, 'report_weekday' => 9, 'report_time' => '99:99',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $schedule = PointsReportSchedule::for($this->masjid);

        $this->assertSame([5, '15:00'], [$schedule['weekday'], $schedule['time']]);
    }

    #[Test]
    public function a_missed_run_catches_up_for_twelve_hours_and_never_goes_out_days_late(): void
    {
        $this->ordinaryWeek();

        // Eleven hours after the moment: still inside the window.
        $this->now('2026-10-10 01:59');
        $this->run_();
        $this->assertSame(['huda@fam.test'], $this->families());

        // Another school-week: twelve hours and one second after, the window has closed.
        Mail::fake();
        DB::table('behavior_weeks')->delete();
        $this->now('2026-10-10 03:00:01');
        $out = $this->run_();
        Mail::assertNothingSent();
        $this->assertStringContainsString('0 class(es) sent', $out);
    }

    #[Test]
    public function the_catch_up_window_is_exactly_twelve_hours_from_the_moment(): void
    {
        $this->ordinaryWeek();        // the moment is Friday 2026-10-09 15:00 EDT

        // Twelve hours to the second: the window has closed (its edge is exclusive).
        $this->now('2026-10-10 03:00:00');
        $out = $this->run_();
        Mail::assertNothingSent();
        $this->assertStringContainsString('0 class(es) sent', $out);
        $this->assertSame(0, DB::table('behavior_weeks')->count());

        // One second inside it, eleven hours fifty-nine minutes fifty-nine seconds after: still owed.
        $this->now('2026-10-10 02:59:59');
        $this->run_();
        $this->assertSame(['huda@fam.test'], $this->families());
    }

    #[Test]
    public function the_window_into_the_next_week_is_exactly_twelve_hours_too(): void
    {
        // Saturday 23:00 moment; the window runs into Sunday, where the previous week is picked up.
        MasjidPointsSetting::create(['masjid_id' => $this->masjid->id, 'report_weekday' => 6, 'report_time' => '23:00']);
        $this->award('2026-10-06 10:00', $this->amiraMembership);

        // Sunday 11:00:00 is twelve hours after: closed.
        $this->now('2026-10-11 11:00:00');
        $this->run_();
        Mail::assertNothingSent();
        $this->assertSame(0, DB::table('behavior_weeks')->count());

        // Sunday 10:59:59: eleven hours fifty-nine minutes fifty-nine seconds after: still owed.
        $this->now('2026-10-11 10:59:59');
        $this->run_();
        $this->assertSame(['huda@fam.test'], $this->families());
        $this->assertSame(['2026-10-04'], DB::table('behavior_weeks')->pluck('week_start')->all());
    }

    #[Test]
    public function a_moment_late_on_saturday_still_catches_up_after_midnight_into_the_next_week(): void
    {
        // Saturday 23:00: the catch-up window runs into Sunday, where the week containing "now" is the NEXT
        // one and its own Saturday has not come. The previous week's report is still owed, and only that.
        MasjidPointsSetting::create(['masjid_id' => $this->masjid->id, 'report_weekday' => 6, 'report_time' => '23:00']);
        $this->award('2026-10-06 10:00', $this->amiraMembership);        // the week of 2026-10-04

        $this->now('2026-10-10 22:59');
        $this->run_();
        Mail::assertNothingSent();

        $this->now('2026-10-11 01:30');                                   // Sunday, inside the window
        $this->run_();
        $this->assertSame(['huda@fam.test'], $this->families());
        $this->assertSame(['2026-10-04'], DB::table('behavior_weeks')->pluck('week_start')->all());

        // And not twice, and not for the week that has just begun.
        $this->now('2026-10-11 02:30');
        $this->run_();
        $this->assertSame(['huda@fam.test'], $this->families());
        $this->assertSame(1, DB::table('behavior_weeks')->count());
    }

    #[Test]
    public function switching_the_grant_on_saturday_does_not_send_fridays_report(): void
    {
        $this->masjid->forceFill(['capability_overrides' => null])->save();
        $this->ordinaryWeek();
        $this->now('2026-10-10 12:00');
        $this->run_();

        $this->masjid->forceFill(['capability_overrides' => ['points_weekly_report' => true]])->save();
        $this->now('2026-10-10 12:30');
        $this->run_();

        Mail::assertNothingSent();
    }

    #[Test]
    public function before_the_moment_in_the_week_nothing_is_sent(): void
    {
        $this->ordinaryWeek();

        foreach (['2026-10-04 00:00', '2026-10-07 12:00', '2026-10-08 23:59'] as $when) {
            $this->now($when);
            $this->run_();
        }

        Mail::assertNothingSent();
        $this->assertSame(0, DB::table('behavior_weeks')->count());
    }

    // --------------------------------------------------- once, and only once

    #[Test]
    public function a_class_is_told_once_however_many_times_the_sweep_runs(): void
    {
        $this->ordinaryWeek();

        foreach (['2026-10-09 15:00', '2026-10-09 15:00:30', '2026-10-09 16:00', '2026-10-09 22:00'] as $when) {
            $this->now($when);
            $this->run_();
        }

        $this->assertSame(['huda@fam.test'], $this->families());
        $this->assertSame(['teacher@school.test'], $this->teachers());
        $this->assertSame(1, DB::table('behavior_weeks')->count());

        $row = DB::table('behavior_weeks')->first();
        $this->assertSame('2026-10-04', $row->week_start);
        $this->assertNotNull($row->report_sent_at);
        $this->assertSame(1, (int) $row->recipients_count);
    }

    #[Test]
    public function the_next_weeks_report_is_a_new_send(): void
    {
        $this->ordinaryWeek();
        $this->award('2026-10-13 10:00', $this->amiraMembership);

        $this->now('2026-10-09 15:00');
        $this->run_();
        $this->now('2026-10-16 15:00');
        $this->run_();

        $this->assertSame(['huda@fam.test', 'huda@fam.test'], $this->families());
        $this->assertSame(['2026-10-04', '2026-10-11'], DB::table('behavior_weeks')->orderBy('week_start')->pluck('week_start')->all());
    }

    #[Test]
    public function the_claim_is_won_by_exactly_one_caller_and_the_database_says_so(): void
    {
        $this->assertTrue(BehaviorWeek::claim($this->masjid->id, $this->group->id, '2026-10-04'));
        $this->assertFalse(BehaviorWeek::claim($this->masjid->id, $this->group->id, '2026-10-04'), 'a second caller loses');
        $this->assertFalse(BehaviorWeek::claim($this->masjid->id, $this->group->id, '2026-10-04'));
        $this->assertTrue(BehaviorWeek::claim($this->masjid->id, $this->group->id, '2026-10-11'), 'another week is another claim');

        $this->assertSame(2, DB::table('behavior_weeks')->count());
        $this->assertTrue(BehaviorWeek::sent($this->group->id, '2026-10-04'));
        $this->assertFalse(BehaviorWeek::sent($this->group->id, '2026-09-27'));

        // The unique index is what makes this safe under a race, not the code above it.
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('behavior_weeks')->insert([
            'masjid_id' => $this->masjid->id, 'group_id' => $this->group->id, 'week_start' => '2026-10-04',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    #[Test]
    public function a_run_that_finds_the_week_already_claimed_sends_nothing(): void
    {
        $this->ordinaryWeek();
        BehaviorWeek::claim($this->masjid->id, $this->group->id, '2026-10-04');

        $this->now('2026-10-09 15:00');
        $out = $this->run_();

        Mail::assertNothingSent();
        $this->assertStringContainsString('1 already sent', $out);
    }

    // --------------------------------------------------------------- dry run

    #[Test]
    public function a_dry_run_counts_who_would_be_told_and_sends_and_records_nothing(): void
    {
        $this->ordinaryWeek();
        $this->now('2026-10-09 15:00');

        $out = $this->run_(['--dry-run' => true]);

        Mail::assertNothingSent();
        $this->assertSame(0, DB::table('behavior_weeks')->count());
        $this->assertStringContainsString('(dry run)', $out);
        $this->assertStringContainsString('1 class(es) would be sent, 1 family notice(s), 1 teacher notice(s)', $out);

        // ...and the real run afterwards still sends: the dry run claimed nothing.
        $this->run_();
        $this->assertSame(['huda@fam.test'], $this->families());
    }

    #[Test]
    public function an_explicit_week_reports_that_week_and_refuses_one_whose_moment_has_not_come(): void
    {
        // Last week's award, run a week late by hand.
        $this->award('2026-09-29 10:00', $this->amiraMembership);
        $this->now('2026-10-09 15:00');

        $this->run_();
        $this->assertSame([], $this->families(), 'the automatic run only ever reports the current week');

        $this->run_(['--week' => '2026-10-02']);
        $this->assertSame(['huda@fam.test'], $this->families(), 'any day of the week names it');
        $this->assertSame(['2026-09-27'], DB::table('behavior_weeks')->pluck('week_start')->all());

        // A week whose Friday has not happened is not reported early.
        Mail::fake();
        $this->award('2026-10-13 10:00', $this->amiraMembership);
        $this->run_(['--week' => '2026-10-14']);
        Mail::assertNothingSent();
    }

    #[Test]
    public function the_options_are_validated_not_guessed(): void
    {
        $this->assertSame(2, Artisan::call('points:weekly-report', ['--week' => 'last-friday']));
        $this->assertSame(2, Artisan::call('points:weekly-report', ['--week' => '2026-02-30']));
        $this->assertSame(2, Artisan::call('points:weekly-report', ['--masjid' => 'al-razi']));
        Mail::assertNothingSent();

        // --masjid narrows to one school.
        $this->ordinaryWeek();
        $this->now('2026-10-09 15:00');
        $this->run_(['--masjid' => (string) ($this->masjid->id + 1000)]);
        Mail::assertNothingSent();
        $this->run_(['--masjid' => (string) $this->masjid->id]);
        $this->assertSame(['huda@fam.test'], $this->families());
    }

    // --------------------------------------------------- which week, which awards

    #[Test]
    public function an_award_after_the_send_moment_never_makes_a_second_email_or_a_late_recipient(): void
    {
        // Amira's award is before 15:00; Yusuf's is at 15:30, after the moment. The run happens at 16:10
        // (a catch-up): the cutoff is the scheduled 15:00, NOT the moment the run started.
        $this->award('2026-10-09 10:00', $this->amiraMembership);
        $this->award('2026-10-09 15:30', $this->yusufMembership);

        $this->now('2026-10-09 16:10');
        $this->run_();

        $this->assertSame(['huda@fam.test'], $this->families(), 'Yusuf\'s family is not told about an award that came after the report');

        // The later award shows in the portal only: another run finds the week claimed.
        $this->now('2026-10-09 17:00');
        $this->run_();
        $this->assertSame(['huda@fam.test'], $this->families());
    }

    #[Test]
    public function a_child_whose_only_award_came_after_the_moment_has_nothing_to_report_and_nothing_is_claimed(): void
    {
        $this->award('2026-10-09 15:30', $this->amiraMembership);

        $this->now('2026-10-09 15:45');
        $this->run_();

        Mail::assertNothingSent();
        $this->assertSame(0, DB::table('behavior_weeks')->count());
    }

    #[Test]
    public function the_week_holds_sunday_morning_and_leaves_the_saturday_before_it_alone(): void
    {
        // Last Saturday night (an earlier week) and this Sunday 00:30 (this week).
        $this->award('2026-10-03 23:30', $this->yusufMembership);
        $this->award('2026-10-04 00:30', $this->amiraMembership);

        $this->now('2026-10-09 15:00');
        $this->run_();

        $this->assertSame(['huda@fam.test'], $this->families());
    }

    #[Test]
    public function a_revoked_award_is_not_a_week(): void
    {
        $this->award('2026-10-06 10:00', $this->amiraMembership)->delete();

        $this->now('2026-10-09 15:00');
        $this->run_();

        Mail::assertNothingSent();
        $this->assertSame(0, DB::table('behavior_weeks')->count());
    }

    #[Test]
    public function a_week_of_only_corrections_is_still_a_week_the_family_may_read(): void
    {
        $this->award('2026-10-06 10:00', $this->amiraMembership, 1, BehaviorSkill::POLARITY_NEGATIVE, 'Late');

        $this->now('2026-10-09 15:00');
        $this->run_();

        $this->assertSame(['huda@fam.test'], $this->families());
    }

    // ------------------------------------------------------------ recipients

    #[Test]
    public function only_a_consented_confirmed_current_guardian_with_a_live_login_is_told(): void
    {
        $this->ordinaryWeek();

        $this->guardianOf($this->amira, 'not-consented@fam.test', ['consented' => false]);
        $this->guardianOf($this->amira, 'media-consent@fam.test', ['scope' => GroupMembership::CONSENT_MEDIA]);
        $this->guardianOf($this->amira, 'revoked@fam.test', ['revoked' => true]);
        $this->guardianOf($this->amira, 'no-login@fam.test', ['login' => false]);
        $this->guardianOf($this->amira, 'self-asserted@fam.test', ['provenance' => GroupMembership::PROVENANCE_SELF_ASSERTED]);
        // An address on file whose login was never switched on (login_enabled_at is null): not a live login.
        $never = $this->guardianOf($this->amira, 'never-enabled@fam.test');
        $never->forceFill(['login_enabled_at' => null])->save();

        $this->now('2026-10-09 15:00');
        $this->run_();

        // Media consent covers feed (a hierarchy); nothing else beyond the original guardian.
        $this->assertSame(['huda@fam.test', 'media-consent@fam.test'], $this->families());
    }

    #[Test]
    public function a_guardian_who_has_left_the_class_is_not_told(): void
    {
        $this->ordinaryWeek();
        $departed = $this->guardianOf($this->amira, 'departed@fam.test');

        // Only the EDGE leaves (the child stays), written straight to the row so no model hook can tidy it:
        // the resolver must check the edge itself.
        DB::table('group_memberships')
            ->where('contact_id', $departed->id)->where('role', GroupMembership::ROLE_GUARDIAN)
            ->update(['left_on' => '2026-10-05']);

        $this->now('2026-10-09 15:00');
        $this->run_();

        $this->assertSame(['huda@fam.test'], $this->families());
    }

    private function secondClass(string $name = 'Hifdh circle'): Group
    {
        return Group::factory()->create([
            'masjid_id' => $this->masjid->id, 'kind' => Group::KIND_HALAQA, 'name' => $name,
        ]);
    }

    #[Test]
    public function a_guardian_of_the_same_child_in_another_class_only_is_not_told_about_this_class(): void
    {
        // Amira is in Grade 3 AND in a hifdh circle. Kareem holds a consented, current, confirmed edge over
        // her in the CIRCLE only. He has no standing in Grade 3, and its report is none of his business.
        $circle = $this->secondClass();
        $this->participant($this->amira, $circle);
        $this->guardianOf($this->amira, 'kareem@fam.test', ['group' => $circle]);
        $this->ordinaryWeek();

        $this->now('2026-10-09 15:00');
        $this->run_();

        $this->assertSame(['huda@fam.test'], $this->families(), 'the guardian is Grade 3\'s, not the circle\'s');
    }

    #[Test]
    public function a_guardian_consented_in_another_class_but_not_in_this_one_is_not_told(): void
    {
        // Layla is Amira's guardian in Grade 3 WITHOUT consent, and holds a consented edge in the circle.
        // Consent is per class: the circle's does not carry over.
        $circle = $this->secondClass();
        $this->participant($this->amira, $circle);
        $layla = $this->guardianOf($this->amira, 'layla@fam.test', ['consented' => false]);
        GroupMembership::create([
            'masjid_id' => $this->masjid->id, 'group_id' => $circle->id,
            'contact_id' => $layla->id, 'role' => GroupMembership::ROLE_GUARDIAN,
            'guardian_of_contact_id' => $this->amira->id,
            'consent_granted_at' => now(), 'consent_scope' => GroupMembership::CONSENT_FEED,
        ]);
        $this->ordinaryWeek();

        $this->now('2026-10-09 15:00');
        $this->run_();

        $this->assertSame(['huda@fam.test'], $this->families());
    }

    #[Test]
    public function a_withdrawn_child_is_neither_reported_nor_their_family_told(): void
    {
        $this->ordinaryWeek();
        $this->award('2026-10-06 11:00', $this->yusufMembership);

        // The office records Yusuf as withdrawn. The model hook takes his guardian edges with him.
        $this->yusufMembership->markLeftByStaff(null, '2026-10-07')->save();

        $this->now('2026-10-09 15:00');
        $this->run_();

        $this->assertSame(['huda@fam.test'], $this->families());
        $this->assertSame(1, (int) DB::table('behavior_weeks')->value('recipients_count'));
    }

    #[Test]
    public function a_ward_who_is_no_longer_on_the_roster_is_not_reported_even_if_a_guardian_edge_lingers(): void
    {
        $this->award('2026-10-06 11:00', $this->yusufMembership);

        // The CHILD leaves, written straight to the row: the guardian edge is still current, exactly the
        // state the model hook exists to prevent. The resolver must not rely on the hook having run.
        DB::table('group_memberships')->where('id', $this->yusufMembership->id)->update(['left_on' => '2026-10-07']);

        $this->now('2026-10-09 15:00');
        $this->run_();

        Mail::assertNothingSent();
        $this->assertSame(0, DB::table('behavior_weeks')->count());
    }

    #[Test]
    public function a_family_is_told_only_about_a_child_who_has_a_week(): void
    {
        $this->ordinaryWeek();          // Amira only
        $this->now('2026-10-09 15:00');

        $this->run_();

        $this->assertSame(['huda@fam.test'], $this->families());
        $this->assertNotContains('sara@fam.test', $this->families(), 'Yusuf has no award, so his family gets no report');
    }

    #[Test]
    public function a_parent_with_two_children_in_the_class_gets_one_notice(): void
    {
        $this->ordinaryWeek();
        $this->award('2026-10-06 11:00', $this->yusufMembership);
        // The same guardian, with an edge over each child.
        GroupMembership::create([
            'masjid_id' => $this->masjid->id, 'group_id' => $this->group->id,
            'contact_id' => $this->huda->id, 'role' => GroupMembership::ROLE_GUARDIAN,
            'guardian_of_contact_id' => $this->yusuf->id,
            'consent_granted_at' => now(), 'consent_scope' => GroupMembership::CONSENT_FEED,
        ]);
        DB::table('group_memberships')->where('contact_id', $this->sara->id)->update(['left_on' => '2026-10-01']);

        $this->now('2026-10-09 15:00');
        $this->run_();

        $this->assertSame(['huda@fam.test'], $this->families(), 'one notice for a parent with two children in the class');
    }

    #[Test]
    public function the_teachers_get_the_class_summary_and_nobody_else_on_staff_does(): void
    {
        $this->ordinaryWeek();
        $this->staffTeacher($this->masjid, $this->group, 'second@school.test');
        $elsewhere = Group::factory()->create(['masjid_id' => $this->masjid->id, 'kind' => Group::KIND_CLASS, 'name' => 'Grade 4']);
        $this->staffTeacher($this->masjid, $elsewhere, 'other-class@school.test');
        User::factory()->create(['type' => 'MasjidAdmin', 'email' => 'office@school.test', 'phone' => '+1'.random_int(1000000000, 9999999999)]);

        $this->now('2026-10-09 15:00');
        $this->run_();

        $this->assertSame(['second@school.test', 'teacher@school.test'], $this->teachers());
    }

    #[Test]
    public function a_legacy_leader_reached_through_a_family_login_is_not_sent_the_teachers_link(): void
    {
        $this->ordinaryWeek();
        $leader = Contact::factory()->create(['masjid_id' => $this->masjid->id, 'email' => null]);
        $leader->forceFill(['login_email' => 'leader@fam.test', 'login_enabled_at' => now()])->save();
        GroupMembership::create([
            'masjid_id' => $this->masjid->id, 'group_id' => $this->group->id,
            'contact_id' => $leader->id, 'role' => GroupMembership::ROLE_LEADER,
        ]);

        $this->now('2026-10-09 15:00');
        $this->run_();

        $this->assertSame(['teacher@school.test'], $this->teachers());
        $this->assertNotContains('leader@fam.test', $this->families());
    }

    #[Test]
    public function a_class_with_no_award_this_week_tells_nobody_teachers_included(): void
    {
        $this->award('2026-09-30 10:00', $this->amiraMembership);    // last week

        $this->now('2026-10-09 15:00');
        $this->run_();

        Mail::assertNothingSent();
    }

    #[Test]
    public function a_class_with_awards_but_nobody_reachable_claims_nothing_so_a_later_login_is_picked_up(): void
    {
        $this->teacher->forceFill(['email' => ''])->save();
        $this->huda->forceFill(['login_revoked_at' => now()])->save();
        $this->ordinaryWeek();

        $this->now('2026-10-09 15:00');
        $this->run_();
        Mail::assertNothingSent();
        $this->assertSame(0, DB::table('behavior_weeks')->count());

        // The parent's login comes back an hour later, inside the window: the next hourly run tells them.
        $this->huda->forceFill(['login_revoked_at' => null])->save();
        $this->now('2026-10-09 16:00');
        $this->run_();
        $this->assertSame(['huda@fam.test'], $this->families());
    }

    // ------------------------------------------------------------ the email

    #[Test]
    public function the_family_email_says_a_report_is_ready_and_nothing_about_the_child(): void
    {
        // Distinctive values, so a leak cannot hide inside a URL or an id.
        $this->award('2026-10-06 10:00', $this->amiraMembership, 91, BehaviorSkill::POLARITY_POSITIVE, 'Zzyzx Persistence', 'a private note about Amira');
        $this->award('2026-10-07 10:00', $this->amiraMembership, 73, BehaviorSkill::POLARITY_NEGATIVE, 'Qwxv Disruption');

        $this->now('2026-10-09 15:00');
        $this->run_();

        Mail::assertSent(WeeklyPointsReportMail::class, function (WeeklyPointsReportMail $mail) {
            if ($mail->audience !== WeeklyPointsReportMail::AUDIENCE_FAMILY) {
                return false;
            }

            // The school's own name carries a random suffix in this fixture; take it out so a
            // number cannot be "found" inside it by chance.
            $html = str_replace($mail->orgName, '', $mail->render());
            $subject = $mail->build()->subject;

            $this->assertSame('Your weekly report is ready', $subject);
            $this->assertStringContainsString("Your child's weekly report is ready", $html);
            $this->assertStringContainsString($mail->orgName, $mail->render());
            $this->assertStringContainsString('Grade 3', $html);

            foreach (['Amira', 'Tester', 'Zzyzx', 'Qwxv', 'private note', '91', '73', 'points'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $html, "the email carries '{$forbidden}'");
                $this->assertStringNotContainsString($forbidden, $subject, "the subject carries '{$forbidden}'");
            }

            return true;
        });
    }

    #[Test]
    public function the_family_link_signs_in_and_lands_on_this_classs_report_for_this_school_only(): void
    {
        $this->ordinaryWeek();
        $this->now('2026-10-09 15:00');
        $this->run_();

        $mail = collect(Mail::sent(WeeklyPointsReportMail::class))->first(fn ($m) => $m->audience === 'family');

        // The link names the week that was reported (?week=), so it still opens THAT week on Sunday.
        $expected = rtrim((string) config('app.url'), '/')."/family/{$this->masjid->id}/sign-in?next="
            .rawurlencode("/family/{$this->masjid->id}/classes/{$this->group->id}/report?week=2026-10-04");

        $this->assertSame($expected, $mail->url);
        $this->assertStringContainsString($expected, $mail->render());
    }

    #[Test]
    public function the_teachers_email_is_a_notice_with_the_teachers_own_link_and_no_numbers(): void
    {
        $this->award('2026-10-06 10:00', $this->amiraMembership, 91, BehaviorSkill::POLARITY_POSITIVE, 'Zzyzx Persistence');

        $this->now('2026-10-09 15:00');
        $this->run_();

        $mail = collect(Mail::sent(WeeklyPointsReportMail::class))->first(fn ($m) => $m->audience === 'teacher');
        $html = $mail->render();

        $this->assertSame('Your weekly class summary is ready', $mail->build()->subject);
        $this->assertSame(rtrim((string) config('app.url'), '/')."/teacher/classes/{$this->group->id}?tab=points&week=2026-10-04", $mail->url);
        $this->assertStringNotContainsString('/family/', $html, 'a teacher is not sent to the family door');

        foreach (['Amira', 'Zzyzx', '91'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html);
        }
    }

    #[Test]
    public function both_links_name_the_week_that_was_reported_even_when_the_email_goes_out_in_the_next_one(): void
    {
        // A Saturday 23:00 school: the report for the week of 2026-10-04 goes out on the Sunday after
        // midnight (a catch-up), where "this week" is already the NEXT week. A link that says nothing about
        // the week would open the new, empty one; both must name 2026-10-04.
        MasjidPointsSetting::create(['masjid_id' => $this->masjid->id, 'report_weekday' => 6, 'report_time' => '23:00']);
        $this->award('2026-10-06 10:00', $this->amiraMembership);

        $this->now('2026-10-11 01:30');
        $this->run_();

        $family = collect(Mail::sent(WeeklyPointsReportMail::class))->first(fn ($m) => $m->audience === 'family');
        $teacher = collect(Mail::sent(WeeklyPointsReportMail::class))->first(fn ($m) => $m->audience === 'teacher');

        parse_str((string) parse_url($family->url, PHP_URL_QUERY), $query);
        $this->assertSame("/family/{$this->masjid->id}/classes/{$this->group->id}/report?week=2026-10-04", $query['next']);

        parse_str((string) parse_url($teacher->url, PHP_URL_QUERY), $teacherQuery);
        $this->assertSame(['tab' => 'points', 'week' => '2026-10-04'], $teacherQuery);

        // The week the link names is the one the sweep recorded, not the one holding "now".
        $this->assertSame(['2026-10-04'], DB::table('behavior_weeks')->pluck('week_start')->all());
    }

    #[Test]
    public function a_planted_web_address_for_a_name_is_not_printed_in_the_greeting(): void
    {
        $this->ordinaryWeek();
        $this->huda->forceFill(['first_name' => 'www.evil.example', 'last_name' => ''])->save();

        $this->now('2026-10-09 15:00');
        $this->run_();

        $mail = collect(Mail::sent(WeeklyPointsReportMail::class))->first(fn ($m) => $m->audience === 'family');

        $this->assertStringNotContainsString('evil.example', $mail->render());
    }

    #[Test]
    public function one_dead_address_does_not_stop_the_rest_and_the_failure_is_logged_where_production_keeps_it(): void
    {
        $this->ordinaryWeek();
        $this->award('2026-10-06 11:00', $this->yusufMembership);
        // The real (array) mailer instead of the fake, so an exception can be thrown from inside send().
        app()->forgetInstance('mail.manager');
        \Illuminate\Support\Facades\Facade::clearResolvedInstance('mail.manager');
        Event::listen(\Illuminate\Mail\Events\MessageSending::class, function ($event) {
            foreach ($event->message->getTo() as $to) {
                if ($to->getAddress() === 'huda@fam.test') {
                    throw new \RuntimeException('smtp said no');
                }
            }
        });
        Log::spy();
        Log::shouldReceive('channel')->with('monitors')->andReturn(Mockery::spy(LoggerInterface::class));

        $this->now('2026-10-09 15:00');
        $this->run_();

        $delivered = collect(app('mail.manager')->mailer('array')->getSymfonyTransport()->messages())
            ->flatMap(fn ($m) => collect($m->getEnvelope()->getRecipients())->map(fn ($a) => $a->getAddress()))->all();

        $this->assertContains('sara@fam.test', $delivered);
        $this->assertContains('teacher@school.test', $delivered);
        $this->assertNotContains('huda@fam.test', $delivered);
        Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains((string) $m, 'huda@fam.test'))->atLeast()->once();
        $this->assertSame(1, (int) DB::table('behavior_weeks')->value('recipients_count'), 'only the delivered family is counted');
        $this->assertSame(1, $this->claimed(), 'a PARTIAL send keeps its claim: a retry would tell the delivered families twice');
    }

    /** Swap the fake for the real array mailer, whose send() can be made to throw while `$down` is true. */
    private function mailTransportThatCanGoDown(bool &$down): void
    {
        app()->forgetInstance('mail.manager');
        \Illuminate\Support\Facades\Facade::clearResolvedInstance('mail.manager');
        Event::listen(\Illuminate\Mail\Events\MessageSending::class, function () use (&$down) {
            if ($down) {
                throw new \RuntimeException('smtp is down');
            }
        });
    }

    /** @return list<string> */
    private function deliveredAddresses(): array
    {
        return collect(app('mail.manager')->mailer('array')->getSymfonyTransport()->messages())
            ->flatMap(fn ($m) => collect($m->getEnvelope()->getRecipients())->map(fn ($a) => $a->getAddress()))
            ->sort()->values()->all();
    }

    #[Test]
    public function a_class_whose_every_email_failed_is_not_claimed_and_is_retried_by_the_next_run(): void
    {
        $this->ordinaryWeek();
        $down = true;
        $this->mailTransportThatCanGoDown($down);
        Log::spy();
        $channel = Mockery::spy(LoggerInterface::class);
        Log::shouldReceive('channel')->with('monitors')->andReturn($channel);

        // The transport is down at the 15:00 sweep: the family and the teacher both fail.
        $this->now('2026-10-09 15:00');
        $out = $this->run_();

        $this->assertSame([], $this->deliveredAddresses());
        $this->assertSame(0, $this->claimed(), 'nobody was told, so the week is not marked as sent');
        $this->assertNull(DB::table('behavior_weeks')->value('recipients_count'));
        $this->assertStringContainsString('0 class(es) sent', $out);
        $this->assertStringContainsString('1 undelivered', $out);
        $channel->shouldHaveReceived('info')->withArgs(fn ($m, $ctx) => $m === 'points:weekly-report'
            && $ctx['classes_sent'] === 0 && $ctx['classes_undelivered'] === 1 && $ctx['failures'] === 2);

        // The transport comes back an hour later, inside the catch-up window: the next run tells them.
        $down = false;
        $this->now('2026-10-09 16:00');
        $out = $this->run_();

        $this->assertSame(['huda@fam.test', 'teacher@school.test'], $this->deliveredAddresses());
        $this->assertSame(1, $this->claimed());
        $this->assertSame(1, (int) DB::table('behavior_weeks')->value('recipients_count'));
        $this->assertStringContainsString('1 class(es) sent', $out);

        // ...and once told, never again.
        $this->now('2026-10-09 17:00');
        $this->run_();
        $this->assertSame(['huda@fam.test', 'teacher@school.test'], $this->deliveredAddresses());
    }

    #[Test]
    public function a_total_outage_is_still_bounded_by_the_catch_up_window(): void
    {
        $this->ordinaryWeek();
        $down = true;
        $this->mailTransportThatCanGoDown($down);

        $this->now('2026-10-09 15:00');
        $this->run_();
        $this->now('2026-10-10 02:00');
        $this->run_();
        $this->assertSame(0, $this->claimed());

        // Back up only after the window has closed: the report does not go out a day late.
        $down = false;
        $this->now('2026-10-10 03:00:00');
        $this->run_();

        $this->assertSame([], $this->deliveredAddresses());
    }

    #[Test]
    public function every_run_leaves_one_line_on_the_monitors_channel_not_the_default_one(): void
    {
        Log::spy();
        $channel = Mockery::spy(LoggerInterface::class);
        Log::shouldReceive('channel')->with('monitors')->once()->andReturn($channel);

        $this->ordinaryWeek();
        $this->now('2026-10-09 15:00');
        $this->run_();

        Log::shouldNotHaveReceived('info');
        $channel->shouldHaveReceived('info')->once()->withArgs(fn ($m, $ctx) => $m === 'points:weekly-report'
            && $ctx['classes_sent'] === 1 && $ctx['family_emails'] === 1 && $ctx['teacher_emails'] === 1 && $ctx['dry_run'] === false);

        $this->assertArrayHasKey('monitors', config('logging.channels'));
    }

    // -------------------------------------------------------- the school calendar

    /** A weekly school that meets Sundays, with a closure on Sunday 2026-10-11. */
    private function sundaySchoolWithClosure(): void
    {
        MasjidPointsSetting::create(['masjid_id' => $this->masjid->id, 'report_weekday' => 0, 'report_time' => '18:00']);

        $year = SchoolYear::create([
            'masjid_id' => $this->masjid->id, 'label' => '2026-27',
            'first_day' => '2026-09-06', 'last_day' => '2027-06-13',
        ]);
        SchoolClosure::create([
            'masjid_id' => $this->masjid->id, 'school_year_id' => $year->id,
            'closed_on' => '2026-10-11', 'reason' => 'Mid-term break',
        ]);
    }

    #[Test]
    public function a_week_the_calendar_marks_closed_is_skipped_and_the_next_open_one_is_sent(): void
    {
        $this->sundaySchoolWithClosure();
        $this->award('2026-10-11 10:00', $this->amiraMembership);   // an award on a closed Sunday: the week is skipped anyway
        $this->award('2026-10-18 10:00', $this->amiraMembership);

        $this->now('2026-10-11 18:00');
        $this->run_();
        Mail::assertNothingSent();
        $this->assertSame(0, DB::table('behavior_weeks')->count());

        $this->now('2026-10-18 18:00');
        $this->run_();
        $this->assertSame(['huda@fam.test'], $this->families());
    }

    #[Test]
    public function a_closure_on_any_day_of_the_week_skips_it_and_one_just_outside_it_does_not(): void
    {
        // The Friday school's week is Sunday 2026-10-04 to Saturday 2026-10-10, sent on Friday the 9th.
        $year = SchoolYear::create([
            'masjid_id' => $this->masjid->id, 'label' => '2026-27',
            'first_day' => '2026-09-06', 'last_day' => '2027-06-13',
        ]);
        $this->ordinaryWeek();

        foreach ([
            // [closed on, is the week skipped?, where in the week that is]
            ['2026-10-04', true, 'the first day'],
            ['2026-10-07', true, 'a middle day, not the send day'],
            ['2026-10-09', true, 'the send day'],
            ['2026-10-10', true, 'the last day, after the send moment'],
            ['2026-10-03', false, 'the day before the week'],
            ['2026-10-11', false, 'the day after the week'],
        ] as [$closed, $skipped, $where]) {
            Mail::fake();
            DB::table('behavior_weeks')->delete();
            SchoolClosure::withoutMasjidScope()->delete();
            SchoolClosure::create([
                'masjid_id' => $this->masjid->id, 'school_year_id' => $year->id,
                'closed_on' => $closed, 'reason' => 'Closed',
            ]);

            $this->now('2026-10-09 15:00');
            $this->run_();

            $this->assertSame($skipped ? [] : ['huda@fam.test'], $this->families(), "closure on {$where} ({$closed})");
        }
    }

    #[Test]
    public function a_retired_class_is_not_told_about_and_claims_nothing(): void
    {
        $this->ordinaryWeek();
        // Retiring a class means is_active = false (groups.md); its last awards must not produce a notice.
        DB::table('groups')->where('id', $this->group->id)->update(['is_active' => false]);

        $this->now('2026-10-09 15:00');
        $this->run_();

        Mail::assertNothingSent();
        $this->assertSame(0, DB::table('behavior_weeks')->count());

        // Brought back, it is reported again.
        DB::table('groups')->where('id', $this->group->id)->update(['is_active' => true]);
        $this->now('2026-10-09 16:00');
        $this->run_();
        $this->assertSame(['huda@fam.test'], $this->families());
    }

    #[Test]
    public function a_school_with_no_calendar_is_never_read_as_closed(): void
    {
        $this->assertSame(0, SchoolYear::withoutMasjidScope()->where('masjid_id', $this->masjid->id)->count());
        $this->ordinaryWeek();

        $this->now('2026-10-09 15:00');
        $this->run_();

        $this->assertSame(['huda@fam.test'], $this->families());
    }

    // ---------------------------------------------------------------- tenancy

    #[Test]
    public function one_schools_families_are_never_told_about_another_schools_week(): void
    {
        $other = $this->school('Neighbour School', true);
        $otherGroup = Group::factory()->create(['masjid_id' => $other->id, 'kind' => Group::KIND_CLASS, 'name' => 'Neighbour class']);
        $this->staffTeacher($other, $otherGroup, 'teacher@neighbour.test');
        $zaid = $this->child('Zaid', $other);
        $zaidMembership = $this->participant($zaid, $otherGroup);
        $this->guardianOf($zaid, 'zaid-parent@neighbour.test', ['group' => $otherGroup]);
        $this->award('2026-10-06 10:00', $zaidMembership);
        $this->ordinaryWeek();

        $this->now('2026-10-09 15:00');
        $this->run_();

        $this->assertSame(['huda@fam.test', 'zaid-parent@neighbour.test'], $this->families());

        // Each notice names its own school and links to its own school's door.
        foreach (Mail::sent(WeeklyPointsReportMail::class) as $mail) {
            if (collect($mail->to)->pluck('address')->contains('zaid-parent@neighbour.test')) {
                $this->assertStringContainsString("/family/{$other->id}/sign-in", $mail->url);
                $this->assertStringNotContainsString("/family/{$this->masjid->id}/", $mail->url);
                $this->assertSame($other->name, $mail->orgName);
            }
        }
        $this->assertSame(2, DB::table('behavior_weeks')->count());
    }

    #[Test]
    public function a_school_clock_of_its_own_moves_its_moment(): void
    {
        // Los Angeles is three hours behind: Friday 15:00 there is 22:00Z, not 19:00Z.
        $la = $this->school('West Coast School', true, 'America/Los_Angeles');
        $laGroup = Group::factory()->create(['masjid_id' => $la->id, 'kind' => Group::KIND_CLASS, 'name' => 'LA class']);
        $kid = $this->child('Layla', $la);
        $membership = $this->participant($kid, $laGroup);
        $this->guardianOf($kid, 'layla-parent@west.test', ['group' => $laGroup]);
        BehaviorAward::factory()->create([
            'masjid_id' => $la->id, 'group_id' => $laGroup->id, 'group_membership_id' => $membership->id,
            'skill_polarity' => 'positive', 'points' => 2, 'awarded_at' => CarbonImmutable::parse('2026-10-06 10:00', 'America/Los_Angeles')->utc(),
        ]);
        $this->ordinaryWeek();

        $this->nowUtc('2026-10-09 19:00:00');   // 15:00 in New York, 12:00 in Los Angeles
        $this->run_();
        $this->assertSame(['huda@fam.test'], $this->families());

        $this->nowUtc('2026-10-09 22:00:00');   // 15:00 in Los Angeles
        $this->run_();
        $this->assertSame(['huda@fam.test', 'layla-parent@west.test'], $this->families());
    }

    #[Test]
    public function the_new_tenant_models_are_invisible_across_schools(): void
    {
        $other = Masjid::create([
            'name' => 'Isolation Other School '.uniqid(), 'email' => 'iso-'.uniqid().'@school.test',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '2 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'crm_enabled' => true, 'org_type' => 'school',
        ]);
        BehaviorWeek::claim($this->masjid->id, $this->group->id, '2026-10-04');
        MasjidPointsSetting::create(['masjid_id' => $this->masjid->id, 'report_weekday' => 0, 'report_time' => '18:00']);
        $week = BehaviorWeek::withoutMasjidScope()->firstOrFail();
        $setting = MasjidPointsSetting::withoutMasjidScope()->firstOrFail();

        // Bound to ANOTHER school, this school's rows do not exist: a find is a miss and a listing is empty.
        app(TenantContext::class)->set($other->id);

        $this->assertNull(BehaviorWeek::find($week->id));
        $this->assertNull(MasjidPointsSetting::find($setting->id));
        $this->assertCount(0, BehaviorWeek::query()->get());
        $this->assertCount(0, MasjidPointsSetting::query()->get());
        $this->assertSame(0, BehaviorWeek::query()->where('group_id', $this->group->id)->count());

        // And a row created while bound is stamped with the bound school, whatever the caller says.
        $created = BehaviorWeek::create(['masjid_id' => $this->masjid->id, 'group_id' => $this->group->id, 'week_start' => '2026-10-11']);
        $this->assertSame($other->id, (int) $created->masjid_id);
        app(TenantContext::class)->forgetTenant();
    }

    // ------------------------------------------------- the SuperAdmin schedule

    private function actAsSuper(): void
    {
        Auth::forgetGuards();
        app(TenantContext::class)->forgetTenant();
        Sanctum::actingAs(User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+1'.random_int(1000000000, 9999999999)]));
    }

    private function scheduleUrl(?Masjid $masjid = null): string
    {
        return '/api/admin/masjids/'.($masjid ?? $this->masjid)->id.'/points-report-schedule';
    }

    #[Test]
    public function a_super_admin_reads_the_schedule_and_where_each_half_comes_from(): void
    {
        $this->actAsSuper();

        $this->getJson($this->scheduleUrl())->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.weekday', 5)
            ->assertJsonPath('data.weekday_name', 'Friday')
            ->assertJsonPath('data.time', '15:00')
            ->assertJsonPath('data.weekday_source', 'default')
            ->assertJsonPath('data.time_source', 'default')
            ->assertJsonPath('data.timezone', self::ZONE);

        $this->masjid->forceFill(['capability_overrides' => null])->save();
        $this->getJson($this->scheduleUrl())->assertOk()->assertJsonPath('data.enabled', false);
    }

    #[Test]
    public function a_super_admin_sets_either_half_or_both_and_null_puts_a_half_back_on_the_default(): void
    {
        $this->actAsSuper();
        Log::spy();

        $this->putJson($this->scheduleUrl(), ['report_weekday' => 0, 'report_time' => '18:00'])->assertOk()
            ->assertJsonPath('data.weekday', 0)->assertJsonPath('data.weekday_name', 'Sunday')
            ->assertJsonPath('data.time', '18:00')->assertJsonPath('data.weekday_source', 'set');
        $this->assertSame(1, MasjidPointsSetting::withoutMasjidScope()->where('masjid_id', $this->masjid->id)->count());

        // Only the time is sent: the weekday keeps what it was.
        $this->putJson($this->scheduleUrl(), ['report_time' => '17:15'])->assertOk()
            ->assertJsonPath('data.weekday', 0)->assertJsonPath('data.time', '17:15');

        // null = default, for that half only.
        $this->putJson($this->scheduleUrl(), ['report_weekday' => null])->assertOk()
            ->assertJsonPath('data.weekday', 5)->assertJsonPath('data.weekday_source', 'default')
            ->assertJsonPath('data.time', '17:15');

        $this->assertSame(1, MasjidPointsSetting::withoutMasjidScope()->where('masjid_id', $this->masjid->id)->count(), 'still one row per school');
        Log::shouldHaveReceived('warning')->withArgs(fn ($m) => $m === 'Weekly points report schedule changed')->times(3);
    }

    #[Test]
    public function the_schedule_takes_the_form_encoded_digits_the_spa_sends(): void
    {
        $this->actAsSuper();

        $this->withHeaders(['Accept' => 'application/json'])
            ->put($this->scheduleUrl(), ['report_weekday' => '0', 'report_time' => '18:00'])
            ->assertOk()->assertJsonPath('data.weekday', 0);
    }

    #[Test]
    public function an_unusable_weekday_or_time_is_refused_and_changes_nothing(): void
    {
        $this->actAsSuper();

        foreach ([
            ['report_weekday' => 7], ['report_weekday' => -1], ['report_weekday' => 'friday'], ['report_weekday' => 1.5],
            ['report_weekday' => 10], ['report_weekday' => true],
            ['report_time' => '25:00'], ['report_time' => '24:00'], ['report_time' => '24:30'], ['report_time' => '23:60'], ['report_time' => '9:00'], ['report_time' => '15:00:00'], ['report_time' => '15:60'],
            ['report_time' => 'noon'], ['report_time' => 1500], ['report_time' => ['15:00']],
            [],
        ] as $body) {
            $this->putJson($this->scheduleUrl(), $body)->assertStatus(422);
        }

        $this->assertSame(0, MasjidPointsSetting::withoutMasjidScope()->count());
    }

    #[Test]
    public function only_a_super_admin_may_read_or_change_when_a_school_is_emailed(): void
    {
        $admin = User::factory()->create(['type' => 'MasjidAdmin', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
        MasjidUser::create(['masjid_id' => $this->masjid->id, 'user_id' => $admin->id, 'role' => 'masjid-admin', 'is_default' => true]);

        // A masjid's own administrator is refused with a 403; a teacher never gets through the admin
        // realm's own gate at all (401). Neither may read or move the schedule.
        foreach ([[$admin, 403], [$this->teacher, 401]] as [$user, $status]) {
            Auth::forgetGuards();
            app(TenantContext::class)->forgetTenant();
            Sanctum::actingAs($user);

            $this->getJson($this->scheduleUrl())->assertStatus($status);
            $this->putJson($this->scheduleUrl(), ['report_time' => '18:00'])->assertStatus($status);
            // Even a body that would fail validation is refused first: no validation output for a non-super.
            $this->putJson($this->scheduleUrl(), ['report_time' => 'nonsense'])->assertStatus($status)->assertJsonMissingPath('data.report_time');
        }

        $this->assertSame(0, MasjidPointsSetting::withoutMasjidScope()->count());
    }

    #[Test]
    public function an_unknown_school_is_a_404_for_a_super_admin(): void
    {
        $this->actAsSuper();

        $this->getJson('/api/admin/masjids/999999/points-report-schedule')->assertNotFound();
        $this->putJson('/api/admin/masjids/999999/points-report-schedule', ['report_time' => '18:00'])->assertNotFound();
    }

    // ------------------------------------------------------ schema and the sweep

    #[Test]
    public function the_new_tables_have_short_index_names_and_the_intended_types(): void
    {
        foreach (['behavior_weeks', 'masjid_points_settings'] as $table) {
            foreach (Schema::getIndexes($table) as $index) {
                $this->assertLessThanOrEqual(64, strlen($index['name']), "{$table}.{$index['name']} is over MySQL's 64-character limit");
            }
        }

        $this->assertSame('date', Schema::getColumnType('behavior_weeks', 'week_start'));
        $this->assertSame('datetime', Schema::getColumnType('behavior_weeks', 'report_sent_at'));

        // SQLite ignores declared widths, so the intent is read off the migrations themselves.
        $weeks = (string) file_get_contents(base_path('database/migrations/2026_10_02_110000_create_behavior_weeks_table.php'));
        $this->assertStringContainsString("dateTime('report_sent_at')", $weeks);
        $this->assertStringNotContainsString("timestamp('report_sent_at')", $weeks, 'domain times are datetime(), not timestamp()');

        $settings = (string) file_get_contents(base_path('database/migrations/2026_10_02_120000_create_masjid_points_settings_table.php'));
        $this->assertStringContainsString("char('report_time', 5)", $settings);
        $this->assertStringContainsString("unsignedTinyInteger('report_weekday')", $settings);

        $uniques = collect(Schema::getIndexes('behavior_weeks'))->where('unique', true)->pluck('columns')->all();
        $this->assertContains(['group_id', 'week_start'], $uniques);
    }

    #[Test]
    public function the_sweep_is_scheduled_hourly_and_cannot_overlap_itself(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'points:weekly-report'));

        $this->assertNotNull($event, 'points:weekly-report is not on the schedule');
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping, 'an overlapping sweep is what the claim exists to survive, not to invite');
    }

    #[Test]
    public function the_biss_schedule_migration_gives_the_sunday_school_sunday_evening_and_touches_nothing_else(): void
    {
        $migration = require base_path('database/migrations/2026_10_02_130000_seed_points_report_schedule_for_biss.php');

        // Not organisation 18: nothing is written.
        $migration->up();
        $this->assertSame(0, MasjidPointsSetting::withoutMasjidScope()->count());

        // Organisation 18 that is some other school: nothing is written either.
        Masjid::forceCreate([
            'id' => 18, 'name' => 'Some Other School', 'email' => 'x@y.test', 'phone' => '+15550000018',
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St', 'latitude' => 0.0, 'longitude' => 0.0,
            'crm_enabled' => true, 'org_type' => 'school',
        ]);
        $migration->up();
        $this->assertSame(0, MasjidPointsSetting::withoutMasjidScope()->count());

        // The Sunday school: one row, Sunday 18:00, and the report itself stays OFF.
        DB::table('masjids')->where('id', 18)->update(['name' => 'Burlington Islamic Sunday School']);
        $migration->up();
        $row = MasjidPointsSetting::withoutMasjidScope()->firstOrFail();
        $this->assertSame([18, 0, '18:00'], [(int) $row->masjid_id, (int) $row->report_weekday, $row->report_time]);
        $this->assertFalse(Masjid::withoutGlobalScopes()->find(18)->hasCapability('points_weekly_report'), 'inert: the grant is still off');

        // Idempotent, and a SuperAdmin's own value is left alone.
        $migration->up();
        $this->assertSame(1, MasjidPointsSetting::withoutMasjidScope()->count());
        $row->forceFill(['report_time' => '19:30'])->save();
        $migration->up();
        $this->assertSame('19:30', $row->fresh()->report_time);

        // down() takes back only what up() wrote.
        $migration->down();
        $this->assertSame(1, MasjidPointsSetting::withoutMasjidScope()->count(), 'a changed row is not ours to remove');
        $row->forceFill(['report_time' => '18:00'])->save();
        $migration->down();
        $this->assertSame(0, MasjidPointsSetting::withoutMasjidScope()->count());
    }

    #[Test]
    public function the_biss_schedule_migration_refuses_a_non_school_and_a_deleted_organisation_even_with_the_right_name(): void
    {
        $migration = require base_path('database/migrations/2026_10_02_130000_seed_points_report_schedule_for_biss.php');

        // Organisation 18 has the Sunday school's NAME but is a masjid: the org_type guard stops it.
        Masjid::forceCreate([
            'id' => 18, 'name' => 'Burlington Islamic Sunday School', 'email' => 'x@y.test', 'phone' => '+15550000018',
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St', 'latitude' => 0.0, 'longitude' => 0.0,
            'crm_enabled' => true, 'org_type' => 'masjid',
        ]);
        $migration->up();
        $this->assertSame(0, MasjidPointsSetting::withoutMasjidScope()->count(), 'a masjid is not the Sunday school');

        // A school with the right name that has been soft-deleted: the deleted_at guard stops it.
        DB::table('masjids')->where('id', 18)->update(['org_type' => 'school', 'deleted_at' => now()]);
        $migration->up();
        $this->assertSame(0, MasjidPointsSetting::withoutMasjidScope()->count(), 'a deleted organisation gets no row');

        // Live and a school: only now is the row written (the two guards were what held it back).
        DB::table('masjids')->where('id', 18)->update(['deleted_at' => null]);
        $migration->up();
        $this->assertSame(1, MasjidPointsSetting::withoutMasjidScope()->count());
    }
}
