I'm labelling this `mode: focused` (live preview and purge) rather than full. Many universal axes were not walked before the coordinator stopped the exploration, and a full-recon label would make the session hook replay an incomplete map as if it were current. Everything below comes from files I read at this commit. Anything I did not open is marked "not verified".

```
=== focused on live preview + save-purge (backend); not a recon file ===
commit: 4df524216d429457727b115c2cadecfa71158815
platform: backend
scope: . (repo root; worktree claude/goofy-kirch-ad9648)
generated: 2026-09-23 (as briefed; host UTC clock read 2026-09-24T03:17Z)
dirty: clean
mode: focused: live preview token + renderer purge
kit: /Users/moneebsayed/.claude/engineering-excellence
```

# Recon: MasjidWebMS (Manara), backend

## Stacks
- The kit's detectors matched `web,backend`. This report covers backend only. The Vue/Vite admin SPA (web) was not walked.
- **PHP** `^8.2` (`composer.json:12`). CI runs PHP 8.3 (`.github/workflows/tests.yml:28`).
- **Laravel** `^12.0` (`composer.json:15`); the lockfile installs **v12.64.0** (`composer.lock:1536`). Sanctum is v4.3.3 (`composer.lock:1817`), spatie/laravel-permission 6.25.0 (`composer.lock:5041`), Guzzle 7.15.5 (`composer.lock:1119`).
- PHP addendum loaded (`platforms/backend/stacks/php.md`): version policy and checklist only.
- **Renderer** (Nuxt, Cloudflare Pages) is a separate repo and is not in reach. What it does is known only from `docs/tenant-host-map.md:93-100` and `docs/live-preview-brief.md:42-48`, both of which are leads, not sources.
- Surprising absence: nothing in `app/`, `config/` or `routes/` calls Cloudflare or the renderer. "cloudflare" appears only in comments: `app/Support/SiteUrl.php:26`, `app/Http/Middleware/TrustedHosts.php:21`, `config/portal.php:18`.

## Binding instructions (what a new route or outbound call must satisfy)
- `CLAUDE.md` is the project memory. The `.claude/rules/*` files are enforced by tests. The ones that apply here:
  - **`auth-permissions.md`**
    - `Permission::count()` stays 8, pinned by `StaffAuthGuardPinTest` (not opened).
    - Never use `can:` or FormRequest `authorize()`: their `AuthorizationException` renders as a 500 in this app.
    - Refusals from the capability gate are returned as responses, not `abort()`, so the sentence survives production (`EnsureOrgCapability.php:69-76`).
  - **`shipping.md`**
    - Coerce the strings `"true"`/`"false"` in `prepareForValidation`.
    - Write at least one test in form encoding.
    - A swallowed failure must be logged at `warning` or above (production `LOG_LEVEL=warning`).
    - Drive the deployed page before calling it done.
  - **`environments.md`**
    - Staging first; ship only via `scripts/ship.sh`.
    - The staging egress deny-list blanks only keys matching `^(STRIPE|RESEND|ANTHROPIC|ONESIGNAL|PUSHER|GITHUB|AWS|VITE_PUSHER)_`. A new renderer secret would not be blanked.
    - Environment checks go through `App\Support\Environment`.
    - An integration with no credentials must no-op, not throw.
  - **`generated-urls.md`**: any URL that outlives its request or is handed to a third party is built with `SiteUrl`.
  - **`tenant-scoping.md`**: `Page`, `Section` and `Service` are in the hand-scoped legacy inventory, so no global tenant scope applies to them.
  - **`section-types.md`**: uploads reach only one array level; `button_page_id` is resolved when content is read.
  - **`events-listeners.md`**: listener auto-discovery is ON.
- `docs/live-preview-brief.md:54-81` holds the owner's eight hard requirements for this feature.

## Shape, entry, execution, deploy
- **Entry.** `bootstrap/app.php:30-72`.
  - `routes/api.php` loads `routes/api_v1.php` with `require` (`routes/api.php:379`).
  - `routes/admin.php` is mounted at `/api` with the `api` group (`bootstrap/app.php:38-40`).
  - `TrustedHosts` is prepended (`:86`); `SecurityHeaders` is appended globally (`:89`).
  - Middleware aliases: `:111-184`. JSON exception envelope: `:211-267`.
