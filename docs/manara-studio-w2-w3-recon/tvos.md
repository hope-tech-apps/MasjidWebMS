# Recon: tvos

> Read-only recon for the Studio W2/W3 plans, 2026-09-24. MasjidWebMS `c3fc0324`, iOS `origin/main` `8e5191f`, Android `origin/master` `cf61d54`, renderer `origin/main` `6a7ead2`.
> Line numbers are as of those commits; re-read before relying on one. This repo is PUBLIC: identifier-shaped values (OneSignal ids, Apple team ids, key ids) are redacted as `[redacted]`.

## Facts

**MasjidTV app**
- [F1] Entry point `@main MasjidTVApp` turns off the idle timer and starts `SignageStore` (`ios:MasjidTV/App/MasjidTVApp.swift:12-24`). There is one full-screen, non-focusable `SignageView` (`ios:MasjidTV/Signage/SignageView.swift:18-33`).
- [F2] How masjidId is chosen (`ios:MasjidTV/App/TVAppConfig.swift:31-49`), in order:
  1. the `-masjidId` launch argument or UserDefaults value;
  2. the Info.plist key `MasjidID`, filled from the `MASJID_ID` build setting (`ios:MasjidTV/Resources/Info.plist:27-28`);
  3. otherwise `1`.
  It is not the iOS app's `BuildMasjid`.
- [F3] The API base URL is a compile-time constant: `.development` = `https://masjid.hopetechapps.com/api` (`ios:MasjidTV/App/TVAppConfig.swift:52`, `ios:MasjidKit/Sources/MasjidKit/Networking/MasjidAPIClient.swift:25`).
- [F4] The app calls exactly four endpoints (`ios:MasjidKit/Sources/MasjidKit/Networking/MasjidEndpoint.swift:13-30`): `/mobile/masjids/{id}/announcements`, `/prayers/settings`, `/mobile/masjids/{id}` and `/tv-config`. Refresh intervals are 5 min, 45 min, 45 min and 3 min (`ios:MasjidTV/App/TVAppConfig.swift:56-59`). Each fetch keeps the last good value when it fails (`ios:MasjidTV/Data/SignageStore.swift:152-193`), and the payloads are cached on disk (`ios:MasjidTV/Data/DiskCache.swift:13-39`).
- [F5] The app does not call `/signage` and does not call `/events`. MasjidKit has no Event model: its Models folder holds only Announcement, Gallery, Masjid, PrayerSettings, Response and TVConfig (`ios:MasjidKit/Sources/MasjidKit/Models/`).

**What the board draws**
- [F6] Prayer panel: a table of adhan ("Begins") and iqama times, Jumu'ah rows on Fridays, and the Hijri date (`ios:MasjidTV/Signage/PrayerPanelView.swift:73-92,112-150,54-62`). The "NEXT" countdown counts to the next **adhan**, not the iqama: `next.adhan.timeIntervalSince(now)` (`:183`). Times are computed on the device by MasjidKit `PrayerCalculator` from `/prayers/settings`.
- [F7] Announcements: a timed carousel with an image slide, or a text card when there is no image (`ios:MasjidTV/Signage/AnnouncementCarouselView.swift:33-59`). Items are filtered to those active now and those with an image URL or text (`ios:MasjidTV/Data/SignageStore.swift:87-105`). `tv_flagged` behaves like `all_active` because there is no flag stored per item (`:95-98`).
- [F8] Donation: a QR code plus a caption only. The URL comes from tv-config `donate_url`, else `masjid.donationLink.link`, with `?src=tv` added (`ios:MasjidTV/Data/SignageStore.swift:69-79`; `ios:MasjidTV/Signage/SignageView.swift:130-132`). `DonationLink` also carries title, message and image (`ios:MasjidKit/Sources/MasjidKit/Models/Masjid.swift:135-140`), but the board does not show them.

**MasjidKit, branding, targets**
- [F9] MasjidKit is one library product `MasjidKit`. It supports iOS 17, tvOS 17 and macOS 12, and its only dependency is adhan-swift from 1.4.0 (`ios:MasjidKit/Package.swift:16-40`). The Xcode project references it by **local** path `relativePath = MasjidKit` (`ios:Masjid.xcodeproj/project.pbxproj:2932-2934`).
  - MasjidTV links only MasjidKit (`pbxproj:1631-1633`).
  - The iOS `Masjid` target does not import it (no `import MasjidKit` under `ios:Masjid/`).
