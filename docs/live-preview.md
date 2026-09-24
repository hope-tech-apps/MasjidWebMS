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
URL on a **dedicated preview host** that serves no tenant (production:
`preview.manara.hopetechapps.com`, a custom domain on the `manara-renderer` project; staging: a
`*.pages.dev` branch alias). The admin puts that URL in an iframe. The renderer sees the path prefix
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
   ◀──── {url: https://preview.manara.hopetechapps.com/__manara/preview/about?mp=v1.…}
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
  That router also appends the address bar's query (`?mp=<token>`) to `payload.path` and replaces
  the frame's URL with it (`router.js:24-26`, `:253`), so the client plugin strips `mp` on that
  first navigation (§4.5).
  `getRouteRules(event)` is memoised from the prefix path, so Nuxt's payload store (written only for
  cache-rule paths, `renderer.mjs:96,164-167`) is not written either. Verified by reading; proven
  end to end in the workerd run (§7.3).
- **Why a dedicated preview host.** The tenant must come from the verified token (brief rule 5),
  which only a host that serves no tenant makes unambiguous. `manara-renderer.pages.dev` answers
  every request today with `x-manara-tenant: unresolved` and a 404 (`docs/tenant-host-map.md`), W1
  excludes `.pages.dev` from its host lookup (`docs/manara-studio-w1.md` S10), and Studio drafts
  that have no host yet can be previewed the same way. A tenant's own domain **never** enters
  preview mode, so its caching and framing cannot change.
  The owner chose `preview.manara.hopetechapps.com` over the `pages.dev` name (2026-09-24). That
  host IS eligible for Studio's runtime host lookup, so it is protected twice: the label `preview`
  is reserved for Studio slugs and the host is imported as `reserved` (W1), and the renderer refuses
  preview mode on any host the tenant middleware resolved to an organisation, by any path
  (renderer branch `feat/live-preview-host-guard`).
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
| `p` | the one path this token may render, in **decoded** form (§4.2.1) |
| `a` | the admin origin; must be in Laravel's and the renderer's allowlists |
| `e` | expiry, unix seconds; 300 s after issue; the renderer refuses `e` more than 900 s ahead |

The renderer compares signatures in constant time. Any failure (absent, malformed, bad signature,
expired, wrong host, wrong path, unknown origin, bad id) renders **the ordinary response for that
URL**: on the preview host that is the existing SiteNotFound 404; on a tenant host it is that
tenant's ordinary page-not-found. Nothing says why.

#### 4.2.1 The path: one rule, one canonical form, both sides

A page slug is free text (`StorePageRequest`: `required|string|max:255`), so an Arabic slug such as
`حول` is valid. The signed `p` is the **decoded** path, exactly as the admin knows it. Laravel
percent-encodes each segment when it builds the iframe URL
(`PreviewToken::encodePath`: `rawurlencode` per segment). The renderer compares `p` with h3's
`event.path`, which h3 has already decoded (`_decodePath`). One exception: h3 leaves `%25` encoded,
which is why `%` is refused below.

The rule is the same byte for byte in `PreviewToken::isSafePath` and `isSafePreviewPath`:

- valid UTF-8, starts with `/`, not `//`;
- at most 512 UTF-16 code units;
- no `.` or `..` segment;
- none of `\ ? # %`;
- no C0 controls or space, no DEL;
- none of the Unicode spaces JavaScript's `\s` matches: U+00A0, U+1680, U+2000–U+200A, U+2028,
  U+2029, U+202F, U+205F, U+3000, U+FEFF.

Before this, the PHP rule used an ASCII-only `\s`, so a no-break space was minted by Laravel and
refused by the renderer. Both sides now list the characters explicitly. `PreviewTokenVectorTest`
and `preview-token.test.ts` share a vector with an Arabic path and one list of safe and unsafe
paths. The workerd run renders the Arabic page from a percent-encoded URL.

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

Honoured only when **all** of these hold:

- the path starts with `/__manara/preview/`;
- the raw `Host` header is in `previewHosts`. `X-Forwarded-Host` is never read;
- the host is not in the static tenant map, and the tenant middleware resolved no organisation for
  it by any path (`hostServesTenant`);
- the token verifies;
- `p` equals the decoded path after the prefix.

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
   `X-Manara-Preview: 1`, `X-Manara-Tenant: {o}`. A `render:response` hook sets them again, so no
   header Nuxt sets later can override them.

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

#### 4.5.1 The frame

**Renderer side** (`app/plugins/manara-preview.client.ts`, with its decisions in
`shared/previewBridge.ts` so `node --test` can reach them):

- The plugin does nothing unless the page is framed (`window.parent !== window`) and preview state
  exists.
- A `router.beforeEach` guard removes `mp` from the first navigation, with `replace`. The frame's
  address becomes `/<path>` with no token, so the token stays out of the frame's history and
  `route.query`.
- The guard also means a reload of the frame is not a preview any more: the token was single-use
  in the URL.

**Admin side** (`LivePreviewPane.vue`, with its decisions in `core/helpers/previewFrame.ts`):

