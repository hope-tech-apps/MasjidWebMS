# Recon: domains-lifecycle

> Read-only recon for the Studio W2/W3 plans, 2026-09-24. MasjidWebMS `c3fc0324`, iOS `origin/main` `8e5191f`, Android `origin/master` `cf61d54`, renderer `origin/main` `6a7ead2`.
> Line numbers are as of those commits; re-read before relying on one. This repo is PUBLIC: identifier-shaped values (OneSignal ids, Apple team ids, key ids) are redacted as `[redacted]`.

## Facts

[F1] `masjid_domains` columns (`database/migrations/2026_09_24_140000_create_masjid_domains_table.php:35-59`):
- `masjid_id`: FK with **cascadeOnDelete** (`:37`).
- `host`: varchar(253), globally unique (`:38`).
- `kind`, `zone_apex`.
- `status`: default `pending`, indexed (`:41`).
- `waiting_on`; `source` default `studio` (`:43`).
- `cf_zone_id`, `cf_dns_record_id`, `cf_pages_domain_id` (`:44-46`); `cf_zone_created` bool (`:47`).
- `nameservers` json; `last_error` text.
- `last_checked_at`; `next_check_at` indexed (`:51`); `verified_at`; `serving_confirmed_at` (`:53`); `verified_by`.
- `created_by_user_id` nullOnDelete; index `md_masjid_status_idx` (`:58`).
- `stage_started_at` is added by `2026_09_24_170000_add_stage_started_at_to_masjid_domains.php:33-35`.

[F2] Model constants (`app/Models/MasjidDomain.php`):
- Seven statuses (`:39-55`).
- SERVED = pending, awaiting_nameservers, provisioning, active, manual (`:58-64`).
- NON_TERMINAL (`:67-71`); TRUSTED = active, manual (`:74-77`).
- Kinds managed_subdomain / custom (`:79-81`); sources studio / imported (`:83-84`); verified_by cloudflare / probe (`:86-87`).
- WAITING_ON = token, token_scope, nameservers, certificate, capacity (`:90`).

[F3] Save invariants:
- An unknown status or kind throws (`MasjidDomain.php:149-155`).
- A `reserved` row can never change status (`:162-168`).
- `active` requires `verified_by=cloudflare` and `verified_at` (`:174-179`).
- Every save or Eloquent delete forgets the CORS cache key (`:182-183`).

[F4] Scopes:
- `served()` = SERVED plus `whereHas('masjid')`, so trashed orgs are excluded (`:227-230`).
- `corsAdmitted()` = served ∩ TRUSTED ∩ `serving_confirmed_at IS NOT NULL` (`:237-242`).
- `corsOrigins()` is cached 300 s under one fixed key (`:99-101`, `:254-265`). A trashed org leaves the list only when the cache expires (`:247-250`).

[F5] Removal rules:
- `deletableThroughStudio()` is false for `source=imported`, `cf_zone_created`, or any `cf_*` id (`:411-418`).
- `removalSteps()` hands the operator dashboard steps and ends "ask the platform owner to remove this row" (`:427-456`).
- `manualSteps()` has a capacity text (`:341-345`), and the reserved text calls changing the row "a platform-level change … not a Studio action in W1" (`:305-310`).

[F6] `CloudflareService` has no delete at all:
- The docblock says the class "has no delete method" (`app/Services/Cloudflare/CloudflareService.php:16-26`).
- `VERBS = ['GET','POST','PUT','PATCH']` (`:63`), and `request()` throws LogicException for any other verb (`:420-424`).
- Public methods: `isConfigured` (`:92`), `findZone` (`:101`), `createZone` (`:135`), `getZone` (`:165`), `requestActivationCheck` (`:190`), `ensureCname` (`:216`), `getPagesDomain` (`:260`), `countPagesDomains` (`:280`), `ensurePagesDomain` (`:305`), `retryPagesDomain` (`:345`).
- Pinned by `tests/Feature/Studio/CloudflareServiceTest.php:293-331`: no public method name contains delete or remove, and `request('DELETE')` throws.

