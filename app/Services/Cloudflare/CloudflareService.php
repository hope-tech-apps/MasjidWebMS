<?php

namespace App\Services\Cloudflare;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * Manara Studio's only door to the Cloudflare API (docs/manara-studio-w1.md, S7;
 * spec D17): find or create a zone, point a host at the renderer with a CNAME,
 * and add it as a custom domain of the renderer's Pages project.
 *
 * ## It can make things and look at things, and nothing else
 *
 * The token (config `cloudflare.studio_token`) holds Zone Edit on every zone in
 * the account, which includes deleting the zones the live tenants are served
 * from. So this class has no delete method, and request() refuses any verb but
 * GET, POST, PUT and PATCH: the power to remove something from Cloudflare is
 * not in the code at all, and a test pins that. Every write is
 * create-if-absent, and a DNS record Studio did not create is never changed:
 * ensureCname() adopts a CNAME that already points at the renderer and calls
 * anything else a `conflict`. That is what keeps `burlingtonmasjid.com`,
 * `alrazischool.org` and the managed zone's own records safe from Studio.
 *
 * ## Without a token it sends nothing
 *
 * Every method answers `not_configured` before building a request, so a box
 * with no token (staging always; production until the owner added it) makes no
 * Cloudflare call at all, and the attacher says what it is waiting on instead.
 *
 * ## The token never leaves this class
 *
 * It goes in the Authorization header and nowhere else. Anything Cloudflare
 * says back is passed through redact() before it reaches a CloudflareResult,
 * a log line or `masjid_domains.last_error`, and the log context never carries
 * a header.
 *
 * ## What the API reference said at build time (plan §8 OQ3), read 2026-09-24
 *
 *  - Pages custom domain, GET/POST/PATCH
 *    /accounts/{account}/pages/projects/{project}/domains[/{name}]:
 *    https://developers.cloudflare.com/api/resources/pages/subresources/projects/subresources/domains/methods/get/
 *    `status` is one of initializing, pending, active, deactivated, blocked,
 *    error; `validation_data` {status, method, error_message, txt_name,
 *    txt_value}; `verification_data` {status, error_message}; plus `id`,
 *    `name`, `zone_tag`, `created_on`. The list carries `result_info.total_count`:
 *    https://developers.cloudflare.com/api/resources/pages/subresources/projects/subresources/domains/methods/list/
 *  - DNS records list: https://developers.cloudflare.com/api/resources/dns/subresources/records/methods/list/
 *    filters by name with `name.exact` (case-insensitive); a plain `name=` is not
 *    in the current reference.
 *  - Zones list: https://developers.cloudflare.com/api/resources/zones/methods/list/
 *    filters by `name` and `account.id`; zone `status` is initializing,
 *    pending, active or moved, with `name_servers` and `created_on`.
 *  The fixtures in tests/Feature/Studio/CloudflareServiceTest.php are shaped on
 *  these pages.
 */
class CloudflareService
{
    /** The only verbs this class may send. DELETE is deliberately absent. */
    public const VERBS = ['GET', 'POST', 'PUT', 'PATCH'];

    /** Pages custom-domain statuses, per the API reference cited above. */
    public const PAGES_STATUSES = ['initializing', 'pending', 'active', 'deactivated', 'blocked', 'error'];

    /** Zone statuses, per the API reference cited above. */
    public const ZONE_STATUSES = ['initializing', 'pending', 'active', 'moved'];

    /**
     * Error codes Cloudflare answers a DNS create with when the name is already
     * taken (81053 "An A, AAAA, or CNAME record with that host already exists",
     * 81057 "The record already exists", 81058 "A record with the same settings
     * already exists"). Known from the API's answers, not from the reference,
     * which lists no error codes; recordExists() also matches the words
     * "already exists", so a new code does not turn the race into a failure.
     */
    private const ALREADY_EXISTS_CODES = [81053, 81057, 81058];

    /**
     * Codes Cloudflare answers GET /zones/{id} with for an id that no longer
     * names a zone (7003 "could not route ... perhaps your object identifier is
     * invalid", 1001 "Invalid zone identifier"), besides a plain 404. Observed
     * answers, not documented ones.
     */
    private const NO_SUCH_ZONE_CODES = [7003, 1001];

