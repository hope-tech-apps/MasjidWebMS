<?php

namespace Tests\Feature;

use App\Models\IqamaTimeRange;
use App\Models\IqamaTimeSetting;
use App\Models\Masjid;
use App\Models\MobileAppUser;
use App\Models\Prayer;
use App\Services\OnesignalService;
use App\Support\IqamaResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The website, the stored prayer rows and the dark-device push all say the same
 * iqama time, because all three ask App\Support\IqamaResolver.
 *
 * The rule (tests/fixtures/iqama-resolution.json): on Specific Time Ranges, a range
 * covering the prayer's day wins for that prayer; otherwise adhan + its offset.
 * Before this, `prayers:send-due` (every minute, to devices silent for
 * PrayerPushes::STALE_DAYS) and `prayers.iqama_times_data` used adhan + offset only,
 * so MEC, once on fixed Dhuhr 1:45 / Asr 5:30 / Isha 8:45, would have had its dark
 * devices told "the iqama time for Dhuhr has arrived" at adhan + 10 while its own
 * website said 1:45. The website's own payload is unchanged for a masjid on Minutes
 * After Adhan (it still shows a stored covering range, as it always has), because
 * live organisations on that mode must see byte-identical times.
 *
 * Every adhan instant below is written into the prayers row by hand, so each test
 * says exactly which minute the push should and should not fire in. All clocks are
 * America/New_York, which changes on 2026-11-01 (EDT -> EST) and 2026-03-08 (back).
 */
class IqamaResolverAgreementTest extends TestCase
{
    use RefreshDatabase;

    private const NY = 'America/New_York';

    /** What prayers:send-due asked OneSignal to send: ['prayer' => ..., 'kind' => adhan|iqama]. */
    private \ArrayObject $sent;

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

        Cache::flush();

