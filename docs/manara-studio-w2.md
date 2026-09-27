# Manara Studio W2: build plan

**Status:** plan, not started. Written 2026-09-24 by a delegated planning session
for whoever builds W2. Planning only: nothing here has been built, deployed or
tried against a live system.
**Contract:** `docs/manara-studio.md`. Decisions D1–D17 are settled, and so are
two owner calls from 2026-09-24: app-store accounts default to Hope Tech's own
(managed), with BYO as the exception, and mobile work means iOS **and** Android.
This plan implements them and does not reopen them.
**Brief:** `docs/manara-studio-w2-w3-brief.md`.
**Inputs:** the spec; `docs/manara-studio-w1.md` §6 and §7; seven recon reports
(iOS app, tvOS, Android, the app-generation plane and OneSignal, editing existing
organisations, the hostname lifecycle, the renderer and export); vendor
documentation read on 2026-09-24; and read-only GitHub metadata. The iOS and
Android reports are persisted as `.claude/ios-recon.md` and
`.claude/android-recon.md`; the other five, the vendor facts and the adversarial
review of this plan's draft are in `docs/manara-studio-w2-w3-recon/`. Where two
sources disagree, §2 records which one this plan follows and why.
**Line numbers** come from the recon. It read MasjidWebMS at `c3fc0324`, the iOS
repo at `origin/main` `8e5191f`, the Android repo at `origin/master` `cf61d54`
and the renderer at `origin/main` `6a7ead2`. Re-read any line before you edit
near it.
**This repository is public** (`gh repo view`: `PUBLIC`). This plan carries no
secret, key, OneSignal id or Apple team id. Where one matters, it cites the line
in the private repository that holds it.

**Owner decisions, 2026-09-24 (interview after the first draft).** These are
settled and recorded in §8:

- **Identity:** new apps use `com.hopetechapps.<slug>` on both platforms.
- **Managed accounts:** Apple is the team NAFIS and MEC ship under; Play is the
  console that holds `com.app.masajid`.
- **Legacy button:** it stops signing and uploading too.
- **Burlington's TV board** takes the D11 board on its next TV release.
- **Jumu'ah:** hidden unless supplied, via `jumaa_is_default`.
- **Arabic labels:** the owner reviews them.
- **Canonical host:** `www`.
- **Re-confirmation:** three misses over at least 72 hours.
- **Ceiling alerts:** 50, 70, 85 and 95 percent.
- **Live orgs' OneSignal:** their move is a later plan.
- **Defaults accepted:** one Firebase project; LLM copy after W3.

**The owner has taken on the setup this plan needs:**

- `OPS_ALERT_EMAIL`;
- the OneSignal organisation key and `ONESIGNAL_ORG_ID`;
- the Cloudflare redirect scope;
- the GitHub pull-request setting;
- making MasjidWebMS private;
- rotating the unused Google key in the iOS repo.

**Path prefixes:**

