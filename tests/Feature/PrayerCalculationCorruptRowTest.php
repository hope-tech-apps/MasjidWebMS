<?php

namespace Tests\Feature;

use App\Models\Masjid;
use App\Models\PrayerCalculationSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A corrupt `prayer_calculation_settings` row must not take down a READ.
 *
 * ## The bug
 *
 * The model casts `method` / `madhab` / `high_latitude_rule` to backed enums,
 * and Laravel resolves an enum cast with `BackedEnum::from()`
 * (HasAttributes::getEnumCaseFromValue) — which raises a `ValueError`, not a
 * null, for a string that matches no case. The cast is applied LAZILY on
 * attribute access, so a row holding a bad string hydrates fine and detonates
 * when something reads the column.
 *
 * `PrayersController::prayersSettings()` was fixed for exactly this. Two readers
 * were left:
 *
 *   - `AdminDashboard\PrayerCalculationSettingsController::index()` returned the
 *     MODEL, and serializing it reads every column through its cast. So the
 *     admin screen whose entire purpose is to fix the bad value 500'd while
 *     loading the form. The write path could already repair the row — the admin
 *     just could not reach it.
 *   - `Api\V1\PrayerCalculationSettingResource` read `$this->method->value`,
 *     which took down the WHOLE `/api/v1/settings` document (masjid details,
 *     theme, features, iqama, jumaa) over one bad column, on a public read.
 *
 * `ValueError` extends `Error`, not `Exception`, so the `catch (\Exception)` in
 * the admin controller never saw it either; it surfaced through the global JSON
 * renderer as a bare 500.
 *
 * ## How a bad value gets in
 *
 * NOT through the model — the enum SETTER runs the same `from()` and throws on
 * assignment, so Eloquent cannot write one. It arrives by raw SQL, a migration
 * default that drifted from the enum, or a column written before a case was
 * renamed. `DB::table()->insert()` below is therefore not a contrivance; it is
 * the only way the state can arise, and the same technique
 * PrayerCalculationSettingsWiringTest already uses.
 *
 * ## What is asserted
 *
 * That both readers answer 200 with the EFFECTIVE settings — what the server is
 * really calculating with, via the one total accessor
 * SettingsCalculationParameters::effectiveTriple() — and that a healthy row is
 * still reported verbatim, because "always answer MoonsightingCommittee" would
 * pass a naive 200 check while quietly lying to every masjid.
 */
