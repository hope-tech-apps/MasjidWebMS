# T-040 Scout C — External surfaces for a Manara STAGING environment

Read-only reconnaissance. Date: 2026-09-09/10. **Nothing was created, modified or deleted.**
Secrets are redacted throughout — token *values* were never printed.

---

## 1. Cloudflare

### 1.1 Token

- File present: `~/.cloudflare-token`, mode `0600`, 53 bytes. Value REDACTED.
- `GET /user/tokens/verify` → **`success:false`, code 1000 "Invalid API Token"**.
  This is NOT a broken token — it is an **account-owned token**, which is not
  visible on the *user* token endpoints.
- `GET /accounts/86cec9c5.../tokens/verify` → **`success:true`, status `active`**.
- Token name: `sparkling-king-3fc2`; token id `a3c6eb9b…REDACTED`.
- Account: `86cec9c5e0efe76fedb5698a2be91beb` — "Developer@hopetechapps.com's Account"
  (the only account this token can see).

#### Token policies (self-described via `GET /accounts/{acc}/tokens/{id}`)

Two allow-policies, **361 permission groups total**:

| # | Resource scope | Perms | Notable |
|---|---|---|---|
| 1 | `com.cloudflare.api.account.86cec9c5…: "*"` | ~271 | **DNS Write**, **Zone Write**, **SSL and Certificates Write**, **Workers Routes Write**, **Workers Scripts Write**, **Pages Write**, Cache Purge |
| 2 | `com.cloudflare.api.account.86cec9c5….zone.*: "*"` | 90 | DNS **Read**, Zone Write, Zone Settings Write, Zone Transform Rules Write, Zone WAF Write, Cache Settings Write |

This is effectively an **all-permissions account token**.

#### CAN it create DNS records in hopetechapps.com? — **YES, verified.**

Because "DNS Write" sits in the account-scoped policy while the zone-scoped
policy only lists "DNS Read", the permission list alone was ambiguous. Resolved
with a **non-creating authorization probe**:

```
POST /zones/859eddb9…/dns_records   body: {}
-> HTTP 400
   {"success":false,"errors":[{"code":9000,"message":"DNS name is invalid."}]}
```

