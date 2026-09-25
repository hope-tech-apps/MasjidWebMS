<engineering-excellence-recon platform="ios" repo="hope-tech-apps/burlington-masjid-iOS">

# Recon: burlington-masjid-iOS (origin/main 8e5191f), for the Studio W2/W3 plan

> Persisted in MasjidWebMS, which is a PUBLIC repository. Identifier-shaped values (the OneSignal app id, Apple team ids, key ids) are redacted as `[redacted]`; read them in the private source repository at the cited line.

commit: 8e5191f (origin/main per brief; snapshot is not a git work tree, full sha not read)
platform: ios
scope: $SP/snap/ios (hope-tech-apps/burlington-masjid-iOS), app root Masjid.xcodeproj; MasjidKit/ is a local-package dependency
generated: 2026-09-24
dirty: n/a (snapshot copy)
mode: quick + briefed questions
kit: ~/.claude/engineering-excellence

## Facts

**Detection and binding files**
- [F1] The kit detector matched only `ios`. There are six native targets: `NAFIS Apex Mosque`, `Masjid`, `Muslim Education Center`, `MasjidTV`, `OneSignalNotificationServiceExtension` and `MasjidTests` (ios:Masjid.xcodeproj/project.pbxproj:1518,1550,1584,1616,1639,1662).
- [F2] There are six shared schemes: Masjid, MasjidTV, MasjidTests, Muslim Education Center, NAFIS Apex Mosque, and "Muslim Education Center TV". The last one builds `MasjidTV` with the launch argument `-masjidId 13` (ios:Masjid.xcodeproj/xcshareddata/xcschemes/Muslim Education Center TV.xcscheme:19,64).
- [F3] CLAUDE.md binds four things: pass `-project Masjid.xcodeproj` (ios:CLAUDE.md:60-62); target membership is explicit, so every shared source goes into all three iPhone targets (ios:CLAUDE.md:69-76); home id vs current id (ios:CLAUDE.md:77-80); no `main` colour asset (ios:CLAUDE.md:88-91). Several of its claims are stale:
  - ship branch is `feat/tvos`, "do not merge main" (ios:CLAUDE.md:94-96), but the snapshot is main and the clone has no `origin/feat/tvos`;
  - a `Masajid.xcodeproj` (ios:CLAUDE.md:55) is absent;
  - the TV bundle is given as `com.hopetech.masjid.tv` (ios:CLAUDE.md:22), which F9 contradicts;
  - it lists SwiftMessages, IQKeyboardManager, AlertToast and others (ios:CLAUDE.md:28-32), none of which are in Package.resolved.
- [F4] Other binding files:
  - ios:.claude/rules/appstore-ship.md covers the beta-macOS stamp strip (:7), the build number having to exceed the App Store Connect (ASC) max (:46-59), and says "auto-bump … is BROKEN" (:49-54);
  - ios:.claude/rules/quran-reader.md says do not rewrite the engine (:13);
  - DECISIONS.md is 2,211 lines and never mentions MasjidKit (grep returns nothing). It has a SHIP GATE: production's password sign-in must be live before any iOS build carrying 7615f10, and this applies to all iPhone targets (ios:DECISIONS.md:88-90).

**Toolchain**
- [F5] Deployment targets:
  - iPhone targets: `IPHONEOS_DEPLOYMENT_TARGET = 17` at target level (pbxproj:2620,2427,2463), overriding 26.1 at project level (pbxproj:2534,2592);
  - notification service extension (NSE): 26.4 (pbxproj:2779,2808);
  - MasjidTV: `TVOS_DEPLOYMENT_TARGET = 17.0` (pbxproj:2404,2729).
- [F6] `SWIFT_VERSION = 5.0` on every target (pbxproj:2372,2440,2633). MasjidKit is `swift-tools-version: 5.9` with platforms iOS 17, tvOS 17 and macOS 12 (ios:MasjidKit/Package.swift:1,19-23). Nothing pins Xcode:
  - no `.xcode-version`;
  - the project stamps are LastUpgradeCheck 2610 and LastSwiftUpdateCheck 2640 (pbxproj:1689-1690);
  - the workflow runs on `macos-15` (ios:.github/workflows/provision-ios-app.yml:31).
