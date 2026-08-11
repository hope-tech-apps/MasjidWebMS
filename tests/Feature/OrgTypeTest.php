<?php

namespace Tests\Feature;

use App\Models\Masjid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Manara vertical discriminator (`masjids.org_type`).
 *
 * The guarantee under test is that adding verticals is behaviour-neutral for
 * existing tenants: an untouched row is a masjid, keeps the masjid feature
 * bundle (including the Islamic worship modules), and nothing about the
 * existing tenancy behaviour shifts.
 */
class OrgTypeTest extends TestCase
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

    #[Test]
    public function an_existing_tenant_defaults_to_masjid(): void
    {
        // Created without ever mentioning org_type — i.e. every row that
        // predates this migration.
        $masjid = $this->makeMasjid();

        $this->assertSame('masjid', $masjid->fresh()->org_type);
        $this->assertTrue($masjid->isMasjid());
        $this->assertFalse($masjid->isSchool());
        $this->assertFalse($masjid->isCommunity());
    }

    #[Test]
    public function each_vertical_reports_itself(): void
    {
        $school = $this->makeMasjid(['org_type' => Masjid::ORG_TYPE_SCHOOL]);
        $community = $this->makeMasjid(['org_type' => Masjid::ORG_TYPE_COMMUNITY]);

        $this->assertTrue($school->isSchool());
        $this->assertFalse($school->isMasjid());
        $this->assertTrue($community->isCommunity());
        $this->assertFalse($community->isMasjid());
    }

    #[Test]
    public function an_unknown_org_type_degrades_to_masjid_rather_than_granting_another_vertical(): void
    {
        $masjid = $this->makeMasjid(['org_type' => 'clinic_typo']);

        $this->assertSame('masjid', $masjid->orgType());
        $this->assertTrue($masjid->isMasjid());
    }

    #[Test]
    public function worship_features_belong_to_the_masjid_bundle_only(): void
    {
        $worship = ['adhkar', 'hadith', 'qibla', 'quran', 'tasbih'];

        $masjidKeys = $this->makeMasjid()->defaultFeatureKeys();
        $schoolKeys = $this->makeMasjid(['org_type' => Masjid::ORG_TYPE_SCHOOL])->defaultFeatureKeys();
        $communityKeys = $this->makeMasjid(['org_type' => Masjid::ORG_TYPE_COMMUNITY])->defaultFeatureKeys();

        foreach ($worship as $key) {
            $this->assertContains($key, $masjidKeys, "masjid bundle should include {$key}");
            $this->assertNotContains($key, $schoolKeys, "school bundle must NOT include {$key}");
            $this->assertNotContains($key, $communityKeys, "community bundle must NOT include {$key}");
        }
    }

    #[Test]
    public function org_generic_features_are_shared_by_every_vertical(): void
    {
        $generic = ['about_us', 'announcements', 'contact_us', 'donate', 'gallery', 'services'];

        foreach (Masjid::ORG_TYPES as $type) {
            $keys = $this->makeMasjid(['org_type' => $type])->defaultFeatureKeys();
            foreach ($generic as $key) {
                $this->assertContains($key, $keys, "{$type} bundle should include {$key}");
            }
        }
    }

    #[Test]
    public function every_configured_feature_key_exists_in_the_seeded_feature_list(): void
    {
        // Guards against a bundle referencing a key the toggle system doesn't
        // have — which would silently provision nothing for that feature.
        $known = [
            'about_us', 'adhkar', 'announcements', 'contact_us', 'donate',
            'gallery', 'hadith', 'qibla', 'quran', 'services', 'tasbih',
        ];

        foreach (Masjid::ORG_TYPES as $type) {
            foreach (config("verticals.{$type}.feature_keys", []) as $key) {
                $this->assertContains($key, $known, "vertical {$type} references unknown feature key {$key}");
            }
        }
    }

    #[Test]
    public function terminology_differs_per_vertical_and_never_returns_empty(): void
    {
        $masjid = $this->makeMasjid();
        $school = $this->makeMasjid(['org_type' => Masjid::ORG_TYPE_SCHOOL]);

        $this->assertSame('Congregants', $masjid->term('members'));
        $this->assertSame('Families', $school->term('members'));
        $this->assertSame('Halaqat', $masjid->term('groups'));
        $this->assertSame('Classrooms', $school->term('groups'));

        // Unknown key degrades to something readable, never blank.
        $this->assertSame('Board members', $masjid->term('board_members'));
    }

    #[Test]
    public function every_vertical_has_a_complete_config_block(): void
    {
        foreach (Masjid::ORG_TYPES as $type) {
            $config = config("verticals.{$type}");
            $this->assertIsArray($config, "vertical {$type} has no config block");
            $this->assertNotEmpty($config['label'] ?? null, "vertical {$type} has no label");
            $this->assertNotEmpty($config['feature_keys'] ?? [], "vertical {$type} has no feature bundle");
            $this->assertNotEmpty($config['terminology'] ?? [], "vertical {$type} has no terminology pack");
        }
    }

    #[Test]
    public function the_scope_filters_by_vertical(): void
    {
        $this->makeMasjid();
        $this->makeMasjid(['org_type' => Masjid::ORG_TYPE_SCHOOL]);
        $this->makeMasjid(['org_type' => Masjid::ORG_TYPE_SCHOOL]);

        $this->assertSame(1, Masjid::ofOrgType(Masjid::ORG_TYPE_MASJID)->count());
        $this->assertSame(2, Masjid::ofOrgType(Masjid::ORG_TYPE_SCHOOL)->count());
        $this->assertSame(0, Masjid::ofOrgType(Masjid::ORG_TYPE_COMMUNITY)->count());
    }

    private function makeMasjid(array $overrides = []): Masjid
    {
        return Masjid::create(array_merge([
            'name' => 'Test Org ' . uniqid(),
            'email' => 'org-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ], $overrides));
    }
}
