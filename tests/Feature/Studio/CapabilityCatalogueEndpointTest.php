<?php

namespace Tests\Feature\Studio;

use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use App\Support\CapabilityCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * GET /api/admin/studio/catalogue — what Studio's feature step offers a new
 * organisation of one type, and what it would be born with. Everything is read
 * from config/capabilities.php, so these tests derive their expectations from
 * config too, and pin the few facts the plan names.
 */
class CapabilityCatalogueEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/admin/studio/catalogue';

    private const SCHOOL_KEYS = ['school_calendar', 'report_card_core_subjects', 'short_lesson_plan', 'simple_marking'];

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    private function superAdmin(): User
    {
        return User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+1' . random_int(1000000000, 9999999999)])->fresh();
    }

    private function org(string $orgType): Masjid
    {
        // What provisioning writes that the column defaults do not: CRM on.
        return Masjid::create([
            'name' => 'Catalogue Org ' . uniqid(),
            'email' => 'cat' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'crm_enabled' => true, 'org_type' => $orgType,
        ]);
    }

    /** @return array<string, mixed> the response's `data` */
    private function catalogue(?string $orgType): array
    {
        $url = $orgType === null ? self::URL : self::URL . '?org_type=' . $orgType;

        return $this->getJson($url)->assertOk()->assertJsonPath('status', 'success')->json('data');
    }

    /** @return Collection<string, array<string, mixed>> key => entry */
    private function entries(array $data): Collection
    {
        return collect($data['groups'])->flatMap(fn (array $group) => $group['entries'])->keyBy('key');
    }

    /** @return list<string> the config keys this org type may see, in config order */
    private function visibleKeys(string $orgType): array
    {
        return array_keys(array_filter(
            config('capabilities'),
            fn (array $def) => ($def['group'] ?? null) !== 'school' || $orgType === Masjid::ORG_TYPE_SCHOOL
        ));
    }

    #[Test]
    public function only_a_super_admin_can_read_it(): void
    {
        $org = $this->org('masjid');
        $admin = User::factory()->create(['type' => 'MasjidAdmin', 'phone' => '+15550001234']);
        MasjidUser::create(['masjid_id' => $org->id, 'user_id' => $admin->id, 'role' => 'masjid-admin', 'is_default' => true]);

        Sanctum::actingAs($admin->fresh());
        $this->getJson(self::URL)->assertStatus(401)->assertExactJson(['status' => 'failed', 'data' => 'Unauthorized.']);

        Sanctum::actingAs($this->superAdmin());
        $this->getJson(self::URL)->assertOk()->assertJsonPath('status', 'success');
    }

    #[Test]
    public function an_absent_org_type_reads_as_masjid_and_an_unknown_one_is_refused_in_the_legacy_envelope(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $masjid = $this->catalogue('masjid');
        $this->assertSame('masjid', $masjid['org_type']);
        $this->assertSame($masjid, $this->catalogue(null));
        // A blank select serialises as an empty string.
        $this->assertSame($masjid, $this->catalogue(''));

        $this->getJson(self::URL . '?org_type=mosque')
            ->assertStatus(422)
            ->assertJsonPath('status', 'failed')
            ->assertJsonStructure(['status', 'data' => ['org_type']]);
    }

    #[Test]
    public function every_entry_the_org_type_may_see_is_served_once_in_capability_groups_order(): void
    {
        Sanctum::actingAs($this->superAdmin());
        $groupOrder = array_keys(config('capability_groups'));

        foreach (Masjid::ORG_TYPES as $orgType) {
            $data = $this->catalogue($orgType);
            $this->assertSame($orgType, $data['org_type']);

            $served = collect($data['groups'])->flatMap(fn (array $g) => array_column($g['entries'], 'key'))->all();
            $expected = $this->visibleKeys($orgType);

            $this->assertSame(count(array_unique($served)), count($served), "a {$orgType} is served a key twice");
            $this->assertEqualsCanonicalizing($expected, $served, "what a {$orgType} is served");

            // Cards in capability_groups order, empty ones dropped, each holding
            // its entries in config order.
            $groupKeys = array_column($data['groups'], 'key');
            $this->assertSame(array_values(array_intersect($groupOrder, $groupKeys)), $groupKeys);

            foreach ($data['groups'] as $group) {
                $this->assertSame(config("capability_groups.{$group['key']}"), $group['label']);
                $this->assertNotEmpty($group['entries']);
                $this->assertSame(
                    array_values(array_filter($expected, fn (string $key) => config("capabilities.{$key}.group") === $group['key'])),
                    array_column($group['entries'], 'key'),
                    "the {$group['key']} card for a {$orgType}"
                );
            }
        }
    }

    #[Test]
    public function school_features_are_never_served_to_a_masjid_or_a_community(): void
    {
        // The group is the only data that marks them (D14).
        $this->assertSame(
            self::SCHOOL_KEYS,
            array_keys(array_filter(config('capabilities'), fn (array $def) => ($def['group'] ?? null) === 'school'))
        );

        Sanctum::actingAs($this->superAdmin());

        foreach ([Masjid::ORG_TYPE_MASJID, Masjid::ORG_TYPE_COMMUNITY] as $orgType) {
            $data = $this->catalogue($orgType);

            $this->assertNotContains('school', array_column($data['groups'], 'key'), "a {$orgType} is served the School card");

            foreach (self::SCHOOL_KEYS as $key) {
                $this->assertFalse($this->entries($data)->has($key), "a {$orgType} is served {$key}");
            }

            $this->assertArrayNotHasKey('school_calendar', CapabilityCatalogue::resolve($orgType, ['school_calendar' => true]));
        }

        $school = $this->entries($this->catalogue('school'));

        foreach (self::SCHOOL_KEYS as $key) {
            $this->assertSame('optional', $school[$key]['visibility'], $key);
            $this->assertFalse($school[$key]['default_at_creation'], $key);
        }
    }

    #[Test]
    public function defaults_offers_and_visibility_are_read_from_the_catalogue(): void
    {
        Sanctum::actingAs($this->superAdmin());

        foreach (Masjid::ORG_TYPES as $orgType) {
            foreach ($this->entries($this->catalogue($orgType)) as $key => $entry) {
                $def = config("capabilities.{$key}");
                $column = ! empty($def['column']);
                $isModule = $def['kind'] === 'module';
                $born = $column ? (bool) ($def['provision_default'] ?? $key === 'crm') : (bool) $def['defaults'][$orgType];
                $offered = $isModule ? Masjid::MODULE_DEFAULTS[$key][$orgType] : (! $column && $def['defaults'][$orgType]);

                $this->assertSame($def['kind'], $entry['kind'], $key);
                $this->assertSame($column ? $key : 'capability', $entry['writer'], $key);
                $this->assertSame($column ? null : $def['defaults'][$orgType], $entry['default_for_org_type'], "{$key} for a {$orgType}");
                $this->assertSame($offered, $entry['offered_by_default'], "{$key} for a {$orgType}");
                $this->assertSame($born, $entry['default_at_creation'], "{$key} for a {$orgType}");
                $this->assertSame(
                    $isModule && ! $offered ? 'not_offered' : ($born ? 'default' : 'optional'),
                    $entry['visibility'],
                    "{$key} for a {$orgType}"
                );
                $this->assertSame($def['studio_preselect_with'] ?? [], $entry['preselect_with'], $key);
            }
        }

        // The facts the plan names.
        $masjid = $this->entries($this->catalogue('masjid'));
        $school = $this->entries($this->catalogue('school'));
        $this->assertSame('default', $masjid['giving']['visibility']);
        $this->assertSame('not_offered', $school['giving']['visibility']);
        $this->assertSame('default', $masjid['crm']['visibility']);
        $this->assertSame('crm', $masjid['crm']['writer']);
        $this->assertSame('optional', $masjid['assistant']['visibility']);
        $this->assertSame('optional', $masjid['web_pages']['visibility']);
        $this->assertSame(['web'], $masjid['web_pages']['preselect_with']);

        // Read on every call: a changed config answers at once.
        config([
            'capabilities.jummah_lunch.defaults.community' => true,
            'capabilities.crm.provision_default' => false,
        ]);
        $community = $this->entries($this->catalogue('community'));
        $this->assertSame('default', $community['jummah_lunch']['visibility']);
        $this->assertTrue($community['jummah_lunch']['default_at_creation']);
        $this->assertSame('optional', $community['crm']['visibility']);
        $this->assertFalse($community['crm']['default_at_creation']);
    }

    #[Test]
    public function what_it_says_a_new_org_is_born_with_is_what_provisioning_gives(): void
    {
        Mail::fake();
        $countryId = DB::table('countries')->insertGetId(['name' => 'Canada', 'code' => 'CA']);
        $cityId = DB::table('cities')->insertGetId(['name' => 'Burlington', 'country_id' => $countryId]);
        Sanctum::actingAs($this->superAdmin());

        foreach (Masjid::ORG_TYPES as $orgType) {
            $response = $this->postJson('/api/admin/onboarding/provision', [
                'org_type' => $orgType,
                'name' => "Born {$orgType} " . uniqid(),
                'email' => "born-{$orgType}-" . uniqid() . '@test.local',
                'phone' => '+1' . random_int(1000000000, 9999999999),
                'address' => '1 Test St',
                'latitude' => 43.32,
                'longitude' => -79.79,
                'timezone' => 'America/Toronto',
                'country_id' => $countryId,
                'city_id' => $cityId,
                'method' => 'MuslimWorldLeague',
                'madhab' => 'Shafi',
                'high_latitude_rule' => 'MiddleOfTheNight',
                'platforms' => ['web'],
            ])->assertCreated();

            $born = Masjid::findOrFail($response->json('data.masjid_id'));
            $entries = $this->entries($this->catalogue($orgType));
            $has = [];

            foreach ($entries as $key => $entry) {
                $has[$key] = $entry['kind'] === 'module' ? ! $born->moduleIsOff($key) : $born->hasCapability($key);
                $this->assertSame($has[$key], $entry['default_at_creation'], "{$key} for a new {$orgType}");
            }

            // No choices resolves to exactly what the org was born with.
            $resolved = CapabilityCatalogue::resolve($orgType, []);
            ksort($has);
            ksort($resolved);
            $this->assertSame($has, $resolved);

            // A school feature it is never served is not something it was born with.
            foreach (self::SCHOOL_KEYS as $key) {
                $this->assertFalse($born->hasCapability($key), "{$key} for a new {$orgType}");
            }
        }

        // A choice is taken only when it is a boolean; anything else is the default.
        $resolved = CapabilityCatalogue::resolve('masjid', ['crm' => false, 'assistant' => true, 'giving' => 'no', 'nope' => true]);
        $this->assertFalse($resolved['crm']);
        $this->assertTrue($resolved['assistant']);
        $this->assertTrue($resolved['giving']);
        $this->assertArrayNotHasKey('nope', $resolved);
    }

    #[Test]
    public function the_app_block_is_derived_from_the_app_menu_registry(): void
    {
        Sanctum::actingAs($this->superAdmin());
        $masjid = $this->entries($this->catalogue('masjid'));

        $this->assertSame(['items' => ['announcements'], 'tab' => true], $masjid['announcements']['app']);
        $this->assertSame(['items' => ['announcements'], 'tab' => true], $masjid['events']['app']);
        $this->assertSame(['items' => ['donate'], 'tab' => true], $masjid['donation_link']['app']);
        $this->assertSame(['items' => ['donate'], 'tab' => true], $masjid['giving']['app']);
        $this->assertSame(['items' => ['contact'], 'tab' => true], $masjid['contact_requests']['app']);
        $this->assertSame(['items' => ['services'], 'tab' => false], $masjid['services']['app']);
        $this->assertSame(['items' => ['quran'], 'tab' => false], $masjid['quran']['app']);
        $this->assertSame(['items' => [], 'tab' => false], $masjid['prayer_times']['app']);
        $this->assertSame(['items' => [], 'tab' => false], $masjid['web_pages']['app']);
        $this->assertSame(['items' => [], 'tab' => false], $masjid['crm']['app']);

        // Services takes Announcements' place on the bar: the chip follows.
        config(['app_menu.tabs' => ['home', 'services', 'contact', 'donate']]);
        $masjid = $this->entries($this->catalogue('masjid'));

        $this->assertSame(['items' => ['services'], 'tab' => true], $masjid['services']['app']);
        $this->assertSame(['items' => ['announcements'], 'tab' => false], $masjid['events']['app']);
    }

    #[Test]
    public function turns_on_falls_back_to_the_description(): void
    {
        Sanctum::actingAs($this->superAdmin());
        $masjid = $this->entries($this->catalogue('masjid'));

        foreach (['website', 'prayer_times', 'giving'] as $key) {
            $this->assertSame(config("capabilities.{$key}.turns_on"), $masjid[$key]['turns_on'], $key);
            $this->assertNotSame($masjid[$key]['description'], $masjid[$key]['turns_on'], $key);
        }

        $this->assertArrayNotHasKey('turns_on', config('capabilities.announcements'));
        $this->assertSame(config('capabilities.announcements.description'), $masjid['announcements']['turns_on']);

        // A blank line is no line.
        config(['capabilities.events.turns_on' => '   ']);
        $this->assertSame(
            config('capabilities.events.description'),
            $this->entries($this->catalogue('masjid'))['events']['turns_on']
        );
    }

    #[Test]
    public function a_new_config_entry_appears_with_no_code_change(): void
    {
        Sanctum::actingAs($this->superAdmin());

        config([
            'capabilities.bake_sale' => [
                'kind' => 'grant',
                'group' => 'registration_money',
                'label' => 'Bake sale',
                'description' => 'Sell baked goods after Jumu\'ah.',
                'defaults' => ['masjid' => true, 'school' => false, 'community' => false],
            ],
            // A group config/capability_groups.php does not name yet.
            'capabilities.car_wash' => [
                'kind' => 'grant',
                'group' => 'fundraising',
                'label' => 'Car wash',
                'description' => 'Book car wash slots.',
                'defaults' => ['masjid' => false, 'school' => false, 'community' => false],
            ],
        ]);

        $data = $this->catalogue('masjid');
        $groups = collect($data['groups'])->keyBy('key');

        $this->assertSame('bake_sale', collect($groups['registration_money']['entries'])->last()['key']);
        $bake = $this->entries($data)['bake_sale'];
        $this->assertSame('Bake sale', $bake['label']);
        $this->assertSame('default', $bake['visibility']);
        $this->assertSame('capability', $bake['writer']);
        $this->assertSame('optional', $this->entries($this->catalogue('school'))['bake_sale']['visibility']);

        $this->assertSame('fundraising', collect($data['groups'])->last()['key']);
        $this->assertSame('fundraising', $groups['fundraising']['label']);
        $this->assertSame(['car_wash'], array_column($groups['fundraising']['entries'], 'key'));
    }

    #[Test]
    public function it_agrees_with_the_switch_panel_for_a_fresh_org_of_each_type(): void
    {
        Sanctum::actingAs($this->superAdmin());

        foreach (Masjid::ORG_TYPES as $orgType) {
            $org = $this->org($orgType);
            $panel = $this->entries($this->getJson("/api/admin/masjids/{$org->id}/capabilities")->assertOk()->json('data'));
            $catalogue = $this->entries($this->catalogue($orgType));

            // The panel shows everything; Studio leaves out only what D14 hides.
            $this->assertEqualsCanonicalizing(
                $orgType === Masjid::ORG_TYPE_SCHOOL ? [] : self::SCHOOL_KEYS,
                $panel->keys()->diff($catalogue->keys())->values()->all(),
                "what the panel shows a {$orgType} and Studio does not"
            );
            $this->assertSame([], $catalogue->keys()->diff($panel->keys())->values()->all());

            foreach ($catalogue as $key => $entry) {
                foreach (['label', 'description', 'kind', 'writer', 'default_for_org_type', 'offered_by_default', 'where', 'surface'] as $field) {
                    $this->assertSame($panel[$key][$field], $entry[$field], "{$key}.{$field} for a {$orgType}");
                }

                $this->assertSame($panel[$key]['enabled'], $entry['default_at_creation'], "{$key} for a fresh {$orgType}");
            }
        }
    }

    #[Test]
    public function it_survives_a_config_without_turns_on_or_provision_default(): void
    {
        // A config cache from before these keys existed, as during a deploy.
        $stale = array_map(
            fn (array $def) => array_diff_key($def, array_flip(['turns_on', 'provision_default', 'studio_preselect_with'])),
            config('capabilities')
        );
        config(['capabilities' => $stale]);

        Sanctum::actingAs($this->superAdmin());

        foreach (Masjid::ORG_TYPES as $orgType) {
            $entries = $this->entries($this->catalogue($orgType));

            $this->assertCount(count($this->visibleKeys($orgType)), $entries);
            $this->assertTrue($entries['crm']['default_at_creation'], "crm for a {$orgType}");
            $this->assertFalse($entries['assistant']['default_at_creation'], "assistant for a {$orgType}");

            foreach ($entries as $key => $entry) {
                $this->assertSame($entry['description'], $entry['turns_on'], $key);
                $this->assertSame([], $entry['preselect_with'], $key);
            }
        }
    }
}