- [F7] Remote packages are pinned upToNextMajor in the pbxproj (pbxproj:2939-3000) and resolved at ios:Masjid.xcodeproj/project.xcworkspace/xcshareddata/swiftpm/Package.resolved:
  - adhan 1.4.0, Alamofire 5.11.0, CountryPicker 5.0.2, PhoneNumberKit 4.2.3, SDWebImageSwiftUI 3.1.4, SVGView 1.0.6, OneSignal-iOS-SDK 5.5.0 (:73);
  - quran-ios tracks **branch main** (:99-100) and popover tracks **branch master** (:90).

**Variants**
- [F8] Per-target values:

| target | masjidId | bundle id | display name | team | icon set | marketing version |
|---|---|---|---|---|---|---|
| Masjid (Burlington) | 1 (BuildMasjid.swift:9) | masjid.burlington.Burlington-Masjid (pbxproj:2626) | Burlington Masjid (:2614) | [redacted team] (:2610) | AppIcon (:2605) | 2.8 (:2625) |
| NAFIS Apex Mosque | 5 (BuildMasjid+NAFIS.swift:12) | org.apexmosque.app (:2469) | NAFIS Apex Mosque (:2457) | [redacted team] (:2453) | AppIcon-NAFIS (:2448) | 2.5 (:2468) |
| Muslim Education Center | 13 (BuildMasjid+MUSLIM.swift:11) | org.meccharlotte.app (:2433) | Muslim Education Center (:2421) | [redacted team] (:2417) | AppIcon-MUSLIM (:2412) | 2.5 (:2432) |

  All three share `Masjid/Info.plist` and `Masjid/Masjid.entitlements` (pbxproj:2414,2450,2607). The build number `CURRENT_PROJECT_VERSION = 2608070933` is the same on every target (pbxproj:2358,2416,2609).
- [F9] MasjidTV reuses Burlington's bundle id `masjid.burlington.Burlington-Masjid` and has `MASJID_ID = 1` (pbxproj:2396-2397,2720-2721). The TV app ships as the Apple TV platform of the **same ASC record, 1514502928** (ios:.claude/rules/appstore-ship.md:99-104).
- [F10] Entitlements hold only `aps-environment` and no app group (ios:Masjid/Masjid.entitlements:5-6). The launch screen is generated, not per-org (`INFOPLIST_KEY_UILaunchScreen_Generation = YES`, pbxproj:2617).
- [F11] There is one shared asset catalog. The only per-org assets are the `AppIcon-<SLUG>.appiconset` folders, and all three hold art (ios:Masjid/Assets/Assets.xcassets/AppIcon-MUSLIM.appiconset/icon.png).
- [F12] How the tenant is bound:
  - each `BuildMasjid*.swift` sits in exactly one Sources phase, while `AppDelegate.swift` and `CurrentOrg.swift` sit in three (grep count of the pbxproj);
  - `homeMasjidId = BuildMasjid.masjidId` (ios:Masjid/Config/AppConfig+MasjidID.swift:37);
  - `masjidId = CurrentOrg.id`, a runtime override for child orgs that is kept in memory only (ios:Masjid/Config/CurrentOrg.swift:42-47).
- [F13] Only `Masjid` depends on and embeds the NSE (pbxproj:1561). NAFIS and MEC have only Sources, Frameworks and Resources phases (pbxproj:1520-1524,1586-1590).

**MasjidKit**
- [F14] MasjidKit is one product, `MasjidKit` (ios:MasjidKit/Package.swift:26-29). It has 10 files and 1,008 lines:
  - Models: Announcement, Gallery, Masjid, PrayerSettings, Response, TVConfig;
  - Networking: MasjidAPIClient, MasjidEndpoint;
  - Prayer: HijriDate, PrayerCalculator.
- [F15] The project references it by local path (`XCLocalSwiftPackageReference`, `relativePath = MasjidKit`, pbxproj:2932-2934). Only MasjidTV links it (pbxproj:1632). `import MasjidKit` appears only in six MasjidTV files and the package's own tests. The clone has no git tags.
- [F16] The iOS app `Masjid/` holds 152 Swift files, about 27.3k lines (pruned count): Models 55 files / 8,446 lines, Views 69 / 14,805, Modifiers 14 / 2,007, Helpers 7 / 1,917, Config 5 / 145.
  - Models duplicated in both places: `Masjid/Models/ObjectModels/{Announcement,Gallery,Masjid,Response}.swift` and the same names under `MasjidKit/Sources/MasjidKit/Models/`.
  - The iqama fixture is also duplicated (ios:MasjidTests/Fixtures/iqama-resolution.json, ios:MasjidKit/Tests/MasjidKitTests/Fixtures/iqama-resolution.json).
