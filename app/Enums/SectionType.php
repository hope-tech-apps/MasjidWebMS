<?php

namespace App\Enums;

/**
 * The page-builder palette.
 *
 * A section type is three things and nothing more: this case, the `content` JSON
 * shape returned by defaultContent(), and a Vue editor keyed off the value in
 * resources/vue-app/components/sections/editors. There is no per-type table, no
 * per-type controller and no registry class — see .claude/rules/section-types.md
 * for the full list of places one change has to land.
 *
 * **The palette is GLOBAL, not per-vertical.** `sectionTypes()` hands every tenant
 * every case, and nothing here or in config/verticals.php filters by `org_type`.
 * The school types below are therefore visible to masjid and community tenants too.
 * That is the status quo being preserved deliberately, not an oversight: gating the
 * palette would be a new mechanism, and a masjid that wants a staff directory or a
 * weekend-school tuition table is a real tenant, not a mistake. If per-vertical
 * offering is ever wanted, add it in ONE place (PageSectionsController@sectionTypes)
 * keyed off `config/verticals.php`, and keep validation ungated so existing rows
 * never stop loading.
 */
enum SectionType: string
{
    case PAGE_TITLE = 'page_title';
    case PRAYER_TIMES = 'prayer_times';
    case TEXT = 'text';
    case ABOUT_US = 'about_us';
    case IMAGE_TEXT_GRID = 'image_text_grid';
    case GRID_CARDS = 'grid_cards';
    case DONATION = 'donation';
    case CONTACT_FORM = 'contact_form';
    case SERVICES_LIST = 'services_list';
    case ANNOUNCEMENTS_LIST = 'announcements_list';
    case GALLERY = 'gallery';
    case EVENTS = 'events';
    case STATS = 'stats';
    case MISSION_VISION = 'mission_vision';
    case CTA = 'cta';
    case FORM = 'form';
    // Layout primitives added for the meccharlotte.org rebuild: a plain decorative
    // image, a row/stack of link buttons (the mailto + website icon strips), and a
    // rotating image carousel.
    case IMAGE = 'image';
    case LINK_LIST = 'link_list';
    case CAROUSEL = 'carousel';
    // A third-party widget framed into a page — the masjid's existing events calendar,
    // a YouTube khutbah, a map. See App\Support\EmbedProviders for why the URL is
    // allowlisted by provider rather than free text.
    case EMBED = 'embed';
    // Manara Schools (T-010). A school's public site has three pages the masjid types
    // cannot express: who teaches here, what is taught, and what it costs to enrol.
    // Modelled on the pages alrazischool.org already publishes. The palette is GLOBAL —
    // these are offered to every tenant, not gated on org_type; see the class docblock
    // and .claude/rules/section-types.md.
    case STAFF_DIRECTORY = 'staff_directory';
    case PROGRAMS = 'programs';
    case ADMISSIONS_TUITION = 'admissions_tuition';

