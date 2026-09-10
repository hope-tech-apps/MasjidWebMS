<?php

namespace App\Http\Middleware;

use App\Models\Masjid;
use App\Models\User;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `capability:<key>` — 403s unless the bound organisation HAS that capability
 * (config/capabilities.php, layer 1 of the access model).
 *
 * Runs after `tenant`, exactly like `crm` (EnsureCrmEnabled), so the
 * organisation the request acts on is already resolved; it falls back to the
 * route param so the gate never depends on middleware order. A key that is not
 * in the catalogue is never granted — a typo in a route fails closed, and
 * CapabilityGateTest lints every `capability:` in the route table against the
 * catalogue so it cannot ship.
 *
 * SuperAdmins pass: they are the platform operator, not an organisation's
 * staff, and they set organisations up (Web Pages was theirs alone before this
 * gate existed). Column-backed capabilities (crm, assistant) keep their own
 * middleware and are not routed through here.
 */
class EnsureOrgCapability
{
    public function __construct(private TenantContext $tenant)
    {
    }

    public function handle(Request $request, Closure $next, string $capability): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->type === 'SuperAdmin') {
            return $next($request);
        }

        $masjidId = $this->tenant->get() ?? $request->route('masjid_id');
        $masjid = $masjidId !== null ? Masjid::find($masjidId) : null;

        if ($masjid === null || ! $masjid->hasCapability($capability)) {
            $label = config("capabilities.{$capability}.label", $capability);

            abort(Response::HTTP_FORBIDDEN, "{$label} is not switched on for this organisation.");
        }

        return $next($request);
    }
}