- [F17] The refactor plan is referenced but not present:
  - "deliberate fast-follow (see TVOS-DESIGN.md §7)" (ios:MasjidKit/Package.swift:7-9);
  - "iOS could migrate onto this over time (Phase B), but does not have to" (ios:MasjidKit/Sources/MasjidKit/Networking/MasjidAPIClient.swift:6-8);
  - TVOS-DESIGN.md is not in the snapshot, and `git log --all -- TVOS-DESIGN.md` in the clone is empty.

**OneSignal and endpoints**
- [F18] OneSignal:
  - `OneSignal.initialize("[redacted]")` is a hardcoded literal (ios:Masjid/AppDelegate.swift:73), compiled into all three iPhone targets (F12);
  - each install is tagged `masjid_id = homeMasjidId` (ios:Masjid/AppDelegate.swift:84) and logged in with `OneSignal.login(deviceId)` (ios:Masjid/Models/Device/DeviceRegistration.swift:268);
  - the NSE holds no app id (ios:OneSignalNotificationServiceExtension/NotificationService.swift:17-25);
  - no Info.plist carries a OneSignal key, and the `Masjid`, `AppMenu` and `AppConfig` models decode no OneSignal field.
- [F19] The scaffolder sets an `ONESIGNAL_APP_ID` build setting (ios:scripts/scaffold_masjid_app.rb:163) that nothing reads (no other reference in the repo). Its checklist says so itself (ios:scripts/scaffold_checklist.md.erb:53-58).
- [F20] The base URL is compile-time. `S.server = DevelopmentServer` (ios:Masjid/Models/S.swift:12), and that server's URL is the production host `https://masjid.hopetechapps.com/api` (:58). `ProductionServer.url` is `""` (:51).
  - Other hardcoded values: the deletion page (ios:Masjid/Models/Member/MemberAPI.swift:25) and the background-task id `com.moneeb.Masjid.refresh` (ios:Masjid/AppDelegate.swift:64).
  - A Google service API key is committed at ios:Masjid/Models/S.swift:16. It is present; its value is not reproduced here.
- [F21] Calls at launch:
  - the app-config gate runs first (ios:Masjid/Views/Splash/SplashViewModel.swift:84-90 → home id, ios:Masjid/Models/Networking/HTTP/APIRouter.swift:137);
  - then three parallel fetches (SplashViewModel.swift:23-29,112-116): `/mobile/masjids/{current}` (APIRouter.swift:122), `/features` (:94) and `/prayers/settings` (:102);
  - `/menu` (home id, ETag) when the shell launches (ios:Masjid/Views/Shell/AppShellView.swift:310 → APIRouter.swift:169; ios:Masjid/Models/Menu/MenuRequest.swift:46);
  - `/orgs` (home id, APIRouter.swift:163);
  - `/splash` (ios:Masjid/Views/Main/Splash/SplashAnnouncementProvider.swift:60);
  - device registration at `/mobile/user` (APIRouter.swift:88).
- [F22] Theme and logo are runtime values:
  - `Color.main` reads `Settings.shared.masjid?.theme?.primary`, falling back to `#01B151` (ios:Masjid/Helpers/CLAUDE.md:20-25);
  - `MasjidTheme` carries primary, secondary, accent, background and tokens (ios:Masjid/Models/ObjectModels/Masjid.swift:95-103);
  - the logo comes from the API `masjid.logo` (ios:Masjid/Views/Main/Home/HomeView.swift:381; ios:Masjid/Views/SideMenu/SideMenuView.swift:82).

**Architecture and state**
- [F23] Entry is `@main` in ios:Masjid/MasjidApp.swift plus a UIKit AppDelegate.
  - `Settings` is a Codable struct singleton persisted to UserDefaults (ios:Masjid/Models/Settings.swift:11,79-81,116-117).
  - `ObservableObject` appears in 29 files and `@Observable` in none.
  - `MenuStore` is a `@MainActor` singleton (ios:Masjid/Models/Menu/MenuStore.swift:88-89,156).
  - Features follow a View + ViewModel split (13 ViewModels), e.g. ios:Masjid/Views/SideMenu/Services/ServicesViewModel.swift:11-23.

**Scaffolder, CI, distribution**
- [F24] Scaffolder inputs (ios:scripts/scaffold_masjid_app.rb:80-91):
  - required: `--masjid-id`, `--name`, `--bundle-id`;
  - optional: display-name, slug, development-team, onesignal-app-id, include-tvos, project, template-target, dry-run, and a YAML/JSON config file;
  - with no team it builds simulator-only (:154-157), and it refuses to run if the target name already exists (:174).
