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
 * EXIT CODES
 * ==========================================================================
 *
 *   0  clean — every probe made, every check passed
 *   1  finding — something the schedule should page on
 *   2  incomplete — ran but could not finish (throttled, truncated,
 *      unreachable, no tenants). Non-zero on purpose: "I could not check" must
 *      alert, or the canary can be silenced by making it fail.
 *
 * `--json` emits the whole run as one machine-readable object and nothing else.
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
                $this->addCheck($uri, 'all', 'skipped', 'NOT PROBED — run ended before this endpoint');

                continue;
            }

            // Whether every probe this endpoint's checks wanted was actually
            // made. A partially probed endpoint is still worth evaluating — a
            // leak the run REACHED must be reported, budget or no budget — but
            // a check whose probes were cut off may never report `pass`. It
            // reports `skipped`, because "I did not look" and "I looked and it
            // was fine" are the two answers a canary must never confuse.
            $complete = count($probeResults) === count($entry['probes']);

            $this->checkServerFaults($uri, $probeResults, $complete);
            $this->checkBodyNamesRequestedTenantOnly($uri, $probeResults, $complete);

            if (! $entry['global']) {
                $this->checkTenantLessRefused($uri, $entry['probes'], $probeResults, $complete);
            }
        }
    }

    /** The outcome of a check that found nothing wrong: only `pass` if it saw everything. */
    private function outcome(bool $failed, bool $complete): string
    {
        return $failed ? 'FAIL' : ($complete ? 'pass' : 'skipped');
    }

    /**
     * Probe 2: a 5xx anywhere. On a tenant-less request it is reported as its
     * own kind, because that is the gallery's exact signature — a client
     * mistake surfacing as a server fault, where it reads as infrastructure
     * flakiness and nobody looks for a tenancy bug behind it.
     *
     * @param  array<int,ProbeResult>  $results
     */
    private function checkServerFaults(string $uri, array $results, bool $complete): void
    {
        $faulted = false;

        foreach ($results as $result) {
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

        $this->addCheck($uri, 'no-server-fault', $this->outcome($faulted, $complete), count($results).' probe(s)');
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
    private function checkBodyNamesRequestedTenantOnly(string $uri, array $results, bool $complete): void
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

        $this->addCheck(
            $uri,
            'body-names-only-the-requested-tenant',
            $this->outcome(! $clean, $complete),
            $observed
                ? 'masjid_id present in body'
                : 'no masjid_id seen (the resource strips it, or there were no rows)'
        );
    }

    /**
     * Probe 1: the fail-open shape, and the arithmetic that proves it.
     *
     * @param  array<int,Probe>  $planned
     * @param  array<int,ProbeResult>  $results
     */
    private function checkTenantLessRefused(string $uri, array $planned, array $results, bool $complete): void
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

        $refused = true;

        foreach ($tenantLess as $result) {
            if (! $result->isSuccessful()) {
                continue;
            }

            $refused = false;

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
            $this->outcome(! $refused, $complete && count($tenantLess) === $plannedTenantLess),
            count($tenantLess).'/'.$plannedTenantLess.' falsy-header variant(s); tenant counts: '.
                (($counts === []) ? 'uncountable' : implode('/', $counts))
        );
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
            $this->line($payload['status'] === 'clean'
                ? '  <fg=green>No cross-tenant leakage detected.</>'
                : '  <fg=yellow>Run did not complete — this is NOT a clean result.</>');
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
