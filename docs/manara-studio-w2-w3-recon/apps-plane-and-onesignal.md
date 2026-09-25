# Recon: apps-plane-and-onesignal

> Read-only recon for the Studio W2/W3 plans, 2026-09-24. MasjidWebMS `c3fc0324`, iOS `origin/main` `8e5191f`, Android `origin/master` `cf61d54`, renderer `origin/main` `6a7ead2`.
> Line numbers are as of those commits; re-read before relying on one. This repo is PUBLIC: identifier-shaped values (OneSignal ids, Apple team ids, key ids) are redacted as `[redacted]`.

## Facts

[F1] `provisioning_jobs` has these columns: `id`, `job_id` (uuid, unique), `masjid_id` (FK, cascadeOnDelete), `platform` enum(`ios`,`android`), `status` enum(`queued`,`dispatched`,`scaffolding`,`building`,`uploaded`,`built`,`failed`, default `queued`), `detail` text, `artifact_url` string, `callback_token` string(64), `github_repo` string, timestamps, and an index on (`masjid_id`,`created_at`). database/migrations/2026_07_23_150000_create_provisioning_jobs_table.php:43-75. No later migration changes the table: this is the only migration that creates or alters it.

[F2] `ProvisioningJob` model:
- Status constants: app/Models/ProvisioningJob.php:24-32.
- `CALLBACK_STATUSES` leaves out `dispatched` but includes `queued`: :39-46.
- Platform constants are `ios`/`android` only: :48-49.
- `callback_token` is `$hidden`: :67-69.
- On create it sets `job_id = Str::uuid()` and `callback_token = Str::random(40)`: :73-83.
- The token is stored in plaintext by design: migration :64-66.
- The model does not use `BelongsToMasjid`. It is exempted at tests/Feature/TenantScopingCoverageTest.php:161.

[F3] Routes:
- `POST {masjid_id}/provision-apps` and `GET {masjid_id}/provisioning-jobs`, both behind `super`: routes/admin.php:722-725. They sit inside the `auth:sanctum`+`admin`+`tenant` group opened at :117.
- `POST /api/provisioning/callback` (named `provisioning.callback`) has no auth or throttle middleware: routes/api.php:357-360. `bootstrap/app.php` contains no `throttle` either.

[F4] `ProvisionAppsRequest`:
- `platforms` is required, an array, min 1, each value in [`ios`,`android`]: app/Http/Requests/Admin/Provisioning/ProvisionAppsRequest.php:28-29.
- Optional overrides: `name`, `display_name`, `development_team`, `bundle_id`, `include_tvos` (`sometimes|boolean`), `flavor`, `application_id_suffix`, `app_name`, `onesignal_app_id`: :32-45.
- Failures use the legacy BaseFormRequest envelope: :18-20.

[F5] `AppProvisioningController::provision`:
- Takes the masjid from the route: app/Http/Controllers/AdminDashboard/AppProvisioningController.php:45. De-duplicates platforms: :48.
- For each platform it creates a job row with status `queued` (:57-62), builds the payload (:64), calls `GithubDispatchService::dispatch` (:66), then marks the job `dispatched`, or `failed` with a detail (:68-75).
- Returns 201 `{masjid_id, jobs[]}`: :86-92.
- `repoFor` sends `ios` to `services.github.ios_repo` and anything else to `android_repo`: :128-133.
- `index` returns the latest 20 jobs as explicit columns: :110-113.

[F6] Dispatch payload:
- Common fields: `job_id`, `masjid_id`, `name`, `display_name`, `account_mode` (from the publishing row, else `'managed'`), `development_team` (request, then publishing row, then `config('services.github.development_team')`), `callback_url = SiteUrl::route('provisioning.callback')`, `callback_token`: :154-178.
- iOS adds `bundle_id` (the request value, else `ios_bundle_prefix` + "." + the name slugged with no separators) and `include_tvos` (the request value, else `platformEnabled('tvos')`): :147-148, :180-193.
- Android adds `flavor`, `application_id_suffix`, `app_name`, and `onesignal_app_id` (request, else publishing row): :197-206.
- The iOS payload has no OneSignal field.