class PrayerCalculationCorruptRowTest extends TestCase
{
    use RefreshDatabase;

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
    }

    // ------------------------------------------------------------- fixtures

    private function makeMasjid(): Masjid
    {
        return Masjid::create([
            'name' => 'Test Masjid '.uniqid(),
            'email' => 'masjid-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => '35.78056000',
            'longitude' => '-78.63890000',
            'timezone' => 'America/New_York',
        ]);
    }

    /**
     * Write a settings row PAST Eloquent. The enum setter would reject these,
     * which is precisely why the corrupt state can only be created this way.
     *
     * @param  array<string, string>  $triple
     */
    private function plantRow(Masjid $masjid, array $triple): void
    {
        DB::table('prayer_calculation_settings')->insert(array_merge(
            ['masjid_id' => $masjid->id, 'created_at' => now(), 'updated_at' => now()],
            $triple
        ));
    }

    private function actingAsSuperAdmin(): void
    {
        Sanctum::actingAs(User::factory()->create([
            'type' => 'SuperAdmin',
            'phone' => '+1'.random_int(1000000000, 9999999999),
        ]));
    }

    // ------------------------------------------------- the admin read (index)

    #[Test]
    public function the_admin_read_answers_200_for_a_row_that_used_to_500_it(): void
    {
        $masjid = $this->makeMasjid();
        $this->plantRow($masjid, [
            'method' => 'CouncilOfNowhere',
            'madhab' => 'Maliki',
            'high_latitude_rule' => 'AngleBased',
        ]);

        $this->actingAsSuperAdmin();

        $this->getJson("/api/admin/masjids/{$masjid->id}/prayer-calculation")
            ->assertOk()
            ->assertJsonPath('status', 'success')
            // The EFFECTIVE triple: what every other surface is already
            // calculating this masjid with.
            ->assertJsonPath('data.method', 'MoonsightingCommittee')
            ->assertJsonPath('data.madhab', 'Shafi')
            ->assertJsonPath('data.high_latitude_rule', 'MiddleOfTheNight');
    }

    #[Test]
    public function only_the_unusable_column_degrades(): void
    {
        $masjid = $this->makeMasjid();
        $this->plantRow($masjid, [
            'method' => 'Karachi',
            'madhab' => 'Maliki',            // the only bad one
            'high_latitude_rule' => 'TwilightAngle',
        ]);

        $this->actingAsSuperAdmin();

        // A blanket "return the defaults on any problem" would pass the test
        // above and fail this one.
        $this->getJson("/api/admin/masjids/{$masjid->id}/prayer-calculation")
            ->assertOk()
            ->assertJsonPath('data.method', 'Karachi')
            ->assertJsonPath('data.madhab', 'Shafi')
            ->assertJsonPath('data.high_latitude_rule', 'TwilightAngle');
    }

    #[Test]
    public function a_healthy_row_is_reported_verbatim_with_its_identity_intact(): void
    {
        $masjid = $this->makeMasjid();
        $settings = PrayerCalculationSetting::create([
            'masjid_id' => $masjid->id,
            'method' => 'Turkey',
            'madhab' => 'Hanafi',
            'high_latitude_rule' => 'SeventhOfTheNight',
        ]);

        $this->actingAsSuperAdmin();

        $this->getJson("/api/admin/masjids/{$masjid->id}/prayer-calculation")
            ->assertOk()
            ->assertJsonPath('data.method', 'Turkey')
            ->assertJsonPath('data.madhab', 'Hanafi')
            ->assertJsonPath('data.high_latitude_rule', 'SeventhOfTheNight')
            // The SPA reads the three above, but the payload used to be the
            // serialized model — keep its identity fields so nothing that
            // reads them breaks.
            ->assertJsonPath('data.id', $settings->id)
            ->assertJsonPath('data.masjid_id', $masjid->id)
            ->assertJsonStructure(['data' => ['id', 'masjid_id', 'method', 'madhab', 'high_latitude_rule', 'created_at', 'updated_at']]);
    }

    #[Test]
    public function a_masjid_with_no_row_at_all_still_reports_null(): void
    {
        $masjid = $this->makeMasjid();

        $this->actingAsSuperAdmin();

        // Deliberately NOT the effective defaults. `data: null` is what leaves
        // the admin form blank so somebody is asked to choose, rather than
        // showing them settings they never saved as though they had.
        $this->getJson("/api/admin/masjids/{$masjid->id}/prayer-calculation")
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    #[Test]
    public function the_admin_can_now_reach_the_form_and_repair_the_row(): void
    {
        $masjid = $this->makeMasjid();
        $this->plantRow($masjid, [
            'method' => 'CouncilOfNowhere',
            'madhab' => 'Maliki',
            'high_latitude_rule' => 'AngleBased',
        ]);

        $this->actingAsSuperAdmin();

        // 1. The screen loads (this is the step that used to 500).
        $this->getJson("/api/admin/masjids/{$masjid->id}/prayer-calculation")->assertOk();

        // 2. The write path repairs it — as it always could.
        $this->postJson("/api/admin/masjids/{$masjid->id}/prayer-calculation", [
            'method' => 'NorthAmerica',
            'madhab' => 'Hanafi',
            'high_latitude_rule' => 'TwilightAngle',
        ])->assertOk()->assertJsonPath('data.method', 'NorthAmerica');

        // 3. The stored column is genuinely fixed, not merely reported fixed.
        $raw = DB::table('prayer_calculation_settings')->where('masjid_id', $masjid->id)->first();
        $this->assertSame('NorthAmerica', $raw->method);
        $this->assertSame('Hanafi', $raw->madhab);
        $this->assertSame('TwilightAngle', $raw->high_latitude_rule);

        $this->getJson("/api/admin/masjids/{$masjid->id}/prayer-calculation")
            ->assertOk()
            ->assertJsonPath('data.madhab', 'Hanafi');
    }

    // ------------------------------------- the public web read (the resource)

    #[Test]
    public function the_v1_settings_document_survives_a_corrupt_row(): void
    {
        $masjid = $this->makeMasjid();
        $this->plantRow($masjid, [
            'method' => 'CouncilOfNowhere',
            'madhab' => 'Maliki',
            'high_latitude_rule' => 'AngleBased',
        ]);

        $response = $this->getJson('/api/v1/settings', ['masjid-id' => $masjid->id])
            ->assertOk()
            ->assertJsonPath('data.prayer_calculation.method', 'MoonsightingCommittee')
            ->assertJsonPath('data.prayer_calculation.madhab', 'Shafi')
            ->assertJsonPath('data.prayer_calculation.high_latitude_rule', 'MiddleOfTheNight');

        // The point of the fix, beyond the status code: one bad column no
        // longer takes the rest of the document with it.
        $response->assertJsonPath('data.masjid.name', $masjid->name);
    }

    #[Test]
    public function the_v1_settings_document_still_reports_a_healthy_row_verbatim(): void
    {
        $masjid = $this->makeMasjid();
        PrayerCalculationSetting::create([
            'masjid_id' => $masjid->id,
            'method' => 'UmmAlQura',
            'madhab' => 'Hanafi',
            'high_latitude_rule' => 'SeventhOfTheNight',
        ]);

        $this->getJson('/api/v1/settings', ['masjid-id' => $masjid->id])
            ->assertOk()
            ->assertJsonPath('data.prayer_calculation.method', 'UmmAlQura')
            ->assertJsonPath('data.prayer_calculation.madhab', 'Hanafi')
            ->assertJsonPath('data.prayer_calculation.high_latitude_rule', 'SeventhOfTheNight');
    }

    #[Test]
    public function the_v1_settings_document_still_reports_null_when_there_is_no_row(): void
    {
        $masjid = $this->makeMasjid();

        // Unchanged: SettingController decides this, not the resource.
        $this->getJson('/api/v1/settings', ['masjid-id' => $masjid->id])
            ->assertOk()
            ->assertJsonPath('data.prayer_calculation', null);
    }

    #[Test]
    public function the_effective_settings_are_what_the_mobile_sync_endpoint_already_reports(): void
    {
        $masjid = $this->makeMasjid();
        $this->plantRow($masjid, [
            'method' => 'CouncilOfNowhere',
            'madhab' => 'Maliki',
            'high_latitude_rule' => 'AngleBased',
        ]);

        $mobile = $this->getJson("/api/mobile/masjids/{$masjid->id}/prayers/settings")
            ->assertOk()
            ->json('data.calculation');

        $web = $this->getJson('/api/v1/settings', ['masjid-id' => $masjid->id])
            ->assertOk()
            ->json('data.prayer_calculation');

        $this->actingAsSuperAdmin();
        $admin = $this->getJson("/api/admin/masjids/{$masjid->id}/prayer-calculation")
            ->assertOk()
            ->json('data');

        // Three surfaces, one answer. A client that calculated locally from a
        // value the server rejects and falls back on would recreate, one level
        // down, the app-vs-server divergence all of this exists to remove.
        $this->assertSame($mobile, $web);
        $this->assertSame($mobile, [
            'method' => $admin['method'],
            'madhab' => $admin['madhab'],
            'high_latitude_rule' => $admin['high_latitude_rule'],
        ]);
    }
}
