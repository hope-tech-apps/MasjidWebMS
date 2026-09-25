# Adversarial review of the w3 plan draft

> Read-only recon for the Studio W2/W3 plans, 2026-09-24. MasjidWebMS `c3fc0324`, iOS `origin/main` `8e5191f`, Android `origin/master` `cf61d54`, renderer `origin/main` `6a7ead2`.
> Line numbers are as of those commits; re-read before relying on one. This repo is PUBLIC: identifier-shaped values (OneSignal ids, Apple team ids, key ids) are redacted as `[redacted]`.

> Every finding below was checked against the code and applied to the plan before it was committed; this is the record of what the review caught.

## Verdict

The plan gets the main vendor facts right. Neither store can create an app through its API. Play needs its first upload done by hand. SwiftPM needs `Package.swift` at the root of a repository. On GitHub Free, organisation secrets are not available to private repos. Most of its citations hold up (about 40 checked).

It is still not ready to build. Several contracts cannot work as written, and one is unsafe:

- It puts an org-wide GitHub admin credential on the public web server and says the live impact is none.
- Client repos "hold no secret", yet they must resolve private packages.
- The TV "shell" is handed to an iOS-only UI product.
- The tvOS upload uses automatic signing, which the iOS repo already records as failing.
- The S2 dependency facts are wrong.
- A new client cannot reach per-client repos without first getting a monorepo target, which S13 then refuses.
- The web export's handover promises a CORS and payment path that no code can create.

S1, S2, S7/S11, S12–S15, S17–S19 and S22 need revising before a builder starts.

## Findings

1. **[BLOCKER] The GitHub App credential on the production web server can delete, publish or push to every repo in the organisation** (R10, S12 owner action, S15 step 3, S17).
   - **What is wrong:** R10 installs `manara-studio` on the organisation with Administration: write and Contents: write, and its private key goes in production `.env`.
     - Creating org repos, and reaching a repo the App just made, in practice needs an all-repositories installation (vendor knowledge, verify).
     - S15 needs Contents and Pull requests write on the shared live iOS and Android repos.
     - With those grants, a leaked key can delete repos, flip them to public, or push to `main` of the live apps' repos. Administration: write is the permission that allows delete and visibility changes.
     - `there_is_no_delete_or_archive_method` is a code guard, not a limit on the credential.
     - R9's own reasoning ("the web server never holds either key") is dropped here for a stronger key.
     - S17 dispatches the renderer workflow, which needs Actions: write or Contents: write on `burlington-masjid-site`. Neither is in the permission list, which grants only Actions: read.
     - S12's "Live impact: None" is therefore wrong in substance.
   - **Evidence:** W3:154, :589-599, :666, :744-745, :830.
   - **Fix:** Keep repo creation off the web server. Either a person runs `gh repo create --template`, or a workflow in a dedicated private repo holds the creator credential, as R9 does for store keys. Give the server's App only a selected-repositories installation: the templates and the `manara-*` client repos, Contents and Pull requests only. Never install it on MasjidWebMS or the iOS, Android or renderer repos. S15's removal pull requests are opened by a person or a workflow. List every permission each method needs, including dispatch.

2. **[MAJOR] Private dependencies make "client repos hold no secret" and "builds green on CI" impossible** (S8, S10, S11, S18, R9, §0 exit 1).
   - **What is wrong:** `hope-tech-apps/MasjidKit` is private, and so are any quran-ios or Popover forks. SwiftPM must fetch them with a credential. A client repo's `GITHUB_TOKEN` cannot read another private repo. S11 still says the iOS template "holds no secret. A simulator build needs none."
   - **Android:** GitHub Packages Maven packages are repository-scoped, with no per-package grants (vendor knowledge, verify). So OQ8 is almost certainly "no". The fallback, "a read-only package token written by the GitHub App", cannot work, because installation tokens expire in an hour.
   - **Also missing:**
     - S8's "Live impact: None" ignores that the owner's release path and Xcode Cloud (if it exists) now need access to MasjidKit.
     - store-ops' `ios-upload` needs the same credential, which S19 does not list.
   - **Evidence:** W3:103-107, :559-562, :571-574, :203, :907-909.
   - **Fix:** Name the credential and its scope in R9. Options: a read-only deploy key or fine-grained token scoped to MasjidKit alone, and a machine-user classic token with `read:packages` only for Maven. Say that each client repo holds exactly this one secret, and add it to S12's "no secret" test as the one allowed secret. List it in S19 and in S8's live-impact line.

