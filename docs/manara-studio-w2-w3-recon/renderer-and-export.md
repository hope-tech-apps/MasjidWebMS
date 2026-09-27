# Recon: renderer-and-export

> Read-only recon for the Studio W2/W3 plans, 2026-09-24. MasjidWebMS `c3fc0324`, iOS `origin/main` `8e5191f`, Android `origin/master` `cf61d54`, renderer `origin/main` `6a7ead2`.
> Line numbers are as of those commits; re-read before relying on one. This repo is PUBLIC: identifier-shaped values (OneSignal ids, Apple team ids, key ids) are redacted as `[redacted]`.

## Facts

**Renderer tenancy on origin/main 6a7ead2**

- [F1] Tenancy is a host→id map held in `runtimeConfig.tenantHosts`. It is `process.env.TENANT_HOSTS` or `DEFAULT_TENANT_HOSTS`, and can be overridden as `NUXT_TENANT_HOSTS` (renderer:nuxt.config.ts:20-58, :127-136).
  - `server/utils/tenant.ts` parses it once and memoises up to 512 hosts, trusting `x-forwarded-host` (renderer:server/utils/tenant.ts:30-53, :78-79).
  - The Nitro middleware stores the tenant, sets `x-manara-tenant`, and applies per-tenant path redirects (renderer:server/middleware/tenant.ts:37-50).
  - An unresolved host gets a 404 page (renderer:app/app.vue:15-27).
  - `/api/tenant` returns `{tenant, locale, dir}` with no-store (renderer:server/api/tenant.get.ts:15-32).
- [F2] S10's runtime lookup is **not** on origin/main or on any remote branch. No remote branch contains `shared/tenantLookup.ts` (checked with `git ls-tree` on every `origin/*`). It lives only on the **local** branch `feat/studio-s10-lookup`: 7 commits on top of 6a7ead2, head d943cbd dated 2026-09-24 20:44, 27 files, +2923/−84.
- [F3] On that branch, `tenantRecordFromLookup` keeps `name`, `description`, `favicon_url` and `share_image_url`, and drops every other key, **`locale` included**. As a result a lookup-resolved tenant renders en/ltr (renderer@feat/studio-s10-lookup:shared/tenant.ts:568-600). This is W1 R15 / "contract A" (docs/manara-studio-w1.md:105).
- [F4] The backend lookup exists: `GET /api/v1/organizations/by-host`, throttled `tenant-host` at 600/min per IP (routes/api_v1.php:29-30; app/Providers/AppServiceProvider.php:236-237).
  - Its payload is `host, masjid_id, name, description, favicon_url, share_image_url`, with no locale field (app/Http/Controllers/Api/V1/OrganizationByHostController.php:60-67).
  - No migration adds an org-level `locale`. The only match is `masjids.mailing_locale`, which is an address line (database/migrations/2026_07_22_110000_add_tax_fields_to_masjids.php:22).
- [F5] **Locale support in the renderer already exists.**
  - The locale set is closed: `['en','ar']`, and `ar` is RTL (renderer:shared/tenant.ts:41, :55).
  - Each map entry has an optional `locale`, normalised with a warning (renderer:shared/tenant.ts:172, :283-289).
  - `<html lang/dir>` is set in SSR by `useTenantHead` (renderer:app/composables/useTenantHead.ts:79).
  - `Accept-Language` is sent from the tenant locale (renderer:app/composables/useApi.ts:170).
  - vue-i18n is pointed at the tenant locale by a plugin (renderer:app/plugins/i18n-tenant-locale.ts:37-60).
  - @nuxtjs/i18n runs with `no_prefix` and `detectBrowserLanguage: false` (renderer:nuxt.config.ts:218-226), and CI enforces both (renderer:.github/workflows/build.yml:117-139).
  - `ar.json` is populated (510 lines, with a `_review` block) (renderer:i18n/i18n.config.ts:4-7).
  - Two gaps remain open: there is no Arabic webfont, and the `_review` keys are unchecked (renderer:docs/multi-tenancy.md:406-414).
