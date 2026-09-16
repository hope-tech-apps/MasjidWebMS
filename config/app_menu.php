<?php

/*
|--------------------------------------------------------------------------
| The mobile app menu — what the side menu can list, and in what order
|--------------------------------------------------------------------------
|
| `GET /api/mobile/masjids/{id}/menu` turns an organisation's SWITCHES into a
| menu: which entries the drawer lists, which sections they sit in, and which
| of them the hybrid tab bar carries. This file is that registry.
|
| What is here, and what deliberately is not:
|
|   HERE    the item keys, their order, which section each rides, which
|           module switch decides each one, the legacy Mobile App Features id
|           each one replaces, and which keys are eligible to be tabs.
|
|   NOT     labels and icons. They are CLIENT-side, keyed off the item key
|           (side-menu plan v3 §2.5). On 2026-08-28 a server-driven icon list
|           emptied the drawer on every phone, and labels have to be
|           localisable (iOS String Catalog, Android strings.xml). The
|           catalogue labels in config/capabilities.php are the ADMIN's words
|           and are never emitted in this payload.
|
| Visibility is `! Masjid::moduleIsOff($key)` for ANY key in `any_of`, and
| nothing else. Not whether a donation link has a URL, not whether Stripe is
| onboarded: the app's fallback menu (built from the legacy /features when
| /menu is killed) cannot know those things, and the kill switch is only worth
| having if the menu it falls back to is the same menu.
|
| `moduleIsOff` FAILS OPEN, so a key this file names that the loaded capability
| config has not caught up with can only ever show an entry, never hide one.
|
| The whole file is mirrored verbatim in App\Support\AppMenu::DEFAULT_REGISTRY,
| for the same reason Masjid::MODULE_KEYS is: during a deploy the new PHP runs
| against the previous config cache for a while, and a menu must never lose rows
| because of that. AppMenu::registry() validates this file and falls back to the
| code copy. AppMenuRegistryMirrorTest pins the two together.
|
*/

return [

    // Bumped only for a BREAKING payload change. A client that reads a higher
    // number than it knows treats the menu as unavailable and falls back, so
    // additive fields (a new item key, a new optional field) must NOT bump it.
    'schema_version' => 1,

    // Home + three. The client appends its own "Menu" tab, making five — iOS's
    // limit before a TabView collapses the overflow into "More".
    'max_tabs' => 4,

    // Section order is menu order; item order within a section is this order.
    // Section TITLES are client-side: none for `main`, "Worship", and
    // "About {profile name}".
    'sections' => [
        'main' => ['home', 'announcements', 'services', 'donate'],
        'worship' => ['quran', 'hadith', 'adhkar', 'qibla', 'tasbih'],
        'about' => ['about_us', 'gallery', 'contact'],
    ],

    // The tab bar, in bar order — which is NOT menu order: `contact` sits in
    // the `about` section but is the third tab. Exactly today's gated set
    // (legacy ids 10, 11 and 6), now labelled and switch-driven. Each profile
    // gets the subset of these that is visible for it, `home` always first.
    // Every tab destination also stays in `sections`, so nothing becomes
    // unreachable at a large text size or after a profile switch drops a tab.
    'tabs' => ['home', 'announcements', 'contact', 'donate'],

    // `legacy_feature_id` is the Mobile App Features row this entry replaces —
    // the id the installed builds still route by. Ids 1-11 each appear exactly
    // once; `home` has none because the old drawer had no Home row.
    'items' => [
        'home' => ['legacy_feature_id' => null, 'always' => true],
        'announcements' => ['legacy_feature_id' => 10, 'any_of' => ['announcements', 'events'], 'parts' => ['announcements', 'events']],
        'services' => ['legacy_feature_id' => 9, 'any_of' => ['services']],
        'donate' => ['legacy_feature_id' => 6, 'any_of' => ['donation_link', 'giving'], 'parts' => ['donation_link', 'giving']],
        'quran' => ['legacy_feature_id' => 1, 'any_of' => ['quran']],
        'hadith' => ['legacy_feature_id' => 2, 'any_of' => ['hadith']],
        'adhkar' => ['legacy_feature_id' => 3, 'any_of' => ['adhkar']],
        'qibla' => ['legacy_feature_id' => 4, 'any_of' => ['qibla']],
        'tasbih' => ['legacy_feature_id' => 5, 'any_of' => ['tasbih']],
        'about_us' => ['legacy_feature_id' => 7, 'any_of' => ['about_us']],
        'gallery' => ['legacy_feature_id' => 8, 'any_of' => ['gallery']],
        'contact' => ['legacy_feature_id' => 11, 'any_of' => ['contact_requests']],
    ],

    /*
    |----------------------------------------------------------------------
    | `navigation` — the per-organisation, per-platform shell lever
    |----------------------------------------------------------------------
    |
    | Documentation, not a registry. AppMenu::registry() reads only the five
    | keys above and ignores everything else in this file, so nothing below
    | can change what a menu contains.
    |
    | `app_version_settings.navigation` decides which shell an R1 app draws.
    | It is emitted inside `data.ios` / `data.android` of
    | `GET /mobile/masjids/{id}/app-config`, omitted when null, read from the
    | HOME organisation's row, and applied at the next COLD LAUNCH only.
    |
    | Four spellings are accepted. The first pair is canonical — what the
    | server documents and what an admin should be offered. The second pair is
    | the vocabulary the clients were compiled with, accepted because both
    | clients map an UNKNOWN value to the NEW shell: rejecting a spelling the
    | apps would have honoured turns this lever into a save that reports
    | success and changes nothing, which is the exact failure it exists to
    | undo.
    |
    |   value          shell            note
    |   -----------    -------------    ---------------------------------------
    |   menu           new              canonical; hybrid tabs + side menu
    |   side_menu      new              client alias for the same thing
    |   legacy         old              canonical; the layout the build shipped
    |   tabs_drawer    old              client alias for the same thing
    |   (null/absent)  client default   the compiled default, which is `menu`
    |
    | Both clients parse tolerantly: anything they do not recognise is the new
    | shell, so a typo can never strand an app on a blank screen.
    |
    | What `legacy` rolls back, honestly: the menu, the store and the drawer.
    | NOT the Android single-activity merge, NOT the iOS HomeView de-nesting
    | and NOT the in-place switch — the legacy shell shares all three. Rolling
    | those back needs a new build, which is what this lever is worth as a
    | release gate.
    |
    */
    'navigation_aliases' => [
        'menu' => 'menu',
        'side_menu' => 'menu',
        'legacy' => 'legacy',
        'tabs_drawer' => 'legacy',
    ],

];
