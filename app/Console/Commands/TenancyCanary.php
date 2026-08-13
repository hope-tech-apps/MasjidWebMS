<?php

namespace App\Console\Commands;

use App\Models\Masjid;
use App\Support\Canary\HttpTransport;
use App\Support\Canary\KernelTransport;
use App\Support\Canary\Probe;
use App\Support\Canary\ProbeCatalog;
use App\Support\Canary\ProbeResult;
use App\Support\Canary\ProbeTransport;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * A cross-tenant CANARY: it probes the RUNNING public API for the shape of the
 * two holes that were live in production on 2026-08-11, and it does so on a
 * schedule rather than when somebody remembers to look.
 *
 * ==========================================================================
 * IT IS READ-ONLY, AND THAT IS A HARD CONSTRAINT, NOT A PREFERENCE
 * ==========================================================================
 *
 * This command is scheduled against a production database serving real
 * congregations. It therefore:
 *
 *   - issues **GET only**. `ProbeCatalog` refuses to plan a route that answers
 *     any write verb, and `dispatch()` asserts it again immediately before the
 *     request goes out, because the two checks fail for different reasons: the
 *     first is a policy about which endpoints are interesting, the second is
 *     the thing that must never be true.
 *   - excludes, BY NAME, the GET endpoints that write anyway. GET-only is
 *     necessary and not sufficient here: `GET /api/mobile/masjids/{id}/prayers`
 *     INSERTs missing `prayers` rows before selecting them back. Nothing in the
 *     route table says so, so it is named in `config/canary.php` with its
 *     reason and pinned by a test. Assume there are others and check before
 *     adding a prefix to `canary.prefixes`.
 *   - makes three kinds of database query of its own, all SELECT, all once per
 *     RUN rather than once per probe: which organisations exist, which of them
 *     hold the most public content (`resolveTenants`), and who owns the record
 *     ids the API just handed back (`resolveOwnership`, one indexed `WHERE id
 *     IN (…)` per attributable table, bounded by OWNERSHIP_MAX_IDS). The third
 *     is what lets the canary say whose rows these are on a surface that
 *     strips `masjid_id` from every response. Nothing else here touches the
 *     database directly, and nothing here writes.
 *   - never sends a credential, so it can only ever see what an anonymous
 *     caller on the internet already sees. A canary that authenticated could
 *     prove more and would itself become a secret worth stealing.
 *
 * Two side effects are real and accepted: probed endpoints that use
 * `Cache::remember` will warm their cache entry (a read from the client's point
 * of view, harmless and mildly useful), and a probe that finds a 500 causes the
 * application to log that 500. The second is deliberate — see "log noise".
 *
 * ==========================================================================
 * WHAT IT PROBES, AND WHY EACH PROBE EXISTS
 * ==========================================================================
 *
 * **1. The fail-open shape.** `SearchableTrait::filterByMasjid()` used to read
 * `if ($resourceId) $query->where(...)`, so a request with no `masjid-id`
 * header — or a falsy one — added no WHERE clause at all, and `Announcement`,
 * `Service`, `Page` and `Section` do not use `BelongsToMasjid`, so nothing else
 * scoped them. Measured against production before the fix:
 *
 *     masjid-id: 1   -> 11 announcements
 *     masjid-id: 13  ->  3 announcements
 *     (no header)    -> 14 announcements   <- both tenants, to an anonymous caller
 *     masjid-id: 0   -> 14 announcements   <- falsy bypass
 *
 * So every public `/api/v1` collection endpoint is requested four ways — with
 * each real tenant, with no header, with `masjid-id: 0`, and with an empty
 * `masjid-id` — and the answers are compared as that arithmetic.
 *
 * An unscoped answer LARGER than any single tenant's is proof of a
 * cross-tenant read and reports as critical. An unscoped answer that merely
 * SUCCEEDS is reported too, one severity down, and that distinction is what
 * makes this command useful on the platform as it actually exists today: every
 * S1-S4 table has zero production rows, so a leak in a new vertical's endpoint
 * would return `0 == 0` and prove nothing by arithmetic. The fail-open *shape*
 * — a tenant-less request answered instead of refused — is present the moment
 * the bug is deployed, months before there is data to leak.
 *
 * **2. Unresolved tenants escaping as server errors.** The gallery hid behind
 * exactly this: `Masjid::findOrFail(request()->header('masjid-id'))` inside a
 * blanket `catch (\Exception)` that answered 500, which put 49 real
 * ModelNotFoundExceptions in the production log where 49 client mistakes
 * belonged. A 500 is where a tenancy bug goes to look like an infrastructure
 * problem, so any 5xx is a finding — and a 5xx on a *tenant-less* probe is
 * reported as its own kind, because that is the gallery's signature.
 *
 * **3. Foreign tenant ids in the body.** Any `/api/v1` or `/api/mobile`
 * response carrying a `masjid_id` that is not the organisation the request
 * asked for. The `/api/mobile` controllers return raw Eloquent models, so this
 * reads the leak directly rather than inferring it from a count, and a body
 * naming two organisations at once is proof no arithmetic is needed for.
 *
 * **4. The same rows served to two different organisations.** Probes 1-3
 * compose to NOTHING across the whole of `/api/v1`: every V1 Resource strips
 * `masjid_id`, so probe 3 is structurally blind there, and probe 1 compares a
 * tenant-less answer against a tenant's, so a scope that validates the header
 * and then ignores it — pinning every query to one organisation — keeps the
 * 400, keeps the counts equal, and passes. Measured on 2026-08-12: that
 * mutation produced exit 0, status clean, 23 passing checks and zero findings
 * while every visitor to masjid 5's and masjid 13's sites read masjid 1's
 * content. So the canary now asks two organisations the same question and
 * compares the PRIMARY KEYS in the two answers; ids are unique across tenants
 * in a shared database, so an overlap is proof. Its blind spots are real and
 * are enumerated on `checkTenantsGetDistinctAnswers()` — chiefly that two empty
 * answers prove nothing, which is the normal state of a vertical with no data
 * yet, and it reports `skipped` there rather than `pass`.
 *
 * **5. Whose rows they actually are.** Probes 1-4 are all NEGATIVE: each passes
 * by failing to find something. Probe 4 in particular proves only that two
 * organisations got DIFFERENT answers, and a SWAP — masjid 1 served masjid 2's
 * announcements and masjid 2 served masjid 1's — makes them differ perfectly.
 * Measured on 2026-08-13 against exactly that fixture, a complete cross-tenant
 * read of every announcement on the platform:
 *
 *     exit=0  status=clean  findings=[]
 *     tenants-get-different-answers  pass  "no shared record ids (1/2 records)"
 *
 * and it counted as `compared`, inflating the arithmetic that exists to say how
 * much of the surface is watched. So the canary now asks the DATABASE who owns
 * the primary keys it was handed — `announcements.masjid_id` says whose row it
 * is whatever the Resource chose to emit — and asserts POSITIVELY that masjid
 * A's answer carries masjid A's rows. See
 * checkAnswerCarriesRequestedTenantsRows(), including the anchor that keeps it
 * from accusing a correct endpoint, and the residual it cannot close (an
 * endpoint pinned to a DORMANT organisation answers everyone with an empty
 * list, and an empty list has no owner).
 *
 * The endpoint list is DISCOVERED from the router every run — see ProbeCatalog
 * for why a hand-written list is the failure mode this command exists to fix.
 *
 * ==========================================================================
 * WHY IT IS SAFE TO RUN AGAINST PRODUCTION CONTINUOUSLY
 * ==========================================================================
 *
 * **Throttles.** `/api/mobile/*` is limited by `throttle:mobile` at 60 requests
 * per minute PER IP. A canary that fired its whole plan as fast as it could
 * would hold that entire bucket for the IP it runs from, and if that IP is
 * shared with anything real it has caused the outage it was watching for. So
 * the run is PACED to `budget.max_per_minute` (default 20 — at most a third of
 * the bucket, ever), the `/api/mobile` surface is covered by a rotating slice
 * rather than in full every run, and endpoints behind scarcer named limiters
 * (`throttle:device` at 10/hour, the per-hour intake and quote limiters) are
 * not probed at all. It is also `withoutOverlapping()` in the schedule, so a
 * slow run cannot stack a second copy on top of itself and double the load.
 *
 * Measured, over a real socket: a default run is 49 probes in 2m26s. With
 * pacing disabled entirely (`--delay=0`) two back-to-back runs did exhaust the
 * mobile bucket — and the second run stopped after ONE probe, on
 * `X-RateLimit-Remaining: 3`, and exited 2. That is the behaviour to keep: the
 * canary gives the bucket back to real clients and reports that it could not
 * check, rather than grinding through and reporting nothing found.
 *
 * **Bounded.** Hard caps on requests (`budget.max_requests`) and wall clock
 * (`budget.max_seconds`). Hitting either can never end the run as clean — a
 * canary that reports green because it ran out of budget is worse than no
 * canary, because it is trusted. Whether it ends `partial` (exit 3) or
 * `incomplete` (exit 2) depends on what the truncation cost: see "THE COVERAGE
 * FLOOR" below. The truncation itself is reported either way.
 *
 * **Rate-aware.** A 429 anywhere aborts the run immediately and unconditionally
 * as `incomplete`, exit 2, however much it had already covered: it is not
 * evidence of leakage, pushing through it is precisely the behaviour to avoid,
 * and a canary being refused service quietly covers less of the platform every
 * hour. Same for an `X-RateLimit-Remaining` that drops into single digits.
 *
 * **Log noise.** Probes carry `X-Canary: tenancy` and their own User-Agent so
 * an access log can filter them out. The run itself leaves exactly one log line
 * — info when clean, error with the findings when not — because `schedule:run`
 * discards stdout and a canary nobody can prove ran is a canary that can stop
 * running unnoticed. The one noise source it will not suppress is a real 500:
 * an endpoint that faults on a tenant-less request logs 24 lines a day until
 * somebody fixes it, which is the correct amount of pressure.
 *
 * ==========================================================================
 * THE OUTCOME MODEL — WHAT A GREEN RUN IS ALLOWED TO MEAN
 * ==========================================================================
 *
 * A canary that can certify a broken platform is worse than no canary, because
 * it is trusted. Three ways this one could do exactly that were measured on
 * 2026-08-12 and are closed here; each has a test that reproduces the original
 * verdict.
 *
 * **Every check has three outcomes, not two.** `pass` requires that the check
 * did not find its bug AND that every probe it wanted was sent AND that it had
 * something real to look at. `skipped` is everything else, and its detail says
 * which of the three was missing. The old `pass` keyed off "every planned probe
 * produced a result object" — and a FAILED probe is a result object, so a run
 * whose 36 probes all died on ConnectionException printed 23 `pass` rows
 * underneath its (correct) `incomplete`.
 *
 * **A check that observed nothing says so.** `body-names-only-the-requested-
 * tenant` reported `pass — no masjid_id seen` for all 8 `/api/v1` endpoints on
 * every run ever made, because the V1 Resources strip `masjid_id`. It is now
 * permanently and visibly `skipped` there, which is the fact that made probe 4
 * necessary.
 *
 * **A run must reach the application before it may call it clean.** Every
 * endpoint has to answer 2xx to at least one probe naming a real organisation;
 * an endpoint that does not has ALL its checks forced to `skipped`, and a run
 * where NO endpoint managed it is `incomplete`, never `clean`. Before this, an
 * origin that answered 301 to everything — or 404 to everything — reported
 * 36/36 probes, 23 passes, zero findings and exit 0. That is one config line
 * away from real: the site's nginx block answers plain HTTP with `return 301
 * https://$host$request_uri`, so a `CANARY_BASE_URL` written `http://` makes
 * every run green forever.
 *
 * ==========================================================================
 * THE RUN-LEVEL VERDICT — FOUR STATES, BECAUSE THREE CONFLATED TWO THINGS
 * ==========================================================================
 *
 * Everything above fixed the conflation at the CHECK level. It survived at the
 * RUN level, where it matters more. Measured on 2026-08-12, eight healthy
 * `/api/v1` endpoints plus one that answers 404 to a valid tenant — the ordinary
 * shape of an OPTIONAL per-organisation record that this org has not configured:
 *
 *     exit=2  status=incomplete  reached=8/9  pass=29  skipped=15  FAIL=0
 *
 * and the 301-origin, 404-origin and dead-transport runs, which reached 0/8 and
 * passed 0 checks:
 *
 *     exit=2  status=incomplete  reached=0/8  pass=0   skipped=39  FAIL=0
 *
 * Identical status, identical exit code. "One optional endpoint has no row for
 * masjid 2" and "CANARY_BASE_URL points at nothing" were the same alarm. The
 * first happens on an ordinary Tuesday; an alarm that fires on an ordinary
 * Tuesday gets silenced, and then the second one is unheard — which is exactly
 * how the platform ends up back where it started.
 *
 * So there are four:
 *
 *   leak        a finding. Outranks everything, including a run that was also
 *               truncated: a leak the canary REACHED must page someone.
 *   incomplete  the run is not evidence about this platform. Loud.
 *   partial     the run IS evidence, about most of the platform, and it names
 *               the part it could not see. Quieter — a distinct exit code and a
 *               `warning` log line rather than an `error` one.
 *   clean       every planned endpoint reached, every planned probe sent, at
 *               least one check satisfied.
 *
 * ==========================================================================
 * THE COVERAGE FLOOR, AND WHY IT IS THIS AND NOT 80%
 * ==========================================================================
 *
 * A run is `incomplete` rather than `partial` when ANY of:
 *
 *   (a) nothing was reached — no endpoint answered 2xx to a valid tenant; or
 *   (b) nothing was verified — 0 checks reported `pass`; or
 *   (c) it saw no strict MAJORITY of the graded surface — the floor is
 *       `reached * 2 > planned`, so the loss is `reached * 2 <= planned`; or
 *   (d) it was refused service (429, or X-RateLimit-Remaining in single digits)
 *       or could not start (no tenants, empty plan).
 *
 * **The graded surface is `/api/v1`, not the whole plan.** This is the load-
 * bearing choice, and it is not a preference:
 *
 *  - `/api/mobile` is probed as a ROTATING SLICE chosen by the clock (see
 *    rotate()). A floor computed over the whole plan therefore returns a
 *    different verdict at 03:00 than at 04:00 for an unchanged platform. A
 *    threshold whose answer depends on which hour it is is not a threshold.
 *  - Reachability means different things on the two surfaces. Every `/api/v1`
 *    endpoint is a COLLECTION for the organisation: an org with no announcements
 *    gets `200 []`, never a 404, so on a healthy release the expected value of
 *    `reached` is `planned` exactly, and any shortfall is anomalous. Much of
 *    `/api/mobile` reads an OPTIONAL per-org singleton — donation-link, about,
 *    app-config, splash, signage, tv-config — where a 404 for a valid tenant is
 *    the correct answer for an org that has not configured it. Grading a surface
 *    on which absence is normal produces exactly the false alarm above.
 *  - `/api/v1` is also the surface probed in full every run, carrying no
 *    throttle, and it is where both production holes actually were.
 *
 * When a plan contains no `/api/v1` endpoint at all (an operator narrowed it
 * with `--only api/mobile`), the floor falls back to the whole plan — then the
 * plan IS what was asked for, and a run that saw less than half of it is not an
 * observation. `canary.core_prefixes` names the graded surface.
 *
 * **Why a strict majority.** It is the weakest claim that keeps the headline
 * honest. The report says "no cross-tenant leakage in what this run could see";
 * below a majority the part it could not see is larger than the part it could,
 * and that sentence starts reading as reassurance about a platform it mostly did
 * not look at. At exactly half there is no majority either way, and a tie must
 * not be resolved in favour of the reassuring reading — hence a STRICT majority,
 * `reached * 2 > planned`, and a tie is a loss. With the eight discovered
 * `/api/v1` endpoints: 5 reached is `partial`, 4 is `incomplete`.
 *
 * **Why a ratio and not "at most N blind endpoints".** The endpoint list is
 * DISCOVERED, and that is the whole design — it grows whenever someone adds a
 * public GET. Any absolute count is wrong at one of the two ends: "at most 2"
 * becomes proportionally stricter until every run is fatal, "at most 6" is
 * meaningless against a surface of eight. A ratio is the only form that survives
 * the route table growing.
 *
 * **The obvious objection, and the answer.** A ratio lets the absolute number of
 * unwatched endpoints grow with the surface — at 40 core endpoints it tolerates
 * 19. That would be fatal to the design if the floor bought SILENCE. It does
 * not. Every unreached and every unprobed endpoint is named in `blind_spots`, in
 * `coverage`, and in the human report, at every level of coverage, and any of
 * them is enough to take the run out of `clean` and off exit 0. The floor
 * decides the TONE of the alarm, never whether the gap is visible. That is the
 * property that makes a percentage acceptable here and would not make one
 * acceptable anywhere it gated visibility.
 *
 * **What this deliberately does NOT do.** It does not make an unreachable
 * `/api/mobile` surface fatal, even in full. This is a tenancy canary, not an
 * uptime monitor; 23 dead mobile endpoints with `/api/v1` healthy is `partial`,
 * exit 3, with all 23 named. Availability belongs to whatever watches
 * availability, and borrowing this alarm for it is how it gets silenced.
 *
 * ==========================================================================
 * THE SECOND FLOOR — REACHED IS NOT WATCHED
 * ==========================================================================
 *
 * Everything above grades whether the canary REACHED the application. Measured
 * on 2026-08-12, that is not enough to earn an exit 0. With the real
 * `SearchableTrait::scopeFilterByMasjid` mutated to validate the `masjid-id`
 * header and then pin every public query to one organisation:
 *
 *     both compared organisations hold rows   exit 1  leak   5 findings
 *     both compared organisations dormant     exit 0  clean  0 findings, blind_spots: []
 *
 * The second run reached 8 of 8 `/api/v1` endpoints and printed
 * `--  BLIND — 0/0 records, no ids…` for six of the seven comparable ones,
 * because two empty answers are byte-identical whether the platform is scoped
 * correctly or pinned to one organisation. `tenants-get-different-answers` is
 * the ONLY detector that sees a pin on `/api/v1` — the fail-open check stays
 * green through one by construction, and the body scan is structurally blind
 * where every V1 Resource strips `masjid_id` — so those six endpoints had
 * stopped being leak detectors while reporting as reached, and nothing in the
 * verdict, the exit code or `blind_spots` said so.
 *
 * So a reached endpoint is also graded on whether the run could TELL TWO
 * ORGANISATIONS APART on it, and the same strict majority applies:
 * `compared * 2 > comparable`, over the graded surface, minus the endpoints
 * declared cross-tenant by design. Losing it is `partial` (exit 3) and never
 * `incomplete`, because the canary did reach the application and did verify the
 * fail-open shape; what it lacks is data to compare, which is a decision to
 * take in daylight rather than a page. See applyComparisonFloor() for the
 * threshold argument, including why ONE blind endpoint must not be amber:
 * `/api/v1/gallery` is blind on a HEALTHY platform for any two organisations
 * without photos, and an alarm that fires nightly for something no operator can
 * act on is one that gets silenced.
 *
 * Two things follow from the same measurement and are done here as well:
 *
 *  - the comparison pair is chosen by CONTENT rather than by age
 *    (`resolveTenants()`), because the two lowest ids are the two oldest
 *    organisations, and an organisation whose announcements have expired proves
 *    nothing about announcements;
 *  - blindness is named at every verdict level, `clean` included, in
 *    `coverage.cross_tenant_comparison` and as `not compared:` lines.
 *
 * ==========================================================================
 * THE THIRD FLOOR — COMPARED IS NOT ATTRIBUTED
 * ==========================================================================
 *
 * And telling two answers apart is not the same as knowing whose rows are in
 * either of them. Measured 2026-08-13 on an endpoint serving each organisation
 * the other's announcements: `compared`, `pass`, exit 0 — the comparison floor
 * counted it as watched while its comparison was actively wrong. So a third
 * question is asked and reported, `coverage.row_ownership`: on how many graded
 * endpoints could this run trace a returned row back to the organisation that
 * asked for it?
 *
 * The unit of that question is the LIST, not the endpoint, and that correction
 * is newer than the floor. Measured 2026-08-13 on a home-feed shape — sibling
 * lists under one key, one correctly scoped and named after its table, the
 * other a total swap under a key no relation is named by — the one anchored
 * bucket carrying its own rows made the whole answer `pass` and the whole
 * endpoint `attributed`, with the swapped list surviving only as the phrase "3
 * not in an attributable table" inside a passing detail string. Every list is
 * now graded on its own: one it cannot place leaves the endpoint UNATTRIBUTED
 * and names the list and its ids. See analyseBuckets(), which also carries the
 * fix for the same detector accusing a correctly scoped endpoint of the leak
 * its global lookup list looked like.
 *
 * It is graded at ZERO and not at a majority, which is the one asymmetry among
 * the three floors and is argued in full on applyOwnershipFloor(). In short:
 * the endpoint list is discovered and grows, and an endpoint backed by a table
 * outside `canary.compare_by` is permanently unattributable, so a ratio would
 * drift into permanent amber as the route table grew — the cry-wolf failure,
 * reached from the other end. Zero cannot: adding endpoints can only add
 * unattributed ones. It fires when the positive assertion has gone completely
 * dark, which is the state in which `clean` would mean "no swap detected" while
 * no swap could have been detected.
 *
 * ==========================================================================
 * EXIT CODES
 * ==========================================================================
 *
 *   0  clean — every planned endpoint was reached, every planned probe was
 *      sent, at least one check actually observed the application and was
 *      satisfied, and the cross-tenant comparison worked on a majority of the
 *      graded surface
 *   1  finding — something the schedule should page on
 *   2  incomplete — the run is not evidence about this platform: it reached
 *      nothing, verified nothing, lost the majority of the graded surface, was
 *      refused service, or could not start. Non-zero on purpose: "I could not
 *      check" must alert, or the canary can be silenced by making it fail.
 *   3  partial — the run reached and verified this platform, found nothing, and
 *      could not see part of what it planned to: an endpoint it never reached,
 *      one it reached and could not compare two organisations on, or a run in
 *      which not one graded endpoint carried a row it could trace to an owner.
 *      `degraded_by` in the payload and in the log summary names which, because
 *      all of them are exit 3 and `warning` by contract and an alert rule should
 *      not have to re-derive the difference from the coverage arithmetic — a
 *      `partial` run deliberately writes nothing to `errors`. NON-ZERO on
 *      purpose too: an endpoint that stops being watched stops being watched,
 *      and at exit 0 that erosion is invisible forever — which is the same class
 *      of bug as every other one this file was rebuilt to close. But it is a
 *      DIFFERENT code, so the alert path can route it to a ticket rather than a
 *      page, and it logs at `warning` where 1 and 2 log at `error`.
 *
 * Whatever consumes these should treat 1 and 2 as pages, 3 as a ticket, and any
 * non-zero as "today's run did not fully certify the platform". A deploy gate
 * that fails on any non-zero is correct and conservative; a deploy gate that
 * wants to ship through a missing optional record should allow 3 explicitly
 * rather than by widening a threshold in here.
 *
 * `--json` carries a `coverage` block — endpoints reached out of planned, the
 * graded-surface arithmetic behind the verdict, the cross-tenant comparison
 * arithmetic beside it, and the pass/skipped/FAIL census — because "probes:
 * 36/36" reads like coverage and is not: it counts requests attempted, not
 * questions answered. `blind_spots` names every endpoint the run could not see
 * and why; `coverage.cross_tenant_comparison.blind` names every graded endpoint
 * it saw and could not compare; `coverage.row_ownership.unattributed` names
 * every graded endpoint whose rows it could not trace to an owner — the last
 * being the one that says whether a SWAP would have been visible there.
 *
 * And `coverage.routes_not_planned` names the outermost boundary of all of it:
 * public GET routes `ProbeCatalog` REFUSED, which appear in none of the fields
 * above because they were never in the plan. `endpoints_reached: 31/31` is a
 * ratio over the plan, and the plan is not the route table. It changes no
 * verdict — it is identical on every run until somebody adds a public GET — but
 * `clean` may never again sit beside `blind_spots: []` while six public reads,
 * one of them the offering surface with its seats and its PRICES, go
 * unlooked-at. plan() carries the argument for naming them and still not
 * probing them.
 */
