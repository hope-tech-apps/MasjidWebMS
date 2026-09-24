<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Studio\StudioDomainCheckRequest;
use App\Models\MasjidDomain;
use App\Services\Cloudflare\CloudflareResult;
use App\Services\Cloudflare\CloudflareService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Manara Studio's domain step: may this host be given to a new organisation?
 *
 * A host is available only when no `masjid_domains` row holds it, whatever that
 * row's status: a `reserved` row is exactly a host held for an organisation
 * (R4), and `host` is unique, so it could not be stored twice anyway. The
 * holder's id is returned so the operator can see who has it.
 *
 * Without CLOUDFLARE_STUDIO_TOKEN it makes no Cloudflare call: `case` is
 * `managed_subdomain` for our own suffix and `unknown` for a custom host, and
 * `pages_domains_used` is null. With the token (S7) it READS Cloudflare, never
 * writes: which case the host is (`managed_subdomain`, `zone_in_account`,
 * `zone_not_in_account`, or `unknown` when Cloudflare could not say), the
 * zone's status, and how many custom domains the renderer project already
 * carries. `zone_status` is present only then, so the answer without a token
 * is byte-for-byte what S3 shipped.
 */
class StudioDomainCheckController extends Controller
{
    public function check(StudioDomainCheckRequest $request, CloudflareService $cloudflare)
    {
        $host = $request->domainHost();
        $holder = MasjidDomain::query()->where('host', $host)->value('masjid_id');
        $managed = $request->validated('kind') === MasjidDomain::KIND_MANAGED_SUBDOMAIN;
        $configured = $cloudflare->isConfigured();

        $data = [
            'host' => $host,
            'available' => $holder === null,
            'taken_by_masjid_id' => $holder !== null ? (int) $holder : null,
            'case' => $managed ? MasjidDomain::KIND_MANAGED_SUBDOMAIN : 'unknown',
            'token_configured' => $configured,
            'pages_domains_used' => null,
            'pages_domains_ceiling' => (int) config('cloudflare.pages_domain_ceiling'),
        ];

        if ($configured) {
            $zone = $managed
                ? $cloudflare->getZone((string) config('cloudflare.managed_zone_id'))
                : $cloudflare->findZone((string) $request->validated('zone_apex'));

            if (! $managed) {
                $data['case'] = match (true) {
                    $zone->is(CloudflareResult::OK) => 'zone_in_account',
                    $zone->is(CloudflareResult::ABSENT) => 'zone_not_in_account',
                    default => 'unknown',
                };
            }

            $data['zone_status'] = $zone->is(CloudflareResult::OK) ? ($zone->data['status'] ?: null) : null;

            $count = $cloudflare->countPagesDomains();
            $data['pages_domains_used'] = $count->is(CloudflareResult::OK) ? (int) $count->data['count'] : null;
        }

        return response()->json(['status' => 'success', 'data' => $data], Response::HTTP_OK);
    }
}
