# Manara Studio W1: build plan

**Status:** plan, not started. Written 2026-09-24 for whoever builds W1.
**Contract:** `docs/manara-studio.md`. Decisions D1–D17 are settled; this plan
implements them and does not reopen them.
**Inputs:** the spec, plus six recon reports (draft-and-logo, catalogue,
layouts-and-pages, renderer-lookup, domains-cloudflare, studio-ui). Their
`file:line` evidence is carried here unchanged. Where two reports disagree, §2
records which one this plan follows and why.
**Line numbers** come from the recon. It read MasjidWebMS `main` at `d746534`
and `3204c93`, and the renderer at `origin/main` `d6a00bc`. MasjidWebMS `main`
was at `b13fd61` when this plan was written. Re-read any line before you edit
near it.

**Path prefixes:**

- A bare path is in MasjidWebMS.
- `renderer:` is `~/Developer/burlington-masjid-site`.
- `ios:` is `~/Developer/NewMasjidSystem-r0`.
- `android:` is `~/Developer/burlington-masjid-Android`.

---

## 0. What W1 delivers

W1 is spec §5: Steps 0–2 and a live website.

**Exit criterion.** A SuperAdmin takes a real new client in Manara Studio from
"New client" to a live `https://<slug>.manara.hopetechapps.com`. That site must:

1. resolve through the runtime host lookup, with no renderer deploy;
2. make its browser-side API calls with no `.env` edit;
3. show the client's logo, palette, favicon and fact-only starter pages;
4. have sent its admin the invite.

Throughout, Burlington (1), MEC (13), Al-Razi (14) and BISS (18) stay
byte-identical, with no exception: the CORS hotfix for 13 and 14 landed on
2026-09-24 (§8 OQ6), so S9 ships as an invisible change.

**Without the token.** `CLOUDFLARE_STUDIO_TOKEN` was **not** on the server when
this plan was written; the owner's first attempt did not land, and it may have
landed since. Every slice works in both states. Until it is present, requirement
(1) needs a human to add the custom domain in the Cloudflare dashboard. Studio
shows the exact steps. It never marks a host live that it has not verified.

**Reading whether it landed.** Read it through the app, never by opening `.env`:
S3's `POST /api/admin/studio/domains/check` returns `token_configured`, and S7's
`GET /api/admin/masjids/{id}/domains` returns `cloudflare.configured`. Before S3
ships there is nothing in the code that reads it, so its state does not matter.

---

## 1. Slice map

Each slice ships to production on its own. The four live tenants see no change
at any point, except the owner-approved S9 case in §0. The order puts the
riskier slices later.

| # | Slice | Repo | Risk to live tenants | Hard deps | Size (estimate) |
|---|---|---|---|---|---|
| S1 | Catalogue read | MasjidWebMS | none: super-only GET | — | ~1 session |
| S2 | Drafts, logo, palette | MasjidWebMS | none: new table, super-only | — | ~2 sessions |
| S3 | Host data, public lookup, live-host import | MasjidWebMS | low: two nullable columns on `masjids`; public read endpoint with no consumer | — | ~2 sessions |
| S4 | Layout presets and preview | MasjidWebMS | none: super-only; `TvConfigController`'s constants become public (visibility only, snapshot-pinned) | S2, S3 | ~3 sessions |
| S5 | Studio SPA, Steps 0–2 | MasjidWebMS (SPA) | none: SuperAdmin-only screens | S1–S4 | ~5 sessions |
| S6 | Extract `OrganisationProvisioner` | MasjidWebMS | low: refactor of a super-only path | — | ~1 session |
| S7 | Cloudflare attach, honest without a token | MasjidWebMS | low–medium: scheduler plus outbound calls, which are a no-op without the token | S3 | ~3 sessions |
| S8 | Provision from draft: the web tenant, Step 3, public-settings strip | MasjidWebMS | medium: creates real tenants; the live `/api/v1/pages` serializer changes and `/api/v1/settings` gains keys, output identical for every live org | S1, S2, S4, S6, S7 | ~4 sessions |
| S9 | CORS and payment-return origins from `masjid_domains` | MasjidWebMS | medium–high: middleware that runs on every request | S3 (the CORS hotfix it waited on landed 2026-09-24) | ~1.5 sessions |
| S10 | Renderer runtime lookup, shipped dark | renderer → `manara-renderer` | high: the live renderer; flag off | S3 | ~3 sessions |
| S11 | Switch the lookup on | renderer env | high: the live renderer | S9, S10 | ~0.5 session |
| S12 | Rename and retire the old wizard | MasjidWebMS (SPA) | none: SuperAdmin-only | S8 plus the first client live | ~0.5 session |

- **Total:** about 26.5 builder sessions. This is an estimate, not a measurement.
- **S12 is low risk but comes last.** It removes the fallback provisioning tool,
  so it waits until Studio has provisioned a real client.
- **S3 (low) precedes S4 and S5 (none)** only because both call what it adds:
  `config/cloudflare.php`'s `managed_suffix` and `/studio/domains/check`.
- **The first change to a live read path is S8.** The public-settings strip was
  moved there from S4 so that nothing a live renderer reads changes before the
  slice that needs it.

---

## 2. Contract conflicts resolved

Each row picks one side and gives the reason in a sentence.

| # | Question | Recon positions | Chosen | Why |
|---|---|---|---|---|
| R1 | Name of the host table | `masjid_domains` (domains-cloudflare) vs `domains` (renderer-lookup, spec D17 prose) | **`masjid_domains`** | It follows the house prefix for org-owned tables (`masjid_abouts`, `masjid_app_publishing`), and the facts established 2026-09-24 name it. |
| R2 | Lookup payload and limiter | `{id,name,host,kind,…}` with `host-lookup` 300/min (domains-cloudflare) vs `{host,masjid_id,name,favicon_url,description}` with `tenant-host` 600/min (renderer-lookup) | **renderer-lookup's**, plus `share_image_url` | The renderer refuses any answer whose echoed `host` differs, and every renderer isolate shares Cloudflare's egress IPs, so the per-IP bucket must be generous. |
| R3 | Which rows the lookup resolves, and which origins CORS admits | Not `failed`, trashed orgs excluded, same scope for CORS (domains-cloudflare) vs any row (renderer-lookup) | **Two scopes.** `served()` = `pending, awaiting_nameservers, provisioning, active, manual`, trashed orgs excluded, feeds **only the lookup**. `corsAdmitted()` = `served()` ∩ `{active, manual}` ∩ `serving_confirmed_at IS NOT NULL` feeds **CORS and payment returns** | The lookup must answer for a pending host, because the probe that confirms it goes through the renderer; an origin is trusted only once we have seen our own site answer on it, so a mistyped or someone-else's custom host is never admitted, and Stripe never returns a payer to it. |
| R4 | Seeding the live hosts | Probe, then import only what matches (domains-cloudflare) vs reserve every static-map host (renderer-lookup) | **Import every host.** A probe match becomes `manual`; anything else becomes a new status, **`reserved`**, which is never served, never in CORS and never advanced | This reserves `meccharlotte.org` and similar hosts for MEC without admitting an unverified origin. |
| R5 | Renderer caching and failure | KV soft 900 s / hard 86 400 s, KV negatives for 60 s, 503 when the lookup fails (renderer-lookup) vs KV positives for 300 s, negatives in memory only, 404 when the lookup fails (domains-cloudflare) | **renderer-lookup's structure and its 503.** Negatives are memo-only. KV is written only when a record is new or has changed, and is read only as a stale-if-error fallback. **An absent answer deletes a found record KV still holds for that host** | The KV plan is unknown, and Workers Free allows 1,000 writes a day, shared with the page cache (renderer-lookup fact [13], risk [2]); without the delete, a trashed or failed host's last record would survive 24 h and be served as `stale` during the next API outage. |
| R6 | Draft storage | Sectioned `answers` + `PATCH` + `lock_version` (draft-and-logo) vs a flat `payload` + `PUT` + `revision` (studio-ui) | **Sectioned** | Each slice owns one section, and replacing a whole section means autosaves from two steps cannot clobber each other. |
| R7 | BYO store secrets | An encrypted column on the draft (draft-and-logo) vs never persisted (studio-ui) | **Never persisted.** Entered at Step 3 and sent in the provision body | W1's deliverable is the web, and a secret that is never stored needs no scrub, backup or rotation story. |
| R8 | Where the draft logo lives | Private disk (draft-and-logo) vs `HasMedia` on `StudioDraft` (studio-ui) | **Private disk** | `Masjid::header_logo()` and `footer_logo()` have no `model_type` filter (`app/Models/Masjid.php:622-634`), and `.claude/rules/private-uploads.md:26-60` forbids medialibrary for private files. |
| R9 | How the Step 1 choice is stored | A `studio_drafts.capabilities` column (catalogue) vs `modules`/`grants` overrides in the payload (studio-ui) | **`answers.features.capabilities`**, holding the full map of served keys. The writer stores only departures from the defaults | One storage mechanism, and Studio orgs keep sparse overrides exactly like wizard-made orgs. |
| R10 | Where new provision inputs are validated | A `config('studio.appliers')` registry (draft-and-logo) vs optional `ProvisionMasjidRequest` keys (catalogue, layouts, domains) | **Optional request keys, applied in a fixed order in `OrganisationProvisioner`.** Only the draft-only writes (logo, ink tokens, draft status) live in `StudioProvisioning` | Capabilities, then pivot, then forms, then starter pages is an order dependency a registry would hide, and one validator keeps draft provisioning byte-identical to a direct POST. |
| R11 | New `/api/v1/settings` keys | Always present, `null` (draft-and-logo) | **Emitted only when the org has that row** | This keeps the live tenants' settings bytes identical, including anything the renderer serializes from them. |
| R12 | `description` vs `vibe` | Publish `description` only if it is public copy (renderer-lookup); the layouts writer publishes it verbatim | **`masjids.description` is public copy, in the client's words. `vibe` stays in the draft only** | The lookup, the hero subtitle and the meta description all publish `description` verbatim. |
| R13 | Extract `designTokens.ts` from `ThemeSettingsView.vue` (studio-ui) | — | **Not done** | That view is live for every tenant's admins (`MosqueDetailsTabsView.vue:96,112`), and the preview gets its tokens from the server's `DesignTokens`. |
| R14 | IDN hosts | `idn_to_ascii` (renderer-lookup) vs refuse non-ASCII (domains-cloudflare) | **Refuse non-ASCII; accept `xn--` labels exactly as typed** | ext-intl is not verified on production, and no client has an IDN. |
| R15 | Starter label locales | `en` and `ar` (layouts) | **`en` only in W1** | A lookup-resolved tenant renders en/ltr in W1 because the payload carries no locale (renderer-lookup contract A), so Arabic section labels would sit inside English chrome. |
| R16 | The contrast rule | Its own WCAG maths with blocking pairs (draft-and-logo) vs per-platform `WcagColor` rows (studio-ui) | **`PaletteReport` blocks, and its ratios come from `WcagColor`. Per-platform rows are advisory** | The iOS home header is hard-coded white (`ios:Masjid/Views/Main/Home/HomeView.swift:174`), and no stored token can change that. |
| R17 | SPA folders | `components/studio` (studio-ui) vs `components/super/studio` (catalogue, layouts) | **`views/dashboard/super/studio/**` and `components/super/studio/**`** | "Brand Studio" and "Flyer Studio" already exist (draft-and-logo risk [10]). |
| R18 | Prefix for new endpoints | `/onboarding/capabilities`, `/onboarding/layout-preview`, `/onboarding/domains/check`, `/studio/palette/check` | **All under `/api/admin/studio/*`** | One `super` group means one access test, and the legacy `/onboarding/*` routes stay untouched. |
| R19 | Preview endpoints | Palette check, layout preview and Studio preview as three endpoints | **One `POST /api/admin/studio/drafts/{draft_id}/preview`**, which embeds the `PaletteReport` and the `StarterPlan` | The gate, the mockups and the writer read one derivation, so they cannot disagree. |
| R20 | The old wizard route | Redirected at once (studio-ui, draft-and-logo) | **Kept until S12** | The wizard is the working provisioning tool until Studio can provision. |
| R21 | The bulk writer | A guarded `apply()`, a `PATCH` endpoint and `setCapability` delegating to it (catalogue) | **W1 ships `applyAtCreation` only.** The rest is W2 | Delegation is the only change that can move the live switch panel, and no W1 flow edits an existing org. |
| R22 | Where `NUXT_TENANT_HOSTS` is defined | Spec cites `nuxt.config.ts:111` | **`renderer:nuxt.config.ts:136`** at `d6a00bc`; line 111 is `pageCacheBuildId` | The spec's citation is stale (renderer-lookup fact [2]). |
| R23 | What a web tenant with no logo shows | The Al-Fateh mark (studio-ui, `renderer:app/stores/app.ts:189-209`) vs `null` on `origin/main` (layouts) | **Trust `origin/main`**, and require a logo for web on the server anyway | The layouts recon re-checked against `origin/main`, and the server gate costs nothing. |
| R24 | When "Open live site" unlocks | When `verified_at` is set (domains-cloudflare) vs polling `/api/tenant` from the SPA (renderer-lookup) | **A new column, `masjid_domains.serving_confirmed_at`, set by a server-side probe.** `live_url` exists only once it is set | An active certificate does not prove the right org is being served (KV takes up to 60 s to propagate), and the SPA cannot read another origin's headers without CORS the renderer does not claim to send. |
| R25 | Default brand for a new draft | The wizard defaults to Burlington's palette (`OnboardingWizardView.vue:635-640`) | **A new draft starts with no colours; Step 0 requires a choice** | A default that is a live client's palette gets shipped by accident. |
| R26 | How a CRM choice at creation is recorded | Create with `provision_default`, then the writer ledgers the departure (catalogue §D3, test `crm_chosen_off_is_born_dark_and_says_so_in_the_ledger`) | **Catalogue's.** A Studio org's `masjids` row is always created with `provision_default` (the Studio path cannot send `crm_enabled`); `applyAtCreation` writes and ledgers a CRM departure like any other | Setting `crm_enabled` from the choice at create time would make the departure invisible to the writer, so turning CRM off would leave no ledger row. |
| R27 | What Step 3 requires for a web deliverable | Logo required server-side (studio-ui risk); no rule for the layout | **Logo, slug and an approved layout preset** | Without a preset no `home` page is written. The renderer's home route reads the page with slug `home` (`renderer:app/pages/index.vue:8-9`) and spins forever when it has no active section (`:40-42`); what it does with no page at all is not recorded, so W1 never produces that state. |
| R28 | Deleting an imported host row | 204 when no `cf_*` id is set (domains-cloudflare §7) | **409 for `source = imported` in W1** | Those rows are the live tenants' map; after S9 deleting one would drop a live origin from CORS. |

---

## 3. Ship paths

### 3.1 MasjidWebMS

