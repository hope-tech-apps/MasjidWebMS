<?php

namespace Tests\Feature;

use App\Enums\SectionType;
use App\Models\Masjid;
use App\Models\MasjidCapabilityChange;
use App\Models\MasjidUser;
use App\Models\Page;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * GET /api/admin/masjids/{id}/capabilities — what the SuperAdmin's switch panel
 * reads: every catalogue entry once, grouped, with its writer, default,
 * override and in-use count, plus this organisation's last 25 flips.
 */
class CapabilitiesEndpointTest extends TestCase
{
    use RefreshDatabase;

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

    private function org(string $orgType = 'school'): Masjid
    {
        return Masjid::create([
            'name' => 'Panel Org ' . uniqid(),
            'email' => 'panel' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'crm_enabled' => true, 'org_type' => $orgType,
            'assistant_enabled' => false,
        ]);
    }

    private function superAdmin(string $name = 'Platform Owner'): User
    {
        return User::factory()->create(['type' => 'SuperAdmin', 'name' => $name, 'phone' => '+1' . random_int(1000000000, 9999999999)])->fresh();
    }

    private function url(Masjid $masjid): string
    {
        return "/api/admin/masjids/{$masjid->id}/capabilities";
    }

    /** A page holding one section of $type. */
    private function placed(Masjid $org, string $type, bool $pageActive = true, bool $sectionActive = true): Page
    {
        $page = Page::create([
            'masjid_id' => $org->id,
            'slug' => 'page-' . uniqid(),
            'title' => 'Page',
            'is_active' => $pageActive,
            'order' => 1,
        ]);

        $section = Section::create([
            'masjid_id' => $org->id,
            'section_type' => $type,
            'title' => 'Section',
            'content' => SectionType::from($type)->defaultContent(),
            'is_active' => $sectionActive,
        ]);

        $page->sections()->attach($section->id, ['order' => 1, 'platforms' => null]);

        return $page;
    }

    /** @return Collection<string, array<string,mixed>> entry key => entry */
    private function entries(array $data): Collection
    {
        return collect($data['groups'])->flatMap(fn (array $group) => $group['entries'])->keyBy('key');
    }

    #[Test]
    public function only_a_super_admin_can_read_it(): void
    {
        $org = $this->org();
        $admin = User::factory()->create(['type' => 'MasjidAdmin', 'phone' => '+15550001234']);
        MasjidUser::create(['masjid_id' => $org->id, 'user_id' => $admin->id, 'role' => 'masjid-admin', 'is_default' => true]);

        Sanctum::actingAs($admin->fresh());
        $this->getJson($this->url($org))->assertForbidden();
    }

    #[Test]
    public function every_catalogue_entry_appears_once_in_its_group_with_its_writer(): void
    {
        $org = $this->org('school');
        Sanctum::actingAs($this->superAdmin());

        $data = $this->getJson($this->url($org))->assertOk()->assertJsonPath('status', 'success')->json('data');

        $this->assertSame(['id' => (int) $org->id, 'name' => $org->name, 'org_type' => 'school'], $data['org']);

        // Groups in config order, each labelled from config.
        $this->assertSame(
            array_keys(config('capability_groups')),
            array_column($data['groups'], 'key')
        );
        $this->assertSame(config('capability_groups.content'), $data['groups'][0]['label']);

        $keys = collect($data['groups'])->flatMap(fn (array $group) => array_column($group['entries'], 'key'))->all();
        $this->assertSame(count($keys), count(array_unique($keys)), 'an entry appears twice');
        $this->assertEqualsCanonicalizing(array_keys(config('capabilities')), $keys);

        $entries = $this->entries($data);

        foreach ($data['groups'] as $group) {
            foreach ($group['entries'] as $entry) {
                $this->assertSame(config("capabilities.{$entry['key']}.group"), $group['key']);
            }
        }

        $this->assertSame('crm', $entries['crm']['writer']);
        $this->assertSame('assistant', $entries['assistant']['writer']);
        $this->assertSame('capability', $entries['web_pages']['writer']);
        $this->assertSame('capability', $entries['events']['writer']);

        $this->assertSame('module', $entries['events']['kind']);
        $this->assertSame('grant', $entries['school_calendar']['kind']);
        $this->assertSame('Events', $entries['events']['label']);

        // Defaults for a school: modules on, new grants off, the column entries have none.
        $this->assertTrue($entries['events']['enabled']);
        $this->assertTrue($entries['events']['default_for_org_type']);
        $this->assertFalse($entries['jummah_lunch']['default_for_org_type']);
        $this->assertNull($entries['crm']['default_for_org_type']);
        $this->assertTrue($entries['crm']['enabled']);
        $this->assertFalse($entries['events']['overridden']);
    }

