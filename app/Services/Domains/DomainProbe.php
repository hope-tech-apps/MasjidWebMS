<?php

namespace App\Services\Domains;

use App\Models\MasjidDomain;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asks a host whether it is serving OUR site for THIS organisation.
 *
 * The renderer answers `GET /api/tenant` on every host it serves and sets
 * `x-manara-tenant: <masjid id>` on every response
 * (renderer:server/middleware/tenant.ts). A 200 carrying the row's own masjid
 * id is the only thing that counts as a match: a certificate, a DNS record or a
 * Cloudflare "active" says the host is attached somewhere, not that the right
 * organisation is being served on it. A match is what sets
 * `serving_confirmed_at` (R24), and it is the only way a host ever becomes
 * CORS-admitted.
 *
 * The probe never sets `active`: only Cloudflare's own verification can
 * (MasjidDomain's saving invariant). A probed host is `manual`.
 *
 * ## It fetches a host a person typed, from our own server
 *
 * So it is an SSRF surface, and it is shut three ways:
 *  - the host is resolved HERE, through HostResolver, and the fetch is refused
 *    when any address is loopback, private, link-local, reserved or otherwise
 *    not globally routable;
 *  - the connection is pinned to the address that was checked (CURLOPT_RESOLVE),
 *    so a second DNS answer between the check and the connect cannot swap in an
 *    internal address;
 *  - redirects are never followed, so a public host cannot bounce the request
 *    somewhere the check never saw. A redirect is simply not a match.
 */
class DomainProbe
{
    public const TIMEOUT_SECONDS = 5;

    /**
     * The most of a body a probe will take (review follow-up 6). Only the
     * status and the headers decide anything; the renderer's /api/tenant and a
     * redirect are a few hundred bytes. A host that sends more is cut off,
     * which reads as "no answer", a miss.
     */
    public const MAX_BODY_BYTES = 65536;

    public const HEADER = 'x-manara-tenant';

    public function __construct(private readonly HostResolver $resolver)
    {
    }

    /**
     * @return array{matched: bool, seen: string}
     */
    public function probe(MasjidDomain $domain): array
    {
        $fetched = $this->fetch((string) $domain->host, '/api/tenant');

        if ($fetched['response'] === null) {
            return ['matched' => false, 'seen' => $fetched['seen']];
        }

        $response = $fetched['response'];
        $status = $response->status();
        $tenant = $response->header(self::HEADER);

        if ($status >= 300 && $status < 400) {
            return ['matched' => false, 'seen' => "{$status} redirect to " . mb_strimwidth((string) $response->header('Location'), 0, 200, '...') . ' (not followed)'];
        }

        $matched = $status === 200 && $tenant === (string) $domain->masjid_id;

        return [
            'matched' => $matched,
            'seen' => "{$status}, " . self::HEADER . ': ' . ($tenant === '' ? '(absent)' : mb_strimwidth($tenant, 0, 40, '...')),
        ];
    }

    /**
     * Whether a redirect host (W2 S5) answers `https://<host>/` with a 301 to
     * `https://<canonical>`: the Single Redirect rule working at Cloudflare's
     * edge. The redirect is read, never followed, under the same SSRF guard
     * as probe().
     *
     * @return array{matched: bool, seen: string}
     */
    public function redirects(MasjidDomain $domain, string $canonical): array
    {
        $fetched = $this->fetch((string) $domain->host, '/');

        if ($fetched['response'] === null) {
            return ['matched' => false, 'seen' => $fetched['seen']];
        }

        $status = $fetched['response']->status();
        $location = (string) $fetched['response']->header('Location');
        $target = parse_url($location);
        $matched = $status === 301
            && strtolower((string) ($target['scheme'] ?? '')) === 'https'
            && strtolower((string) ($target['host'] ?? '')) === strtolower($canonical);

        return [
            'matched' => $matched,
            'seen' => $status . ($location !== '' ? ' to ' . mb_strimwidth($location, 0, 200, '...') : ''),
        ];
    }

    /**
     * GET `https://<host><path>` with no redirect followed, only to a public
     * address and pinned to the one that was checked. An unreachable host is
     * an ordinary answer (`response` null, with what was seen), logged at
     * warning because production logs nothing below it.
     *
     * @return array{response: ?\Illuminate\Http\Client\Response, seen: string}
     */
    private function fetch(string $host, string $path): array
    {
        $addresses = $this->resolver->addresses($host);

        if ($addresses === []) {
            return ['response' => null, 'seen' => "not fetched: {$host} does not resolve"];
        }

        foreach ($addresses as $address) {
            if (! self::isPublicAddress($address)) {
                return ['response' => null, 'seen' => "refused: {$host} resolves to {$address}, which is not a public address"];
            }
        }

        $pinned = $addresses[0];
        $resolve = $host . ':443:' . (str_contains($pinned, ':') ? "[{$pinned}]" : $pinned);

        try {
            $response = Http::withOptions(['curl' => [
                CURLOPT_RESOLVE => [$resolve],
                // Refused up front when the length is declared, and cut off
                // mid-transfer when it is not.
                CURLOPT_MAXFILESIZE => self::MAX_BODY_BYTES,
                CURLOPT_NOPROGRESS => false,
                CURLOPT_XFERINFOFUNCTION => static fn ($curl, int $downloadTotal, int $downloaded): int => $downloaded > self::MAX_BODY_BYTES ? 1 : 0,
            ]])
                ->withoutRedirecting()
                ->timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(self::TIMEOUT_SECONDS)
                ->accept('application/json')
                ->get("https://{$host}{$path}");
        } catch (Throwable $e) {
            Log::warning('Domain probe could not reach the host.', ['host' => $host, 'error' => $e->getMessage()]);

            return ['response' => null, 'seen' => 'no answer: ' . mb_strimwidth($e->getMessage(), 0, 200, '...')];
        }

        return ['response' => $response, 'seen' => ''];
    }

    /**
     * Probe, and on a match stamp the row (unsaved) as seen serving: always
     * `serving_confirmed_at`, and for a row Cloudflare has not verified, status
     * `manual` verified by the probe. It never writes `active`, and it never
     * touches a row on a miss beyond `last_checked_at`.
     *
     * A `reserved` row gets `last_checked_at` and nothing else, match or not:
     * it is never advanced (R4), so a held host such as meccharlotte.org cannot
     * become served or CORS-admitted because it started answering. A `failed`
     * row that matches does become `manual`: our own site answering with this
     * organisation's id is the proof a pending row needs (R24), whatever went
     * wrong while it was being set up.
     *
     * @return array{matched: bool, seen: string}
     */
    public function confirm(MasjidDomain $domain): array
    {
        $result = $this->probe($domain);
        $now = now();

        $domain->last_checked_at = $now;

        if ($domain->status === MasjidDomain::STATUS_RESERVED) {
            return $result;
        }

        if ($result['matched']) {
            $domain->serving_confirmed_at = $now;

            if ($domain->status !== MasjidDomain::STATUS_ACTIVE) {
                $domain->status = MasjidDomain::STATUS_MANUAL;
                $domain->verified_by = MasjidDomain::VERIFIED_BY_PROBE;
                $domain->verified_at = $now;
            }
        }

        return $result;
    }

    /** The IPv4 address an IPv6 address carries in one of the forms above, or null. */
    private static function embeddedIpv4(string $address): ?string
    {
        if (! str_contains($address, ':')) {
            return null;
        }

        $bytes = @inet_pton($address);

        if ($bytes === false || strlen($bytes) !== 16) {
            return null;
        }

        $v4 = match (true) {
            // ::/96 (IPv4-compatible) and ::ffff:0:0/96 (IPv4-mapped); not ::
            // or ::1, which filter_var already refuses as reserved.
            str_starts_with($bytes, str_repeat("\0", 10) . "\xff\xff") => substr($bytes, 12, 4),
            str_starts_with($bytes, str_repeat("\0", 12)) && substr($bytes, 12, 4) !== "\0\0\0\0" && substr($bytes, 12, 4) !== "\0\0\0\1" => substr($bytes, 12, 4),
            // NAT64 well-known prefix 64:ff9b::/96.
            str_starts_with($bytes, "\x00\x64\xff\x9b" . str_repeat("\0", 8)) => substr($bytes, 12, 4),
            // 6to4 2002::/16: the IPv4 address is the next 32 bits.
            str_starts_with($bytes, "\x20\x02") => substr($bytes, 2, 4),
            default => null,
        };

        return $v4 === null ? null : inet_ntop($v4);
    }

    /**
     * Whether an address is one the probe may connect to: globally routable,
     * so not loopback, private (RFC 1918, fc00::/7), link-local, CGNAT or any
     * other reserved range. An IPv4 address mapped into IPv6 (::ffff:a.b.c.d)
     * is judged by the IPv4 address it carries.
     */
    public static function isPublicAddress(string $address): bool
    {
        if (preg_match('/^::ffff:(\d{1,3}(?:\.\d{1,3}){3})$/i', $address, $m) === 1) {
            $address = $m[1];
        }

        // IPv6 forms that carry an IPv4 address and reach it (review
        // follow-up 5): IPv4-mapped ::ffff:0:0/96 and IPv4-compatible ::/96
        // written in hex, NAT64 64:ff9b::/96 and 6to4 2002::/16. Each is
        // public only if the address it carries is.
        if (($embedded = self::embeddedIpv4($address)) !== null) {
            return self::isPublicAddress($embedded);
        }

        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_GLOBAL_RANGE
        ) !== false;
    }
}