    /**
     * Get all section type values
     */
    public static function getValues(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get section type label
     */
    public function label(): string
    {
        return match ($this) {
            self::PAGE_TITLE => 'Page Title',
            self::PRAYER_TIMES => 'Prayer Times',
            self::TEXT => 'Text Section',
            self::ABOUT_US => 'About Us',
            self::IMAGE_TEXT_GRID => 'Image Text Grid',
            self::GRID_CARDS => 'Grid Cards',
            self::DONATION => 'Donation Section',
            self::CONTACT_FORM => 'Contact Form',
            self::SERVICES_LIST => 'Services List',
            self::ANNOUNCEMENTS_LIST => 'Announcements List',
            self::GALLERY => 'Photo Gallery',
            self::EVENTS => 'Events',
            self::STATS => 'Statistics Section',
            self::MISSION_VISION => 'Mission & Vision',
            self::CTA => 'Call to Action',
            self::FORM => 'Sign-up Form',
            self::IMAGE => 'Image',
            self::LINK_LIST => 'Link Buttons',
            self::CAROUSEL => 'Image Carousel',
            self::EMBED => 'Embedded Widget',
            self::STAFF_DIRECTORY => 'Staff & Faculty Directory',
            self::PROGRAMS => 'Programs & Curriculum',
            self::ADMISSIONS_TUITION => 'Admissions & Tuition',
        };
    }

    /**
     * Get section type description
     */
    public function description(): string
    {
        return match ($this) {
            self::PAGE_TITLE => 'Page header with background image and title',
            self::PRAYER_TIMES => 'Prayer times section with image, title, and subtitle',
            self::TEXT => 'Rich text content section',
            self::ABOUT_US => 'About us section with title, subtitle, text, image, and button',
            self::IMAGE_TEXT_GRID => 'Grid layout with text content, header/footer images, and button with page redirection',
            self::GRID_CARDS => 'Grid of cards with configurable items per row, each card has title, text, and image',
            self::DONATION => 'Donation information with payment button',
            self::CONTACT_FORM => 'Contact us section with optional map',
            self::SERVICES_LIST => 'Display services from API (dynamic data)',
            self::ANNOUNCEMENTS_LIST => 'Display announcements from API (dynamic data)',
            self::GALLERY => 'Photo gallery from API (dynamic data)',
            self::EVENTS => 'Display upcoming events from API (dynamic data)',
            self::STATS => 'Statistics/counters display',
            self::MISSION_VISION => 'Mission and vision cards with icons',
            self::CTA => 'Call to action button section',
            self::FORM => 'A sign-up form — event RSVPs, membership applications, camp registrations. Responses appear under Form Responses.',
            self::IMAGE => 'A single standalone image with optional caption — flyers, posters, decorative banners',
            self::LINK_LIST => 'A row or stack of link buttons — email, phone, website, external registration links',
            self::CAROUSEL => 'A rotating slideshow of images with optional titles, captions and links',
            self::EMBED => 'A widget from another site — your existing events calendar, a YouTube video, a Google Map or Form',
            self::STAFF_DIRECTORY => 'The people who work here — photo, role and department, with contact details only if you choose to publish them',
            self::PROGRAMS => 'Programs or courses of study — grade level, schedule, and what each one covers',
            self::ADMISSIONS_TUITION => 'Tuition rates, additional fees, payment plans, and the steps to enrol',
        };
    }

    /**
     * Check if section type uses external API data
     */
    public function usesExternalData(): bool
    {
        // Exhaustive match with NO default arm, on purpose: adding a case without
        // classifying it here fails loudly instead of silently defaulting to
        // "self-contained". defaultContent() below is exhaustive for the same reason.
        return match ($this) {
            self::SERVICES_LIST,
            self::ANNOUNCEMENTS_LIST,
            self::GALLERY,
            self::EVENTS => true,

            self::PAGE_TITLE,
            self::PRAYER_TIMES,
            self::TEXT,
            self::ABOUT_US,
            self::IMAGE_TEXT_GRID,
            self::GRID_CARDS,
            self::DONATION,
            self::CONTACT_FORM,
            self::STATS,
            self::MISSION_VISION,
            self::CTA,
            self::FORM,
            // Author-supplied content only — nothing is pulled from another table.
            self::IMAGE,
            self::LINK_LIST,
            self::CAROUSEL,
            // The widget fetches its own content from the provider, in the visitor's
            // browser. Nothing is pulled from our tables, so this is false — the flag
            // means "this renderer needs data from another endpoint of OURS".
            self::EMBED => false,
            // The school types are author-supplied too. A staff directory in
            // particular is NOT a view onto `contacts` or `group_memberships` —
            // those are private CRM records about families and congregants, and a
            // public page must never be a window onto them. Publishing a person is
            // an editorial act, so the names on the page are typed into the section.
            self::STAFF_DIRECTORY,
            self::PROGRAMS,
            self::ADMISSIONS_TUITION => false,
        };
    }

    /**
     * Get default content structure for this section type
     */
    public function defaultContent(): array
    {
        return match ($this) {
            self::PAGE_TITLE => [
                'title' => '',
                'background_image_url' => null,
            ],
            self::PRAYER_TIMES => [
                'title' => '',
                'subtitle' => '',
                'image_url' => null,
            ],
            self::TEXT => [
                'heading' => '',
                'content' => '',
                'layout' => 'single_column',
                'background_color' => '#ffffff',
            ],
            self::ABOUT_US => [
                'title' => '',
                'subtitle' => '',
                'text' => '',
                'image_url' => null,
                'button_text' => '',
            ],
            self::IMAGE_TEXT_GRID => [
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
            ],
            self::GRID_CARDS => [
                'items_per_row' => 3,
                'items' => [
                    [
                        'title' => '',
                        'text' => '',
                        'image_url' => null,
                    ],
                ],
            ],
            self::DONATION => [
                'title' => '',
                'subtitle' => '',
                'image_url' => null,
                'button_text' => 'Donate Now',
            ],
            self::CONTACT_FORM => [
                'title' => '',
                'subtitle' => '',
                'button_text' => 'Send Message',
                'show_map' => true,
            ],
            self::SERVICES_LIST => [
                'title' => '',
                'subtitle' => '',
                'button_text' => 'View All',
            ],
            self::ANNOUNCEMENTS_LIST => [
                'title' => '',
                'subtitle' => '',
                'button_text' => 'View All',
            ],
            self::GALLERY => [
                'heading' => 'Photo Gallery',
                'description' => '',
                'layout' => 'masonry',
                'items_per_page' => 12,
                'columns' => 4,
                'enable_lightbox' => true,
            ],
            self::EVENTS => [
                'heading' => 'Upcoming Events',
                'description' => '',
                'items_per_page' => 10,
            ],
            self::STATS => [
                'heading' => 'Our Impact',
                'stats' => [],
                'layout' => 'horizontal',
            ],
            self::MISSION_VISION => [
                'heading' => 'Our Mission & Vision',
                'items' => [],
                'layout' => 'side_by_side',
            ],
            self::CTA => [
                'heading' => '',
                'description' => '',
                'button_text' => 'Get Started',
                'button_link' => '',
                'button_style' => 'primary',
                'background_image_url' => null,
                'background_color' => '#2c5f2d',
            ],
            // The section holds a REFERENCE, never the schema. The form and its
            // responses live in the `forms` / `form_responses` tables so that removing
            // this section from a page does not destroy the submissions collected
            // through it (sections are hard-deleted). See the create_forms_table
            // migration for the full reasoning.
            self::FORM => [
                'form_id' => null,
                'title' => '',
                'intro' => '',
            ],
            // A plain decorative image. `max_width` is a named size the renderer maps to
            // its own layout scale (full-bleed / page container / narrow column) rather
            // than a pixel value, so the same content renders correctly on web and mobile.
            self::IMAGE => [
                'image_url' => null,
                'alt_text' => '',
                'caption' => '',
                'max_width' => 'container', // full | container | narrow
                'background_color' => '#ffffff',
            ],
            // A row/stack of link buttons. Each link is
            // ['label' => '', 'url' => '', 'icon' => '', 'style' => 'primary'].
            // `url` carries mailto:/tel: as readily as https:, which is what the
            // contact strips on meccharlotte.org need.
            self::LINK_LIST => [
                'heading' => '',
                'description' => '',
                'links' => [],
                'layout' => 'stack', // stack | inline | grid
                'background_color' => '#ffffff',
            ],
            // A rotating slideshow. Each slide is
            // ['image_url' => '', 'title' => '', 'caption' => '', 'link_url' => '', 'link_text' => ''].
            // `height` is a named size for the same reason `max_width` is above.
            self::CAROUSEL => [
                'slides' => [],
                'autoplay' => true,
                'interval_ms' => 6000,
                'show_arrows' => true,
                'show_dots' => true,
                'height' => 'medium', // short | medium | tall
            ],
            // A framed third-party widget. `provider` decides which hosts `url` may
            // belong to and which sandbox the frame gets — see App\Support\EmbedProviders.
            //
            // `aspect` wins over `height` when both are set, because a ratio survives a
            // phone screen and a fixed pixel height does not. Leaving both unset falls
            // back to the provider's own default ratio (16:9 for video, 4:3 for a map).
            //
            // `fallback_text` is the label on the "open in a new tab" link that renders
            // BESIDE the frame, always. Providers block framing without warning and
            // without an error event; a blank rectangle is the failure mode, so the
            // content stays reachable by a plain link no matter what the frame does.
            self::EMBED => [
                'provider' => null,
                'url' => '',
                'title' => '',
                'caption' => '',
                'height' => null,
                'aspect' => null,
                'fallback_text' => '',
                'background_color' => '#ffffff',
            ],
            // ---------------------------------------------------------------
            // Manara Schools (T-010)
            // ---------------------------------------------------------------
            // The people who work here. Each member is
            //   ['name' => '', 'role' => '', 'department' => '', 'credentials' => '',
            //    'bio' => '', 'email' => '', 'phone' => '', 'photo_url' => null]
            //
            // ONE flat list, with `department` as a free grouping label the renderer
            // buckets by ("Administration", "Lower School", "Specialists"), rather
            // than a nested departments[].members[] tree. That is not a simplification
            // for its own sake: the section image pipeline matches a SINGLE array
            // index (`members.*.photo_url` → `members_0_photo_url`), so a second level
            // of nesting would silently drop every uploaded photo. See
            // SectionsController::handleArrayImageUploads.
            //
            // `show_contact` defaults to FALSE. Publishing a teacher's email address
            // to the open web is a decision someone has to make on purpose; the
            // default must not make it for them.
            self::STAFF_DIRECTORY => [
                'heading' => '',
                'description' => '',
                'members' => [],
                'layout' => 'grid', // grid | list
                'columns' => 3,
                'show_contact' => false,
                'background_color' => '#ffffff',
            ],
            // Courses of study. Each program is
            //   ['name' => '', 'level' => '', 'schedule' => '', 'summary' => '',
            //    'highlights' => [], 'image_url' => null, 'link_url' => '', 'link_text' => '']
            //
            // `level` (the grade or age band) and `schedule` (the meeting pattern) are
            // free text on purpose — "Pre-K", "Grades 1–2", "Ages 4–6" and "Mon–Fri,
            // 8:00 AM–3:00 PM" are all things a real school writes, and no enum
            // covers them across schools. `highlights` is a plain list of strings:
            // the curriculum bullets under each program.
            self::PROGRAMS => [
                'heading' => '',
                'description' => '',
                'programs' => [],
                'layout' => 'cards', // cards | list | accordion
                'columns' => 3,
                'background_color' => '#ffffff',
            ],
            // The tuition and fee schedule plus how to apply.
            //
            //   tiers[]         ['name' => '', 'badge' => '', 'amount' => '',
            //                    'period' => '', 'note' => '', 'includes' => []]
            //   fees[]          ['label' => '', 'amount' => '', 'note' => '']
            //   payment_plans[] ['label' => '', 'detail' => '']
            //   steps[]         ['title' => '', 'description' => '']
            //
            // Every money value is DISPLAY TEXT, never a number. A real schedule mixes
            // "$8,000", "Included", "Contact us" and "$250 discount/child" in the same
            // table, and nothing in this app charges from these values — tuition
            // billing does not exist here, and the Stripe path is for donations only.
            // A string says "this is what the page says"; a decimal would imply a
            // machine reads it. `stats.value` makes the same call for the same reason.
            //
            // This section is the PRICE LIST, not the application. Collecting an
            // applicant's details is the `form` section type over the forms tables
            // (T-011) — `button_page_id` is how the two are joined: it resolves to
            // `button_page_url` on read (Section::getContentAttribute), so the apply
            // button points at a registration page built in the same builder without
            // anyone typing a URL that can rot.
            self::ADMISSIONS_TUITION => [
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
            ],
        };
    }
}

