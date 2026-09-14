<?php

namespace Tests\Feature;

use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Modules: the default-on screens a SuperAdmin can switch off for ONE
 * organisation (config/capabilities.php, kind => module; DECISIONS.md
 * 2026-09-16).
 *
 * What must hold: nothing moves for an organisation nobody switched anything
 * off for (the admin payload's `capabilities` is still grants only and
 * `modules_off` is []), every module's admin API carries its gate, a switched-
 * off module refuses that organisation's admin with a sentence and nobody else,
 * and the SPA's form-encoded switch stores a real boolean.
 *
 * The stale-config deploy window is ModulesFailOpenTest; the side doors
 * (Broadcasts, Assistant, search, public intake) are ModuleSideDoorsTest.
 */
class OrganisationModulesTest extends TestCase
{
    use RefreshDatabase;

    /** Admin route prefix (after `{masjid_id}/`) => the module that must gate it. */
    private const GATED_PREFIXES = [
        'gallery' => 'gallery',
        'announcements' => 'announcements',
        'broadcasts' => 'broadcasts',
        'events' => 'events',
        'about' => 'about_us',
        'notifications' => 'push_notifications',
        'flyer-templates' => 'flyer_studio',
        'flyers' => 'flyer_studio',
        'contact-requests' => 'contact_requests',
        'contact-reasons' => 'contact_requests',
        'zakat-settings' => 'zakat',
        'offerings' => 'programs',
        'impact' => 'impact_report',
        'pages' => 'website',
        'sections' => 'website',
        'section-types' => 'website',
    ];

    /** One admin read per module screen (push and the website are checked on their own). */
    private const MODULE_READS = [
        'gallery' => 'gallery',
        'announcements' => 'announcements',
        'broadcasts' => 'broadcasts',
        'events' => 'events',
        'about_us' => 'about',
        'flyer_studio' => 'flyers',
        'contact_requests' => 'contact-requests',
        'zakat' => 'zakat-settings',
        'programs' => 'offerings',
        'impact_report' => 'impact/report',
    ];

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

