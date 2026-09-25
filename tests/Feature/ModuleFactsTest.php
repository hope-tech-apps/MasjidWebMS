<?php

namespace Tests\Feature;

use App\Models\Donation;
use App\Models\DonationSubscription;
use App\Models\Fund;
use App\Models\IqamaTimeRange;
use App\Models\IqamaTimeSetting;
use App\Models\Masjid;
use App\Models\MobileAppUser;
use App\Models\SplashAnnouncement;
use App\Models\User;
use App\Support\ModuleFacts;
use App\Support\PrayerPushes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * App\Support\ModuleFacts — the sentences the switch panel prints under Giving,
 * Prayer times and Splash, and repeats in the confirm dialog before a flip.
 *
 * A fact that counts another organisation's gifts or phones would mislead the
 * one decision it exists for, and a fact that breaks the panel would block
 * the switch itself. So: per organisation, the phone count is the backstop's
 * own recipients, and a failing query costs only its own module's facts.
 */
class ModuleFactsTest extends TestCase
{
    use RefreshDatabase;

    private const PLATFORM_APP = 'This organisation has no OneSignal app of its own; pushes use the platform app';

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        config([
            'onesignal.api_url' => 'https://onesignal.com/api/v1/notifications',
            'onesignal.app_id' => 'app-id-test',
            'onesignal.app_rest_api_key' => 'rest-key-test',
        ]);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    private function org(array $attributes = []): Masjid
    {
        return Masjid::create(array_merge([
            'name' => 'Facts Org ' . uniqid(),
            'email' => 'facts' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'crm_enabled' => true, 'org_type' => 'masjid',
        ], $attributes));
    }

    private function superAdmin(): User
    {
        return User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+15550000000'])->fresh();
    }

    private function device(Masjid $masjid, ?string $subscriptionId, ?Carbon $lastActiveAt): MobileAppUser
    {
        return MobileAppUser::create([
            'masjid_id' => $masjid->id,
            'device_id' => 'device-' . uniqid('', true),
            'onesignal_subscription_id' => $subscriptionId,
            'user_agent' => 'ModuleFactsTest',
            'last_active_at' => $lastActiveAt,
        ]);
    }

    private function fund(Masjid $masjid, bool $active = true): Fund
    {
        return Fund::factory()->create(['masjid_id' => $masjid->id, 'is_active' => $active]);
    }

    /** Only ENUM-valid statuses: production's column cannot hold anything else. */
    private function subscription(Masjid $masjid, Fund $fund, string $status, ?string $stripeId, ?string $checkoutSessionId = null): DonationSubscription
    {
        $this->assertContains($status, ['pending', 'active', 'past_due', 'canceled']);

        return DonationSubscription::create([
            'masjid_id' => $masjid->id,
            'fund_id' => $fund->id,
            'intended_amount' => 5000,
            'charged_amount' => 5000,
            'currency' => 'usd',
            'interval' => 'month',
            'status' => $status,
            'stripe_subscription_id' => $stripeId,
            'stripe_checkout_session_id' => $checkoutSessionId,
            'idempotency_key' => 'sub_' . uniqid('', true),
        ]);
    }

    private function iqamaUntil(Masjid $masjid, string ...$endDates): void
    {
        $this->iqamaInModeUntil($masjid, 'specific_time_ranges', ...$endDates);
    }

    private function iqamaInModeUntil(Masjid $masjid, string $mode, string ...$endDates): void
    {
        $setting = IqamaTimeSetting::create([
            'masjid_id' => $masjid->id,
            'iqama_type' => $mode,
            'fajr' => 20, 'dhuhr' => 10, 'asr' => 10, 'maghrib' => 10, 'isha' => 10,
        ]);

        foreach ($endDates as $end) {
            IqamaTimeRange::create([
                'iqama_time_setting_id' => $setting->id,
                'salah' => 'fajr',
                'start_date' => Carbon::parse($end)->subDays(10)->toDateString(),
                'end_date' => $end,
                'specific_time' => '05:30:00',
            ]);
        }
    }