- [F6] No backend app code reads `Accept-Language`: grep of `app/`, `bootstrap/`, `routes/`, `config/`, `database/` and `tests/` returns 0 hits. The renderer's `.env.example` comment says "the backend reads it", which is unverified.
- [F7] Production `NUXT_TENANT_HOSTS` on `manara-renderer` has six hosts, no wildcards and **no locale fields** (docs/manara-studio-w1.md:2003). This contradicts renderer:tests/tenant-unchanged.test.ts:27-28, which says "TENANT_HOSTS is deliberately UNSET" (stale).

**Single-tenant branch (`cloudflare-migration`)**

- [F8] The `mec-web` Pages project serves `origin/cloudflare-migration` (af71ceb). It sets no `NUXT_TENANT_HOSTS` and runs on the map in git (docs/manara-studio-w1.md:2003-2004; docs/tenant-host-map.md:98).
  - The branch is 30 commits ahead of main and 41 behind. Its merge-base is 9d282db (2026-09-13). The diff is 94 files, +10388/−1553.
  - Its code is the same multi-tenant resolver with the same built-in map, including Burlington's hosts (renderer@origin/cloudflare-migration:nuxt.config.ts:20-58, :136).
  - It is **single-tenant only operationally**: the project has no env map, and the hosts attached to it are MEC's. The exact attached-host list is U8.
  - It also lacks main's live-preview, purge and registration code (41 commits).

**Hand-built standalone sites**

- [F9] mec-web, intellicor-web and mas-youth-web are copies of al-razi-school-web.
  - Each has `package.json` `"name": "al-razi-school-web"` (e.g. mec-web@origin/master:package.json:2), and a CLAUDE.md headed "Al-Razi School Web".
  - Each has 2-3 commits, starting with "Initial commit: back up CLI-deployed Vercel project to git".
  - They are SvelteKit 2 / Svelte 5 apps whose adapter is switched by `DEPLOY_TARGET` (cloudflare, otherwise adapter-auto for Vercel) (mec-web@origin/master:svelte.config.js:3-13).
  - They hold static content, and their `.env.example` lists only `SUPABASE_URL` and `SUPABASE_SERVICE_ROLE_KEY`.
  - They make **no Manara API calls**: grep for hopetechapps, masjid-id, /api/v1 or /api/mobile in `src` returns 0 files in each repo.
  - None was derived from the renderer.
- [F10] al-razi-school-web is also SvelteKit, but pinned to adapter-cloudflare (al-razi-school-web@origin/main:svelte.config.js:1). It deploys on push to main with `wrangler pages deploy … --project-name=al-razi-school-web` because the Pages project is direct-upload (.github/workflows/deploy.yml:3-4, :46-50).
  - It uses Supabase and Stripe env keys, with Stripe on a connected account under the Manara platform (src/lib/server/stripe.ts:12-19, :40).
  - Its only Manara link is a `/portal` 307 to the portal host (src/routes/portal/+server.ts:32).

**Export inputs and API dependence**

- [F11] Runtime and env inputs of the renderer:
  - `tenantHosts` (renderer:nuxt.config.ts:136).
  - `manaraSharedSecret`, `previewHosts`, `previewAdminOrigins`, all empty meaning off (:146-148).
  - `public.apiBaseUrl` from `API_BASE_URL` (:151).
  - Build-time: `ISR_SECONDS` (:71), `DEPLOY_TARGET` (:78), and the `PAGE_CACHE_BUILD_ID`, `CF_PAGES_COMMIT_SHA` or git HEAD build id (renderer:shared/pageCacheBuildId.ts:86-90).
  - KV binding `MANARA_PAGE_CACHE` (renderer:nuxt.config.ts:276-285).
  - Hardcoded Manara references: the API fallback `https://masjid.hopetechapps.com/api/v1/` (renderer:app/composables/useApi.ts:43, :200; useEvents.ts:79; useSplashAnnouncement.ts:51; components/section/Donate.vue:12), and `/jummah-lunch/**` redirecting to masjid.hopetechapps.com (renderer:nuxt.config.ts:317).