[F7] `GithubDispatchService`:
- Sends `POST https://api.github.com/repos/{repo}/dispatches` with `event_type` `scaffold-masjid`, bearer `config('services.github.dispatch_token')`, 30 s timeout: app/Services/GithubDispatchService.php:30, :59-71.
- If the token is blank it returns an error array instead of throwing: :41-50.
- Config keys: `GITHUB_DISPATCH_TOKEN`, `GITHUB_IOS_REPO` (default `hope-tech-apps/burlington-masjid-iOS`), `GITHUB_ANDROID_REPO` (default `…-Android`), `APPLE_DEVELOPMENT_TEAM`, `IOS_BUNDLE_PREFIX` (default `com.hopetechapps`): config/services.php:66-72.

[F8] Callback controller:
- Needs `job_id` plus a bearer token, compares with `hash_equals` even when the job is unknown, and answers every failure with the same opaque 404: app/Http/Controllers/ProvisioningCallbackController.php:36-54, :122-128.
- Validates `status` ∈ `CALLBACK_STATUSES`, `platform` ∈ [`ios`,`android`] (validated but never stored), `detail` ≤2000, `artifact_url` a URL ≤2000: :57-62.
- Writes `status` always, `detail` if present, `artifact_url` if filled: :71-82.
- There is no transition check. Any listed status is accepted at any time, including after `built` or `failed`.

[F9] The runners:
- Both workflows listen for `scaffold-masjid`: ios:.github/workflows/provision-ios-app.yml:23 and android:.github/workflows/provision-android-app.yml:36.
- iOS reads `job_id`…`callback_token` from the payload (no OneSignal field) at ios:…:38-47, and signs with the repo's own secrets `ASC_KEY_ID`, `ASC_ISSUER_ID`, `ASC_KEY_P8` at :49-51. A failed callback POST only logs a warning and the run continues: :103.
- Android reads `onesignal_app_id` (android:…:58), passes `--onesignal-app-id` only when it is non-empty (:127-128), and uses `secrets.PLAY_SERVICE_ACCOUNT_JSON` (:191).

[F10] `masjid_app_publishing` columns:
- 2026_07_23_130000_create_masjid_app_publishing_table.php:35-55: `id`, `masjid_id` (unique FK, cascade), `ios_account_mode`/`android_account_mode`/`web_account_mode` enum(`managed`,`byo`, default `managed`), `asc_key_p8` text, `asc_key_id` text, `asc_issuer_id` text, `play_service_account_json` longText, timestamps.
- 2026_07_23_140000_add_onesignal_config_to_masjid_app_publishing_table.php:34, :37: `onesignal_app_id` string, `onesignal_rest_api_key` text.
- 2026_07_23_150001: `development_team` string (:27).
- 2026_07_23_160000: `enabled_platforms` json (:30), with existing rows backfilled to `["ios","android","web"]` (:35-37).

[F11] `MasjidAppPublishing` model:
- Casts: `enabled_platforms` array; `asc_key_p8`, `asc_key_id`, `asc_issuer_id`, `play_service_account_json`, `onesignal_rest_api_key` encrypted: app/Models/MasjidAppPublishing.php:58-65.
- The same five are `$hidden`: :72-78.
- Appends `has_asc_key`, `has_play_service_account`, `has_onesignal_key`, each a presence check on the raw column: :84-88, :112-130.
- `onesignal_app_id` and `development_team` are plain and visible: :18-20, :40-44.
- `platformEnabled()` treats a null `enabled_platforms` as ios/android/web: :101-109.
- `hasOwnOnesignalApp()` needs both the app id and the raw key: :138-142.
- Relation: `Masjid::appPublishing()` is a hasOne, app/Models/Masjid.php:916-917.

[F12] `OneSignalProvisioningService::provisionApp(masjid, bundleId, overrides)`:
- Needs `services.onesignal.user_auth_key` (env `ONESIGNAL_USER_AUTH_KEY`), otherwise returns a soft error: app/Services/OneSignalProvisioningService.php:53-61. Needs a bundle id: :63-68.
- Sends `POST` to `services.onesignal.apps_api_url` (default `https://api.onesignal.com/apps`) with `Authorization: Basic <user auth key>`: :71-79.
- Reads `id` and `basic_auth_key` from the response: :90-93.
- Saves both with `updateOrCreate` (the key goes through the encrypted cast): :113-119. Returns `app_id` and `has_onesignal_key`: :121-125.
- The request body always has a name. APNs fields are added only when p8, key id and team id are all set; FCM v1 JSON only when set: :134-160.
- Config: config/services.php:286-308 (`ONESIGNAL_APNS_P8`, `_KEY_ID`, `_TEAM_ID`, `_ENV` default `production`, `ONESIGNAL_FCM_V1_SERVICE_ACCOUNT_JSON`).

