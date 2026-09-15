<?php

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stamps every admin response with the tenant the SERVER resolved for it.
 *
 * S4 of docs/multi-tenant-admin-design.md ("Every response echoes the
 * server-resolved tenant, and the chrome renders that — not the store").
 *
 * ------------------------------------------------------------------------------
 * The failure this exists to prevent
 * ------------------------------------------------------------------------------
 *
 * Once one human can administer several organisations, the SPA holds a belief
 * about which one it is looking at (`dashboardMasjidId`, a localStorage value,
 * a pinia store) and the server holds a FACT (App\Support\TenantResolver ->
 * TenantContext, which is what every BelongsToMasjid query was actually
 * filtered by). Those two disagree for a whole request every time somebody
 * switches: the store flips first, in-flight responses from the previous
 * organisation land afterwards, and the chrome renders organisation A's name,
 * logo and menu over organisation B's donors and children's records. Nobody
 * gets an error; the screen simply lies about whose data it is showing.
 *
 * A response cannot be mis-attributed if it carries its own answer. The SPA is
 * to render THIS value and drop any payload whose tenant is not the one it is
 * currently showing, rather than trusting its own store.
 *
 * ------------------------------------------------------------------------------
 * Headers, not the body
 * ------------------------------------------------------------------------------
 *
 * The admin API returns more than JSON envelopes — CSV exports, private-file
 * blob downloads, streamed statements. A body injection would have to know
 * every one of those shapes and would corrupt the ones it did not, and it would
 * change the bytes of payloads the suite asserts on. A header is uniform across
 * every response this group can produce and leaves all of them byte-identical.
 *
 * `X-Tenant-Id` is emitted on EVERY response from the group, and carries either
 * the bound masjid id or the literal `unbound`.
 *
 * THE NAME IS A CONTRACT, not a preference: the admin SPA reads exactly this
 * header (`resources/vue-app/core/tenancy/tenantRequests.ts`, `TENANT_HEADERS`,
 * where it is the first and canonical spelling). Renaming it does not break
 * loudly — the SPA simply finds nothing, falls back to its own store, and goes
 * back to painting the organisation it *believes* it is in over whatever rows
 * arrived. That is the exact failure this middleware exists to remove, arriving
 * silently. Change the two together or not at all.
 *
 * Spelling "unbound" out rather than omitting the header is deliberate: it is
 * TenantResolution's third verdict — *this route is not about one masjid* — and
 * it is not the same fact as a missing header, which means "this did not come
 * from the admin API" (or came from a build older than this one). The SPA reads
 * an unparsable value as "no echo" and keeps the last bound tenant, so the two
 * behave identically there; the value is written for the operator reading a
 * response by hand, and for `TenantContext::get()`'s instruction to echo the key
 * with a null value rather than omit it.
 *
 * ------------------------------------------------------------------------------
 * There is deliberately no request-epoch echo
 * ------------------------------------------------------------------------------
 *
 * S5's other half — dropping responses that were already on the wire when the
 * user switched — needs no help from the server. The SPA stamps the epoch on the
 * axios request CONFIG and reads it back off `response.config`, so the pairing
 * never leaves the browser and cannot be lost, reflected or spoofed. Echoing a
 * client-supplied counter back in a header would add a reflection point (and the
 * validation it needs) to buy nothing. If a non-axios caller ever needs one —
 * a bare `fetch()` for a blob download, say — that is the moment to add it, with
 * the caller that requires it, not before.
 *
 * ------------------------------------------------------------------------------
 * Dark until the gate opens
 * ------------------------------------------------------------------------------
 *
 * Nothing about a request's behaviour changes here: no status, no body, no
 * binding, no query. The header is emitted whether `tenancy.multi_membership`
 * is true or false, and with it false it always names the single organisation
 * the admin already had — so the value S5 will rely on is one that has been
 * observable in production the whole time, rather than appearing for the first
 * time in the same deploy that opens the gate.
 */
class EchoResolvedTenant
{
    /**
     * The masjid every BelongsToMasjid query in this request was filtered by.
     *
     * Must stay byte-identical to the first entry of `TENANT_HEADERS` in
     * resources/vue-app/core/tenancy/tenantRequests.ts.
     */
    public const TENANT_HEADER = 'X-Tenant-Id';

    /** TenantResolution's third verdict: this route is not about one masjid. */
    public const UNBOUND = 'unbound';

    public function __construct(private TenantContext $tenant)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Read AFTER the request has run, never before: `tenant`
        // (ResolveMasjidTenant) binds downstream of this middleware, and
        // ImpactMetrics::withTenant() and TenantContext::runWithout() may move
        // the binding during the request. What the client needs is what the
        // response was actually built under, which is only knowable here.
        //
        // A fail-closed abort (403 from ResolveMasjidTenant) throws rather than
        // returns, so it unwinds past this line and is rendered by the
        // exception handler unstamped. That is correct: a refused request
        // resolved no tenant, and stamping one would suggest it had.
        $masjidId = $this->tenant->get();

        $response->headers->set(
            self::TENANT_HEADER,
            $masjidId === null ? self::UNBOUND : (string) $masjidId,
        );

        return $response;
    }
}
