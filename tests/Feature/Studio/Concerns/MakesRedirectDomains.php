<?php

namespace Tests\Feature\Studio\Concerns;

use App\Models\Masjid;
use App\Models\MasjidDomain;
use Illuminate\Support\Facades\Http;

/**
 * An apex/www pair (W2 S5): `www.pair-masjid.org` serving and attached, and
 * `pair-masjid.org` redirecting to it, in the zone `zone-pair`. Used with
 * FakesCloudflare and MakesStudioDomains.
 */
trait MakesRedirectDomains
{
    private const APEX = 'pair-masjid.org';

    private const WWW = 'www.pair-masjid.org';

    private const PAIR_ZONE = 'zone-pair';

    private const ENTRYPOINT = '/zones/zone-pair/rulesets/phases/http_request_dynamic_redirect/entrypoint';

    /** The canonical `www` host, attached by Studio and seen serving. */
    private function canonicalRow(Masjid $org, array $attributes = []): MasjidDomain
    {
        return $this->makeDomain($org, self::WWW, MasjidDomain::STATUS_ACTIVE, array_merge([
            'zone_apex' => self::APEX,
            'verified_by' => MasjidDomain::VERIFIED_BY_CLOUDFLARE,
            'verified_at' => now(),
            'serving_confirmed_at' => now(),
            'cf_zone_id' => self::PAIR_ZONE,
            'cf_dns_record_id' => 'rec-www',
            'cf_dns_record_created' => true,
            'cf_pages_domain_id' => 'pd-' . md5(self::WWW),
            'cf_pages_domain_created' => true,
        ], $attributes));
    }

    /** The apex, redirecting to `$canonical`. */
    private function redirectRow(Masjid $org, MasjidDomain $canonical, string $status = MasjidDomain::STATUS_PENDING, array $attributes = []): MasjidDomain
    {
        return $this->makeDomain($org, self::APEX, $status, array_merge([
            'zone_apex' => self::APEX,
            'role' => MasjidDomain::ROLE_REDIRECT,
            'redirect_to_id' => $canonical->id,
        ], $attributes));
    }

    /** The rule Studio writes for `$row`, as Cloudflare lists it back. */
    private function studioRule(MasjidDomain $row, string $id = 'rule-studio'): array
    {
        return [
            'id' => $id,
            'ref' => MasjidDomain::REDIRECT_RULE_REF . $row->id,
            'action' => 'redirect',
            'enabled' => true,
            'expression' => 'http.host eq "' . self::APEX . '"',
            'action_parameters' => ['from_value' => [
                'status_code' => 301,
                'target_url' => ['expression' => 'concat("https://' . self::WWW . '", http.request.uri.path)'],
                'preserve_query_string' => true,
            ]],
        ];
    }

    /** A rule the client made themselves, which Studio must never touch. */
    private function clientRule(): array
    {
        return [
            'id' => 'rule-client',
            'ref' => 'client-own',
            'action' => 'redirect',
            'enabled' => true,
            'expression' => 'http.request.uri.path eq "/old"',
            'action_parameters' => ['from_value' => ['status_code' => 302, 'target_url' => ['value' => 'https://' . self::WWW . '/new']]],
        ];
    }

    /** @param  list<array<string, mixed>>  $rules */
    private function ruleset(array $rules, string $id = 'rs-1'): array
    {
        return ['id' => $id, 'kind' => 'zone', 'phase' => 'http_request_dynamic_redirect', 'name' => 'default', 'rules' => $rules];
    }

    /** The apex answers 301 to the canonical host, or with `$status` somewhere else. */
    private function apexAnswers(int $status = 301, string $location = 'https://www.pair-masjid.org/'): array
    {
        return ['GET https://' . self::APEX . '/' => Http::response('', $status, $location !== '' ? ['Location' => $location] : [])];
    }
}
