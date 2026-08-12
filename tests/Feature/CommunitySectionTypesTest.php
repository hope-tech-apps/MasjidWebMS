<?php

namespace Tests\Feature;

use App\Enums\SectionType;
use App\Http\Controllers\AdminDashboard\PageSectionsController;
use App\Http\Controllers\AdminDashboard\SectionsController;
use App\Models\Masjid;
use App\Models\MasjidAbout;
use App\Models\Page;
use App\Models\Section;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Enum;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Manara Community — community-vertical page-builder section types (T-020).
 *
 * `services_eligibility`, `providers_directory` and `impact_stats` are added the
 * way every other section type is: an enum case, a `content` JSON shape, a Vue
 * editor. They exist because a free clinic's real public pages — the pilot is
 * al-aqsaclinic.org — say three things the masjid and school types cannot: what
 * we do and who qualifies, who provides the care, and the numbers a funder asks
 * for. This suite pins the two halves that can break silently.
 *
 *  1. The new shapes SURVIVE the round trip. `services_eligibility` carries the
 *     deepest content in the palette (a list of objects AND a fixed object with
 *     a string list and a nested card inside it), and the only thing between it
 *     and the renderer is a JSON column plus Section::getContentAttribute. A
 *     field that flattens here is a blank eligibility card on a live clinic page.
 *
 *  2. Nothing else moved. The change is purely additive: the twenty pre-existing
 *     types and the three school types keep their values and their default
 *     content byte for byte.
 *
 * Ordering note: boots the app FIRST via parent::setUp(), THEN points the default
 * connection at sqlite :memory: and migrates — same as SchoolSectionTypesTest, so
 * each test is self-contained and runnable in isolation.
 */
class CommunitySectionTypesTest extends TestCase
{
    /**
     * The palette as it stood before T-020 (the twenty originals plus the three
     * school types from T-010). If a value here ever changes, an already-published
     * page stops rendering — `section_type` is cast to this enum, so an unknown
     * string is not "extra data", it is a row that throws on every read.
     */
    private const PRE_EXISTING_TYPES = [
        'page_title',
        'prayer_times',
        'text',
        'about_us',
        'image_text_grid',
        'grid_cards',
        'donation',
        'contact_form',
        'services_list',
        'announcements_list',
        'gallery',
        'events',
        'stats',
        'mission_vision',
        'cta',
        'form',
        'image',
        'link_list',
        'carousel',
        'embed',
        'staff_directory',
        'programs',
        'admissions_tuition',
    ];

    private const COMMUNITY_TYPES = [
        'services_eligibility',
        'providers_directory',
        'impact_stats',
    ];

    /**
     * Types added to the palette AFTER this suite was written. Every value pinned
     * above is still pinned unchanged — this list exists only so the "nothing else
     * moved" count below stays an exact count instead of being loosened to a
     * minimum, which would stop catching a type that quietly disappears. Same
     * device as SchoolSectionTypesTest::LATER_TYPES.
     * OfferingSectionTypeTest owns this one; see T-006g.
     */
    private const LATER_TYPES = [
        'offering',
    ];

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

        \DB::purge('sqlite');
        \DB::reconnect('sqlite');

        // The masjids migration hardcodes a MySQL collation (utf8mb4_bin) with no
        // driver guard, which sqlite doesn't know. Register a binary collation of
        // that name so the schema is portable in tests.
        $pdo = \DB::connection('sqlite')->getPdo();
        if (method_exists($pdo, 'sqliteCreateCollation')) {
            $pdo->sqliteCreateCollation('utf8mb4_bin', static fn($a, $b) => strcmp($a, $b));
        }