- [F10] Code that exists only for TV and lives outside MasjidKit: `MasjidTV/App/{MasjidTVApp,TVAppConfig}.swift`, `Data/{SignageStore,DiskCache}.swift`, `Signage/{SignageView,AnnouncementCarouselView,PrayerPanelView,QRCodeView,TVTheme}.swift`, plus Resources. That is about 1,200 lines of Swift (from `wc`).
- [F11] Branding at runtime:
  - Brand colour comes from `masjid.theme.tokens.color.primary`, then the legacy `theme.primary`, then the accent colour (`ios:MasjidTV/Signage/TVTheme.swift:30-35`; `ios:MasjidKit/Sources/MasjidKit/Models/Masjid.swift:72-87`).
  - The logo is `masjid.logo.originalUrl`, falling back to the bundled `MasjidLogo` image (`ios:MasjidTV/Signage/SignageView.swift:100-113`), which is `BurlingtonLogo.png` (`ios:MasjidTV/Resources/Assets.xcassets/MasjidLogo.imageset/`).
  - The title is `tvConfig.headerTitle`, else `masjid.name` (`ios:MasjidTV/Data/SignageStore.swift:81-83`).
  - Dark or light background follows tv-config `theme` (`ios:MasjidTV/Signage/SignageView.swift:161-163`).
  - The backend adds `theme` to the payload for `/mobile/masjids/{id}` (`app/Http/Controllers/Mobile/MasjidsController.php:123-127`).
- [F12] Branding fixed at compile time: the display name is `"Masjid TV"` (`ios:MasjidTV/Resources/Info.plist:7-8`). The app icon and Top Shelf art are Burlington's dome art (`ios:.claude/rules/appstore-ship.md:105-110`), and the target uses the `"App Icon & Top Shelf Image"` icon (`pbxproj:2382`).
- [F13] There is **one** tvOS target, `MasjidTV` (`pbxproj:1615-1637`).
  - Release and Debug both use `MASJID_ID = 1` and bundle `masjid.burlington.Burlington-Masjid` (`pbxproj:2396-2397,2720-2721`), with team `[redacted team]` (`pbxproj:2387`).
  - That is the iOS `Masjid` target's own bundle id (`pbxproj:2626,2662`).
- [F14] TV schemes:
  - `MasjidTV.xcscheme` exists.
  - `Muslim Education Center TV.xcscheme` passes `-masjidId 13` only in its LaunchAction (`ios:Masjid.xcodeproj/xcshareddata/xcschemes/Muslim Education Center TV.xcscheme:64`). Its ArchiveAction has no argument (`:89-92`).
  - There is no NAFIS TV scheme, although `ios:XCODE-CLOUD.md:82` names one.
- [F15] The tvOS app ships as the "Apple TV platform of the SAME app record (1514502928)" (`ios:.claude/rules/appstore-ship.md:99-104`).
  - The tvOS platform had to be added in the ASC website: "Add Platform → tvOS (website only; no API)" (`:117-119`).
  - Upload uses `altool --type appletvos` (`:116`).
  - A distribution cert and profile were created through the ASC API with local scripts in a scratchpad (`:111-115`).
- [F16] `ios:XCODE-CLOUD.md:78-87` says there is "exactly one tvOS workflow today, and it builds Burlington." Shipping MEC or NAFIS on TV "needs separate tvOS targets with their own bundle ids." `:3-9` says tvOS cannot be archived from the command line on this machine (no tvOS dev profile, and the team is at its certificate maximum).
- [F17] Two stale docs:
  - `ios:CLAUDE.md:22` gives the bundle id as `com.hopetech.masjid.tv`, which is outdated.
  - `ios:scripts/add_tvos_target.rb:10` still hardcodes that id. The script adds the local MasjidKit reference, the target and a shared scheme (`:24-34,39-126`).
  - `fix_tvos_infoplist.rb` only excludes `Resources/Info.plist` from the synchronized group (`ios:scripts/fix_tvos_infoplist.rb:15-24`).