class TenancyCanary extends Command
{
    protected $signature = 'tenancy:canary
        {--base-url= : Probe this origin instead of config(app.url)}
        {--transport=auto : auto|http|kernel — auto uses the in-process kernel under test, HTTP otherwise}
        {--tenants= : Comma-separated masjid ids to compare (default: the lowest live ids)}
        {--only= : Comma-separated substrings; probe only endpoints whose URI matches one}
        {--all : Probe the whole /api/mobile surface instead of this hour\'s rotating slice}
        {--max-requests= : Hard ceiling on requests for this run}
        {--delay= : Milliseconds between requests (overrides the derived pacing)}
        {--json : Emit the run as JSON and nothing else}';

    protected $description = 'Probe the running public API for cross-tenant leakage (read-only; safe on production).';

    private const SEVERITY_CRITICAL = 'critical';

    private const SEVERITY_HIGH = 'high';

    private const EXIT_CLEAN = 0;

    private const EXIT_LEAK = 1;

    private const EXIT_INCOMPLETE = 2;

    private const EXIT_PARTIAL = 3;

    /**
     * Ceiling on how many distinct record ids one run will look up per
     * attributable table. The lookup is `WHERE id IN (…)` against a primary
     * key, so it is cheap — but it is a query the canary issues against a
     * production database, and an unbounded IN list built out of whatever the
     * API happened to return is not something to discover at 3am. At
     * `per_page` 100 across 8 /api/v1 endpoints and two organisations the real
     * number is in the hundreds; 5000 is far above that and still a small
     * indexed read. Exceeding it truncates the lookup and SAYS SO, which costs
     * attribution rather than inventing it.
     */
    private const OWNERSHIP_MAX_IDS = 5000;

    /**
     * WHAT THE DETECTORS CANNOT SEE — a standing, hard-coded declaration,
     * emitted in `coverage.detector_limits` on every run including a clean one.
     *
     * Every other honesty field in this payload is about which ENDPOINTS were
     * watched. None of them is about which LEAKS are visible at all, and the
     * three constructions below were each measured on this branch reporting
     * `exit 0 / clean / 0 findings / every check pass` against a live
     * cross-tenant read. A detector always misses something; the defect is a
     * report that says `clean, endpoints_reached 31/31, blind_spots []` with
     * nothing anywhere stating how narrow that sentence is.
     *
     * These are NOT `blind_spots` and they degrade nothing: they are properties
     * of this code, identical on every run, and an alarm that fires nightly for
     * something no operator can act on tonight is the failure this whole design
     * is a reaction to. They are here to be READ — by the next person deciding
     * what a green run entitles them to believe, and by whoever closes one.
     *
     * @var array<string,string>
     */
    private const DETECTOR_LIMITS = [
        'request_inputs' => 'Probes vary ONE input: the `masjid-id` header (and the tenant id in the '.
            'path). The only query parameter ever sent is `per_page`, on api/v1. A scope that reads any '.
            'OTHER request input — a `?masjid_id=` query parameter, a cookie, a body field — is invisible '.
            'to every check here. MEASURED 2026-08-13: a fail-open '.
            '`if (request()->filled(\'masjid_id\')) $q->where(...)` filter scored exit 0, clean, 0 '.
            'findings, every check pass, while the live request as masjid A with `?masjid_id=B` returned '.
            'B\'s rows. That is the 2026-08-11 SearchableTrait shape one parameter over — the exact bug '.
            'class this canary was built after. NOT CLOSED.',
        'identity_in_the_body' => 'A row is recognised by an integer `id` (ResponseFacts::recordIdentity) '.
            'or by a `masjid_id` in the body (canary.tenant_keys). A resource that emits NEITHER — name, '.
            'email, phone and no key — is unrecognisable to both, and the row-ownership assertion has '.
            'nothing to look up. MEASURED 2026-08-13: a sibling bucket carrying another organisation\'s '.
            'FAMILIES (name, email, phone, guardian_of) scored exit 0, clean, row_ownership attributable '.
            '1 attributed 1 met true — the strongest verdict this command can issue, on an endpoint '.
            'handing every caller another school\'s parent contact details. NOT CLOSED.',
        'attributable_tables' => 'A row can only be traced through a relation named in '.
            '`canary.compare_by` — three of the four names there can attribute. Every other table on the '.
            'platform is unanchored by construction, so a swap on one is invisible to the only detector '.
            'that sees swaps. `coverage.row_ownership.tables_available` lists the Masjid relations that '.
            'could be named and are not; `unplaced` names the endpoints where it cost something on THIS '.
            'run, and since 2026-08-13 that costs the run its floor.',
        'route_surface' => 'GET only, and only under `canary.prefixes`. See `coverage.route_table`: on '.
            'this route table 31 of 364 routes are probed. The 302 under api/admin and api/family — the '.
            'admin tree and the parent portal, where a school\'s classrooms and children\'s records will '.
            'live, and where every defect of rounds one to three actually shipped — are probed by '.
            'nothing, on this run or on any other.',
    ];

    /** @var array<int,array<string,mixed>> */
    private array $findings = [];

    /** @var array<int,array<string,mixed>> */
    private array $checks = [];

    /** @var array<int,string> */
    private array $errors = [];

    /** @var array<int,string> */
    private array $notes = [];

    /** Endpoints the run never got to at all (budget, throttle, abort). */
    /** @var array<int,string> */
    private array $notProbed = [];

    /**
     * Public GET routes `ProbeCatalog` REFUSED to plan, and why — a `{slug}`, an
     * `{id}`, a limiter this canary may not spend, the `canary.skip` list.
     *
     * Deliberately NOT part of `blindSpots` and deliberately not a reason to
     * degrade. `blindSpots` means "an endpoint this run was going to look at and
     * could not", every entry of which is an event with a remedy; this is a
     * standing property of the route table that is identical on every run, and
     * charging it to the verdict would put a permanent amber on a correct
     * platform — the cry-wolf failure, reached from a third direction.
     *
     * What it must do is be VISIBLE. Measured on 2026-08-13: a total swap with
     * no masjid filter on an `{id}` route left `--all` at `exit=0 clean
     * blind_spots=[] endpoints_reached=31/31` with the uri mentioned nowhere in
     * the payload. See plan() for why they are named and still not probed.
     *
     * @var array<string,string>
     */
    private array $routesNotPlanned = [];

    /**
     * THE WHOLE ROUTE TABLE, as arithmetic — and the outermost boundary of
     * anything this command is entitled to say.
     *
     * `routesNotPlanned` above is eight URIs, and until 2026-08-13 it was the
     * only statement of scope in the payload. It reads like the boundary and it
     * is a boundary inside a boundary: the eight are eight of 333 routes no run
     * will ever probe. `ProbeCatalog::scan()` dropped a non-GET route and a
     * credentialed one into NEITHER list, silently, and `canary.prefixes`
     * excluded 313 more before any predicate ran.
     *
     * Measured on this route table: 364 routes, 31 planned. The 302 under
     * `api/admin` and `api/family` are the parent portal and the admin tree —
     * the surface every defect of rounds one to three actually shipped on, and
     * the one a school's classrooms and children's records will sit behind. The
     * twelve write-verb routes under the probed prefixes are unauthenticated,
     * and seven of them are on the graded `api/v1` surface, several of them
     * READS shaped as writes (`offerings/{slug}/quote`, `zakat/calculate`).
     *
     * Like `routesNotPlanned` it degrades nothing — it is byte-identical on
     * every run until somebody adds a route. It exists so that `clean` cannot be
     * read as a claim about the application when it is a claim about 31 routes.
     *
     * @var array<string,mixed>
     */
    private array $routeTable = [];

    /** Endpoints that were probed and never answered 2xx to a valid tenant. */
    /** @var array<string,string> */
    private array $unreached = [];

    /**
     * Every endpoint the run could not see, and why — whether it was never
     * probed or probed and never answered. Named at EVERY verdict level: the
     * coverage floor decides how loud the alarm is, never whether the gap is
     * visible.
     *
     * @var array<string,string>
     */
    private array $blindSpots = [];

    /**
     * Endpoints the run DID see and could not compare: two organisations were
     * asked the same question and nothing in either answer distinguished one
     * organisation's from the other's.
     *
     * Kept apart from `blindSpots` because it is a different fact with a
     * different threshold — "I could not reach it" against "I reached it and it
     * told me nothing". See applyComparisonFloor().
     *
     * @var array<string,string>
     */
    private array $blindDetectors = [];

    /**
     * Endpoints where the cross-tenant comparison does not APPLY — declared
     * cross-tenant by design, or a platform with no second organisation. A
     * legitimate skip is not a gap, and counting one as either "compared" or
     * "blind" would make the arithmetic say something untrue in one direction
     * or the other.
     *
     * @var array<string,string>
     */
    private array $comparisonNotApplicable = [];

    /**
     * WHO OWNS THE ROWS THE API HANDED BACK — read from the database, not from
     * the body. `relation name => [record id => owning masjid id]`.
     *
     * This is what makes a POSITIVE assertion possible on `/api/v1`, where
     * every Resource strips `masjid_id` and no detector that reads the body can
     * say whose rows these are. See resolveOwnership().
     *
     * @var array<string,array<int,int>>
     */
    private array $ownership = [];

    /**
     * Relations named in `canary.compare_by` that CANNOT attribute a row, and
     * why. Reported rather than logged as a note: it is a standing property of
     * the configuration, not an event in this run.
     *
     * @var array<string,string>
     */
    private array $ownershipSkipped = [];

    /** Endpoints whose answers were positively attributed to the requesting organisation. */
    /** @var array<string,string> */
    private array $attributed = [];

    /**
     * Endpoints the run reached, could have attributed, and could not — the
     * answer carried no row this canary can trace to an owner.
     *
     * The union of the two fields below, kept because it is the field the
     * payload has always carried and the one an operator greps.
     *
     * @var array<string,string>
     */
    private array $unattributed = [];

    /**
     * ROWS CAME BACK AND NOTHING COULD PLACE THEM. The endpoint served real
     * record ids to an anonymous caller, and no `canary.compare_by` relation is
     * named by the route or by the key path those ids sat under — or the lists
     * that share an anchor contradicted each other.
     *
     * This is the half of `unattributed` that is a HOLE rather than a silence,
     * and it is the shape a swap hides in: measured 2026-08-13, a total swap
     * under an unanchored bucket beside one correctly scoped sibling scored
     * `attributable 2, attributed 1, met TRUE, exit 0, status clean`. It is
     * graded at zero — see applyOwnershipFloor().
     *
     * @var array<string,string>
     */
    private array $unplaced = [];

    /**
     * ...and the other half: the endpoint carried no rows at all, so there was
     * nothing to attribute. A dormant `/api/v1/gallery`, `/api/v1/settings` with
     * no list in it, an organisation whose announcements have all expired.
     *
     * Named on every run and graded by nothing, because no assertion over an
     * empty body is possible and no operator can make one exist tonight. Keeping
     * it apart from `unplaced` is the whole of the threshold argument.
     *
     * @var array<string,string>
     */
    private array $nothingToAttribute = [];

    /**
     * Why this run is `partial`, as a list of machine-readable reasons.
     *
     * A `partial` run deliberately writes nothing to `errors` — that is what
     * separates it from a blocked one, and a test pins it — so before this the
     * ONLY way an alert rule could tell "one endpoint 404'd" from "the leak
     * detector is asleep on most of the graded surface" was to re-derive it
     * from the coverage arithmetic. Both are exit 3 and both log `warning`, by
     * contract; this is the field that routes them apart.
     *
     * @var array<int,string>
     */
    private array $degradedBy = [];

    private int $plannedEndpoints = 0;

    /**
     * The run is not evidence about this platform — it reached nothing,
     * verified nothing, lost the majority of the graded surface, was refused
     * service, or could not start. Exit 2.
     */
    private bool $blocked = false;

    /**
     * The run IS evidence, about most of the platform, and something it planned
     * to look at it did not see. Exit 3. Never an alternative to naming the gap
     * — only to shouting about it.
     */
    private bool $degraded = false;

    /** The arithmetic behind the floor, carried into the report. */
    /** @var array<string,mixed> */
    private array $gradedSurface = [];

    /** The arithmetic behind the comparison floor, likewise. */
    /** @var array<string,mixed> */
    private array $comparison = [];

    /** ...and behind the row-ownership assertion, which is reported and not rationed. */
    /** @var array<string,mixed> */
    private array $rowOwnership = [];

    public function handle(): int
    {
        // A console run is one process per run in production, but `Artisan::call`
        // reuses this instance, and every field above accumulates. Without this
        // a second run in the same process inherits the first one's findings,
        // checks and blind spots — which is only ever exercised by tests, and is
        // exactly where a test comparing two verdicts would get a false answer.
        $this->findings = [];
        $this->checks = [];
        $this->errors = [];
        $this->notes = [];
        $this->notProbed = [];
        $this->routesNotPlanned = [];
        $this->routeTable = [];
        $this->unreached = [];
        $this->blindSpots = [];
        $this->blindDetectors = [];
        $this->comparisonNotApplicable = [];
        $this->ownership = [];
        $this->ownershipSkipped = [];
        $this->attributed = [];
        $this->unattributed = [];
        $this->unplaced = [];
        $this->nothingToAttribute = [];
        $this->degradedBy = [];
        $this->gradedSurface = [];
        $this->comparison = [];
        $this->rowOwnership = [];
        $this->plannedEndpoints = 0;
        $this->blocked = false;
        $this->degraded = false;

        $startedAt = now();
        $started = microtime(true);

        /** @var array<string,mixed> $config */
        $config = config('canary');

        $baseUrl = (string) ($this->option('base-url') ?: ($config['base_url'] ?: config('app.url')));

        if (trim($baseUrl) === '') {
            $this->emitFatal('No base URL: set APP_URL, canary.base_url, or pass --base-url.');

            return 2;
        }

        $tenants = $this->resolveTenants($config);

        if ($tenants === []) {
            $this->blocked = true;
            $this->errors[] = 'No masjids found to probe — the canary has nothing to compare.';

            return $this->report($startedAt, $started, $baseUrl, 'unresolved', $tenants, 0, 0);
        }

        if (count($tenants) < 2) {
            // Still worth running: the fail-open SHAPE check does not need two
            // tenants, only the count arithmetic does.
            $this->notes[] = 'Only one tenant available — a leak can be detected by shape but not proven by row counts.';
        }

        $transport = $this->makeTransport($baseUrl, $config);
        $plan = $this->plan($config, $tenants);

        if ($plan === []) {
            $this->blocked = true;
            $this->errors[] = 'The router exposed no probeable public GET endpoint — discovery is broken, not the API.';

            return $this->report($startedAt, $started, $baseUrl, $transport->name(), $tenants, 0, 0);
        }

        [$results, $sent, $planned] = $this->runProbes($plan, $transport, $config, $started);

        $this->evaluate($plan, $results, $config, count($tenants));
        $this->assessCoverage($plan, $config);

        return $this->report($startedAt, $started, $baseUrl, $transport->name(), $tenants, $sent, $planned);
    }

    // ---------------------------------------------------------------- tenants

    /**
     * The organisations to compare — the only part of the run that reads the
     * database directly.
     *
     * A console run binds no tenant, and `Masjid` is the tenant rather than a
     * tenant-scoped model, so no global scope applies. Soft-deleted rows are
     * excluded by the model's SoftDeletes, which is the same predicate
     * `PublicTenant::exists()` applies to the header — so an organisation
     * chosen here is one the API will still answer for.
     *
     * ## Why this is not "the two lowest ids" any more
     *
     * It was, and the lowest ids are the OLDEST organisations, which is not the
     * same thing as the two that hold rows. The cross-tenant comparison in
     * checkTenantsGetDistinctAnswers() can only prove anything when both
     * answers actually carry rows: two empty answers are byte-identical whether
     * the platform is correctly scoped or pinned to one organisation. So a
     * comparison pair chosen by age quietly stops being a leak detector as soon
     * as those two organisations go quiet — `/api/v1/announcements` returns zero
     * rows the moment an org's announcements expire — while every organisation
     * that DOES hold content goes uncompared.
     *
     * Measured on 2026-08-12: with the real `SearchableTrait::scopeFilterByMasjid`
     * mutated to pin every public query to one organisation, a run whose two
     * compared organisations held rows exits 1 with five
     * `same_rows_served_to_two_tenants` findings; a run whose two compared
     * organisations were dormant exited 0 and called the platform clean.
     *
     * So the pair is chosen by CONTENT: the organisations holding rows across
     * the most of the graded surface (`canary.compare_by`), then by total rows,
     * then by id. Deterministic — no clock, no randomness, and two canaries on
     * two hosts reading one database agree. It changes when the content
     * changes, which is the point: the detector follows the data instead of
     * going blind next to it.
     *
     * `--tenants=` still overrides everything, because an operator chasing a
     * specific pair is not guessing.
     *
     * @param  array<string,mixed>  $config
     * @return array<int,int>
     */
    private function resolveTenants(array $config): array
    {
        $explicit = (string) ($this->option('tenants') ?? '');

        if (trim($explicit) !== '') {
            return array_values(array_filter(array_map(
                static fn ($id) => (int) trim($id),
                explode(',', $explicit)
            ), static fn (int $id) => $id > 0));
        }

        $want = max(1, (int) ($config['tenants'] ?? 2));

        $oldest = Masjid::query()
            ->orderBy('id')
            ->limit($want)
            ->pluck('id')
            ->map(static fn ($id) => (int) $id)
            ->all();

        $holding = $this->tenantsHoldingContent($config, $want);

        if ($holding === [] || $holding === $oldest) {
            return $oldest;
        }

        $this->notes[] = 'Comparing masjid '.implode(' and ', $holding).
            ' — the organisations holding the most public content, not the lowest ids ('.
            implode(', ', $oldest).'). Two dormant organisations cannot prove anything about a leak.';

        return $holding;
    }

    /**
     * The `$want` organisations that hold rows across the most of the compared
     * surface, ranked BREADTH first.
     *
     * Breadth before depth on purpose: each non-empty relation is one more
     * endpoint on which the two answers can be told apart, and an organisation
     * with ten thousand photos and nothing else makes exactly one endpoint
     * comparable. Ties fall back to total rows and then to the lowest id, so a
     * platform where nothing has content resolves to precisely the old
     * behaviour.
     *
     * One query. It ranks by counting related rows, so it reads more tables
     * than the old `SELECT id FROM masjids` — all of it SELECT, none of it per
     * probe. A relation named in config that this model does not have is
     * skipped rather than fatal, and any failure at all falls back to the id
     * order with a note: a canary that dies choosing its tenants is a canary
     * that stopped watching.
     *
     * @param  array<string,mixed>  $config
     * @return array<int,int>
     */
    private function tenantsHoldingContent(array $config, int $want): array
    {
        $relations = array_values(array_filter(
            (array) ($config['compare_by'] ?? []),
            static fn ($relation) => is_string($relation) && method_exists(Masjid::class, $relation)
        ));

        if ($relations === []) {
            return [];
        }

        try {
            $masjids = Masjid::query()->select('id')->withCount($relations)->get();
        } catch (\Throwable $e) {
            $this->notes[] = 'Could not rank organisations by content ('.$e->getMessage().
                '); comparing the lowest ids instead.';

            return [];
        }

        $ranked = $masjids->map(static function ($masjid) use ($relations) {
            $counts = array_map(
                static fn (string $relation) => (int) ($masjid->getAttribute(Str::snake($relation).'_count') ?? 0),
                $relations
            );

            return [
                'id' => (int) $masjid->id,
                'breadth' => count(array_filter($counts)),
                'total' => array_sum($counts),
            ];
        })->all();

        usort($ranked, static fn (array $a, array $b) => ($b['breadth'] <=> $a['breadth'])
            ?: (($b['total'] <=> $a['total'])
            ?: ($a['id'] <=> $b['id'])));

        return array_map(
            static fn (array $row) => $row['id'],
            array_slice($ranked, 0, $want)
        );
    }

    // -------------------------------------------------------------- transport

    /** @param array<string,mixed> $config */
    private function makeTransport(string $baseUrl, array $config): ProbeTransport
    {
        $choice = (string) $this->option('transport');
        $tenantKeys = (array) ($config['tenant_keys'] ?? ['masjid_id']);

        if ($choice === 'auto') {
            // Under test there is no server to talk to, and a test that reached
            // the network would be probing the internet instead of the
            // application it is supposed to be pinning.
            $choice = $this->laravel->runningUnitTests() ? 'kernel' : 'http';
        }

        if ($choice === 'kernel') {
            return new KernelTransport(
                $this->laravel->make(HttpKernel::class),
                $this->laravel,
                $baseUrl,
                $tenantKeys,
            );
        }

        return new HttpTransport(
            $this->laravel->make(HttpFactory::class),
            $baseUrl,
            $tenantKeys,
            (int) ($config['timeout'] ?? 10),
            (int) ($config['connect_timeout'] ?? 5),
        );
    }

    // ------------------------------------------------------------------- plan

    /**
     * The probe plan, keyed by route URI.
     *
     * `/api/v1` first and in full — it carries no throttle at all, and it is
     * where both production holes actually were. `/api/mobile` second and by
     * rotating slice, so a truncated run loses the cheaper signal rather than
     * the expensive one.
     *
     * ==========================================================================
     * THE ROUTES THAT ARE NOT IN THE PLAN, AND WHY THEY STAY OUT OF IT
     * ==========================================================================
     *
     * `ProbeCatalog` refuses a route with a parameter other than `{masjid_id}`
     * and a route behind a limiter this canary may not spend. Until 2026-08-13
     * those refusals were invisible — not in the plan, not in `blind_spots`, not
     * in coverage, not in the verdict — and a total swap with no masjid filter
     * at all on an `{id}` route produced `exit=0 clean blind_spots=[]` with the
     * uri mentioned nowhere in the payload. They are now carried in
     * `coverage.routes_not_planned` and printed on every run.
     *
     * They are still NOT PROBED, and that is a judgement rather than an
     * omission. Feeding record ids learned from the collection probes back into
     * the `{id}` routes would make them probeable, and it was weighed:
     *
     *  - It buys the least where it is needed most. The route this most matters
     *    for is `GET /api/v1/offerings/{slug}` — a program's name, its seats and
     *    its PRICES — and no public collection endpoint publishes an offering
     *    slug, so there is nothing to feed it. It is also behind
     *    `throttle:registration-quote`, a per-hour bucket a registrant needs
     *    while filling in a form; probing it hourly spends that bucket, and
     *    widening `throttle_allowlist` to reach it is an availability decision,
     *    not a canary one. The same is true of `/api/v1/zakat/nisab`. So the
     *    doubly-invisible money surface stays unprobed either way, and what
     *    id-feedback WOULD buy is `{id}` variants of announcements, services,
     *    gallery and pages — the four collections this run already probes in
     *    full, twice, every hour.
     *  - It is a new probe class with an inverted verdict (asking for masjid A's
     *    row as masjid B, where a 200 is the leak and a 404 is correct), landing
     *    in the same round as two fixes to detectors that each shipped a false
     *    accusation. This file's last three rounds each added a refusal without
     *    asking what else it refuses.
     *
     * The honest thing today is therefore to NAME them — every run, at every
     * verdict level, with the reason — so that `clean` can never again sit
     * beside `blind_spots: []` while six public GET routes go unlooked-at. If
     * this is revisited, the argument to beat is the first bullet: make the
     * offering surface reachable first, or the expensive half of the work
     * watches the endpoints that were already watched.
     *
     * @param  array<string,mixed>  $config
     * @param  array<int,int>  $tenants
     * @return array<string,array{global:bool,probes:array<int,Probe>}>
     */
    private function plan(array $config, array $tenants): array
    {
        $catalog = new ProbeCatalog($this->laravel->make('router'), $config);
        $endpoints = $catalog->endpoints();
        $declined = $catalog->declined();

        // Taken before `--only` narrows anything, and deliberately NOT narrowed
        // by it. `--only` says which endpoints this run is about; it does not
        // make the other 333 routes watched, and a census that shrank when an
        // operator narrowed the run would be a coverage claim that improves by
        // looking at less.
        $this->routeTable = $catalog->census();

        $only = array_filter(array_map('trim', explode(',', (string) ($this->option('only') ?? ''))));

        if ($only !== []) {
            $matches = static function (string $uri) use ($only): bool {
                foreach ($only as $needle) {
                    if (str_contains($uri, $needle)) {
                        return true;
                    }
                }

                return false;
            };

            $endpoints = array_values(array_filter(
                $endpoints,
                static fn (array $e) => $matches($e['uri'])
            ));

            // `--only` narrows what this run is ABOUT, so it has to narrow the
            // refusals too. An operator chasing one endpoint should not be
            // handed the whole route table's blind spots, and a test that
            // asserts an empty list for a narrowed run is asserting something
            // true.
            $declined = array_filter(
                $declined,
                static fn (string $uri) => $matches($uri),
                ARRAY_FILTER_USE_KEY
            );
        }

        $this->routesNotPlanned = $declined;

        $v1 = array_values(array_filter($endpoints, static fn ($e) => str_starts_with($e['uri'], 'api/v1')));
        $mobile = array_values(array_filter($endpoints, static fn ($e) => ! str_starts_with($e['uri'], 'api/v1')));

        $mobile = $this->rotate($mobile, $config);

        $perPage = (int) ($config['per_page'] ?? 100);
        $plan = [];

        foreach (array_merge($v1, $mobile) as $endpoint) {
            $probes = $endpoint['global']
                ? $this->globalProbes($endpoint, $tenants, $perPage)
                : $this->tenantProbes($endpoint, $tenants, $perPage);

            if ($probes !== []) {
                $plan[$endpoint['uri']] = ['global' => $endpoint['global'], 'probes' => $probes];
            }
        }

        return $plan;
    }

    /**
     * This hour's slice of the /api/mobile surface.
     *
     * Deterministic from the clock, so it needs no stored cursor and two
     * canaries on two hosts agree. The whole surface is covered every
     * ceil(n/slice) hours; against a hole that lived for months, a few hours of
     * detection latency costs nothing, and the alternative — every mobile
     * endpoint every run — is the thing that eats `throttle:mobile`.
     *
     * @param  array<int,array{uri:string,global:bool,params:array<int,string>}>  $mobile
     * @param  array<string,mixed>  $config
     * @return array<int,array{uri:string,global:bool,params:array<int,string>}>
     */
    private function rotate(array $mobile, array $config): array
    {
        $slice = max(1, (int) ($config['budget']['mobile_slice'] ?? 8));

        if ($this->option('all') || count($mobile) <= $slice) {
            return $mobile;
        }

        $windows = (int) ceil(count($mobile) / $slice);
        $window = ((int) floor(time() / 3600)) % $windows;

        $this->notes[] = "/api/mobile probed as rotating slice {$window} of {$windows} (--all for the full surface).";

        return array_slice($mobile, $window * $slice, $slice);
    }

    /**
     * @param  array{uri:string,global:bool,params:array<int,string>}  $endpoint
     * @param  array<int,int>  $tenants
     * @return array<int,Probe>
     */
    private function tenantProbes(array $endpoint, array $tenants, int $perPage): array
    {
        $probes = [];
        $inPath = in_array('masjid_id', $endpoint['params'], true);

        foreach ($tenants as $tenantId) {
            $probes[] = new Probe(
                endpoint: $endpoint['uri'],
                path: $this->fillPath($endpoint['uri'], $tenantId),
                query: $this->query($endpoint['uri'], $perPage),
                headers: $this->headers($inPath ? null : (string) $tenantId),
                variant: Probe::VARIANT_TENANT,
                tenantId: $tenantId,
            );
        }

        // The fail-open variants only exist for endpoints that take the tenant
        // from a HEADER. When the organisation is a path segment there is no
        // "absent" spelling — `/api/mobile/masjids//events` is a different
        // route, and `/0/events` is a 404 from findOrFail, not a fail-open.
        if (! $inPath) {
            foreach ([
                Probe::VARIANT_ABSENT => null,
                Probe::VARIANT_ZERO => '0',
                Probe::VARIANT_EMPTY => '',
            ] as $variant => $headerValue) {
                $probes[] = new Probe(
                    endpoint: $endpoint['uri'],
                    path: $endpoint['uri'],
                    query: $this->query($endpoint['uri'], $perPage),
                    headers: $this->headers($headerValue),
                    variant: $variant,
                    tenantId: null,
                );
            }
        }

        return $probes;
    }

    /**
     * A deliberately cross-tenant endpoint (the masjid directory, the azkar
     * library). Probed once, for server faults only — it is *supposed* to
     * answer a caller who named no organisation.
     *
     * @param  array{uri:string,global:bool,params:array<int,string>}  $endpoint
     * @param  array<int,int>  $tenants
     * @return array<int,Probe>
     */
    private function globalProbes(array $endpoint, array $tenants, int $perPage): array
    {
        $tenantId = in_array('masjid_id', $endpoint['params'], true) ? ($tenants[0] ?? null) : null;

        return [new Probe(
            endpoint: $endpoint['uri'],
            path: $tenantId === null ? $endpoint['uri'] : $this->fillPath($endpoint['uri'], $tenantId),
            query: $this->query($endpoint['uri'], $perPage),
            headers: $this->headers($tenantId === null ? null : (string) $tenantId),
            variant: Probe::VARIANT_GLOBAL,
            tenantId: null,
        )];
    }

    private function fillPath(string $uri, int $tenantId): string
    {
        return str_replace('{masjid_id}', (string) $tenantId, $uri);
    }

    /** @return array<string,scalar> */
    private function query(string $uri, int $perPage): array
    {
        // A large page is what makes an unscoped answer visibly bigger than any
        // single tenant's; the production exploit used per_page=1000. Harmless
        // on endpoints that ignore it.
        return str_starts_with($uri, 'api/v1') ? ['per_page' => $perPage] : [];
    }

    /** @return array<string,string> */
    private function headers(?string $masjidId): array
    {
        $headers = [
            'Accept' => 'application/json',
            'User-Agent' => 'ManaraTenancyCanary/1',
            // So an access log can drop these in one filter rather than having
            // the canary look like traffic.
            'X-Canary' => 'tenancy',
        ];

        if ($masjidId !== null) {
            $headers['masjid-id'] = $masjidId;
        }

        return $headers;
    }

    // -------------------------------------------------------------- execution

    /**
     * @param  array<string,array{global:bool,probes:array<int,Probe>}>  $plan
     * @param  array<string,mixed>  $config
     * @return array{0: array<string,array<int,ProbeResult>>, 1: int, 2: int}
     */
    private function runProbes(array $plan, ProbeTransport $transport, array $config, float $started): array
    {
        $maxRequests = (int) ($this->option('max-requests') ?: ($config['budget']['max_requests'] ?? 60));
        $maxSeconds = (int) ($config['budget']['max_seconds'] ?? 300);
        $perMinute = max(1, (int) ($config['budget']['max_per_minute'] ?? 20));

        $delayMs = $this->option('delay') !== null
            ? max(0, (int) $this->option('delay'))
            : (int) ceil(60000 / $perMinute);

        $planned = array_sum(array_map(static fn ($e) => count($e['probes']), $plan));
        $results = [];
        $sent = 0;
        $first = true;

        foreach ($plan as $uri => $entry) {
            $results[$uri] = [];

            foreach ($entry['probes'] as $probe) {
                // Truncation is a COVERAGE loss, not a verdict of its own: it
                // degrades the run, and assessCoverage() decides from what is
                // left whether that is `partial` or `incomplete`. Cutting the
                // last mobile endpoint off a run that saw all of /api/v1 is not
                // the same event as cutting the run off after three probes, and
                // the old single `incomplete` flag called them both exit 2. The
                // truncation itself is still reported, always.
                if ($sent >= $maxRequests) {
                    $this->degrade('truncated_request_budget');
                    $this->errors[] = "Request budget exhausted after {$sent} probes ({$planned} planned) — run truncated.";

                    return [$results, $sent, $planned];
                }

                if ((microtime(true) - $started) >= $maxSeconds) {
                    $this->degrade('truncated_time_budget');
                    $this->errors[] = "Time budget of {$maxSeconds}s exhausted after {$sent} probes — run truncated.";

                    return [$results, $sent, $planned];
                }

                if (! $first && $delayMs > 0) {
                    usleep($delayMs * 1000);
                }

                $first = false;
                $sent++;

                $result = $this->dispatch($transport, $probe);
                $results[$uri][] = $result;

                // Being REFUSED SERVICE is blocking and unconditional, however
                // much the run had already covered. It is a fact about the
                // canary's own budget rather than a gap in an otherwise valid
                // observation, and its failure mode is that the canary quietly
                // covers less and less of the surface every hour. Measured: two
                // back-to-back runs with pacing disabled exhausted the mobile
                // bucket and the second stopped after ONE probe. That must stay
                // the loud outcome.
                if ($result->isThrottled()) {
                    $this->blocked = true;
                    $this->errors[] = 'Throttled (429) on '.$probe->endpoint.
                        ' — run aborted. A 429 is not evidence of leakage, and pushing through one is the behaviour this canary must never have.';

                    return [$results, $sent, $planned];
                }

                if ($result->rateLimitRemaining !== null && $result->rateLimitRemaining < 5) {
                    $this->blocked = true;
                    $this->errors[] = 'Backing off: X-RateLimit-Remaining fell to '.$result->rateLimitRemaining.
                        ' on '.$probe->endpoint.'. Real clients need the rest of that bucket more than this run does.';

                    return [$results, $sent, $planned];
                }

                // One dead socket degrades; a whole dead origin is caught by the
                // coverage floor, because every endpoint then lands in
                // `unreached` and the graded surface goes to zero.
                if ($result->transportError !== null) {
                    $this->degrade('transport_error');
                    $this->errors[] = $probe->endpoint.' ('.$probe->variant.') unreachable: '.$result->transportError;
                }
            }
        }

        return [$results, $sent, $planned];
    }

    /**
     * The last gate before a request leaves the process.
     *
     * `Probe` cannot express a method and `ProbeCatalog` will not plan a route
     * that answers a write verb — this re-reads the router at DISPATCH time, so
     * a route redefined between planning and sending (a provider booting late,
     * a fixture registered mid-run) cannot slip a write past. Three checks for
     * one invariant is not paranoia when the invariant is "does not mutate the
     * production database of four live congregations".
     */
    private function dispatch(ProbeTransport $transport, Probe $probe): ProbeResult
    {
        foreach ($this->laravel->make('router')->getRoutes() as $route) {
            if ($route->uri() !== $probe->endpoint) {
                continue;
            }

            if (array_intersect(['POST', 'PUT', 'PATCH', 'DELETE'], $route->methods()) !== []) {
                return ProbeResult::failed(
                    $probe,
                    'refused: '.$probe->endpoint.' answers a write verb; this command issues GET only',
                    0
                );
            }
        }

        return $transport->send($probe);
    }

    // ------------------------------------------------------------- evaluation

    /**
     * @param  array<string,array{global:bool,probes:array<int,Probe>}>  $plan
     * @param  array<string,array<int,ProbeResult>>  $results
     * @param  array<string,mixed>  $config
     * @param  int  $tenantsAvailable  how many organisations the run had to compare at all
     */
    private function evaluate(array $plan, array $results, array $config, int $tenantsAvailable): void
    {
        // Ask the database who owns the rows this run was handed, ONCE, before
        // any check reads them — one small indexed SELECT per attributable
        // table rather than one per endpoint per organisation.
        $this->resolveOwnership($config, $results);

        foreach ($plan as $uri => $entry) {
            $probeResults = $results[$uri] ?? [];

            if ($probeResults === []) {
                $this->notProbed[] = $uri;
                $this->addCheck($uri, 'origin-answered', 'skipped', 'NOT PROBED — run ended before this endpoint');

                continue;
            }

            // Whether every probe this endpoint's checks wanted was actually
            // made. A partially probed endpoint is still worth evaluating — a
            // leak the run REACHED must be reported, budget or no budget — but
            // a check whose probes were cut off may never report `pass`. It
            // reports `skipped`, because "I did not look" and "I looked and it
            // was fine" are the two answers a canary must never confuse.
            $complete = count($probeResults) === count($entry['probes']);

            // ...and whether the application was reached AT ALL. Everything
            // below reads a response body or a status; if the only answers this
            // endpoint gave were redirects, 404s or dead sockets, none of those
            // checks looked at the application, and all of them must say so.
            [$reached, $reachDetail] = $this->reachability($probeResults);

            $this->addCheck($uri, 'origin-answered', $reached ? 'pass' : 'skipped', $reachDetail);

            if (! $reached) {
                $this->unreached[$uri] = $reachDetail;
            }

            // ONE bucket analysis per endpoint, read by BOTH id-based
            // detectors. They are answering different questions off the same
            // lists, and a bucket the canary cannot identify must not be proof
            // for one of them and excluded from the other.
            $analysis = $this->analyseBuckets((string) $uri, $probeResults, $config);

            $this->checkServerFaults($uri, $probeResults, $complete, $reached);
            $this->checkBodyNamesRequestedTenantOnly($uri, $probeResults, $complete, $reached);
            $this->checkTenantsGetDistinctAnswers(
                $uri, $entry, $probeResults, $complete, $reached, $tenantsAvailable, $analysis
            );
            $this->checkAnswerCarriesRequestedTenantsRows($uri, $entry, $probeResults, $complete, $reached, $analysis);

            if (! $entry['global']) {
                $this->checkTenantLessRefused($uri, $entry['probes'], $probeResults, $complete, $reached);
            }
        }
    }

    /**
     * Did a probe that named a REAL organisation get a 2xx from this endpoint?
     *
     * This is the anchor every other check hangs off, and it is the question
     * nothing used to ask. `isSuccessful()` was 2xx and `isServerError()` was
     * 5xx; 3xx and 4xx were in a gap no check read, and `checkTenantLessRefused`
     * treated ANY non-2xx tenant-less answer as "correctly refused". So an
     * origin that redirected everything — or 404'd everything — satisfied every
     * check by never answering one. Measured: 36/36 probes, 23 passes, exit 0,
     * against an origin that served the application not at all.
     *
     * A valid-tenant 2xx is the cheapest available proof that the thing on the
     * other end of the socket is this API rather than a redirect, an error page
     * or a stale release.
     *
     * @param  array<int,ProbeResult>  $results
     * @return array{0:bool,1:string}
     */
    private function reachability(array $results): array
    {
        $anchors = array_values(array_filter(
            $results,
            static fn (ProbeResult $r) => ! $r->probe->isTenantLess()
        ));

        if ($anchors === []) {
            return [false, 'NOT REACHED — no probe naming a real organisation was sent'];
        }

        $ok = array_values(array_filter($anchors, static fn (ProbeResult $r) => $r->isSuccessful()));

        if ($ok !== []) {
            return [true, count($ok).'/'.count($anchors).' valid-tenant probe(s) answered 2xx'];
        }

        $seen = array_values(array_unique(array_map(
            static fn (ProbeResult $r) => $r->statusLabel(),
            $anchors
        )));

        return [false, 'NOT REACHED — valid-tenant probe(s) answered '.implode(', ', $seen).
            '; no 2xx, so nothing below was observed'];
    }

    /**
     * The outcome of one check — a THREE-state answer, and the reason this file
     * exists in its current form.
     *
     * `pass` used to mean nothing more than "every planned probe produced a
     * result object", and a FAILED probe is a result object. So a run in which
     * all 36 probes died on ConnectionException reported the truncation
     * correctly at the run level and then printed 23 `pass` rows underneath it,
     * including "no-server-fault :: 5 probe(s)" for endpoints no request ever
     * reached. And `body-names-only-the-requested-tenant` reported `pass` with
     * "no masjid_id seen" for all 8 /api/v1 endpoints on EVERY run, forever,
     * because the V1 Resources strip `masjid_id` — the `$observed` flag was
     * computed and then thrown away.
     *
     * So a `pass` now requires all three:
     *
     *   $failed === false    the check did not find the thing it looks for
     *   $complete === true   every probe it wanted was actually sent
     *   $observed === true   it had something real to look AT
     *
     * Anything less is `skipped`, and the detail string must say what was
     * missing. "I did not look" and "I looked and it was fine" are the two
     * answers a canary must never confuse.
     */
    private function outcome(bool $failed, bool $complete, bool $observed): string
    {
        if ($failed) {
            return 'FAIL';
        }

        return $complete && $observed ? 'pass' : 'skipped';
    }

    /**
     * Probe 2: a 5xx anywhere. On a tenant-less request it is reported as its
     * own kind, because that is the gallery's exact signature — a client
     * mistake surfacing as a server fault, where it reads as infrastructure
     * flakiness and nobody looks for a tenancy bug behind it.
     *
     * @param  array<int,ProbeResult>  $results
     */
    private function checkServerFaults(string $uri, array $results, bool $complete, bool $reached): void
    {
        $faulted = false;
        $answered = 0;

        foreach ($results as $result) {
            if ($result->isAnswered()) {
                $answered++;
            }

            if (! $result->isServerError()) {
                continue;
            }

            $faulted = true;

            $this->addFinding(
                kind: $result->probe->isTenantLess() ? 'tenant_error_escaped_as_5xx' : 'server_fault',
                severity: self::SEVERITY_HIGH,
                probe: $result->probe,
                summary: $result->probe->isTenantLess()
                    ? "{$uri} answers {$result->status} to a request that names no organisation; an unresolved tenant must be a 4xx."
                    : "{$uri} answers {$result->status} for a valid tenant.",
                evidence: ['status' => $result->status, 'variant' => $result->probe->variant],
            );
        }

        // A probe that never got a response cannot clear an endpoint of faulting.
        // 36 dead sockets used to print "no-server-fault :: pass" 23 times.
        $this->addCheck(
            $uri,
            'no-server-fault',
            $this->outcome($faulted, $complete, $reached && $answered > 0),
            $answered.'/'.count($results).' probe(s) answered'.
                ($reached ? '' : '; endpoint not reached')
        );
    }

    /**
     * Probe 3: whose rows came back.
     *
     * Two failures, both proof rather than inference: a body naming an
     * organisation the request did not ask for, and a tenant-less body naming
     * more than one organisation at once.
     *
     * @param  array<int,ProbeResult>  $results
     */
    private function checkBodyNamesRequestedTenantOnly(string $uri, array $results, bool $complete, bool $reached): void
    {
        $clean = true;
        $observed = false;

        foreach ($results as $result) {
            if ($result->masjidIds === []) {
                continue;
            }

            $observed = true;

            if ($result->probe->tenantId !== null) {
                $foreign = array_values(array_diff($result->masjidIds, [$result->probe->tenantId]));

                if ($foreign !== []) {
                    $clean = false;

                    $this->addFinding(
                        kind: 'foreign_tenant_in_body',
                        severity: self::SEVERITY_CRITICAL,
                        probe: $result->probe,
                        summary: "{$uri} asked for masjid {$result->probe->tenantId} and the body names ".
                            implode(', ', $foreign).'.',
                        evidence: [
                            'requested' => $result->probe->tenantId,
                            'returned' => $result->masjidIds,
                            'foreign' => $foreign,
                        ],
                    );
                }

                continue;
            }

            if (count($result->masjidIds) > 1) {
                $clean = false;

                $this->addFinding(
                    kind: 'cross_tenant_body',
                    severity: self::SEVERITY_CRITICAL,
                    probe: $result->probe,
                    summary: "{$uri} answered a request naming no organisation with rows from ".
                        count($result->masjidIds).' organisations ('.implode(', ', $result->masjidIds).').',
                    evidence: ['returned' => $result->masjidIds],
                );
            }
        }

        // `$observed` was computed here and never used, so this check reported
        // `pass` — "no masjid_id seen" — for all 8 /api/v1 endpoints on every
        // run since it was written. Every V1 Resource strips `masjid_id`, so it
        // was structurally incapable of ever seeing anything on that surface,
        // and it said `ok` in the table 8 times an hour anyway.
        //
        // It is now `skipped` there, permanently and visibly, which is what made
        // it obvious that /api/v1 needed a detector that does not depend on the
        // body naming its own tenant. See checkTenantsGetDistinctAnswers().
        $this->addCheck(
            $uri,
            'body-names-only-the-requested-tenant',
            $this->outcome(! $clean, $complete, $reached && $observed),
            $observed
                ? 'masjid_id present in body'
                : 'BLIND — no masjid_id in any body (the resource strips it, or there were no rows)'
        );
    }

    /**
     * Probe 1: the fail-open shape, and the arithmetic that proves it.
     *
     * @param  array<int,Probe>  $planned
     * @param  array<int,ProbeResult>  $results
     */
    private function checkTenantLessRefused(string $uri, array $planned, array $results, bool $complete, bool $reached): void
    {
        $plannedTenantLess = count(array_filter($planned, static fn (Probe $p) => $p->isTenantLess()));

        $tenantResults = array_values(array_filter($results, static fn (ProbeResult $r) => $r->probe->variant === Probe::VARIANT_TENANT));
        $tenantLess = array_values(array_filter($results, static fn (ProbeResult $r) => $r->probe->isTenantLess()));

        if ($tenantLess === []) {
            // Either the tenant is a path segment (/api/mobile/masjids/{id}/…),
            // which has no tenant-less spelling at all, or the budget cut the
            // falsy-header probes before they were sent. Neither is a pass.
            $this->addCheck($uri, 'tenant-less-request-refused', 'skipped', $plannedTenantLess === 0
                ? 'n/a — tenant is a path segment'
                : 'not reached — 0 of '.$plannedTenantLess.' falsy-header variants sent');

            return;
        }

        $counts = array_values(array_filter(
            array_map(static fn (ProbeResult $r) => $r->recordCount, $tenantResults),
            static fn ($c) => $c !== null
        ));
        $maxTenantCount = $counts === [] ? null : max($counts);

        // A refusal is a 4xx, and ONLY a 4xx. This used to be `if (!
        // isSuccessful()) continue;` — which counted a 301 from the edge, a 404
        // from a misrouted origin, a 500, and a dead socket as "correctly
        // refused", i.e. as evidence FOR the platform. Those four say nothing
        // about whether the application refuses a tenant-less request; they say
        // the application was not asked.
        $failed = false;
        $refusals = 0;
        $unusable = [];

        foreach ($tenantLess as $result) {
            if (! $result->isSuccessful()) {
                if ($result->isClientError()) {
                    $refusals++;
                } else {
                    $unusable[] = $result->probe->variant.' => '.$result->statusLabel();
                }

                continue;
            }

            $failed = true;

            $proven = $maxTenantCount !== null
                && $result->recordCount !== null
                && $result->recordCount > $maxTenantCount;

            $tenantSummary = implode(', ', array_map(
                static fn (ProbeResult $r) => 'masjid '.$r->probe->tenantId.' => '.($r->recordCount ?? '?'),
                $tenantResults
            ));

            $this->addFinding(
                kind: $proven ? 'cross_tenant_rows' : 'fail_open',
                severity: $proven ? self::SEVERITY_CRITICAL : self::SEVERITY_HIGH,
                probe: $result->probe,
                summary: $proven
                    ? "{$uri} answered a request naming no organisation with {$result->recordCount} rows — more than any single tenant ({$tenantSummary}). This is a cross-tenant read."
                    : "{$uri} answered {$result->status} to a request naming no organisation; the contract is 400. This is the fail-open shape, whether or not there is data to leak yet.",
                evidence: array_filter([
                    'variant' => $result->probe->variant,
                    'status' => $result->status,
                    'unscoped_records' => $result->recordCount,
                    'count_basis' => $result->countBasis,
                    'per_tenant_records' => $tenantSummary,
                    'max_single_tenant' => $maxTenantCount,
                ], static fn ($v) => $v !== null),
            );
        }

        $this->addCheck(
            $uri,
            'tenant-less-request-refused',
            $this->outcome(
                $failed,
                $complete && count($tenantLess) === $plannedTenantLess,
                $reached && $refusals > 0,
            ),
            $refusals.'/'.$plannedTenantLess.' falsy-header variant(s) refused with 4xx'.
                ($unusable === [] ? '' : '; not a refusal: '.implode(', ', $unusable)).
                '; tenant counts: '.(($counts === []) ? 'uncountable' : implode('/', $counts))
        );
    }

    /**
     * Probe 4: two organisations, two answers — are they the same answer?
     *
     * ==========================================================================
     * THE LEAK THE OTHER THREE DETECTORS COMPOSE TO NOTHING AGAINST
     * ==========================================================================
     *
     * Measured on 2026-08-12. Change `SearchableTrait::scopeFilterByMasjid()` to
     * validate the `masjid-id` header and then ignore it — pinning every public
     * query to the lowest masjid id, which is exactly what a `where('masjid_id',
     * $someDefault)` typo or a mis-plumbed tenant resolver produces — and:
     *
     *     exit=0  status=clean  probes=36/36  findings=0  checks: 23 pass
     *
     * while every visitor to masjid 5's and masjid 13's websites reads MASJID 1's
     * announcements, services, pages and home feed, and their admins watch
     * content they published never appear.
     *
     * All three existing detectors are blind to it BY CONSTRUCTION, not by
     * accident:
     *
     *   - the fail-open check still sees a 400 on a tenant-less request, because
     *     the mutation kept the validation and dropped only the filter;
     *   - the body check has nothing to read, because every /api/v1 Resource
     *     strips `masjid_id`;
     *   - the count arithmetic compares tenant-less against tenant, and the
     *     tenant-less request is refused; the per-tenant counts are all EQUAL to
     *     each other, which is exactly what correct behaviour also looks like.
     *
     * ==========================================================================
     * WHAT IS STILL OBSERVABLE
     * ==========================================================================
     *
     * The canary knows which organisation it asked for, and it asked twice. When
     * identity is stripped from the body, the CONTENT still differs between two
     * real tenants even though the SHAPE does not — and the strongest form of
     * that is the primary key. In a shared-database tenancy `announcements.id`
     * is unique across all organisations, so if masjid A's answer and masjid B's
     * answer name the same `id` at the same key path, those are the same rows.
     * That is proof, not inference, and unlike a fingerprint comparison it also
     * catches a PARTIAL contamination — B's rows plus one of A's.
     *
     * ==========================================================================
     * WHAT THIS CANNOT SEE — read this before trusting a `pass`
     * ==========================================================================
     *
     *  1. **Two empty answers.** If both organisations return zero rows, there is
     *     nothing to compare and a pin is invisible. This is not a corner case:
     *     every S1-S4 vertical table has zero production rows today, so this
     *     check reports `skipped` for most of the new surface until real data
     *     lands. The check SAYS SO in its detail rather than passing — AND, since
     *     2026-08-12, records the endpoint in `$blindDetectors`, because saying
     *     so only in a table cell left the run-level verdict unable to tell a
     *     watched endpoint from an unwatched one. See applyComparisonFloor().
     *  2. **Rows with no `id` in the response.** A Resource that strips the
     *     primary key as well as `masjid_id` leaves only the whole-body
     *     fingerprint, which is reported one severity down because "identical
     *     content" is also what a legitimately non-tenant-scoped endpoint looks
     *     like.
     *  3. **A leak into a tenant the run did not compare.** Only `canary.tenants`
     *     organisations are probed (default 2, the lowest ids). A pin to one of
     *     THOSE is caught; content leaking to a third organisation's site is not
     *     observed here at all.
     *  4. **A single-tenant deployment**, or an endpoint where only one of the
     *     two probes answered 2xx. Reported `skipped`.
     *  5. **Row-identical-but-distinct records** — two organisations legitimately
     *     holding rows with the same ids cannot happen with a shared
     *     autoincrement, but an endpoint that embeds a GLOBAL list inside a
     *     tenant response would overlap legitimately. `canary.global_endpoints`
     *     is the escape hatch, and the finding names the exact key path so that
     *     judgement takes one look rather than an investigation.
     *
     * @param  array{global:bool,probes:array<int,Probe>}  $entry
     * @param  array<int,ProbeResult>  $results
     */
    /**
     * @param  array{buckets:array<string,array<string,mixed>>,contested:array<int,string>,declared:array<int,string>}  $analysis
     */
    private function checkTenantsGetDistinctAnswers(
        string $uri,
        array $entry,
        array $results,
        bool $complete,
        bool $reached,
        int $tenantsAvailable,
        array $analysis,
    ): void {
        $check = 'tenants-get-different-answers';

        if ($entry['global']) {
            // Not a blindness: an operator declared this endpoint cross-tenant
            // by design, so there is nothing here for the comparison to prove.
            // The declaration is the thing under review, not the run.
            $this->notApplicable($uri, $check,
                'n/a — declared cross-tenant by design in canary.global_endpoints');

            return;
        }

        if ($tenantsAvailable < 2) {
            // Also not a blindness. On a platform with one organisation there is
            // no second organisation to leak to, and no threshold can conjure
            // one; the run says so once, in `notes`, rather than reporting every
            // endpoint as a gap it could close.
            $this->notApplicable($uri, $check,
                'n/a — '.$tenantsAvailable.' organisation(s) available to compare, so no pair exists');

            return;
        }

        /** @var array<int,ProbeResult> $byTenant */
        $byTenant = [];

        foreach ($results as $result) {
            if ($result->probe->variant !== Probe::VARIANT_TENANT || $result->probe->tenantId === null) {
                continue;
            }

            if ($result->isSuccessful()) {
                $byTenant[$result->probe->tenantId] = $result;
            }
        }

        if (count($byTenant) < 2) {
            // One organisation answered and the other did not, on an endpoint
            // that IS reachable — `reachability()` needs only one 2xx anchor, so
            // this endpoint counts as reached and its comparison is dead. That
            // combination was invisible at the run level until this line: the
            // endpoint looked watched from every angle the report offered.
            $this->recordBlindComparison($uri, $check, $reached,
                'needs two organisations answering 2xx; got '.count($byTenant).
                ' — a pin to one tenant is not observable here');

            return;
        }

        // A CONTESTED bucket is one whose identity the answers themselves
        // contradicted — see analyseBuckets(). "The same ids at the same key
        // path" is proof only if that path is one organisation's collection,
        // and the contradiction is the evidence that it may instead be a
        // lookup list nobody owns. Measured 2026-08-13: this detector raised
        // `same_rows_served_to_two_tenants :: both answers contain categories
        // ids 1, 2, 3` against a perfectly scoped endpoint, alongside two
        // critical findings from the ownership assertion, and the only hatch
        // was `canary.global_endpoints`, which would have stopped watching the
        // endpoint's real rows as well.
        //
        // It goes BLIND rather than passing on whatever else it has: an
        // unidentifiable list is the one place a leak could be hiding here, so
        // "the bodies differed" is not a claim this endpoint has earned.
        if ($analysis['contested'] !== []) {
            $this->recordBlindComparison($uri, $check, $reached,
                'the identity of '.implode(', ', $analysis['contested']).' is contested — '.
                'those list(s) rest only on the route name and the answers contradict it, so shared '.
                'ids there are not proof. Declare a global lookup in canary.global_buckets, or fix the scope');

            return;
        }

        $failed = false;
        $observed = false;
        $details = [];
        $tenantIds = array_keys($byTenant);
        $declared = $analysis['declared'];

        for ($i = 0; $i < count($tenantIds); $i++) {
            for ($j = $i + 1; $j < count($tenantIds); $j++) {
                $left = $byTenant[$tenantIds[$i]];
                $right = $byTenant[$tenantIds[$j]];
                $pair = 'masjid '.$tenantIds[$i].' vs '.$tenantIds[$j];
                // null is "the shape carried no countable list", which is a
                // different fact from "the list was empty". Printing both as 0
                // would misreport the reason this check could not see anything.
                $counts = ($left->recordCount ?? 'uncountable').'/'.($right->recordCount ?? 'uncountable');

                // Declared global lists are excluded from the proof and from
                // the "did either answer carry ids" test, because two
                // organisations being handed the same lookup rows is what the
                // operator has said this list is FOR. The exclusion is named in
                // the detail rather than applied silently.
                $leftIds = array_diff_key($left->recordIds, array_flip($declared));
                $rightIds = array_diff_key($right->recordIds, array_flip($declared));

                $shared = [];

                foreach ($leftIds as $path => $ids) {
                    $overlap = array_values(array_intersect($ids, $rightIds[$path] ?? []));

                    if ($overlap !== []) {
                        $shared[$path] = $overlap;
                    }
                }

                if ($shared !== []) {
                    $failed = true;
                    $observed = true;

                    $this->addFinding(
                        kind: 'same_rows_served_to_two_tenants',
                        severity: self::SEVERITY_CRITICAL,
                        probe: $right->probe,
                        summary: "{$uri} served the SAME database rows to masjid {$tenantIds[$i]} and masjid ".
                            "{$tenantIds[$j]} — ".self::describeShared($shared).
                            '. Primary keys are unique across organisations, so this is one tenant reading another\'s content.',
                        evidence: [
                            'tenants' => [$tenantIds[$i], $tenantIds[$j]],
                            'shared_record_ids' => $shared,
                            'records' => [
                                $tenantIds[$i] => $left->recordCount,
                                $tenantIds[$j] => $right->recordCount,
                            ],
                            'reproduce_other_tenant' => $left->probe->curl((string) ($this->option('base-url')
                                ?: (config('canary.base_url') ?: config('app.url')))),
                        ],
                    );

                    continue;
                }

                if ($leftIds !== [] || $rightIds !== []) {
                    $observed = true;
                    $details[] = $pair.': no shared record ids ('.$counts.' records)'.
                        ($declared === []
                            ? ''
                            : ', excluding '.implode(', ', $declared).' (declared global in canary.global_buckets)');

                    continue;
                }

                // No primary keys anywhere in either body — the whole-body
                // fingerprint is all that is left, and the two directions of it
                // are NOT symmetric.
                //
                // DIFFERENT bodies are positive evidence and cost nothing: two
                // organisations demonstrably got two answers, so this endpoint
                // is not pinned to one of them. That is worth a `pass` whether
                // or not the answer contained countable rows — it is the only
                // coverage the singleton/map-shaped endpoints (`/api/v1/settings`,
                // `…/tv-config`, `…/app-config`) get at all.
                if ($left->fingerprint !== null
                    && $right->fingerprint !== null
                    && $left->fingerprint !== $right->fingerprint) {
                    $observed = true;
                    $details[] = $pair.': bodies differ ('.$counts.' records, no ids) — '.
                        'rules out a pin to one organisation, but a partial cross-tenant mix '.
                        'would not be visible here';

                    continue;
                }

                // IDENTICAL bodies are ambiguous, so this only speaks when both
                // answers actually CARRIED rows. Two empty envelopes are
                // byte-identical for every correctly scoped endpoint on the
                // platform — every S1-S4 table has zero production rows — and
                // alerting on those would be an hourly false alarm on every
                // vertical that has no data yet.
                if (($left->recordCount ?? 0) > 0 && ($right->recordCount ?? 0) > 0
                    && $left->fingerprint !== null) {
                    $failed = true;
                    $observed = true;

                    $this->addFinding(
                        kind: 'identical_body_served_to_two_tenants',
                        severity: self::SEVERITY_HIGH,
                        probe: $right->probe,
                        summary: "{$uri} returned byte-identical non-empty bodies to masjid {$tenantIds[$i]} ".
                            "and masjid {$tenantIds[$j]} ({$left->recordCount} records each, and the rows carry ".
                            'no id to compare). Either the query is not scoped to the requested organisation, '.
                            'or this endpoint is not tenant-scoped at all and belongs in canary.global_endpoints.',
                        evidence: [
                            'tenants' => [$tenantIds[$i], $tenantIds[$j]],
                            'records' => $left->recordCount,
                            'fingerprint' => substr((string) $left->fingerprint, 0, 16),
                        ],
                    );

                    continue;
                }

                $details[] = $pair.': BLIND — '.$counts.' records, no ids, and nothing in either '.
                    'body to tell one organisation\'s answer from another\'s';
            }
        }

        $detail = implode('; ', $details) ?: 'compared '.count($byTenant).' organisations';

        if (! $observed) {
            $this->recordBlindComparison($uri, $check, $reached, $detail);

            return;
        }

        $this->addCheck($uri, $check, $this->outcome($failed, $complete, $reached && $observed), $detail);
    }

    // ----------------------------------------------------- bucket analysis

    /**
     * What each LIST in this endpoint's answers is, and whether the canary is
     * entitled to say so — computed once and read by both id-based detectors.
     *
     * ==========================================================================
     * WHY THE BUCKET AND NOT THE ENDPOINT IS THE UNIT
     * ==========================================================================
     *
     * Both detectors grade a whole ENDPOINT and both were wrong about it, in
     * opposite directions, measured on 2026-08-13.
     *
     * TOO GENEROUS. An answer shaped like the home feed — sibling lists under
     * one key, one correctly scoped and named after its table, the other a total
     * swap under a key no relation is named by (`featured`, `latest`, `news`,
     * `upcoming`):
     *
     *     {"announcements":[{"id":1},{"id":2}],  "featured":[{"id":3}]}   <- org B's row
     *
     *     exit=0 status=clean findings=0
     *       tenants-get-different-answers              pass  "no shared record ids (3/3 records)"
     *       answer-carries-the-requested-tenants-rows  pass  "masjid 1: 2 row(s) traced back to
     *                                                         it; masjid 2: 1 row(s); 0 ambiguous,
     *                                                         3 not in an attributable table"
     *       row_ownership: attributable=1 attributed=1 unattributed=[] met=true
     *
     * One anchored bucket carrying its own rows made the whole answer `pass` and
     * the whole endpoint `attributed`, INFLATING the coverage number that exists
     * to say how much of the surface a swap would show up on. The only trace of
     * the swapped list was the phrase "3 not in an attributable table" inside a
     * passing detail string. The shared-id comparison cannot see it either:
     * overlap is bucketed per key path and the swap is symmetric across buckets.
     * `/api/v1/home` already carries three buckets no relation is named by.
     *
     * TOO SUSPICIOUS. A perfectly scoped collection that also returns a global
     * lookup list — see anchorFor() for that measurement, three findings and a
     * page against correct code.
     *
     * Both are the same mistake: the identity of one LIST was decided at the
     * level of the whole endpoint. So every list is now graded on its own, and
     * the only thing that is still endpoint-level is the CONTRADICTION below,
     * which is a fact about an inherited anchor rather than about a list.
     *
     * ==========================================================================
     * STEP 4 — UNANIMITY AMONG THE BUCKETS THAT SHARE AN INHERITED ANCHOR
     * ==========================================================================
     *
     * Steps 1-3 are on checkAnswerCarriesRequestedTenantsRows(). This is the
     * fourth, and it is the same idiom as the third one level up: step 3
     * resolves "which TABLE is this id in" by unanimity and drops disagreement
     * rather than picking a winner; step 4 does it for "which table is this
     * LIST".
     *
     * Within ONE organisation's answer, the buckets attributed through the SAME
     * relation are claiming to be the same table read by the same request. One
     * request cannot have read that table both scoped and unscoped. So if some
     * of them carried the asking organisation's rows and others carried another
     * organisation's, at least one of those claims is false — and the ones
     * resting on an INHERITED anchor (the route's name, not their own) are the
     * ones with no direct evidence behind them. Those are CONTESTED: they
     * attribute nothing, they accuse nobody, they are not proof for the shared-id
     * comparison either, and they are named with both remedies.
     *
     * It is deliberately narrow, because the two cases it must not touch are the
     * two the canary exists for:
     *
     *  - A PIN (every organisation served one organisation's rows) makes one
     *    answer `own` and the other `foreign`. That is a disagreement ACROSS
     *    answers, not within one, and step 4 never looks across answers. The pin
     *    still exits 1.
     *  - A SWAP makes every bucket `foreign` in every answer — unanimous. Still
     *    exits 1, in both directions.
     *  - `/api/v1/pages/menu` returns `menu_items` and `button_items`, both
     *    pages, both inherited, and on a healthy platform both `own` —
     *    unanimous, so both stay attributed and the endpoint keeps its coverage
     *    credit.
     *
     * THE RESIDUAL, so nobody reads this as having closed the case: a lookup
     * list still accuses if NEITHER compared organisation owns a row in the
     * inherited table on that endpoint, because then there is no `own` verdict
     * to contradict the `foreign` one. It needs a third organisation to own the
     * colliding ids while both compared organisations own none, and the fix is
     * the declaration — `canary.global_buckets` — which the report names in
     * every detail string this produces.
     *
     * @param  array<int,ProbeResult>  $results
     * @param  array<string,mixed>  $config
     * @return array{buckets:array<string,array<string,mixed>>,contested:array<int,string>,declared:array<int,string>}
     */
    private function analyseBuckets(string $uri, array $results, array $config): array
    {
        /** @var array<int,ProbeResult> $answers */
        $answers = [];

        foreach ($results as $result) {
            if ($result->probe->variant === Probe::VARIANT_TENANT
                && $result->probe->tenantId !== null
                && $result->isSuccessful()) {
                $answers[$result->probe->tenantId] = $result;
            }
        }

        // Step 1, over BOTH organisations' answers: one bucket is one list from
        // one query, so the table that explains it is a property of the
        // endpoint, not of whichever organisation asked.
        $union = [];

        foreach ($answers as $result) {
            foreach ($result->recordIds as $path => $ids) {
                $union[$path] = array_values(array_unique(array_merge($union[$path] ?? [], $ids)));
            }
        }

        $buckets = [];

        foreach ($union as $path => $ids) {
            [$anchor, $candidates] = $this->anchorFor($uri, (string) $path, $config);

            $buckets[$path] = [
                'anchor' => $anchor,
                // Step 2: of the relations this bucket may be attributed
                // through, the ones that best explain its ids.
                'relations' => $anchor === 'declared' ? [] : $this->relationsExplaining($ids, $candidates),
                'ids' => $ids,
                'verdicts' => [],
                'rows' => [],
                'contested' => false,
            ];
        }

        // Step 3, per bucket per organisation: who owns these rows, and only
        // where the surviving tables agree.
        foreach ($answers as $tenantId => $result) {
            foreach ($result->recordIds as $path => $ids) {
                if (! isset($buckets[$path]) || $buckets[$path]['anchor'] === 'declared') {
                    continue;
                }

                $mine = 0;
                $ambiguous = 0;
                $unknown = 0;
                /** @var array<int,array<int,int>> $foreign owner => ids */
                $foreign = [];

                foreach ($ids as $id) {
                    [$state, $owner] = $this->attribute($id, $buckets[$path]['relations']);

                    if ($state === 'unknown') {
                        $unknown++;

                        continue;
                    }

                    if ($state === 'ambiguous') {
                        $ambiguous++;

                        continue;
                    }

                    if ($owner === $tenantId) {
                        $mine++;

                        continue;
                    }

                    $foreign[$owner][] = $id;
                }

                $buckets[$path]['rows'][$tenantId] = [
                    'mine' => $mine,
                    'foreign' => $foreign,
                    'ambiguous' => $ambiguous,
                    'unknown' => $unknown,
                ];

                $buckets[$path]['verdicts'][$tenantId] = match (true) {
                    $foreign !== [] => 'foreign',
                    $mine > 0 => 'own',
                    default => 'unreadable',
                };
            }
        }

        // Step 4: unanimity among the buckets that share an inherited anchor,
        // WITHIN ONE ANSWER. Across answers is a different fact and must stay
        // one — a pin makes one organisation's answer `own` and the other's
        // `foreign`, and that has to keep exiting 1.
        foreach (array_keys($answers) as $tenantId) {
            $byRelation = [];

            foreach ($buckets as $path => $bucket) {
                $verdict = $bucket['verdicts'][$tenantId] ?? 'unreadable';

                if ($verdict === 'unreadable') {
                    continue;
                }

                foreach ($bucket['relations'] as $relation) {
                    $byRelation[$relation][$path] = $verdict;
                }
            }

            foreach ($byRelation as $paths) {
                if (count(array_unique(array_values($paths))) < 2) {
                    continue;
                }

                foreach (array_keys($paths) as $path) {
                    if ($buckets[$path]['anchor'] === 'uri') {
                        $buckets[$path]['contested'] = true;
                    }
                }
            }
        }

        return [
            'buckets' => $buckets,
            'contested' => array_keys(array_filter(
                $buckets,
                static fn (array $b) => $b['contested'] === true
            )),
            'declared' => array_keys(array_filter(
                $buckets,
                static fn (array $b) => $b['anchor'] === 'declared'
            )),
        ];
    }

    /**
     * Why one bucket could not be traced to an owner, in the words an operator
     * needs to decide what to do about it.
     *
     * A CONTESTED bucket is not described here: the contradiction is one fact
     * about two or more lists, and a clause per bucket would print the same
     * paragraph twice. The caller collects them into one sentence.
     *
     * @param  array<string,mixed>  $bucket
     */
    private function describeBucketGap(string $path, array $bucket): string
    {
        $ids = (array) $bucket['ids'];
        sort($ids);

        $shown = $path.' (ids '.implode(', ', array_slice($ids, 0, 5)).(count($ids) > 5 ? ', …' : '').')';

        if ($bucket['anchor'] === 'none') {
            return $shown.' — no canary.compare_by relation is named by the route or by this key path, '.
                'so nothing ties these ids to a table';
        }

        return $shown.' — anchored to '.implode('/', (array) $bucket['relations'] ?: ['nothing']).
            ' and no id in it resolved to an owner';
    }

    // ------------------------------------------------------- row ownership

    /**
     * Probe 5: are the rows masjid A was handed actually MASJID A's ROWS?
     *
     * ==========================================================================
     * THE LEAK PROBE 4 COMPOSES TO NOTHING AGAINST: THE SWAP
     * ==========================================================================
     *
     * Probe 4 asks whether two organisations got DIFFERENT answers. It never
     * asks whose rows are in either of them, and those are not the same
     * question. Measured on this branch, an `/api/v1` endpoint serving masjid 1
     * masjid 2's announcements and masjid 2 masjid 1's — a complete cross-tenant
     * read of every announcement on the platform:
     *
     *     exit=0  status=clean  findings=[]
     *     origin-answered                       pass
     *     no-server-fault                       pass
     *     body-names-only-the-requested-tenant  skipped   BLIND — no masjid_id in any body
     *     tenants-get-different-answers         pass      "no shared record ids (1/2 records)"
     *     tenant-less-request-refused           pass
     *
     * Every detector agreed, and every one of them was answering a question the
     * bug does not touch. Worse than a blind spot: the endpoint counted as
     * `compared` in applyComparisonFloor(), so it INFLATED the arithmetic that
     * exists to say how much of the surface is actually watched, while its own
     * comparison was actively wrong.
     *
     * The four negative detectors all share one premise — that the answer
     * carries its own identity. On `/api/v1` it does not: every V1 Resource
     * strips `masjid_id`, a fact `checkBodyNamesRequestedTenantOnly()` reports
     * as permanently BLIND there. A fix that reads the body harder would have
     * found nothing in the run above.
     *
     * ==========================================================================
     * WHERE THE IDENTITY ACTUALLY IS
     * ==========================================================================
     *
     * In the database, which this command already reads — `resolveTenants()`
     * ranks organisations by counting their rows. So: take the primary keys the
     * API handed back, and ask the database who owns them. `announcements.id`
     * is unique across organisations in a shared-database tenancy and
     * `announcements.masjid_id` says whose it is, whatever the Resource chose to
     * emit. That turns the question from "are these two answers different?" —
     * which a swap satisfies perfectly — into "does masjid A's answer contain
     * masjid A's rows?", which a swap fails on both sides at once.
     *
     * This is the one detector on this surface that is POSITIVE. Every other
     * one passes by failing to find something.
     *
     * ==========================================================================
     * WHICH TABLE AN ID BELONGS TO — THE ONE HARD PART
     * ==========================================================================
     *
     * A body says `items: [{id: 3, …}]`. It does not say which table row 3 is,
     * and `announcements.id = 3` and `services.id = 3` are different rows that
     * may belong to different organisations. Guessing wrong in the accusing
     * direction manufactures a leak out of correct behaviour, which is the one
     * mistake this file treats as worse than missing one.
     *
     * That is not hypothetical here. The first cut of this check scored the
     * candidate tables purely by how many of a bucket's ids they contained, and
     * a healthy `--all` run went RED: `GET /api/mobile/masjids/2` returns the
     * organisation's OWN row, so `(root)` carried the id `2` — which is also
     * `announcements.id = 2`, a row belonging to masjid 1. Three tables agreed
     * that row 2 was masjid 1's, unanimously and wrongly, and the canary
     * accused a correct endpoint. Any id-only rule has this shape: `/signage`
     * returns broadcast ids, `/events` returns event ids, and both collide with
     * announcement ids from the first row onwards.
     *
     * So attribution is decided in three steps, and every one of them fails
     * towards "unknown":
     *
     *  1. **The endpoint has to NAME the table.** A bucket may only be
     *     attributed through a `compare_by` relation whose name appears as a
     *     segment of the route URI or as the bucket's own key path.
     *     `api/v1/announcements` and `api/v1/__anything/announcements` name
     *     `announcements`; `/api/v1/home` names nothing but its buckets are
     *     literally `services` and `announcements`; `/api/mobile/masjids/{id}`,
     *     `…/signage` and `/api/v1/settings` name nothing at all and are
     *     therefore never attributed. This is the anchor — without it the ids
     *     are being matched against tables the endpoint has no relationship to,
     *     which is not evidence, it is a coincidence with a schema.
     *  2. **Which of those explains this bucket.** Ids are bucketed by the key
     *     path of the LIST they came from (ResponseFacts::recordIdentity), and a
     *     bucket is one list from one query — the same table for BOTH
     *     organisations. The named candidates are scored against the union of
     *     both answers' ids, and only the highest scorers survive.
     *  3. **Unanimity among the survivors.** On a small or freshly seeded
     *     database several tables tie (ids 1,2,3 exist in all of them), so an id
     *     is attributed only when every surviving table that HAS a row with that
     *     key agrees on the owner. Disagreement is `ambiguous` and is dropped,
     *     counted, and reported — never resolved by picking one.
     *
     * Steps 2 and 3 cover each other: 2 is decisive on production-shaped data
     * where 3 would go ambiguous, 3 carries small datasets where 2 ties. Step 1
     * is what makes either of them evidence rather than arithmetic.
     *
     * ==========================================================================
     * WHAT THIS CANNOT SEE
     * ==========================================================================
     *
     *  1. **An answer with no rows.** Nothing to attribute — the C2 residual: an
     *     endpoint pinned to a DORMANT organisation answers everyone with an
     *     empty list, and an empty list has no owner. Reported as unattributed,
     *     which is the truth; no assertion over an empty body can close it.
     *  2. **Endpoints that do not name their table.** `/api/v1/settings` returns
     *     the organisation's own row under `masjid.id` and no list at all;
     *     `/api/mobile/masjids/{id}/signage` returns broadcasts, which no
     *     `compare_by` relation covers. A swap on either is still invisible.
     *     Widening `compare_by` is the lever where the table exists as a Masjid
     *     relation, and the endpoints that need it are named in
     *     `coverage.row_ownership.unattributed` rather than left to be inferred.
     *  3. **A global list embedded in a tenant response.** If an endpoint
     *     legitimately returns rows nobody owns, and their ids collide with rows
     *     another organisation owns in an attributable table, this used to
     *     report a leak that is not one — three findings and a page against
     *     correct code, measured 2026-08-13 and reproduced by
     *     `a_global_lookup_list_beside_a_scoped_one_is_not_a_leak`. Step 4
     *     (analyseBuckets()) now withdraws the inherited anchor when the answer
     *     contradicts it, and `canary.global_buckets` is the declaration that
     *     resolves it for good WITHOUT turning the endpoint off — which is what
     *     `canary.global_endpoints`, the only hatch there used to be, does. The
     *     residual is on analyseBuckets().
     *  4. **A list whose ids it cannot place at all.** `featured`, `latest`,
     *     `news` — a bucket no relation is named by. Nothing here will guess
     *     that `featured` is announcements from the ids alone; guessing exactly
     *     that from ids alone is what turned a healthy `--all` run red in round
     *     three. The bucket is NAMED, with its ids, the endpoint loses its
     *     attribution credit, and a swap hiding there is a reported gap rather
     *     than a silent `pass`.
     *
     * @param  array{global:bool,probes:array<int,Probe>}  $entry
     * @param  array<int,ProbeResult>  $results
     * @param  array{buckets:array<string,array<string,mixed>>,contested:array<int,string>,declared:array<int,string>}  $analysis
     */
    private function checkAnswerCarriesRequestedTenantsRows(
        string $uri,
        array $entry,
        array $results,
        bool $complete,
        bool $reached,
        array $analysis,
    ): void {
        $check = 'answer-carries-the-requested-tenants-rows';

        if ($entry['global']) {
            // Rows nobody owns, by declaration. Asserting ownership over the
            // masjid directory or the azkar library would demand a property the
            // operator has said is not true of it.
            $this->addCheck($uri, $check, 'skipped',
                'n/a — declared cross-tenant by design in canary.global_endpoints');

            return;
        }

        if ($this->ownership === []) {
            $this->addCheck($uri, $check, 'skipped',
                'n/a — no relation in canary.compare_by can attribute a row to an organisation');

            return;
        }

        /** @var array<int,ProbeResult> $answers */
        $answers = [];

        foreach ($results as $result) {
            if ($result->probe->variant !== Probe::VARIANT_TENANT || $result->probe->tenantId === null) {
                continue;
            }

            if ($result->isSuccessful()) {
                $answers[$result->probe->tenantId] = $result;
            }
        }

        if ($answers === []) {
            $this->addCheck($uri, $check, 'skipped',
                'no valid-tenant answer to attribute'.($reached ? '' : '; endpoint not reached'));

            return;
        }

        $buckets = $analysis['buckets'];

        $failed = false;
        $ambiguous = 0;
        $unknown = 0;
        $unknownInTraced = 0;
        $details = [];
        /** @var array<string,true> $traced buckets that produced a usable verdict */
        $traced = [];

        foreach ($answers as $tenantId => $result) {
            $mine = 0;
            /** @var array<int,array<string,array<int,int>>> $foreign owner => path => ids */
            $foreign = [];

            foreach (array_keys($result->recordIds) as $path) {
                $bucket = $buckets[$path] ?? null;

                // A declared global list is not this organisation's rows by
                // declaration; a contested one is a list the canary cannot
                // identify. Neither may attribute and neither may accuse.
                if ($bucket === null || $bucket['anchor'] === 'declared' || $bucket['contested'] === true) {
                    continue;
                }

                $rows = $bucket['rows'][$tenantId] ?? null;

                if ($rows === null) {
                    continue;
                }

                $mine += $rows['mine'];
                $unknown += $rows['unknown'];

                foreach ($rows['foreign'] as $owner => $ids) {
                    $foreign[$owner][$path] = array_merge($foreign[$owner][$path] ?? [], $ids);
                }

                if (($bucket['verdicts'][$tenantId] ?? 'unreadable') === 'unreadable') {
                    // An id this bucket could not place is described by the NOT
                    // TRACED clause below, with its bucket and its ids. Counting
                    // it in the running totals as well would report the same gap
                    // twice and, worse, under the wrong sentence — "in a traced
                    // list" is exactly what it is not.
                    continue;
                }

                $traced[$path] = true;
                $ambiguous += $rows['ambiguous'];
                $unknownInTraced += $rows['unknown'];
            }

            if ($foreign !== []) {
                $failed = true;

                $this->addFinding(
                    kind: 'foreign_rows_for_requested_tenant',
                    severity: self::SEVERITY_CRITICAL,
                    probe: $result->probe,
                    summary: "{$uri} answered masjid {$tenantId} with rows the database says belong to ".
                        implode(' and ', array_map(
                            static fn (int $owner) => 'masjid '.$owner,
                            array_keys($foreign)
                        )).' — '.self::describeOwned($foreign).'. '.
                        ($mine === 0
                            ? 'Not one row in that answer belongs to the organisation the request named.'
                            : $mine.' row(s) in the same answer do belong to masjid '.$tenantId.
                                ', so this is a partial cross-tenant read.'),
                    evidence: [
                        'requested' => $tenantId,
                        'foreign_rows' => $foreign,
                        'own_rows' => $mine,
                        // WHICH tables the accusation rests on, so the judgement
                        // is checkable in one look rather than an investigation.
                        'attributed_by' => $this->tablesUsed(
                            $buckets,
                            array_merge(...array_map('array_keys', array_values($foreign)))
                        ),
                    ],
                );

                $details[] = 'masjid '.$tenantId.': '.self::describeOwned($foreign).
                    ' — NOT this organisation\'s rows'.($mine > 0 ? ' ('.$mine.' of its own alongside)' : '');

                continue;
            }

            $details[] = 'masjid '.$tenantId.': '.$mine.' row(s) traced back to it';
        }

        // ...and now the half that used to be absorbed into the sentence above.
        // Every list the run could NOT place is named here, by path and by id,
        // and the endpoint does not get its coverage credit while one is
        // outstanding. See analyseBuckets() for the measurement.
        $untraced = [];
        $contested = [];

        foreach ($buckets as $path => $bucket) {
            if ($bucket['anchor'] === 'declared' || isset($traced[$path])) {
                continue;
            }

            if ($bucket['contested'] === true) {
                // One contradiction, however many lists it took to make it. A
                // clause per bucket would print the same paragraph twice.
                $contested[] = (string) $path;

                continue;
            }

            $untraced[$path] = $this->describeBucketGap((string) $path, $bucket);
        }

        if ($contested !== []) {
            $untraced['contested'] = implode(', ', $contested).' rest only on the route name and the '.
                'answer contradicts it — lists attributed through the same table carried different '.
                'organisations\' rows, so at least one of them is not that table. Declare the global '.
                'lookup in canary.global_buckets, or fix the scope';
        }

        if ($analysis['declared'] !== []) {
            // Named on every run, including a passing one. A watch that was
            // narrowed by a declaration has to say so, or the declaration
            // becomes invisible the day after it is made.
            $details[] = 'excluded by declaration (canary.global_buckets): '.
                implode(', ', $analysis['declared']);
        }

        if ($ambiguous > 0) {
            $details[] = $ambiguous.' id(s) ambiguous between tables';
        }

        if ($unknownInTraced > 0) {
            // Ids inside a list that WAS placed and that still resolved to no
            // owner. It does not withhold the endpoint's credit — one odd id in
            // a paginated collection is not a coverage gap — but it is a row
            // this run said nothing about, and saying nothing quietly is the
            // habit this file is a reaction to.
            $details[] = $unknownInTraced.' id(s) in a traced list resolved to no owner';
        }

        if ($untraced !== []) {
            $details[] = 'NOT TRACED: '.implode('; ', $untraced);
        }

        // The endpoint is attributed only when something was traced AND nothing
        // was left untraced. Either half alone is how a leaking bucket rode in
        // on an attributed sibling's credit.
        $attributed = $traced !== [] && $untraced === [];
        $detail = implode('; ', $details);

        if (! $failed && $traced === []) {
            // Wording preserved: this is the state where the positive assertion
            // could not be made AT ALL on this endpoint, which is a different
            // sentence from "part of the answer was unreadable".
            $detail = 'BLIND — nothing in either answer could be traced to an owner ('.
                $ambiguous.' ambiguous, '.$unknown.' not in an attributable table'.
                ($buckets === [] ? ', no row ids at all' : '').')'.
                ($untraced === [] ? '' : '; '.implode('; ', $untraced));
        }

        $this->addCheck($uri, $check, $this->outcome($failed, $complete, $reached && $attributed), $detail);

        if (! $reached) {
            return;
        }

        // Counted as attributed whether it passed or FAILED — but only when the
        // WHOLE answer was traceable. The coverage question is "could this run
        // trace these rows to an owner at all", not "did it like the answer": an
        // endpoint that traced them to the WRONG organisation is the assertion
        // working, and calling that a coverage gap would report the detector as
        // asleep at the moment it spoke. An endpoint with an untraced list is a
        // different case, and it is the one measured on 2026-08-13: the
        // assertion spoke about part of the answer and the number said it had
        // covered all of it.
        if ($attributed) {
            $this->attributed[$uri] = $detail;

            return;
        }

        $this->unattributed[$uri] = $detail;

        // ...and WHICH KIND of unattributed, because the two are graded
        // differently and conflating them is what let a swap ride in at exit 0.
        //
        //   unplaced   ids came back and nothing could place them. A hole, and
        //              one with a one-line remedy: name the table in
        //              `canary.compare_by`, or the list in
        //              `canary.global_buckets`.
        //   nothing    the answer had no rows. A silence. No assertion over an
        //              empty body exists to be made, and nobody can make one
        //              exist tonight.
        if ($untraced !== []) {
            $this->unplaced[$uri] = 'NOT TRACED: '.implode('; ', $untraced);

            return;
        }

        $this->nothingToAttribute[$uri] = $detail;
    }

    /**
     * The distinct relations the accused buckets were attributed through.
     *
     * @param  array<string,array<string,mixed>>  $buckets
     * @param  array<int,string>  $paths
     * @return array<int,string>
     */
    private function tablesUsed(array $buckets, array $paths): array
    {
        $used = [];

        foreach ($paths as $path) {
            foreach ((array) ($buckets[$path]['relations'] ?? []) as $relation) {
                $used[$relation] = true;
            }
        }

        return array_keys($used);
    }

    /**
     * The relations a single piece of text — a route URI, or one bucket's key
     * path — names.
     *
     * Matching is exact after normalising separators, and unmatched is the
     * default. No singularisation, no prefix matching, no "close enough": an
     * accusation resting on a fuzzy name match is an accusation nobody can
     * check, and the cost of not matching is a bucket reported as unattributed,
     * which is the truth.
     *
     * @return array<int,string>
     */
    private function relationsNamedIn(string $text): array
    {
        $segments = array_map(
            static fn (string $segment) => Str::snake(str_replace('-', '_', trim($segment))),
            preg_split('#[/.]#', $text) ?: []
        );

        return array_values(array_filter(
            array_keys($this->ownership),
            static fn (string $relation) => in_array(Str::snake($relation), $segments, true)
        ));
    }

    /**
     * THE ANCHOR, per bucket — and it is now a two-tier thing, because merging
     * the tiers is what made the canary accuse a correctly scoped endpoint.
     *
     * ==========================================================================
     * WHAT IT USED TO DO, AND WHAT THAT COST
     * ==========================================================================
     *
     * The candidate set was the union of the URI's segments AND the bucket path's
     * segments, in one list. So EVERY bucket of `/api/v1/services` inherited the
     * `services` anchor whether it was services or not. Measured 2026-08-13 on a
     * perfectly scoped endpoint that also returns a global lookup list
     * (`categories`, `tags`, `types` — ids 1, 2, 3, the same three for every
     * organisation because nobody owns them):
     *
     *     exit=1 status=leak findings=3
     *       same_rows_served_to_two_tenants   :: both answers contain categories ids 1, 2, 3
     *       foreign_rows_for_requested_tenant :: masjid 1 … categories ids 3 (masjid 2)  [CRITICAL]
     *       foreign_rows_for_requested_tenant :: masjid 2 … categories ids 1, 2          [CRITICAL]
     *
     * Lookup ids 1, 2 and 3 exist in `services` as well, owned by two different
     * organisations, so the anchor pointed at a table and the scorer confirmed
     * the table had those rows. Both steps agreed, unanimously and wrongly —
     * the same failure as the `(root)` collision that turned a healthy `--all`
     * run red in round three, one level down.
     *
     * ==========================================================================
     * THE TWO TIERS
     * ==========================================================================
     *
     *   `path`  the bucket's OWN key path names the relation. `/api/v1/home`
     *           returns buckets literally called `announcements` and `services`.
     *           Direct evidence about THIS list.
     *   `uri`   only the route names it. That is evidence about what the
     *           ENDPOINT is for, INHERITED by a list that did not say what it
     *           is. It is real evidence and it carries real endpoints —
     *           `/api/v1/announcements` returns `items`, `/api/v1/pages/menu`
     *           returns `menu_items` AND `button_items` and both are pages — but
     *           it is one step weaker, and the weakness is exactly the room the
     *           lookup list slipped through.
     *
     * A bucket that names a relation itself never falls back to the route's: it
     * has said what it is. A bucket that names nothing inherits, and is marked
     * as having inherited, so relationsContested() can withdraw the inheritance
     * when the answer contradicts it.
     *
     * `declared` is the third state and is not evidence at all: an operator has
     * said in `canary.global_buckets` that this list is not about one
     * organisation. See declaredGlobalBuckets().
     *
     * @param  array<string,mixed>  $config
     * @return array{0:'path'|'uri'|'none'|'declared',1:array<int,string>}
     */
    private function anchorFor(string $uri, string $path, array $config): array
    {
        if (in_array($path, $this->declaredGlobalBuckets($uri, $config), true)) {
            return ['declared', []];
        }

        $direct = $this->relationsNamedIn($path);

        if ($direct !== []) {
            return ['path', $direct];
        }

        $inherited = $this->relationsNamedIn($uri);

        return $inherited === [] ? ['none', []] : ['uri', $inherited];
    }

    /**
     * The bucket key paths an operator has declared NOT to be about one
     * organisation, for this endpoint.
     *
     * The same declaration `canary.global_endpoints` makes, one level finer, and
     * the finer level is the whole point: a lookup list sits INSIDE an answer
     * whose other lists are the organisation's own rows, so silencing the
     * endpoint to silence the list stops watching the rows. Measured — that was
     * the only escape hatch available for the false accusation above.
     *
     * @param  array<string,mixed>  $config
     * @return array<int,string>
     */
    private function declaredGlobalBuckets(string $uri, array $config): array
    {
        $declared = (array) ($config['global_buckets'] ?? []);

        return array_values(array_filter(
            (array) ($declared[$uri] ?? []),
            static fn ($path) => is_string($path)
        ));
    }

    /**
     * Of the relations this endpoint names, the ones that best explain one
     * bucket of ids.
     *
     * Highest hit count wins, ties are all kept — a tie is a genuine ambiguity
     * about which table this bucket came from, and resolving it by preferring
     * one relation over another would be a coin toss dressed as evidence.
     *
     * @param  array<int,int>  $ids
     * @param  array<int,string>  $candidates
     * @return array<int,string>
     */
    private function relationsExplaining(array $ids, array $candidates): array
    {
        $wanted = array_flip($ids);
        $best = 0;
        $winners = [];

        foreach ($candidates as $relation) {
            $owners = $this->ownership[$relation] ?? [];
            $hits = count(array_intersect_key($wanted, $owners));

            if ($hits === 0) {
                continue;
            }

            if ($hits > $best) {
                $best = $hits;
                $winners = [$relation];

                continue;
            }

            if ($hits === $best) {
                $winners[] = $relation;
            }
        }

        return $winners;
    }

    /**
     * Who owns record `$id`, according to the tables still in the running for
     * this bucket — and only when they agree.
     *
     * @param  array<int,string>  $relations
     * @return array{0:'attributed'|'ambiguous'|'unknown',1:int|null}
     */
    private function attribute(int $id, array $relations): array
    {
        $owners = [];

        foreach ($relations as $relation) {
            $owner = $this->ownership[$relation][$id] ?? null;

            if ($owner !== null) {
                $owners[$owner] = true;
            }
        }

        if ($owners === []) {
            return ['unknown', null];
        }

        if (count($owners) > 1) {
            return ['ambiguous', null];
        }

        return ['attributed', (int) array_key_first($owners)];
    }

    /**
     * Learn, in one SELECT per attributable table, who owns every record id
     * this run was handed.
     *
     * ## Why this is a safe thing for a production canary to do
     *
     * It is SELECT-only, it reads primary keys the API has just published to an
     * anonymous caller, and it is bounded: one query per relation named in
     * `canary.compare_by`, over the distinct ids observed in the whole run,
     * capped at OWNERSHIP_MAX_IDS. It returns two integer columns and never the
     * rows themselves — this object ends up in a log line.
     *
     * The command's docblock says it makes exactly one kind of query of its own.
     * That has been two since the compared pair started being chosen by content
     * (`tenantsHoldingContent`), and it is three now. All three are SELECTs, all
     * three are per-run rather than per-probe.
     *
     * ## Which relations can attribute, and which are refused
     *
     * Only a has-one/has-many whose FOREIGN KEY is one of `canary.tenant_keys`.
     * That excludes `gallery`, which is `hasMany(Media::class, 'model_id')` —
     * its foreign key is a polymorphic owner id, so `model_id = 5` means "row 5
     * of some model", not "masjid 5". Attributing through it would read an
     * announcement's image as belonging to masjid 5 and report a leak that is
     * not one. It is still used to RANK the compared pair, where an over-count
     * costs nothing; it is refused here, where a wrong answer accuses somebody.
     *
     * A relation that cannot attribute is named in `coverage.row_ownership.
     * tables_skipped` with its reason, not dropped silently.
     *
     * @param  array<string,mixed>  $config
     * @param  array<string,array<int,ProbeResult>>  $results
     */
    private function resolveOwnership(array $config, array $results): void
    {
        $tenantKeys = (array) ($config['tenant_keys'] ?? ['masjid_id']);

        /** @var array<string,HasOneOrMany<covariant \Illuminate\Database\Eloquent\Model>> $usable */
        $usable = [];

        foreach ((array) ($config['compare_by'] ?? []) as $name) {
            if (! is_string($name) || ! method_exists(Masjid::class, $name)) {
                continue;
            }

            try {
                // noConstraints, or the relation carries `where masjid_id is
                // null` from the unsaved parent and matches nothing.
                $relation = Relation::noConstraints(static fn () => (new Masjid)->{$name}());

                if (! $relation instanceof HasOneOrMany) {
                    $this->ownershipSkipped[$name] = 'not a has-one/has-many relation, so it has no '.
                        'foreign key that names an organisation';

                    continue;
                }

                if (! in_array($relation->getForeignKeyName(), $tenantKeys, true)) {
                    $this->ownershipSkipped[$name] = 'keyed on `'.$relation->getForeignKeyName().
                        '`, which is not one of canary.tenant_keys — a value there does not name an organisation';

                    continue;
                }

                // Empty map rather than no map: the relation IS attributable,
                // this run just saw none of its rows, and the distinction is
                // what `tables` in the report means.
                $usable[$name] = $relation;
                $this->ownership[$name] = [];
            } catch (\Throwable $e) {
                // Same posture as tenantsHoldingContent(): a canary that dies
                // working out who owns a row is a canary that stopped watching.
                $this->ownershipSkipped[$name] = 'lookup failed: '.$e->getMessage();
            }
        }

        if ($usable === []) {
            return;
        }

        // Only ids the endpoint ANCHORS to a relation are looked up. That keeps
        // the production query to the rows this run could actually attribute
        // rather than every primary key the API happened to emit — and it means
        // an `IN` list bounded by the endpoints that name a table, not by the
        // size of the plan.
        $wanted = [];

        foreach ($results as $uri => $endpointResults) {
            foreach ($endpointResults as $result) {
                if ($result->probe->tenantId === null || ! $result->isSuccessful()) {
                    continue;
                }

                foreach ($result->recordIds as $path => $ids) {
                    [$anchor, $candidates] = $this->anchorFor((string) $uri, (string) $path, $config);

                    if ($anchor === 'declared') {
                        // An operator has said this list is not about one
                        // organisation. Not looked up at all — the production
                        // `IN` list gets shorter, which is the right direction
                        // for a query a canary issues against a live database.
                        continue;
                    }

                    foreach ($candidates as $relation) {
                        foreach ($ids as $id) {
                            $wanted[$relation][$id] = true;
                        }
                    }
                }
            }
        }

        foreach ($wanted as $name => $idSet) {
            $ids = array_keys($idSet);

            if (count($ids) > self::OWNERSHIP_MAX_IDS) {
                $this->notes[] = 'Row-ownership lookup on `'.$name.'` capped at '.self::OWNERSHIP_MAX_IDS.
                    ' of '.count($ids).' observed record ids; attribution there is partial.';
                $ids = array_slice($ids, 0, self::OWNERSHIP_MAX_IDS);
            }

            try {
                $relation = $usable[$name];
                $related = $relation->getRelated();
                $table = $related->getTable();
                $key = $related->getKeyName();
                $foreignKey = $relation->getForeignKeyName();

                // ->getQuery()->getQuery() is the base builder: it keeps the
                // relation's own constraints (a collection filter, a morph type)
                // and drops the model's global scopes on purpose. A soft-deleted
                // row that came back in a response still has an owner, and the
                // question here is who owns it, not whether it should have been
                // served.
                $rows = $relation->getQuery()->getQuery()
                    ->whereIn($table.'.'.$key, $ids)
                    ->get([$table.'.'.$key.' as canary_id', $table.'.'.$foreignKey.' as canary_owner']);

                foreach ($rows as $row) {
                    if ($row->canary_owner === null) {
                        // A genuinely unowned row proves nothing about tenancy.
                        continue;
                    }

                    $this->ownership[$name][(int) $row->canary_id] = (int) $row->canary_owner;
                }
            } catch (\Throwable $e) {
                unset($this->ownership[$name]);
                $this->ownershipSkipped[$name] = 'lookup failed: '.$e->getMessage();
            }
        }
    }

    /** @param array<int,array<string,array<int,int>>> $foreign */
    private static function describeOwned(array $foreign): string
    {
        $parts = [];

        foreach ($foreign as $owner => $paths) {
            foreach ($paths as $path => $ids) {
                $shown = array_slice($ids, 0, 5);

                $parts[] = $path.' ids '.implode(', ', $shown).(count($ids) > 5 ? ', …' : '').
                    ' (masjid '.$owner.')';
            }
        }

        return implode('; ', $parts);
    }

    /**
     * The comparison ran and could not tell the two organisations apart.
     *
     * Recorded as well as printed. `skipped` in a table cell is the honest
     * CHECK-level answer and round one got that right; what it did not do is
     * carry it up. Measured on 2026-08-12 against the real trait pinned to one
     * organisation: with both compared organisations dormant, this check printed
     * `--` for six of the seven comparable `/api/v1` endpoints and the run still
     * exited 0 `clean` with `blind_spots: []`, because only `notProbed` and
     * `unreached` fed the verdict. A structurally blind detector was invisible
     * to the exit code; only an unreachable endpoint was not.
     *
     * Guarded on `$reached` so a gap is counted once and in the right place: an
     * endpoint that answered nothing at all is already a blind SPOT, and
     * charging it again here would double-count the same silence.
     */
    private function recordBlindComparison(string $uri, string $check, bool $reached, string $detail): void
    {
        $this->addCheck($uri, $check, 'skipped', $detail);

        if ($reached) {
            $this->blindDetectors[$uri] = $detail;
        }
    }

    /**
     * The comparison does not apply here at all — and that is a fact about the
     * ENDPOINT or the PLATFORM, not about this run's coverage.
     *
     * Kept out of the floor's denominator in both directions. Counting a global
     * endpoint as "compared" would inflate the arithmetic with an endpoint the
     * comparison never looked at; counting it as "blind" would demand a
     * comparison that is meaningless by declaration, and a canary that reports
     * gaps it must not close is one that gets ignored.
     */
    private function notApplicable(string $uri, string $check, string $detail): void
    {
        $this->addCheck($uri, $check, 'skipped', $detail);

        $this->comparisonNotApplicable[$uri] = $detail;
    }

    /** @param array<string,array<int,int>> $shared */
    private static function describeShared(array $shared): string
    {
        $parts = [];

        foreach ($shared as $path => $ids) {
            $shown = array_slice($ids, 0, 5);

            $parts[] = $path.' ids '.implode(', ', $shown).(count($ids) > 5 ? ', …' : '');
        }

        return 'both answers contain '.implode(' and ', $parts);
    }

    // -------------------------------------------------------------- coverage

    /**
     * Turn "what did this run actually verify" into the run-level verdict.
     *
     * The four checks above answer questions about ENDPOINTS. This answers the
     * question about the RUN, which is the one an operator reads and the one
     * that used to lie — twice, in opposite directions.
     *
     * First it lied green: `clean` was the default whenever no finding was
     * raised and no budget was blown, so a canary that observed literally
     * nothing reported the same word as a canary that observed everything and
     * liked it. A run may only call itself clean if it can point at something it
     * saw, and that is the `reached`/`passed` floor below.
     *
     * Then it lied red. Every entry in `$unreached` set `incomplete`, with no
     * threshold, so ONE endpoint that 404s for a valid tenant — the ordinary
     * shape of an optional per-organisation record — produced the same status
     * and the same exit code as an origin that served the API not at all:
     *
     *     8 healthy + 1 optional 404   exit=2 incomplete reached=8/9 pass=29
     *     301 / 404 / dead origin      exit=2 incomplete reached=0/8 pass=0
     *
     * The second is a platform-wide emergency. The first is a Tuesday. An alarm
     * that fires on a Tuesday gets silenced, and then the emergency is unheard.
     *
     * The floor and the argument for its shape are in the class docblock, under
     * "THE COVERAGE FLOOR". In short: grade the surface that is probed IN FULL
     * every run and on which absence is never normal (`/api/v1`), not the whole
     * plan — because the rest of the plan is a slice chosen by the clock, and a
     * threshold whose answer depends on the hour is not a threshold. Require a
     * strict majority of it. Name every gap regardless, at every level.
     *
     * @param  array<string,array{global:bool,probes:array<int,Probe>}>  $plan
     * @param  array<string,mixed>  $config
     */
    private function assessCoverage(array $plan, array $config): void
    {
        $this->plannedEndpoints = count($plan);

        $probed = count($plan) - count($this->notProbed);
        $reached = $probed - count($this->unreached);

        // Every gap gets named before anything decides how loudly to say it.
        // `notProbed` and `unreached` are disjoint by construction — evaluate()
        // stops at the first and only then computes the second.
        foreach ($this->notProbed as $uri) {
            $this->blindSpots[$uri] = 'NOT PROBED — the run ended before it got here';
        }

        foreach ($this->unreached as $uri => $detail) {
            $this->blindSpots[$uri] = $detail;
        }

        // ANY blind spot degrades, with no threshold, and that asymmetry with
        // the comparison floor next door is deliberate — it is the one place in
        // this file where the two axes are graded differently on the same
        // surface, so it is argued from measurement rather than symmetry.
        //
        // The comparison floor needed a majority threshold because blindness is
        // the NORMAL state of a correct platform: measured on a healthy `--all`
        // run of this branch, 14 ungraded endpoints and 1 graded one
        // (`/api/v1/gallery`) are legitimately blind for two organisations with
        // no photos, events, funds or notifications. "Any blind detector is
        // amber" would be amber every night for a condition no operator can act
        // on.
        //
        // Unreachability is not that fact. On the same healthy `--all` run,
        // `endpoints_reached` is 31 of 31 and `blind_spots` is empty — the
        // expected value is zero, not a majority. `config/canary.php` used to
        // justify the ungraded surface by claiming a 404 for a valid tenant is
        // the correct answer for six /api/mobile singletons; re-derived against
        // the controllers on 2026-08-13 that is false — `MasjidsController::about`
        // and `::donationLink` answer 200 with `data: null`,
        // `SplashAnnouncementsController::current` answers 204,
        // `AppConfigController` answers 200, and `TvConfigController` and
        // `SignageController` findOrFail the MASJID rather than the singleton.
        // None of them 404s for an organisation that exists.
        //
        // So a threshold here would be calibrated against a failure mode this
        // API does not have. It would buy silence for the case that DOES happen
        // — one endpoint stops answering and therefore stops being watched for
        // tenancy — and would only speak for "most of /api/mobile is gone",
        // which every other monitor sees too. Exit 3 is a ticket, not a page;
        // that is the proportionate price for an endpoint that went dark.
        if ($this->unreached !== []) {
            $this->degrade('unreached_endpoints');
        }

        if ($this->notProbed !== []) {
            $this->degrade('endpoints_not_probed');
        }

        if ($probed > 0 && $reached === 0) {
            // The F1 shape: an origin that redirects everything, 404s
            // everything, or is not there. Every probe "completed", every check
            // found nothing, and nothing whatsoever was verified.
            $this->blocked = true;
            $this->errors[] = 'NOTHING WAS VERIFIED: no endpoint answered 2xx to a probe naming a real '.
                'organisation ('.$probed.' endpoint(s) probed). The canary did not reach this application — '.
                'check that CANARY_BASE_URL points at an origin that serves the API rather than redirecting '.
                'to one, and that the release is up.';
        }

        $passed = count(array_filter($this->checks, static fn (array $c) => $c['outcome'] === 'pass'));

        if ($passed === 0 && $this->checks !== []) {
            $this->blocked = true;
            $this->errors[] = 'NOTHING WAS VERIFIED: 0 of '.count($this->checks).' checks reported pass — '.
                'every one of them either could not look or had nothing to look at. A run that observed '.
                'nothing is not a clean run.';
        }

        $this->applyCoverageFloor($plan, $config);
        $this->applyComparisonFloor($plan, $config);
        $this->applyOwnershipFloor($plan, $config);
    }

    /**
     * Record WHY this run is partial, once per distinct reason.
     *
     * `partial` writes nothing to `errors` on purpose — that is what keeps it
     * from reading like a blocked run, and a test pins it — which left the
     * reason machine-unreadable except by re-deriving it from the coverage
     * arithmetic. Both halves of exit 3 log at `warning` by contract; this is
     * the field an alert rule routes them apart on.
     */
    private function degrade(string $reason): void
    {
        $this->degraded = true;

        if (! in_array($reason, $this->degradedBy, true)) {
            $this->degradedBy[] = $reason;
        }
    }

    /**
     * The endpoints the run-level verdict is computed on, and what to call them.
     *
     * @param  array<string,array{global:bool,probes:array<int,Probe>}>  $plan
     * @param  array<string,mixed>  $config
     * @return array{0:array<int,string>,1:string}
     */
    private function gradedSurface(array $plan, array $config): array
    {
        $corePrefixes = (array) ($config['core_prefixes'] ?? []);

        $core = array_values(array_filter(
            array_keys($plan),
            fn (string $uri) => $this->hasPrefix($uri, $corePrefixes)
        ));

        // No core surface in this plan means an operator narrowed the run away
        // from it. Then the plan IS what was asked for, and half of it is the
        // same argument applied to the same question.
        if ($core === []) {
            return [array_keys($plan), 'the whole plan (no core surface in it)'];
        }

        return [$core, implode(', ', array_map(static fn ($p) => (string) $p, $corePrefixes))];
    }

    /**
     * The majority floor, applied to the graded surface.
     *
     * @param  array<string,array{global:bool,probes:array<int,Probe>}>  $plan
     * @param  array<string,mixed>  $config
     */
    private function applyCoverageFloor(array $plan, array $config): void
    {
        [$graded, $surface] = $this->gradedSurface($plan, $config);

        $plannedGraded = count($graded);
        $reachedGraded = count(array_filter(
            $graded,
            fn (string $uri) => ! isset($this->blindSpots[$uri])
        ));

        // A STRICT majority — more than half, so the floor is `* 2 >` and the
        // loss is `* 2 <=`. At exactly half there is no majority either way, and
        // a tie must not be resolved in favour of the reassuring reading: a run
        // that saw exactly as much as it missed is not a partial all-clear.
        $lost = $plannedGraded > 0 && $reachedGraded * 2 <= $plannedGraded;

        $this->gradedSurface = [
            'surface' => $surface,
            'planned' => $plannedGraded,
            'reached' => $reachedGraded,
            'floor' => 'reached * 2 > planned — a strict majority; a tie is a loss',
            'met' => ! $lost,
        ];

        if ($lost) {
            $this->blocked = true;
            $this->errors[] = 'COVERAGE FLOOR: only '.$reachedGraded.' of '.$plannedGraded.
                ' endpoint(s) on the graded surface ('.$surface.') were reached — not a majority, so this '.
                'run describes less of the platform than it missed and cannot be read as a partial all-clear.';
        }
    }

    /**
     * The SECOND floor: of the graded endpoints this run reached, on how many
     * could it actually tell two organisations apart?
     *
     * ==========================================================================
     * WHY A REACHED ENDPOINT IS NOT NECESSARILY A WATCHED ONE
     * ==========================================================================
     *
     * `applyCoverageFloor()` asks whether the canary reached the application.
     * Reaching it is necessary and it is not sufficient, because on `/api/v1`
     * exactly one detector can see the leak that matters most — the pin, where
     * every organisation is served one organisation's rows. The fail-open check
     * sees a different bug (and stays green through a pin, by construction), and
     * the body scan is structurally blind on `/api/v1` because every V1 Resource
     * strips `masjid_id`. So when `tenants-get-different-answers` cannot
     * distinguish the two answers, that endpoint has stopped being a leak
     * detector while still reporting as reached.
     *
     * Measured on 2026-08-12, with the real `SearchableTrait::scopeFilterByMasjid`
     * mutated to validate the header and then pin every query to one organisation:
     *
     *     both compared orgs hold rows   exit 1  leak    5 x same_rows_served_to_two_tenants
     *     both compared orgs dormant     exit 0  clean   0 findings, blind_spots: []
     *
     * In the second run six of the seven comparable `/api/v1` endpoints printed
     * `--  BLIND — 0/0 records, no ids…` and the run-level consequence was nil.
     * That is the same class of bug as every other one this file was rebuilt to
     * close, one level up: the check level was honest and the verdict threw the
     * honesty away.
     *
     * ==========================================================================
     * THE THRESHOLD, AND WHY IT IS NOT "ANY BLIND ENDPOINT"
     * ==========================================================================
     *
     * An unreached endpoint degrades a run on its own. A blind comparison must
     * not, and the reason is measured rather than aesthetic: on a HEALTHY
     * platform with the fix in place, `/api/v1/gallery` is blind for any pair of
     * organisations that have not uploaded photos, and on `/api/mobile` a dozen
     * endpoints are blind for a pair that has no events, funds or notifications.
     * "Any blind endpoint is amber" would put a permanent amber on a correct
     * platform for a condition no operator can act on — a canary that goes amber
     * every night is one that gets silenced, which is the failure mode round one
     * was fixing.
     *
     * So it is a strict MAJORITY, the same shape and the same argument as the
     * coverage floor: the run's headline is "no cross-tenant leakage in what this
     * run could see", and once the comparison saw half or less of the endpoints
     * it reached, that sentence is reassurance about a surface whose only working
     * leak detector was mostly asleep. A tie is a loss, for the same reason as
     * over there: nothing about a run that saw exactly as much as it missed
     * should be resolved in favour of the reassuring reading.
     *
     * With the eight discovered `/api/v1` endpoints (one of them declared
     * global): seven comparable, so four compared holds the floor and three
     * loses it.
     *
     * ==========================================================================
     * WHY IT DEGRADES AND NEVER BLOCKS
     * ==========================================================================
     *
     * A lost comparison floor is `partial` (exit 3), never `incomplete` (exit 2),
     * and that is deliberate. Exit 2 means one thing operationally — "this run is
     * not evidence, go look at the canary and the release" — and it is the code
     * a dead origin, a 429 and a truncated run raise. A blind comparison is a
     * different fact: the canary reached the application, verified the fail-open
     * shape and the absence of 5xx, and found the two organisations had nothing
     * to tell apart. The remedy is a data or configuration decision (compare
     * organisations that hold content; accept that a vertical with no rows cannot
     * be watched yet), not a 3am one, and pushing it onto the page path would
     * teach an operator to ignore the code that also means "the origin is gone".
     *
     * The bottom is still guarded from underneath by `$passed === 0`: a run in
     * which no check at all reported `pass` is blocked whatever the reason.
     *
     * ==========================================================================
     * WHAT IS COUNTED
     * ==========================================================================
     *
     * The graded surface, as with the coverage floor, and for the identical
     * reasons — `/api/mobile` is a slice chosen by the clock (a threshold whose
     * answer depends on the hour is not a threshold) and much of it reads an
     * optional per-org singleton where an empty answer for both organisations is
     * the correct answer, not an erosion. Blindness there is still NAMED, in the
     * check table and as a count in `coverage`; it just does not decide the
     * verdict.
     *
     * Minus the endpoints this run could not reach (they are already blind SPOTS
     * and already counted once) and the ones declared cross-tenant by design in
     * `canary.global_endpoints` (a declaration to review, not a gap to close).
     *
     * @param  array<string,array{global:bool,probes:array<int,Probe>}>  $plan
     * @param  array<string,mixed>  $config
     */
    private function applyComparisonFloor(array $plan, array $config): void
    {
        [$graded, $surface] = $this->gradedSurface($plan, $config);

        $comparable = array_values(array_filter(
            $graded,
            fn (string $uri) => ! isset($this->blindSpots[$uri])
                && ! isset($this->comparisonNotApplicable[$uri])
        ));

        $blind = [];

        foreach ($comparable as $uri) {
            if (isset($this->blindDetectors[$uri])) {
                $blind[$uri] = $this->blindDetectors[$uri];
            }
        }

        $compared = count($comparable) - count($blind);
        $lost = $comparable !== [] && $compared * 2 <= count($comparable);

        $this->comparison = [
            'surface' => $surface,
            'comparable' => count($comparable),
            'compared' => $compared,
            'blind' => $blind,
            // Named separately rather than listed: on a --all run a dozen mobile
            // endpoints are legitimately blind, and burying the graded ones in
            // that list is how the number that matters stops being read.
            'blind_outside_graded_surface' => count($this->blindDetectors) - count($blind),
            'floor' => $comparable === []
                // Vacuous rather than met. It happens on a single-organisation
                // platform (no pair exists to compare) and on a run that reached
                // nothing (already blocked by the coverage floor); saying
                // "majority held" about an empty set would be the reassuring
                // reading of a run that compared nothing.
                ? 'n/a — no endpoint on the graded surface was comparable'
                : 'compared * 2 > comparable — a strict majority; a tie is a loss',
            'met' => ! $lost,
        ];

        if ($lost) {
            $this->degrade('comparison_floor');
        }
    }

    /**
     * The THIRD floor. Graded at zero on the wrong axis until 2026-08-13, which
     * meant R4's own new machinery could not fail a run.
     *
     * `checkAnswerCarriesRequestedTenantsRows()` is the only detector on
     * `/api/v1` that asserts anything POSITIVELY — that the rows masjid A was
     * handed are masjid A's rows — and the only one that sees a total swap. So
     * "on how many graded endpoints could this run make that assertion?" is a
     * real coverage question, reported at every verdict level in
     * `coverage.row_ownership`.
     *
     * ==========================================================================
     * WHAT THE OLD FLOOR MEASURED, AND WHY IT COULD NOT FIRE
     * ==========================================================================
     *
     * It was `attributed > 0`: any one graded endpoint traceable and the floor
     * held. Measured on this branch, a TOTAL SWAP served under a bucket no
     * relation is named by, beside one correctly scoped sibling endpoint:
     *
     *     LEAK4  exit=0  status=clean  findings=0  degraded_by=null
     *       answer-carries-the-requested-tenants-rows : skipped
     *          "NOT TRACED: featured (ids 1, 2, 3) — no canary.compare_by
     *           relation is named by the route or by this key path"
     *       row_ownership: attributable 2, attributed 1, met TRUE
     *
     * The check was honest, the payload named the bucket and its ids, and the
     * VERDICT threw it away — the identical shape as every defect this file has
     * been rebuilt to close, one level up. Thirty of thirty-one graded endpoints
     * could have been in that state and the run would still have logged `info`
     * and exited 0.
     *
     * ==========================================================================
     * THE THRESHOLD, AND WHY IT IS NOT A MAJORITY OF `attributed`
     * ==========================================================================
     *
     * The obvious repair is the strict majority the two floors above use. It is
     * wrong here, and the reason the previous author gave for refusing it was
     * half right: the endpoint list is DISCOVERED and it grows, `/api/v1/settings`
     * (the organisation's own row under `masjid.id`, no list at all) and a
     * photoless `/api/v1/gallery` are unattributed on a HEALTHY run, and a ratio
     * over all of them drifts into permanent amber as the route table grows.
     * That is the cry-wolf failure reached from the other end, and it is real.
     *
     * What that argument got wrong is that it treated one column as one fact.
     * `unattributed` is two facts with opposite remedies:
     *
     *   NOTHING TO ATTRIBUTE   the answer carried no rows. `/api/v1/settings`,
     *                          a dormant gallery, an organisation whose
     *                          announcements have expired. No assertion over an
     *                          empty body exists to be made and nobody can make
     *                          one exist tonight. A floor over this is the
     *                          permanent amber. NOT GRADED — named, counted, and
     *                          left alone.
     *   UNPLACED ROWS          real record ids were served to an anonymous
     *                          caller and nothing could place them: no
     *                          `compare_by` relation is named by the route or by
     *                          the key path, or the lists sharing an anchor
     *                          contradicted each other. GRADED AT ZERO. One is
     *                          enough.
     *
     * Zero on the second column is defensible where zero on the whole column was
     * not, for three reasons and all of them are about this codebase:
     *
     *  1. **It is the leak's own shape.** Every construction that reached exit 0
     *     through this detector — the swapped `featured` bucket, the total swap
     *     under an unanchored key — lands here and nowhere else. A floor that
     *     cannot fire on the fixture it was written for is decoration.
     *  2. **It is actionable tonight, in one line.** The payload names the
     *     endpoint, the bucket and the ids. The remedy is a relation in
     *     `canary.compare_by` or a bucket in `canary.global_buckets`, and it
     *     CLEARS — permanently, the next run. That is the property "no operator
     *     can act on tonight" was pointing at, and it is exactly inverted here.
     *  3. **It is growth-proof in the way that matters.** A new endpoint cannot
     *     lose this floor by existing; it loses it by SERVING ROWS nobody can
     *     trace. On a platform about to put a school's classrooms behind these
     *     routes, an endpoint that hands out rows no owner can be established
     *     for is precisely the thing that should cost a ticket the day it ships,
     *     not the day someone reads the coverage block.
     *
     * The cost is on the record rather than assumed. `canary.compare_by` is four
     * relations, three of which can attribute, so EVERY other table on the
     * platform is unanchored by construction — `coverage.row_ownership.
     * tables_available` now says how many Masjid relations could be named and
     * are not. A vertical shipping its first public read endpoint with rows in it
     * will lose this floor until its relation is named. That is a ticket, exit 3,
     * `warning`; it is not a page, and it is the true statement about what the
     * swap detector covers.
     *
     * ==========================================================================
     * ...AND THE ZERO FLOOR THAT WAS ALREADY HERE
     * ==========================================================================
     *
     * `row_ownership_dark` — not one graded endpoint attributed at all — is kept
     * unchanged. It is a different fact from an unplaced list (every compared
     * organisation dormant, `compare_by` misconfigured) and it still deserves
     * its own reason code, because its remedy is data rather than configuration.
     *
     * @param  array<string,array{global:bool,probes:array<int,Probe>}>  $plan
     * @param  array<string,mixed>  $config
     */
    private function applyOwnershipFloor(array $plan, array $config): void
    {
        [$graded, $surface] = $this->gradedSurface($plan, $config);

        $attributable = array_values(array_filter(
            $graded,
            fn (string $uri) => ! isset($this->blindSpots[$uri])
                && ! isset($this->comparisonNotApplicable[$uri])
        ));

        $attributed = [];
        $unattributed = [];
        $unplaced = [];
        $nothing = [];

        foreach ($attributable as $uri) {
            if (isset($this->attributed[$uri])) {
                $attributed[] = $uri;

                continue;
            }

            if (isset($this->unattributed[$uri])) {
                $unattributed[$uri] = $this->unattributed[$uri];
            }

            if (isset($this->unplaced[$uri])) {
                $unplaced[$uri] = $this->unplaced[$uri];

                continue;
            }

            if (isset($this->nothingToAttribute[$uri])) {
                $nothing[$uri] = $this->nothingToAttribute[$uri];
            }
        }

        $dark = $attributable !== [] && $attributed === [];

        $this->rowOwnership = [
            'surface' => $surface,
            'tables' => array_keys($this->ownership),
            'tables_skipped' => $this->ownershipSkipped,
            // Relations this Masjid HAS that could attribute a row and are not
            // named in `canary.compare_by`. The size of the unanchored surface,
            // and the one-line remedy for every entry in `unplaced` below.
            'tables_available' => $this->attributableRelationsNotConfigured($config),
            'attributable' => count($attributable),
            'attributed' => count($attributed),
            'unattributed' => $unattributed,
            // The graded half of `unattributed`: rows were served and nothing
            // could place them.
            'unplaced' => $unplaced,
            // ...and the ungraded half: there was nothing to place.
            'nothing_to_attribute' => $nothing,
            // Endpoints OFF the graded surface in the same state. Counted rather
            // than listed for the same reason blindness is: on a `--all` run the
            // whole of /api/mobile is unanchored, and burying the graded number
            // in that list is how the number that matters stops being read.
            'unplaced_outside_graded_surface' => count($this->unplaced) - count($unplaced),
            'floor' => $attributable === []
                ? 'n/a — no endpoint on the graded surface could be attributed'
                : 'unplaced === [] — zero rows served that nothing could place; and attributed > 0. '.
                    'Neither is a majority; see applyOwnershipFloor()',
            'met' => ! $dark && $unplaced === [],
        ];

        if ($dark) {
            $this->degrade('row_ownership_dark');
        }

        if ($unplaced !== []) {
            $this->degrade('row_ownership_unplaced');
        }
    }

    /**
     * Masjid relations that COULD attribute a row and are not named in
     * `canary.compare_by` — the size of the surface no swap detector reaches.
     *
     * `compare_by` is four names. Every other table on the platform is
     * unanchored BY CONSTRUCTION, and nothing in the payload said how many that
     * was; an operator reading `row_ownership: attributed 5 of 7` had no way to
     * see that the seven are seven endpoints backed by three tables out of
     * however many the model has.
     *
     * Read by reflection over `Masjid`'s own public methods, and it executes no
     * query: `Relation::noConstraints` builds the relation object without
     * touching the database, and only its foreign key name is read. A method
     * that needs arguments, throws, or is not a has-one/has-many keyed on a
     * `canary.tenant_keys` column is not a candidate and is passed over in
     * silence — a canary that dies taking its own inventory is a canary that
     * stopped watching.
     *
     * @param  array<string,mixed>  $config
     * @return array<int,string>
     */
    private function attributableRelationsNotConfigured(array $config): array
    {
        $configured = array_map('strval', (array) ($config['compare_by'] ?? []));
        $tenantKeys = (array) ($config['tenant_keys'] ?? ['masjid_id']);

        $available = [];

        try {
            $reflection = new \ReflectionClass(Masjid::class);
        } catch (\Throwable) {
            return [];
        }

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $name = $method->getName();

            if ($method->getDeclaringClass()->getName() !== Masjid::class
                || $method->isStatic()
                || $method->getNumberOfParameters() > 0
                || in_array($name, $configured, true)) {
                continue;
            }

            try {
                $relation = Relation::noConstraints(static fn () => (new Masjid)->{$name}());

                if ($relation instanceof HasOneOrMany
                    && in_array($relation->getForeignKeyName(), $tenantKeys, true)) {
                    $available[] = $name;
                }
            } catch (\Throwable) {
                // Not a relation, or not one that can be built without a saved
                // parent. Either way it is not a candidate.
                continue;
            }
        }

        sort($available);

        return $available;
    }

