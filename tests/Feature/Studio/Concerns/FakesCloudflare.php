<?php

namespace Tests\Feature\Studio\Concerns;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * A stand-in for the Cloudflare API, for the S7 tests. Nothing here, and no
 * test that uses it, can reach the real API: every request must match a route
 * the test declared, and anything else is a stray request that
 * Http::preventStrayRequests() turns into an exception.
 *
 * Bodies follow the API reference read at build time (plan §8 OQ3; the URLs
 * are cited in App\Services\Cloudflare\CloudflareService): the v4 envelope
 * {success, errors, messages, result, result_info}; a Pages custom domain with
 * {id, name, status, validation_data, verification_data, zone_tag,
 * created_on}; a zone with {id, name, status, name_servers, created_on}; DNS
 * records filtered by `name.exact`.
 */
trait FakesCloudflare
{
    private const TOKEN = 'cf-test-token-7f3a9c1e5b';

    private const API = 'https://api.cloudflare.com/client/v4';

    /** @var array<string, mixed>|null the routes the fake answers from */
    private ?array $cloudflareRoutes = null;

    private function withStudioToken(): void
    {
        config(['cloudflare.studio_token' => self::TOKEN]);
    }

    /**
     * Route each request by "METHOD path" (Cloudflare, relative to the API
     * base, query included) or "METHOD url" (anything else, like a probe).
     * Keys are Str::is patterns, tried in order; a value is a response, a
     * ResponseSequence, or a closure taking the request.
     *
     * @param  array<string, mixed>  $routes
     */
    private function fakeCloudflare(array $routes): void
    {
        // Http::fake() appends, and the first stub that answers wins, so a
        // second call could never change what a route returns. One stub is
        // registered and reads the routes of the latest call instead.
        $first = $this->cloudflareRoutes === null;
        $this->cloudflareRoutes = $routes;

        if (! $first) {
            return;
        }

        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $url = $request->url();
            $key = $request->method() . ' ' . (str_starts_with($url, self::API) ? Str::after($url, self::API) : $url);

            foreach ($this->cloudflareRoutes as $pattern => $response) {
                if (Str::is($pattern, $key)) {
                    return is_callable($response) ? $response($request) : $response;
                }
            }

            return null;
        });
    }

    /** @param  array<string, mixed>|list<mixed>  $result */
    private function cfOk(array $result, ?array $resultInfo = null, int $status = 200)
    {
        return Http::response(array_filter([
            'success' => true,
            'errors' => [],
            'messages' => [],
            'result' => $result,
            'result_info' => $resultInfo,
        ], fn ($value) => $value !== null), $status);
    }

    private function cfError(int $status, int $code, string $message)
    {
        return Http::response([
            'success' => false,
            'errors' => [['code' => $code, 'message' => $message]],
            'messages' => [],
            'result' => null,
        ], $status);
    }

    /** @return array<string, mixed> */
    private function pagesDomainBody(string $host, string $status, array $extra = []): array
    {
        return array_replace_recursive([
            'id' => 'pd-' . md5($host),
            'name' => $host,
            'status' => $status,
            'domain_id' => 'dom-' . md5($host),
            'zone_tag' => 'zone-' . md5($host),
            'certificate_authority' => 'google',
            'created_on' => '2026-09-24T10:00:00Z',
            'validation_data' => ['status' => $status === 'active' ? 'active' : 'pending', 'method' => 'http'],
            'verification_data' => ['status' => $status === 'active' ? 'active' : 'pending'],
        ], $extra);
    }

    /** @return array<string, mixed> */
    private function zoneBody(string $apex, string $status, string $id = 'zone-custom-1'): array
    {
        return [
            'id' => $id,
            'name' => $apex,
            'status' => $status,
            'name_servers' => ['ada.ns.cloudflare.com', 'bob.ns.cloudflare.com'],
            'created_on' => '2026-09-24T10:00:00Z',
        ];
    }

    /** @return array<string, mixed> */
    private function dnsRecord(string $host, string $type, string $content, string $id = 'rec-1'): array
    {
        return ['id' => $id, 'name' => $host, 'type' => $type, 'content' => $content, 'proxied' => true, 'ttl' => 1];
    }

    /** "METHOD url" for every request sent so far. */
    private function sent(): array
    {
        return Http::recorded()->map(fn ($pair) => $pair[0]->method() . ' ' . $pair[0]->url())->values()->all();
    }

    /** Requests sent to the Cloudflare API only. */
    private function sentToCloudflare(): array
    {
        return array_values(array_filter($this->sent(), fn (string $line) => str_contains($line, ' ' . self::API)));
    }
}
