<?php

namespace Tests\Feature;

use App\Enums\SectionType;
use App\Http\Controllers\AdminDashboard\PageSectionsController;
use App\Http\Controllers\AdminDashboard\SectionsController;
use App\Models\FeePlan;
use App\Models\Form;
use App\Models\Masjid;
use App\Models\Offering;
use App\Models\Page;
use App\Models\Section;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Enum;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * T-006g — the `offering` page section: how a registration reaches a page.
 *
 * The section is added the way every section type is (.claude/rules/section-types.md):
 * an enum case, a `content` JSON shape, a Vue editor. What is different is what
 * it points at — the first section type whose referenced row decides what
 * somebody is CHARGED.
 *
 * That is why the shape is a REFERENCE and nothing else. `admissions_tuition`
 * holds tuition figures as display TEXT because nothing charges from them; this
 * one holds no figure at all, because something does. A price copied into a
 * section's JSON would go stale the instant a fee plan was replaced — plans are
 * immutable and are deactivated-and-replaced, never edited — and the page would
 * then advertise one number while Stripe charged another.
 *
 * This suite pins:
 *  1. the type is registered everywhere one has to land, including the two
 *     image-field maps and the enum's exhaustive matches;
 *  2. the content shape survives the ADMIN BUILDER round trip (create, read
 *     back, update) — the "editorMap is a type error vite does not catch" trap
 *     has shipped two types broken already;
 *  3. the binder inlines the offering's PUBLIC payload, tenant-scoped, and
 *     inlines null for every way the reference can be dead;
 *  4. nothing else in the palette moved.
 */
class OfferingSectionTypeTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjidA;

    private Masjid $masjidB;

    private User $adminA;

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

        $this->masjidA = $this->makeMasjid();
        $this->masjidB = $this->makeMasjid();
        $this->adminA = $this->makeAdminFor($this->masjidA);
        $this->makeAdminFor($this->masjidB);
    }

    /* ---------------- registration ---------------- */

    #[Test]
    public function the_offering_type_is_registered_in_the_enum(): void
    {
        $type = SectionType::tryFrom('offering');

        $this->assertInstanceOf(SectionType::class, $type);
        $this->assertContains('offering', SectionType::getValues());
        $this->assertNotSame('', $type->label());
        $this->assertNotSame('', $type->description());
    }

    #[Test]
    public function the_offering_type_is_not_flagged_as_external_data(): void
    {
        // FALSE, like `form`, and for the same reason: this flag means "the
        // renderer must call another endpoint of ours to draw this", and it must
        // not, because SectionContentBinder inlines the offering when the page
        // is served. One fetch, and no window in which the page has rendered and
        // the price has not.
        $this->assertFalse(SectionType::OFFERING->usesExternalData());

        $external = collect(SectionType::cases())
            ->filter(fn (SectionType $type) => $type->usesExternalData())
            ->map(fn (SectionType $type) => $type->value)
            ->values()
            ->all();

        $this->assertSame(['services_list', 'announcements_list', 'gallery', 'events'], $external);
    }

    #[Test]
    public function the_section_type_validation_rule_accepts_offering_and_still_rejects_junk(): void
    {
        // The enum IS the allowlist: every section request validates with
        // `new Enum(SectionType::class)`, so nothing else needed changing.
        $rules = ['section_type' => ['required', new Enum(SectionType::class)]];

        $this->assertTrue(Validator::make(['section_type' => 'offering'], $rules)->passes());
        $this->assertFalse(Validator::make(['section_type' => 'offerings'], $rules)->passes());
        $this->assertFalse(Validator::make(['section_type' => 'registration'], $rules)->passes());
    }

    #[Test]
    public function offering_default_content_has_the_documented_shape(): void
    {
        // A REFERENCE plus page-level wording. NO amount, NO currency, NO
        // capacity, NO window — every one of those lives on the offering and its
        // fee plans, and a copy here would be a second, staler answer.
        $this->assertSame([
            'offering_id' => null,
            'title' => '',
            'intro' => '',
            'show_fee_plans' => true,
            'button_text' => 'Register',
            'background_color' => '#ffffff',
        ], SectionType::OFFERING->defaultContent());

        foreach (['amount', 'amount_minor', 'price', 'currency', 'capacity', 'fee_plans'] as $forbidden) {
            $this->assertArrayNotHasKey(
                $forbidden,
                SectionType::OFFERING->defaultContent(),
                "{$forbidden} was copied into the section — money must not live in section JSON"
            );
        }
    }

    #[Test]
    public function the_type_is_offered_to_every_tenant_by_the_section_types_payload(): void
    {
        // The palette is GLOBAL: sectionTypes() maps SectionType::cases() with no
        // filter, so a masjid taking iftar RSVPs is offered this exactly as a
        // school taking tuition is. Gating on org_type is forbidden by
        // .claude/rules/section-types.md.
        Sanctum::actingAs($this->adminA);

        $types = $this->getJson("/api/admin/masjids/{$this->masjidA->id}/section-types")
            ->assertStatus(200)
            ->json('data');

        $offering = collect($types)->firstWhere('value', 'offering');

        $this->assertNotNull($offering, 'offering is not offered by the section-types payload');
        $this->assertSame(SectionType::OFFERING->defaultContent(), $offering['default_content']);
        $this->assertFalse($offering['uses_external_data']);
    }

    #[Test]
    public function the_type_maps_to_no_image_fields_in_either_upload_map(): void
    {
        // Both controllers own a copy of the map. This type carries no uploads at
        // all — its images, if it ever has any, belong to the offering.
        foreach ([SectionsController::class, PageSectionsController::class] as $controllerClass) {
            $method = new ReflectionMethod($controllerClass, 'getImageFieldsForSectionType');
            $method->setAccessible(true);

            $this->assertSame(
                [],
                $method->invoke(new $controllerClass(), SectionType::OFFERING),
                "{$controllerClass} maps image fields for a type that has none"
            );
        }
    }

    /* ---------------- the editorMap trap, mechanically ---------------- */

    #[Test]
    public function every_section_type_is_wired_into_the_spa_union_the_editor_map_and_a_real_editor_file(): void
    {
        // THE TRAP THIS EXISTS TO CLOSE. `editorMap` in SectionFormModal.vue is
        // typed `Record<SectionType, …>`, so a type added to the backend enum and
        // to PageSection.ts without an entry there IS a type error — but
        // `npm run build` is `vite build` with NO typecheck, and this repo has no
        // vue-tsc, so it builds happily and the admin gets the type in the
        // dropdown with a blank editor pane.
        //
        // That has shipped twice already (`events`, `form`), and
        // .claude/rules/section-types.md has said "add both in the same change"
        // the whole time. A rule a human has to remember is documentation, not a
        // control — the same argument .claude/rules/tenant-scoping.md makes about
        // the cross-tenant test it now enforces mechanically. This is the control.
        //
        // It is LEXICAL and it is a floor, not a ceiling: it proves the case is
        // named in each file and that the component file exists, not that the
        // editor is correct.
        $base = base_path('resources/vue-app');

        $union = $this->between(
            file_get_contents("{$base}/core/types/data/masjid-related/PageSection.ts"),
            'export type SectionType =',
            ';'
        );

        $modal = file_get_contents("{$base}/components/modals/SectionFormModal.vue");
        $editorMap = $this->between($modal, 'const editorMap: Record<SectionType, any> = {', "\n};");

        $this->assertNotSame('', $union, 'The SectionType union could not be located in PageSection.ts');
        $this->assertNotSame('', $editorMap, 'The editorMap could not be located in SectionFormModal.vue');

        foreach (SectionType::cases() as $type) {
            $this->assertStringContainsString(
                "'{$type->value}'",
                $union,
                "{$type->value} is missing from the SectionType union in PageSection.ts"
            );

            $this->assertMatchesRegularExpression(
                "/'" . preg_quote($type->value, '/') . "'\s*:/",
                $editorMap,
                "{$type->value} has no editorMap entry in SectionFormModal.vue — the dropdown offers it and the editor pane renders nothing"
            );
        }

        // Every component the map names must exist AND be imported, or the map
        // entry is a reference to undefined and the pane is blank anyway.
        preg_match_all("/'[a-z_]+'\s*:\s*(\w+)\s*,/", $editorMap, $matches);

        $this->assertNotEmpty($matches[1]);

        foreach (array_unique($matches[1]) as $component) {
            $this->assertMatchesRegularExpression(
                "/import\s+{$component}\s+from/",
                $modal,
                "{$component} is keyed in editorMap but never imported"
            );
            $this->assertFileExists("{$base}/components/sections/editors/{$component}.vue");
        }
    }

    /* ---------------- the admin builder round trip ---------------- */

    #[Test]
    public function an_offering_section_round_trips_through_the_page_builder(): void
    {
        Sanctum::actingAs($this->adminA);

        [$offering] = $this->makeOffering($this->masjidA, ['slug' => 'weekend-school']);
        $page = $this->makePage($this->masjidA, 'register');

        $content = [
            'offering_id' => $offering->id,
            'title' => 'Register for the autumn semester',
            'intro' => 'Places are limited and go quickly.',
            'show_fee_plans' => true,
            'button_text' => 'Reserve a place',
            'background_color' => '#f7f7f5',
        ];

        // CREATE through the real builder endpoint, posted the way
        // SectionFormModal actually posts: `content` as a JSON STRING inside a
        // form body, which StorePageSectionRequest::prepareForValidation
        // decodes. Sending it as a nested JSON array instead would exercise a
        // path the SPA never takes — and one where Laravel's
        // ConvertEmptyStringsToNull walks into the nested content and turns
        // every '' into null.
        $created = $this->post(
            "/api/admin/masjids/{$this->masjidA->id}/pages/{$page->id}/sections",
            [
                'section_type' => 'offering',
                'title' => 'Registration',
                'content' => json_encode($content),
                'order' => 1,
                'is_active' => 1,
            ]
        )->assertStatus(201)->json('data');

        $this->assertSame('offering', $created['section_type']);
        $this->assertSame($content, $created['content']);

        // READ IT BACK. The admin surface does NOT run the content binder, so
        // what an admin edits is what they stored — the resolved offering is a
        // public-serve concern only.
        $read = $this->getJson(
            "/api/admin/masjids/{$this->masjidA->id}/pages/{$page->id}/sections/{$created['id']}"
        )->assertStatus(200)->json('data');

        $this->assertSame($content, $read['content']);
        $this->assertArrayNotHasKey('offering', $read['content']);

        // UPDATE — repointing at another offering and turning the price table
        // off is the whole edit surface this section has.
        [$other] = $this->makeOffering($this->masjidA, ['slug' => 'summer-camp']);

        $updated = $this->post(
            "/api/admin/masjids/{$this->masjidA->id}/pages/{$page->id}/sections/{$created['id']}",
            [
                '_method' => 'PUT',
                'section_type' => 'offering',
                'content' => json_encode(array_merge($content, [
                    'offering_id' => $other->id,
                    'show_fee_plans' => false,
                ])),
            ]
        )->assertStatus(200)->json('data');

        $this->assertSame($other->id, $updated['content']['offering_id']);
        $this->assertFalse($updated['content']['show_fee_plans']);
    }

    #[Test]
    public function the_default_content_is_storable_as_authored_with_nothing_selected(): void
    {
        // The state an admin is in for the first few seconds after picking the
        // type from the dropdown. It must persist and read back rather than
        // failing validation or collapsing a null into something else.
        Sanctum::actingAs($this->adminA);

        $page = $this->makePage($this->masjidA, 'blank');

        $created = $this->post(
            "/api/admin/masjids/{$this->masjidA->id}/pages/{$page->id}/sections",
            [
                'section_type' => 'offering',
                'content' => json_encode(SectionType::OFFERING->defaultContent()),
                'order' => 1,
            ]
        )->assertStatus(201)->json('data');

        // Byte for byte, empty strings included. The empty-string defaults have
        // to survive: the editor's normalize() re-defaults them anyway, but a
        // null arriving where a string was authored is how a `v-model` on a
        // text input starts printing "null" into a published page.
        $this->assertSame(SectionType::OFFERING->defaultContent(), $created['content']);
        $this->assertNull($created['content']['offering_id']);
    }

    /* ---------------- what the site is served ---------------- */

    #[Test]
    public function a_published_section_inlines_the_offerings_public_payload(): void
    {
        [$offering, $plan] = $this->makeOffering($this->masjidA, [
            'slug' => 'weekend-school',
            'name' => 'Weekend School 2026-2027',
            'description' => 'Saturdays, 10am to 1pm.',
            'capacity' => 20,
            'registration_count' => 3,
        ]);

        $page = $this->publish($this->masjidA, 'register', $offering->id);

        $content = $this->serializedContent($this->masjidA, $page);

        // The wording the admin authored is preserved untouched...
        $this->assertSame('Register now', $content['title']);
        $this->assertSame($offering->id, $content['offering_id']);

        // ...and the offering is resolved beside it, so the site fetches the
        // page ONCE and can draw the whole registration block.
        $this->assertNotNull($content['offering']);
        $this->assertSame('weekend-school', $content['offering']['slug']);
        $this->assertSame('Weekend School 2026-2027', $content['offering']['name']);
        $this->assertSame('Saturdays, 10am to 1pm.', $content['offering']['description']);
        $this->assertSame('open', $content['offering']['registration_state']);
        $this->assertSame(17, $content['offering']['seats']['remaining']);
        $this->assertSame([$plan->id], array_column($content['offering']['fee_plans'], 'id'));
        $this->assertSame(15000, $content['offering']['fee_plans'][0]['amount_minor']);
        $this->assertSame('usd', $content['offering']['fee_plans'][0]['currency']);
    }

    #[Test]
    public function the_inlined_payload_is_the_same_one_the_public_endpoint_serves(): void
    {
        // ONE presenter, two consumers. Two hand-written copies of "what is safe
        // to publish about an offering" is how a private field reaches a public
        // page: the one that gets reviewed is tightened and the one nobody
        // remembered is not.
        [$offering] = $this->makeOffering($this->masjidA, ['slug' => 'same-shape', 'capacity' => 8]);

        $page = $this->publish($this->masjidA, 'signup', $offering->id);

        $inlined = $this->serializedContent($this->masjidA, $page)['offering'];

        $direct = $this->getJson('/api/v1/offerings/same-shape', ['masjid-id' => (string) $this->masjidA->id])
            ->assertStatus(200)
            ->json('data');

        $this->assertSame($direct, $inlined);
    }

    #[Test]
    public function a_section_can_never_inline_another_tenants_offering(): void
    {
        // The failure this prevents is not a 500 — it is masjid B's programme,
        // and B's prices, rendering on A's website.
        [$offeringB] = $this->makeOffering($this->masjidB, ['slug' => 'b-program', 'name' => 'B Only']);

        $page = $this->publish($this->masjidA, 'cross-tenant', $offeringB->id);

        $content = $this->serializedContent($this->masjidA, $page);

        $this->assertNull($content['offering']);

        $body = $this->withHeader('masjid-id', (string) $this->masjidA->id)
            ->getJson("/api/v1/pages/{$page->slug}")
            ->getContent();

        $this->assertStringNotContainsString('B Only', $body);
        $this->assertStringNotContainsString('b-program', $body);
    }

    #[Test]
    public function every_way_the_reference_can_be_dead_inlines_null(): void
    {
        // The renderer treats all of these the same way — draw nothing — rather
        // than an empty shell with a Register button that every submission would
        // be refused by.

        // (a) nothing selected
        $page = $this->publish($this->masjidA, 'unset-ref', null);
        $this->assertNull($this->serializedContent($this->masjidA, $page)['offering']);

        // (b) points at an id that does not exist
        $page = $this->publish($this->masjidA, 'missing-ref', 99999);
        $this->assertNull($this->serializedContent($this->masjidA, $page)['offering']);

        // (c) soft-deleted
        [$deleted] = $this->makeOffering($this->masjidA, ['slug' => 'deleted-ref']);
        $page = $this->publish($this->masjidA, 'deleted-ref-page', $deleted->id);
        $deleted->delete();
        $this->assertNull($this->serializedContent($this->masjidA, $page)['offering']);

        // (d) switched off — the unpublish switch, and the public read agrees
        //     with the public write about what it means.
        [$off] = $this->makeOffering($this->masjidA, ['slug' => 'off-ref', 'is_active' => false]);
        $page = $this->publish($this->masjidA, 'off-ref-page', $off->id);
        $this->assertNull($this->serializedContent($this->masjidA, $page)['offering']);
    }

    #[Test]
    public function a_published_section_pointing_at_a_closed_offering_says_closed(): void
    {
        [$offering] = $this->makeOffering($this->masjidA, [
            'slug' => 'ended',
            'closes_at' => now()->subWeek(),
        ]);

        $page = $this->publish($this->masjidA, 'ended-page', $offering->id);

        $offeringPayload = $this->serializedContent($this->masjidA, $page)['offering'];

        // is_active is still true, which is exactly the trap Offering::is_open
        // exists for. A published section must not draw a live Register button
        // over a window that shut a week ago.
        $this->assertFalse($offeringPayload['is_open']);
        $this->assertSame('closed', $offeringPayload['closed_reason']);
        $this->assertSame('closed', $offeringPayload['registration_state']);
    }

    #[Test]
    public function the_published_section_leaks_no_registrant_and_no_seat_counter(): void
    {
        [$offering] = $this->makeOffering($this->masjidA, [
            'slug' => 'private-roster',
            'capacity' => 30,
            'registration_count' => 7,
        ]);

        $page = $this->publish($this->masjidA, 'roster-page', $offering->id);

        $payload = $this->serializedContent($this->masjidA, $page)['offering'];

        // Publishing capacity AND remaining would hand out the subtraction,
        // which is the seat counter — a count of PEOPLE — by another name.
        $this->assertArrayNotHasKey('capacity', $payload);
        $this->assertArrayNotHasKey('registration_count', $payload);
        $this->assertArrayNotHasKey('id', $payload);
        $this->assertSame(23, $payload['seats']['remaining']);
    }

    #[Test]
    public function the_binder_leaves_every_other_section_type_alone(): void
    {
        // Adding an arm to SectionContentBinder must not disturb the types that
        // fall through its `default`.
        $page = Page::create([
            'masjid_id' => $this->masjidA->id,
            'slug' => 'untouched',
            'title' => 'Untouched',
            'is_active' => true,
            'order' => 1,
        ]);

        $content = [
            'heading' => 'Our Impact',
            'description' => '',
            'period' => 'In 2025',
            'stats' => [['value' => '6,000+', 'label' => 'visits', 'description' => '']],
            'layout' => 'row',
            'columns' => 3,
            'background_color' => '#ffffff',
        ];

        $section = Section::create([
            'masjid_id' => $this->masjidA->id,
            'section_type' => 'impact_stats',
            'content' => $content,
            'is_active' => true,
        ]);
        $page->sections()->attach($section->id, ['order' => 1, 'platforms' => null]);

        $serialized = $this->serializedContent($this->masjidA, $page);

        $this->assertSame($content, $serialized);
        $this->assertArrayNotHasKey('offering', $serialized);
    }

    /* ---------------- purely additive ---------------- */

    #[Test]
    public function the_palette_gained_exactly_one_type(): void
    {
        // Every value pinned by SchoolSectionTypesTest and
        // CommunitySectionTypesTest is still pinned there; this asserts the
        // arithmetic of the change itself. A section_type is CAST to this enum,
        // so a value that disappears is not "extra data", it is a published row
        // that throws on every read.
        $this->assertCount(27, SectionType::getValues());
        $this->assertContains('offering', SectionType::getValues());
    }

    #[Test]
    public function the_form_type_is_untouched_by_the_new_one(): void
    {
        // `form` and `offering` are separate types on purpose and their content
        // shapes must not converge: a form submission writes a FormResponse and
        // takes no seat and moves no money, while an offering registration
        // reserves a place and opens a Stripe Checkout Session. An admin who
        // reached for the wrong one would think sign-ups were being collected.
        $this->assertSame(
            ['form_id' => null, 'title' => '', 'intro' => ''],
            SectionType::FORM->defaultContent()
        );
        $this->assertNotSame(
            SectionType::FORM->defaultContent(),
            SectionType::OFFERING->defaultContent()
        );
    }

    /* ============================= helpers ============================= */

    /** The slice of $haystack between the first $start and the next $end after it. */
    private function between(string $haystack, string $start, string $end): string
    {
        $from = strpos($haystack, $start);

        if ($from === false) {
            return '';
        }

        $from += strlen($start);
        $to = strpos($haystack, $end, $from);

        return $to === false ? '' : substr($haystack, $from, $to - $from);
    }

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
            // Stripe onboarding complete — this test is about the SECTION
            // inlining the public payload, not about clause 5 (organisation
            // cannot collect), which would otherwise close the paid offering.
            'stripe_account_id' => 'acct_TEST'.uniqid(),
            'stripe_charges_enabled' => true,
            'stripe_payouts_enabled' => true,
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

    /** @return array{0: Offering, 1: FeePlan} */
    private function makeOffering(Masjid $masjid, array $state = []): array
    {
        // Seeded UNBOUND so the explicit masjid_id is honoured rather than
        // overridden by the BelongsToMasjid creating hook.
        app(TenantContext::class)->forgetTenant();

        $offering = Offering::factory()->forMasjid($masjid)->create($state);

        $plan = FeePlan::factory()->create([
            'masjid_id' => $masjid->id,
            'offering_id' => $offering->id,
        ]);

        return [$offering, $plan];
    }

    private function makePage(Masjid $masjid, string $slug): Page
    {
        return Page::create([
            'masjid_id' => $masjid->id,
            'slug' => $slug,
            'title' => ucfirst($slug),
            'is_active' => true,
            'order' => 1,
        ]);
    }

    /** A page carrying one `offering` section pointed at $offeringId. */
    private function publish(Masjid $masjid, string $slug, ?int $offeringId): Page
    {
        $page = $this->makePage($masjid, $slug);

        $section = Section::create([
            'masjid_id' => $masjid->id,
            'section_type' => 'offering',
            'title' => 'Registration',
            'content' => array_merge(SectionType::OFFERING->defaultContent(), [
                'offering_id' => $offeringId,
                'title' => 'Register now',
            ]),
            'is_active' => true,
        ]);

        $page->sections()->attach($section->id, ['order' => 1, 'platforms' => null]);

        return $page;
    }

    /** @return array<string,mixed> the serialized `content` of the page's first section */
    private function serializedContent(Masjid $masjid, Page $page): array
    {
        return $this->withHeader('masjid-id', (string) $masjid->id)
            ->getJson("/api/v1/pages/{$page->slug}")
            ->assertStatus(200)
            ->json('data.sections.0.content');
    }
}