1. **Worktree.** Branch from `main` in a clean worktree. The shared tree is used
   by parallel sessions (owner's notes: shared-tree integration).
2. **Suite on the droplet.**
   - There is no PHP locally. Run the suite on the droplet, one suite at a time
     in the shared CI tree, behind a busy check that blocks.
   - Exclude `bootstrap/cache/` and clear compiled views first (owner's notes:
     test runner, CI worktree, stale bootstrap cache, stale compiled views).
   - Every test named in the slice must pass, and so must the full suite.
   - A test listed as "unedited" must go green **without edits**. If one needs
     an edit, stop.
3. **Staging.** Run `scripts/ship.sh staging <branch>`. Drive the change on
   staging in the browser, per `.claude/rules/shipping.md`. The staging box is
   shared, so check its `git reflog` before shipping.
4. **Production.** Merge to `main`, then run `scripts/ship.sh production`.
   - The script ships `main` only and asks for a typed confirmation.
   - It builds the SPA on the Mac, rsyncs it, runs `sudo bin/deploy`, then
     proves the served entry chunk exists (`scripts/ship.sh:6, :12-25, :121-127`).
   - The owner's go is required, as for every production ship.
5. **Verify** through the user's layer: each slice's "Verify in production",
   plus the ABI check (§3.4) where listed.
6. **Record** `STATE.md` and `DECISIONS.md` in MasjidWebMS at ship time.

**Migrations:**

- Blueprint only, additive.
- Name composite indexes by hand, under 64 characters. MySQL enforces that
  limit and SQLite does not.
- Tests assert column types (`.claude/rules/shipping.md:43-53`).

**Config vs `.env`:**

- `bin/deploy` caches config (`scripts/set-server-secret.sh:22-23`), so new `config/*.php` keys are live from the ship.
- A new `.env` key is live only after a ship that follows
  `scripts/set-server-secret.sh`. That script deliberately does not run
  `config:cache` (`scripts/set-server-secret.sh:22-25`).
- Code reads `config()`, never `env()`.

### 3.2 Renderer (`burlington-masjid-site`)

1. **Branch from `origin/main`.** The local checkout is on
   `feat/registration-page`, about 30 commits behind (renderer-lookup fact [0]).
   The repo's `CLAUDE.md` and `docs/multi-tenancy.md` still describe Vercel;
   ignore them on deployment.
2. **Two Pages projects, one repo:**
   - `manara-renderer` builds from `main`. It serves `www.burlingtonmasjid.com`
     and `burlingtonmasjid.com` (1), `sundayschool.burlingtonmasjid.com` (18),
     `mec.manara.hopetechapps.com` (13) and `alrazi.manara.hopetechapps.com` (14).
   - `mec-web` builds from `cloudflare-migration` and serves `mec-web.pages.dev` (13).
   - **W1 merges only to `main`. `cloudflare-migration` is not touched.**
3. **Gates:**
   - `npm test`: `node --test`, which runs before `npm ci`, so new logic must be
     pure code in `shared/` with its I/O injected (`renderer:.github/workflows/build.yml:30-37`).
   - The build.
   - A `wrangler pages dev` integration run against a stub API.
4. **Cache facts the check has to respect:**
   - The page cache lives in KV `MANARA_PAGE_CACHE`, and its key includes the
     build id (`renderer:shared/pageCacheBuildId.ts:5-12`), so every deploy re-keys.
   - Responses ≥400 are never stored (nitropack `cache.mjs:147-161`).
   - With swr, an expired entry is served while a re-render runs (`cache.mjs:44-96`).
5. **Every renderer slice runs RBI (§3.3) before and after.** Rollback is the
   Pages rollback to the previous deployment.

### 3.3 RBI: renderer byte-identity check

Add it in S10 as `scripts/rbi-capture.mjs` in the renderer repo.

| Host | Expect |
|---|---|
| `www.burlingtonmasjid.com` | `x-manara-tenant: 1` |
| `burlingtonmasjid.com` | Status and `Location` only (today it is a 307 to www) |
| `sundayschool.burlingtonmasjid.com` | `18` |
| `mec.manara.hopetechapps.com` | `13` |
| `alrazi.manara.hopetechapps.com` | `14` |
| `mec-web.pages.dev` | `13`. This is the control; its project is not redeployed |
| `manara-renderer.pages.dev` | 404 with `x-manara-tenant: unresolved` (`docs/tenant-host-map.md:100`) |

**Paths:**

- `/`, plus every page slug that org serves. Take the slug list once, before,
  from `GET https://masjid.hopetechapps.com/api/v1/pages` with the `Masjid-Id`
  header.
- Every route file under `renderer:app/pages/` at the deployed commit, with one
  real id for each dynamic segment. Why: S10 makes the middleware async for every
  route, not only page-builder pages.
- Every GET route under `renderer:server/api/` and `renderer:server/routes/` at
  the deployed commit, `/api/tenant` included.
- `manara-renderer.pages.dev` (table above) stands in for an unmapped host,
  because a host attached to no project never reaches the renderer.

**Capture for each URL:** status, `x-manara-tenant`, the absence of
`x-manara-tenant-source`, and the body. Also capture `https://<host>/api/tenant`
(no-store, `renderer:server/api/tenant.get.ts:15-32`).

**Before:** take two captures at least 5 minutes apart. Bytes that differ between
them are volatile, for example a server-rendered countdown. List them and mask them.

**After:** request each URL once to warm it, then capture. Pass means:

- status, the headers and `/api/tenant` are byte-equal;
- the HTML is byte-equal after masking the volatile bytes, the build-hashed asset
  names and the build id.

The normaliser is committed with the slice, so a reviewer can see exactly what it
hides. **Any other difference: roll back, then investigate.**

### 3.4 ABI: API byte-identity check

For masjids 1, 13, 14 and 18, capture:

- `GET /api/v1/settings`, `/api/v1/pages` and each `/api/v1/pages/{slug}`, all
  with `Masjid-Id`;
- `GET /api/mobile/masjids/{id}`, `/api/mobile/masjids/{id}/features` and
  `/api/mobile/masjids/{id}/tv-config`.

Capture twice before and once after. Compare bytes, and also compare decoded
JSON, because MySQL's JSON type reorders keys (layouts risk [10]). Mask the
volatile fields. **Any unexplained difference: roll back.**

---

## 4. Preflight reads

All of these are read-only. Do each one before the slice it gates.

| Read | Gates | How |
|---|---|---|
| Production `NUXT_TENANT_HOSTS` on `manara-renderer`: hosts, wildcards, `locale` fields | S3 import completeness; S11 | Pages project API, read-only (`docs/tenant-host-map.md:211`) |
| The commit `manara-renderer` and `mec-web` actually serve; whether `main` auto-deploys | S10 | Pages deployments list |
| Count of `sections` whose `settings` JSON has a `studio` key (expect 0) | S8 | A read-only SQL count on production |
| Production `CORS_ALLOWED_ORIGINS` and `FORMS_PAYMENT_RETURN_ORIGINS`, and whether the one-line CORS hotfix has landed | S9 | Read the values; do not edit them |
| `curl -sI -H 'X-Forwarded-Host: www.burlingtonmasjid.com' https://manara-renderer.pages.dev/ \| grep x-manara-tenant` | S11 (record only) | renderer-lookup unknown [3] |

---

## 5. Slices

### S1: Catalogue read (MasjidWebMS, backend)

**Goal.** Step 1's catalogue comes from one server GET, so the "eighth copy"
(landmine 3) never exists.

**Contract.**

- **`config/capabilities.php`** gains three optional keys. Nothing that exists
  today reads any of them.
  - `turns_on`: one plain sentence saying what switching the entry ON gives.
    Required at least on `website`, `prayer_times` and `giving`, whose
    `description` talks about switching off (catalogue §B).
  - `provision_default` (bool): `crm => true` (entry at `:118-124`) and
    `assistant => false` (`:126-132`).
  - `studio_preselect_with => ['web']` on `web_pages` (`:87-94`). Why: that
    grant defaults off for every org type and gates the page builder
    (`routes/admin.php:456`), so without it the client's admin cannot fill a
    single starter placeholder.
- **`App\Support\CapabilityCatalogue`** (final, static, reads config on every call):
  - `forOrgType(string): array`
  - `entry(string $key, array $def, string $orgType): array`
  - `visibility(...)`
  - `defaultAtCreation(string $key, string $orgType): bool`
  - `resolve(string $orgType, array $choices): array<string,bool>`
  - `appPlacement(string $key): array{items: list<string>, tab: bool}`, derived
    from `AppMenu::registry()`
- **Visibility.** The first rule that matches wins:
  1. `group === 'school'` and the org type is not school → `hidden`, and the
     entry is not served (D14). The four school-group grants default to false
     for **every** type (`config/capabilities.php:106-116, :153-178`), so the
     group is the only existing data that marks them.
  2. A module with `Masjid::MODULE_DEFAULTS[key][orgType] === false` → `not_offered`.
  3. `defaultAtCreation` is true → `default`.
  4. Anything else → `optional`.
- **`defaultAtCreation`:**
  - column-backed entries: `provision_default ?? ($key === 'crm')`;
  - everything else: `defaults[orgType] ?? MODULE_DEFAULTS[key][orgType] ?? false`.
- **Route.** `GET /api/admin/studio/catalogue?org_type=masjid|school|community`.
  - It sits in a new group, `Route::prefix('studio')->middleware('super')`,
    inside the admin group (`routes/admin.php:110`) and beside `onboarding`
    (`:1563-1578`).
  - Controller: `App\Http\Controllers\AdminDashboard\StudioCatalogueController@show`.
  - Request: `App\Http\Requests\Admin\Studio\StudioCatalogueRequest`
    (BaseFormRequest). An empty `org_type` becomes `masjid`, then it is checked
    against `Rule::in(Masjid::ORG_TYPES)`.
- **200 response:** `{status:'success', data:{org_type, groups:[{key,label,entries:[Entry]}]}}`.
  - Groups come in `config/capability_groups.php:16-23` order. A group missing
    from that config is appended, labelled by its key, as
    `MasjidsController.php:445-458` does.
  - `Entry` = `{key, kind:'module'|'grant', label, description, turns_on (turns_on ?? description), writer:'capability'|'crm'|'assistant', default_for_org_type:bool|null, offered_by_default:bool, default_at_creation:bool, visibility:'default'|'optional'|'not_offered', preselect_with:string[], where:string|null, surface:'app'|null, app:{items:string[], tab:bool}}`.
- **Errors:**
  - a non-super caller gets 401 `{status:'failed', data:'Unauthorized.'}` (`app/Http/Middleware/SuperAdminMiddleware.php:37-40`);
  - a bad `org_type` gets 422 in the legacy envelope.

**Files:** `config/capabilities.php`, `app/Support/CapabilityCatalogue.php`,
`app/Http/Controllers/AdminDashboard/StudioCatalogueController.php`,
`app/Http/Requests/Admin/Studio/StudioCatalogueRequest.php`, `routes/admin.php`, tests.

**Tests.**

- `tests/Feature/Studio/StudioAccessTest`, which every later slice extends:
  - every route under `/api/admin/studio` returns 401 for a MasjidAdmin, a
    Teacher and a guest;
  - it returns 2xx for a SuperAdmin.
- `CapabilityCatalogueEndpointTest`:
  - `only_a_super_admin_can_read_it`
  - `an_absent_org_type_reads_as_masjid_and_an_unknown_one_is_refused_in_the_legacy_envelope`
  - `every_entry_the_org_type_may_see_is_served_once_in_capability_groups_order`
  - `school_features_are_never_served_to_a_masjid_or_a_community`
  - `defaults_offers_and_visibility_are_read_from_the_catalogue`
  - `what_it_says_a_new_org_is_born_with_is_what_provisioning_gives`
  - `the_app_block_is_derived_from_the_app_menu_registry`
  - `turns_on_falls_back_to_the_description`
  - `a_new_config_entry_appears_with_no_code_change`
  - `it_agrees_with_the_switch_panel_for_a_fresh_org_of_each_type`
  - `it_survives_a_config_without_turns_on_or_provision_default`
- `CapabilityGateTest` gains:
  - `a_turns_on_line_when_present_is_a_non_empty_string`
  - `every_column_backed_grant_has_a_boolean_provision_default`
  - `studio_preselect_with_names_real_platforms`
- These must pass **unedited**: `CapabilitiesEndpointTest`,
  `CapabilityTsMirrorTest`, and the existing `CapabilityGateTest` methods.

**Live impact.** None. The GET is additive and super-only, and nothing reads the
new config keys today (catalogue live impact §4).

**Verify in production.** Signed in as a SuperAdmin in the dashboard, open the
GET for each of the three org types.

- The entry counts match what the test derives from config. The recon counted
  masjid 29, school 33 and community 29.
- The four school keys are absent for masjid and community.
- Signed in as a MasjidAdmin, the GET returns 401.

**Size.** ~1 session, ~6 files (estimate).

---

### S2: Studio drafts, logo and palette (MasjidWebMS, backend)

**Goal.** D7: a draft that survives across sessions, with the client's logo
stored properly and a server-side palette and contrast report. Nothing
provisions yet.

**Migration `2026_09_24_000000_create_studio_drafts_table`** (Blueprint only):

| Column | Type | Note |
|---|---|---|
| `id` | bigIncrements | |
| `status` | string(16), default `'draft'` | `StudioDraft::STATUSES = ['draft','provisioned']`, a PHP const, never a DB enum |
| `current_step` | string(32), default `'foundation'` | `foundation`, `features`, `layout` or `generate` |
| `schema_version` | unsignedSmallInteger, default 1 | |
| `lock_version` | unsignedInteger, default 0 | Optimistic lock |
| `name` | string(255), nullable | `mb_substr` copy of `answers.identity.name` |
| `org_type` | string(32), nullable | Copy of `answers.identity.org_type` |
| `answers` | json, nullable | Cast to array; `null` reads as `[]` |
| `logo_disk`, `logo_path` | string(32), string(255), nullable | Private disk |
| `logo_original_name`, `logo_mime_type` | string(255), string(100), nullable | The name is `mb_substr`'d to 255 |
| `logo_size_bytes` | unsignedInteger, nullable | |
| `logo_width`, `logo_height` | unsignedSmallInteger, nullable | |
| `logo_sha256` | char(64), nullable | |
| `provisioned_masjid_id` | foreignId, nullable, **unique**, `constrained('masjids')`, `nullOnDelete` | Deliberately not called `masjid_id`: a draft exists before its tenant |
| `provisioned_at` | timestamp, nullable | |
| `created_by`, `updated_by` | foreignId, nullable, `constrained('users')`, `nullOnDelete` | |
| timestamps; index `(status, updated_at)` named `studio_drafts_status_updated_idx` | | |

There is no `secrets` column (R7).

**`App\Models\StudioDraft`:**

- It does not use `BelongsToMasjid`. It goes on the `TenantScopingCoverageTest`
  DECLINED list with `has_masjid_id_column=false` (`tests/Feature/TenantScopingCoverageTest.php:76-106`).
- `ANSWER_SECTIONS` and `SECRET_KEYS` (`asc_key_p8`, `asc_key_id`,
  `asc_issuer_id`, `play_service_account_json`).
- `$hidden = ['logo_disk','logo_path']`.
- A `deleting` hook deletes the logo bytes.
- `toProvisionPayload(array $secrets = []): array` flattens the sections into
  `ProvisionMasjidRequest` keys. It adds secrets only for platforms whose
  `account_mode` is `byo`.
- Add `studio_drafts` to the `drop_rows` list in `config/staging_scrub.php:96`.

**The `answers` sections.** A `PATCH` replaces a whole section.

| Section | Shape |
|---|---|
| `identity` | `{org_type, name, email, phone, address, country_id, city_id, latitude, longitude, timezone, user_id, admin:{name,email,phone}, slug, description, vibe, donation_link, donation_title, donation_message, facebook_url, youtube_url, instagram_url, whatsapp_url, whatsapp_number}` |
| `prayer` | `{method, madhab, high_latitude_rule, iqama_type, iqama:{fajr,dhuhr,asr,maghrib,isha}, jumaa_iqama, iqama_given:bool}` |
| `brand` | `{primary_color, secondary_color, accent_color, background_color ('#RRGGBB'), extracted:['#RRGGBB'], ink_overrides:{onPrimary?,onSecondary?,onAccent?}}` |
| `content` | `{about, mission, vision}` |
| `features` | `{capabilities:{<served catalogue key>: bool}}` (R9) |
| `layout` | `{preset, approved_at}` |
| `platforms` | `{platforms:[ios\|android\|tvos\|web], apps:{ios:{account_mode}, android:{account_mode}, web:{account_mode}}}` |
| `domain` | `{custom: null \| {host, zone_apex}}`. The managed host is always `{identity.slug}.manara.hopetechapps.com` |

`vibe` never leaves the draft (R12).

**Routes.** All are under `/api/admin/studio`, behind `super`. The controller is
`App\Http\Controllers\AdminDashboard\StudioDraftsController`, and `{draft_id}`
is `whereNumber`.

| # | Verb and path | Body | Responses |
|---|---|---|---|
| 1 | `GET /drafts?status=draft\|provisioned\|all` | — | 200: rows `{id,status,name,org_type,current_step,has_logo,provisioned_masjid_id,updated_at}`, newest `updated_at` first, limit 100 |
| 2 | `POST /drafts` | `{org_type?, name?}` | 201: `StudioDraftResource` |
| 3 | `GET /drafts/{draft_id}` | — | 200, or 404 (`findOrFail` kept outside any try/catch) |
| 4 | `PATCH /drafts/{draft_id}` | `{lock_version (required), current_step?, answers? (object or JSON string)}` | 200 with `lock_version+1` · 409 `{status:'conflict', data:<current resource>}` when stale · 409 when provisioned · 422 for an unknown section, any `SECRET_KEYS` path, or raw answers over 262 144 bytes |
| 5 | `DELETE /drafts/{draft_id}` | — | 200 (a hard delete through the model, so the bytes go) · 409 when provisioned |
| 6 | `POST /drafts/{draft_id}/logo` | multipart field `logo` | 200: the resource · 422 in the legacy envelope |
| 7 | `GET /drafts/{draft_id}/logo` | — | The file, with the sniffed `Content-Type`, `Cache-Control: private, no-store`, inline · 404 |
| 8 | `DELETE /drafts/{draft_id}/logo` | — | 200: the resource |

**`StudioDraftResource`:**

```
{id, status, current_step, schema_version, lock_version, name, org_type, answers,
 logo: null | {original_name, mime_type, size_bytes, width, height, sha256,
               url: '/api/admin/studio/drafts/{id}/logo'},
 palette: PaletteReport | null,
 provisioned_masjid_id, provisioned_at, created_at, updated_at}
```

The logo `url` is relative, and the SPA fetches it as a bearer blob.

**Requests.**

- `UpdateStudioDraftRequest`: the rules in draft-and-logo §C, minus the secrets
  rules. `prepareForValidation` JSON-decodes a string `answers`. `withValidator`
  refuses an unknown section, any `SECRET_KEYS` path and oversize answers.
- `StoreStudioDraftLogoRequest`:
  - `mimetypes:image/png,image/jpeg`, sniffed;
  - `max:8192`;
  - `dimensions:min_width=96,min_height=96,max_width=8000,max_height=8000`;
  - SVG is refused.
  - Why: GD cannot rasterise SVG, and the private-uploads rule refuses it as
    script-bearing (`.claude/rules/private-uploads.md:82-89`).

**`App\Support\Studio\PaletteContrast::report(array $brand, array $inkOverrides = [], ?array $logoDims = null): array`.**
It returns a `PaletteReport`:

```
{valid, blocking_failures,
 pairs: [{key, foreground, background, ratio (2 dp), required: 4.5|3.0, passes, blocking,
          ink_source: 'design_tokens'|'auto'|'manual'|null}],
 tokens: {color: {onPrimary?, onSecondary?, onAccent?}},
 aspect_warning}
```

- The pairings come from `DesignTokens::resolve(new ThemeSetting([...colours]))`.
  `DesignTokens` itself is not modified.
- Ratios come from `WcagColor` (`app/Support/WcagColor.php:99-162`). If its ratio
  method is not public, widen its visibility only; do not add a third WCAG
  implementation.
- **Auto-ink.** When `DesignTokens`' `onX` gives less than 4.5:1 and there is no
  manual override, pick whichever of `#111827` and `#FFFFFF` scores higher. Those
  are DesignTokens' two inks (`DesignTokens.php:104-107`). The winner goes into
  `tokens.color.onX`.
- **Blocking pairs (≥4.5):** `text_on_background`, `on_primary`, `on_secondary`,
  `on_accent`.
- **Advisory pairs (≥3.0):** `primary_on_background`, `accent_on_background`.
- `aspect_warning` is true when the logo is wider than 2:1.

**`config/studio.php`:**

| Key | Value |
|---|---|
| `logo.disk` | `env('STUDIO_LOGO_DISK','local')` |
| `logo.directory` | `'studio-drafts'` |
| `logo.mime_types` | `'image/png,image/jpeg'` |
| `logo.max_kb` | 8192 |
| `logo.min_px` | 96 |
| `drafts.max_answers_bytes` | 262144 |
| `drafts.retention_days` | 90 |

A stored logo's path is `studio-drafts/{draft_id}/{Str::random(40)}.{png|jpg}`.

**`php artisan studio:purge-drafts`.** It force-deletes `status=draft` rows not
touched for `retention_days`, through the model so the bytes go.

- It is scheduled daily in `routes/console.php`, `withoutOverlapping`.
- Its minute must avoid `:00`, `:15`, `:30`, `:45` (the reaper) and `:47` (the
  canary) (`routes/console.php:92, :168-177, :367`).
- Why: drafts hold admin PII, and the private disk is not backed up
  (`.claude/rules/backups.md:218-223`).

**Tests.**

- `StudioAccessTest` gains the eight routes.
- `StudioDraftAutosaveTest`:
  - `patch_replaces_a_section_wholesale_and_leaves_others`
  - `stale_lock_version_is_a_409_carrying_the_current_draft`
  - `answers_survive_the_clients_encodings`
  - `secret_keys_are_refused_and_never_stored`
  - `a_provisioned_draft_refuses_patch_and_delete`
- `StudioDraftRoundTripTest::every_section_key_written_is_read_back` (a
  write-only field fails it).
- `StudioDraftSchemaTest`:
  - `answers` is `json` on MySQL or `text` on SQLite;
  - `name` is a string;
  - a 300-character logo filename is truncated rather than refused.
- `StudioDraftLogoTest`:
  - `png_lands_on_the_private_disk_under_a_random_name`
  - `logo_endpoint_streams_with_cache_control_private`
  - `svg_and_renamed_text_are_refused_by_sniffed_type`
  - `replacing_a_logo_deletes_the_old_bytes`
- `StudioDraftDiscardTest`.
- `PaletteContrastTest` (unit):
  - black on white is 21.0;
  - `#777777` on `#FFFFFF` is ≈4.48 and fails;
  - on `#01B151`, DesignTokens' ink `#FFFFFF` is ≈2.83 and the auto-ink
    `#111827` is ≈6.26. These figures are the recon's own arithmetic; this test
    is the measurement;
  - `aspect_warning` is true for a 1000×250 logo.
- `DesignTokensUnchangedTest`: Burlington's resolved tokens equal a committed snapshot.
- `StudioPurgeDraftsCommandTest`.
- The meta-tests stay green: `TenantScopingCoverageTest`,
  `StagingScrubCoverageTest`, `MigrationsBootTest`.

**Live impact.** None. The table is new, the routes are super-only, and
`DesignTokens` is untouched.

**Verify in production.** As a SuperAdmin, from the dashboard session (devtools
`fetch`):

1. Create a draft.
2. `PATCH` the brand section with `#01b151 / #1b1b2e / #ffba63 / #f3f8fb`. Expect
   `palette.pairs.on_primary` to pass through auto-ink `#111827`.
3. Upload a PNG. `GET` the logo and expect `Cache-Control: private, no-store`.
   Confirm nothing appeared under `/storage/`.
4. `DELETE` the draft. The logo `GET` now returns 404.

**Size.** ~2 sessions, ~16 files (estimate).

---

### S3: Host data, public lookup and live-host import (MasjidWebMS, backend)

**Goal.** Landmine 2: the host→org map becomes data. A public lookup exists and
the live hosts are recorded. Nothing consumes any of it yet.

**Migration A: `create_masjid_domains_table`**

| Column | Type | Note |
|---|---|---|
| `id` | bigIncrements | |
| `masjid_id` | foreignId → `masjids`, `cascadeOnDelete` | |
| `host` | string(253), **unique** | Always stored normalised (`HostName`) |
| `kind` | string(32) | `managed_subdomain` or `custom` |
| `zone_apex` | string(253) | `host == zone_apex` or `host` ends with `'.'.zone_apex`; no public-suffix guessing |
| `status` | string(32), default `'pending'`, indexed | See below |
| `waiting_on` | string(32), nullable | `token`, `token_scope`, `nameservers`, `certificate` or `capacity` |
| `source` | string(32), default `'studio'` | `studio` or `imported` |
| `cf_zone_id`, `cf_dns_record_id`, `cf_pages_domain_id` | string(64), nullable | |
| `cf_zone_created` | boolean, default false | True only when Studio created the zone |
| `nameservers` | json, nullable | |
| `last_error` | **text**, nullable | Third-party text. A test asserts the type |
| `last_checked_at`, `next_check_at` (indexed), `verified_at`, `serving_confirmed_at` | timestamp, nullable | `serving_confirmed_at` is R24 |
| `verified_by` | string(16), nullable | `cloudflare` or `probe` |
| `created_by_user_id` | foreignId → `users`, nullable, `nullOnDelete` | |
| timestamps; index `(masjid_id, status)` named `md_masjid_status_idx` | | |

**Statuses** (constants on `App\Models\MasjidDomain`):

- `STATUSES = pending, awaiting_nameservers, provisioning, active, manual, failed, reserved`
- `SERVED = pending, awaiting_nameservers, provisioning, active, manual`
- `NON_TERMINAL = pending, awaiting_nameservers, provisioning`
- `reserved` is never served, never in CORS and never advanced (R4).

**Migration B: `add_slug_and_description_to_masjids`**

- `slug`: string(63), nullable, unique (index `masjids_slug_unique`).
- `description`: text, nullable.
- Add both to `$fillable` and to `Masjid::PUBLIC_DIRECTORY_DENYLIST`
  (`app/Models/Masjid.php:114-120`; catalogue cites `:144`).
- Why these live on `masjids`: `slug` names the managed host and, in W3, the
  client repos; `description` is public copy that the renderer's head and hero read.

**`App\Support\HostName::normalize(?string): ?string`.**

- In order: trim, lowercase, keep the part before the first `,`, strip `:port`,
  strip a trailing `.`.
- Returns `null` for: an empty result, more than 253 characters, an IPv4 or IPv6
  literal, a non-ASCII host (R14), or any label failing
  `/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/` (`\z`, not `$`: PCRE's `$` also
  matches before a final newline, so `www\n` would pass as a label; the
  `:port` strip is anchored the same way).
- The fixture `tests/fixtures/host-normalization.json` holds `{input, expected}`
  pairs. S10 commits a byte-identical copy to the renderer.

**`App\Models\MasjidDomain`** (domains-cloudflare §1, plus R3, R4 and R24):

- The `host` mutator uses `HostName`; a `null` result throws `InvalidArgumentException`.
- `scopeServed()`: `SERVED`, plus `whereHas` a non-trashed masjid (Masjid uses
  SoftDeletes, `app/Models/Masjid.php:8`). Read by the lookup only.
- `scopeCorsAdmitted()`: `served()`, status `active` or `manual`, and
  `serving_confirmed_at` not null (R3). Read by CORS and payment returns only.
- `corsOrigins(): list<string>`:
  - returns `'https://'.host` for each `corsAdmitted()` row;
  - is cached under the **fixed** key `masjid-domains:cors-origins:v1` for 300 s,
    and forgotten on `saved` and `deleted`;
  - why a fixed key: production's cache store is the database, and keys a caller
    can choose grow without bound (`app/Http/Middleware/TrustedHosts.php:216-230`).
- `manualSteps(): list<string>` is built server-side for each case. The SPA
  never restates Cloudflare's instructions.
- `liveUrl()` returns `'https://'.host` if and only if `serving_confirmed_at` is set.
- A `saving` invariant: `status = active` requires `verified_by = 'cloudflare'`
  and `verified_at` to be set, otherwise it throws `LogicException`.
- A second one: a stored `reserved` row cannot change status (`LogicException`).
  Every reserved row is imported, so Studio cannot delete it (409) and its host
  cannot be added again (422): a reservation that should go live, or be let go,
  is a platform-level change, and `manualSteps()` says so rather than offering
  remove-and-add.
- `TenantScopingCoverageTest` DECLINED entry: `has_masjid_id_column=true`,
  reason "Host→org map read by the unauthenticated renderer lookup and written
  only by SuperAdmin Studio routes; unbound by design".

**`config/cloudflare.php`** (domains-cloudflare §4 as written):

- `studio_token`, `account_id` (default `86cec9c5e0efe76fedb5698a2be91beb`),
  `pages_project` (`manara-renderer`), `pages_target` (`manara-renderer.pages.dev`);
- `managed_zone` (`hopetechapps.com`), `managed_zone_id`
  (`859eddb9bce48f4f35e6197f6c0b8e15`, `deploy/staging/cloudflare-dns.sh:15-25`),
  `managed_suffix` (`manara.hopetechapps.com`);
- `reserved_labels`: `['www','api','admin','app','staging','portal','mail','manara','mec','alrazi','preview']` (`preview` added 2026-09-24: the live-preview host);
- `pages_domain_ceiling` 100, `api_base`, `timeout` 15.

In the same change:

- add a blank `CLOUDFLARE_STUDIO_TOKEN=` to `.env.example` and to
  `deploy/staging/env.staging.example`;
- add **`CLOUDFLARE_`** to `DENY_PREFIXES` in `deploy/staging/provision.sh:382`.

Why now: the owner is already adding the token, and a staging copy of it would
attach domains against the production Pages project (domains-cloudflare fact [15]).

**`App\Services\Domains\DomainProbe::probe(MasjidDomain): array{matched: bool, seen: string}`.**

- It fetches `https://{host}/api/tenant` with a 5 s timeout, without following
  redirects, with TLS verified.
- `matched` means a 200 whose `x-manara-tenant` header equals `(string) masjid_id`.
  The renderer sets that header on every response
  (`renderer:server/middleware/tenant.ts:37-44`).
- It never sets `active`.
- `confirm()` stamps a match: `serving_confirmed_at`, and `manual` for a row
  Cloudflare has not verified (a `failed` row included). A `reserved` row gets
  only `last_checked_at`, match or not (R4).
- **SSRF guard** (domains-cloudflare risk: the probe fetches a SuperAdmin-typed
  host). It resolves the host through an injected resolver, refuses to fetch when
  any address is loopback, private, link-local or reserved
  (`FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE`), and pins the
  connection to the address it checked (`CURLOPT_RESOLVE`) so a second lookup
  cannot swap it. `seen` records the refusal.

**Write-side host rule.** `StudioDomainCheckRequest`, S7's `StoreMasjidDomainRequest`
and S8's `web_domain.custom_host` all refuse, after `HostName::normalize`: fewer
than two labels, `localhost`, and hosts ending `.localhost`, `.local`,
`.internal`, `.pages.dev` or `.workers.dev`. Why not in `HostName` itself: it
must stay byte-compatible with the renderer's `normalizeHost` through the shared
fixture, and the renderer's map holds `localhost`.

**Public route.** `GET /api/v1/organizations/by-host?host=`

- It lives in `routes/api_v1.php`, inside `prefix('v1')` (`:20`), with
  `->middleware('throttle:tenant-host')`.
- The limiter is registered in `AppServiceProvider`:
  `RateLimiter::for('tenant-host', fn ($r) => Limit::perMinute(600)->by('tenant-host:'.$r->ip()))`.
- `App\Http\Controllers\Api\V1\OrganizationByHostController@show`:
  1. Normalise the host.
  2. `MasjidDomain::served()->where('host', $h)->first()`.
  3. Load the masjid.
  4. Build the payload from an explicit **allowlist**; never `toArray()`.
- 200: `{status:'success', message:'OK', data:{host, masjid_id, name, description, favicon_url, share_image_url}}`.
  `favicon_url` and `share_image_url` are `null` until S8 fills them from their
  own collections, never from `logos`.
- 404: `{status:'error', message:'No organisation serves this host.'}` for a
  missing, invalid, unknown, failed, reserved or trashed-org host.
- Every response carries `Cache-Control: no-store`. Nothing is cached
  server-side per host.

**Domain check route.** `POST /api/admin/studio/domains/check` (super).

- Body: `{kind:'managed_subdomain', label}` or `{kind:'custom', host, zone_apex}`.
- 200: `{host, available, taken_by_masjid_id, case:'managed_subdomain'|'unknown', token_configured, pages_domains_used:null, pages_domains_ceiling}`.
- It makes **no Cloudflare call in S3**; S7 adds zone detection.
- `StudioDomainCheckRequest` checks:
  - the DNS-label rule;
  - the label is not reserved;
  - a custom host does not end with `managed_suffix`;
  - the zone-apex suffix rule.

**`php artisan domains:import-host-map {map} {--apex=*} {--dry-run} {--execute}`.**

- It is a dry run unless `--execute` is given, and `--dry-run` always wins.
- `map` is a JSON object `host => id`, the same shape as `NUXT_TENANT_HOSTS`:
  each value is a bare id or an object carrying one (`{"id": "13", ...}`), as
  the renderer's `toRecord` reads it. Only the id is used.
- `--apex=HOST:APEX` states the zone of each custom host. A custom host without
  one is refused.
- For each host:
  1. Normalise it. Localhost, IP literals and `*.pages.dev` are skipped.
  2. Refuse an id that has no masjid.
  3. Probe it.
  4. On a match, write it as `source=imported, status=manual, verified_by=probe, verified_at, serving_confirmed_at`.
  5. Otherwise, write it as `source=imported, status=reserved`.
- `kind` is `managed_subdomain` when the host ends with `managed_suffix`,
  otherwise `custom`.
- It is idempotent, and it never re-points an existing host to a different masjid.

**Operator step after the production ship:**

1. Do the §4 read of `NUXT_TENANT_HOSTS`. Merge it with the in-git map
   (`renderer:nuxt.config.ts:20-58`).
2. Add Al-Razi's hosts served outside the renderer, so Studio can never give them
   to another org: `alrazischool.org` and `www.alrazischool.org` → 14 (the
   `al-razi-school-web` project, domains-cloudflare live impact (d); the apex
   307s to `www`, so `www` is the host the public lands on),
   `portal.alrazischool.org` → 14 (`PORTAL_HOSTS`, live impact (c)) and
   `parents.alrazischool.org` → 14 (the `alrazi-parent-guide` Worker,
   docs/tenant-host-map.md). None answers `x-manara-tenant`, so all four land
   `reserved`. The lookup matches exact hosts only, so a host left out here is
   one Studio reports as available.
3. Run the import with `--dry-run` and an `--apex=HOST:APEX` for every custom
   host (for example `--apex=www.alrazischool.org:alrazischool.org`). Check the
   plan against the table below, then run the same command with `--execute`.

| Expected (verify with the dry-run) | Status |
|---|---|
| `www.burlingtonmasjid.com`→1, `sundayschool.burlingtonmasjid.com`→18, `mec.manara.hopetechapps.com`→13, `alrazi.manara.hopetechapps.com`→14 | `manual` |
| `burlingtonmasjid.com`→1 | `manual` or `reserved`. Its 307 to www is answered outside the renderer code (domains-cloudflare fact [26]), and the probe does not follow redirects. Either outcome is correct |
| `mec.hopetechapps.com`, `meccharlotte.org`, `www.meccharlotte.org`→13; `alrazischool.org`, `www.alrazischool.org`, `portal.alrazischool.org`, `parents.alrazischool.org`→14 | `reserved` |
| `new.burlingtonmasjid.com`→1 | `reserved`. It is in the live map but is not a custom domain on the project (OQ1), so the probe cannot match it |
| `mec-web.pages.dev`, `localhost`, `127.0.0.1` | skipped |

**Tests.**

- `MasjidDomainSchemaTest`: `host` is unique; `last_error` is `text`; `status`
  and `kind` are strings, not enums; every index name is under 64 characters; no
  column name matches a staging-scrub PII token.
- `MasjidDomainHostTest` (unit).
- `HostNameFixtureTest`.
- `MasjidDomainActiveInvariantTest`.
- `MasjidIdentityColumnsTest`: `slug` is unique and a string; `description` is `text`.
- `PublicPayloadKeysUnchangedTest`: for a fixture org, the key sets of
  `/api/v1/settings`, `/api/mobile/masjids/{id}` and the mobile directory equal
  a committed list.
- `OrganizationByHostTest`:
  - `data` keys are exactly `[host, masjid_id, name, description, favicon_url, share_image_url]`;
  - email, phone, `google_maps_key`, `stripe_account_id` and `user_id` are absent;
  - `WWW.Example.ORG.:443` resolves the row stored as `www.example.org`;
  - 404 for an unknown, missing, 254-character, IP-literal, failed, reserved or
    trashed-org host;
  - with two orgs, no host ever returns the other org;
  - a miss writes no cache row;
  - the response carries `no-store`;
  - the route has `throttle:tenant-host`, and neither `tenant` nor `auth:sanctum`.
- `DomainProbeTest`: a match gives `manual`; a mismatch, a redirect or a timeout
  does not match; the probe never produces `active`;
  `a_host_resolving_to_a_loopback_or_private_address_is_never_fetched`
  (`Http::assertNothingSent`).
- `MasjidDomainScopesTest`:
  - `served_includes_pending_for_the_lookup_but_cors_admitted_does_not`;
  - `an_active_row_is_not_cors_admitted_until_serving_is_confirmed`;
  - `failed_reserved_and_trashed_org_rows_are_in_neither_scope`;
  - `cors_origins_equals_the_cors_admitted_hosts_exactly`.
- `ImportHostMapCommandTest`: probe mismatches become `reserved`; `--dry-run`
  writes nothing; an unknown id is refused; the command is idempotent; it never
  re-points a host.
- `StudioDomainCheckTest`: a reserved host is unavailable and returns `taken_by`;
  with the token blank, a custom host's case is `unknown`;
  `single_label_and_internal_suffix_hosts_are_refused`.
- The meta-tests stay green: `TenantScopingCoverageTest`,
  `StagingScrubCoverageTest`, `MigrationsBootTest`.
- `PublicMasjidDirectoryTest::every_masjids_column_is_deliberately_classified`
  (`tests/Feature/PublicMasjidDirectoryTest.php:145-164`) goes green **unedited**
  once `slug` and `description` are on the denylist. It is the guard that fails
  if a new `masjids` column reaches an anonymous caller.

**Live impact.**

- `masjids` gains two nullable columns. They are denylisted, and the key sets are
  pinned by `PublicPayloadKeysUnchangedTest`.
- Nothing reads the table or the endpoint until S9 and S11. The imported rows
  change nothing: CORS stays static until S9, and the renderer stays static-only
  until S11.
- The deny-list edit affects staging only.

**Verify in production.**

1. Run ABI (§3.4) for settings, show and the directory, before and after the ship.
2. After the import:
   - `curl` by-host for `mec.manara.hopetechapps.com`: 200, `masjid_id` 13;
   - `meccharlotte.org`: 404;
   - `example.org`: 404;
   - the header reads `Cache-Control: no-store`;
   - a read-only `SELECT host, status FROM masjid_domains` matches the dry-run.

**Size.** ~2 sessions, ~20 files (estimate).

---

### S4: Layout presets and preview (MasjidWebMS, backend, no writes)

**Goal.** Step 2's data and one server-derived preview, which the mockups, the
contrast gate and (in S8) the writer all read.

**Contract.**

- **`config/studio_layouts.php`**, as in layouts §A. Its top-level keys are
  `version`, `labels` (**`en` only**, R15), `hints`, `blocks`, `presets` and
  `defaults`.
  - Three presets per vertical, with the layouts recon's page and section lists:
    `masjid.essentials | masjid.classic | masjid.gathering`,
    `school.essentials | school.prospectus | school.community`,
    `community.essentials | community.services | community.gathering`.
  - `defaults`: `{masjid:'masjid.classic', school:'school.essentials', community:'community.essentials'}`.
  - Each preset also declares `theme_layout: {header:'default'|'overlay', footer:'default'|'columns'}`.
    These are the two variants the renderer already draws from
    `theme.tokens.layout` (`renderer:app/components/layout/Header.vue:17-33`,
    `Footer.vue:20-43`); `DesignTokens` deep-merges stored tokens
    (`app/Support/DesignTokens.php:66-69`).
    - `*.essentials`: default header, default footer.
    - `classic`, `prospectus`, `services`: default header, `columns` footer.
    - `gathering` and `school.community`: `overlay` header, `columns` footer.
    - Why: three visibly different sites, built only from what the renderer
      already renders.
- **Rules, carried over verbatim from the layouts recon:** the activation rules
  (§B), the placeholder convention (`sections.settings.studio`, §C) and the
  no-invention rule (§G).
  - Starter content is facts copied verbatim, interface labels, page refs, form
    refs, structural enums, or empty. That is D8.
  - No preset uses `stats`, `impact_stats`, `carousel`, `embed`, `offering`,
    `services_list`, `image`, `text` or `grid_cards`.
- **Classes:**
  - `App\Support\Studio\LayoutPresets`: `forOrgType`, `find`, `keysFor`,
    `defaultFor`, `optionsPayload`.
  - `StarterFacts`: `fromArray`, `fromMasjid`, `get`.
  - `StarterPlan`: a value object.
  - `StarterSite::plan(Masjid $org, string $presetKey, StarterFacts $f): StarterPlan`
    (pure), plus `const STRUCTURAL`.
  - `StarterPlaceholders::publicSettings` is **not** here; it ships with the
    writer in S8.
- **`App\Support\AppFeaturePivot::rowsFor(Masjid $m): array<int,bool>`.**
  - For each `MobileAppFeature` **by id**, it returns
    `AppMenu::legacyAvailability($m, $id)` (`app/Support/AppMenu.php:460-473`).
  - Why by id: production's key is `qur’an` (U+2019), not `quran` (catalogue fact [9]).
  - The write, `seedFromSwitches`, lands in S8.
- **`App\Support\Studio\StudioPreview::build(StudioDraft $draft, array $answerOverrides = []): array`**
  follows studio-ui §4 with these fields:
  - `palette`: `PaletteContrast::report(...)`, which is the gate.
  - `web_tokens`: `DesignTokens::resolve(new ThemeSetting(colours + tokens{color: palette.tokens.color, layout: preset.theme_layout}))['color']`.
  - `platform_contrast` (advisory only):
    - `ios.home_header`: `#FFFFFF` on primary (`ios:…/HomeView.swift:174`);
    - `android.home_header`;
    - `app.menu_band`;
    - `ios.selected_tab`;
    - `android.selected_tab`: `#00AA55` (`android:…/bottomBar/BottomBar.kt:38-100`);
    - `web.primary_button`;
    - `tvos.header`: `#FFFFFF` on `#0F0F0F`.
  - `app.ios`: `{tabs: AppMenu::tabs, sections: AppMenu::sections}`, computed on
    an unsaved `Masjid` (`AppMenu.php:69-91, :361-385, :434-445`).
  - `app.android.tabs`: from `AppFeaturePivot::rowsFor` ids 10, 11 and 6.
  - `web`: `StarterPlan::toArray()` plus `theme_layout`.
  - `tvos`: read from the `TvConfigController` constants
    (`app/Http/Controllers/Mobile/TvConfigController.php:84-93`), which change
    from private to **public**. That is a visibility change only.
  - `org.host`: `{slug}.{managed_suffix}`, or `domain.custom.host`.
- **Routes** (super, under `/api/admin/studio`):
  - `GET /layout-presets?org_type=` returns `LayoutPresets::optionsPayload()[org_type]`.
  - `POST /drafts/{draft_id}/preview`, body `{answers?}` (sections that override
    the saved ones), returns 200 `{data: StudioPreview}`. **It writes nothing.**

**Tests.**

- `StudioLayoutPresetsTest`:
  - `every_org_type_has_exactly_three_presets_and_a_default_that_is_one_of_them`
  - `every_block_type_is_a_SectionType_case_the_renderer_draws`
  - `every_content_key_is_in_defaultContent_or_the_documented_renderer_extras`
  - `every_string_leaf_is_a_fact_label_page_ref_form_ref_structural_value_or_empty`
  - `resolved_plans_contain_only_provenanced_strings`
  - `labels_have_every_key_contain_no_digits_and_match_the_pinned_snapshot`
  - `every_page_ref_and_form_ref_resolves_within_the_preset_and_vertical`
  - `every_background_color_is_empty_and_every_image_field_is_null`
  - `every_preset_home_has_an_active_hero_under_the_minimal_required_facts`.
    Why this one matters: an empty home page renders an infinite spinner
    (`renderer:app/pages/index.vue:40-42`).
- `StarterPlanTest::a_module_that_is_off_omits_its_sections_and_an_emptied_page`
- `StudioPreviewTest`:
  - `masjid_defaults` (tabs `['home','announcements','contact','donate']`, worship section)
  - `school_has_no_worship_and_no_prayer_panel`
  - `switches_change_the_tabs`
  - `contrast_values_come_from_WcagColor`
  - `preview_writes_nothing`
  - `a_section_without_a_renderer_is_flagged`
- `AppFeaturePivotTest::rows_are_derived_by_id_so_the_production_quran_key_is_found`
- `TvConfigSnapshotTest`: the `tv-config` body is identical.
- `StudioAccessTest` gains the two routes.

**Live impact.** None to any output.

- No public read path changes in this slice (the settings strip is S8).
- `TvConfigController`'s constants become public. The `tv-config` body is
  identical, pinned by the snapshot.

**Verify in production.**

- Run ABI for `tv-config`, for 1, 13, 14 and 18.
- Request the preview for a scratch draft as a SuperAdmin, then delete the draft.

**Size.** ~3 sessions, ~18 files (estimate).

---

### S5: Studio SPA, Steps 0–2 (MasjidWebMS, SPA)

**Goal.** A SuperAdmin can open Manara Studio, start or resume a draft, and walk
Foundation, Features and Layout with live mockups. The old wizard stays exactly
as it is.

**Contract.**

- **Routes** (`resources/vue-app/router/routes/superDashboardRoutes.ts`, beside
  `:101-111`; the `masjid.onboarding` block is untouched until S12):
  - `{path:'studio', name:'studio.drafts', component:'views/dashboard/super/studio/StudioDraftsView.vue', meta:{auth:true, allowedUsers:['SuperAdmin'], pageTitle:'Manara Studio', dashboardType:'super'}}`
  - `{path:'studio/drafts/:draft_id(\\d+)', name:'studio.draft', component:'views/dashboard/super/studio/StudioView.vue'}`, with the same meta.
- **Sidebar** (`core/constants/dashboardAsideMenuItems.ts:705-714`): add
  **"Manara Studio"** → `/dashboard/super/studio`, next to "Onboard Masjid",
  which stays until S12.
- **Typed routes.** `SystemRoutes.ts:98` gains the two paths. `BackendApiRoutes.ts:216-221`
  gains the studio API paths, appended with a leading pipe.
- **Store: `stores/super/studioDraftStore.ts`** (Pinia setup store).
  - State: `draft, answers, options, catalogue, presets, preview, saveState ('idle'|'saving'|'saved'|'error'|'conflict'), savedAt, loadError`.
  - `patchSection(section)` debounces 1500 ms and sends a **plain object**
    (JSON). Why: PHP parses multipart only on POST (`core/services/ApiService.ts:32-62`).
  - Autosave is armed **only after a successful load**. A failed load shows
    Retry and never saves defaults over the draft.
  - A 409 sets `conflict` and shows "Reload draft". It is never retried blindly.
  - A `beforeunload` guard runs while a save is in flight.
  - `refreshPreview()` debounces 400 ms and posts the unsaved sections.
- **Helpers:**
  - `core/helpers/prepareLogo.ts`:
    - turns SVG, WebP, GIF or any other type into a PNG through a canvas, at
      most 2048 px on the longest side, keeping alpha;
    - PNG and JPEG files already within 2048 px are sent as they are;
    - never use `preparePhoto.ts`, which flattens to JPEG (`core/helpers/preparePhoto.ts:36`).
  - `core/helpers/extractPalette.ts`: `extractDominantColors` and `rgbToHex`,
    moved **verbatim** from `OnboardingWizardView.vue:896-944`. The wizard
    imports them back and nothing else in it changes.
  - `core/studio/appLabels.ts`: the only place native strings are copied. Every
    constant carries a comment naming its source `path:line`.
  - `core/studio/mockPrayerTimes.ts`: uses the `adhan` dependency already in
    `package.json`. The method names match `app/Enums/PrayerCalculationMethod.php:7-18`.
- **Views and components** (`views/dashboard/super/studio/` and `components/super/studio/`):

| Component | Does |
|---|---|
| `StudioDraftsView` | A `PageDataContainer` with a "New client" button. Columns: Name ("Untitled draft" when empty), Type, Step, Last saved, Status (Draft, or Live → `/dashboard/super/masjids/{id}`). Actions: Resume, and Discard with a confirm |
| `StudioView` | The stepper (Foundation, Features, Layout, Generate); the save status; the step body in `col-xl-7`; the preview panel in `col-xl-5`, sticky, collapsible below xl. Generate is disabled until S8 |
| `steps/StepFoundation` | Panels. **Identity**: org type and its vertical effects; the identity fields; the slug, checked live through `/studio/domains/check`; `description` labelled "client's words, published"; `vibe` labelled "internal, never published". **Prayer**: masjid only; iqama and Jumu'ah times, or a "Client has not given iqama times" tick that sets `iqama_given=false`. **Brand**: logo upload, blob preview, palette candidates, the four colours, ink overrides, the server palette report. **About**: "Only what the client told you." **Links**. **Platforms**: from wizard `:335-422`, keeping the tvOS→iOS watch at `:704-711`, without secrets. **Domain**: a preview of the managed host, plus an optional custom host and zone apex |
| `steps/StudioFeatureStep` | Calls `GET /studio/catalogue`. Groups render in the order served. `not_offered` rows sit collapsed under "Not usually for a {vertical label}". Each row shows `turns_on` and an app chip. `preselect_with` applies when the selected platforms match. **No key or label literals.** If the GET fails, the step blocks with Retry and never falls back to the 11 legacy keys |
| `steps/StudioLayoutStep` | Preset cards with a `WebFrame` thumbnail each. Approving writes `layout.preset` and `layout.approved_at` |
| `preview/StudioPreviewPanel` | One tab per selected platform. Caption: "The apps look the same for every organisation; only colours, logo, name, tabs and menu change." |
| `preview/DeviceStage` | Measures **synchronously in `onMounted`**, then with a ResizeObserver, because rAF and ResizeObserver may not fire in a hidden pane |
| `preview/WebFrame` | 1280×800 or 390×844. Header and footer follow `theme_layout`. Sections come from the preview's `web` plan. A section with `has_renderer:false` becomes a red block; a missing logo becomes a red notice |
| `preview/IosFrame` | 393×852. Tabs are exactly `app.ios.tabs` |
| `preview/AndroidFrame` | 412×915. Tabs are exactly `app.android.tabs`, selected in `#00AA55` |
| `preview/TvFrame` | 1920×1080 on `#0F0F0F`, with the note "Events calendar arrives with the tvOS template (W2)" |
| `preview/PlatformContrastList` | Lists the `platform_contrast` rows, labelled advisory |

- **Logo rule in the SPA.** With web selected and no logo, Next is blocked. S8
  enforces the same rule on the server.

**Tests.**

- `StudioSpaSourceTest`:
  - no terminology labels and no worship keys appear as literals;
  - `IosFrame` reads `app.ios.tabs` and `AndroidFrame` reads `app.android.tabs`,
    with no hard-coded tab arrays;
  - the `IOS_MENU_TITLES` keys equal the `AppMenu::DEFAULT_REGISTRY` items, and
    the `IOS_TAB_TITLES` keys equal its tabs;
  - the autosave body never contains a `SECRET_KEYS` field;
  - both routes exist.
- `StudioFeatureStepSourceTest`: no config key or label literals; no `feature_keys`.
- `StudioLayoutStepLintTest`: no preset key, label or section type is typed in.
- `OnboardingVerticalPickerTest` also scans the Studio files. Its `WIZARD_VIEW`
  (`tests/Feature/OnboardingVerticalPickerTest.php:47`) is kept, because the
  wizard still exists.
- Build gates: `npm run build` is green, and `vue-tsc` adds no errors over the
  recorded baseline of 29 (`CLAUDE.md`, as the studio-ui recon cites it).

**Live impact.** None.

- The route is SuperAdmin-only, and the sidebar list is
  `SUPER_DASHBOARD_ASIDE_MENU`, which MasjidAdmins never load
  (`dashboardAsideMenuItems.ts:682`).
- `ThemeSettingsView` is not touched (R13).
- The wizard only imports two moved functions.

**Verify in production.** First, `ship.sh`'s own bundle check. Then, as a
SuperAdmin, at 1440 px and at 390 px:

1. New client creates a draft.
2. An SVG upload arrives as a PNG.
3. A reload resumes at the same step.
4. Two tabs editing the same draft: the second save shows the conflict notice.
5. Step 1 works for all three org types.
6. Step 2 renders frames for every vertical and preset.
7. `/dashboard/super/onboarding` still loads.
8. Discard the test draft.

**Size.** ~5 sessions, ~40 files (estimate). This is the largest slice.

---

### S6: Extract `OrganisationProvisioner` (MasjidWebMS, backend refactor)

**Goal.** One transaction body that both the legacy endpoint and the draft path
call. Nothing it produces changes.

**Contract.**

- `App\Support\Studio\OrganisationProvisioner::create(ProvisionMasjidRequest $request, array &$invitations, ProvisionContext $ctx): Masjid`.
  - It is the body of `OnboardingController.php:135-346` (as of bb60da7f), moved verbatim.
  - It throws `LogicException` when `DB::transactionLevel() === 0`.
- `OnboardingController::provision` keeps its signature
  `(ProvisionMasjidRequest $request, ?AccountAccessService $access = null)`, its
  try/catch and its response.
  - Why the signature must not change: `DemoSchoolSeeder` calls it directly
    (`app/Support/DemoSchoolSeeder.php:179-209`).
  - Its body becomes `DB::transaction(fn () => app(OrganisationProvisioner::class)->create(...))`.
- **Never wrap `provision()` in an outer transaction.** Its catch returns a 500
  instead of throwing, and it sends invites and flushes MobileCache after only a
  savepoint release (draft-and-logo risk [0]).

**Tests.**

- These must pass **unedited**: `ProvisionOrgTypeTest`,
  `OnboardingVerticalPickerTest`, `FormTemplateTest`, `DemoSchoolSeederTest`,
  `OrgTypeTest`.
- `OrganisationProvisionerTest::refuses_to_run_outside_a_transaction`.
- `ProvisionResponseSnapshotTest`: capture the fixture response before moving
  the code, then assert it is equal after.

**Live impact.** None. The path only creates new orgs, and its response is identical.

**Verify.**

- On staging, provision a throwaway org through the **old** wizard with the same
  inputs as a provision made before the ship. The rows must match.
- In production, `/dashboard/super/onboarding` loads and `/onboarding/options`
  answers. No production org is created just to test a refactor.

**Size.** ~1 session, ~4 files (estimate).

---

### S7: Cloudflare attach, honest without a token (MasjidWebMS)

**Goal.** D17: the machinery that attaches a hostname, which runs as a visible,
truthful no-op until the owner's token lands.

**Contract.** This follows domains-cloudflare §5–§7 and §9, with R4 and R24 applied.

- **`App\Services\Cloudflare\CloudflareService`** and **`CloudflareResult`**
  (readonly `{ok, outcome, data, error, http_status}`).
  - `outcome` is one of `ok, created, adopted, absent, conflict, not_configured, unauthorized, rate_limited, transient, rejected`.
  - Without a token, every method returns `not_configured` and **makes no HTTP request**.
  - Status mapping: 401 and 403 → `unauthorized`; 429 → `rate_limited`; 5xx or
    a timeout → `transient`; `success:false` → `rejected`.
  - Methods:
    - `findZone`, `createZone`, `getZone`;
    - `requestActivationCheck`, at most once per 6 h per row;
    - `ensureCname`: creates the record if absent; adopts a CNAME that already
      points at `pages_target`; any other record is a `conflict`, **never overwritten**;
    - `getPagesDomain`, `countPagesDomains`;
    - `ensurePagesDomain`: at the ceiling it returns `conflict`/`capacity`;
    - `retryPagesDomain`.
  - **There is no delete method, and the service never sends DELETE.** The token
    never appears in a log line or in `last_error`.
- **`App\Services\Domains\DomainAttacher::advance(MasjidDomain)`** is the state
  machine in domains-cloudflare §6. It runs under
  `Cache::lock('masjid-domain:'.$id, DomainAttacher::LOCK_SECONDS)`, 300 s:
  the slowest step's 8 Cloudflare requests at 15 s connect + 15 s total each,
  plus 60 s (derivation on the constant). Additions:
  - once the Pages status is active, the row becomes `active` with
    `verified_by = 'cloudflare'`; then `DomainProbe` runs, and a match sets
    `serving_confirmed_at`;
  - **without a token**, `advance()` sends nothing to Cloudflare, sets
    `waiting_on = token`, then probes (domains-cloudflare §6). A match moves a
    `pending` row to `manual` with `verified_by = 'probe'`, `verified_at` and
    `serving_confirmed_at`. This is how a host the owner attached by hand in the
    dashboard becomes live;
  - every probe match sets `serving_confirmed_at`; a later mismatch never clears
    it in W1 (§6);
  - `reserved` rows are never advanced;
  - `imported` and `manual` rows, when there is a token, are promoted by reads only.
- **Job `App\Jobs\AttachMasjidDomain`**: `ShouldQueue`, `$afterCommit = true`,
  `$tries = 1`. The queue driver is `database` (`config/queue.php:16`).
- **Command `domains:reconcile {--id=*} {--json}`.**
  - It selects:
    - `NON_TERMINAL` rows that are due;
    - `active` rows without `serving_confirmed_at`;
    - `manual`/`imported` rows, only when the token is configured.
  - Without a token and with rows waiting, it logs one `Log::warning` per hour,
    using a `Cache::add` marker.
  - It always exits 0.
  - It is scheduled with `->cron('3-59/5 * * * *')->withoutOverlapping(10)`, which
    avoids `:00/:15/:30/:45` and `:47`.
- **Admin routes** (super). The request is `StoreMasjidDomainRequest`
  (BaseFormRequest), and it accepts form-encoded or JSON bodies.
  - `GET /api/admin/masjids/{masjid_id}/domains`
  - `POST /api/admin/masjids/{masjid_id}/domains`
  - `POST …/{domain_id}/refresh` runs `advance()` synchronously. A `failed` row
    is reset to `pending` first. While another writer holds the row's lock it
    checks nothing and returns 409 ("try again in a moment"), the envelope
    DELETE uses for a held row.
  - `DELETE …/{domain_id}` returns 204 only when no `cf_*` id is set,
    `cf_zone_created` is false and `source` is not `imported` (R28). Otherwise it
    returns 409 with the manual removal steps.
- **`/api/admin/studio/domains/check`**, when the token is configured, adds
  `case` = `managed_subdomain | zone_in_account | zone_not_in_account | unknown`,
  plus `zone_status` and `pages_domains_used`.
- **SPA:**
  - `core/types/data/MasjidDomain.ts`;
  - `stores/super/masjidDomainsStore.ts`, which sends booleans as `'1'`/`'0'`;
  - `components/super/studio/StudioDomainAttachPanel.vue`, used by Step 3 in S8.
- **What the panel shows without a token:**
  - the host as text with a copy button, **not a link**;
  - an amber badge: "Waiting for the Cloudflare token: nothing has been sent to Cloudflare";
  - the server's `manual_steps` as a numbered list;
  - a "Check now" button, which calls refresh and so runs the probe;
  - "Open live site", disabled until `live_url` exists.
  - A green tick appears only for `active` with `serving_confirmed_at`, or for
    `manual` with `verified_at`. The second is labelled "Serving (confirmed by
    visiting it; Cloudflare not checked)".