[F13] Provisioning is not idempotent. A second call overwrites the stored app id and key (`updateOrCreate`, :113-119) without checking for an existing app.

[F14] `MasjidOneSignalController`:
- `GET` returns `{masjid_id, onesignal_app_id, has_onesignal_key}`: app/Http/Controllers/AdminDashboard/MasjidOneSignalController.php:33-47.
- `POST provision` answers 422 on a service failure and 201 on success: :59-91.
- Routes: `{masjid_id}/onesignal` GET and `/provision` POST, behind `super`: routes/admin.php:446-449.
- `ProvisionOnesignalAppRequest` requires `bundle_id`, `name` is optional (:22-23), and it extends BaseFormRequest (:17).

[F15] D9 is not wired to anything. A grep for `OneSignalProvisioningService|onesignal/provision` across app/, resources/vue-app/ and tests/ finds only the service and its controller: no SPA caller and no test.

[F16] Live-use evidence:
- No mention of OneSignal app provisioning or of a `provision-apps` run in DECISIONS.md, STATE.md, or the gitignored LOG.md, NOTES.md and PLAN.md in the main checkout.
- The spec's landmine 1: docs/manara-studio.md:72-75. D9: :132-133.
- docs/manara-studio-w1.md:1912-1914: "Studio does not call `provision-apps`… D9 must land before the first Studio-generated app."
- LOG.md:919 (main checkout): the stale droplet's `.env` held live ONESIGNAL and GITHUB keys, which were blanked on 2026-09-10.

[F17] `OnesignalService`:
- Shared config comes from `onesignal.api_url`, `app_id`, `app_rest_api_key`: app/Services/OnesignalService.php:35-39; config/onesignal.php:4-8.
- `isConfigured()` requires all three shared values: :53-56. Every send checks it first (:165, :211, :285, :362).
- `resolveConfig(masjid)` returns the org's own app id and key when `hasOwnOnesignalApp()`, otherwise the shared ones: :111-124.
- `notifyAll`: with an org's own app it targets `included_segments: Active Subscriptions`; on the shared app it uses a `masjid_id` tag filter: :170-189.
- `notifyAllOfMasjid` (:209-220), `sendDataSync` (:274-296) and `sendPrayerAlert` (:352-370) all go through `resolveConfig`.
- `getNotificationDetails` always uses the shared app: :436-441.

[F18] Every sender and which OneSignal app it uses:
- `SendMasjidNotificationJob` → `notifyAllOfMasjid($masjid)`, so per-org: app/Jobs/SendMasjidNotificationJob.php:89. Dispatched from AdminDashboard/NotificationsController.php:40, app/Services/Broadcast/Channels/PushChannel.php:94 and SeedSummerPrograms2026.php:175.
- `SendPrayerSyncJob` → `sendDataSync(…, Masjid::find(id))`, per-org: app/Jobs/SendPrayerSyncJob.php:52-58. Dispatched from IqamaTimeSettingsController.php:90 and DailyPrayerResync.php:58.
- `prayers:send-due` → `sendPrayerAlert(…, $masjid)`, per-org: SendDuePrayerNotifications.php:190-198. TestPrayerPush.php:45-51 also passes `$masjid`.
- `GroupPushChannel` sends nothing; it logs "email-only" at info: app/Services/Groups/GroupPushChannel.php:61-67.
- `OnesignalInAppMessageService` (splash in-app messages) uses only the shared `app_id` plus `user_auth_key` (falling back to the REST key), with a `masjid_id` tag trigger: :52-60, :96-101. It is never per-org.
- `ModuleFacts` repeats the `resolveConfig` logic for admin fact text: app/Support/ModuleFacts.php:145-155.

[F19] `mobile_app_users` stores `onesignal_subscription_id` but not which OneSignal app it belongs to: 2026_06_23_020000_add_onesignal_subscription_id_to_mobile_app_users_table.php:20. It is written by MobileAppUsersController.php:129-145.

[F20] No mobile or public endpoint returns the org's `onesignal_app_id`. Nothing in routes/api.php, api_v1.php, family.php, app/Http/Controllers/Mobile/ or app/Support/AppMenu.php reads it. OnboardingController's `app_publishing` response leaves it out: :175-182.