[F7] Counting and the ceiling in `CloudflareService`:
- `countPagesDomains()` GETs `/accounts/{id}/pages/projects/{project}/domains` (`:529-533`). It reads `result_info.total_count`, else counts one page of `result` (`:280-297`).
- `ensurePagesDomain()` returns CONFLICT `reason=capacity` and adds nothing when used ≥ ceiling (`:318-331`).
- `unsuccessful()` logs only failed non-404 calls (`:479-490`). The capacity conflict is built directly and is never logged.

[F8] `config/cloudflare.php`:
- `studio_token` (`:30`), `account_id` (`:32`), `pages_project` = manara-renderer (`:36`), `pages_target` (`:37`).
- `managed_zone`, `managed_zone_id`, `managed_suffix` (`:42-44`), `reserved_labels` (`:52`).
- `pages_domain_ceiling => 100` is a literal, not an env value (`:57`).
- `api_base` (`:59`), `timeout` 15 (`:62`).
- Staging blanks every `CLOUDFLARE_*` key (`deploy/staging/provision.sh:387-391`).

[F9] `DomainAttacher` (`app/Services/Domains/DomainAttacher.php`):
- Transition table (`:20-33`).
- Lock `Cache::lock('masjid-domain:'.id, LOCK_SECONDS)` with LOCK_SECONDS = 8×30+60 = 300 s (`:99`, `:139-142`), shared with DELETE (`:59-66`).
- `step()` skips reserved and failed rows (`:256-258`).
- Without a token it only probes (`:288-304`).
- An active row gets `confirmServing` (`:307-316`).
- Imported or manual rows with a token get `promoteByReads`: GETs only, may set `active` and record `cf_pages_domain_id` / `cf_zone_id` (`:324-348`).
- `fromPending` (`:350-402`).
- `fromAwaitingNameservers`: fails after 28 days (`:404-457`).
- `fromProvisioning`: one retry at 24 h, fails at 72 h (`:459-522`).
- `attach()` creates or adopts the CNAME and records `cf_zone_id` / `cf_dns_record_id` **before** the Pages capacity check. At capacity the row goes back to `pending` / `waiting_on=capacity` and retries hourly (`:533-572`).
- "Check now" is `checkNow()`, which runs `restartIfFailed` then `step` (`:189-225`).

[F10] `serving_confirmed_at` is never cleared:
- The attacher docblock says it is "never cleared by a later miss in W1" (`DomainAttacher.php:67-68`).
- `runProbe` writes nothing on a miss after a match (`:617-631`).
- `confirmServing` probes only while the column is null, then sets `next_check_at` null (`:307-316`).
- `DomainProbe::confirm` only ever sets it (`app/Services/Domains/DomainProbe.php:125-133`).

[F11] `DomainProbe`:
- Sends `GET https://{host}/api/tenant`. A match is a 200 whose `x-manara-tenant` equals the row's `masjid_id` (`:74`, `:91`).
- Redirects are not followed and never match (`:70`, `:87-89`).
- SSRF guard: public addresses only, and the connection is pinned to the checked address (`:59-66`).
- A reserved row gets only `last_checked_at` (`:121-123`). A matching non-active row becomes `manual` / `probe` (`:128-132`).
- `HostResolver` is `dns_get_record` A/AAAA (`app/Services/Domains/HostResolver.php:18-34`).

[F12] `domains:reconcile` (`app/Console/Commands/ReconcileDomains.php`):
- Selection (`:114-136`): never reserved or failed; due NON_TERMINAL rows; active rows not yet confirmed; manual or imported rows only with a token (6 h cadence, `DomainAttacher.php:114`, `:347`).
- **The selection has no masjid or trashed filter.**
- One no-token warning per hour (`:62-68`); always exits 0 (`:105`).
- Scheduled `cron('3-59/5 * * * *')->withoutOverlapping(10)` (`routes/console.php:164`).

[F13] `AttachMasjidDomain`: `afterCommit`, `tries=1`, skips a deleted row (`app/Jobs/AttachMasjidDomain.php:32-59`). Dispatched from `MasjidDomainsController.php:91` and `app/Support/Studio/StudioProvisioning.php:170`.

[F14] Routes:
- `/api/admin/masjids/{masjid_id}/domains`, `super` only: GET `/`, POST `/`, POST `/{domain_id}/refresh`, DELETE `/{domain_id}` (`routes/admin.php:465-470`).
- `POST /api/admin/studio/domains/check` (`routes/admin.php:1676`).

