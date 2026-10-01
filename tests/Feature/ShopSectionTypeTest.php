<?php

namespace Tests\Feature;

use App\Enums\SectionType;
use App\Http\Controllers\AdminDashboard\PageSectionsController;
use App\Http\Controllers\AdminDashboard\SectionsController;
use App\Models\Masjid;
use App\Models\Page;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The `shop` section type: products from the organisation's online shop, drawn by the
 * renderer from the public shop API (the section stores none), offered only to an
 * organisation that has the `shop` grant.
 *
 * Three things are pinned here, because each breaks silently:
 *
 *  1. The content SHAPE and its bounds, on all four writers (page-section store and
 *     update, library store and update). The renderer reads these four keys as stored.
 *  2. The GRANT. Creating a shop section, or changing a section to one, for an
 *     organisation without the `shop` grant is a 422; the palette does not offer it; a
 *     SuperAdmin is judged by the organisation, not by who they are; and a shop section
 *     that already exists stays readable, editable and deletable when the grant is
 *     switched off, and still listed by the public page payload.
 *  3. Tenancy: another organisation's section id answers as a missing id does.
 *
 * The SPA wiring (union, editorMap, editor file) is enforced for every case by
 * OfferingSectionTypeTest::every_section_type_is_wired_into_the_spa_union_the_editor_map_and_a_real_editor_file.
 */