    /** The record types that decide where a host's web traffic goes. */
    private const ADDRESS_TYPES = ['A', 'AAAA', 'CNAME'];

    public function isConfigured(): bool
    {
        return filled(config('cloudflare.studio_token'));
    }

    /**
     * The account's zone for exactly this apex: data {id, name, status,
     * name_servers, created_on}, or `absent`.
     */
    public function findZone(string $apex): CloudflareResult
    {
        if (! $this->isConfigured()) {
            return CloudflareResult::notConfigured();
        }

        $apex = strtolower($apex);
        $response = $this->request('GET', '/zones', [
            'name' => $apex,
            'account.id' => (string) config('cloudflare.account_id'),
        ]);

        if (! $response->is(CloudflareResult::OK)) {
            return $response;
        }

        foreach ((array) ($response->data['result'] ?? []) as $zone) {
            if (is_array($zone) && strtolower((string) ($zone['name'] ?? '')) === $apex) {
                return CloudflareResult::of(CloudflareResult::OK, self::zone($zone), null, $response->http_status);
            }
        }

        return CloudflareResult::of(CloudflareResult::ABSENT, [], null, $response->http_status);
    }

    /**
     * Add the apex to the account as a full-setup zone, unless it is already
     * there (`adopted`, nothing sent but the read). `created` carries the
     * nameservers the registrar must be given.
     */
    public function createZone(string $apex): CloudflareResult
    {
        $found = $this->findZone($apex);

        if ($found->is(CloudflareResult::OK)) {
            return CloudflareResult::of(CloudflareResult::ADOPTED, $found->data, null, $found->http_status);
        }

        if (! $found->is(CloudflareResult::ABSENT)) {
            return $found;
        }

        $created = $this->request('POST', '/zones', [
            'name' => strtolower($apex),
            'account' => ['id' => (string) config('cloudflare.account_id')],
            'type' => 'full',
        ]);

        if (! $created->is(CloudflareResult::OK)) {
            return $created;
        }

        return CloudflareResult::of(CloudflareResult::CREATED, self::zone((array) ($created->data['result'] ?? [])), null, $created->http_status);
    }

    /** One zone by id, or `absent` when the id no longer names one. */
    public function getZone(string $zoneId): CloudflareResult
    {
        if (! $this->isConfigured()) {
            return CloudflareResult::notConfigured();
        }

        $response = $this->request('GET', '/zones/' . rawurlencode($zoneId));

        if ($response->is(CloudflareResult::OK)) {
            return CloudflareResult::of(CloudflareResult::OK, self::zone((array) ($response->data['result'] ?? [])), null, $response->http_status);
        }

        if ($response->is(CloudflareResult::REJECTED)
            && ($response->http_status === 404 || array_intersect(self::NO_SUCH_ZONE_CODES, (array) ($response->data['codes'] ?? [])) !== [])) {
            return CloudflareResult::of(CloudflareResult::ABSENT, [], $response->error, $response->http_status);
        }

        return $response;
    }

    /**
     * Ask Cloudflare to look for the new nameservers now rather than on its own
     * schedule. The endpoint is rate-limited; the attacher calls it at most once
     * every six hours per row (DomainAttacher::ACTIVATION_CHECK_EVERY_HOURS).
     */
    public function requestActivationCheck(string $zoneId): CloudflareResult
    {
        if (! $this->isConfigured()) {
            return CloudflareResult::notConfigured();
        }

        return $this->request('PUT', '/zones/' . rawurlencode($zoneId) . '/activation_check');
    }