- [F25] Scaffolder outputs:
  - a duplicate of the `Masjid` target;
  - `BuildMasjid+<Slug>.swift` (:336-338);
  - an icon-set stub;
  - a shared scheme (:379);
  - optionally a "<Name> TV" scheme on the shared MasjidTV target with `-masjidId` (:387-393);
  - `scripts/generated/<slug>-checklist.md` (:371);
  - `project.save` (:421).

  It does not copy the NSE embed phase or target dependencies (:29,225). The README's claim of "all 20" package products (ios:scripts/SCAFFOLD-README.md:39) is stale; the `Masjid` target now has 13 (pbxproj:1564-1577). The only generated output is ios:scripts/generated/muslim-checklist.md, for MEC, team [redacted team], dated 2026-07-24 (:1-6).
- [F26] Manual steps the checklist leaves (ios:scripts/scaffold_checklist.md.erb:23-74):
  - the icon PNG;
  - registering the App ID and push capability;
  - **creating the ASC app record** (:41-42);
  - signing;
  - the OneSignal app and APNs key;
  - the per-target OneSignal and NSE refactor;
  - build bump, archive and upload, testers, store listing;
  - tvOS platform and art.
- [F27] Provisioning workflow (ios:.github/workflows/provision-ios-app.yml):
  - trigger: `repository_dispatch` of type `scaffold-masjid` (:21-23);
  - payload: job_id, masjid_id, name, display_name, account_mode, development_team, bundle_id, include_tvos, callback_url, callback_token (:38-47);
  - secrets: ASC_KEY_ID, ASC_ISSUER_ID and ASC_KEY_P8 (base64 .p8) (:49-51);
  - callback: `POST callback_url` with `Bearer callback_token` and body `{job_id, platform:"ios", status, detail, artifact_url}` (:85-103). Statuses are scaffolding (:130), building (:153), built (:217,278), uploaded (:276) and failed (:113,128,151,298);
  - build: managed account with secrets archives, exports and uploads via `altool` (:163-187,259-273); anything else only compiles for the simulator (:190-204);
  - `permissions: contents: read` (:26-27) and no commit or push step;
  - no tvOS build (`include_tvos` only adds a scheme, :137).
- [F28] The runner docs disagree with the workflow. They say a self-hosted runner, `runs-on: [self-hosted, macOS]` (ios:.github/PROVISIONING-RUNNER.md:6,82; yml:5 comment), but the workflow uses `macos-15` (yml:31). BYO accounts never upload (PROVISIONING-RUNNER.md:116-118). There is **no** `provision-android-app.yml` in this snapshot.
- [F29] ios:scripts/ship-testflight.sh:
  - hardcodes `SCHEME="Masjid"` (:53) and an absolute path on one Mac (:51);
  - defaults to team [redacted team] (:71) and a hardcoded ASC key id (:72); issuer comes from env or file (:24-26);
  - bumps **every** `CURRENT_PROJECT_VERSION` (:217-219), then commits and pushes (:289,294) before archiving, and uploads with `altool` (:411).
- [F30] Apple teams are labelled inconsistently across files:
  - [redacted team] is "Burlington Masjid" (ship-testflight.sh:38) or "Burlington Makkah Masjid" (appstore-ship.md:111), but "Hope Tech org team" (SCAFFOLD-README.md:97);
  - [redacted team] is "Muslim American Society DC" (ship-testflight.sh:37);
  - [redacted team] is an "org personal team" (SCAFFOLD-README.md:98);
  - both [redacted team] and [redacted team] had unaccepted license agreements (PLAs) in June 2026 (ship-testflight.sh:35-41).
- [F31] Xcode Cloud is designed but its existence is not confirmed:
  - five TestFlight/test workflows on branch main (ios:XCODE-CLOUD.md:55-63);
  - the first workflow needs a UI OAuth step (:38-47);
  - `ci_post_clone.sh` asserts MasjidKit is present and logs each BuildMasjid id (ios:ci_scripts/ci_post_clone.sh:23-47);
  - cloud builds ignore scheme launch arguments, so a MEC TV build would ship Burlington (XCODE-CLOUD.md:80-87).
