# Live preview for the Manara page tool — delegation brief

Written 2026-09-24 by the point session (Abdullah) for a delegated session. The owner
approved delegating this and required that the delegated session work on the
**engineering-excellence kit**.

## 0. The kit, first

Every Claude Code session on this Mac loads the kit from `~/.claude` (the core via
`~/.claude/CLAUDE.md`, a SessionStart hook, the `codebase-explorer` agent). Confirm it loaded:
your first context should carry an `<engineering-excellence-…>` note from the hook. If it did
not, read `~/.claude/engineering-excellence/CLAUDE.md` before anything else and say so.

Then follow it: **probe** both repos (MasjidWebMS is web + backend; the renderer is web),
**load only the matched addenda** — `platforms/web/stacks/nuxt-vue.md` (it records this
renderer's real cache, payload and runtime-config traps), `platforms/web/stacks/vite-spa.md`,
`platforms/backend/stacks/php.md` — and the web references your work touches. **Persist recon**
to `.claude/<platform>-recon.md` in each repo. Create `STATE.md`, `DECISIONS.md`,
`ASSUMPTIONS.md` at the root of each repo you change, per the kit's Bootstrap (§12). Ask and
proceed in the same turn; production changes wait for the owner's yes.

## 1. What the owner asked for, and decided

> "on the web page management tool inside Manara, I need a live preview for making edits and
> tweaks on the sites in general"

Decided with the owner on 2026-09-24 — do not relitigate:

| Question | Decision |
|---|---|
| Save model | **Preview as you type; Save publishes.** Visitors see nothing until Save; Save then makes the change live IMMEDIATELY (today a saved edit takes up to 5 minutes to appear, because of the page cache) |
| Who | **Everyone who can edit pages**, client admins included — so the preview's security is client-grade from day one |
| Scope | **Page sections, theme (colours, fonts, header/footer style), menu and page settings, and the splash pop-up** |
| Fidelity | **The real renderer, exact** — the actual Nuxt site in a pane, in a signed preview mode, at desktop, tablet and phone widths. Not a re-drawn mock |

## 2. What exists (read it yourself; these are leads, not sources)

- Admin page builder: `resources/vue-app/views/dashboard/pages/PageSectionsView.vue` (~300
  lines) + its section editor modal; `PagesView.vue`; `sections/SectionsLibraryView.vue`.
  Saves go straight to live data. `pages` has `is_active` only — no draft/publish, no preview,
  no iframe anywhere.
- Renderer: `~/Developer/burlington-masjid-site` (Nuxt 4, Cloudflare Pages). Two projects:
  `manara-renderer` (branch `main`: Burlington 1, BISS 18, mec.manara 13, alrazi.manara 14) and
  `mec-web` (branch `cloudflare-migration`). Tenant = Host, via the `NUXT_TENANT_HOSTS` runtime
  map. HTML cached in KV (`MANARA_PAGE_CACHE`) through Nitro route rules with
  `varies: [host, x-forwarded-host]`, a build-id cache `name`, stale-while-revalidate, 300 s.
  `server/middleware/payload-guard.ts` 404s every `_payload.json` (a cross-tenant leak closed
  today — read `shared/payloadPath.ts` for why). No preview mode exists.
- Theme: `theme_settings` (four colours + `tokens` JSON) → `/api/v1/settings` →
  `app/utils/tenantTheme.ts`. Splash: `splash_announcements`, cached server-side in Laravel
  (`MobileCache::masjidKey(id, MobileCache::SPLASH)`).
- CORS is a static env list (`CORS_ALLOWED_ORIGINS`); read `docs/tenant-host-map.md`.

## 3. Hard requirements (each needs a test that fails without it)

1. **A preview render is never cached and never served from the cache.** A preview written
   into `MANARA_PAGE_CACHE` under any key, or a public request answered with preview HTML, shows
   unsaved content to visitors. Route rules key on path + query + `varies` headers, so a
   `?preview=` query still gets STORED under its own key — that is not "not cached". Prove it
   with the kit's method: body hashes across requests, in the target runtime.
2. **Preview requires a short-lived token the API signs**, scoped to one organisation (and
   page), issued only to a user allowed to edit that organisation's pages. No token, bad token,
   expired token, or another organisation's token → the ordinary public page, never an error
   page that leaks why.
3. **Unsaved content reaches the renderer only from the admin.** If you use `postMessage`, the
   renderer accepts messages only from the admin origin, only in preview mode, and validates the
   payload shape. Unsaved content must not be persisted anywhere public.
4. **Framing is opened only for preview.** Public pages keep their current framing policy; the
   preview response allows `frame-ancestors` for the admin origin only. The admin SPA's own CSP
   must allow the renderer as a frame source (the repo has an incident where CSP `self` broke a
   second host).
5. **The preview never renders another tenant.** Resolve the tenant from the VERIFIED TOKEN, not
   from the Host header — that also keeps you independent of W1 below.
6. **Save goes live immediately.** Purging the saved page's KV entries needs the renderer's KV
   binding; do not hand Laravel Cloudflare KV credentials for it — a signed purge endpoint on the
   renderer (HMAC, host + path scoped, idempotent) is one defensible design. Theme and menu saves
   affect every page of the tenant: purge the tenant, not one path.
7. `noindex` on every preview response.
8. Live tenants unchanged: the visible text of `/`, `/about`, `/services` on all five hosts
   byte-identical before and after each deploy (method: strip tags, hash; see the point
   session's `/tmp/rend-check/vis.py` pattern), and the payload guard still 404s.

## 4. Coordination with Manara Studio W1 (the point session is building it now)

W1 changes the renderer's tenant resolution (a runtime host → org lookup, falling back from
`NUXT_TENANT_HOSTS`), adds a `masjid_domains` table, and makes CORS read it. **Do not change
tenant resolution or CORS.** Rule 5 above keeps you independent of it. Studio's web preview will
later REUSE your preview mode, so build it as a general "render this tenant with these overrides"
capability, not a page-builder-only one, and document its contract in the renderer repo.
Plan: `MasjidWebMS/docs/manara-studio.md`, `docs/manara-studio-w1.md`.

## 5. How work ships here (standing rules)

- Work in **fresh worktrees off `origin/main` / `origin/cloudflare-migration`**. Never edit the
  shared checkouts: `~/Developer/MasjidWebMS` belongs to the point session, and
  `~/Developer/burlington-masjid-site` is checked out on another session's branch.
- No PHP on this Mac: the Laravel suite runs on the production droplet in `/root/manara-ci`,
  shared, one suite at a time. Check for a running suite with
  `ps -eo pid,args | grep "[p]hp artisan test" | grep -v "bash -c"` — `pgrep -f` matches itself.
  Exclude `bootstrap/cache/` when rsyncing; `view:clear` before a suite.
- Renderer: `npm test` must be `# fail 0`; commit BEFORE building (the cache build id is HEAD).
- Staging exists and is shared (`scripts/ship.sh staging <ref>`; check the box's `git reflog`
  first). **Production is shipped by the point session, with the owner's go.** Hand back a
  branch, the test results, and the staging URL.
- Never add a Co-Authored-By or any AI attribution line to a commit. Never loosen permissions on
  `/root` on the production droplet. Any production data script is guarded: `$APPLY = false` on
  line 1, host guard, masjid-scoped, backup written AND read back, one transaction, every write
  read back, a `$REVERSE` path.

## 6. Deliverable

First a plan, in `MasjidWebMS/docs/live-preview.md`: the contract (token, preview route, message
shape, purge), the slices in ship order, and the tests. Then build it slice by slice. Report
what is verified and what is assumed, separately.
