<?php

/*
|--------------------------------------------------------------------------
| Organisation capabilities — layer 1 of the access model
|--------------------------------------------------------------------------
|
| What an ORGANISATION has. A SuperAdmin decides. The organisation's own
| administrators inherit all of it — "everything this organisation has" — and
| a scoped staff login reaches only the part its access names (layer 2: the
| Team screen, App\Http\Controllers\AdminDashboard\TeamController).
|
| Every entry carries a `kind`, a `group` (config/capability_groups.php), a
| `label` and a plain-language `description`. Two kinds:
|
|   'grant'    Opt-in. Something an organisation reaches only once a SuperAdmin
|              decides it should (or its org_type default says so). Unknown or
|              missing means NOT granted — Masjid::hasCapability() fails closed.
|              Two grants are 'column'-backed: an existing masjids boolean that
|              already has its own gate middleware and its own SuperAdmin
|              toggle (crm_enabled, assistant_enabled). They are listed so every
|              capability reads from one catalogue; their storage, gates and
|              endpoints are unchanged.
|
|   'module'   A screen an organisation has until a SuperAdmin switches it OFF
|              for one organisation (BISS has no website, so it has no use for
|              Announcements or Events) — or, for the five `surface` => 'app'
|              worship modules, an entry the mobile app's menu lists until a
|              SuperAdmin switches it off. Most modules are offered to every org
|              type. The masjid screens (Splash, Services, Donation link,
|              Giving, Properties & Rent) are offered to masjids only (owner,
|              2026-09-14), and so are the five worship modules, which is the
|              rule config/verticals.php already applies to those app feature
|              keys. A SuperAdmin can switch one of them ON for a school or
|              community organisation. Masjid::moduleIsOff() is the only reader
|              and it FAILS
|              OPEN: a key the loaded config does not know as a module reads as
|              its org type's default, never as a decision, so a stale config
|              cache during a deploy can never take a screen away. Module keys
|              and their defaults are also held in Masjid::MODULE_KEYS and
|              Masjid::MODULE_DEFAULTS, in this file's order.
|
| A module needs one of three placements, or the switch panel cannot place its
| row:
|
|   a sidebar item     `requiresModule` in the SPA's dashboardAsideMenuItems.ts.
|
|   `where`            The module lives inside another screen; the string names
|                      the place without the screen noun, and the SPA prints
|                      "{Details menu title} › {where}" (prayer_times).
|
|   `surface` => 'app' The module has no admin screen at all. It decides whether
|                      the MOBILE APP's menu lists that entry, and the SPA
|                      prints "Where: Mobile app menu". The five worship
|                      modules are the only ones, one per legacy Mobile App
|                      Features id 1-5.
|
| Non-column entries are stored in masjids.capability_overrides and enforced by
| the `capability:<key>` middleware (EnsureOrgCapability). 'defaults' is what an
| organisation has until a SuperAdmin overrides it, per org_type — chosen to
| reproduce exactly what each vertical could reach before this catalogue
| existed, so shipping it moves nobody. Every non-column entry names all three
| org types (CapabilityGateTest): a missing one reads as false.
|
| A module gates the ADMIN side: the screen, its editing API and the intake a
| switched-off screen could no longer read. A module ALSO decides whether the
| app menu (GET /api/mobile/masjids/{id}/menu) lists its entry — that is the
| whole job of a `surface` => 'app' module, and the reason an app-only module is
| never named in the staff sentences about switched-off screens. Mobile and
| public DATA endpoints still never follow a module: what families already see
| on the website, and every screen an app can already open, stays.
|
| SuperAdmins are the platform operator, not an organisation's staff: no
| `capability:` gate ever locks them out (they set organisations up).
|
| `listed_when_off` => false keeps a grant off the Team & Access chips while it
| is off, so adding a grant does not add an "off" chip to every organisation.
|
*/

