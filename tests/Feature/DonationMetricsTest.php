<?php

namespace Tests\Feature;

use App\Models\Donation;
use App\Models\Fund;
use App\Models\Masjid;
use App\Support\DonationMetrics;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The money rules behind the giving dashboard (App\Support\DonationMetrics).
 *
 * These are pinned at the support-class layer rather than through the stats
 * controller, because the controller is a thin wrapper and the Masjid Assistant
 * calls the same object — the rules, not one HTTP route, are what must not
 * regress. TenantContext is bound directly, exactly as ResolveMasjidTenant would
 * per request, so the queries run under the real global scope.
 *
 * The clock is frozen inside August 2026 so "this month" and "year to date" are
 * fixed windows instead of whatever the CI box's calendar happens to say.
 *
 * The fixture is built around the four things that are easy to break:
 *
 *   - only status='succeeded' is money; everything else is an intent, a failure
 *     or a reversal;
 *   - net_amount is null until Stripe settles (and forever, for offline gifts),
 *     so net has to fall back to charged_amount;
 *   - the month boundary is the MASJID's, not UTC's — a gift at
 *     2026-08-01T02:00:00Z is still July 31 in America/New_York;
 *   - an offline gift is dated by donated_at, so imported history lands in the
 *     month it was GIVEN, not the month it was typed in.
 *
 * Sqlite-in-memory + RefreshDatabase per the testing convention.
 */
class DonationMetricsTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $tenant;

    /** America/New_York — every expectation below is in this masjid's calendar. */
    private Masjid $masjid;

    /** A second masjid that has never taken a gift. */
    private Masjid $quietMasjid;

    private Fund $fund;

    /** Stripe gift at 2026-08-01T02:00:00Z = 2026-07-31 22:00 in New York. */
    private Donation $lateJulyGift;

    /** Stripe gift plainly inside August, local time. */
    private Donation $augustGift;

    /** Offline gift GIVEN in March 2024, TYPED IN on 2026-08-05. */
    private Donation $oldOfflineGift;

    protected function setUp(): void
    {
        parent::setUp();

        // Force sqlite-in-memory regardless of phpunit.xml.
        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        // 12:00 UTC on Aug 5 is 08:00 EDT the same day, so "today" is
        // unambiguously Aug 5 in both frames and no assertion below depends on
        // which side of midnight the runner happens to be on. An absolute
        // instant, so the frozen clock means the same thing in any app timezone.
        $this->travelTo(Carbon::parse('2026-08-05 12:00:00', 'UTC'));

        $this->tenant = app(TenantContext::class);
        // Seeding runs UNBOUND so the explicit masjid_id is honored.
        $this->tenant->forgetTenant();

        $this->masjid = $this->makeMasjid(['timezone' => 'America/New_York']);
        $this->quietMasjid = $this->makeMasjid(['timezone' => 'America/New_York']);

        $this->fund = Fund::factory()->create(['masjid_id' => $this->masjid->id]);

        // 2026-08-01T02:00:00Z. August in UTC, JULY 31 at the masjid.
        $this->lateJulyGift = $this->makeGift([
            'created_at' => $this->instant('2026-08-01 02:00:00'),
            'donated_at' => null,
            'charged_amount' => 10000,
            // Settled, so this one has a real net.
            'net_amount' => 9700,
        ]);

        // Plainly inside August, local time.
        $this->augustGift = $this->makeGift([
            'created_at' => $this->instant('2026-08-02 15:00:00'),
            'donated_at' => null,
            'charged_amount' => 5000,
            // Not settled yet: net must fall back to what was charged.
            'net_amount' => null,
        ]);

        // Offline history: given 2024-03-17, imported today.
        $this->oldOfflineGift = $this->makeGift([
            'source' => 'offline',
            'payment_method' => 'cash',
            'created_at' => $this->instant('2026-08-05 12:00:00'),
            'donated_at' => '2024-03-17',
            'charged_amount' => 2500,
            'net_amount' => null,
        ]);

        // Money that never arrived. All three sit inside the August window on
        // purpose, so a status regression would show up immediately.
        $this->makeGift([
            'status' => 'pending',
            'created_at' => $this->instant('2026-08-03 12:00:00'),
            'charged_amount' => 9900,
        ]);
        $this->makeGift([
            'status' => 'failed',
            'created_at' => $this->instant('2026-08-03 12:00:00'),
            'charged_amount' => 6600,
        ]);
        $this->makeGift([
            'status' => 'refunded',
            'created_at' => $this->instant('2026-08-03 12:00:00'),
            'charged_amount' => 7700,
        ]);
    }

    /**
     * A UTC instant, handed to Eloquent already converted into the APP timezone.
     *
     * That conversion is the point. `created_at` is stored as a wall time in the
     * app timezone (which is what a webhook writing now() produces), and
     * DonationMetrics compares it against a boundary expressed in that same
     * timezone. Writing the fixture in the app's frame keeps the test honest on a
     * runner whose .env is not UTC, while the instant itself — and therefore what
     * it means in America/New_York — stays fixed either way.
     */
    private function instant(string $utc): Carbon
    {
        return Carbon::parse($utc, 'UTC')->setTimezone(config('app.timezone', 'UTC'));
    }

    /** Create a Masjid row with the minimum columns the schema requires. */
    private function makeMasjid(array $overrides = []): Masjid
    {
        return Masjid::create(array_merge([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ], $overrides));
    }

    /** A succeeded stripe gift to $this->masjid unless the caller says otherwise. */
    private function makeGift(array $attributes): Donation
    {
        $attributes = array_merge([
            'masjid_id' => $this->masjid->id,
            'fund_id' => $this->fund->id,
            'status' => 'succeeded',
            'source' => 'stripe',
            'charged_amount' => 5000,
        ], $attributes);

        // Nothing here exercises partial captures, so keep the fixture
        // self-consistent rather than leaving the factory's default behind.
        $attributes['intended_amount'] = $attributes['charged_amount'];

        return Donation::factory()->create($attributes);
    }

    private function metrics(): DonationMetrics
    {
        return DonationMetrics::forMasjid($this->masjid);
    }

    #[Test]
    public function metrics_use_the_masjids_own_timezone(): void
    {
        $this->assertSame('America/New_York', $this->metrics()->timezone());
    }

    #[Test]
    public function the_boundary_gift_really_is_stored_on_two_different_calendar_days(): void
    {
        // Read first, so a failure here says "the fixture did not round-trip"
        // rather than letting every money test below fail for the wrong reason.
        $storedAt = $this->lateJulyGift->fresh()->created_at;

        $this->assertSame('2026-08-01 02:00:00', $storedAt->copy()->setTimezone('UTC')->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-01', $storedAt->copy()->setTimezone('UTC')->toDateString());
        $this->assertSame('2026-07-31', $storedAt->copy()->setTimezone('America/New_York')->toDateString());

        // The contrast row: unambiguously August at the masjid.
        $this->assertSame(
            '2026-08-02',
            $this->augustGift->fresh()->created_at->copy()->setTimezone('America/New_York')->toDateString()
        );

        // Stripe gifts have no gift date of their own; that is why created_at is
        // the column the month boundary has to be applied to.
        $this->assertNull($this->lateJulyGift->fresh()->donated_at);

        // …and the offline row is the mirror image: a gift date, two years back.
        $this->assertSame('2024-03-17', $this->oldOfflineGift->fresh()->donated_at->toDateString());
    }

    #[Test]
    public function only_succeeded_gifts_count_as_money(): void
    {
        $this->tenant->set($this->masjid->id);

        $summary = $this->metrics()->summary();

        // 10000 + 5000 + 2500. The pending (9900), failed (6600) and refunded
        // (7700) rows are not money received and must not appear anywhere.
        $this->assertSame(17500, $summary['all_time']['gross_cents']);
        $this->assertSame(3, $summary['all_time']['gift_count']);
    }

    #[Test]
    public function net_falls_back_to_the_charged_amount_when_net_amount_is_null(): void
    {
        $this->tenant->set($this->masjid->id);

        $summary = $this->metrics()->summary();

        // Only the late-July gift has settled (net 9700); the other two fall back
        // to what was charged (5000 + 2500) rather than contributing nothing,
        // which would make net look mysteriously smaller than gross.
        $this->assertSame(17500, $summary['all_time']['gross_cents']);
        $this->assertSame(17200, $summary['all_time']['net_cents']);

        // The August bucket holds only the unsettled gift, so net == gross there.
        $this->assertSame(5000, $summary['this_month']['gross_cents']);
        $this->assertSame(5000, $summary['this_month']['net_cents']);
    }

    #[Test]
    public function this_month_is_the_masjids_month_not_utcs(): void
    {
        $this->tenant->set($this->masjid->id);

        $summary = $this->metrics()->summary();

        // The 2026-08-01T02:00:00Z gift is 2026-07-31 22:00 in America/New_York.
        // August by the server's clock, July by the masjid's — and the masjid's
        // is the one the treasurer reconciles against.
        $this->assertSame(1, $summary['this_month']['gift_count']);
        $this->assertSame(5000, $summary['this_month']['gross_cents']);

        // It is booked to July, not lost: year-to-date still holds both gifts.
        $this->assertSame(2, $summary['year_to_date']['gift_count']);
        $this->assertSame(15000, $summary['year_to_date']['gross_cents']);
    }

    #[Test]
    public function an_offline_gift_lands_in_its_own_month_not_the_month_it_was_imported(): void
    {
        $this->tenant->set($this->masjid->id);

        // Given 2024-03-17. Filtering to March 2024 finds it...
        $march2024 = $this->metrics()->summary(['from' => '2024-03-01', 'to' => '2024-03-31']);

        $this->assertSame(1, $march2024['all_time']['gift_count']);
        $this->assertSame(2500, $march2024['all_time']['gross_cents']);

        // ...and the month it was TYPED IN (created_at 2026-08-05) does not claim
        // it: August 2026 holds only the one gift actually given in August.
        $august2026 = $this->metrics()->summary(['from' => '2026-08-01', 'to' => '2026-08-31']);

        $this->assertSame(1, $august2026['all_time']['gift_count']);
        $this->assertSame(5000, $august2026['all_time']['gross_cents']);

        // Same story for the live header bucket.
        $this->assertSame(1, $this->metrics()->summary()['this_month']['gift_count']);
    }

    #[Test]
    public function average_gift_is_integer_cents_floored(): void
    {
        $this->tenant->set($this->masjid->id);

        $summary = $this->metrics()->summary();

        // 15000 / 2 exactly; 17500 / 3 floored (5833, not 5833.33).
        $this->assertSame(7500, $summary['year_to_date']['average_gift_cents']);
        $this->assertSame(5833, $summary['all_time']['average_gift_cents']);
    }

    #[Test]
    public function average_gift_is_zero_for_a_masjid_with_no_gifts(): void
    {
        // A brand-new masjid must render a dashboard, not a division by zero.
        $this->tenant->set($this->quietMasjid->id);

        $summary = DonationMetrics::forMasjid($this->quietMasjid)->summary();

        foreach (['this_month', 'year_to_date', 'all_time'] as $bucket) {
            $this->assertSame(0, $summary[$bucket]['gift_count'], "{$bucket} gift_count");
            $this->assertSame(0, $summary[$bucket]['gross_cents'], "{$bucket} gross_cents");
            $this->assertSame(0, $summary[$bucket]['net_cents'], "{$bucket} net_cents");
            $this->assertSame(0, $summary[$bucket]['average_gift_cents'], "{$bucket} average_gift_cents");
        }
    }

    #[Test]
    public function summary_is_scoped_to_the_bound_masjid(): void
    {
        // The quiet masjid's zeroes are only meaningful if the other masjid's
        // 17500 was actually excluded rather than never queried.
        $this->tenant->set($this->quietMasjid->id);
        $this->assertSame(0, DonationMetrics::forMasjid($this->quietMasjid)->summary()['all_time']['gross_cents']);

        $this->tenant->set($this->masjid->id);
        $this->assertSame(17500, $this->metrics()->summary()['all_time']['gross_cents']);
    }

    #[Test]
    public function gifts_with_no_contact_still_count_as_money_and_are_reported_separately(): void
    {
        $this->tenant->set($this->masjid->id);

        $summary = $this->metrics()->summary();

        // Every gift in this fixture is anonymous (contact_id null), so the money
        // is all there while donor_count — distinct IDENTIFIED donors — is zero.
        // The gap between donor_count and gift_count has to be explainable.
        $this->assertSame(3, $summary['all_time']['gift_count']);
        $this->assertSame(3, $summary['all_time']['anonymous_gift_count']);
        $this->assertSame(0, $summary['all_time']['donor_count']);
        $this->assertSame(17500, $summary['all_time']['gross_cents']);
    }

    #[Test]
    public function by_fund_lists_the_funds_gifts_under_the_same_money_rules(): void
    {
        $this->tenant->set($this->masjid->id);

        $rows = $this->metrics()->byFund();

        $this->assertCount(1, $rows);
        $this->assertSame($this->fund->id, $rows[0]['fund_id']);
        // Same three succeeded gifts, same net fallback as the header totals.
        $this->assertSame(17500, $rows[0]['gross_cents']);
        $this->assertSame(17200, $rows[0]['net_cents']);
        $this->assertSame(3, $rows[0]['gift_count']);
        // last_gift_at is the newest COALESCE(donated_at, created_at). The offline
        // row was ENTERED most recently (2026-08-05) but GIVEN in 2024, so the
        // most recent actual gift is the August 2 stripe one.
        $this->assertSame('2026-08-02', $rows[0]['last_gift_at']);
    }

    #[Test]
    public function a_fund_that_has_taken_nothing_is_still_listed_at_zero(): void
    {
        // Seeded unbound so the explicit masjid_id is honored.
        $this->tenant->forgetTenant();
        $emptyFund = Fund::factory()->create([
            'masjid_id' => $this->masjid->id,
            'name' => 'Masjid Expansion',
        ]);

        $this->tenant->set($this->masjid->id);

        $rows = collect($this->metrics()->byFund())->keyBy('fund_id');

        $this->assertTrue($rows->has($emptyFund->id), 'A designation sitting at $0 must still appear.');
        $this->assertSame(0, $rows[$emptyFund->id]['gross_cents']);
        $this->assertSame(0, $rows[$emptyFund->id]['gift_count']);
        $this->assertNull($rows[$emptyFund->id]['last_gift_at']);
    }
}