- **Execution model.** PHP-FPM behind nginx, one request per process. nginx is `default_server` (`docs/tenant-host-map.md:31`). The FPM config itself is not verified. A synchronous outbound call inside a save blocks that admin's request.
- **Queue.** The database driver in production, with the worker unit at `deploy/masjid-queue.service`. Tests run `sync` (`phpunit.xml:33`).
- **Deploy unit.** `bin/deploy`:
  - `migrate --force` (`:213`), then config/route/view clear (`:216-218`), then `config:cache` and `route:cache` (`:219-220`), then `systemctl restart $QUEUE_SERVICE` (`:225`).
  - Frontend assets are rsynced separately by `scripts/ship.sh` (per `environments.md`).
  - A php-fpm reload was not seen in the slice I read (not verified).
- **Variant matrix.** Laravel runs in production, staging and a stale droplet (`environments.md` table). The renderer projects are:
  - `manara-renderer` (prod) serves tenants 1, 18, 14 and 13.
  - `mec-web` is a separate project that also serves tenant 13.
  - `manara-renderer-staging` points at the staging API.
  - Source: `docs/tenant-host-map.md:93-100`.
- **Data ownership.** One managed MySQL 8.4. Laravel is the only writer of pages, sections, theme and splash. The renderer reads through `/api/v1` and `/api/mobile` only.

## Q1. Admin write routes, middleware, controllers

**Stack for every route below.** `EchoResolvedTenant, auth:sanctum, admin, tenant` (`routes/admin.php:110`), under the `admin` prefix (`:85`), mounted at `/api/admin`.

| Surface | Routes (routes/admin.php) | Extra gate | Controller methods |
|---|---|---|---|
| Pages | `:458-465`: GET `/`, POST `/`, POST `/reorder`, GET/PUT/DELETE `/{page_id}` | `capability:web_pages` **and** `capability:website`: two separate middleware, both must pass (`:456`) | `AdminDashboard/PagesController` `store:51-79`, `update:105-132`, `reorder:137-159` (a loop with no transaction, `:142-147`), `destroy:164-182` (soft delete; `Page` uses SoftDeletes, `Page.php:13`) |
| Section library | `:468-474` | same group | `SectionsController` `store:65`, `update:97` (scoped via `$masjid->sections()->findOrFail`, `:100-101`), `destroy:132`, uploads `:156-209` |
| Page sections | `:477-484`: index, store, `attach`, show, PUT, DELETE (detach) | same group | `PageSectionsController` `store:53-97`, `update:124-197`, `destroy:202-224`, `attach:229-270`; section-types list at `:487`, handled by `sectionTypes:275-321` |
| Theme | `:407-410`: GET `/`, POST `/` | **none**: group stack only | `ThemeSettingsController` `index:13`, `save:24-65` |
| Splash | `:251-258`: index, store, show, POST `/{id}` (update), DELETE, DELETE `/trash` | `capability:splash` (a module) | `SplashAnnouncementsController` `store:52-92`, `update:106-…` |
| Details / general settings (logos, copyright; feed `/v1/settings`) | `:204-213` | none | `MasjidDetailsController` (flushes at `:83-88`, `:138-140`) |

- **Page fields.** `UpdatePageRequest.php:32-45`: `slug` (unique per masjid among live rows), `title`, `page_title`, `page_title_background_image`, `is_active`, `order`, `show_in_menu`, `show_as_button`, `meta_description`.
- **"Menu".** There is no menu resource. The web menu is derived from pages' `show_in_menu`, `show_as_button` and `order` (`Api/V1/PagesController.php:82-100`), so a menu edit is a page update or reorder.
- **Header/footer style.** Unknown, needs investigation. It may live in theme `tokens` (`App\Support\DesignTokens`, not opened).
- **Section content.** On update, top-level keys are merged into the stored content unless the section type changes (`PageSectionsController.php:146-155`). Placement fields (`order`, `platforms`) are on the pivot (`:162-171`).
- **Upload pipeline.** `getImageFieldsForSectionType` at `PageSectionsController.php:420-445`, duplicated in `SectionsController.php:209`.
  - Files go to the medialibrary `section_images` collection.
  - `$media->getUrl()` is written into the content JSON (`:384-387`, `:408-412`).
  - Array fields are matched one level deep (`:402`).
- **Theme validation.** Hex colours plus `tokens` as a nullable array (`SaveThemeSettingsRequest.php:17-24`). The model casts `tokens` to an array (`ThemeSetting.php:18-20`).

