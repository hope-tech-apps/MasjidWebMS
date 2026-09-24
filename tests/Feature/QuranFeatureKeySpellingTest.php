<?php

namespace Tests\Feature;

use App\Models\Masjid;
use App\Models\MasjidMobileAppFeature;
use App\Models\MobileAppFeature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Qur'an under the key production actually has.
 *
 * Production's `mobile_app_features` row 1 is keyed `qur’an` with U+2019
 * (bytes 717572e28099616e), not `quran`: the 2025-12-09 backfill looked the row
 * up by an ASCII-apostrophe name, missed it, and generated the key from the
 * curly-quoted name. The seeder and config/verticals.php both say `quran`, so
 * every other test passes on a catalogue production does not have, while
 * provisioning matched keys exactly and gave every new masjid Qur'an OFF.
 *
 * So every test here renames the seeded row to the production bytes before it
 * provisions anything.
 */
class QuranFeatureKeySpellingTest extends TestCase
{
    use RefreshDatabase;

    /** The seeded catalogue (MobileAppFeaturesSeeder), in its ASCII spelling. */
    private const CATALOGUE = [
        'quran', 'hadith', 'adhkar', 'qibla', 'tasbih', 'donate',
        'about_us', 'gallery', 'services', 'announcements', 'contact_us',
    ];

    /** Production's key and name for row 1, byte for byte. */
    private const PRODUCTION_KEY = "qur\u{2019}an";

    private const PRODUCTION_NAME = "Qur\u{2019}an";

    private const FEATURE_KEY_HELPER = 'resources/vue-app/core/helpers/featureKey.ts';

    private const WIZARD_VIEW = 'resources/vue-app/views/dashboard/super/OnboardingWizardView.vue';

    /** @var array<int,string> real-fs files a test staged and must remove. */
    private array $tempFiles = [];

    private int $cityId;

    private int $countryId;

    private MobileAppFeature $quran;

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

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        foreach (self::CATALOGUE as $key) {
            MobileAppFeature::create(['name' => ucfirst($key), 'key' => $key]);
        }

        $this->quran = MobileAppFeature::where('key', 'quran')->firstOrFail();
        $this->quran->forceFill(['key' => self::PRODUCTION_KEY, 'name' => self::PRODUCTION_NAME])->save();

