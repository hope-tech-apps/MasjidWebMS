# Live preview for the page tool — plan and contract

Written 2026-09-24 by the delegated live-preview session, on the engineering-excellence kit.
The brief is `docs/live-preview-brief.md`; this document is the contract that answers it.
Recon: `.claude/web-recon.md`, `.claude/backend-recon.md` (this repo) and
`burlington-masjid-site/.claude/web-recon.md` (the renderer).

Status markers below: **verified** = read in installed source or observed live on
2026-09-24; **assumed** = a reasoned choice not yet proven; **unknown** = needs investigation.

---

## 1. What the owner decided (not relitigated)

| Question | Decision |
|---|---|
| Save model | Preview as you type. Save publishes, and Save goes live immediately (no 5-minute wait) |
| Who | Everyone who can edit pages, client admins included: client-grade security |
| Scope | Page sections, theme (colours, fonts, header/footer style), menu and page settings, splash pop-up |
| Fidelity | The real Nuxt renderer, in a signed preview mode, at desktop / tablet / phone widths |

## 2. The design in one paragraph

The admin asks Laravel for a **preview session**. Laravel signs a five-minute token naming one
organisation, one surface (pages, theme or splash), one path and the admin origin, and returns a
URL on a **dedicated preview host** (the renderer project's own `*.pages.dev` host, which serves
no tenant). The admin puts that URL in an iframe. The renderer sees the path prefix
`/__manara/preview/`, which **no cache rule covers**, so Nitro routes it to the uncached catch-all
renderer; a Nitro `render:before` hook verifies the token, takes the tenant **from the token**,
rewrites the path to the real page and stamps no-store, noindex and a `frame-ancestors` naming the
admin origin. The page that comes back is the saved site, rendered by the real components. After
hydration a client plugin accepts `postMessage` from **that admin origin only**, validates the
payload, and overlays the unsaved edits on the Pinia store the components already render from, so
every keystroke repaints without a reload. Unsaved content never leaves the admin's browser except
into that iframe. On Save, Laravel calls a **signed purge endpoint** on the renderer, which deletes
that organisation's entries from `MANARA_PAGE_CACHE`, so the next visitor gets a fresh render.

```
 admin SPA (masjid.hopetechapps.com)               Laravel                         renderer (Pages)
 ─────────────────────────────────────            ───────                         ────────────────
 1 POST .../pages/preview-session {path} ───────▶ gate = the save route's gate
                                                   sign {o,s,p,a,e}  ◀── shared secret ──▶ verify
   ◀──── {url: https://manara-renderer.pages.dev/__manara/preview/about?mp=v1.…}
 2 <iframe src=url> ───────────────────────────────────────────────────────────▶ router: /** (uncached)
                                                                                  render:before: verify,
                                                                                  tenant := token.o,
                                                                                  path := /about,
                                                                                  headers: no-store,
                                                                                  noindex, frame-ancestors a
   ◀──── postMessage {ready} (targetOrigin = a) ───────────────────────────────── client plugin
 3 postMessage {overrides} (targetOrigin = preview origin) ───────────────────▶ origin === a && source === parent
                                                                                  → validate → overlay store
 4 Save ────────────────────────────────────────▶ write DB (as today)
                                                   after response:
                                                   POST /__manara/purge {org} ──▶ HMAC + ts window
                                                        signed                    delete org's KV keys
```

## 3. Why these mechanisms (the facts they rest on)

- **The page cache is per path, not per host.** At build, Nitro inserts a copy of the renderer
  handler for every route rule with `cache` (`nitropack/dist/rollup/index.mjs:1077`,
  `extendMiddlewareWithRuleOverlaps`), and wraps each copy in `cachedEventHandler` at startup
  (`nitropack/dist/runtime/internal/app.mjs:124-135`). The catch-all `/**` renderer is wrapped only
  if a rule matches `/_`; none does. Paths such as `/__manara/preview/about` therefore reach the
  **uncached** renderer. Verified in nitropack 2.13.4.
- **Why not a query flag on the real path.** A cached handler keys on path + query + `varies`
  headers (`cache.mjs` `getKey`), so `/about?preview=…` is stored under its own KV key: stored is
  stored. `shouldBypassCache` exists but route rules are JSON (`route-rules.mjs`), so no function
  can be passed. Verified.