- **Case 3 (a domain not yet on Cloudflare):** the panel shows the nameservers
  with two warnings: changing nameservers moves **all** of the domain's DNS,
  including MX, and a zone left pending for 28 days is deleted.
- **When the token arrives.** This is the owner's action, not a slice.
  1. The owner runs `scripts/set-server-secret.sh CLOUDFLARE_STUDIO_TOKEN` in
     their own terminal. The script writes through the `.env` inode and does not
     run `config:cache`.
  2. The value becomes readable as `config('cloudflare.studio_token')` after the
     next `scripts/ship.sh production`.
  3. Scopes: Account › Cloudflare Pages: Edit; Zone › Zone: Edit; Zone › DNS:
     Edit. Zone resources: all zones in account `86cec9c5…` (spec D17).

**Tests.**

- `CloudflareServiceTest` (`Http::preventStrayRequests`):
  - a blank token gives `not_configured` and `Http::assertNothingSent()`;
  - `ensureCname`: absent, matching (adopted), a conflicting A record, and the
    "already exists" race;
  - `ensurePagesDomain`: absent, present, and at capacity;
  - `createZone`: existing and absent;
  - the status mapping;
  - the DELETE verb is never used;
  - the token never appears in the log (`Log::spy`).
- `DomainAttacherTest`:
  - the managed happy path across two ticks;
  - Pages `pending` stays `provisioning`;
  - Pages `error` fails, with Cloudflare's message;
  - a DNS conflict fails with no write;
  - without a token, nothing is sent and `waiting_on = token`;
  - case 3 ends in `awaiting_nameservers`;
  - a zone pending for 28 days fails;
  - a 403 gives `waiting_on = token_scope`;
  - an imported row is promoted by GETs only;
  - `reserved` is never advanced;
  - `active` → probe → `serving_confirmed_at`;
  - a held lock makes `advance()` a no-op;
  - `without_a_token_a_probe_match_confirms_a_pending_row_as_manual_and_serving`;
  - `without_a_token_a_probe_mismatch_leaves_the_row_pending_and_unconfirmed`.