- A bare path is in MasjidWebMS.
- `renderer:` is `burlington-masjid-site` (read `origin/main`; the shared
  checkout is on another session's branch).
- `ios:` is `hope-tech-apps/burlington-masjid-iOS` (`~/Developer/NewMasjidSystem-r0`
  and its sibling worktrees; read `origin/main`).
- `android:` is `hope-tech-apps/burlington-masjid-Android`
  (`~/Developer/burlington-masjid-Android`; read `origin/master`).

**Baseline W2 assumes.** W1 S1–S8 are on `main` (`718c59b2`). W1 S9 exists only
on the local branch `feat/studio-s9` (`26053319`), and W1 S10 only on the
renderer's local branch `feat/studio-s10-lookup` (`d943cbd`); neither is pushed
(`git log` of both local branches, 2026-09-24). The brief says to assume S9–S11
land. Every slice below that depends on one of them names it as a hard
dependency, and none of them may start until it is on `main`.

---

## 0. What W2 delivers

W2 is spec §5 "W2 — tvOS" (`docs/manara-studio.md:237-240`), plus every item
`docs/manara-studio-w1.md` §6 (`:1910-1956`) defers to W2. §6 below maps each of
those items to a slice or gives the reason it is left out.

Six groups, in ship order:

1. **Keep the owner informed and stop leaks** (S1–S2): the Pages
   custom-domain ceiling report, and the two gaps that let a trashed or
   force-deleted organisation leave Cloudflare state behind.
2. **Hostname lifecycle** (S3–S6): detach, periodic re-confirmation, apex↔www
   canonical redirects that cost one Pages slot instead of two, and a reviewed
   tool for the imported rows W1 froze.
3. **Existing organisations** (S7–S11): the guarded bulk capability writer,
   brand-asset regeneration, Studio opening a live organisation, the
   MasjidAdmin placeholder checklist and `studio:apply-layout`.
4. **Arabic** (S12–S13): Arabic starter labels and a website-locale field the
   runtime lookup carries.
5. **Phone apps** (S14–S17): a OneSignal app per client (D9), apps that read
   their OneSignal id from build configuration, generation that leaves a
   reviewed pull request that builds, and Studio driving it.
6. **tvOS** (S18–S19): the D11 board, then a per-organisation TV target that the
   same generation plane produces.

**Exit criterion.** For a new client that Studio provisioned in W1's flow, a
SuperAdmin presses **Generate apps** in Studio, and:

1. the iOS repo gets one pull request adding that client's iPhone target and TV
   target, and the Android repo one pull request adding its flavor. The
   generation run that opened each pull request built exactly the committed
   tree, and it is green. Neither repo has other CI (§3.4), and a pull request
   opened with `GITHUB_TOKEN` triggers no workflow. Nothing is signed, and
   nothing is uploaded to either store;
2. each generated app carries the client's own OneSignal app id (D9), its
   masjid id, name and icon, and never Burlington's;
3. the TV target draws prayer times with an iqama countdown, announcements,
   upcoming events and the donation appeal for that organisation (D11);
4. the same SuperAdmin can open a **live** organisation in Studio, change several
   switches in one request, regenerate its favicon and share image, and detach a
   Studio-attached hostname together with its Cloudflare records;
5. the owner has received the Pages capacity report.

Throughout, Burlington (1), NAFIS (5), MEC (13), Al-Razi (14) and BISS (18)
stay byte-identical on every public and mobile payload (W1 §3.4 ABI, which W2
extends to masjid 5 because NAFIS's live iOS and Android apps read the mobile
API), and 1, 13, 14 and 18 on the renderer (W1 §3.3 RBI). No live app's push channel changes, and no live board changes until
the owner releases a TV build for it.

**What "generated" means in W2.** Today the runner scaffolds a target, builds
it, and throws it away: neither workflow commits or pushes
(`ios:.github/workflows/provision-ios-app.yml:26-27`, `permissions: contents:
read`; `android:.github/workflows/provision-android-app.yml:38-39`; no `git`
command in either, per both recon reports). W2 makes the output durable **as a
pull request on the shared repo**. Per-client repos from a template are W3
(D5), and they cannot come sooner: they need MasjidKit as a pinned remote
package, and today it is a local path that only the TV app imports
(`ios:Masjid.xcodeproj/project.pbxproj:2932-2934`).

**The plane has never gone green.** `provision-ios-app.yml` has run five times,
all on 2026-07-23 and 2026-07-24, and every run ended failed or cancelled. The
last one scaffolded MEC and failed compiling a phone-number package that is no
longer in `Package.resolved` (`gh run list` / `gh run view --log-failed`,
read 2026-09-24). `provision-android-app.yml` has never run. S16 therefore opens
with a green iOS run before it changes anything. The Android workflow's first
run is S16's gated version, because the workflow as it stands signs with
Burlington's upload key (§4).

---

## 1. Slice map

Each slice ships on its own. The order puts slices with no live effect first,
and the ones that touch live apps, live admin screens or the live renderer
later.

| # | Slice | Repo | Risk to live tenants | Hard deps | Size (estimate) |
|---|---|---|---|---|---|
| S1 | Pages capacity report and retirement runbook | MasjidWebMS | none: a daily read-only command | W1 S7 | ~1 session |
| S2 | Trashed and force-deleted orgs leave no Cloudflare state behind | MasjidWebMS | low: reconcile selects fewer rows; a guard on a path only the demo seeder uses | W1 S7 | ~1 session |
| S3 | Detach a Studio hostname, with its Cloudflare records | MasjidWebMS | medium: the first Cloudflare DELETE; limited to ids Studio itself stored | S2 | ~2.5 sessions |
| S4 | Periodic re-confirmation of serving hosts | MasjidWebMS | medium: can withdraw CORS and payment returns from a Studio host; never from an imported one | W1 S9 | ~1.5 sessions |
| S5 | Apex↔www canonical redirects, one Pages slot per client | MasjidWebMS | medium: writes redirect rules into client zones; only zones Studio created or the owner allowlisted | S3; a token scope the owner adds | ~2.5 sessions |
| S6 | A reviewed tool for the imported rows | MasjidWebMS | medium–high: edits the live tenants' host rows; every run needs the owner's go | S3 | ~1.5 sessions |
| S7 | Guarded bulk capability writer; the single switch delegates to it | MasjidWebMS | medium: the live switch panel's writer | — | ~2 sessions |
| S8 | Brand-asset regeneration for an existing org | MasjidWebMS | low–medium: explicit per-org action; the logo upload changes only for orgs that already have derivatives | — | ~1.5 sessions |
| S9 | Studio opens a live organisation | MasjidWebMS (SPA + backend) | none directly: SuperAdmin screens calling S7 and S8 | S7, S8 | ~3 sessions |
| S10 | MasjidAdmin placeholder checklist | MasjidWebMS (SPA + backend) | medium–low: live page-builder screens; renders nothing without a marker | — | ~2 sessions |
| S11 | `studio:apply-layout` | MasjidWebMS | low: operator-run, dry run by default, never overwrites | — | ~1 session |
| S12 | Website locale and Arabic starter labels | MasjidWebMS | low: a nullable column; the lookup key is absent for every live org | S9; a reviewed Arabic label table | ~2 sessions |
| S13 | The renderer keeps `locale` from the lookup | renderer → `manara-renderer` | high: the live renderer; invisible because no live host sends a locale | W1 S10, W1 S11, S12 | ~1 session |
| S14 | A OneSignal app per client (D9), backend | MasjidWebMS | medium: the push senders; only orgs with a dedicated app change, and none exists | owner: OneSignal organisation key | ~2.5 sessions |
| S15 | Apps read their OneSignal id from build configuration | iOS, Android | high: ships in Burlington's, NAFIS's and MEC's next releases; behaviour identical | — | ~2 sessions |
| S16 | Generation leaves a pull request that builds; never uploads | iOS, Android (workflows, scaffolders) | medium: the runner Mac holds Burlington's upload key; workflows gain write access | S15 | ~3 sessions |
| S17 | Studio generates apps; `tvos` is a job platform | MasjidWebMS (SPA + backend) | low–medium: SuperAdmin-only; one column change on a live table | S9, S14, S16 | ~3 sessions |
| S18 | The D11 board, and Jumu'ah shown only when supplied | iOS (MasjidKit, MasjidTV, phone prayer screen), Android (prayer screen), MasjidWebMS (one additive field) | medium: shared TV code; Burlington's board changes only when the owner releases its TV build; the payload field is absent for every live org | — | ~4 sessions |
| S19 | Per-organisation TV targets | iOS (scaffolder, workflow) + MasjidWebMS (callback) | medium: the shared project file; Burlington's `MasjidTV` target untouched | S16, S17, S18 | ~3 sessions |

- **Total:** about 41 builder sessions. This is an estimate, not a measurement.
- **S1 and S2 go first** although they are small, because the owner asked to be
  kept informed about the ceiling (brief §2) and because S2 closes a leak that
  exists on production today (§7).
- **S14 can start at once.** It needs only the owner's OneSignal credential,
  and it must be on production before S17 dispatches the first Studio-generated
  app (W1 §6: "D9 must land before the first Studio-generated app").
- **The first change to a live app is S15.** It is deliberately a no-op for the
  three live iPhone and Android apps: each target sets, in configuration, the
  exact value its code hard-codes today.

---

## 2. Contract conflicts resolved

Each row picks one side and gives the reason in one sentence.

| # | Question | Positions | Chosen | Why |
|---|---|---|---|---|
| R1 | How tvOS enters the job plane | A `tvos` platform on `ProvisioningJob` (spec §5, `docs/manara-studio.md:238-239`) vs the `include_tvos` flag on the iOS job (`app/Http/Controllers/AdminDashboard/AppProvisioningController.php:180-193`) | **A `tvos` job row of its own, dispatched in the same iOS-repo run as its iOS job.** `include_tvos` stays accepted for the legacy button | A TV build fails independently and needs its own status, but two runs against one project file would produce two conflicting pull requests. |
| R2 | The TV app's identity | Separate TV targets "with their own bundle ids" (`ios:XCODE-CLOUD.md:78-87`) vs the tvOS platform on the org's own App Store record (`ios:.claude/rules/appstore-ship.md:99-104`; `app/Http/Requests/Admin/Onboarding/ProvisionMasjidRequest.php:339-341`) | **One TV target per organisation, whose bundle id is that organisation's iOS bundle id** (universal purchase, as Burlington ships today) | Both statements then hold: each org's TV build has a bundle id of its own rather than Burlington's, and it ships on its own org's record, as the backend's tvOS-requires-iOS rule already assumes. |
| R3 | Where an app reads its OneSignal id | Served by the API at runtime vs set in build configuration | **Build configuration** (an Info.plist key and a BuildConfig field), and **fail closed at build time**: a target or flavor with no id does not build | A subscription belongs to the app id it registered under, so an id that could change at runtime would orphan subscriptions; and the SDK initialises before any network call (`ios:Masjid/AppDelegate.swift:73`; `android:app/src/main/java/com/app/masajid/MasajidApp.kt:94`). |
| R4 | What generation leaves behind before W3 | Nothing: the runner discards its scaffold (both workflows, `contents: read`) vs a per-client repo (D5, which needs W3's refactor) | **A branch and a pull request on the shared repo**, merged by a person | D10's default deliverable is "a repo that builds", and until W3 the only repo that can build a client app is the shared one. |
| R5 | Whether generation uploads | The iOS workflow uploads when the account is managed and the ASC secrets are set (`provision-ios-app.yml:163-187, :259-273`); Android when four conditions hold (`provision-android-app.yml:189-236`) vs D10 (upload is an opt-in toggle, default OFF, and the toggles are W3) | **An absent or false `upload` means no signing and no upload.** The iOS run builds for the simulator and the Android run assembles a debug build. W2 never sends `upload: true`, so the legacy "Generate Apps" button stops signing and uploading too | D10's reason ("a wizard that can publish to the developer account is a different blast radius") covers signing as well: automatic signing can register identifiers in the developer account, and the Android runner signs with Burlington's upload key (`android:app/build.gradle:66-90`), which Play would bind to a new app on its first upload. |
| R6 | The bulk writer's policy for a live org | `applyAtCreation`: only departures from the defaults, with `resolve()` filling unsent keys (`app/Support/CapabilityWriter.php:51-56`; `app/Support/CapabilityCatalogue.php:174-189`) vs `setCapability`: always store the override, ledger even a no-op (`app/Http/Controllers/AdminDashboard/MasjidsController.php:353, :359`) | **`apply()` follows `setCapability`, for exactly the keys sent.** It never calls `resolve()` | On a live org, an unsent key reset to its creation default would silently undo a decision, such as Burlington's `web_pages` being off (`.claude/rules/auth-permissions.md`, "Burlington's site is run by the owner with `web_pages` off"). |
| R7 | Column-backed grants in the bulk request | Included vs refused | **Refused with 422, as `setCapability` refuses them** (`MasjidsController.php:288-299`) | `crm`, `assistant` and directory listing each have their own endpoint with its own cache flushes (`MasjidsController.php:155, :200, :246`), and a second writer for them would be the eighth copy of the feature list's rules. |
| R8 | Detach needs a Cloudflare DELETE; `CloudflareService` may never send one (`app/Services/Cloudflare/CloudflareService.php:16-26, :63, :420-424`, pinned by `tests/Feature/Studio/CloudflareServiceTest.php:293-331`) | Add delete methods to the service vs a separate class | **A separate `CloudflareRemover` that deletes only objects Studio's own POST created.** New flags `cf_dns_record_created` and `cf_pages_domain_created` record that, as `cf_zone_created` already does. An **adopted** object, whose id W1 stores just like a created one (`app/Services/Domains/DomainAttacher.php:548-566`), is never deleted. Each delete re-reads the object first and refuses if it has changed. The service's invariant and its test stay unedited. No code deletes a zone | The Studio token can edit every zone in the account, including `burlingtonmasjid.com` and `alrazischool.org`, so the delete surface must be small enough to review line by line, and "it matches what Studio would have created" is not the same as "Studio created it". |
| R9 | Where apex↔www canonicalisation happens | Renderer middleware vs a Cloudflare redirect rule | **A Cloudflare Single Redirect rule on the alias host. Only the canonical host is a Pages custom domain** | It halves the Pages slots a two-host client uses (the ceiling is per project, `developers.cloudflare.com/pages/platform/limits`), needs no change to the live renderer, and matches how Burlington's apex already answers: outside the renderer code (`docs/manara-studio-w1.md:749`; `docs/tenant-host-map.md:94`). |
| R10 | What a failed re-probe does | Clear `serving_confirmed_at` on a miss vs never clear it (W1, `app/Services/Domains/DomainAttacher.php:67-68`) | **A Studio row is demoted only after three consecutive misses spanning at least 72 hours. An imported row is never demoted automatically; the owner is emailed** | One blip must not withdraw CORS and card-payment returns from a live host (domains recon R3), and imported rows are the live tenants' hosts, which W1 froze on purpose (R28). |
| R11 | Where the website locale is stored | `masjids` vs `masjid_domains` vs `theme_settings` (none exists today: `OrganizationByHostController.php:59-67`; `masjids.mailing_locale` is an address line, `database/migrations/2026_07_22_110000_add_tax_fields_to_masjids.php:22`) | **`masjids.website_locale`**, nullable | The starter labels and the site's chrome must agree, and both are chosen per organisation, not per host. |
| R12 | Whether a placeholder's "open" state is stored | Stored vs computed | **Computed at read time** | The marker's design already says `open` is never stored (DECISIONS.md:2732-2735; `app/Support/Studio/StarterSite.php:497-512`). |
| R13 | Whether `studio:apply-layout` writes `theme_settings.tokens.layout` | As provisioning does (`app/Support/Studio/OrganisationProvisioner.php:263-266`) vs never | **Only with `--with-theme-layout`** | That token moves the header and footer of a live site (existing-org recon R6). |
| R14 | How Studio edits a live organisation | A draft in "edit" mode (D7's draft machinery) vs the live writers | **No draft.** Features and Brand apply through S7 and S8; every other section is shown read-only and links to the admin screen that already writes it | Those screens already purge the renderer and flush caches (`routes/admin.php:212, :219, :307, :314, :426, :498-517`), and a second writer per datum would repeat landmine 3 for data. |
| R15 | How a OneSignal app is created | The existing service: `Authorization: Basic <user auth key>`, reads `basic_auth_key` from the response, sends no `organization_id` (`app/Services/OneSignalProvisioningService.php:71-79, :90-93, :134-160`) vs OneSignal's current reference: `Authorization: Key <Organization API key>`, `organization_id` required, no REST key in the response, the key minted by `POST /apps/{app_id}/auth/tokens` and returned once as `formatted_token` (documentation.onesignal.com/reference/create-an-app and /create-api-key, read 2026-09-24) | **The current reference.** Encode it in `Http::fake` fixtures, and confirm it with the owner's key at build time | The service has never run (no caller, no test, no recorded use, apps-plane recon F15–F16), so nothing depends on the old shape. |
| R16 | Where an app's identity comes from | The organisation's **name**, slugged (`AppProvisioningController.php:147-148, :196-201`) vs `masjids.slug` (W1 S3) | **`masjids.slug`.** Generation refuses an organisation without one | A name can change and can collide. A slug is unique (`masjids_slug_unique`) and already names the organisation's managed host. |
| R17 | The Android package namespace for new apps | A suffix on Burlington's `com.app.masajid` (`android:app/build.gradle:8`; the workflow at `:194`) vs `com.hopetechapps.<slug>`, as iOS already defaults (`config/services.php:66-72`, `IOS_BUNDLE_PREFIX`) | **`com.hopetechapps.<slug>` on both platforms, confirmed by the owner (§8 OQ1)** | A package name is permanent once published, and deriving every client's from Burlington's ties them all to Burlington's namespace. |
| R18 | The checklist's specification | "layouts §E/§F" (`docs/manara-studio-w1.md:1927-1931`) vs nothing: that recon report is not in the repository | **Defined in S10 from the code** | The marker is in the code (`app/Support/Studio/StarterPlaceholders.php:25-28, :56-81`), and that is what the checklist has to read. |

---

## 3. Ship paths

### 3.1 MasjidWebMS

As W1 §3.1, unchanged: a clean worktree off `main`; the suite on the droplet;
`scripts/ship.sh staging <branch>` and a browser walk on staging per
`.claude/rules/shipping.md`; merge; `scripts/ship.sh production` with the owner's
go; verify through the user's layer; record `STATE.md`, `DECISIONS.md` and
`LOG.md`. Migrations are Blueprint-only, and use `->change()` where a column
changes, as existing migrations already do (24 calls across 8 files) (for example
`database/migrations/2026_09_09_180000_widen_sms_consent_evidence.php`). The
framework is Laravel `^12.0` (`composer.json:16`); the project `CLAUDE.md` says
11, which is stale.

Two additions for W2:

- **Scheduled commands** log to the `monitors` channel, as `tenancy:canary`,
  `media:verify` and `backup:check` do (`routes/console.php:398, :590, :753`).
  That channel reaches the owner's inbox only at `error` and only when
  `OPS_ALERT_EMAIL` is set (`config/logging.php:84-98, :139-143`). Whether it
  is set on production is §4's first read.
- **Studio routes** join `StudioAccessTest::calls()` (DECISIONS.md:2076-2086).
- **ABI covers masjid 5 from S1 on.** W1 §3.4 captured 1, 13, 14 and 18. NAFIS
  (5) has live iOS and Android apps that read the mobile API
  (`ios:Masjid.xcodeproj/project.pbxproj:2469`; `android:app/build.gradle:43-51`),
  and S7, S8, S12 and S14 touch state those payloads derive from. W2 adds 5 to
  every ABI capture, mobile endpoints included.

### 3.2 Renderer

As W1 §3.2 and §3.3 (RBI), unchanged. S13 is W2's only renderer slice. Branch
from `origin/main` **after W1 S10 has merged there**; today S10 exists only on a
local branch (`feat/studio-s10-lookup`, `d943cbd`). The renderer's CI builds
without `DEPLOY_TARGET=cloudflare` (`renderer:.github/workflows/build.yml:39-43`),
so the preset that ships is compiled only by the manual `wrangler pages deploy`.
S13 runs a Cloudflare-preset build locally before RBI.

### 3.3 iOS repo (`hope-tech-apps/burlington-masjid-iOS`)

1. **Worktree off `origin/main`.** Several shared checkouts sit on feature
   branches (`NewMasjidSystem-r0` is on `feat/r1-side-menu`). `ios:CLAUDE.md:94-96`
   says the ship branch is `feat/tvos` and not to merge `main`; that is stale,
   and the remote has no `feat/tvos` (iOS recon F3).
2. **Gates:**
   - `xcodebuild -project Masjid.xcodeproj build` for every iPhone scheme and
     every TV scheme against a simulator destination (`ios:CLAUDE.md:60-62`
     requires `-project`);
   - `swift test` in `MasjidKit/`;
   - the `MasjidTests` scheme;
   - for a scaffolder change, the scaffolder in `--dry-run` against a fixture
     configuration, plus one real scaffold in a scratch clone that then builds.
3. **Target membership is explicit.** A shared source file goes into every
   iPhone target (`ios:CLAUDE.md:69-76`). Every PR states which targets it
   touched.
4. **Ship.** A TestFlight build of each affected target, with the owner's go.
   - DECISIONS' ship gate still applies: production's password sign-in must be
     live before any build carrying `7615f10` (`ios:DECISIONS.md:88-90`).
   - `ios:scripts/ship-testflight.sh` is Burlington-only (`:53`), and it bumps
     **every** target's build number and pushes before it archives (`:217-219,
     :289, :294`). Its auto-bump is documented as broken
     (`ios:.claude/rules/appstore-ship.md:49-54`). Do not use it for a
     multi-target change without reading those lines first.
5. **Verify** on a TestFlight build on a device. Push, and anything else that
   touches APNs, needs hardware.

### 3.4 Android repo (`hope-tech-apps/burlington-masjid-Android`)

1. **Worktree off `origin/master`.** The shared checkout is on `feat/member-realm`.
2. **Gates:**
   - unit tests for every flavor: `./gradlew testBurlingtonDebugUnitTest
     testNafisDebugUnitTest testMecDebugUnitTest`;
   - `assemble<Flavor>Debug` for every flavor, plus any flavor a slice adds;
   - for a scaffolder change, a Python unit test of `scripts/scaffold_masjid_flavor.py`
     plus one `--apply` run in a scratch clone that then assembles.
   - There is no lint gate and CI runs no tests (`android:CLAUDE.md:63`; the
     repo's only workflow is provisioning). W2 does not add one; the gates above
     are run by the builder.
3. **Ship.** An internal-track build of each affected flavor, with the owner's
   go. The binding documents disagree on how uploads work: `android:CLAUDE.md:94-95`
   says Play submission is blocked on an upload-key reset,
   `android:.claude/rules/release-signing.md:29-49` says every upload is manual,
   and `android:HANDOFF.md:10, :64, :113-115` records version code 13 uploaded
   through a service account and live since 2026-08-12. Follow HANDOFF, the most
   recent record, and correct the other two in the same PR as the first W2
   release.
4. **`versionCode` is shared by every flavor** (`android:app/build.gradle:9-18`),
   so shipping one flavor moves the number Burlington's listing tracks. Bump it
   once per release, for all flavors.

---

## 4. Preflight reads

All are read-only. The owner runs any that touch production, or approves each
one. Do each read before the slice it gates.

| Read | Gates | How |
|---|---|---|
| `OPS_ALERT_EMAIL` present on production | S1, S4, S14 (whether an `error` reaches anyone) | Presence only: `grep -c '^OPS_ALERT_EMAIL=.\+' .env` on droplet 586894889 |
| `CLOUDFLARE_STUDIO_TOKEN` present, and its scopes | S1 (exact count vs estimate), S3, S5 | Presence on the server; scopes in the Cloudflare dashboard |
| The account's Cloudflare plan | S1's ceiling value | Dashboard (domains recon U5). The config's 100 is a literal (`config/cloudflare.php:57`) |
| `SELECT host, status, source FROM masjid_domains` | S2, S3, S6 | Production, read-only. S7's migration noted the table was empty then (`database/migrations/2026_09_24_170000_add_stage_started_at_to_masjid_domains.php:21-22`); whether the W1 S3 import has run since is unknown |
| How `burlingtonmasjid.com` answers 307 to `www` | S5 | Read the zone's rulesets, Page Rules and Bulk Redirects in the dashboard (domains recon U3) |
| Rows in `masjid_app_publishing` with `onesignal_app_id` or a stored key; rows in `provisioning_jobs` | S14, S17 | Production, read-only (apps-plane recon U1). The 2026-07-24 MEC run did reach production's callback, so at least one job row is expected |
| `mobile_app_users` with a `onesignal_subscription_id`, counted per masjid | S14's live-audience guard | Production, read-only |
| `ONESIGNAL_*` and `GITHUB_DISPATCH_TOKEN` present on production | S14, S17 | Presence only |
| Every live org has a `theme_settings` row | S8, S11 (`ApplyDraftBrand` and the layout write use `firstOrFail`) | Production, read-only (existing-org recon U2) |
| W1 S9 on `main`; W1 S10 and S11 on the renderer's `main` and on `manara-renderer` | S4, S13 | `git log origin/main` in both repos; the Pages deployments list |
| A green run of the **iOS** provisioning workflow **as it is today** | S16 | With the owner's go, send one `repository_dispatch` through `gh api` with `account_mode: byo`. The iOS workflow then only validates on the simulator (`provision-ios-app.yml:190-204`). Use a throwaway name, a random `job_id`, and `callback_url: https://example.invalid/`, so nothing reaches production; a failed callback only logs (`provision-ios-app.yml:103`). Read the result with `gh run view`. The workflow does not commit, so the run leaves nothing behind |
| **No as-is run of the Android workflow** | S16 | It signs a release bundle whenever the upload keystore resolves on the runner, whatever the account mode (`provision-android-app.yml:150-172`; only the upload step checks the mode, `:197-199`). The runner Mac holds Burlington's keystore (`android:HANDOFF.md:75`), and an absent suffix builds Burlington's own package (`:24, :194`). The first Android run is S16's `upload`-gated version, with a unique application id. Before it, the owner confirms the self-hosted runner is online (`gh api repos/hope-tech-apps/burlington-masjid-Android/actions/runners`, admin read) |

---

## 5. Slices

### S1: Pages capacity report and retirement runbook (MasjidWebMS)

**Goal.** The owner hears about the Pages custom-domain ceiling before it bites,
without asking. Owner, 2026-09-24: "When we get there we will handle it
insha'Allah but definitely keep me up to date and where we need to start
retiring some we will."

**Facts it builds on.**

- The ceiling is per Pages project: 100 on Free, 250 on Pro, 500 on Business
  (developers.cloudflare.com/pages/platform/limits, "Last updated Sep 5, 2026").
  The spec estimates about 47 more two-host clients on Free
  (`docs/manara-studio.md:209-215`).
- The config's ceiling is a literal 100 (`config/cloudflare.php:57`).
- `CloudflareService::countPagesDomains()` makes one GET and reads
  `result_info.total_count`, falling back to counting one page of `result`
  (`app/Services/Cloudflare/CloudflareService.php:280-297`).
- At the ceiling, `ensurePagesDomain()` returns a capacity conflict and adds
  nothing (`:318-331`). The attacher then parks the row as `pending` /
  `waiting_on=capacity` and retries hourly (`app/Services/Domains/DomainAttacher.php:533-572`).
- The capacity conflict is never logged (`CloudflareService.php:479-490` logs
  only failed non-404 calls). No threshold exists anywhere (domains recon F23).

**Contract.**

- `config/cloudflare.php`:
  - `pages_domain_ceiling` becomes `env('CLOUDFLARE_PAGES_DOMAIN_CEILING', 100)`.
  - It gains `pages_domain_notice_at` = `[50, 70, 85, 95]`, in percent of the ceiling.
- **Command `domains:capacity {--json}`** (`App\Console\Commands\DomainsCapacity`):
  - `used`: `countPagesDomains()` when the token is configured, with
    `source: 'cloudflare'`. Otherwise it counts `masjid_domains` rows that hold
    or are acquiring a Pages slot (status in `pending, provisioning, active,
    manual`; `reserved` is never counted), with `source: 'rows_estimate'`. S5
    later narrows this to `role = serving` and owns that edit. The output always says which one it used.
  - `ceiling`, `percent`, `waiting_on_capacity` (a count of rows with
    `waiting_on = 'capacity'`), and `clients_left_estimate` =
    `floor((ceiling − used) / 2)`, labelled **estimate** (two hosts per client,
    the spec's assumption; after S5 a client uses one, and the label says so).
  - **Notices.** When `percent` first reaches a threshold in
    `pages_domain_notice_at`, it writes one `Log::channel('monitors')->error(...)`,
    which emails the owner through `ops-alerts` (`config/logging.php:84-98`). The
    line names the used and ceiling figures, the source, and the runbook path.
    A `Cache::forever('domains:capacity:noticed:'.$threshold)` marker keeps each
    notice to one; a cache clear can only repeat a notice, never lose one.
  - **Any row waiting on capacity** writes an `error` line every run. At that
    point a real client is waiting.
  - Otherwise it writes one `info` line per run. The command always exits 0.
  - Scheduled daily: `->dailyAt('07:17')->withoutOverlapping(10)`, on the same
    offset-minute convention as `domains:reconcile` (`routes/console.php:164`).
- **`docs/runbooks/pages-domain-ceiling.md`** is the retirement procedure, in
  the order to try:
  1. Collapse two-host clients to one Pages domain plus a redirect (S5's
     `domains:collapse-alias`; available once S5 ships). This frees one slot
     per client and changes nothing a visitor sees.
  2. Detach the hosts of trashed or departed organisations (S3's
     `domains:release`; available once S3 ships), each with the owner's go.
     Until then, the dashboard steps in `removalSteps()`.
  3. Upgrade the Cloudflare plan: Pro allows 250 per project. This is a cost
     decision for the owner.
  4. Shard: a second Pages project deployed from the same renderer build, with
     `config('cloudflare.pages_project')` becoming per row. That is a separate
     slice, with its own RBI.
  5. Cloudflare for SaaS custom hostnames, the spec's long-term answer
     (`docs/manara-studio.md:213-215`). That is a design of its own.
  
  The runbook states that the five live hosts count toward the 100
  (`docs/manara-studio.md:168-171`), and that the reserved rows (for example
  `meccharlotte.org`) do not, because they are not custom domains on the
  project.

**Files.** `config/cloudflare.php`, `app/Console/Commands/DomainsCapacity.php`,
`routes/console.php`, `docs/runbooks/pages-domain-ceiling.md`, `.env.example`
(a blank `CLOUDFLARE_PAGES_DOMAIN_CEILING=`), tests.

**Tests** (`tests/Feature/Studio/DomainsCapacityCommandTest`):

- `without_a_token_it_counts_rows_and_says_it_is_an_estimate` (`Http::assertNothingSent`)
- `with_a_token_it_counts_through_cloudflare_with_one_get`
- `each_threshold_emails_once_and_only_once`
- `a_row_waiting_on_capacity_is_an_error_every_run`
- `reserved_rows_are_not_counted`
- `it_is_scheduled_daily`
- `the_ceiling_can_be_raised_by_env_without_a_code_change`

`CloudflareServiceTest` passes unedited.

**Live impact.** None. The command reads, and makes one GET a day when the
token is configured.

**Verify in production.**

- `php artisan domains:capacity --json` prints the figures and their source.
- `php artisan schedule:list` shows the command.
- The owner confirms that `OPS_ALERT_EMAIL` is set (§4). If it is not, a notice
  goes only to `monitors.log`, and this slice's purpose is not met until it is.

**Size.** ~1 session, ~6 files (estimate).

---

### S2: Trashed and force-deleted orgs leave no Cloudflare state behind (MasjidWebMS)

**Goal.** Close two gaps that exist on production today (domains recon, answer 5).

**Facts.**

- `Masjid` soft-deletes (`app/Models/Masjid.php:18`). Both admin deletions
  soft-delete, and permanent deletion is no longer exposed
  (`app/Http/Controllers/AdminDashboard/MasjidsController.php:628-666`).
- `domains:reconcile`'s selection has **no masjid or trashed filter**
  (`app/Console/Commands/ReconcileDomains.php:114-136`, read for this plan). So a
  trashed Studio organisation's pending host is still attached in Cloudflare on
  the next tick.
- The only application `forceDelete()` of a masjid is the demo-school rollback
  (`app/Support/DemoSchoolSeeder.php:949, :973`).
- A force-delete cascades `masjid_domains` in the database with no Eloquent
  event (`cascadeOnDelete`,
  `database/migrations/2026_09_24_140000_create_masjid_domains_table.php:37`).
  The CNAME, the Pages domain and any zone Studio created are then left
  untracked. `Masjid::forceDeleted` touches only forms-card links
  (`app/Models/Masjid.php:738-760`).

**Contract.**

- `ReconcileDomains::selection()` adds `->whereHas('masjid')`. The relation
  excludes trashed organisations, as `served()` already relies on
  (`app/Models/MasjidDomain.php:227-230`).
- `DomainAttacher::advance()` returns without writing anything when the row's
  organisation is trashed. It covers "Check now" and the job, not just the
  command.
- `Masjid::forceDeleting` throws `App\Exceptions\DomainsStillAttached` when any
  of the organisation's `masjid_domains` rows carries a `cf_*` id or
  `cf_zone_created`. Until S3 ships, the message gives `removalSteps()`; S3
  changes it to name `php artisan domains:release {id}`.
- Restoring an organisation resumes its rows exactly where they stopped.
  Nothing is cleared on trash, because a trash is reversible.

**Tests** (`tests/Feature/Studio/TrashedOrgDomainsTest`):

- `reconcile_never_selects_a_trashed_orgs_rows`
- `check_now_on_a_trashed_orgs_row_changes_nothing`
- `a_restored_org_resumes_where_it_stopped`
- `force_delete_is_refused_while_cloudflare_state_is_recorded`
- `force_delete_proceeds_when_no_row_carries_cloudflare_state`
- `the_demo_school_rollback_still_force_deletes` (it has no domain rows)

`DomainsReconcileCommandTest` passes unedited.

**Live impact.** None for 1, 13, 14 and 18: none is trashed, and their rows are
imported, which reconcile promotes by reads only.

**Verify in production.** `php artisan domains:reconcile --json` selects the
same rows as before for the live organisations. That count is recorded before
and after.

**Size.** ~1 session, ~6 files (estimate).

---

### S3: Detach a Studio hostname, with its Cloudflare records (MasjidWebMS)

**Goal.** A SuperAdmin removes a hostname Studio attached, and Studio removes
exactly what it created in Cloudflare and nothing else.

**Facts.**

- `DELETE .../domains/{id}` answers 204 only when the row carries no `cf_*` id,
  `cf_zone_created` is false and `source` is not `imported`. Otherwise it
  answers 409 with `removalSteps()`, and while the lock is held it answers 409
  "try again" (`app/Http/Controllers/AdminDashboard/MasjidDomainsController.php:152-184`;
  `app/Models/MasjidDomain.php:411-456`).
- `CloudflareService` cannot send DELETE (R8).
- The row lock is `masjid-domain:{id}` for 300 s, shared by the attacher and
  DELETE (`DomainAttacher.php:59-66, :99, :139-142`).

**Contract.**

- **Migration `add_created_flags_to_masjid_domains`:** `cf_dns_record_created`
  and `cf_pages_domain_created`, both boolean, default false, plus
  `adopted_from_import_at`, a nullable timestamp that S6 sets.
  - `DomainAttacher::attach()` sets a flag only when the service's outcome is
    `created`. It is never set when the outcome is `adopted`
    (`CloudflareService::ensureCname` and `ensurePagesDomain` already
    distinguish the two).
  - Every existing row keeps both flags false. Nothing attached before this
    slice can ever be deleted by it; `removalSteps()` covers those rows.
- **`App\Services\Cloudflare\CloudflareRemover`** (R8). It holds the only
  DELETE requests in the codebase. Each method refuses a row with
  `adopted_from_import_at` set, and re-reads the object first.
  - `removePagesDomain(MasjidDomain $row): CloudflareResult`:
    - requires `source = studio`, `cf_pages_domain_created`, and a non-null
      `cf_pages_domain_id`;
    - GETs the project's domain by the row's host, and deletes it only when
      the domain's name equals `row.host` and the project equals
      `config('cloudflare.pages_project')`;
    - otherwise returns `conflict` and deletes nothing.
    - The endpoint is `DELETE /accounts/{a}/pages/projects/{p}/domains/{name}`.
      The Cloudflare API reference lists it under Pages › Projects › Domains;
      verify it at build time and encode the result in the fixtures, as W1 OQ3
      did.
  - `removeDnsRecord(MasjidDomain $row, string $expectedType, string $expectedContent)`:
    - requires `source = studio`, `cf_dns_record_created`, and a non-null
      `cf_dns_record_id`;
    - the expected type and content follow the row's role: a `CNAME` to
      `pages_target` for a serving row, and S5's proxied `A 192.0.2.1` for a
      redirect row;
    - GETs that record and deletes it only when its type, content, name
      (`row.host`) and zone (`cf_zone_id`) all still match;
    - a record someone has since changed is left alone and reported.
  - `removeRedirectRule(MasjidDomain $row)`: S5's rule, with the same
    re-read-then-delete shape.
  - **There is no zone method.** A zone Studio created (`cf_zone_created`)
    carries the client's whole DNS, email included (W1 S7's case-3 warning).
    Deleting it stays a manual step that `removalSteps()` describes.
- **`App\Services\Domains\DomainDetacher::detach(MasjidDomain $row, ?int $actor): DetachResult`**,
  under the row lock:
  1. Set `status = detaching`. This is a new status in
     `MasjidDomain::STATUSES`, and it is **not** in `SERVED`, so the lookup
     stops answering for the host at once.
  2. Remove the redirect rule if there is one, then the Pages domain, then the
     DNS record. An object Studio did not create (flag false) is skipped, and
     named in `manual_steps`.
  3. On full success, delete the row through Eloquent. The model event
     forgets the CORS cache key (`MasjidDomain.php:182-183`).
  4. On any failure, keep the row as `detaching` with `last_error` and
     `waiting_on`. `domains:reconcile` selects `detaching` rows and retries.
- **Routes** (super, in the existing group at `routes/admin.php:465-470`):
  - `POST .../domains/{domain_id}/detach` → 202 with the result, or 409 for an
    imported row (R28 holds), an adopted row (`adopted_from_import_at` set) or
    a held lock. `DELETE` keeps its exact behaviour, so a row with no
    Cloudflare state still deletes with 204.
  - Imported and adopted rows are never detachable here. S6 is their tool.
- **Command `domains:release {masjid_id} {--execute}`.** It detaches every
  Studio row of one organisation. It is a dry run unless `--execute` is given.
  S2's force-delete guard names it.
- **SPA.** `StudioDomainAttachPanel.vue` shows "Detach" on Studio rows that
  carry Cloudflare state, with a confirm dialog listing what will be removed:
  the Pages domain, the DNS record, and the redirect rule if any. For a zone
  Studio created, it adds the server's manual step for the zone. Booleans are
  sent as `'1'`/`'0'`.
- **Token scopes.** No new scope is needed. W1's Pages Edit and DNS Edit cover
  these deletes (`docs/manara-studio.md:195-199`).

**Tests.**

- `CloudflareRemoverTest` (`Http::preventStrayRequests`):
  - `an_adopted_object_is_never_deleted` (the flag is false; zero DELETEs)
  - `a_pages_domain_on_another_project_or_host_is_not_deleted`
  - `a_cname_whose_content_changed_is_left_and_reported`
  - `a_redirect_rows_a_record_is_removed_by_its_own_expected_shape`
  - `an_imported_or_adopted_row_is_refused_before_any_request`
  - `no_method_can_delete_a_zone` (reflection over the public methods)
  - `the_token_never_appears_in_a_log_line` (`Log::spy`)
- `DomainDetacherTest`:
  - `a_detaching_row_is_not_served_by_the_lookup`
  - `full_success_deletes_the_row_and_forgets_the_cors_key`
  - `a_partial_failure_keeps_the_row_detaching_and_reconcile_retries_it`
  - `a_held_lock_is_a_409`
- `MasjidDomainsDetachRouteTest`: 401 for a MasjidAdmin and a Teacher; 409 for
  an imported row; a form-encoded POST works.
- `DomainsReleaseCommandTest`.
- `DomainAttacherTest` gains `the_created_flags_are_set_only_for_objects_studio_created`.
- Passing **unedited**: `CloudflareServiceTest` (its
  `the_delete_verb_is_never_used_and_cannot_be` stays true of the service) and
  `MasjidDomainsAdminRoutesTest`.
- **Edited on purpose:** `MasjidDomainSchemaTest`, to add the new status and
  columns to its expectations.

**Live impact.** None reachable for 1, 13, 14 and 18. All their rows are
imported, carry no created flag, and are refused before any request is sent.
The only new outbound requests are DELETEs of objects Studio's own POST created.

**Verify in production.** On the QA sandbox (masjid 17), with the owner's go:

1. Attach a throwaway managed host, for example `qa-detach-<date>.manara.hopetechapps.com`.
2. Wait for `active`, then detach it.
3. In the Cloudflare audit log, exactly one Pages domain delete and one DNS
   record delete appear, both for that host.
4. `GET /api/v1/organizations/by-host` for the host answers 404.

**Size.** ~2.5 sessions, ~14 files (estimate).

---

### S4: Periodic re-confirmation of serving hosts (MasjidWebMS)

**Goal.** A host that stops serving its organisation loses its CORS and
payment-return admission, and one blip never takes it away. W1 §6: "Periodic
re-confirmation is W2."

**Facts.**

- Nothing ever clears `serving_confirmed_at` (`DomainAttacher.php:67-68,
  :307-316, :617-631`; `app/Services/Domains/DomainProbe.php:125-133`).
- Once confirmed, a row is never probed again (domains recon, answer 9).
- W1 S9 makes `corsAdmitted()` (`MasjidDomain.php:237-242`) feed CORS and card
  payment returns (`docs/manara-studio-w1.md:1586-1631`).
- The probe is a 5-second GET that does not follow redirects
  (`DomainProbe.php:70, :87-89`).

**Contract.**

- Migration `add_serving_health_to_masjid_domains`:
  - `serving_last_seen_at` timestamp nullable;
  - `serving_missed_since` timestamp nullable;
  - `serving_miss_count` unsignedSmallInteger default 0.
- `ReconcileDomains::selection()` adds confirmed rows: `active` or `manual`
  with `serving_confirmed_at` set, due at most every 24 h through
  `next_check_at`. When S5 adds redirect rows, it excludes them here, since they
  have their own check, and owns that edit.
- **On a match:** set `serving_last_seen_at`, and reset `serving_miss_count`
  and `serving_missed_since`.
- **On a miss:** increment the count and set `serving_missed_since` if it is
  null. Then:
  - **A Studio row that was never adopted** (`adopted_from_import_at` null) is
    demoted when `serving_miss_count >= 3` **and**
    `serving_missed_since <= now() - 72h`. Demotion clears
    `serving_confirmed_at` and nothing else: the status is unchanged, CORS and
    payment-return admission drop, and the lookup keeps answering so the next
    probe can reach the host. A later match re-confirms it.
  - **An imported or adopted row is never demoted automatically** (R10). At the third
    consecutive miss it writes one `monitors` `error` per episode, which emails
    the owner. The line names the host, the organisation, what the probe saw,
    and the S6 command that would act on it.
- `config/cloudflare.php` gains `reconfirm = ['every_hours' => 24,
  'demote_after_misses' => 3, 'demote_after_hours' => 72]`, derived in the
  config's comment.

**Tests** (`tests/Feature/Studio/DomainReconfirmationTest`):

- `one_miss_changes_nothing_a_visitor_or_payer_sees`
- `three_misses_over_seventy_two_hours_demote_a_studio_row`
- `three_misses_inside_seventy_two_hours_do_not_demote`
- `an_imported_row_is_never_demoted_and_the_owner_is_told_once`
- `a_match_after_demotion_reconfirms`
- `a_demoted_row_leaves_cors_admission_within_the_cache_ttl`

`CorsDomainOriginsTest` (W1 S9) passes unedited.

**`DomainsReconcileCommandTest` is edited on purpose, and the edit is recorded
in `DECISIONS.md`.** Its fixture's active, confirmed row (`:46`) was expected
never to be selected, and
`without_a_token_on_productions_rows_it_selects_nothing_and_sends_nothing` pins
that production's imported, confirmed rows cause no request without a token.
S4 changes both on purpose. The new pin,
`without_a_token_confirmed_rows_are_probed_once_a_day_on_their_own_host_only`,
asserts that the only request is a GET to each confirmed host's own
`/api/tenant`, at most once in 24 hours, and never to Cloudflare. An
unreachable host logs a `warning` on each miss (`app/Services/Domains/DomainProbe.php:78`),
and that is the intended signal.

**Live impact.** The imported live hosts are probed once a day: one
`GET /api/tenant` each, a no-store renderer route. They can never lose admission
through this slice.

**Verify in production.**

- After 24 h, `SELECT host, serving_last_seen_at FROM masjid_domains` shows a
  fresh timestamp for every confirmed live host.
- No `monitors` error has been sent.

**Size.** ~1.5 sessions, ~6 files (estimate).

---

### S5: Apex↔www canonical redirects, one Pages slot per client (MasjidWebMS)

**Goal.** W1 §6 left "no apex↔www canonical redirect policy" for W2. A client
with both hosts serves one, the other redirects to it, and only the serving
host uses a Pages slot (R9).

**Facts.**

- Single Redirects run at Cloudflare's edge and require the source hostname to
  be proxied (developers.cloudflare.com/rules/url-forwarding/, updated
  2026-08-14).
- The rules live in the zone's entry-point ruleset for the phase
  `http_request_dynamic_redirect`. "Create a zone ruleset rule" (`POST
  /zones/{zone}/rulesets/{ruleset_id}/rules`) **appends** a rule. "Update a
  zone ruleset" **replaces** every rule (…/single-redirects/create-api/,
  updated 2026-08-25).
- The token permission needed is "Dynamic URL Redirects Write". It is **not**
  among the three scopes the Studio token was specified with
  (`docs/manara-studio.md:195-199`).
- Burlington's apex answers 307 to `www` from outside the renderer, by a
  mechanism not yet read (`docs/tenant-host-map.md:94`; domains recon U3).

**Contract.**

- Migration `add_role_to_masjid_domains`:
  - `role` string(16), default `'serving'`, with values `serving` or
    `redirect`;
  - `redirect_to_id` nullable, a foreign key to `masjid_domains`, `nullOnDelete`;
  - `cf_redirect_rule_id` string(64) nullable.
- **Scopes.** `served()` and `corsAdmitted()` add `role = serving`. A redirect
  host never reaches the renderer or the API.
- **Adding a client's own domain** (`StoreMasjidDomainRequest`, and W1 S8's
  `web_domain`) accepts an optional `canonical` of `www` (the default, §8 OQ5)
  or `apex`. Studio then writes two rows, the canonical host as `serving` and
  the other as `redirect`.
- **Attaching a redirect row** (in `DomainAttacher`):
  1. `ensureCname`'s sibling `ensureProxiedPlaceholder` creates a proxied `A`
     record pointing at `192.0.2.1`, the documentation address Cloudflare's
     redirect examples use. It adopts one that already matches and refuses
     anything else, never overwriting it.
  2. Read the phase entry point. If it exists, append one rule with `ref =
     manara-studio-redirect-{row id}`, expression `http.host eq "<alias>"`,
     target `concat("https://<canonical>", http.request.uri.path)`, status 301
     and `preserve_query_string: true`. If it does not exist, create it with
     that one rule. **It never updates the whole ruleset**, so a client's
     existing rules survive.
  3. Verify: the probe GETs `https://<alias>/`, does not follow the redirect,
     and expects a 301 whose `Location` host equals the canonical host. A
     match sets `verified_at` and `verified_by = probe`.
- **Refusals.** An **allowlist**, not a denylist:
  - S5 writes a redirect rule or placeholder record only in a zone Studio
    created (`cf_zone_created`), or in a zone the owner has added to
    `config('cloudflare.redirect_zones')`, which ships empty.
  - Every zone already in the account when S5 ships is therefore refused
    unless the owner lists it. That includes `burlingtonmasjid.com`,
    `alrazischool.org` and the owner's other product zones
    (`docs/manara-studio.md:164-167`). It does not depend on the W1 import
    having run, which §4 could not confirm.
  - Without the scope, the row waits with `waiting_on = token_scope`, and
    `manualSteps()` gives the dashboard steps for one Single Redirect.
- **The placeholder record's id** is stored in `cf_dns_record_id`, with
  `cf_dns_record_created` set only when S5's own POST made it. So S3's
  `removeDnsRecord` removes it by its expected shape (`A`, `192.0.2.1`).
- **Command `domains:collapse-alias {domain_id} {--execute}`** converts an
  existing Studio `serving` row whose sibling is its canonical into a
  `redirect` row. It adds the rule, verifies the 301, then removes the Pages
  domain through `CloudflareRemover::removePagesDomain`. It is a dry run by
  default. S1's runbook step 1 is this command.
- `CloudflareRemover::removeRedirectRule` deletes the rule by id only when its
  `ref` equals `manara-studio-redirect-{row id}`.

**Owner action (not a slice).** Add "Zone › Dynamic URL Redirects: Edit" to the
Studio token, limited to this account. Until then S5 ships inert: rows wait on
`token_scope` and show the manual steps.

**Tests.**

- `RedirectRuleTest` (`Http::fake`):
  - `an_existing_ruleset_gets_one_rule_appended_never_replaced` (asserts no PUT)
  - `a_missing_entry_point_is_created_with_one_rule`
  - `a_zone_neither_studio_created_nor_allowlisted_is_refused_before_any_request`
  - `the_allowlist_ships_empty`
  - `without_the_scope_the_row_waits_on_token_scope_with_manual_steps`
- `RedirectProbeTest`:
  - `a_301_to_the_canonical_host_verifies`
  - `a_301_elsewhere_or_a_200_does_not`
- `MasjidDomainScopesTest` gains `a_redirect_row_is_in_neither_scope`. Its
  existing methods pass unedited.
- `CollapseAliasCommandTest`: dry run writes nothing; execute removes the Pages
  domain only after the redirect verifies.
- `DomainsCapacityCommandTest`: `a_redirect_row_uses_no_slot`.

**Live impact.** None for the live organisations. Their zones are not on the
allowlist, and Studio did not create them.

**Verify in production.** With the owner's go, on a **dedicated, empty test
zone** the owner adds to the account and to `redirect_zones` for the purpose.
Never use a live client's zone or one of the owner's product zones.

1. Attach `www.<zone>` as serving and `<zone>` as redirect.
2. The apex answers 301 to `www`.
3. `domains:capacity` counts one slot, not two.
4. The zone's pre-existing rules are unchanged in the dashboard.

Then detach both.

**Size.** ~2.5 sessions, ~14 files (estimate).

---

### S6: A reviewed tool for the imported rows (MasjidWebMS)

**Goal.** W1 froze the imported rows on purpose (R28): Studio can neither delete
nor re-point them. W1 §6 says "changing them is a W2 tool with its own review."
This slice is that tool. Each use is a production change that needs the
owner's go.

**Facts.**

- Imported rows come from `domains:import-host-map` with `source = imported`
  and a status of `manual` or `reserved`
  (`app/Console/Commands/ImportHostMap.php:133, :294-298`).
- Five places freeze them (domains recon, answer 7):
  - `deletableThroughStudio()` is false;
  - DELETE answers 409;
  - their hosts cannot be added again;
  - a `reserved` status can never change (`MasjidDomain.php:162-168`);
  - the attacher only reads them.
- Rows known to need a decision:
  - `new.burlingtonmasjid.com` is `reserved`; it is in the live map but is not
    a custom domain on the project (W1 OQ1);
  - `meccharlotte.org` and `www.` are reserved for MEC until their zone comes
    to Cloudflare (W1 §6, "Case 3 for a live client's domain is not run
    without a go").

**Contract.**

- **Command `domains:imported {action} {--id=*} {--operator=} {--reason=} {--execute}`.**
  It is a dry run unless `--execute` is given. `--execute` requires both
  `--operator` and `--reason`.
  - **`list`**: every imported row with its host, organisation, status, last
    probe, and whether a probe matches now.
  - **`release`**: deletes an imported `reserved` row, so the host is free to
    be attached again. It refuses when a probe of the host matches its own
    organisation, because then the host is serving.
  - **`adopt`**: turns an imported **`reserved`** row into a Studio row
    (`source = studio`, `status = pending`, `adopted_from_import_at = now()`),
    so the attacher may attach it. This is the step for MEC once
    `meccharlotte.org` is a zone in the account and MEC and the owner have said
    go.
    - **It refuses a `manual` or `active` row.** Such a host is serving, and
      `pending` is outside the scope CORS and card-payment returns admit
      (`app/Models/MasjidDomain.php:74-77`), so adopting it would withdraw
      both at once.
    - The attacher's `ensureCname` still adopts only a CNAME that already
      points at `pages_target` and refuses anything else, so adopting a row can
      never overwrite a record.
    - **An adopted row keeps an imported row's protections for its life.** S3
      refuses to detach it, and S4 never demotes it automatically. Its
      Cloudflare objects keep their created flags false unless Studio's own
      POST creates them after adoption.
  - **There is no re-point action.** Moving a live host to another
    organisation would move a live site. That stays a manual database change
    with its own review.
- `MasjidDomain::reclassifyImported(string $to, string $operator, string $reason)`
  is the only code path that may change an imported or reserved row. The
  `saving` invariant (`MasjidDomain.php:162-168`) allows a change only while
  that method's flag is set.
- **Ledger.** Migration `create_masjid_domain_changes_table`: append-only rows
  of `{masjid_domain_id nullable, host, action, before json, after json,
  operator, reason, created_at}`. Each executed action writes one row inside
  its transaction, plus a `Log::warning` (production logs at `warning`). The
  model refuses update and delete, the pattern `contact_login_events` uses
  (`.claude/rules/auth-permissions.md`).

**Tests** (`tests/Feature/Studio/ImportedDomainsCommandTest`):

- `list_writes_nothing`
- `execute_without_operator_and_reason_is_refused`
- `release_refuses_a_host_that_is_serving_its_org`
- `release_frees_a_reserved_host_for_studio`
- `adopt_hands_the_row_to_the_attacher_which_still_refuses_a_foreign_record`
- `adopt_refuses_a_manual_or_active_row`
- `an_adopted_row_cannot_be_detached_or_auto_demoted`
- `every_executed_action_writes_one_ledger_row_and_a_warning`
- `the_reserved_invariant_still_holds_outside_the_tool`

`MasjidDomainReservedInvariantTest` passes unedited.

**Live impact.** None until the tool is run. Running it on a live organisation's
row is a production change: the owner says go, and the dry-run output goes into
`LOG.md` before `--execute`.

**Verify in production.** `php artisan domains:imported list` prints the
imported rows, and they match §4's SELECT.

**Size.** ~1.5 sessions, ~8 files (estimate).

---

### S7: Guarded bulk capability writer; the single switch delegates to it (MasjidWebMS)

**Goal.** W1 R21: "W1 ships `applyAtCreation` only. The rest is W2": a guarded
`apply()`, a bulk `PATCH`, and `setCapability` delegating to it
(`docs/manara-studio-w1.md:111`). The spec's reason is that "today every flip
is its own PATCH with a ledger row, so N flips is N requests"
(`docs/manara-studio.md:228-230`).

**Facts.**

- `CapabilityWriter` has one method, `applyAtCreation`
  (`app/Support/CapabilityWriter.php:44`). Its docblock reserves the guarded
  `apply()` for W2 (`:36`).
- `setCapability` (`app/Http/Controllers/AdminDashboard/MasjidsController.php:282`):
  - is SuperAdmin-only through an in-controller `abort(403)`;
  - looks the key up with a **dotted** `config("capabilities.{$capability}")`
    (`:288`), so `giving.defaults` passes and stores a junk override (latent,
    read for this plan and in the existing-org recon, R7);
  - refuses column-backed and unknown keys with 422 (`:288-299`);
  - runs the Giving refusals (`:314-345`), which can call Stripe
    (`app/Support/GivingSwitch.php:87, :101`);
  - always stores the override explicitly (`:353`) and ledgers with
    `override_before` (`:359`), no-ops included
    (`app/Support/CapabilityLedger.php:13-15`);
  - flushes the family cache (`:366`) and returns `ADMIN_APPENDS` (`:370`);
  - takes no row lock.
- The live panel sends one form-encoded `PATCH .../capabilities/{key}` with
  `enabled` of `'1'`/`'0'`, then re-reads
  (`resources/vue-app/components/super/OrganisationSwitchesPanel.vue:552-558, :577`).

**Contract.**

- **`CapabilityWriter::apply(Masjid $org, array $changes, int $actor): array{changed: list<string>, unchanged: list<string>}`.**
  - `$changes` maps key to a real PHP boolean. The request coerces strings.
  - Keys must be **top-level** in `config('capabilities')`
    (`array_key_exists`, as Studio's request already does,
    `app/Http/Requests/Admin/Onboarding/ProvisionMasjidRequest.php:405`;
    DECISIONS.md:2844-2846). Column-backed keys are refused (R7). This closes
    the dotted-key hole for the single switch too.
  - **Before the transaction:** if `giving` is being set false, it runs the
    same Giving refusals `setCapability` runs (`MasjidsController.php:314-345`),
    in the same order, with the same 422 envelope. Any refusal writes nothing for **any** key.
  - **In one transaction:**
    - `lockForUpdate()` the `masjids` row, then re-read `capability_overrides`,
      so two concurrent writes cannot lose each other's JSON;
    - for each sent key, in catalogue order, store the explicit override and
      write one `CapabilityLedger` row, no-ops included (R6);
    - never call `CapabilityCatalogue::resolve()` (R6), and never touch the
      pivot (`app/Support/AppFeaturePivot.php:58-61`; S2b owns it).
  - **After commit:** `MobileCache::flushFamily`, as today.
- **`setCapability`** keeps its route, request and 403. After them it calls
  `apply([$key => $enabled], …)` and returns exactly what it returns today.
- **Route.** `PATCH /api/admin/masjids/{masjid_id}/capabilities`, next to the
  single switch (`routes/admin.php:687-708`) and outside every gate, like it.
  - Body: `capabilities[<key>]=1|0`, form-encoded or JSON.
  - Controller: `MasjidsController::setCapabilities`, with the same
    in-controller 403 for a non-super.
  - Request: `SetCapabilitiesRequest` (BaseFormRequest). It coerces each value
    with `FILTER_VALIDATE_BOOLEAN | FILTER_NULL_ON_FAILURE` in
    `prepareForValidation`, then requires `boolean`
    (`.claude/rules/shipping.md:15-38`). `capabilities` holds between 1 and
    the catalogue's size of keys.
  - 200 returns `ADMIN_APPENDS` plus `{changed, unchanged}`. A refusal is 422
    `{status:'failed', data:{capability:[sentence]}}`, the single switch's
    envelope.
- The live panel is **not** changed. It keeps sending one key per request.
  Studio (S9) is the bulk caller.

**Tests.**

- `CapabilityWriterApplyTest`:
  - `an_unsent_key_is_never_touched` (fixture: `web_pages` explicitly off, the
    shape of Burlington's)
  - `every_sent_key_stores_an_explicit_override_even_at_its_default`
  - `each_sent_key_writes_one_ledger_row_no_ops_included`
  - `giving_off_with_a_live_subscription_refuses_the_whole_request_and_writes_nothing`
  - `a_column_backed_or_dotted_key_is_refused`
  - `the_pivot_is_never_touched`
  - `two_concurrent_writes_both_land` (two writers, one after the other, on a
    stale model)
  - `the_family_cache_is_flushed_after_commit_only`
- `BulkCapabilitiesEndpointTest`:
  - `only_a_super_admin_may_call_it` (403 in the house envelope)
  - `form_encoded_strings_true_and_false_are_read_as_booleans`
  - `the_response_is_the_single_switchs_payload_plus_what_changed`
- `SetCapabilityDelegatesTest::the_single_switch_answers_byte_for_byte_as_before`
  (recorded before the change)
- Passing **unedited**: `CapabilityChangeLedgerTest` (including
  `a_catalogue_flip_records_before_after_the_prior_decision_and_who` and
  `a_refused_or_invalid_flip_writes_nothing`), `CapabilitiesEndpointTest`,
  `GivingSwitchTest`, `GivingSwitchPreconditionTest`, `ModulesFailOpenTest`,
  `CapabilityGateTest`, `StudioProvisionCapabilitiesTest`.

**Live impact.**

- The live switch panel's writer changes internally. Its request and response
  are unchanged, and pinned.
- It gains a row lock.
- A dotted key, which today stores a junk override, becomes a 422. That is a
  fix, and no known caller sends one: the panel sends only catalogue keys (`:537`).

**Verify in production.**

- On the QA sandbox (masjid 17), flip one switch in the live panel and back.
  Two ledger rows appear, and the panel re-reads the same state it showed.
- `PATCH .../17/capabilities` with two keys writes two ledger rows.
- ABI unchanged for 1, 5, 13, 14 and 18.

**Size.** ~2 sessions, ~8 files (estimate).

---

### S8: Brand-asset regeneration for an existing org (MasjidWebMS)

**Goal.** W1 §6: "regenerating brand assets for an existing org." A SuperAdmin
rebuilds an organisation's favicon, touch icon and share image from its current
logo. And a Studio organisation whose admin uploads a new logo stops keeping
the old favicon.

**Facts.**

- `LogoDerivatives::generate(StudioDraft $draft, string $bg)` reads the draft's
  private bytes (`app/Support/Studio/LogoDerivatives.php:41-44`). It writes a
  48×48 transparent favicon, a 180×180 opaque touch icon and a 1200×630 share
  image (`:47-73`).
- `ApplyDraftLogo` adds them to `favicons`, `touch_icons` and `share_images`
  with `preservingOriginal()` (`app/Support/Studio/ApplyDraftLogo.php:25-43`).
- Readers:
  - `/api/v1/settings` emits the three URLs **only when the row exists**
    (`app/Http/Controllers/Api/V1/SettingController.php:123-138`);
  - the by-host lookup emits `favicon_url` and `share_image_url`, never from
    `logos` (`app/Http/Controllers/Api/V1/OrganizationByHostController.php:32-36`);
  - no mobile payload reads them (existing-org recon F15).
- The two admin logo uploads write `logos` only
  (`app/Http/Controllers/AdminDashboard/MasjidDetailsController.php:57-58`;
  `MasjidsController.php:598-600`).

**Contract.**

- **`LogoDerivatives::fromFile(string $absPath, string $bg): LogoFiles`** holds
  the image code. `generate(StudioDraft …)` becomes a caller of it, and its
  output must stay byte-identical (`StudioProvisionLogoTest` unedited).
- **`App\Support\BrandAssets::regenerate(Masjid $org, ?string $bg, int $actor)`:**
  - the source is `$org->logo` (`app/Models/Masjid.php:626-632`), which must be
    a raster GD can read. Otherwise it answers 422 "Upload a PNG or JPEG logo
    first";
  - `$bg` defaults to the organisation's `theme_settings` background colour,
    and must be `#RRGGBB`;
  - **new first, old after commit.** It adds the three new media rows, with
    `preservingOriginal()`, in one transaction, and only after that commit
    deletes the previous rows of each collection. medialibrary removes a
    media row's files as the row is deleted, not at commit (vendor behaviour;
    confirm at build time), so clearing first inside the transaction could
    lose the old files on a rollback;
  - if anything throws before the commit, it deletes the directories of the
    new rows it created, the W1 S8 pattern. The old derivatives are untouched;
  - after commit it runs `RendererPurgeScheduler::afterSave` for the
    organisation (`app/Support/Renderer/RendererPurgeScheduler.php`), so the
    renderer drops the old head. The lookup's KV record rewrites itself on
    change (W1 R5).
- **Route.** `POST /api/admin/masjids/{masjid_id}/brand-assets/regenerate`
  (super, in-controller 403). Body `{background_color?}`. 200 returns the four
  URLs.
- **Logo upload keeps derivatives in step.** After either admin logo upload
  commits, if the organisation **already has** a row in any of the three
  collections, `BrandAssets::regenerate` runs. An organisation with none, which
  is every live organisation, is unchanged.
  - The hook runs after the upload has committed and **never changes the
    upload's response.** A failure, such as a logo GD cannot read, is caught
    and logged at `warning` (production's level), and the previous
    derivatives stay.

**Tests** (`BrandAssetRegenerationTest`):

- `it_writes_the_three_sizes_from_the_current_logo`
- `it_replaces_rather_than_appends`
- `a_failure_leaves_the_previous_derivatives_and_no_stray_files`
- `the_renderer_purge_is_scheduled_after_commit`
- `an_org_without_derivatives_is_untouched_by_a_logo_upload`
- `a_studio_orgs_logo_upload_regenerates_its_derivatives`
- `a_failed_regeneration_after_an_upload_leaves_the_upload_response_unchanged`
- `a_non_super_gets_403`

Passing **unedited**: `StudioProvisionLogoTest`,
`LiveSettingsPayloadUnchangedTest` (its premise holds: a live org gets
derivatives only by an explicit regenerate), and
`OrganizationByHostTest::favicon_comes_from_favicons_never_logos`.

**Live impact.**

- None automatic.
- Running regenerate on a live organisation **adds** three keys to its
  `/api/v1/settings` and changes its tab icon and share card. That is the
  change W1's R11 and "rule F" kept away from Burlington and MEC, so it needs
  the owner's go per organisation. The SPA's confirm dialog says so in words.

**Verify in production.** On the QA sandbox (masjid 17), with the owner's go:

1. Regenerate.
2. `/api/v1/settings` gains the three URLs.
3. Each URL returns an image of the right size.
4. ABI unchanged for 1, 5, 13, 14 and 18.

**Size.** ~1.5 sessions, ~8 files (estimate).

---

### S9: Studio opens a live organisation (MasjidWebMS, SPA and backend)

**Goal.** W1 §6: "Studio opening a live org." A SuperAdmin opens any
organisation in Studio, sees it through Studio's sections, previews a change on
the device mockups, and applies feature and brand changes (R14).

**Facts.**

- A draft cannot represent an existing organisation:
  - its statuses are only `draft` and `provisioned` (`app/Models/StudioDraft.php:30-33`);
  - `provisioned_masjid_id` is unique
    (`database/migrations/2026_09_24_000000_create_studio_drafts_table.php:33-68`);
  - `StudioProvisioning::provision` always creates
    (`app/Support/Studio/StudioProvisioning.php:65`).
- Where each draft section lives on a live organisation is mapped in the
  existing-org recon F13. The admin writers for identity, general settings,
  donation link, about, theme and pages carry `renderer.purge`
  (`routes/admin.php:212, :219, :307, :314, :426, :498-517`).

**Contract.**

- **`GET /api/admin/studio/organisations/{masjid_id}`** (super) returns a
  read-only snapshot in Studio's section order: `identity`, `prayer`, `brand`,
  `content`, `features`, `layout`, `platforms`, `domain`, and `apps` (which
  S17 fills). Each section has `data` plus `edit_in`, which is `studio` for
  `features` and `brand` and otherwise the SPA route of the existing screen.
  - `features` is the catalogue for the organisation's type
    (`CapabilityCatalogue::forOrgType`), with each entry's **effective** value
    read through `hasCapability` or `moduleIsOff`, never the raw override.
  - `platforms` carries modes and `has_*` flags only. No secret leaves the
    server, and `onesignal_rest_api_key` is never included.
- **`POST /api/admin/studio/organisations/{masjid_id}/preview`** (super), body
  `{brand?: {four colours}, capabilities?: {key: bool}}`.
  - It returns the W1 preview shape for the live organisation with the
    overrides applied, and writes nothing.
  - `StudioPreview` takes a `PreviewInput`, with `PreviewInput::fromDraft` and
    `PreviewInput::fromMasjid($org, $overrides)`. The draft path must stay
    byte-identical (`StudioPreviewTest`, `StudioLayoutPreviewTest` and
    `StudioPreviewParityTest` unedited).
- **SPA.**
  - `views/dashboard/super/studio/StudioOrganisationView.vue` at
    `/dashboard/super/studio/organisations/:id`.
  - `StudioDraftsView.vue` gains an "Organisations" tab, listing through the
    existing SuperAdmin masjids index.
  - The **Features** card reuses Studio's Step 1 component in a live mode. It
    sends only the keys that changed, and only those whose catalogue `writer`
    is `capability` (`app/Support/CapabilityCatalogue.php:97`), to S7's bulk
    PATCH, and shows the ledger outcome.
    - Column-backed entries (`crm`, `assistant`) are shown read-only, with
      "Change on the organisation's details screen", as the live panel treats
      them (`OrganisationSwitchesPanel.vue:536`). Sending one would make the
      whole request a 422 (R7).
  - The **Brand** card reuses the foundation palette components. It shows
    `PaletteContrast` results from the preview, saves colours through the
    **existing** theme endpoint (`routes/admin.php:426`), and offers S8's
    Regenerate with its confirm dialog.
  - Every other card is read-only, with "Edit in {screen}" linking to the
    existing admin screen.
  - Booleans are sent as `'1'`/`'0'`.

**Tests.**

- `StudioOrganisationSnapshotTest`:
  - `no_secret_or_key_ever_appears` (it walks the whole JSON)
  - `features_report_effective_values`
  - `every_section_names_where_it_is_edited`
- `StudioOrganisationPreviewTest`: `overrides_reach_the_mockups_and_nothing_is_written`
  (query log)
- `StudioAccessTest` gains both routes.
- `StudioSpaSourceTest`: the route; the Features card posts to the bulk route
  with only the changed `capability`-writer keys, never `crm` or `assistant`.

**Live impact.** None directly: reads, plus writers that already exist or that
S7 and S8 own.

**Verify in production.** Open masjids 1 and 17 in Studio.

- The snapshot matches the admin screens.
- The mockups render.
- On 17, change two features in one save. Two ledger rows appear.

**Size.** ~3 sessions, ~16 files (estimate).

---

### S10: MasjidAdmin placeholder checklist (MasjidWebMS, SPA and backend)

**Goal.** A Studio client's admin sees what to fill in on their own site, in
their own page builder. W1 §6 put the checklist in W2 "because both touch live
admin screens" (`docs/manara-studio-w1.md:1927-1931`). The layouts recon's
§E/§F that specified it is not in the repository, so this slice defines it (R18).

**Facts.**

- The marker is `sections.settings.studio` = `{version: 1, preset, slot,
  placeholders: [{field, kind, hint, essential, source?}]}`
  (`app/Support/Studio/StarterPlaceholders.php:25-28, :56-81`).
- `open` is computed at plan time and never stored
  (`app/Support/Studio/StarterSite.php:497-512`; DECISIONS.md:2732-2735).
- The public path strips the marker (`StarterPlaceholders.php:39-47`).
- The admin section payload returns `settings` raw
  (`app/Http/Controllers/AdminDashboard/PageSectionsController.php:340`).
- `SectionFormModal` round-trips `settings`
  (`resources/vue-app/components/modals/SectionFormModal.vue:581, :686-687`),
  and the update writes only validated keys (`PageSectionsController.php:138`).
- No admin screen outside Studio reads `settings.studio` today.
- `Page` and `Section` are hand-scoped
  (`tests/Feature/TenantScopingCoverageTest.php:158, :162`).

**Contract.**

- **One implementation of "open", moved rather than rewritten.** Today it is
  the private `StarterSite::isOpen($placeholder, $content, StarterFacts $f,
  bool $review)` (`app/Support/Studio/StarterSite.php:505-512`):
  - `bound` is open while the bound facts are absent (`! $f->hasBound(source)`),
    which needs the organisation's current rows;
  - `review` is open while the section awaits review;
  - `text`, `image` and `list` are open while the field is empty.
  
  It moves to `StarterPlaceholders::isOpen`, with the same arguments. At read
  time, the facts come from `StarterFacts::fromMasjid`, and a `review`
  placeholder is open while its section is inactive. `StarterSite` calls the
  moved method, so plan time and read time cannot disagree
  (DECISIONS.md:2732-2735: openness is "a function of the content and the
  bound rows").
- **`GET /api/admin/masjids/{masjid_id}/pages/placeholders`**, inside the page
  builder's existing route group, so it carries the same gates as the pages
  list (the `website` module and the `web_pages` grant, `routes/admin.php:488`;
  `.claude/rules/auth-permissions.md`, "`website` is not `web_pages`").
  - **Register it before `Route::get('/{page_id}', 'show')`**
    (`routes/admin.php:502`), which would otherwise capture `placeholders` as a
    page id, the way `preview-session` is placed (`:492`).
  - Filters by `masjid_id` explicitly.
  - Response: `{status:'success', data:{open, essential_open, pages:[{page_id,
    slug, title, sections:[{section_id, title, active, placeholders:[{field,
    kind, hint, essential, open}]}]}]}}`.
  - A page with no marked section is omitted. For a live organisation, `pages`
    is `[]`.
- **Badges**, rendering nothing when the data is empty:
  - `PagesView.vue`: an "{n} to fill" chip per page, and a total in the header;
  - `PageSectionsView.vue`: a chip per section, plus "inactive until filled"
    for an inactive essential section;
  - `SectionFormModal.vue`: the marker's `hint` under each open field, labelled
    "Starter placeholder".
  - **No save path changes.**
- Every new string is interface wording. None states a fact about the
  congregation (D8).

**Tests.**

- `PlaceholderChecklistTest`:
  - `a_studio_orgs_open_placeholders_are_listed_per_page_and_section`
  - `a_filled_field_is_no_longer_open`
  - `a_live_org_without_markers_gets_an_empty_list`
  - `it_carries_the_page_builders_gates`
  - `the_route_is_not_captured_by_the_page_show_route`
  - `read_time_count_equals_plan_time_count_at_provision` (for every preset,
    with the minimal and maximal fact fixtures of W1 S4)
- `PlaceholderChecklistTenantIsolationTest`: another tenant's pages never appear.
- `SectionMarkerSurvivesEditsTest`:
  - `saving_toggling_and_reordering_a_section_keep_its_studio_marker`.
    This drives every write path `PageSectionsView` uses (existing-org recon U3).
- `StarterPlaceholdersIsOpenTest`: each kind (`bound`, `review`, `text`,
  `image`, `list`) opens and closes on its own condition.
- SPA source tests: the three components render nothing for an empty payload.
- Passing **unedited**: `StarterSitePublicPayloadTest`,
  `LivePublicPayloadsUnchangedTest`.

**Live impact.** Every admin who can open the page builder makes one more GET
when `PagesView` loads. For every live organisation it returns an empty list
and draws nothing. Public payloads are unchanged.

**Verify in production.**

- As the QA sandbox's MasjidAdmin, `PagesView` looks exactly as before.
- On the first Studio client, the header total equals W1 S8's
  `starter_site.placeholders_open`, and filling one field lowers it by one.

**Size.** ~2 sessions, ~12 files (estimate).

---

### S11: `studio:apply-layout` (MasjidWebMS)

**Goal.** W1 §6's second placeholder item. An operator gives an existing
organisation a Studio starter site: one that predates Studio, or a Studio
organisation that added the web later.

**Facts.**

- `StarterSite::applyTo`:
  - skips every slug the organisation holds, trashed or not, and never updates
    or restores (`app/Support/Studio/StarterSite.php:170-172, :192`);
  - runs in its own transaction or savepoint (`:178-180`);
  - takes its facts from `StarterFacts::fromMasjid`
    (`app/Support/Studio/OrganisationProvisioner.php:261`).
- Provisioning also writes `theme_settings.tokens.layout` (`:263-266`).
- No `studio:apply-layout` exists. `form:apply-templates` is the idempotent
  precedent (`app/Console/Commands/ApplyFormTemplatesCommand.php:16-26`).

**Contract.**

- **`php artisan studio:apply-layout {masjid_id} {preset} {--with-theme-layout} {--execute}`.**
  - It is a dry run unless `--execute` is given.
  - It refuses a trashed organisation, a preset not in
    `LayoutPresets::keysFor($org->orgType())`, and an organisation whose
    `website` module is off. Each refusal has its own sentence.
  - The plan printed: pages created, pages skipped (and why), sections active
    or inactive, and placeholders opened.
  - `--execute` runs `StarterSite::applyTo` with facts from the live
    organisation, in `en` until S12 ships and in the organisation's stored
    locale after it.
  - `theme_settings.tokens.layout` is written **only** with
    `--with-theme-layout` (R13).
  - After commit it runs `RendererPurgeScheduler::afterSave` for the
    organisation, and writes one `Log::warning` with the operator's OS user and
    the counts.

**Tests** (`StudioApplyLayoutCommandTest`):

- `a_dry_run_writes_nothing`
- `existing_slugs_are_skipped_never_touched`
- `the_theme_layout_is_written_only_when_asked`
- `a_preset_for_another_vertical_is_refused`
- `a_trashed_org_is_refused`
- `execute_schedules_a_renderer_purge`

**Live impact.** None until an operator runs it. Against a live organisation it
adds pages and sections under slugs the organisation does not already hold;
that needs the owner's go per organisation.

**Verify in production.** A dry run for masjid 17 with a masjid preset prints a
plan and writes nothing (`SELECT COUNT(*) FROM pages WHERE masjid_id = 17`,
before and after).

**Size.** ~1 session, ~4 files (estimate).

---

### S12: Website locale and Arabic starter labels (MasjidWebMS)

**Goal.** W1 §6: "Arabic starter labels, and a website-locale field in the
lookup, are W2." An Arabic-first client is provisioned from Studio and served
through the runtime lookup, with no static-map edit and no renderer deploy
(W1 R15 explains why W1 held it back).

**Facts.**

- Starter labels exist only as `labels.en` (`config/studio_layouts.php:49-52, :68-69`).
- `StarterFacts` defaults to `en` and coerces an unknown locale to it
  (`app/Support/Studio/StarterFacts.php:27, :171-176`).
- `StarterSite` throws when a locale has no labels (`StarterSite.php:117-119`).
- The lookup payload has no locale (`OrganizationByHostController.php:59-67`).
- The renderer already supports `ar` with RTL, but only from a static-map
  entry's `locale` field (`renderer:shared/tenant.ts:41, :55, :70, :108-113,
  :283`; `renderer:app/composables/useTenantHead.ts:79`;
  `renderer:app/plugins/i18n-tenant-locale.ts:37-60`). No production map entry
  sets one (`docs/manara-studio-w1.md:2003`).

**Contract.**

- **Migration `add_website_locale_to_masjids`:** `website_locale` string(8),
  nullable.
  - Add it to `Masjid::PUBLIC_DIRECTORY_DENYLIST` (`app/Models/Masjid.php:114-120`).
  - Add `Masjid::WEBSITE_LOCALES = ['en', 'ar']`.
- **Studio.**
  - `answers.identity.website_locale` is accepted by `UpdateStudioDraftRequest`.
  - Step 0 gains a "Website language" select. It offers Arabic **only while
    `config('studio_layouts.labels.ar')` exists**.
  - `ProvisionMasjidRequest` gains an optional `website_locale`
    (`Rule::in(Masjid::WEBSITE_LOCALES)`). When it is absent, behaviour is
    exactly today's (`ProvisionResponseSnapshotTest` unedited).
  - `OrganisationProvisioner` stores it, and `StarterFacts` reads it as the
    locale for the labels.
- **`labels.ar`** in `config/studio_layouts.php`:
  - it has the same keys as `labels.en`, pinned by `StudioLayoutPresetsTest`
    against `tests/fixtures/studio-layout-labels.json`;
  - its strings are written or approved by a fluent reader the owner names
    (§8 OQ8), and the config comment records who reviewed it and when;
  - until then the key is absent and Arabic is not offered. The slice ships
    dark.
  - Labels are interface words. No fact about a congregation is added (D8).
- **Lookup.** `OrganizationByHostController` adds `locale` **only when
  `website_locale` is set** (the W1 R11 pattern). Every live organisation's
  payload is therefore unchanged.
- **Existing organisations.** `PATCH /api/admin/studio/organisations/{masjid_id}/website-locale`
  (super), body `{locale: 'en'|'ar'|''}`. After commit it runs the renderer
  purge. S9 shows the value in its identity card with this edit.

**Tests.**

- `WebsiteLocaleTest`:
  - `the_lookup_carries_locale_only_when_set`
  - `an_arabic_draft_provisions_arabic_labels`
  - `arabic_is_refused_while_the_label_table_is_absent`
  - `an_unknown_locale_is_a_422`
- `OrganizationByHostTest`: its key-set test is extended on purpose, with six
  keys when the locale is unset and seven when it is set.
- `StudioLayoutPresetsTest`: `labels.ar` has exactly `labels.en`'s keys.
- `StudioStarterSiteServedProvenanceTest` runs with the Arabic fixture as well.
- `PublicMasjidDirectoryTest::every_masjids_column_is_deliberately_classified`
  goes green **unedited** once the column is denylisted.

**Live impact.** None on shipping. The column is null for every existing
organisation, and the lookup's bytes are unchanged for them. **But the locale
route can change a live site:** once S13 ships, setting `ar` on a live,
lookup-resolved organisation turns its whole site right-to-left. Using the
route on a live organisation needs the owner's go, and its confirm dialog says
what will change.

**Verify in production.**

- By-host for `mec.manara.hopetechapps.com` is byte-identical to before.
- The Studio Step 0 select offers Arabic only once the reviewed table has shipped.

**Size.** ~2 sessions, ~12 files (estimate), plus the reviewer's time for the
label table.

---

### S13: The renderer keeps `locale` from the lookup (renderer → `manara-renderer`)

**Goal.** A lookup-resolved Arabic tenant renders `lang="ar" dir="rtl"`, with
the Arabic catalogue.

**Facts.**

- On the S10 branch, `tenantRecordFromLookup` keeps `name`, `description`,
  `favicon_url` and `share_image_url` and drops every other key, `locale`
  included (`renderer@feat/studio-s10-lookup:shared/tenant.ts:568-600`).
- A backend-only change would be silently ignored (renderer recon R2).

**Contract.**

- `tenantRecordFromLookup` keeps `locale` when the existing `normalizeTenantLocale`
  accepts it (`renderer:shared/tenant.ts:70`, which map entries already pass
  through at `:283`), and drops it otherwise.
  The record then drives `lang`, `dir`, `Accept-Language` and the i18n plugin
  exactly as a map entry's `locale` does today (`useTenantHead.ts:79`;
  `app/composables/useApi.ts:170`; `app/plugins/i18n-tenant-locale.ts:37-60`).
- The KV value's `record` may now carry `locale`. W1 R5's write-on-change rule
  is unchanged, so no existing record is rewritten: none carries a locale.

**Tests.**

- `tests/tenant-lookup.test.ts`:
  - `a_lookup_locale_of_ar_is_kept`
  - `an_unknown_or_non_string_locale_is_dropped`
- `tests/rtl.test.ts`: `a_lookup_resolved_ar_tenant_renders_rtl`.
- Passing **unedited**: `tests/tenant-unchanged.test.ts`,
  `tests/tenant-lookup-isolation.test.ts`, `tests/tenant-locale.test.ts`.

**Ship.** Merge to `main`, deploy with `wrangler pages deploy` after a local
Cloudflare-preset build, and run RBI before and after (W1 §3.3).

**Live impact.** None. No live host is lookup-resolved with a locale.

**Verify in production.**

- RBI passes.
- If a Studio organisation is set to Arabic (S12), its `/api/tenant` returns
  `locale: 'ar'` and `dir: 'rtl'`, and its home page's `<html>` carries both.

**Size.** ~1 session, ~4 files (estimate).

---

### S14: A OneSignal app per client (D9), backend (MasjidWebMS)

**Goal.** Landmine 1 (`docs/manara-studio.md:72-75`) and D9: every app Studio
generates gets its own OneSignal app, created by Studio and stored against the
organisation. This must be on production before S17 dispatches the first
Studio-generated app.

**Facts.**

- Both native apps hard-code one OneSignal app id, shared by every
  organisation and told apart only by a `masjid_id` tag
  (`ios:Masjid/AppDelegate.swift:73, :84`;
  `android:app/src/main/java/com/app/masajid/MasajidApp.kt:94, :105`).
- The storage exists: `masjid_app_publishing.onesignal_app_id` (plain) and
  `onesignal_rest_api_key` (encrypted, hidden)
  (`database/migrations/2026_07_23_140000_add_onesignal_config_to_masjid_app_publishing_table.php:34, :37`;
  `app/Models/MasjidAppPublishing.php:58-65, :72-78`).
- Sending already routes per organisation: `OnesignalService::resolveConfig`
  uses the organisation's own app when `hasOwnOnesignalApp()`, which requires
  the id **and** the key (`app/Services/OnesignalService.php:111-124`;
  `MasjidAppPublishing.php:138-142`). The exceptions:
  - in-app (splash) messages always use the shared app
    (`app/Services/OnesignalInAppMessageService.php:52-60, :96-101`);
  - notification-detail reads always use the shared app (`OnesignalService.php:436-441`).
- A creator exists, but it has never been called, tested or used, and it does
  not match OneSignal's current API (R15). The recon found no caller, no test
  and no recorded use (apps-plane recon F15–F16).
  - Its route, `POST .../{masjid_id}/onesignal/provision` (super,
    `routes/admin.php:446-449`), has no guard. Called for a live organisation,
    it would move that organisation's sends to an app with no subscribers at
    once (apps-plane recon R1).
  - It is not idempotent (`app/Services/OneSignalProvisioningService.php:113-119`).
- `mobile_app_users.onesignal_subscription_id` does not record which app a
  subscription belongs to
  (`database/migrations/2026_06_23_020000_add_onesignal_subscription_id_to_mobile_app_users_table.php:20`).

**Contract.**

- **Config** (`config/services.php`, the `onesignal` block at `:286-308`):
  - **the organisation key is the existing `user_auth_key`**
    (`ONESIGNAL_USER_AUTH_KEY`), which the code already describes as the
    "Organization REST API Key" (`app/Services/OnesignalInAppMessageService.php:40-42`).
    No second env name is added for the same credential;
  - `org_id` from a new `ONESIGNAL_ORG_ID`;
  - the existing APNs keys (`ONESIGNAL_APNS_P8`, `_KEY_ID`, `_TEAM_ID`, `_ENV`)
    and `ONESIGNAL_FCM_V1_SERVICE_ACCOUNT_JSON`;
  - `never_provision`: `[1, 5, 13]`, the organisations whose live apps are on
    the shared app. The service refuses them before any other check.
  - Blank entries go in `.env.example`. Staging already blanks every
    `ONESIGNAL_` key (`deploy/staging/provision.sh:382`).
- **Migration `add_app_identity_to_masjid_app_publishing`:**
  - `ios_bundle_id` string(155), nullable, unique;
  - `android_application_id` string(150), nullable, unique;
  - `onesignal_provisioned_at` timestamp, nullable;
  - `onesignal_platforms` json, nullable: the platforms the OneSignal app has
    been configured for (APNs for `ios`, FCM for `android`).
  - S17 writes the two identity columns. The unique indexes stop two
    organisations from sharing an identity.
- **`OneSignalProvisioningService::ensureApp(Masjid $org, list<string> $platforms): OneSignalResult`**,
  replacing `provisionApp`. `outcome` is one of `created`, `exists`,
  `platform_added`, `key_minted`, `not_configured`, `refused_live_org`,
  `has_audience`, `missing_apns`, `missing_fcm`, `rejected` or `transient`.
  1. The organisation is in `never_provision`: `refused_live_org`, before
     anything else.
  2. Without the organisation key or `org_id`: `not_configured`, and **no HTTP
     request** (the house rule for integrations without credentials,
     `.claude/rules/environments.md`).
  3. `ios` requested: the APNs configuration and `ios_bundle_id` are required,
     else `missing_apns`. `android` requested: the FCM JSON is required, else
     `missing_fcm`.
  4. The organisation already has an app id and a key, and `onesignal_platforms`
     covers every requested platform: `exists`, with no request. This changes
     nothing, so it needs no guard.
  5. **Live-audience guard, before any step that creates, mints or reconfigures.**
     Any `mobile_app_users` row for the organisation with a non-null
     `onesignal_subscription_id` → `has_audience`. Those devices may have
     registered under the shared app, and nothing records which
     (`…add_onesignal_subscription_id_to_mobile_app_users_table.php:20`).
     Resolving such an organisation is an operator decision with the owner's
     go, outside this service.
  6. It has an id and a key, but a requested platform is missing from
     `onesignal_platforms`: add APNs or FCM to the existing app through
     OneSignal's update call (`PUT /apps/{app_id}`; confirm at build time),
     then record the platform. Outcome `platform_added`. So an Android-first
     client's later iOS app is never born with no working push.
  7. It has an id but no key: mint the key only (step 9).
  8. `POST https://api.onesignal.com/apps` with `Authorization: Key <org key>`
     and `{name: "Manara · {org name} · #{id}"` (truncated to 128),
     `organization_id`, the `apns_*` fields with `apns_bundle_id =
     ios_bundle_id` and `apns_env = production` when `ios` is requested, and
     `fcm_v1_service_account_json` base64-encoded when `android` is`}`.
  9. `POST /apps/{id}/auth/tokens` with `{name: "manara-{app env}-masjid-{id}"}`.
     Store `formatted_token` as the encrypted key. OneSignal returns it only
     once (documentation.onesignal.com/reference/create-api-key).
  10. Store the id, the key and `onesignal_platforms` together. If step 9
      fails after step 8 succeeded, store the id and platforms alone and
      return `key_minted: false`. `hasOwnOnesignalApp()` stays false, so sends
      stay where they were until a retry mints the key.
  - It never logs a key. Failures are logged at `warning` with the
    organisation id, the outcome and the HTTP status (production's level,
    `.claude/rules/shipping.md`).
- **`MasjidOneSignalController::provision`** delegates to `ensureApp`, so the
  existing route gains the guard and becomes idempotent. Its request still
  takes `bundle_id`, and fills `ios_bundle_id` when that is empty.
- **Sends for an organisation with its own app.**
  - **The auth scheme travels with the key.** Every send path hard-codes
    `Authorization: Basic` (`app/Services/OnesignalService.php:193, :247, :293,
    :404, :440`). A key minted through `/auth/tokens` is used with `Key` in
    OneSignal's current reference, and whether `Basic` accepts it is Unknown,
    needs investigation. `resolveConfig` therefore returns
    `[appId, key, scheme, isDedicated]`: `Basic` for the shared app (today's
    bytes) and `Key` for a dedicated app.
  - `OnesignalInAppMessageService` resolves the app through `resolveConfig`,
    and authenticates with the organisation key for a dedicated app. Whether
    the in-app messages endpoint accepts it is confirmed against OneSignal's
    reference at build time and pinned in fixtures.
  - `getNotificationDetails` resolves through `resolveConfig` as well.
  - An organisation without its own app is byte-for-byte unchanged, headers
    included.
- **Command `onesignal:ensure-app {masjid_id} {--platform=*} {--pretend}`.**
  `--pretend` evaluates every step up to the first request and prints the
  outcome, sending nothing. It is how the refusals are verified on production.
- **Unchanged:** the shared `isConfigured()` check still runs first
  (`OnesignalService.php:53-56`). Production keeps the shared credentials,
  because every live organisation depends on them.

**Owner actions (not a slice).**

- Confirm that `ONESIGNAL_USER_AUTH_KEY` on production holds the Organization
  API key (presence only; §4). If it does not, create one in OneSignal.
- Set `ONESIGNAL_ORG_ID` with `scripts/set-server-secret.sh`. It takes effect
  after the next production ship (W1 §3.1).
- Supply the APNs key of the Apple team that is Hope Tech's managed account
  (§8 OQ2), and the FCM service account (§8 OQ3).

**Tests.**

- `OneSignalAppProvisioningTest` (`Http::preventStrayRequests`):
  - `it_creates_the_app_with_the_organisation_key_and_id`
  - `it_mints_the_rest_key_through_auth_tokens_and_stores_it_encrypted`
  - `a_second_call_makes_no_request`
  - `an_org_with_a_subscribed_device_is_refused_before_any_request`
  - `a_failed_key_mint_stores_the_id_only_and_sends_do_not_move`
  - `without_credentials_nothing_is_sent`
  - `ios_without_apns_configuration_is_refused`
  - `a_live_org_on_the_never_provision_list_is_refused_first`
  - `the_audience_guard_runs_before_a_mint_or_a_platform_add`
  - `a_new_platform_is_added_to_the_existing_app`
  - `pretend_sends_nothing`
  - `the_key_never_appears_in_a_log_line` (`Log::spy`)
- `OnesignalDedicatedAppRoutingTest`:
  - `every_send_path_uses_the_key_scheme_for_a_dedicated_app` (header
    assertions on each of the five call sites)
  - `in_app_messages_for_an_org_with_its_own_app_use_that_app`
  - `an_org_without_its_own_app_is_unchanged_headers_included`
- `MasjidOneSignalRouteTest`: `the_legacy_route_is_refused_for_an_org_with_an_audience`.
- Passing **unedited**: `OnesignalUnconfiguredTest`, `ModuleFactsTest`,
  `StagingScrubTest`, `AppProvisioningTest`.

**Live impact.**

- None for 1, 5, 13, 14 and 18, provided §4's read confirms that none has a
  dedicated app today; nothing in the code or the records suggests one does
  (apps-plane recon F15–F16). The guard now refuses to create one for any
  organisation with subscribed devices.
- The unguarded route that could cut off a live organisation's pushes is closed.

**Verify in production.**

- `php artisan onesignal:ensure-app 1 --pretend`, and the same for 5 and 13,
  print `refused_live_org` and send nothing. **The creator is never called for
  1, 5 or 13**, not even to see it refuse.
- For the first Studio client (no devices), with the owner's go:
  - the app appears in the dashboard;
  - `GET .../onesignal` shows `has_onesignal_key: true`;
  - once a test device has installed its build, a push from the admin to that
    organisation reaches the device. That proves the `Key` scheme end to end.

**Size.** ~2.5 sessions, ~10 files (estimate).

---

### S15: Apps read their OneSignal id from build configuration (iOS, Android)

**Goal.** D9's second half: "read from config by the apps." A generated app
carries its own id, and an app with no id cannot be built at all, rather than
joining another congregation's channel (R3).

**Facts.**

- iOS:
  - the id is a literal in the shared `AppDelegate` (`ios:Masjid/AppDelegate.swift:73`);
  - the scaffolder already writes an `ONESIGNAL_APP_ID` build setting that
    nothing reads (`ios:scripts/scaffold_masjid_app.rb:163`), and its own
    checklist says so (`ios:scripts/scaffold_checklist.md.erb:53-58`);
  - only Burlington's target embeds the notification service extension
    (`ios:Masjid.xcodeproj/project.pbxproj:1561`).
- Android:
  - the id is a literal in `MasajidApp.kt:94`;
  - the scaffolder can emit a `String ONESIGNAL_APP_ID` field
    (`android:scripts/scaffold_masjid_flavor.py:139-162`), which nothing reads
    (`android:scripts/SCAFFOLD-CHECKLIST.md:87-103`).

**Contract.**

**"Fail closed" means the build fails**, not the app. Skipping initialisation
at runtime is not safe: the Android app calls `OneSignal.User`,
`OneSignal.Notifications` and `login` unguarded
(`android:…/MasajidApp.kt:105, :112, :148, :153, :239`;
`android:…/MainActivity.kt:578, :588`; `android:…/ui/views/SplashScreen.kt:180`),
and on SDK 5.1.32 (`android:gradle/libs.versions.toml:33`) those are expected to
throw before `initWithContext` (vendor behaviour; confirm at build time).

- **iOS.**
  - `Masjid/Info.plist` gains `OneSignalAppID = $(ONESIGNAL_APP_ID)`.
  - The `Masjid`, `NAFIS Apex Mosque` and `Muslim Education Center` targets
    each set `ONESIGNAL_APP_ID` to exactly the value the literal holds today.
  - A Run Script build phase fails the build of any target whose
    `ONESIGNAL_APP_ID` is empty or not UUID-shaped.
  - `AppDelegate` reads the key, and the literal is removed from the source.
- **Android.**
  - Every flavor in `android:app/build.gradle:32-64` gets `buildConfigField
    "String", "ONESIGNAL_APP_ID", …`. Burlington, NAFIS and MEC set today's value.
  - A Gradle check wired into `preBuild` fails any variant whose id is blank or
    not UUID-shaped.
  - `MasajidApp` reads `BuildConfig.ONESIGNAL_APP_ID`. Every OneSignal call
    site listed above goes through one `PushGateway` that does nothing if
    initialisation did not happen, as a second line of defence.
- **Both scaffolders require `--onesignal-app-id`, in the same pull requests.**
  The iOS scaffolder deep-copies the template target's build settings
  (`ios:scripts/scaffold_masjid_app.rb:264`) and overrides the id only when one
  is given (`:163`). Once this slice sets Burlington's id on the `Masjid`
  target, a scaffold without an id would inherit Burlington's push channel.
  That is landmine 1 again, through configuration, and a non-empty inherited
  value passes the build check. So the scaffolder refuses to run without an id,
  and clears the inherited key before applying the one it was given.
- **Nothing else changes.** The tag, the device registration and the NSE stay
  as they are.

**Tests.**

- iOS `OneSignalConfigurationTests`:
  - `the_id_comes_from_the_bundle`
  - `every_iphone_target_sets_the_build_setting`, a test that parses the
    project file.
- iOS build check: a scratch target with an empty id fails to build (run
  once, deliberately, per the kit's "a gate that cannot pass" rule).
- iOS `scripts/test_scaffold.rb`:
  - `it_refuses_to_run_without_a_onesignal_id`
  - `it_never_inherits_the_template_targets_onesignal_id`
- Android:
  - `every_flavor_defines_a_uuid_shaped_id`, a unit test in each flavor's
    source set;
  - `the_prebuild_check_fails_a_blank_id`, run once deliberately;
  - a Robolectric test that launches `MasajidApp` with `PushGateway` left
    uninitialised and asserts no crash;
  - `scripts/test_scaffold_masjid_flavor.py::refuses_to_run_without_a_onesignal_id`.

**Ship.** §3.3 and §3.4. It ships in each live app's **next ordinary
release**, with the owner's go. It does not need a release of its own.

**Live impact.** Behaviour is identical: each live app initialises with the
same id as before. The risk is a mis-set build setting, which the per-target
tests catch before a build exists.

**Verify in production.** On the TestFlight or internal build of each of the
three apps:

- the device's subscription appears in the **same** OneSignal app as before,
  with its `masjid_id` tag;
- a test push to that one device, sent from the OneSignal dashboard's
  test-device list and not to an audience, arrives.

**Size.** ~2 sessions, one per platform (estimate).

---

### S16: Generation leaves a pull request that builds, and never uploads (iOS, Android)

**Goal.** R4 and R5. A `scaffold-masjid` run leaves a reviewable pull request
on the shared repo and uploads nothing, whatever the account mode.

**Facts.**

- Neither workflow commits (both recon reports).
- iOS uploads when the account is managed and the secrets are set
  (`ios:.github/workflows/provision-ios-app.yml:163-187, :259-273`), on the
  hosted `macos-15` runner (`:31`), although
  `ios:.github/PROVISIONING-RUNNER.md:6, :82` describes a self-hosted one.
- Android uploads with `fastlane supply` when four conditions hold
  (`android:.github/workflows/provision-android-app.yml:189-236`). It runs on
  the self-hosted Mac (`:43`), which also holds Burlington's upload key and the
  Play service account (`android:HANDOFF.md:64, :75`).
- The Android scaffolder:
  - never writes `DEFAULT_LAT`/`DEFAULT_LON`, which main code reads
    (`scaffold_masjid_flavor.py` has no occurrence; three reads under
    `android:app/src/main/java`), so a generated flavor probably fails to
    compile. That is inferred from the code and not built (Android recon U4);
  - never writes `brand_primary` or the in-app logo, so a new app would fall
    back to Burlington's (`android:app/src/main/res/values/colors.xml:16`;
    `…/ui/components/OrgLogo.kt:28-31`);
  - lets an empty suffix through to Burlington's own package
    (`provision-android-app.yml:24, :194`).
- The iOS scaffolder refuses an existing target name
  (`ios:scripts/scaffold_masjid_app.rb:174`).
- The iOS run of 2026-07-24 failed compiling a package that has since been
  dropped (§0).

**Contract.** Workflow payload additions, all optional:

- `upload` (bool). **Only `true` signs or uploads**, and no W2 caller sends
  it (R5). Otherwise iOS builds for the simulator (`provision-ios-app.yml:190-204`
  is that path today) and Android runs `assemble<Flavor>Debug`.
- `open_pull_request` (bool).
- `icon_url`, a 1024×1024 opaque PNG.
- `onesignal_app_id`, now also sent for iOS.
- For Android: `application_id`, `logo_url`, `brand_primary` (`#RRGGBB`),
  `default_lat` and `default_lon`.
- For S19: `tvos_job_id` and `tvos_callback_token`.

Rules for fetching URLs:

- every URL is `https` and on the host in the new workflow input
  `MANARA_API_HOST` (a repository variable);
- the runner fetches nothing else.

**iOS workflow.**

- Step 0 is §4's green run of the iOS workflow **as it is**, before any change.
  The Android workflow gets no as-is run (§4).
- `--onesignal-app-id` is passed through, now meaningful after S15.
- `icon_url` becomes the `AppIcon-<SLUG>` set, checked with `sips` for size and
  alpha.
- Archive, export, signing and upload run only when `upload == true`.
- With `open_pull_request == true`:
  1. `git switch -c studio/<slug>-<job id first 8>`;
  2. commit the scaffold output: target, `BuildMasjid+<Slug>.swift`, icon set,
     scheme, and `scripts/generated/<slug>-checklist.md`;
  3. push, then `gh pr create --base main`, with the checklist as the body;
  4. call back `built` with `artifact_url` set to the pull request URL.
- `permissions: contents: write, pull-requests: write`.
- **It pushes only `refs/heads/studio/*`.** The push command names the ref
  explicitly, and a test greps the workflow for any other push target. On
  GitHub Free a private repo cannot protect `main` (the organisation's plan,
  §8 OQ12), so the workflow's own code is the only guard.
- `concurrency: {group: scaffold-masjid, cancel-in-progress: false}`, so two
  runs never race on the project file. GitHub keeps only one **pending** run
  per group, so a third dispatch replaces the second. S17 therefore refuses a
  new dispatch while any job for that repo is not terminal.
- **Owner action:** enable "Allow GitHub Actions to create and approve pull
  requests" on both repos. It is off by default, and `gh pr create` with
  `GITHUB_TOKEN` fails without it.
- The runner stays hosted `macos-15`, and `PROVISIONING-RUNNER.md` is
  corrected. That keeps Burlington's keys off iOS runs. §8 OQ12 records the
  decision on Actions minutes.

**Android workflow.**

- The same `upload`, pull-request, permissions and concurrency rules. With
  `upload` not true it never touches the upload keystore, even when
  `BURLINGTON_UPLOAD_STORE_FILE` resolves (`provision-android-app.yml:150-172`
  signs whenever it does today).
- The scaffolder gains:
  - `--application-id`, which sets `applicationId` on the flavor instead of a
    suffix (R17);
  - `DEFAULT_LAT`/`DEFAULT_LON`;
  - `values/colors.xml` `brand_primary`;
  - `drawable/ic_logo_green.png` from `logo_url`;
  - mipmaps in five densities from `icon_url`.
- It refuses:
  - a missing or empty application id;
  - `com.app.masajid` itself;
  - an id equal to any existing flavor's.

**Unchanged:** the callback contract (`job_id`, bearer, status, `detail`,
`artifact_url`) and the statuses. Each run still calls back to the URL it was
given.

**Tests.**

- iOS `scripts/test_scaffold.rb` (minitest) against a fixture config:
  - `it_sets_the_onesignal_id_build_setting`
  - `it_builds_the_icon_set_from_a_1024_png`
  - `it_refuses_an_existing_target`
- Android `scripts/test_scaffold_masjid_flavor.py` (unittest):
  - `emits_default_lat_lon`
  - `writes_brand_primary_and_logo`
  - `sets_application_id_not_suffix`
  - `refuses_burlingtons_package_and_an_empty_id`
  - `refuses_a_duplicate_id`
- **Workflow run.** With the owner's go, one dispatch per repo with the
  changed workflows, `open_pull_request: true`, a throwaway name, a unique
  application id, and the §4 dummy callback. It is green. It opens a pull
  request on a `studio/*` branch, which the builder closes without merging.
  Nothing is signed or uploaded: ASC and Play show no new build, and the
  Android run's log shows no use of the upload keystore.

**Live impact.**

- None until a pull request is merged. Merging one adds a target or flavor;
  review it for membership changes to existing targets (none are expected).
- The workflows gain write access to their own repos only (`GITHUB_TOKEN`).
- The legacy "Generate Apps" button stops signing and uploading (R5, §8 OQ10).

**Verify.** A merged Studio pull request's target or flavor builds on the
builder's machine from `main`.

**Size.** ~3 sessions (estimate).

---

### S17: Studio generates apps; `tvos` is a job platform (MasjidWebMS, SPA and backend)

**Goal.** W1 §6: "phone-app generation from Studio." And spec §5 W2: "`tvos` in
`ProvisioningJob`." A SuperAdmin generates a Studio client's apps from Studio,
and each generated app is born with its own push channel.

**Facts.**

- `provisioning_jobs.platform` is `enum('ios','android')`
  (`database/migrations/2026_07_23_150000_create_provisioning_jobs_table.php:47`).
- `ProvisionAppsRequest` accepts only `ios` and `android`
  (`app/Http/Requests/Admin/Provisioning/ProvisionAppsRequest.php:29`), and so
  does the callback (`app/Http/Controllers/ProvisioningCallbackController.php:59`).
- `repoFor` sends anything that is not `ios` to the Android repo
  (`AppProvisioningController.php:128-133`).
- `artifact_url` is a 255-character string (migration `:62`), but the callback
  accepts up to 2,000 (`ProvisioningCallbackController.php:61`), so a long
  URL gets a 500.
- The callback accepts any listed status in any order (`:57-82`).
- Identity comes from the name (R16). The iOS payload has no OneSignal id
  (`AppProvisioningController.php:180-193`).
- The legacy button is `MasjidDetailsView.vue:472-526`. It polls every 4 s
  (`:1369-1392`).

**Contract.**

- **Migration `widen_provisioning_jobs_platform_and_artifact_url`:**
  - `platform` becomes `string(16)` via `->change()`, with the values kept.
    Kinds are strings, never database enums (`.claude/rules/groups.md`).
  - `artifact_url` becomes `text`.
- **`ProvisioningJob`:**
  - adds `PLATFORM_TVOS` and `PLATFORMS = [ios, android, tvos]`;
  - adds `TERMINAL = [uploaded, built, failed]`, and the callback refuses to
    move a job out of a terminal status (apps-plane recon R8).
- **Extract `App\Support\Provisioning\AppDispatcher`** from
  `AppProvisioningController`: job rows, payload and dispatch. The legacy
  controller delegates to it, and its payload stays byte-identical
  (`AppProvisioningTest` unedited). `repoFor('tvos')` returns the iOS repo.
- **`App\Support\Studio\StudioAppGeneration::generate(Masjid $org, list<string> $platforms, int $actor): array`.** It refuses, each with its own sentence:
  - an organisation without `slug` (R16);
  - one not provisioned from a Studio draft;
  - a platform not in `enabled_platforms`, or outside `ios, android, tvos`;
  - `tvos` without `ios`, either generated already or in the same request
    (R2, and `ProvisionMasjidRequest.php:339-351`).
  
  **Identity**, written once and never changed afterwards:
  - `ios_bundle_id = config('services.github.ios_bundle_prefix').'.'.slug`;
  - `android_application_id = 'com.hopetechapps.'.<slug with - as _>`, where
    each segment must start with a letter, so a leading digit gets an `app`
    prefix. This is R17, confirmed by the owner (§8 OQ1).
  
  **D9:** for `ios` or `android`, `OneSignalProvisioningService::ensureApp`
  must return an id and a key. Any other outcome refuses generation and names
  the outcome. **No Studio app is ever built without its own push channel.**

  **Icon:** `LogoDerivatives::fromFile` writes a 1024×1024 opaque app icon to a
  new `app_icons` collection. Every URL the runner receives is built with
  `SiteUrl`, never from the request (`.claude/rules/generated-urls.md`, "a URL
  handed to a third party").

  **Dispatch:** through `AppDispatcher`, with `open_pull_request: true`,
  `upload: false` and the S16 fields. One iOS run carries the iOS and tvOS jobs
  (R1).

  **One run per repo at a time.** It refuses a dispatch while any job for the
  same repo, for any organisation, is not terminal (S16's concurrency note). A
  job left `dispatched` for more than two hours is marked `failed`, with
  "the runner never started", by a new scheduled command
  `provisioning:expire-stale` (every 15 minutes), so a refused dispatch can
  never wait forever.
- **Routes** (super, under `/api/admin/studio/organisations/{masjid_id}/apps`):
  - `GET` returns the identity, `onesignal: {configured, app_id_present, has_key}`
    and the latest 20 jobs, without tokens;
  - `POST {platforms: []}` answers 201 with the jobs, or 422 in the legacy
    envelope.
- **SPA.** `components/super/studio/apps/StudioAppsPanel.vue`, mounted in
  `ProvisionResults` and in S9's organisation view. It shows:
  - per platform: identity, OneSignal state, the job's status polled every 4 s
    (the `MasjidDetailsView.vue:1369-1392` pattern), and the pull-request link;
  - the sentence "Nothing is uploaded to the App Store or Google Play. Store
    publishing is a separate step."

**Tests.**

- `StudioAppGenerationTest`:
  - `an_org_without_a_slug_or_not_from_studio_is_refused`
  - `generation_refuses_without_a_dedicated_onesignal_app`
  - `the_payload_carries_the_orgs_own_onesignal_id_and_never_asks_to_upload`
  - `identity_is_written_once_and_never_changes`
  - `tvos_rides_the_ios_run_as_its_own_job`
  - `tvos_without_ios_is_refused`
  - `a_second_dispatch_to_a_busy_repo_is_refused`
  - `a_job_dispatched_two_hours_ago_with_no_callback_is_failed`
  - `every_url_handed_to_the_runner_is_on_the_configured_host` (in the
    `HostHeaderUrlIntegrityTest` style, with a forged `Host`)
- `ProvisioningJobPlatformTest`:
  - `platform_is_a_string_and_accepts_tvos`
  - `a_long_artifact_url_is_stored`
  - `a_terminal_job_cannot_be_moved_by_a_late_callback`
- Passing **unedited**: `AppProvisioningTest`,
  `HostHeaderUrlIntegrityTest::the_provisioning_callback_handed_to_the_runner_is_on_the_configured_host`,
  `StudioGenerateWireContractTest`, `MigrationsBootTest`.

**Live impact.**

- One column change on a small live table. Existing rows keep their values.
- No live organisation can be generated: none has a slug, and none was
  provisioned from a Studio draft.
- The legacy button is unchanged on the server side.

**Verify.**

- **Staging:** generation refuses cleanly, because `GITHUB_DISPATCH_TOKEN` and
  every `ONESIGNAL_` key are blank there. The refusal is the proof of the gate.
- **Production,** with the owner's go: the first Studio client's generation
  opens the pull requests; the jobs reach `built` with pull-request links; ASC
  and Play show no new build.

**Size.** ~3 sessions, ~16 files (estimate).

---

### S18: The D11 board (iOS: MasjidKit, MasjidTV; MasjidWebMS; the phone apps' Jumu'ah)

**Goal.** D11: "prayer times with an iqama countdown; announcements and the
events calendar; a donation / fundraising appeal." The school board is out.

**Facts.**

- The board's countdown runs to the next **adhan**
  (`ios:MasjidTV/Signage/PrayerPanelView.swift:183`).
- Announcements exist (`ios:MasjidTV/Signage/AnnouncementCarouselView.swift:33-59`).
- There is no events model or endpoint in MasjidKit
  (`ios:MasjidKit/Sources/MasjidKit/Models/`;
  `ios:MasjidKit/Sources/MasjidKit/Networking/MasjidEndpoint.swift:13-30`).
  The backend feed exists (`routes/api.php:93`).
- The appeal is a QR code and caption only (`ios:MasjidTV/Data/SignageStore.swift:69-79`),
  although `DonationLink` carries a title, a message and an image
  (`ios:MasjidKit/Sources/MasjidKit/Models/Masjid.swift:135-140`).
- There is no goal or progress data (`app/Models/Fund.php:23-29`).
- An unshipped fix treats the backend's id-0 placeholder image as "no image"
  (`origin/wip/tvos-carousel-placeholder-fallback`, commit `3294c89`, 38
  commits behind `main`; `app/Support/MobileMedia.php:116`).
- The fallback logo is Burlington's (`ios:MasjidTV/Signage/SignageView.swift:100-113`).
- tv-config is pinned byte for byte, and installed boards decode it strictly
  (`tests/Feature/Studio/TvConfigSnapshotTest.php:47`;
  `app/Http/Controllers/Mobile/TvConfigController.php:29-39`).

**Contract.**

- **MasjidKit.**
  - An `Event` model and `MasjidEndpoint.events(masjidId:)` for
    `/mobile/masjids/{id}/events`. Every field is optional in decoding.
  - This is the first model W3's convergence would move anyway.
- **`SignageStore`** fetches events every 15 minutes, with the same keep-last-good
  and disk-cache pattern (`SignageStore.swift:152-193`; `DiskCache.swift:13-39`).
- **Prayer panel.**
  - While the next prayer's iqama is still ahead, it counts down to the iqama,
    labelled "Iqama in"; otherwise it counts to the adhan, labelled "Adhan in".
  - It shows an iqama only where the settings give one. Whether
    `/prayers/settings` says so directly is Unknown, needs investigation; if
    it does not, the rule is "an iqama time exists for that prayer". This
    follows W1 S8's iqama decision: on the Studio path, iqama is displayed only
    when the client gave times (`docs/manara-studio-w1.md`, S8, "Decisions this
    slice records").
- **Jumu'ah.** The board draws a Jumu'ah section every Friday
  (`ios:MasjidTV/Signage/PrayerPanelView.swift:41-42, :112-114`). But a Studio
  organisation stores a 13:30 default that the client never gave, and "W2/W3
  must not show it unless it was supplied" (DECISIONS.md:2728-2729). The board
  therefore draws Jumu'ah only when the organisation supplied it.
  - **No signal exists today**, and the board cannot tell a Studio
    organisation from any other. So this slice adds one, in MasjidWebMS:
    - a nullable boolean `jumaa_settings.is_default`. `OrganisationProvisioner`
      sets it true when it writes the 13:30 default
      (`app/Support/Studio/OrganisationProvisioner.php:165-168`), and the admin
      Jumu'ah save sets it false;
    - the prayer-settings payload emits `jumaa_is_default: true` **only when it
      is true**, the W1 R11 pattern, so every live organisation's bytes are
      unchanged (ABI).
  - The board hides Jumu'ah while the flag is present and true.
  - **The phone apps must honour the same flag before the first Studio app
    ships,** or a generated app shows the invented time too. That is a small
    change in the iOS and Android prayer screens, in this slice, released with
    each app's next ordinary release. §8 OQ16 asks the owner to confirm the
    approach.
- **Events** join the carousel after the announcements: up to six upcoming in
  the next 14 days, each an image or a text slide. With none, nothing is added.
- **Appeal.** The QR panel adds the donation link's title and message, and its
  image when present. There is no goal and no progress bar.
- **The id-0 fix.** Rebase `3294c89` onto `main` and ship it.
- **Fallback logo** is read from a new Info.plist key, `TVFallbackLogoAsset`.
  `MasjidTV` sets it to today's `MasjidLogo`, which S19's targets replace.
- **tv-config is not changed.**

**Tests.**

- `MasjidKitTests`: `EventDecodingTests`, using a fixture recorded from
  staging's `/events` for a scrubbed organisation.
- `CountdownTargetTests`: before the adhan; between the adhan and the iqama;
  with no iqama; on Friday.
- `JumuahVisibilityTests`: a Friday where only the stored default exists draws
  no Jumu'ah section; a Friday with a supplied time draws it.
- `SignageStoreTests`: `events_keep_the_last_good_value_on_failure`.
- Passing **unedited**: `TvConfigEndpointTest`, `TvConfigSnapshotTest`.

**Ship.** §3.3. **Burlington's board changes only when the owner releases a
new Burlington TV build.** That is a manual `altool --type appletvos` upload
(`ios:.claude/rules/appstore-ship.md:122`), and the owner has said it should (§8 OQ4). No
iPhone target links MasjidKit, so the phone apps are untouched
(`pbxproj:1631-1633`).

**Live impact.**

- The board: none until a TV release.
- The payload field: none. It is absent for every existing organisation, whose
  `is_default` is null (ABI).
- The phone apps: the Jumu'ah change reaches Burlington's, NAFIS's and MEC's apps
  in their next ordinary releases, and changes nothing for them, because none
  carries the flag.
- **This slice must be merged before any Studio-generated app is released.**

**Verify.** On a TestFlight TV build, with the owner's go, on an Apple TV or
the simulator:

- the countdown label switches from iqama to adhan at the right moment;
- an event slide appears;
- the appeal shows its text.

**Size.** ~4 sessions (estimate).

---

### S19: Per-organisation TV targets (iOS scaffolder and workflow; MasjidWebMS)

**Goal.** Templatise `MasjidTV` (spec §5 W2) so every Studio client with tvOS
gets its own TV target, configured rather than code-edited, on its own App
Store record (R2).

**Facts.**

- There is one TV target, and it is Burlington's: its bundle id and
  `MASJID_ID = 1` (`pbxproj:2396-2397, :2720-2721`) ship on Burlington's
  record (`appstore-ship.md:99-104`).
- The TV app reads the organisation from Info.plist `MasjidID` =
  `$(MASJID_ID)`, with a launch argument that only Xcode applies
  (`ios:MasjidTV/Resources/Info.plist:27-28`; `ios:MasjidTV/App/TVAppConfig.swift:31-49`).
  So an archive of the MEC TV scheme builds Burlington
  (`ios:Masjid.xcodeproj/xcshareddata/xcschemes/Muslim Education Center TV.xcscheme:64, :89-92`;
  `ios:XCODE-CLOUD.md:80-87`).
- The display name `Masjid TV`, the icon and the Top Shelf art are Burlington's
  (`Info.plist:7-8`; `appstore-ship.md:105-110`).
- `--include-tvos` adds a scheme and no target
  (`ios:scripts/scaffold_masjid_app.rb:382-394`).
- `ios:XCODE-CLOUD.md:3-9` says tvOS cannot be archived from the command line
  on the owner's machine: there is no tvOS development profile, and the team
  is at its certificate maximum.

**Contract.**

- **Scaffolder `--tvos`.** It duplicates the `MasjidTV` target into
  `<Name> TV`, the way it duplicates the iPhone target, and sets:
  - `PRODUCT_BUNDLE_IDENTIFIER` to the organisation's iOS bundle id (R2);
  - `MASJID_ID`;
  - `INFOPLIST_KEY_CFBundleDisplayName`;
  - an icon and Top Shelf set `TVAppIcon-<SLUG>` built from `icon_url`: a
    layered stack whose back layer is the brand colour and front layer the
    logo, plus a wide Top Shelf image in the brand colour with the logo;
  - `TVFallbackLogoAsset` set to a neutral mark;
  - a shared `<Name> TV` scheme that builds that target.
  
  `--include-tvos` stays accepted with a deprecation note. `MasjidTV` is not
  modified.
- **Workflow.** When the payload carries `tvos_job_id`, after the iPhone build:
  - it builds `<Name> TV` for the tvOS simulator. **W2 never signs or uploads**
    (R5), so a simulator build is "a repo that builds". Signing a TV archive
    is W3, and §8 OQ15 applies there;
  - it calls back with `"platform":"tvos"` and the TV job's token;
  - both targets go in the same pull request.
- **MasjidWebMS:** S17's callback already accepts `tvos`. This slice adds only
  its tests.

**Tests.**

- `scripts/test_scaffold.rb`:
  - `the_tv_target_bundle_id_equals_the_ios_bundle_id`
  - `the_tv_target_sets_masjid_id_and_display_name`
  - `masjidtv_is_byte_identical_in_the_project_file`
- `StudioAppGenerationTest`: `a_tvos_callback_advances_only_the_tvos_job`.

**Live impact.** None. Burlington's `MasjidTV` target is unchanged. A merged
pull request adds a target.

**Verify.** The first Studio client's pull request includes its TV target, and
CI builds it. On the tvOS simulator, the board shows that organisation's name,
logo, colours and prayer times, and never Burlington's.

**Size.** ~3 sessions (estimate).

---

### Exit walk (not a slice)

With the owner:

1. Studio provisions a new client with iOS, Android, tvOS and web (W1's flow).
2. **Generate apps** in Studio. The OneSignal app appears (S14), and pull
   requests open in both repos (S16, S17, S19).
3. Both generation runs are green on the committed trees. The pull requests
   are reviewed and merged by a person.
4. A local build of each from `main` on a device shows the client's name, icon
   and colours. Its subscription lands in the client's own OneSignal app.
   Nothing reaches either store.
5. The TV target shows the D11 board for that organisation.
6. Studio opens masjid 17: two features change in one save, and brand assets
   are regenerated.
7. A throwaway host is attached and detached (S3).
8. The owner has the capacity report (S1).
9. RBI shows 1, 13, 14 and 18 unchanged, and ABI shows 1, 5, 13, 14 and 18
   unchanged.

---

## 6. What W2 does not do

**Each item W1 §6 deferred** (`docs/manara-studio-w1.md:1910-1956`):

| W1 §6 item | W2 |
|---|---|
| No apps generated; D9 | S14–S17 |
| tvOS (D11) | S18–S19 |
| Repos, MasjidKit, web export, source download, store toggles | W3 (`docs/manara-studio-w3.md`) |
| No LLM-written copy (D4) | **Not in W2 or W3.** Spec §5 assigns it to neither. It would also need a way to prove where generated words came from, as `StudioStarterSiteServedProvenanceTest` does for facts (§8 OQ13) |
| Existing orgs not edited: the bulk PATCH, delegation, brand regeneration, Studio opening a live org | S7, S8, S9 |
| MasjidAdmin placeholder checklist; `studio:apply-layout` | S10, S11 |
| Arabic starter labels; website locale in the lookup | S12, S13 |
| Detach or remove a hostname; Cloudflare cleanup on force-delete | S3; S2 with S3 |
| Case 3 for a live client's domain | **A standing rule, not a slice.** S6's `adopt` is the tool once MEC and the owner say go. W2 runs nothing against `meccharlotte.org` |
| No apex↔www policy | S5 |
| Static env lists not retired | **Left out.** After W1 S9 they are the fallback when the table read fails (W1 S9: "On any `Throwable` … the static list only"). Retiring them removes the safety net to save one env line |
| Confirmed hosts not re-probed | S4 |
| Imported rows frozen | S6 |
| Pages custom-domain ceiling | S1, and S5 halves each client's use |
| Live orgs' pivot/switch disagreements | **Left out.** The S2b cutover owns them. ASSUMPTIONS.md:37 (row 14, 🔴) remains open for the owner |

**Also not in W2:**

- **Moving Burlington, NAFIS or MEC to their own OneSignal apps.** Their
  devices are registered under the shared app, so a move needs a period of
  sending through both apps and a release through each store listing. S14's
  guard refuses it (§8 OQ9).
- **Uploading any build, or creating any store record.** Those are W3's
  toggles (D10).
- **Signing or publishing a BYO client's app.** W2 builds it unsigned.
- **A tv-config admin screen, or any change to tv-config's bytes.**
- **A notification service extension for targets other than Burlington's**
  (rich pushes and confirmed delivery). Alert pushes work without one.
- **Android TV, and the school TV board** (D11, D15).
- **An Arabic webfont in the renderer** (`renderer:docs/multi-tenancy.md:406-414`).

---

## 7. Observed, out of scope

Each needs its own ticket.

**Carried from W1 §7, unchanged:**

- `google_maps_key` on `/api/v1/settings`;
- the possible `X-Forwarded-Host: localhost` case;
- `SectionType::withoutRenderer()` listing OFFERING;
- `Masjid::header_logo()`/`footer_logo()` without a `model_type` filter;
- `renderer:tests/tenant-unchanged.test.ts` pinning `mec-web`'s map;
- `PagesSeeder` inventing content.

**New:**

- **MasjidWebMS is a public repository** (`gh repo view`). Its docs, host maps
  and plans are world-readable. The owner decided on 2026-09-24 to make it
  private, and will change the visibility themselves.
- **A Google API key is committed in the iOS repo** (`ios:Masjid/Models/S.swift:16`;
  value not reproduced). It must move to configuration before W3 derives
  client repos from this code.
- **The renderer tracks `.env` and `.wrangler/state` on `origin/main`**
  (renderer recon F14; values not read). They need review, and W3's export
  must exclude them.
- **The "Muslim Education Center TV" scheme archives Burlington's board**
  (S19's facts). Retire it once MEC has a real TV target.
- **Stale binding docs:**
  - `ios:CLAUDE.md:22, :28-32, :55, :94-96`;
  - `ios:scripts/add_tvos_target.rb:10`;
  - `ios:scripts/SCAFFOLD-README.md:39`;
  - the three Android release documents (§3.4);
  - the renderer's Vercel-era docs (`renderer:CLAUDE.md:11, :27`);
  - the project `CLAUDE.md`'s "Laravel 11" (`composer.json:16`: `^12.0`).
- **Android `versionCode` is shared by every flavor** (`android:app/build.gradle:9-18`).
- **Only Burlington embeds the OneSignal extension** (`pbxproj:1561`).
- **The renderer's CI never builds the Cloudflare preset**
  (`renderer:.github/workflows/build.yml:39-43`).
- **`setAssistantAccess` does no MobileCache flush**
  (`MasjidsController.php:255-266`). Whether `/menu` reads the assistant flag
  is unknown.
- **Stored BYO store credentials are never read** (apps-plane recon F25), and
  each runner signs with its own repo's secrets. W3 decides whether to keep
  collecting them (W3 OQ). The owner decided on 2026-09-24 to keep collecting
  them, per D1 (W3 §8 OQ4).
- **Dedicated-app sends still need the shared credentials,** because the
  shared `isConfigured()` check runs first (`OnesignalService.php:53-56`).
  Production has them, but removing them later would stop every send.
- **The Android scaffolder's generated flavors fall back to Burlington's
  branding.** S16 fixes this for generated flavors. The same fallback exists in
  main code for any flavor that lacks those files (`android:HANDOFF.md:31-39`).

---

## 8. Open questions

Most of these were put to the owner in an interview on 2026-09-24. A resolved
row keeps its question struck through and records the answer. The rows still
open are either reads that settle themselves (OQ14), unknowns to confirm at
build time (OQ15), or another plan's decision (OQ11).

| # | Question | Blocks | Answer or recommended default |
|---|---|---|---|
| OQ1 | ~~The package and bundle namespace for new apps~~ | S17 | **Resolved (owner, 2026-09-24): `com.hopetechapps.<slug>` on both platforms** (R17) |
| OQ2 | ~~Which Apple team, and which Play console, is "Hope Tech's own" managed account?~~ | S14 (APNs key), S16, S19 | **Resolved (owner, 2026-09-24).** Apple: the team NAFIS and MEC ship under (`ios:Masjid.xcodeproj/project.pbxproj:2417, :2453`). Play: the console that holds `com.app.masajid` |
| OQ3 | ~~One Firebase project for every client's Android push, or one per client?~~ | S14 | **Resolved (owner accepted the default, 2026-09-24): one "Manara apps" Firebase project, with each package registered in it.** Confirm against OneSignal's Android setup at build time |
| OQ4 | ~~Does Burlington's board take the D11 board on its next TV release?~~ | S18's release | **Resolved (owner, 2026-09-24): yes.** It still reaches Burlington only when the owner releases its TV build |
| OQ5 | ~~Which host is canonical for a client's own domain~~ | S5 | **Resolved (owner, 2026-09-24): `www` serves, the apex redirects** |
| OQ6 | ~~The re-confirmation thresholds~~ | S4 | **Resolved (owner, 2026-09-24): three misses over at least 72 hours.** Imported and adopted rows are never demoted automatically; the owner is emailed |
| OQ7 | ~~The ceiling notice thresholds, and `OPS_ALERT_EMAIL`~~ | S1 | **Resolved (owner, 2026-09-24): 50, 70, 85 and 95 percent. The owner sets `OPS_ALERT_EMAIL`** |
| OQ8 | ~~Who writes or approves the Arabic starter labels?~~ | S12 | **Resolved (owner, 2026-09-24): the owner reviews them.** S12 ships dark until the review lands. The renderer's Arabic interface strings still marked `_review` (`renderer:i18n/i18n.config.ts:4-7`) go to the same review |
| OQ9 | ~~When do Burlington, NAFIS and MEC move to their own OneSignal apps?~~ | Not W2 | **Resolved (owner, 2026-09-24): later, in a plan of its own, once the first Studio client is live** |
| OQ10 | ~~The legacy "Generate Apps" button stops signing and uploading (R5). Acceptable?~~ | S16 | **Resolved (owner, 2026-09-24): yes, it stops too** |
| OQ11 | A Studio organisation given content for a module that is off by default blocks the S2b cutover (ASSUMPTIONS.md:37) | Not W2; the first such Studio client | Open, and S2b's decision. Recommended: make `app-features:cutover-plan` treat "pivot off, switch already off" as non-blocking |
| OQ12 | ~~GitHub Actions minutes on the Free plan~~ | S16, and W3 | **Resolved (owner, 2026-09-24).** W2 keeps its few iOS generation runs on hosted `macos-15`. W3 uses a dedicated self-hosted Mac (W3 §8 OQ6). The owner's personal GitHub Pro plan does not apply to `hope-tech-apps`, which is billed separately on Free (docs.github.com, "GitHub Actions billing") |
| OQ13 | ~~When should the provenance design for LLM-written copy (D4) be planned?~~ | Neither W2 nor W3 | **Resolved (owner accepted the default, 2026-09-24): after W3, as its own plan.** D4 itself is not reopened |
| OQ14 | Where Burlington's apex 307 is configured | S5's refusal list | A read (§4). S5 refuses every zone that is neither Studio-created nor on its allowlist, so it is safe either way |
| OQ15 | Whether tvOS signing works on a CI runner, given the certificate situation (`ios:XCODE-CLOUD.md:3-9`) | W3's TV upload | Unknown, needs investigation. W2 builds the TV target for the simulator only. W3 R16 adopts the repo's recorded manual-signing recipe |
| OQ16 | ~~Jumu'ah that the client never supplied~~ | S18 | **Resolved (owner, 2026-09-24): use the `jumaa_is_default` flag,** emitted only when true and honoured by the board and both phone apps |
