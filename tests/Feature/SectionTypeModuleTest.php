<?php

namespace Tests\Feature;

use App\Enums\SectionType;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Which section types show a switchable module's data, and the note the page
 * builder prints when that module is switched off (DECISIONS.md 2026-09-16,
 * .claude/rules/section-types.md).
 *
 * The note follows the ORGANISATION, never the viewer: a SuperAdmin building a
 * page for an organisation with Events off reads exactly what that
 * organisation's own admin reads. And the palette is not filtered — every type
 * is still offered.
 */
class SectionTypeModuleTest extends TestCase
{
    use RefreshDatabase;

    private const EXPECTED = [
        'announcements_list' => 'announcements',
        'events' => 'events',
        'gallery' => 'gallery',
        'about_us' => 'about_us',
        'mission_vision' => 'about_us',
        'contact_form' => 'contact_requests',
        'offering' => 'programs',
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

    private function org(array $overrides): Masjid
    {
        $masjid = Masjid::create([
            'name' => 'Builder Org ' . uniqid(),
            'email' => 'builder' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'crm_enabled' => true, 'org_type' => 'masjid',
        ]);

        $masjid->forceFill(['capability_overrides' => $overrides])->save();

        return $masjid;
    }

    private function adminOf(Masjid $masjid): User
    {
        $user = User::factory()->create(['type' => 'MasjidAdmin', 'phone' => '+1' . random_int(1000000000, 9999999999)]);
        MasjidUser::create(['masjid_id' => $masjid->id, 'user_id' => $user->id, 'role' => 'masjid-admin', 'is_default' => true]);

        return $user->fresh();
    }

    /** @return array<string, array<string,mixed>> value => payload entry */
    private function types(Masjid $masjid): array
    {
        return collect($this->getJson("/api/admin/masjids/{$masjid->id}/section-types")->assertOk()->json('data'))
            ->keyBy('value')
            ->all();
    }

    #[Test]
    public function every_type_is_classified_and_the_map_is_the_one_decided(): void
    {
        $actual = [];

        foreach (SectionType::cases() as $type) {
            // Calling it is the exhaustiveness check: an unclassified case is an
            // UnhandledMatchError here.
            $module = $type->requiresModule();

            if ($module === null) {
                $this->assertNull($type->moduleOffNote(), "{$type->value} has a note but no module");

                continue;
            }

            $this->assertContains($module, Masjid::MODULE_KEYS, "{$type->value} names '{$module}', which is not a module");
            $this->assertNotEmpty($type->moduleOffNote(), "{$type->value} shows {$module} but has no note");
            $actual[$type->value] = $module;
        }

        // Key order follows SectionType::cases(), which is not the order above,
        // so sort both sides: this compares each type against its module exactly.
        $expected = self::EXPECTED;
        ksort($expected);
        ksort($actual);
        $this->assertSame($expected, $actual);
    }

    #[Test]
    public function the_note_follows_the_organisation_not_the_viewer(): void
    {
        $org = $this->org(['web_pages' => true, 'events' => false, 'contact_requests' => false]);

        Sanctum::actingAs(User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+15550000000'])->fresh());
        $asOwner = $this->types($org);

        Sanctum::actingAs($this->adminOf($org));
        $asAdmin = $this->types($org);

        foreach ([$asOwner, $asAdmin] as $types) {
            $this->assertSame(SectionType::EVENTS->moduleOffNote(), $types['events']['module_off_note']);
            $this->assertSame(SectionType::CONTACT_FORM->moduleOffNote(), $types['contact_form']['module_off_note']);
            $this->assertNull($types['announcements_list']['module_off_note']);
            $this->assertNull($types['text']['module_off_note']);

            // The palette is not filtered: every type is still offered, with the key.
            $this->assertCount(count(SectionType::cases()), $types);

            foreach ($types as $value => $entry) {
                $this->assertArrayHasKey('module_off_note', $entry, "{$value} has no module_off_note key");
            }
        }

        // Another organisation, same SuperAdmin: nothing is off there, so no note.
        $allOn = $this->org(['web_pages' => true]);
        Sanctum::actingAs(User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+15550000001'])->fresh());

        foreach ($this->types($allOn) as $value => $entry) {
            $this->assertNull($entry['module_off_note'], "{$value} carries a note at an organisation with everything on");
        }
    }
}