- [F32] The ASC API is used headlessly for builds, the encryption flag, screenshots and review submission (appstore-ship.md:75-83). Nothing in the repo creates an ASC app record; it appears only as a checklist step (F26).
- [F33] Last touched on origin/main:
  - Models/Menu: 2026-09-17 (1d25e42);
  - Views/Member: 2026-09-16;
  - BuildMasjid+MUSLIM: 2026-07-25;
  - scaffolder: 2026-07-23;
  - workflow: 2026-07-24;
  - MasjidKit/Sources: 2026-07-28;
  - MasjidTV: 2026-08-07.

## Answers

**Q0. Quick-mode axes.**
- **Toolchain:** see F5-F7. Nothing pins Xcode (F6), and two dependencies float on branches (F7).
- **Architecture:** SwiftUI MVVM with singletons (Settings, MenuStore, NotificationInboxStore) and one app shell (F23). I did not probe for TCA or VIPER; nothing suggested either.
- **State:** `ObservableObject`/`@Published` plus a UserDefaults-cached `Settings`. The home id vs current id split is binding (F12, F3).
- **Binding files:** F3 and F4.
- **Closest parallel:** the MEC target (F8, F25, F33), which is the newest scaffolder output. For a screen, Services (F23).
- **Elided:** all other axes (quick mode).

**Q1. Variant model.** One xcodeproj holds one iPhone target per org. Each target has one `BuildMasjid` file, a bundle id, a display name and an `AppIcon-<SLUG>`, and shares Info.plist, entitlements and the asset catalog (F8, F10-F13). There are no xcconfigs and no app groups.
- iPhone orgs: 3 — Burlington 1, NAFIS 5, MEC 13 (F8).
- TV: one target, bound to Burlington (id 1), plus a MEC TV scheme that works only through a launch argument (F2, F9, F31).
- Al-Razi (14), BISS (18) and the QA sandbox (17) have no targets.
- TV files: `MasjidTV/{App,Data,Signage,Resources}`, `scripts/add_tvos_target.rb`, `scripts/fix_tvos_infoplist.rb`, and the MasjidTV and "Muslim Education Center TV" schemes.

**Q2. MasjidKit.**
- **What is in it:** models, the API client and the prayer engine, with only MasjidTV consuming it (F14, F15).
- **What the iOS target still owns:** everything — 152 files with zero MasjidKit imports (F16).
- **Docs and plan:** only "fast-follow / Phase B, optional" comments; TVOS-DESIGN.md §7 is missing (F17). Current state: not started.
- **Remaining to move (estimate, unverified):**
  - model and logic convergence: about 65 files, about 12k lines (Models + Modifiers + Helpers);
  - a config-only D5 repo: about 145-150 of 152 files, about 27k lines, plus fonts, sounds, the Qur'an databases and 46 imagesets.
- **Remote consumption:** it is referenced by local path only and the repo has no tags (F15). Inferred from SwiftPM behaviour: a remote package needs `Package.swift` at the repository root, so remote consumption requires extracting MasjidKit into its own repo.

**Q3. OneSignal.** The iOS app's id is a single hardcoded literal in the shared AppDelegate. The NSE holds no id and exists only for Burlington. Nothing is per-target and nothing is fetched (F13, F18, F19). All three apps share one OneSignal app and are separated by the `masjid_id` tag. Launch endpoints are listed in F21: app-config, masjid show, features, prayers/settings, menu, orgs, splash and device registration.

**Q4. What a new client target sets, compile-time vs runtime.**

| value | how it is set | source |
|---|---|---|
| masjidId | compile-time | F12 |
| API base URL | compile-time, same for all | F20 |
| deletion URL | compile-time | F20 |
| bundle id, display name, team, app icon | compile-time build settings and assets | F8, F11 |
| OneSignal id | compile-time, shared | F18 |
| background-task id | compile-time | F20 |
| colours/theme | runtime (`/masjids/{id}`), compile-time fallback `#01B151` | F22 |
| logo | runtime | F22 |
| feature menu | runtime (`/menu`, `/features`) | F21 |
| splash announcement | runtime | F21 |
| launch screen | generated, not per-org | F10 |
| TV org id | compile-time `MASJID_ID`, overridable by launch argument | F9 |

**Q5. Scaffolder and CI.** Inputs, steps and outputs are in F24-F25; manual steps in F26. The workflow is described in F27-F28.
- The workflow does **not** commit or push, so the scaffolded target exists only on the runner and is thrown away.
- It builds iOS only.
- It uploads only when the account is managed and all three secrets are set, and even then only after an ASC record exists, which nothing creates (F32).

