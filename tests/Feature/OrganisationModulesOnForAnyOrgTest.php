<?php

namespace Tests\Feature;

use App\Models\Masjid;
use App\Models\MasjidCapabilityChange;
use App\Models\MasjidUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The masjid screens for any organisation (owner, 2026-09-14).
 *
 * Splash, Services, Donation link, Giving and Properties & Rent are offered to
 * masjids only (Masjid::MODULE_DEFAULTS). A SuperAdmin can switch one ON for a
 * school or community organisation. Two payload lists keep their meanings apart:
 *
 *  - `modules_off`: offered to this org type, and switched off here;
 *  - `modules_on`:  NOT offered to this org type, and switched on here.
 *
 * A module that is not offered and still off rides neither: nobody took it
 * away. The gate refuses it as "not switched on", never "switched off". And a
 * config cache from before these modules existed answers each org type's
 * default, so a deploy neither hands a school a money screen nor takes one from
 * a masjid.
 */
class OrganisationModulesOnForAnyOrgTest extends TestCase
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

    private function org(string $orgType, bool $crm = true): Masjid
    {
        return Masjid::create([
            'name' => 'Any Org ' . uniqid(),
            'email' => 'anyorg' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'crm_enabled' => $crm, 'org_type' => $orgType,
            'assistant_enabled' => false,
        ]);
    }

    private function admin(Masjid $masjid): User
    {
        $user = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);

        MasjidUser::create([
            'masjid_id' => $masjid->id, 'user_id' => $user->id,
            'role' => 'masjid-admin', 'is_default' => true,
        ]);

        return $user->fresh();
    }

    private function superAdmin(): User
    {
        return User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+1' . random_int(1000000000, 9999999999)])->fresh();
    }

    /** @param array<string,bool> $decisions */
    private function decide(Masjid $masjid, array $decisions): void
    {
        $overrides = $masjid->fresh()->capability_overrides ?? [];
        $masjid->forceFill(['capability_overrides' => array_merge($overrides, $decisions)])->save();
    }

    #[Test]
    public function modules_off_is_offered_and_off_and_modules_on_is_not_offered_and_on(): void
    {
        $this->assertContains('modules_on', Masjid::ADMIN_APPENDS);

        $masjid = $this->org('masjid');
        $school = $this->org('school');
        $community = $this->org('community');

        foreach ([$masjid, $school, $community] as $org) {
            $this->assertSame([], $org->modules_off, "a fresh {$org->orgType()} has modules_off");
            $this->assertSame([], $org->modules_on, "a fresh {$org->orgType()} has modules_on");
        }

        $this->assertTrue($masjid->moduleOfferedByDefault('giving'));
        $this->assertFalse($school->moduleOfferedByDefault('giving'));
        $this->assertFalse($community->moduleOfferedByDefault('splash'));
        $this->assertTrue($school->moduleOfferedByDefault('prayer_times'));
        $this->assertTrue($community->moduleOfferedByDefault('appointment_requests'));

        // A masjid switching Giving off: offered and off. Splash decided on,
        // which is its default, is on nobody's list.
        $this->decide($masjid, ['giving' => false, 'splash' => true]);
        $this->assertSame(['giving'], $masjid->fresh()->modules_off);
        $this->assertSame([], $masjid->fresh()->modules_on);

        // A school switched on for Services and Giving, with Splash decided off
        // (its default) and Events switched off.
        $this->decide($school, ['giving' => true, 'services' => true, 'splash' => false, 'events' => false]);
        $fresh = $school->fresh();
        $this->assertSame(['services', 'giving'], $fresh->modules_on, 'catalogue order');
        $this->assertSame(['events'], $fresh->modules_off);
        $this->assertFalse($fresh->moduleIsOff('giving'));
        $this->assertTrue($fresh->moduleIsOff('splash'));
        $this->assertTrue($fresh->moduleIsOff('donation_link'));

        $this->decide($community, ['properties' => true, 'prayer_times' => false, 'appointment_requests' => false]);
        $this->assertSame(['properties'], $community->fresh()->modules_on);
        $this->assertSame(['prayer_times', 'appointment_requests'], $community->fresh()->modules_off);
    }

    #[Test]
    public function the_switch_panel_turns_a_masjid_screen_on_for_a_school_and_off_again(): void
    {
        $school = $this->org('school');
        Sanctum::actingAs($this->superAdmin());

        // Form-encoded, exactly as the SPA sends it.
        $this->patch("/api/admin/masjids/{$school->id}/capabilities/giving", ['enabled' => '1'], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.modules_on', ['giving'])
            ->assertJsonPath('data.modules_off', []);

        $row = MasjidCapabilityChange::where('masjid_id', $school->id)->where('capability', 'giving')->latest('id')->firstOrFail();
        $this->assertFalse((bool) $row->enabled_before);
        $this->assertTrue((bool) $row->enabled_after);
        $this->assertNull($row->override_before);

        // The admin payload the SPA loads carries it.
        Sanctum::actingAs($this->admin($school));
        $this->getJson("/api/admin/masjids/{$school->id}")
            ->assertOk()
            ->assertJsonPath('data.modules_on', ['giving'])
            ->assertJsonPath('data.modules_off', []);

        // Switched back off, it is on neither list again: not offered, and off.
        Sanctum::actingAs($this->superAdmin());
        $this->patch("/api/admin/masjids/{$school->id}/capabilities/giving", ['enabled' => '0'], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.modules_on', [])
            ->assertJsonPath('data.modules_off', []);

        $this->assertFalse($school->fresh()->capability_overrides['giving']);
        $this->assertTrue($school->fresh()->moduleIsOff('giving'));
    }

    #[Test]
    public function a_module_the_org_type_is_not_offered_is_refused_as_not_switched_on(): void
    {
        $school = $this->org('school');
        Sanctum::actingAs($this->admin($school));

        $expected = [
            'funds' => 'Giving is not switched on for this organisation.',
            'donations' => 'Giving is not switched on for this organisation.',
            'recurring-donations' => 'Giving is not switched on for this organisation.',
            'splash-announcements' => 'Splash is not switched on for this organisation.',
            'donation-link' => 'Donation link is not switched on for this organisation.',
            'properties' => 'Properties & Rent is not switched on for this organisation.',
            'services/1' => 'Services is not switched on for this organisation.',
        ];

        foreach ($expected as $path => $sentence) {
            $this->getJson("/api/admin/masjids/{$school->id}/{$path}")
                ->assertForbidden()
                ->assertExactJson(['status' => 'error', 'message' => $sentence]);
        }

        // Offered modules a SuperAdmin switched off still say so.
        $this->decide($school, ['prayer_times' => false]);
        $this->getJson("/api/admin/masjids/{$school->id}/iqama")
            ->assertForbidden()
            ->assertExactJson(['status' => 'error', 'message' => 'Prayer times is switched off for this organisation.']);

        $masjid = $this->org('masjid');
        $this->decide($masjid, ['giving' => false]);
        Sanctum::actingAs($this->admin($masjid));
        $this->getJson("/api/admin/masjids/{$masjid->id}/funds")
            ->assertForbidden()
            ->assertExactJson(['status' => 'error', 'message' => 'Giving is switched off for this organisation.']);

        $community = $this->org('community');
        $this->decide($community, ['appointment_requests' => false]);
        Sanctum::actingAs($this->admin($community));
        $this->getJson("/api/admin/masjids/{$community->id}/appointment-requests")
            ->assertForbidden()
            ->assertExactJson(['status' => 'error', 'message' => 'Appointment Requests is switched off for this organisation.']);
    }

    #[Test]
    public function a_school_with_giving_switched_on_reaches_its_giving_screens_as_its_admin(): void
    {
        $school = $this->org('school');
        $stillOff = $this->org('school');
        $this->decide($school, ['giving' => true]);

        Sanctum::actingAs($this->admin($school));

        foreach (['funds', 'donations', 'recurring-donations'] as $path) {
            $this->getJson("/api/admin/masjids/{$school->id}/{$path}")->assertOk();
        }

        $this->assertNotSame(403, $this->getJson("/api/admin/masjids/{$school->id}/annual-statements")->getStatusCode());

        // Only Giving: Properties & Rent was not switched on.
        $this->getJson("/api/admin/masjids/{$school->id}/properties")->assertForbidden();

        // Another school's switch is not this one's.
        Sanctum::actingAs($this->admin($stillOff));
        $this->getJson("/api/admin/masjids/{$stillOff->id}/funds")->assertForbidden();

        // Switching a money screen on never switches the CRM on: its gate still
        // answers first.
        $school->forceFill(['crm_enabled' => false])->save();
        Sanctum::actingAs($this->admin($school));
        $body = $this->getJson("/api/admin/masjids/{$school->id}/funds")->assertForbidden()->getContent();
        $this->assertStringNotContainsString('for this organisation.', $body);
    }

    #[Test]
    public function team_access_never_lists_a_screen_the_org_type_is_not_offered(): void
    {
        // A school holds none of the masjid screens by default. Its admins'
        // "switched off" sentence must not fill with them.
        $school = $this->org('school');
        Sanctum::actingAs($this->admin($school));

        $this->getJson("/api/admin/masjids/{$school->id}/team")
            ->assertOk()
            ->assertJsonPath('data.screens_off', []);

        $this->decide($school, ['events' => false, 'giving' => false]);

        $this->getJson("/api/admin/masjids/{$school->id}/team")
            ->assertOk()
            ->assertJsonPath('data.screens_off', [['key' => 'events', 'label' => 'Events']]);
    }

    #[Test]
    public function a_stale_config_answers_each_org_types_default_and_reads_no_override(): void
    {
        $masjid = $this->org('masjid');
        $school = $this->org('school');
        $community = $this->org('community');

        $this->decide($masjid, ['giving' => false, 'events' => false]);
        $this->decide($school, ['giving' => true, 'events' => false]);

        // While the catalogue knows the modules, the decisions bite.
        $this->assertTrue($masjid->fresh()->moduleIsOff('giving'));
        $this->assertFalse($school->fresh()->moduleIsOff('giving'));

        // The deploy window: a config cache from before any module existed.
        config(['capabilities' => array_filter(
            config('capabilities', []),
            fn ($definition) => ($definition['kind'] ?? null) !== 'module'
        )]);

        $masjid = $masjid->fresh();
        $school = $school->fresh();

        // A masjid keeps every screen it had.
        foreach (Masjid::MODULE_KEYS as $key) {
            $this->assertFalse($masjid->moduleIsOff($key), "{$key} closed for a masjid on a stale config");
        }

        // A school reads its defaults: the masjid screens off, everything else on.
        foreach (Masjid::MODULE_KEYS as $key) {
            $this->assertSame(
                ! Masjid::MODULE_DEFAULTS[$key]['school'],
                $school->moduleIsOff($key),
                "{$key} for a school on a stale config"
            );
        }

        $this->assertTrue($community->fresh()->moduleIsOff('properties'));
        $this->assertFalse($community->fresh()->moduleIsOff('prayer_times'));

        // Neither list moves on a stale config.
        $this->assertSame([], $masjid->modules_off);
        $this->assertSame([], $school->modules_off);
        $this->assertSame([], $school->modules_on);

        // An unknown key is never off, whatever the config.
        $this->assertFalse($school->moduleIsOff('nope'));

        // The gate agrees: the masjid's admin passes, the school's is refused.
        Sanctum::actingAs($this->admin($masjid));
        $this->getJson("/api/admin/masjids/{$masjid->id}/funds")->assertOk();

        Sanctum::actingAs($this->admin($school));
        $this->getJson("/api/admin/masjids/{$school->id}/funds")->assertForbidden();
    }
}