    #[Test]
    public function an_organisation_with_nothing_reads_plain_zeroes(): void
    {
        $org = $this->org();

        $this->assertSame([
            'Stripe is not connected',
            '0 monthly gifts can still charge donors',
            '0 monthly-gift checkout pages opened in the last 24 hours can still start a monthly gift',
            '0 gifts started in the last 24 hours may still complete',
            '0 gifts recorded in the last 12 months',
            '0 active funds',
        ], ModuleFacts::for($org, 'giving'));

        $this->assertSame([
            'No prayer settings saved yet',
            "0 phones that have not opened the app for 5 days get Manara's backup prayer reminders",
            '0 phones get the daily background refresh',
            self::PLATFORM_APP,
        ], ModuleFacts::for($org, 'prayer_times'));

        $this->assertSame(['No live splash'], ModuleFacts::for($org, 'splash'));

        // Only three modules have anything to say.
        $this->assertSame([], ModuleFacts::for($org, 'events'));
        $this->assertSame([], ModuleFacts::for($org, 'services'));
        $this->assertSame([], ModuleFacts::for($org, 'nope'));
    }

    #[Test]
    public function facts_count_this_organisation_only(): void
    {
        $org = $this->org([
            'stripe_account_id' => 'acct_TEST' . uniqid(),
            'stripe_charges_enabled' => true,
            'timezone' => 'America/New_York',
        ]);
        $other = $this->org();

        // Money.
        $fund = $this->fund($org);
        $this->fund($org, active: false);
        $otherFund = $this->fund($other);
        $otherFund2 = $this->fund($other);

        $this->subscription($org, $fund, 'active', 'sub_' . uniqid());
        $this->subscription($other, $otherFund, 'active', 'sub_' . uniqid());
        $this->subscription($other, $otherFund, 'past_due', 'sub_' . uniqid());
        // Monthly-gift checkout pages still open: one here, two at the neighbour, and
        // one here whose Stripe call threw (no page, so not counted).
        $this->subscription($org, $fund, 'pending', null, 'cs_open_' . uniqid());
        $this->subscription($org, $fund, 'pending', null);
        $this->subscription($other, $otherFund, 'pending', null, 'cs_open_' . uniqid());
        $this->subscription($other, $otherFund, 'pending', null, 'cs_open_' . uniqid());

        Donation::factory()->create(['masjid_id' => $org->id, 'fund_id' => $fund->id, 'status' => 'pending']);
        Donation::factory()->create(['masjid_id' => $org->id, 'fund_id' => $fund->id, 'status' => 'pending', 'created_at' => now()->subDays(2)]);
        Donation::factory()->succeeded()->create(['masjid_id' => $org->id, 'fund_id' => $fund->id]);
        Donation::factory()->succeeded()->create(['masjid_id' => $org->id, 'fund_id' => $fund->id, 'created_at' => now()->subMonths(13)]);
        Donation::factory()->create(['masjid_id' => $other->id, 'fund_id' => $otherFund->id, 'status' => 'pending']);
        Donation::factory()->succeeded()->create(['masjid_id' => $other->id, 'fund_id' => $otherFund2->id]);

        // Prayer.
        $this->iqamaUntil($org, '2026-11-30', '2026-12-31');
        $this->iqamaUntil($other, '2027-06-30');

        $this->device($org, 'sub-stale', now()->subDays(6));
        $this->device($org, 'sub-fresh', now()->subDay());
        $this->device($org, 'sub-never-heartbeat', null);
        $this->device($org, null, now()->subDays(9));
        $this->device($other, 'other-stale-1', now()->subDays(8));
        $this->device($other, 'other-stale-2', now()->subDays(8));

        // Splash: one live, one switched off with a later end, and another
        // organisation's live one ending later still.
        SplashAnnouncement::factory()->create([
            'masjid_id' => $org->id,
            'starts_at' => now()->subHour(),
            'ends_at' => Carbon::parse('2030-01-15 17:00:00', 'UTC'),
        ]);
        SplashAnnouncement::factory()->inactive()->create([
            'masjid_id' => $org->id,
            'starts_at' => now()->subHour(),
            'ends_at' => Carbon::parse('2031-01-15 17:00:00', 'UTC'),
        ]);
        SplashAnnouncement::factory()->create([
            'masjid_id' => $other->id,
            'starts_at' => now()->subHour(),
            'ends_at' => Carbon::parse('2032-01-15 17:00:00', 'UTC'),
        ]);

        $this->assertSame([
            'Stripe can take card gifts for this organisation',
            '1 monthly gift can still charge donors',
            '1 monthly-gift checkout page opened in the last 24 hours can still start a monthly gift',
            '1 gift started in the last 24 hours may still complete',
            '1 gift recorded in the last 12 months',
            '1 active fund',
        ], ModuleFacts::for($org, 'giving'));

        $this->assertSame([
            'Fixed iqama times are set until December 31, 2026; after that the website and apps show minutes after adhan',
            "1 phone that has not opened the app for 5 days gets Manara's backup prayer reminders",
            '3 phones get the daily background refresh',
            self::PLATFORM_APP,
        ], ModuleFacts::for($org, 'prayer_times'));

        // 17:00 UTC is noon in New York in January: the organisation's own clock.
        $this->assertSame(['A splash is live until January 15, 2030 12:00 PM'], ModuleFacts::for($org, 'splash'));

        // And the endpoint serves exactly these.
        Sanctum::actingAs($this->superAdmin());
        $entries = collect($this->getJson("/api/admin/masjids/{$org->id}/capabilities")->assertOk()->json('data.groups'))
            ->flatMap(fn (array $group) => $group['entries'])
            ->keyBy('key');

        $this->assertSame(ModuleFacts::for($org, 'giving'), $entries['giving']['facts']);
        $this->assertSame(ModuleFacts::for($org, 'prayer_times'), $entries['prayer_times']['facts']);
        $this->assertSame(ModuleFacts::for($org, 'splash'), $entries['splash']['facts']);
    }

