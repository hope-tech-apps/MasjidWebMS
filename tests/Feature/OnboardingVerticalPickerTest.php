<?php

namespace Tests\Feature;

use App\Models\Masjid;
use App\Models\MasjidMobileAppFeature;
use App\Models\MobileAppFeature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The onboarding wizard's vertical picker (PLAN T-002 follow-through).
 *
 * The API has accepted `org_type` since the verticals work landed;
 * `OnboardingWizardView.vue` never asked, so creating a school meant calling the
 * endpoint by hand. This suite covers the contract the wizard now depends on:
 *
 *  - `/onboarding/options` serves the verticals STRAIGHT FROM
 *    `config/verticals.php`, so the SPA renders what provisioning will actually
 *    do instead of a second copy of the same facts;
 *  - the wizard's pre-selected default AGREES with the fallback
 *    `ProvisionMasjidRequest` applies to a payload that omits `org_type` — the
 *    two are the same constant and this proves it rather than assuming it;
 *  - the exact payload the wizard posts for a school produces a school tenant
 *    that reads back with School terminology and the school feature bundle, i.e.
 *    with no worship modules.
 *
 * ProvisionOrgTypeTest already covers provisioning per vertical at the API
 * level. This one covers the WIZARD's half: what it is told, and what it sends.
 */
class OnboardingVerticalPickerTest extends TestCase
{
    use RefreshDatabase;

    /** Full seeded catalog (MobileAppFeaturesSeeder) — equal to the masjid bundle. */
    private const FEATURE_KEYS = [
        'quran', 'hadith', 'adhkar', 'qibla', 'tasbih', 'donate',
        'about_us', 'gallery', 'services', 'announcements', 'contact_us',
    ];

    private const WORSHIP_KEYS = ['adhkar', 'hadith', 'qibla', 'quran', 'tasbih'];

    private const WIZARD_VIEW = 'resources/vue-app/views/dashboard/super/OnboardingWizardView.vue';

    private int $cityId;

    private int $countryId;

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

        $this->countryId = DB::table('countries')->insertGetId(['name' => 'Canada', 'code' => 'CA']);
        $this->cityId = DB::table('cities')->insertGetId([
            'name' => 'Burlington',
            'country_id' => $this->countryId,
        ]);