[F15] `MasjidDomainsController` (`app/Http/Controllers/AdminDashboard/MasjidDomainsController.php`):
- `index` returns `cloudflare.{configured, pages_project, pages_domains_used (null without a token or on a failed read), pages_domains_ceiling}` (`:35-67`).
- `store` answers 422 when two SuperAdmins race for one host (`:95-104`).
- `refresh` answers 409 when the lock is held (`:122-138`).
- `destroy` (`:152-184`):
  - 409 "try again" while the lock is held.
  - 409 with `manual_steps = removalSteps()` when the row is not deletable.
  - Otherwise 204.
- `Masjid::findOrFail` means a trashed org's domains answer 404 (`:188`).

[F16] SPA:
- The domain panel is mounted only in Studio Step 3 (`StepGenerate.vue:43`, `generate/ProvisionResults.vue:79`).
- "Remove" shows only when `deletable` (`StudioDomainAttachPanel.vue:111-113`).
- The ceiling line is at `StudioDomainAttachPanel.vue:12-14`.

[F17] Org deletion:
- `Masjid` uses SoftDeletes (`app/Models/Masjid.php:18`).
- `destroy` and `moveToTrash` both soft-delete ("Permanent deletion is no longer exposed", `MasjidsController.php:628-666`). `restore` is at `:697-700`; the routes are `routes/admin.php:193-195`.
- The only app-code `forceDelete()` of a Masjid is `DemoSchoolSeeder::rollback` (`app/Support/DemoSchoolSeeder.php:949`, `:973`), reached through `demo:seed-school --rollback` (`app/Console/Commands/SeedDemoSchool.php:47-48`).
- `Masjid::forceDeleted` touches only forms-card links, not domains (`Masjid.php:738-760`).

[F18] CORS and payment return on HEAD `c3fc0324`:
- CORS is a static env list (`config/cors.php:22-24`). There is no `HandleCorsWithDomains` in `app/` or `bootstrap/`.
- `corsAdmitted` / `corsOrigins` have no consumer.
- `JummahLunchOrdersController.php:521` reads `cors.allowed_origins` as a Stripe return allowlist.
- Payment returns use a static list (`config/forms.php:125-131`, `app/Support/FormPaymentReturn.php:118-127`).
- S9 exists only on local branch `feat/studio-s9` (26053319; 14 files, adds `HandleCorsWithDomains.php`, `CorsDomainOriginsTest`, `FormPaymentReturnDomainTest`, `TrustedHostsIgnoresDomainsTest`), per `git diff --stat HEAD...feat/studio-s9`.

[F19] `domains:import-host-map` (`app/Console/Commands/ImportHostMap.php`):
- Signature: `{map} --apex=* --dry-run --execute` (`:55-59`).
- Dry run by default, all-or-nothing transaction with read-back, create-only (`:36-50`, `:123-155`).
- A probe match becomes `manual`, anything else `reserved` (`:294-298`).
- Never re-points a host (`:263-268`); every row gets `source=imported` (`:133`).

[F20] Apex and www:
- `burlingtonmasjid.com` is recorded as "307 to `www`" (`docs/tenant-host-map.md:94`).
- The renderer maps both apex and www to id 1 (`renderer:nuxt.config.ts:21-22`).
- The renderer's middleware redirects only per-tenant path tables, GET/HEAD only (`renderer:server/middleware/tenant.ts:37-51`).
- The snapshot has no `public/_redirects` (`renderer:public/` holds `assets`, `favicon.ico`, `robots.txt`).
- The W1 plan says the 307 "is answered outside the renderer code" (`docs/manara-studio-w1.md:749`).
- Al-Razi's apex 307s to www from a separate Pages project (`docs/tenant-host-map.md:101`). `hopetechapps.com` 308s to www (`:104`).

[F21] Renderer lookup (S10) is not in the snapshot: `renderer:shared/` has no `tenantLookup.ts`. The planned caches are memo found 300 s, absent 60 s, KV found 86,400 s (`docs/manara-studio-w1.md:1717-1723`). R5: "An absent answer deletes a found record KV still holds" (`:95`). OQ4 says trashing a lookup-resolved org may need a KV page-key purge (`:2006`).

