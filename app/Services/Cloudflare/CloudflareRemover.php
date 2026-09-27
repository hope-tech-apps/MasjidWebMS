<?php

namespace App\Services\Cloudflare;

use App\Models\MasjidDomain;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The only DELETE requests in the codebase (Manara Studio W2, S3; plan R8).
 *
 * CloudflareService can make things and look at things and nothing else, and a
 * test pins that. Detaching a host needs the opposite, so it lives here, kept
 * small enough to review line by line, because the Studio token can edit every
 * zone in the account: `burlingtonmasjid.com` and `alrazischool.org` included.
 *
 * ## What it will delete
 *
 * Only an object Studio's own POST created, as the row records it
 * (`cf_pages_domain_created`, `cf_dns_record_created`), on a row Studio owns
 * (`source = studio`, never adopted from the import). Everything else is
 * refused before any request is built: an ADOPTED object is stored by id just
 * like a created one, and "it matches what Studio would have created" is not
 * the same as "Studio created it".
 *
 * Each delete RE-READS the object first and goes ahead only if it is still
 * exactly what Studio made: the same id, the same host, and for a DNS record
 * the same type and content. Anything changed since is left alone and
 * reported as `conflict`; somebody meant that change.
 *
 * ## What it will never delete
 *
 * A zone. There is no zone method, and no path here can build one: a zone
 * Studio created (`cf_zone_created`) holds the client's whole DNS, email
 * included, so removing it stays a person's decision
 * (MasjidDomain::zoneRemovalStep()). A test pins that no public method can.
 *
 * ## Outcomes
 *
 *  - `ok`: deleted, data {removed: true, id}.
 *  - `absent`: already gone (the read or the delete answered 404). The caller
 *    forgets the id; there is nothing left to remove.
 *  - `conflict`: refused, data {reason}: `not_configured` is separate;
 *    `imported` (the row is not Studio's), `not_created_by_studio` (the flag is
 *    false or the id is missing), `changed` (the re-read no longer matches,
 *    with what it saw).
 *  - anything else CloudflareService can answer (`unauthorized`,
 *    `rate_limited`, `transient`, `rejected`, `not_configured`), which the
 *    caller records and retries.
 *
 * ## What the API reference said at build time, read 2026-09-27
 *
 *  - DELETE /accounts/{account_id}/pages/projects/{project_name}/domains/{domain_name}
 *    ("Delete Pages Project Custom Domain", permission Pages Write):
 *    https://developers.cloudflare.com/api/resources/pages/subresources/projects/subresources/domains/methods/delete/
 *    The v4 envelope with an untyped `result`.
 *  - DELETE /zones/{zone_id}/dns_records/{dns_record_id} (permission DNS
 *    Write), answering `result: {id}`; GET on the same path reads one record:
 *    https://developers.cloudflare.com/api/resources/dns/subresources/records/methods/delete/
 *  Both are covered by the W1 token scopes (Pages Edit, DNS Edit), so detach
 *  needs no new scope. The fixtures in tests/Feature/Studio/CloudflareRemoverTest.php
 *  are shaped on these pages.
 *
 * The token never leaves this class: Authorization header only, and every
 * word Cloudflare says back is redacted before it reaches a result or a log.
 */
class CloudflareRemover
{
    public function __construct(private readonly CloudflareService $cloudflare)
    {
    }

    /**
     * Remove the row's Pages custom domain from the renderer project, if
     * Studio added it and it is still the one Studio added.
     */
    public function removePagesDomain(MasjidDomain $row): CloudflareResult
    {
        if (($refused = $this->refuse($row, (bool) $row->cf_pages_domain_created, $row->cf_pages_domain_id)) !== null) {
            return $refused;
        }

        // Read through the project this deployment serves, by the row's own
        // host: the project and the name are part of the path, so a domain on
        // another project or under another name is never the one read.
        $seen = $this->cloudflare->getPagesDomain($row->host);

        if (! $seen->is(CloudflareResult::OK)) {
            return $seen;
        }

        if (($seen->data['name'] ?? null) !== $row->host || ($seen->data['id'] ?? null) !== $row->cf_pages_domain_id) {
            return $this->changed($row, 'Pages custom domain', [
                'id' => $seen->data['id'] ?? null,
                'name' => $seen->data['name'] ?? null,
            ]);
        }

        return $this->delete($this->pagesDomainsPath() . '/' . rawurlencode($row->host));
    }