        foreach (self::FEATURE_KEYS as $key) {
            MobileAppFeature::create(['name' => ucfirst($key), 'key' => $key]);
        }
    }

    #[Test]
    public function the_options_endpoint_offers_every_vertical_with_its_bundle_and_terminology(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $verticals = $this->getJson('/api/admin/onboarding/options')
            ->assertOk()
            ->json('data.verticals');

        // Masjid::ORG_TYPES is the authority on the set, so the picker cannot
        // silently offer fewer verticals than provisioning accepts.
        $this->assertSame(Masjid::ORG_TYPES, array_column($verticals, 'org_type'));

        foreach ($verticals as $vertical) {
            $config = config('verticals.' . $vertical['org_type']);

            $this->assertSame($config['label'], $vertical['label']);
            $this->assertSame($config['plural'], $vertical['plural']);
            $this->assertSame(array_values($config['feature_keys']), $vertical['feature_keys']);
            $this->assertSame($config['terminology'], $vertical['terminology']);
        }
    }

    #[Test]
    public function the_school_bundle_the_picker_advertises_contains_no_worship_modules(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $school = collect($this->getJson('/api/admin/onboarding/options')->json('data.verticals'))
            ->firstWhere('org_type', 'school');

        $this->assertNotNull($school, 'the options payload offers no school vertical');
        $this->assertSame('School', $school['label']);
        $this->assertSame('Families', $school['terminology']['members']);
        $this->assertSame('Classrooms', $school['terminology']['groups']);

        foreach (self::WORSHIP_KEYS as $key) {
            $this->assertNotContains(
                $key,
                $school['feature_keys'],
                "the wizard would promise a school the {$key} module"
            );
        }
    }

    /**
     * The wizard pre-selects a vertical; the request supplies one when the field
     * is absent. If those two ever disagree, the wizard shows one thing and
     * provisions another. Rather than asserting a literal on both sides, this
     * provisions WITHOUT an org_type and compares what the request chose to what
     * the options endpoint tells the wizard to pre-select.
     */
    #[Test]
    public function the_wizards_default_vertical_is_the_one_the_request_falls_back_to(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $advertisedDefault = $this->getJson('/api/admin/onboarding/options')
            ->assertOk()
            ->json('data.default_org_type');

        $created = $this->provision();
        $created->assertCreated();

        $masjid = Masjid::findOrFail($created->json('data.masjid_id'));

        $this->assertSame($masjid->orgType(), $advertisedDefault);
        $this->assertSame(Masjid::ORG_TYPE_MASJID, $advertisedDefault);
    }

    /**
     * End to end through the wizard's own payload shape: read the options, take
     * the school's bundle from it exactly as the SPA does, and post.
     *
     * This is the test that would have caught the real trap. The wizard always
     * sends `feature_keys_provided`, so its checkbox state OVERRIDES the
     * backend's vertical default — before this change it pre-checked the whole
     * catalog, so picking "School" would still have provisioned Qur'an, Adhkar
     * and Qibla.
     */
    #[Test]
    public function the_wizard_payload_for_a_school_creates_a_school_with_the_school_bundle(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $options = $this->getJson('/api/admin/onboarding/options')->assertOk()->json('data');

        $school = collect($options['verticals'])->firstWhere('org_type', 'school');
        $catalogKeys = array_column($options['features'], 'key');

        // What the wizard checks by default: the vertical's bundle, narrowed to
        // keys the catalog actually ships (applyVerticalFeatureDefaults()).
        $checked = array_values(array_intersect($school['feature_keys'], $catalogKeys));

        $created = $this->provision([
            'org_type' => 'school',
            'feature_keys_provided' => '1',
            'feature_keys' => $checked,
        ]);

        $created->assertCreated()
            ->assertJsonPath('data.masjid.vertical.org_type', 'school')
            ->assertJsonPath('data.masjid.vertical.label', 'School');

        $masjid = Masjid::findOrFail($created->json('data.masjid_id'));
        $this->assertTrue($masjid->isSchool());

        // Terminology as the admin SPA will read it back.
        $readBack = $this->getJson("/api/admin/masjids/{$masjid->id}")->assertOk();
        $readBack->assertJsonPath('data.org_type', 'school')
            ->assertJsonPath('data.vertical.terminology.members', 'Families')
            ->assertJsonPath('data.vertical.terminology.groups', 'Classrooms');

        $this->assertEqualsCanonicalizing(
            config('verticals.school.terminology'),
            $readBack->json('data.vertical.terminology')
        );

        // Feature bundle: exactly the advertised set, and no worship modules.
        $enabled = $this->enabledKeys($masjid);
        sort($checked);
        $sortedEnabled = $enabled;
        sort($sortedEnabled);
        $this->assertSame($checked, $sortedEnabled);

        foreach (self::WORSHIP_KEYS as $key) {
            $this->assertNotContains($key, $enabled, "a school was provisioned with {$key}");
        }
    }

    /**
     * Behaviour-neutrality for the vertical every existing tenant is: choosing
     * Masjid in the picker must still switch the entire catalog on, because the
     * masjid bundle IS the full catalog (.claude/rules/verticals.md).
     */
    #[Test]
    public function the_wizard_payload_for_a_masjid_still_enables_the_whole_catalog(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $options = $this->getJson('/api/admin/onboarding/options')->assertOk()->json('data');
        $masjidVertical = collect($options['verticals'])->firstWhere('org_type', 'masjid');
        $catalogKeys = array_column($options['features'], 'key');

        $checked = array_values(array_intersect($masjidVertical['feature_keys'], $catalogKeys));

        $this->assertEqualsCanonicalizing(
            $catalogKeys,
            $checked,
            'the masjid bundle has drifted from the seeded catalog — provisioning a masjid would now enable fewer features than before'
        );

        $created = $this->provision([
            'org_type' => 'masjid',
            'feature_keys_provided' => '1',
            'feature_keys' => $checked,
        ]);

        $created->assertCreated();

        $masjid = Masjid::findOrFail($created->json('data.masjid_id'));
        $this->assertSame(self::FEATURE_KEYS, $this->enabledKeys($masjid));
    }

    /**
     * The picker's copy has to come from the backend, not from a second hand-typed
     * copy of `config/verticals.php` in the SPA — that is exactly how the two
     * drift. Guard the distinctive labels of each pack: none of them may appear
     * as a literal in the wizard.
     */
    #[Test]
    public function the_wizard_does_not_retype_the_vertical_configuration(): void
    {
        $source = file_get_contents(base_path(self::WIZARD_VIEW));

        $this->assertNotFalse($source, 'the onboarding wizard view is missing');

        foreach (['Congregants', 'Halaqat', 'Families', 'Classrooms', 'Imams', 'Faculty'] as $label) {
            $this->assertStringNotContainsString(
                $label,
                $source,
                "'{$label}' is hardcoded in the wizard — the terminology pack must come from /onboarding/options"
            );
        }

        foreach (self::WORSHIP_KEYS as $key) {
            $this->assertStringNotContainsString(
                "'{$key}'",
                $source,
                "the wizard hardcodes the '{$key}' feature key — the bundle must come from /onboarding/options"
            );
        }

        // And it must actually consume the two fields this suite pins.
        $this->assertStringContainsString('d.verticals', $source);
        $this->assertStringContainsString('default_org_type', $source);
        $this->assertStringContainsString('org_type: form.org_type', $source);
    }

    /** Feature keys the tenant ended up with, in catalog order. */
    private function enabledKeys(Masjid $masjid): array
    {
        return MobileAppFeature::whereIn(
            'id',
            MasjidMobileAppFeature::where('masjid_id', $masjid->id)
                ->where('is_available', true)
                ->pluck('feature_id')
        )->orderBy('id')->pluck('key')->all();
    }

    private function superAdmin(): User
    {
        return User::factory()->create([
            'type' => 'SuperAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
    }

    private function provision(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        Sanctum::actingAs($this->superAdmin());

        return $this->postJson('/api/admin/onboarding/provision', array_merge([
            'name' => 'Test Org ' . uniqid(),
            'email' => 'org-' . uniqid() . '@test.local',
            'phone' => '+15551234567',
            'address' => '1 Test St',
            'latitude' => 43.32,
            'longitude' => -79.79,
            'timezone' => 'America/Toronto',
            'country_id' => $this->countryId,
            'city_id' => $this->cityId,
            'method' => 'MuslimWorldLeague',
            'madhab' => 'Shafi',
            'high_latitude_rule' => 'MiddleOfTheNight',
            'platforms' => ['web'],
        ], $overrides));
    }
}