- `DomainsReconcileCommandTest`: the selection; one warning per hour; the
  schedule registration.
- `MasjidDomainsAdminRoutesTest`:
  - MasjidAdmin and Teacher get 401;
  - a form-encoded POST works;
  - a duplicate host gets the legacy 422;
  - DELETE's 409 and 204 cases;
  - `an_imported_row_cannot_be_deleted`;
  - `a_single_label_or_internal_suffix_host_is_refused`.
- `StudioDomainCheckTest`: the zone cases, using `Http::fake`.

**Live impact.**

- The imported rows for 1, 13, 14 and 18 are read-only to the attacher.
- `ensureCname` refuses any record it did not create, which protects the
  `burlingtonmasjid.com` zone and `alrazischool.org`.
- Without the token there is zero outbound HTTP to Cloudflare. The only
  outbound call is the probe of a row's own host.
- A row that carries `cf_*` ids, or was imported, cannot be deleted through Studio.
- The `POST …/domains` route can add a host to any org, a live one included. That
  is a deliberate SuperAdmin act that no W1 flow takes for 1, 13, 14 or 18, and
  `ensureCname` still refuses any record Studio did not create.

**Verify in production.**

- `php artisan schedule:list` shows `domains:reconcile` on `3-59/5`.
- As a SuperAdmin, read `GET /api/admin/masjids/13/domains` and note
  `cloudflare.configured`. Then take the matching branch:
  - **false:** `php artisan domains:reconcile --json` selects no rows and makes
    no HTTP call, and 13's rows are unchanged;
  - **true** (now, or whenever the token lands): the next reconcile promotes the
    imported `manual` rows to `active` by GETs only, `reserved` rows are
    untouched, and the owner confirms in the Cloudflare audit log that no write
    was made.

