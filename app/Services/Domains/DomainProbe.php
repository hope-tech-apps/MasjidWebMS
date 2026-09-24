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

    public const HEADER = 'x-manara-tenant';

    public function __construct(private readonly HostResolver $resolver)
    {
    }

    /**
     * @return array{matched: bool, seen: string}
     */
    public function probe(MasjidDomain $domain): array
    {
        $host = (string) $domain->host;
        $addresses = $this->resolver->addresses($host);

        if ($addresses === []) {
            return ['matched' => false, 'seen' => "not fetched: {$host} does not resolve"];
        }

        foreach ($addresses as $address) {
            if (! self::isPublicAddress($address)) {
                return ['matched' => false, 'seen' => "refused: {$host} resolves to {$address}, which is not a public address"];
            }
        }

        $pinned = $addresses[0];
        $resolve = $host . ':443:' . (str_contains($pinned, ':') ? "[{$pinned}]" : $pinned);

        try {
            $response = Http::withOptions(['curl' => [CURLOPT_RESOLVE => [$resolve]]])
                ->withoutRedirecting()
                ->timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(self::TIMEOUT_SECONDS)
                ->accept('application/json')
                ->get("https://{$host}/api/tenant");
        } catch (Throwable $e) {
            // An unreachable host is an ordinary answer here, not an incident:
            // the caller records `seen`. Logged at warning because production
            // logs nothing below it (.claude/rules/shipping.md).
            Log::warning('Domain probe could not reach the host.', ['host' => $host, 'error' => $e->getMessage()]);

            return ['matched' => false, 'seen' => 'no answer: ' . mb_strimwidth($e->getMessage(), 0, 200, '...')];
        }

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

        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_GLOBAL_RANGE
        ) !== false;
    }
}
