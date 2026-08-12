<?php

namespace App\Support\Canary;

use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * Probes the deployed application over the network, the way a client does.
 *
 * This is the production transport. It is the only one that can see a leak
 * introduced OUTSIDE PHP — a cache that keys on the path and not on `masjid-id`
 * and therefore serves one organisation's page to another; a proxy that drops
 * an unrecognised request header; a load balancer routing to a stale release.
 * None of those change a line of code, and none are visible to the test suite.
 */
final class HttpTransport implements ProbeTransport
{
    /** @param array<int,string> $tenantKeys */
    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $baseUrl,
        private readonly array $tenantKeys,
        private readonly int $timeout,
        private readonly int $connectTimeout,
    ) {
    }

    public function name(): string
    {
        return 'http';
    }

    public function send(Probe $probe): ProbeResult
    {
        $started = microtime(true);

        try {
            $response = $this->http
                ->withHeaders($probe->headers)
                ->timeout($this->timeout)
                ->connectTimeout($this->connectTimeout)
                // A redirect is an answer, and following it silently would let
                // a canary report on an endpoint it did not actually probe.
                ->withoutRedirecting()
                ->get($probe->url($this->baseUrl));
        } catch (\Throwable $e) {
            return ProbeResult::failed($probe, $e::class.': '.$e->getMessage(), self::elapsed($started));
        }

        $elapsed = self::elapsed($started);
        $content = $response->body();
        $payload = ResponseFacts::payload(json_decode($content, true));

        [$count, $basis] = ResponseFacts::recordCount($payload);

        $remaining = $response->header('X-RateLimit-Remaining');

        return new ProbeResult(
            probe: $probe,
            status: $response->status(),
            recordCount: $count,
            countBasis: $basis,
            masjidIds: ResponseFacts::tenantIds($payload, $this->tenantKeys),
            bytes: strlen($content),
            durationMs: $elapsed,
            rateLimitRemaining: is_numeric($remaining) ? (int) $remaining : null,
            fingerprint: ResponseFacts::fingerprint($payload),
            recordIds: ResponseFacts::recordIdentity($payload),
        );
    }

    private static function elapsed(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
