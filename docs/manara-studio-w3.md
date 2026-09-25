# Manara Studio W3: build plan

**Status:** plan, not started. Written 2026-09-24 by a delegated planning session
for whoever builds W3. Planning only: nothing here has been built, deployed or
tried against a live system.
**Contract:** `docs/manara-studio.md`. Decisions D1–D17 are settled, and so are
two owner calls from 2026-09-24: app-store accounts default to Hope Tech's own
(managed), with BYO as the exception, and mobile work means iOS **and** Android.
**Brief:** `docs/manara-studio-w2-w3-brief.md`.
**Follows:** `docs/manara-studio-w2.md`. W3 assumes W2 is complete: a OneSignal
app per client, apps that read their OneSignal id from configuration, a job
plane that knows `tvos`, the D11 board, and per-organisation TV targets.
**Inputs:** the same seven recon reports as W2 (the iOS and Android ones are
persisted as `.claude/ios-recon.md` and `.claude/android-recon.md`; the rest, the
vendor facts and the adversarial review of this plan's draft are in
`docs/manara-studio-w2-w3-recon/`), vendor documentation read on 2026-09-24,
and read-only GitHub metadata.
**Line numbers** come from the recon: MasjidWebMS `c3fc0324`, iOS `origin/main`
`8e5191f`, Android `origin/master` `cf61d54`, renderer `origin/main` `6a7ead2`.
W2 will have moved some of them, so re-read before editing.
**This repository is public** (`gh repo view`: `PUBLIC`). Nothing here is a
secret. Every repository W3 creates is private.

**Path prefixes:** as in W2. A bare path is MasjidWebMS; `renderer:`, `ios:` and
`android:` are the other three repos.

---

## 0. What W3 delivers

W3 is spec §5 "W3 — Repos and export" (`docs/manara-studio.md:242-245`):

- finish the iOS-onto-MasjidKit refactor (D5);
- per-client repo creation from a template;
- the standalone web export (D2);
- the source download;
- the three store toggles (D10).

Because mobile means both platforms, W3 also gives Android the same shape: a
versioned library that client repos pin.

**Exit criterion.** For a new client provisioned in Studio:

1. **Repos.** Studio creates private `manara-<slug>-ios`, holding the iPhone
   and TV targets, and `manara-<slug>-android`, both from templates.
   - Each holds only configuration and assets, plus exactly one read-only
     secret for its private dependency (R15).
   - Each pins MasjidKit (iOS) or `masjidkit` (Android) at a released version.
   - Each builds green on CI.
   - A MasjidKit fix reaches every client as one version-bump pull request per
     client repo (D5's reason for existing).
2. **Web export.** The SuperAdmin can export the client's website as a private
   single-tenant repo, with a handover note that states honestly what still
   depends on Manara (D2).
3. **Source download.** The SuperAdmin can download a client repo's source
   against a recorded handover reference (D3).
4. **Store toggles.** Each of the three, default OFF, does everything the
   store's API allows and walks a person through what it does not.
   - It registers the bundle id and push capability, then confirms the App
     Store record once a person has created it.
   - It confirms the Play app once a person has created it and uploaded its
     first build.
   - It uploads later builds to TestFlight, including tvOS, and to Play's
     internal track.

Throughout, the live apps of Burlington (1), NAFIS (5) and MEC (13) keep
working. Each refactor slice reaches them only through a TestFlight or internal
build, a regression walk, and the owner's go.

**Four things that shape W3, found while planning it.**

- **Neither store can create an app through its API.**
  - App Store Connect's `apps` resource supports list, read and update only:
    "Don't use this API to create new apps; instead, create new apps on the App
    Store Connect website" (developer.apple.com/documentation/appstoreconnectapi/apps,
    read 2026-09-24).
  - The Google Play Developer API cannot create a new public app. Its Custom
    App Publishing API creates only private enterprise apps, which "can't be
    made public" (developers.google.com/android/work/play/custom-app-api).
  - So two of D10's toggles cannot be fully automatic. §2 R1–R3 record how W3
    handles that.
- **The standalone web export cannot be independent of Manara.** Every page the
  renderer draws reads the Manara API at request time, and prerendering is
  forbidden by policy and by CI (`renderer:app/stores/app.ts:374-411`;
  `renderer:nuxt.config.ts:251-260`; `renderer:.github/workflows/build.yml:45-64`).
  §2 R4.
- **MasjidKit cannot be pinned by client repos as it stands.**
  - It is a local-path package in a subdirectory, with no tags
    (`ios:Masjid.xcodeproj/project.pbxproj:2932-2934`; iOS recon F15).
  - The iOS app does not import it at all. 152 files, about 27,000 lines
    (a counted estimate, iOS recon F16), live in the app target.
  - Two dependencies float on branches: `quran-ios` on `main`, and `Popover` on
    `master`, which arrives through `quran-ios`'s own manifest
    (`ios:Masjid.xcodeproj/project.xcworkspace/xcshareddata/swiftpm/Package.resolved:90, :99-100`).
    The app uses `quran-ios` only for its fonts (R6).
- **GitHub writes do not belong on the public web server.** Creating
  repositories needs a credential that can also delete them, re-publish them,
  or push to the live apps' repos. W3 keeps every such credential in two
  private ops repos, which the server can only start (R10).

---

## 1. Slice map

| # | Slice | Repo | Risk to live tenants | Hard deps | Size (estimate) |
|---|---|---|---|---|---|
| **A** | **iOS onto MasjidKit (D5)** | | | | |
| S1 | Per-org constants become configuration; the unused committed key is deleted | iOS | high: ships in three live iPhone apps; behaviour identical | W2 S15 | ~1.5 sessions |
| S2 | Remove `quran-ios`; its fonts become resources | iOS | low–medium: the app's font registration | — | ~1 session |
| S3 | Models and networking converge on MasjidKit | iOS | high: every screen's data layer in three live apps | S1 | ~3 sessions |
| S4 | UI foundations move into a `MasjidKitUI` product | iOS | high | S3 | ~2 sessions |
| S5 | Features move into `MasjidKitUI`, in five batches | iOS | high | S4 | ~6 sessions |
| S6 | Resources move to the package bundle | iOS | high: fonts, Qur'an databases, sounds | S5 | ~2 sessions |
| S7 | The app targets become shells; a `MasjidKitTV` product for the TV | iOS | high | S6 | ~1.5 sessions |
| S8 | MasjidKit moves to its own repo, with tags | iOS, MasjidKit | medium: same code, remote pin; every checkout now needs read access to MasjidKit | S2, S7 | ~1.5 sessions |
| **B** | **Android equivalent** | | | | |
| S9 | A `:masjidkit` library module; configuration replaces BuildConfig | Android | high: three live Android flavors | W2 S15 | ~4 sessions |
| S10 | Publish `masjidkit` as a versioned private package | Android | medium: the live apps then build against a downloaded binary | S9 | ~1.5 sessions |
| **C** | **Per-client repos** | | | | |
| S11 | The client templates | new template repos | none: new private repos | S8, S10 | ~3 sessions |
| S12 | The ops plumbing, and Studio creating client repos | MasjidWebMS, new `manara-repo-ops` | low: SuperAdmin-only; no GitHub write credential on the web server | plumbing: none; mobile repos: S11; owner: the ops credentials | ~3.5 sessions |
| S13 | Studio's Repositories panel; each org's code home | MasjidWebMS (SPA + backend) | low | S12 | ~1.5 sessions |
| S14 | One version bump, a pull request per client | MasjidWebMS, `manara-repo-ops` | low–medium: opens pull requests in client repos | S12 | ~1.5 sessions |
| S15 | Move one existing Studio client to its own repos (the proof) | MasjidWebMS, iOS, Android | medium: a live client's code home moves; its identity does not | S13 | ~2 sessions |
| **D** | **Web export (D2)** | | | | |
| S16 | The renderer's export mode | renderer | medium: tracked `.env` leaves git; build inputs must not change | — | ~2 sessions |
| S17 | Studio exports a client's website | MasjidWebMS, `manara-repo-ops` | low | S12 (plumbing), S16 | ~2 sessions |
| **E** | **Source download (D3)** | | | | |
| S18 | Source download against a handover reference | MasjidWebMS, `manara-repo-ops` | low: SuperAdmin-only, ledgered | S12 | ~1.5 sessions |
| **F** | **Store toggles (D10)** | | | | |
| S19 | The store-operations repo, the toggle model and Step 3's switches | new `manara-store-ops`, MasjidWebMS | low: switches default OFF | S12 (plumbing) | ~2 sessions |
| S20 | App Store record: register automatically, create by hand, confirm automatically | store-ops, MasjidWebMS | medium: writes to Hope Tech's Apple developer account | S19 | ~2.5 sessions |
| S21 | Play app: create by hand, first upload by hand, confirm automatically | store-ops, MasjidWebMS | medium: a new upload key per client | S19 | ~2 sessions |
| S22 | Upload builds: TestFlight (iPhone and TV) and Play's internal track | store-ops, MasjidWebMS | medium–high: uploads to the developer accounts that hold the live apps | S20, S21 | ~2 sessions |