**As built (2026-09-24).** One additive column the plan did not name:
`masjid_domains.stage_started_at` (nullable timestamp), the start of the 28-day and
72-hour clocks. DECISIONS.md records it and the other calls S7 made.

**Size.** ~3 sessions, ~18 files (estimate).

---

### S8: Provision from draft, the web tenant, and Step 3 (MasjidWebMS, backend and SPA)

**Goal.** Approving Step 3 creates the org, its capabilities, theme, logo
assets, pages, domain rows and invite in one transaction (D7, D8). The attach
runs after commit.

**Preflight.** The §4 `settings.studio` count must be 0. If it is not, stop.

**Public read path (first, in the same ship).**
`app/Http/Resources/Api/V1/PageSectionResource.php:44` becomes
`'settings' => StarterPlaceholders::publicSettings($this->settings)`.

- It unsets `studio` only when that key is present, and returns `null` when
  nothing is left. Any other value is returned untouched.
- It ships in the deploy that adds the writer, before any row carries
  `settings.studio`, so its live check is clean. Why here and not in S4: it is
  the first change to a path every live renderer reads, and it has no use
  before the writer exists.

**`ProvisionMasjidRequest` gains optional keys.** When they are absent, the
behaviour is exactly today's.

| Key | Rules | Effect |
|---|---|---|
| `slug` | nullable; the DNS-label regex; not in `cloudflare.reserved_labels`; `unique:masjids,slug`; `{slug}.{managed_suffix}` not already a `masjid_domains.host` | Sets `masjids.slug` |
| `description` | nullable, string, max:300 | Sets `masjids.description` (public copy) |
| `capabilities` | `sometimes`, array; values coerced with `FILTER_VALIDATE_BOOLEAN \| FILTER_NULL_ON_FAILURE` in `prepareForValidation`, then `boolean`; keys must be ones the catalogue serves for this `org_type` (hidden or unknown → 422); `prohibits:crm_enabled,feature_keys,feature_keys_provided` | Runs `CapabilityWriter::applyAtCreation`, then `AppFeaturePivot::seedFromSwitches` |
| `layout_preset` | nullable, `Rule::in(LayoutPresets::keysFor(org_type))`; requires `web` in `platforms` | Runs `StarterSite::applyTo` and writes `theme_settings.tokens.layout` |
| `show_iqama_times` | `sometimes`, boolean (coerced) | Written instead of the hard-coded `true` default (`OrganisationProvisioner.php:118-127`) |
| `web_domain.custom_host`, `web_domain.custom_zone_apex` | nullable; the `HostName` rules, S3's write-side host rule and the apex rule; unique `masjid_domains.host` | Adds a custom domain row |