A **400 validation** error (not `403` / code `9109` "Unauthorized to access
requested resource") proves the request passed authorization and failed only on
body validation. An empty body cannot create a record; record count re-checked
immediately after and was **still 15**. So write permission is **confirmed, not
inferred**.

### 1.2 Zones visible to the token (8)

| zone | id | plan |
|---|---|---|
| aiinnovation.dev | eb820530… | Free |
| al-aqsaclinic.org | e0f0180c… | Free |
| **alrazischool.org** | 825790009319825b1f1ba581ac34ca0c | Free |
| burlingtonmasjid.com | 4d18c044… | Free |
| **hopetechapps.com** | 859eddb9bce48f4f35e6197f6c0b8e15 | Free |
| joinwird.com | 8dc102b5… | Free |
| mizanfintech.app | 359e3304… | Free |
| tapcraft.tech | 2ce0915f… | Free |

All `status: active`, `paused: false`.

### 1.3 DNS — hopetechapps.com (15 records)

| name | type | content | proxied |
|---|---|---|---|
| hopetechapps.com | CNAME | hope-tech-site.pages.dev | **true** |
| www.hopetechapps.com | CNAME | hope-tech-site.pages.dev | **true** |
| api.hopetechapps.com | CNAME | hope-tech-site.pages.dev | **true** |
| **masjid.hopetechapps.com** | **A** | **164.90.253.138** | **false** (TTL 60) |
| **manara.hopetechapps.com** | A | 164.90.253.138 | **true** |
| **alrazi.manara.hopetechapps.com** | CNAME | manara-renderer.pages.dev | **true** |
| **mec.manara.hopetechapps.com** | CNAME | manara-renderer.pages.dev | **true** |
| hopetechapps.com | MX | mx.zoho.com / mx2 / mx3 | false |
| hopetechapps.com | TXT | `v=spf1 include:zohomail.com ~all` | false |
| zmail._domainkey | TXT | DKIM (REDACTED) | false |
| hopetechapps.com | CAA | `0 issue "letsencrypt.org"` | false |
| hopetechapps.com | CAA | `0 issue "sectigo.com"` | false |
| hopetechapps.com | CAA | `0 issue "pki.goog"` | false |

### 1.4 SSL — hopetechapps.com

- `GET /zones/{id}/settings/ssl` → **`"full"`** (non-strict). Confirms the belief.
- `GET /zones/{id}/ssl/universal/settings` → **`enabled: true`**, CA `google`.
- `GET /zones/{id}/ssl/certificate_packs?status=all` → **2 universal packs, no
  advanced/ACM pack**:

| type | status | hosts | CA |
|---|---|---|---|
| universal | **active** | `hopetechapps.com`, **`*.hopetechapps.com`** | google |
| universal | backup_issued | `hopetechapps.com`, `*.hopetechapps.com` | lets_encrypt |

**CONFIRMED — the one-level wildcard fact holds.** The cert hosts are exactly
`hopetechapps.com` + `*.hopetechapps.com`. RFC 6125 wildcards match a single
label, therefore:

| candidate staging hostname | covered by Universal SSL? |
|---|---|
| `masjid-staging.hopetechapps.com` | ✅ **YES** (one label) |
| `staging-api.hopetechapps.com` | ✅ YES |
| `staging.masjid.hopetechapps.com` | ❌ **NO** (two labels) |
| `api.staging.hopetechapps.com` | ❌ NO |

Caveat worth recording: `alrazi.manara.*` and `mec.manara.*` ARE two-level and
DO work — but only because they are **Cloudflare Pages custom domains**, which
get their own per-hostname certificate issued by Pages, entirely separate from
this zone's Universal SSL pack. That mechanism is not available to a plain
proxied A record pointing at the droplet. Do not read those two rows as
evidence that two-level hostnames work in general.

### 1.5 Workers routes on hopetechapps.com

`GET /zones/{id}/workers/routes` → exactly 4, all `script: manara-marketing`:

```
manara.hopetechapps.com/
manara.hopetechapps.com/masjids
manara.hopetechapps.com/schools
manara.hopetechapps.com/community
```

**Nothing would intercept a new staging hostname.** All four patterns are
host-pinned to `manara.hopetechapps.com` and are exact paths (no `/*`).
Account has 3 Worker scripts: `manara-marketing`, `tapcraft-student-id`,
`tapcraft-web`.

### 1.6 Sibling zone alrazischool.org (checked opportunistically)

- SSL mode: **`full`**; Universal SSL active, hosts `alrazischool.org` + `*.alrazischool.org` (CA google), backup lets_encrypt.
- **Zero Workers routes.**
- `portal.alrazischool.org` → **A 164.90.253.138, proxied:false** (DNS-only) — matches STATE.md; it serves the droplet's own Let's Encrypt cert via certbot.
- Apex + `www` are proxied CNAMEs to `al-razi-school-web.pages.dev`.
- Same CAA triple (letsencrypt / sectigo / pki.goog).

### 1.7 Cloudflare Pages projects (10) — relevant domain bindings

| project | domains |
|---|---|
| `manara-renderer` | manara-renderer.pages.dev, **alrazi.manara.hopetechapps.com**, **mec.manara.hopetechapps.com**, burlingtonmasjid.com, www.burlingtonmasjid.com |
| `al-razi-school-web` | al-razi-school-web.pages.dev, www.alrazischool.org |
| `hope-tech-site` | hope-tech-site.pages.dev, api.hopetechapps.com, www.hopetechapps.com |
| `burlington-masjid-website` | burlington-masjid-website.pages.dev |
| others | mec-charlotte-prototype, alaqsa-clinic-web, burlington-camp-2026, arqam-study, ai-innovations, mizan |

Note: the Nuxt renderer is on **Cloudflare Pages**, not Vercel. Pages gives
free per-branch preview URLs (`<branch>.manara-renderer.pages.dev`) plus
per-environment env vars — the natural staging front door.

### 1.8 Implications for a staging host

- **Low-friction path:** `masjid-staging.hopetechapps.com`, **proxied A → 164.90.253.138**.
  Edge cert already exists (active `*.hopetechapps.com` pack) — no issuance, no
  wait. SSL mode `full` (non-strict) means CF→origin accepts the droplet's
  existing/self-signed cert, so **no certbot run is needed on the droplet**.
- **If instead DNS-only** (mirroring how `masjid.*` is configured), Cloudflare's
  cert is bypassed entirely and the **origin must present its own valid cert** —
  i.e. a `certbot certonly --webroot` run for that hostname. CAA permits it
  (`letsencrypt.org` is listed).
- nginx on the droplet is `default_server` on 80/443, so any Host is already
  answered; per STATE.md a new vhost is for SNI/cert only and must NOT repeat
  `default_server`.
- **Avoid** `staging.masjid.hopetechapps.com` — two labels, not covered.

---

## 2. Nuxt public renderer — `/Users/moneebsayed/Developer/burlington-masjid-site`

The API base is env-driven at `nuxt.config.ts:94` — `apiBaseUrl: process.env.API_BASE_URL`
under `runtimeConfig.public`, so Nuxt's convention makes **`NUXT_PUBLIC_API_BASE_URL`**
the runtime override (`API_BASE_URL` alone is build-time only). Tenancy is a
separate, private key: `nuxt.config.ts:91` `tenantHosts: process.env.TENANT_HOSTS || JSON.stringify(DEFAULT_TENANT_HOSTS)`
(defaults at `:19-24` cover only burlingtonmasjid.com/localhost/127.0.0.1),
overridable at runtime as **`NUXT_TENANT_HOSTS`**. `server/middleware/tenant.ts:22`
calls `resolveTenantFromEvent` (`server/utils/tenant.ts:39`), which reads the host
from `x-forwarded-host` else `host`, supports wildcard `*.suffix` keys, and sets
`x-manara-tenant`; **an unmatched host yields `null` → 404, with no fallback tenant.**
Consumers — `app/composables/useApi.ts:43,200`, `app/composables/useSplashAnnouncement.ts:50-51`,
`app/components/section/Donate.vue:11-12`, `app/components/section/event/Paginated.vue:30-31` —
all read `config.public.apiBaseUrl` with the same hardcoded fallback
`'https://masjid.hopetechapps.com/api/v1/'`.
**Answer: yes, a preview can point at staging with no code change**, by setting
`NUXT_PUBLIC_API_BASE_URL=https://masjid-staging.hopetechapps.com/api/v1/`
**plus `NUXT_TENANT_HOSTS`** including the preview host (e.g. `{"*.pages.dev":{"id":"1"},…}`),
otherwise every preview URL 404s.
Three things defeat env-only switching: (a) the production fallbacks above
silently take over if the var is unset or typo'd — a staging misconfig reads as
*working*, against prod; (b) `nuxt.config.ts:239` `"/jummah-lunch/**": { redirect: "https://masjid.hopetechapps.com/jummah-lunch/**" }`
is a build-time literal so a preview still bounces to prod there; (c)
`.github/workflows/canary.yml:31` pins `API: https://masjid.hopetechapps.com/api/v1`.
Also note the `.replace(/\/api\/v1\/.*$/,'/api')` derivation — a base **not**
containing `/api/v1/` breaks the mobile/splash/donate endpoints.

## 3. iOS — `/Users/moneebsayed/Developer/New Masjid System`

The API host is a **hardcoded Swift literal in three independent places with no
build-time switching mechanism at all.** The single source for the phone app is
`Masjid/Models/S.swift:63` — `static let url = "https://masjid.hopetechapps.com/api"`
inside `S.DevelopmentServer`, selected by `S.swift:12`
(`static var server: ServerConfig.Type = DevelopmentServer.self`).
`S.ProductionServer.url` at `S.swift:55` is the **empty string** — the
"production" struct is a dead stub and every shipped build runs against the
"development" one. Consumers: `Masjid/Models/Networking/HTTP/APIRouter.swift:263`
(`let baseUrl = S.server.url`; key header at `:278`) and
`Masjid/Views/Main/Splash/SplashAnnouncementProvider.swift:60`. The tvOS/shared
package repeats the literal at
`MasjidKit/Sources/MasjidKit/Networking/MasjidAPIClient.swift:25` (`APIConfig.development`),
consumed by `MasjidTV/App/TVAppConfig.swift:52`. The `BuildMasjid*.swift` files
carry only the tenant id, **no host** (`Masjid/Config/BuildMasjid.swift:9`
`masjidId = 1`; `BuildMasjid+NAFIS.swift` `masjidId = 5`). The pbxproj has
exactly 7 Debug + 7 Release `XCBuildConfiguration` entries and nothing else —
**no Staging config, zero `.xcconfig` files** (0 `baseConfigurationReference`),
and `SWIFT_ACTIVE_COMPILATION_CONDITIONS` is only ever `DEBUG`
(`Masjid.xcodeproj/project.pbxproj:7233,7388,7418`). No
`ProcessInfo.processInfo.environment` and no `CommandLine.arguments` use in any
Swift file; the only launch argument anywhere is `-masjidId 13` in
`xcshareddata/xcschemes/Muslim Education Center TV.xcscheme:64`, which switches
**tenant, not host** (and per memory the `-masjidId` launch-arg path is dead —
tenant is fixed at compile time). No Info.plist holds a URL (only `MASJID_ID = 1`
at pbxproj:7089,7413, read via `TVAppConfig.swift:42`).
**To point a build at staging today you must edit source** — minimally
`S.swift:63`, plus `MasjidAPIClient.swift:25` for TV. A proper staging path
needs a new build configuration (or `.xcconfig` + `SWIFT_ACTIVE_COMPILATION_CONDITIONS=STAGING`)
built from scratch. Easiest interim: flip `S.swift:12` to `ProductionServer` and
give `ProductionServer.url` the staging host — the stub is already wired in.

## 4. Android — `/Users/moneebsayed/Developer/burlington-masjid-Android`

Also a **hardcoded Kotlin literal, not gradle-driven**:
`app/src/main/java/com/app/masajid/data/api/RetrofitClient.kt:11` —
`private const val BASE_URL = "https://masjid.hopetechapps.com/"`, fed straight
into `Retrofit.Builder().baseUrl(BASE_URL)` at `:26`. It is the **only**
`hopetechapps` hit in the repo. `app/build.gradle` has `flavorDimensions "masjid"`
(line 32) with two flavors, `burlington` (34) and `nafis` (43), but their
`buildConfigField`s are tenant/geo only — `MASJID_ID` ("1"/"5", lines 36/46),
`DEFAULT_LAT`/`DEFAULT_LON` (40-41, 49-50) — plus `resValue "string","app_name"`
and `applicationIdSuffix ".apex"`. There is **no `BASE_URL`/`API_URL`
buildConfigField and no debug buildType block**; `buildTypes` (line 69) defines
only `release` (minify off + signing). The one `manifestPlaceholders` entry is
`MAPS_API_KEY` (line 27) from `project.findProperty`. `BuildConfig.*` is consumed
only at `AppConfig.kt:14,29,30` and `AppGateViewModel.kt:42-43`.
`gradle.properties` and `local.properties` contain no host (only `sdk.dir`).
**No existing flavor or buildType targets a different host** — you would add
`buildConfigField "String", "BASE_URL", …` per buildType/flavor and change
`RetrofitClient.kt:11` to read `BuildConfig.BASE_URL`. This is the cheapest of
the two native ports to make staging-switchable.

## 5. Al-Razi school site — `/Users/moneebsayed/Developer/al-razi-school-web`

Only one live reference, and it is a plain **server-side 307 redirect, not a
proxy or rewrite**: `src/routes/portal/+server.ts:29-32` —
`const PORTAL = 'https://portal.alrazischool.org/portal';` then `redirect(307, PORTAL)`.
The host is **hardcoded, not env-driven** (no `PORTAL_*`/`MANARA_*` var anywhere;
`.env.example` covers only Supabase/Stripe). Nav links are relative
`href="/portal"` (`src/routes/+layout.svelte:137,216`), so they hit that redirect.
There is **no** `vercel.json`, `netlify.toml`, `_redirects` or `next.config`;
`svelte.config.js:23-26` is `adapter-cloudflare` with no rewrite/proxy config,
and its comment records that the old `masjid.hopetechapps.com/portal/14` rewrite
(and `window.__PORTAL_MASJID__` injection) was **deliberately removed** —
`hopetechapps.com` now appears only in that file's comments (`+server.ts:7,11,25`)
and `.github/workflows/deploy.yml`. **Repointing the portal at a staging Manara
host requires a code edit.** Deploy target is Cloudflare Pages project
`al-razi-school-web`.

## 6. Local tooling on this Mac

| tool | version |
|---|---|
| node | v22.12.0 |
| npm | 10.9.0 |
| rsync | **openrsync, protocol version 29** (macOS default, NOT GNU rsync 3.x) |
| ssh | OpenSSH_10.3p1, LibreSSL 3.3.6 |
| curl | 8.7.1 (SecureTransport / LibreSSL 3.3.6) |
| jq | jq-1.7.1-apple |
| git | 2.53.0 |

`rsync` is Apple's **openrsync at protocol 29** — GNU-only flags (`--info=progress2`,
`--outbuf`, some `--delete` variants) may not behave as in GNU rsync 3.x. The
documented deploy recipe (build locally + rsync `public/build` WITHOUT `--delete`,
then chown www-data) is still fine at protocol 29.

