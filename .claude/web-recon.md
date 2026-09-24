commit: 4df524216d429457727b115c2cadecfa71158815
platform: web
scope: resources/vue-app (plus the Blade shell, SecurityHeaders and the admin routes it calls)
generated: 2026-09-23
dirty: clean
mode: quick (the five quick axes plus the nine briefed live-preview questions; stopped early at the coordinator's request)
kit: /Users/moneebsayed/.claude/engineering-excellence

# Recon: MasjidWebMS admin SPA, web

## Stacks
- The kit's detectors (run from the root) matched **web, backend**.
- **Web: a Vite SPA with Vue and no meta-framework.** Declared ranges: `vite ^6.0.11`, `@vitejs/plugin-vue ^5.2.1`, `vue ^3.5.13`, `vue-router ^4.5.0`, `pinia ^2.3.1`, `axios ^1.7.9`, `bootstrap ^5.3.3`, `vuedraggable ^4.1.0`, `vee-validate ^4.15.0` + `yup`, `sweetalert2`, `dompurify`, `typescript ^5.7.3` (package.json:9-48).
  - Lockfile: `package-lock.json` (npm). The lockfile-resolved versions were **not verified**.
  - Loaded `platforms/web/stacks/vite-spa.md` (Version policy and checklist only).
- Entry chain: `vite.config.js:11` input `resources/js/app.js`, which imports `../vue-app/main` (resources/js/app.js:17). The `@` alias points at `resources/vue-app` (vite.config.js:18).
- Backend: Laravel. The version comes from CLAUDE.md ("Laravel 11 + PHP 8.2") and was **not verified** against composer.lock.
- **Stack that is absent but matters:** the public site is a separate Nuxt repo (`~/Developer/burlington-masjid-site`, per docs/live-preview-brief.md:42-48). It is not in this tree and was not read.

## Binding instructions
- **`CLAUDE.md`** (root): tenancy by `masjid_id`, app-layer only.
- **`.claude/rules/shipping.md`**: booleans are sent as `"1"`/`"0"`. Adding a form field means two edits, the template and the serialiser. Drive the real page before calling it shipped.
- **`.claude/rules/environments.md`**:
  - `npm run build:prod` is **forbidden**; `VITE_APP_URL` must be empty.
  - Ship only with `scripts/ship.sh`.
  - Everything goes to staging first.
- **`.claude/rules/section-types.md`**: a section type lands in 7 places. Enforced by the lint `OfferingSectionTypeTest::every_section_type_is_wired_into_the_spa`.
- **`.claude/rules/generated-urls.md`**: no URL that outlives its request may be built from the request. Use `SiteUrl`. Enforced by `HostHeaderUrlIntegrityTest`.
- **`.claude/rules/auth-permissions.md`**:
  - Modules are read through `moduleIsOff` (fails open).
  - Never put a module key on `requiresCapability`.
  - SuperAdmins pass every `capability:` gate.
- **`resources/vue-app/components/sections/editors/CLAUDE.md:9-19`**: the editor contract is `modelValue`/`update:modelValue` only. The modal owns saving.
- **`docs/live-preview-brief.md:54-81`**: eight hard requirements for this feature. The ones that bind the SPA:
  - rule 4: the admin CSP must allow the renderer as a frame source;
  - rule 3: postMessage only from the admin origin;
  - rule 5: the tenant comes from the token, not the Host header.

## Shape and entry
- `main.ts` does three things in order:
  - `ApiService.init(app, API_CONFIG.base_url)` (main.ts:23);
  - reads the token and dashboard masjid id from localStorage (main.ts:32-33) and fetches the auth user, then `masjidStore.fetchMasjid()` (main.ts:42-72);
  - only then installs the router and mounts `#app` (main.ts:86-96).
- Shell: `resources/views/vue-app-index.blade.php:90-91` (`<body id="app"><admin-dashboard>`).

## Toolchain and floor
| Axis | Value | Source |
|---|---|---|
| Vite | ^6.0.11 declared | package.json:26 |
| Vue | ^3.5.13 declared | package.json:44 |
| TypeScript | strict: true | tsconfig.json:5 |
| Typecheck | **none runs**; no `vue-tsc` dependency | package.json:4-8 |
| Build-time env | only `VITE_APP_URL`, optional, empty when deployed | env.d.ts:17-24, appConfigConstants.ts:11 |
| Browsers | autoprefixer "last 2 versions, >1%, iOS>=12, Safari>=12" | vite.config.js:38-43 |
| Node engines | none declared | package.json |

- Because nothing typechecks, `strict: true` does nothing in practice.
- CI runs only `npm ci && npm run build` (.github/workflows/tests.yml:56-62).

## Architecture
- Pinia **setup stores**, one per domain (e.g. `stores/masjid/pagesStore.ts:10`). Each store calls a static `ApiService` and builds its URLs from `useMasjidStore().masjid.id`.
- Views use Bootstrap 5 markup. Modals are hand-rolled overlays (`modal fade show d-block`, SectionFormModal.vue:2-3), not Bootstrap's JS modal.
- Confirmations and toasts use SweetAlert (`Swal`, or `QSwal` from `core/plugins/SweetAlerts2`).
- Some screens skip the store and call `ApiService` directly: ThemeSettingsView.vue:287,305 and SplashAnnouncementFormView.vue:225.

## State, navigation, data, persistence
- **Router guard** (router.ts:16):
  - `meta.requiresCapability` is checked against `masjid.capabilities[key] === true` for non-SuperAdmins (router.ts:73-75).
  - `meta.requiresModule` goes through `moduleIsOff()` for non-SuperAdmins (router.ts:86-87).
- **Persistence:** localStorage holds only `MASJID_APP_AUTH_TOKEN` and `MASJID_APP_DASHBOARD_MASJID_ID` (appConfigConstants.ts:1-4).
- **Organisation switching:** request/response interceptors stamp and drop responses from a superseded tenant epoch (ApiService.ts:79-105).

## 1. Page builder
**Screens and routes** (pagesManagementRoutes.ts):

| Path | View | Meta |
|---|---|---|
| `/masjid/pages` | PagesView.vue | `requiresCapability:'web_pages'`, `requiresModule:'website'`, allowedUsers SuperAdmin/MasjidAdmin (:4-18) |
| `/masjid/sections-library` | SectionsLibraryView.vue | same (:19-33) |
| `/masjid/pages/:pageId/sections` | PageSectionsView.vue | same (:34-48) |

- Sidebar entry: `dashboardAsideMenuItems.ts:162-175`.
- Backend mirror: `Route::middleware(['capability:web_pages','capability:website'])` (routes/admin.php:456). It sits inside `auth:sanctum` + `admin` + `tenant` (routes/admin.php:110).

**Where a page's sections live while editing**
- PageSectionsView keeps a component-local `sections = ref<PageSection[]>` (PageSectionsView.vue:139). It is filled from `pagesStore.fetchPageSections` and sorted by `order` (:163-165).
- `pagesStore.currentPage` holds the page row (pagesStore.ts:14, :51).
- There is no store-level draft of the sections.

**Edit flow**
- Clicking Edit opens `SectionFormModal` with the section as a prop (PageSectionsView.vue:111-117, :183-186).
- The modal keeps its own `formData` ref: `{section_type, title, content, order, platforms, is_active, settings}` (SectionFormModal.vue:388-397), seeded from the prop (:481-494).
- The type's editor is bound with `<component :is="currentEditor" v-model="formData.content">` (:282-285).
- **Unsaved data exists only in this modal's `formData.value`**, plus the pending image `File`s in the `useSectionImages()` instance the modal `provide`s (SectionFormModal.vue:377-380; useSectionImages.ts:9).
- Closing the modal discards both.

**editorMap**
- `SectionFormModal.vue:424-468`, typed `Record<SectionType, any>`, with 27 entries matching the `SectionType` union (PageSection.ts:9-41).
- Palette metadata comes from `GET /api/admin/masjids/{id}/section-types` (pagesStore.ts:255-268). Each entry is a `SectionTypeInfo` carrying `default_content`, `has_renderer`, `renderer_note` and `module_off_note` (PageSection.ts:278-310).

**Save: one section at a time, never the whole page**
- The request body is **multipart `FormData`** (SectionFormModal.vue:583-605):
  - `section_type`, `title`;
  - `content` as a JSON string;
  - `order`;
  - `platforms` as a JSON string;
  - `is_active` as `'1'`/`'0'`;
  - `settings`, only when not empty;
  - one file per pending image, keyed by its content path (e.g. `image_url`, `members.0.photo_url`).
- Before sending, `stripBase64Images` replaces every `data:image/…` string in `content` with `null` (:554-576).
- Update: `_method=PUT` plus a POST to `/api/admin/masjids/{id}/pages/{pageId}/sections/{sectionId}` (:607-610 → pagesStore.ts:211-232).
- Create: a POST to `.../pages/{pageId}/sections` (pagesStore.ts:185-206).
- After success, the parent reloads the whole list (PageSectionsView.vue:193-195).
- Server side (PageSectionsController.php):
  - it **merges** top-level content keys into the stored ones (:140-155);
  - it writes `order`/`platforms` to the pivot (:161-171);
  - it attaches uploads through the media library and writes the URL back into `content` (:372-418).

**How images are uploaded**
- `ImageDraggableInput` reads the file as a data URL (ImageDraggableInput.vue:322,379).
- The editor puts that data URL into `content` and queues the `File` (e.g. ImageSectionEditor.vue:104-114).
- So **before save, an image in `formData.content` is a `data:` URL.**

**Other calls**
- Reorder: drag-end fires **one JSON `PUT {order}` per section** in `Promise.all` (PageSectionsView.vue:206-214 → pagesStore.ts:164-180).
- Detach: `DELETE .../pages/{p}/sections/{s}` (pagesStore.ts:237-250).
- Attach from the library: JSON `POST .../sections/attach {section_id, order, platforms}` (pagesStore.ts:353-369).

**Sections are shared library rows**
- Content lives on the `Section`; only `order`/`platforms` are per page (pagesStore.ts:311-313; PageSectionsController.php:129,158-171).
- **Editing a section on one page changes every page that uses it.**

**SectionsLibraryView**
- It lists sections (`fetchSectionsLibrary`, SectionsLibraryView.vue:260), shows which pages use each one (:162-205), and deletes (:309).
- It has no editor.
- Whether `createSectionInLibrary`/`updateSectionInLibrary` (pagesStore.ts:293-330) have any caller is **not verified**.

## 2. Theme editing
- **Screen:** `ThemeSettingsView.vue`, titled "Brand Studio" (:6).
  - It is a **tab** inside `MosqueDetailsTabsView.vue:53,95-96`, at route `/masjid/details` (dashboardLayoutRoutes.ts:32-39).
  - That route has **no** `requiresCapability`/`requiresModule`.
  - The backend `/theme` routes carry no capability gate either (routes/admin.php:407-410).
- **No store.** Local `settingsModel = {primary_color, secondary_color, accent_color, background_color}` (:161-166).
- **Calls:**
  - `GET /api/admin/masjids/{id}/theme` (:287);
  - `POST` to the same URL with that plain object, which the interceptor sends as **JSON** (:305; ApiService.ts:134-135).
- **Payload:** four hex strings (#RGB, #RRGGBB or #RRGGBBAA) or `''` (:168-176; SaveThemeSettingsRequest.php:14-20).
- **A client-side preview already exists.** `derived` is a TS port of `App\Support\DesignTokens` (:179-252), rendered as swatches and a mock card (:78-123).
- **`tokens` JSON:** the backend accepts it (SaveThemeSettingsRequest.php:21-24; ThemeSettingsController.php:30-36).
  - The SPA **never sends it**.
  - `ThemeSetting.ts:1-10` has no `tokens` field.
- **Fonts and header/footer *style* are not editable anywhere in the SPA.** A grep for `header_style|footer_style|font_family|heading_font|body_font` matched only flyer files.
- **What does exist for header/footer:** header logo, footer logo, copyright text and app links, in the `GeneralSettingsView` tab (GeneralSettingsView.vue:17-65). It saves as multipart `POST .../general-settings` (:163-177).
- Theme save flushes the mobile cache family (ThemeSettingsController.php:53).

## 3. Menu and page settings
- **`PageFormModal.vue` fields:** `title, slug, order, is_active, show_in_menu, show_as_button, meta_description` (:168-176, :184-192).
  - The slug is auto-generated on create only (:197-205).
  - Save is JSON `PUT .../pages/{id}` or `POST .../pages` (:211-216 → pagesStore.ts:64-101).
- **SEO:** `meta_description` only (the counter shows /160 characters, :115-121).
- The backend also accepts `page_title` and a `page_title_background_image` file (UpdatePageRequest.php:38-45; Page.ts:8-9). The modal sends neither.
- **Menu:** there is no separate menu editor.
  - The menu is `show_in_menu` / `show_as_button` / `order` on pages.
  - Reorder: drag in PagesView → JSON `POST .../pages/reorder {pages:[{id,order}]}` (PagesView.vue:219-227 → pagesStore.ts:374-388).
  - `PageMenuItem` (Page.ts:21-26) has no reader in the SPA.
  - Public read: `GET /api/v1/pages/menu` (routes/api_v1.php:168). The tenant is taken from the `masjid-id` request header (app/Traits/SearchableTrait.php:86).

## 4. Splash pop-up
- **Routes:** `/masjid/splash-announcements[/create|/:id/edit|/:id]`, all with `requiresModule:'splash'` (splashAnnouncementsManagementRoutes.ts:3-47). Backend: `capability:splash` (routes/admin.php:251-258).
- **Store:** list, show and delete only (splashAnnouncementsStore.ts:19-58).
- **Form** (SplashAnnouncementFormView.vue):
  - Local `form` ref (`title, body, cta_label, cta_url, starts_at, ends_at, priority, is_active`) plus `imageFile` (:121-131).
  - Save is **multipart** `POST` to `.../splash-announcements` on create, or `.../splash-announcements/{id}` on update (POST, not PUT) (:205-225).
  - Dates are sent as ISO strings and `is_active` as `'1'`/`'0'` (:211-214).
- **Type:** `SplashAnnouncement.ts:3-19`.
- **Model:** many rows, each with a time window and a priority.
- **Server:** each mutation flushes `MobileCache` SPLASH (SplashAnnouncementsController.php:80,130,155,182). The public current splash is served at `/{masjid_id}/splash` (routes/api.php:99).

## 5. ApiService and axios
- **Base URL:** `import.meta.env.VITE_APP_URL ?? ''` (appConfigConstants.ts:11). It is empty in deployed builds (env.d.ts:4-6), so every call is **same-origin relative**.
  - Separately, `vite.config.js:20-24` still defines `process.env.APP_URL`.
- **Global defaults** (ApiService.ts):
  - `withCredentials=true` (:28);
  - `X-Requested-With` (:29);
  - `Accept: application/json` and `Authorization: Bearer <token>` on `axios.defaults.headers.common` (:109-112);
  - a default **`Content-Type: multipart/form-data` with no boundary** (:112, :116-118);
  - `put`/`patch` rewrite that global default to `application/x-www-form-urlencoded` (:141-150).
- **The interceptor decides the real encoding from the body type** (:40-64), and `post()` repeats the same logic per request (:126-138):
  - `FormData`: the header is deleted so the browser adds a boundary;
  - `URLSearchParams`: urlencoded;
  - plain object: `application/json`.
- **Bearer token:** read from localStorage, then `ApiService.setHeader(TOKEN)` (main.ts:32,41).
- **Current masjid:** `useMasjidStore().masjid` (masjidStore.ts:21). `fetchMasjid` GETs `/api/admin/masjids/{id}/`, taking the id from `authStore.dashboardMasjidId` or localStorage (:70-93).
- **Public site URL for a masjid: there is no reliable field.**
  - The only candidate is `website_link` (Masjid.ts:47), typed by hand in MosqueDetailsView (:31-32, :284).
  - It is validated as just `nullable|string` (UpdateMasjidDetailsRequest.php:14) and is a unique nullable column (2025_02_06_092023_create_masjids_table.php:39).
  - There is no domain or host field. The renderer's host-to-organisation map lives in Cloudflare Pages `NUXT_TENANT_HOSTS` (docs/tenant-host-map.md:93-100,140).
  - A planned `masjid_domains` table (live-preview-brief.md:85-86): whether it exists at this commit is **not verified**.

## 6. SPA shell and CSP
**Blade shell** (`vue-app-index.blade.php`):
- Figtree loaded from fonts.bunny.net (:11-12).
- Montserrat self-hosted through `asset()` (:29-39).
- Icons (:41-43).
- **Inline scripts:** `window.__PORTAL_MASJID__` on hosts mapped in `config/portal.php` (:62-65), and `window.__APP_ENV__ = Environment::name()`, always (:84).
- `@vite('resources/js/app.js')` (:86).
- Both globals are declared in env.d.ts:39-45. There is no global for the admin's own origin or the renderer's URL.

**Headers.** `SecurityHeaders` is appended to both the web and api stacks (bootstrap/app.php:89). It sets its headers with `replace=false` (SecurityHeaders.php:135). It sends:
- `X-Frame-Options: DENY` (:36);
- `Cross-Origin-Opener-Policy: same-origin` (:43);
- the CSP (:114-134), exactly:
  - `default-src 'self'`
  - `script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net https://*.pusher.com https://js.pusher.com[+app.url]`
  - `style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://fonts.bunny.net https://cdn.jsdelivr.net[+app.url]`
  - `font-src 'self' https://fonts.gstatic.com https://fonts.bunny.net data:[+app.url]`
  - `img-src 'self' data: blob: https://*.supabase.co https://*.supabase.in https://maps.gstatic.com https://maps.googleapis.com[+app.url]`
  - `connect-src 'self' https://*.supabase.co https://*.supabase.in https://*.pusher.com wss://*.pusher.com https://onesignal.com https://*.onesignal.com[+app.url]`
  - **`frame-src 'self' https://www.google.com https://maps.google.com`** (never widened)
  - **`frame-ancestors 'none'`**
  - `form-action 'self'; base-uri 'self'; object-src 'none'; upgrade-insecure-requests`
  - There is **no `child-src`**.

**Widened paths**
- Only paths that start with `jummah-lunch` or `portal` (:93-103).
- The widening appends `config('app.url')` to script, style, font, img and connect only (:105-112). **Never to frame-src.**
- nginx adds no `X-Frame-Options` (deploy/TRUSTED-HOSTS-ENFORCEMENT.md:136-138).

## 7. Hosts serving the admin SPA
**Production** (docs/tenant-host-map.md:31-33; environments.md table):
- `masjid.hopetechapps.com`: DNS-only, `APP_URL`, the admin SPA.
- `manara.hopetechapps.com`: proxied. It has no vhost of its own and reaches Laravel through `default_server`. It carries `/auth/sign-in` and the SPA. The Worker takes only `/`, `/masjids`, `/schools` and `/community`.
- `portal.alrazischool.org`: same document root, own vhost, maps to organisation 14.

**Staging** (docs/tenant-host-map.md:39-41): `masjid-staging.`, `manara-staging.` and `portal-staging.hopetechapps.com`, all proxied.

The same bundle is also proxied onto organisation domains for `/portal` and `/jummah-lunch` only (SecurityHeaders.php:77-86).

## 8. Types, build, existing preview code
- **Types:**
  - `Page.ts:3-26`;
  - `PageSection.ts` (union :9-41, `PageSection` :44-59, `SectionContent` :62+, `SectionTypeInfo` :278-310);
  - `ThemeSetting.ts:1-10`;
  - `SplashAnnouncement.ts:3-19`;
  - `Masjid.ts:7-76`;
  - `Vertical.ts`, `Capability.ts`.
- **Build:** `npm run build` (vite build). `npm run dev` (vite). `build:prod` is forbidden (package.json:4-8; environments.md).
  - There is no tsc script. CLAUDE.md records a manual `tsc --noEmit` at 29 pre-existing errors; plain `tsc` does not check `.vue` files.
- **No iframe, postMessage or preview-mode code exists in the SPA.**
  - A grep for `<iframe|postMessage|addEventListener('message'|srcdoc` found only an unrelated chat method (groupThreadsStore.ts:116) and comments (SafeHtml.vue:24; ContactCredentialsPanel.vue:170,698).
  - "Preview" hits are local mocks only: the Brand Studio palette, FlyerPreview, and the attach-mode "Section Preview" alert (SectionFormModal.vue:71-84).

## 9. Patterns to reuse
- **FlyerStudioView.vue** is the closest parallel:
  - a `col-lg-5` form beside a `col-lg-7` preview (:64, :107);
  - `.preview-column { position: sticky; top: 1rem }` (:418-421);
  - a props-driven `<FlyerPreview :content :cssVars @overflow>` (:134-140);
  - warning alerts stacked above the preview (:109-132).
- **ThemeSettingsView.vue:** a `col-lg-6` editor beside a "Derived Palette (live preview)" column (:18, :79-80).
- **Segmented toggle** (for desktop/tablet/phone): a Bootstrap `btn-group` of `btn-check` radios (SectionFormModal.vue:15-43, with styling at :652-655).
- **Editor contract:** v-model only (editors/CLAUDE.md:9-12). The pane can watch `formData.content` deeply without touching any editor.
- **Recency** of these candidates (`git log`) was not checked.

## Closest parallel features
1. `views/dashboard/FlyerStudioView.vue` + `components/flyer/FlyerPreview.vue`. Use for the split pane and a sticky, props-fed preview.
2. `components/modals/SectionFormModal.vue`. Use for where live content comes from (`formData`, `sectionImages`) and the save sequence the preview must mirror.
3. `views/dashboard/ThemeSettingsView.vue`. Use for the theme source of truth (`settingsModel`) and the existing token derivation.

## Inconsistencies and risks
- **High (for this feature):** the SPA CSP `frame-src` allows only `'self'` and Google (SecurityHeaders.php:128), so any iframe of the Nuxt site is blocked today.
- **Medium:** section content is shared across pages (pagesStore.ts:311-313). A preview of page A shows nothing about the same edit's effect on page B.
- **Medium:** section reorder is N separate PUTs (PageSectionsView.vue:209-213). A partial failure leaves a mixed order.
- **Medium (inferred from PagesView.vue:222-225):** page reorder numbers the current *pagination* page 1..n, so reordering on page 2 collides with page 1's orders.
- **Medium:** the `tokens` comment says "The Brand Studio sends a structured tree" (SaveThemeSettingsRequest.php:22-23). The SPA never sends one (ThemeSettingsView.vue:305), and the type lacks it.
- **Medium:** the theme screen and theme API are ungated (dashboardLayoutRoutes.ts:32-39; routes/admin.php:407-410), while pages need `web_pages` + `website`. "Who may preview" differs per surface.
- **Low:** a route comment says SuperAdmins are blocked when `website` is off (pagesManagementRoutes.ts:10-12). The guard exempts them (router.ts:86-87), and so does the backend (auth-permissions.md).
- **Low:** shipping.md says ApiService pins a global urlencoded default. The code pins boundary-less multipart (ApiService.ts:112,116) and then rewrites per body type (:40-64). The service also mutates `axios.defaults` on every put/patch (:142,148), which the kit's checklist forbids. `pagesStore.ts:193,219` hand-set a multipart header that the interceptor removes again.
- **Low:** there is no typecheck in CI (tests.yml:56-62). `editorMap` exhaustiveness is enforced only by the PHP lint test.

## Open questions
- **The public site URL per organisation:** no DB field exists; `website_link` is free text. Checked: Masjid.ts, masjids migrations, UpdateMasjidDetailsRequest. Whether `masjid_domains` exists: **not verified**.
- **Lockfile-resolved versions:** not verified.
- **Callers of `createSectionInLibrary`/`updateSectionInLibrary`:** not verified.
- **Whether axios 1.x runs request interceptors before `transformRequest`:** inferred, not verified. This decides that plain objects really go as JSON.
- **Renderer CSP and `img-src` for the `data:` image URLs a preview would carry:** separate repo, not read.

## Elided
Analytics, localization, accessibility, feature flags (beyond capabilities and modules), concurrency, DI, module visibility, release identity, device reality: not walked, because the coordinator asked me to stop. Testing is covered only for CI and typecheck.

## Facts for preview design
1. **Where live data comes from:**
   - Sections: `SectionFormModal`'s `formData.value` (`content`, `title`, `is_active`, `platforms`, `order`), SectionFormModal.vue:388-397.
   - Pending images: `data:` URLs inside `content` (ImageDraggableInput.vue:322).
   - Theme: `settingsModel` (ThemeSettingsView.vue:161-166).
   - Page settings: `PageFormModal` `formData` (:168-176).
   - Splash: the `form` ref plus `imageFile` (SplashAnnouncementFormView.vue:121-131).
   - All four are component-local; **no store holds a draft.**
2. **Save paths the "Save publishes" step must follow:**
   - sections: multipart POST with `_method=PUT`, one section per request;
   - theme: JSON POST;
   - page: JSON PUT;
   - reorder: JSON POST;
   - splash: multipart POST.
   - The server merges section content keys (PageSectionsController.php:140-155), so a preview should render **stored content merged with the edits**, not the edits alone.
3. **The editor is a modal overlay** (`modal-xl`, SectionFormModal.vue:2-3). A side-by-side pane means turning it into an in-page panel (the FlyerStudio layout), or putting the iframe inside the modal body.
4. **The admin CSP must gain the renderer origin(s) in `frame-src`** (SecurityHeaders.php:128). That middleware runs on every response, so scope the widening to the admin shell (the pattern at :93-112).
   - The SPA's own `frame-ancestors 'none'` and XFO DENY do not stop it framing others.
   - COOP `same-origin` does not block iframe postMessage (inferred).
5. **The admin origin is plural:** `masjid.hopetechapps.com` and `manara.hopetechapps.com` in production, and their `-staging` twins. The renderer's `frame-ancestors` and postMessage origin allowlist must name each one (tenant-host-map.md:31-41).
6. **The SPA cannot work out the renderer URL for an organisation.** The token-issuing endpoint, or a new admin payload field, must supply the preview URL.
   - Build it with `SiteUrl`, not from the request (generated-urls.md).
   - Do not bake it into the bundle: `VITE_*` must stay empty (environments.md).
7. **The token is a Sanctum bearer in localStorage**, sent as a global Authorization header (ApiService.ts:111). A preview token must come from a new admin endpoint and must never ride in the iframe URL beyond its short TTL.
8. **Gates to mirror:**
   - pages: route meta `requiresCapability:'web_pages'` + `requiresModule:'website'`; server `capability:web_pages,website` separately (admin.php:456);
   - splash: `requiresModule:'splash'`;
   - theme and general settings: ungated.
9. **Scope gaps:** fonts and header/footer style have no editor and no field in the SPA. Theme `tokens` are never sent. Menu = page `order`/`show_in_menu`/`show_as_button`.
10. **Nothing to extend:** no iframe, postMessage or preview code exists. Reuse the FlyerStudio sticky preview column and the `btn-check` segmented group for the device toggle.
11. **Verification:** build is `npm run build` only, with no typecheck in CI. Admin screens must be driven on staging with the QA sandbox organisation before calling them done (shipping.md).