3. **[MAJOR] The TV target cannot be a shell handed to `MasjidKitUI`, and the signage code has no home** (S4, S7, S11 `TV/`).
   - **What is wrong:** S7 has every TV target hand its configuration to `MasjidKitUI`, "every screen, the app shell". That code imports OneSignalFramework, SafariServices, CountryPicker, PhoneNumberKit and MapKit. OneSignal and SafariServices do not support tvOS (vendor knowledge, verify). No slice moves `MasjidTV/{App,Data,Signage}` (9 files, 1,185 lines) into a package. So S11's config-only `TV/` target has nothing to link.
   - **Evidence:** W3:442-444; imports counted in `ios:Masjid/**`.
   - **Fix:** Add a `MasjidKitTV` product (signage plus data) in S7. Make `MasjidKitUI` iOS-only, with `.when(platforms: [.iOS])` conditions. Add a test that the TV shell links only `MasjidKit` and `MasjidKitTV`.

4. **[MAJOR] S22 uses automatic signing for tvOS, which the repo records as failing** (S22, S19, OQ9).
   - **What is wrong:** `ios:.claude/rules/appstore-ship.md:111-119` says automatic signing fails for tvOS: there is no registered device and no development profile. The working recipe is a DISTRIBUTION certificate plus a `TVOS_APP_STORE` profile made through the API, manual signing, and an isolated keychain.
     - S19's secrets have no distribution certificate `.p12`, and S20 creates no profiles.
     - OQ9's "revoke an unused certificate" does not say which one. Revoking one breaks the next release of whichever live app's profiles use it.
     - W2 OQ15 is left unresolved.
   - **Fix:**
     - S20 also creates the `IOS_APP_STORE` and `TVOS_APP_STORE` profiles for the new bundle id.
     - store-ops holds one distribution `.p12`.
     - S22 signs manually.
     - OQ9 names the certificate and checks which live profiles depend on it before anything is revoked.

5. **[MAJOR] S2's dependency facts are wrong** (S2, R6, S5 batch 5, slice map "the Qur'an reader's dependency").
   - **Popover is transitive:** it is not among the project's package references (`ios:…/project.pbxproj:2939-3000` lists eight, and Popover is not one). It comes in through quran-ios's own manifest, so "require that tag" at the project level cannot pin it. A tagged quran-ios fork whose manifest still says `branch: master` is rejected under a version-pinned MasjidKit.
   - **The reader does not use quran-ios:** the reader is the ported WirdReader on system SQLite3 (`ios:.claude/rules/quran-reader.md:7-20`). The only quran-ios use in app code is `import NoorFont` / `FontName.registerFonts()` (`ios:Masjid/AppDelegate.swift:9, :55`).
   - **Two smaller errors:**
     - R6's "or an exact revision" is also unstable under SwiftPM.
     - S2's "a tag at or after the revision" contradicts "the resolved revision must not change".
   - **Fix:** Remove quran-ios, and Popover with it. Keep only the NoorFont fonts actually used, as resources. Otherwise fork both, point the quran-ios fork's manifest at a tagged Popover fork, and use `exact:` tags only. Correct the risk column and R6.

6. **[MAJOR] A new client cannot get client repos, and `code_home` contradicts itself** (S12, S13, S15).
   - **No path for a new client:** S12 requires W2's identity and OneSignal app. Those are written only inside W2 S17's `generate()`, which also dispatches the monorepo pull request (W2:1706-1730). S13 then refuses client-repo creation "while a monorepo target exists". So the §0 exit path is circular.
   - **Contradictory timing:** S12/S13 set `code_home = client_repos` when the first client repo is created (W3:682). S15 sets it only after the removal merges, and pins that in `code_home_changes_only_after_the_removal_merges` (:746, :754). S15's step 1 is itself refused by S13's rule.
   - **Order:** S12 writes S13's column before S13 exists.
   - **Fix:**
     - Extract `AppIdentity::ensure($org)` from W2 S17, covering the identity columns plus `ensureApp`, and have S12 call it.
     - Add a `moving` code-home state for S15.
     - Move the column into S12.