**SPA build known-good locally: YES.** `/Users/moneebsayed/Developer/MasjidWebMS/artifacts`
holds **13 files, all `vue_build_*.log`**. Most recent by mtime:
`vue_build_deploy_20260811-163642.log` and `vue_build_deploy2_20260811-205918.log`
(both mtime 2026-09-08 09:25, content from the 2026-08-11 runs), then nine more
from 2026-08-11 and two from 2026-07-13. Tail of the newest:

```
public/build/assets/app-CFCpSYsQ.js   671.12 kB │ gzip: 218.97 kB
(!) Some chunks are larger than 500 kB after minification...
✓ built in 5.31s
```

Clean Vite success in 5.31s. No build was run during this recon.
Standing warning from memory: **never `npm run build:prod`** — it bakes
`VITE_APP_URL` and breaks the `manara.*` host; there is no node on prod.

---

## 7. Surprises / things that contradict the brief

1. **`/user/tokens/verify` returns "Invalid API Token" while the token works fine.**
   It is account-owned; the correct endpoint is `/accounts/{acc}/tokens/verify`.
   Anyone testing this token with the documented user endpoint will wrongly
   conclude it is dead.
2. **Two-level subdomains already exist and work** (`alrazi.manara.*`,
   `mec.manara.*`) — but only via Pages per-hostname certs, not Universal SSL.
   Easy to mis-generalise into "two levels are fine".