**Who may edit what (read from the code).**
- `admin` admits only `User` rows with `type` of `SuperAdmin` or `MasjidAdmin`; everyone else gets 401 (`UserAdminMiddleware.php:43,51-58`). Teacher and LunchStaff are refused.
- `tenant` binds a MasjidAdmin from their membership and returns 403 for any other `{masjid_id}` (`ResolveMasjidTenant.php:114-126,180-186`). A SuperAdmin is bound to the masjid in the route (`:127-134`).
- `capability:` behaviour (`EnsureOrgCapability.php`):
  - A **SuperAdmin passes before any check** (`:50-52`), including when the `website` module is off.
  - The masjid is taken from `TenantContext` first, then the route (`:54`).
  - Module keys go through `moduleIsOff` (fails open); other keys go through `hasCapability` (fails closed) (`:59-61`).
  - Several keys in one `capability:a,b` mean any-of (`:58-66`).
- `hasCapability` reads the `capability_overrides` value, else the org-type default; an unknown key is false (`Masjid.php:419-438`). `moduleIsOff` is at `Masjid.php:458-471`.
- Defaults:
  - `web_pages` is a grant, **false for every org type** (`config/capabilities.php:87-95`).
  - `website` is a module, true for every org type (`:184-190`).
- **Net rule for pages and sections:** a SuperAdmin always; a MasjidAdmin of *that* organisation only when `web_pages` has been switched on for it **and** `website` is not off.
- **Theme:** any MasjidAdmin of the organisation, plus SuperAdmin, with **no `web_pages` check**.
- **Splash:** MasjidAdmin or SuperAdmin while `splash` is not off. Per `auth-permissions.md`, splash is not offered to school or community organisations by default.
- Spatie `permission:` is not applied to any of these routes.

## Q2. Public reads used by the renderer
- **Routes.**
  - `/api/v1/home` → `HomeController` (`api_v1.php:21`)
  - `/api/v1/settings` → `SettingController@index` (`:22`)
  - `/api/v1/pages` → `index` (`:167`)
  - `/api/v1/pages/menu` → `menu` (`:168`)
  - `/api/v1/pages/{slug}` → `show` (`:169`)
  - Splash is on the **mobile** tree: `GET /api/mobile/masjids/{masjid_id}/splash` (`routes/api.php:99`), throttled by `throttle:mobile` (`:40`).
  - None of the `/v1` reads carries auth or a throttle.
- **How the masjid is chosen.** By the `masjid-id` **header**, never the Host.
  - Pages go through `SearchableTrait::scopeFilterByMasjid`: a missing or ≤0 header gives 400, a masjid that is not live gives 404 (`PublicTenant::exists`), otherwise `where masjid_id` (`app/Traits/SearchableTrait.php:84-101`).
  - `/settings` does `findOrFail(request()->header('masjid-id'))` with no `PublicTenant` check (`SettingController.php:39`). Whether `Masjid` soft-delete excludes trashed organisations here is not verified.
  - Splash takes the masjid from the URL parameter.
- **Envelope.** The `response()->api()` macro returns `{status: success|error, message, data}` passed through `array_filter`, so empty or null keys are dropped (`AppServiceProvider.php:1019-1026`).
- **`/settings` fields** (`SettingController.php:49-93`):
  - `masjid{id,name,email,phone,address,latitude,longitude,country,city,timezone}`
  - `prayer_calculation`
  - `theme` = `{primary, secondary, accent, background, tokens}`, where `tokens` is `resolvedTokens()` (`ThemeSettingResource.php:25-33`)
  - `logo_url`, `header_logo_url`, `footer_logo_url`, `copyright_text`
  - `app_store_link`, `google_play_link`, `google_maps_key`
  - `social_media[{type,value}]`, `activated_features[]`, `iqama_settings`, `jumaa_settings`
- **Page fields** (`PageResource.php:18-36`): `id, slug, title, page_title, page_title_background_image_url, is_active, order, show_in_menu, show_as_button, meta_description, sections[]`.
  - Each section (`PageSectionResource.php:30-45`): `id, section_type, section_type_label, title, content, items_per_page, order, platforms, is_active, settings, uses_external_data`.
  - Only active pages and active sections are served (`V1/PagesController.php:37-41,61-65`; `Page.php` `activeSections` filters `sections.is_active`).