    /**
     * Make `host` a proxied CNAME to the renderer's Pages target, without ever
     * touching a record Studio did not create:
     *
     *  - no A, AAAA or CNAME record at that name: create one (`created`);
     *  - exactly one, a CNAME already pointing at the target: `adopted`, left
     *    as it is;
     *  - anything else: `conflict`, with the record's {type, content}. Never
     *    overwritten, never removed.
     *
     * Other record types at the name (TXT, MX, CAA) do not decide where web
     * traffic goes and can sit beside a CNAME in Cloudflare (it flattens one at
     * an apex), so they are neither a conflict nor touched.
     *
     * If the create loses a race to another writer ("already exists"), the name
     * is read again and judged by the same rule.
     */
    public function ensureCname(string $zoneId, string $host, string $comment = 'Manara Studio'): CloudflareResult
    {
        if (! $this->isConfigured()) {
            return CloudflareResult::notConfigured();
        }

        $host = strtolower($host);
        $existing = $this->cnameState($zoneId, $host);

        if (! $existing->is(CloudflareResult::ABSENT)) {
            return $existing;
        }

        $target = (string) config('cloudflare.pages_target');
        $created = $this->request('POST', '/zones/' . rawurlencode($zoneId) . '/dns_records', [
            'type' => 'CNAME',
            'name' => $host,
            'content' => $target,
            'proxied' => true,
            'ttl' => 1,
            'comment' => mb_strimwidth($comment, 0, 100),
        ]);

        if ($created->is(CloudflareResult::OK)) {
            return CloudflareResult::of(CloudflareResult::CREATED, [
                'id' => (string) ($created->data['result']['id'] ?? ''),
                'type' => 'CNAME',
                'content' => $target,
            ], null, $created->http_status);
        }

        if ($created->is(CloudflareResult::REJECTED) && self::recordExists($created)) {
            $again = $this->cnameState($zoneId, $host);

            return $again->is(CloudflareResult::ABSENT) ? $created : $again;
        }

        return $created;
    }

    /**
     * The renderer project's custom domain for `host`: data {id, name, status,
     * validation_data, verification_data, zone_tag, created_on}, or `absent`.
     */
    public function getPagesDomain(string $host): CloudflareResult
    {
        if (! $this->isConfigured()) {
            return CloudflareResult::notConfigured();
        }

        $response = $this->request('GET', $this->pagesDomainsPath() . '/' . rawurlencode(strtolower($host)));

        if ($response->is(CloudflareResult::OK)) {
            return CloudflareResult::of(CloudflareResult::OK, self::pagesDomain((array) ($response->data['result'] ?? [])), null, $response->http_status);
        }

        if ($response->is(CloudflareResult::REJECTED) && $response->http_status === 404) {
            return CloudflareResult::of(CloudflareResult::ABSENT, [], null, 404);
        }

        return $response;
    }

    /** How many custom domains the renderer project carries: data {count}. */
    public function countPagesDomains(): CloudflareResult
    {
        if (! $this->isConfigured()) {
            return CloudflareResult::notConfigured();
        }

        $response = $this->request('GET', $this->pagesDomainsPath());

        if (! $response->is(CloudflareResult::OK)) {
            return $response;
        }

        $total = $response->data['result_info']['total_count'] ?? null;
        $count = is_numeric($total) ? (int) $total : count((array) ($response->data['result'] ?? []));

        return CloudflareResult::of(CloudflareResult::OK, ['count' => $count], null, $response->http_status);
    }

    /**
     * Add `host` to the renderer project, unless it is already there
     * (`adopted`). At the ceiling (config `cloudflare.pages_domain_ceiling`) it
     * adds nothing and answers `conflict` with reason `capacity`: going past it
     * is a plan decision for the owner, not something to discover from a
     * refused POST.
     */
    public function ensurePagesDomain(string $host): CloudflareResult
    {
        $found = $this->getPagesDomain($host);

        if ($found->is(CloudflareResult::OK)) {
            return CloudflareResult::of(CloudflareResult::ADOPTED, $found->data, null, $found->http_status);
        }

        if (! $found->is(CloudflareResult::ABSENT)) {
            return $found;
        }

        $count = $this->countPagesDomains();

        if (! $count->is(CloudflareResult::OK)) {
            return $count;
        }

        $used = (int) $count->data['count'];
        $ceiling = (int) config('cloudflare.pages_domain_ceiling');

        if ($used >= $ceiling) {
            return CloudflareResult::of(
                CloudflareResult::CONFLICT,
                ['reason' => 'capacity', 'count' => $used, 'ceiling' => $ceiling],
                "The {$this->project()} Pages project already has {$used} of its {$ceiling} custom domains.",
                $count->http_status,
            );
        }

        $created = $this->request('POST', $this->pagesDomainsPath(), ['name' => strtolower($host)]);

        if (! $created->is(CloudflareResult::OK)) {
            return $created;
        }

        return CloudflareResult::of(CloudflareResult::CREATED, self::pagesDomain((array) ($created->data['result'] ?? [])), null, $created->http_status);
    }

