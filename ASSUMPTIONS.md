# Assumptions

> Live register of things taken on faith. Reviewed at every checkpoint.
> An assumption stated once in a reply is lost. One in here gets closed.
> Status: 🟢 resolved · 🟡 at risk · 🔴 open

| # | Assumption | Source | Status | Impact if wrong | Owner | Resolved how |
|---|---|---|---|---|---|---|
| 1 | A `/__manara/preview/*` request is routed to the uncached `/**` renderer, never a cached copy | read nitropack 2.13.4 `app.mjs:124-135`, `rollup/index.mjs:1077` | 🟡 | preview HTML written to KV under a preview-host key (requirement 1) | live-preview session | workerd run: KV key list before/after preview requests |
| 2 | Rewriting `event._path` in `render:before` makes Nuxt render and hydrate the target page | read `renderer.mjs`, `app.mjs:23`, `pages/runtime/plugins/router.js:74` | 🟡 | preview shows 404 or hydration mismatch | live-preview session | workerd run + staging browser drive |
| 3 | KV deletes reach other colos within ~60 s | Cloudflare KV docs (eventual consistency) | 🔴 | "live immediately" is slower elsewhere | live-preview session | measure on staging |
| 4 | Nitro's `hash()` from `nitropack/runtime/internal/hash` is the function the cache used for the key segments | same package, same file (`cache.mjs` imports `./hash.mjs`) | 🟡 | purge deletes nothing | live-preview session | workerd run: keys gone after purge |
| 5 | Production CORS need not include the preview origin for the core preview to work | code read: store keeps SSR state on a failed refetch | 🟡 | preview blank after hydration | live-preview session | staging (preview alias is outside staging CORS) |
| 6 | `manara-renderer.pages.dev` stays a non-tenant host | `docs/tenant-host-map.md`; W1 S10 excludes `.pages.dev` | 🟢 | preview host would also be a tenant host | point session | renderer refuses a preview host found in the tenant map |
| 7 | The admin SPA origins are `masjid.` and `manara.hopetechapps.com` (+ `-staging`) | `docs/tenant-host-map.md`; web recon §7 | 🟡 | pane refuses to load on an unlisted admin host (fails closed) | point session | config lists at ship |