[F21] Studio today:
- `StudioDraft::toProvisionPayload` sends `account_mode` for selected platforms only, and adds BYO secrets from the Step 3 request body only when the mode is `byo`: app/Models/StudioDraft.php:284-303, `SECRET_PLATFORM` :49-54.
- `StudioProvisionRequest`: `secrets.*` rules at :33-39, `secrets()` at :51-60.
- `StudioProvisioning` validates through `ProvisionMasjidRequest` and calls `OrganisationProvisioner::create`: StudioProvisioning.php:73-79, :111.
- OrganisationProvisioner.php:286-288 does read `$apps['ios']['account_mode'] ?? 'managed'`, and the same for android and web (verified). It writes `enabled_platforms` and the modes, stores BYO credentials only for a platform that is both selected and `byo` (:297-306), then `MasjidAppPublishing::create` (:307).
- Nothing dispatches builds. `StudioProvisionController` only calls `provision()` (:54). Nothing in app/Support/Studio or `StudioProvisionController` references `GithubDispatchService` or `AppProvisioningController`.

[F22] `ProvisionMasjidRequest`:
- `platforms`: required, array, min 1, values in [`ios`,`android`,`tvos`,`web`]: :233-234.
- `apps.{ios,android,web}.account_mode`: nullable, in [`managed`,`byo`]: :240-242.
- BYO credentials are `required_if` the mode is `byo`: :245-250.
- Choosing `tvos` without `ios` adds an error on `platforms`: :346-351.

[F23] Studio SPA:
- `PlatformsPanel.vue` note: "Store credentials are entered at Generate and never saved in the draft" (:2). tvOS requires iOS (:7-14, :87-90). Each of iOS/Android/Web gets an account-mode pill that defaults to Managed when selected (:17-31, :82-83).
- `StepGenerate.vue` hint lists "settings, features, brand, logo and icons, website pages and web address", with no apps (:5-8). It shows a store-credentials panel for BYO platforms (:51-53).
- `ReviewGrid.vue` lists platforms with their mode; tvOS shows no mode (:135-142).
- `ProvisionResults.vue` has no app-build output.

[F24] The legacy wizard and "Generate Apps":
- `OnboardingWizardView.vue` collects platforms (:334-349, :570-573; default ios/android/web at :647-649) and account modes plus BYO secrets (:353-420, :651-653). It posts to `/api/admin/onboarding/provision` (:1051) and never triggers app generation.
- "Generate Apps" is on `MasjidDetailsView.vue:472-526`, with iOS and Android checkboxes only. It posts multipart `platforms[]` to `provision-apps` (:1402-1433) and polls `provisioning-jobs` every 4 s (:1369-1392). Its TS type allows `platform: 'ios'|'android'` (:606).

[F25] Stored BYO credentials are never read:
- A grep of app/ for `asc_key_p8|play_service_account_json` finds only writers (StudioDraft, OrganisationProvisioner), request rules and the model.
- The dispatch payload carries none of them (F6).
- The only provisioning route is the callback, so there is no endpoint a runner could fetch them from (routes/api.php:357-360).

[F26] Staging:
- config/staging_scrub.php drops all `provisioning_jobs` rows (`drop_rows`, :152).
- It NULLs the encrypted `masjid_app_publishing` columns `asc_key_p8`, `asc_key_id`, `asc_issuer_id`, `play_service_account_json`, `onesignal_rest_api_key` (:184-189).
- It NULLs `onesignal_app_id` (:327-328) and `mobile_app_users.onesignal_subscription_id` (:241).
- deploy/staging/provision.sh blanks every key with prefix `ONESIGNAL_` or `GITHUB_` (`DENY_PREFIXES`, :382) and checks for leftover `GITHUB_DISPATCH_TOKEN`/`ONESIGNAL_REST_API_KEY` values (:478).
- deploy/staging/env.staging.example leaves `ONESIGNAL_APP_ID`, `REST_API_KEY`, `USER_AUTH_KEY`, `REST_API_URL` blank (:131-134) and `GITHUB_DISPATCH_TOKEN` blank (:150-153). `ONESIGNAL_APNS_*` and `FCM_V1` are not listed there but the prefix rule covers them.