- **Menu shape.** `{menu_items[], button_items[]}`, each item `[id, slug, title, order, show_as_button]` (`V1/PagesController.php:85-100`).
- **Splash shape.** `{status, data: <raw SplashAnnouncement model + image>}` or 204 (`Mobile/SplashAnnouncementsController.php:41-48`). Whether the model's `$hidden` keeps internal fields such as `onesignal_iam_id` out is not verified.
- **Content computed when read, not stored.**
  - `SectionContentBinder::bind` fills `about_us`, `mission_vision`, `donation`, `contact_form`, `form`, `embed` and `offering` sections from other tables (`SectionContentBinder.php:75-81`).
  - `Section::getContentAttribute` resolves `button_page_id` to another page's slug (`Section.php:146-157`). That lookup is an unscoped `Page::find`.

**Cache layers.**
- **In Laravel, `/v1` settings, pages and menu are uncached.** No `Cache::` call exists under `app/Http/Controllers/Api/`, and `ThemeSettingsController.php:44-46` says the same.
- None of these controllers sets `Cache-Control`. Only `AppMenuController.php:133-142` does (`no-cache` plus ETag).
- **Splash** uses `Cache::remember(mobile.masjid.{id}.splash, TTL_SHORT=300s)` (`Mobile/SplashAnnouncementsController.php:28-30`; key format `MobileCache.php:96-99`; TTLs `:90-93`). The cache store is `database` with no tags (`config/cache.php:18`, `MobileCache.php:15`).
  - A null result is not cached, per the in-repo comment at `Mobile/MasjidsController.php:198` (not independently verified).
- **What saves already forget.**
  - Splash forgets `SPLASH` on store, update, destroy and trash (`Admin/SplashAnnouncementsController.php:80,130,155,182`).
  - Theme calls `flushFamily`, which clears FEATURES, MENU, ORGS and SHOW plus the ancestors' MENU and ORGS. **All of those are mobile keys** (`ThemeSettingsController.php:53`, `MobileCache.php:182-236`).
  - Details calls `flushMasjidAll` plus the directory list (`MasjidDetailsController.php:83-88`).
  - **Page and section saves forget nothing** (grep of the three controllers finds nothing), because there is nothing in Laravel to forget.
- **The renderer's KV HTML cache** (300 s, stale-while-revalidate) is the only cache between a page save and visitors. That comes from `docs/live-preview-brief.md:45-47` and is not verified in this repo.

## Q3. Host, URL and header plumbing
- **`SiteUrl`**: `to()` (`:74-80`), `base()` which upgrades to https when `app.force_https` is set (`:94-103`), `route()` which builds with `absolute:false` (`:112-115`), and `host()` (`:118-123`).
- **`Environment`**: `isProduction()`, `name()` which falls back to `production`, and `label()` (`Environment.php:27-54`).
- **`config/app.php` keys**: `name:16`, `env:29` (default `production`), `debug:42`, `url:55`, `force_https:77`, `key:122`, `previous_keys:124`. There is **no key for the admin origin or the renderer origin**.
- **CORS** (`config/cors.php:18-34`):
  - Paths `api/*` and `sanctum/csrf-cookie`.
  - `allowed_origins` comes from `CORS_ALLOWED_ORIGINS` and **defaults to `*`** (`:22-24`).
  - Headers `*`; `supports_credentials` is false.
  - The production value is not verified.
- **`SecurityHeaders`** sets these on every Laravel response, with `replace=false`, so a header already on the response wins (`:35-44,135`):
  - `X-Frame-Options: DENY` (`:36`)
  - HSTS over https (`:64-70`)
  - `X-Robots-Tag: noindex, nofollow` when not production (`:59-61`)
- **CSP** (`:114-134`):
  ```
  default-src 'self'
  script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net https://*.pusher.com https://js.pusher.com
  style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://fonts.bunny.net https://cdn.jsdelivr.net
  font-src 'self' https://fonts.gstatic.com https://fonts.bunny.net data:
  img-src 'self' data: blob: https://*.supabase.co https://*.supabase.in https://maps.gstatic.com https://maps.googleapis.com
  connect-src 'self' https://*.supabase.co https://*.supabase.in https://*.pusher.com wss://*.pusher.com https://onesignal.com https://*.onesignal.com
  frame-src 'self' https://www.google.com https://maps.google.com
  frame-ancestors 'none'; form-action 'self'; base-uri 'self'; object-src 'none'; upgrade-insecure-requests
  ```
  - On paths starting `jummah-lunch` or `portal` (`:93-112`), `config('app.url')` is appended to script, style, font, img and connect sources.
  - **`frame-src` does not name any renderer origin**, so the admin SPA cannot frame the Nuxt site today (`:128`).