- [F12] Every page reads the API at request time: `/home`, `/settings` and `/pages` (renderer:app/stores/app.ts:374-411), plus `/api/mobile/masjids/{id}/events` and `/donation-link` (renderer:app/composables/useEvents.ts:88; components/section/Donate.vue:18-19).
  - Prerendering is forbidden by policy (renderer:nuxt.config.ts:251-260) and by a CI guard (renderer:.github/workflows/build.yml:45-64).
- [F13] MEC-specific code is keyed by tenant id `'13'` in the shared renderer (renderer:shared/tenantBranding.ts:55; tenantHeader.ts:37; tenantEvents.ts:26; tenantRedirects.ts:71; tenantRedirectIndex.ts:9; shared/tenant-sites/mec-*.ts).
- [F14] `.env` and `.wrangler/state` (local miniflare KV blobs) are tracked on renderer origin/main (`git ls-tree origin/main`). Their values were not read.

**Source download**

- [F15] MasjidWebMS has no repo, source or zip export.
  - The only archive is the backup's `media.tar.gz` (app/Support/Backup/BackupSet.php:43).
  - Records export is deliberately not a ZIP (app/Http/Controllers/AdminDashboard/SchoolRecordsExportController.php:110).
  - GitHub use is limited to `repository_dispatch` (app/Services/GithubDispatchService.php:14, :59). No repo-generate call exists.

**GitHub org**

- [F16] `gh repo list hope-tech-apps` (output saved to `$SP/g-repos.json`, the only file this recon wrote, in scratchpad) returns:
  - **33 repos**: 29 private, 4 **public** (`MasjidWebMS`, `RG10-iOS`, `MasjidSystem`, `MasjidAppFlutter`), 0 archived, **0 templates**.
  - `manara-*` repos: `manara-dns-migration` and `manara-marketing` only.
  - Naming patterns: `<client>-web` (al-razi-school-web, intellicor-web, mas-youth-web, mec-web) and `burlington-masjid-{iOS,Android,site,website,resources}`.
  - No `manara-<client>-ios` repos exist.
  - The renderer repo is `burlington-masjid-site`; no `manara-renderer` repo exists.

**Renderer deploy and gates**

- [F17] There is no `wrangler.toml` or `wrangler.jsonc` in the repo. The KV binding is described as "declared for the Pages project" (renderer:nuxt.config.ts:273-275).
  - Both Pages projects are direct uploads that **never auto-deploy**, deployed by hand with `wrangler pages deploy` (docs/manara-studio-w1.md:2004).
  - The live-preview release is recorded as deployed from 6a7ead2 (memory live-preview-effort.md:12 — a lead, not verified via Pages).
  - A staging project `manara-renderer-staging` points at the staging API (docs/tenant-host-map.md:99).
- [F18] CI on push to main and on PRs (renderer:.github/workflows/build.yml:11-15):
  - `npm test` runs before `npm ci` (:30-37).
  - `npm run build` runs **without `DEPLOY_TARGET=cloudflare`** (:39-43), so CI never compiles the production preset.
  - Grep guards cover no-prerender (:45-64), cache `varies` including host (:66-115), and i18n settings (:117-139).
  - canary.yml runs twice a day against Burlington only, comparing Fajr iqama with the API (renderer:.github/workflows/canary.yml:17-34).
- [F19] Tests:
  - `npm test` is `node --experimental-strip-types --test tests/*.test.ts` (renderer:package.json:11). There are 35 `.test.ts` files plus 3 helpers. `test:integration` runs the workerd live-preview script (:12).
  - tests/tenant-unchanged.test.ts pins today's production map copy, Burlington's head and its API headers byte for byte (tests at :45, :65, :91, :105, :120, :130, :155, :164, :179).
  - RTL and locale are covered by tests/rtl.test.ts, tenant-locale.test.ts, i18n-catalogue.test.ts and i18n-no-literals.test.ts.