- **Total:** about 49 builder sessions. This is an estimate. Most of the
  uncertainty is in S5 and S9, which move code that has not been read
  file by file.
- **S16 can start at once.** S17 and S18 need only S12's ops plumbing, which does
  not wait for the refactor: mobile repo creation is the part of S12 that
  needs S11.
- **The refactor (A, B) has no user-visible benefit on its own.** It exists
  so that C, E and F can exist. Each of its slices ships through the ordinary
  release of the three live apps, not a release of its own (§3).
- **Nothing in F runs before a person switches it on for one client**, and
  every step refuses a bundle id or package name that a live app uses.

---

## 2. Contract conflicts resolved

Each row picks one side and gives the reason in one sentence.

| # | Question | Positions | Chosen | Why |
|---|---|---|---|---|
| R1 | D10's "create the App Store record" | Automate it (D10) vs the API has no create (developer.apple.com/documentation/appstoreconnectapi/apps); only an Apple ID session can create one (fastlane `produce`) | **A guided step.** Studio registers the bundle id, its push capability and the App Store profiles through the API, shows a person the exact values to create the record on the website, then confirms the record through the API | Automating with an Apple ID means storing a password and a two-factor session, which is a credential this system must never hold. |
| R2 | D10's "create the Play listing" | Automate it vs the Play API cannot create a public app (developers.google.com/android-publisher; the Custom App Publishing API makes only permanently private apps) | **A guided step**, confirmed through the API afterwards | The only automatic path makes an app that can never be public, which is not what a client wants. |
| R3 | D10's "upload a first build" on Android | Automate it vs the Play API cannot address a package before one build was uploaded through the Console (fastlane `supply` setup docs) | **The first Android upload is part of R2's guided step.** Studio supplies the signed bundle. The toggle automates every later upload | That is the earliest point the API can act. |
| R4 | What D2's export is | A site "not managed by Manara once handed over" (`docs/manara-studio.md:93-98`) vs a renderer that reads the Manara API on every request, with prerendering forbidden (`renderer:app/stores/app.ts:374-411`; `renderer:nuxt.config.ts:251-260`) | **Frozen code, live data.** The export is the renderer at a pinned commit, configured for one tenant. Manara stops maintaining the code; the content is still what the client edits in Manara's admin, and Manara's API still serves it. The handover note says this in plain words. A static snapshot is not offered (§8 OQ1) | A snapshot would silently stop showing prayer times, events and forms, and D2's own reason is that nobody should get a frozen site by accident. |
| R5 | How client repos pin MasjidKit | As it is (a local path, no tags) vs a remote package at a version | **Its own private repo, `hope-tech-apps/MasjidKit`, with semver tags**, extracted with history once the refactor is done (S8) | SwiftPM needs a package's `Package.swift` at a repository's root, so a subdirectory cannot be pinned remotely. |
| R6 | The floating dependencies | Pin `quran-ios` (branch `main`) and its transitive `Popover` (branch `master`) vs remove them | **Remove `quran-ios`, and with it `Popover`.** The app uses it only for the NoorFont fonts (`ios:Masjid/AppDelegate.swift:9, :55`); the Qur'an reader is the ported WirdReader on system SQLite (`ios:.claude/rules/quran-reader.md:7-20`). The fonts become package resources | A package required at a version cannot depend on branch- or revision-based packages under SwiftPM's resolution rules, and `Popover` comes in through `quran-ios`'s own manifest, not the project (`ios:Masjid.xcodeproj/project.pbxproj:2939-3000` lists eight packages and no Popover), so pinning at the project level could not reach it. |
| R7 | D5 names only iOS (`manara-<client>-ios`) | iOS only vs both platforms | **Both.** Android gets a `:masjidkit` library published as a versioned private package (S9, S10) | The owner's standing call is that mobile means both, and a per-client Android repo needs the same shape to be "config only". |
| R8 | Where a client's TV target lives | Its own repo vs the client's iOS repo | **In `manara-<slug>-ios`**, sharing its configuration, linking `MasjidKit` and a new `MasjidKitTV` product, never `MasjidKitUI` | It ships on the same App Store record (W2 R2), and the phone UI's dependencies (OneSignal, SafariServices) are not tvOS frameworks. |
| R9 | Where store credentials live and store jobs run | The web server vs each client repo vs one central repo | **One private repo, `manara-store-ops`**, holding every store credential as repository secrets and running every store job | On GitHub Free, organisation secrets are not available to private repos (docs.github.com, "Using secrets in GitHub Actions"), and D10's blast radius is smallest when exactly one place can publish. |
| R10 | Where GitHub write credentials live | A GitHub App with Administration and Contents write, its key on the production web server vs no GitHub write credential on the web server | **None on the web server.** Every GitHub write (create a repo, commit configuration, open a bump pull request, build an export, archive a source download) runs as a workflow in a second private repo, **`manara-repo-ops`**, which holds the credential that can create repositories. The web server holds one fine-grained token, `GITHUB_OPS_TOKEN`, with **Actions: read and write on those two ops repos only**. It starts their workflows with validated inputs and reads their results | MasjidWebMS is public and its web server faces the internet. A key there that can create, delete, re-publish or push to any repo in the organisation, the live apps' included, is a larger blast radius than D10 ever accepted, and Actions-only access cannot change code or read a secret. |
| R11 | How a source download is delivered | Streamed through PHP vs a short-lived download link | **`manara-repo-ops` builds the archive as a workflow artifact kept for one day. The server asks GitHub for the artifact's download location without following it (`withoutRedirecting()`), and hands that URL to the SuperAdmin's browser, never logging it** | Streaming a repository through one PHP-FPM worker on a 2 GB droplet is the capacity risk already recorded for video (ASSUMPTIONS.md row 18), and the server never needs read access to a client repo. |
| R12 | Existing monorepo clients | Migrate all at W3 vs one at a time | **One as the proof (S15). Each of the others is its own owner decision, with Burlington last** | Moving a live app's code home changes nothing a user sees, but it changes how every future fix reaches that app. |
| R13 | BYO store credentials | Wire them into store-ops vs leave them unused | **Unused in W3. The toggles are managed-only**, and a BYO client's repo builds while its own team publishes. D1's "BYO credential handling is kept" is not changed | Sending a client's `.p8` to a runner is a new blast radius that D10 did not ask for. |
| R14 | How an Xcode project is templated | A per-client generated project file vs one fixed project file driven by configuration | **One fixed `.xcodeproj` in the template. Every per-client value lives in `Config/Client.xcconfig`, `Info.plist` keys and the asset catalogue** | D4: "a known-good template with the client's config … written in", and a project file no generator touches cannot be generated wrong. |
| R15 | What a client repo may hold | "No secret" vs what a build of a private dependency needs | **Exactly one secret each**, installed by `manara-repo-ops` when it creates the repo: for iOS, a **read-only deploy key on `MasjidKit`**; for Android, a **classic token with `read:packages` only**, from a machine account. A test pins that nothing else is ever written | `MasjidKit` and the `masjidkit` package are private, a repo's `GITHUB_TOKEN` cannot read another private repo, and GitHub Packages grants per repository, not per package (vendor behaviour; confirm at build time). The narrowest credential that lets a client build is read access to one package. |
| R16 | How the tvOS build is signed | Automatic signing (as the iPhone build) vs the recipe the iOS repo records | **Manual signing with a distribution certificate and a `TVOS_APP_STORE` (and `IOS_APP_STORE`) profile made through the API, in an isolated keychain** (`ios:.claude/rules/appstore-ship.md:113-121`) | The repo records that automatic signing fails for tvOS with "no devices … no tvOS App Development profiles" on the managed team. |

---

## 3. Ship paths

### 3.1 MasjidWebMS and the renderer

As W2 §3.1 and §3.2: W1's ship paths, the suite on the droplet, the staging
walk, RBI, and ABI including masjid 5.

### 3.2 iOS repo, for slices S1–S8

As W2 §3.3, with three additions:

- **Every refactor slice ends with a TestFlight build of all three iPhone
  targets and the TV target, and a regression walk of each on a device**:
  - home, prayer times and iqama;
  - announcements, events and gallery;
  - donate;
  - sign-in and the member area;
  - the side menu and tabs, as `/menu` serves them;
  - the Qur'an reader;
  - push registration.
  
  The walk is written into `LOG.md` with the build numbers.
- **Every refactor slice leaves all three apps shippable,** and its walk is the
  proof. Ordinary releases from `main` therefore continue between slices; the
  refactor never holds `main` hostage. Whether the owner would rather let the
  moves reach the App Store together, in one reviewed release after S7, is
  §8 OQ3.
- **Target membership.** A moved file leaves every target's Sources phase, and
  the package owns it. Each pull request lists the files it moved.

### 3.3 MasjidKit repo (from S8)

- Private: `hope-tech-apps/MasjidKit`.
- Semantic-version tags. `CHANGELOG.md` per release.
- Its CI runs `swift test` for the iOS and tvOS simulators on every pull
  request.
- A release is a tag plus S14's bump pull requests.
- Every checkout of the iOS repo now needs read access to it: the owner's own
  release machine, and Xcode Cloud if it exists (S8).