    /**
     * Remove the row's DNS record, if Studio created it and its type,
     * content, name and zone are still exactly `$expectedType` /
     * `$expectedContent` / the row's host / the row's zone. The caller says
     * what the record must look like, from the row's role: a CNAME to the
     * Pages target for a serving host.
     */
    public function removeDnsRecord(MasjidDomain $row, string $expectedType, string $expectedContent): CloudflareResult
    {
        if (($refused = $this->refuse($row, (bool) $row->cf_dns_record_created, $row->cf_dns_record_id)) !== null) {
            return $refused;
        }

        if (blank($row->cf_zone_id)) {
            return CloudflareResult::of(CloudflareResult::CONFLICT, ['reason' => 'not_created_by_studio'],
                "Studio has no zone recorded for {$row->host}'s DNS record, so it cannot tell where the record is.");
        }

        $path = '/zones/' . rawurlencode((string) $row->cf_zone_id) . '/dns_records/' . rawurlencode((string) $row->cf_dns_record_id);
        $seen = $this->send('GET', $path);

        if ($seen->is(CloudflareResult::REJECTED) && $seen->http_status === 404) {
            return CloudflareResult::of(CloudflareResult::ABSENT, [], null, 404);
        }

        if (! $seen->is(CloudflareResult::OK)) {
            return $seen;
        }

        $record = (array) ($seen->data['result'] ?? []);
        $shape = [
            'id' => (string) ($record['id'] ?? ''),
            'name' => self::host((string) ($record['name'] ?? '')),
            'type' => strtoupper((string) ($record['type'] ?? '')),
            'content' => self::host((string) ($record['content'] ?? '')),
            // Older answers carry the zone; the current reference does not
            // promise it. When present it must agree with the path's.
            'zone_id' => isset($record['zone_id']) ? (string) $record['zone_id'] : null,
        ];

        if ($shape['id'] !== $row->cf_dns_record_id
            || $shape['name'] !== $row->host
            || $shape['type'] !== strtoupper($expectedType)
            || $shape['content'] !== self::host($expectedContent)
            || ($shape['zone_id'] !== null && $shape['zone_id'] !== $row->cf_zone_id)) {
            return $this->changed($row, 'DNS record', $shape);
        }

        return $this->delete($path);
    }

    /**
     * Refuse before any request: no token, a row that is not Studio's, or an
     * object Studio did not create. Null means go on.
     */
    private function refuse(MasjidDomain $row, bool $createdByStudio, ?string $id): ?CloudflareResult
    {
        if (! $this->cloudflare->isConfigured()) {
            return CloudflareResult::notConfigured();
        }

        if (! $row->ownedByStudio()) {
            return CloudflareResult::of(CloudflareResult::CONFLICT, ['reason' => 'imported'],
                "{$row->host} came from the live host map, so Studio removes nothing in Cloudflare for it.");
        }

        if (! $createdByStudio || blank($id)) {
            return CloudflareResult::of(CloudflareResult::CONFLICT, ['reason' => 'not_created_by_studio'],
                "Studio did not create this Cloudflare object for {$row->host}, so it does not remove it.");
        }

        return null;
    }

    /** @param  array<string, mixed>  $seen */
    private function changed(MasjidDomain $row, string $what, array $seen): CloudflareResult
    {
        Log::warning("Studio did not remove a {$what}: it has changed since Studio created it.", [
            'masjid_domain_id' => $row->id,
            'host' => $row->host,
            'seen' => $seen,
        ]);

        return CloudflareResult::of(CloudflareResult::CONFLICT, ['reason' => 'changed', 'seen' => $seen],
            "The {$what} for {$row->host} has changed since Studio created it, so Studio left it alone.");
    }

    /** DELETE, with a 404 read as "already gone". */
    private function delete(string $path): CloudflareResult
    {
        $deleted = $this->send('DELETE', $path);

        if ($deleted->is(CloudflareResult::REJECTED) && $deleted->http_status === 404) {
            return CloudflareResult::of(CloudflareResult::ABSENT, [], null, 404);
        }

        if (! $deleted->is(CloudflareResult::OK)) {
            return $deleted;
        }

        return CloudflareResult::of(CloudflareResult::OK, [
            'removed' => true,
            'id' => isset($deleted->data['result']['id']) ? (string) $deleted->data['result']['id'] : null,
        ], null, $deleted->http_status);
    }

    /**
     * One request, mapped onto an outcome exactly as CloudflareService maps
     * its own: 401/403 `unauthorized`, 429 `rate_limited`, 5xx or no answer
     * `transient`, `success: false` `rejected`, otherwise `ok`. GET and
     * DELETE only.
     */
    private function send(string $method, string $path): CloudflareResult
    {
        try {
            $response = $method === 'GET' ? $this->client()->get($path) : $this->client()->delete($path);
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
            ['codes' => array_values(array_map(fn (array $error) => (int) ($error['code'] ?? 0), $errors))],
            $message !== '' ? $message : "Cloudflare answered HTTP {$status}.",
            $status,
        ));
    }

    /** At warning, which production logs; never a header; a 404 is not logged. */
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
            ->timeout($timeout)
            ->connectTimeout($timeout);
    }

    private function redact(string $text): string
    {
        $token = (string) config('cloudflare.studio_token');

        if ($token !== '') {
            $text = str_replace($token, '[redacted]', $text);
        }

        return mb_strimwidth($text, 0, 1000, '...');
    }

    private function pagesDomainsPath(): string
    {
        return '/accounts/' . rawurlencode((string) config('cloudflare.account_id'))
            . '/pages/projects/' . rawurlencode((string) config('cloudflare.pages_project')) . '/domains';
    }

    private static function host(string $name): string
    {
        return strtolower(rtrim(trim($name), '.'));
    }
}
