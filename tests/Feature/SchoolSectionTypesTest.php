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
 * Manara Schools — school-vertical page-builder section types (T-010).
 *
 * `staff_directory`, `programs` and `admissions_tuition` are added the way every
 * other section type is: an enum case, a `content` JSON shape, a Vue editor. This
 * suite pins the two halves that can break silently.
 *
 *  1. The new shapes SURVIVE the round trip. Their content is deeper than any
 *     existing type (a list of objects, one of which holds a list of strings),
 *     and the only thing standing between that and the renderer is a JSON column
 *     plus Section::getContentAttribute. A field that silently flattens here
 *     shows up as a blank staff photo on a live school site.
 *
 *  2. Nothing else moved. The change is purely additive by contract: the twenty
 *     pre-existing types keep their values and their default content byte for
 *     byte, and a stored section of an old type serializes exactly as before.
 *
 * Ordering note: this boots the app FIRST via parent::setUp(), THEN points the
 * default connection at sqlite :memory: and migrates — same as
 * ContentUnification\PageSectionContentUnificationTest, so each test is
 * self-contained and runnable in isolation.
 */
class SchoolSectionTypesTest extends TestCase
{
    /**
     * The palette as it stood before T-010. If a value here ever changes, an
     * already-published page stops rendering — `section_type` is cast to this
     * enum, so an unknown string is not "extra data", it is a row that throws on
     * every read.
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
    ];

    private const SCHOOL_TYPES = [
        'staff_directory',
        'programs',
        'admissions_tuition',
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
            'name' => 'Test School ' . uniqid(),
            'email' => 'school-' . uniqid() . '@test.local',
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
    public function the_three_school_types_are_registered_in_the_enum(): void
    {
        foreach (self::SCHOOL_TYPES as $value) {
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
    public function school_types_are_author_supplied_not_external_data(): void
    {
        // A staff directory is NOT a view onto contacts/group_memberships — those
        // are private CRM records about families. Publishing a person is editorial.
        $this->assertFalse(SectionType::STAFF_DIRECTORY->usesExternalData());
        $this->assertFalse(SectionType::PROGRAMS->usesExternalData());
        $this->assertFalse(SectionType::ADMISSIONS_TUITION->usesExternalData());
    }

    #[Test]
    public function the_section_type_validation_rule_accepts_the_new_types_and_still_rejects_junk(): void
    {
        // The exact rule Store/UpdateSectionRequest and the page-scoped requests use.
        $rules = ['section_type' => ['required', new Enum(SectionType::class)]];

        foreach (self::SCHOOL_TYPES as $value) {
            $this->assertTrue(
                Validator::make(['section_type' => $value], $rules)->passes(),
                "{$value} was rejected by the section_type rule"
            );
        }

        $this->assertFalse(
            Validator::make(['section_type' => 'faculty_directory'], $rules)->passes()
        );
    }

    #[Test]
    public function the_new_types_are_offered_by_the_admin_section_types_payload(): void
    {
        // PageSectionsController@sectionTypes maps over SectionType::cases() with no
        // filter — the palette is global, so the same list reaches every tenant
        // regardless of org_type. This asserts that shape, not a per-vertical one.
        $offered = collect(SectionType::cases())->map(fn(SectionType $type) => [
            'value' => $type->value,
            'label' => $type->label(),
            'description' => $type->description(),
            'uses_external_data' => $type->usesExternalData(),
            'default_content' => $type->defaultContent(),
        ]);

        $values = $offered->pluck('value')->all();

        foreach (array_merge(self::PRE_EXISTING_TYPES, self::SCHOOL_TYPES) as $value) {
            $this->assertContains($value, $values);
        }

        // Every offered type carries a default content array the editor can seed from.
        $offered->each(function (array $entry): void {
            $this->assertIsArray($entry['default_content'], "{$entry['value']} has no default content");
        });
    }

    /* ---------------- default content shapes ---------------- */

    #[Test]
    public function staff_directory_default_content_has_the_documented_shape(): void
    {
        $this->assertSame([
            'heading' => '',
            'description' => '',
            'members' => [],
            'layout' => 'grid',
            'columns' => 3,
            'show_contact' => false,
            'background_color' => '#ffffff',
        ], SectionType::STAFF_DIRECTORY->defaultContent());

        // Publishing a teacher's email to the open web must be an explicit choice.
        $this->assertFalse(SectionType::STAFF_DIRECTORY->defaultContent()['show_contact']);
    }

    #[Test]
    public function programs_default_content_has_the_documented_shape(): void
    {
        $this->assertSame([
            'heading' => '',
            'description' => '',
            'programs' => [],
            'layout' => 'cards',
            'columns' => 3,
            'background_color' => '#ffffff',
        ], SectionType::PROGRAMS->defaultContent());
    }

    #[Test]
    public function admissions_tuition_default_content_has_the_documented_shape(): void
    {
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
    }

    /* ---------------- round trips ---------------- */

