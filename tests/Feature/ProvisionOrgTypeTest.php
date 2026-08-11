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
 * Provisioning a tenant of a chosen vertical (PLAN T-002).
 *
 * The bar is behaviour-neutrality: a provision call that never mentions
 * `org_type` — which is every call the wizard makes today — must still create a
 * masjid with the full feature catalog switched on. On top of that, a school or
 * community tenant becomes creatable and gets ONLY its vertical's bundle, so the
 * Islamic worship modules are never seeded onto a non-masjid.
 */
class ProvisionOrgTypeTest extends TestCase
{
    use RefreshDatabase;

    /** Full seeded catalog (MobileAppFeaturesSeeder) — the masjid bundle. */
    private const FEATURE_KEYS = [
        'quran', 'hadith', 'adhkar', 'qibla', 'tasbih', 'donate',
        'about_us', 'gallery', 'services', 'announcements', 'contact_us',
    ];

    private const WORSHIP_KEYS = ['adhkar', 'hadith', 'qibla', 'quran', 'tasbih'];

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

        // Country/City declare no $fillable, so seed them through the query
        // builder rather than mass assignment.
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
    public function provisioning_without_an_org_type_still_creates_a_masjid_with_the_worship_modules(): void
    {
        $response = $this->provision();

        $response->assertCreated()->assertJsonPath('status', 'success');

        $masjid = Masjid::findOrFail($response->json('data.masjid_id'));
        $this->assertTrue($masjid->isMasjid());
        $this->assertSame('masjid', $masjid->org_type);

        // Behaviour-neutral: the pre-vertical code path enabled every seeded
        // feature, and the masjid bundle is that same full catalog.
        $this->assertSame(self::FEATURE_KEYS, $this->enabledKeys($masjid));
    }

    #[Test]
    public function provisioning_a_school_records_the_vertical_and_seeds_no_worship_modules(): void
    {
        $response = $this->provision(['org_type' => 'school']);

        $response->assertCreated();

        $masjid = Masjid::findOrFail($response->json('data.masjid_id'));
        $this->assertTrue($masjid->isSchool());
        $this->assertSame('school', $masjid->org_type);

        $enabled = $this->enabledKeys($masjid);
        foreach (self::WORSHIP_KEYS as $key) {
            $this->assertNotContains($key, $enabled, "a school must not have {$key} enabled");
        }
        foreach (['about_us', 'announcements', 'contact_us', 'donate', 'gallery', 'services'] as $key) {
            $this->assertContains($key, $enabled, "a school should have {$key} enabled");
        }

        // Every feature row is still written — the toggle exists, it is just off,
        // so a SuperAdmin can turn one on later without a repair script.
        $this->assertSame(
            count(self::FEATURE_KEYS),
            MasjidMobileAppFeature::where('masjid_id', $masjid->id)->count()
        );
    }

    #[Test]
    public function provisioning_a_community_organization_seeds_the_org_generic_bundle(): void
    {
        $response = $this->provision(['org_type' => 'community']);

        $response->assertCreated();

        $masjid = Masjid::findOrFail($response->json('data.masjid_id'));
        $this->assertTrue($masjid->isCommunity());

        $enabled = $this->enabledKeys($masjid);
        foreach (self::WORSHIP_KEYS as $key) {
            $this->assertNotContains($key, $enabled, "a community org must not have {$key} enabled");
        }
    }

    #[Test]
    public function an_unknown_org_type_is_rejected_in_the_legacy_failure_envelope(): void
    {
        $response = $this->provision(['org_type' => 'clinic']);

        // BaseFormRequest's envelope — a raw ValidationException would 500 here.
        $response->assertStatus(422)
            ->assertJsonPath('status', 'failed')
            ->assertJsonStructure(['status', 'data' => ['org_type']]);

        $this->assertSame(0, Masjid::count());
    }

    #[Test]
    public function an_explicit_feature_selection_still_wins_over_the_vertical_default(): void
    {
        // A school admin deliberately turning Qur'an on: the bundle is a default,
        // not an authorization check (.claude/rules/verticals.md).
        $response = $this->provision([
            'org_type' => 'school',
            'feature_keys_provided' => '1',
            'feature_keys' => ['quran', 'announcements'],
        ]);

        $response->assertCreated();

        $masjid = Masjid::findOrFail($response->json('data.masjid_id'));
        $this->assertSame(['quran', 'announcements'], $this->enabledKeys($masjid));
    }

    #[Test]
    public function an_explicit_empty_selection_enables_nothing_even_for_a_masjid(): void
    {
        // The flag disambiguates "chose none" from "field absent"; the vertical
        // default must not sneak back in when the admin unchecked everything.
        $response = $this->provision(['feature_keys_provided' => '1']);

        $response->assertCreated();

        $masjid = Masjid::findOrFail($response->json('data.masjid_id'));
        $this->assertSame([], $this->enabledKeys($masjid));
    }

    #[Test]
    public function the_admin_masjid_payload_carries_the_vertical_and_its_terminology(): void
    {
        $created = $this->provision(['org_type' => 'school']);
        $masjidId = $created->json('data.masjid_id');

        // The provision echo itself.
        $created->assertJsonPath('data.masjid.vertical.org_type', 'school')
            ->assertJsonPath('data.masjid.vertical.terminology.members', 'Families');

        // And the payload the SPA's masjidStore actually loads.
        $this->getJson("/api/admin/masjids/{$masjidId}")
            ->assertOk()
            ->assertJsonPath('data.org_type', 'school')
            ->assertJsonPath('data.vertical.org_type', 'school')
            ->assertJsonPath('data.vertical.label', 'School')
            ->assertJsonPath('data.vertical.terminology.members', 'Families')
            ->assertJsonPath('data.vertical.terminology.groups', 'Classrooms');
    }

    #[Test]
    public function an_untouched_masjid_reports_masjid_terminology_in_the_admin_payload(): void
    {
        $masjid = Masjid::create([
            'name' => 'Legacy Masjid',
            'email' => 'legacy-' . uniqid() . '@test.local',
            'phone' => '+15550000000',
            'country_id' => $this->countryId,
            'city_id' => $this->cityId,
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ]);

        Sanctum::actingAs($this->superAdmin());

        $this->getJson("/api/admin/masjids/{$masjid->id}")
            ->assertOk()
            ->assertJsonPath('data.vertical.org_type', 'masjid')
            ->assertJsonPath('data.vertical.terminology.members', 'Congregants');
    }

    /**
     * Feature keys the tenant ended up with, in catalog order so the assertions
     * read as "exactly this set".
     */
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
