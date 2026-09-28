<?php

namespace Tests\Feature\Studio;

use App\Http\Requests\Admin\Onboarding\ProvisionMasjidRequest;
use App\Models\IqamaTimeRange;
use App\Models\IqamaTimeSetting;
use App\Models\JumaaSetting;
use App\Models\Masjid;
use App\Models\StudioDraft;
use App\Support\IqamaResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\ProvisionsStudioDrafts;
use Tests\TestCase;

/**
 * Studio Step 0's fixed iqama times (DECISIONS, 2026-09-27 "Studio Step 0:
 * fixed iqama times and several Jumu'ah times"). NAFIS gave clock times for
 * Fajr, Dhuhr and Asr and minutes for Maghrib and Isha. Each fixed prayer is
 * provisioned as one Specific Time Range from today, where the organisation
 * is, to the until-date the client gave; the rest, and every prayer after
 * that date, are adhan + offset (IqamaResolver). With no fixed time the
 * provision is exactly the minutes-only one (ProvisionIqamaTruthTest and
 * ProvisionResponseSnapshotTest pin that, unedited).
 */
class StudioFixedIqamaTest extends TestCase
{
    use ProvisionsStudioDrafts;
    use RefreshDatabase;

    private const PRAYER = ['method' => 'NorthAmerica', 'madhab' => 'Shafi', 'high_latitude_rule' => 'MiddleOfTheNight'];

    /** NAFIS's answers as the Prayer panel stores them. */
    private const NAFIS = [
        'iqama' => ['maghrib' => 5, 'isha' => 10],
        'iqama_fixed' => ['fajr' => '06:15', 'dhuhr' => '13:45', 'asr' => '17:30'],
        'iqama_fixed_until' => '2026-11-01',
    ];

    private const UNTIL_REQUIRED = 'Enter the date the fixed iqama times hold until, in Foundation.';

    private const UNTIL_WINDOW = 'The date the fixed iqama times hold until must be between today and 400 days from today.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpProvisioning();
        $this->actAsSuperAdmin();

