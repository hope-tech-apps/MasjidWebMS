<engineering-excellence-recon platform="android" repo="hope-tech-apps/burlington-masjid-Android">

# Recon: burlington-masjid-Android (origin/master cf61d54), for the Studio W2/W3 plan

> Persisted in MasjidWebMS, which is a PUBLIC repository. Identifier-shaped values (the OneSignal app id, Apple team ids, key ids) are redacted as `[redacted]`; read them in the private source repository at the cited line.

commit: cf61d54 (origin/master, per brief. The snapshot has no `.git`, so the full sha cannot be read.)
platform: android
scope: repo root, single module `:app` (`Qibla-Finder/` noted, not walked)
generated: 2026-09-24
dirty: unknown (snapshot, not a work tree)
mode: quick + briefed questions
kit: ~/.claude/engineering-excellence (detectors matched `android` only; read the version policy in `stacks/compose.md`)
elided: persistence, navigation detail, a11y, localization, test depth, performance (quick mode)

Path shorthand: `…/masajid/` = `android:app/src/main/java/com/app/masajid/`.

## Facts

**Toolchain and binding files**
- [F1] AGP 8.13.1, Kotlin 2.0.21, Compose BOM 2024.09.00, and Navigation-Compose 2.9.6, which is pinned on purpose (android:gradle/libs.versions.toml:4,8,16,19-21). Gradle wrapper 8.13 (android:gradle/wrapper/gradle-wrapper.properties:4).
- [F2] compileSdk 36, minSdk 26, targetSdk 36. versionCode 13 and versionName 2.8.1 are set in `defaultConfig`, so every flavor shares them. JVM target 11 (android:app/build.gradle:9-18,91-97).
- [F3] `compose.foundation` is declared at 1.9.5 outside the BOM (android:gradle/libs.versions.toml:7; android:app/build.gradle:124). So the Compose versions that actually resolve likely sit above what the BOM pins. This is inferred from the addendum's resolution rule; there is no lockfile and Gradle was not run.
- [F4] There is one Gradle module: settings includes only `:app` (android:settings.gradle:22-23). `Qibla-Finder/` is a separate vendored Gradle project with its own `settings.gradle.kts`, and the build does not include it.
- [F5] The UI is Compose plus MVVM, counted over `app/src/main` (pruned):
  - 149 Kotlin files, 18 `*ViewModel` classes, 37 files using `@Composable`.
  - 0 Fragments and 1 XML layout.
  - StateFlow in 27 files, LiveData in none.
  - No DI framework (0 hits for Hilt, Koin or Dagger).
  - Networking is `object RetrofitClient` with the constant `BASE_URL = "https://masjid.hopetechapps.com/"` (…/masajid/data/api/RetrofitClient.kt:64,66).
- [F6] Tests: 32 unit-test files and 3 instrumented. The repo's only workflow is provisioning, so CI runs no tests or lint. CLAUDE.md also says no lint gate exists (android:CLAUDE.md:63).
- [F7] Binding files:
  - android:CLAUDE.md: iOS is the source of truth (:69-71); themed accents use `Main`, never a hex value (:72-73); secrets stay in `~/.gradle/gradle.properties` (:78-80).
  - Path-scoped Claude rules android:.claude/rules/ios-parity.md:1-4 and notifications-inbox.md:1-4. They are enforced only as rules, with no lint or test behind them.
  - android:.claude/rules/release-signing.md and android:HANDOFF.md.
  - android:DECISIONS.md (1175 lines, 2026-07-02 to 2026-09-15) has no entry on provisioning, flavors or per-org OneSignal. Its OneSignal mentions concern the org-switch rule (:99,:198,:263).
- [F8] The binding files disagree on release state:
  - CLAUDE.md says Play submission is blocked on an upload-key reset (android:CLAUDE.md:94-95).
  - release-signing.md says no Play service account exists and every upload is manual (android:.claude/rules/release-signing.md:29-49).
  - HANDOFF records vc13 uploaded through a service account kept in a local file (path [redacted]; android:HANDOFF.md:64), live as the only production release since 2026-08-12 (android:HANDOFF.md:10,64,113-115).
  - CLAUDE.md and release-signing.md give `:app:bundleRelease`, which builds all flavors (android:CLAUDE.md:56; release-signing.md:26). HANDOFF uses `:app:bundleBurlingtonRelease` (android:HANDOFF.md:46).