- [F18] The scaffolder's `--include-tvos` adds a `"<Name> TV"` scheme that launches the shared `MasjidTV` target with `-masjidId <id>`. It creates no target (`ios:scripts/scaffold_masjid_app.rb:33-35,88,382-394,423`). Its checklist says "No separate tvOS App Store record is required unless you ship it standalone" (`ios:scripts/scaffold_checklist.md.erb:67-74`).
- [F19] No push on TV: nothing matches OneSignal under `MasjidTV/` or `MasjidKit/`, and neither TV build config sets `CODE_SIGN_ENTITLEMENTS` (`pbxproj:2379-2409,2703-2733`).

**iOS provisioning workflow**
- [F20] `provision-ios-app.yml` (`ios:.github/workflows/provision-ios-app.yml`):
  - It is triggered by `repository_dispatch` with type `scaffold-masjid` (`:22-23`).
  - `include_tvos` only turns into `--include-tvos` (`:45,137`).
  - It archives for `generic/platform=iOS` (`:179`) and uploads with `altool -t ios` (`:268`).
  - Callback bodies hardcode `"platform":"ios"` (`:92,298`).
  - There is no `git` command in the file, so nothing is committed or pushed.

**WIP branch**
- [F21] `origin/wip/tvos-carousel-placeholder-fallback` holds one commit, `3294c89` ("WIP backup (unshipped)…", 2026-09-16), touching one file: `AnnouncementCarouselView.swift` (+7/-1).
  - It treats `image.id == 0` as "no image", so the slide falls through to the text card.
  - Its base `1ebfccb` is 38 commits behind `origin/main 8e5191f`.
  - Main still has the old condition (`ios:MasjidTV/Signage/AnnouncementCarouselView.swift:56`).
  - The backend sends that id-0 placeholder: `app/Support/MobileMedia.php:116`, applied to announcements at `app/Http/Controllers/Mobile/AnnouncementsController.php:61-63`.

**Backend**
- [F22] Route `GET /api/mobile/masjids/{masjid_id}/tv-config` (`routes/api.php:116`) sits in the `mobile` prefix with `throttle:mobile` (`routes/api.php:41,65`). `/signage` (broadcast slides) is a separate route (`routes/api.php:107`). `/events` exists (`routes/api.php:93`).
- [F23] `TvConfigController` (`app/Http/Controllers/Mobile/TvConfigController.php`):
  - Payload keys (`:113-128`): `is_enabled`=true; `header_title`=null; `carousel_interval_seconds`=10; `show_prayer_panel`=`$masjid->isMasjid()`; `show_qr`=whether a donation link exists; `donate_url`; `donate_caption`="Scan to Donate"; `announcement_selection`="all_active"; `announcement_ids`=null; `theme`="dark".
  - It is cached per masjid with `TTL_SHORT` (`:101-106`; `app/Support/MobileCache.php:77`).
  - There is no `tv_config` table and no admin screen (`:43-45`).
  - The constants are public so StudioPreview can read them (`:83-97`).
- [F24] Tests that pin tv-config:
  - `tests/Feature/TvConfigEndpointTest.php`: keys match the Swift decoder (`:120`), JSON types (`:131`), defaults (`:159`), prayer panel by vertical (`:188`), donate url (`:218`), cross-tenant check (`:258`), cache key (`:278`), `/signage` untouched (`:316`).
  - `tests/Feature/Studio/TvConfigSnapshotTest.php:47` checks the response bytes against `tests/fixtures/tv-config-snapshot.json`.
  - `tests/Feature/ModuleSideDoorsTest.php:966-1006` (`public_and_app_reads_never_follow_a_module`) asserts that tv-config's `show_prayer_panel` and `donate_url` stay the same when a module is switched off.