        // The backstop's only way out is OnesignalService::sendPrayerAlert; record
        // what it was asked to send instead of calling OneSignal.
        $this->sent = new \ArrayObject();
        $this->app->instance(OnesignalService::class, new class($this->sent) extends OnesignalService {
            public function __construct(private \ArrayObject $sent)
            {
                parent::__construct();
            }

            public function sendPrayerAlert(array $subscription_ids, string $title, string $body, ?string $iosSound = null, array $data = [], ?string $iosCategory = null, ?Masjid $masjid = null, ?string $androidChannelId = null)
            {
                $this->sent[] = ['prayer' => $data['prayer'], 'kind' => $data['kind']];

                return null;
            }
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function inside_a_range_the_dark_device_push_fires_at_the_fixed_time_not_at_adhan_plus_offset(): void
    {
        $masjid = $this->masjidOnMecsSchedule();
        // Wed 2026-10-15, EDT. Dhuhr adhan 13:20 -> offset would say 13:30; MEC says 13:45.
        $this->row($masjid, '2026-10-15', ['fajr' => '07:05', 'dhuhr' => '13:20']);

        $this->assertSame([], $this->pushesAt('2026-10-15 13:30', 'iqama'), 'adhan + 10 is not MEC\'s Dhuhr iqama inside its range');
        $this->assertSame(['dhuhr'], $this->pushesAt('2026-10-15 13:45', 'iqama'));

        // Fajr has no range: its +20 offset still drives it, on the same day.
        $this->assertSame(['fajr'], $this->pushesAt('2026-10-15 07:25', 'iqama'));
    }

    #[Test]
    public function the_last_day_of_a_range_is_fixed_and_the_day_after_returns_to_the_offset(): void
    {
        $masjid = $this->masjidOnMecsSchedule();
        // MEC's ranges end Sat 2026-10-31. Isha 8:45 PM EDT that evening is 00:45 UTC on
        // Nov 1, so the day is the row's, not the UTC date of the instant.
        $this->row($masjid, '2026-10-31', ['isha' => '19:55']);
        $this->row($masjid, '2026-11-01', ['isha' => '18:54']);

        $this->assertSame([], $this->pushesAt('2026-10-31 20:05', 'iqama'), 'adhan + 10 on the last day of the range');
        $this->assertSame(['isha'], $this->pushesAt('2026-10-31 20:45', 'iqama'));

        // Sun Nov 1: no range covers it (MEC has not published winter times), so Isha is
        // adhan + 10 again, and 8:45 PM is nothing.
        $this->assertSame(['isha'], $this->pushesAt('2026-11-01 19:04', 'iqama'));
        $this->assertSame([], $this->pushesAt('2026-11-01 20:45', 'iqama'));
    }

    #[Test]
    public function an_isha_after_utc_midnight_is_judged_by_its_own_day(): void
    {
        // A late-June Isha in New York is about 10 PM EDT, 02:00 UTC the NEXT day. The
        // range is tested against the prayer's own day (the row's), so on the last day
        // of a June range the fixed 10:15 PM still wins over adhan + 10.
        $masjid = $this->masjidOnMecsSchedule([
            ['salah' => 'isha', 'start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'specific_time' => '22:15:00'],
        ]);
        $this->row($masjid, '2026-06-30', ['isha' => '21:58']);

        $this->assertSame([], $this->pushesAt('2026-06-30 22:08', 'iqama'));
        $this->assertSame(['isha'], $this->pushesAt('2026-06-30 22:15', 'iqama'));
    }

    #[Test]
    public function a_fixed_time_keeps_its_wall_clock_across_both_daylight_saving_changes(): void
    {
        $masjid = $this->masjidOnMecsSchedule([
            ['salah' => 'dhuhr', 'start_date' => '2026-10-31', 'end_date' => '2026-11-01', 'specific_time' => '13:45:00'],
            ['salah' => 'dhuhr', 'start_date' => '2026-03-07', 'end_date' => '2026-03-08', 'specific_time' => '13:45:00'],
        ]);

        foreach (['2026-10-31', '2026-11-01', '2026-03-07', '2026-03-08'] as $day) {
            $this->row($masjid, $day, ['dhuhr' => '13:00']);
        }

        // 1:45 PM EDT is 17:45 UTC; 1:45 PM EST is 18:45 UTC. The push follows the wall
        // clock, not yesterday's UTC minute.
        $this->assertSame('2026-10-31 17:45', $this->utc('2026-10-31 13:45'));
        $this->assertSame(['dhuhr'], $this->pushesAt('2026-10-31 13:45', 'iqama'));

        $this->assertSame('2026-11-01 18:45', $this->utc('2026-11-01 13:45'));
        $this->assertSame([], $this->pushesAtUtc('2026-11-01 17:45', 'iqama'), 'Nov 1 at 17:45 UTC is 12:45 PM EST');
        $this->assertSame(['dhuhr'], $this->pushesAt('2026-11-01 13:45', 'iqama'));

        $this->assertSame('2026-03-07 18:45', $this->utc('2026-03-07 13:45'));
        $this->assertSame(['dhuhr'], $this->pushesAt('2026-03-07 13:45', 'iqama'));

        $this->assertSame('2026-03-08 17:45', $this->utc('2026-03-08 13:45'));
        $this->assertSame([], $this->pushesAtUtc('2026-03-08 18:45', 'iqama'), 'Mar 8 at 18:45 UTC is 2:45 PM EDT');
        $this->assertSame(['dhuhr'], $this->pushesAt('2026-03-08 13:45', 'iqama'));
    }

    #[Test]
    public function a_masjid_on_minutes_after_adhan_is_pushed_at_its_offsets_even_with_old_ranges_stored(): void
    {
        // A regression guard, not a fix: this path never changed. It pins that the
        // resolver asks the MODE before any range, so a range left over from an earlier
        // Specific Time Ranges schedule cannot move a live masjid's pushes.
        $masjid = $this->masjidOnMecsSchedule();
        $masjid->iqamaTimeSettings()->update(['iqama_type' => 'minutes_after_adhan']);
        $this->row($masjid, '2026-10-15', ['dhuhr' => '13:20']);

        $this->assertSame(['dhuhr'], $this->pushesAt('2026-10-15 13:30', 'iqama'));
        $this->assertSame([], $this->pushesAt('2026-10-15 13:45', 'iqama'));
    }

    #[Test]
    public function a_masjid_with_no_iqama_row_is_still_sent_nothing_and_stores_iqama_at_the_adhan(): void
    {
        // Also a guard for unchanged behaviour: the backstop has always skipped such a
        // masjid (adhan too), and the stored column has always been adhan + 0.
        $masjid = $this->masjid();
        $this->darkDevice($masjid);
        $this->row($masjid, '2026-10-15', ['dhuhr' => '13:20']);

        $this->assertSame([], $this->pushesAt('2026-10-15 13:20', 'adhan'));
        $this->assertSame([], $this->pushesAt('2026-10-15 13:20', 'iqama'));

        // The stored column, through the endpoint that writes it: iqama == adhan.
        $this->getJson("/api/mobile/masjids/{$masjid->id}/prayers?start_date=2026-10-16&end_date=2026-10-16")->assertOk();
        $row = Prayer::where('masjid_id', $masjid->id)->where('date', '2026-10-16')->firstOrFail();
        $adhan = json_decode($row->getRawOriginal('prayers_data'), true);
        $iqama = json_decode($row->getRawOriginal('iqama_times_data'), true);

        foreach (IqamaResolver::PRAYERS as $salah) {
            $this->assertSame(Carbon::parse($adhan[$salah])->format('H:i:s'), $iqama[$salah], $salah);
        }
    }

    #[Test]
    public function the_website_the_stored_prayer_rows_and_the_push_agree_on_one_day(): void
    {
        $masjid = $this->masjidOnMecsSchedule();

        // The stored rows, written by the real generator for Charlotte on Oct 15.
        $this->getJson("/api/mobile/masjids/{$masjid->id}/prayers?start_date=2026-10-15&end_date=2026-10-15")->assertOk();
        $row = Prayer::where('masjid_id', $masjid->id)->where('date', '2026-10-15')->firstOrFail();
        $adhan = json_decode($row->getRawOriginal('prayers_data'), true);
        $iqama = json_decode($row->getRawOriginal('iqama_times_data'), true);

        // UTC wall clock, as the column has always held it: 1:45 PM EDT = 17:45.
        $this->assertSame('17:45:00', $iqama['dhuhr']);
        $this->assertSame('21:30:00', $iqama['asr']);
        $this->assertSame('00:45:00', $iqama['isha']);
        // No range: adhan + 20 and adhan + 5, exactly as before.
        $this->assertSame(Carbon::parse($adhan['fajr'])->addMinutes(20)->format('H:i:s'), $iqama['fajr']);
        $this->assertSame(Carbon::parse($adhan['maghrib'])->addMinutes(5)->format('H:i:s'), $iqama['maghrib']);

        // The website at noon in Charlotte that day.
        Carbon::setTestNow(Carbon::parse('2026-10-15 12:00', self::NY)->utc());
        $site = $this->getJson('/api/v1/settings', ['masjid-id' => (string) $masjid->id])
            ->assertOk()
            ->json('data.iqama_settings.specific_time_ranges');
        $this->assertSame(['fajr' => null, 'dhuhr' => '01:45 PM', 'asr' => '05:30 PM', 'maghrib' => null, 'isha' => '08:45 PM'], $site);

        // The push, on the row the endpoint wrote.
        $this->assertSame(['dhuhr'], $this->pushesAt('2026-10-15 13:45', 'iqama'));
        $this->assertSame(['fajr'], $this->pushesAtUtc(Carbon::parse($adhan['fajr'])->addMinutes(20)->format('Y-m-d H:i'), 'iqama'));
    }

    #[Test]
    public function the_website_still_shows_a_stored_range_for_a_masjid_on_minutes_after_adhan(): void
    {
        // Byte-identical for live Minutes After Adhan organisations: this payload has
        // always sent a covering range whatever the mode, and the website prints it.
        // Hiding it is the owner's call (DECISIONS.md), so the resolver's mode check
        // stops at the apps and the push. Fails if the payload asks fixedTime().
        $masjid = $this->masjidOnMecsSchedule();
        $masjid->iqamaTimeSettings()->update(['iqama_type' => 'minutes_after_adhan']);

        Carbon::setTestNow(Carbon::parse('2026-10-15 12:00', self::NY)->utc());
        $site = $this->getJson('/api/v1/settings', ['masjid-id' => (string) $masjid->id])
            ->assertOk()
            ->json('data.iqama_settings');

        $this->assertSame('minutes_after_adhan', $site['type']);
        $this->assertSame(['fajr' => null, 'dhuhr' => '01:45 PM', 'asr' => '05:30 PM', 'maghrib' => null, 'isha' => '08:45 PM'], $site['specific_time_ranges']);
        $this->assertSame([20, 10, 10, 5, 10], array_map('intval', array_values($site['minutes_after_adhan'])));
    }

    #[Test]
    public function the_isha_iqama_the_day_after_a_fixed_range_ends_is_pushed_although_both_fall_on_one_utc_date(): void
    {
        // MEC's last fixed Isha, 8:45 PM EDT on Sat Oct 31, is 00:45 UTC on Nov 1. On
        // Sun Nov 1 (EST) Isha is adhan + 10 again, 6:49 PM = 23:49 UTC, the SAME UTC
        // date. The once-a-day guard used to be keyed on that UTC date and held for 26
        // hours, so Sunday's push was swallowed. Keyed on each prayer's own day, both
        // go out. The cache is deliberately NOT cleared between the two runs.
        $masjid = $this->masjidOnMecsSchedule();
        $this->row($masjid, '2026-10-31', ['isha' => '19:55']);
        $this->row($masjid, '2026-11-01', ['isha' => '18:39']);

        $this->assertSame(['isha'], $this->pushesAtUtc('2026-11-01 00:45', 'iqama'));
        $this->assertSame(['isha'], $this->pushesAtUtc('2026-11-01 23:49', 'iqama'));

        // And the guard still does its job: a second run in the same minute is silent.
        $this->assertSame([], $this->pushesAtUtc('2026-11-01 23:49', 'iqama'));
    }

    #[Test]
    public function a_masjid_on_ranges_without_a_zone_of_its_own_keeps_its_offset_pushes_and_says_so_once(): void
    {
        // `masjids.timezone` defaulted to 'UTC' for every masjid that predates it. A
        // fixed 1:45 PM placed in UTC would push at 9:45 AM in New York while the
        // website prints 1:45 PM, so the push keeps adhan + offset (what it always
        // did) and a warning names the masjid, once a day rather than once a minute.
        $masjid = $this->masjidOnMecsSchedule();
        $masjid->update(['timezone' => 'UTC']);
        $this->row($masjid, '2026-10-15', ['dhuhr' => '13:20']);
        Log::spy();

        $this->assertSame([], $this->pushesAtUtc('2026-10-15 13:45', 'iqama'), '1:45 PM read as UTC');
        $this->assertSame(['dhuhr'], $this->pushesAt('2026-10-15 13:30', 'iqama'), 'adhan + 10, as before ranges were read');

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $line) => str_contains($line, "masjid={$masjid->id} is on Specific Time Ranges"))
            ->once();

        // The stored column follows the same rule, so the apps' cached rows agree.
        $this->getJson("/api/mobile/masjids/{$masjid->id}/prayers?start_date=2026-10-16&end_date=2026-10-16")->assertOk();
        $row = Prayer::where('masjid_id', $masjid->id)->where('date', '2026-10-16')->firstOrFail();
        $adhan = json_decode($row->getRawOriginal('prayers_data'), true);
        $iqama = json_decode($row->getRawOriginal('iqama_times_data'), true);
        $this->assertSame(Carbon::parse($adhan['dhuhr'])->addMinutes(10)->format('H:i:s'), $iqama['dhuhr']);
    }

    #[Test]
    public function the_resolver_gives_every_iqama_in_the_shared_fixture(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/fixtures/iqama-resolution.json')), true, 512, JSON_THROW_ON_ERROR);
        $zone = $fixture['timezone'];

        $checked = 0;

        foreach ([['settings', 'cases'], ['offsets_only_settings', 'offsets_only_cases'], ['ignored_ranges_settings', 'ignored_ranges_cases']] as [$settingsKey, $casesKey]) {
            $resolver = IqamaResolver::for($this->settingFrom($fixture[$settingsKey]), $zone);

            foreach ($fixture[$casesKey] as $case) {
                foreach (IqamaResolver::PRAYERS as $salah) {
                    $adhan = Carbon::parse("{$case['date']} {$case['adhan'][$salah]}", $zone);

                    $this->assertSame(
                        $case['expected'][$salah],
                        $resolver->iqamaAt($salah, $case['date'], $adhan)->setTimezone($zone)->format('H:i'),
                        "[{$case['name']}] {$salah} on {$case['date']}"
                    );
                    $checked++;
                }
            }
        }

        $this->assertGreaterThan(50, $checked, 'the fixture must not pass vacuously');
    }

    // ------------------------------------------------------------- helpers

    /**
     * Prayers the backstop pushed of $kind when run at $local (New York wall clock).
     *
     * @return array<int, string>
     */
    private function pushesAt(string $local, string $kind): array
    {
        return $this->pushesAtUtc($this->utc($local), $kind);
    }

    /** @return array<int, string> */
    private function pushesAtUtc(string $utc, string $kind): array
    {
        $this->sent->exchangeArray([]);
        Carbon::setTestNow(Carbon::parse($utc, 'UTC'));

        $this->artisan('prayers:send-due')->assertExitCode(0)->run();

        Carbon::setTestNow();

        return array_values(array_map(
            fn (array $s) => $s['prayer'],
            array_filter($this->sent->getArrayCopy(), fn (array $s) => $s['kind'] === $kind)
        ));
    }

    private function utc(string $local): string
    {
        return Carbon::parse($local, self::NY)->utc()->format('Y-m-d H:i');
    }

    /**
     * A prayers row for $day whose adhans are the given New York wall-clock times,
     * stored as the generator stores them (UTC ISO-8601).
     *
     * @param  array<string, string>  $adhans
     */
    private function row(Masjid $masjid, string $day, array $adhans): void
    {
        $data = ['date' => "{$day}T00:00:00.000Z"];

        foreach ($adhans as $prayer => $time) {
            $data[$prayer] = Carbon::parse("{$day} {$time}", self::NY)->utc()->format('Y-m-d\TH:i:s.000\Z');
        }

        Prayer::create(['masjid_id' => $masjid->id, 'date' => $day, 'prayers_data' => $data]);
    }

    private function masjid(): Masjid
    {
        return Masjid::create([
            'name' => 'Resolver Test Org ' . uniqid(),
            'email' => 'org-' . uniqid() . '@example.test',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Example Way',
            'latitude' => '35.22990000',
            'longitude' => '-80.75040000',
            'timezone' => self::NY,
        ]);
    }

    /**
     * MEC's shape: offsets 20/10/10/5/10 and fixed Dhuhr/Asr/Isha until 2026-10-31,
     * plus one dark device for the backstop to target.
     *
     * @param  array<int, array<string, string>>|null  $ranges
     */
    private function masjidOnMecsSchedule(?array $ranges = null): Masjid
    {
        $masjid = $this->masjid();

        $setting = IqamaTimeSetting::create([
            'masjid_id' => $masjid->id,
            'iqama_type' => 'specific_time_ranges',
            'show_iqama_times' => true,
            'fajr' => 20, 'dhuhr' => 10, 'asr' => 10, 'maghrib' => 5, 'isha' => 10,
        ]);

        foreach ($ranges ?? [
            ['salah' => 'dhuhr', 'start_date' => '2026-09-25', 'end_date' => '2026-10-31', 'specific_time' => '13:45:00'],
            ['salah' => 'asr', 'start_date' => '2026-09-25', 'end_date' => '2026-10-31', 'specific_time' => '17:30:00'],
            ['salah' => 'isha', 'start_date' => '2026-09-25', 'end_date' => '2026-10-31', 'specific_time' => '20:45:00'],
        ] as $range) {
            $setting->timeRanges()->create($range);
        }

        $this->darkDevice($masjid);

        return $masjid;
    }

    /** A device that has heartbeated, but not for longer than STALE_DAYS: the backstop's audience. */
    private function darkDevice(Masjid $masjid): void
    {
        MobileAppUser::create([
            'masjid_id' => $masjid->id,
            'device_id' => 'dark-handset-' . uniqid(),
            'onesignal_subscription_id' => 'sub-' . uniqid(),
            'user_agent' => 'test',
            'last_active_at' => Carbon::parse('2025-01-01', 'UTC'),
        ]);
    }

    /** An unsaved setting carrying a fixture block's mode, offsets and ranges. */
    private function settingFrom(array $settings): IqamaTimeSetting
    {
        $model = new IqamaTimeSetting();
        $model->iqama_type = $settings['iqama_type'];

        foreach ($settings['offsets'] as $salah => $minutes) {
            $model->{$salah} = $minutes;
        }

        $model->setRelation('timeRanges', collect($settings['time_ranges'])->map(function (array $row) {
            $range = new IqamaTimeRange();
            $range->forceFill($row);

            return $range;
        }));

        return $model;
    }
}