        // Noon in Toronto (the drafts' timezone): the same date there and in UTC.
        $this->travelTo(Carbon::parse('2026-09-27 16:00:00', 'UTC'));
    }

    #[Test]
    public function fixed_fajr_dhuhr_and_asr_with_minutes_for_maghrib_and_isha_provision_three_ranges_and_the_offsets(): void
    {
        $id = $this->provisionPrayer(self::NAFIS)->assertCreated()->json('data.masjid_id');

        $setting = IqamaTimeSetting::where('masjid_id', $id)->firstOrFail();
        $this->assertSame('specific_time_ranges', $setting->getAttributes()['iqama_type']);
        $this->assertTrue((bool) $setting->show_iqama_times, 'all five given, two kinds between them');
        $this->assertSame([0, 0, 0, 5, 10], array_map('intval', [$setting->fajr, $setting->dhuhr, $setting->asr, $setting->maghrib, $setting->isha]),
            'the minutes given; 0 for a fixed prayer given none, never the wizard\'s 20/10/10');

        $this->assertSame([
            ['fajr', '2026-09-27', '2026-11-01', '06:15'],
            ['dhuhr', '2026-09-27', '2026-11-01', '13:45'],
            ['asr', '2026-09-27', '2026-11-01', '17:30'],
        ], $this->ranges($setting));

        // What every consumer asks (the website, the stored prayer rows, the push).
        $resolver = IqamaResolver::for($setting->fresh('timeRanges'), Masjid::findOrFail($id)->timezone);
        $this->assertSame('2026-09-27', $resolver->today());

        $adhan = fn (string $clock, string $day = '2026-09-27') => Carbon::parse("{$day} {$clock}", 'America/Toronto');
        $utc = fn (Carbon $at) => $at->copy()->utc()->format('Y-m-d H:i');

        $this->assertSame($utc($adhan('06:15')), $utc($resolver->iqamaAt('fajr', '2026-09-27', $adhan('05:52'))));
        $this->assertSame($utc($adhan('13:45')), $utc($resolver->iqamaAt('dhuhr', '2026-09-27', $adhan('13:14'))));
        $this->assertSame($utc($adhan('17:30')), $utc($resolver->iqamaAt('asr', '2026-09-27', $adhan('16:35'))));
        $this->assertSame($utc($adhan('19:12')), $utc($resolver->iqamaAt('maghrib', '2026-09-27', $adhan('19:07'))), 'adhan + 5');
        $this->assertSame($utc($adhan('20:35')), $utc($resolver->iqamaAt('isha', '2026-09-27', $adhan('20:25'))), 'adhan + 10');

        // The until-date is the last fixed day; the day after, adhan + offset (0).
        $this->assertSame($utc($adhan('13:45', '2026-11-01')), $utc($resolver->iqamaAt('dhuhr', '2026-11-01', $adhan('12:55', '2026-11-01'))));
        $this->assertSame($utc($adhan('12:55', '2026-11-02')), $utc($resolver->iqamaAt('dhuhr', '2026-11-02', $adhan('12:55', '2026-11-02'))));
    }

    /** 9 PM in Toronto is already tomorrow in UTC; the range starts on the organisation's today. */
    #[Test]
    public function the_ranges_start_on_the_organisations_own_today_not_the_servers(): void
    {
        $this->travelTo(Carbon::parse('2026-09-28 01:00:00', 'UTC'));

        $id = $this->provisionPrayer(['iqama_fixed_until' => '2026-09-27'] + self::NAFIS)
            ->assertCreated()
            ->json('data.masjid_id');

        $setting = IqamaTimeSetting::where('masjid_id', $id)->firstOrFail();
        $this->assertSame(['2026-09-27'], array_values(array_unique(array_column($this->ranges($setting), 1))));
        $this->assertSame(['2026-09-27'], array_values(array_unique(array_column($this->ranges($setting), 2))), 'today where the organisation is may be the until-date');
    }

    #[Test]
    public function fixed_times_without_an_until_date_are_refused(): void
    {
        $prayer = self::NAFIS;
        unset($prayer['iqama_fixed_until']);

        $this->provisionPrayer($prayer)
            ->assertStatus(422)
            ->assertJsonPath('data.iqama_fixed_until.0', self::UNTIL_REQUIRED);

        $this->assertSame(0, Masjid::count());
        $this->assertSame(0, IqamaTimeRange::count());
    }

    #[Test]
    public function an_until_date_in_the_past_or_more_than_400_days_ahead_is_refused(): void
    {
        foreach (['2026-09-26', '2027-11-02'] as $until) {
            $this->provisionPrayer(['iqama_fixed_until' => $until] + self::NAFIS)
                ->assertStatus(422)
                ->assertJsonPath('data.iqama_fixed_until.0', self::UNTIL_WINDOW);
        }

        $this->assertSame(0, Masjid::count());

        // 2027-11-01 is exactly 400 days after 2026-09-27: the last date allowed.
        $id = $this->provisionPrayer(['iqama_fixed_until' => '2027-11-01'] + self::NAFIS)->assertCreated()->json('data.masjid_id');
        $this->assertSame(['2027-11-01'], array_values(array_unique(array_column($this->ranges(IqamaTimeSetting::where('masjid_id', $id)->firstOrFail()), 2))));
    }

    #[Test]
    public function a_prayer_given_neither_minutes_nor_a_fixed_time_is_still_refused_by_name(): void
    {
        $this->provisionPrayer([
            'iqama' => ['isha' => 10],
            'iqama_fixed' => ['fajr' => '06:15', 'dhuhr' => '13:45'],
            'iqama_fixed_until' => '2026-11-01',
        ])
            ->assertStatus(422)
            ->assertJsonPath('data.iqama.0', 'The iqama times are incomplete: Asr, Maghrib are missing. Enter all five in Foundation, or tick "Client has not given iqama times".');

        $this->assertSame(0, Masjid::count());
    }

    #[Test]
    public function a_key_that_is_not_one_of_the_five_prayers_is_refused(): void
    {
        $payload = $this->draftWith($this->studioAnswers(sections: ['prayer' => self::PRAYER + self::NAFIS]), logo: false)->toProvisionPayload();
        unset($payload['layout_preset'], $payload['capabilities'], $payload['slug'], $payload['description']);
        $payload['iqama_fixed']['jumuah'] = '13:30';

        $this->postJson('/api/admin/onboarding/provision', $payload)
            ->assertStatus(422)
            ->assertJsonPath('data', fn ($errors) => isset($errors['iqama_fixed.jumuah']));

        $this->assertSame(0, Masjid::count());
    }

    /**
     * With no fixed time the rows are exactly today's: the iqama row as the
     * minutes-only provision writes it, no range, and the Jumu'ah row as the older draft's single time provisions it.
     * A fixed time cleared on the panel (null) and a stale until-date left
     * behind are not sent at all, so an until-date long past refuses nothing.
     */
    #[Test]
    public function with_no_fixed_time_the_rows_are_exactly_the_minutes_only_ones(): void
    {
        $given = ['fajr' => 25, 'dhuhr' => 0, 'asr' => 12, 'maghrib' => 7, 'isha' => 15];

        foreach ([
            'never fixed' => ['iqama' => $given, 'jumaa_iqama' => '13:15'],
            'fixed cleared, stale date' => ['iqama' => $given, 'jumaa_iqama' => '13:15', 'iqama_fixed' => ['dhuhr' => null], 'iqama_fixed_until' => '2025-01-01'],
        ] as $case => $prayer) {
            $id = $this->provisionPrayer($prayer)->assertCreated()->json('data.masjid_id');

            $setting = IqamaTimeSetting::where('masjid_id', $id)->firstOrFail()->getAttributes();
            unset($setting['id'], $setting['created_at'], $setting['updated_at']);
            $this->assertEquals([
                'masjid_id' => $id,
                'iqama_type' => 'minutes_after_adhan',
                'show_iqama_times' => 1,
                'fajr' => 25,
                'dhuhr' => 0,
                'asr' => 12,
                'maghrib' => 7,
                'isha' => 15,
            ], $setting, $case);
            $this->assertSame(0, IqamaTimeRange::count(), $case);

            // Studio collects khutbah times, so an older draft's lone jumaa_iqama
            // goes out as the list's first entry: an athan, with no iqama.
            $jumaa = JumaaSetting::where('masjid_id', $id)->firstOrFail();
            $this->assertSame(['13:15'], $jumaa->athans, $case);
            $this->assertNull($jumaa->getAttributes()['iqama'], $case);
            $this->assertNull($jumaa->getAttributes()['shifts'] ?? null, $case);
        }
    }

    /** The tick means no times were given: fixed ones typed before it are not provisioned, and need no date. */
    #[Test]
    public function client_has_not_given_iqama_times_provisions_no_fixed_times(): void
    {
        $prayer = self::NAFIS + ['iqama_given' => false];
        unset($prayer['iqama_fixed_until']);

        $id = $this->provisionPrayer($prayer)->assertCreated()->json('data.masjid_id');

        $setting = IqamaTimeSetting::where('masjid_id', $id)->firstOrFail();
        $this->assertFalse((bool) $setting->show_iqama_times);
        $this->assertSame('minutes_after_adhan', $setting->getAttributes()['iqama_type']);
        $this->assertSame(0, IqamaTimeRange::count());
    }

    /**
     * The wizard's endpoint takes the same keys (a direct POST is held to the
     * rules a draft is), posted form-encoded as its SPA posts. Without
     * show_iqama_times it keeps its `true`, but a fixed prayer's missing
     * offset is 0, not its invented one.
     */
    #[Test]
    public function the_wizard_endpoint_provisions_fixed_times_posted_form_encoded(): void
    {
        $payload = $this->draftWith($this->studioAnswers(sections: ['prayer' => self::PRAYER + self::NAFIS]), logo: false)->toProvisionPayload();
        unset($payload['layout_preset'], $payload['capabilities'], $payload['slug'], $payload['description'], $payload['show_iqama_times']);

        $id = $this->post('/api/admin/onboarding/provision', $payload, ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('data.masjid_id');

        $setting = IqamaTimeSetting::where('masjid_id', $id)->firstOrFail();
        $this->assertSame('specific_time_ranges', $setting->getAttributes()['iqama_type']);
        $this->assertTrue((bool) $setting->show_iqama_times);
        $this->assertSame([0, 0, 0, 5, 10], array_map('intval', [$setting->fajr, $setting->dhuhr, $setting->asr, $setting->maghrib, $setting->isha]));
        $this->assertCount(3, $this->ranges($setting));
    }

    /**
     * The apps and push place a fixed time only in the organisation's own
     * zone (IqamaResolver::placesFixedTimes), so under a UTC name they would put
     * iqama on the adhan while the website showed the fixed time (PRAYER-1/2).
     */
    #[Test]
    public function fixed_times_are_refused_for_a_utc_named_timezone(): void
    {
        foreach (['UTC', 'Etc/UTC', 'GMT'] as $zone) {
            $this->provisionPrayer(self::NAFIS, $zone)
                ->assertStatus(422)
                ->assertJsonPath('data.timezone.0', ProvisionMasjidRequest::UTC_FIXED_REFUSAL);
        }

        $this->assertSame('Fixed iqama times need the organisation\'s own timezone, such as Europe/London. UTC cannot place them.', ProvisionMasjidRequest::UTC_FIXED_REFUSAL);
        $this->assertSame(0, Masjid::count());
        $this->assertSame(0, IqamaTimeRange::count());

        // Through the wizard's endpoint too.
        $payload = $this->draftWith($this->studioAnswers(sections: ['prayer' => self::PRAYER + self::NAFIS]), logo: false)->toProvisionPayload();
        unset($payload['layout_preset'], $payload['capabilities'], $payload['slug'], $payload['description']);
        $payload['timezone'] = 'UTC';
        $this->postJson('/api/admin/onboarding/provision', $payload)
            ->assertStatus(422)
            ->assertJsonPath('data.timezone.0', ProvisionMasjidRequest::UTC_FIXED_REFUSAL);
        $this->assertSame(0, Masjid::count());
    }

    /** Offsets need no zone: a UTC-named organisation with none fixed still provisions. */
    #[Test]
    public function a_utc_named_timezone_with_offsets_only_still_provisions(): void
    {
        $given = ['iqama' => ['fajr' => 25, 'dhuhr' => 10, 'asr' => 12, 'maghrib' => 7, 'isha' => 15]];

        foreach (['UTC', 'Etc/UTC'] as $zone) {
            $this->provisionPrayer($given, $zone)->assertCreated();
        }

        $this->assertSame(2, Masjid::count());
        $this->assertSame(0, IqamaTimeRange::count());
    }

    #[Test]
    public function the_draft_holds_fixed_times_and_the_until_date_and_refuses_other_keys(): void
    {
        $draft = $this->newDraft(['org_type' => 'masjid']);

        $this->patchDraft($draft['id'], $draft['lock_version'], ['prayer' => self::NAFIS])
            ->assertOk()
            ->assertJsonPath('data.answers.prayer.iqama_fixed', self::NAFIS['iqama_fixed'])
            ->assertJsonPath('data.answers.prayer.iqama_fixed_until', '2026-11-01');

        $saved = StudioDraft::findOrFail($draft['id']);
        $this->assertSame(self::NAFIS['iqama_fixed'], $saved->section('prayer')['iqama_fixed']);

        // A past date is saved (a draft may sit); provisioning is what refuses it.
        $this->patchDraft($draft['id'], $saved->lock_version, ['prayer' => ['iqama_fixed_until' => '2025-01-01'] + self::NAFIS])->assertOk();

        $this->patchDraft($draft['id'], $saved->lock_version + 1, ['prayer' => ['iqama_fixed' => ['jumuah' => '13:30']]])
            ->assertStatus(422)
            ->assertJsonPath('data', fn ($errors) => isset($errors['answers.prayer.iqama_fixed.jumuah']));

        $this->patchDraft($draft['id'], $saved->lock_version + 1, ['prayer' => ['iqama_fixed' => ['dhuhr' => '1:45 PM']]])
            ->assertStatus(422)
            ->assertJsonPath('data', fn ($errors) => isset($errors['answers.prayer.iqama_fixed.dhuhr']));
    }

    /** Provision a complete masjid draft whose prayer section is PRAYER plus $prayer. */
    private function provisionPrayer(array $prayer, ?string $timezone = null): \Illuminate\Testing\TestResponse
    {
        $answers = $this->studioAnswers(sections: ['prayer' => self::PRAYER + $prayer]);
        if ($timezone !== null) {
            $answers['identity']['timezone'] = $timezone;
        }

        return $this->provision($this->draftWith($answers)->id);
    }

    /** @return list<array{0: string, 1: string, 2: string, 3: string}> [salah, start, end, HH:MM] in saved order */
    private function ranges(IqamaTimeSetting $setting): array
    {
        return $setting->timeRanges()->get()->map(fn (IqamaTimeRange $range) => [
            $range->salah,
            $range->start_date->format('Y-m-d'),
            $range->end_date->format('Y-m-d'),
            substr((string) $range->specific_time, 0, 5),
        ])->all();
    }
}
