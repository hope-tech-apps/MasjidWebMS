<?php

namespace App\Support\Canary;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

/**
 * Dispatches probes through this application's own HTTP kernel, in-process.
 *
 * This is the transport the test suite uses, which is what lets a canary test
 * assert against the TEST application — seeded tenants, in-memory sqlite, a
 * scratch route — without a server, a socket, or any chance of a test run
 * touching the internet or production.
 *
 * It exercises the real middleware stack and the real controllers, so a probe
 * here sees exactly the routing, the tenant resolution and the throttles that a
 * network request would. What it does NOT see is everything outside PHP: the
 * proxy, the TLS terminator, any edge cache. That is why production uses
 * HttpTransport instead — a `masjid-id` header stripped by a misconfigured
 * cache key is a real leak this transport cannot observe.
 */
final class KernelTransport implements ProbeTransport
{
    /** @param array<int,string> $tenantKeys */
    public function __construct(
        private readonly HttpKernel $kernel,
        private readonly Application $app,
        private readonly string $baseUrl,
        private readonly array $tenantKeys,
    ) {
    }

    public function name(): string
    {
        return 'kernel';
    }

    public function send(Probe $probe): ProbeResult
    {
        $request = Request::create($probe->url($this->baseUrl), 'GET');

        foreach ($probe->headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        // TenantContext is registered scoped() precisely so a long-lived
        // process cannot carry one tenant's binding into the next unit of work
        // (.claude/rules/tenant-scoping.md). A canary run is exactly such a
        // process: many requests, one container. Clearing between probes states
        // that rather than relying on the public routes happening never to
        // bind — and if one ever does, the next probe must not inherit it.
        $this->app->forgetScopedInstances();

        $started = microtime(true);

        try {
            $response = $this->kernel->handle($request);
        } catch (\Throwable $e) {
            // The kernel renders its own exceptions, so reaching here means the
            // failure was outside the request lifecycle. Report it as a
            // transport fault, never as a passing probe.
            return ProbeResult::failed($probe, $e::class.': '.$e->getMessage(), self::elapsed($started));
        }

        $elapsed = self::elapsed($started);
        $content = (string) $response->getContent();
        $body = json_decode($content, true);
        $payload = ResponseFacts::payload($body);

        [$count, $basis] = ResponseFacts::recordCount($payload);

        $remaining = $response->headers->get('X-RateLimit-Remaining');

        return new ProbeResult(
            probe: $probe,
            status: $response->getStatusCode(),
            recordCount: $count,
            countBasis: $basis,
            masjidIds: ResponseFacts::tenantIds($payload, $this->tenantKeys),
            bytes: strlen($content),
            durationMs: $elapsed,
            rateLimitRemaining: is_numeric($remaining) ? (int) $remaining : null,
        );
    }

    private static function elapsed(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