[F22] Everything that reads `serving_confirmed_at`:
- `corsAdmitted` (`MasjidDomain.php:237-242`).
- `liveUrl` (`:273-276`), which feeds `toAdminArray` `live_url` (`:483`) and `StudioProvisionController.php:154`.
- `manualSteps` (`:326-328`).
- The attacher: `withoutToken` (`:296-301`) and `confirmServing` (`:307-316`).
- The reconcile selection (`ReconcileDomains.php:126`).
- The SPA `isConfirmedServing` (`resources/vue-app/core/types/data/MasjidDomain.ts:84-86`). For a `manual` row it uses `verified_at`, not `serving_confirmed_at`.

[F23] Where the ceiling count is read:
- `MasjidDomainsController.php:45-48`.
- `StudioDomainCheckController.php:44-45`, `:63-64`.
- `ensurePagesDomain`.
- The spec's figures: 100 on Free, 250 on Pro, 500 on Business; 5 domains in use; about 47 two-host clients left (`docs/manara-studio.md:168-171`, `:209-215`).
- There is no warning threshold anywhere; the only number is the hard ceiling (`config/cloudflare.php:57`).

[F24] How the owner is told something today:
- Scheduled monitors (`tenancy:canary` `routes/console.php:398`, `media:verify` `:590`, `backup:check` `:753`, `backup:drill` `:814`) log one line per run to the `monitors` channel.
- `monitors` is a stack of `monitors-file`, `single` and `ops-alerts` (`config/logging.php:139-143`).
- `ops-alerts` emails `OPS_ALERT_EMAIL` at `error` and above through `App\Logging\OpsAlertChannel` (`config/logging.php:84-98`). It is inert until that variable is set (`config/backup.php:277-284`; `deploy/README.md:191-194`; `.env.example:233` blank).
- `app:legacy-features-report` writes one `Log::warning` per run (`routes/console.php:115-142`).
- No SuperAdmin dashboard banner exists (`resources/vue-app/views/super/DashboardsView.vue` has no alert or banner markup).

## Answers

**1. Schema, statuses and scopes.** [F1]–[F4].
- `source`: studio or imported.
- `cf_zone_created` is set only when Studio's own POST made the zone (`DomainAttacher.php:365-368`).
- `stage_started_at` starts the 28-day and 72-hour clocks and is cleared on the way out (`DomainAttacher.php:394`, `:423`, `:482`, `:569`).
- `verified_by` is `cloudflare` or `probe`.
- `waiting_on` holds one of five values.
- `served()` feeds only the by-host lookup (`app/Http/Controllers/Api/V1/OrganizationByHostController.php:46`, route `routes/api_v1.php:29-30`). `corsAdmitted()` has no consumer on main.

**2. CloudflareService.** [F6]–[F8].
- There is no delete method and no DELETE verb, and a test pins both.
- `countPagesDomains` is a single GET.
- Capacity handling: a hard refusal at `pages_domain_ceiling` (100). The attacher parks the row on `capacity` and retries hourly ([F9]). Nothing is logged ([F7]).

**3. Attacher, probe, resolver and reconcile.** [F9]–[F13].
- One writer per row, through a 300 s cache lock.
- A probe match needs our own tenant header, and it only ever confirms.
- `reconcile` runs every 5 minutes.

**4. The domains controller.** [F14]–[F16].
- DELETE answers 204 only when `deletableThroughStudio()` is true and the lock is free.
- It answers 409 in three cases: the lock is held, the row is imported, or the row carries Cloudflare state. The last two come with `manual_steps`.
- `manual_steps` are built on the server ([F5]).
- `refresh` is "Check now": it restarts a failed row, advances it, and answers 409 when the lock is held.

**5. Org deletion.** [F17], [F18], [F4], [F12].
- **Trash:** the rows stay.
  - The lookup stops at once through `whereHas`.
  - CORS drops the host within 300 s once S9 lands.
  - The unique host stays held: the check and store read the holder regardless of trash (`StoreMasjidDomainRequest.php:42-46`, `StudioDomainCheckController.php:34`).
  - The domain screens 404.
  - **`domains:reconcile` keeps advancing the rows** (no masjid filter), so a Studio row can still be attached in Cloudflare after the org is trashed.
  - Restore brings the lookup back unchanged.
