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
    | `max_requests` and `max_seconds` are hard stops. Hitting either can never
    | end the run as clean — a canary that quietly reports green because it ran
    | out of budget is worse than no canary. Which non-clean verdict it gets is
    | decided by what the truncation actually COST: a cut that still left a
    | majority of the graded surface reached is `partial` (exit 3, and the
    | truncation named); a cut deep enough to lose that majority is `incomplete`
    | (exit 2). Measured on this branch against a `--all` plan of 31 endpoints /
    | 76 probes: `--max-requests=3` reaches 1 of 31 endpoints and is incomplete;
    | `--max-requests=60` reaches 23 of 31 with all of /api/v1 seen and is
    | partial. Both report the truncation; they differ in how loudly.
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
    | Measured arithmetic at these defaults, re-derived 2026-08-12 against the
    | current route table:
    |
    |   scheduled run (rotating slice)   16 endpoints, 47-52 probes — the 8
    |                                    /api/v1 endpoints are always 36 of
    |                                    them; this hour's 8 mobile endpoints
    |                                    are 8-16 more, since a global endpoint
    |                                    is probed once and a tenant one twice
    |   deploy gate (`--all`)            31 endpoints, 76 probes
    |
    | The slice makes the scheduled number hour-dependent; `--all` is fixed.
    | Pacing at 20/minute puts the scheduled run at roughly 2m30s and `--all` at
    | under four minutes, inside the 15-minute withoutOverlapping() window in the
    | schedule.
    |
    | max_requests is set above the LARGER of those ON PURPOSE — the ceiling has
    | to clear the `--all` plan, because that is what the deploy gate runs, and a
    | ceiling the normal plan grazes turns every new endpoint into an hourly
    | "incomplete" alert. An alarm that cries wolf gets silenced, which is the
    | same outcome as not having built this. At 90 the headroom over `--all` is
    | 14 probes ≈ 7 more tenant-in-path endpoints; raise it in the same commit
    | that adds the eighth, or the deploy gate starts truncating instead of
    | certifying.
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
    | WHICH tenants — and, since 2026-08-13, WHOSE ROWS THOSE ARE
    |--------------------------------------------------------------------------
    |
    | This list does TWO jobs. Both are about the same fact — these are the
    | tables that back the graded surface — but the second one is newer and
    | stricter, so read it before adding a name here.
    |
    | JOB 1: choosing the compared pair. `Masjid` relations the canary counts to
    | pick the pair it compares. It takes the organisations with rows in the MOST
    | of these (breadth first, then total rows, then lowest id — deterministic,
    | and identical to the old "two lowest ids" whenever nothing has content).
    |
    | JOB 2: attributing a row to its owner. TenancyCanary::resolveOwnership()
    | reads these tables to answer "who owns the rows this endpoint just served?"
    | — the only positive assertion the canary makes, and the only detector that
    | sees a total SWAP (masjid 1 served masjid 2's announcements and vice
    | versa). Measured on this branch before it existed: a complete cross-tenant
    | read of every announcement on the platform reported exit 0, status clean,
    | zero findings, because `tenants-get-different-answers` proves only that the
    | two answers DIFFER and a swap makes them differ perfectly.
    |
    | A relation can do job 1 and not job 2, and `gallery` is exactly that: it is
    | `hasMany(Media::class, 'model_id')`, so its foreign key is a polymorphic
    | owner id — `model_id = 5` means "row 5 of some model", not "masjid 5".
    | Counting it to rank organisations is harmless; attributing through it would
    | read an announcement's image as belonging to masjid 5 and accuse a correct
    | endpoint. Only relations whose foreign key is one of `tenant_keys` below
    | attribute; the rest are named, with the reason, in
    | `coverage.row_ownership.tables_skipped` on every run.
    |
    | Attribution is also ANCHORED: a relation only attributes an endpoint that
    | NAMES it — as a segment of the route URI (`api/v1/announcements`,
    | `api/v1/__anything/announcements`) or as the key path of the list the ids
    | came from (`/api/v1/home` returns buckets literally called `services` and
    | `announcements`). Without that anchor the first cut of this turned a
    | healthy `--all` run RED: `GET /api/mobile/masjids/2` returns the
    | organisation's own row, `(root)` carried the id 2, and `announcements.id =
    | 2` belongs to a different organisation. Adding a relation here therefore
    | widens attribution only over the endpoints that already name it.
    |
    | This is not a tuning knob either. The cross-tenant comparison in
    | TenancyCanary::checkTenantsGetDistinctAnswers() proves a leak by finding the
    | same primary keys in two organisations' answers, and TWO EMPTY ANSWERS
    | PROVE NOTHING: they are byte-identical whether the platform is scoped
    | correctly or pinned to a single organisation. The pair used to be the two
    | lowest ids, which is the two OLDEST organisations — not the two that
    | publish. Measured 2026-08-12 with the real SearchableTrait pinned to one
    | organisation: a pair holding rows exits 1 with five findings, a dormant
    | pair exited 0 and called the platform clean.
    |
    | The list is the graded surface's own tables: announcements, services and
    | pages back /api/v1/announcements, /services, /pages, /pages/menu and /home;
    | gallery backs /api/v1/gallery. A name this model does not have is skipped
    | rather than fatal, and an empty list falls back to the lowest ids.
    |
    | `--tenants=1,13` overrides it entirely, for when someone is chasing a
    | specific pair.
    */

    'compare_by' => ['announcements', 'services', 'pages', 'gallery'],

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
    |--------------------------------------------------------------------------
    | The graded surface — which endpoints the run-level verdict is computed on
    |--------------------------------------------------------------------------
    |
    | A run is `incomplete` (exit 2, the loud one) rather than `partial` (exit 3,
    | the quiet one) when it failed to reach a STRICT majority of THESE endpoints
    | — `reached * 2 > planned`, so exactly half is a loss, because a run that saw
    | as much as it missed is not a partial all-clear. Of the eight discovered
    | /api/v1 endpoints, 5 reached is partial and 4 is incomplete.
    | Everything outside this list can still take a run out of `clean`
    | and is always named in `blind_spots`; it just cannot on its own turn a run
    | that reached the platform into a run that did not.
    |
    | It is `api/v1` and not the whole plan for two reasons, and both are about
    | this codebase rather than about taste:
    |
    |  1. `/api/mobile` is probed as a ROTATING SLICE chosen from the clock. A
    |     floor computed over the whole plan therefore answers differently at
    |     03:00 than at 04:00 for a platform that did not change. A threshold
    |     that depends on the hour is not a threshold.
    |  2. Blindness is normal on one surface and anomalous on the other.
    |     Measured on a healthy `--all` run of this branch with two organisations
    |     that publish: 14 `/api/mobile` endpoints and 1 `/api/v1` endpoint
    |     (`gallery`) are legitimately BLIND to the cross-tenant comparison,
    |     because a pair with no events, funds, notifications or photos gives two
    |     empty answers that no comparison can tell apart. Grading blindness
    |     there would put a permanent amber on a correct platform. That is what
    |     this list keeps off the verdict.
    |
    |     WHAT THIS PARAGRAPH USED TO SAY, AND WHY IT WAS WRONG. It claimed much
    |     of `/api/mobile` reads an OPTIONAL per-org singleton — donation-link,
    |     about, app-config, splash, signage, tv-config — "where a 404 for a
    |     valid tenant is the correct answer". Re-derived against the controllers
    |     on 2026-08-13, none of the six behaves that way:
    |
    |       MasjidsController::about, ::donationLink   200 with `data: null`
    |       SplashAnnouncementsController::current     204
    |       AppConfigController::index                 200 (`{}` when no rows)
    |       TvConfigController, SignageController      findOrFail the MASJID,
    |                                                  not the singleton
    |
    |     All six answer 2xx for an organisation that exists, and a healthy
    |     `--all` run reaches 31 of 31 endpoints with `blind_spots` empty. So
    |     absence is NOT normal on `/api/mobile`; the expected number of
    |     unreachable endpoints there is zero, exactly as on `/api/v1`.
    |
    |     The practical effect of the error was benign — fewer spurious ambers
    |     than the doc predicted — but the sentence is load-bearing in the wrong
    |     direction: it is the one a future reader would cite to put a majority
    |     threshold on UNREACHABILITY here, which would buy silence for the
    |     failure that does happen (one endpoint stops answering, and therefore
    |     stops being watched for tenancy) while only speaking for the one every
    |     other monitor also sees. See the argument at the `blindSpots` check in
    |     TenancyCanary::assessCoverage().
    |
    | `/api/v1` is also the surface probed in full every run, the one carrying no
    | throttle, and the one where both production holes actually were.
    |
    | This list also decides where BLINDNESS is graded — the second floor, where
    | a run is degraded because the cross-tenant comparison could not tell two
    | organisations' answers apart on a majority of the endpoints it reached
    | (TenancyCanary::applyComparisonFloor) — and where ROW OWNERSHIP is graded,
    | the third floor, which asks whether the rows an organisation was handed are
    | that organisation's rows (TenancyCanary::applyOwnershipFloor). The reasons
    | are the same two, and the second is sharper for both: the measurement in
    | reason 2 above is 14 ungraded endpoints blind on a HEALTHY run.
    |
    | The three floors are not the same shape and deliberately so. Reachability
    | and comparison take a strict majority; ownership takes a ZERO floor,
    | because the endpoint list is discovered and grows, and every endpoint on a
    | table outside `compare_by` is permanently unattributable — a ratio there
    | would go amber as the route table grew, for a reason no operator can act on
    | tonight. See applyOwnershipFloor() for the full argument.
    |
    | Adding a prefix here makes the run-level verdict STRICTER for it. Do not
    | add `api/mobile` without first removing the optional-singleton endpoints
    | from the plan, or every org missing a donation link will page hourly —
    | which is the bug this setting exists to fix, reintroduced from the other
    | end.
    |
    */

    'core_prefixes' => ['api/v1'],

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
    | ...and the same declaration for ONE LIST inside an otherwise tenant answer
    |--------------------------------------------------------------------------
    |
    | `uri => [bucket key path, …]`. Empty today, and it is here because the
    | list above is the wrong granularity for the shape it has to excuse.
    |
    | An endpoint that is perfectly scoped can still return a global lookup list
    | beside its own rows — `categories`, `tags`, `types`, `statuses` — and those
    | rows belong to nobody. Two organisations are correctly handed the SAME
    | lookup ids, which is exactly what a pin looks like to the shared-id
    | comparison; and if those ids happen to exist in a table the route names,
    | the row-ownership assertion reads them as two organisations' rows in one
    | answer. Measured 2026-08-13 against a correctly scoped endpoint returning
    | `items` (its own services) plus `categories` (ids 1, 2, 3, owned by
    | nobody, colliding with `services.id`):
    |
    |     exit=1 status=leak findings=3
    |       same_rows_served_to_two_tenants   :: both answers contain categories ids 1, 2, 3
    |       foreign_rows_for_requested_tenant :: masjid 1 … categories ids 3 (masjid 2)  [CRITICAL]
    |       foreign_rows_for_requested_tenant :: masjid 2 … categories ids 1, 2          [CRITICAL]
    |
    | Three findings and a page, against correct code. The only hatch available
    | was `global_endpoints` above — which is PER ENDPOINT, so silencing
    | `categories` would also have stopped watching `items`, the list carrying
    | the organisation's real rows. Turning a watch off to quiet a false alarm is
    | how a canary becomes decoration.
    |
    | So the declaration is per bucket. A declared path is excluded from BOTH id
    | detectors and from the ownership lookup, its siblings stay fully watched,
    | and every run that honours a declaration SAYS SO in the check detail — a
    | narrowed watch that goes quiet about being narrowed is the same defect one
    | level down.
    |
    | The canary does not need this to avoid the false page: TenancyCanary::
    | analyseBuckets() withdraws an inherited anchor when the answer contradicts
    | it, so an undeclared lookup list lands as a NAMED, contested gap at exit 3
    | rather than a finding at exit 1. This is how the gap is CLOSED rather than
    | merely made quiet: declare it and the sibling list goes back to being
    | compared and attributed.
    |
    | Same rule as `global_endpoints`: read the controller before adding an
    | entry. A bucket named here stops being watched, and a per-tenant list
    | misfiled here is a cross-tenant read nobody will ever see.
    */

    'global_buckets' => [
        // 'api/v1/services' => ['categories'],
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