3. **The iOS "ProductionServer" URL is the empty string** and the shipping app
   runs on `DevelopmentServer`. Every production iPhone build is pointed at prod
   through a struct literally named *Development*.
4. **The Nuxt fallback is prod.** If `NUXT_PUBLIC_API_BASE_URL` is unset or
   misspelled on a staging preview, it silently talks to **production** and
   looks healthy — the exact silent-failure shape already in memory.
5. **Nuxt renderer is on Cloudflare Pages, not Vercel** — the brief's "preview
   deployment" framing maps to Pages preview branches + Pages env vars.
6. `nuxt.config.ts:239` hard-redirects `/jummah-lunch/**` to prod at build time,
   so that route can never be exercised on staging without a code change.
7. The token is an **all-permissions account token** (361 permission groups,
   Workers Scripts Write, Pages Write, Zone Write, Cache Purge across all 8
   zones). Far broader than a staging task needs — worth scoping down.
8. `masjid.hopetechapps.com` has **TTL 60** — a cutover or rollback propagates
   in about a minute, which is helpful.

## 8. Method note

Every call above was a `GET` except one deliberate authorization probe: a
`POST /dns_records` with an **empty JSON body**, which cannot create a record and
returned HTTP 400 `code 9000 "DNS name is invalid."` (a validation error, not
`403`/`9109`), thereby proving write authorization. DNS record count was
re-read immediately afterwards and was unchanged at **15**.
