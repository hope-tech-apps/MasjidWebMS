<?php

namespace Tests\Unit;

use App\Enums\HighLatitudeRule as HighLatitudeRuleEnum;
use App\Enums\Madhab as MadhabEnum;
use App\Enums\PrayerCalculationMethod;
use App\Models\PrayerCalculationSetting;
use App\Services\PrayerTimes\HighLatitudeRule;
use App\Services\PrayerTimes\Madhab;
use App\Services\PrayerTimes\SettingsCalculationParameters;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ValueError;

/**
 * The stored-setting -> calculation-parameters mapping.
 *
 * The parameters it produces are checked against
 * tests/fixtures/adhan-prayer-times.json — payloads emitted by adhan-js itself
 * — rather than against the PHP port, so the mapping table is pinned by the
 * library it is supposed to agree with and not by the code under test. The
 * StudlyCase <-> lowercase table below is restated here independently; if the
 * implementation ever disagreed with it, these fail.
 *
 * No database. The models are hydrated with setRawAttributes(), which is
 * literally what Eloquent does when it builds a model from a row, so the
 * corrupt-value cases here behave exactly as a corrupt row would.
 */
class SettingsCalculationParametersTest extends TestCase
{
    /** Stored enum value -> the serialized form adhan writes for the madhab. */
    private const MADHAB_SERIALIZED = [
        'Shafi' => 'shafi',
        'Hanafi' => 'hanafi',
    ];

    /** Stored enum value -> the serialized form adhan writes for the rule. */
    private const RULE_SERIALIZED = [
        'MiddleOfTheNight' => 'middleofthenight',
        'SeventhOfTheNight' => 'seventhofthenight',
        'TwilightAngle' => 'twilightangle',
    ];

    /**
     * Hydrate a settings row the way Eloquent hydrates one from the database:
     * raw column values, no casting, no validation.
     */
    private function row(?string $method, ?string $madhab, ?string $rule, int $masjidId = 7): PrayerCalculationSetting
    {
        $settings = new PrayerCalculationSetting();

        $settings->setRawAttributes([
            'id' => 42,
            'masjid_id' => $masjidId,
            'method' => $method,
            'madhab' => $madhab,
            'high_latitude_rule' => $rule,
        ], true);

        return $settings;
    }

    /**
     * @return list<array{method: string, madhab: string, highLatitudeRule: string, calculationParameters: array<string, mixed>}>
     */
    private function goldenCombinations(): array
    {
        $vectors = json_decode(file_get_contents(__DIR__.'/../fixtures/adhan-prayer-times.json'), true);

        $seen = [];

        foreach ($vectors as $vector) {
            $overrides = $vector['overrides'];

            if (!isset($overrides['madhab'], $overrides['highLatitudeRule'])) {
                continue;
            }

            $key = $vector['method'].'|'.$overrides['madhab'].'|'.$overrides['highLatitudeRule'];

            $seen[$key] ??= [
                'method' => $vector['method'],
                'madhab' => $overrides['madhab'],
                'highLatitudeRule' => $overrides['highLatitudeRule'],
                'calculationParameters' => $vector['expected']['calculationParameters'],
            ];
        }

        return array_values($seen);
    }

    #[Test]
    public function it_maps_every_stored_combination_onto_the_parameters_adhan_javascript_produces(): void
    {
        $combinations = $this->goldenCombinations();

        // 12 methods x 2 madhabs x 3 high latitude rules. Asserted so a
        // truncated fixture cannot make this test pass by covering nothing.
        $this->assertCount(72, $combinations);

        $methodsCovered = [];

        foreach ($combinations as $combination) {
            $storedMadhab = array_search($combination['madhab'], self::MADHAB_SERIALIZED, true);
            $storedRule = array_search($combination['highLatitudeRule'], self::RULE_SERIALIZED, true);

            $this->assertNotFalse($storedMadhab, "Fixture madhab {$combination['madhab']} is not in the mapping table.");
            $this->assertNotFalse($storedRule, "Fixture rule {$combination['highLatitudeRule']} is not in the mapping table.");

            $params = SettingsCalculationParameters::fromSetting(
                $this->row($combination['method'], $storedMadhab, $storedRule)
            );

            // Compared as JSON text: the key ORDER and the int-vs-float
            // rendering (18 not 18.0) are part of what gets stored, and an
            // array comparison would notice neither.
            $this->assertSame(
                json_encode($combination['calculationParameters']),
                json_encode($params->toArray()),
                "{$combination['method']} / {$storedMadhab} / {$storedRule}"
            );

            $methodsCovered[$combination['method']] = true;
        }

        // Every case the admin UI can actually select is exercised above.
        foreach (PrayerCalculationMethod::cases() as $case) {
            $this->assertArrayHasKey($case->value, $methodsCovered, "{$case->value} was never mapped.");
        }
    }

