# Vendor and GitHub facts read for the W2/W3 plans

> Read-only recon for the Studio W2/W3 plans, 2026-09-24. MasjidWebMS `c3fc0324`, iOS `origin/main` `8e5191f`, Android `origin/master` `cf61d54`, renderer `origin/main` `6a7ead2`.
> Line numbers are as of those commits; re-read before relying on one. This repo is PUBLIC: identifier-shaped values (OneSignal ids, Apple team ids, key ids) are redacted as `[redacted]`.

# External facts (public vendor docs, read 2026-09-24)

W1. Cloudflare Pages limits (developers.cloudflare.com/pages/platform/limits/, "Last updated Sep 5, 2026"):
   custom domains per PROJECT: Free 100, Pro 250, Business 500, Enterprise 500 (more via account team).
   Pages PROJECTS per account: 100, "not routinely increased"; beyond that: Workers for Platforms / Workers Static Assets
   (Workers: 100 on Free, 500 on paid). Builds: Free 500/month, 1 concurrent. Files per site: Free 20,000.
   -> relevant to W3 standalone export if each export is a Pages project in Hope Tech's account.

W2. App Store Connect API, Apps resource (developer.apple.com/documentation/appstoreconnectapi/apps):
   only GET /v1/apps, GET /v1/apps/{id}, PATCH /v1/apps/{id}. No create. Doc text: "Don't use this API to create new
   apps; instead, create new apps on the App Store Connect website." POST returns 403 "does not allow 'CREATE'"
   (github.com/andrewralon/app-template/issues/2). fastlane produce creates apps via Apple-ID session (2FA), not API key.
   -> D10 "create the ASC record" cannot be done with the ASC .p8 key. Options: Apple-ID session automation (fastlane
   produce/spaceship; a password + 2FA = prohibited for an agent and fragile), or a human step Studio tracks.

W3. Google Play: the Publishing API "edits" do not create new apps (developers.google.com/android-publisher; Play Console
   Help "Create and set up your app"). The Custom App Publishing API (developers.google.com/android/work/play/custom-app-api)
   creates only PRIVATE managed-Play apps: "Apps published through this API are permanently private, meaning they can't be
   made public." -> D10 "create the Play listing" cannot be automated for a public app. Human step in Play Console.
   Uploading a build (edits.bundles.upload + track) to an EXISTING app IS automatable with a service account.

W4. OneSignal (documentation.onesignal.com/reference/create-an-app): POST https://api.onesignal.com/apps,
   header `Authorization: Key <ORGANIZATION API KEY>`, body name (≤128), organization_id; iOS p8: apns_key_id,
   apns_team_id, apns_bundle_id, apns_p8 (base64), apns_env; Android: fcm_v1_service_account_json (base64).
   Response: id (app id, UUID), name, organization_id, timestamps — NO REST key.
   REST key: POST /apps/{app_id}/auth/tokens (Organization key), body name; response `formatted_token` "returned only
   on create and rotate, and does not store it" (documentation.onesignal.com/reference/create-api-key).

W5. Cloudflare redirects (developers.cloudflare.com/rules/url-forwarding/, updated Aug 14 2026; .../single-redirects/create-api/, Aug 25 2026):
   Single/Bulk Redirects REQUIRE the source hostname's DNS record to be proxied. Rules live in the zone entry-point ruleset of phase
   http_request_dynamic_redirect. Create the entrypoint if absent; if present, "Update a zone ruleset" (PUT, replaces rules) OR
   "Create a zone ruleset rule" POST /zones/{zone}/rulesets/{ruleset_id}/rules (APPENDS, keeps existing). Token permission needed:
   "Dynamic URL Redirects Write" (not in the Studio token's three scopes).
W6. Pages custom-domain delete: not found by docs search; the API reference's Pages > Projects > Domains lists a delete method
   (DELETE /accounts/{a}/pages/projects/{p}/domains/{name}) -- VERIFY at build time, encode in fixtures (as W1 OQ3 did).

# gh / GitHub facts (read-only, 2026-09-24)
G1. hope-tech-apps org plan = free (private_repos 10000, members can create private). -> Actions on private repos: included minutes
    only (macOS minutes cost 10x) -- Unknown exact quota; hosted macOS builds per client repo are metered.
G2. iOS provision-ios-app.yml: 5 runs, all 2026-07-23/24, all failure/cancelled; latest = MEC scaffold, archive failed compiling
    FlagPhoneNumber (a package no longer in Package.resolved). Callback reached production ({"status":"success"}). Never green.
    Secrets present: ASC_ISSUER_ID, ASC_KEY_ID, ASC_KEY_P8.
G3. Android provision-android-app.yml: 0 runs; `gh secret list` returned no secrets (PLAY_SERVICE_ACCOUNT_JSON absent, or not listable).
G4. MasjidWebMS repo visibility = PUBLIC (gh repo view). Plans must carry no secret-shaped values.
G5. MasjidWebMS composer.json:16 laravel/framework ^12.0 (CLAUDE.md says 11 -- stale). 24 migrations already use ->change().
G6. Local-only branches: MasjidWebMS feat/studio-s9 (26053319), renderer feat/studio-s10-lookup (d943cbd). Not pushed.
W7. GitHub Free: organization-level Actions secrets/variables are NOT accessible to PRIVATE repos (docs.github.com
    "Using secrets in GitHub Actions"; community discussion #33712). Repo-level secrets work.
W8. fastlane supply setup (docs.fastlane.tools/actions/supply/, /getting-started/android/setup/): the app must exist and at least
    one build must have been uploaded manually through Play Console before the API can upload.
