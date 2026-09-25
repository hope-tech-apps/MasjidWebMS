# Tenant host map

Every hostname that serves Manara, what answers it, which organisation it
belongs to, and where that mapping is set. Compiled on **2026-09-17** from the
sources listed under each table. Anything marked *unverified* was not checked
that day.

This file exists because the list is split across five places (the app's
`.env`, nginx, Cloudflare DNS, Cloudflare Worker routes, and the Nuxt
renderer's tenant map). `App\Http\Middleware\TrustedHosts` needs the Laravel
part of it to be complete, and nobody could state that part from one place.
Enforcing the middleware is a separate decision; see
`deploy/TRUSTED-HOSTS-ENFORCEMENT.md`.

Organisation ids used below: **1** Burlington Masjid · **13** Muslim Education
Center (MEC) · **14** Al-Razi School · **18** Burlington Islamic Sunday School
(BISS).

---

## 1. Hostnames that reach this Laravel app

These are the only names the middleware has to admit. **All organisations share
one app**, so no hostname here is limited to one organisation, except the portal
names, which pick the organisation `/portal` opens.

### Production — droplet `masjid-backend-24-04`, 159.65.239.51 (reserved IP 164.90.253.138)

| hostname | DNS (Cloudflare) | how nginx serves it | organisation | admitted by | evidence, 2026-09-17 |
|---|---|---|---|---|---|
| `masjid.hopetechapps.com` | A 164.90.253.138, **DNS-only** | its own `server_name`, **and** `default_server` on :80 and :443 | all: admin SPA, the API used by both native apps and every Nuxt site, `/jummah-lunch/{id}`, `/portal/{id}`, `/family/{id}` | `APP_URL` | `/up` 200 with `server: nginx/1.24.0`; its Host appears in 4,441 nginx error-log lines (the files described below) |
| `portal.alrazischool.org` | A 164.90.253.138, **DNS-only** | its own vhost (`server_name`, no `default_server`), same document root | **14** (the id-less `/portal`; `/` redirects there) | `PORTAL_HOSTS=portal.alrazischool.org=14` | `/up` 200 with `server: nginx`; 558 error-log lines |
| `manara.hopetechapps.com` | A 164.90.253.138, **proxied** | **no vhost of its own.** It reaches the app only because the masjid vhost is `default_server` | all: the one sign-in door (`/auth/sign-in`), `/api/*`, `/build/*`, `/portal/{id}` | **nothing yet. It must go in `TRUSTED_HOSTS`** | `/api/mobile/masjids` answers JSON with this app's CSP; 127 error-log lines. Worker `manara-marketing` takes **only** the exact paths `/`, `/masjids`, `/schools`, `/community`, and everything else reaches Laravel |

### Staging — droplet `masjid-staging`, 157.230.212.38

| hostname | DNS (Cloudflare) | how nginx serves it | admitted by | evidence |
|---|---|---|---|---|
| `masjid-staging.hopetechapps.com` | A 157.230.212.38, proxied | vhost `server_name` | `APP_URL` | `/up` 200 with `X-Robots-Tag: noindex, nofollow` (staging's marker) |
| `manara-staging.hopetechapps.com` | A 157.230.212.38, proxied | same vhost's `server_name` | **nothing yet. It must go in `TRUSTED_HOSTS`** | same; the middleware logged this exact gap on its first staging deploy (2026-09-15) |
| `portal-staging.hopetechapps.com` | A 157.230.212.38, proxied | portal vhost | `PORTAL_HOSTS=portal-staging.hopetechapps.com=14` | same |

The staging `.env` was **not re-read** on 2026-09-17, because that task was
read-only toward staging. The staging values come from
`deploy/staging/env.staging.example` and the staging run of 2026-09-15.

### Hosts that also reach the production origin, and must NEVER be admitted

nginx logs the Host header only in its **error** log, so these counts cover
requests that produced an nginx error line. That is a sample of the traffic,
not a full count. The 15 error-log files read on 2026-09-17 (2026-09-03 00:22 to
2026-09-17 12:14 UTC) name **58 distinct Host values**. With the port and a
trailing dot removed, which is how the middleware compares them, they are **49
names**: the 3 above, and these 46:

- **2 IP literals**, `159.65.239.51` (8,444 lines) and `164.90.253.138` (7,276),
  plus both again with `:443` (2,330 / 2,315). This is scanner traffic: almost
  all of it is dotfile probes that nginx denies.
- **44 third-party hostnames whose DNS still points at our reserved IP.** 38
  recur, for example `promocao.energisaprev.com.br` (5,428),
  `pdscatarinense.idplugger.com` (5,031), `promocaocredceg.com.br` (3,636) and
  many other `*.idplugger.*` and `promocao*.com.br` names. The other 6 are
  one-off Qualys scanner names (`*.qualysperiscope.com.`). None of them is ours.

**What this shows, and what it does not.** Of the 76,099 lines that carry a
Host, 73,566 are `access forbidden by rule` and 2,521 are `directory index ...
is forbidden`: nginx refused those requests before PHP ran. The remaining 12
are FastCGI lines, for `masjid` (11) and `manara` (1). So the error log proves
that these names **arrive at the origin**, not that they **reach Laravel**. The
access log records no Host. It does show that, for every one of the 44 foreign
names, at least one client IP that sent it also got `GET /` 200 (the SPA,
served by Laravel) in the same files. That is evidence by IP, not proof for
each name.

**Consequence: the unknown-host log will never be empty.** Each unlisted name
that reaches Laravel is written at most once an hour: at most about 1,100 lines
a day (46 names × 24), and fewer in practice. "A week of zero warnings" cannot
happen. The usable test is **a week with zero warnings that name a hostname in
a zone we own, and no hour in which the log paused**. The commands are in
`deploy/TRUSTED-HOSTS-ENFORCEMENT.md`.

---

## 2. Hostnames that serve Manara but never reach Laravel under their own name

None of these hostnames arrives at the app in the Host header. The Nuxt sites call
the API at `masjid.hopetechapps.com`, so their requests arrive with that Host.
Their own hostname arrives only as the browser's `Origin`, and CORS controls
that (`CORS_ALLOWED_ORIGINS`, plus, from Studio S9, every `masjid_domains` row
confirmed serving), not `TRUSTED_HOSTS`.

| hostname | served by | organisation | where the mapping lives | evidence, 2026-09-17 |
|---|---|---|---|---|
| `www.burlingtonmasjid.com` | Pages `manara-renderer` (Nuxt) | 1 | `NUXT_TENANT_HOSTS` (Pages production env) | `x-powered-by: Nuxt`, `x-manara-tenant: 1` |
| `burlingtonmasjid.com` | same project | 1 | same | 307 to `www` |
| `sundayschool.burlingtonmasjid.com` | Pages `manara-renderer` | **18** | `NUXT_TENANT_HOSTS` | `x-manara-tenant: 18` |
| `alrazi.manara.hopetechapps.com` | Pages `manara-renderer` | **14** | `NUXT_TENANT_HOSTS` | `x-manara-tenant: 14` |
| `mec.manara.hopetechapps.com` | Pages `manara-renderer` | **13** | `NUXT_TENANT_HOSTS` | `x-manara-tenant: 13` |
| `mec-web.pages.dev` | Pages `mec-web` (MEC's live site) | **13** | `DEFAULT_TENANT_HOSTS` in `burlington-masjid-site/nuxt.config.ts` (branch `cloudflare-migration`). The project sets no `NUXT_TENANT_HOSTS` | `x-manara-tenant: 13` |
| `manara-renderer-staging.pages.dev` (+ `burlington.` / `mec.` / `alrazi.` / `sundayschool.` subdomains in its map) | Pages `manara-renderer-staging` → **staging** API | 1 / 13 / 14 / 18 | that project's `NUXT_TENANT_HOSTS`; its API base is `masjid-staging.hopetechapps.com` | bare host: `x-manara-tenant: 1` |
| `manara-renderer.pages.dev` | Pages `manara-renderer` | none | not in the map, so the renderer fails closed | 404, `x-manara-tenant: unresolved` |
| `alrazischool.org`, `www.alrazischool.org` | Pages `al-razi-school-web` (SvelteKit) | (14's public site) | no Manara tenant | apex 307 to `www`; `/portal` 307 to `portal.alrazischool.org/portal` |
| `parents.alrazischool.org` | Worker `alrazi-parent-guide` (custom domain) | (14's parent guide) | Worker custom domain | 301/200 from Cloudflare |
| `mec-planner.hopetechapps.com` | Worker `mec-feature-planner` (custom domain, D1) | — | Worker custom domain | `/up` 404 from Cloudflare |
| `hopetechapps.com`, `www.hopetechapps.com`, `api.hopetechapps.com` | Pages `hope-tech-site` | — | Pages domains | apex 308 to `www` |
| `resources.burlingtonmasjid.com` | CNAME `burlington-masjid-resources.pages.dev` | — | DNS | 200 from Cloudflare |

Paths on the renderer hosts that look like app paths **do not proxy to Laravel.**
Checked on all five `manara-renderer` hostnames and `mec-web.pages.dev`:
`/jummah-lunch/{id}` is a **307 to `masjid.hopetechapps.com`**, so the request
arrives at the app with our own Host. `/portal`, `/api/v1/settings`, `/storage/…`,
`/account-deletion` and `/family/…` are all answered by Nuxt itself.

### Names in a map with no DNS behind them (inert)

- `new.burlingtonmasjid.com` is in `manara-renderer`'s `NUXT_TENANT_HOSTS` (org 1).
  It has no DNS record and is not a Pages domain.
- `mec.hopetechapps.com` is in `DEFAULT_TENANT_HOSTS` (org 13) **and** in
  production `CORS_ALLOWED_ORIGINS`, but it has no DNS record.

### Native apps

The iOS (`New Masjid System`) and Android (`burlington-masjid-Android`) apps
have `https://masjid.hopetechapps.com` compiled in. No other API host was found
in either repository.

---

## 3. Where each mapping is configured

| setting | where | what it decides |
|---|---|---|
| `APP_URL` | app `.env` → `config('app.url')` | the canonical host; every URL that outlives its request (`App\Support\SiteUrl`); always admitted by `TrustedHosts` |
| `PORTAL_HOSTS` | app `.env` → `config/portal.php` | organisation hostnames pointed straight at the app, mapped to an org id; always admitted |
| `TRUSTED_HOSTS` | app `.env` → `config/trusted_hosts.php` | any other hostname the app answers to; today only the `manara` pair |
| `TRUSTED_HOSTS_ENFORCE` | app `.env` | **unset = log-only** (the shipped default); `true` answers 400 |
| nginx vhosts | `/etc/nginx/sites-enabled/` on each box | TLS certificate and routing; the masjid vhost is `default_server`, which is why every Host reaches PHP |
| DNS | Cloudflare zones `hopetechapps.com`, `alrazischool.org`, `burlingtonmasjid.com` | which box or project a name reaches; proxied or DNS-only |
| Worker routes | zone `hopetechapps.com` | `manara-marketing` on four exact paths of `manara.hopetechapps.com` |
| Worker custom domains | account | `parents.alrazischool.org`, `mec-planner.hopetechapps.com` |
| Pages project domains + `NUXT_TENANT_HOSTS` | Cloudflare Pages env (`manara-renderer`, `manara-renderer-staging`) | Nuxt host → org. **Setting this variable replaces the whole code map**; always write the full map |
| `DEFAULT_TENANT_HOSTS` | `burlington-masjid-site/nuxt.config.ts` | Nuxt host → org when `NUXT_TENANT_HOSTS` is unset (`mec-web`) |
| `masjid_domains` table | app database (Manara Studio S3, `App\Models\MasjidDomain`) | host → org as data, with a status per host. Served publicly by `GET /api/v1/organizations/by-host` (pending/awaiting_nameservers/provisioning/active/manual hosts of live orgs; never `failed` or `reserved`). From S9, CORS (`App\Http\Middleware\HandleCorsWithDomains`) admits the origin of every `corsAdmitted()` row (active or manual, `serving_confirmed_at` set, org not trashed) on top of `CORS_ALLOWED_ORIGINS`, and a form's card payment may return to such a row's origin when it belongs to the form's own organisation; the renderer reads it from S11. Seeded from the live map by `php artisan domains:import-host-map` (dry run unless `--execute`); a probe match is `manual`, anything else `reserved` |
| `NUXT_PUBLIC_API_BASE_URL` | Pages env | which Laravel host the Nuxt site calls (prod: `masjid.hopetechapps.com`; staging project: `masjid-staging.hopetechapps.com`) |
| `CORS_ALLOWED_ORIGINS` | app `.env` → `config/cors.php` | the base list of browser origins that may call `/api/*`; from S9 the confirmed `masjid_domains` origins are added to it per request, never removed from it |
| `FORMS_PAYMENT_RETURN_ORIGINS` | app `.env` → `config/forms.php` | which origins a form card payment may return to (prod: `sundayschool.burlingtonmasjid.com`) |

### Production `CORS_ALLOWED_ORIGINS`, as read on 2026-09-17

`www.burlingtonmasjid.com`, `burlingtonmasjid.com`, `masjid.hopetechapps.com`,
`mec-charlotte-prototype.vercel.app`, `alrazischool.org`, `www.alrazischool.org`,
`mec-web.pages.dev`, `mec.hopetechapps.com`, `sundayschool.burlingtonmasjid.com`
(all `https://`).

This does not affect `TRUSTED_HOSTS`, but it is recorded here because it
disagrees with the table above:

- **`alrazi.manara.hopetechapps.com` and `mec.manara.hopetechapps.com` are live
  renderer hosts that are missing from it.** A preflight with either origin gets
  no `Access-Control-Allow-Origin` (checked 2026-09-17), so data fetched in the
  browser on those two sites is blocked. Server-side rendering still works.
- `mec-charlotte-prototype.vercel.app` and `mec.hopetechapps.com` are listed but
  serve nothing that calls this API.

Changing this is a production `.env` edit, so it needs its own decision.

Update 2026-09-24: the hotfix added both hosts (production now lists 12 origins,
`https://preview.manara.hopetechapps.com` included), so S9's table read changes
nothing for any live origin. See `docs/manara-studio-w1.md` §8 OQ6.

---

## 4. Adding a hostname

**If the new name will reach Laravel** (an A record at a droplet):

1. DNS record. If it is DNS-only, it also needs its own nginx vhost and a
   certificate. Never repeat `default_server`: nginx refuses to start, and
   that reload takes the existing hostnames down with it.
2. If the name is an organisation's portal, add it to `PORTAL_HOSTS`
   (`host=orgId`). Otherwise add it to `TRUSTED_HOSTS`.
3. `php artisan config:cache`. Write the `.env` through its inode (`cat >` or
   an editor, never `mv`).
4. Confirm the name stops appearing in the unknown-host log.
5. Add a row to section 1 of this file.

**If it is a Nuxt tenant site:**

1. Add the name to the project's `NUXT_TENANT_HOSTS`, keeping the full map, and
   to `DEFAULT_TENANT_HOSTS` plus its drift-guard test in `burlington-masjid-site`.
2. From S9: nothing, once its `masjid_domains` row is confirmed serving (Studio's
   "Check now"); CORS admits it then. Before S9 ships, or for a host with no row,
   add its origin to `CORS_ALLOWED_ORIGINS`.
3. It does **not** go in `TRUSTED_HOSTS`.
4. Add a row to section 2.

---

## 5. Re-deriving this file

Every command below is read-only.

```sh
# nginx: names and default_server (production)
grep -nE "server_name|listen" /etc/nginx/sites-enabled/*

# app settings, hostnames only
grep -E "^(APP_URL|PORTAL_HOSTS|TRUSTED_HOSTS[A-Z_]*|CORS_ALLOWED_ORIGINS|FORMS_PAYMENT_RETURN_ORIGINS)=" .env

# Host values nginx has seen (its error log is the only log that records them)
( cat /var/log/nginx/error.log /var/log/nginx/error.log.1; zcat /var/log/nginx/error.log.*.gz ) \
  | grep -oE 'host: "[^"]*"' | sort | uniq -c | sort -rn

# Cloudflare (API token with DNS/Workers/Pages read): per zone
#   GET /zones/{zone}/dns_records          -- A/AAAA/CNAME and proxied flag
#   GET /zones/{zone}/workers/routes
#   GET /accounts/{acct}/workers/domains
#   GET /accounts/{acct}/pages/projects    -- domains
#   GET /accounts/{acct}/pages/projects/{name}  -- deployment_configs.*.env_vars

# which service actually answers a name
curl -sI https://<host>/up | grep -iE '^(server|x-powered-by|x-manara-tenant|x-robots-tag|content-security-policy):'
```