- **Force-delete:** the DB cascade deletes the rows with no Eloquent event, so no cache forget and no Cloudflare call. The CNAME, Pages domain and any Studio-created zone become untracked.
- No endpoint force-deletes an org. Only the demo-seeder rollback does.

**6. CORS and payment return.** [F18].
- Main has only the static lists. S9 is on `feat/studio-s9`.
- S9's contract (`docs/manara-studio-w1.md:1586-1631`), which W2 must respect:
  - `HandleCorsWithDomains` replaces `HandleCors`.
  - With no Origin, or an Origin already on the static list, it reads neither the table nor the cache.
  - It adds `corsOrigins()` (`corsAdmitted` only), and any error falls back to the static list, never a 500.
  - A payment return accepts a `corsAdmitted` host **of the form's own masjid** only.
  - The static env lists are never narrowed, and `TrustedHosts` is unchanged.
- The S9 branch adds one more rule: the merged list is visible only to the CORS decision, so the lunch return allowlist does not widen (branch `DECISIONS.md`, from `git show feat/studio-s9`).
- **For W2:** any detach, demotion or re-confirmation changes CORS and payment admission within 300 s, because the model events forget the key. A cascade or raw delete waits for the TTL.

**7. Imported rows.** `domains:import-host-map` ([F19]) writes `manual` or `reserved`, both with `source=imported`. R28 freezes them in five places:
- `deletableThroughStudio` is false (`MasjidDomain.php:413`).
- DELETE answers 409 (`MasjidDomainsController.php:171-173`).
- Their hosts cannot be added again (`StoreMasjidDomainRequest.php:42-46`).
- `reserved` can never change status (`MasjidDomain.php:162-168`).
- The attacher only reads for imported rows and never probes reserved ones (`DomainAttacher.php:44-50`, `:266-267`).

The plan calls changing them "a W2 tool with its own review" (`docs/manara-studio-w1.md:1948-1949`). Whether production has run the import is U1.

**8. Apex and www.** [F20]. The renderer and this repo have no host-level redirect. Where Burlington's 307 is configured is U3. The probe does not follow redirects ([F11]), so a redirecting apex can never be confirmed.

**9. Re-confirmation.** Nothing clears `serving_confirmed_at` ([F10]). Confirmed rows are never probed again: active rows are deselected, and a manual row has `next_check_at` null without a token. Its readers are listed in [F22]; S9 would add CORS and payment return.

**10. The Pages ceiling.** Where the count is read: [F23]. There is no threshold config and no owner notice ([F7], [F23]).
- The house pattern is [F24]: a scheduled `--json` monitor command logging to the `monitors` channel, which records every run in `monitors.log` and emails at `error` through `OPS_ALERT_EMAIL`.
- A ceiling monitor would add Cloudflare read calls that need the token. Rows waiting on `capacity` are already queryable (`waiting_on='capacity'`).

**11. `docs/tenant-host-map.md` and tests.**
- Key facts in `docs/tenant-host-map.md`:
  - The renderer hosts and the live CORS origins (`:93-104`, `:148-152`).
  - `masjid_domains` is "Nothing consumes it yet" (`:142`).
  - Client hosts reach Laravel only as `Origin` (`:84-89`).
  - Setting `NUXT_TENANT_HOSTS` replaces the whole code map (`:140`).
- Tests are in `tests/Feature/Studio/` unless noted:
  - `CloudflareServiceTest`: `ensure_pages_domain_at_the_ceiling_is_a_capacity_conflict_and_adds_nothing` (`:218`), `the_delete_verb_is_never_used_and_cannot_be` (`:293`).
  - `DomainAttacherTest` (25): `an_imported_row_is_promoted_by_gets_only_and_never_failed` (`:368`), `a_reserved_row_is_never_advanced_with_or_without_a_token` (`:601`), `a_held_lock_makes_advance_a_no_op` (`:655`).
  - `DomainProbeTest` (11): `a_redirect_is_not_followed_and_does_not_match` (`:98`).
  - `DomainsReconcileCommandTest` (8): `without_a_token_on_productions_rows_it_selects_nothing_and_sends_nothing` (`:85`).
  - `ImportHostMapCommandTest` (10): `it_never_re_points_a_host` (`:186`).
  - `MasjidDomainScopesTest`: `failed_reserved_and_trashed_org_rows_are_in_neither_scope` (`:66`).
  - `MasjidDomainsAdminRoutesTest` (17): `delete_answers_204_…` (`:220`), `delete_answers_409_with_the_removal_steps_…` (`:233`), `an_imported_row_cannot_be_deleted` (`:372`).
  - Also: `MasjidDomainActiveInvariantTest` (4), `MasjidDomainReservedInvariantTest` (1), `MasjidDomainSchemaTest` (5), `OrganizationByHostTest` (10), `ProvisionAttachesDomainTest` (5), `StudioDomainCheckTest` (7), `tests/Unit/MasjidDomainHostTest.php` (3).
  - Helpers: `Concerns/FakesCloudflare.php`, `Concerns/MakesStudioDomains.php`.
  - **No test covers a trashed org's rows in reconcile or the attacher** (no "trash" in either test file).