**apex→www**

- [F20] burlingtonmasjid.com answers a 307 to www (docs/tenant-host-map.md:94). No renderer code does this:
  - No `public/_redirects` or `_headers` file exists (`ls-tree origin/main public`).
  - Grep for www/apex in server/, shared/, middleware and plugins finds only map entries and a comment.
  - The map resolves **both** apex and www to 1 (renderer:nuxt.config.ts:21-22).
  - Per-tenant redirects are path-only and keyed by id (renderer:shared/tenantRedirects.ts:12-21).
  - W1 recorded "No apex↔www canonical redirect policy" as W2 work (docs/manara-studio-w1.md:1942).

**Stale renderer docs**

- [F21] These describe the renderer as Vercel with auto-deploy, which no longer holds:
  - renderer:CLAUDE.md:11, 27
  - STATE.md:7, 30-32 ("Tests: none")
  - docs/multi-tenancy.md:13-22 (Vercel domain steps)
  - docs/tenant-migration-runbook.md (the whole file dates from 2026-08-12/17)
  - shared/tenant.ts:17-24 and server/utils/tenant.ts:25-27 ("no host→org endpoint")
  - nuxt.config.ts:215-217 ("catalogues intentionally EMPTY")

## Answers

**1. Tenancy and locale.**
- Tenancy is a static host map from env or code, resolved in Nitro middleware [F1]. S10's lookup is not on origin/main; it exists only on a local branch and drops `locale` [F2][F3].
- ar/RTL is fully plumbed: a closed locale set, `lang` and `dir` in SSR, `Accept-Language`, and populated catalogues [F5]. The locale comes **only** from the host-map entry's `locale` field [F5]. No live tenant sets one [F7].
- W2's website-locale field therefore needs three things:
  - (a) a backend column or source, since none exists today [F4];
  - (b) a `locale` key in the by-host payload [F4];
  - (c) a renderer change to `tenantRecordFromLookup` so it keeps a normalised `locale` [F3]. (b) alone is ignored by the renderer.
- Alternatively, an Arabic-first client can go in the static map via `NUXT_TENANT_HOSTS`, which means a redeploy [F7] (docs/manara-studio-w1.md:1932-1934).

**2. `cloudflare-migration`.** It carries MEC's header, branding, redirects, events calendar, service page and photo work: 30 commits, most of them already ported to main under different SHAs. It is behind main's preview, purge and registration work [F8]. Nothing in the code makes it single-tenant. It is the `mec-web` Pages project running on the git map with no env override, with only MEC's hosts attached [F8].

**3. Standalone sites** [F9][F10]:
- **mec-web**: SvelteKit static brochure for MEC, forked from the Al-Razi template, with no Manara API. Built for Vercel or Cloudflare. The `mec-web.pages.dev` hostname now serves the **Nuxt renderer**, not this repo (renderer:nuxt.config.ts:35-37).
- **intellicor-web**: the same template, a static IntelliCor Academy site with Supabase-backed contact. No Manara API. Where it is deployed is unknown (U2).
- **mas-youth-web**: the same template, static MAS Youth Charlotte with Supabase for contact and get-involved. No Manara API. Deployment unknown (U2).
- **al-razi-school-web**: SvelteKit with its own Supabase and Stripe back end: registration, careers, admin, shirts. Deploys to Pages `al-razi-school-web` through a GitHub Action. It only links to the Manara portal. Not derived from the renderer.

**4. D2 export.** The natural base is the renderer at a pinned commit, with `NUXT_TENANT_HOSTS` holding one tenant's hosts, `API_BASE_URL`, `DEPLOY_TARGET=cloudflare`, `ISR_SECONDS` and a `MANARA_PAGE_CACHE` KV binding [F11].
- It **would still depend on the Manara API at runtime** for every page and for events and donations [F12]. A static snapshot contradicts the no-prerender rule [F12].
- It would also carry Manara-hardcoded fallbacks and the jummah-lunch redirect [F11], plus MEC's id-keyed tables [F13].
- "Not managed by Manara once handed over" (docs/manara-studio.md:95-98) is therefore not achievable without a data layer that does not exist.