7. **[MAJOR] After S1, W2's generation plane binds a new app to an unspecified default org** (S1).
   - **What is wrong:** S1 deletes `BuildMasjid+*.swift` and reads `MASJID_ID`, but the scaffolder still writes `BuildMasjid+<Slug>.swift` and no `MASJID_ID` (`ios:scripts/scaffold_masjid_app.rb:336-338`; W2 S16 commits it). The Release "fail-closed default" names no value for `masjidId`. If it is 1, a generated app shows Burlington, which is landmine 1 in another form.
   - **Fix:**
     - S1 updates the scaffolder and `test_scaffold.rb` to emit `MASJID_ID` and every required key.
     - In Release, a missing `masjidId` shows a configuration-error screen and never a live org's id, with a test that pins it.

8. **[MAJOR] An exported site's host cannot be "re-confirmed", so CORS and card payments break** (S17 handover).
   - **What is wrong:** W2 S3's detach deletes the `masjid_domains` row (W2:492-493). Re-adding the host runs `DomainAttacher`, which writes a CNAME and a Pages domain on `manara-renderer`, or creates a zone in Hope Tech's account for a domain that lives in the client's own account (`app/Services/Domains/DomainAttacher.php:25-33`). No row type exists for "served elsewhere, probe only".
   - **Fix:** S17 adds `source = external` rows. They are probe-only, never written to Cloudflare, and re-probed by W2 S4. The handover note names that step.

9. **[MAJOR] The web export's exclusion list leaks internal material** (S16).
   - **Other tenants' notes:** The denylist misses the renderer root's `LOG.md`, `NOTES.md`, `DECISIONS.md`, `ASSUMPTIONS.md`, `PLAN.md` and `CHANGELOG.md`, and `scripts/mec-media-sync.mjs`, all present in the snapshot. They carry operational notes about Burlington, Al-Razi and MEC that are public nowhere, and they reach a client through S18.
   - **Lookup not pinned off:** After W1 S10 the export also carries the runtime host lookup. Nothing pins it off, and with it on an exported deploy could resolve other tenants' hosts.
   - **Fix:**
     - Use an allowlist of top-level paths (app/, server/, shared/, public/, i18n/, config and package files), with a test that fails on any unlisted path.
     - Pin the lookup flag off in `export.json`, with a test.

10. **[MAJOR] The source download hands over something that cannot build, and web repos can never pass its gate** (S18, D3).
    - **Cannot build:** A config-only `manara-<slug>-ios`/`-android` zip needs private MasjidKit or `masjidkit` to build.
    - **Gate never passes:** S17's web repo is committed without workflows (S16 strips them), so "latest run has a green secret scan" answers 409 forever.
    - **Fix:**
      - Add an OQ: does a handover include MasjidKit at the pinned tag, and under what licence?
      - S17 commits a gitleaks workflow into the web repo.
      - S18 calls GitHub with `withoutRedirecting()` and never logs the tokenised archive URL.

11. **[MAJOR] store-ops can still reach live listings** (R9, S19–S22, §4).
    - **Play access:** §4 treats account-wide Play access as the goal. With it, store-ops' service account can edit Burlington's, NAFIS's and MEC's listings.
    - **Denylist input:** The denylist is checked against the stored identity, not the built artifact. A client repo edit to `PRODUCT_BUNDLE_IDENTIFIER` would pass.
    - **"Exactly one place" is not true:** the iOS repo keeps its `ASC_*` secrets (web-facts G2).
    - **Missing from store-ops:** the App key it needs to read client repos, and `MAPS_API_KEY` for Android builds.
    - **Open edits:** `play-check`'s `edits.insert` leaves edits open.
    - **Fix:**
      - Grant the Play service account per app, as the person step in S21.
      - Check the denylist against the archived `CFBundleIdentifier` and the AAB's `applicationId`, which must also equal the recorded identity.
      - Remove `ASC_*` from the iOS repo once store-ops is live.
      - List every store-ops secret, or pass scoped tokens.
      - Delete the edit after the check.