    /** Ask Pages to validate `host` again (PATCH on the custom domain). */
    public function retryPagesDomain(string $host): CloudflareResult
    {
        if (! $this->isConfigured()) {
            return CloudflareResult::notConfigured();
        }

        $response = $this->request('PATCH', $this->pagesDomainsPath() . '/' . rawurlencode(strtolower($host)));

        if (! $response->is(CloudflareResult::OK)) {
            return $response;
        }

        return CloudflareResult::of(CloudflareResult::OK, self::pagesDomain((array) ($response->data['result'] ?? [])), null, $response->http_status);
    }

    /**
     * The A, AAAA and CNAME records at exactly `host`, judged: `absent` when
     * there are none, `adopted` for a lone CNAME to the Pages target,
     * `conflict` otherwise.
     */
    private function cnameState(string $zoneId, string $host): CloudflareResult
    {
        $response = $this->request('GET', '/zones/' . rawurlencode($zoneId) . '/dns_records', [
            'name.exact' => $host,
            'per_page' => 100,
        ]);

        if (! $response->is(CloudflareResult::OK)) {
            return $response;
        }

        // Filtered again here by name as well as type: the name match must be
        // exact even if the API ever ignored the filter, because a record for
        // another host judged as this one's would be adopted or refused wrongly.
        $records = array_values(array_filter(
            (array) ($response->data['result'] ?? []),
            fn ($record) => is_array($record)
                && strtolower(rtrim((string) ($record['name'] ?? ''), '.')) === $host
                && in_array(strtoupper((string) ($record['type'] ?? '')), self::ADDRESS_TYPES, true),
        ));

        if ($records === []) {
            return CloudflareResult::of(CloudflareResult::ABSENT, [], null, $response->http_status);
        }

        $target = strtolower((string) config('cloudflare.pages_target'));
        $first = $records[0];
        $shape = [
            'id' => (string) ($first['id'] ?? ''),
            'type' => strtoupper((string) ($first['type'] ?? '')),
            'content' => (string) ($first['content'] ?? ''),
        ];

        if (count($records) === 1
            && $shape['type'] === 'CNAME'
            && strtolower(rtrim($shape['content'], '.')) === $target) {
            return CloudflareResult::of(CloudflareResult::ADOPTED, $shape, null, $response->http_status);
        }

        return CloudflareResult::of(
            CloudflareResult::CONFLICT,
            $shape,
            "{$host} already has a DNS record ({$shape['type']} {$shape['content']}).",
            $response->http_status,
        );
    }

    /**
     * Send one request and map the answer onto an outcome:
     * 401/403 `unauthorized`, 429 `rate_limited`, 5xx or no answer
     * `transient`, `success: false` `rejected`, otherwise `ok` with the body as
     * data. Rejections carry Cloudflare's error codes in data.codes.
     *
     * @param  array<string, mixed>  $payload  query for GET, JSON body otherwise
     */
    private function request(string $method, string $path, array $payload = []): CloudflareResult
    {
        if (! in_array($method, self::VERBS, true)) {
            throw new LogicException("CloudflareService never sends {$method}.");
        }

        try {
            $client = $this->client();
            // A write with nothing to say (the activation check, the Pages
            // retry) goes with no body at all rather than a JSON "[]".
            $response = match (true) {
                $method === 'GET' => $client->get($path, $payload),
                $payload === [] => $client->send($method, $path),
                default => $client->send($method, $path, ['json' => $payload]),
            };
        } catch (ConnectionException $e) {
            return $this->unsuccessful($method, $path, CloudflareResult::of(
                CloudflareResult::TRANSIENT,
                [],
                'Cloudflare did not answer: ' . $this->redact($e->getMessage()),
            ));
        }

        $status = $response->status();
        $body = $response->json();
        $body = is_array($body) ? $body : [];
        $errors = array_values(array_filter((array) ($body['errors'] ?? []), 'is_array'));
        $message = $this->redact(implode('; ', array_map(
            fn (array $error) => trim(($error['code'] ?? '') . ' ' . ($error['message'] ?? '')),
            $errors,
        )));
        $codes = array_values(array_map(fn (array $error) => (int) ($error['code'] ?? 0), $errors));

        $outcome = match (true) {
            $status === 401, $status === 403 => CloudflareResult::UNAUTHORIZED,
            $status === 429 => CloudflareResult::RATE_LIMITED,
            $status >= 500 => CloudflareResult::TRANSIENT,
            ($body['success'] ?? false) !== true => CloudflareResult::REJECTED,
            default => CloudflareResult::OK,
        };

        if ($outcome === CloudflareResult::OK) {
            return CloudflareResult::of(CloudflareResult::OK, $body, null, $status);
        }

        return $this->unsuccessful($method, $path, CloudflareResult::of(
            $outcome,
            ['codes' => $codes],
            $message !== '' ? $message : "Cloudflare answered HTTP {$status}.",
            $status,
        ));
    }