## Risks to live clients

[R1] Detach code needs a Cloudflare DELETE. That breaks the pinned "no delete" invariant ([F6]), and the token has Zone Edit over every zone in the account, including `burlingtonmasjid.com` (orgs 1 and 18) and `alrazischool.org` (14) (`CloudflareService.php:18-26`). A wrong delete takes their DNS and email with it. Limit deletes to ids stored on `source=studio` rows. Never delete a zone Studio did not create (`cf_zone_created`), and never touch imported rows.

[R2] Once S9 and S11 land, detaching or demoting an imported row can remove a live host from the lookup, CORS and payment returns:
- Burlington's www/apex (org 1).
- `sundayschool.burlingtonmasjid.com` (org 18, the only payment-return origin, `docs/manara-studio-w1.md:1580`).
- `mec.manara` (org 13) and `alrazi.manara` (org 14).

The static env lists cushion CORS only for the hosts they name.

[R3] Re-confirmation that clears `serving_confirmed_at` on one missed probe would cut CORS and card returns for a live host after a blip, a Cloudflare outage, or a 5 s timeout ([F11]). It would also demote any host that starts redirecting.

[R4] A canonical apex/www redirect added in renderer middleware would change Burlington's apex behaviour. `renderer:tests/tenant-unchanged.test.ts:122` pins apex resolution to tenant 1. It would also make the redirected host unprovable by the probe.

[R5] At the ceiling, a new row already holds a CNAME, so it is not deletable, and it retries silently every hour ([F9], [F7]). "Retiring" domains to free slots means detaching real hosts. The 5 live hosts count toward the 100.

[R6] A force-delete path added in W2 without Cloudflare cleanup first would orphan records ([F17]). Trashing a Studio org today leaves reconcile still attaching its hosts ([F12]).

[R7] If `OPS_ALERT_EMAIL` is unset on production, an `error`-level ceiling alert reaches nobody by mail ([F24]).

## Unknowns

[U1] Whether `domains:import-host-map --execute` has run on production, and what each row became. At S7 the table was "empty on production today" (`2026_09_24_170000_…:21-22`). To resolve: a read-only SELECT on production, with the owner's go.

[U2] Whether `CLOUDFLARE_STUDIO_TOKEN` is set on production, and with which scopes. To resolve: an env presence check (never print the value).

[U3] Where `burlingtonmasjid.com` → www (307) is configured: a Redirect Rule, Page Rule, Bulk Redirect, or Pages itself. To resolve: a Cloudflare dashboard or API read of that zone's rulesets.

[U4] Whether the Pages domains list paginates, and whether `result_info.total_count` is always present, since the fallback counts one page ([F7]). To resolve: the Cloudflare API reference.

[U5] The account's Cloudflare plan against the hardcoded 100. To resolve: the dashboard.

[U6] Whether `OPS_ALERT_EMAIL` is set on production. To resolve: an env presence check.

[U7] What happens to a request for a host with a proxied CNAME to `manara-renderer.pages.dev` that is not a Pages custom domain (the capacity wait). To resolve: Cloudflare docs or a staging test.

[U8] Whether deleting a Pages custom domain through the API also removes its DNS record, and what zone deletion does to MX. Not in the repo. To resolve: Cloudflare docs.

[U9] The state of S9 (`feat/studio-s9`, not on main) and of S10/S11 in the renderer (no `tenantLookup.ts` in the snapshot) when W2 starts. To resolve: `git log origin/main` in both repos at plan time.
