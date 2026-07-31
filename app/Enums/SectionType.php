<?php

namespace App\Enums;

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
            self::CAROUSEL => false,
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
        };
    }
}