### 3.4 Android repo and the `masjidkit` package

- As W2 §3.4.
- The package is published to GitHub Packages (Maven), private, per tag.
- Client repos resolve it with R15's token.

### 3.5 The ops repos, the templates and client repos

- All are private, in `hope-tech-apps`.
- **`manara-repo-ops` and `manara-store-ops`** run only on `workflow_dispatch`,
  never on push or pull request, so a pushed change cannot start a workflow
  that holds their secrets. Their workflow files change only through a
  reviewed pull request by a person.
- **The templates** are marked `is_template`. Each builds itself against a
  sample configuration, the QA sandbox (masjid 17).
- **Template changes** reach existing client repos only as pull requests a
  person writes, never pushed into a client repo directly. `studio:bump` (S14)
  moves only the pinned kit version.
- **Client iOS CI runs on hosted macOS**, metered on the Free plan. It
  therefore runs on pull requests that change `Package.resolved`, the
  configuration or the assets, and on the default branch, and never on other
  pushes.
  - Per-bump cost: a simulator build of two targets, roughly 10–15 minutes,
    billed at the macOS rate. That is an estimate, not a measurement; read the
    real figure from the first bump.
  - §8 OQ6 is the runner decision.
- **Client Android CI runs on `ubuntu-latest`**, which is not multiplied.

---

## 4. Preflight reads

Every read is read-only. The owner runs any that touch an account.

| Read | Gates | How |
|---|---|---|
| **The owner confirms that MasjidWebMS is meant to be public** (W2 §7) | everything in W3 | The owner's answer, recorded in DECISIONS.md. W3 writes nothing secret here in either case, but the answer governs how much of the ops design is documented in this repo |
| W2 is complete on every repo | all | `git log` on each `main`; W2's exit walk in `LOG.md` |
| The Actions minutes used and included for `hope-tech-apps` (Free plan) | S11, S12, S19–S22 | Billing page (organisation admin) |
| The managed Apple team's distribution certificates, and **which live profiles depend on each** | S20, S22 | The Certificates and Profiles pages, or ASC API `GET /v1/certificates` and `GET /v1/profiles`. The iOS repo records that the app's team had no distribution certificate at the time of the tvOS ship (`ios:.claude/rules/appstore-ship.md:113-121`), and that the owner's team was at its maximum (`ios:XCODE-CLOUD.md:3-9`) |
| The role of the App Store Connect API key (the iOS repo holds it as `ASC_KEY_ID` and related secrets; names from `gh secret list`) | S20 | ASC › Users and Access › Integrations. Registering bundle ids and profiles needs Admin or App Manager |
| Whether the Play service account has account-wide access | S21, S22 | Play Console › Users and permissions. **Account-wide access is not the goal:** W3 grants it per app (S21) |
| What the renderer's tracked `.env` holds, and whether the manual production build reads it | S16 | The owner opens `renderer:.env` locally. If it holds a secret, rotate it (renderer recon F14) |
| NAFIS's and MEC's store status (App Store and Play) | S15's choice of proof client | ASC `GET /v1/apps`; Play tracks (iOS recon U6, Android recon U5) |

---

## 5. Slices

### Group A: iOS onto MasjidKit (D5)

**What exists** (iOS recon F14–F17):

- MasjidKit is one product: models, an API client and the prayer engine, about
  1,000 lines. Only MasjidTV links it.
- The iOS app owns 152 Swift files, about 27,300 lines (a counted estimate):
  - Models: 55 files, 8,446 lines;
  - Views: 69 files, 14,805 lines;
  - Modifiers: 14 files, 2,007 lines;
  - Helpers: 7 files, 1,917 lines;
  - Config: 5 files, 145 lines.
- The TV app's own code, `MasjidTV/{App,Data,Signage}`, is about 1,200 lines.
- Four models exist twice, once in the app and once in the kit:
  `Announcement`, `Gallery`, `Masjid` and `Response`.
- The refactor plan the code cites, `TVOS-DESIGN.md` §7
  (`ios:MasjidKit/Package.swift:7-9`), is in neither the repo nor its history
  (iOS recon U1).

**The target shape.** The package exports four products.

| Product | Holds | Platforms | Used by |
|---|---|---|---|
| **`MasjidKit`** | models, networking, the prayer engine, configuration | iOS and tvOS | phone and TV |
| **`MasjidKitUI`** | every phone screen, the app shell, the theme, the phone's resources | **iOS only** | phone |
| **`MasjidKitQuran`** | the Qur'an reader and its databases | iOS | phone |
| **`MasjidKitTV`** | the signage board: data, board views, TV theme | tvOS | TV |

- **`MasjidKitUI` is iOS-only** because it imports OneSignal, SafariServices and
  other frameworks that tvOS does not have (W3 review).
- **`MasjidKitQuran`** is kept apart so that its large databases load only
  where they are used. The reader is the ported WirdReader on system SQLite,
  and the binding rule is not to rewrite its engine
  (`ios:.claude/rules/quran-reader.md:7-20`).

Each app target becomes a shell: an `@main` that hands a
`MasjidKitConfiguration` to `MasjidKitUI` (phone) or `MasjidKitTV` (TV), an
`Info.plist`, an xcconfig and an asset catalogue.

---

### S1: Per-org constants become configuration; the unused committed key is deleted (iOS)

**Facts.**

- The per-org and per-environment constants a new client target must set (iOS
  recon, answer to Q4):
  - `BuildMasjid.masjidId` (`ios:Masjid/Config/AppConfig+MasjidID.swift:37`);
  - the API base URL, which lives in a class named `DevelopmentServer` but
    points at production (`ios:Masjid/Models/S.swift:12, :58`);
  - the deletion page (`ios:Masjid/Models/Member/MemberAPI.swift:25`);
  - the background task id (`ios:Masjid/AppDelegate.swift:64`), which
    `BGTaskSchedulerPermittedIdentifiers` must match
    (`ios:Masjid/Info.plist:30-32`);
  - the fallback colour `#01B151` (`ios:Masjid/Helpers/CLAUDE.md:20-25`).
- A Google API key is declared at `ios:Masjid/Models/S.swift:16` and **read
  nowhere else in the repo** (a search for its name finds only the
  declaration).
- The scaffolder writes a `BuildMasjid+<Slug>.swift` file and no `MASJID_ID`
  (`ios:scripts/scaffold_masjid_app.rb:336-338`).

**Contract.**

- `MasjidKit` gains `MasjidKitConfiguration`, a value type with `masjidId`,
  `apiBaseURL`, `deletionPageURL`, `backgroundRefreshTaskId`,
  `fallbackBrandHex`, `oneSignalAppId` (W2 S15) and `displayName`.
- **`MasjidKitConfiguration.fromBundle(_:)`** reads `Info.plist` keys fed by
  build settings.
  - A missing `masjidId`, API base URL or OneSignal id shows a full-screen
    "This app is not configured" error, in every build configuration.
  - **It never falls back to a live organisation's id.** A default of 1 would
    show Burlington, which is landmine 1 in another form.
  - W2 S15's build check also refuses to build without the OneSignal id.
- **Each of the three iPhone targets sets its values to exactly today's.** The
  `BuildMasjid*.swift` files are deleted, and `masjidId` comes from each
  target's `MASJID_ID` setting.
- **The scaffolder changes in the same pull request.** It emits `MASJID_ID` and
  every other required key instead of a `BuildMasjid+<Slug>.swift` file, so W2's
  generation plane keeps producing complete targets.
- **The unused Google key is deleted from the source.** It has sat in the
  private repo's history, so rotating it is advisable; that is the owner's
  action. Nothing replaces it, and no client repo will carry it.

**Tests.**