    private function org(string $orgType = 'masjid', bool $crm = true): Masjid
    {
        return Masjid::create([
            'name' => 'Modules Org ' . uniqid(),
            'email' => 'modules' . uniqid() . '@test.local',
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
        return User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+15550000000'])->fresh();
    }

    /** @param array<string,bool> $decisions */
    private function decide(Masjid $masjid, array $decisions): void
    {
        $overrides = $masjid->fresh()->capability_overrides ?? [];
        $masjid->forceFill(['capability_overrides' => array_merge($overrides, $decisions)])->save();
    }

    #[Test]
    public function the_capabilities_payload_is_still_grants_only_and_nothing_is_off_for_a_fresh_organisation(): void
    {
        foreach (Masjid::ORG_TYPES as $orgType) {
            $org = $this->org($orgType);
            Sanctum::actingAs($this->admin($org));

            $data = $this->getJson("/api/admin/masjids/{$org->id}")->assertOk()->json('data');

            $this->assertSame(
                ['web_pages', 'jummah_lunch', 'school_calendar', 'crm', 'assistant', 'form_editing'],
                array_keys($data['capabilities']),
                "a {$orgType}'s capabilities gained or lost a key"
            );
            $this->assertSame([], $data['modules_off']);
        }
    }

    #[Test]
    public function legacy_grant_overrides_switch_no_module_off_and_keep_their_values(): void
    {
        // MEC holds web_pages; a masjid may have lunch switched off; BISS holds
        // the school calendar. None of those decisions is about a module.
        $masjid = $this->org('masjid');
        $this->decide($masjid, ['web_pages' => true, 'jummah_lunch' => false, 'school_calendar' => true]);
        Sanctum::actingAs($this->admin($masjid));

        $data = $this->getJson("/api/admin/masjids/{$masjid->id}")->assertOk()->json('data');

        $this->assertSame([], $data['modules_off']);
        $this->assertSame([
            'web_pages' => true,
            'jummah_lunch' => false,
            'school_calendar' => true,
            'crm' => true,
            'assistant' => false,
            'form_editing' => false,
        ], $data['capabilities']);

        foreach (self::MODULE_READS as $module => $path) {
            $this->assertNotSame(
                403,
                $this->getJson("/api/admin/masjids/{$masjid->id}/{$path}")->getStatusCode(),
                "{$module} refused an organisation that switched nothing off"
            );
        }

        $this->getJson("/api/admin/masjids/{$masjid->id}/pages")->assertOk();
    }

    #[Test]
    public function modules_off_names_what_was_switched_off_in_catalogue_order(): void
    {
        $school = $this->org('school');
        $this->decide($school, ['zakat' => false, 'announcements' => false, 'events' => true]);

        $fresh = $school->fresh();
        $this->assertSame(['announcements', 'zakat'], $fresh->modules_off);
        $this->assertArrayNotHasKey('zakat', $fresh->capabilities);
    }

    #[Test]
    public function every_module_admin_route_carries_its_gate(): void
    {
        $base = 'api/admin/masjids/{masjid_id}/';
        $checked = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();

            if (! str_starts_with($uri, $base)) {
                continue;
            }

            $rest = substr($uri, strlen($base));
            $middleware = $route->gatherMiddleware();

            foreach (self::GATED_PREFIXES as $prefix => $module) {
                if ($rest !== $prefix && ! str_starts_with($rest, $prefix . '/')) {
                    continue;
                }

                $this->assertContains("capability:{$module}", $middleware, "{$uri} is not gated on {$module}");

                // Web Pages needs the org's admins to hold the grant as well.
                if ($module === 'website') {
                    $this->assertContains('capability:web_pages', $middleware, "{$uri} lost capability:web_pages");
                }

                $checked[$module] = true;
            }

            // The lunch flyer upload is Friday lunch, not the Studio.
            if ($rest === 'jummah-lunch/flyer') {
                $this->assertNotContains('capability:flyer_studio', $middleware);
                $this->assertContains('capability:jummah_lunch', $middleware);
            }
        }

        foreach (array_unique(array_values(self::GATED_PREFIXES)) as $module) {
            $this->assertArrayHasKey($module, $checked, "no route found for {$module}; did a prefix move?");
        }
    }

    #[Test]
    public function a_switched_off_module_refuses_that_organisations_admin_and_nobody_else(): void
    {
        $off = $this->org('school');
        $on = $this->org('school');
        $this->decide($off, array_fill_keys(Masjid::MODULE_KEYS, false) + ['web_pages' => true]);
        $this->decide($on, ['web_pages' => true]);

        Sanctum::actingAs($this->admin($off));

        foreach (self::MODULE_READS as $module => $path) {
            $sentence = config("capabilities.{$module}.label") . ' is switched off for this organisation.';

            $response = $this->getJson("/api/admin/masjids/{$off->id}/{$path}")->assertForbidden();
            $this->assertStringContainsString($sentence, $response->getContent(), "{$module} refused without its sentence");
        }

        $this->assertStringContainsString(
            'Notifications is switched off for this organisation.',
            $this->postJson("/api/admin/masjids/{$off->id}/notifications", [])->assertForbidden()->getContent()
        );

        // web_pages is granted, so the only closed gate is the website module.
        $this->assertStringContainsString(
            'Web Pages Management is switched off for this organisation.',
            $this->getJson("/api/admin/masjids/{$off->id}/pages")->assertForbidden()->getContent()
        );

        // One organisation's switch is not another's.
        Sanctum::actingAs($this->admin($on));

        foreach (self::MODULE_READS as $module => $path) {
            $this->assertNotSame(
                403,
                $this->getJson("/api/admin/masjids/{$on->id}/{$path}")->getStatusCode(),
                "{$module} was refused at an organisation that has it"
            );
        }

        $this->getJson("/api/admin/masjids/{$on->id}/pages")->assertOk();
    }

    #[Test]
    public function the_form_encoded_switch_stores_a_boolean_and_moves_modules_off(): void
    {
        $school = $this->org('school');
        Sanctum::actingAs($this->superAdmin());

        // "0"/"1", exactly as the SPA sends it.
        $this->patch("/api/admin/masjids/{$school->id}/capabilities/events", ['enabled' => '0'], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.modules_off', ['events']);
        $this->assertFalse($school->fresh()->capability_overrides['events']);

        $this->patch("/api/admin/masjids/{$school->id}/capabilities/events", ['enabled' => '1'], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.modules_off', []);
        $this->assertTrue($school->fresh()->capability_overrides['events']);
    }
}