- **The path rewrite point.** Nitro's `defineRenderHandler` calls the `render:before` hook after
  the router has matched and before Nuxt renders (`nitropack/dist/runtime/internal/renderer.mjs`).
  Nuxt reads `event.path` for `ssrContext.url` (`@nuxt/nitro-server/.../utils/renderer/app.mjs:23`),
  and the browser router uses `payload.path` when it differs from the address bar
  (`nuxt/dist/pages/runtime/plugins/router.js:74`), so the rewritten page hydrates as the real one.
  `getRouteRules(event)` is memoised from the prefix path, so Nuxt's payload store (written only for
  cache-rule paths, `renderer.mjs:96,164-167`) is not written either. Verified by reading; proven
  end to end in the workerd run (§7.3).
- **Why a dedicated preview host.** The tenant must come from the verified token (brief rule 5),
  which only a host that serves no tenant makes unambiguous. `manara-renderer.pages.dev` answers
  every request today with `x-manara-tenant: unresolved` and a 404 (`docs/tenant-host-map.md`), W1
  excludes `.pages.dev` from its host lookup (`docs/manara-studio-w1.md` S10), and Studio drafts
  that have no host yet can be previewed the same way. A tenant's own domain **never** enters
  preview mode, so its caching and framing cannot change.
- **Why overrides in the browser, not in the request.** Everything the page shows is derived from
  the Pinia store `app` (`settings`, `pages`), and the page, menu, buttons, theme and header/footer
  variant are computed from it reactively (`stores/app.ts:142-300`, `layouts/default.vue:130-148`,
  `pages/[...slug].vue`). Writing unsaved values into that store repaints with the real components.
  The renderer holds no credential, so a server-side draft would need a new authenticated read; the
  browser route stores nothing anywhere.

## 4. The contract

### 4.1 Configuration

| Side | Key | Meaning | Default |
|---|---|---|---|
| Laravel | `RENDERER_SHARED_SECRET` → `services.renderer.secret` | HMAC key shared with the renderer; ≥ 32 characters or the integration is off | blank = off |
| Laravel | `RENDERER_PREVIEW_ORIGIN` → `services.renderer.preview_origin` | origin the preview iframe loads | blank = off |
| Laravel | `RENDERER_PURGE_ORIGINS` → `services.renderer.purge_origins` | comma list of renderer deployments to purge on save | blank = no purge |
| Laravel | `RENDERER_PREVIEW_ADMIN_ORIGINS` → `services.renderer.admin_origins` | comma list of admin SPA origins a token may name | `SiteUrl::base()` |
| Laravel | `RENDERER_TIMEOUT` → `services.renderer.timeout` | seconds for each purge call | 5 |
| Renderer | `NUXT_MANARA_SHARED_SECRET` → `runtimeConfig.manaraSharedSecret` | same secret, set as an encrypted Pages secret | blank = off |
| Renderer | `NUXT_PREVIEW_HOSTS` → `runtimeConfig.previewHosts` | comma list of hosts allowed to serve previews; a host in the tenant map is refused | blank = off |
| Renderer | `NUXT_PREVIEW_ADMIN_ORIGINS` → `runtimeConfig.previewAdminOrigins` | comma list of origins allowed to frame and message a preview | blank = off |

Every key fails **closed**: blank means no preview, no purge, and a public site identical to
today's. Production values are set by the point session with the owner's go (§8).

### 4.2 The preview token

```
v1.<payload>.<signature>
payload   = base64url(JSON {"o":13,"s":"pages","p":"/about","a":"https://masjid.hopetechapps.com","e":1790000000})
signature = base64url(HMAC-SHA256(secret, "manara-preview|v1|" + payload))
```