- The iframe carries
  `sandbox="allow-scripts allow-same-origin allow-forms allow-popups allow-popups-to-escape-sandbox"`.
  There is no `allow-top-navigation`, so nothing in the frame can replace the admin tab.
  `allow-same-origin` is safe because the frame is cross-origin to the admin.
- It posts only to the origin the session named (`postTargetFor`), and accepts `ready` only from its
  own frame's window at that origin (`isReadyFromFrame`).
- If the frame fully loads again after `ready` (a reload, or Nuxt's chunk-error reload after a
  renderer deploy), the pane shows "loading". If no `ready` arrives within 8 s, it opens a new
  session. It does this at most once every 30 s; after that it shows the error.

**Saved rich text** (`useSafeHtml`, via `shared/safeLinkAttributes.ts`) is the other half of the
sandbox. An external or protocol-relative link, or one that asks for `_blank`, opens in a new tab
with `rel="noopener noreferrer"`. Every other `target` (`_top`, `_parent`, a named frame) is
removed, so the link stays in its own frame. This is the **one deliberate change to live output**:
the rule is shared code, so public pages apply it too. Four kinds of saved link render differently:
`_top`, `_parent` or a named target (target removed); `target="_self"` (removed, same behaviour); a
protocol-relative `//host` link (now opens in a new tab); and an in-site `_blank` link (gains `rel`).
How many exist: staging's scrubbed copy of production has **no** `<a ` and no `target=` in any text
or JSON column of any table. Its 98 sections contain no `href` at all. Links saved in production since that copy was taken are
unmeasured. §8 step 0 counts them with a read-only query.

### 4.6 Purge

`POST {renderer origin}/__manara/purge`

```
X-Manara-Timestamp: 1790000000
X-Manara-Signature: v1=<hex HMAC-SHA256(secret, "manara-purge|v1|" + timestamp + "|" + rawBody)>
Content-Type: application/json

{"v":1,"org":13}                     first call
{"v":1,"org":13,"after":"nitro:routes:…"}   a later call, resuming after the cursor
```

**The renderer side** (`shared/pageCachePurge.ts` `runPurge`, with the route
`server/routes/__manara/purge.post.ts` only reading the request and writing the answer):

- **Refusals.** A bad signature, or a timestamp more than 300 s from the renderer's clock, gets 401
  `{"ok":false}`. A body over 1 KB is refused. An `after` that is not a string of at most 512
  characters starting `nitro:routes:` is refused. With no secret configured the route answers 404,
  which is how a deployment that lacks the secret shows itself (§8). A replay inside the window
  deletes nothing new.
- **Listing and deleting.** It lists `MANARA_PAGE_CACHE` under `nitro:routes:{name}:` for each
  cache-rule `name` in the running config. Today there is one name, the build id. It deletes the
  keys whose `host`, `xforwardedhost` or (after W1 S10) `xmanaratenantkey` segment is Nitro's
  `hash()` of one of the organisation's hosts in the tenant map, or of `t{org}`. If the
  organisation has a wildcard host, every key under the name is deleted instead, and that is
  logged.
- **Paging by key.** The keys are sorted, and only keys after the signed `after` cursor are taken.
  Each call deletes at most 800 and answers
  `{"ok":true,"scanned":n,"deleted":n,"remaining":bool,"cursor":key|null}`. It pages by key, not by
  position, because KV's list is eventually consistent: a re-listing can still show keys the
  previous call deleted, and "the first 800 again" would re-delete them (billed) and never reach
  the rest.

**The Laravel side:**

- **What triggers a purge.** The `renderer.purge` route middleware is on every group whose writes
  change what the public site shows:
  - pages, the section library and page sections;
  - the theme save;
  - general settings (logos, copyright);
  - the sources `SectionContentBinder` reads into pages at serve time: details (about, mission,
    vision), About Us, the donation link, contact reasons, forms, offerings and their fee plans.

  `RendererCachePurgeTest::the_writes_that_purge_are_exactly_the_site_editors_writes` pins the
  groups by prefix. Reads (GET, HEAD, OPTIONS) and failed writes purge nothing. The preview mint
  routes carry no purge. Splash needs none: the browser fetches it, and its Laravel cache is already
  forgotten on save.
- **Nothing runs in the request.** `terminate()` only calls `RendererPurgeScheduler::afterSave`,
  which records the save time and queues two jobs. No PHP-FPM worker waits on the renderer, and a
  failure can never turn a save into an error.
- **First pass, coalesced** (`App\Jobs\PurgeRendererCache`). It runs 3 s after the first save of
  a burst and is unique per organisation until it starts (`ShouldBeUniqueUntilProcessing`).
  Dragging to reorder sections sends one request per section, and those requests now share one
  purge. Before this, each request fired its own purge, and each purge listed the namespace and
  deleted the same keys.
- **Second pass, trailing the last save** (`App\Jobs\PurgeRendererCacheAgain`, `ShouldBeUnique`).
  When it runs, it checks the most recent save. If that save is less than 75 s old, the job
  `release()`s itself for the difference (a released job keeps its unique lock) and purges only
  once 75 s have passed with no save. Measured on staging: a page warmed in another region seconds
  before a save was missing from the first pass's listing, because KV's list is eventually
  consistent. The second pass finds it.
  - Before this fix, a save made inside an earlier save's 75 s window got no second pass of its own
    (the old job was a throttle).
  - The job gives up after 30 minutes (`retryUntil`). Someone saving at least every 75 s for 30
    minutes loses only that trailing pass. The first pass of every burst still runs, and the next
    save starts a new trailing pass.
