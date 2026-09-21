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
 * Modules: the screens a SuperAdmin can switch off for ONE organisation
 * (config/capabilities.php, kind => module; DECISIONS.md 2026-09-16), and the
 * masjid screens a SuperAdmin can switch ON for a school or community
 * organisation (owner, 2026-09-14; OrganisationModulesOnForAnyOrgTest).
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

    /**
     * Admin route prefix (after `{masjid_id}/`) => the module that must gate it.
     *
     * Not `services`: its index stays open (see
     * services_gates_every_write_and_show_and_leaves_the_picker_list_open).
     * `donations` covers donations/export and donations/stats too.
     */
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
        'splash-announcements' => 'splash',
        'donation-link' => 'donation_link',
        'iqama' => 'prayer_times',
        'jumaa' => 'prayer_times',
        'prayer-calculation' => 'prayer_times',
        'funds' => 'giving',
        'donations' => 'giving',
        'recurring-donations' => 'giving',
        'annual-statements' => 'giving',
        'properties' => 'properties',
        'appointment-requests' => 'appointment_requests',
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
        'prayer_times' => 'iqama',
        'splash' => 'splash-announcements',
        'donation_link' => 'donation-link',
        'giving' => 'donations',
        'properties' => 'properties',
        'appointment_requests' => 'appointment-requests',
    ];

    /** The gates that sit inside `crm`, by prefix. */
    private const CRM_MODULE_PREFIXES = [
        'funds' => 'giving',
        'donations' => 'giving',
        'recurring-donations' => 'giving',
        'annual-statements' => 'giving',
        'properties' => 'properties',
        'appointment-requests' => 'appointment_requests',
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

    /** @return list<string> the modules an org type is not offered until a SuperAdmin switches them on */
    private function notOfferedTo(string $orgType): array
    {
        return array_keys(array_filter(Masjid::MODULE_DEFAULTS, fn (array $defaults) => $defaults[$orgType] === false));
    }

    /** The sentence the gate refuses a module with at this organisation. */
    private function refusal(Masjid $masjid, string $module): string
    {
        $label = config("capabilities.{$module}.label");

        return $masjid->moduleOfferedByDefault($module)
            ? "{$label} is switched off for this organisation."
            : "{$label} is not switched on for this organisation.";
    }

    /** @return iterable<array{0:\Illuminate\Routing\Route, 1:string}> admin routes under {masjid_id}/, with the rest of the uri */
    private function orgRoutes(): iterable
    {
        $base = 'api/admin/masjids/{masjid_id}/';

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (str_starts_with($route->uri(), $base)) {
                yield [$route, substr($route->uri(), strlen($base))];
            }
        }
    }

    private static function under(string $rest, string $prefix): bool
    {
        return $rest === $prefix || str_starts_with($rest, $prefix . '/');
    }

    #[Test]
    public function the_capabilities_payload_is_still_grants_only_and_nothing_is_off_for_a_fresh_organisation(): void
    {
        foreach (Masjid::ORG_TYPES as $orgType) {
            $org = $this->org($orgType);
            Sanctum::actingAs($this->admin($org));

            $data = $this->getJson("/api/admin/masjids/{$org->id}")->assertOk()->json('data');

            $this->assertSame(
                ['web_pages', 'jummah_lunch', 'school_calendar', 'crm', 'assistant', 'form_editing',
                    'report_card_core_subjects', 'short_lesson_plan', 'simple_marking'],
                array_keys($data['capabilities']),
                "a {$orgType}'s capabilities gained or lost a key"
            );
            $this->assertSame([], $data['modules_off']);
            $this->assertSame([], $data['modules_on']);
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
        $this->assertSame([], $data['modules_on']);
        $this->assertSame([
            'web_pages' => true,
            'jummah_lunch' => false,
            'school_calendar' => true,
            'crm' => true,
            'assistant' => false,
            'form_editing' => false,
            'report_card_core_subjects' => false,
            'short_lesson_plan' => false,
            'simple_marking' => false,
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
        $checked = [];

        foreach ($this->orgRoutes() as [$route, $rest]) {
            $middleware = $route->gatherMiddleware();

            foreach (self::GATED_PREFIXES as $prefix => $module) {
                if (! self::under($rest, $prefix)) {
                    continue;
                }

                $this->assertContains("capability:{$module}", $middleware, "{$route->uri()} is not gated on {$module}");

                // Web Pages needs the org's admins to hold the grant as well.
                if ($module === 'website') {
                    $this->assertContains('capability:web_pages', $middleware, "{$route->uri()} lost capability:web_pages");
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
        // The masjid screens are not offered to a school, so "nobody else" is a
        // school a SuperAdmin switched them on for.
        $this->decide($on, ['web_pages' => true] + array_fill_keys($this->notOfferedTo('school'), true));

        Sanctum::actingAs($this->admin($off));

        foreach (self::MODULE_READS as $module => $path) {
            $sentence = $this->refusal($off, $module);

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

    /**
     * BroadcastComposerView (service audiences), jummahLunchStore (the
     * notify-followers picker) and AboutUsView read GET /services. They keep
     * listing the services already published while Services is off, so the
     * index is the one services route without the gate; only the Services
     * screens call show and the writes.
     */
    #[Test]
    public function services_gates_every_write_and_show_and_leaves_the_picker_list_open(): void
    {
        $gated = [];
        $index = false;

        foreach ($this->orgRoutes() as [$route, $rest]) {
            if (! self::under($rest, 'services')) {
                continue;
            }

            $middleware = $route->gatherMiddleware();
            $methods = array_values(array_diff($route->methods(), ['HEAD']));

            if ($rest === 'services' && $methods === ['GET']) {
                $this->assertNotContains('capability:services', $middleware, 'the services index must stay open');
                $index = true;

                continue;
            }

            $this->assertContains('capability:services', $middleware, implode('|', $methods) . " {$route->uri()} is not gated on services");
            $gated[] = implode('|', $methods) . ' ' . $rest;
        }

        $this->assertTrue($index, 'no GET services index found; did the prefix move?');
        $this->assertEqualsCanonicalizing([
            'POST services',
            'GET services/{service_id}',
            'POST services/{service_id}',
            'DELETE services/{service_id}',
            'DELETE services/{service_id}/trash',
        ], $gated);

        // Behaviour: the list answers, the Services screen's own calls refuse.
        $masjid = $this->org('masjid');
        $this->decide($masjid, ['services' => false]);
        Sanctum::actingAs($this->admin($masjid));

        $this->getJson("/api/admin/masjids/{$masjid->id}/services")->assertOk();
        $this->getJson("/api/admin/masjids/{$masjid->id}/services?page=1")->assertOk();

        $sentence = 'Services is switched off for this organisation.';
        $this->assertStringContainsString(
            $sentence,
            $this->postJson("/api/admin/masjids/{$masjid->id}/services", ['title' => 'Nikah'])->assertForbidden()->getContent()
        );
        $this->assertStringContainsString(
            $sentence,
            $this->getJson("/api/admin/masjids/{$masjid->id}/services/1")->assertForbidden()->getContent()
        );

        // A school was never offered Services: its list still answers, and a
        // write hears that it was not switched on.
        $school = $this->org('school');
        Sanctum::actingAs($this->admin($school));

        $this->getJson("/api/admin/masjids/{$school->id}/services")->assertOk();
        $this->assertStringContainsString(
            'Services is not switched on for this organisation.',
            $this->postJson("/api/admin/masjids/{$school->id}/services", ['title' => 'Nikah'])->assertForbidden()->getContent()
        );
    }

    #[Test]
    public function money_and_appointment_gates_sit_inside_crm(): void
    {
        // Route order: `crm` runs before the module gate on every one of them.
        $checked = [];

        foreach ($this->orgRoutes() as [$route, $rest]) {
            foreach (self::CRM_MODULE_PREFIXES as $prefix => $module) {
                if (! self::under($rest, $prefix)) {
                    continue;
                }

                $middleware = $route->gatherMiddleware();
                $crm = array_search('crm', $middleware, true);
                $gate = array_search("capability:{$module}", $middleware, true);

                $this->assertNotFalse($crm, "{$route->uri()} is not inside crm");
                $this->assertNotFalse($gate, "{$route->uri()} is not gated on {$module}");
                $this->assertLessThan($gate, $crm, "{$route->uri()} checks {$module} before crm");
                $checked[$prefix] = true;
            }
        }

        foreach (array_keys(self::CRM_MODULE_PREFIXES) as $prefix) {
            $this->assertArrayHasKey($prefix, $checked, "no route found under {$prefix}; did a prefix move?");
        }

        // Behaviour: an organisation without the CRM hears about the CRM, not
        // about a module it also has switched off.
        $masjid = $this->org('masjid', crm: false);
        $this->decide($masjid, ['giving' => false, 'properties' => false, 'appointment_requests' => false]);
        Sanctum::actingAs($this->admin($masjid));

        foreach (['funds', 'donations', 'donations/export', 'donations/stats/summary', 'recurring-donations', 'annual-statements', 'properties', 'appointment-requests'] as $path) {
            $body = $this->getJson("/api/admin/masjids/{$masjid->id}/{$path}")->assertForbidden()->getContent();

            $this->assertStringNotContainsString('for this organisation.', $body, "/{$path} answered with a module sentence before the CRM one");
        }
    }

    #[Test]
    public function connect_zakat_and_fee_plans_do_not_carry_capability_giving(): void
    {
        // Stripe Connect is never behind Giving: Friday lunch, programs and form
        // card payments depend on it. Zakat prices, programs and their fee plans,
        // a member's record, the Impact Report and the app drawer are not giving
        // screens either.
        $prefixes = ['connect', 'zakat-settings', 'offerings', 'contacts', 'impact', 'features'];
        $checked = [];

        foreach ($this->orgRoutes() as [$route, $rest]) {
            foreach ($prefixes as $prefix) {
                if (! self::under($rest, $prefix)) {
                    continue;
                }

                $middleware = $route->gatherMiddleware();
                $this->assertNotContains('capability:giving', $middleware, "{$route->uri()} is behind Giving");
                $this->assertNotContains('capability:properties', $middleware, "{$route->uri()} is behind Properties & Rent");
                $checked[$prefix] = true;
            }
        }

        foreach ($prefixes as $prefix) {
            $this->assertArrayHasKey($prefix, $checked, "no route found under {$prefix}; did a prefix move?");
        }
    }

    #[Test]
    public function prayer_calculation_options_stays_ungated(): void
    {
        // A static list of methods and madhabs that names no organisation.
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($route) => str_starts_with($route->uri(), 'api/admin/') && str_ends_with($route->uri(), 'prayer-calculation/options'));

        $this->assertNotNull($route, 'the prayer-calculation options route moved');
        $this->assertStringNotContainsString('{masjid_id}', $route->uri());

        foreach ($route->gatherMiddleware() as $middleware) {
            $this->assertFalse(
                is_string($middleware) && str_starts_with($middleware, 'capability:'),
                "prayer-calculation/options carries {$middleware}"
            );
        }
    }
}