| Claim | Rule |
|---|---|
| `o` | organisation id, integer 1 … 9 999 999 999, taken from the bound tenant, never from the body |
| `s` | surface: `pages`, `theme` or `splash`; decides which overrides the renderer accepts |
| `p` | the one path this token may render: starts with `/`, not `//`, no `\`, `..`, control characters or `?`, ≤ 512 characters |
| `a` | the admin origin; must be in Laravel's and the renderer's allowlists |
| `e` | expiry, unix seconds; 300 s after issue; the renderer refuses `e` more than 900 s ahead |

The renderer compares signatures in constant time. Any failure (absent, malformed, bad signature,
expired, wrong host, wrong path, unknown origin, bad id) renders **the ordinary response for that
URL**: on the preview host that is the existing SiteNotFound 404; on a tenant host it is that
tenant's ordinary page-not-found. Nothing says why.

### 4.3 Minting (Laravel)

One route per surface, each placed **inside the group its save route lives in**, so the gate is
identical by construction:

| Route | Group (routes/admin.php) | Surface |
|---|---|---|
| `POST /api/admin/masjids/{masjid_id}/pages/preview-session` | `capability:web_pages` + `capability:website` | `pages` |
| `POST /api/admin/masjids/{masjid_id}/theme/preview-session` | theme group (admin + tenant only, as its save) | `theme` |
| `POST /api/admin/masjids/{masjid_id}/splash-announcements/preview-session` | `capability:splash` | `splash` |
| `POST /api/admin/masjids/{masjid_id}/theme/preview` | theme group | none: returns the resolved theme for unsaved colours/tokens, writes nothing |

Request `{path}` (default `/`). The admin origin is the request's `Origin`, accepted only if it is
in the allowlist. Responses:

- configured: `{status:"success", data:{enabled:true, url, origin, expires_at}}`, `url` built from
  `services.renderer.preview_origin` (never the request host);
- unconfigured, or an origin outside the allowlist: `{status:"success", data:{enabled:false, reason}}`
  so the SPA hides the pane instead of showing an error;
- invalid `path`: 422 `{status:"failed", data:{path:[…]}}`.

`theme/preview` exists because the renderer prefers `theme.tokens.color.*` over the four flat
colours (`app/utils/tenantTheme.ts:246-262`); only the server's `DesignTokens::resolve` gives the
exact tree Save will publish (W1 R13 made the same call).

### 4.4 The preview request (renderer)

`GET {preview origin}/__manara/preview{p}?mp={token}[&other query]`

Honoured only when **all** hold: the path starts with `/__manara/preview/`; the raw `Host` is in
`previewHosts` and is not a tenant host; the token verifies; `p` equals the path after the prefix.
Then, in `render:before`:

1. `event.context.manaraTenant` := the tenant-map record for `o` (name, favicon, locale), with
   `host` set to the preview host so `tenant-guard.client.ts` stays quiet; a bare `{id}` record for
   an organisation not in the map;
2. `event._path` and `req.url` := `p` plus the query without `mp`;
3. `event.context.manaraPreview` := `{org, surface, adminOrigin, path, expiresAt}`, which a server
   plugin puts in `useState('manara-preview')` (the payload) and uses to add
   `<meta name="robots" content="noindex,nofollow">`;
4. response headers: `Cache-Control: no-store, private`, `X-Robots-Tag: noindex, nofollow, noarchive`,
   `Content-Security-Policy: frame-ancestors {a}`, `Referrer-Policy: no-referrer`,
   `X-Manara-Preview: 1`, `X-Manara-Tenant: {o}`.

A public request never reaches step 1: the hook returns on the prefix test, and no public page
creates the `manara-preview` state, so public HTML and payloads are unchanged.

### 4.5 Messages

Both directions carry `{source, v: 1, type, …}`. The renderer posts only to `a` (never `*`); the
admin posts only to the preview origin and accepts only messages whose `source` is its own iframe's
window and whose origin is the preview origin.

| Direction | `type` | Body |
|---|---|---|
| renderer → admin | `ready` | `{path, org, surface}` after hydration and after each client navigation |
| admin → renderer | `overrides` | `{overrides}` (below); replaces the previous overrides entirely |
| admin → renderer | `navigate` | `{path}`; a client-side route change inside the preview |

The renderer accepts a message only when `event.origin === a` **and** `event.source === window.parent`
**and** preview state exists **and** `parseAdminMessage` accepts it. Overrides by surface:

| Key | Surfaces | Shape | Applied as |
|---|---|---|---|
| `theme` | theme | `{primary?, secondary?, accent?, background?, tokens?}` as `/api/v1/settings` serves it | replaces `settings.theme` |
| `pages` | pages | `[{id, slug?, title?, page_title?, meta_description?, is_active?, show_in_menu?, show_as_button?, order?, sections?}]` | merged into `pages` by `id`; an unknown id is appended; sections merged by `id` with `content` merged key by key (as `PageSectionsController@update` merges), unknown ids appended; the staff-contact redaction runs on the result |
| `splash` | splash | `null` (show none) or `{id, title, body?, cta_label?, cta_url?, image?}` | shown by `SplashModal` instead of the fetched splash, ignoring the session dismissal |

Limits: serialised size ≤ 4 MB (pending images travel as `data:image/…` URLs), ≤ 200 pages, ≤ 200
sections per page, object depth ≤ 12, no `__proto__` / `constructor` / `prototype` keys, colours
must be hex or empty. Anything else is dropped without a reply.

The overlay keeps the last store value **it did not write** as the baseline, and re-applies the
overrides whenever the store is replaced (the browser refetches `settings` and `pages` after
hydration because their `useAsyncData` keys are random, `useApi.ts:64`).

### 4.6 Purge

`POST {renderer origin}/__manara/purge`

```
X-Manara-Timestamp: 1790000000
X-Manara-Signature: v1=<hex HMAC-SHA256(secret, "manara-purge|v1|" + timestamp + "|" + rawBody)>
Content-Type: application/json