- [F25] Studio uses these constants in `StudioPreview` (`app/Support/Studio/StudioPreview.php:162-172`) and adds `TVOS_HEADER_INK`/`TVOS_BACKGROUND` `#FFFFFF`/`#0F0F0F` (`:57-60`). Tests: `tests/Feature/Studio/StudioPreviewTest.php:59,84,102,113,148`. `LayoutPresets.php` has no TV reference.
- [F26] `platforms` accepts `ios|android|tvos|web` (`app/Http/Requests/Admin/Onboarding/ProvisionMasjidRequest.php:233-234`). tvOS without iOS is refused (`:346-351`), on the stated grounds that tvOS uses "the SAME Apple Developer account / App Store Connect record" (`:339-341`). The Studio draft uses the same enum (`app/Http/Requests/Admin/Studio/UpdateStudioDraftRequest.php:143`).
- [F27] Studio's provisioning stores the chosen platforms, including `tvos`, in `masjid_app_publishing.enabled_platforms` (`app/Support/Studio/OrganisationProvisioner.php:283-292`). `platformEnabled()` falls back to `ios/android/web`, never `tvos`, when that column is null (`app/Models/MasjidAppPublishing.php:101-109`).
- [F28] `ProvisioningJob` has only `PLATFORM_IOS` and `PLATFORM_ANDROID` (`app/Models/ProvisioningJob.php:48-49`). The column is `enum('platform',['ios','android'])` (`database/migrations/2026_07_23_150000_create_provisioning_jobs_table.php:47`), and the callback validates `Rule::in(['ios','android'])` (`app/Http/Controllers/ProvisioningCallbackController.php:59`).
- [F29] `ProvisionAppsRequest` allows only `ios` and `android` (`app/Http/Requests/Admin/Provisioning/ProvisionAppsRequest.php:29`), so a request for `tvos` gets a 422. tvOS reaches the iOS runner only as the `include_tvos` flag (`app/Http/Controllers/AdminDashboard/AppProvisioningController.php:180-193`). `repoFor()` sends any platform other than `ios` to the Android repo (`:128-133`).
- [F30] In the SPA, the tvOS pill is disabled unless iOS is chosen (`resources/vue-app/components/super/studio/foundation/PlatformsPanel.vue:7-9,89-90`), and tvOS has no account mode (`resources/vue-app/components/super/studio/generate/ReviewGrid.vue:142`).
- [F31] `config/capabilities.php` has no TV or signage key; the keys are listed at `:106-424`. Device telemetry treats `app_platform: 'tvos'` as "a platform nobody ships" (`tests/Feature/InstalledBuildsContractTest.php:204`), and the TV client sends no client header (`ios:MasjidKit/Sources/MasjidKit/Networking/MasjidAPIClient.swift:61-65`).
- [F32] No fundraising goal or progress data exists: `Fund` is fillable for only masjid_id, name, type, receiptable and is_active (`app/Models/Fund.php:23-29`).

## Answers

**1. Structure, masjidId, endpoints.** The App, Data, Resources and Signage folders are laid out in [F1] and [F10]. masjidId comes from a launch argument, then the `MASJID_ID` Info.plist key, then 1 [F2]. It is not taken from the shared BuildMasjid. Endpoints are in [F4]. tv-config exists and is served by the backend [F22][F23]. The base URL is fixed in the binary [F3].

**2. The screens D11 asks for.**
- Prayer times: yes. The iqama times show in the table, but the countdown runs to the adhan, not the iqama [F6].
- Announcements: yes [F7].
- Events calendar: **no, confirmed** [F5]. The backend feed exists [F22], but MasjidKit has no model or endpoint for it.
- Donation appeal: a QR code and caption only, with no appeal text or image and no goal [F8][F32].

**3. MasjidKit usage.** MasjidTV uses the one product, `MasjidKit` [F9]: the API client, the models, `PrayerCalculator` and `HijriDate`. The TV-only code is listed in [F10].

**4. Per-org TV targets.** No organisation has its own TV target. The single `MasjidTV` target is Burlington's (masjid 1) [F13]. The MEC TV scheme sets the id only when launched from Xcode, so an archive of it builds Burlington [F14][F16]. The scripts are described in [F17][F18].
- **Same App Store record or separate?** The evidence says the **same record** (universal purchase): Burlington's TV build shares the iOS bundle id and record 1514502928 [F13][F15], and the backend rule assumes the same [F26].
- **But the paths conflict.** XCODE-CLOUD.md says other organisations need separate targets and bundle ids [F16]. The scaffolder checklist hedges [F18].
- Adding the tvOS platform to a record was done by hand in the website [F15].