return [

    // ------------------------------------------------------------------
    // Grants
    // ------------------------------------------------------------------

    'web_pages' => [
        'kind' => 'grant',
        'group' => 'content',
        'label' => 'Website pages',
        'description' => 'Lets this organisation\'s own administrators open Web Pages Management. A SuperAdmin can open it whenever Web Pages Management is switched on.',
        // Web Pages Management was SuperAdmin-only in the menu for every
        // organisation, so no organisation has it until it is switched on.
        'defaults' => ['masjid' => false, 'school' => false, 'community' => false],
    ],

    'jummah_lunch' => [
        'kind' => 'grant',
        'group' => 'registration_money',
        'label' => 'Friday lunch ordering',
        'description' => 'Sell Jummah lunch online, run the order board, and give volunteers a lunch-only login.',
        // The menu offered it to masjids only (requiresOrgTypes ['masjid']).
        'defaults' => ['masjid' => true, 'school' => false, 'community' => false],
    ],

    'school_calendar' => [
        'kind' => 'grant',
        'group' => 'school',
        'label' => 'School calendar',
        'description' => 'School days, no-school days, and the school-day choices on registration forms.',
        // New, so nobody reached it before: OFF for every organisation, schools
        // included, and switched on per organisation by a SuperAdmin (BISS
        // first). A school default of true would have handed Al-Razi a new
        // screen nobody decided to give it. DECISIONS.md 2026-09-14.
        'defaults' => ['masjid' => false, 'school' => false, 'community' => false],
    ],

    'crm' => [
        'kind' => 'grant',
        'group' => 'registration_money',
        'label' => 'Members, classes & giving',
        'description' => 'Member directory, groups and classes, teachers, programs, donations and funds.',
        'column' => 'crm_enabled',
    ],

    'assistant' => [
        'kind' => 'grant',
        'group' => 'tools',
        'label' => 'Manara Assistant',
        'description' => 'The AI assistant for admins, and form insights.',
        'column' => 'assistant_enabled',
    ],

    'form_editing' => [
        'kind' => 'grant',
        'group' => 'registration_money',
        'label' => 'Edit sign-up forms',
        'description' => 'Lets this organisation\'s administrators create and edit sign-up forms from Form Responses, without Web Pages Management.',
        // Before this grant, an organisation's admins reached the form builder
        // only inside Web Pages Management, so nobody has it until it is
        // switched on. The forms write API accepts web_pages OR this.
        'defaults' => ['masjid' => false, 'school' => false, 'community' => false],
        'listed_when_off' => false,
    ],

    // ------------------------------------------------------------------
    // Modules — default ON; labels are the sidebar titles
    // ------------------------------------------------------------------

    'website' => [
        'kind' => 'module',
        'group' => 'content',
        'label' => 'Web Pages Management',
        'description' => 'The screen where the website\'s pages and sections are built. Switch it off for an organisation with no website: nobody sees the screen, a SuperAdmin only from the switched-off list. The public website keeps serving.',
        'defaults' => ['masjid' => true, 'school' => true, 'community' => true],
    ],

    'announcements' => [
        'kind' => 'module',
        'group' => 'content',
        'label' => 'Announcements',
        'description' => 'Write the announcements shown on the website and in the app, including the Announcements channel in Broadcasts.',
        'defaults' => ['masjid' => true, 'school' => true, 'community' => true],
    ],

    'events' => [
        'kind' => 'module',
        'group' => 'content',
        'label' => 'Events',
        'description' => 'Add and edit the events listed on the website and in the app. Not the school calendar.',
        'defaults' => ['masjid' => true, 'school' => true, 'community' => true],
    ],

    'about_us' => [
        'kind' => 'module',
        'group' => 'content',
        'label' => 'About Us',
        'description' => 'Edit the About Us, mission and vision text shown on the website and in the app.',
        'defaults' => ['masjid' => true, 'school' => true, 'community' => true],
    ],

    'gallery' => [
        'kind' => 'module',
        'group' => 'content',
        'label' => 'Photo Gallery',
        'description' => 'Upload and remove the photos shown in the website and app gallery.',
        'defaults' => ['masjid' => true, 'school' => true, 'community' => true],
    ],

    'push_notifications' => [
        'kind' => 'module',
        'group' => 'communication',
        'label' => 'Notifications',
        'description' => 'Send push notifications to people who have the mobile app, including the Push channel in Broadcasts. Does nothing without an app. Scheduled prayer reminders follow Prayer times, not this switch.',
        'defaults' => ['masjid' => true, 'school' => true, 'community' => true],
    ],

    'contact_requests' => [
        'kind' => 'module',
        'group' => 'communication',
        'label' => 'Contact Requests',
        'description' => 'Read and answer messages sent from the website and app contact forms, and set the contact reasons. Switched off, those contact forms refuse new messages.',
        'defaults' => ['masjid' => true, 'school' => true, 'community' => true],
    ],

    'programs' => [
        'kind' => 'module',
        'group' => 'registration_money',
        'label' => 'Programs',
        'description' => 'Programs with fee plans, places and registrations. Also needs Members, classes & giving. Switched off, public program sign-up closes.',
        'defaults' => ['masjid' => true, 'school' => true, 'community' => true],
    ],

    'zakat' => [
        'kind' => 'module',
        'group' => 'registration_money',
        'label' => 'Zakat Calculator',
        'description' => 'Set the gold and silver prices the zakat calculator uses. The public calculator keeps answering from the last price you set.',
        'defaults' => ['masjid' => true, 'school' => true, 'community' => true],
    ],

    'broadcasts' => [
        'kind' => 'module',
        'group' => 'communication',
        'label' => 'Broadcasts',
        'description' => 'Compose one message and send it to announcements, push, the TV board and email at once.',
        'defaults' => ['masjid' => true, 'school' => true, 'community' => true],
    ],

    'flyer_studio' => [
        'kind' => 'module',
        'group' => 'tools',
        'label' => 'Flyer Studio',
        'description' => 'Design flyers from ready-made templates. The Jummah lunch flyer upload is part of Friday lunch ordering, not this.',
        'defaults' => ['masjid' => true, 'school' => true, 'community' => true],
    ],

    'impact_report' => [
        'kind' => 'module',
        'group' => 'tools',
        'label' => 'Impact Report',
        'description' => 'The numbers a grant application or funder report asks for, computed from your own records. Also needs Members, classes & giving.',
        'defaults' => ['masjid' => true, 'school' => true, 'community' => true],
    ],

    // ------------------------------------------------------------------
    // Prayer times and the masjid screens (owner, 2026-09-14). Splash,
    // Services, Donation link, Giving and Properties & Rent are offered to
    // masjids only: a school or community organisation has one once a
    // SuperAdmin switches it on.
    // ------------------------------------------------------------------

    'prayer_times' => [
        'kind' => 'module',
        'group' => 'prayer',
        'label' => 'Prayer times',
        'description' => 'Set how prayer times are calculated, the iqama times and Jumu\'ah. Switched off, the website, apps and TV board keep showing the last saved times. Manara stops its backup reminders to phones that have not opened the app for 5 days, and its daily background refresh. Android phones re-arm their own adhan and iqama alerts every day; an iPhone re-arms them when the app is opened, so one left unopened for about 6 days can stop alerting, and iqama times saved while this is off reach iPhones only when the app is next opened.',
        // No sidebar item of its own: three tabs on the Details screen.
        'where' => 'Prayer Calculation, Iqama Settings and Jumaa Settings tabs',
        'defaults' => ['masjid' => true, 'school' => true, 'community' => true],
    ],

    'splash' => [
        'kind' => 'module',
        'group' => 'content',
        'label' => 'Splash',
        'description' => 'The pop-up shown when the website or app opens. Switched off, admins cannot add or change one; a splash already live keeps showing until its end date.',
        'defaults' => ['masjid' => true, 'school' => false, 'community' => false],
    ],

    'services' => [
        'kind' => 'module',
        'group' => 'content',
        'label' => 'Services',
        'description' => 'Add and edit the services listed on the website and in the app. Switched off, the list already published stays, and Broadcasts, Friday lunch and About Us can still use it.',
        'defaults' => ['masjid' => true, 'school' => false, 'community' => false],
    ],

    'donation_link' => [
        'kind' => 'module',
        'group' => 'registration_money',
        'label' => 'Donation link',
        'description' => 'The donation web page the website Donate section, the TV board QR code and the app open. Switched off, admins cannot change it; the link already set keeps showing.',
        'defaults' => ['masjid' => true, 'school' => false, 'community' => false],
    ],

    'giving' => [
        'kind' => 'module',
        'group' => 'registration_money',
        'label' => 'Giving',
        'description' => 'Gifts, funds, monthly giving and year-end statements. Also needs Members, classes & giving. Stripe setup is not part of this switch. Switched off, the giving screens are hidden; a member\'s record still shows their giving history, and the Impact Report still totals gifts for admins who can view donations. The app stops taking new gifts. Gifts already paid are still recorded and receipted.',
        'defaults' => ['masjid' => true, 'school' => false, 'community' => false],
    ],

    'properties' => [
        'kind' => 'module',
        'group' => 'registration_money',
        'label' => 'Properties & Rent',
        'description' => 'Rental properties and the rent recorded against them. Also needs Members, classes & giving. Rent is typed in by hand and never charged through Stripe.',
        'defaults' => ['masjid' => true, 'school' => false, 'community' => false],
    ],

    'appointment_requests' => [
        'kind' => 'module',
        'group' => 'communication',
        'label' => 'Appointment Requests',
        'description' => 'The inbox for appointment requests sent from the website form. Also needs Members, classes & giving. Switched off, the form refuses new requests.',
        'defaults' => ['masjid' => true, 'school' => true, 'community' => true],
    ],

    // ------------------------------------------------------------------
    // The app-only worship modules (`surface` => 'app'). No admin screen, no
    // sidebar item, no editing API: each one decides whether the mobile app's
    // menu lists that entry, one per legacy Mobile App Features id 1-5, so the
    // app menu names every worship feature separately (owner: the client list
    // is user-facing features only). Offered to masjids only, which is the rule
    // config/verticals.php already applies to these keys.
    //
    // They ride the `prayer` group rather than a group of their own: an extra
    // card would be a second place to look for one organisation's worship
    // switches.
    // ------------------------------------------------------------------

    'quran' => [
        'kind' => 'module',
        'group' => 'prayer',
        'label' => 'Qur’an',
        'description' => 'The Qur’an reader in the mobile app menu. Switched off, the app menu stops listing it. There is no admin screen, so nothing changes in the admin.',
        'surface' => 'app',
        'defaults' => ['masjid' => true, 'school' => false, 'community' => false],
    ],

    'hadith' => [
        'kind' => 'module',
        'group' => 'prayer',
        'label' => 'Hadith',
        'description' => 'The hadith collection in the mobile app menu. Switched off, the app menu stops listing it. There is no admin screen, so nothing changes in the admin.',
        'surface' => 'app',
        'defaults' => ['masjid' => true, 'school' => false, 'community' => false],
    ],

    'adhkar' => [
        'kind' => 'module',
        'group' => 'prayer',
        'label' => 'Adhkar',
        'description' => 'The morning and evening adhkar in the mobile app menu. Switched off, the app menu stops listing it. There is no admin screen, so nothing changes in the admin.',
        'surface' => 'app',
        'defaults' => ['masjid' => true, 'school' => false, 'community' => false],
    ],

    'qibla' => [
        'kind' => 'module',
        'group' => 'prayer',
        'label' => 'Qibla',
        'description' => 'The qibla compass in the mobile app menu. Switched off, the app menu stops listing it. There is no admin screen, so nothing changes in the admin.',
        'surface' => 'app',
        'defaults' => ['masjid' => true, 'school' => false, 'community' => false],
    ],

    'tasbih' => [
        'kind' => 'module',
        'group' => 'prayer',
        'label' => 'Tasbih',
        'description' => 'The tasbih counter in the mobile app menu. Switched off, the app menu stops listing it. There is no admin screen, so nothing changes in the admin.',
        'surface' => 'app',
        'defaults' => ['masjid' => true, 'school' => false, 'community' => false],
    ],

];