12. **[MAJOR] Hosted-macOS CI on every client push contradicts OQ6 and exhausts the organisation's minutes.**
    - **What is wrong:** S11 builds the iOS template and every client repo on hosted macOS for every push and pull request. S14 fans out one pull request per client. Free includes about 2,000 minutes, and macOS counts 10× (vendor knowledge; §4 reads the real figure). One bump across a few clients can stop every private-repo workflow in the organisation, the renderer's CI and W2 generation included.
    - **Fix:** Run client iOS CI on the self-hosted runner, or only for bump pull requests, and state the per-bump cost.

13. **[MINOR] The provisioning callback is "unchanged" but must carry store results** (S19, S20). It cannot carry `asc_app_id`, the highest build number or `waiting_on_person`, and W2 makes `built` terminal. Add a per-kind `result` object to the callback.

14. **[MINOR] Group D does not "start at once".** S17 and S18 depend on S12, which depends on S11 → S8/S10, the whole refactor. Split S12a (the GitHub client and `client_repos` table, no dependencies) from S12b.

15. **[MINOR] Android details** (S9, S10).
    - `BuildConfig` is also read in `CurrentOrg.kt:57, :74` and `RetrofitClient.kt:120` (`DEBUG`).
    - The library's default `ic_logo_green` and `brand_primary` must be neutral, not Burlington's.
    - `mapsApiKey` in `MasjidKitConfig` is inert, because the Maps SDK reads the manifest.
    - S10 switches the live apps to a downloaded binary, so its risk is medium, not low.

16. **[MINOR] S1: the Google key is unused and the template's key list is incomplete.**
    - The key is declared at `S.swift:16` and read nowhere. Delete it and drop `googleServicesKey` and the Secrets.xcconfig/CI-secret steps.
    - S11's xcconfig key list lacks S1's API base, deletion URL, background task id and fallback colour. `BGTaskSchedulerPermittedIdentifiers` must match the id (`ios:Masjid/Info.plist:30-32`).
    - Add a test that the template carries every required key.

17. **[MINOR] Coverage tests not named.** `store_steps` and `source_downloads` need `TenantScopingCoverageTest` entries. `source_downloads`' name and email snapshot will trip `StagingScrubCoverageTest` until `config/staging_scrub.php` classifies it.

18. **[MINOR] `studio:bump` edits only the pin.** It must also update `Package.resolved` with the tag's commit and the Gradle lockfile. The reconciler only reads `building` rows, so it needs a default-branch pass to update `pinned_version`.

19. **[MINOR] S12 generate/commit race.** Template generation finishes asynchronously, so `commitFiles` must wait until the default branch exists (verify).

20. **[MINOR] OQ4's default relitigates D1.** D1 says the "BYO credential handling" is kept. Frame stopping collection as a D1 change for the owner.

21. **[MINOR] tvOS platform via the API.** `POST /v1/appStoreVersions` may be able to add the tvOS platform (uncertain, verify). If it can, S20 automates the step instead of routing it to a person.

22. **[MINOR] Wrong citations.**
    - `appstore-ship.md:116` should be `:122`.
    - `:117-119` should be `:123-125`.
    - `AppConfig.kt:15-19` is a doc comment; the read is at `:86`.
    - `quran-reader.md:13` binds WirdReader, not quran-ios.

## Citations checked

42 checked. 3 have the wrong line (finding 22), and 2 more are mischaracterised: the Popover dependency and `quran-reader.md:13` (finding 5). No secret-shaped values were found in the plan.

## Coverage

Complete for brief §2 W3 and for W1 §6's W3 items. The W2 hand-offs are mapped: the Google key, the renderer `.env`, BYO credentials and the runner question (W2 OQ12). Two gaps remain:

- **W2 OQ15**, tvOS signing, is not resolved by S22 (finding 4).
- **W2 §7's** "owner confirms MasjidWebMS is meant to be public before W3 creates derived repos" appears in W3 §7 but is not a §4 preflight gate.