    #[Test]
    public function a_staff_directory_survives_the_round_trip_with_every_member_field(): void
    {
        $masjid = $this->makeMasjid();

        $content = [
            'heading' => 'Faculty & Staff',
            'description' => 'The people who teach here.',
            'members' => [
                [
                    'name' => 'Sr. Aisha Rahman',
                    'role' => 'Kindergarten Lead Teacher',
                    'department' => 'Lower School',
                    'credentials' => 'M.Ed., NC B-K Licensed',
                    'bio' => 'Twelve years in early-childhood classrooms.',
                    'email' => 'arahman@school.test',
                    'phone' => '+1 336 555 0100',
                    'photo_url' => 'https://cdn.test/aisha.jpg',
                ],
                [
                    'name' => 'Br. Yusuf Adam',
                    'role' => 'Quran Specialist',
                    'department' => 'Specialists',
                    'credentials' => 'Ijazah in Hafs',
                    'bio' => '',
                    'email' => '',
                    'phone' => '',
                    'photo_url' => null,
                ],
            ],
            'layout' => 'grid',
            'columns' => 4,
            'show_contact' => true,
            'background_color' => '#f7f7f5',
        ];

        $page = $this->makePageWithSection($masjid, 'staff_directory', $content);

        $this->assertSame($content, $this->serializedContent($masjid, $page));
    }

    #[Test]
    public function a_programs_section_survives_the_round_trip_including_the_nested_highlight_lists(): void
    {
        $masjid = $this->makeMasjid();

        $content = [
            'heading' => 'Our Programs',
            'description' => '',
            'programs' => [
                [
                    'name' => 'Academic Program',
                    'level' => 'Grades K-2',
                    'schedule' => 'Monday - Friday, 8:00 AM - 3:00 PM',
                    'summary' => 'A standards-based curriculum.',
                    // The deepest nesting any section type carries: a list of
                    // strings inside a list of objects inside the content blob.
                    'highlights' => [
                        'Math, Science and English Language Arts',
                        'Social Studies',
                    ],
                    'image_url' => 'https://cdn.test/classroom.jpg',
                    'link_url' => '/curriculum',
                    'link_text' => 'See the curriculum',
                ],
                [
                    'name' => 'Quran & Islamic Studies',
                    'level' => 'Pre-K - 2nd Grade',
                    'schedule' => 'Daily',
                    'summary' => '',
                    'highlights' => [],
                    'image_url' => null,
                    'link_url' => '',
                    'link_text' => '',
                ],
            ],
            'layout' => 'cards',
            'columns' => 2,
            'background_color' => '#ffffff',
        ];

        $page = $this->makePageWithSection($masjid, 'programs', $content);

        $serialized = $this->serializedContent($masjid, $page);

        $this->assertSame($content, $serialized);
        // Named explicitly: an empty list must come back as a list, not as null or
        // as an object, or the renderer's v-for breaks on a real page.
        $this->assertSame([], $serialized['programs'][1]['highlights']);
    }

    #[Test]
    public function an_admissions_tuition_section_survives_the_round_trip_with_all_four_lists(): void
    {
        $masjid = $this->makeMasjid();

        $content = [
            'heading' => 'Tuition & Fee Schedule',
            'description' => '',
            'school_year' => '2026-2027 School Year',
            'tiers' => [
                [
                    'name' => 'Kindergarten - 2nd Grade',
                    'badge' => 'Full-Time School',
                    // Display text, deliberately: a real schedule mixes numbers with
                    // "Included" and "Contact us", and nothing charges from it.
                    'amount' => '$8,000',
                    'period' => '/year',
                    'note' => '',
                    'includes' => [
                        'Monday - Friday, 8:00 AM - 3:00 PM',
                        'Full academic curriculum + Islamic studies',
                    ],
                ],
                [
                    'name' => 'Pre-Kindergarten',
                    'badge' => 'PreK & Daycare',
                    'amount' => 'Contact us',
                    'period' => '',
                    'note' => 'Toilet training confirmation required',
                    'includes' => [],
                ],
            ],
            'fees' => [
                ['label' => 'Registration Fee', 'amount' => '$200', 'note' => 'non-refundable'],
                ['label' => 'Books & Materials', 'amount' => '$500', 'note' => 'per student, per year'],
            ],
            'payment_plans' => [
                ['label' => 'Annual', 'detail' => 'Full tuition due Aug 1st ($250 discount per child)'],
                ['label' => 'Monthly', 'detail' => '10 payments, 1st of each month'],
            ],
            'steps' => [
                ['title' => 'Tour the school', 'description' => 'Book a visit with the office.'],
                ['title' => 'Submit the packet', 'description' => 'Complete every form and pay the registration fee.'],
            ],
            'disclaimer' => 'Tuition and fees are subject to change.',
            'button_text' => 'Start an Application',
            'button_page_id' => null,
            'button_link' => 'https://apply.test/al-razi',
            'background_color' => '#ffffff',
        ];

        $page = $this->makePageWithSection($masjid, 'admissions_tuition', $content);

        $this->assertSame($content, $this->serializedContent($masjid, $page));
    }