    /**
     * Log a call that did not succeed, at warning because production logs
     * nothing below it (.claude/rules/shipping.md). The context names the call
     * and the outcome; it never carries a header, and the error is already
     * redacted. A 404 is how a read says "not there" and is not logged.
     */
    private function unsuccessful(string $method, string $path, CloudflareResult $result): CloudflareResult
    {
        if ($result->http_status !== 404) {
            Log::warning('Cloudflare API call did not succeed.', [
                'method' => $method,
                'path' => $path,
                'outcome' => $result->outcome,
                'http_status' => $result->http_status,
                'error' => $result->error,
            ]);
        }

        return $result;
    }

    private function client(): PendingRequest
    {
        $timeout = (int) config('cloudflare.timeout', 15);

        return Http::baseUrl(rtrim((string) config('cloudflare.api_base'), '/'))
            ->withToken((string) config('cloudflare.studio_token'))
            ->acceptJson()
            ->asJson()
            ->timeout($timeout)
            ->connectTimeout($timeout);
    }

    /** Cloudflare's words with the token cut out, and bounded. */
    private function redact(string $text): string
    {
        $token = (string) config('cloudflare.studio_token');

        if ($token !== '') {
            $text = str_replace($token, '[redacted]', $text);
        }

        return mb_strimwidth($text, 0, 1000, '...');
    }

    private static function recordExists(CloudflareResult $result): bool
    {
        return array_intersect(self::ALREADY_EXISTS_CODES, (array) ($result->data['codes'] ?? [])) !== []
            || str_contains(strtolower((string) $result->error), 'already exists');
    }

    private function project(): string
    {
        return (string) config('cloudflare.pages_project');
    }

    private function pagesDomainsPath(): string
    {
        return '/accounts/' . rawurlencode((string) config('cloudflare.account_id'))
            . '/pages/projects/' . rawurlencode($this->project()) . '/domains';
    }

    /**
     * @param  array<string, mixed>  $zone
     * @return array{id: string, name: string, status: string, name_servers: list<string>, created_on: ?string}
     */
    private static function zone(array $zone): array
    {
        return [
            'id' => (string) ($zone['id'] ?? ''),
            'name' => strtolower((string) ($zone['name'] ?? '')),
            'status' => (string) ($zone['status'] ?? ''),
            'name_servers' => array_values(array_map('strval', (array) ($zone['name_servers'] ?? []))),
            'created_on' => isset($zone['created_on']) ? (string) $zone['created_on'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $domain
     * @return array{id: string, name: string, status: string, validation_data: array<string, mixed>, verification_data: array<string, mixed>, zone_tag: ?string, created_on: ?string}
     */
    private static function pagesDomain(array $domain): array
    {
        return [
            'id' => (string) ($domain['id'] ?? ''),
            'name' => strtolower((string) ($domain['name'] ?? '')),
            'status' => (string) ($domain['status'] ?? ''),
            'validation_data' => (array) ($domain['validation_data'] ?? []),
            'verification_data' => (array) ($domain['verification_data'] ?? []),
            'zone_tag' => isset($domain['zone_tag']) ? (string) $domain['zone_tag'] : null,
            'created_on' => isset($domain['created_on']) ? (string) $domain['created_on'] : null,
        ];
    }
}
