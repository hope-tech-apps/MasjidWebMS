<?php

namespace Tests\Feature;

use App\Models\BehaviorSkill;
use App\Models\BehaviorWeek;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\MasjidPointsSetting;
use App\Models\PrizeLedgerEntry;
use App\Models\SchoolYear;
use App\Support\ClassStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Console\Scheduling\Schedule;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\Support\BuildsClassStoreFixture;
use Tests\TestCase;

/**
 * T-003.4 (W6): how a week of points becomes Manara Bucks, and how bucks end.
 *
 * `bucks:mint` runs hourly for a school that has the store on. The rules under test (owner
 * B6, R1 and R2): POSITIVE points only, one `earned` row per child and week, a two-week window
 * of late changes as `adjusted` deltas that never take a balance below zero, nothing
 * retroactive by surprise, negatives never take bucks. `bucks:expire` writes a balance off at
 * the end of the class or the school year.
 *
 * Al-Razi's clock is America/New_York. 2026-10-04 is a Sunday, EDT until 2026-11-01. Unless a
 * test says otherwise "now" is Monday 2026-10-12 09:00: the week Oct 4 to Oct 10 is closed and
 * Oct 11 to Oct 17 is in progress.
 */
class ClassStoreMintingTest extends TestCase
{
    use BuildsClassStoreFixture;
    use RefreshDatabase;