    #[Test]
    public function the_stale_phone_fact_counts_exactly_the_devices_the_backstop_targets(): void
    {
        // One constant for the fact and prayers:send-due, so they cannot drift.
        $this->assertSame(5, PrayerPushes::STALE_DAYS);

        $org = $this->org();
        $other = $this->org();

        $this->device($org, 'stale-a', now()->subDays(PrayerPushes::STALE_DAYS)->subMinutes(5));
        $this->device($org, 'stale-b', now()->subDays(30));
        $this->device($org, 'almost-stale', now()->subDays(PrayerPushes::STALE_DAYS)->addMinutes(5));
        $this->device($org, 'never-heartbeat', null);
        $this->device($org, null, now()->subDays(30));
        $this->device($org, '', now()->subDays(30));
        $this->device($other, 'someone-else', now()->subDays(30));

        // The backstop's recipient query (SendDuePrayerNotifications::maybeSend),
        // restated: a subscription id, a heartbeat older than STALE_DAYS, and
        // blank ids dropped by its ->filter().
        $backstopRecipients = MobileAppUser::where('masjid_id', $org->id)
            ->whereNotNull('onesignal_subscription_id')
            ->whereNotNull('last_active_at')
            ->where('last_active_at', '<', now()->subDays(PrayerPushes::STALE_DAYS))
            ->pluck('onesignal_subscription_id')
            ->filter()
            ->count();

        $this->assertSame(2, $backstopRecipients);

        $stale = ModuleFacts::for($org, 'prayer_times')[1];
        $this->assertStringEndsWith("get Manara's backup prayer reminders", $stale);
        $this->assertSame($backstopRecipients, (int) strtok($stale, ' '));

        // Every device with a real subscription id gets the daily refresh.
        $this->assertSame('4 phones get the daily background refresh', ModuleFacts::for($org, 'prayer_times')[2]);
    }

