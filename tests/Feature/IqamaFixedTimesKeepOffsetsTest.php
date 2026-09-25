<?php

namespace Tests\Feature;

use App\Models\IqamaTimeRange;
use App\Models\IqamaTimeSetting;
use App\Models\Masjid;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A masjid on fixed iqama times for SOME prayers keeps its offsets for the rest.
 *
 * Specific Time Ranges is not "every prayer fixed". The rule every client implements
 * (tests/fixtures/iqama-resolution.json) is per prayer: a range covering today wins,
 * otherwise adhan + that prayer's offset. NAFIS Apex runs fixed Fajr/Dhuhr/Asr with
 * offsets for Maghrib/Isha; MEC Charlotte publishes fixed Dhuhr/Asr/Isha with Fajr +20
 * and Maghrib +5.
 *
 * Three things broke that for a masjid maintaining its own times:
 *
 *  1. The admin screen sent no offsets in that mode and the controller stored `?? 0`,
 *     so any save there put Fajr and Maghrib iqama ON the adhan. Pinned by
 *     a_ranges_save_that_carries_no_offsets_keeps_the_stored_ones.
 *  2. Once the screen sends them, `min:1` refused the 0 that Burlington and NAFIS Apex store.
 *     Pinned by zero_is_a_real_fallback_offset_on_specific_time_ranges.
 *  3. The website decided "today" in UTC, so a New York masjid's range changed over at
 *     8 PM: on the last evening of a range the site showed the next range's Isha while
 *     the apps showed the right one. Pinned by the_website_decides_today_in_the_masjids_zone.
 *
 * The saves are sent form-encoded, as the admin SPA sends them (.claude/rules/shipping.md).
 */
class IqamaFixedTimesKeepOffsetsTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjid;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        Queue::fake();
        Cache::flush();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->masjid = Masjid::create([
            'name' => 'Fixed Iqama Org ' . uniqid(),
            'email' => 'org-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '4301 Test Dr',
            'latitude' => '35.22990000',
            'longitude' => '-80.75040000',
            'timezone' => 'America/New_York',
        ]);

        $this->admin = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
        $this->masjid->user_id = $this->admin->id;
        $this->masjid->save();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** MEC Charlotte's row as it stood on 2026-09-21: every prayer relative. */
    private function meccharlotteOffsets(): IqamaTimeSetting
    {
        return IqamaTimeSetting::create([
            'masjid_id' => $this->masjid->id,
            'iqama_type' => 'minutes_after_adhan',
            'show_iqama_times' => true,
            'fajr' => 20, 'dhuhr' => 10, 'asr' => 10, 'maghrib' => 5, 'isha' => 10,
        ]);
    }

    private function url(): string
    {
        return "/api/admin/masjids/{$this->masjid->id}/iqama";
    }

    /** MEC's published fixed times, Sep 21 - Oct 31 2026. */
    private function mecRanges(): array
    {
        return [
            ['salah' => 'dhuhr', 'start_date' => '2026-09-21', 'end_date' => '2026-10-31', 'specific_time' => '13:45'],
            ['salah' => 'asr', 'start_date' => '2026-09-21', 'end_date' => '2026-10-31', 'specific_time' => '17:30'],
            ['salah' => 'isha', 'start_date' => '2026-09-21', 'end_date' => '2026-10-31', 'specific_time' => '20:45'],
        ];
    }

    private function offsets(IqamaTimeSetting $setting): array
    {
        $fresh = $setting->fresh();

        return [
            'fajr' => (int) $fresh->fajr,
            'dhuhr' => (int) $fresh->dhuhr,
            'asr' => (int) $fresh->asr,
            'maghrib' => (int) $fresh->maghrib,
            'isha' => (int) $fresh->isha,
        ];
    }

    #[Test]
    public function a_ranges_save_that_carries_no_offsets_keeps_the_stored_ones(): void
    {
        $setting = $this->meccharlotteOffsets();
        Sanctum::actingAs($this->admin);

        // Exactly what the pre-fix screen sent on Specific Time Ranges: no offsets.
        $this->post($this->url(), [
            'iqama_type' => 'specific_time_ranges',
            'show_iqama_times' => 'true',
            'time_ranges' => $this->mecRanges(),
        ], ['Accept' => 'application/json'])->assertOk();

        $this->assertSame('specific_time_ranges', $setting->fresh()->iqama_type->value);
        $this->assertSame(3, IqamaTimeRange::where('iqama_time_setting_id', $setting->id)->count());

        // Fajr +20 and Maghrib +5 still drive those two prayers; 10 is the fallback for
        // Dhuhr/Asr/Isha on any day outside the ranges. Before the fix all five were 0.
        $this->assertSame(
            ['fajr' => 20, 'dhuhr' => 10, 'asr' => 10, 'maghrib' => 5, 'isha' => 10],
            $this->offsets($setting)
        );
    }

    #[Test]
    public function offsets_sent_with_a_ranges_save_are_stored(): void
    {
        $setting = $this->meccharlotteOffsets();
        Sanctum::actingAs($this->admin);

        $this->post($this->url(), [
            'iqama_type' => 'specific_time_ranges',
            'show_iqama_times' => 'true',
            'fajr' => '25', 'dhuhr' => '10', 'asr' => '10', 'maghrib' => '7', 'isha' => '10',
            'time_ranges' => $this->mecRanges(),
        ], ['Accept' => 'application/json'])->assertOk();

        $this->assertSame(
            ['fajr' => 25, 'dhuhr' => 10, 'asr' => 10, 'maghrib' => 7, 'isha' => 10],
            $this->offsets($setting)
        );
    }

    #[Test]
    public function zero_is_a_real_fallback_offset_on_specific_time_ranges(): void
    {
        // Burlington's stored offsets on 2026-09-21: 0/0/0/5/5 (its ranges cover all five prayers).
        $setting = IqamaTimeSetting::create([
            'masjid_id' => $this->masjid->id,
            'iqama_type' => 'specific_time_ranges',
            'fajr' => 0, 'dhuhr' => 0, 'asr' => 0, 'maghrib' => 5, 'isha' => 5,
        ]);
        Sanctum::actingAs($this->admin);

        $this->post($this->url(), [
            'iqama_type' => 'specific_time_ranges',
            'show_iqama_times' => 'true',
            'fajr' => '0', 'dhuhr' => '0', 'asr' => '0', 'maghrib' => '5', 'isha' => '5',
            'time_ranges' => [
                ['salah' => 'fajr', 'start_date' => '2026-09-21', 'end_date' => '2026-09-25', 'specific_time' => '06:20'],
            ],
        ], ['Accept' => 'application/json'])->assertOk();

        $this->assertSame(
            ['fajr' => 0, 'dhuhr' => 0, 'asr' => 0, 'maghrib' => 5, 'isha' => 5],
            $this->offsets($setting)
        );
    }

    #[Test]
    public function a_stored_offset_can_be_set_to_zero_on_specific_time_ranges_and_a_blank_one_is_kept(): void
    {
        // 0 is a value the admin typed ("iqama at the adhan"), not a missing field, so
        // it must replace a stored 20. A blank field arrives as null (form-encoded ''
        // through ConvertEmptyStringsToNull) and is "not sent": the stored 5 stays.
        $setting = $this->meccharlotteOffsets();
        Sanctum::actingAs($this->admin);

        $this->post($this->url(), [
            'iqama_type' => 'specific_time_ranges',
            'show_iqama_times' => 'true',
            'fajr' => '0', 'dhuhr' => '10', 'asr' => '10', 'maghrib' => '', 'isha' => '10',
            'time_ranges' => $this->mecRanges(),
        ], ['Accept' => 'application/json'])->assertOk();

        $this->assertSame(
            ['fajr' => 0, 'dhuhr' => 10, 'asr' => 10, 'maghrib' => 5, 'isha' => 10],
            $this->offsets($setting)
        );
    }

    #[Test]
    public function a_first_ranges_save_with_no_offsets_creates_the_row_with_offsets_of_zero(): void
    {
        // An organisation with no iqama row yet (Intellicor, the QA sandbox on
        // production). Nothing stored and nothing sent is 0, the column's own value
        // and what the resolver reads for a missing row: never an invented default.
        $this->assertNull($this->masjid->iqamaTimeSettings()->first(), 'premise: no iqama row');
        Sanctum::actingAs($this->admin);

        $this->post($this->url(), [
            'iqama_type' => 'specific_time_ranges',
            'show_iqama_times' => 'true',
            'time_ranges' => $this->mecRanges(),
        ], ['Accept' => 'application/json'])->assertOk();

        $setting = $this->masjid->iqamaTimeSettings()->firstOrFail();
        $this->assertSame('specific_time_ranges', $setting->iqama_type->value);
        $this->assertSame(
            ['fajr' => 0, 'dhuhr' => 0, 'asr' => 0, 'maghrib' => 0, 'isha' => 0],
            $this->offsets($setting)
        );
        $this->assertSame(
            [['dhuhr', '13:45'], ['asr', '17:30'], ['isha', '20:45']],
            $setting->timeRanges->map(fn (IqamaTimeRange $r) => [$r->salah, substr((string) $r->specific_time, 0, 5)])->all()
        );
    }

    #[Test]
    public function minutes_after_adhan_still_requires_an_offset_of_at_least_one(): void
    {
        $setting = $this->meccharlotteOffsets();
        Sanctum::actingAs($this->admin);

        $this->post($this->url(), [
            'iqama_type' => 'minutes_after_adhan',
            'fajr' => '0', 'dhuhr' => '10', 'asr' => '10', 'maghrib' => '5', 'isha' => '10',
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertSame(20, (int) $setting->fresh()->fajr, 'a refused save must not write');
    }

    #[Test]
    public function the_website_and_the_app_sync_payload_carry_the_mixed_schedule(): void
    {
        $setting = $this->meccharlotteOffsets();
        $setting->update(['iqama_type' => 'specific_time_ranges']);
        foreach ($this->mecRanges() as $r) {
            $setting->timeRanges()->create($r);
        }

        // Noon in Charlotte on the first day of the ranges.
        Carbon::setTestNow(Carbon::parse('2026-09-21 12:00:00', 'America/New_York')->utc());

        $site = $this->getJson('/api/v1/settings', ['masjid-id' => (string) $this->masjid->id])
            ->assertOk()
            ->json('data.iqama_settings');

        $this->assertSame('specific_time_ranges', $site['type']);
        $this->assertSame(
            ['fajr' => null, 'dhuhr' => '01:45 PM', 'asr' => '05:30 PM', 'maghrib' => null, 'isha' => '08:45 PM'],
            $site['specific_time_ranges']
        );
        $this->assertSame(
            ['fajr' => 20, 'dhuhr' => 10, 'asr' => 10, 'maghrib' => 5, 'isha' => 10],
            array_map('intval', $site['minutes_after_adhan'])
        );

        // What iOS / tvOS / Android resolve from: the raw row, offsets and every range.
        $iqama = $this->getJson("/api/mobile/masjids/{$this->masjid->id}/prayers/settings")
            ->assertOk()
            ->json('data.iqama');
        $this->assertSame('specific_time_ranges', $iqama['iqama_type']);
        $this->assertSame([20, 5], [(int) $iqama['fajr'], (int) $iqama['maghrib']]);
        $this->assertCount(3, $iqama['time_ranges']);
    }

    #[Test]
    public function the_website_decides_today_in_the_masjids_zone(): void
    {
        $setting = $this->meccharlotteOffsets();
        $setting->update(['iqama_type' => 'specific_time_ranges']);
        $setting->timeRanges()->create(['salah' => 'isha', 'start_date' => '2026-09-21', 'end_date' => '2026-10-31', 'specific_time' => '20:45']);
        $setting->timeRanges()->create(['salah' => 'isha', 'start_date' => '2026-11-01', 'end_date' => '2026-11-30', 'specific_time' => '19:30']);

        $isha = function (string $utc): ?string {
            Carbon::setTestNow(Carbon::parse($utc, 'UTC'));

            return $this->getJson('/api/v1/settings', ['masjid-id' => (string) $this->masjid->id])
                ->assertOk()
                ->json('data.iqama_settings.specific_time_ranges.isha');
        };

        // Oct 31, 8:30 PM EDT = Nov 1 00:30 UTC. Still Oct 31 in Charlotte, where Isha is
        // about to be prayed at 8:45. Before the fix the site said 7:30 PM here.
        $this->assertSame('08:45 PM', $isha('2026-11-01 00:30:00'));

        // Last day of the range at noon: inclusive, even though Charlotte's midnight is
        // 04:00 UTC and the stored date parses at 00:00 UTC.
        $this->assertSame('08:45 PM', $isha('2026-10-31 16:00:00'));

        // Nov 1 in Charlotte: the next range.
        $this->assertSame('07:30 PM', $isha('2026-11-01 17:00:00'));
    }
}
