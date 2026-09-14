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
 * `capability:a,b` means ANY OF: the request passes when the organisation has
 * at least one of the keys (the forms write routes take `web_pages` or
 * `form_editing`).
 *
 * Runs after `tenant`, exactly like `crm` (EnsureCrmEnabled), so the
 * organisation the request acts on is already resolved; it falls back to the
 * route param so the gate never depends on middleware order.
 *
 * The two kinds are read differently, on purpose:
 *  - a MODULE key (Masjid::MODULE_KEYS) passes unless Masjid::moduleIsOff(),
 *    which fails OPEN — a config cache from before the module existed answers
 *    with the org type's default (Masjid::MODULE_DEFAULTS), so a deploy never
 *    takes a screen away. Its refusal says "switched off" for a module the
 *    org type is offered and "not switched on" for one it is not;
 *  - any other key passes only when Masjid::hasCapability(), which fails
 *    CLOSED — a typo in a route is never a grant, and CapabilityGateTest lints
 *    every `capability:` in the route table against the catalogue so it cannot
 *    ship.
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

    public function handle(Request $request, Closure $next, string ...$capabilities): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->type === 'SuperAdmin') {
            return $next($request);
        }

        $masjidId = $this->tenant->get() ?? $request->route('masjid_id');
        $masjid = $masjidId !== null ? Masjid::find($masjidId) : null;

        if ($masjid !== null) {
            foreach ($capabilities as $capability) {
                $passes = in_array($capability, Masjid::MODULE_KEYS, true)
                    ? ! $masjid->moduleIsOff($capability)
                    : $masjid->hasCapability($capability);

                if ($passes) {
                    return $next($request);
                }
            }
        }

        // RETURNED, not abort()ed: the JSON exception renderer replaces an
        // HttpException's message with "Request failed." whenever app.debug is
        // off, so an abort() sentence reaches the tests (APP_DEBUG=true) and never
        // production. Same {status, message} envelope that renderer uses.
        return response()->json([
            'status' => 'error',
            'message' => $this->refusal($capabilities, $masjid),
        ], Response::HTTP_FORBIDDEN);
    }

    /**
     * The sentence, in the catalogue's own labels.
     *
     * A module this organisation's type is offered was switched OFF by a
     * SuperAdmin. A module it is not offered (Giving at a school) was never
     * switched ON, and "switched off" there would describe a decision nobody
     * made.
     */
    private function refusal(array $capabilities, ?Masjid $masjid): string
    {
        $labels = array_map(fn (string $key) => config("capabilities.{$key}.label", $key), $capabilities);

        if (count($capabilities) === 1 && in_array($capabilities[0], Masjid::MODULE_KEYS, true)) {
            return $masjid === null || $masjid->moduleOfferedByDefault($capabilities[0])
                ? "{$labels[0]} is switched off for this organisation."
                : "{$labels[0]} is not switched on for this organisation.";
        }

        return implode(' or ', $labels) . ' is not switched on for this organisation.';
    }
}