Every new boolean is coerced in `prepareForValidation`, because the wizard's
serializer posts `"true"`/`"false"` strings and the `boolean` rule rejects them
(`OnboardingWizardView.vue:1005`; `.claude/rules/shipping.md:15-38`).

The `: true` fallback at `OrganisationProvisioner.php:85-87` becomes
`config('capabilities.crm.provision_default', true)`; a sent `crm_enabled` is
still honoured, as today. The config fallback keeps today's value if the config
cache is stale.

**Order inside `OrganisationProvisioner`.** All of it runs in the one transaction.

1. The `masjids` row. `crm_enabled` is the sent value when the legacy request
   sends one, otherwise `provision_default`. The Studio path never sends it
   (`capabilities` prohibits it), so a Studio org is always born with
   `provision_default` and a CRM choice is a departure for step 2 (R26). Then
   every existing row, as today.
2. If `capabilities` is present:
   - `CapabilityWriter::applyAtCreation` compares each served key's desired
     value with `defaultAtCreation`, which is the state the org was just born
     with. A key that matches is skipped entirely: no override, no column write,
     no ledger row. A departure is written (the column for `crm`/`assistant`,
     otherwise the override) with one `CapabilityLedger` row and the SuperAdmin
     as actor;
   - then `AppFeaturePivot::seedFromSwitches` **replaces** the key-matched loop
     at `OrganisationProvisioner.php:186-192`.
   - If `capabilities` is absent, the existing loop runs unchanged.
3. `FormTemplates::applyTo`, which already runs at `OrganisationProvisioner.php:202`.
4. If `layout_preset` is present:
   - `StarterSite::applyTo` writes the pages, the sections (with
     `settings.studio`) and the `page_section` rows with `platforms` null;
   - it sets `masjid_id` explicitly, because `Page` and `Section` are
     hand-scoped (`tests/Feature/TenantScopingCoverageTest.php:150,154`);
   - it never updates or restores an existing row;
   - then `theme_settings.tokens.layout = preset.theme_layout`.
5. If `web` is in `platforms` and `slug` is set: a managed `MasjidDomain` row
   `{host: slug.'.'.managed_suffix, kind: managed_subdomain, zone_apex: hopetechapps.com, status: pending, source: studio}`,
   plus the optional custom row.

**`App\Support\CapabilityWriter::applyAtCreation(Masjid $new, array $desired, ?int $actor): array{changed, unchanged}`.**

- It has **no Giving guard**. The org is seconds old and inside an uncommitted
  transaction, so it cannot have a gift, a subscription or a checkout, and the
  guard may call Stripe (catalogue risk [9]).
- It flushes `MobileCache::flushFamily` through `DB::afterCommit`.
- It never touches the pivot.
- The guarded `apply()`, the bulk `PATCH` and `setCapability` delegating to it
  are W2 (§6).

**`AppFeaturePivot::seedFromSwitches(Masjid)`** writes one pivot row per
feature id, from `rowsFor()`. It throws if the org already has pivot rows.

**`App\Support\Studio\StudioProvisioning::provision(StudioDraft $draft, array $secrets): Masjid`.**

*Before the transaction:*

1. Build the request with
   `ProvisionMasjidRequest::create('/api/admin/onboarding/provision','POST',$draft->toProvisionPayload($secrets))`,
   then call `setContainer`, `setRedirector` and `validateResolved`. This is the
   `DemoSchoolSeeder.php:189-197` pattern, so the rules are the direct POST's,
   byte for byte. `show_iqama_times` is `prayer.iqama_given`.
2. `StudioBrandGate::assert`. Each failure is a 422 keyed like the request or as
   `brand.<field>`:
   - each of the four colours is exactly `#RRGGBB`;
   - every blocking `PaletteReport` pair passes;
   - if `web` is selected: a logo, a slug, and an approved layout
     (`layout.preset` with `layout.approved_at`) are all present (R27).
3. `LogoDerivatives::generate` writes to
   `storage/app/private/studio-tmp/{draft}-{rand}/`. It uses spatie/image 3.9.5
   on GD (`config/media-library.php:185`), and writes no ICO and no SVG.
   - favicon: 48×48, logo contained, transparent padding;
   - touch icon: 180×180, logo contained in 144×144 on an opaque `background_color`;
   - share image: 1200×630 `background_color` canvas, logo contained in 720×360 at the centre.

*The transaction:*

1. Lock the draft with `lockForUpdate`. If its status is not `draft`, throw
   `StudioDraftConflict` (409).
2. `OrganisationProvisioner::create`.
3. `ApplyDraftBrand` merges `tokens.color.on*` from `palette.tokens` with `tokens.layout`.
4. `ApplyDraftLogo` calls `addMedia($abs)->preservingOriginal()` into `logos`,
   then adds the three derivatives to `favicons`, `touch_icons` and
   `share_images`. **`preservingOriginal()` is mandatory:** `FileAdder` deletes
   the source otherwise, and a rolled-back attempt could not be retried
   (`vendor/spatie/laravel-medialibrary/src/MediaCollections/FileAdder.php:605-627`).
5. Mark the draft `provisioned`, with `provisioned_masjid_id` and `provisioned_at`.

*On any `Throwable`:*

- Delete the directory of every Media row created. Media rows are saved before
  their files are copied, and a DB rollback does not remove files.
- Delete the temp directory.
- Rethrow.

*After commit:*

1. Send the invites.
2. `MobileCache::flushGlobal(MASJIDS_LIST)`.
3. `AttachMasjidDomain::dispatch` for each domain row.
4. Delete the draft's private logo bytes. Its logo metadata stays.
5. Delete the temp directory.

Each after-commit step runs in its own `try`. A failure is logged at warning and
reported in the 201 body's `after_commit`; it never turns a committed provision
into a 500. Why: a 500 invites a retry of something that already happened, and
the results screen must not show an invite as sent when it was not.

**Model and payload changes.**

- `Masjid` gains `favicon()`, `touch_icon()` and `share_image()`:
  `hasOne(Media::class,'model_id')->where('model_type', self::class)->where('collection_name', …)->latest()`,
  mirroring `logo()` (`app/Models/Masjid.php:614-620`). **The `model_type`
  filter is required.**
- `SettingController::index` (`app/Http/Controllers/Api/V1/SettingController.php:23-39, :67-72`)
  adds `favicon_url`, `touch_icon_url` and `share_image_url` **only when the org
  has that row** (R11).
- `OrganizationByHostController` fills `favicon_url` and `share_image_url` from
  those collections only, and **never falls back to `logos`**. Otherwise Burlington's
  and MEC's tab icons would change unasked (draft-and-logo cross-slice rule F).

**Route.** `POST /api/admin/studio/drafts/{draft_id}/provision`

- Body: `{secrets?: {ios?:{asc_key_p8, asc_key_id, asc_issuer_id}, android?:{play_service_account_json}}}`.
- 201: `{status:'success', data:{masjid_id, masjid, app_publishing (the exact /onboarding/provision shape), draft_id, brand_assets:{logo_url, favicon_url, touch_icon_url, share_image_url}, capabilities_applied:{changed, unchanged}, starter_site:{preset, created, skipped, sections_active, sections_inactive, placeholders_open}, web:{host, status, waiting_on, live_url, manual_steps}, domains:[…], after_commit:{invites_sent:int, invites_failed:int, warnings:string[]}}}`.
- 422: legacy envelope.
- 409: `{status:'conflict', data:{draft_id, provisioned_masjid_id}}` when the
  draft is already provisioned, so the SPA can show what exists instead of an error.
- 500: `Errors::publicMessage`, only for a failure before or inside the transaction.

**SPA Step 3 (`StepGenerate`).**

- `ReviewGrid`, from wizard `:443-477`.
- `ByoCredentialsFields`, only for `byo` platforms. It is in-memory only and never autosaved.
- The Provision button. It is disabled until the web gate's three items are
  present (R27), and from the click until the response arrives.
- The results:
  - organisation ✓;
  - capabilities (n changed);
  - pages: created, and inactive sections with their hints;
  - invite ✓ only when `after_commit.invites_sent > 0` and
    `invites_failed == 0`; otherwise the warning, in words;
  - web address: `StudioDomainAttachPanel`.
- If a response lacks `capabilities_applied`, the SPA shows an error instead of
  success. Why: an older backend would ignore the unknown key and seed defaults
  (catalogue risk [2]).
- A provisioned draft is read-only. A 409 reloads the draft and shows the
  provisioned org; it never offers a retry.

**Decisions this slice records in `DECISIONS.md`.**

- **Two ledger policies.** The single switch still ledgers a no-op
  (`CapabilityChangeLedgerTest:91-97`), while `applyAtCreation` ledgers only
  departures from the defaults.
- **Iqama.** On the Studio path, iqama is displayed only when the client gave
  times. The legacy invented schedule of 20/10/10/5/10
  (`OrganisationProvisioner.php:118-127`) stays only on the legacy path.
- **Donation labels.** The generic donation labels "Donation Link" / "Donate
  Now" (`OrganisationProvisioner.php:140-141`) stay: they are interface words,
  not facts about the congregation, and D8's list is history, scholars,
  programmes and numbers.
- **Jumu'ah.** The Jumu'ah default of 13:30 (`:209`) is still stored. The web
  does not draw it (layouts fact [30]), and W2/W3 must not show it unless it was supplied.

**Tests.**

- `StudioProvisionParityTest`: a draft and a direct POST produce the same rows
  and the same response shape.
- `StudioProvisionLogoTest`:
  - `$masjid->logo` exists;
  - `getimagesize` gives 48×48, 180×180 and 1200×630;
  - the settings payload returns the three URLs;
  - the draft's private bytes are gone.
- `StudioProvisionRollbackTest`: a throwing step leaves:
  - no masjid and no media rows;
  - an empty public disk;
  - the draft intact, with its logo;
  - no invite mail sent.
  A retry then succeeds.
- `StudioProvisionConflictTest` (a draft must never provision twice):
  - `a_second_provision_of_the_same_draft_is_a_409_naming_the_first_masjid`:
    afterwards there is exactly one masjid, one set of domain rows, one set of
    pages and one invite;
  - `a_draft_provisioned_after_the_checks_is_refused_under_the_lock`: bind a
    `LogoDerivatives` fake that marks the draft provisioned while it runs
    (between validation and `lockForUpdate`); expect 409 and zero new masjids;
  - `an_after_commit_failure_returns_201_leaves_the_draft_provisioned_and_a_retry_is_a_409`:
    make the invite throw; expect 201 with `after_commit.invites_failed = 1`,
    then a second call answers 409.
- `StudioBrandGateTest`:
  - `#777777` background → 422;
  - an alpha hex is a 422 in Studio but still accepted by the legacy endpoint;
  - web without a logo → 422, web without a slug → 422, and web without an
    approved layout → 422, each creating nothing;
  - Burlington's green auto-inks to `#111827`.
- `StudioProvisionThemeTest::tokens_carry_both_the_auto_ink_and_the_preset_layout`:
  `ApplyDraftBrand` must merge, so `theme_settings.tokens` holds `color.on*` and
  `layout` together, and the served `theme.tokens.layout` equals the preview's
  `theme_layout`.
- `StudioProvisionCapabilitiesTest`:
  - `a_full_map_with_every_key_at_its_default_writes_no_override_and_no_ledger_row`:
    send every served key at its `default_at_creation`, as the SPA does (R9);
    expect `capability_overrides` null, `crm_enabled` unchanged, 0 ledger rows
    and `capabilities_applied.changed = []`
  - `departures_are_stored_and_ledgered_with_the_super_admin_as_actor`: exactly
    one ledger row per departure, none for the rest of the map
  - `crm_chosen_off_is_born_dark_and_says_so_in_the_ledger` (R26)
  - `the_app_drawer_rows_are_derived_from_the_chosen_switches`
  - `the_derivation_goes_by_id_so_the_production_quran_key_is_found`
  - `a_studio_org_has_no_blocking_cutover_finding`
  - `capabilities_cannot_be_sent_with_the_legacy_feature_fields`
  - `a_hidden_or_unknown_key_creates_no_organisation`
  - `the_multipart_strings_true_and_false_are_read_as_booleans`
  - `the_response_echoes_what_was_applied`