- **`TrustedHosts`**:
  - Allowed hosts = `SiteUrl::host()` + `config('portal.hosts')` keys + `trusted_hosts.extra` (`TrustedHosts.php:120-135`).
  - **Log-only** unless `TRUSTED_HOSTS_ENFORCE` is set, in which case it answers 400 (`:97-110`; `config/trusted_hosts.php:73`).
  - It logs at warning (`:298-301`).

## Q4. Signing and outbound HTTP to reuse or match
- **Closest parallel: the form staff token.** Format `VERSION.formId.codeId.expiry.hmac` (`app/Support/FormStaffCodes.php:205-208`).
  - `readToken` enforces a length cap, a strict regex, `hash_equals` and an expiry check, with no database read (`:217-236`).
  - The signature is `hash_hmac('sha256', "form-staff-token|{…}", config('app.key'))`. The **prefix is domain separation** (`:501-507`).
- **Other APP_KEY HMACs:** `TwoFactorService.php:391`, `FamilyLoginService.php:291`, `MemberSignupService.php:580` (purpose inside the MAC), `FormResponse.php:646`, `FormStaffCode.php:165`, `FormSubmissionsController.php:159`.
- **Crypt tokens:** `EmailSuppressionService.php:226` (URL-safe base64 of `encryptString`) and `:250`; `RosterImportController.php:277,298`.
- **Bearer compare:** `ProvisioningCallbackController.php:47-54`, constant time, with a dummy value when the job is unknown.
- **Inbound signature verifier:** `TwilioSignatureVerifier.php:51,73`.
- **No `URL::signedRoute` or `temporarySignedRoute` anywhere.**
- **Every existing HMAC is verified inside Laravel.** No key is shared with an outside party.
- **Outbound `Http::` calls:**

  | Call | Timeout | Other |
  |---|---|---|
  | `GithubDispatchService.php:62-68` | 30 s | `Log::error` on failure |
  | `OneSignalProvisioningService.php:74-79` | 30 s | |
  | `OnesignalService.php:295,406` | 15 s | |
  | `OnesignalService.php:192` | **none** | |
  | `TwilioSmsProvider.php:88-90` | `services.twilio.timeout` | timeout is a config key (`config/services.php:262`) |
  | `GeocodingService.php:67` | 15 s | |
  | `OffsiteStore.php:212-213` | from target config | |
  | **`OnesignalInAppMessageService.php:148-157`** | **none** | runs synchronously inside the splash save; fail-soft |

- The **`isConfigured()` + warning** pattern: `OnesignalService.php:53,77-81`; `TwilioSmsProvider.php:55,63`.
- Secrets live in `config/services.php` as `env()` entries (github `:66-71`, twilio `:257-262`, onesignal `:286-307`).
- **Nothing calls the renderer or Cloudflare today.** Studio W1 plans a "Cloudflare attach" slice (`docs/manara-studio-w1.md:1056`); it is not built at this commit.

## Q5. Where a public website host is recorded
- **The `masjid_domains` table does not exist**: no hits in `database/`, `app/`, `routes/` or `config/`. W1 plans it in S3 and S9 (`docs/manara-studio-w1.md:556,1531`).
- No host or domain column on `masjids` turned up in a grep of migrations for `website_url`, `domain`, `custom_domain`, `web_host` or `site_url`. That grep is not exhaustive.
- Laravel knows organisation hosts only through `PORTAL_HOSTS` (the portal host mapped to an org id; `docs/tenant-host-map.md:133`).
- Renderer hosts live in the renderer's `NUXT_TENANT_HOSTS`. `mec-web` uses `DEFAULT_TENANT_HOSTS` in `nuxt.config.ts` instead (`docs/tenant-host-map.md:93-100`).
- **Laravel therefore cannot list the hosts for org X.**

