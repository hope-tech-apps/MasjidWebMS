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
| Two kinds of entry:
|
|   'column'   Backed by an existing masjids boolean that already has its own
|              gate middleware and its own SuperAdmin toggle (crm_enabled,
|              assistant_enabled). Listed so every capability reads from one
|              catalogue; their storage, gates and endpoints are unchanged.
|
|   (no column) Stored in masjids.capability_overrides and enforced by the
|              `capability:<key>` middleware (EnsureOrgCapability). 'defaults'
|              is what an organisation has until a SuperAdmin overrides it, per
|              org_type — chosen to reproduce exactly what each vertical could
|              reach before this catalogue existed, so shipping it moves nobody.
|
| SuperAdmins are the platform operator, not an organisation's staff: no
| capability ever locks them out (they set organisations up).
|
*/

return [

    'web_pages' => [
        'label' => 'Website pages',
        'description' => 'Build and edit the public website: pages, sections and the section library.',
        // Web Pages Management was SuperAdmin-only in the menu for every
        // organisation, so no organisation has it until it is switched on.
        'defaults' => ['masjid' => false, 'school' => false, 'community' => false],
    ],

    'jummah_lunch' => [
        'label' => 'Friday lunch ordering',
        'description' => 'Sell Jummah lunch online, run the order board, and give volunteers a lunch-only login.',
        // The menu offered it to masjids only (requiresOrgTypes ['masjid']).
        'defaults' => ['masjid' => true, 'school' => false, 'community' => false],
    ],

    'crm' => [
        'label' => 'Members, classes & giving',
        'description' => 'Member directory, groups and classes, teachers, programs, donations and funds.',
        'column' => 'crm_enabled',
    ],

    'assistant' => [
        'label' => 'Manara Assistant',
        'description' => 'The AI assistant for admins, and form insights.',
        'column' => 'assistant_enabled',
    ],

];