- `StarterSiteWriterTest`:
  - no preset → no pages;
  - menu order;
  - a minimal draft publishes only fact-backed sections;
  - a module that is off writes no section;
  - facts are copied verbatim;
  - placeholders are recorded in `settings.studio`;
  - the admissions form is written inactive;
  - a rerun never overwrites;
  - a failure rolls everything back;
  - a preset for another vertical, or without web, is refused;
  - the multipart form encoding works.
- `StarterSitePublicPayloadTest` (moved here with the strip):
  - `live_shaped_sections_serialize_identically`: settings `null`, `{}`,
    `{bind:'about_text'}` and `{bind:'mission_vision_cards'}`, compared decoded;
  - `the_public_pages_payload_omits_inactive_placeholder_sections_and_never_contains_studio_or_template_markers`
    (layouts tests).
- `StudioStarterSiteServedProvenanceTest::every_string_the_public_api_serves_for_a_studio_org_is_provenanced`.
  This is the end-to-end no-invention check, because `SectionContentBinder`
  rewrites content at serve time (`app/Support/SectionContentBinder.php:67-83`)
  and the plan-level lint cannot see that.
  - Provision each preset twice, with the minimal and the maximal sentinel-fact
    fixtures from S4 (for example `name = 'FACT-NAME-7f3a'`).
  - Walk `GET /api/v1/pages` and each `/pages/{slug}` as served.
  - Every non-empty string in each page's `title`, `page_title` and
    `meta_description`, and in each section's `title` and `content`, must be one
    of: a sentinel fact or its `tel:`/`mailto:` derivation; a value from the
    pinned label table; a page path of the preset; a STRUCTURAL value; a URL the
    org's own media produced; the binder's own labels
    (`SectionContentBinder.php:430, 439`); or a provisioner default this slice
    records in `DECISIONS.md` (the donation labels, below).
  - Anything else fails, and needs a `DECISIONS.md` entry before it may be allowed.
- `StudioLayoutPreviewTest::preview_equals_what_provision_then_writes`.
- `StudioPreviewParityTest`: `/menu` tabs and sections equal `preview.app.ios`,
  and `/features` ids 6, 10 and 11 equal `preview.app.android.tabs`.
- `ProvisionAttachesDomainTest`:
  - exactly one managed row;
  - rollback leaves no row and no job;
  - the job is dispatched after commit;
  - with the token blank, `data.web.status` is `pending` and `waiting_on` is
    `token`, with no HTTP call.
- `ProvisionIqamaTruthTest`: the Studio path with `iqama_given=false` gives
  `show_iqama_times=false`; the legacy path is unchanged.
- `LiveSettingsPayloadUnchangedTest`: an org with `logos` and no derivatives
  keeps exactly its previous key set.
- `OrganizationByHostTest::favicon_comes_from_favicons_never_logos`.
- These must pass **unedited**: `ProvisionOrgTypeTest`,
  `OnboardingVerticalPickerTest`, `FormTemplateTest`, `OrgTypeTest`,
  `DemoSchoolSeederTest`, `CapabilitiesEndpointTest`, `WorshipAppModulesTest`,
  `ModulesFailOpenTest`, `CapabilityTsMirrorTest`, `FamilyAuthGuardTest`,
  `CapabilityChangeLedgerTest`.

**Live impact.**

- The legacy `/onboarding/provision` is identical when the new keys are absent,
  and this is pinned.
- `/api/v1/pages` and `/pages/{slug}` take a new code path for every live
  renderer. The output is identical, because no code has ever written
  `settings.studio` (layouts live impact 1) and the preflight count proves it.
- `/api/v1/settings` is identical for every org without derivative rows, which
  is every live org.
- Only new orgs get rows.
- **Until S11, a Studio org's site does not render.** The renderer's lookup is
  off, so its host answers 404 and the panel stays unconfirmed. The org, pages
  and invite are real. Walk a real client before S11 only if the owner wants the
  org before the site.

**Verify.**

- In production, run ABI on `/api/v1/settings`, `/api/v1/pages` and every
  `/pages/{slug}` for 1, 13, 14 and 18.
- On staging, do the full Studio walk, including a throwaway provision. With the
  token absent, the panel shows `pending` / `waiting_on: token` with the manual
  steps, and "Open live site" is disabled.
- In production, provisioning is proven by the first client walk (§0). No
  throwaway org is created in production, so the counts stay clean (D7).

**Size.** ~4 sessions, ~30 files (estimate).

---

### S9: CORS and payment-return origins from `masjid_domains` (MasjidWebMS)

**Goal.** The permanent fix for the static origin allowlists. A new client
domain works in the browser the moment its row is confirmed serving, with no
`.env` edit.

**Facts, verified live 2026-09-24.**

- CORS is a static env allowlist (`config/cors.php:22-24`).
- `mec.manara.hopetechapps.com` and `alrazi.manara.hopetechapps.com` are
  **blocked**: they get no `Access-Control-Allow-Origin` on GET or on the POST
  preflight.
- `burlingtonmasjid.com`, `www.`, `sundayschool.burlingtonmasjid.com`,
  `mec-web.pages.dev` and `alrazischool.org` are allowed.
- That was the state the recon read. The one-line hotfix adding the two blocked
  origins **landed on 2026-09-24** (production `.env` backup
  `.env.bak-20260924T025936Z-445611-cors`); `CORS_ALLOWED_ORIGINS` now holds 11
  origins, both included. `FORMS_PAYMENT_RETURN_ORIGINS` is a second static list
  (`config/forms.php:109-132`) and still holds only
  `https://sundayschool.burlingtonmasjid.com`, so card payment on a form fails
  closed on every other host today. That is by design, not an S9 defect.

**Preflight.** Do the §4 read of both env lists, and record whether the hotfix has landed.

**Gate.** The hotfix has landed, so S9 ships as an invisible change and is
verified by the "If the hotfix has landed" branch below. Re-read both lists in the
preflight anyway: if either differs from §8 OQ6, stop and re-plan the gate.

**Contract.**

- **`App\Http\Middleware\HandleCorsWithDomains extends Illuminate\Http\Middleware\HandleCors`.**
  - Registered in `bootstrap/app.php` with
    `$middleware->replace(\Illuminate\Http\Middleware\HandleCors::class, HandleCorsWithDomains::class)`.
    `HandleCors` is in the default global stack
    (`vendor/…/Foundation/Configuration/Middleware.php:458`), and the app has
    not replaced it (`bootstrap/app.php:86, :89`).
  - `handle()`:
    - if `config('cors.allowed_origins')` contains `'*'`, change nothing;
    - if the path does not match `cors.paths` (`hasMatchingPath`), the request
      has no `Origin`, or its `Origin` is already in the static list, call
      `parent::handle` with **no table or cache read**. Why: this runs on every
      request, the mobile apps and the renderer's SSR send no `Origin`, and the
      production cache store is the database;
    - otherwise set, for this request only,
      `config(['cors.allowed_origins' => array_values(array_unique([...static, ...MasjidDomain::corsOrigins()]))])`,
      then call `parent::handle`. `HandleCors` re-reads `config('cors')` on every
      request (`HandleCors.php:64`).
    - On any `Throwable` while reading origins: `Log::warning`, then the static
      list only. **Never a 500.**
  - `corsOrigins` covers `corsAdmitted()` rows only (R3): `active` or `manual`
    with `serving_confirmed_at` set, org not trashed. `pending`, `provisioning`,
    `awaiting_nameservers`, `reserved` and `failed` admit nothing.
  - `config/cors.php:34` has `supports_credentials` false (domains-cloudflare
    fact [0]), so an admitted origin can read responses but never with cookies.
- **Payment return.** `App\Support\FormPaymentReturn::allowedOrigin`
  (`FormPaymentReturn.php:82-96, :118-125`) also accepts `'https://'.host` of a
  **`corsAdmitted()` row whose `masjid_id` equals the form's masjid**. Why the
  stricter scope matters most here: Stripe sends a payer who just paid to this
  origin.
  - The env list is unchanged, and the check still fails closed.
  - `base()` gains the form's masjid id and passes it to `allowedOrigin`. Its
    two callers change: `FormSubmissionsController.php:360` and
    `FormResponsePaymentsController.php:87`.
- **`TrustedHosts` is unchanged.** Client hostnames reach Laravel only as
  `Origin`, never as `Host` (`docs/tenant-host-map.md:84-89`; `TrustedHosts.php:120-134`).

**Tests.**

- `CorsDomainOriginsTest`:
  - a preflight from a new origin gets no ACAO until its row is confirmed
    serving, then gets it;
  - `a_pending_provisioning_or_awaiting_nameservers_row_admits_nothing`;
  - `an_active_row_without_serving_confirmed_at_admits_nothing`;
  - a failed or reserved row admits nothing;
  - `a_trashed_orgs_confirmed_row_admits_nothing`;
  - `a_request_with_no_origin_or_a_static_origin_reads_neither_the_table_nor_the_cache`
    (query log and cache spy);
  - the static origins are still admitted, with the same `Vary` header;
  - `['*']` is unchanged;
  - a DB error falls back to the static list with a 2xx and a logged warning;
  - the cache key is forgotten on save.
- `TrustedHostsIgnoresDomainsTest`.
- `FormPaymentReturnDomainTest`: an origin is accepted for its own masjid's form
  and refused for another masjid's; a pending or unconfirmed row's origin is
  refused for its own masjid's form too.
- `FormPaymentCheckoutTest` passes **unedited**.

**Live impact.**

- **If the hotfix has landed,** the merged list equals today's for every live
  origin, so nothing a live tenant sees changes.
- **If it has not,** S9 **is** the fix, and it must be announced as a fix, not
  shipped as invisible. MEC's and Al-Razi's browser calls start working:
  - event pagination (`renderer:app/components/section/event/Paginated.vue:30-39`);
  - form POSTs (`Form.vue:1157`);
  - the offering re-read (`Offering.vue:1303`).
- The same applies to card-payment returns to those two hosts, for their own
  forms only, if production's `FORMS_PAYMENT_RETURN_ORIGINS` lacks them.

**Verify in production.**

1. For each origin, send an OPTIONS preflight and a GET to `/api/v1/settings`
   with that `Origin`:
   - `https://burlingtonmasjid.com`, `https://www.burlingtonmasjid.com`,
     `https://sundayschool.burlingtonmasjid.com`, `https://mec-web.pages.dev`,
     `https://alrazischool.org`: ACAO and `Vary` exactly as recorded before;
   - `https://mec.manara.hopetechapps.com`, `https://alrazi.manara.hopetechapps.com`:
     ACAO echoed;
   - `https://example.org`: no ACAO.
2. In a browser on `mec.manara` and `alrazi.manara`, open an events page with
   pagination and a form page. The console shows no CORS errors.

**Size.** ~1.5 sessions, ~8 files (estimate).

---

### S10: Renderer runtime lookup, shipped dark (renderer; `main` → `manara-renderer`)

**Goal.** Put the lookup code in production with the flag off. Every live host
keeps resolving statically, exactly as today.

**Preflight.** Do the §4 reads (the deployed commits, and `NUXT_TENANT_HOSTS`),
plus OQ4 (whether headers are writable under workerd).

**Contract.** This follows renderer-lookup §B, with R5 applied.

- **`nuxt.config.ts`:**
  - `runtimeConfig.tenantLookupEnabled: false`. Setting
    `NUXT_TENANT_LOOKUP_ENABLED=true` turns it on.
  - An exported `apiBaseUrlFrom(config)` =
    `config.public.apiBaseUrl ?? 'https://masjid.hopetechapps.com/api/v1/'`,
    used by both `useApi` and the lookup, so the two can never point at
    different backends.
  - The cache rule's `varies` becomes `["host","x-forwarded-host","x-manara-tenant-key"]`
    (today it is exactly two; rule at `:113-117`, pinned by
    `tests/payload-isolation.test.ts:44`).
- **`shared/tenant.ts`:**
  - `match: 'exact'|'wildcard'|'lookup'`.
  - `LOOKUP_TENANT_ID_RE = /^[1-9][0-9]{0,9}$/`. Why: the id is interpolated
    into URL paths (`app/composables/useApi.ts:72-78`).
  - `LOOKUP_EXCLUDED_SUFFIXES = ['.pages.dev','.workers.dev']`.
  - `isLookupEligibleHost`.
  - `lookupCandidate(resolvedHost, rawHostHeader)`: a lookup happens only when
    the raw `Host` normalises to the resolved host.
  - `tenantRecordFromLookup(data, requestedHost)`:
    - requires the host echo and a valid id;
    - maps `masjid_id`→`id` and `name`;
    - maps `favicon_url`→`favicon` and `share_image_url`→`shareImage`, each only
      when it is `https`;
    - keeps a non-empty `description`;
    - drops every other key.
  - `classifyLookupResponse`.