- **Each call.** At most two renderer calls per pass (`MAX_CALLS = 2`), following `cursor`. Timeouts
  come from config. Refusals and failures log at `warning` (production runs `LOG_LEVEL=warning`).

**KV cost per save: bounded, whatever the plan.** The account's KV plan is **unknown**. Assume
Workers Free: 1,000 list operations and 1,000 deletes a day, shared by every organisation.

| | per save burst |
|---|---|
| renderer calls | at most 4 (two passes × `MAX_CALLS` 2); normally 2 |
| KV list operations | one per call while the build's keys number under 1,000; normally 2 |
| KV deletes | the organisation's keys under the current build at the first pass, plus whatever was re-warmed or missed by the second |

Measured in production's `MANARA_PAGE_CACHE` on 2026-09-24 (a read-only listing): 289 keys in all,
**45 under the current build's name**. The rest belong to older builds, which the purge never lists
(Burlington alone had 124 under an older build). So one burst costs about 2 list operations and at
most about 2 × K deletes, where K is the organisation's keys under the current build (K ≤ 45
today). Before this fix, a 10-section reorder cost about 10 lists and 10 × K deletes.

On Free, **deletes are the tighter budget**: about 1,000 ÷ K bursts a day across all organisations,
at least about 22 a day while K ≤ 45. When the budget runs out, KV refuses the delete. The purge
call then fails, Laravel logs it at `warning`, and the save goes live when its entry expires,
within the 5-minute window, which is how it worked before this feature. Nothing breaks.

This failure mode is **assumed** from KV's documented behaviour; it has not been observed. One
thing suggests the plan is paid. A cached page is rewritten at most every 5 minutes while visited,
so on Free the page cache's own writes would pass 1,000 a day on a modestly busy day, and no such
refusal is known. That is **unknown, needs investigation**: the plan is visible in the
Cloudflare dashboard.

**What "immediately" means — measured on staging, 2026-09-24.** After a save, the purge deletes the
organisation's entries at once, but Cloudflare KV caches reads at each location for about 60 s, so
a visitor elsewhere keeps the old page until that read cache lapses: the fresh render appeared **64 s
after the save** (the entry was 3.5 minutes short of expiring, so the purge, not expiry, did it).
Worst case, for a page the first pass's listing missed: about 75 s after the last save, plus 60 s. Before this work: up to 5 minutes plus one
stale-while-revalidate request. Getting to "the next request, everywhere" would need a strongly
consistent store for the page cache (a Durable Object, or no shared HTML cache); that is a
separate decision for the owner (§9).

## 5. The eight hard requirements, and the test that fails without each

Test files are named as they are on disk. Renderer tests are in `tests/*.test.ts` and run with
`npm test`. Laravel tests are PHPUnit classes. The admin SPA's tests are in
`resources/vue-app/tests/*.test.ts` and run with `npm run test:spa`. "Workerd" is the renderer's
`npm run test:integration` (§7.3).

