<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Studio\StudioDomainCheckRequest;
use App\Models\MasjidDomain;
use Symfony\Component\HttpFoundation\Response;

/**
 * Manara Studio's domain step: may this host be given to a new organisation?
 *
 * A host is available only when no `masjid_domains` row holds it, whatever that
 * row's status: a `reserved` row is exactly a host held for an organisation
 * (R4), and `host` is unique, so it could not be stored twice anyway. The
 * holder's id is returned so the operator can see who has it.
 *
 * S3 makes no Cloudflare call. `case` is `managed_subdomain` for our own
 * suffix and `unknown` for a custom host; S7 adds zone detection and the Pages
 * domain count, so `pages_domains_used` is null until then.
 */
class StudioDomainCheckController extends Controller
{
    public function check(StudioDomainCheckRequest $request)
    {
        $host = $request->domainHost();
        $holder = MasjidDomain::query()->where('host', $host)->value('masjid_id');

        return response()->json([
            'status' => 'success',
            'data' => [
                'host' => $host,
                'available' => $holder === null,
                'taken_by_masjid_id' => $holder !== null ? (int) $holder : null,
                'case' => $request->validated('kind') === MasjidDomain::KIND_MANAGED_SUBDOMAIN
                    ? MasjidDomain::KIND_MANAGED_SUBDOMAIN
                    : 'unknown',
                'token_configured' => filled(config('cloudflare.studio_token')),
                'pages_domains_used' => null,
                'pages_domains_ceiling' => (int) config('cloudflare.pages_domain_ceiling'),
            ],
        ], Response::HTTP_OK);
    }
}