## Q6. Test conventions and the route-table pins
- `tests/TestCase.php` is an empty base.
- `phpunit.xml`: `APP_ENV=testing` (`:21`), `CACHE_STORE=array` (`:24`), SQLite `:memory:` (`:25,30`), queue `sync` (`:33`).
- Pest is installed, but files use PHPUnit's `#[Test]` attribute (`CapabilityGateTest.php:83`).
- Feature tests `use RefreshDatabase` (`CapabilityGateTest.php:25`) and seed `RolesAndPermissionsSeeder` in `setUp` (`:37`). Helpers `admin(Masjid)` (MasjidAdmin, role `masjid-admin`) and `superAdmin()` are at `:66-74`, used with `Sanctum::actingAs(...)` (`:190,:335`).
- `Http::fake` is used in `tests/Feature/AppProvisioningTest.php`, `HostHeaderUrlIntegrityTest.php`, `ModuleSideDoorsTest.php` and `ModulesFailOpenTest.php`, and in `tests/Unit/OnesignalUnconfiguredTest.php` (line numbers not verified).
- **Pins a new admin route must pass:**
  - `CapabilityGateTest::every_capability_gate_in_the_route_table_names_a_real_catalogue_entry` (`:243`): every `capability:` key must exist in the catalogue.
  - `FamilyAuthGuardTest::a_family_token_is_refused_on_every_authenticated_admin_route` (`:291`) sweeps `routesBehind('api/admin','auth:sanctum')` (`:297`), replacing every parameter with `1` (`:207`). Auth must answer first.
  - `OrganisationModulesTest::every_module_admin_route_carries_its_gate` (`:253`).
  - `StaffAuthGuardPinTest` (`Permission::count()===8`, not opened).
  - `TenantScopingCoverageTest`, if a new model carries `masjid_id`.
- **Any public write** needs a named throttle (`api_v1.php:47-51`).
- **CI:** a SQLite suite plus a MySQL 8.0 migrations job (`tests.yml:111-160`). Production is 8.4.

## Q7. After-save hooks and logging
- **No observer or event on Page, Section, ThemeSetting or SplashAnnouncement.**
  - `app/Observers` holds only `UserObserver`, registered at `AppServiceProvider.php:106`.
  - `app/Listeners` holds only `ResetTenantContextBetweenJobs` (`:102`).
  - None of the four models has a `booted()` hook.
- What happens after a save today is inline in the controllers: the MobileCache flushes above, and the OneSignal IAM sync for splash (`Admin/SplashAnnouncementsController.php:75,125`).
- Logging: the default channel is `stack` and `LOG_LEVEL` defaults to `debug` (`config/logging.php:21,64,71`). Production sets `warning` (rules). The house style for a fail-soft third-party call is `Log::warning` (`OnesignalService.php:81,309`; `TrustedHosts.php:298-301`).

## Inconsistencies and risks
1. **Medium: Theme is outside the web-pages gate** (`routes/admin.php:407` against `:456`). The set of people who can change the theme is not the set who can edit pages. A preview token covering theme plus pages needs one decided predicate.
2. **Medium: Page and section saves are bound into other surfaces.** `button_page_id` slug resolution (`Section.php:146-157`) and the binder arms (`SectionContentBinder.php:75-81`) mean a page slug or `is_active` change, or an About/Donation/Form/Offering edit made on *another* screen, changes rendered pages. "Save goes live" misses those unless they purge too.
3. **Medium: Splash save makes a synchronous third-party call with no timeout** (`OnesignalInAppMessageService.php:148-157`). This is a precedent to avoid for a purge.
4. **Low: `findOrFail` inside a broad `catch (\Exception)`** in the page and section controllers (e.g. `PagesController.php:107-131`), so a miss reports as a 500, contrary to `tenant-scoping.md`.
5. **Low: `/v1/settings` skips `PublicTenant`** (`SettingController.php:39`), unlike pages (`SearchableTrait.php:94`).
6. **Low: `reorder` is not transactional** (`PagesController.php:142-147`).
7. **Low: CORS falls back to `*`** when the env variable is unset (`cors.php:23`).
8. **Low: Version drift.** `CLAUDE.md:3` says "Laravel 11"; the lockfile installs 12.64.0. CI's MySQL is 8.0; production is 8.4.
9. **Low: Unscoped lookup.** `Section::getContentAttribute` calls `Page::find` without masjid scoping (`Section.php:148,154`), so an id that belongs to another organisation would resolve to its slug.