| # | Requirement | Mechanism | Test that fails without it |
|---|---|---|---|
| 1 | A preview render is never cached nor served from cache | prefix path on the uncached renderer; `render:before` rewrite | `preview-cache-bypass.test.ts` loads the **real** `nuxt.config.ts` (`DEPLOY_TARGET=cloudflare`) and runs its route rules through a Nitro-like matcher. Control: tenant pages ARE cached. Test: no cache rule covers `/__manara/preview/**`, and the hook tests the prefix first. **Workerd:** the KV key list is identical before and after 20 previews, and public body hashes are unchanged |
| 2 | Short-lived API-signed token, one org (and page), editors only | §4.2, §4.3 | `preview-token.test.ts`: every refusal renders the ordinary page; the Arabic vector. Laravel `LivePreviewSessionTest`: each surface's gate; another org gets 403; the token's org comes from the route; an Arabic slug is signed decoded and sent encoded; the unsafe-path list. `PreviewTokenVectorTest`: the same vectors on both sides |
| 3 | Unsaved content only from the admin | origin + source + state + validator, both ends | Renderer: `preview-message.test.ts`; `preview-bridge.test.ts` (plugin only when framed with verified state; only `window.parent` at the admin origin is heard; `ready` goes only to that origin; `mp` stripped from the first navigation). SPA: `preview-frame.test.ts` (a `ready` counts only from this pane's frame at the preview origin; overrides go only to the preview origin, never `*`) |
| 4 | Framing opened only for preview | `frame-ancestors {a}` on preview responses only; admin CSP `frame-src` gains the preview origin only when configured; the frame is sandboxed without top navigation; sanitised links keep only `_blank` / `_self` | `preview-decision.test.ts` (headers only on a verified preview). Laravel `SecurityHeadersPreviewFrameTest` (unconfigured CSP byte-identical to today's; configured adds exactly the origin). SPA `preview-frame.test.ts` (the sandbox list has no `allow-top-navigation`, and the pane uses it). `safe-link-attributes.test.ts` (`_top`, `_parent` and named targets dropped; the sanitiser routes every link through it) |
| 5 | Never another tenant; tenant from the token | `manaraTenant` from `o`; tenant hosts refused as preview hosts; raw `Host` only | `preview-decision.test.ts` (Host names org 1 and the token 13: renders 13; a mapped or middleware-resolved tenant host never previews). `preview-cache-bypass.test.ts` (the hook reads the raw `Host`, never `X-Forwarded-Host`, and passes `hostServesTenant`). **Workerd:** an `X-Forwarded-Host` naming the preview host does not make a tenant host preview |
| 6 | Save goes live immediately, via a signed renderer endpoint | §4.6 | `page-cache-purge.test.ts` (signature, window, Nitro's key format, `runPurge` scoped to the signed org, a cursor that never re-selects, the route is only I/O around `runPurge`). Laravel `RendererCachePurgeTest` (queue only; a burst is one first pass; the second pass trails the last save; cursor in the signed body; exactly `MAX_CALLS` requests sent; the route table by prefix, bound sources included). **Workerd:** the purge removes that org's keys and no other org's; forged and stale purges are refused |
| 7 | `noindex` on every preview response | header + meta, restated at `render:response` | `preview-decision.test.ts`; `preview-cache-bypass.test.ts` (the `render:response` hook restates the headers; the robots meta comes only from verified state). **Workerd** checks both on a real response |
| 8 | Live tenants byte-identical | nothing changes off the preview host, except the link-target rule (§4.5.1) | visible-text hashes of `/`, `/about` and `/services` on the five hosts before and after each deploy; the payload guard still 404s; `tenant-unchanged.test.ts`, `payload-isolation.test.ts` and `page-cache-build-key.test.ts` pass **unedited**; `scripts/compare-builds-public.mjs` (main against this branch under workerd) |

## 6. Slices, in ship order

Each slice ships dark: with its config blank, nothing any visitor or admin sees changes.

| # | Repo | Slice | Live impact | Rollback |
|---|---|---|---|---|
| R1 | renderer (`main` only) | `shared/manaraSignature.ts`, `shared/pageCachePurge.ts`, `server/routes/__manara/purge.post.ts`, three runtime-config keys | none: 404 until the secret is set | Pages rollback |
| R2 | renderer (`main` only) | preview mode: `shared/previewToken.ts`, `previewDecision.ts`, `previewMessage.ts`, `previewOverrides.ts`; `server/plugins/manara-preview.ts`; `app/plugins/manara-preview.{server,client}.ts`; SplashModal hook | none: `/__manara/preview/*` renders as today until hosts, origins and secret are set | Pages rollback |
| L1 | MasjidWebMS | `config/services.php` renderer block; `App\Support\Renderer\{RendererConfig, PreviewToken}`; the four routes in §4.3; tests | none: `enabled:false` while unconfigured | revert |
| L2 | MasjidWebMS | `App\Support\Renderer\RendererCachePurge` + the after-response calls in §4.6 | none while unconfigured; configured, saves go live at once | revert, or blank `RENDERER_PURGE_ORIGINS` |
| L3 | MasjidWebMS | `SecurityHeaders` adds the preview origin to `frame-src` when configured | none while unconfigured | revert |
| L4 | MasjidWebMS SPA | `LivePreviewPane.vue` (sandboxed iframe, desktop 1280 / tablet 834 / phone 390, scaled to fit, self-heals after a reload), `composables/useLivePreview.ts`, `core/helpers/previewFrame.ts`; wired into the section editor, page settings, Brand Studio and the splash form. **Menu order is not previewed:** dragging to reorder publishes on drop, as before (owner, 2026-09-24) | the pane appears only when a session says `enabled:true` | revert + rsync |
| L5 | MasjidWebMS SPA | Brand Studio controls for the three token families the renderer already reads: heading/body font (`tokens.typography.headingFamily/bodyFamily/fontsUrl`), header style (`tokens.layout.header`), footer style (`tokens.layout.footer`); the token rules in `core/helpers/themeTokens.ts` | new controls on the Brand Studio; nothing changes until an admin saves one | revert |

L5 exists because the owner put fonts and header/footer style in scope, and no screen edits them
today (the SPA sends only four colours). It is a new editing capability, and it **ships with the
preview**: the controls appear when a preview session is available (point session for the owner,
2026-09-24; not hidden behind a flag of its own). Two rules keep it from damaging what an
organisation already has:

- Typography is rebuilt **only when a font select changes**. Changing only the header or footer
  style posts typography byte for byte as saved. Changing only colours posts no tokens at all.
- When a face is left as it is ("Current"), the families the saved `fontsUrl` loads for it are
  kept. A saved stylesheet this screen cannot extend (not a Google `css2` URL) is kept as it is.

Before this fix, touching only Header style on an organisation whose heading font is custom and
whose body font is on the list rewrote `fontsUrl` to load the body font alone. That removed the
heading face from every public page. `theme-tokens.test.ts` pins both rules.

Order of deployment: R1+R2 (dark) → L1-L3 (dark) → L4 → staging configured end to end and
verified (§7.4) → hand-back. Production is the point session's, with the owner's go.

## 7. Tests and verification

### 7.1 Renderer: `npm test` (`node --test`, pure `shared/` code, no `node_modules`)

The files are `manara-signature`, `preview-token` (with the cross-language vectors, Arabic
included), `preview-decision`, `preview-message`, `preview-overrides`, `preview-bridge`,
`page-cache-purge`, `preview-cache-bypass` and `safe-link-attributes`. The three existing isolation
tests (`tenant-unchanged`, `payload-isolation`, `page-cache-build-key`) run unedited. The gate is
`# fail 0`.

### 7.2 Laravel (SQLite suite on the droplet's CI tree, one suite at a time)

- `PreviewTokenVectorTest`.
- `LivePreviewSessionTest`: form-encoded and JSON bodies; forged Host; unconfigured; the origin
  allowlist; each gate; the Arabic slug; the theme preview endpoint, which writes nothing.
- `RendererCachePurgeTest`: `Queue::fake` and `Http::fake`.
- `SecurityHeadersPreviewFrameTest`, with its production twin: unconfigured output is today's.

The route-table pins must pass too: `CapabilityGateTest`, `FamilyAuthGuardTest`,
`OrganisationModulesTest` and `StaffAuthGuardPinTest`. Then the full suite.

### 7.2.1 Admin SPA: `npm run test:spa`

The SPA's first unit tests, `resources/vue-app/tests/preview-frame.test.ts` and
`theme-tokens.test.ts`. They run under `node --experimental-strip-types --test` (Node ≥ 22.6) and
test the pure helpers the components use, plus source pins that the components use them. CI runs
them after the Vite build (`.github/workflows/tests.yml`). `tsconfig.json` excludes the folder, so
`tsc --noEmit` stays at its baseline.

### 7.3 Workerd integration: `npm run test:integration` (required, not optional)

This is a Cloudflare build (`DEPLOY_TARGET=cloudflare`, `ISR_SECONDS=300`) under
`wrangler pages dev`, with a local KV store and a stub API. The script
(`scripts/live-preview-integration.mjs`) **refuses to run** unless `dist/` was built from HEAD: it
looks for the page-cache build id `b<first 16 of HEAD>`. So commit first, then build, then run.

It checks:

1. caching is really on;
2. previews are fresh and carry the preview headers and the robots meta;
3. an Arabic slug previews;
4. 20 previews add no KV key and move no public body hash;
5. invalid tokens, and a valid token on a tenant host, render the ordinary response;
6. an `X-Forwarded-Host` spoof is not a preview;
7. forged and stale purges are refused;
8. a signed purge removes exactly one organisation's entries;
9. the payload guard still 404s.

Results are recorded in the renderer's `docs/live-preview.md`.

### 7.4 Staging

Renderer branch deployed as the `live-preview` alias of `manara-renderer-staging` (direct upload,
preview environment, its own KV binding `dfd5ece5…`, staging API). Staging Laravel configured with
a **staging-only** secret. Then, driven in a browser as the QA sandbox admin:

1. The pane loads at three widths.
2. A section edit, a theme colour, a heading font, a page-settings toggle and a splash draft each
   repaint without a reload.
3. The frame's address holds no `mp`, and reloading the frame brings the pane back by itself.
4. Save goes live on the staging renderer. The purge's queued passes run on the real worker (read
   `jobs` and the log).
5. A wrong-origin message changes nothing.
6. The preview response headers, and the absence of any new KV key, are read directly.

## 8. What the point session does at production ship (owner's go)

**Order: the renderer is switched on and proven BEFORE Laravel is.** A Pages deployment reads only
the secrets and variables that existed when it was created. Laravel switched on first would meet a
renderer that does not have them yet:

- every purge would get a 404, so saves would not go live;
- every client admin's pane would time out after 20 s with "The preview could not load";
- the L5 font and layout controls would be on screen anyway.

So: dark ship, renderer config, **redeploy**, prove the renderer, and only then Laravel.

**0. Dark ship, both repos.**

- **Renderer.** Merge `feat/live-preview` to `main` (it already holds the host-guard and
  review-fix commits). Build from the committed tree (`DEPLOY_TARGET=cloudflare`, as today). Deploy
  `manara-renderer` by direct upload (`npx wrangler@4 pages deploy dist --project-name
  manara-renderer --branch main --commit-hash <sha>`). **Keep that `dist/`**: step 3 uploads it
  again.
- **Check the renderer is dark and unchanged:**
  - RBI on the five hosts is byte-identical;
  - the payload guard still 404s;
  - `curl -s -o /dev/null -w '%{http_code}' -X POST https://manara-renderer.pages.dev/__manara/purge`
    is **404** (no secret yet).
- **Laravel.** Merge MasjidWebMS `feat/live-preview` and run `scripts/ship.sh production`. It is
  dark too: every preview route answers `enabled:false`, no save queues anything, and the CSP is
  unchanged.
- **Optional, read-only:** count the saved links the link-target rule could change (§4.5.1). Count
  rows in any text or JSON column containing `target=` or `<a `. Staging's copy has none. A
  non-zero count is not a blocker, but compare those pages' links before and after.

**1. The preview host.**

- Add a DNS CNAME `preview.manara.hopetechapps.com` and the matching custom domain on the
  `manara-renderer` Pages project.
- Reserve the label `preview` in Studio's `cloudflare.reserved_labels`, and import the host as
  `reserved` (W1 S3).

**2. The secret and the renderer's configuration.**

- Generate one secret of at least 32 random characters.
- Put it into `manara-renderer` production as the encrypted secret `NUXT_MANARA_SHARED_SECRET`,
  with the value on stdin. It must never pass through a chat, argv or history.
- Set `NUXT_PREVIEW_HOSTS=preview.manara.hopetechapps.com`.
- Set `NUXT_PREVIEW_ADMIN_ORIGINS=https://masjid.hopetechapps.com,https://manara.hopetechapps.com`.

`wrangler pages secret put` writes the production environment, which is the one `manara-renderer`
serves from, so all three keys can be set that way. (On staging the preview is a branch alias in
the PREVIEW environment, which wrangler 4 cannot write. Its keys were added with one Pages API
PATCH, which merges, verified by downloading the project config before and after.)

**3. REDEPLOY `manara-renderer`.** Upload the **same `dist/`** from step 0 again, with the same
`--commit-hash`. (If `dist/` was lost, rebuild from the same commit, never from a newer `main`.) The
new deployment picks up step 2's values. The page-cache build id comes from the commit, so the
cache namespace and every public byte stay as step 0 left them, and RBI below measures only the
configuration.

**4. Prove the renderer side, before anything else.**

- `curl -s -o /dev/null -w '%{http_code}' -X POST https://manara-renderer.pages.dev/__manara/purge`
  must be **401**. A **404** means the serving deployment does not have the secret: stop, check
  step 2, and redeploy.
- `curl -sI https://preview.manara.hopetechapps.com/__manara/preview/` with no token: the ordinary
  SiteNotFound 404, and no `x-manara-preview` header.
- RBI on the five hosts, byte-identical to step 0, and the payload guard still 404s.

**5. Only now, Laravel.** Put the same secret into production Laravel as `RENDERER_SHARED_SECRET`
with `scripts/set-server-secret.sh`. Set:

- `RENDERER_PREVIEW_ORIGIN=https://preview.manara.hopetechapps.com`;
- `RENDERER_PURGE_ORIGINS=https://manara-renderer.pages.dev`. The purge stays on `pages.dev`: it is
  the same production deployment and KV binding, it sits outside the `hopetechapps.com` zone's WAF
  and bot rules (which could challenge a server-to-server POST), and the purge route never checks
  Host;
- `RENDERER_PREVIEW_ADMIN_ORIGINS=https://masjid.hopetechapps.com,https://manara.hopetechapps.com`;
- add `https://preview.manara.hopetechapps.com` to `CORS_ALLOWED_ORIGINS` (owner, 2026-09-24).
  Without it the preview still works, because the store keeps the server-rendered data, but
  browser-side reads inside the preview (events pagination, an offering's seat re-read, a page not
  yet in the store) fail quietly. W1 S9 keeps the env list as its base, so this carries over.

Then run `scripts/ship.sh production`. It caches the config, and `bin/deploy` restarts the queue
worker, which now runs **both** purge passes. Nothing purges from the request.

**6. Verify, as on staging (§7.4).**

- Open a preview session from the admin: the pane loads, and the frame's address holds no `mp`.
- Make an unsaved edit on each surface, and save one: the fresh render arrives in about a minute.
  Read the queued passes in `jobs` and the log.
- RBI again.
- `curl -I` one signed preview URL and expect exactly:
  - `content-security-policy: frame-ancestors <admin origins>`;
  - `cache-control: no-store, private`;
  - `x-robots-tag: noindex, nofollow, noarchive`;
  - no `x-frame-options`.

  This matters because the custom domain puts previews behind the `hopetechapps.com` zone.

Email Obfuscation is on for the zone. The server-rendered preview matches what live `mec.` and
`alrazi.manara` show, and the browser-side overlays render unobfuscated, which is accepted.

Checked in production on 2026-09-24 by the point session: `SESSION_DOMAIN` is null, so cookies are
host-only, and `SANCTUM_STATEFUL_DOMAINS` is unset (localhost plus the APP_URL host). So the
preview host, which is same-site with the admin, receives no admin cookie and is not stateful.

**Rollback.**

- Blank `RENDERER_PREVIEW_ORIGIN` and `RENDERER_PURGE_ORIGINS` in Laravel and ship. Previews and
  purges stop, and the CSP returns to today's.
- The renderer can keep its configuration: nothing reaches it without a signed token.
- The code itself rolls back with a Pages rollback and a revert.

## 9. Known limits and open questions

- **Decided by the owner, 2026-09-24:**
  - "immediately" is about a minute on KV, accepted (§4.6);
  - ship and switch on after the independent review;
  - the preview host is `preview.manara.hopetechapps.com`, and its origin goes into production
    CORS.
- **Decided for the owner by the point session, 2026-09-24:**
  - L5 ships with the preview, with the font-drop bug fixed (§6);
  - read-time bound content is purged on save (§4.6);
  - dragging to reorder the menu keeps publishing on drop, with no preview step;
  - the KV plan is unknown, so the purge must be bounded per save whatever the plan (§4.6);
  - `mec-web` staying unpurged, and a library section previewed only on the edited page, are
    accepted.

- **`mec-web` is not purged** (accepted until W1). It builds from `cloudflare-migration`, which W1
  leaves untouched, so `mec-web.pages.dev` keeps its 5-minute window. MEC's Manara host is on
  `manara-renderer` and is purged.
- **Content bound at read time** (`SectionContentBinder`: about, donation, contact reasons, forms,
  offerings and fee plans; a `button_page_id` resolved to a slug) is previewed as saved. The preview
  overlays only what the open editor holds. Saving any of those sources now purges (§4.6).
- **Menu order is not previewed.** Dragging pages to reorder the menu publishes on drop, as it did
  before this feature, and that save purges like any other.
- **A section shared by several pages** (library sections) is previewed on the page being edited;
  the other pages show it after Save (accepted).
- **Header pins, sub-menus, favicon and share tags** come from renderer code keyed by id
  (`shared/tenantHeader.ts`, `tenantBranding.ts`), not from any editor, so they render as live.
- **The custom preview domain needs no code change.** Every host and origin is read from
  configuration: the admin CSP (`SecurityHeaders.php`, from `RENDERER_PREVIEW_ORIGIN`), the pane's
  iframe URL and postMessage origin (from the session response), and the renderer's preview hosts
  (`NUXT_PREVIEW_HOSTS`). The one hardening it prompted is the host guard in §3.
- **`scripts/set-server-secret.sh`** refused a comma and exited silently on a piped value with no
  trailing newline; both were found here and fixed on `main` in `bb60da7f`, so production's
  two-origin `RENDERER_PREVIEW_ADMIN_ORIGINS` can go through it.

## 10. Status and evidence (2026-09-24)

Branches: MasjidWebMS `feat/live-preview-review-fixes`, renderer `feat/live-preview-review-fixes`
(renderer merges to `main` only). `feat/live-preview` in both repos is fast-forwarded to them at
hand-back.

### 10.1 The independent review: 18 confirmed findings, each fixed and pinned

"Caught" means the fix was reverted or mutated in place and the named test failed. Then the file
was restored.

| # | Finding | Fix | Test that fails without it |
|---|---|---|---|
| 1 | An Arabic slug minted a token but never previewed; the whitespace rules differed | decoded canonical path, encoded URL, explicit character list (§4.2.1) | `PreviewTokenVectorTest` (renderer's rule, Arabic vector), `LivePreviewSessionTest::an_arabic_slug_is_signed_decoded_and_sent_encoded`, `preview-token.test.ts` |
| 2 | `mp` stayed in the frame's URL; a reload dropped preview mode | `mp` stripped at the first navigation; the pane reopens a session after a reload (§4.5.1) | `preview-bridge.test.ts` (strip, and its registration); `preview-frame.test.ts` (a second load is a reload, the first never is; one reopen per 30 s) |
| 3 | A saved `target="_top"` link could replace the admin tab | sandboxed frame; sanitiser keeps only `_blank` / no target | `preview-frame.test.ts` (no `allow-top-navigation`; the pane uses the list); `safe-link-attributes.test.ts` |
| 4, 10 | The 75 s second pass was a throttle | the second pass trails the last save (§4.6) | `RendererCachePurgeTest::the_second_pass_trails_the_last_save_not_the_first`, `…_lock_outlives_a_whole_editing_session…` |
| 5 | A reorder fanned out one full purge per request | coalesced first pass; `MAX_CALLS` 2; KV budget (§4.6) | `…a_burst_of_writes_queues_one_first_pass_and_one_second_pass` |
| 6 | Pagination re-selected the first 800 keys | key cursor, signed | `page-cache-purge.test.ts` (a cursor never re-selects); `…remaining_entries_are_fetched_by_cursor_in_the_signed_body` |
| 7 | L5 could drop a saved custom font | typography rebuilt only on a font change; saved families kept (§6) | `theme-tokens.test.ts` (finding 7; saved faces; non-css2 kept; colours post no tokens; the view's wiring) |
| 8 | The purge ran inside the PHP-FPM worker | queue only | `…a_save_queues_both_passes_and_sends_nothing_from_the_request` |
| 9 | The runbook turned Laravel on before the renderer had its secrets | §8 rewritten: renderer config, redeploy, unsigned POST must be 401, RBI, then Laravel | (runbook) |
| 11, 15 | The client plugin's guards had no test | `shared/previewBridge.ts` + `preview-bridge.test.ts` | framed-only, parent-only, origin-only, `ready` to the admin origin only |
| 12 | The doc claimed menu order was previewed | doc corrected; drag keeps publishing on drop (owner) | (doc) |
| 13 | The cache-bypass test checked one spelling of a rule | the test evaluates the real `nuxt.config.ts` route rules | `preview-cache-bypass.test.ts::no_route_rule_covers_the_preview_prefix` |
| 14 | "Raw Host, never X-Forwarded-Host" was untested | behaviour test + workerd check | `preview-plugin.test.ts`, `preview-cache-bypass.test.ts`, workerd |
| 16 | Purge route, header restatement and robots meta were covered only by workerd | `runPurge` extracted; the plugins run under test | `page-cache-purge.test.ts` (`runPurge`, route is only I/O); `preview-plugin.test.ts` |
| 17 | The call-cap test asserted a hard-coded number | counts the requests actually sent | `…a_renderer_that_always_has_more_is_called_exactly_max_calls_times_and_logged` |
| 18 | The admin pane's postMessage target and origin/source check had no test | `core/helpers/previewFrame.ts` + `preview-frame.test.ts` | `*` never used; `ready` only from this frame at the preview origin |
| — | (point session) purge bound content on save | `renderer.purge` on the binder's sources | `…the_writes_that_purge_are_exactly_the_site_editors_writes_and_their_bound_sources` |

**Mutation pass (verified).** 48 mutations of the fixes, all caught by the named test:

- 23 in the renderer, under `node --test`;
- 14 in the admin SPA, under `node --test`;
- 11 in Laravel, `php artisan test --filter` on the droplet's CI tree.

Two things this pass found:

- **One mutation survived at first.** Removing the renderer's `render:response` hook passed a
  source-text pin, so `tests/preview-plugin.test.ts` now runs both preview plugins with their
  auto-imports stubbed.
- **A new bug, from writing a test for the pane's reload self-heal.** The first version treated any
  `load` after `ready` as a reload. A frame's first `load` can follow hydration when images finish
  late, which would have looped the pane. It now counts loads per frame element.

### 10.2 Verification of the fixed branches (2026-09-24)

MasjidWebMS `9057ef8b` and renderer `9862509` (code). Later commits on both branches are docs only.

**Verified**

- **Laravel full suite** on the droplet's CI tree, one suite at a time: **4,360 passed, 1 skipped,
  0 failed** (48,535 assertions, 536 s).
- **Admin SPA:** `npm run test:spa` 15/15. The Vite build is green. `tsc --noEmit` stays at its
  baseline of 54 errors.
- **Renderer:** `npm test` **520/520**. The workerd gate at `9862509` is **28/28**. Public output
  against `origin/main` is **10/10 identical**.
  - Two gate failures on the way were harness races, not renderer faults. A warm-up baseline was
    listed before a public write landed, and a stale keep-alive socket was reused. Both are fixed
    in the script, and the renderer doc records them.
- **Staging, real Cloudflare and MySQL** (Laravel `9057ef8b` via `scripts/ship.sh staging`,
  renderer alias from `9862509`):
  - An unsigned `POST /__manara/purge` on the alias answers **401**.
  - A SuperAdmin session for `/حول` returned `…/__manara/preview/%D8%AD%D9%88%D9%84?mp=…`. The
    real renderer answered it as a preview: 200, `x-manara-preview: 1`, tenant 13, `no-store`,
    `frame-ancestors https://masjid-staging.hopetechapps.com`. So h3 decodes the path on Cloudflare
    as it does locally.
  - MEC's client admin was refused a pages session (403, `web_pages` off) and granted a theme
    session. Its preview carried `noindex`.
  - Two same-values theme saves, 40 s apart, watched in the `jobs` table:
    - each first pass ran within about 3 s;
    - the one second-pass job came due 75 s after save #1, released itself and reappeared (attempts
      1) due 40 s later, which is 75 s after save #2, then ran;
    - `failed_jobs` stayed 0, and no renderer warning was logged.
  - The served `LivePreviewPane` chunk carries the sandbox list and no `allow-top-navigation`.
  - QA tokens revoked afterwards (0 left); no staging data changed.

**Assumed / not verified**

- **Not driven in a browser:** the frame's address after the `mp` strip, and the pane's reload
  self-heal. Both are unit-tested, with source pins on the wiring. Driving them needs an admin
  session in the browser, which this pass did not use.
- **Unknown:** the KV plan, and what a quota refusal looks like in practice (ASSUMPTIONS 8–9).
- **Unknown until §8 step 0:** production links with a non-`_blank` target (ASSUMPTIONS 10).
- **Not set yet:** the production configuration and the CORS addition (the point session's, §8).

### 10.3 Before the review (8fff6bf8 / fa59bc4)

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
- Laravel full suite on the droplet CI copy: at `2de3a9de` 4322 passed, 1 skipped, 2 failed; at
  `0b648b50` 4325 passed, 1 skipped, 1 failed. Every failure was a new test's own mistake
  (persisting headers; a shadowed HTTP fake; expecting four follow-ups where the job's uniqueness
  correctly queues one), fixed in `ecb00efd` and `61e0375c`; the preview, purge, CSP and
  route-table pin files were re-run green after each fix.
- Live tenants unchanged, before production: the renderer's `scripts/compare-builds-public.mjs`
  served `origin/main` and this branch under workerd against one stub API — 10/10 identical
  (visible text, cached re-read, status and header names; two tenants; `/`, `/about`,
  `/services`, the payload guard's 404 and an unknown path). Production RBI on the five hosts
  remains the point session's, at deploy.
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