        // The premise, asserted rather than assumed: no ASCII `quran` row is
        // left for an exact match to find.
        $this->assertSame('717572e28099616e', bin2hex($this->quran->fresh()->key));
        $this->assertFalse(MobileAppFeature::where('key', 'quran')->exists());
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        parent::tearDown();
    }

    #[Test]
    public function a_masjid_provisioned_from_its_default_bundle_is_born_with_quran_on(): void
    {
        // No `feature_keys_provided`: the vertical bundle from
        // config/verticals.php decides, and it spells the key `quran`.
        $masjid = $this->provisioned();

        $this->assertTrue($masjid->isMasjid());
        $this->assertTrue($this->isOn($masjid, $this->quran), 'the default masjid bundle left Qur\'an off');
        $this->assertCount(count(self::CATALOGUE), $this->enabledIds($masjid), 'the masjid bundle is the whole catalogue');
    }

    #[Test]
    public function the_wizard_posting_the_catalogues_own_keys_turns_quran_on(): void
    {
        // What the wizard posts: the catalogue's keys, as /options serves them.
        $keys = MobileAppFeature::orderBy('id')->pluck('key')->all();
        $this->assertContains(self::PRODUCTION_KEY, $keys);

        $masjid = $this->provisioned([
            'feature_keys_provided' => '1',
            'feature_keys' => $keys,
        ]);

        $this->assertTrue($this->isOn($masjid, $this->quran));
        $this->assertCount(count(self::CATALOGUE), $this->enabledIds($masjid));
    }

    #[Test]
    public function an_explicit_quran_in_the_documented_spelling_is_accepted_and_switches_the_production_row_on(): void
    {
        // `quran` is the spelling config/verticals.php documents; before the
        // fix `exists:mobile_app_features,key` refused it on production.
        $masjid = $this->provisioned([
            'org_type' => 'school',
            'feature_keys_provided' => '1',
            'feature_keys' => ['quran', 'announcements'],
        ]);

        $announcements = MobileAppFeature::where('key', 'announcements')->firstOrFail();
        $this->assertEqualsCanonicalizing([$this->quran->id, $announcements->id], $this->enabledIds($masjid));
    }

    #[Test]
    public function a_key_the_catalogue_does_not_ship_is_still_refused(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $response = $this->postJson('/api/admin/onboarding/provision', $this->payload([
            'feature_keys_provided' => '1',
            'feature_keys' => ['quran', 'teleport'],
        ]))
            ->assertStatus(422)
            ->assertJsonPath('status', 'failed')
            ->assertJsonStructure(['status', 'data' => ['feature_keys.1']]);

        $this->assertArrayNotHasKey('feature_keys.0', $response->json('data'), 'the documented `quran` was refused');

        $this->assertSame(0, Masjid::count());
    }

    #[Test]
    public function a_nested_array_in_the_posted_keys_is_refused_as_invalid_not_a_server_error(): void
    {
        Sanctum::actingAs($this->superAdmin());

        // toCatalogueKeys() runs in prepareForValidation(), before the
        // `feature_keys.*` string rule. Handing it an array to normalise throws
        // a TypeError, which reaches the client as a 500 instead of this 422.
        $this->postJson('/api/admin/onboarding/provision', $this->payload([
            'feature_keys_provided' => '1',
            'feature_keys' => [['quran'], 'announcements'],
        ]))
            ->assertStatus(422)
            ->assertJsonPath('status', 'failed')
            ->assertJsonStructure(['status', 'data' => ['feature_keys.0']]);

        $this->assertSame(0, Masjid::count());
    }

    #[Test]
    public function mapping_to_the_catalogue_spelling_leaves_non_strings_for_validation_to_refuse(): void
    {
        $this->assertSame(
            [['x'], 7, null, self::PRODUCTION_KEY],
            MobileAppFeature::toCatalogueKeys([['x'], 7, null, 'quran'])
        );
    }

    #[Test]
    public function a_school_and_a_community_are_still_born_with_quran_off(): void
    {
        foreach (['school', 'community'] as $orgType) {
            $masjid = $this->provisioned(['org_type' => $orgType]);

            $this->assertFalse($this->isOn($masjid, $this->quran), "a {$orgType} was born with Qur'an on");
            // Off, not missing: the row exists so a SuperAdmin can switch it on.
            $this->assertTrue(
                MasjidMobileAppFeature::where('masjid_id', $masjid->id)->where('feature_id', $this->quran->id)->exists()
            );
            $this->assertCount(6, $this->enabledIds($masjid), "a {$orgType} gets exactly its six org-generic features");
        }
    }

    #[Test]
    public function the_shared_normaliser_folds_every_spelling_the_way_the_mobile_endpoint_always_has(): void
    {
        // Byte-identical to the private helper it replaced in
        // Mobile\MasjidMobileAppFeaturesController: lower-case, then drop every
        // byte outside a-z0-9.
        $this->assertSame('quran', MobileAppFeature::normaliseKey(self::PRODUCTION_KEY));
        $this->assertSame('quran', MobileAppFeature::normaliseKey("Qur'an"));
        $this->assertSame('quran', MobileAppFeature::normaliseKey('quran'));
        $this->assertSame('aboutus', MobileAppFeature::normaliseKey('about_us'));
        $this->assertSame('contactus', MobileAppFeature::normaliseKey('Contact Us'));
        $this->assertSame('', MobileAppFeature::normaliseKey(null));
    }

    #[Test]
    public function the_mobile_features_endpoint_still_finds_the_quran_glyph_for_the_production_key(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('icons/alqurann.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
        Cache::flush();

        $masjid = $this->provisioned();

        $row = collect($this->getJson("/api/mobile/masjids/{$masjid->id}/features")->assertOk()->json('data'))
            ->firstWhere('id', $this->quran->id);

        $this->assertNotNull($row);
        $this->assertSame(self::PRODUCTION_KEY, $row['key'], 'the endpoint must still serve the stored key unchanged');
        $this->assertStringEndsWith('storage/icons/alqurann.svg', $row['icon']['original_url']);
    }

    #[Test]
    public function the_icon_repair_command_restores_the_quran_icon_under_the_production_key(): void
    {
        Storage::fake('public');
        Cache::flush();

        // The command copies from the real storage/app/public/icons, not the
        // faked disk. Stage the Qur'an SVG only if it is missing, so a tree that
        // already holds the shipped file keeps it.
        $dir = storage_path('app/public/icons');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $src = $dir.'/alqurann.svg';
        if (! is_file($src)) {
            file_put_contents($src, '<svg xmlns="http://www.w3.org/2000/svg"/>');
            $this->tempFiles[] = $src;
        }

        $this->assertNull($this->quran->icon, 'precondition: Qur\'an starts with no icon');

        // Other catalogue rows may be unresolved here (their SVGs are not
        // staged), so the exit code is not the assertion; Qur'an's row is.
        Artisan::call('app:features-ensure-icons', ['--json' => true]);
        $run = json_decode(Artisan::output(), true);

        $this->assertIsArray($run, 'the command did not emit JSON: '.Artisan::output());
        $this->assertNotContains(
            self::PRODUCTION_KEY,
            array_column($run['unresolved'], 'key'),
            'the production Qur\'an key fell through to "no icon mapping"'
        );
        $this->assertNotNull($this->quran->fresh()->icon, 'app:features-ensure-icons did not attach the Qur\'an icon');
    }

    /**
     * The wizard seeds its toggles itself and always posts them as an explicit
     * selection, so the server's normalising cannot bring back a key the wizard
     * left out. There is no JavaScript test runner in this repo, so this reads
     * the source, as OnboardingVerticalPickerTest does: the wizard must match
     * through catalogueKeysFor(), that must compare normalised keys on both
     * sides, and normaliseFeatureKey() must be the expression that agrees with
     * MobileAppFeature::normaliseKey().
     */
    #[Test]
    public function the_wizard_ticks_bundled_features_by_the_servers_normalised_key(): void
    {
        $helper = file_get_contents(base_path(self::FEATURE_KEY_HELPER));
        $wizard = file_get_contents(base_path(self::WIZARD_VIEW));
        $this->assertNotFalse($helper, 'the feature-key helper is missing');
        $this->assertNotFalse($wizard, 'the onboarding wizard view is missing');

        $this->assertMatchesRegularExpression(
            '/const bundledKeys = computed\(\(\) =>\s*catalogueKeysFor\(selectedVertical\.value\?\.feature_keys \?\? \[\], features\.value\.map\(f => f\.key\)\)/',
            $wizard,
            'the wizard no longer matches its bundle through catalogueKeysFor()'
        );

        $this->assertSame(1, preg_match('/export function catalogueKeysFor\([^)]*\): string\[\] \{(.*?)\n\}/s', $helper, $m));
        $this->assertStringContainsString('new Set(bundle.map(normaliseFeatureKey))', $m[1]);
        $this->assertStringContainsString('catalogueKeys.filter(key => wanted.has(normaliseFeatureKey(key)))', $m[1]);

        $this->assertSame(1, preg_match('/export function normaliseFeatureKey\([^)]*\): string \{\s*return (.*?);\s*\}/s', $helper, $m));
        $this->assertSame("(key ?? '').replace(/[^A-Za-z0-9]/g, '').toLowerCase()", $m[1]);

        // That expression, transcribed: strip outside A-Za-z0-9, then
        // lower-case. It must agree with the server on every spelling that
        // matters, including `İ`, which JavaScript would fold if it lower-cased
        // first and PHP's strtolower() does not.
        foreach ([self::PRODUCTION_KEY, "Qur'an", 'quran', 'about_us', 'Contact Us', "\u{0130}", ''] as $key) {
            $this->assertSame(
                MobileAppFeature::normaliseKey($key),
                strtolower(preg_replace('/[^A-Za-z0-9]/', '', $key)),
                "the wizard and the server normalise '{$key}' differently"
            );
        }
    }

    private function provisioned(array $overrides = []): Masjid
    {
        Sanctum::actingAs($this->superAdmin());

        $id = $this->postJson('/api/admin/onboarding/provision', $this->payload($overrides))
            ->assertCreated()
            ->json('data.masjid_id');

        return Masjid::findOrFail($id);
    }

    private function payload(array $overrides): array
    {
        return array_merge([
            'name' => 'Test Org '.uniqid(),
            'email' => 'org-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
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
        ], $overrides);
    }

    private function isOn(Masjid $masjid, MobileAppFeature $feature): bool
    {
        return (bool) MasjidMobileAppFeature::where('masjid_id', $masjid->id)
            ->where('feature_id', $feature->id)
            ->value('is_available');
    }

    /** @return list<int> */
    private function enabledIds(Masjid $masjid): array
    {
        return MasjidMobileAppFeature::where('masjid_id', $masjid->id)
            ->where('is_available', true)
            ->pluck('feature_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function superAdmin(): User
    {
        return User::factory()->create([
            'type' => 'SuperAdmin',
            'phone' => '+1'.random_int(1000000000, 9999999999),
        ]);
    }
}
