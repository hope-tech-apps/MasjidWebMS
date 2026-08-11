<?php

/*
|--------------------------------------------------------------------------
| Manara verticals
|--------------------------------------------------------------------------
|
| Manara runs three verticals on ONE core (DECISIONS.md, 2026-08-10):
| Masjids, Schools, Community. A vertical is CONFIGURATION, not a fork — it is
| (a) a default feature bundle, (b) a terminology pack for admin-facing labels,
| and (c) which page-builder section types are offered.
|
| `feature_keys` are seeded onto a tenant at provisioning time and remain
| individually togglable afterwards (`mobile_app_features` pivot + the existing
| per-tenant gates). They are DEFAULTS, never a runtime authorization check —
| the pivot's `is_available` stays the single source of truth for what a tenant
| actually has. Keys here MUST exist in `mobile_app_features.key`; the set below
| is the full seeded list as of 2026-08-10:
|   about_us, adhkar, announcements, contact_us, donate, gallery, hadith,
|   qibla, quran, services, tasbih
|
| Islam-specific features (adhkar, hadith, qibla, quran, tasbih) belong to the
| masjid bundle only. Everything else is org-generic and shared by all three.
|
*/

return [

    'masjid' => [
        'label' => 'Masjid',
        'plural' => 'Masjids',
        // Universal + the Islamic worship modules.
        'feature_keys' => [
            'about_us', 'announcements', 'contact_us', 'donate', 'gallery', 'services',
            'adhkar', 'hadith', 'qibla', 'quran', 'tasbih',
        ],
        'terminology' => [
            'organization' => 'Masjid',
            'members' => 'Congregants',
            'staff' => 'Imams & Staff',
            'programs' => 'Programs',
            'groups' => 'Halaqat',
            'giving' => 'Donations',
        ],
    ],

    'school' => [
        'label' => 'School',
        'plural' => 'Schools',
        // No worship modules: a school tenant never loads prayer/Qur'an/azkar.
        'feature_keys' => [
            'about_us', 'announcements', 'contact_us', 'donate', 'gallery', 'services',
        ],
        'terminology' => [
            'organization' => 'School',
            'members' => 'Families',
            'staff' => 'Faculty & Staff',
            'programs' => 'Programs',
            'groups' => 'Classrooms',
            'giving' => 'Giving',
        ],
    ],

    'community' => [
        'label' => 'Community Organization',
        'plural' => 'Community Organizations',
        'feature_keys' => [
            'about_us', 'announcements', 'contact_us', 'donate', 'gallery', 'services',
        ],
        'terminology' => [
            'organization' => 'Organization',
            'members' => 'Contacts',
            'staff' => 'Team',
            'programs' => 'Services',
            'groups' => 'Teams',
            'giving' => 'Donations',
        ],
    ],

];