    /** @param array<int,string> $prefixes */
    private function hasPrefix(string $uri, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            $prefix = (string) $prefix;

            if ($uri === $prefix || str_starts_with($uri, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string,mixed> */
    private function coverage(): array
    {
        $outcomes = ['pass' => 0, 'skipped' => 0, 'FAIL' => 0];

        foreach ($this->checks as $check) {
            $outcomes[$check['outcome']] = ($outcomes[$check['outcome']] ?? 0) + 1;
        }

        return [
            'endpoints_planned' => $this->plannedEndpoints,
            'endpoints_probed' => $this->plannedEndpoints - count($this->notProbed),
            'endpoints_reached' => $this->plannedEndpoints - count($this->notProbed) - count($this->unreached),
            'endpoints_not_reached' => $this->unreached,
            'endpoints_not_probed' => array_values($this->notProbed),
            // Public GET routes the CATALOGUE refused, with the reason. Not a
            // gap in this run — a gap in what any run looks at — so it changes
            // no verdict and appears at every one of them. Before 2026-08-13 a
            // refused route was in the plan, in `blind_spots`, in coverage and
            // in the verdict exactly nowhere, and `clean` sat next to
            // `blind_spots: []` while six public GETs went unlooked-at.
            'routes_not_planned' => $this->routesNotPlanned,
            // Called out separately because these are the ones on the surface
            // the verdict claims to cover. A `{slug}` route under `api/v1` is a
            // hole in the graded surface's own arithmetic, which counts only
            // what got planned.
            'routes_not_planned_on_graded_surface' => count(array_filter(
                array_keys($this->routesNotPlanned),
                fn (string $uri) => $this->hasPrefix($uri, (array) config('canary.core_prefixes', []))
            )),
            // ...and the boundary OUTSIDE that boundary. `routes_not_planned` is
            // eight URIs and reads like the edge of the map; it is eight of 333
            // routes no run will ever probe, because `ProbeCatalog::scan()`
            // dropped a non-GET and a credentialed route into neither list and
            // `canary.prefixes` excluded 313 more before any predicate ran.
            // Every defect rounds one to three shipped lives on those routes.
            'route_table' => $this->routeTable,
            // What the detectors themselves cannot see, whatever the coverage
            // arithmetic says. Standing properties of this implementation, on
            // every run at every verdict, because a canary that lists its
            // blind endpoints and not its blind DETECTORS is still selling a
            // narrower claim than it prints.
            'detector_limits' => self::DETECTOR_LIMITS,
            // The arithmetic the verdict was actually computed from. Without it
            // an operator reading `partial` has to re-derive which endpoints
            // counted toward the floor and which could never have.
            'graded_surface' => $this->gradedSurface,
            // ...and the second floor. "Reached" says the canary got an answer;
            // this says whether the answer could be told from another
            // organisation's, which is the only question that catches a pin.
            'cross_tenant_comparison' => $this->comparison,
            // ...and the third. Telling two answers apart is not the same as
            // knowing whose rows are in either of them — a total swap satisfies
            // the second and fails this one. Reported at every verdict level,
            // graded only at zero.
            'row_ownership' => $this->rowOwnership,
            'checks' => $outcomes,
        ];
    }

    // ---------------------------------------------------------------- report

    /** @param array<string,mixed> $evidence */
    private function addFinding(string $kind, string $severity, Probe $probe, string $summary, array $evidence): void
    {
        $this->findings[] = [
            'severity' => $severity,
            'kind' => $kind,
            'endpoint' => $probe->endpoint,
            'summary' => $summary,
            'evidence' => $evidence,
            'request' => $probe,
        ];
    }

    /** @param 'pass'|'FAIL'|'skipped' $outcome */
    private function addCheck(string $endpoint, string $check, string $outcome, string $detail): void
    {
        $this->checks[] = [
            'endpoint' => $endpoint,
            'check' => $check,
            'outcome' => $outcome,
            'detail' => $detail,
        ];
    }

    /** @param array<int,int> $tenants */
    private function report(
        Carbon $startedAt,
        float $started,
        string $baseUrl,
        string $transport,
        array $tenants,
        int $sent,
        int $planned,
    ): int {
        // Four states, in strict precedence. Findings outrank everything — a
        // leak the run REACHED must page someone even if the run was also
        // truncated and half blind. `incomplete` outranks `partial`, because
        // "this run is not evidence" is a stronger statement than "this run is
        // evidence about most of it". `clean` is the narrowest and the only one
        // that has to be earned outright: total coverage, nothing skipped at the
        // endpoint level, at least one check satisfied.
        $status = match (true) {
            $this->findings !== [] => 'leak',
            $this->blocked => 'incomplete',
            $this->degraded => 'partial',
            default => 'clean',
        };

        $exit = match ($status) {
            'leak' => self::EXIT_LEAK,
            'incomplete' => self::EXIT_INCOMPLETE,
            'partial' => self::EXIT_PARTIAL,
            default => self::EXIT_CLEAN,
        };

        $payload = [
            'command' => 'tenancy:canary',
            'status' => $status,
            'started_at' => $startedAt->toIso8601String(),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'base_url' => $baseUrl,
            'transport' => $transport,
            'tenants' => $tenants,
            'probes' => ['planned' => $planned, 'sent' => $sent],
            'coverage' => $this->coverage(),
            'checks' => $this->checks,
            'findings' => array_map(function (array $finding) use ($baseUrl) {
                /** @var Probe $probe */
                $probe = $finding['request'];
                $finding['request'] = $probe->toArray($baseUrl);

                return $finding;
            }, $this->findings),
            'errors' => $this->errors,
            // Kept separate from `errors` on purpose: an error is something that
            // stopped the run, a blind spot is something the run could not see.
            // The verdict is computed from the second and an operator triaging a
            // `partial` needs it as a list, not as prose to be grepped.
            'blind_spots' => $this->blindSpots,
            // Why this run is `partial`, in one field. A partial run writes
            // nothing to `errors` by design, so without this an alert rule had
            // to re-derive "an endpoint went dark" from "the leak detector is
            // asleep" out of the coverage arithmetic. Empty on every other
            // status.
            'degraded_by' => $this->degradedBy,
            'notes' => $this->notes,
        ];

        $this->log($status, $payload);

        if ($this->option('json')) {
            $this->output->writeln((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $exit;
        }

        $this->renderHuman($payload, $baseUrl);

        return $exit;
    }

    /** @param array<string,mixed> $payload */
    private function log(string $status, array $payload): void
    {
        $channel = Log::channel(config('canary.log_channel'));

        $summary = [
            'status' => $status,
            'transport' => $payload['transport'],
            'tenants' => $payload['tenants'],
            'probes' => $payload['probes'],
            // Probe counts alone were what made a green line believable: 36/36
            // reads like coverage and is not. The coverage block is what says
            // how much of that run was actually an observation.
            'coverage' => $payload['coverage'],
            'findings' => count($payload['findings']),
            'errors' => $payload['errors'],
            'blind_spots' => $payload['blind_spots'],
            // The level says WHICH of the four states this run is in; this says
            // which half of exit 3 it is, without an alert rule having to parse
            // the coverage block to find out.
            'degraded_by' => $payload['degraded_by'],
        ];

        if ($status === 'clean') {
            // One line an hour, so "the canary stopped running" is a question
            // the log can answer. schedule:run discards stdout.
            $channel->info('tenancy:canary clean', $summary);

            return;
        }

        if ($status === 'partial') {
            // The quieter half of the fix, and in practice the half that works
            // TODAY: schedule:run discards stdout, so for a scheduled run this
            // log line IS the alert path, and a level is something an alerting
            // rule can already route on without anyone editing the schedule.
            // `error` here is what made one missing optional record page at the
            // same volume as a dead origin.
            $channel->warning('tenancy:canary partial', $summary);

            return;
        }

        $channel->error('tenancy:canary '.$status, $summary + [
            'detail' => array_map(static fn (array $f) => [
                'severity' => $f['severity'],
                'kind' => $f['kind'],
                'endpoint' => $f['endpoint'],
                'summary' => $f['summary'],
                'reproduce' => $f['request']['reproduce'] ?? null,
            ], $payload['findings']),
        ]);
    }

    /** @param array<string,mixed> $payload */
    private function renderHuman(array $payload, string $baseUrl): void
    {
        $this->line('');
        $this->line("<options=bold>tenancy:canary</> — {$payload['transport']} transport against {$baseUrl}");
        $this->line('tenants compared: '.(implode(', ', $payload['tenants']) ?: 'none').
            '  |  probes: '.$payload['probes']['sent'].'/'.$payload['probes']['planned'].
            '  |  '.$payload['duration_ms'].'ms');

        $coverage = $payload['coverage'];

        $this->line('endpoints reached: '.$coverage['endpoints_reached'].'/'.$coverage['endpoints_planned'].
            '  |  checks: '.$coverage['checks']['pass'].' pass, '.
            $coverage['checks']['skipped'].' skipped, '.$coverage['checks']['FAIL'].' FAIL');

        if ($coverage['graded_surface'] !== []) {
            // The verdict's own arithmetic, next to the verdict. Whichever way
            // this run went, the next question is always "out of what?".
            $graded = $coverage['graded_surface'];

            $this->line('graded surface ('.$graded['surface'].'): '.$graded['reached'].'/'.$graded['planned'].
                ' reached — majority '.($graded['met'] ? 'held' : '<fg=red>LOST</>'));
        }

        if (($coverage['cross_tenant_comparison'] ?? []) !== []) {
            // Reached is not watched. This line is the difference, and it is the
            // one an operator has to read before believing a green verdict.
            $comparison = $coverage['cross_tenant_comparison'];

            $this->line('cross-tenant comparison ('.$comparison['surface'].'): '.
                $comparison['compared'].'/'.$comparison['comparable'].
                ' endpoint(s) had two answers to tell apart — '.
                ($comparison['comparable'] === 0
                    ? '<fg=yellow>nothing here was comparable</>'
                    : 'majority '.($comparison['met'] ? 'held' : '<fg=red>LOST</>')).
                ($comparison['blind_outside_graded_surface'] > 0
                    ? '  |  '.$comparison['blind_outside_graded_surface'].' more blind off the graded surface'
                    : ''));
        }

        if (($coverage['row_ownership'] ?? []) !== []) {
            // Different from the line above, and an operator has to read both:
            // that one says two answers could be told apart, this one says the
            // canary knows WHOSE rows are in them. A swap passes the first and
            // fails this.
            $ownership = $coverage['row_ownership'];

            // "could trace", not "traced to the right organisation" — on a run
            // WITH a finding these are the same endpoints, and a line reading
            // "carried rows traceable to the organisation that asked" directly
            // above a critical finding saying they did not would be the report
            // contradicting itself.
            $this->line('row ownership ('.$ownership['surface'].'): '.
                $ownership['attributed'].'/'.$ownership['attributable'].
                ' endpoint(s) served rows this run could trace to an owner'.
                ($ownership['attributable'] === 0
                    ? ''
                    : ' — '.match (true) {
                        ($ownership['unplaced'] ?? []) !== [] => '<fg=red>'.count($ownership['unplaced']).
                            ' served rows NOTHING COULD PLACE</>',
                        $ownership['met'] => 'the swap detector was awake',
                        default => '<fg=red>NONE — a swap would not have shown up</>',
                    }));
        }

        if (($coverage['route_table'] ?? []) !== []) {
            // The outermost boundary, printed above the check table rather than
            // buried under it, because it is the sentence that sizes every
            // number below: `endpoints reached: 31/31` is a ratio over the plan,
            // and the plan is 31 of 364 routes.
            $table = $coverage['route_table'];

            $this->line('route table: '.$table['planned'].'/'.$table['routes_total'].
                ' route(s) probed — <fg=yellow>'.$table['never_probed'].' watched by nothing</>: '.
                $table['public_get_refused'].' public GET refused, '.
                count($table['write_verb_routes']).' behind a write verb, '.
                array_sum($table['outside_probed_prefixes']).' outside '.
                implode('/', $table['probed_prefixes']));
        }

        $this->line('');

        $rows = array_map(static fn (array $c) => [
            match ($c['outcome']) { 'pass' => 'ok', 'skipped' => '--', default => 'FAIL' },
            $c['endpoint'],
            $c['check'],
            $c['detail'],
        ], $payload['checks']);

        if ($rows !== []) {
            $this->table(['', 'endpoint', 'check', 'detail'], $rows);
        }

        foreach ($payload['notes'] as $note) {
            $this->line("  <fg=gray>note:</> {$note}");
        }

        // Named whatever the verdict turned out to be. The floor decides how
        // loudly this is said; it never decides whether it is said.
        foreach ($payload['blind_spots'] as $uri => $reason) {
            $this->line("  <fg=yellow>not seen:</> {$uri} — {$reason}");
        }

        // Routes NOTHING in this run was ever going to look at. Printed on a
        // clean run for the same reason as the two lists below it, and it is the
        // one an operator has never been shown before: `endpoints reached: 31/31`
        // is a ratio over the plan, and the plan is not the route table.
        foreach (($coverage['routes_not_planned'] ?? []) as $uri => $reason) {
            $this->line("  <fg=yellow>never planned:</> {$uri} — {$reason}");
        }

        // Seen, and not compared — the endpoints that answered and still told
        // this run nothing about whether one organisation is reading another's
        // rows. Printed on a CLEAN run too, which is the whole point: a green
        // verdict has to say which of its detectors were asleep.
        foreach (($coverage['cross_tenant_comparison']['blind'] ?? []) as $uri => $reason) {
            $this->line("  <fg=yellow>not compared:</> {$uri} — {$reason}");
        }

        // Seen, compared, and still not proven to be serving the right
        // organisation's rows. Printed on a CLEAN run for the same reason as
        // the line above: a green verdict has to say which of its detectors
        // could not speak, and this is the only one that sees a swap.
        foreach (($coverage['row_ownership']['unattributed'] ?? []) as $uri => $reason) {
            $this->line("  <fg=yellow>not attributed:</> {$uri} — {$reason}");
        }

        // The route groups nothing here has ever looked at. Two lines, not 302.
        foreach (($coverage['route_table']['outside_probed_prefixes'] ?? []) as $group => $count) {
            $this->line("  <fg=yellow>never probed:</> {$group} — {$count} route(s), outside canary.prefixes");
        }

        foreach (($coverage['route_table']['write_verb_routes'] ?? []) as $route) {
            $this->line("  <fg=yellow>never probed:</> {$route} — a read-only prober may not send this verb");
        }

        // What no run of this command can see, whatever it reached. Printed on a
        // clean run above all: the verdict is a claim, and this is its size.
        foreach (($coverage['detector_limits'] ?? []) as $name => $limit) {
            $this->line("  <fg=yellow>cannot see ({$name}):</> {$limit}");
        }

        foreach ($payload['errors'] as $error) {
            $label = $payload['status'] === 'partial' ? 'partial' : 'incomplete';

            $this->line("  <fg=yellow>{$label}:</> {$error}");
        }

        if ($payload['findings'] === []) {
            $this->line('');

            $skipped = $payload['coverage']['checks']['skipped'];
            $comparison = $payload['coverage']['cross_tenant_comparison'] ?? [];
            $notCompared = count($comparison['blind'] ?? []);

            if ($payload['status'] === 'clean') {
                // Never just "no leakage detected". A green run on this platform
                // is always partly blind — every S1-S4 table has zero rows, so
                // the cross-tenant comparison has nothing to compare on most of
                // the new surface — and the number of things it did NOT look at
                // belongs next to the verdict, not three screens up.
                $this->line('  <fg=green>No cross-tenant leakage in what this run could see</> — '.
                    $payload['coverage']['checks']['pass'].' check(s) verified'.
                    ($skipped > 0
                        ? ', <fg=yellow>'.$skipped.' skipped</> (the `--` rows above say what was not looked at).'
                        : '.'));

                if ($notCompared > 0) {
                    // A clean run that was blind on part of the graded surface
                    // must say so IN THE VERDICT. The floor held, so this is not
                    // an alarm; it is the size of the claim being made.
                    $this->line('  <fg=yellow>Blind on '.$notCompared.' of '.$comparison['comparable'].
                        ' graded endpoint(s)</> — two organisations were asked and nothing in either answer '.
                        'told them apart, so a pin to one organisation would not have shown up there.');
                }

                $ownership = $payload['coverage']['row_ownership'] ?? [];
                $notAttributed = count($ownership['unattributed'] ?? []);

                if ($notAttributed > 0) {
                    // The same contract for the third detector, and it is the
                    // one that matters most on a green run: telling two answers
                    // apart does not say whose rows are in either of them.
                    $this->line('  <fg=yellow>Rows not traceable on '.$notAttributed.' of '.
                        $ownership['attributable'].' graded endpoint(s)</> — nothing in those answers could '.
                        'be traced back to an owner, so a straight swap of two organisations\' rows would '.
                        'not have shown up there.');
                }

                $unplanned = count($payload['coverage']['routes_not_planned'] ?? []);

                if ($unplanned > 0) {
                    // The claim's outermost boundary, and the one a reader of
                    // "endpoints reached: 31/31" cannot otherwise see: that
                    // ratio is over the PLAN, and the plan is not the route
                    // table.
                    $this->line('  <fg=yellow>'.$unplanned.' public GET route(s) were never planned at all</> — '.
                        ($payload['coverage']['routes_not_planned_on_graded_surface'] ?? 0).
                        ' of them on the graded surface. They are named above; nothing in this run looked at '.
                        'them, on this run or on any other.');
                }

                $table = $payload['coverage']['route_table'] ?? [];

                $outside = (array) ($table['outside_probed_prefixes'] ?? []);

                // The two groups by name, because "313 routes elsewhere" and
                // "the admin tree and the parent portal" are the same number and
                // only one of them tells an operator what is unwatched.
                $named = implode(', ', array_map(
                    static fn (string $g, int $n) => $g.' '.$n,
                    array_keys(array_slice($outside, 0, 2, true)),
                    array_values(array_slice($outside, 0, 2, true))
                ));

                if (($table['never_probed'] ?? 0) > 0) {
                    // ...and the boundary outside THAT one. The sentence above
                    // has been printed since 2026-08-13 and reads as the edge of
                    // the map; eight refused public GETs is 2% of what nothing
                    // probes. A green run has to state the size of its own
                    // claim, or `clean` is doing work the run did not do.
                    $this->line('  <fg=yellow>This run probed '.$table['planned'].' of '.
                        $table['routes_total'].' routes in this application.</> The other '.
                        $table['never_probed'].' are watched by nothing here — including '.
                        array_sum($outside).' outside '.
                        implode(' and ', (array) ($table['probed_prefixes'] ?? [])).' entirely'.
                        ($named === '' ? '' : ' ('.$named.')').
                        '. `clean` is a statement about the '.$table['planned'].'.');
                }

                // The last thing printed before the blank line, deliberately.
                // Everything above is about which endpoints were watched; this
                // is about which leaks are visible AT ALL, and three of them were
                // measured scoring exactly the verdict printed above.
                $this->line('  <fg=yellow>...and what nothing here can see at any coverage</> — '.
                    'a query-parameter scope, a disclosure carrying no primary key, and any table '.
                    'outside canary.compare_by. Each was measured on this branch reporting `clean`. '.
                    'See `cannot see:` above and coverage.detector_limits.');
            } elseif ($payload['status'] === 'partial') {
                // The distinction the whole four-state model exists for, said in
                // one sentence: what it DID see was fine, and here is the size
                // of what it did not. Never "did not complete" — that is the
                // dead-origin sentence, and printing it here is how an operator
                // learns to ignore both.
                $blind = count($payload['blind_spots']);

                $this->line('  <fg=yellow>PARTIAL COVERAGE</> — no cross-tenant leakage in the '.
                    $payload['coverage']['endpoints_reached'].' of '.
                    $payload['coverage']['endpoints_planned'].' endpoint(s) this run reached ('.
                    $payload['coverage']['checks']['pass'].' check(s) verified, '.$skipped.' skipped).'.
                    ($blind > 0
                        ? ' The other '.$blind.' '.($blind === 1 ? 'was' : 'were').' never seen at all — named above.'
                        : ''));

                if (($comparison['met'] ?? true) === false) {
                    // The other way to be partial, and it needs its own sentence:
                    // these endpoints ANSWERED. Reusing the unreachable-endpoint
                    // wording here would send an operator looking for a routing
                    // problem that does not exist.
                    $this->line('  <fg=yellow>The cross-tenant comparison was blind on '.
                        ($comparison['comparable'] - $comparison['compared']).' of '.
                        $comparison['comparable'].' graded endpoint(s)</> — they answered, and nothing in '.
                        'either organisation\'s answer told it from the other\'s. On this surface that '.
                        'comparison is the ONLY detector that sees a pin to one organisation, so most of '.
                        'what this run reached is not actually being watched for one.');
                    $this->line('  Give the compared organisations content, or point '.
                        '`--tenants=` at two that have some.');
                }

                if ((($payload['coverage']['row_ownership']['met']) ?? true) === false) {
                    // The third way to be partial, and the one that needs its
                    // own sentence most: these endpoints answered AND could be
                    // told apart, and still nothing in them could be traced to
                    // an owner. That is the state in which a swap is invisible.
                    $this->line('  <fg=yellow>Not one graded endpoint carried a row this run could trace '.
                        'to an owner</> — the positive assertion is dark, so an endpoint serving each '.
                        'organisation another organisation\'s rows would read as clean.');
                }

                if ($blind > 0) {
                    $this->line('  <fg=yellow>This is not a clean run, and it is not a dead canary either.</> '.
                        'Fix the unreachable endpoints or the run will keep not watching them.');
                }
            } else {
                $this->line('  <fg=red>Run is not evidence about this platform — this is NOT a clean result.</>');
            }

            $this->line('');

            return;
        }

        $this->line('');
        $this->line('<fg=red;options=bold>FINDINGS</>');

        foreach ($payload['findings'] as $i => $finding) {
            $n = $i + 1;
            $this->line('');
            $this->line("  <fg=red;options=bold>{$n}. [{$finding['severity']}] {$finding['kind']}</> — {$finding['endpoint']}");
            $this->line("     {$finding['summary']}");
            $this->line('     evidence: '.json_encode($finding['evidence'], JSON_UNESCAPED_SLASHES));
            $this->line('     <options=bold>reproduce:</> '.$finding['request']['reproduce']);
        }

        $this->line('');
    }

    private function emitFatal(string $message): void
    {
        if ($this->option('json')) {
            $this->output->writeln((string) json_encode([
                'command' => 'tenancy:canary',
                'status' => 'incomplete',
                'errors' => [$message],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }

        $this->error($message);
    }
}
