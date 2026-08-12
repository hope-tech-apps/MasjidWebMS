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
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

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
 *   - makes exactly one kind of database query of its own — `SELECT id FROM
 *     masjids` — to learn which tenants exist. Nothing else here touches the
 *     database directly.
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
 * (`budget.max_seconds`). Hitting either ends the run as INCOMPLETE, exit 2 —
 * never as clean. A canary that reports green because it ran out of budget is
 * worse than no canary, because it is trusted.
 *
 * **Rate-aware.** A 429 anywhere aborts the run immediately: it is not evidence
 * of leakage, and pushing through it is precisely the behaviour to avoid. Same
 * for an `X-RateLimit-Remaining` that drops into single digits.
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
 * EXIT CODES
 * ==========================================================================
 *
 *   0  clean — every planned endpoint was reached, and at least one check
 *      actually observed the application and was satisfied
 *   1  finding — something the schedule should page on
 *   2  incomplete — ran but could not finish (throttled, truncated,
 *      unreachable, no tenants), OR finished without verifying anything.
 *      Non-zero on purpose: "I could not check" must alert, or the canary can
 *      be silenced by making it fail.
 *
 * `--json` carries a `coverage` block — endpoints reached out of planned, and
 * the pass/skipped/FAIL census — because "probes: 36/36" reads like coverage and
 * is not: it counts requests attempted, not questions answered.
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

    /** Endpoints that were probed and never answered 2xx to a valid tenant. */
    /** @var array<string,string> */
    private array $unreached = [];

    private int $plannedEndpoints = 0;

    private bool $incomplete = false;

    public function handle(): int
    {
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
            $this->incomplete = true;
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
            $this->incomplete = true;
            $this->errors[] = 'The router exposed no probeable public GET endpoint — discovery is broken, not the API.';

            return $this->report($startedAt, $started, $baseUrl, $transport->name(), $tenants, 0, 0);
        }

        [$results, $sent, $planned] = $this->runProbes($plan, $transport, $config, $started);

        $this->evaluate($plan, $results);
        $this->assessCoverage($plan);

        return $this->report($startedAt, $started, $baseUrl, $transport->name(), $tenants, $sent, $planned);
    }

    // ---------------------------------------------------------------- tenants

    /**
     * The organisations to compare — the ONLY database query this command makes.
     *
     * A console run binds no tenant, and `Masjid` is the tenant rather than a
     * tenant-scoped model, so no global scope applies. Soft-deleted rows are
     * excluded by the model's SoftDeletes.
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

        return Masjid::query()
            ->orderBy('id')
            ->limit($want)
            ->pluck('id')
            ->map(static fn ($id) => (int) $id)
            ->all();
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
     * @param  array<string,mixed>  $config
     * @param  array<int,int>  $tenants
     * @return array<string,array{global:bool,probes:array<int,Probe>}>
     */
    private function plan(array $config, array $tenants): array
    {
        $catalog = new ProbeCatalog($this->laravel->make('router'), $config);
        $endpoints = $catalog->endpoints();

        $only = array_filter(array_map('trim', explode(',', (string) ($this->option('only') ?? ''))));

        if ($only !== []) {
            $endpoints = array_values(array_filter($endpoints, static function (array $e) use ($only) {
                foreach ($only as $needle) {
                    if (str_contains($e['uri'], $needle)) {
                        return true;
                    }
                }

                return false;
            }));
        }

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
                if ($sent >= $maxRequests) {
                    $this->incomplete = true;
                    $this->errors[] = "Request budget exhausted after {$sent} probes ({$planned} planned) — run truncated.";

                    return [$results, $sent, $planned];
                }

                if ((microtime(true) - $started) >= $maxSeconds) {
                    $this->incomplete = true;
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

                if ($result->isThrottled()) {
                    $this->incomplete = true;
                    $this->errors[] = 'Throttled (429) on '.$probe->endpoint.
                        ' — run aborted. A 429 is not evidence of leakage, and pushing through one is the behaviour this canary must never have.';

                    return [$results, $sent, $planned];
                }

                if ($result->rateLimitRemaining !== null && $result->rateLimitRemaining < 5) {
                    $this->incomplete = true;
                    $this->errors[] = 'Backing off: X-RateLimit-Remaining fell to '.$result->rateLimitRemaining.
                        ' on '.$probe->endpoint.'. Real clients need the rest of that bucket more than this run does.';

                    return [$results, $sent, $planned];
                }

                if ($result->transportError !== null) {
                    $this->incomplete = true;
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
     */
    private function evaluate(array $plan, array $results): void
    {
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

            $this->checkServerFaults($uri, $probeResults, $complete, $reached);
            $this->checkBodyNamesRequestedTenantOnly($uri, $probeResults, $complete, $reached);
            $this->checkTenantsGetDistinctAnswers($uri, $entry, $probeResults, $complete, $reached);

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
     *     lands. The check SAYS SO in its detail rather than passing.
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
    private function checkTenantsGetDistinctAnswers(
        string $uri,
        array $entry,
        array $results,
        bool $complete,
        bool $reached,
    ): void {
        $check = 'tenants-get-different-answers';

        if ($entry['global']) {
            $this->addCheck($uri, $check, 'skipped',
                'n/a — declared cross-tenant by design in canary.global_endpoints');

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
            $this->addCheck($uri, $check, 'skipped',
                'needs two organisations answering 2xx; got '.count($byTenant).
                ' — a pin to one tenant is not observable here');

            return;
        }

        $failed = false;
        $observed = false;
        $details = [];
        $tenantIds = array_keys($byTenant);

        for ($i = 0; $i < count($tenantIds); $i++) {
            for ($j = $i + 1; $j < count($tenantIds); $j++) {
                $left = $byTenant[$tenantIds[$i]];
                $right = $byTenant[$tenantIds[$j]];
                $pair = 'masjid '.$tenantIds[$i].' vs '.$tenantIds[$j];
                // null is "the shape carried no countable list", which is a
                // different fact from "the list was empty". Printing both as 0
                // would misreport the reason this check could not see anything.
                $counts = ($left->recordCount ?? 'uncountable').'/'.($right->recordCount ?? 'uncountable');

                $shared = [];

                foreach ($left->recordIds as $path => $ids) {
                    $overlap = array_values(array_intersect($ids, $right->recordIds[$path] ?? []));

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

                if ($left->recordIds !== [] || $right->recordIds !== []) {
                    $observed = true;
                    $details[] = $pair.': no shared record ids ('.$counts.' records)';

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

        $this->addCheck(
            $uri,
            $check,
            $this->outcome($failed, $complete, $reached && $observed),
            implode('; ', $details) ?: 'compared '.count($byTenant).' organisations',
        );
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
     * The three checks above answer questions about ENDPOINTS. This answers the
     * question about the RUN, which is the one an operator reads and the one
     * that used to lie: `clean` was the default whenever no finding was raised
     * and no budget was blown, so a canary that observed literally nothing
     * reported the same word as a canary that observed everything and liked it.
     *
     * A run may only call itself clean if it can point at something it saw.
     *
     * @param  array<string,array{global:bool,probes:array<int,Probe>}>  $plan
     */
    private function assessCoverage(array $plan): void
    {
        $this->plannedEndpoints = count($plan);

        $probed = count($plan) - count($this->notProbed);
        $reached = $probed - count($this->unreached);

        if ($probed > 0 && $reached === 0) {
            // The F1 shape: an origin that redirects everything, 404s
            // everything, or is not there. Every probe "completed", every check
            // found nothing, and nothing whatsoever was verified.
            $this->incomplete = true;
            $this->errors[] = 'NOTHING WAS VERIFIED: no endpoint answered 2xx to a probe naming a real '.
                'organisation ('.$probed.' endpoint(s) probed). The canary did not reach this application — '.
                'check that CANARY_BASE_URL points at an origin that serves the API rather than redirecting '.
                'to one, and that the release is up.';
        } elseif ($this->unreached !== []) {
            foreach ($this->unreached as $uri => $detail) {
                $this->incomplete = true;
                $this->errors[] = $uri.' was not verified: '.$detail.'.';
            }
        }

        $passed = count(array_filter($this->checks, static fn (array $c) => $c['outcome'] === 'pass'));

        if ($passed === 0 && $this->checks !== []) {
            $this->incomplete = true;
            $this->errors[] = 'NOTHING WAS VERIFIED: 0 of '.count($this->checks).' checks reported pass — '.
                'every one of them either could not look or had nothing to look at. A run that observed '.
                'nothing is not a clean run.';
        }
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
        // `clean` is the narrowest of the three and the only one that has to be
        // EARNED: findings outrank everything (a leak the run reached must page
        // someone even if the run was also truncated), and `incomplete` now
        // covers "could not finish" AND "finished without verifying anything".
        $status = $this->findings !== [] ? 'leak' : ($this->incomplete ? 'incomplete' : 'clean');
        $exit = $this->findings !== [] ? 1 : ($this->incomplete ? 2 : 0);

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
        ];

        if ($status === 'clean') {
            // One line an hour, so "the canary stopped running" is a question
            // the log can answer. schedule:run discards stdout.
            $channel->info('tenancy:canary clean', $summary);

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

        foreach ($payload['errors'] as $error) {
            $this->line("  <fg=yellow>incomplete:</> {$error}");
        }

        if ($payload['findings'] === []) {
            $this->line('');

            if ($payload['status'] === 'clean') {
                // Never just "no leakage detected". A green run on this platform
                // is always partly blind — every S1-S4 table has zero rows, so
                // the cross-tenant comparison has nothing to compare on most of
                // the new surface — and the number of things it did NOT look at
                // belongs next to the verdict, not three screens up.
                $skipped = $payload['coverage']['checks']['skipped'];

                $this->line('  <fg=green>No cross-tenant leakage in what this run could see</> — '.
                    $payload['coverage']['checks']['pass'].' check(s) verified'.
                    ($skipped > 0
                        ? ', <fg=yellow>'.$skipped.' skipped</> (the `--` rows above say what was not looked at).'
                        : '.'));
            } else {
                $this->line('  <fg=yellow>Run did not complete — this is NOT a clean result.</>');
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