    #[Test]
    public function an_absent_settings_row_produces_the_historical_default_parameters(): void
    {
        // The `calculationParameters` block of production prayers row id=74,
        // written by the Node script before the port existed. A masjid with no
        // settings row must still calculate with exactly this.
        $historical = '{"madhab":"shafi","highLatitudeRule":"middleofthenight","adjustments":{"fajr":0,"sunrise":0,"dhuhr":0,"asr":0,"maghrib":0,"isha":0},"methodAdjustments":{"fajr":0,"sunrise":0,"dhuhr":5,"asr":0,"maghrib":3,"isha":0},"polarCircleResolution":"Unresolved","rounding":"nearest","shafaq":"general","method":"MoonsightingCommittee","fajrAngle":18,"ishaAngle":18,"ishaInterval":0,"maghribAngle":0}';

        $this->assertSame(
            $historical,
            json_encode(SettingsCalculationParameters::fromSetting(null)->toArray())
        );

        // ...and so must a row that spells those defaults out, through the same
        // mapping rather than through a separate branch.
        $this->assertSame(
            $historical,
            json_encode(SettingsCalculationParameters::fromSetting(
                $this->row('MoonsightingCommittee', 'Shafi', 'MiddleOfTheNight')
            )->toArray())
        );

        // The constant the controllers compare against is that same triple.
        $this->assertSame(
            ['method' => 'MoonsightingCommittee', 'madhab' => 'Shafi', 'high_latitude_rule' => 'MiddleOfTheNight'],
            SettingsCalculationParameters::DEFAULTS
        );
    }

    #[Test]
    public function an_unrecognised_method_degrades_to_moonsighting_committee_rather_than_a_neighbour(): void
    {
        foreach (['Nonsense', '', 'moonsightingcommittee', 'Other', 'MuslimWorldLeagu'] as $garbage) {
            $params = SettingsCalculationParameters::fromSetting($this->row($garbage, 'Shafi', 'MiddleOfTheNight'));

            $this->assertSame('MoonsightingCommittee', $params->method, "stored: {$garbage}");
            $this->assertSame(18.0, $params->fajrAngle);
            $this->assertSame(5, $params->methodAdjustments['dhuhr']);
        }
    }

    #[Test]
    public function an_unrecognised_madhab_or_high_latitude_rule_degrades_to_the_default(): void
    {
        foreach (['Maliki', 'shafi', '', 'Hanbali'] as $garbage) {
            $this->assertSame(
                Madhab::SHAFI,
                SettingsCalculationParameters::fromSetting($this->row('Karachi', $garbage, 'MiddleOfTheNight'))->madhab,
                "stored madhab: {$garbage}"
            );
        }

        foreach (['AngleBased', 'middleofthenight', '', 'OneSeventh'] as $garbage) {
            $this->assertSame(
                HighLatitudeRule::MIDDLE_OF_THE_NIGHT,
                SettingsCalculationParameters::fromSetting($this->row('Karachi', 'Shafi', $garbage))->highLatitudeRule,
                "stored rule: {$garbage}"
            );
        }

        // Degrading one column must not drag the others down with it: the
        // method the admin actually chose survives a bad madhab.
        $params = SettingsCalculationParameters::fromSetting($this->row('Karachi', 'Maliki', 'TwilightAngle'));
        $this->assertSame('Karachi', $params->method);
        $this->assertSame(HighLatitudeRule::TWILIGHT_ANGLE, $params->highLatitudeRule);
    }

    #[Test]
    public function a_row_whose_every_column_is_unusable_still_produces_the_historical_default(): void
    {
        foreach ([$this->row('junk', 'junk', 'junk'), $this->row(null, null, null)] as $settings) {
            $this->assertSame(
                '{"madhab":"shafi","highLatitudeRule":"middleofthenight","adjustments":{"fajr":0,"sunrise":0,"dhuhr":0,"asr":0,"maghrib":0,"isha":0},"methodAdjustments":{"fajr":0,"sunrise":0,"dhuhr":5,"asr":0,"maghrib":3,"isha":0},"polarCircleResolution":"Unresolved","rounding":"nearest","shafaq":"general","method":"MoonsightingCommittee","fajrAngle":18,"ishaAngle":18,"ishaInterval":0,"maghribAngle":0}',
                json_encode(SettingsCalculationParameters::fromSetting($settings)->toArray())
            );
        }
    }

