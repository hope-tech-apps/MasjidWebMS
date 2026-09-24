<?php

/*
|--------------------------------------------------------------------------
| Manara Studio layout presets (docs/manara-studio-w1.md S4)
|--------------------------------------------------------------------------
|
| Step 2's choice: three starter websites per vertical, stored as data, and
| the pages and sections each one would write. App\Support\Studio\LayoutPresets
| reads this file and App\Support\Studio\StarterSite resolves it against a
| client's facts into a StarterPlan. S4 only reads it; the writer is S8.
|
| THE NO-INVENTION RULE (docs/manara-studio.md D4, D8). A starter section may
| contain only:
|
|   1. a fact the client gave, copied verbatim, or its documented tel:/mailto:
|      derivation              {fact: <StarterFacts::KEYS>}
|   2. a label from `labels`   {label: <key>}; `page.{slug}` is the label of
|                              the page the section sits on
|   3. a page path or id, or a seeded form's id
|                              {page_path: <slug>}  => '/<slug>'
|                              {page_id: <slug>}    => pages.id, at write time
|                              {form_template: <config/form_templates.php slug>}
|   4. a value StarterSite::STRUCTURAL allows for that section type and key
|   5. '', null or []
|
| Nothing else is accepted, and StudioLayoutPresetsTest walks every template
| to prove it. Organisation prose (about, mission, vision, donation wording) is
| never copied: SectionContentBinder draws it at serve time from the rows the
| client's own answers created, which is what a `bound` placeholder records.
| Imagery is always null. No history, people, programmes, numbers, verses or
| times are ever written. database/seeders/PagesSeeder.php is the counter-
| example (invented history, a system-chosen verse, Lorem ipsum) and must
| never become the basis of a preset.
|
| Blocks. `type` is a SectionType value; `title` is sections.title, the name an
| admin sees in the page builder; `content` covers EVERY key the type's
| defaultContent() stores, so no editor default ('Photo Gallery', '#2c5f2d')
| is ever filled in for the client. A placeholder is
| {field, kind: text|image|list|bound|review, hint, source?}: an `essential`
| one that is open writes the section inactive; an `optional` one is recorded
| and the section stays active; `review` blocks are always written inactive.
| `omit_if_empty` names a field whose empty value drops the section.
|
| No preset uses stats, impact_stats, carousel, embed, offering, services_list,
| image, text or grid_cards: each needs facts Studio does not collect, or
| numbers.
|
| Labels are `en` only in W1 (R15): a lookup-resolved tenant renders en/ltr
| because the host payload carries no locale, so Arabic section labels would
| sit inside English chrome. Labels are interface nouns with no digits, and
| StudioLayoutPresetsTest pins the table to tests/fixtures/studio-layout-labels.json.
|
| Hints are admin-facing sentences and are never published.
|
| `theme_layout` is written to the new org's theme_settings.tokens.layout in
| S8. The renderer already draws both header variants and both footer
| variants from theme.tokens.layout (renderer app/components/layout/Header.vue,
| Footer.vue), so three visibly different sites come only from what it already
| renders.
|
*/