{"v":1,"org":13}
```

- Refused (401 `{"ok":false}`) on a bad signature or a timestamp more than 300 s from the
  renderer's clock. 404 when the secret is not configured. Idempotent: a replay inside the window
  deletes nothing new.
- It lists `MANARA_PAGE_CACHE` under `nitro:routes:{name}:` for each cache-rule `name` in the
  running config (the build id), and deletes the keys whose `host`, `xforwardedhost` or (after W1
  S10) `xmanaratenantkey` segment is Nitro's `hash()` of one of the organisation's hosts in the
  tenant map (or of `t{org}`). If the organisation has a wildcard host, every key in the namespace
  is deleted instead, and that is logged.
- At most 800 deletions per call; the response is `{"ok":true,"scanned":n,"deleted":n,"remaining":bool}`
  and Laravel repeats while `remaining` (at most 5 calls).
- Laravel calls it **after the response** of every successful write that changes what the site
  shows: pages (store, update, reorder, destroy), page sections (store, update, destroy, attach),
  the section library (store, update, destroy), theme save, and general settings (logos,
  copyright) — the `renderer.purge` route middleware, pinned route by route by
  `RendererCachePurgeTest::the_writes_that_purge_are_exactly_the_site_editors_writes`. Timeouts
  from config; failures log at `warning` (production runs `LOG_LEVEL=warning`) and never fail the
  save. Splash needs no purge: it is fetched in the browser and its Laravel cache is already
  forgotten on save.
- **A second pass 75 s later** (`App\Jobs\PurgeRendererCacheAgain`, queued, unique per
  organisation for its delay). The renderer finds keys by listing KV, and KV's list is eventually
  consistent: measured on staging, a page warmed in another region seconds before a save was not
  in the first pass's listing and would otherwise have been served for its whole 5-minute window.

**What "immediately" means — measured on staging, 2026-09-24.** After a save, the purge deletes the
organisation's entries at once, but Cloudflare KV caches reads at each location for about 60 s, so
a visitor elsewhere keeps the old page until that read cache lapses: the fresh render appeared **64 s
after the save** (the entry was 3.5 minutes short of expiring, so the purge, not expiry, did it).
Worst case with the second pass: about 75 s + 60 s. Before this work: up to 5 minutes plus one
stale-while-revalidate request. Getting to "the next request, everywhere" would need a strongly
consistent store for the page cache (a Durable Object, or no shared HTML cache); that is a
separate decision for the owner (§9).

## 5. The eight hard requirements, and the test that fails without each

| # | Requirement | Mechanism | Test that fails without it |
|---|---|---|---|
| 1 | A preview render is never cached nor served from cache | prefix path on the uncached renderer; `render:before` rewrite | renderer `preview-cache-bypass.test.ts` (no cache rule may cover `/__manara/**` or `/**`; the hook tests the prefix before anything else); **workerd run**: KV key list identical before and after 20 preview requests, and public body hashes unchanged |
| 2 | Short-lived API-signed token, one org (and page), editors only | §4.2, §4.3 | `previewToken.test.ts` (every refusal renders ordinary); Laravel `LivePreviewSessionTest` (each surface's gate, other org 403, token org from the route) and `PreviewTokenVectorTest` (the same vector both sides) |
| 3 | Unsaved content only from the admin | origin + source + state + validator | `previewMessage.test.ts`; `previewBridge.test.ts` (a message from any other origin, from a non-parent window, or without preview state changes nothing) |
| 4 | Framing opened only for preview | `frame-ancestors {a}` on preview responses only; admin CSP `frame-src` gains the preview origin only when configured | `previewDecision.test.ts` (headers only on a verified preview); Laravel `SecurityHeadersPreviewFrameTest` (unconfigured CSP byte-identical to today's; configured adds exactly the origin) |
| 5 | Never another tenant; tenant from the token | `manaraTenant` from `o`; tenant hosts refused as preview hosts | `previewDecision.test.ts` (Host names org 1, token names 13 → 13; a tenant host in `previewHosts` → refused) |
| 6 | Save goes live immediately, via a signed renderer endpoint | §4.6 | `pageCachePurge.test.ts` (signature, window, host-hash matching against Nitro's key format, limits); Laravel `RendererCachePurgeTest` (every listed write purges once, signed, after response; unconfigured sends nothing; failure logs and the save still succeeds); **workerd run**: purge removes that org's keys and no other org's |
| 7 | `noindex` on every preview response | header + meta | `previewDecision.test.ts`; workerd run checks both on a real response |
| 8 | Live tenants byte-identical | nothing changes off the preview host | visible-text hashes of `/`, `/about`, `/services` on the five hosts before and after each deploy; payload guard still 404s; `tenant-unchanged.test.ts`, `payload-isolation.test.ts` and `page-cache-build-key.test.ts` pass **unedited** |

## 6. Slices, in ship order

Each slice ships dark: with its config blank, nothing any visitor or admin sees changes.

| # | Repo | Slice | Live impact | Rollback |
|---|---|---|---|---|
| R1 | renderer (`main` only) | `shared/manaraSignature.ts`, `shared/pageCachePurge.ts`, `server/routes/__manara/purge.post.ts`, three runtime-config keys | none: 404 until the secret is set | Pages rollback |
| R2 | renderer (`main` only) | preview mode: `shared/previewToken.ts`, `previewDecision.ts`, `previewMessage.ts`, `previewOverrides.ts`; `server/plugins/manara-preview.ts`; `app/plugins/manara-preview.{server,client}.ts`; SplashModal hook | none: `/__manara/preview/*` renders as today until hosts, origins and secret are set | Pages rollback |
| L1 | MasjidWebMS | `config/services.php` renderer block; `App\Support\Renderer\{RendererConfig, PreviewToken}`; the four routes in §4.3; tests | none: `enabled:false` while unconfigured | revert |
| L2 | MasjidWebMS | `App\Support\Renderer\RendererCachePurge` + the after-response calls in §4.6 | none while unconfigured; configured, saves go live at once | revert, or blank `RENDERER_PURGE_ORIGINS` |
| L3 | MasjidWebMS | `SecurityHeaders` adds the preview origin to `frame-src` when configured | none while unconfigured | revert |
| L4 | MasjidWebMS SPA | `LivePreviewPane.vue` (iframe, desktop 1280 / tablet 834 / phone 390, scaled to fit), `usePreviewBridge.ts`; wired into the section editor, page settings + menu order, Brand Studio, splash form | the pane appears only when a session says `enabled:true` | revert + rsync |
| L5 | MasjidWebMS SPA | Brand Studio controls for the three token families the renderer already reads: heading/body font (`tokens.typography.headingFamily/bodyFamily/fontsUrl`), header style (`tokens.layout.header`), footer style (`tokens.layout.footer`) | new controls on the Brand Studio; nothing changes until an admin saves one | revert |

L5 exists because the owner put fonts and header/footer style in scope, and no screen edits them
today (the SPA sends only four colours, `ThemeSettingsView.vue:305`). It is a new editing
capability, not only a preview, so it ships last and separately.

Order of deployment: R1+R2 (dark) → L1-L3 (dark) → L4 → staging configured end to end and
verified (§7.4) → hand-back. Production is the point session's, with the owner's go.

## 7. Tests and verification

### 7.1 Renderer, `npm test` (`node --test`, pure `shared/` code, no `node_modules`)

`manara-signature`, `preview-token` (including the cross-language vector), `preview-decision`,
`preview-message`, `preview-overrides`, `page-cache-purge`, `preview-cache-bypass`. The three
existing isolation tests run unedited.

### 7.2 Laravel (SQLite suite on the CI tree)

`PreviewTokenVectorTest`, `LivePreviewSessionTest` (form-encoded and JSON bodies; forged Host;
unconfigured; origin allowlist; each gate), `ThemePreviewEndpointTest` (writes nothing),
`RendererCachePurgeTest` (`Http::fake`), `SecurityHeadersPreviewFrameTest` (with its production
twin: unconfigured output is today's). The route-table pins (`CapabilityGateTest`,
`FamilyAuthGuardTest`, `OrganisationModulesTest`, `StaffAuthGuardPinTest`) must pass.

### 7.3 Workerd integration (the kit's method, in the target runtime)

A Cloudflare build (`DEPLOY_TARGET=cloudflare`, `ISR_SECONDS=300`) under `wrangler pages dev`
with a local KV store and a stub API: list KV keys, hash public bodies on two hosts, make preview
requests (valid, expired, wrong host, wrong path), list keys and hash again, purge one org, list
again. Script and results recorded in the renderer's `docs/live-preview.md`.

### 7.4 Staging

Renderer branch deployed as the `live-preview` alias of `manara-renderer-staging` (direct upload,
preview environment, its own KV binding `dfd5ece5…`, staging API). Staging Laravel configured with
a **staging-only** secret. Then, driven in a browser as the QA sandbox admin: pane loads at three
widths; a section edit, a theme colour, a menu toggle and a splash draft repaint without reload;
Save goes live on the staging renderer; a wrong-origin message changes nothing; the preview
response headers and the absence of any new KV key are read directly.

## 8. What the point session does at production ship (owner's go)

Order: renderer first (dark), then MasjidWebMS (dark), then the configuration, then verify.

0. Merge renderer `feat/live-preview` to `main`, build from the committed tree
   (`DEPLOY_TARGET=cloudflare`, as today) and deploy `manara-renderer`. With no configuration it is
   dark: RBI on the five hosts must be byte-identical and the payload guard must still 404.
   Merge MasjidWebMS `feat/live-preview` and `scripts/ship.sh production`: dark too (every preview
   route answers `enabled:false`, no save calls anything, the CSP is unchanged).

1. Generate one secret (≥ 32 random characters) and put it, without it passing through a chat,
   argv or history (`scripts/set-server-secret.sh`), into production Laravel as
   `RENDERER_SHARED_SECRET` and into `manara-renderer` production as the encrypted secret
   `NUXT_MANARA_SHARED_SECRET`.
2. Laravel: `RENDERER_PREVIEW_ORIGIN=https://manara-renderer.pages.dev`,
   `RENDERER_PURGE_ORIGINS=https://manara-renderer.pages.dev`,
   `RENDERER_PREVIEW_ADMIN_ORIGINS=https://masjid.hopetechapps.com,https://manara.hopetechapps.com`.
3. Renderer: `NUXT_PREVIEW_HOSTS=manara-renderer.pages.dev`,
   `NUXT_PREVIEW_ADMIN_ORIGINS=https://masjid.hopetechapps.com,https://manara.hopetechapps.com`.
   `wrangler pages secret put` writes the production environment, which is the one
   `manara-renderer` serves from, so all three can be set that way (value on stdin). On staging the
   preview is a branch alias in the PREVIEW environment, which wrangler 4 cannot write; its three
   keys were added with one Pages API PATCH (merge semantics, verified by downloading the project
   config before and after).
4. The next ship after the `.env` edits caches config; `bin/deploy` restarts the queue worker,
   which runs the second purge pass.
5. Verify as on staging (§10): a preview session from the admin, the frame's headers, a save
   followed by a fresh render, and RBI again.
6. Optional, recommended: add `https://manara-renderer.pages.dev` to `CORS_ALLOWED_ORIGINS`. Without
   it the preview still works (the store keeps the server-rendered data), but browser-side reads
   inside the preview (events pagination, offering seat re-read, a page not yet in the store) fail
   quietly. W1 S9 keeps the env list as its base, so this carries over.

## 9. Known limits and open questions

- **Owner decision needed: "immediately" is about a minute, not the next request.** See §4.6.
  Options: accept (recommended: it is a 5x improvement and needs nothing new), or move the page
  cache to a strongly consistent store, which is new infrastructure and its own project.

- **`mec-web` is not purged.** It builds from `cloudflare-migration`, which W1 leaves untouched, so
  `mec-web.pages.dev` keeps its 5-minute window. MEC's Manara host is on `manara-renderer` and is
  purged.
- **Content bound at read time** (`SectionContentBinder`: about, donation, forms, offerings; a
  `button_page_id` resolved to a slug) is shown as saved; the preview overlays only what the editor
  holds. Saves on those other screens are not in this feature's purge list. **Open:** add them.
- **A section shared by several pages** (library sections) is previewed on the page being edited;
  the other pages show it after Save.
- **Header pins, sub-menus, favicon and share tags** come from renderer code keyed by id
  (`shared/tenantHeader.ts`, `tenantBranding.ts`), not from any editor, so they render as live.
- **A custom preview domain** (`preview.manara.hopetechapps.com`) would read better than
  `*.pages.dev`; it is DNS plus a Pages domain, so it is the owner's call. Everything here takes the
  origin from config.
- **`scripts/set-server-secret.sh` cannot write `RENDERER_PREVIEW_ADMIN_ORIGINS` with two
  origins**: its value check refuses a comma. Staging holds one origin. Production needs two
  (`masjid.` and `manara.hopetechapps.com`): widen the script's character set to include `,`
  (commas need no quoting in `.env`), or set it by a reviewed hand edit.
- **The same script exits silently when piped a value with no trailing newline** (`read` returns
  non-zero at EOF under `set -e`). Found setting staging's secret; the second attempt, with a
  newline, succeeded.

## 10. Status and evidence (2026-09-24)

Branches: MasjidWebMS `feat/live-preview`; renderer `feat/live-preview` (merges to `main` only).

**Verified**
- Renderer `npm test` 492/492 (the three existing isolation tests unedited); 16 mutations of the
  security rules each caught by a failing test.
- Renderer in workerd (`scripts/live-preview-integration.mjs`, 27/27): caching really on; previews
  fresh with the preview headers; 20 previews added no KV key and moved no public body hash; four
  kinds of invalid token and a valid token on a tenant host render the ordinary response; forged
  and stale purges refused; a signed purge removed exactly one organisation's entries; payload
  guard still 404s.
- Browser (local sandbox): unsaved section, theme and splash repaint without reload; a theme sent
  under a pages token is ignored; an unlisted origin is refused by `frame-ancestors`.
- Laravel full suite on the droplet CI copy at `2de3a9de`: 4322 passed, 1 skipped, 2 failed — both
  failures were the new tests' own mistakes, fixed in `ecb00efd` and re-run green with the
  route-table pins (148 passed). Final full run at the branch head: see LOG / hand-back.
- Staging, real Cloudflare and MySQL: renderer alias `https://live-preview.manara-renderer-staging.pages.dev`
  (build `b4561a2b`); Laravel `https://masjid-staging.hopetechapps.com` at `ecb00efd`. Admin CSP
  frames the preview origin; MEC's client admin is refused a pages session while `web_pages` is off
  and granted one once on; another organisation is 403; an unlisted Origin gets `enabled:false`;
  minted URLs render the token's organisation with `no-store`, `noindex` and
  `frame-ancestors https://masjid-staging.hopetechapps.com`. In the admin SPA, as that client
  admin: the section editor's unsaved CTA heading appeared in MEC's real About page, at desktop and
  phone widths; the Brand Studio's unsaved colour and heading font repainted MEC's home page; an
  unsaved splash appeared as the site's pop-up. A theme save purged MEC's cached page (fresh render
  64 s later, before expiry). Staging was returned to its prior state afterwards (`web_pages` off
  for MEC through the ledgered endpoint, QA tokens revoked).

**Assumed / not verified**
- Production values of the config (§8) and the CORS addition: not set; the point session's.
- The second purge pass on a live queue: unit-tested and asserted queued; not observed firing on
  staging.
- A Studio draft organisation with no tenant-map entry previews as a bare `{id}` tenant (unit
  test only).
- `mec-web.pages.dev` keeps its 5-minute window (not touched, by W1's rule).