    #[Test]
    public function the_enum_cast_throws_on_access_not_on_hydration_which_is_what_lets_the_mapper_catch_it(): void
    {
        $settings = $this->row('NotAMethod', 'Shafi', 'MiddleOfTheNight');

        // Hydration is unaffected — the raw column is stored untouched, so a
        // corrupt row loads (and eager-loads) perfectly happily. If this ever
        // started throwing, the problem would move upstream of the mapper and
        // would have to be handled where the model is loaded instead.
        $this->assertSame('NotAMethod', $settings->getAttributes()['method']);

        // Laravel resolves an enum cast with BackedEnum::from(), which raises a
        // ValueError rather than returning null. This is the throw the mapper
        // exists to absorb.
        try {
            $cast = $settings->method;
            $this->fail('Expected the enum cast to throw, got '.var_export($cast, true));
        } catch (ValueError $e) {
            $this->assertStringContainsString('NotAMethod', $e->getMessage());
        }

        // Serializing the model hits the same cast, which is why the mapper
        // reads raw attributes instead of leaning on $settings->method.
        try {
            $settings->toArray();
            $this->fail('Expected model serialization to throw on a value that is not a case.');
        } catch (ValueError $e) {
            $this->assertStringContainsString('NotAMethod', $e->getMessage());
        }

        // The mapper, over the same object, does not.
        $this->assertSame(
            'MoonsightingCommittee',
            SettingsCalculationParameters::fromSetting($settings)->method
        );
    }

    #[Test]
    public function a_column_holding_a_backed_enum_instance_maps_the_same_as_the_raw_string(): void
    {
        // What $settings->method actually hands back on a healthy row. (Eloquent
        // stores the backing value on assignment, so this also covers an unsaved
        // in-memory change.)
        $settings = new PrayerCalculationSetting();
        $settings->masjid_id = 7;
        $settings->method = PrayerCalculationMethod::TURKEY;
        $settings->madhab = MadhabEnum::HANAFI;
        $settings->high_latitude_rule = HighLatitudeRuleEnum::TWILIGHT_ANGLE;

        $this->assertInstanceOf(PrayerCalculationMethod::class, $settings->method);

        $params = SettingsCalculationParameters::fromSetting($settings);

        $this->assertSame('Turkey', $params->method);
        $this->assertSame(Madhab::HANAFI, $params->madhab);
        $this->assertSame(HighLatitudeRule::TWILIGHT_ANGLE, $params->highLatitudeRule);
        $this->assertSame(-7, $params->methodAdjustments['sunrise']);
    }

    #[Test]
    public function each_call_returns_a_fresh_object_so_one_masjid_cannot_poison_the_next(): void
    {
        $settings = $this->row('Singapore', 'Hanafi', 'TwilightAngle');

        $first = SettingsCalculationParameters::fromSetting($settings);
        $first->fajrAngle = 99;
        $first->methodAdjustments['dhuhr'] = 99;
        $first->madhab = Madhab::SHAFI;

        $second = SettingsCalculationParameters::fromSetting($settings);

        $this->assertNotSame($first, $second);
        $this->assertSame(20.0, $second->fajrAngle);
        $this->assertSame(1, $second->methodAdjustments['dhuhr']);
        $this->assertSame(Madhab::HANAFI, $second->madhab);
        // Singapore is the only method that ships a non-default rounding.
        $this->assertSame('up', $second->rounding);
    }

    #[Test]
    public function a_degraded_setting_is_logged_with_the_masjid_that_carries_it(): void
    {
        Log::spy();

        SettingsCalculationParameters::fromSetting($this->row('NotAMethod', 'Shafi', 'MiddleOfTheNight', 314));

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return str_contains($message, 'Unusable prayer calculation setting')
                    && $context['masjid_id'] === 314
                    && $context['column'] === 'method'
                    && $context['stored'] === 'NotAMethod'
                    && $context['fallback'] === 'MoonsightingCommittee';
            });
    }

    #[Test]
    public function a_healthy_row_logs_nothing(): void
    {
        Log::spy();

        SettingsCalculationParameters::fromSetting($this->row('Qatar', 'Hanafi', 'SeventhOfTheNight'));
        SettingsCalculationParameters::fromSetting(null);

        Log::shouldNotHaveReceived('warning');
    }
}