    private const WEEK1 = '2026-10-04';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStoreSchools();
        $this->storeOn();
        $this->freeze('2026-10-12 09:00');
        $this->startFrom(self::WEEK1);
    }

    protected function tearDown(): void
    {
        $this->thaw();

        parent::tearDown();
    }

    /** `bucks_from` as a SuperAdmin would have set it, so history counts. */
    private function startFrom(?string $day, ?int $rate = null): void
    {
        MasjidPointsSetting::withoutMasjidScope()->updateOrCreate(
            ['masjid_id' => $this->school->id],
            array_filter(['bucks_from' => $day, 'points_per_buck' => $rate], fn ($v) => $v !== null) ?: ['bucks_from' => null],
        );
    }

    private function mint(array $args = []): string
    {
        $this->assertSame(0, Artisan::call('bucks:mint', $args));

        return Artisan::output();
    }

    private function expire(array $args = []): string
    {
        $this->assertSame(0, Artisan::call('bucks:expire', $args));

        return Artisan::output();
    }

    /** @return \Illuminate\Support\Collection<int,PrizeLedgerEntry> */
    private function ledger(?GroupMembership $m = null)
    {
        return PrizeLedgerEntry::query()
            ->when($m !== null, fn ($q) => $q->where('group_membership_id', $m->id))
            ->orderBy('id')->get();
    }

    private function week1Points(): void
    {
        // Amira: 3 + 2 positive. A NEGATIVE-polarity award (stored as a magnitude) and a positive skill
        // docked with a negative override: neither takes bucks and neither mints.
        $this->awardAt('2026-10-05 10:00', $this->amira, 3);
        $this->awardAt('2026-10-07 10:00', $this->amira, 2);
        $this->awardAt('2026-10-08 10:00', $this->amira, 1, BehaviorSkill::POLARITY_NEGATIVE);
        $this->awardAt('2026-10-08 11:00', $this->amira, -4);
        // Yusuf: 2 positive, and a negative that is not deducted.
        $this->awardAt('2026-10-06 10:00', $this->yusuf, 2);
        $this->awardAt('2026-10-06 11:00', $this->yusuf, 3, BehaviorSkill::POLARITY_NEGATIVE);
    }

    // ------------------------------------------------------------- the switch

    #[Test]
    public function a_school_without_the_store_mints_nothing_however_many_points_it_has(): void
    {
        $this->storeOn(null, false);
        $this->week1Points();

        $out = $this->mint();

        $this->assertStringContainsString('1 organisation(s)', $out);
        $this->assertSame(0, PrizeLedgerEntry::query()->count());
        $this->assertSame(0, DB::table('behavior_weeks')->count(), 'and touches no week either');
    }

    #[Test]
    public function switching_the_store_on_pays_out_no_history_nobody_expected(): void
    {
        $this->startFrom(null);
        DB::table('masjid_points_settings')->where('masjid_id', $this->school->id)->update(['bucks_from' => null]);
        $this->week1Points();

        $this->mint();

        // The first run found no start day and set it to the week in progress (Oct 11), so the
        // closed week of Oct 4 to 10 is not credited retroactively.
        $this->assertSame('2026-10-11', ClassStoreSettingsRead::bucksFrom($this->school->id));
        $this->assertSame(0, PrizeLedgerEntry::query()->count());

        // The week that was in progress closes, and THEN it mints.
        $this->awardAt('2026-10-13 10:00', $this->amira, 4);
        $this->freeze('2026-10-19 09:00');
        $this->mint();

        $this->assertSame([4], $this->ledger($this->amira)->pluck('amount')->all());
        $this->assertSame('2026-10-11', $this->ledger($this->amira)->first()->week_start);
    }

    // ------------------------------------------------------------- what mints

    #[Test]
    public function a_closed_week_mints_positive_points_only_once_per_child(): void
    {
        $this->week1Points();

        $this->mint();

        $amira = $this->ledger($this->amira);
        $this->assertCount(1, $amira);
        $this->assertSame(PrizeLedgerEntry::KIND_EARNED, $amira[0]->kind);
        $this->assertSame(5, $amira[0]->amount, '3 + 2 positive points; the negative and the docking mint and take nothing');
        $this->assertSame(5, $amira[0]->week_basis);
        $this->assertSame(self::WEEK1, $amira[0]->week_start);
        $this->assertSame('earned:'.$this->amira->id.':'.self::WEEK1, $amira[0]->dedupe_key);
        $this->assertSame($this->school->id, $amira[0]->masjid_id);
        $this->assertSame($this->class->id, $amira[0]->group_id);
        $this->assertNotNull($amira[0]->retained_until, 'stamped from its own date');
        $this->assertSame([2], $this->ledger($this->yusuf)->pluck('amount')->all(), 'a negative never takes bucks away (R2)');
        $this->assertNull($amira[0]->created_by_user_id, 'minted by the system, not a person');
    }

    #[Test]
    public function running_it_again_and_again_changes_nothing(): void
    {
        $this->week1Points();

        $this->mint();
        $first = $this->ledger()->pluck('id')->all();
        $this->mint();
        $this->mint(['--masjid' => $this->school->id]);

        $this->assertSame($first, $this->ledger()->pluck('id')->all(), 'the same rows, no new ones');
        $this->assertSame(5, $this->balanceOf($this->amira));
        $this->assertSame(2, $this->balanceOf($this->yusuf));
    }

    #[Test]
    public function a_week_of_only_negatives_mints_nothing_and_takes_nothing(): void
    {
        $this->credit($this->amira, 8);
        $this->awardAt('2026-10-05 10:00', $this->amira, 5, BehaviorSkill::POLARITY_NEGATIVE);
        $this->awardAt('2026-10-06 10:00', $this->amira, 5, BehaviorSkill::POLARITY_NEGATIVE);

        $this->mint();

        $this->assertSame(8, $this->balanceOf($this->amira));
        $this->assertSame(1, PrizeLedgerEntry::query()->count(), 'only the credit the test made');
    }

    #[Test]
    public function the_rate_is_points_per_buck_and_a_week_mints_whole_bucks(): void
    {
        $this->startFrom(self::WEEK1, 2);
        $this->awardAt('2026-10-05 10:00', $this->amira, 5); // floor(5 / 2) = 2
        $this->awardAt('2026-10-05 10:00', $this->yusuf, 1); // floor(1 / 2) = 0: no row at all

        $this->mint();

        $this->assertSame([2], $this->ledger($this->amira)->pluck('amount')->all());
        $this->assertSame(2, $this->ledger($this->amira)->first()->week_basis);
        $this->assertSame([], $this->ledger($this->yusuf)->pluck('amount')->all());
    }

    #[Test]
    public function a_week_runs_sunday_midnight_to_sunday_midnight_on_the_schools_clock(): void
    {
        // Saturday 23:59 belongs to the closed week; Sunday 00:00 belongs to the one in progress.
        $this->awardAt('2026-10-10 23:59', $this->amira, 2);
        $this->awardAt('2026-10-11 00:00', $this->amira, 7);
        // And Sunday 00:00 exactly STARTS the closed week.
        $this->awardAt('2026-10-04 00:00', $this->yusuf, 3);
        // The evening award that UTC calls Sunday: 21:00 EDT Saturday is 01:00Z Sunday.
        $this->awardAt('2026-10-10 21:00', $this->yusuf, 4);

        $this->mint();

        $this->assertSame([2], $this->ledger($this->amira)->pluck('amount')->all());
        $this->assertSame([7], $this->ledger($this->yusuf)->pluck('amount')->all());
    }

    #[Test]
    public function the_daylight_saving_week_still_holds_saturday_night_and_not_sunday_morning(): void
    {
        // Clocks go back 2026-11-01. The week of Nov 1 is 169 hours: it ends Sunday 2026-11-08 00:00 EST.
        $this->startFrom('2026-11-01');
        $this->freeze('2026-11-09 09:00');
        $this->awardAt('2026-11-07 23:30', $this->amira, 3);  // Saturday night EST: in
        $this->awardAt('2026-11-08 00:00', $this->amira, 9);  // Sunday 00:00 EST: the next week
        $this->awardAt('2026-11-01 00:30', $this->amira, 1);  // Sunday 00:30 EDT: the first hour of the week

        $this->mint();

        $rows = $this->ledger($this->amira)->keyBy(fn ($r) => $r->week_start);
        $this->assertSame(4, $rows['2026-11-01']->amount);
        $this->assertCount(1, $rows, 'the 9 points are in the week still in progress and mint only when it closes');
    }

    #[Test]
    public function a_revoked_award_is_not_live_so_it_does_not_mint(): void
    {
        $award = $this->awardAt('2026-10-05 10:00', $this->amira, 6);
        $this->awardAt('2026-10-06 10:00', $this->amira, 1);
        $award->delete();

        $this->mint();

        $this->assertSame([1], $this->ledger($this->amira)->pluck('amount')->all());
    }

    #[Test]
    public function a_child_who_has_left_the_class_is_not_minted_for_and_an_inactive_class_is_skipped(): void
    {
        $this->awardAt('2026-10-05 10:00', $this->amira, 4);
        $this->awardAt('2026-10-05 10:00', $this->yusuf, 4);
        $this->yusuf->forceFill(['left_on' => '2026-10-09'])->save();

        $other = Group::factory()->create(['masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS, 'name' => 'Dormant', 'is_active' => false]);
        $kid = $this->enrol($this->school, $other, Contact::factory()->create(['masjid_id' => $this->school->id, 'email' => null]));
        $this->awardAt('2026-10-05 10:00', $kid, 9, BehaviorSkill::POLARITY_POSITIVE, $other);

        $this->mint();

        $this->assertSame([4], $this->ledger($this->amira)->pluck('amount')->all());
        $this->assertSame([], $this->ledger($this->yusuf)->pluck('amount')->all());
        $this->assertSame([], $this->ledger($kid)->pluck('amount')->all());
    }

    #[Test]
    public function a_school_that_did_not_switch_the_store_on_is_not_minted_for_even_beside_one_that_did(): void
    {
        $otherSchool = $this->newSchool('Neighbour');
        $group = Group::factory()->create(['masjid_id' => $otherSchool->id, 'kind' => Group::KIND_CLASS, 'name' => 'Grade 3']);
        $kid = $this->enrol($otherSchool, $group, Contact::factory()->create(['masjid_id' => $otherSchool->id, 'email' => null]));
        MasjidPointsSetting::withoutMasjidScope()->create(['masjid_id' => $otherSchool->id, 'bucks_from' => self::WEEK1]);
        $this->awardAt('2026-10-05 10:00', $kid, 9, BehaviorSkill::POLARITY_POSITIVE, $group);
        $this->awardAt('2026-10-05 10:00', $this->amira, 2);

        $this->mint();

        $this->assertSame([], $this->ledger($kid)->pluck('amount')->all());
        $this->assertSame([2], $this->ledger($this->amira)->pluck('amount')->all());
    }

    // -------------------------------------------------------- late changes

    #[Test]
    public function a_late_award_inside_the_two_week_window_is_an_adjusted_delta(): void
    {
        $this->week1Points();
        $this->mint();

        // Wednesday of the following week, the teacher enters a forgotten award for week 1.
        $this->awardAt('2026-10-09 14:00', $this->amira, 3);
        $this->mint();

        $rows = $this->ledger($this->amira);
        $this->assertSame([PrizeLedgerEntry::KIND_EARNED, PrizeLedgerEntry::KIND_ADJUSTED], $rows->pluck('kind')->all());
        $this->assertSame([5, 3], $rows->pluck('amount')->all());
        $this->assertSame([5, 8], $rows->pluck('week_basis')->all());
        $this->assertSame('adjusted:'.$this->amira->id.':'.self::WEEK1.':1', $rows[1]->dedupe_key);
        $this->assertSame(8, $this->balanceOf($this->amira));

        // A second run finds nothing more to do.
        $this->mint();
        $this->assertCount(2, $this->ledger($this->amira));

        // Another late change is the NEXT adjustment, with its own key.
        $this->awardAt('2026-10-09 15:00', $this->amira, 1);
        $this->mint();
        $this->assertSame('adjusted:'.$this->amira->id.':'.self::WEEK1.':2', $this->ledger($this->amira)->last()->dedupe_key);
        $this->assertSame(9, $this->balanceOf($this->amira));
    }

    #[Test]
    public function a_revoked_award_takes_its_bucks_back_as_an_adjusted_delta(): void
    {
        $first = $this->awardAt('2026-10-05 10:00', $this->amira, 4);
        $this->awardAt('2026-10-06 10:00', $this->amira, 2);
        $this->mint();
        $this->assertSame(6, $this->balanceOf($this->amira));

        $first->delete();
        $this->mint();

        $this->assertSame(2, $this->balanceOf($this->amira));
        $this->assertSame(-4, $this->ledger($this->amira)->last()->amount);
        $this->assertSame(PrizeLedgerEntry::KIND_ADJUSTED, $this->ledger($this->amira)->last()->kind);
    }

    #[Test]
    public function a_clawback_never_takes_a_balance_below_zero_and_is_never_collected_later(): void
    {
        $award = $this->awardAt('2026-10-05 10:00', $this->amira, 5);
        $this->mint();
        // She spends all five.
        ClassStore::redeem($this->class, $this->amira, $this->prize(['cost_bucks' => 5]), $this->teacher);
        $this->assertSame(0, $this->balanceOf($this->amira));

        // The award is revoked: the delta would be -5, but she holds nothing, so it is clamped to 0
        // and the basis moves to 0: the shortfall is forgiven, once.
        $award->delete();
        $this->mint();
        $last = $this->ledger($this->amira)->last();
        $this->assertSame(PrizeLedgerEntry::KIND_ADJUSTED, $last->kind);
        $this->assertSame(0, $last->amount);
        $this->assertSame(0, $last->week_basis);
        $this->assertSame(0, $this->balanceOf($this->amira));

        // Next week she earns 4. The old shortfall is not taken out of it.
        $this->awardAt('2026-10-13 10:00', $this->amira, 4);
        $this->freeze('2026-10-19 09:00');
        $this->mint();
        $this->mint();
        $this->assertSame(4, $this->balanceOf($this->amira), 'week 1 is not clawed back out of week 2');
    }

    #[Test]
    public function a_partial_clawback_takes_what_is_left_and_no_more(): void
    {
        $award = $this->awardAt('2026-10-05 10:00', $this->amira, 5);
        $this->mint();
        ClassStore::redeem($this->class, $this->amira, $this->prize(['cost_bucks' => 3]), $this->teacher);
        $this->assertSame(2, $this->balanceOf($this->amira));

        $award->delete();
        $this->mint();

        $this->assertSame(0, $this->balanceOf($this->amira));
        $this->assertSame(-2, $this->ledger($this->amira)->last()->amount);
    }

    #[Test]
    public function a_week_older_than_the_two_week_window_is_frozen(): void
    {
        $this->awardAt('2026-10-05 10:00', $this->amira, 4);
        $this->mint();
        $this->assertSame(4, $this->balanceOf($this->amira));

        // Three weeks on, week 1 is no longer one of the last two closed weeks.
        $this->freeze('2026-11-02 09:00');
        $this->awardAt('2026-10-06 10:00', $this->amira, 6); // a very late entry for week 1
        $this->mint();

        $this->assertSame(4, $this->balanceOf($this->amira), 'a term-old correction never reaches into a balance already spent');
        $this->assertSame(1, $this->ledger($this->amira)->count());
    }

    #[Test]
    public function missed_runs_are_caught_up_and_each_week_is_recorded_as_converted(): void
    {
        $this->awardAt('2026-10-05 10:00', $this->amira, 2);
        $this->awardAt('2026-10-12 10:00', $this->amira, 3);
        $this->awardAt('2026-10-19 10:00', $this->amira, 4);

        // Nothing ran for two weeks: one run mints all the closed weeks since bucks_from.
        $this->freeze('2026-10-27 09:00');
        $out = $this->mint();

        $weeks = $this->ledger($this->amira)->map(fn ($r) => $r->week_start.'='.$r->amount)->all();
        $this->assertSame(['2026-10-04=2', '2026-10-11=3', '2026-10-18=4'], $weeks);
        $this->assertStringContainsString('3 week(s)', $out);

        $converted = DB::table('behavior_weeks')->whereNotNull('prizes_converted_at')->orderBy('week_start')->pluck('week_start')->map(fn ($d) => substr((string) $d, 0, 10))->all();
        $this->assertSame(['2026-10-04', '2026-10-11', '2026-10-18'], $converted);
    }

    #[Test]
    public function minting_does_not_disturb_the_fridays_report_claim(): void
    {
        $this->week1Points();
        $this->mint();

        $this->assertFalse(BehaviorWeek::sent($this->class->id, self::WEEK1), 'a row made by minting is not a sent report');
        $this->assertTrue(BehaviorWeek::prizesConverted($this->class->id, self::WEEK1));

        // The report's own claim still works on the same row, and does not undo the conversion.
        $this->assertTrue(BehaviorWeek::claim($this->school->id, $this->class->id, self::WEEK1));
        $this->assertTrue(BehaviorWeek::sent($this->class->id, self::WEEK1));
        $this->assertTrue(BehaviorWeek::prizesConverted($this->class->id, self::WEEK1));
        $this->assertSame(1, DB::table('behavior_weeks')->count(), 'one row per class and week');

        // And the reverse: releasing the report's claim leaves the conversion alone.
        BehaviorWeek::release($this->class->id, self::WEEK1);
        $this->assertTrue(BehaviorWeek::prizesConverted($this->class->id, self::WEEK1));
    }

    // ------------------------------------------------------------- the run

    #[Test]
    public function a_dry_run_writes_nothing_at_all_and_says_what_it_would_do(): void
    {
        $this->week1Points();
        DB::table('masjid_points_settings')->where('masjid_id', $this->school->id)->update(['bucks_from' => null]);

        $out = $this->mint(['--dry-run' => true]);

        $this->assertStringContainsString('(dry run)', $out);
        $this->assertSame(0, PrizeLedgerEntry::query()->count());
        $this->assertSame(0, DB::table('behavior_weeks')->count());
        $this->assertNull(ClassStoreSettingsRead::bucksFrom($this->school->id), 'not even the start day is written');

        $this->startFrom(self::WEEK1);
        $out = $this->mint(['--dry-run' => true]);
        $this->assertStringContainsString('2 student(s) minted 7 buck(s)', $out);
        $this->assertSame(0, PrizeLedgerEntry::query()->count());
    }

    #[Test]
    public function every_run_leaves_one_line_on_the_monitors_channel(): void
    {
        $this->week1Points();
        Log::spy();
        $channel = Mockery::spy(LoggerInterface::class);
        Log::shouldReceive('channel')->with('monitors')->once()->andReturn($channel);

        $this->mint();

        $channel->shouldHaveReceived('info')->once()->withArgs(fn ($m, $ctx) => $m === 'bucks:mint'
            && $ctx['minted_students'] === 2 && $ctx['minted_bucks'] === 7 && $ctx['dry_run'] === false && $ctx['failures'] === 0);
    }

    #[Test]
    public function a_bad_masjid_option_is_refused_before_anything_runs(): void
    {
        $this->assertSame(2, Artisan::call('bucks:mint', ['--masjid' => 'abc']));
        $this->assertSame(2, Artisan::call('bucks:expire', ['--masjid' => '1 or 1=1']));
    }

    #[Test]
    public function both_sweeps_are_scheduled_hourly_and_cannot_overlap_themselves(): void
    {
        // Resolving the Schedule needs the console kernel bootstrapped, which is what loads
        // routes/console.php; calling any command does that.
        Artisan::call('list', ['--raw' => true]);
        $events = collect(app(Schedule::class)->events());

        foreach (['bucks:mint', 'bucks:expire'] as $command) {
            $event = $events->first(fn ($e) => str_contains((string) $e->command, $command));
            $this->assertNotNull($event, "{$command} is not scheduled");
            $this->assertTrue($event->withoutOverlapping, "{$command} may overlap itself");
            $this->assertMatchesRegularExpression('/^\d+ \* \* \* \*$/', $event->expression, "{$command} must be hourly");
        }
    }

    // --------------------------------------------------------------- expiry

    #[Test]
    public function a_class_that_has_ended_writes_its_balances_off_once(): void
    {
        $this->credit($this->amira, 9, '2026-10-04');
        $this->credit($this->yusuf, 0);
        $this->class->forceFill(['ends_on' => '2026-10-09'])->save();

        $out = $this->expire();

        $this->assertStringContainsString('1 student(s) expired 9 buck(s)', $out);
        $expired = $this->ledger($this->amira)->last();
        $this->assertSame(PrizeLedgerEntry::KIND_EXPIRED, $expired->kind);
        $this->assertSame(-9, $expired->amount);
        $this->assertSame('expired:'.$this->amira->id.':2026-10-10', $expired->dedupe_key);
        $this->assertSame(0, $this->balanceOf($this->amira));
        $this->assertSame([], $this->ledger($this->yusuf)->where('kind', PrizeLedgerEntry::KIND_EXPIRED)->all(), 'a zero balance writes nothing');

        // Once: another run, and another, write nothing.
        $this->expire();
        $this->expire();
        $this->assertSame(1, $this->ledger($this->amira)->where('kind', PrizeLedgerEntry::KIND_EXPIRED)->count());
    }

    #[Test]
    public function a_class_that_has_not_ended_or_has_no_end_date_never_expires(): void
    {
        $this->credit($this->amira, 9, '2026-10-04');

        $this->expire(); // no end date, no calendar
        $this->class->forceFill(['ends_on' => '2026-10-12'])->save(); // today: not yet past
        $this->expire();

        $this->assertSame(9, $this->balanceOf($this->amira));
        $this->assertSame(0, $this->ledger()->where('kind', PrizeLedgerEntry::KIND_EXPIRED)->count());
    }

    #[Test]
    public function the_end_of_a_school_year_expires_what_was_minted_before_it_and_keeps_what_came_after(): void
    {
        app(\App\Support\TenantContext::class)->runWithout(fn () => SchoolYear::create([
            'masjid_id' => $this->school->id, 'label' => '2025-26', 'first_day' => '2025-09-07', 'last_day' => '2026-10-10',
        ]));

        // 6 bucks from a week before the year ended; 4 bucks minted for the week that starts after it.
        $this->credit($this->amira, 6, '2026-10-04');
        $this->credit($this->amira, 4, '2026-10-11');
        // She spent 2 of it: what expires is what she still holds of the OLD year: 10 - 2 - 4.
        ClassStore::redeem($this->class, $this->amira, $this->prize(['cost_bucks' => 2]), $this->teacher);

        $this->expire();

        $this->assertSame(4, $this->balanceOf($this->amira), 'the new year keeps its own earnings');
        $this->assertSame(-4, $this->ledger($this->amira)->last()->amount);
        $this->assertSame('expired:'.$this->amira->id.':2026-10-11', $this->ledger($this->amira)->last()->dedupe_key);
    }

    #[Test]
    public function expiry_never_pushes_a_balance_below_zero_when_more_was_spent_than_the_old_year_held(): void
    {
        $this->class->forceFill(['ends_on' => '2026-10-09'])->save();
        $this->credit($this->amira, 3, '2026-10-04');
        ClassStore::redeem($this->class, $this->amira, $this->prize(['cost_bucks' => 3]), $this->teacher);

        $this->expire();

        $this->assertSame(0, $this->balanceOf($this->amira));
        $this->assertSame(0, $this->ledger()->where('kind', PrizeLedgerEntry::KIND_EXPIRED)->count());
    }

    #[Test]
    public function a_school_without_the_store_is_not_expired(): void
    {
        $this->credit($this->amira, 9, '2026-10-04');
        $this->class->forceFill(['ends_on' => '2026-10-09'])->save();
        $this->storeOn(null, false);

        $this->expire();

        $this->assertSame(9, $this->balanceOf($this->amira));
    }

    #[Test]
    public function an_expired_set_is_purged_together_and_never_leaves_a_negative_or_partial_balance(): void
    {
        config(['groups.bucks.retention_days' => 30]);
        $this->credit($this->amira, 9, '2026-10-04');
        ClassStore::redeem($this->class, $this->amira, $this->prize(['cost_bucks' => 4]), $this->teacher);
        $this->class->forceFill(['ends_on' => '2026-10-09'])->save();
        $this->expire();
        $this->assertSame(3, $this->ledger($this->amira)->count());

        // The expiry row is the newest, so it decides when the whole set goes: a day early, nothing goes.
        $this->assertSame(0, PrizeLedgerEntry::purgeDueSets(now()->addDays(29)->toDateString()));
        $this->assertSame(0, $this->balanceOf($this->amira));

        $this->assertSame(3, PrizeLedgerEntry::purgeDueSets(now()->addDays(30)->toDateString()));
        $this->assertSame(0, $this->ledger($this->amira)->count());
    }
}

/** A tiny reader, so the test asks the database and not the settings class it is checking. */
final class ClassStoreSettingsRead
{
    public static function bucksFrom(int $masjidId): ?string
    {
        $v = DB::table('masjid_points_settings')->where('masjid_id', $masjidId)->value('bucks_from');

        return $v === null ? null : substr((string) $v, 0, 10);
    }
}
