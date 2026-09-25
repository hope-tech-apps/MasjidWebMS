# Adversarial review of the w2 plan draft

> Read-only recon for the Studio W2/W3 plans, 2026-09-24. MasjidWebMS `c3fc0324`, iOS `origin/main` `8e5191f`, Android `origin/master` `cf61d54`, renderer `origin/main` `6a7ead2`.
> Line numbers are as of those commits; re-read before relying on one. This repo is PUBLIC: identifier-shaped values (OneSignal ids, Apple team ids, key ids) are redacted as `[redacted]`.

> Every finding below was checked against the code and applied to the plan before it was committed; this is the record of what the review caught.

## Verdict

The plan is thorough, and most of its code citations are accurate. I checked about 95 `path:line` citations and 7 were wrong, most by a few lines. It is still not safe to build as written. One composition of slices can delete a live client's Cloudflare records: S6 `adopt` followed by S3 detach. The "fail closed" OneSignal contract would crash Android. The iOS scaffolder would quietly put new client apps back on Burlington's push channel (landmine 1). Three slices claim "passes unedited" or "never signs" where the code says otherwise. Fix finding 1 before S3 or S6 starts, and findings 2–7 before S14, S15 or S16. The rest can be corrected in the plan text.

## Findings

1. **[BLOCKER] S3 can delete records Studio never created, and S6 `adopt` turns live hosts into detachable rows** (S3 contract, S6 `adopt`, R8)
   - **What is wrong.** R8 promises S3 deletes an object only "if it is still exactly what Studio created". The re-read checks only the name, project and content, and W1 stores the id of an *adopted* CNAME or Pages domain exactly as it stores one it created (`DomainAttacher.php:548-566`; the service's docblock says `ensureCname()` adopts an existing CNAME). S6 `adopt` accepts any imported row, not just `reserved` ones, and sets `source=studio, status=pending`. For a live `manual` row, `pending` is outside `TRUSTED` (`MasjidDomain.php:74-77`), so CORS and payment-return admission drop at once. After adoption the row also gets S3's Detach button and S4's automatic demotion, the two protections W1 gave imported rows (R28). The plan names MEC's `meccharlotte.org` as the adopt case, so after MEC's cutover one confirmed click in the detach dialog deletes MEC's live CNAME and Pages domain.
   - **Fix.**
     - Add `cf_dns_record_created` and `cf_pages_domain_created` flags, set only when Studio's own POST created the object, as `cf_zone_created` already is. `CloudflareRemover` deletes only flagged objects.
     - Restrict `adopt` to `reserved` rows.
     - Stamp adopted rows with `adopted_from_import_at`. S3 refuses to detach them, and S4 treats them as imported.
     - Tests: `an_adopted_object_is_never_deleted` and `adopt_refuses_a_manual_or_active_row`.

2. **[MAJOR] S15's Android "skip initialisation" crashes the app instead of failing closed**
   - **What is wrong.** The app uses OneSignal SDK 5.1.32 (`android:gradle/libs.versions.toml:33`). As I recall its behaviour (vendor knowledge, not read in the evidence files; confirm at build), `OneSignal.User`, `OneSignal.Notifications` and `login` throw before `initWithContext`. Unguarded call sites: `MasajidApp.kt:105, :112, :148, :153, :239`, `MainActivity.kt:578, :588` and `SplashScreen.kt:180`. A blank id would therefore crash `Application.onCreate`, and the test `a_blank_id_skips_initialisation` cannot see that.
   - **Fix.** Make a blank id fail the Gradle build for any flavor, or route every call through one wrapper that no-ops when uninitialised. Add a Robolectric launch test with a blank id.

3. **[MAJOR] Generated iOS targets inherit Burlington's OneSignal id after S15** (S15, S16)
   - **What is wrong.** The scaffolder deep-copies the golden target's build settings (`ios:scripts/scaffold_masjid_app.rb:264`) and overrides `ONESIGNAL_APP_ID` only when an id is given (`:163`). Once S15 sets Burlington's id on the `Masjid` target, any scaffold without an id inherits it. The legacy button never sends one (`AppProvisioningController.php:180-193`). The result is landmine 1 through configuration, and S15's fail-closed check never fires because the value is not empty.
   - **Fix.** Make the iOS scaffolder require `--onesignal-app-id`, or clear the inherited key. Test `it_never_inherits_the_template_targets_onesignal_id`.

4. **[MAJOR] Dedicated-app sends may be refused because every send hard-codes `Basic`** (S14)
   - **What is wrong.** S14 mints the key through `/apps/{id}/auth/tokens`, and OneSignal's current reference uses `Authorization: Key` for keys minted that way (web-facts W4). Every send path hard-codes `Authorization: Basic` (`OnesignalService.php:193, :247, :293, :404`). Whether `Basic` accepts the new key is Unknown, needs investigation. S14 tests only in-app-message routing.
   - **Fix.** `resolveConfig` returns the auth scheme along with the key. Add `Http::fake` header assertions for each send path. Add a real send to the first client's test device to "Verify in production".

5. **[MAJOR] S14's production check is run against Burlington, and the audience guard runs too late**
   - **What is wrong.** "Verify in production" calls `POST .../1/onesignal/provision` and expects `has_audience`. If the guard misreads, Burlington's sends move to an empty app immediately (apps-plane recon R1). Separately, step 3 (mint a key for an id with no key) runs before step 4 (the live-audience guard).
   - **Fix.** Move the guard to step 1. Verify on masjid 17 with a registered test device, or with a read-only `--pretend`. Never call the creator for 1, 5 or 13.

6. **[MAJOR] `ensureApp` returns `exists` without checking which platforms the app was set up for** (S14, S17)
   - **What is wrong.** If a client's Android app is generated first, the OneSignal app is created without APNs. A later iOS generation returns `exists` and sends nothing. The iOS target then ships with its own id and no working push.
   - **Fix.** Record the platforms the OneSignal app is configured for. When a new platform is requested, add APNs or FCM to the existing app through OneSignal's update call. Test it.

7. **[MAJOR] The Android preflight run signs with Burlington's upload key, possibly under Burlington's own package** (§4 "A green run…")
   - **What is wrong.** §4 claims `account_mode: byo` means "compile only and never sign". The workflow signs `bundle<Flavor>Release` whenever the upload key resolves, whatever the account mode (`provision-android-app.yml:150-172`). Only the upload step checks the mode (`:197-199`). The runner Mac holds Burlington's keystore (`android:HANDOFF.md:75`). With no `application_id_suffix`, the package is `com.app.masajid`, Burlington's own (`:24, :194`). The run would leave a Burlington-package AAB signed with Burlington's upload key on the owner's Mac.
   - **Fix.**
     - Send a unique suffix.
     - Confirm `SIGNING_CONFIGURED=0` on the runner, or skip the as-is Android run and make S16's `upload`-gated version the first run.
     - Check the runner is online first (`gh api .../actions/runners`).

8. **[MAJOR] S16's pull-request flow needs an owner setting the plan never lists, and "builds green on CI" cannot hold**
   - **Unlisted owner action.** `gh pr create` with `GITHUB_TOKEN` needs the setting "Allow GitHub Actions to create and approve pull requests", which is off by default.
   - **No CI.** PRs opened with `GITHUB_TOKEN` trigger no workflows. The Android repo has no CI (§3.4), and the iOS repo's only workflow is provisioning. Exit criterion 1 ("each builds green on CI") can only mean the provisioning run.
   - **No branch protection.** On GitHub Free, private repos cannot protect branches (G1), so `contents: write` could push to `main`.
   - **Lost runs.** GitHub keeps only one pending run per concurrency group, so a third dispatch cancels the second and leaves its job rows `dispatched` forever.
   - **Fix.**
     - List the setting as an owner action.
     - Reword the exit criterion to "the run that opened the PR built the committed tree".
     - Push only `refs/heads/studio/*`.
     - In S17, refuse a dispatch while any job is not terminal, or fail stale `dispatched` jobs.

9. **[MAJOR] S4 breaks a test it claims passes unedited, and that test pins a production property**
   - **What is wrong.** `DomainsReconcileCommandTest` expects the `'active, confirmed'` row (fixture `:46`) not to be selected. `without_a_token_on_productions_rows_it_selects_nothing_and_sends_nothing` pins that an imported, confirmed `manual` row with no token selects 0 rows and sends nothing, `Http::assertNothingSent()` included. S4 selects both and sends a GET to `/api/tenant`. The probe also logs a `warning` whenever a host is unreachable (`DomainProbe.php:78`).
   - **Fix.** Declare the edit, pin the new contract (the probe goes only to our own host, never to Cloudflare, once a day), and record it in DECISIONS.

10. **[MAJOR] S10's pure `isOpen` cannot reproduce W1's placeholder count**
    - **What is wrong.** `StarterSite::isOpen` has `bound` placeholders (open while the bound rows are empty, which needs a database read) and `review` placeholders (open while the section is unpublished) (`StarterSite.php:506-511`). DECISIONS.md:2734 says openness is "a function of the content and the bound rows". S10's function reads content only, so its own verify step (the header total equals `placeholders_open`) fails.
    - **Fix.** Extract the one existing implementation and give it the section's active state and a bound-facts reader.

11. **[MAJOR] S8's "one transaction" does not protect the old favicon, and the upload hook can fail a successful upload**
    - **What is wrong.** Spatie media deletes files from its model observer as each row is deleted, not on commit (vendor behaviour; verify at build). A failure after the clear rolls back rows whose files are already gone, so `a_failure_leaves_the_previous_derivatives…` cannot pass. The logo-upload hook would also turn a non-raster logo into a 422 after the upload has committed.
    - **Fix.** Add the new media first, commit, then delete the old media after commit. In the upload hook, catch, log at `warning`, and leave the upload's response unchanged.

12. **[MAJOR] The TV board would show a Jumu'ah time the client never gave** (S18, S19)
    - **What is wrong.** S18 cites DECISIONS.md:2728-2729 for iqama. Those lines actually say the stored 13:30 Jumu'ah default must not be shown "unless it was supplied". The board draws Jumu'ah every Friday (`ios:MasjidTV/Signage/PrayerPanelView.swift:41-42, :112-114`), so a Studio client's board would show an invented 13:30.
    - **Fix.** Add the rule to S18. The backend signal for "supplied" is Unknown, needs investigation. Add a Friday case to `CountdownTargetTests` where only the default exists.

13. **[MAJOR] NAFIS (5) is missing from the per-slice live-payload checks (W1's ABI)**
    - **What is wrong.** The brief names NAFIS as live on both platforms. S7 changes the writer that `/menu` is derived from, and S8, S12 and S14 touch mobile-readable or push state. Masjid 5 appears only in exit-walk step 9, and not in §0.
    - **Fix.** Capture 5 in ABI from S1 onward, and list it in every "ABI unchanged" line.

14. **[MAJOR] S5's zone refusal rests on data that may not exist, and its production check uses the owner's other product domains**
    - **What is wrong.** "Never in a zone holding an imported row" protects `burlingtonmasjid.com` and `alrazischool.org` only if W1's import ran with the reserved extras (`docs/manara-studio-w1.md:734-750`). The table was empty at W1 S7 (`…170000…:21-22`), and §4 does not gate S5 on the SELECT that would show it. "Verify in production" proposes `joinwird.com`, `aiinnovation.dev` or `tapcraft.tech`, which are the owner's other products, not known test zones.
    - **Fix.** Add a config `protected_zones` list that is refused regardless of rows. Gate S5 on §4's SELECT. Verify on a dedicated empty zone.

15. **[MAJOR] S3 cannot remove the placeholder record S5 creates** (S3 ↔ S5)
    - **What is wrong.** S5 creates a proxied `A 192.0.2.1` record for each redirect host. `removeCname` requires type `CNAME` and content equal to `pages_target`. A redirect row's detach therefore stays `detaching` forever, retried by reconcile, and the A record is left behind.
    - **Fix.** One `removeDnsRecord(row, expectedType, expectedContent)` chosen by the row's role, S5 storing the record id, and a test.

16. **[MINOR] S10's new route is captured by the existing `{page_id}` route**
    - `GET pages/placeholders` falls under the unconstrained `Route::get('/{page_id}', 'show')` (`routes/admin.php:502`).
    - **Fix.** Register it before that route, as `preview-session` is (`:492`), and test it.

17. **[MINOR] S9's Features card will send column-backed grants**
    - Catalogue entries carry `writer` (`CapabilityCatalogue.php:97`), and the live panel skips `writer !== 'capability'` (`OrganisationSwitchesPanel.vue:536`). Sending `crm` or `assistant` to the bulk PATCH makes the whole request a 422 (R7).
    - **Fix.** Route those keys to their own endpoints, or show them read-only.

18. **[MINOR] S12's live impact and dependencies are understated**
    - The website-locale PATCH can flip a live organisation's site to RTL once S13 ships. That needs the owner's go, and "Live impact: None" should say so.
    - S12 adds a route under S9's prefix and edits S9's identity card, but the slice map gives it no S9 dependency.

19. **[MINOR] S1's runbook names commands that do not exist yet**
    - It cites `domains:collapse-alias` (S5) and `domains:release` (S3), which are missing when S1 ships.
    - **Fix.** Mark those steps "available after S3" and "after S5".

20. **[MINOR] S14 adds a second env name for an existing credential**
    - `ONESIGNAL_ORG_API_KEY` duplicates `ONESIGNAL_USER_AUTH_KEY`, which the code already describes as the "Organization REST API Key" (`OnesignalInAppMessageService.php:40-42`).
    - **Fix.** Reuse the existing key, or retire it explicitly.

21. **[MINOR] OQ13 reopens D4**
    - It asks whether LLM-written copy is "still wanted", but D4 is settled.
    - **Fix.** Reword it as "deferred beyond W3 pending a provenance design".

22. **[MINOR] Wrong facts**
    - `routes/admin.php:456` should be `:488`.
    - `TenantScopingCoverageTest.php:150, :154` should be `:158, :162`.
    - DECISIONS.md `:2849-2850` should be `:2844-2846`.
    - DECISIONS.md `:2728-2729` is about Jumu'ah, not iqama (see finding 12).
    - "24 existing migrations" use `->change()`: it is 24 calls in 8 migrations.
    - `normalizeLocale` is actually `normalizeTenantLocale` (`renderer:shared/tenant.ts:283`).
    - §4's "byo never signs" is false (see finding 7).
    - "Three Giving refusals" is two (`MasjidsController.php:314-345`).
    - S3 lists `MasjidDomainSchemaTest` as passing "unedited" while editing it.

## Citations checked

About 95 checked and 7 wrong. The wrong ones are listed in finding 22, and the §4 "never signs" claim is expanded in finding 7. Separately, three "passes unedited" or behaviour claims are false (findings 7, 9 and 12).

## Coverage

Every item in brief §2 for W2 and every item W1 §6 defers is either sliced or left out with a reason. One process gap: the plan says the iOS and Android recon is persisted as `.claude/ios-recon.md` and `.claude/android-recon.md`, but both files are untracked on `docs/studio-w2-w3-plan`. Unless they are committed, the pushed branch will not have the files the plan points to.