**5. Theming.** Colour, logo and name come from the API at runtime. The app icon, Top Shelf art, fallback logo, display name and base URL are fixed in the binary [F11][F12][F3].

**6. Push on TV.** None, confirmed [F19].

**7. CI.** `provision-ios-app.yml` does not build tvOS. The only tvOS switch is `include_tvos`, which adds a scheme. Archive, upload and callback are all iOS-only, and nothing is committed [F20]. The backend side has no tvOS job type either [F28][F29].

**8. The WIP branch.** It holds one unshipped fix so the board treats the backend's id-0 placeholder image as "no image" [F21]. It is relevant: a W2 template should include it, and it needs a rebase since it is 38 commits behind main.

**9. Backend.** The controller, route, payload and tests are in [F22]–[F24]. Studio reads the constants [F25]. Platform rules are in [F26], job platforms in [F28], and a tvos request to AppProvisioningController gets a 422 [F29].

**10. What the TV needs from the backend that does not exist yet:**
- a place to store tv-config settings and an admin screen for them (header override, pause, manual selection, `tv_flagged`, donate override) [F23][F7];
- an events model and endpoint in MasjidKit, since the backend feed already exists [F5][F22];
- appeal content and fundraising goal data [F8][F32];
- the board consuming `/signage` broadcasts [F5][F22];
- a TV module switch, which does not exist, and a test that pins tv-config to ignore modules [F31][F24];
- `tvos` as a provisioning job platform, in the callback and in `repoFor` [F28][F29];
- fleet telemetry, which has no support for TV [F31];
- a base URL set by configuration rather than in the binary [F3].

## Risks to live clients

- [R1] **Burlington (1)** has the only shipped TV build, on the shared iOS record 1514502928 [F13][F15]. Changing the bundle id or restructuring the shared `MasjidTV` target could separate the TV platform from that record or change what Burlington's board runs.
- [R2] Installed boards decode tv-config strictly. A type or key change silently drops every board back to its built-in defaults (`TvConfigController.php:29-39`) [F24]. The byte-level snapshot test must stay green.
- [R3] **MEC (13):** archiving or cloud-building "Muslim Education Center TV" today would produce a Burlington board [F14][F16].
- [R4] **Burlington (1):** reading the code, text-only announcements reach the board as the grey id-0 placeholder image instead of a text card [F21][F7]. This has not been seen on a device.
- [R5] Making tv-config follow module switches (for example `prayer_times`) would change live boards and break a pinned test [F24]. **Al-Razi (14)** and **BISS (18)** already get `show_prayer_panel=false` because they are schools [F23].
- [R6] Every TV build reads production [F3]. A W2 test build cannot point at staging without a code change.
- [R7] Adding `tvos` to the enum on the live `provisioning_jobs` table needs a MySQL enum change [F28]. Leave `repoFor` unchanged and a tvOS job goes to the Android repo [F29].

## Unknowns

- [U1] Should each organisation's TV app be the tvOS platform on that organisation's iOS record, or a separate record? The evidence conflicts [F15][F16][F18]. An owner decision is needed.
- [U2] Is adding the tvOS platform to an ASC record still website-only [F15]? This needs a check of the current ASC API docs, which requires network access I did not have.
- [U3] How many Apple TVs are deployed, for which organisations, and on which builds? Resolve through TestFlight/ASC build and tester lists, or ask the owner.
- [U4] Does the Burlington board show the placeholder today [R4]? Someone needs to look at the device.
- [U5] `TVOS-DESIGN.md`, which the code cites (`TVAppConfig.swift:7`, `MasjidTVApp.swift:5`), is not in the iOS repo on main. Its location is unknown; ask the owner or search the other repos.
- [U6] Can Xcode Cloud workflows be created per organisation without manual steps? Signing a tvOS build on the self-hosted runner is blocked [F16]. Check the Xcode Cloud API docs.
- [U7] Whether any NAFIS TV scheme exists outside this snapshot [F14]. Resolve with `git log -S "NAFIS Apex Mosque TV"` on the iOS repo.
