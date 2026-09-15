<?php

namespace Tests\Feature;

use App\Enums\SectionType;
use App\Models\DonationLink;
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
 * override, in-use count, place (`where`, or `surface` for a module with no
 * admin screen at all), facts and whether the org type is offered it, plus this
 * organisation's last 25 flips.
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
    public function the_org_says_whether_the_apps_have_a_donation_link_to_fall_back_on(): void
    {
        // Both apps show the link on Donate while no fund is offered, and "No donation
        // options are available right now" when it is blank; the Giving confirm reads this.
        $org = $this->org('masjid');
        Sanctum::actingAs($this->superAdmin());

        $this->getJson($this->url($org))->assertOk()->assertJsonPath('data.org.donation_link_set', false);

        $link = DonationLink::create(['masjid_id' => $org->id, 'link' => '   ']);
        $this->getJson($this->url($org))->assertOk()->assertJsonPath('data.org.donation_link_set', false);

        $link->forceFill(['link' => 'https://give.example.org'])->save();
        $this->getJson($this->url($org))->assertOk()->assertJsonPath('data.org.donation_link_set', true);

        // Another organisation's link is not this one's.
        $this->getJson($this->url($this->org('masjid')))->assertOk()->assertJsonPath('data.org.donation_link_set', false);
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

        $this->assertSame(['id' => (int) $org->id, 'name' => $org->name, 'org_type' => 'school', 'donation_link_set' => false], $data['org']);

        // Groups in config order, each labelled from config.
        $this->assertSame(
            array_keys(config('capability_groups')),
            array_column($data['groups'], 'key')
        );
        $this->assertSame(config('capability_groups.content'), $data['groups'][0]['label']);

        // Prayer and worship have a card of their own, straight after content:
        // prayer times, then the five app-only worship modules in catalogue order.
        $this->assertSame('prayer', $data['groups'][1]['key']);
        $this->assertSame(config('capability_groups.prayer'), $data['groups'][1]['label']);
        $this->assertSame(
            ['prayer_times', 'quran', 'hadith', 'adhkar', 'qibla', 'tasbih'],
            array_column($data['groups'][1]['entries'], 'key')
        );

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
        $this->placed($org, 'prayer_times');
        $this->placed($org, 'events', pageActive: false);
        $this->placed($org, 'gallery', sectionActive: false);
        $this->placed($org, 'announcements_list')->delete(); // a soft-deleted page
        $this->placed($other, 'contact_form');
        $this->placed($other, 'offering');
        $this->placed($other, 'donation');

        Sanctum::actingAs($this->superAdmin());
        $entries = $this->entries($this->getJson($this->url($org))->assertOk()->json('data'));

        $this->assertSame(1, $entries['contact_requests']['in_use']);
        $this->assertSame(2, $entries['about_us']['in_use'], 'about_us and mission_vision both show About Us');
        $this->assertSame(1, $entries['prayer_times']['in_use']);
        $this->assertSame(0, $entries['events']['in_use']);
        $this->assertSame(0, $entries['gallery']['in_use']);
        $this->assertSame(0, $entries['announcements']['in_use']);
        $this->assertSame(0, $entries['programs']['in_use'], 'another organisation\'s section was counted');
        $this->assertSame(0, $entries['donation_link']['in_use'], 'another organisation\'s section was counted');
        $this->assertSame(0, $entries['services']['in_use']);

        // Nothing a section depends on.
        $this->assertNull($entries['broadcasts']['in_use']);
        $this->assertNull($entries['giving']['in_use']);
        $this->assertNull($entries['web_pages']['in_use']);
        $this->assertNull($entries['crm']['in_use']);
    }

    #[Test]
    public function where_facts_and_offered_by_default_ride_every_entry(): void
    {
        $school = $this->org('school');
        $masjid = $this->org('masjid');
        Sanctum::actingAs($this->superAdmin());

        $entries = $this->entries($this->getJson($this->url($school))->assertOk()->json('data'));

        foreach ($entries as $key => $entry) {
            $this->assertIsArray($entry['facts'], "{$key} has no facts list");
            $this->assertTrue(array_is_list($entry['facts']), "{$key}'s facts is not a list");
            $this->assertIsBool($entry['offered_by_default'], "{$key} has no offered_by_default");

            // Every entry has exactly ONE placement, or the panel cannot put its
            // row anywhere: a sidebar item (`where` and `surface` both null), a
            // `where` on the Details screen, or `surface: 'app'` — the app-only
            // worship modules, which have no admin screen at all.
            $placements = (int) ($entry['where'] !== null) + (int) ($entry['surface'] !== null);
            $this->assertLessThanOrEqual(1, $placements, "{$key} carries both a where and a surface");

            if ($key === 'prayer_times') {
                $this->assertSame(config('capabilities.prayer_times.where'), $entry['where']);
                $this->assertNotEmpty($entry['where']);
                $this->assertNull($entry['surface']);
            } elseif (in_array($key, ['quran', 'hadith', 'adhkar', 'qibla', 'tasbih'], true)) {
                $this->assertSame('app', $entry['surface'], "{$key} is not placed on the app menu");
                $this->assertNull($entry['where'], "{$key} carries a where");
            } else {
                $this->assertNull($entry['where'], "{$key} carries a where");
                $this->assertNull($entry['surface'], "{$key} carries a surface");
            }

            // One answer, two fields: never let them disagree for a module.
            if ($entry['kind'] === 'module') {
                $this->assertSame($entry['default_for_org_type'], $entry['offered_by_default'], "{$key}: offered_by_default disagrees with default_for_org_type");
            }
        }

        // A school is not offered the masjid screens or the app-only worship
        // modules: they read off, nobody overrode them, and the panel may switch
        // them on.
        foreach (['splash', 'services', 'donation_link', 'giving', 'properties', 'quran', 'hadith', 'adhkar', 'qibla', 'tasbih'] as $key) {
            $this->assertFalse($entries[$key]['offered_by_default'], "{$key} is offered to a school");
            $this->assertFalse($entries[$key]['enabled'], "{$key} is on for a fresh school");
            $this->assertFalse($entries[$key]['overridden']);
        }

        foreach (['prayer_times', 'appointment_requests', 'events'] as $key) {
            $this->assertTrue($entries[$key]['offered_by_default'], "{$key} is not offered to a school");
            $this->assertTrue($entries[$key]['enabled']);
        }

        $this->assertFalse($entries['crm']['offered_by_default']);
        $this->assertFalse($entries['jummah_lunch']['offered_by_default']);

        // Facts only where there is something to say.
        $this->assertNotEmpty($entries['giving']['facts']);
        $this->assertNotEmpty($entries['prayer_times']['facts']);
        $this->assertSame(['No live splash'], $entries['splash']['facts']);
        $this->assertSame([], $entries['events']['facts']);
        $this->assertSame([], $entries['services']['facts']);
        $this->assertSame([], $entries['web_pages']['facts']);

        // A masjid is offered all of them, and has them.
        $entries = $this->entries($this->getJson($this->url($masjid))->assertOk()->json('data'));

        foreach (Masjid::MODULE_KEYS as $key) {
            $this->assertTrue($entries[$key]['offered_by_default'], "{$key} is not offered to a masjid");
            $this->assertTrue($entries[$key]['enabled'], "{$key} is off for a fresh masjid");
        }
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
