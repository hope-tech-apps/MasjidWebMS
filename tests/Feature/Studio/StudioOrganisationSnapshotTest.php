<?php

namespace Tests\Feature\Studio;

use App\Models\Masjid;
use App\Models\MasjidAppPublishing;
use App\Models\MasjidDomain;
use App\Models\ThemeSetting;
use App\Support\Studio\OrganisationSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\StudioDraftFixtures;
use Tests\TestCase;

/**
 * GET /api/admin/studio/organisations/{id} (Studio W2 S9): an existing
 * organisation through Studio's sections, read-only, with where each section
 * is edited, effective feature values, and never a credential.
 */
class StudioOrganisationSnapshotTest extends TestCase
{
    use RefreshDatabase;
    use StudioDraftFixtures;

    /** Credentials and identifiers seeded on the organisation; none may be served. */
    private const SECRETS = [
        'asc_key_p8' => '-----BEGIN PRIVATE KEY-----SNAPSHOT-P8-SECRET',
        'asc_key_id' => 'SNAPKEYID9',
        'asc_issuer_id' => 'snapshot-issuer-0000-secret',
        'development_team' => 'SNAPTEAM42',
        'play_service_account_json' => '{"private_key":"SNAPSHOT-PLAY-SECRET"}',
        'onesignal_app_id' => 'snapshot-onesignal-app-id',
        'onesignal_rest_api_key' => 'snapshot-onesignal-rest-key',
        'stripe_account_id' => 'acct_SNAPSHOTsecret',
        'cf_zone_id' => 'snapshotzoneid000000000000000000',
        'cf_dns_record_id' => 'snapshotdnsrecord00000000000000',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpStudio();
        $this->actAsSuperAdmin();
    }