        $this->artisan('migrate', ['--database' => 'sqlite'])->run();
    }

    protected function tearDown(): void
    {
        \DB::purge('sqlite');
        parent::tearDown();
    }

    /* ---------------------------------------------------------------- */

    private function makeMasjid(array $overrides = []): Masjid
    {
        return Masjid::create(array_merge([
            'name' => 'Test Clinic ' . uniqid(),
            'email' => 'clinic-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ], $overrides));
    }

    /**
     * Create a page with one section attached, returning the page.
     */
    private function makePageWithSection(Masjid $masjid, string $sectionType, array $content): Page
    {
        $page = Page::create([
            'masjid_id' => $masjid->id,
            'slug' => 'test-page-' . uniqid(),
            'title' => 'Test Page',
            'is_active' => true,
            'order' => 1,
        ]);

        $section = Section::create([
            'masjid_id' => $masjid->id,
            'section_type' => $sectionType,
            'title' => 'Stored Title',
            'content' => $content,
            'is_active' => true,
        ]);

        $page->sections()->attach($section->id, ['order' => 1, 'platforms' => null]);

        return $page;
    }

    /**
     * @return array<string,mixed> The serialized `content` of the page's first section.
     */
    private function serializedContent(Masjid $masjid, Page $page): array
    {
        return $this->withHeader('masjid-id', (string) $masjid->id)
            ->getJson("/api/v1/pages/{$page->slug}")
            ->assertStatus(200)
            ->json('data.sections.0.content');
    }

    /* ---------------- registration ---------------- */

    #[Test]
    public function the_three_community_types_are_registered_in_the_enum(): void
    {
        foreach (self::COMMUNITY_TYPES as $value) {
            $type = SectionType::tryFrom($value);

            $this->assertInstanceOf(
                SectionType::class,
                $type,
                "{$value} is not a SectionType case"
            );
            $this->assertContains($value, SectionType::getValues());
            $this->assertNotSame('', $type->label());
            $this->assertNotSame('', $type->description());
        }
    }

    #[Test]
    public function community_types_are_author_supplied_not_external_data(): void
    {
        // A providers directory is NOT a view onto contacts — those are private CRM
        // records. And the impact numbers are the audited figures a clinic reported
        // for a stated period, not a live count of whatever rows exist today.
        $this->assertFalse(SectionType::SERVICES_ELIGIBILITY->usesExternalData());
        $this->assertFalse(SectionType::PROVIDERS_DIRECTORY->usesExternalData());
        $this->assertFalse(SectionType::IMPACT_STATS->usesExternalData());
    }

    #[Test]
    public function the_section_type_validation_rule_accepts_the_new_types_and_still_rejects_junk(): void
    {
        // The exact rule Store/UpdateSectionRequest and the page-scoped requests use.
        $rules = ['section_type' => ['required', new Enum(SectionType::class)]];

        foreach (self::COMMUNITY_TYPES as $value) {
            $this->assertTrue(
                Validator::make(['section_type' => $value], $rules)->passes(),
                "{$value} was rejected by the section_type rule"
            );
        }

        $this->assertFalse(
            Validator::make(['section_type' => 'clinic_services'], $rules)->passes()
        );
    }

    #[Test]
    public function the_new_types_are_offered_to_every_tenant_by_the_section_types_payload(): void
    {
        // PageSectionsController@sectionTypes maps over SectionType::cases() with no
        // filter — the palette is GLOBAL. A masjid running a food pantry is offered
        // services_eligibility exactly as the clinic is; gating on org_type is
        // forbidden by .claude/rules/section-types.md.
        $offered = collect(SectionType::cases())->map(fn(SectionType $type) => [
            'value' => $type->value,
            'label' => $type->label(),
            'description' => $type->description(),
            'uses_external_data' => $type->usesExternalData(),
            'default_content' => $type->defaultContent(),
        ]);

        $values = $offered->pluck('value')->all();

        foreach (array_merge(self::PRE_EXISTING_TYPES, self::COMMUNITY_TYPES) as $value) {
            $this->assertContains($value, $values);
        }

        // Every offered type carries a default content array the editor can seed from.
        $offered->each(function (array $entry): void {
            $this->assertIsArray($entry['default_content'], "{$entry['value']} has no default content");
        });
    }

    /* ---------------- default content shapes ---------------- */

    #[Test]
    public function services_eligibility_default_content_has_the_documented_shape(): void
    {
        $this->assertSame([
            'heading' => '',
            'description' => '',
            'services' => [],
            'layout' => 'cards',
            'columns' => 3,
            'eligibility' => [
                'heading' => '',
                'intro' => '',
                'criteria' => [],
                'note' => '',
                'highlight' => [
                    'badge' => '',
                    'title' => '',
                    'subtitle' => '',
                    'body' => '',
                ],
            ],
            'button_text' => '',
            'button_page_id' => null,
            'button_link' => null,
            'background_color' => '#ffffff',
        ], SectionType::SERVICES_ELIGIBILITY->defaultContent());
    }

    #[Test]
    public function providers_directory_default_content_has_the_documented_shape(): void
    {
        $this->assertSame([
            'heading' => '',
            'description' => '',
            'providers' => [],
            'layout' => 'grid',
            'columns' => 3,
            'background_color' => '#ffffff',
        ], SectionType::PROVIDERS_DIRECTORY->defaultContent());
    }

    #[Test]
    public function impact_stats_default_content_has_the_documented_shape(): void
    {
        $this->assertSame([
            'heading' => '',
            'description' => '',
            'period' => '',
            'stats' => [],
            'layout' => 'row',
            'columns' => 3,
            'background_color' => '#ffffff',
        ], SectionType::IMPACT_STATS->defaultContent());
    }

    /* ---------------- round trips ---------------- */

    #[Test]
    public function a_services_eligibility_section_survives_the_round_trip_including_the_eligibility_block(): void
    {
        $masjid = $this->makeMasjid();

        $content = [
            'heading' => 'Our Services',
            'description' => 'Free primary care for uninsured adults in our county.',
            'services' => [
                [
                    'name' => 'Primary Medical Care',
                    'description' => 'Chronic disease management, acute visits and referrals.',
                    'image_url' => 'https://cdn.test/exam-room.jpg',
                ],
                [
                    'name' => 'Dental Care',
                    'description' => '',
                    'image_url' => null,
                ],
            ],
            'layout' => 'cards',
            'columns' => 2,
            'eligibility' => [
                'heading' => 'Who We Serve',
                'intro' => 'To be seen at the clinic you must meet all of the following:',
                // A list of plain strings nested inside a fixed object — the deepest
                // any section content goes without becoming un-uploadable.
                'criteria' => [
                    'Household income at or below 200% of the Federal Poverty Level',
                    'No health insurance of any kind',
                    'Resident of the county',
                ],
                'note' => 'Bring proof of income and residency to your first visit.',
                'highlight' => [
                    'badge' => 'Yellow Card',
                    'title' => 'Do you have a Yellow Card?',
                    'subtitle' => 'Income at or below 200% FPL',
                    'body' => 'The Yellow Card is issued at your first eligibility screening and covers every clinic service at no charge.',
                ],
            ],
            'button_text' => 'Check If You Qualify',
            'button_page_id' => null,
            'button_link' => 'https://intake.test/screening',
            'background_color' => '#ffffff',
        ];

        $page = $this->makePageWithSection($masjid, 'services_eligibility', $content);

        $serialized = $this->serializedContent($masjid, $page);

        $this->assertSame($content, $serialized);
        // Named explicitly: the highlighted card is a block of its own, not one more
        // criterion, so the renderer can style it without guessing which line matters.
        $this->assertSame('Yellow Card', $serialized['eligibility']['highlight']['badge']);
        $this->assertCount(3, $serialized['eligibility']['criteria']);
    }

    #[Test]
    public function an_empty_eligibility_block_comes_back_as_lists_and_objects_not_null(): void
    {
        $masjid = $this->makeMasjid();

        $content = SectionType::SERVICES_ELIGIBILITY->defaultContent();
        $content['heading'] = 'Services';

        $page = $this->makePageWithSection($masjid, 'services_eligibility', $content);

        $serialized = $this->serializedContent($masjid, $page);

        // An empty list must come back as a list, and the fixed block as an object,
        // or the renderer's v-for and property access break on a real page.
        $this->assertSame([], $serialized['services']);
        $this->assertSame([], $serialized['eligibility']['criteria']);
        $this->assertIsArray($serialized['eligibility']['highlight']);
        $this->assertSame('', $serialized['eligibility']['highlight']['title']);
    }

    #[Test]
    public function the_services_eligibility_button_resolves_an_internal_page_id_to_a_url(): void
    {
        $masjid = $this->makeMasjid();

        $intakePage = Page::create([
            'masjid_id' => $masjid->id,
            'slug' => 'become-a-patient',
            'title' => 'Become a Patient',
            'is_active' => true,
            'order' => 2,
        ]);

        $content = array_merge(SectionType::SERVICES_ELIGIBILITY->defaultContent(), [
            'heading' => 'Our Services',
            'button_text' => 'Apply for the Yellow Card',
            'button_page_id' => $intakePage->id,
        ]);

        $page = $this->makePageWithSection($masjid, 'services_eligibility', $content);

        $serialized = $this->serializedContent($masjid, $page);

        // Section::getContentAttribute resolves the id at read time, so renaming the
        // intake page cannot leave the button pointing at a dead slug.
        $this->assertSame('/become-a-patient', $serialized['button_page_url']);
        $this->assertSame($intakePage->id, $serialized['button_page_id']);
    }

    #[Test]
    public function a_providers_directory_survives_the_round_trip_as_a_flat_list_grouped_by_label(): void
    {
        $masjid = $this->makeMasjid();

        $content = [
            'heading' => 'Our Providers',
            'description' => 'Every clinician here volunteers their time.',
            // FLAT, grouped by the `department` label — a departments[].providers[]
            // tree would nest the photo two array levels deep and every upload
            // would vanish. Same shape and same reason as staff_directory.
            'providers' => [
                [
                    'name' => 'Dr. Layla Haddad',
                    'credential' => 'MD',
                    'specialty' => 'Family Medicine',
                    'department' => 'Primary Care',
                    'photo_url' => 'https://cdn.test/haddad.jpg',
                ],
                [
                    'name' => 'Sam Okonkwo',
                    'credential' => 'FNP-C',
                    'specialty' => 'Adult Primary Care',
                    'department' => 'Primary Care',
                    'photo_url' => null,
                ],
                [
                    'name' => 'Dr. Rana Aziz',
                    'credential' => 'DDS',
                    'specialty' => 'General Dentistry',
                    'department' => 'Dental',
                    'photo_url' => null,
                ],
            ],
            'layout' => 'grid',
            'columns' => 3,
            'background_color' => '#f7f7f5',
        ];

        $page = $this->makePageWithSection($masjid, 'providers_directory', $content);

        $serialized = $this->serializedContent($masjid, $page);

        $this->assertSame($content, $serialized);
        // Two providers share a department label; the renderer buckets on it.
        $this->assertSame('Primary Care', $serialized['providers'][0]['department']);
        $this->assertSame('Primary Care', $serialized['providers'][1]['department']);
        $this->assertSame('Dental', $serialized['providers'][2]['department']);
    }

    #[Test]
    public function impact_stat_values_survive_as_display_text_and_are_never_numbers(): void
    {
        $masjid = $this->makeMasjid();

        $content = [
            'heading' => 'Our Impact',
            'description' => 'What your support paid for.',
            'period' => 'Since 2010',
            'stats' => [
                [
                    // The exact strings a funder report carries. The comma, the plus
                    // and the "M" are part of the claim; formatting a stored decimal
                    // here would change a published, audited figure.
                    'value' => '6,000+',
                    'label' => 'patient visits',
                    'description' => 'delivered at no cost to patients',
                ],
                [
                    'value' => '$6.3M',
                    'label' => 'in services provided',
                    'description' => '',
                ],
                [
                    'value' => '1 in 4',
                    'label' => 'patients seen the same week',
                    'description' => '',
                ],
            ],
            'layout' => 'row',
            'columns' => 3,
            'background_color' => '#ffffff',
        ];

        $page = $this->makePageWithSection($masjid, 'impact_stats', $content);

        $serialized = $this->serializedContent($masjid, $page);

        $this->assertSame($content, $serialized);

        foreach ($serialized['stats'] as $stat) {
            $this->assertIsString($stat['value'], 'An impact value was cast away from display text');
        }

        $this->assertSame('$6.3M', $serialized['stats'][1]['value']);
        $this->assertSame('Since 2010', $serialized['period']);
    }

    #[Test]
    public function the_content_binder_leaves_community_sections_alone(): void
    {
        $masjid = $this->makeMasjid();

        // The binder overrides about_us / mission_vision / donation / contact_form
        // from their dedicated models. A community section must fall through
        // untouched even when those models are populated.
        MasjidAbout::create([
            'masjid_id' => $masjid->id,
            'about' => 'Canonical about text',
            'mission' => 'Canonical mission',
            'vision' => 'Canonical vision',
        ]);

        $content = array_merge(SectionType::IMPACT_STATS->defaultContent(), [
            'heading' => 'Our Impact',
            'description' => 'Authored in the section, not in MasjidAbout.',
        ]);

        $page = $this->makePageWithSection($masjid, 'impact_stats', $content);

        $this->assertSame($content, $this->serializedContent($masjid, $page));
    }

    /* ---------------- image upload wiring ---------------- */

    #[Test]
    public function per_item_images_are_wired_into_both_upload_maps_at_one_array_level(): void
    {
        // Both controllers own a copy of this map. A type missing from EITHER means
        // an uploaded photo is accepted, stored as media, and then never written
        // back into the content — a blank card with no error anywhere.
        foreach ([SectionsController::class, PageSectionsController::class] as $controllerClass) {
            $method = new ReflectionMethod($controllerClass, 'getImageFieldsForSectionType');
            $method->setAccessible(true);
            $controller = new $controllerClass();

            $this->assertSame(
                ['services.*.image_url'],
                $method->invoke($controller, SectionType::SERVICES_ELIGIBILITY),
                "{$controllerClass} does not map service images"
            );
            $this->assertSame(
                ['providers.*.photo_url'],
                $method->invoke($controller, SectionType::PROVIDERS_DIRECTORY),
                "{$controllerClass} does not map provider photos"
            );
            // Nothing on the numbers block uploads.
            $this->assertSame(
                [],
                $method->invoke($controller, SectionType::IMPACT_STATS)
            );

            // handleArrayImageUploads matches exactly ONE array index, so a mapped
            // pattern with two wildcards would silently drop every upload.
            foreach ([SectionType::SERVICES_ELIGIBILITY, SectionType::PROVIDERS_DIRECTORY] as $type) {
                foreach ($method->invoke($controller, $type) as $field) {
                    $this->assertSame(1, substr_count($field, '*'), "{$field} nests too deep to upload");
                }
            }
        }
    }

    /* ---------------- purely additive ---------------- */

    #[Test]
    public function every_pre_existing_section_type_still_exists_with_the_same_value(): void
    {
        $values = SectionType::getValues();

        foreach (self::PRE_EXISTING_TYPES as $value) {
            $this->assertContains($value, $values, "{$value} disappeared from the palette");
        }

        $this->assertCount(
            count(self::PRE_EXISTING_TYPES) + count(self::COMMUNITY_TYPES) + count(self::LATER_TYPES),
            $values,
            'The palette gained or lost a type beyond the community types and the ones added since'
        );
    }

    #[Test]
    public function pre_existing_default_content_is_unchanged(): void
    {
        // A representative sample across the shapes: nested-items, the one with a
        // read-time-resolved page id, and the two school shapes T-010 added.
        $this->assertSame([
            'items_per_row' => 3,
            'items' => [
                ['title' => '', 'text' => '', 'image_url' => null],
            ],
        ], SectionType::GRID_CARDS->defaultContent());

        $this->assertSame([
            'heading' => 'Our Impact',
            'stats' => [],
            'layout' => 'horizontal',
        ], SectionType::STATS->defaultContent());

        $this->assertSame([
            'heading' => '',
            'description' => '',
            'members' => [],
            'layout' => 'grid',
            'columns' => 3,
            'show_contact' => false,
            'background_color' => '#ffffff',
        ], SectionType::STAFF_DIRECTORY->defaultContent());

        $this->assertSame([
            'heading' => '',
            'description' => '',
            'school_year' => '',
            'tiers' => [],
            'fees' => [],
            'payment_plans' => [],
            'steps' => [],
            'disclaimer' => '',
            'button_text' => '',
            'button_page_id' => null,
            'button_link' => null,
            'background_color' => '#ffffff',
        ], SectionType::ADMISSIONS_TUITION->defaultContent());

        $this->assertSame(['form_id' => null, 'title' => '', 'intro' => ''], SectionType::FORM->defaultContent());
    }

    #[Test]
    public function the_pre_existing_stats_type_is_untouched_by_the_new_impact_type(): void
    {
        $masjid = $this->makeMasjid();

        // `stats` and `impact_stats` are separate types on purpose: the old one is
        // already on published pages and keeps its own shape (icon per stat, no
        // reporting period). Adding the new one must not rewrite it.
        $content = [
            'heading' => 'Our Impact',
            'stats' => [
                ['label' => 'Families served', 'value' => '250', 'icon' => 'bi-people'],
            ],
            'layout' => 'horizontal',
        ];

        $page = $this->makePageWithSection($masjid, 'stats', $content);

        $serialized = $this->serializedContent($masjid, $page);

        $this->assertSame($content, $serialized);
        $this->assertArrayNotHasKey('period', $serialized);
        $this->assertArrayNotHasKey('description', $serialized);
    }

    #[Test]
    public function an_existing_section_type_still_serializes_exactly_as_before(): void
    {
        $masjid = $this->makeMasjid();

        $content = [
            'heading' => '',
            'description' => '',
            'members' => [
                [
                    'name' => 'Sr. Aisha Rahman',
                    'role' => 'Volunteer Coordinator',
                    'department' => 'Administration',
                    'credentials' => '',
                    'bio' => '',
                    'email' => '',
                    'phone' => '',
                    'photo_url' => null,
                ],
            ],
            'layout' => 'grid',
            'columns' => 3,
            'show_contact' => false,
            'background_color' => '#ffffff',
        ];

        $page = $this->makePageWithSection($masjid, 'staff_directory', $content);

        $serialized = $this->serializedContent($masjid, $page);

        $this->assertSame($content, $serialized);
        $this->assertArrayNotHasKey('providers', $serialized);
        $this->assertArrayNotHasKey('eligibility', $serialized);
    }

    #[Test]
    public function pre_existing_external_data_flags_are_unchanged(): void
    {
        // The four dynamic types stayed dynamic and nothing else became dynamic —
        // in particular impact_stats did NOT, which is what keeps a published
        // figure a reported figure rather than a live query.
        $external = collect(SectionType::cases())
            ->filter(fn(SectionType $type) => $type->usesExternalData())
            ->map(fn(SectionType $type) => $type->value)
            ->values()
            ->all();

        $this->assertSame(
            ['services_list', 'announcements_list', 'gallery', 'events'],
            $external
        );
    }
}