return [

    'version' => 1,

    'labels' => [
        'en' => [
            'page.home' => 'Home',
            'page.about' => 'About',
            'page.contact' => 'Contact',
            'page.donate' => 'Donate',
            'page.events' => 'Events',
            'page.announcements' => 'Announcements',
            'page.gallery' => 'Gallery',
            'page.programs' => 'Programs',
            'page.staff' => 'Staff',
            'page.admissions' => 'Admissions',
            'page.services' => 'Services',
            'page.team' => 'Team',

            'heading.prayer_times' => 'Prayer Times',
            'heading.about' => 'About Us',
            'heading.mission_vision' => 'Mission and Vision',
            'heading.contact' => 'Contact Us',
            'heading.events' => 'Events',
            'heading.announcements' => 'Announcements',
            'heading.gallery' => 'Gallery',
            'heading.connect' => 'Connect',
            'heading.programs' => 'Programs',
            'heading.staff' => 'Staff',
            'heading.admissions' => 'Admissions',
            'heading.services' => 'Services',
            'heading.team' => 'Team',

            'button.read_more' => 'Read More',
            'button.contact' => 'Contact Us',
            'button.apply' => 'Apply',
            'button.donate' => 'Donate',

            'link.call' => 'Call',
            'link.email' => 'Email',
            'link.facebook' => 'Facebook',
            'link.instagram' => 'Instagram',
            'link.youtube' => 'YouTube',
            'link.whatsapp' => 'WhatsApp',
        ],
    ],

    'hints' => [
        'about_text' => 'Add the organisation\'s About text on the About Us screen. This section shows it once it is there.',
        'about_image' => 'Upload a photograph the organisation sent, if it sent one.',
        'mission_vision' => 'Add the organisation\'s mission or vision on the About Us screen. This section shows them once they are there.',
        'donation_link' => 'Add the organisation\'s donation link on the Donation screen. This section shows it once it is there.',
        'programs' => 'List the programs the school offers, in its own words.',
        'staff' => 'Add the staff the school has agreed to publish.',
        'tuition' => 'Enter the tuition and fees the school publishes.',
        'admissions_form_review' => 'Check the admissions form, then switch this section on to publish it.',
        'services' => 'List the services the organisation offers, and who qualifies, in its own words.',
        'team' => 'Add the people the organisation has agreed to publish.',
        'linked_page' => 'This section\'s button opens a page that is not published yet. Publish that page first.',
    ],

    'blocks' => [

        // The home page's banner: the organisation's own name and description.
        // The logo comes from /settings and is not copied (logo_url '').
        'hero' => [
            'type' => 'page_title',
            'title' => ['label' => 'page.home'],
            'content' => [
                'layout' => 'hero',
                'title' => ['fact' => 'name'],
                'subtitle' => ['fact' => 'description'],
                'button_text' => ['label' => 'button.contact'],
                'button_link' => ['page_path' => 'contact'],
                'logo_url' => '',
                'eyebrow' => '',
                'title_accent' => '',
                'background_image_url' => null,
            ],
        ],

        // Every page after home opens with this, titled with that page's label.
        'header' => [
            'type' => 'page_title',
            'title' => ['label' => 'page.{slug}'],
            'content' => [
                'layout' => 'hero_compact',
                'title' => ['label' => 'page.{slug}'],
                'subtitle' => '',
                'background_image_url' => null,
            ],
        ],

        'prayer' => [
            'type' => 'prayer_times',
            'title' => ['label' => 'heading.prayer_times'],
            'content' => [
                'title' => ['label' => 'heading.prayer_times'],
                'subtitle' => '',
                'image_url' => null,
            ],
        ],

        'about_teaser' => [
            'type' => 'about_us',
            'title' => ['label' => 'heading.about'],
            'content' => [
                'title' => ['label' => 'heading.about'],
                'subtitle' => '',
                'text' => '',
                'image_url' => null,
                'button_text' => ['label' => 'button.read_more'],
            ],
            'essential' => [
                ['field' => 'text', 'kind' => 'bound', 'source' => 'masjid_about.about', 'hint' => 'about_text'],
            ],
            'optional' => [
                ['field' => 'image_url', 'kind' => 'image', 'hint' => 'about_image'],
            ],
        ],

        'about' => [
            'type' => 'about_us',
            'title' => ['label' => 'heading.about'],
            'content' => [
                'title' => ['label' => 'heading.about'],
                'subtitle' => '',
                'text' => '',
                'image_url' => null,
                'button_text' => '',
            ],
            'essential' => [
                ['field' => 'text', 'kind' => 'bound', 'source' => 'masjid_about.about', 'hint' => 'about_text'],
            ],
            'optional' => [
                ['field' => 'image_url', 'kind' => 'image', 'hint' => 'about_image'],
            ],
        ],

        'mission' => [
            'type' => 'mission_vision',
            'title' => ['label' => 'heading.mission_vision'],
            'content' => [
                'heading' => ['label' => 'heading.mission_vision'],
                'items' => [],
                'layout' => 'side_by_side',
            ],
            'essential' => [
                ['field' => 'items', 'kind' => 'bound', 'source' => 'masjid_about.mission_vision', 'hint' => 'mission_vision'],
            ],
        ],

        'contact_short' => [
            'type' => 'contact_form',
            'title' => ['label' => 'heading.contact'],
            'content' => [
                'title' => ['label' => 'heading.contact'],
                'subtitle' => '',
                'button_text' => '',
                'show_map' => false,
            ],
        ],

        'contact_full' => [
            'type' => 'contact_form',
            'title' => ['label' => 'heading.contact'],
            'content' => [
                'title' => ['label' => 'heading.contact'],
                'subtitle' => '',
                'button_text' => '',
                'show_map' => true,
            ],
        ],

        // Its title, message and link are bound from the DonationLink row.
        'donation' => [
            'type' => 'donation',
            'title' => ['label' => 'page.donate'],
            'content' => [
                'title' => '',
                'subtitle' => '',
                'image_url' => null,
                'button_text' => ['label' => 'button.donate'],
            ],
            'essential' => [
                ['field' => 'link', 'kind' => 'bound', 'source' => 'donation_link.link', 'hint' => 'donation_link'],
            ],
        ],

        'announcements' => [
            'type' => 'announcements_list',
            'title' => ['label' => 'heading.announcements'],
            'content' => [
                'title' => ['label' => 'heading.announcements'],
                'subtitle' => '',
                'button_text' => '',
            ],
        ],

        'events' => [
            'type' => 'events',
            'title' => ['label' => 'heading.events'],
            'content' => [
                'heading' => ['label' => 'heading.events'],
                'description' => '',
                'items_per_page' => 10,
            ],
        ],

        'gallery' => [
            'type' => 'gallery',
            'title' => ['label' => 'heading.gallery'],
            'content' => [
                'heading' => ['label' => 'heading.gallery'],
                'description' => '',
                'layout' => 'masonry',
                'items_per_page' => 12,
                'columns' => 4,
                'enable_lightbox' => true,
            ],
        ],

        // One button per contact fact the client gave; an item whose fact is
        // empty is dropped, and with no items the section is not written.
        'connect' => [
            'type' => 'link_list',
            'title' => ['label' => 'heading.connect'],
            'content' => [
                'heading' => ['label' => 'heading.connect'],
                'description' => '',
                'links' => [
                    ['when' => 'phone', 'label' => ['label' => 'link.call'], 'url' => ['fact' => 'phone_tel'], 'icon' => 'phone', 'style' => 'outline'],
                    ['when' => 'email', 'label' => ['label' => 'link.email'], 'url' => ['fact' => 'email_mailto'], 'icon' => 'email', 'style' => 'outline'],
                    ['when' => 'facebook_url', 'label' => ['label' => 'link.facebook'], 'url' => ['fact' => 'facebook_url'], 'icon' => 'external', 'style' => 'outline'],
                    ['when' => 'instagram_url', 'label' => ['label' => 'link.instagram'], 'url' => ['fact' => 'instagram_url'], 'icon' => 'external', 'style' => 'outline'],
                    ['when' => 'youtube_url', 'label' => ['label' => 'link.youtube'], 'url' => ['fact' => 'youtube_url'], 'icon' => 'youtube', 'style' => 'outline'],
                    ['when' => 'whatsapp_url', 'label' => ['label' => 'link.whatsapp'], 'url' => ['fact' => 'whatsapp_url'], 'icon' => 'whatsapp', 'style' => 'outline'],
                ],
                'layout' => 'inline',
                'background_color' => '',
            ],
            'omit_if_empty' => 'links',
        ],

        'cta_admissions' => [
            'type' => 'cta',
            'title' => ['label' => 'heading.admissions'],
            'content' => [
                'heading' => ['label' => 'heading.admissions'],
                'description' => '',
                'button_text' => ['label' => 'button.apply'],
                'button_link' => ['page_path' => 'admissions'],
                'button_style' => 'primary',
                'layout' => 'gradient',
                'background_image_url' => null,
                'background_color' => '',
            ],
        ],

        'cta_contact' => [
            'type' => 'cta',
            'title' => ['label' => 'heading.contact'],
            'content' => [
                'heading' => ['label' => 'heading.contact'],
                'description' => '',
                'button_text' => ['label' => 'button.contact'],
                'button_link' => ['page_path' => 'contact'],
                'button_style' => 'primary',
                'layout' => 'gradient',
                'background_image_url' => null,
                'background_color' => '',
            ],
        ],

        'programs' => [
            'type' => 'programs',
            'title' => ['label' => 'heading.programs'],
            'content' => [
                'heading' => ['label' => 'heading.programs'],
                'description' => '',
                'programs' => [],
                'layout' => 'cards',
                'columns' => 3,
                'background_color' => '',
            ],
            'essential' => [
                ['field' => 'programs', 'kind' => 'list', 'hint' => 'programs'],
            ],
        ],

        'staff' => [
            'type' => 'staff_directory',
            'title' => ['label' => 'heading.staff'],
            'content' => [
                'heading' => ['label' => 'heading.staff'],
                'description' => '',
                'members' => [],
                'layout' => 'grid',
                'columns' => 3,
                'show_contact' => false,
                'background_color' => '',
            ],
            'essential' => [
                ['field' => 'members', 'kind' => 'list', 'hint' => 'staff'],
            ],
        ],

        'team' => [
            'type' => 'providers_directory',
            'title' => ['label' => 'heading.team'],
            'content' => [
                'heading' => ['label' => 'heading.team'],
                'description' => '',
                'providers' => [],
                'layout' => 'grid',
                'columns' => 3,
                'background_color' => '',
            ],
            'essential' => [
                ['field' => 'providers', 'kind' => 'list', 'hint' => 'team'],
            ],
        ],

        'services' => [
            'type' => 'services_eligibility',
            'title' => ['label' => 'heading.services'],
            'content' => [
                'heading' => ['label' => 'heading.services'],
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
                'background_color' => '',
            ],
            'essential' => [
                ['field' => 'services', 'kind' => 'list', 'hint' => 'services'],
            ],
        ],

        // A price list is the school's to write; every field starts empty.
        'tuition' => [
            'type' => 'admissions_tuition',
            'title' => ['label' => 'heading.admissions'],
            'content' => [
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
                'background_color' => '',
            ],
            'essential' => [
                ['field' => 'tiers', 'kind' => 'list', 'hint' => 'tuition'],
            ],
        ],

        // The school's seeded Admissions Interest form (config/form_templates.php),
        // placed but never published until someone has read it.
        'admissions_form' => [
            'type' => 'form',
            'title' => ['label' => 'page.admissions'],
            'content' => [
                'form_id' => ['form_template' => 'admissions-interest'],
                'title' => '',
                'intro' => '',
            ],
            'review' => true,
            'essential' => [
                ['field' => 'form_id', 'kind' => 'review', 'hint' => 'admissions_form_review'],
            ],
        ],

    ],

    // Pages in menu order. Each page after home is written as [header] plus
    // the blocks listed. `show_in_menu` defaults to true, `show_as_button` to
    // false.
    'presets' => [

        'masjid.essentials' => [
            'label' => 'Essentials',
            'summary' => 'Home, About and Contact: the smallest complete site.',
            'theme_layout' => ['header' => 'default', 'footer' => 'default'],
            'pages' => [
                ['slug' => 'home', 'blocks' => ['hero', 'prayer', 'about_teaser', 'contact_short']],
                ['slug' => 'about', 'blocks' => ['about', 'mission']],
                ['slug' => 'contact', 'blocks' => ['contact_full']],
            ],
        ],

        // Burlington's own page set (database/seeders/PagesSeeder.php:236-316)
        // without Services, for which Studio has no facts, and without any of
        // that seeder's invented text.
        'masjid.classic' => [
            'label' => 'Classic',
            'summary' => 'Prayer times, announcements, events, a gallery and a Donate button, with a column footer.',
            'theme_layout' => ['header' => 'default', 'footer' => 'columns'],
            'pages' => [
                ['slug' => 'home', 'blocks' => ['hero', 'prayer', 'announcements', 'about_teaser', 'donation', 'contact_short']],
                ['slug' => 'about', 'blocks' => ['about', 'mission']],
                ['slug' => 'announcements', 'blocks' => ['announcements']],
                ['slug' => 'events', 'blocks' => ['events']],
                ['slug' => 'gallery', 'blocks' => ['gallery']],
                ['slug' => 'donate', 'blocks' => ['donation'], 'show_in_menu' => false, 'show_as_button' => true],
                ['slug' => 'contact', 'blocks' => ['contact_full']],
            ],
        ],

        'masjid.gathering' => [
            'label' => 'Gathering',
            'summary' => 'Leads with events and announcements under an overlay header.',
            'theme_layout' => ['header' => 'overlay', 'footer' => 'columns'],
            'pages' => [
                ['slug' => 'home', 'blocks' => ['hero', 'prayer', 'events', 'announcements', 'connect']],
                ['slug' => 'events', 'blocks' => ['events']],
                ['slug' => 'announcements', 'blocks' => ['announcements']],
                ['slug' => 'gallery', 'blocks' => ['gallery']],
                ['slug' => 'about', 'blocks' => ['about', 'mission']],
                ['slug' => 'contact', 'blocks' => ['contact_full', 'connect']],
            ],
        ],

        'school.essentials' => [
            'label' => 'Essentials',
            'summary' => 'Home, About, Admissions and Contact.',
            'theme_layout' => ['header' => 'default', 'footer' => 'default'],
            'pages' => [
                ['slug' => 'home', 'blocks' => ['hero', 'about_teaser', 'cta_admissions', 'contact_short']],
                ['slug' => 'about', 'blocks' => ['about', 'mission']],
                ['slug' => 'admissions', 'blocks' => ['tuition', 'admissions_form']],
                ['slug' => 'contact', 'blocks' => ['contact_full']],
            ],
        ],

        'school.prospectus' => [
            'label' => 'Prospectus',
            'summary' => 'Adds Programs and Staff pages for families comparing schools, with a column footer.',
            'theme_layout' => ['header' => 'default', 'footer' => 'columns'],
            'pages' => [
                ['slug' => 'home', 'blocks' => ['hero', 'about_teaser', 'cta_admissions', 'connect']],
                ['slug' => 'about', 'blocks' => ['about', 'mission']],
                ['slug' => 'programs', 'blocks' => ['programs']],
                ['slug' => 'staff', 'blocks' => ['staff']],
                ['slug' => 'admissions', 'blocks' => ['tuition', 'admissions_form']],
                ['slug' => 'contact', 'blocks' => ['contact_full']],
            ],
        ],

        'school.community' => [
            'label' => 'Community',
            'summary' => 'Leads with announcements and events under an overlay header.',
            'theme_layout' => ['header' => 'overlay', 'footer' => 'columns'],
            'pages' => [
                ['slug' => 'home', 'blocks' => ['hero', 'announcements', 'events', 'cta_admissions', 'contact_short']],
                ['slug' => 'announcements', 'blocks' => ['announcements']],
                ['slug' => 'events', 'blocks' => ['events']],
                ['slug' => 'gallery', 'blocks' => ['gallery']],
                ['slug' => 'about', 'blocks' => ['about', 'mission']],
                ['slug' => 'admissions', 'blocks' => ['tuition', 'admissions_form']],
                ['slug' => 'contact', 'blocks' => ['contact_full']],
            ],
        ],

        'community.essentials' => [
            'label' => 'Essentials',
            'summary' => 'Home, About and Contact: the smallest complete site.',
            'theme_layout' => ['header' => 'default', 'footer' => 'default'],
            'pages' => [
                ['slug' => 'home', 'blocks' => ['hero', 'about_teaser', 'connect']],
                ['slug' => 'about', 'blocks' => ['about', 'mission']],
                ['slug' => 'contact', 'blocks' => ['contact_full']],
            ],
        ],

        'community.services' => [
            'label' => 'Services',
            'summary' => 'Services and Team pages, with a call to get in touch and a column footer.',
            'theme_layout' => ['header' => 'default', 'footer' => 'columns'],
            'pages' => [
                ['slug' => 'home', 'blocks' => ['hero', 'about_teaser', 'cta_contact']],
                ['slug' => 'services', 'blocks' => ['services']],
                ['slug' => 'team', 'blocks' => ['team']],
                ['slug' => 'about', 'blocks' => ['about', 'mission']],
                ['slug' => 'contact', 'blocks' => ['contact_full']],
            ],
        ],

        'community.gathering' => [
            'label' => 'Gathering',
            'summary' => 'Leads with events and announcements under an overlay header.',
            'theme_layout' => ['header' => 'overlay', 'footer' => 'columns'],
            'pages' => [
                ['slug' => 'home', 'blocks' => ['hero', 'events', 'announcements', 'connect']],
                ['slug' => 'events', 'blocks' => ['events']],
                ['slug' => 'announcements', 'blocks' => ['announcements']],
                ['slug' => 'gallery', 'blocks' => ['gallery']],
                ['slug' => 'about', 'blocks' => ['about', 'mission']],
                ['slug' => 'contact', 'blocks' => ['contact_full']],
            ],
        ],

    ],

    'defaults' => [
        'masjid' => 'masjid.classic',
        'school' => 'school.essentials',
        'community' => 'community.essentials',
    ],

];