    #[Test]
    public function the_admissions_apply_button_resolves_an_internal_page_id_to_a_url(): void
    {
        $masjid = $this->makeMasjid();

        $applyPage = Page::create([
            'masjid_id' => $masjid->id,
            'slug' => 'registration',
            'title' => 'Registration',
            'is_active' => true,
            'order' => 2,
        ]);

        $content = array_merge(SectionType::ADMISSIONS_TUITION->defaultContent(), [
            'heading' => 'Tuition',
            'button_text' => 'Apply Now',
            'button_page_id' => $applyPage->id,
        ]);

        $page = $this->makePageWithSection($masjid, 'admissions_tuition', $content);

        $serialized = $this->serializedContent($masjid, $page);

        // Section::getContentAttribute resolves the id at read time, so renaming the
        // target page cannot leave the apply button pointing at a dead slug.
        $this->assertSame('/registration', $serialized['button_page_url']);
        $this->assertSame($applyPage->id, $serialized['button_page_id']);
    }

    #[Test]
    public function the_content_binder_leaves_school_sections_alone(): void
    {
        $masjid = $this->makeMasjid();

        // The binder overrides about_us / mission_vision / donation / contact_form
        // from their dedicated models. A school section must fall through untouched
        // even when those models are populated.
        MasjidAbout::create([
            'masjid_id' => $masjid->id,
            'about' => 'Canonical about text',
            'mission' => 'Canonical mission',
            'vision' => 'Canonical vision',
        ]);

        $content = array_merge(SectionType::STAFF_DIRECTORY->defaultContent(), [
            'heading' => 'Faculty',
            'description' => 'Authored in the section, not in MasjidAbout.',
        ]);

        $page = $this->makePageWithSection($masjid, 'staff_directory', $content);

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
                ['members.*.photo_url'],
                $method->invoke($controller, SectionType::STAFF_DIRECTORY),
                "{$controllerClass} does not map staff photos"
            );
            $this->assertSame(
                ['programs.*.image_url'],
                $method->invoke($controller, SectionType::PROGRAMS),
                "{$controllerClass} does not map program images"
            );
            // No images on the price list.
            $this->assertSame(
                [],
                $method->invoke($controller, SectionType::ADMISSIONS_TUITION)
            );

            // handleArrayImageUploads matches exactly ONE array index, so a mapped
            // pattern with two wildcards would silently drop every upload.
            foreach ([SectionType::STAFF_DIRECTORY, SectionType::PROGRAMS] as $type) {
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
            count(self::PRE_EXISTING_TYPES) + count(self::SCHOOL_TYPES),
            $values,
            'The palette gained or lost a type beyond the three school types'
        );
    }

    #[Test]
    public function pre_existing_default_content_is_unchanged(): void
    {
        // A representative sample across the shapes: flat, nested-items, and the
        // one with a read-time-resolved page id.
        $this->assertSame([
            'items_per_row' => 3,
            'items' => [
                ['title' => '', 'text' => '', 'image_url' => null],
            ],
        ], SectionType::GRID_CARDS->defaultContent());

        $this->assertSame([
            'heading' => 'Our Mission & Vision',
            'items' => [],
            'layout' => 'side_by_side',
        ], SectionType::MISSION_VISION->defaultContent());

        $this->assertSame([
            'title' => '',
            'subtitle' => '',
            'text' => '',
            'main_image_url' => null,
            'header_image_url' => null,
            'footer_image_url' => null,
            'button_text' => '',
            'button_page_id' => null,
            'button_link' => null,
            'show_button' => true,
            'content_direction' => 'ltr',
            'background_color' => '#ffffff',
        ], SectionType::IMAGE_TEXT_GRID->defaultContent());

        $this->assertSame([
            'provider' => null,
            'url' => '',
            'title' => '',
            'caption' => '',
            'height' => null,
            'aspect' => null,
            'fallback_text' => '',
            'background_color' => '#ffffff',
        ], SectionType::EMBED->defaultContent());

        $this->assertSame(['form_id' => null, 'title' => '', 'intro' => ''], SectionType::FORM->defaultContent());
    }

    #[Test]
    public function an_existing_section_type_still_serializes_exactly_as_before(): void
    {
        $masjid = $this->makeMasjid();

        $content = [
            'items_per_row' => 3,
            'items' => [
                ['title' => 'Weekend School', 'text' => 'Saturdays', 'image_url' => null],
            ],
        ];

        $page = $this->makePageWithSection($masjid, 'grid_cards', $content);

        $serialized = $this->serializedContent($masjid, $page);

        $this->assertSame($content['items_per_row'], $serialized['items_per_row']);
        $this->assertSame($content['items'], $serialized['items']);
        $this->assertArrayNotHasKey('members', $serialized);
        $this->assertArrayNotHasKey('tiers', $serialized);
    }

    #[Test]
    public function pre_existing_external_data_flags_are_unchanged(): void
    {
        // The four dynamic types stayed dynamic and nothing else became dynamic.
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