- **`shared/tenantLookup.ts`** is pure, with its I/O injected.
  - Constants:

    | Constant | Value |
    |---|---|
    | `MEMO_FOUND_TTL_S` | 300 |
    | `MEMO_ABSENT_TTL_S` | 60 |
    | `MEMO_FAILED_TTL_S` | 10 |
    | `MAX_MEMO_HOSTS` | 512 |
    | `LOOKUP_TIMEOUT_MS` | 2000 |
    | `TENANT_LOOKUP_KV_PREFIX` | `'manara:tenant-host:v1:'` |
    | `KV_FOUND_TTL_S` | 86400 |

  - Order of resolution:
    1. The memo.
    2. In-flight coalescing: one request per host.
    3. The API.
  - On **found**: write the memo, then write KV **only if** it holds no record
    for this host or holds a different one. Resolve with source `api`.
  - On **absent**: memo for 60 s. Then read KV for this host, and if it holds a
    found record, delete it (`store.remove`, backed by unstorage's `removeItem`).
    No other KV write. The result is `unknown`. Why: otherwise a trashed or failed
    host's last record survives 24 h and is served as `stale` in the next outage.
  - On **failed**: read KV for **this** host. A found record resolves with
    source `stale`; otherwise the result is `unavailable`, memoised as failed for 10 s.
  - The KV value is `{v:1, host, state:'found', record, checkedAt}`. An invalid
    value counts as a miss, never as a tenant.
  - `TenantLookupStore` is `{get, set, remove}`: renderer-lookup §B3's interface
    plus `remove`.
- **`server/utils/tenant.ts`: `resolveTenantForRequest(event)`.**
  1. Check the static map first. On a hit it returns **before any `await`**,
     with the same object as today.
  2. If the lookup is disabled, return `unresolved`.
  3. If `lookupCandidate` returns null, return `unresolved`.
  4. Otherwise run the lookup.
  - It logs `console.error` when the lookup is on and the map contains wildcards.
  - `resolveTenantFromEvent` is kept.
- **`server/middleware/tenant.ts`** becomes async.
  - Before any `return`, always overwrite the request header
    `x-manara-tenant-key` with `t{id}` or the status.
  - The response header `x-manara-tenant` is the id, `unresolved` or `unavailable`.
  - `x-manara-tenant-source` is sent only when the source is not the static map.
  - `unavailable` answers 503 with `Retry-After: 30`, `Cache-Control: no-store`
    and a fixed neutral body.
- **`useTenantHead`** emits `og:image` only when `tenant.shareImage` is set.
  Static-map tenants have none, so their head is unchanged.
- **`tests/fixtures/host-normalization.json`**: a byte-identical copy of the
  MasjidWebMS fixture. Compare the two `sha256` values in the PR.
- **`scripts/rbi-capture.mjs`**: §3.3.

**Tests.**

- `tests/tenant-lookup.test.ts`:
  - parity with the static map comes first: every live host, with the lookup ON
    and a fetcher that would answer id 99, makes 0 fetches and 0 KV calls, and
    Burlington still serialises to `{"id":"1","host":"www.burlingtonmasjid.com","match":"exact"}`;
  - with the lookup disabled there are 0 fetches;
  - a 404 makes no KV write when KV holds nothing for the host, and is memoised
    for 60 s;
  - `a_404_deletes_the_found_record_kv_still_holds_for_that_host`;
  - a found record is written to KV once, and a second found with the same
    record makes 0 writes;
  - stale-if-error;
  - the X-Forwarded-Host gate;
  - eligibility;
  - coalescing, and the memo cap.
- `tests/tenant-lookup-isolation.test.ts`: **an unknown host never renders
  another tenant.**
  - Each failure mode gives `tenant: null` with no KV write: a throw, a timeout,
    500/502/503/429/301, an HTML 200, `{data:null}`, a host echo mismatch, and
    the bad ids `'1/../13'`, `0`, `-1`, `1.5`, `'abc'` and `12345678901`.
  - A fuzz run of 2,000 random hosts against a fetcher that always answers
    Burlington resolves zero of them.
  - KV poisoning is ignored.
  - `a_host_the_api_now_answers_404_for_is_never_served_from_kv_again`: KV holds
    a found record for H, the API answers 404, then every later fetch fails;
    the result stays `unknown`/`unavailable` and never resolves H's old tenant.
- Source-level config tests: the exact `varies` list; the flag defaults to
  false; the tenant key is assigned before the first `return`.
- The two existing tests that pin the old behaviour are updated **on purpose**:
  `tests/payload-isolation.test.ts:44` and `tests/page-cache-build-key.test.ts`.
- `tests/tenant-unchanged.test.ts` stays green **unedited**.
- **A workerd integration run before merging** (`wrangler pages dev` against a
  stub API):
  - an unknown host with the stub answering 404 → 404;
  - the stub answering 500 → 503, not cached;
  - the stub answering id 21 → 21;
  - switch the stub to 22 and delete the KV key → the next request renders 22.
    That proves the tenant is part of the page-cache key.

**Live impact.**

- With the flag off, unknown hosts behave as today: a 404 SiteNotFound
  (`renderer:app/app.vue:15-27`).
- Every live host resolves on the static path, with zero fetches and zero KV
  calls; a spy test proves it.
- The `varies` change re-keys each (host, path) page once. That is one cold
  render, the same as every deploy's build-id re-key, and the HTML is unchanged.
- `mec-web` is not redeployed.

**Ship.** Merge to `main`, which deploys `manara-renderer`. Do **not** merge
into `cloudflare-migration`.

**Verify in production.**

- RBI (§3.3) on every host.
- `manara-renderer.pages.dev` still returns 404 with `unresolved`.
- No live host sends `x-manara-tenant-source`.

**Rollback.** The Pages rollback.

**Size.** ~3 sessions, ~10 files (estimate).

---

### S11: Switch the lookup on (renderer env)

**Goal.** Adding a client no longer needs a renderer deploy (spec D17).

**Pre-gates.**

1. The production `NUXT_TENANT_HOSTS` holds **no wildcard**. A wildcard such as
   `*.manara.hopetechapps.com` would render every new Studio subdomain as that
   tenant (renderer-lookup risk [0]).
2. The S3 import is done, and S9 is shipped.
3. The X-Forwarded-Host `curl` result from §4 is recorded.
4. The canary host exists (OQ10). Creating it is a public production hostname,
   so it needs the owner's go.

**Steps.**

1. Set `NUXT_TENANT_LOOKUP_ENABLED=true` on `manara-renderer-staging` and verify there.
2. Then set it on `manara-renderer`. That is an env change plus a redeploy, and
   it needs the owner's go, as every production change does.
3. **Redeploy the exact commit S10 shipped** (retry that deployment), not a
   fresh build of `main`'s head. If `main` has moved since S10, ship and RBI that
   commit first as its own change. Why: otherwise RBI measures two changes at
   once and cannot say which one moved a byte.

**Verify in production.**

- RBI: the live hosts are unchanged, and none sends a source header.
- The canary host:
  - `/api/tenant` returns `id == masjid_id` with `match:'lookup'`;
  - `x-manara-tenant-source` is `api` on the first request and `memo` after it.
- `manara-renderer.pages.dev` still returns 404.
- The Laravel access log shows by-host calls only for the canary.

**Size.** ~0.5 session (estimate).

---

### S12: Rename and retire the old wizard (MasjidWebMS, SPA)

**When.** After the first Studio client is live.

**Contract.**

- `superDashboardRoutes.ts`: `{path:'onboarding', redirect:'/dashboard/super/studio'}`
  replaces the `masjid.onboarding` block (`:101-111`). Nothing else references
  that name.
- The sidebar keeps a single entry, "Manara Studio" (`:705-714`).
- Delete `OnboardingWizardView.vue`.
- Retarget `OnboardingVerticalPickerTest`'s `WIZARD_VIEW` (`:47`) to the Studio
  files. Keep its three positive assertions: `d.verticals`, `default_org_type`
  and `org_type: form.org_type`.
- The backend `/onboarding/options` and `/onboarding/provision` routes stay.
  `DemoSchoolSeeder` and cached bundles use them.

**Tests.**

- `StudioSpaSourceTest` routing: the redirect is present, and the sidebar title
  is "Manara Studio".
- `OnboardingVerticalPickerTest`, retargeted.
- The build gates.

**Verify in production.** `/dashboard/super/onboarding` redirects to
`/dashboard/super/studio`, and the sidebar has one entry.

**Size.** ~0.5 session (estimate).

---

### Exit walk (not a slice)

Walk the first real client, with the owner:

1. Studio, Steps 0–3.
2. Provision.
3. Attach the hostname. With the token this is automatic. Without it, the owner
   follows `manual_steps` in the Cloudflare dashboard, and "Check now" confirms
   by probe.
4. `live_url` opens.
5. The client's admin receives the invite.
6. RBI and ABI show the four live tenants unchanged.

---

## 6. What W1 does not do

- **No apps are generated.** Studio does not call `provision-apps`.
  - D9 (a OneSignal app per client) is not in W1. Landmine 1 still exists; W1
    does not trigger it, because it ships no app.
  - D9 must land before the first Studio-generated app (W2 or W3).
- **tvOS (D11) is W2.** W1 draws only a preview frame. `MasjidTV` does not draw
  an events calendar today (studio-ui fact [26]).
- **Repos, MasjidKit, the standalone web export, the source download and the
  store toggles are W3** (D2, D3, D5, D10).
- **No LLM-written copy (D4).** W1 writes facts and interface labels only, and
  the default preset comes from config.
- **Existing orgs are not edited.** W2 candidates:
  - the bulk `PATCH /api/admin/masjids/{id}/capabilities`;
  - `setCapability` delegating to a guarded `CapabilityWriter::apply()`;
  - regenerating brand assets for an existing org;
  - Studio opening a live org.
- **The MasjidAdmin placeholder checklist** (layouts §E/§F: a new endpoint, plus
  badges in `PagesView`, `PageSectionsView` and `SectionFormModal`) and the
  `studio:apply-layout` command are W2. Both touch live admin screens. W1 shows
  the open placeholders in Studio's Step 3 report, and the client sees the
  inactive sections in their own page builder.
- **Arabic starter labels, and a website-locale field in the lookup, are W2.**
  An Arabic-first client stays on the static map, which needs a renderer
  deploy, until then.
- **Hostname lifecycle:**
  - detaching or removing a hostname is not in W1;
  - neither is cleaning up Cloudflare records when an org is force-deleted
    (domains-cloudflare risk [11]).
- **Case 3 for a live client's domain is not run without a go.** Creating the
  zone for MEC's `meccharlotte.org` moves MEC's whole DNS, email included.
  Nothing runs without the owner's and MEC's go (`renderer:nuxt.config.ts:28-29`).
- **No apex↔www canonical redirect policy** for new client domains (domains-cloudflare unknown [6]).
- **The static env lists are not retired.** `CORS_ALLOWED_ORIGINS` and
  `FORMS_PAYMENT_RETURN_ORIGINS` stay; the table only adds to them.
- **Confirmed hosts are not re-probed.** Once `serving_confirmed_at` is set, a
  host that later stops serving keeps its CORS and payment-return admission
  until its row changes. Periodic re-confirmation is W2.
- **Imported rows are frozen.** Studio can neither delete nor re-point the live
  tenants' rows (R28); changing them is a W2 tool with its own review.
- **The Pages custom-domain ceiling is not addressed.** The spec estimates ≈47
  more two-host clients on Free, a decision for when there are forty.
- **Live orgs' pivot/switch disagreements are not fixed.** The S2b cutover owns
  them (catalogue live impact 2).

---

## 7. Observed, out of scope

These are not W1 work. Each needs its own ticket.

- `/api/v1/settings` returning `google_maps_key` to unauthenticated callers
  (`SettingController.php`) is **intended, not a leak**. The renderer
  (`app/utils/mapEmbed.ts`, `MasjidMap.vue`, `getGoogleMapsKey`) loads a
  tenant's styled Maps JavaScript API map with it; only org 1 (Burlington)
  sets one on production. Removing it would drop Burlington's live map to the
  keyless embed. The exposure is wider than the rendered site: the endpoint is
  anonymous, serves whichever tenant the caller's `masjid-id` header names,
  and masjid ids are public, so anyone can collect every tenant's key, even a
  tenant whose website is off or not yet live (the endpoint does not check the
  `website` module). The key's Google Cloud restrictions are therefore the
  only protection for every tenant that sets one. The mobile directory still
  denylists it (`PublicMasjidDirectoryTest`), and `WebsiteSettingsMapsKeyTest`
  pins only that the header selects the row: each tenant id gets that
  tenant's key, never a neighbour's. The remaining action is the owner's:
  check in Google Cloud that every stored key is restricted by HTTP referrer
  and to the Maps JavaScript API. Serving the key only when the tenant's
  `website` module is on would narrow the exposure; it is not done here.
- **Fixed on `fix/quran-key-and-maps-key-note`:** the legacy wizard gave new
  masjids Qur'an OFF on production. The production key is `qur’an` (U+2019),
  and both the wizard and `OnboardingController@provision` matched features by
  the raw key. Both now match by `MobileAppFeature::normaliseKey()`, and an
  explicit `quran` post maps to the production row
  (`QuranFeatureKeySpellingTest`). Studio avoids the bug by matching on id.
- **Possible:** a client-sent `X-Forwarded-Host: localhost` may render
  Burlington on `mec-web.pages.dev`. The git map sends `localhost`→1, and h3
  falls back to `localhost` (renderer-lookup risk [1]). This is unverified.
- `SectionType::withoutRenderer()` still lists OFFERING, although the renderer
  draws it (`app/Enums/SectionType.php:242-245`).
- `Masjid::header_logo()` and `footer_logo()` lack the `model_type` filter
  (`app/Models/Masjid.php:622-634`).
- `renderer:tests/tenant-unchanged.test.ts:27-43` pins `mec-web`'s map, not
  `manara-renderer`'s.
- `PagesSeeder` / `seed:pages` writes invented content, and without
  `--masjid_id` it seeds every org (`database/seeders/PagesSeeder.php`,
  `app/Console/Commands/SeedPagesCommand.php:27-39`).

---

## 8. Open questions (Unknowns that block a slice)

| # | Unknown | Blocks | Recommended default |
|---|---|---|---|
| OQ1 | ~~What production `NUXT_TENANT_HOSTS` holds on `manara-renderer`~~ **Resolved 2026-09-24** (Pages API, read-only) | — | Six hosts, `plain_text`, no wildcards, no locale fields: `mec.manara.hopetechapps.com`→13, `alrazi.manara.hopetechapps.com`→14, `burlingtonmasjid.com`, `www.` and `new.burlingtonmasjid.com`→1, `sundayschool.burlingtonmasjid.com`→18. `new.burlingtonmasjid.com` is in the map but is **not** a custom domain on the project, so the import's probe cannot match it: it becomes `reserved`. `mec-web` sets no `NUXT_TENANT_HOSTS` at all and runs on the map in git. Re-read before the S3 import; S11 is not blocked. |
| OQ2 | ~~Which commit each project serves, and whether `main` auto-deploys~~ **Resolved 2026-09-24** | — | `manara-renderer` serves deployment `dfd3f1ee` = `cd876b7` = `origin/main`'s head; `mec-web` serves `7060b4b1` = `af71ceb` = `origin/cloudflare-migration`'s head. Neither project has a git source: they are direct uploads and **never auto-deploy**. Every renderer slice deploys by hand with `wrangler pages deploy`. Re-check the head before S10's before-capture. |
| OQ3 | The Cloudflare Pages custom-domain `status` values and field names (`validation_data`, `verification_data`); whether `dns_records` filters by `name=` or `name.exact=` | S7 | Read the API reference at build time and encode it in the `CloudflareServiceTest` fixtures. An unrecognised status stays `provisioning` and fails at 72 h. |
| OQ4 | Whether `event.node.req.headers` is writable under workerd (nitropack 2.13.4, `cloudflare_pages` preset) | S10's cache re-key | Prove it in the wrangler integration run. If it is not writable, ship S10 without the `varies` change and document that re-pointing a host, or trashing its org, keeps serving the cached pages (swr keeps an entry whose re-render fails, renderer-lookup fact [10]) until a KV purge of its page keys or the next build id. W1 re-points nothing; trashing a lookup-resolved org then needs that purge as a manual step. |
| OQ5 | The Workers/KV plan and its write quota | Shapes S10 | Assume Free (1,000 writes a day). R5's write-on-change policy holds under either plan. |
| OQ6 | ~~Production origin lists, and whether the hotfix has landed~~ **Resolved 2026-09-24** | — | The hotfix landed (see S9). `CORS_ALLOWED_ORIGINS` has 11 origins; `FORMS_PAYMENT_RETURN_ORIGINS` has one, `https://sundayschool.burlingtonmasjid.com`. S9 is invisible. Card payment on MEC's or Al-Razi's forms needs their host on that list (today, by `.env`; after S9, by a confirmed `masjid_domains` row) before it is switched on. |
| OQ7 | Whether spatie/image's `GdColor` parses a transparent background (`GdColor.php:35-60`) | S8's favicon padding | Check it at build time. If no transparent form parses, pad the favicon with `background_color`. |
| OQ8 | Whether any production section carries `settings.studio`. None is expected | S8 | The preflight count. If it is non-zero, stop. |
| OQ9 | How many layout presets per vertical (spec §6 Open) | S4 content | Three per vertical, per the layouts contract. Changing it later is a config change. |
| OQ10 | A production host for S11's positive check, before any client depends on it | S11 | With the owner's go, attach a managed subdomain to the QA sandbox org (masjid 17, per the owner's notes) through the S7 admin route; with no token the owner adds it in the dashboard and "Check now" confirms it after S11. Fallback: the first client is the proof. |
| OQ11 | Which IP Laravel sees for Worker subrequests, which is the throttle key | S11 (soft) | Keep 600/min per IP. If 429s show up in the log, raise it. A 429 only makes a dynamic host without a stale record return a 503; it never renders another tenant. |
| OQ12 | Whether `MANARA_PAGE_CACHE` is bound on `manara-renderer-staging` | S11's staging step | If it is unbound, the lookup's store is `null` and it runs memo-only. Test that path in S10's unit tests. |