    #[Test]
    public function overridden_is_true_only_once_a_super_admin_decided(): void
    {
        $org = $this->org('school');
        Sanctum::actingAs($this->superAdmin());

        $this->patch("/api/admin/masjids/{$org->id}/capabilities/events", ['enabled' => '0'], ['Accept' => 'application/json'])->assertOk();
        // Decided equal to the default is still decided.
        $this->patch("/api/admin/masjids/{$org->id}/capabilities/web_pages", ['enabled' => '0'], ['Accept' => 'application/json'])->assertOk();

        $entries = $this->entries($this->getJson($this->url($org))->assertOk()->json('data'));

        $this->assertTrue($entries['events']['overridden']);
        $this->assertFalse($entries['events']['enabled']);
        $this->assertTrue($entries['events']['default_for_org_type']);
        $this->assertTrue($entries['web_pages']['overridden']);
        $this->assertFalse($entries['announcements']['overridden']);
        $this->assertFalse($entries['crm']['overridden']);
    }

    #[Test]
    public function in_use_counts_active_sections_on_active_pages_of_this_organisation_only(): void
    {
        $org = $this->org('school');
        $other = $this->org('school');

        $this->placed($org, 'contact_form');
        $this->placed($org, 'about_us');
        $this->placed($org, 'mission_vision');
        $this->placed($org, 'events', pageActive: false);
        $this->placed($org, 'gallery', sectionActive: false);
        $this->placed($org, 'announcements_list')->delete(); // a soft-deleted page
        $this->placed($other, 'contact_form');
        $this->placed($other, 'offering');

        Sanctum::actingAs($this->superAdmin());
        $entries = $this->entries($this->getJson($this->url($org))->assertOk()->json('data'));

        $this->assertSame(1, $entries['contact_requests']['in_use']);
        $this->assertSame(2, $entries['about_us']['in_use'], 'about_us and mission_vision both show About Us');
        $this->assertSame(0, $entries['events']['in_use']);
        $this->assertSame(0, $entries['gallery']['in_use']);
        $this->assertSame(0, $entries['announcements']['in_use']);
        $this->assertSame(0, $entries['programs']['in_use'], 'another organisation\'s section was counted');

        // Nothing a section depends on.
        $this->assertNull($entries['broadcasts']['in_use']);
        $this->assertNull($entries['web_pages']['in_use']);
        $this->assertNull($entries['crm']['in_use']);
    }

    #[Test]
    public function history_is_this_organisations_last_25_newest_first_with_who(): void
    {
        $org = $this->org('school');
        $other = $this->org('school');
        $owner = $this->superAdmin('Moneeb');

        $mine = [];
        for ($i = 0; $i < 30; $i++) {
            $mine[] = MasjidCapabilityChange::create([
                'masjid_id' => $org->id, 'capability' => 'events',
                'enabled_before' => true, 'enabled_after' => false, 'override_before' => null,
                'actor_user_id' => $owner->id,
            ])->id;
        }

        $theirs = MasjidCapabilityChange::create([
            'masjid_id' => $other->id, 'capability' => 'gallery',
            'enabled_before' => true, 'enabled_after' => false, 'override_before' => null,
            'actor_user_id' => $owner->id,
        ])->id;

        // An actor whose user row no longer exists at all.
        $gone = MasjidCapabilityChange::create([
            'masjid_id' => $org->id, 'capability' => 'directory_listing',
            'enabled_before' => false, 'enabled_after' => true, 'override_before' => null,
            'actor_user_id' => 987654321,
        ]);

        Sanctum::actingAs($owner);
        $history = $this->getJson($this->url($org))->assertOk()->json('data.history');

        $this->assertCount(25, $history);
        $ids = array_column($history, 'id');
        $this->assertNotContains($theirs, $ids);
        $this->assertSame((int) $gone->id, $history[0]['id'], 'newest first');
        $this->assertSame(array_values(array_reverse(array_slice($mine, -24))), array_slice($ids, 1));

        $this->assertNull($history[0]['actor_name']);
        $this->assertSame('Directory listing', $history[0]['label']);
        $this->assertSame('Moneeb', $history[1]['actor_name']);
        $this->assertSame('Events', $history[1]['label']);
        $this->assertTrue($history[1]['enabled_before']);
        $this->assertFalse($history[1]['enabled_after']);
        $this->assertNull($history[1]['override_before']);
        $this->assertNotEmpty($history[1]['created_at']);
    }
}
