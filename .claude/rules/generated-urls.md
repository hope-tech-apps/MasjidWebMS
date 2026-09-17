# Generated URLs

`url()`, `asset()` and `route()` resolve against the **incoming request's Host
header**. nginx on both boxes is `default_server` on :80 and :443, so any Host
reaches the app, and the origin answers requests that never passed through
Cloudflare. Verified read-only against production on 2026-09-15:

```
curl -H 'Host: evil.example' https://masjid.hopetechapps.com/account-deletion
=> <form method="POST" action="https://evil.example/account-deletion"
```

That is the page on which a member types an email address and then a mailed
code, served over our certificate, posting wherever the caller chose.

## The rule

> **No value that outlives the request that built it may be built from the
> request.** Use `App\Support\SiteUrl`.

Three ways a URL outlives its request in this application, in order of how long
the damage lasts:

| | lives for | example |
|---|---|---|
| a **column** | forever | `meal_menus.flyer_image_url`, written by `MealMenusController::uploadFlyer`, served on the public ordering page |
| a **cache entry** | the TTL (5–10 min) | every `MobileCache` key — announcements, services, features, show/about/gallery/donation-link |
| a **page someone else acts on** | until they click | a `<form action>` on `/account-deletion` or `/unsubscribe` |
| an **emailed link** | forever, in an inbox | the `List-Unsubscribe` header, minted by `EmailSuppressionService::urls()` |
| a **URL handed to a third party** | until they call it | the provisioning runner's `callback_url` (it carries the job's bearer token there); Stripe's onboarding `refresh_url` / `return_url` |

The cache case is the one with an attacker in it: the keys hold an organisation
id and **no host**, so the first caller to warm a cold entry decides what every
later caller is served, and repeating the request each time it expires holds it
indefinitely. One unauthenticated GET, well inside the mobile rate bucket.

The column case needs no attacker at all — this deploy answers to three
hostnames, and an admin with the SPA open on the wrong one is enough.

The **email** case is the longest-lived of all, and the least obvious: the
broadcast send path is synchronous for anything not scheduled into the future
(`BroadcastsController` → `BroadcastComposer::send()` → `dispatcher->dispatch()`),
so it runs inside the admin's request. `List-Unsubscribe` is POSTed
*automatically* by Gmail and Yahoo, so a wrong host there is a request the
recipient never made — and an unsubscribe that silently never happens.

## What deliberately still follows the request

Same-origin decoration, consumed inside the very response that generated it.
**This list is exhaustive as of 2026-09-15** — it is every remaining
request-relative URL builder in `app/` and `resources/views/`, so it can be
checked rather than trusted:

| where | what it builds |
|---|---|
| `vue-app-index.blade.php` (6 calls) | the SPA's fonts, icons, bundle |
| `Avatar::imageUrl()` | contact avatars in admin/portal payloads (no cached public payload serialises a Contact — re-check if one ever does) |
| `FlyersController::imageUrls()` | bearer-authenticated blob fetches |
| `FlyerCutoutController` (same shape) | ditto |
| `FormResponsesController::…attachments` | bearer-authenticated attachment download |
| `ToolRegistry::flyerStudioUrl()` | a link the admin clicks in their own session |
| every `->paginate()` | `next_page_url` & co. — all paginated endpoints are uncached, so the only caller who sees a forged host is the one who sent it |

Reviewed again on 2026-09-17 against `main` at 045ce11, which had added the app
menu (`AppMenu::payload()` builds `deletion_page_url` through `SiteUrl`) and the
orgs payload (`logo_url` is a media row's `original_url`, from the public disk's
configured `url`). The teacher-notes commits that followed (up to 138ae37) add
no URL builder. The same pass moved three bare `route()` calls that the old
regeneration grep did not look for onto `SiteUrl::route()` — the provisioning
`callback_url` and the two Stripe onboarding URLs.

Regenerate the list with:

```sh
grep -rnE "(^|[^>a-zA-Z_\$'\":])(url|asset|secure_url|secure_asset|route|action)\(|URL::(to|route|asset|action|signedRoute|temporarySignedRoute)|->paginate\(" app resources/views \
  | grep -vE "\\\$(request|this)->route\(|SiteUrl::|:\s*[0-9]+:\s*(\*|//)"
```

Anything in that output which is **not** in the table above is either a new
same-origin case that belongs in it, or a defect. (It also prints a few lines
that build nothing — `Canary\Probe::url()`, `route(s)` inside TenancyCanary's
console strings, `SiteUrl::route()`'s own `absolute: false` call, and a Blade
comment. As of 2026-09-17 those are the only ones.)

**Do not "fix" these.** `SecurityHeaders` sends `default-src 'self'`, widened to
name `config('app.url')` only on the proxied paths (`/portal`, `/jummah-lunch`).
Pin `asset()` and every bundle, font and icon on manara.hopetechapps.com becomes
cross-origin against a `'self'` policy and is refused by the browser — the
failure already recorded in this repo as *assets pinned to one host*.
`CORS_ALLOWED_ORIGINS` does not name manara.hopetechapps.com either, so the
bearer-token blob fetches would fail there too. And `flyerStudioUrl` pinned would
walk an admin across hostnames mid-session, away from their session cookie.

The test that keeps this honest is
`HostHeaderUrlIntegrityTest::the_test_harness_really_does_forge_the_host`: it
asserts the SPA shell **does** echo a forged Host back. If somebody pins
`asset()`, that case goes red and says why.

## The door

`App\Http\Middleware\TrustedHosts` refuses a Host this deployment does not
serve. It is prepended, so an unknown Host reaches no controller, no cache write
and no rendered form action.

It ships **observing, not refusing** (`TRUSTED_HOSTS_ENFORCE` unset = false):
the host list cannot be fully known from the code, because health checks and
uptime monitors reach an origin by IP and send a Host nobody wrote down. It logs
at `warning` because production's `LOG_LEVEL` is `warning` — anything quieter is
thrown away before it reaches the file. Read that log, confirm it names nothing
legitimate, then — as a separate owner decision — set it true. The payload fix
does not depend on that switch. The live host list, and why the log is never
empty (third-party domains still point at our IP), is `docs/tenant-host-map.md`;
the procedure is `deploy/TRUSTED-HOSTS-ENFORCEMENT.md`.

It is **not** `Illuminate\Http\Middleware\TrustHosts`, for three reasons, the
first of which is the one that matters here: the framework's middleware disables
itself under tests (`shouldSpecifyTrustedHosts()` excludes `runningUnitTests()`),
so every assertion about it would pass against an application that had never
registered it. See the class docblock for the other two.

## Writing a test for this

Two traps, both of which produce a test that cannot fail:

1. **`withHeader('Host', ...)` on a relative URI does nothing.**
   `MakesHttpRequests::prepareUrlForRequest()` rewrites the path through `url()`,
   and Symfony's `Request::create()` then overwrites `HTTP_HOST` from that URI.
   Put the host in the URI: `$this->getJson('https://evil.example/api/...')`.

2. **Asserting on the builder, or on the response, is not asserting on the
   cache.** The blast radius is the stored entry. Forget the key, make the
   request, then read `Cache::get(MobileCache::masjidKey(...))` and assert on
   *that* — and have a second, ordinary caller read it back.

`tests/Feature/HostHeaderUrlIntegrityTest.php`,
`tests/Feature/TrustedHostsMiddlewareTest.php` and
`tests/Feature/TrustedHostsLogOnlyTest.php` are the worked examples.