    #[Test]
    public function fixed_times_stored_on_minutes_after_adhan_are_not_called_in_use(): void
    {
        // The apps and the backstop push ask the mode before any range
        // (IqamaResolver), so on Minutes After Adhan "set until" would describe
        // a schedule they ignore. Only the website still prints a covering range.
        $org = $this->org();
        $this->iqamaInModeUntil($org, 'minutes_after_adhan', '2026-12-31');

        $this->assertSame(
            'Fixed iqama times are stored until December 31, 2026 but not in use: the apps and prayer reminders show minutes after adhan, while the website still shows a stored time on the days it covers',
            ModuleFacts::for($org, 'prayer_times')[0]
        );
    }

    #[Test]
    public function an_unconfigured_platform_app_says_no_prayer_pushes_are_sent(): void
    {
        config(['onesignal.app_id' => null, 'onesignal.app_rest_api_key' => null]);

        $facts = ModuleFacts::for($this->org(), 'prayer_times');

        $this->assertSame(
            'This organisation has no OneSignal app of its own, and no platform OneSignal app is configured, so no prayer pushes are sent',
            end($facts)
        );
    }

    #[Test]
    public function only_a_non_masjid_is_told_the_app_prayer_table_is_masjids_only(): void
    {
        // The app menu computes the prayer table as isMasjid() AND this module,
        // so switching it on for a school or community organisation gives them
        // everything else this switch carries and still no table. The panel says
        // so first, before the counts that sit under it.
        foreach (['school', 'community'] as $orgType) {
            $facts = ModuleFacts::for($this->org(['org_type' => $orgType]), 'prayer_times');

            $this->assertSame('App prayer table: masjids only', $facts[0], "a {$orgType} is not told");
            // The rest of the list is unchanged, one place further down.
            $this->assertSame('No prayer settings saved yet', $facts[1]);
            $this->assertCount(5, $facts);
        }

        // A masjid's list is byte-identical to what it always was: the org-type
        // floor never applies to it, so saying it would be noise.
        $facts = ModuleFacts::for($this->org(), 'prayer_times');

        $this->assertNotContains('App prayer table: masjids only', $facts);
        $this->assertSame('No prayer settings saved yet', $facts[0]);
        $this->assertCount(4, $facts);

        // An unrecognised org_type degrades to masjid everywhere (Masjid::orgType),
        // so it must not pick up a line that says it has no prayer table.
        $this->assertNotContains(
            'App prayer table: masjids only',
            ModuleFacts::for($this->org(['org_type' => 'nonsense']), 'prayer_times')
        );
    }

    #[Test]
    public function a_fact_query_that_throws_costs_only_that_modules_facts(): void
    {
        $org = $this->org();
        Log::spy();

        // Splash's query now fails; the panel must still load.
        Schema::drop('splash_announcements');

        $this->assertSame([], ModuleFacts::for($org, 'splash'));

        Sanctum::actingAs($this->superAdmin());
        $entries = collect($this->getJson("/api/admin/masjids/{$org->id}/capabilities")->assertOk()->json('data.groups'))
            ->flatMap(fn (array $group) => $group['entries'])
            ->keyBy('key');

        $this->assertSame([], $entries['splash']['facts']);
        $this->assertNotEmpty($entries['giving']['facts']);
        $this->assertNotEmpty($entries['prayer_times']['facts']);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []) => $message === 'Module facts could not be read'
                && ($context['module'] ?? null) === 'splash'
                && ($context['masjid_id'] ?? null) === (int) $org->id)
            ->atLeast()->once();
    }
}