**Variants**
- [F9] One flavor dimension, `masjid`, with three flavors (android:app/build.gradle:32-64):
  - `burlington`: base id `com.app.masajid`, MASJID_ID 1, "Burlington Masjid" (:34-42)
  - `nafis`: `.apex` suffix, MASJID_ID 5, "NAFIS Apex Mosque" (:43-51)
  - `mec`: `.mec` suffix, MASJID_ID 13, "Muslim Education Center". IntelliCor (16) is reached through the in-app org switcher, not a flavor (:52-63).
- [F10] Each flavor sets `buildConfigField` `MASJID_ID`, `DEFAULT_LAT` and `DEFAULT_LON`, plus `resValue app_name` (android:app/build.gradle:36-62). `MAPS_API_KEY` is a single shared manifest placeholder, read from a Gradle property (android:app/build.gradle:27; android:app/src/main/AndroidManifest.xml:31).
- [F11] `app/src/{nafis,mec}/res/` each hold:
  - launcher mipmaps in 5 densities plus adaptive XML
  - `drawable/ic_logo_green.png` (the in-app logo)
  - `values/colors.xml` `brand_primary` (MEC #2B66C2, android:app/src/mec/res/values/colors.xml:5)
  
  Burlington has no flavor directory and uses `main/res` (`brand_primary` #01B151, android:app/src/main/res/values/colors.xml:16).
- [F12] `HOME_MASJID_ID` is `BuildConfig.MASJID_ID`. `MASJID_ID` is the runtime org override (…/masajid/AppConfig.kt:15-19,78,86). `DEFAULT_LAT/LON` are read from BuildConfig (:101-102).

**OneSignal**
- [F13] The OneSignal app id is a string literal: `OneSignal.initWithContext(this, "[redacted]")` (…/masajid/MasajidApp.kt:94).
  - No flavor sets an id (android:app/build.gradle:33-64).
  - iOS initialises the same id (ios:Masjid/AppDelegate.swift:73).
  - Orgs are separated only by the tag `masjid_id = HOME_MASJID_ID` (MasajidApp.kt:105).
  - The only OneSignal value that crosses the API is the subscription id (…/masajid/data/models/DeviceRegistration.kt:29; MasajidApp.kt:245).
  - The full id also appears in android:scripts/SCAFFOLD-CHECKLIST.md:92.

**Runtime sources**
- [F14] Endpoints the app calls: `app-config`, the masjid show, `prayers/settings`, `features`, `announcements`, `events`, `splash`, `notifications`, `donation-link`, `funds`, `about`, `services`, `gallery`, `orgs`, and member auth (…/masajid/data/api/MasjidApiService.kt:51-249). It never calls `/menu`; the only match is a comment (AppConfig.kt:37).
- [F15] Theme colour comes from the masjid payload, is cached per masjid in SharedPreferences, and falls back to the flavor's `R.color.brand_primary` (…/masajid/ui/theme/MasjidThemeManager.kt:14-17,81,120; MasajidApp.kt:80-86).
- [F16] Logo and splash:
  - The logo comes from the API's `logo.original_url`. The bundled `ic_logo_green` is used only as home's fallback (…/masajid/ui/components/OrgLogo.kt:25-35,68).
  - The splash name is text, with the flavor `app_name` as fallback (…/masajid/ui/views/SplashScreen.kt:391-395).
  - The splash art `R.drawable.splash` exists only in main, so all flavors share it (:428).

**Scaffolder and CI**
- [F17] `scaffold_masjid_flavor.py` arguments:
  - Required: `--name` (must match `^[a-z][a-zA-Z0-9_]*$`), `--masjid-id`, `--app-name`.
  - Optional: `--application-id-suffix`, `--onesignal-app-id`, `--icon-background`, `--icon`.
  - Dry-run is the default; `--apply` writes (android:scripts/scaffold_masjid_flavor.py:58-60,350-375).
- [F18] What `--apply` writes:
  - A flavor block: dimension, optional suffix, `MASJID_ID`, `app_name`, and optional `String ONESIGNAL_APP_ID` (android:scripts/scaffold_masjid_flavor.py:139-162).
  - `strings.xml`, `ic_app_logo_background.xml`, two adaptive-icon XMLs, and a `PLACEHOLDER.txt` in each mipmap density (:305-336).
  
  It does not write `DEFAULT_LAT/LON`, `ic_logo_green.png` or `brand_primary`.
- [F19] Steps it leaves to a person (android:scripts/SCAFFOLD-CHECKLIST.md:53-158):
  - real icons
  - wiring OneSignal in MasajidApp.kt and adding the field to every flavor, plus the FCM credential in the OneSignal dashboard (:87-103)
  - a BYO signing config
  - the Play listing, data safety and content rating (:125-136)
  - build, upload and review
  - the per-flavor versionCode question (:121)
- [F20] android:.github/workflows/provision-android-app.yml:
  - Trigger: `repository_dispatch`, type `scaffold-masjid` (:34-36).
  - Runner: `[self-hosted, macOS]` (:43). Permissions: `contents: read` (:38-39).
  - Payload: `job_id, masjid_id, name, app_name, account_mode, flavor, application_id_suffix, onesignal_app_id, callback_url, callback_token` (:17-27,51-60).
  - Callback: Bearer-authenticated JSON POST. Statuses are `scaffolding | building | uploaded | built | failed` (:29-32,84-93).
- [F21] Build and upload steps:
  - It builds a signed `bundle<Flavor>Release` only if `BURLINGTON_UPLOAD_STORE_FILE` resolves on the runner; otherwise it only checks `assemble<Flavor>Debug` compiles (:150-172).
  - It uploads only when all four hold: account is managed, a signed AAB exists, the `PLAY_SERVICE_ACCOUNT_JSON` secret is set, and fastlane is installed. The call is `fastlane supply --track internal --package_name com.app.masajid${suffix}` with metadata and images skipped (:189-236).
  - Every other path reports `built` with "upload manually" (:197-212,238-241).
  - `artifact_url` is the AAB's path on the runner, not a downloadable URL (:177,229).
- [F22] The workflow contains no `git commit`, `git push` or `upload-artifact` (grep, 0 hits).
- [F23] The iOS snapshot has no `provision-android-app.yml`; its only workflow is `ios:.github/workflows/provision-ios-app.yml`. The Android workflow lives in the Android repo and runs on the same self-hosted Mac as iOS (android:.github/PROVISIONING-RUNNER.md:8-9,53-63).
- [F24] Backend side:
  - Event type is `scaffold-masjid` (app/Services/GithubDispatchService.php:30); target repo is `hope-tech-apps/burlington-masjid-Android` (config/services.php:69).
  - `flavor` = `Str::camel(slug)`, `application_id_suffix` = `'.'` plus the name slugged with no separator (app/Http/Controllers/AdminDashboard/AppProvisioningController.php:147-148,196-201).
  - `onesignal_app_id` comes from the request, else `masjid_app_publishing` (:205). `account_mode` defaults to `managed` (:154-156).

**Play publishing**
- [F25] `signingConfigs.release` reads the `BURLINGTON_UPLOAD_*` properties and is guarded so it still builds unsigned without them (android:app/build.gradle:66-90). The keystore sits outside the repo (android:.claude/rules/release-signing.md:9-16). All flavors use the one upload key. There is no Gradle Play Publisher plugin (android:build.gradle:2-6; android:gradle/libs.versions.toml:74-77).
- [F26] Play API work is done by hand-run calls with the `androidpublisher` scope, and the gotchas are recorded (android:HANDOFF.md:62-73). The repo's docs treat creating a listing as a manual Play Console step (android:scripts/SCAFFOLD-CHECKLIST.md:125-136; release-signing.md:35-36).

**masapp**
- [F27] Android-MAS-App ("Check it by MAS"):
  - An Android goal-tracking companion to an iOS MAS app, with an AWS API Gateway backend and OneSignal plus Firebase push (masapp:CLAUDE.md:5-10).
  - Package `com.mas.checkit` (masapp:CLAUDE.md:62-63; masapp:app/build.gradle:19,82). Base URL `…execute-api.us-east-1.amazonaws.com/prod/` (masapp:app/src/main/java/com/example/android_mas_app/services/APIConstants.kt:9).
  - GitHub org `tahasinc` (masapp:CLAUDE.md:71-72). A keystore is committed at the repo root with its password kept out of the repo (masapp:CLAUDE.md:47; not opened).
  - Its Python Play upload script reads the same local service-account file (path [redacted]; android:HANDOFF.md:64) (masapp:scripts/play_upload.py:30,46).
  - No mention of hopetechapps, Manara or masajid anywhere (grep, 0 hits).

## Answers

**0. Quick recon.**
- Covered by F1-F8: Compose plus MVVM, no DI, a Retrofit singleton, one module.
- The closest existing parallel for per-org work is the `mec` flavor, which has all the per-flavor pieces: fields, mipmaps, logo and colour (F9-F11).
- The scaffolder models itself on `nafis` (android:scripts/scaffold_masjid_flavor.py:5-14).

**1. Variant model.**
- Product flavors, 3 orgs: 1, 5, 13 (F9-F12).
- masjidId is fixed at compile time through BuildConfig. Child orgs are shown through a runtime override (F12).
- Al-Razi (14), BISS (18) and the QA sandbox (17) have no Android flavor.

**2. OneSignal.**
- The id is a hard-coded literal in the Application class. All flavors and the iOS app share it, and orgs are told apart only by the `masjid_id` tag (F13).
- Nothing reads an app id from the API.
- The scaffolder's `ONESIGNAL_APP_ID` field has no effect until MasajidApp.kt reads it and every flavor defines it (F18, F19).
- Per-org apps (D9) would need that code change, plus an FCM credential for each OneSignal app (SCAFFOLD-CHECKLIST.md:102-103).

**3. Config vs constant.**

| value | source | when |
|---|---|---|
| masjidId | flavor `MASJID_ID` | compile (F9, F12) |
| API base URL | `RetrofitClient` const, shared | compile (F5) |
| colours | API theme, flavor `brand_primary` fallback | runtime, compile fallback (F11, F15) |
| menu/features | `/features` | runtime (F14) |
| OneSignal id | literal, shared | compile (F13) |
| launcher icon | flavor mipmaps | compile (F11) |
| in-app logo | API logo, flavor `ic_logo_green` fallback | runtime, compile fallback (F16) |
| splash | name is text at runtime; art is shared; pop-up comes from `/splash` | mixed (F14, F16) |
| app name, fallback coords | `resValue`, `DEFAULT_LAT/LON` | compile (F10) |
| Maps key | Gradle property on the build machine | build (F10) |
| versionCode | `defaultConfig`, shared | compile (F2) |

**4. Scaffolder and CI.**
- Inputs and outputs are F17-F19. Provisioning runs in the Android repo's own workflow; `ios:.github/workflows/provision-android-app.yml` does not exist (F23). The trigger, payload and callbacks are in F20, and the build and upload in F21.
- Nothing it produces is kept. The workflow never commits (F22) and has only read permission, so the new flavor exists only for that run. The next run checks out master without it, and the app cannot be rebuilt later (inferred).
- A scaffolded flavor likely fails to compile. Main code reads `BuildConfig.DEFAULT_LAT/LON` (F12), which the scaffolder never emits (F18), so a real run would probably end `failed`. This is inferred; I did not build it (see U4).
- Even with that fixed, the new app would show Burlington's branding. Its icons, logo and colour fall back to Burlington's (F11, F18), which is the tenant-leak pattern recorded in android:HANDOFF.md:31-39 and …/masajid/ui/components/OrgLogo.kt:28-31.

**5. Play publishing.**
- Signing: F25.
- Uploads: hand-run Python against the Play API (android:HANDOFF.md:62-73), or `fastlane supply` from CI when the managed-account conditions hold (F21). There is no Gradle Play Publisher.
- The service-account JSON is a local file for manual runs (android:HANDOFF.md:64). In CI it is the `PLAY_SERVICE_ACCOUNT_JSON` secret, written to a temp file and deleted after the upload (workflow:215-234; android:.github/PROVISIONING-RUNNER.md:80-92).
- Creating a listing: the repo's docs say it is manual (F26). Whether the API can do it is unknown (U3).

**6. Shared module.** There is none; everything is in `:app` (F4). A config-only per-client repo would need:
- **A library.** Extract the app code into an Android library, published as a versioned AAR or pinned as a submodule or composite build. No publishing setup exists (android:build.gradle:2-6).
- **A client-supplied config object.** It would replace the BuildConfig reads (`MASJID_ID`, `DEFAULT_LAT/LON`, and the version-gate `VERSION_NAME/CODE` at …/masajid/ui/views/gate/AppGateViewModel.kt:42-43), because a library's BuildConfig cannot carry the client's values (inferred). The OneSignal id, base URL and Maps key would move into the same object (F5, F10, F13).
- **Client-owned files:** `applicationId`, `app_name`, mipmaps, `ic_logo_green`, `brand_primary`, splash art, signing and versionCode (F2, F9-F11). App resources overriding library resources by name is standard AGP merging (inferred).
- **A template repo and a workflow that creates and commits a repo.** Today the workflow patches the shared `app/build.gradle` and keeps nothing (F18, F22).
- **An id-namespace decision.** Every applicationId today derives from `com.app.masajid` (android:app/build.gradle:8; workflow:194).

**7. Android-MAS-App.** It is not part of Manara. It is a separate "Check it by MAS" goal-tracker (`com.mas.checkit`) for MAS, kept in the `tahasinc` GitHub org and backed by AWS API Gateway rather than MasjidWebMS (F27). It has no white-label or flavor model and no reference to Manara or hopetechapps. The one link is operational: its Python Play upload script reads the same local service-account path Burlington's releases used (masapp:scripts/play_upload.py:46; android:HANDOFF.md:64), so one Google service account appears to reach both consoles (inferred). The script is a working pattern for Play API uploads. Its release status: signed builds on this Mac are blocked because the keystore password is missing (masapp:CLAUDE.md:87).

## Risks to live clients

- [R1] **Burlington (1) and MEC (13) share one OneSignal app**, with iOS too (F13). Moving Android to per-org apps (D9) means a MasajidApp.kt release through each live listing. Devices still on an old build would stay on the shared app until the backend sends to both (inferred).
- [R2] **Re-provisioning a live org would create a second app.**
  - The backend derives flavor and suffix from the org name (F24). For Burlington and MEC these would not equal `burlington`/`mec`/`.mec` (inferred from AppProvisioningController.php:147-148), so the duplicate guard (scaffold_masjid_flavor.py:311-313) would not fire and a managed run would upload a new package, `com.app.masajid.<slug>`.
  - The workflow contract also allows an empty suffix, which would upload to Burlington's own `com.app.masajid` internal track (workflow:24,194). Only the backend's always-send-a-suffix default prevents this today (AppProvisioningController.php:201).
- [R3] **The runner Mac holds sensitive credentials.** It has Burlington's upload key and the Play service account (android:HANDOFF.md:64,75), and any `scaffold-masjid` dispatch runs repo code there (android:.github/PROVISIONING-RUNNER.md:75-76). Payload values are passed as environment variables, not pasted into scripts (workflow:47-48).
- [R4] **versionCode 13 is shared by all flavors** (F2). Shipping MEC or NAFIS moves the number Burlington's listing tracks.
- [R5] **Stale binding docs** (F8). A session that trusts release-signing.md would skip the service-account route, and one that follows CLAUDE.md would build all three flavors.
- [R6] **NAFIS (masjid 5)** has a flavor and the package `com.app.masajid.apex` but is not on the live-org list. Every shared-code change ships into it, and its Play status is unknown (U5).

## Unknowns

- [U1] The full sha and dirty state of cf61d54. Resolve with `git rev-parse origin/master` in a real clone.
- [U2] Whether the self-hosted runner is registered for the Android repo, whether `PLAY_SERVICE_ACCOUNT_JSON` is set, and whether fastlane is on the Mac. Resolve by reading the repo's GitHub runner and secret settings and running `fastlane --version` on the Mac.
- [U3] Whether a Play app or listing can be created by API. The repo's docs only say it is manual. Resolve against the Play Developer Publishing API reference.
- [U4] Whether a scaffolded flavor really fails on `DEFAULT_LAT/LON`. Resolve by scaffolding with `--apply` and running `assemble<Flavor>Debug` in a scratch clone.
- [U5] Play status of `com.app.masajid.apex` and `com.app.masajid.mec`; HANDOFF covers only `com.app.masajid` (android:HANDOFF.md:111). Resolve with the Play API tracks listing for each package.
- [U6] Whether `fastlane supply` can upload to a package that was created in the Console but has never had a release. Resolve from Play docs or a test app.
- [U7] The Compose versions that actually resolve (F3). Resolve with `./gradlew :app:dependencies` in a scratch clone.
- [U8] Whether the Android provisioning workflow has ever run. Resolve with `gh run list --workflow provision-android-app.yml` on the Android repo, or `provisioning_jobs` rows where platform is android.

</engineering-excellence-recon>
