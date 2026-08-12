<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Where the canary points
    |--------------------------------------------------------------------------
    |
    | Null means "this application's own APP_URL". Set CANARY_BASE_URL when the
    | prober should cross the edge it normally sits behind — e.g. running the
    | canary from a bastion against https://masjid.hopetechapps.com so the
    | probes traverse the real proxy, TLS terminator and cache, which is where
    | a header can be rewritten or stripped without any code changing.
    |
    | In the test suite the base URL is irrelevant: the `kernel` transport
    | dispatches through the test application in-process and never opens a
    | socket. See App\Support\Canary\KernelTransport.
    |
    */

    'base_url' => env('CANARY_BASE_URL'),

    'timeout' => (int) env('CANARY_TIMEOUT', 10),

    'connect_timeout' => (int) env('CANARY_CONNECT_TIMEOUT', 5),

    /*
    |--------------------------------------------------------------------------
    | The budget — this is the production-safety control, not a tuning knob
    |--------------------------------------------------------------------------
    |
    | `max_per_minute` is the important one. The public mobile surface is
    | limited by `throttle:mobile` at 60 requests per minute PER IP
    | (AppServiceProvider). A canary that fires its whole plan as fast as it can
    | would burn that bucket from whatever IP it runs on, and if that IP is
    | shared with anything else — a health checker, a CDN origin-pull, the app
    | server itself behind a proxy that does not forward the client address —
    | it takes real users down while claiming to protect them.
    |
    | So the run is PACED, not bursted: the command derives a per-request delay
    | of 60_000 / max_per_minute milliseconds and sleeps it between probes. At
    | the default of 20 the canary can never hold more than a third of the
    | mobile bucket, and a full run takes minutes rather than seconds — which is
    | fine, because it is scheduled hourly and guarded by withoutOverlapping().
    |
    | `max_requests` and `max_seconds` are hard stops. Hitting either ends the
    | run as INCOMPLETE (exit 2), never as clean — a canary that quietly reports
    | green because it ran out of budget is worse than no canary.
    |
    | `mobile_slice` rotates the /api/mobile surface: each run probes that many
    | mobile endpoints, chosen by a deterministic hourly offset, so the whole
    | surface is covered every few hours without any single run being large.
    | /api/v1 carries no throttle at all and is probed in full every run — it is
    | also where both production holes actually were.
    |
    | The pacing is applied to the UNTHROTTLED /api/v1 probes too, deliberately.
    | It buys nothing against a limiter there; it buys the plainest possible
    | statement about the load this thing puts on a live system — the canary
    | never issues more than max_per_minute requests in any minute, of any kind,
    | to anything. A two-tier rule would be faster and harder to reason about at
    | 3am.
    |
    | Measured arithmetic at these defaults (2026-08-12): 49 probes per run — 36
    | across the seven /api/v1 collection endpoints, 13 across one mobile slice —
    | taking 2m26s. max_requests is set well above that ON PURPOSE. A ceiling
    | that the normal plan grazes turns every new endpoint into an hourly
    | "incomplete" alert, and an alarm that cries wolf gets silenced, which is
    | the same outcome as not having built this.
    |
    */

    'budget' => [
        'max_requests' => (int) env('CANARY_MAX_REQUESTS', 90),
        'max_per_minute' => (int) env('CANARY_MAX_PER_MINUTE', 20),
        'max_seconds' => (int) env('CANARY_MAX_SECONDS', 600),
        'mobile_slice' => (int) env('CANARY_MOBILE_SLICE', 8),
    ],

    /*
    | Page size sent to every /api/v1 collection probe. The exploit measured
    | against production on 2026-08-11 used `per_page=1000`; a large page is
    | what makes an unscoped answer visibly bigger than any single tenant's.
    | 100 is large enough for that arithmetic and small enough to stay a cheap
    | read against a live database.
    */

    'per_page' => (int) env('CANARY_PER_PAGE', 100),

    /*
    | How many real tenants to compare. Two is the minimum that can prove a
    | leak: A's rows, B's rows, and an unscoped answer larger than either.
    */

    'tenants' => (int) env('CANARY_TENANTS', 2),

    /*
    |--------------------------------------------------------------------------
    | What gets probed
    |--------------------------------------------------------------------------
    |
    | The probe plan is DISCOVERED from the router at runtime, not listed here.
    | That is deliberate: `.claude/rules/tenant-scoping.md` has required a
    | cross-tenant test per trait user since before either hole shipped, and the
    | requirement was silently unmet for six models because a human has to
    | remember to add the test. A route table cannot forget a route. A new
    | public GET collection endpoint is probed by the next scheduled run without
    | anyone deciding to include it.
    |
    | The lists below only narrow that: they say which discovered endpoints are
    | NOT tenant-scoped (so a tenant-less 200 from them is correct, not a leak)
    | and which to leave alone entirely.
    |
    */

    'prefixes' => ['api/v1', 'api/mobile'],

    /*
    | Named limiters the canary is allowed to spend. Everything else — the
    | per-hour intake/quote/zakat limiters, `throttle:device` at 10/hour — is
    | skipped rather than consumed, because a canary that eats a scarce bucket
    | on a schedule is an availability bug it introduced itself. `api` and
    | `mobile` are per-minute and are handled by the pacing above.
    */

    'throttle_allowlist' => ['api', 'mobile'],

    /*
    | Endpoints that are public but NOT about one organisation, so the fail-open
    | comparison does not apply to them. They are still probed once for server
    | faults; they are simply never expected to refuse a tenant-less request.
    |
    |  - contact-us/reasons        `contact_us_reasons` is a GLOBAL table (see
    |                              the limiter note in AppServiceProvider).
    |  - masjids (index)           the tenant DIRECTORY. Cross-tenant by design;
    |                              it is how a device finds a masjid at all.
    |  - azkar/hadiths/tasabih     global library content, no tenant column.
    |
    | Note what is NOT here. `/api/mobile/masjids/{id}/contact-reasons` looks
    | like a sibling of the global `contact-us/reasons` and is not: it reads
    | `ContactReason` scoped by `masjid_id`, so it stays fully watched. It sat on
    | this list for one draft of this file, which is the whole hazard in
    | miniature — an endpoint added here because its NAME resembled a global
    | one, silently un-watched. Read the controller before adding anything.
    */

    'global_endpoints' => [
        'api/v1/contact-us/reasons',
        'api/mobile/masjids',
        'api/mobile/azkar',
        'api/mobile/azkar/categorized',
        'api/mobile/hadiths',
        'api/mobile/hadiths/today',
        'api/mobile/tasabih',
    ],

    /*
    |--------------------------------------------------------------------------
    | Never probe these — GET IS NOT THE SAME AS READ-ONLY
    |--------------------------------------------------------------------------
    |
    | The canary refuses to plan anything but a GET, and that is necessary but
    | NOT sufficient in this codebase:
    |
    |   GET /api/mobile/masjids/{id}/prayers  WRITES. PrayersController::index()
    |   calls store(), which INSERTs missing rows into `prayers` for the
    |   requested date window before selecting them back. A read-only prober
    |   must not touch it, and the exclusion has to be by name because nothing
    |   in the route table says so.
    |
    | Anything added here stops being watched, so each entry carries the reason
    | it earned its place. If an endpoint is excluded because it writes, the
    | right long-term fix is for it to stop writing on a GET — not to grow this
    | list.
    */

    'skip' => [
        'api/mobile/masjids/{masjid_id}/prayers',
    ],

    /*
    | Keys whose value names an organisation. Any of these appearing in a
    | response body with a value other than the tenant the request asked for is
    | a proven cross-tenant read.
    */

    'tenant_keys' => ['masjid_id'],

    /*
    | Log channel for the one line a run leaves behind. Null = the default
    | stack. `schedule:run` discards stdout, so the log line is the only
    | evidence a scheduled run ever happened — and a canary you cannot prove
    | ran is a canary that can stop running unnoticed.
    */

    'log_channel' => env('CANARY_LOG_CHANNEL'),

];