**5. Source download.** None exists [F15].

**6. GitHub.** 33 repos, no templates, no `manara-<client>-*` repos. MasjidWebMS is public [F16].

**7. Deploy and gates.** No wrangler config file; manual direct uploads; the CI build skips the Cloudflare preset; guards and tests as listed [F17][F18][F19].

**8. apex→www.** It is not in code. The 307 is observed, but its mechanism is unknown (U1) [F20].

**9. Renderer docs.**
- docs/multi-tenancy.md: host-map resolution, caching and isolation, the planned by-host swap, locale/RTL and to-dos. The Vercel steps are stale [F21].
- docs/tenant-migration-runbook.md: plan from 2026-08 for folding MEC, Al-Razi and Al-Aqsa onto one deploy. Vercel-era.
- docs/live-preview.md: preview-mode and `/__manara/purge` contract, config and evidence.
- .claude/web-recon.md: platform recon (266 lines).

## Risks to live clients

- [R1] Putting `locale` or any new host into `NUXT_TENANT_HOSTS` replaces the whole map. Omitting an entry 404s Burlington (1), MEC (13), Al-Razi (14) or BISS (18), all of which are served by `manara-renderer` (docs/tenant-host-map.md:93-97, :140; renderer:docs/multi-tenancy.md:53-56). Any W2 locale work done through the static map touches every live tenant.
- [R2] A locale change done on the backend alone is silently dropped by the renderer [F3]. Mixed Arabic labels inside English chrome is the failure R15 describes.
- [R3] S10 exists only on one machine's local branch [F2]. W2 renderer work must be built on it, or it has to be pushed first.
- [R4] MEC's live site (mec-web, cloudflare-migration) receives no W2 renderer change unless someone ports it. It already diverges by 41 commits [F8].
- [R5] CI never builds the Cloudflare preset [F18], and deploys are manual uploads [F17]. A W2 renderer change can reach production without a CI-compiled artifact of what ships. The canary covers Burlington only.
- [R6] Removing or changing the unexplained apex→www rule would expose duplicate-content apex serving for Burlington, because the map resolves both hosts [F20].
- [R7] MasjidWebMS is a public repo [F16]. Any W3 "source download" or per-client repo derived from it, and today's docs and host maps, are world-readable. Needs owner review.
- [R8] A renderer-based export ships MEC's id-13 tables, tracked `.env`/`.wrangler` state, and hardcoded Manara hosts [F11][F13][F14].

## Unknowns

- [U1] How burlingtonmasjid.com is redirected to www (a Cloudflare Redirect Rule, Page Rule or Bulk Redirect). Resolve with a read-only Cloudflare rules listing for the zone.
- [U2] Where intellicor-web and mas-youth-web are served, and on which hosts. Resolve with a read-only Vercel and Pages project listing.
- [U3] Whether Laravel changes any output for `Accept-Language: ar`. Check `lang/` and send a staging request.
- [U4] Where the `manara-renderer` KV namespace and compatibility flags are configured. Resolve with a read-only Pages API read.
- [U5] Whether the org's GitHub plan allows private template repos and `POST /repos/{template}/generate`. Check `gh api orgs/hope-tech-apps` (read).
- [U6] Whether 6a7ead2 is what `manara-renderer` currently serves (memory says yes, not re-verified). Resolve with a read-only Pages deployments list.
- [U7] Where a website `locale` should be stored in the backend (a new column vs `theme_settings`). This is a design decision; no source exists [F4].
- [U8] The exact custom-domain list on the `mec-web` Pages project, and whether it has a cloudflare-migration-only KV binding. Resolve with a read-only Pages API read.