class ShopSectionTypeTest extends TestCase
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

        $this->masjid = $this->makeMasjid(['shop' => true]);
        $this->admin = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
        $this->masjid->user_id = $this->admin->id;
        $this->masjid->save();
    }

    /* ---------------------------------------------------------------- the type */

    #[Test]
    public function the_shop_default_content_is_the_four_keys_the_renderer_reads(): void
    {
        $this->assertSame('shop', SectionType::SHOP->value);
        $this->assertSame([
            'heading' => null,
            'category' => null,
            'max_items' => 8,
            'show_view_all' => true,
        ], SectionType::SHOP->defaultContent());
    }

    #[Test]
    public function the_shop_type_is_classified(): void
    {
        $this->assertSame('Shop', SectionType::SHOP->label());
        $this->assertSame('Products from this organisation\'s online shop', SectionType::SHOP->description());
        // The renderer fetches the products from the public shop API; the section stores none.
        $this->assertTrue(SectionType::SHOP->usesExternalData());
        // No module governs it: a GRANT does.
        $this->assertNull(SectionType::SHOP->requiresModule());
        $this->assertNull(SectionType::SHOP->moduleOffNote());
        $this->assertSame('shop', SectionType::SHOP->requiresGrant());
        // MEC's renderer draws it; the one list's sentence is about an unrendered sign-up form.
        $this->assertNotContains(SectionType::SHOP, SectionType::withoutRenderer());
        $this->assertTrue(SectionType::SHOP->hasRenderer());
        $this->assertNull(SectionType::SHOP->rendererNote());
    }

    #[Test]
    public function the_grant_it_names_is_a_real_catalogue_grant(): void
    {
        $definition = config('capabilities.' . SectionType::SHOP->requiresGrant());

        $this->assertIsArray($definition);
        $this->assertSame('grant', $definition['kind']);
        $this->assertSame('Online shop', $definition['label']);
    }

    #[Test]
    public function every_other_type_is_offered_to_every_organisation(): void
    {
        foreach (SectionType::cases() as $type) {
            if ($type === SectionType::SHOP) {
                continue;
            }

            $this->assertNull($type->requiresGrant(), "{$type->value} became grant-gated");
        }
    }

    #[Test]
    public function both_upload_maps_name_the_shop_with_no_image_fields(): void
    {
        foreach ([SectionsController::class, PageSectionsController::class] as $controllerClass) {
            $method = new ReflectionMethod($controllerClass, 'getImageFieldsForSectionType');
            $method->setAccessible(true);

            $this->assertSame(
                [],
                $method->invoke(new $controllerClass(), SectionType::SHOP),
                "{$controllerClass} maps image fields for a type that has none"
            );
        }
    }

    /* ----------------------------------------------------------- the round trip */

    #[Test]
    public function a_shop_section_survives_store_update_and_read_through_the_page_builder(): void
    {
        Sanctum::actingAs($this->admin);
        $page = $this->makePage('home');

        $content = [
            'heading' => 'Uniform shop',
            'category' => 'Uniforms',
            'max_items' => 12,
            'show_view_all' => false,
        ];

        $created = $this->postJson($this->pageSections($page), [
            'section_type' => 'shop',
            'title' => 'Shop',
            'content' => $content,
            'order' => 1,
        ])->assertStatus(201)->json('data');

        $this->assertSame('shop', $created['section_type']);
        $this->assertSame($content, $created['content']);
        $this->assertTrue($created['uses_external_data']);

        // An update of the same type merges onto what is stored.
        $updated = $this->putJson("{$this->pageSections($page)}/{$created['id']}", [
            'content' => ['max_items' => 4],
        ])->assertStatus(200)->json('data');

        $this->assertSame(array_merge($content, ['max_items' => 4]), $updated['content']);

        $read = $this->getJson("{$this->pageSections($page)}/{$created['id']}")->assertStatus(200)->json('data');
        $this->assertSame(array_merge($content, ['max_items' => 4]), $read['content']);

        $listed = $this->getJson($this->pageSections($page))->assertStatus(200)->json('data');
        $this->assertSame(['shop'], array_column($listed, 'section_type'));
    }

    #[Test]
    public function a_shop_section_survives_store_update_and_read_through_the_library(): void
    {
        Sanctum::actingAs($this->admin);

        $content = [
            'heading' => null,
            'category' => null,
            'max_items' => 24,
            'show_view_all' => true,
        ];

        $created = $this->postJson($this->library(), [
            'section_type' => 'shop',
            'content' => $content,
        ])->assertStatus(201)->json('data');

        $this->assertSame('shop', $created['section_type']);
        $this->assertSame($content, $created['content']);

        // The library's update replaces the content whole.
        $replacement = [
            'heading' => 'Books',
            'category' => 'Books',
            'max_items' => 1,
            'show_view_all' => false,
        ];
        $updated = $this->putJson("{$this->library()}/{$created['id']}", [
            'section_type' => 'shop',
            'content' => $replacement,
        ])->assertStatus(200)->json('data');

        $this->assertSame($replacement, $updated['content']);
        $this->assertSame($replacement, $this->getJson("{$this->library()}/{$created['id']}")->assertStatus(200)->json('data.content'));
    }

    #[Test]
    public function the_default_content_is_accepted_and_the_palette_serves_it(): void
    {
        Sanctum::actingAs($this->admin);
        $page = $this->makePage('home');

        $this->postJson($this->pageSections($page), [
            'section_type' => 'shop',
            'content' => SectionType::SHOP->defaultContent(),
        ])->assertStatus(201);

        $offered = collect($this->types($this->masjid))->firstWhere('value', 'shop');

        $this->assertNotNull($offered);
        $this->assertSame(SectionType::SHOP->defaultContent(), $offered['default_content']);
        $this->assertSame('Shop', $offered['label']);
        $this->assertTrue($offered['uses_external_data']);
        $this->assertTrue($offered['has_renderer']);
        $this->assertNull($offered['module_off_note']);
    }

    #[Test]
    public function the_public_page_payload_returns_the_section_as_every_other_type_is_returned(): void
    {
        $page = $this->makePage('shop');
        $content = [
            'heading' => 'Uniform shop',
            'category' => 'Uniforms',
            'max_items' => 6,
            'show_view_all' => true,
        ];
        $this->makeSection($this->masjid, 'shop', $content, $page);

        $section = $this->withHeader('masjid-id', (string) $this->masjid->id)
            ->getJson("/api/v1/pages/{$page->slug}")
            ->assertStatus(200)
            ->json('data.sections.0');

        $this->assertSame('shop', $section['section_type']);
        $this->assertSame('Shop', $section['section_type_label']);
        // Nothing is bound server-side: the stored content comes back as it is, and no
        // product, price or size is inlined (the renderer fetches those).
        $this->assertSame($content, $section['content']);
        $this->assertTrue($section['uses_external_data']);
    }

    /* --------------------------------------------------------- content bounds */

    #[Test]
    public function every_writer_refuses_a_value_outside_the_content_bounds(): void
    {
        Sanctum::actingAs($this->admin);

        $refused = [
            'max_items 0' => ['max_items' => 0],
            'max_items 25' => ['max_items' => 25],
            'max_items negative' => ['max_items' => -1],
            'max_items text' => ['max_items' => '8'],
            'max_items fraction' => ['max_items' => 8.5],
            'max_items null' => ['max_items' => null],
            'heading 121 characters' => ['heading' => str_repeat('a', 121)],
            'heading a number' => ['heading' => 12],
            'heading a list' => ['heading' => ['x']],
            'category 61 characters' => ['category' => str_repeat('c', 61)],
            'category a number' => ['category' => 7],
            'show_view_all text' => ['show_view_all' => 'yes'],
            'show_view_all a number' => ['show_view_all' => 1],
            'show_view_all null' => ['show_view_all' => null],
            'an unknown key' => ['price' => '9.99'],
        ];

        foreach ($this->writers() as $writer => $write) {
            foreach ($refused as $what => $content) {
                $response = $write(array_merge(SectionType::SHOP->defaultContent(), $content));

                $this->assertSame(422, $response->status(), "{$writer}: {$what} was accepted: " . $response->getContent());
                $this->assertArrayHasKey('content', (array) $response->json('data'), "{$writer}: {$what} was not refused on `content`");
            }
        }
    }

    #[Test]
    public function every_writer_accepts_the_edges_of_the_content_bounds(): void
    {
        Sanctum::actingAs($this->admin);

        $accepted = [
            'max_items 1' => ['max_items' => 1],
            'max_items 24' => ['max_items' => 24],
            'heading 120 characters' => ['heading' => str_repeat('a', 120)],
            // Characters, not bytes: 120 Arabic letters are 240 bytes.
            'heading 120 Arabic letters' => ['heading' => str_repeat('م', 120)],
            'category 60 characters' => ['category' => str_repeat('c', 60)],
            'no heading' => ['heading' => null],
            'no category' => ['category' => null],
            'link off' => ['show_view_all' => false],
            'link on' => ['show_view_all' => true],
        ];

        foreach ($this->writers() as $writer => $write) {
            foreach ($accepted as $what => $content) {
                $response = $write(array_merge(SectionType::SHOP->defaultContent(), $content));

                $this->assertContains($response->status(), [200, 201], "{$writer}: {$what} was refused: " . $response->getContent());
            }
        }
    }

    #[Test]
    public function a_key_may_be_left_out_and_the_stored_value_is_what_was_sent(): void
    {
        Sanctum::actingAs($this->admin);
        $page = $this->makePage('home');

        $created = $this->postJson($this->pageSections($page), [
            'section_type' => 'shop',
            'content' => ['heading' => 'Just a heading'],
        ])->assertStatus(201)->json('data');

        // Nothing is filled in on the way in; the renderer reads an absent key as its default.
        $this->assertSame(['heading' => 'Just a heading'], $created['content']);
    }

    #[Test]
    public function the_content_rule_is_for_this_type_only(): void
    {
        Sanctum::actingAs($this->admin);
        $page = $this->makePage('home');

        // The same keys on another type are none of this rule's business.
        $this->postJson($this->pageSections($page), [
            'section_type' => 'text',
            'content' => ['max_items' => 99, 'show_view_all' => 'yes', 'price' => '9.99'],
        ])->assertStatus(201);

        // An update that leaves out section_type is judged by the stored type, so junk is
        // refused on a stored shop and tolerated on a stored text.
        $shop = $this->makeSection($this->masjid, 'shop', SectionType::SHOP->defaultContent(), $page);
        $text = $this->makeSection($this->masjid, 'text', ['text' => 'Hello'], $page);

        $this->putJson("{$this->pageSections($page)}/{$shop->id}", ['content' => ['max_items' => 99]])->assertStatus(422);
        $this->putJson("{$this->pageSections($page)}/{$text->id}", ['content' => ['max_items' => 99]])->assertStatus(200);
    }

    /* ------------------------------------------------------------- the grant */

    #[Test]
    public function without_the_grant_a_shop_section_cannot_be_created_by_any_writer(): void
    {
        $this->setShopGrant($this->masjid, false);
        Sanctum::actingAs($this->admin);
        $page = $this->makePage('home');

        $stores = [
            'page builder' => fn () => $this->postJson($this->pageSections($page), [
                'section_type' => 'shop',
                'content' => SectionType::SHOP->defaultContent(),
            ]),
            'library' => fn () => $this->postJson($this->library(), [
                'section_type' => 'shop',
                'content' => SectionType::SHOP->defaultContent(),
            ]),
        ];

        foreach ($stores as $route => $store) {
            $response = $store()->assertStatus(422)->assertJsonStructure(['data' => ['section_type']]);

            $this->assertSame(
                'Online shop is not switched on for this organisation, so it cannot have a Shop section.',
                $response->json('data.section_type.0'),
                $route
            );
        }

        $this->assertSame(0, Section::where('section_type', 'shop')->count(), 'a refused store left a section behind');
    }

    #[Test]
    public function without_the_grant_no_section_can_be_changed_to_a_shop(): void
    {
        $this->setShopGrant($this->masjid, false);
        Sanctum::actingAs($this->admin);
        $page = $this->makePage('home');
        $text = $this->makeSection($this->masjid, 'text', ['text' => 'Hello'], $page);

        $changes = [
            'page builder' => fn () => $this->putJson("{$this->pageSections($page)}/{$text->id}", [
                'section_type' => 'shop',
                'content' => SectionType::SHOP->defaultContent(),
            ]),
            'library' => fn () => $this->putJson("{$this->library()}/{$text->id}", [
                'section_type' => 'shop',
                'content' => SectionType::SHOP->defaultContent(),
            ]),
        ];

        foreach ($changes as $route => $change) {
            $change()->assertStatus(422)->assertJsonStructure(['data' => ['section_type']]);

            $this->assertSame(SectionType::TEXT, $text->fresh()->section_type, "{$route}: the section was changed anyway");
        }
    }

    #[Test]
    public function a_shop_section_cannot_be_changed_away_and_back_while_the_grant_is_off(): void
    {
        Sanctum::actingAs($this->admin);
        $page = $this->makePage('home');
        $shop = $this->makeSection($this->masjid, 'shop', SectionType::SHOP->defaultContent(), $page);
        $this->setShopGrant($this->masjid, false);

        // Away from it is fine: nothing is gated on leaving.
        $this->putJson("{$this->pageSections($page)}/{$shop->id}", [
            'section_type' => 'text',
            'content' => ['text' => 'Now text'],
        ])->assertStatus(200);

        // Back to it is a change TO a shop, and the organisation has no shop.
        $this->putJson("{$this->pageSections($page)}/{$shop->id}", [
            'section_type' => 'shop',
            'content' => SectionType::SHOP->defaultContent(),
        ])->assertStatus(422)->assertJsonStructure(['data' => ['section_type']]);

        $this->assertSame(SectionType::TEXT, $shop->fresh()->section_type);
    }

    #[Test]
    public function with_the_grant_a_shop_section_is_created_and_a_section_is_changed_to_one(): void
    {
        Sanctum::actingAs($this->admin);
        $page = $this->makePage('home');

        $this->postJson($this->pageSections($page), [
            'section_type' => 'shop',
            'content' => SectionType::SHOP->defaultContent(),
        ])->assertStatus(201);
        $this->postJson($this->library(), [
            'section_type' => 'shop',
            'content' => SectionType::SHOP->defaultContent(),
        ])->assertStatus(201);

        $viaPage = $this->makeSection($this->masjid, 'text', ['text' => 'Hello'], $page);
        $viaLibrary = $this->makeSection($this->masjid, 'text', ['text' => 'Hello']);

        $this->putJson("{$this->pageSections($page)}/{$viaPage->id}", [
            'section_type' => 'shop',
            'content' => SectionType::SHOP->defaultContent(),
        ])->assertStatus(200);
        $this->putJson("{$this->library()}/{$viaLibrary->id}", [
            'section_type' => 'shop',
            'content' => SectionType::SHOP->defaultContent(),
        ])->assertStatus(200);

        $this->assertSame(SectionType::SHOP, $viaPage->fresh()->section_type);
        $this->assertSame(SectionType::SHOP, $viaLibrary->fresh()->section_type);
    }

    #[Test]
    public function an_existing_shop_section_survives_the_grant_being_switched_off(): void
    {
        Sanctum::actingAs($this->admin);
        $page = $this->makePage('home');

        $content = [
            'heading' => 'Uniform shop',
            'category' => 'Uniforms',
            'max_items' => 6,
            'show_view_all' => true,
        ];
        $onPage = $this->postJson($this->pageSections($page), [
            'section_type' => 'shop',
            'content' => $content,
            'order' => 1,
        ])->assertStatus(201)->json('data');
        $inLibrary = $this->postJson($this->library(), [
            'section_type' => 'shop',
            'content' => $content,
        ])->assertStatus(201)->json('data');

        $this->setShopGrant($this->masjid, false);

        // Readable, on both surfaces.
        $this->assertSame($content, $this->getJson("{$this->pageSections($page)}/{$onPage['id']}")->assertStatus(200)->json('data.content'));
        $this->assertSame(
            [$onPage['id']],
            array_column($this->getJson($this->pageSections($page))->assertStatus(200)->json('data'), 'id')
        );
        $this->assertSame($content, $this->getJson("{$this->library()}/{$inLibrary['id']}")->assertStatus(200)->json('data.content'));

        // The public page payload still lists it. The renderer draws nothing, because the
        // public shop API answers the dark 404; the section is not what hides.
        $public = $this->withHeader('masjid-id', (string) $this->masjid->id)
            ->getJson("/api/v1/pages/{$page->slug}")
            ->assertStatus(200)
            ->json('data.sections');
        $this->assertSame(['shop'], array_column($public, 'section_type'));
        $this->assertSame($content, $public[0]['content']);

        // Not offered for a new one.
        $this->assertNotContains('shop', array_column($this->types($this->masjid), 'value'));

        // Still editable as the same type (it is neither a creation nor a change to it).
        $this->putJson("{$this->pageSections($page)}/{$onPage['id']}", ['content' => ['max_items' => 3]])->assertStatus(200);
        $this->putJson("{$this->library()}/{$inLibrary['id']}", [
            'section_type' => 'shop',
            'content' => array_merge($content, ['max_items' => 2]),
        ])->assertStatus(200);

        // Deletable, on both surfaces.
        $this->deleteJson("{$this->pageSections($page)}/{$onPage['id']}")->assertStatus(200);
        $this->assertSame(0, $page->sections()->count());
        $this->deleteJson("{$this->library()}/{$inLibrary['id']}")->assertStatus(200);
        $this->assertNull(Section::find($inLibrary['id']));
    }

    #[Test]
    public function the_grant_is_the_organisations_never_the_viewers(): void
    {
        $without = $this->makeMasjid([]);
        $page = Page::create([
            'masjid_id' => $without->id,
            'slug' => 'home',
            'title' => 'Home',
            'is_active' => true,
            'order' => 1,
        ]);

        // A SuperAdmin builds pages for any organisation, but is offered, and may create,
        // exactly what that organisation's own admin would: the public shop API answers the
        // dark 404 for it whoever built the section.
        Sanctum::actingAs(User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+15550000001'])->fresh());

        $this->assertNotContains('shop', array_column($this->types($without), 'value'));
        $this->postJson("/api/admin/masjids/{$without->id}/pages/{$page->id}/sections", [
            'section_type' => 'shop',
            'content' => SectionType::SHOP->defaultContent(),
        ])->assertStatus(422)->assertJsonStructure(['data' => ['section_type']]);

        // And the same SuperAdmin, for an organisation that has it.
        $this->assertContains('shop', array_column($this->types($this->masjid), 'value'));
        $withPage = $this->makePage('home');
        $this->postJson($this->pageSections($withPage), [
            'section_type' => 'shop',
            'content' => SectionType::SHOP->defaultContent(),
        ])->assertStatus(201);
    }

    /* ------------------------------------------------------------ the palette */

    #[Test]
    public function the_palette_offers_the_shop_only_to_an_organisation_with_the_grant(): void
    {
        Sanctum::actingAs(User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+15550000002'])->fresh());

        $everyOtherType = collect(SectionType::cases())
            ->reject(fn (SectionType $type) => $type === SectionType::SHOP)
            ->map(fn (SectionType $type) => $type->value)
            ->values()
            ->all();

        // Off, whether the grant was never given (no override at all, the catalogue default)
        // or was given and switched off.
        $neverGiven = $this->makeMasjid([]);
        $switchedOff = $this->makeMasjid(['shop' => false]);

        foreach ([$neverGiven, $switchedOff] as $org) {
            $payload = $this->getJson("/api/admin/masjids/{$org->id}/section-types")->assertStatus(200)->json('data');

            // Every other type, in the enum's order, and a JSON LIST (not an object keyed by
            // the gap the shop leaves).
            $this->assertTrue(array_is_list($payload));
            $this->assertSame($everyOtherType, array_column($payload, 'value'));
        }

        // On, for an organisation of every type: the grant is not about the org type.
        foreach (['masjid', 'school', 'community'] as $orgType) {
            $org = $this->makeMasjid(['shop' => true], $orgType);
            $payload = $this->getJson("/api/admin/masjids/{$org->id}/section-types")->assertStatus(200)->json('data');

            $this->assertTrue(array_is_list($payload));
            $this->assertSame(
                array_map(fn (SectionType $type) => $type->value, SectionType::cases()),
                array_column($payload, 'value'),
                "{$orgType}: the palette with the grant is every type, in order"
            );
        }
    }

    #[Test]
    public function the_other_types_are_served_exactly_as_before(): void
    {
        $without = $this->makeMasjid([]);
        Sanctum::actingAs(User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+15550000003'])->fresh());

        $payload = collect($this->getJson("/api/admin/masjids/{$without->id}/section-types")->assertStatus(200)->json('data'))
            ->keyBy('value');

        foreach (SectionType::cases() as $type) {
            if ($type === SectionType::SHOP) {
                continue;
            }

            $this->assertSame($type->label(), $payload[$type->value]['label']);
            $this->assertSame($type->description(), $payload[$type->value]['description']);
            $this->assertSame($type->usesExternalData(), $payload[$type->value]['uses_external_data']);
            $this->assertEquals($type->defaultContent(), $payload[$type->value]['default_content']);
        }
    }

    /* ---------------------------------------------------------------- tenancy */

    #[Test]
    public function another_organisations_section_id_answers_as_a_missing_id_does(): void
    {
        Sanctum::actingAs($this->admin);
        $page = $this->makePage('home');
        $other = $this->makeMasjid(['shop' => true]);

        $foreign = [];
        foreach (['shop' => SectionType::SHOP->defaultContent(), 'text' => ['text' => 'Theirs']] as $type => $content) {
            $foreign[$type] = $this->makeSection($other, $type, array_merge($content, $type === 'shop' ? ['heading' => 'THEIR SHOP'] : []));
        }
        $missing = max($foreign['shop']->id, $foreign['text']->id) + 1000;

        $routes = [
            'page builder' => fn (int $id) => "{$this->pageSections($page)}/{$id}",
            'library' => fn (int $id) => "{$this->library()}/{$id}",
        ];

        // Once with the grant and once without: the answer must not depend on what the
        // other organisation's section is, or it says what that section is.
        foreach ([true, false] as $hasGrant) {
            $this->setShopGrant($this->masjid, $hasGrant);

            foreach ($routes as $route => $url) {
                $answers = [];
                foreach (['their shop' => $foreign['shop']->id, 'their text' => $foreign['text']->id, 'no such id' => $missing] as $what => $id) {
                    $response = $this->putJson($url($id), [
                        'section_type' => 'shop',
                        'content' => SectionType::SHOP->defaultContent(),
                    ]);
                    $answers[$what] = [$response->status(), array_keys((array) $response->json('data'))];
                }

                $this->assertSame($answers['no such id'], $answers['their shop'], "{$route}, grant " . var_export($hasGrant, true) . ': their shop answers unlike a missing id');
                $this->assertSame($answers['no such id'], $answers['their text'], "{$route}, grant " . var_export($hasGrant, true) . ': their text answers unlike a missing id');
                $this->assertNotContains($answers['their shop'][0], [200, 201], "{$route}: another organisation's section was written");
            }
        }

        $this->setShopGrant($this->masjid, true);

        foreach ($routes as $route => $url) {
            $this->assertGreaterThanOrEqual(400, $this->getJson($url($foreign['shop']->id))->status(), "{$route}: another organisation's section was readable");
            $this->assertGreaterThanOrEqual(400, $this->deleteJson($url($foreign['shop']->id))->status(), "{$route}: another organisation's section was deleted");
        }

        $this->assertSame(SectionType::SHOP, $foreign['shop']->fresh()->section_type);
        $this->assertSame('THEIR SHOP', $foreign['shop']->fresh()->content['heading']);
        $this->assertNotNull(Section::find($foreign['shop']->id));
    }

    #[Test]
    public function an_organisations_public_page_shows_only_its_own_shop_section(): void
    {
        $page = $this->makePage('shop');
        $this->makeSection($this->masjid, 'shop', array_merge(SectionType::SHOP->defaultContent(), ['heading' => 'OUR SHOP']), $page);

        $other = $this->makeMasjid(['shop' => true]);
        $theirPage = Page::create([
            'masjid_id' => $other->id,
            'slug' => 'shop',
            'title' => 'Shop',
            'is_active' => true,
            'order' => 1,
        ]);
        $this->makeSection($other, 'shop', array_merge(SectionType::SHOP->defaultContent(), ['heading' => 'THEIR SHOP']), $theirPage);

        $body = $this->withHeader('masjid-id', (string) $this->masjid->id)
            ->getJson('/api/v1/pages/shop')
            ->assertStatus(200)
            ->getContent();

        $this->assertStringContainsString('OUR SHOP', $body);
        $this->assertStringNotContainsString('THEIR SHOP', $body);
    }

    /* ---------------------------------------------------------------- helpers */

    /**
     * A masjid whose page builder is on, with the shop grant on or off or never given
     * (`[]` means no override at all, the catalogue default).
     *
     * @param  array<string,bool>  $grants
     */
    private function makeMasjid(array $grants, string $orgType = 'masjid'): Masjid
    {
        $masjid = Masjid::create([
            'name' => 'Test Org ' . uniqid(),
            'email' => 'org-' . uniqid() . '@example.test',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'org_type' => $orgType,
        ]);

        // The page builder is an organisation capability (config/capabilities.php).
        $masjid->forceFill(['capability_overrides' => array_merge(['web_pages' => true], $grants)])->save();

        return $masjid;
    }

    private function setShopGrant(Masjid $masjid, bool $on): void
    {
        $masjid->forceFill(['capability_overrides' => ['web_pages' => true, 'shop' => $on]])->save();
    }

    private function makePage(string $slug): Page
    {
        return Page::create([
            'masjid_id' => $this->masjid->id,
            'slug' => $slug,
            'title' => ucfirst($slug),
            'is_active' => true,
            'order' => 1,
        ]);
    }

    /** A section written straight to the table, so it exists whatever the grant says. */
    private function makeSection(Masjid $masjid, string $type, array $content, ?Page $page = null): Section
    {
        $section = Section::create([
            'masjid_id' => $masjid->id,
            'section_type' => $type,
            'title' => 'Stored',
            'content' => $content,
            'is_active' => true,
        ]);

        if ($page !== null) {
            $page->sections()->attach($section->id, ['order' => $page->sections()->count() + 1, 'platforms' => null]);
        }

        return $section;
    }

    private function pageSections(Page $page): string
    {
        return "/api/admin/masjids/{$this->masjid->id}/pages/{$page->id}/sections";
    }

    private function library(): string
    {
        return "/api/admin/masjids/{$this->masjid->id}/sections";
    }

    /** @return array<int, array<string,mixed>> the section-types payload for an organisation */
    private function types(Masjid $masjid): array
    {
        return $this->getJson("/api/admin/masjids/{$masjid->id}/section-types")->assertStatus(200)->json('data');
    }

    /**
     * The four endpoints that can write a section, each taking only the content. The two
     * updates target a stored shop section (written straight to the table); the page update
     * leaves out section_type, so the stored type decides, and the library update sends it.
     *
     * @return array<string, callable(array<string,mixed>): \Illuminate\Testing\TestResponse>
     */
    private function writers(): array
    {
        $page = $this->makePage('writers-' . uniqid());
        $stored = $this->makeSection($this->masjid, 'shop', SectionType::SHOP->defaultContent(), $page);

        return [
            'page store' => fn (array $content) => $this->postJson($this->pageSections($page), [
                'section_type' => 'shop',
                'content' => $content,
            ]),
            'library store' => fn (array $content) => $this->postJson($this->library(), [
                'section_type' => 'shop',
                'content' => $content,
            ]),
            'page update' => fn (array $content) => $this->putJson("{$this->pageSections($page)}/{$stored->id}", [
                'content' => $content,
            ]),
            'library update' => fn (array $content) => $this->putJson("{$this->library()}/{$stored->id}", [
                'section_type' => 'shop',
                'content' => $content,
            ]),
        ];
    }
}