    private function liveOrg(): Masjid
    {
        $org = Masjid::create([
            'name' => 'Snapshot Masjid', 'email' => 'snapshot@example.test', 'phone' => '+15550103000',
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Snapshot St', 'latitude' => 0.0, 'longitude' => 0.0,
            'org_type' => 'masjid', 'crm_enabled' => true, 'assistant_enabled' => false,
            'stripe_account_id' => self::SECRETS['stripe_account_id'],
        ]);
        // Burlington's shape: web_pages decided off; a module switched off.
        $org->forceFill(['capability_overrides' => ['web_pages' => false, 'events' => false]])->save();

        ThemeSetting::create([
            'masjid_id' => $org->id, 'primary_color' => '#01B151', 'secondary_color' => '#1B1B2E',
            'accent_color' => '#FFBA63', 'background_color' => '#F3F8FB',
            'tokens' => ['layout' => ['header' => 'default', 'footer' => 'columns']],
        ]);

        MasjidAppPublishing::create([
            'masjid_id' => $org->id,
            'ios_account_mode' => 'byo', 'android_account_mode' => 'managed', 'web_account_mode' => 'managed',
            'enabled_platforms' => ['ios', 'android'],
        ] + array_intersect_key(self::SECRETS, array_flip([
            'asc_key_p8', 'asc_key_id', 'asc_issuer_id', 'development_team', 'play_service_account_json', 'onesignal_app_id', 'onesignal_rest_api_key',
        ])));

        DB::table('masjid_domains')->insert([
            'masjid_id' => $org->id, 'host' => 'snapshot.example.test', 'kind' => MasjidDomain::KIND_CUSTOM, 'zone_apex' => 'example.test',
            'status' => MasjidDomain::STATUS_ACTIVE, 'source' => MasjidDomain::SOURCE_STUDIO,
            'cf_zone_id' => self::SECRETS['cf_zone_id'], 'cf_dns_record_id' => self::SECRETS['cf_dns_record_id'],
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $org->fresh();
    }

    private function snapshot(Masjid $org): array
    {
        return $this->getJson("/api/admin/studio/organisations/{$org->id}")->assertOk()->json('data');
    }

    #[Test]
    public function no_secret_or_key_ever_appears(): void
    {
        $data = $this->snapshot($this->liveOrg());
        $json = json_encode($data);

        foreach (self::SECRETS as $column => $value) {
            $this->assertStringNotContainsString($value, $json, "{$column}'s value is served");
        }

        $keys = [];
        array_walk_recursive($data, function ($value, $key) use (&$keys) {
            $keys[] = (string) $key;
        });
        $walk = function (array $node) use (&$walk, &$keys) {
            foreach ($node as $key => $value) {
                $keys[] = (string) $key;
                if (is_array($value)) {
                    $walk($value);
                }
            }
        };
        $walk($data);

        foreach (array_unique($keys) as $key) {
            $this->assertDoesNotMatchRegularExpression('/secret|token|password|p8|api_key|issuer|service_account_json|stripe|onesignal_app_id|development_team|^cf_/i', $key, "the key \"{$key}\" names a credential");
        }

        // The flags that stand in for them are there.
        $this->assertTrue($data['sections']['platforms']['data']['has_asc_key']);
        $this->assertTrue($data['sections']['platforms']['data']['has_onesignal_key']);
        $this->assertTrue($data['sections']['platforms']['data']['has_onesignal_app']);
    }

    #[Test]
    public function features_report_effective_values(): void
    {
        $org = $this->liveOrg();
        $entries = collect($this->snapshot($org)['sections']['features']['data'])->flatMap(fn ($g) => $g['entries'])->keyBy('key');

        foreach ($entries as $key => $entry) {
            $expected = $entry['kind'] === 'module' ? ! $org->moduleIsOff($key) : $org->hasCapability($key);
            $this->assertSame($expected, $entry['enabled'], "{$key} reports its effective value");
        }

        $this->assertFalse($entries['web_pages']['enabled'], 'a decided-off grant');
        $this->assertTrue($entries['web_pages']['decided']);
        $this->assertFalse($entries['events']['enabled'], 'a module switched off');
        $this->assertTrue($entries['crm']['enabled'], 'a column-backed grant reads its column');
        $this->assertFalse($entries['crm']['decided'], 'a column-backed grant has no override');
        $this->assertTrue($entries['website']['enabled'], 'a module left at its default');
        $this->assertFalse($entries['website']['decided']);

        // The catalogue for this org type, the one list Studio reads.
        $this->assertSame(
            collect(\App\Support\CapabilityCatalogue::forOrgType('masjid'))->flatMap(fn ($g) => $g['entries'])->pluck('key')->all(),
            $entries->keys()->all(),
        );
    }

    #[Test]
    public function every_section_names_where_it_is_edited(): void
    {
        $org = $this->liveOrg();
        $data = $this->snapshot($org);

        $this->assertSame(['id' => (int) $org->id, 'name' => 'Snapshot Masjid', 'org_type' => 'masjid', 'slug' => $org->slug], $data['org']);
        $this->assertSame(OrganisationSnapshot::SECTIONS, array_keys($data['sections']));

        foreach ($data['sections'] as $name => $section) {
            $this->assertSame(['data', 'edit_in'], array_keys($section), $name);
            $this->assertIsString($section['edit_in'], $name);

            if (in_array($name, ['features', 'brand'], true)) {
                $this->assertSame('studio', $section['edit_in'], $name);
            } else {
                $this->assertMatchesRegularExpression('~^/(masjid|dashboard/super)/~', $section['edit_in'], $name);
                $this->assertStringNotContainsString('{id}', $section['edit_in'], $name);
            }
        }

        $this->assertSame("/dashboard/super/masjids/{$org->id}", $data['sections']['domain']['edit_in']);
        $this->assertSame([['host' => 'snapshot.example.test', 'kind' => 'custom', 'status' => 'active', 'source' => 'studio', 'served' => true]], $data['sections']['domain']['data']);
        $this->assertSame(['primary_color' => '#01B151', 'secondary_color' => '#1B1B2E', 'accent_color' => '#FFBA63', 'background_color' => '#F3F8FB'], $data['sections']['brand']['data']['colours']);
        $this->assertFalse($data['sections']['brand']['data']['has_derivatives']);
        $this->assertNull($data['sections']['apps']['data'], 'S17 fills it');
    }

    #[Test]
    public function a_missing_organisation_is_a_404_and_a_masjid_admin_is_refused(): void
    {
        $this->getJson('/api/admin/studio/organisations/999999')->assertNotFound();

        $org = $this->liveOrg();
        $admin = \App\Models\User::factory()->create(['type' => 'MasjidAdmin', 'phone' => '+15550103001']);
        \App\Models\MasjidUser::create(['masjid_id' => $org->id, 'user_id' => $admin->id, 'role' => 'masjid-admin', 'is_default' => true]);
        \Laravel\Sanctum\Sanctum::actingAs($admin->fresh());

        $this->getJson("/api/admin/studio/organisations/{$org->id}")->assertStatus(401);
    }
}
