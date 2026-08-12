<?php

namespace Tests\Feature;

use App\Enums\SectionType;
use App\Models\Masjid;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * THE PRODUCT TELLS ONE TRUTH ABOUT WHAT A SECTION TYPE WILL DRAW.
 *
 * The section renderers live in the Nuxt site repo and ship separately, so the
 * backend half of a type can land while the half that DRAWS it does not.
 * `offering` is in exactly that state: the public read, the presenter, the
 * section and the binder all exist; the component that draws a registration
 * block does not.
 *
 * Three surfaces described that differently at once:
 *
 *   SectionType::OFFERING->description()   "…its fee plans, the places left, and
 *                                           the registration form"  — a promise
 *   OfferingSectionEditor.vue              a preview of the states the published
 *                                           block would show — a second promise
 *   OfferingsView.vue                      "the public sign-up page is not drawn
 *                                           yet" — the truth, on a screen an
 *                                           admin need never open
 *
 * An admin who read the first two published a section that renders nothing and,
 * as the third warns, reasonably published the intake FORM instead — which
 * writes a `FormResponse` and never a `Registration`: no seat taken, no money
 * moved, and every screen agreeing that nothing happened.
 *
 * The fix is structural rather than editorial. `SectionType::withoutRenderer()`
 * is the one list; `hasRenderer()`, `rendererNote()`, the appended
 * `description()` and the admin `section-types` payload are all derived from it,
 * and the SPA prints the server's string rather than keeping copies. This file
 * is what stops that decaying back into three copies.
 */
class SectionTypeRendererTruthTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjid;

    private User $admin;

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

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->masjid = $this->makeMasjid();
        $this->admin = $this->makeAdminFor($this->masjid);
    }

    #[Test]
    public function the_offering_type_is_currently_the_only_one_without_a_renderer(): void
    {
        // Not a style assertion: a list that quietly grows is how "some sections
        // do not render" becomes folklore instead of a fact the UI states. When
        // the Nuxt component ships, this expectation becomes an empty array in
        // the same commit that empties withoutRenderer().
        $this->assertSame([SectionType::OFFERING], SectionType::withoutRenderer());

        $this->assertFalse(SectionType::OFFERING->hasRenderer());
        $this->assertNotNull(SectionType::OFFERING->rendererNote());
    }

    #[Test]
    public function every_type_without_a_renderer_says_so_in_its_own_description(): void
    {
        // The load-bearing property. Any surface that prints only the
        // description — the palette dropdown does exactly that — still tells the
        // truth, without having to remember a caveat.
        foreach (SectionType::cases() as $type) {
            if ($type->hasRenderer()) {
                $this->assertNull($type->rendererNote(), "{$type->value} has a note but claims a renderer");
                $this->assertStringNotContainsString(
                    SectionType::RENDERER_PENDING_NOTE,
                    $type->description(),
                    "{$type->value} carries the not-rendered caveat but DOES render — it will read as broken"
                );

                continue;
            }

            $this->assertSame(SectionType::RENDERER_PENDING_NOTE, $type->rendererNote());
            $this->assertStringContainsString(
                SectionType::RENDERER_PENDING_NOTE,
                $type->description(),
                "{$type->value} has no renderer and its description does not say so"
            );
        }
    }

    #[Test]
    public function the_offering_description_no_longer_promises_a_rendering(): void
    {
        $description = SectionType::OFFERING->description();

        // The specific over-promise that shipped. It named three things a page
        // would show, and a page shows none of them.
        foreach (['the places left', 'the registration form'] as $promise) {
            $this->assertStringNotContainsString(
                $promise,
                $description,
                'the offering description still promises what a page will render'
            );
        }

        // It still describes what the section HOLDS — a reference — so an admin
        // can tell it apart from `form` in the palette.
        $this->assertStringContainsString('reference', $description);
        $this->assertStringContainsString('NOT YET RENDERED', $description);
    }

    #[Test]
    public function the_admin_section_types_payload_carries_the_flag_and_the_sentence(): void
    {
        Sanctum::actingAs($this->admin);

        $types = collect(
            $this->getJson("/api/admin/masjids/{$this->masjid->id}/section-types")
                ->assertStatus(200)
                ->json('data')
        )->keyBy('value');

        // Every type is classified — a missing key would silently read as
        // `undefined` in the SPA and hide the banner.
        foreach (SectionType::cases() as $type) {
            $this->assertArrayHasKey('has_renderer', $types[$type->value], "{$type->value} has no has_renderer");
            $this->assertArrayHasKey('renderer_note', $types[$type->value], "{$type->value} has no renderer_note");
            $this->assertSame($type->hasRenderer(), $types[$type->value]['has_renderer']);
            $this->assertSame($type->rendererNote(), $types[$type->value]['renderer_note']);
        }

        $this->assertFalse($types['offering']['has_renderer']);
        $this->assertSame(SectionType::RENDERER_PENDING_NOTE, $types['offering']['renderer_note']);

        // The type is still OFFERED. It is not gated out of the palette: the data
        // genuinely is served, a tenant may lay its page out ahead of the
        // renderer, and per-type gating is the mechanism
        // .claude/rules/section-types.md says not to invent. What is forbidden is
        // promising a rendering.
        $this->assertNotNull($types['offering']);
    }

    #[Test]
    public function the_spa_prints_the_servers_sentence_rather_than_keeping_a_copy(): void
    {
        // Lexical, and a floor rather than a ceiling — it proves the two surfaces
        // that used to promise a rendering now read `renderer_note` off the
        // payload. A hardcoded second copy is what made the three stories
        // possible, and a copy cannot be flipped by deleting one enum entry.
        $sources = [
            'resources/vue-app/components/modals/SectionFormModal.vue',
            'resources/vue-app/components/sections/editors/OfferingSectionEditor.vue',
        ];

        foreach ($sources as $relative) {
            $source = file_get_contents(base_path($relative));

            $this->assertStringContainsString(
                'renderer_note',
                $source,
                "{$relative} does not read the server's renderer_note"
            );

            // The enum's own words must not be duplicated into a component.
            $this->assertStringNotContainsString(
                'NOT YET RENDERED',
                $source,
                "{$relative} hardcodes the caveat instead of printing the server's — it will not "
                . 'flip when the renderer ships'
            );
        }
    }

    // ============================= helpers =============================

    private function makeMasjid(array $overrides = []): Masjid
    {
        return Masjid::create(array_merge([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'crm_enabled' => true,
        ], $overrides));
    }

    private function makeAdminFor(Masjid $masjid): User
    {
        $admin = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);

        $masjid->user_id = $admin->id;
        $masjid->save();

        return $admin;
    }
}