[F27] Tests:
- tests/Feature/AppProvisioningTest.php:
  - `a_superadmin_dispatches_both_platforms_and_jobs_flip_to_dispatched` :89. Payload assertions at :117-133: event type, bearer, `masjid_id`, `development_team`, `bundle_id`, `include_tvos === false`, `job_id`, `callback_token`, `callback_url`.
  - `a_failed_dispatch_marks_the_job_failed_with_detail` :138
  - `a_masjid_admin_cannot_dispatch` :157
  - `provision_requires_at_least_one_valid_platform` :173
  - `the_jobs_index_never_leaks_the_callback_token` :190
  - `a_valid_callback_token_advances_the_job` :222
  - `a_valid_callback_can_attach_an_artifact_url_on_success` :242
  - `a_wrong_callback_token_is_rejected_and_the_job_is_unchanged` :260
  - `a_missing_callback_token_is_rejected` :275
  - `an_unknown_job_id_is_an_opaque_404` :288
  - `a_valid_token_with_an_invalid_status_is_422` :299
- `HostHeaderUrlIntegrityTest::the_provisioning_callback_handed_to_the_runner_is_on_the_configured_host` :567.
- Studio:
  - `StudioGenerateWireContractTest::the_credentials_the_spa_sends_are_the_ones_the_request_reads_and_the_draft_never_keeps_them` :82
  - `StudioGenerateWireContractTest::a_bring_your_own_mode_left_on_an_unselected_platform_asks_for_nothing` :136
  - `ProvisionWizardGuaranteesTest::store_credentials_are_kept_only_for_a_platform_that_was_selected` :138
  - `StudioProvisionParityTest` (lists `masjid_app_publishing`, :32)
  - `ProvisionResponseSnapshotTest` plus tests/fixtures/provision-snapshot/*.json
- `StagingScrubTest::encrypted_columns_are_nulled_never_rewritten` :304 and `drop_rows_tables_are_emptied` :324.
- tests/Unit/OnesignalUnconfiguredTest.php :54-156; `ModuleFactsTest` platform-app fact :37.
- Not tested at all: `OneSignalProvisioningService`, `MasjidOneSignalController`, the org's-own-app branch of `resolveConfig`, `include_tvos=true`, callback ordering. A grep for `onesignal_app_id|hasOwnOnesignalApp` in tests/ hits only StagingScrubTest.

[F28] `artifact_url` is a plain `string` column, 255 characters (migration :62), but the callback accepts up to 2000 (callback :61).

## Answers

**1. ProvisioningJob and the dispatch plane.**
- Columns, statuses and token handling: F1, F2.
- Accepted platforms are `ios` and `android` only (F4). Dispatch is `GithubDispatchService`: `repository_dispatch` with `event_type=scaffold-masjid` to `services.github.ios_repo`/`android_repo`, token `services.github.dispatch_token` (F5, F7). Payload fields: F6.
- The callback is `POST /api/provisioning/callback`, authenticated by the per-job bearer token (F3, F8).
- Transitions: `queued` → `dispatched` or `failed` inside the portal (F5). After that the runner can set any status in `CALLBACK_STATUSES`, in any order (F8).
- tvOS is refused with a 422 by `ProvisionAppsRequest` (F4), is not in the DB enum (F1), and is refused by the callback (F8). It exists only as the `include_tvos` flag on the iOS job (F6).

**2. `masjid_app_publishing`.** Columns: F10. Casts, `$hidden`, encryption and appended flags: F11. Five secrets are encrypted and hidden. `onesignal_app_id` and `development_team` are plain.

**3. D9 state.**
- Yes, it creates a OneSignal app through `POST https://api.onesignal.com/apps` using `ONESIGNAL_USER_AUTH_KEY`, and stores the app id and encrypted REST key (F12).
- Routes are SuperAdmin-only at admin.php:446-449 (F14).
- There are no tests and it is not wired to Studio or the legacy wizard (F15). Nothing records a live use (F16).
- It is not idempotent (F13).

**4. Sending.**
- Admin pushes, broadcasts, prayer alerts and the prayer data sync use the org's own app when it has both an app id and a key, otherwise the shared env app (F17, F18).
- Splash in-app messages and message-detail lookups always use the shared app (F17, F18). Group push sends nothing (F18).
- An org with no app of its own goes through the shared app: a `masjid_id` tag filter for `notifyAll`, or subscription ids taken from the org's own devices. If the shared config is blank, every send returns a "not sent" result, even for an org with its own app, because the shared `isConfigured()` check runs first (F17).

**5. Is the OneSignal app id served to the apps?** No (F20). The only way it reaches an app is the Android dispatch payload at build time (F6, F9). The iOS payload never carries it (F6).

**6. Studio.**
- `OrganisationProvisioner` does use `?? 'managed'` for all three platforms (F21). It stores platforms and modes and dispatches nothing (F21).
- Step 3 shows modes, BYO credential fields and a review of platforms, but offers no app generation and reports none (F23).
- The legacy wizard also only stores these settings. App generation is a separate SuperAdmin button on `MasjidDetailsView`, iOS and Android only (F24).

**7. How store credentials reach the runner.** They don't. They are neither in the dispatch payload nor fetchable by the runner (F25). Each runner uses its own repo's GitHub secrets, which are Hope Tech's (F9). Stored BYO credentials are dead data today. `account_mode` is sent, but only as a label (F6).

**8. Staging.** See F26: all provisioning job rows are dropped, the publishing secrets and the app id are NULLed, device subscription ids are NULLed, and the `ONESIGNAL_`/`GITHUB_` env prefixes are blanked.

**9. Tests.** See F27, including the gaps listed there.

## Risks to live clients

[R1] **Giving a live org its own OneSignal app cuts off its pushes straight away.** Applies to Burlington 1, MEC 13, Al-Razi 14, BISS 18. Once both the app id and key are stored, `resolveConfig` sends through the new app (F17). But every stored subscription id was issued by the shared app, and nothing records that (F19). The shipped apps still have the shared id built in (docs/manara-studio.md:72-75). Subscription-id sends and prayer alerts would target ids the new app does not know, and `Active Subscriptions` on an empty new app reaches nobody. Splash in-app messages would stay on the shared app (F18), which splits the org across two apps. The endpoint can be called today by any SuperAdmin through the API and has no guard (F14). W2 should create the app separately from switching sends to it, and switch only after the new app builds are adopted.

[R2] A second provision call replaces the stored key and leaves the earlier OneSignal app orphaned (F13).

[R3] "Generate Apps" on a live org's `MasjidDetailsView` derives `bundle_id = com.hopetechapps.<slug>` (F6). That may not be the live app's real bundle id. It then dispatches to the shared repos, and the iOS runner holds App Store Connect upload secrets (F9). What the scaffolder does when a target already exists is for agent A to answer.

[R4] If the shared `ONESIGNAL_*` values are ever removed after orgs move to their own apps, every send stops, because the shared `isConfigured()` check runs first (F17). `ModuleFacts` would still claim prayer pushes go through the org's own app (F18).

[R5] BYO orgs: their credentials are collected and stored, but a build would be signed and uploaded under Hope Tech's account (F9, F25).

[R6] A TestFlight or Play artifact URL longer than 255 characters passes validation but fails the MySQL write, so the callback returns 500 (F28). The iOS runner carries on after a failed callback (F9), so the job would sit at its last status.

[R7] `include_tvos` sent form-encoded as "true" or "false" is rejected with a 422 by the `boolean` rule (F4; .claude/rules/shipping.md). The current SPA never sends it (F24), but a W2 caller might.

[R8] A late or replayed callback can move a job from `built` back to `building` (F8). The damage is limited to that one job, because its token is per job.

## Unknowns

[U1] Whether production has any `masjid_app_publishing` rows with `onesignal_app_id` or a key set, or any `provisioning_jobs` rows. Needs investigation: an owner-approved read-only SELECT on production.

[U2] Whether `GITHUB_DISPATCH_TOKEN`, `ONESIGNAL_USER_AUTH_KEY`, `ONESIGNAL_APNS_*` and `ONESIGNAL_FCM_V1_*` are set in production's `.env` on droplet 586894889. Needs investigation: a presence-only check such as `grep -c '^KEY=.\+'` there.

[U3] Whether OneSignal's current create-app response still returns `basic_auth_key`, which the service depends on (F12, :92-93). Needs investigation: OneSignal API docs, or one sandbox-account call with the owner's approval.

[U4] Whether a self-hosted runner is registered and online for either repo, and whether `provision-apps` has ever run end to end. Needs investigation: read-only `gh api repos/…/actions/runners` and `…/actions/runs?event=repository_dispatch`.

[U5] How the apps would read an org's own OneSignal id at runtime, since no endpoint serves it (F20). This is a W2 design decision and depends on agents A and C.