**Q6. Distribution.** There are three paths:
- manual `ship-testflight.sh` for Burlington only (F29);
- the provisioning workflow's best-effort `altool` upload (F27);
- the Xcode Cloud design (F31).

Teams are [redacted team], [redacted team] and [redacted team], with conflicting ownership labels (F30). The ASC API key is used for signing, uploads and ASC API scripting (F27, F32). **Creating an ASC app record is not automated anywhere** (F26, F32).

**Q7. Blockers for a config-only `manara-<client>-ios` repo (D5).**
1. None of the app code lives in a package (F16). The views, stores and networking would all need to become a MasjidKit product, taking the ~27k lines (estimate) with them.
2. MasjidKit cannot be consumed remotely: it sits in a subdirectory and has no semver tags (F15). It needs its own repo and release tags.
3. Per-org constants are baked into shared code: BuildMasjid, the OneSignal literal, the base URL, the deletion URL and the background-task id (F12, F18, F20). The kit needs a configuration input, such as Info.plist keys or a config struct.
4. Resources are app-bundle-bound: fonts through `UIAppFonts` (ios:Masjid/Info.plist:10), and the shared asset catalog, sounds and Qur'an databases. They need `Bundle.module` rewrites.
5. There is no project template; the scaffolder only mutates the monorepo pbxproj (F25). XcodeGen or Tuist are absent.
6. No NSE template exists for non-Burlington targets (F13).
7. CI assumes this repo, never pushes, and Xcode Cloud needs a manual OAuth step per repo (F27, F31).
8. ASC record creation is manual (F32).
9. The tests are hosted by the `Masjid` target (ios:Masjid.xcodeproj/xcshareddata/xcschemes/MasjidTests.xcscheme:19,33).

## Risks to live clients

- **[R1] One code change ships to three orgs.** Any shared iOS change ships to Burlington (1), MEC (13) and NAFIS (5) together. A file left out of one target's membership breaks that one app's link (F3, F12).
- **[R2] Moving MEC to its own OneSignal app splits its audience.** Per-org OneSignal (D9) moves MEC subscribers to a new app. Installs that do not update stay in the shared app ([redacted]), so the backend must keep sending through the shared app for old builds. MEC and NAFIS also have no NSE today (F13, F18).
- **[R3] The TV app is Burlington's.** MasjidTV is Burlington's bundle and ASC record with id 1. Templating the shared target changes Burlington's TVs, and a cloud-built MEC TV ships Burlington (F9, F31).
- **[R4] Workflow statuses do not mean anything was committed.** A successful run leaves nothing in the repo (F27). If W3 adds a push, it would rewrite the pbxproj that all three live apps build from.
- **[R5] `ship-testflight.sh` touches every app.** It rewrites every target's build number and pushes before it archives, and its auto-bump is documented as broken (F29, F4).
- **[R6] Ship gate.** DECISIONS.md:88-90 applies to any iPhone upload from main.
- **[R7] No staging build.** Every iOS build talks to production (F20).
- **[R8] Committed key.** The Google API key in S.swift:16 would be copied into any client repo derived from this one.

## Unknowns

- **[U1] The TVOS-DESIGN.md §7 plan.** It is not in the snapshot or git history. Resolve by asking the owner or searching the other repos' `docs/`.
- **[U2] The full SHA of 8e5191f.** Resolve with `git -C ~/Developer/NewMasjidSystem-r0 rev-parse origin/main`.
- **[U3] Whether any Xcode Cloud workflow exists.** Resolve with ASC API `GET /v1/ciProducts` (read-only).
- **[U4] Who owns [redacted team] and [redacted team], and which one is Hope Tech's "managed" account** (F30). Resolve through Apple Developer team membership.
- **[U5] Whether the ASC_* secrets are set and whether provision-ios-app.yml has ever run.** Resolve with `gh secret list` and `gh run list --workflow provision-ios-app.yml`.
- **[U6] ASC status and app ids for NAFIS and MEC.** Resolve with ASC `GET /v1/apps`.
- **[U7] Which Xcode version produces release builds.** Resolve from the owner or the Xcode Cloud settings.
- **[U8] Whether any backend payload could carry a OneSignal id.** Agent D covers this. The iOS app decodes none (F18).
- **[U9] Whether Al-Razi, BISS or the QA sandbox appear as child orgs inside an existing app through `/orgs`.** Resolve from the backend's parent/child org data.

</engineering-excellence-recon>