## Open questions
- **The renderer's KV keys, purge surface and preview mode.** Not in this repo; the brief says none exists.
- **Header/footer style storage.** Whether it lives in theme `tokens` (`DesignTokens.php` not opened).
- **Production values** of `CORS_ALLOWED_ORIGINS` and `TRUSTED_HOSTS`. `.env` was not read.
- **`SplashAnnouncement` fields.** Its `$hidden` and serialized fields were not opened.
- **php-fpm reload in `bin/deploy`.** Not seen in `:213-240`.

## Elided (and why)
- Lint and format, localization, analytics, accessibility, and the SPA side of feature flags: not walked because the coordinator cut the run short.
- The web platform: not in the brief's scope.
- The CRM, family, teacher and lunch realms: not relevant to this change.

## Facts for preview design
- **Mint route placement.** A route inside the `Route::middleware(['capability:web_pages','capability:website'])` group (`routes/admin.php:456`) inherits exactly the page editors' gate, including the SuperAdmin bypass. Theme (`:407`) and splash (`:251`) have different gates.
- **Tenant for the token.** Read `TenantContext` first, then the route (the `EnsureOrgCapability.php:54` pattern). Never take it from the body or a header (`ResolveMasjidTenant.php:198-213`).
- **Token style to match.** `FormStaffCodes`: a versioned dotted string, a domain-separated `hash_hmac('sha256', "purpose|…")`, strict regex plus `hash_equals` plus expiry, and a length cap.
  - It is keyed with `APP_KEY` today, and no key leaves Laravel.
  - If the renderer is to verify tokens or signed purge calls by itself, it needs a separate shared secret. That would be a new `config/services.php` entry. Staging's deny-list regex would not blank it, and staging must point at `manara-renderer-staging`.
- **Unsaved content cannot come from the API.** Every `/v1` read serves saved, active rows only, chosen by the `masjid-id` header. There is no draft state (`Page` fields `Page.php:15-25`). Saved data read through `/v1` is always fresh because Laravel does not cache it. Splash is the one read Laravel caches, for 300 s, and a save already flushes it.
- **Where a purge call would hang.** Nothing fires on save beyond controller code: no observers, no model events. The write methods to touch:
  - `PagesController` `store`, `update`, `reorder`, `destroy`
  - `PageSectionsController` `store`, `update`, `destroy`, `attach`
  - `SectionsController` `store`, `update`, `destroy`
  - `ThemeSettingsController@save`
  - `SplashAnnouncementsController` mutations
  - Plus the binder sources (About, Donation link, Details, Forms, Offerings), menu-affecting page fields and slug changes. These are tenant-wide effects.
- **Purge by tenant id.** Laravel has no org→host map (no `masjid_domains`). Tenant 13 is served by two Pages projects (`manara-renderer` and `mec-web`), and staging has its own project.
- **Outbound call conventions to follow.**
  - An explicit, config-driven timeout (`services.twilio.timeout` style).
  - `isConfigured()`: no-op on blank credentials, returning a shaped `not_sent` result.
  - `Log::warning` or above on failure, and never fail the save.
  - Or dispatch it on the existing database queue. The worker restarts on deploy; tests run `sync`.
- **Framing.** Laravel responses carry `frame-ancestors 'none'` and `X-Frame-Options: DENY`. The admin SPA's `frame-src` must add the renderer origins (`SecurityHeaders.php:128`), per environment. `replace=false` means a route can set its own CSP first. The public renderer's framing is the renderer's own business.
- **URLs.** Any URL Laravel hands to the renderer or stores must be built with `SiteUrl` (`generated-urls.md`). `APP_URL` differs per environment.
- **Test obligations for a new admin route.**
  - Auth must answer first for the `FamilyAuthGuardTest` sweep.
  - Any `capability:` key must be in the catalogue.
  - No new spatie permission.
  - Include a form-encoded or string-boolean case.
  - Use `Http::fake` for the purge call.
  - A production twin for any non-production behaviour (`StagingSafetyTest` pattern).
- **Envelopes.**
  - Admin success is `{status:'success', data}`.
  - Errors are `{status:'error', message}`.
  - A 422 is `{status:'failed', data:{field:[…]}}` (`bootstrap/app.php:246-266`).
  - Public `/v1` uses the `api` macro, which drops empty keys.