- `ConfigurationTests`:
  - `every_iphone_target_defines_every_required_key` (it parses the project file);
  - `values_equal_todays_constants_for_each_org` (a fixture holding the three
    organisations' current values, with no secret in it);
  - `a_missing_masjid_id_shows_the_configuration_error_and_never_a_live_org`.
- `scripts/test_scaffold.rb`: `it_emits_masjid_id_and_every_required_key`.
- The `MasjidTests` scheme passes unedited.

**Live impact.** Behaviour is identical in all three apps.

**Verify.** §3.2's walk on TestFlight builds of all three. Each app talks to the
same host and registers push with the same id.

**Size.** ~1.5 sessions (estimate).

---

### S2: Remove `quran-ios`; its fonts become resources (iOS)

**Facts.**

- `quran-ios` tracks the `main` branch (`Package.resolved:99-100`). The app
  imports it only for `NoorFont` and calls `FontName.registerFonts()`
  (`ios:Masjid/AppDelegate.swift:9, :55`).
- `Popover` (`Package.resolved:90`) is not one of the project's package
  references (`ios:Masjid.xcodeproj/project.pbxproj:2939-3000`). It arrives
  through `quran-ios`.

**Contract.**

- Copy the NoorFont font files the app actually registers into the app's
  resources, and register them the way the app registers its other fonts.
  The files keep their upstream licence, which is recorded beside them.
- Remove the `quran-ios` package reference. `Popover` leaves
  `Package.resolved` with it.
- No other dependency changes in this slice.

**Tests.** `FontRegistrationTests`: every font name the Qur'an and prayer
screens use resolves after launch. The Qur'an reader's walk passes.

**Live impact.** Low–medium. A missing font falls back to the system font, and
the walk would show it.

**Size.** ~1 session (estimate).

---

### S3: Models and networking converge on MasjidKit (iOS)

**Contract.**

- The iOS app imports `MasjidKit` for every model the kit already has.
- The four duplicated models are deleted from `Masjid/Models/ObjectModels`, but
  only after a decoding-parity test shows the kit's decoders accept every
  payload the app's did.
- The app's router, HTTP layer and the remaining 51 model files move into
  `MasjidKit`, behind the same public API the screens call. The iqama fixture
  then exists once (iOS recon F16).
- The kit's networking honours `MasjidKitConfiguration.apiBaseURL`.

**Tests.**

- `DecodingParityTests`, run against a fixture recorded from **staging's**
  scrubbed data for each endpoint in the launch set (iOS recon F21):
  app-config, masjid, features, prayers/settings, menu, orgs, splash, events
  and donation link.
  - Each fixture must decode under the kit.
  - The decoded values must equal what the app's old decoder produced, field
    by field.
- The existing app tests pass.

**Live impact.** High in principle: this is every screen's data. The parity
test and the walk carry it.

**Size.** ~3 sessions (estimate).

---

### S4: UI foundations move into `MasjidKitUI` (iOS)

**Contract.** A new product, `MasjidKitUI`, iOS only, receives
`Masjid/Modifiers` and `Masjid/Helpers`:

- the theme: `Color.main` reads the configured fallback when the organisation
  has no colour (`ios:Masjid/Helpers/CLAUDE.md:20-25`);
- `Settings` (`ios:Masjid/Models/Settings.swift`);
- `MenuStore` (`ios:Masjid/Models/Menu/MenuStore.swift`).

Singletons keep their names, and are created from the configuration.

**Tests.** Unit tests move with their code, and the walk runs.

**Size.** ~2 sessions (estimate).

---

### S5: Features move into `MasjidKitUI`, in five batches (iOS)

**Contract.** Five pull requests, each shippable and walked on its own:

1. Home and prayer, including iqama and Jumu'ah.
2. Announcements, events, gallery and the splash announcement.
3. Giving: donate, funds and the donation link.
4. Member and auth, the side menu, tabs and the app shell
   (`ios:Masjid/Views/Shell/AppShellView.swift`).
5. The Qur'an reader into `MasjidKitQuran`; qibla, tasbih, adhkar and hadith
   into `MasjidKitUI`.

In every batch, each view exposes the smallest `public` surface the shell needs.

**Tests.** Each batch keeps its tests green. It also adds a snapshot of each
top-level screen, using Burlington's configuration, recorded before the move
and compared after it. This uses swift-snapshot-testing, a new test-only
dependency.

**Live impact.** High. It is mitigated by the per-batch walk, and by §3.2's rule
that each batch leaves all three apps shippable.

**Size.** ~6 sessions (estimate). This is the largest uncertainty in W3: the 69
view files have not been read one by one.

---

### S6: Resources move to the package bundle (iOS)

**Facts.**

- Fonts are registered through `UIAppFonts` (`ios:Masjid/Info.plist:10`).
- The asset catalogue, the sounds and the Qur'an databases live in the app
  bundle (iOS recon, answer to Q7, item 4).

**Contract.**

- **Fonts** move to `MasjidKitUI`, NoorFont's included, and are registered at
  start-up with `CTFontManagerRegisterFontsForURL`.
- **Lookups:** every `Image("…")`, sound and database lookup reads
  `Bundle.module`.
- **Per-client assets stay in the app, as named overrides:** the app icon, the
  TV icon stack, the fallback logo and the splash art. The kit looks each one
  up in the main bundle first.
  - The kit's own defaults for these are **neutral**, never Burlington's.

**Tests.** `ResourceLookupTests`: every named asset the kit references resolves,
either in `Bundle.module` or in the documented per-client override list.

**Size.** ~2 sessions (estimate).

---

### S7: The app targets become shells; a `MasjidKitTV` product for the TV (iOS)

**Contract.**

- **`MasjidKitTV`**: `MasjidTV/Data` and `MasjidTV/Signage` move into this new
  tvOS product. It depends on `MasjidKit` only.
- **Each iPhone target** holds only four things:
  - an `@main` that hands `MasjidKitConfiguration.fromBundle(.main)` to
    `MasjidKitUI`;
  - its `Info.plist`;
  - its xcconfig;
  - its assets.
- **Each TV target** holds the same four, with its `@main` handing the
  configuration to `MasjidKitTV`.
- The `MasjidTests` host becomes a package test target (iOS recon, answer to
  Q7, item 9).

**Tests.**

- `ShellShapeTests`: each target's Sources phase lists only its shell file.
- `every_tv_target_links_only_masjidkit_and_masjidkittv`.

**Verify.** The full walk on all three apps, and on Burlington's TV.

**Size.** ~1.5 sessions (estimate).

---

### S8: MasjidKit moves to its own repo, with tags (iOS, MasjidKit)

**Contract.**

- **Extract:** `git subtree split --prefix=MasjidKit` into the private
  `hope-tech-apps/MasjidKit`, keeping its history. Tag it `1.0.0`.
- **Switch:** the iOS repo's project changes from `XCLocalSwiftPackageReference`
  to a remote reference at `exactVersion: 1.0.0`. The `MasjidKit/` directory is
  deleted from the iOS repo in the same pull request.
- **Post-clone check:** `ci_post_clone.sh` checks that MasjidKit is present
  (`ios:ci_scripts/ci_post_clone.sh:23-47`). It becomes a check that the
  package resolves.
- **Release procedure:** tag, `CHANGELOG.md`, then S14.

**Tests.**

- The iOS repo builds every target from the remote pin.
- `swift test` runs green in the new repo.

**Live impact.** Medium. The code is the same, now pinned remotely, but **every
checkout of the iOS repo needs read access to MasjidKit from now on**. That
covers the owner's release machine and `ship-testflight.sh`, Xcode Cloud if it
exists (iOS recon U3), and W2's provisioning workflow, whose repo secrets gain
a read-only deploy key.

**Size.** ~1.5 sessions (estimate).

---

### Group B: Android

### S9: A `:masjidkit` library module; configuration replaces BuildConfig (Android)

**Facts.**

- Everything is in `:app` (`android:settings.gradle:22-23`; Android recon F4).
- A library's `BuildConfig` cannot carry a client's values (Android recon,
  answer to question 6). Main code reads `BuildConfig` in these places:
  - `MASJID_ID` (`android:…/AppConfig.kt:86`);
  - `DEFAULT_LAT/LON` (`:101-102`);
  - `VERSION_NAME/CODE` for the version gate
    (`android:…/ui/views/gate/AppGateViewModel.kt:42-43`);
  - `CurrentOrg.kt:57, :74`;
  - `DEBUG` in `…/data/api/RetrofitClient.kt:120`.
- The base URL is a constant (`RetrofitClient.kt:64, :66`).
- The Maps key is a manifest placeholder
  (`android:app/src/main/AndroidManifest.xml:31`), read by the Maps SDK from
  the app's manifest.

**Contract.**

- **A new `:masjidkit` Android library module** receives every package under
  `com.app.masajid` except `MasajidApp` and the flavor resources.
- **`MasjidKitConfig`** is a data class with `masjidId`, `homeLat`, `homeLon`,
  `apiBaseUrl`, `oneSignalAppId`, `appVersionName`, `appVersionCode` and
  `isDebug`. `:app` builds it from its own `BuildConfig` and passes it to
  `MasjidKit.init(context, config)` in `MasajidApp.onCreate`.
  - The Maps key is not in it. The placeholder stays in the app's manifest,
    where the SDK reads it.
- **Library code never references `BuildConfig`.** A unit test greps the
  library's sources to enforce that.
- **Per-client resources stay in the app and override by name**, following
  AGP's merge order: `ic_logo_green`, `brand_primary`, `app_name`, mipmaps and
  splash.
  - The library's own defaults for these are neutral, never Burlington's.
- **The three flavors keep exactly today's values.**

**Tests.**

- The existing unit tests move with their code and pass.
- `LibraryHasNoBuildConfigTest`.
- `LibraryDefaultsAreNeutralTest`.
- `assemble<Flavor>Debug` for all three flavors.

**Verify.** An internal-track build of each flavor, with the owner's go, and the
Android walk: the same list as §3.2's iOS walk.

**Size.** ~4 sessions (estimate). The 149 Kotlin files are mostly screens that
move without edits.

---

### S10: Publish `masjidkit` as a versioned private package (Android)

**Contract.**

- `maven-publish` to GitHub Packages as `com.hopetechapps:masjidkit`, from a
  tag `masjidkit-vX.Y.Z`.
- The Android repo's flavors consume the published version instead of the
  module, so the shared repo is the package's first client. CI and the release
  machine resolve it with R15's token.

**Tests.** A clean checkout resolves the package with that token and assembles
all flavors.

**Live impact.** Medium. The live apps then build against a downloaded binary.
The walk on an internal build proves it is the same code.

**Size.** ~1.5 sessions (estimate).

---

### Group C: Per-client repos

### S11: The client templates (new template repos)

**Contract.** Two private template repositories in `hope-tech-apps`, each
marked `is_template`.

- **`manara-client-ios-template`**:
  - one fixed `Client.xcodeproj` (R14), with an iPhone target `App`, a tvOS
    target `TV`, and a shared scheme for each;
  - `Config/Client.xcconfig`, included by both targets, carries **every**
    required key: `PRODUCT_BUNDLE_IDENTIFIER`, `MASJID_ID`, `DISPLAY_NAME`,
    `API_BASE_URL`, `DELETION_PAGE_URL`, `BACKGROUND_REFRESH_TASK_ID`,
    `FALLBACK_BRAND_HEX`, `ONESIGNAL_APP_ID`, `DEVELOPMENT_TEAM`,
    `MARKETING_VERSION` and `CURRENT_PROJECT_VERSION`;
  - `App/Info.plist`'s `BGTaskSchedulerPermittedIdentifiers` reads the same
    `BACKGROUND_REFRESH_TASK_ID` (compare `ios:Masjid/Info.plist:30-32`);
  - `client.json` holds the same values in machine-readable form. It is the
    record S12 renders from, and a test keeps it and the xcconfig in agreement;
  - `App/` and `TV/`: an `@main` shell each (S7), an `Info.plist` each, and an
    asset catalogue with the app icon, the TV icon stack, the Top Shelf image
    and the fallback logo;
  - MasjidKit pinned at `exactVersion` (S8);
  - `.github/workflows/build.yml` builds both targets for their simulators, on
    hosted macOS, only under §3.5's triggers;
  - `.github/workflows/secret-scan.yml` runs gitleaks on every push, on
    `ubuntu-latest`.
- **`manara-client-android-template`**:
  - `app/` depends on `com.hopetechapps:masjidkit` at an exact version (S10);
  - `client.properties`: `applicationId`, `masjidId`, `homeLat`, `homeLon`,
    `oneSignalAppId`, `versionCode` and `versionName`, read into `BuildConfig`
    and passed to `MasjidKit.init`;
  - `res/` overrides: mipmaps, `ic_logo_green`, `brand_primary`, `app_name`;
  - `.github/workflows/build.yml`: `assembleDebug` and unit tests on
    `ubuntu-latest`, plus the same secret scan.
- **Secrets.** Each client repo holds **exactly one** secret, installed by
  `manara-repo-ops` when it creates the repo (R15):
  - iOS: a read-only deploy key on `MasjidKit`;
  - Android: a `read:packages` token.
  
  The templates themselves hold the same one, for their own CI.
- Each template's own CI builds it against a sample `client.json` for the QA
  sandbox (masjid 17).

**Tests.**

- The templates' CI is green.
- A generated copy with a second sample configuration also builds, which
  proves no value is hard-coded in the template.
- `every_required_key_is_in_the_template` (iOS): it compares
  `MasjidKitConfiguration`'s required keys with the xcconfig.

**Live impact.** None. These are new private repositories.

**Size.** ~3 sessions (estimate).

---

### S12: The ops plumbing, and Studio creating client repos (MasjidWebMS, `manara-repo-ops`)

**Owner actions (not a slice).**

1. **Create the private repo `manara-repo-ops`.**
2. **Create a GitHub App, `manara-repo-ops`,** installed on the organisation
   with these permissions:
   - Administration: write, to create repositories;
   - Contents: write;
   - Pull requests: write;
   - Secrets: write, to install R15's one secret;
   - Actions: read;
   - Metadata: read.
   
   Its private key is stored **only** as a secret of `manara-repo-ops`, never
   on a server.
3. **Put the credentials R15 installs into `manara-repo-ops`'s secrets:** the
   MasjidKit read-only deploy key's private half, and a machine account's
   `read:packages` token (§8 OQ15).
4. **Create a fine-grained token, `GITHUB_OPS_TOKEN`.** Resource owner: the
   organisation. Repository access: only `manara-repo-ops` and
   `manara-store-ops`. Permissions: Actions read and write, Metadata read. Set
   it on the server with `scripts/set-server-secret.sh`. Staging already
   blanks every `GITHUB_` key (`deploy/staging/provision.sh:382`).

**Contract.**

- **`App\Services\GitHub\OpsDispatcher`** is the web server's only GitHub
  writer (R10).
  - `run(string $opsRepo, string $workflow, array $inputs, ProvisioningJob $job)`
    sends `POST /repos/{org}/{opsRepo}/actions/workflows/{file}/dispatches`
    with `GITHUB_OPS_TOKEN`.
  - It refuses any repo other than the two ops repos.
  - `artifactLocation(int $artifactId)` requests the artifact's zip with
    `withoutRedirecting()` and returns the `Location` header. It is never
    logged.
  - The same "no credential, no request" rule as W2 S14.
- **The job plane.**
  - `provisioning_jobs.kind` string(24), default `scaffold`;
  - `provisioning_jobs.result` json, nullable;
  - the callback accepts an optional `result` object, validated per kind by an
    explicit schema, at most 8 KB. Unknown keys are refused;
  - W2's terminal rule is unchanged.
- **Migration `create_client_repos_table`:**
  - `masjid_id` (FK, restrictOnDelete);
  - `platform` string(16), one of `ios`, `android` or `web`;
  - `full_name` string(140), unique;
  - `template` string(140) and `template_sha` string(40);
  - `pinned_version` string(32);
  - `status` string(16), one of `creating`, `building`, `green` or `failed`;
  - `last_run_url` text;
  - `created_by_user_id` (nullOnDelete);
  - timestamps.
  
  `restrictOnDelete` makes a force-delete of an organisation with repositories
  fail loudly, as W2 S2 does for Cloudflare state.
- **The same migration adds `masjid_app_publishing.code_home`,** string(16),
  nullable, with the values `monorepo`, `client_repos` or `moving`. It
  back-fills `monorepo` for every organisation with a W2 generation job.
- **`App\Support\Studio\AppIdentity::ensure(Masjid $org, list<string> $platforms)`**
  is extracted from W2 S17's `StudioAppGeneration::generate`. It writes the
  identity columns once and calls `OneSignalProvisioningService::ensureApp`.
  Both W2's generation and this slice call it, so a new client reaches client
  repos without first getting a monorepo target.
- **`App\Support\Studio\ClientRepoProvisioner::create(Masjid $org, string $platform, int $actor)`:**
  - it refuses when `code_home` is `monorepo` (S15 is the way out) or
    `moving`, and when a row already exists for the organisation and platform;
  - it calls `AppIdentity::ensure`;
  - `ClientConfigRenderer` renders `client.json` and the xcconfig or
    properties file, byte-deterministically;
  - it dispatches repo-ops' **`create-client-repo`** workflow, with the
    rendered configuration, the asset URLs (built with `SiteUrl`) and the job
    token.
- **The `create-client-repo` workflow** (`ubuntu-latest`):
  1. generate the repo from the template, `private: true`;
  2. **wait until its default branch exists**, since generation finishes
     asynchronously;
  3. commit the rendered files and fetched assets in **one** commit,
     "Configure for {name}";
  4. install R15's one secret;
  5. call back `built`, with `result: {full_name, head_sha}`.
  
  On success the server sets `code_home = client_repos` and the row to
  `building`.
- **Status.** A repo-ops **`report`** workflow reads each client repo's latest
  run and its default-branch pin, and calls back with results. It runs every
  15 minutes while any row is `building`, and daily otherwise, dispatched by
  `client-repos:reconcile`.
- **Routes** (super): `POST /api/admin/studio/organisations/{masjid_id}/repos`
  with `{platform}`, and `GET .../repos`.
- **Coverage:** a `TenantScopingCoverageTest` DECLINED entry for
  `client_repos` (SuperAdmin-only, read by no tenant route).

**Tests.**

- `OpsDispatcherTest` (`Http::preventStrayRequests`):
  - `a_blank_token_sends_nothing`
  - `only_the_two_ops_repos_can_be_dispatched_to`
  - `the_artifact_location_is_returned_and_never_logged`
- `ProvisioningCallbackResultTest`:
  - `a_result_is_accepted_only_in_its_kinds_schema`
  - `an_oversized_or_unknown_result_is_refused`
- `ClientRepoProvisionerTest`:
  - `it_calls_app_identity_ensure_and_needs_no_monorepo_target`
  - `it_refuses_a_monorepo_or_moving_org`
  - `the_rendered_config_equals_the_orgs_identity`
  - `no_secret_is_ever_rendered_into_a_client_file`
  - `a_second_create_for_the_same_platform_is_refused`
- repo-ops: `create-client-repo` has a `dry_run` input that validates and
  prints the plan. It is run once against masjid 17 in the slice's PR.
- `StudioAccessTest` gains the routes.

**Live impact.** None. The web server gains a credential that can only start
workflows in two private ops repos. Everything the plumbing creates is new and
private.

**Verify.** On the QA sandbox (masjid 17), with the owner's go:

1. Create both repos.
2. Both are private, each holds exactly one secret, their CI goes green, and
   `report` marks both `green`.
3. Archive them in GitHub by hand. Studio never deletes or archives a repo.

**Size.** ~3.5 sessions (estimate).

---

### S13: Studio's Repositories panel; each org's code home (MasjidWebMS, SPA and backend)

**Contract.**

- **`code_home` rules** (the column is S12's):
  - `null`: no apps yet. Either path may be taken.
  - `monorepo`: W2's pull-request generation. `StudioAppGeneration` sets it on
    first dispatch; W3 edits that W2 code in this slice.
  - `client_repos`: S12.
  - `moving`: S15.
  - W2's generation refuses `client_repos` and `moving`, and S12 refuses
    `monorepo` and `moving`. **One organisation's code never lives in two
    places.**
- **SPA.** `StudioReposPanel.vue`, in S9's organisation view and in Step 3's
  results. It shows:
  - each repo with its CI state and link;
  - "Create repositories" for an organisation whose `code_home` is null or
    `client_repos`;
  - W2's Apps panel for an organisation whose code home is `monorepo`.

**Tests.**

- `CodeHomeTest`:
  - `monorepo_generation_is_refused_for_a_client_repos_or_moving_org`
  - `client_repo_creation_is_refused_for_a_monorepo_org`
- SPA source test.

**Size.** ~1.5 sessions (estimate).

---

### S14: One version bump, a pull request per client (MasjidWebMS, `manara-repo-ops`)

**Goal.** D5's reason for existing: "a platform fix is a version bump rather than
N hand-edits across client repos."

**Contract.**

- **`php artisan studio:bump {package} {version} {--org=*} {--execute}`**, where
  `package` is `masjidkit-ios` or `masjidkit-android`. It is a dry run unless
  `--execute` is given.
  - With `--execute` it dispatches repo-ops' **`bump`** workflow once per
    `client_repos` row of that platform whose `pinned_version` is lower.
- **The `bump` workflow** (`ubuntu-latest`) works on a branch
  `bump/<package>-<version>`:
  - **iOS:**
    - sets `exactVersion` in the project;
    - rewrites the pin's `version` and `revision` in `Package.resolved`. The
      revision is the tag's commit, read from the MasjidKit repo. This is a
      deterministic JSON edit, so no macOS is needed.
  - **Android:** sets the dependency version in `app/build.gradle`. The Android
    projects have no lockfile (Android recon F3).
  - It opens a pull request with the `CHANGELOG.md` excerpt as its body, and
    **never merges.**
  - A merged bump is picked up by S12's `report`, which updates
    `pinned_version`.
- **Template changes** reach existing clients only as pull requests a person
  writes. `bump` moves the pinned version and nothing else.

**Tests.**

- `StudioBumpCommandTest`:
  - `a_dry_run_dispatches_nothing`
  - `one_dispatch_per_repo_behind_the_version`
  - `repos_already_at_the_version_are_skipped`
- The `bump` workflow's `dry_run` input shows a correct `Package.resolved`
  diff for a fixture.

**Size.** ~1.5 sessions (estimate).

---

### S15: Move one existing Studio client to its own repos (the proof)

**Goal.** Prove the move with the first W2 Studio client, before any of
Burlington, NAFIS or MEC is considered (R12).

**Contract.**

- **`php artisan studio:move-to-client-repos {masjid_id} {--execute} {--confirm-removed}`.**
  1. It refuses organisations 1, 5 and 13 outright. Moving one of them is a
     later, separate plan.
  2. It sets `code_home = moving`, then creates the client repos through S12's
     workflow with the organisation's **existing** identity, OneSignal id and
     assets.
     - Nothing about the app's identity changes, so its store listing is
       unaffected.
     - S12's refusal of `moving` has one exception, for this command.
  3. When both repos are green, **a person** opens the pull requests in the
     shared iOS and Android repos that remove the organisation's target and
     flavor, following `docs/runbooks/move-client-to-own-repos.md`.
     - No automated credential writes to the live apps' repositories (R10).
     - Git history keeps the removed target and flavor.
  4. `--confirm-removed`, run after both removals have merged, sets
     `code_home = client_repos`.
- **The runbook** holds the same steps for later moves. Each needs the owner's
  go, and Burlington goes last.

**Tests** (`MoveToClientReposCommandTest`):

- `the_identity_and_onesignal_id_are_carried_unchanged`
- `code_home_is_moving_until_the_removal_is_confirmed`
- `organisations_1_5_and_13_are_refused`

**Live impact.** Medium, for the one client moved: its next release comes from
its own repo.

**Verify.** The moved client's next TestFlight and internal builds come from
its repos, with the same bundle id, package name and OneSignal app.

**Size.** ~2 sessions (estimate).

---

### Group D: Web export (D2)

### S16: The renderer's export mode (renderer)

**Facts.**

- `.env` and `.wrangler/state` are tracked on the renderer's `origin/main`
  (renderer recon F14; values not read).
- The renderer root also holds operational notes about several tenants:
  `LOG.md`, `NOTES.md`, `DECISIONS.md`, `ASSUMPTIONS.md`, `PLAN.md` and
  `CHANGELOG.md`. It also holds tenant-specific scripts, such as
  `scripts/mec-media-sync.mjs`.
- The renderer's inputs are listed in the renderer recon, F11.
- Other tenants' configuration is keyed by tenant id in shared files
  (`renderer:shared/tenantBranding.ts:55`; `tenantHeader.ts:37`;
  `tenantEvents.ts:26`; `tenantRedirects.ts:71`; `tenantRedirectIndex.ts:9`;
  `shared/tenant-sites/mec-*.ts`).
- W1 S10 adds a runtime host lookup behind `NUXT_TENANT_LOOKUP_ENABLED`
  (`docs/manara-studio-w1.md`, S10).

**Contract.**

- **Untrack `.env` and `.wrangler/state`** (`git rm --cached` plus
  `.gitignore`), after §4's read shows what the manual production build takes
  from them. Anything it needs moves into the documented deploy command.
- **`scripts/export-client.mjs --tenant-id <id> --hosts <a,b> --api-base <url> --out <dir>`**,
  pure file operations over `git ls-files` at `HEAD`.
  - **Copies an allowlist, not everything minus a denylist:** `app/`,
    `server/`, `shared/`, `public/`, `i18n/`, `nuxt.config.ts`,
    `package.json`, `package-lock.json`, `tsconfig.json`, and the other build
    configuration files named in the script.
    - Anything else is left out, and a test fails if a new top-level path
      appears that the allowlist neither lists nor names as excluded.
  - **Rewrites `DEFAULT_TENANT_HOSTS`** to hold only the client's hosts.
  - **Pins the runtime lookup off:** `NUXT_TENANT_LOOKUP_ENABLED=false` in the
    generated `.env.example` and in `export.json`. An exported deploy serves
    only its own static map and can never resolve another tenant's host.
  - **Writes three more files:**
    - `export.json`: renderer commit, tenant id, hosts, API base, date;
    - a `.env.example` with `NUXT_TENANT_HOSTS`, `API_BASE_URL` and
      `DEPLOY_TARGET=cloudflare`;
    - a gitleaks workflow, so S18's gate can pass.
  - **Leaves other tenants' id-keyed tables in place.** They are inert for
    another tenant's id, and whether to prune them is §8 OQ10.

**Tests** (`node --test`):

- `export_contains_only_allowlisted_paths`
- `a_new_top_level_path_fails_the_allowlist_test`
- `export_map_holds_only_the_client_hosts`
- `export_pins_the_lookup_off`
- `export_records_its_commit`
- A CI job exports tenant 13 and builds it with `DEPLOY_TARGET=cloudflare`.

**Live impact.** Only the untracking of `.env`, proven by RBI before and after
(W1 §3.3).

**Size.** ~2 sessions (estimate).

---

### S17: Studio exports a client's website (MasjidWebMS, `manara-repo-ops`)

**Contract.**

- **`POST /api/admin/studio/organisations/{masjid_id}/website-export`** (super).
  - It refuses an organisation without a confirmed serving host.
  - It dispatches repo-ops' **`export-website`** workflow with the tenant id,
    its serving hosts, and the API base from `SiteUrl`.
- **The workflow:**
  1. checks out the renderer at `main`'s head with the repo-ops App;
  2. runs S16's script and builds the result with the Cloudflare preset;
  3. creates the private `manara-<slug>-web`, and commits the tree plus
     `HANDOVER.md` in one commit;
  4. calls back with `result: {full_name, renderer_commit}`.
  
  A `client_repos` row is recorded with `platform = web`.
- **A new host source, `external`,** in `masjid_domains`, for a host that
  serves this organisation from a deploy Manara does not run:
  - `DomainAttacher` never writes to Cloudflare for it;
  - it is probe-only, and W2 S4 re-probes it like a Studio row;
  - it is created by a SuperAdmin after the client's own deploy answers on the
    host.
- **`HANDOVER.md`**, rendered from a template that a test pins, says in plain
  words:
  - the code is frozen at renderer commit X and no longer receives Manara's
    fixes;
  - content, forms, events and donations still come from Manara's API and
    admin, and stop if the organisation leaves Manara (R4);
  - how to deploy it with `wrangler pages deploy` into the client's own
    Cloudflare account;
  - **the move, in order:**
    1. detach the host from `manara-renderer` (W2 S3). A custom domain belongs
       to one Pages project at a time;
    2. the client attaches it to their own project;
    3. a SuperAdmin adds the host as `external`, and the probe confirms it
       (the export still sends `x-manara-tenant`).
    
    CORS and card-payment returns work again from the moment of confirmation.
    The site is unreachable between steps 1 and 2, so the client should do them
    together.
- **Manara does not deploy the export** (§8 OQ2).

**Tests** (`WebsiteExportTest`):

- `it_refuses_an_org_without_a_serving_host`
- `the_handover_note_states_the_api_dependency_and_the_move_order`
- `an_external_row_is_never_written_to_cloudflare`
- `an_external_row_is_admitted_to_cors_once_the_probe_confirms_it`

**Live impact.** None until a client deploys the export and a host is moved.
Moving the host is W2 S3 plus this slice's `external` row, with the owner's go.

**Size.** ~2 sessions (estimate).

---

### Group E: Source download (D3)

### S18: Source download against a handover reference (MasjidWebMS, `manara-repo-ops`)

**Goal.** D3: "Clients get apps and sites, not source, unless a handover is
agreed." A download therefore needs the agreement named.

**Contract.**

- **`POST /api/admin/studio/organisations/{masjid_id}/repos/{client_repo_id}/download`**
  (super), body `{handover_reference, ref?}`.
  - The repo must be one of this organisation's `client_repos` rows. The
    shared iOS, Android and renderer repos can never be downloaded here,
    because they hold every client.
  - `ref` defaults to the default branch's head sha.
  - It dispatches repo-ops' **`source-archive`** workflow with the repo and
    sha. The workflow:
    1. runs gitleaks on that sha, and stops with `failed` and the findings'
       file names (never their values) if anything is found;
    2. otherwise builds the archive with `git archive`;
    3. uploads it as an artifact kept for one day, and calls back with
       `result: {artifact_id}`.
  - The server returns `{url}` from `OpsDispatcher::artifactLocation`. It is
    never logged (R11), and the SPA opens it.
  - It writes an append-only `source_downloads` row: organisation, repo, sha,
    `handover_reference`, and the user id plus a snapshot of the user's name
    and email (the `contact_login_events` pattern). It also writes a
    `Log::warning`.
- **What a handover includes.** A config-only client repo does not build
  without the private kit. Whether a handover also includes MasjidKit at the
  pinned tag, and under what licence, is §8 OQ14. Until the owner decides,
  the SPA says, next to the button, that the kit is not included.
- **Coverage:**
  - a `TenantScopingCoverageTest` DECLINED entry for `source_downloads`;
  - `config/staging_scrub.php` classifies its name and email snapshot, or
    `StagingScrubCoverageTest` fails.

**Tests** (`SourceDownloadTest`):

- `a_repo_of_another_org_or_a_shared_repo_is_refused`
- `a_missing_handover_reference_is_422`
- `a_failed_scan_returns_no_url`
- `every_download_is_ledgered`
- `the_download_url_is_never_logged`

**Size.** ~1.5 sessions (estimate).

---

### Group F: Store toggles (D10)

### S19: The store-operations repo, the toggle model and Step 3's switches

**Contract.**

- **`hope-tech-apps/manara-store-ops`**, private, dispatched only through
  `workflow_dispatch` (R9, §3.5).
- **Its complete secret list:**
  - `ASC_KEY_ID`, `ASC_ISSUER_ID`, `ASC_KEY_P8`;
  - `DIST_CERT_P12` and its password: the managed team's distribution
    certificate (R16; §8 OQ9);
  - `PLAY_SERVICE_ACCOUNT_JSON`;
  - one upload keystore and its password per Android client (S21);
  - `CLIENT_REPOS_READ_TOKEN`: the machine account's fine-grained token with
    Contents read on the organisation's repositories, which also reads
    MasjidKit;
  - the `read:packages` token.
- **Owner action.** Once store-ops is live, remove the `ASC_*` secrets from the
  iOS repo. After W2 its workflow never signs or uploads (W2 R5), so they
  serve nothing there. Then store-ops really is the one place that can publish.
- **Workflows:** `asc-register`, `asc-check`, `play-check`, `ios-upload` and
  `android-upload`.
  - API-only jobs run on `ubuntu-latest`. Archiving an iOS or tvOS build needs
    macOS (§8 OQ6).
  - Each calls back with its `kind` and a `result` (S12).
- **Identity denylist, in two layers.** Store-ops' `denylist.json` and the
  backend's `config/studio.php` list the live apps' identities: the three
  iPhone bundle ids (`ios:Masjid.xcodeproj/project.pbxproj:2433, :2469, :2626`),
  the Android packages (`android:app/build.gradle:34-63`), and anything under
  `com.app.masajid`. Every job checks, before any store call, both:
  - the recorded identity; and
  - **the identity inside the artifact it built**: `CFBundleIdentifier` from
    the archive, and `package` from the bundle's manifest (via `bundletool`).
    That identity must also equal the recorded one.
- **`provisioning_jobs.kind`** gains `store_asc_register`, `store_asc_check`,
  `store_play_check`, `store_ios_upload` and `store_android_upload`.
- **Migration `create_store_steps_table`:**
  - `masjid_id`;
  - `platform`: `ios`, `tvos` or `android`;
  - `step`: `asc_record`, `play_listing` or `first_build`;
  - `state`: `off`, `requested`, `waiting_on_person`, `checking`, `done` or
    `failed`;
  - `values` json, holding what a person must type;
  - `detail` text;
  - `requested_by_user_id`, `requested_at`, `done_at`;
  - a hand-named unique index on `(masjid_id, platform, step)`;
  - a `TenantScopingCoverageTest` DECLINED entry.
- **Rules:**
  - managed accounts only. A BYO platform shows "Your team publishes this app"
    and no switch (R13);
  - every switch defaults OFF (D10);
  - turning a switch off stops future jobs and never undoes a store action.
- **SPA.** `StudioStorePanel.vue` shows the three switches per platform, each
  with its state and, when it is waiting on a person, the exact values and
  steps.

**Tests** (`StoreStepsTest`):

- `every_switch_is_off_by_default`
- `a_byo_platform_offers_no_switch`
- `a_live_identity_is_refused_before_any_dispatch`
- `an_artifact_whose_identity_differs_from_the_record_is_refused` (store-ops
  fixture)
- `turning_a_switch_off_dispatches_nothing_further`

**Size.** ~2 sessions (estimate).

---

### S20: App Store record: register automatically, create by hand, confirm automatically

**Contract.** When "App Store record" is switched on, three steps follow.

1. **`asc-register`** (ubuntu). Each call is idempotent (GET first), and the
   key's role must allow them (§4).
   - The bundle id for `ios_bundle_id`: `POST /v1/bundleIds`, with the platform
     value verified against the API reference at build time.
   - The push-notifications capability: `/v1/bundleIdCapabilities`.
   - An `IOS_APP_STORE` profile, plus a `TVOS_APP_STORE` profile when tvOS is
     enabled, both on the managed team's distribution certificate (R16):
     `POST /v1/profiles`.
2. **`waiting_on_person`.** `values` holds the app name, the primary language,
   the bundle id and the SKU (`manara-<slug>`).
   - When tvOS is enabled, `asc-register` first tries to add the tvOS platform
     through the API: creating an `appStoreVersion` with `platform: TV_OS`.
     Whether that works is uncertain; confirm at build time.
   - If it cannot, the person adds it on the website, which is how the repo
     records doing it: "Add Platform → tvOS (website only; no API)"
     (`ios:.claude/rules/appstore-ship.md:123-125`).
3. **`asc-check`** runs every 6 hours while waiting:
   `GET /v1/apps?filter[bundleId]=…`. When the app exists, the state is `done`,
   and its id is stored as `masjid_app_publishing.asc_app_id`, a new nullable
   column.

**Tests** (`AscRecordStepTest`):

- `registration_runs_once_and_is_idempotent`
- `profiles_are_created_on_the_distribution_certificate`
- `the_person_sees_the_exact_values`
- `the_check_marks_done_and_stores_the_app_id`
- `a_denylisted_bundle_id_is_refused`

**Live impact.** Writes to Hope Tech's Apple developer account: one identifier,
one capability and up to two profiles per new client. **None of them is ever
made for a live app's identifier, and no existing certificate is revoked.**

**Size.** ~2.5 sessions (estimate).

---

### S21: Play app: create by hand, first upload by hand, confirm automatically

**Contract.** When "Play listing" is switched on, four steps follow.

1. **An upload key for the client** (§8 OQ5).
   - The owner runs `scripts/new-upload-key.sh <slug>` in a local clone of
     `manara-store-ops`. It creates the keystore, puts it in the owner's
     password manager, and sets the repository secret with `gh secret set`.
   - This is a person's step because a lost upload key needs a Play support
     reset, so the key must live somewhere more durable than a CI artifact.
   - Every client gets its own key, never Burlington's (W2 R5).
2. **`android-upload`** in `first` mode builds a signed release bundle from the
   client repo's head, with versionCode 1, and keeps it as an artifact that
   Studio links to.
3. **`waiting_on_person`**, with these steps:
   - create the app in Play Console (values: name, default language, app, free);
   - complete the Console's required declarations;
   - **grant the store-ops service account access to this app only.**
     Account-wide access would let it reach the live apps' listings;
   - upload the linked bundle to internal testing by hand (R3).
4. **`play-check`** runs every 6 hours.
   - It calls `edits.insert` for the package, then `edits.delete` on that edit,
     so no edit is left open.
   - Success means the app exists and this service account can address it. The
     state then becomes `done`.

**Tests** (`PlayListingStepTest`):

- `nothing_is_built_until_an_upload_key_is_recorded`
- `the_first_bundle_is_versioncode_one_and_signed_with_the_clients_key`
- `the_check_deletes_its_edit`
- `a_denylisted_package_is_refused`

**Size.** ~2 sessions (estimate).

---

### S22: Upload builds: TestFlight (iPhone and TV) and Play's internal track

**Contract.**

- **iOS, "Upload a first build"**, needs `asc_record` done. `ios-upload`
  (macOS):
  1. checks out the client repo at a sha;
  2. sets `CURRENT_PROJECT_VERSION` above the highest build App Store Connect
     reports. The repo's own auto-bump is recorded as broken
     (`ios:.claude/rules/appstore-ship.md:49-54`), so this reads the API
     instead;
  3. imports `DIST_CERT_P12` into an isolated build keychain, and archives the
     iPhone target (and the TV target when tvOS is enabled) with **manual
     signing** against S20's profiles. This is the recipe the repo records
     (`ios:.claude/rules/appstore-ship.md:113-121`; R16);
  4. checks the artifact's identity (S19);
  5. uploads with `altool`, using `--type appletvos` for TV (`:122`);
  6. **stops at TestFlight processing.** Submission for review stays a
     person's act.
- **Android, "first build"**, is marked done when S21 is done, because the
  first upload was the person's (R3). Later uploads go through
  `android-upload` (ubuntu):
  - versionCode = the track's highest + 1;
  - a signed bundle, with the identity check;
  - `edits.bundles.upload` to the internal track.
- **Later builds of either platform** use an "Upload build" button in the
  Store panel. It is not a toggle, and it is available once the first-build
  step is done.

**Tests** (`StoreUploadTest`):

- `ios_upload_waits_for_the_record`
- `the_build_number_is_above_the_stores_highest`
- `signing_is_manual_with_the_stored_certificate`
- `nothing_is_submitted_for_review`
- `android_later_uploads_go_to_internal`
- `a_denylisted_or_mismatched_identity_is_refused_at_both_layers`

**Live impact.** Uploads land in the developer accounts that also hold the live
apps. The two denylists, the artifact identity check and the per-app Play grant
are what keep them to new clients.

**Verify.** For the first client, with the owner's go:

- a TestFlight build appears, and a TV build if tvOS is enabled;
- an internal-track build appears after the person's first upload;
- the live apps' build lists are unchanged.

**Size.** ~2 sessions (estimate).

---

### Exit walk (not a slice)

With the owner:

1. A new Studio client gets `manara-<slug>-ios` and `manara-<slug>-android`
   (S12). Both are private, green, and hold exactly one secret each.
2. A MasjidKit patch release reaches it as one pull request per repo (S14).
3. Its store switches, one at a time:
   - its bundle id and profiles are registered, a person creates the record,
     and Studio confirms it (S20);
   - a person creates the Play app, grants access and uploads the first bundle,
     and Studio confirms it (S21);
   - a TestFlight build, and a TV build, appear (S22).
4. Its website export repo exists, with a truthful `HANDOVER.md` (S17).
5. One source download is made and ledgered with its handover reference (S18).
6. The web server holds no GitHub write credential and no store credential:
   the owner checks its `.env` key names.
7. Burlington's, NAFIS's and MEC's apps passed every refactor walk, and their
   build lists show only releases the owner made.

---

## 6. What W3 does not do

- **LLM-written copy (D4).** It is settled and deferred beyond W3 (W2 §8 OQ13).
- **Store metadata:** screenshots, descriptions, privacy labels, Play's
  data-safety and content-rating forms, and submission for review. These
  remain a person's work in each store.
- **BYO publishing** (R13).
- **A static snapshot of a website** (R4).
- **Moving Burlington, NAFIS or MEC** to their own repos (R12). Each is a later
  owner decision, and S15 refuses them.
- **A notification service extension and app groups for client apps.** Alert
  pushes work without them. Adding them means one more registered capability
  per client in S20.
- **Deleting, archiving or transferring a repository.** Nothing in W3 does
  this, and a transfer to a client's own GitHub is a person's act after a
  handover.
- **Automated writes to the shared iOS and Android repos.** S15's removals are
  opened by a person (R10).
- **A client-facing download or intake** (D12).
- **Android TV** (D15).

---

## 7. Observed, out of scope

- **MasjidWebMS is public** (§4's first read).
- **The web server already holds `GITHUB_DISPATCH_TOKEN`** for
  `repository_dispatch` to the shared iOS and Android repos
  (`config/services.php:66-72`). `repository_dispatch` needs contents write on
  the target repo, so a leak of that token could push to the live apps' repos.
  After W2, moving those dispatches behind the same Actions-only pattern as R10
  would close it. That is its own slice.
- **The GitHub organisation is on the Free plan.** Organisation secrets are not
  available to private repos (docs.github.com, "Using secrets in GitHub
  Actions"), and hosted macOS minutes are metered (§8 OQ6).
- **The Apple team labels conflict** across the iOS repo's scripts and docs
  (W2 §8 OQ2).
- **Xcode Cloud is designed but not confirmed to exist** (`ios:XCODE-CLOUD.md`;
  iOS recon U3). W3 does not depend on it.
- **`ios:scripts/ship-testflight.sh` bumps every target and pushes before it
  archives** (`:217-219, :289, :294`). After S15, client builds come from
  store-ops.
- **`TVOS-DESIGN.md` is missing** (`ios:MasjidKit/Package.swift:7-9`).

---

## 8. Open questions

| # | Question | Blocks | Recommended default |
|---|---|---|---|
| OQ1 | Is "frozen code, live Manara data" what D2 meant by an export? | S16, S17 | Yes (R4). A static snapshot is not offered, because it would silently stop showing prayer times, events and forms |
| OQ2 | Where does an exported site run? | S17's handover note | The client's own Cloudflare account. Hope Tech's account allows 100 Pages projects in total (developers.cloudflare.com/pages/platform/limits) |
| OQ3 | Do the refactor's moves reach the App Store one slice at a time, or together after S7? | §3.2 | One at a time. Each slice leaves every app shippable and has its own walk |
| OQ4 | D1 keeps "BYO credential handling", and W3's toggles use none of it (R13). Should BYO credentials still be collected? | S19 | Keep collecting them, as D1 says. Stopping would be a change to D1, which is the owner's call |
| OQ5 | Who holds each Android client's upload key? | S21 | The owner, in the password manager and as a store-ops secret. One key per client |
| OQ6 | The runner and plan: GitHub Free (metered macOS minutes, no organisation secrets for private repos), a self-hosted Mac, or GitHub Team | S11, S19–S22 | Linux jobs on hosted runners. Client iOS CI and store-ops' macOS archives on a **dedicated** self-hosted Mac that holds no other keys, registered to store-ops and the client repos only; the Mac that holds Burlington's upload key is not used. Revisit Team at ten clients |
| OQ7 | Which existing clients move to their own repos, and when? | after S15 | One at a time, each with the owner's go. Burlington last |
| OQ8 | Can a private GitHub Packages package grant read to a new repo by API, making R15's machine-account token unnecessary? | S11 | Assume not (packages grant per repository). Confirm at build time; if it can, drop the token |
| OQ9 | Which distribution certificate does store-ops use, and which live profiles depend on it? | S20, S22 | Read both (§4). Use a certificate no live app's profile depends on. **Never revoke one to make room without listing the profiles that would break** |
| OQ10 | Should an export prune other tenants' id-keyed configuration? | S16 | Not in W3. It is inert and public on those tenants' own sites. Revisit when a tenant's configuration holds anything private |
| OQ11 | Which Apple team and which Play console is Hope Tech's managed account? | S19–S22 | Carried from W2 §8 OQ2 |
| OQ12 | Repository names | S12 | `manara-<slug>-ios`, `-android` and `-web`. They stay distinct from the hand-built `<client>-web` repos (renderer recon F16) |
| OQ13 | What does a handover give the client: a zip or a repository transfer? | S18 | A zip through S18. A transfer is a person's act in GitHub and is not automated |
| OQ14 | Does a handover include MasjidKit (or `masjidkit`) at the pinned version, and under what licence? | S18 | Not until the owner decides. The SPA says the kit is not included |
| OQ15 | Who owns the machine account behind R15's `read:packages` token and store-ops' read token? | S11, S12, S19 | A dedicated, least-privilege organisation member created by the owner, with two-factor authentication, used for nothing else |
