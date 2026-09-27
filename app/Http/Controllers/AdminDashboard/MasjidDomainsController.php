<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Studio\StoreMasjidDomainRequest;
use App\Jobs\AttachMasjidDomain;
use App\Models\Masjid;
use App\Models\MasjidDomain;
use App\Services\Cloudflare\CloudflareResult;
use App\Services\Cloudflare\CloudflareService;
use App\Services\Domains\DetachResult;
use App\Services\Domains\DomainAttacher;
use App\Services\Domains\DomainDetacher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * An organisation's web addresses, for SuperAdmins (Manara Studio W1, S7):
 * list them, add one, "Check now", remove one that Studio never took past this
 * table, and (W2 S3) detach one Studio attached, taking what Studio created
 * for it off Cloudflare.
 *
 * `super` only, and the organisation always comes from the route. Adding a host
 * to a live organisation is a deliberate SuperAdmin act that no W1 flow takes
 * for the live tenants; it creates a `studio` row, and even then
 * CloudflareService refuses any DNS record Studio did not create.
 *
 * Every answer says whether the token is configured, and every row carries the
 * server-built `manual_steps` and a `live_url` that exists only once a probe
 * has seen our site answer on the host (R24). Nothing here marks a host live;
 * only DomainAttacher's probe or Cloudflare's own verification does.
 */
class MasjidDomainsController extends Controller
{
    public function index($masjid_id, CloudflareService $cloudflare)
    {
        $masjid = Masjid::findOrFail($masjid_id);
        $configured = $cloudflare->isConfigured();

        // One read of the project when there is a token, so the screen can show
        // how near the custom-domain ceiling it is. A failed read is `null`,
        // "not known", never a guessed number.
        $used = null;

        if ($configured) {
            $count = $cloudflare->countPagesDomains();
            $used = $count->is(CloudflareResult::OK) ? (int) $count->data['count'] : null;
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'cloudflare' => [
                    'configured' => $configured,
                    'pages_project' => (string) config('cloudflare.pages_project'),
                    'pages_domains_used' => $used,
                    'pages_domains_ceiling' => (int) config('cloudflare.pages_domain_ceiling'),
                ],
                'domains' => MasjidDomain::query()
                    ->where('masjid_id', $masjid->id)
                    ->orderBy('id')
                    ->get()
                    ->map(fn (MasjidDomain $domain) => $domain->toAdminArray())
                    ->values(),
            ],
        ], Response::HTTP_OK);
    }

    /**
     * Record the host and queue its first step. The job is dispatched inside
     * the transaction and marked afterCommit, so no Cloudflare call is ever
     * made for a row that was not stored. With `canonical` (W2 S5) both hosts
     * of a pair are recorded: the canonical one serving, the other redirecting.
     */
    public function store(StoreMasjidDomainRequest $request, $masjid_id)
    {
        $masjid = Masjid::findOrFail($masjid_id);
        $kind = $request->validated('kind');

        $pair = $request->canonicalPair();

        try {
            $rows = DB::transaction(function () use ($request, $masjid, $kind, $pair) {
                $make = fn (string $host, array $extra = []) => MasjidDomain::create([
                    'masjid_id' => $masjid->id,
                    'host' => $host,
                    'kind' => $kind,
                    'zone_apex' => $request->zoneApex(),
                    'status' => MasjidDomain::STATUS_PENDING,
                    'source' => MasjidDomain::SOURCE_STUDIO,
                    'created_by_user_id' => Auth::id(),
                ] + $extra);

                // W2 S5: the canonical host serves; the other redirects to it.
                $rows = $pair === null
                    ? [$make($request->domainHost())]
                    : [$serving = $make($pair['serving']), $make($pair['redirect'], [
                        'role' => MasjidDomain::ROLE_REDIRECT,
                        'redirect_to_id' => $serving->id,
                    ])];

                foreach ($rows as $row) {
                    AttachMasjidDomain::dispatch($row->id);
                }

                return $rows;
            });
        } catch (UniqueConstraintViolationException) {
            // Two SuperAdmins adding the same host at once: the unique index
            // decides, and the loser gets the same answer the rule gives.
            $field = $kind === MasjidDomain::KIND_MANAGED_SUBDOMAIN ? 'label' : 'host';

            return response()->json([
                'status' => 'failed',
                'data' => [$field => ["{$request->domainHost()} is already recorded for another organisation."]],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $typed = collect($rows)->firstWhere('host', $request->domainHost()) ?? $rows[0];

        return response()->json([
            'status' => 'success',
            'data' => [
                'domain' => ($typed->fresh() ?? $typed)->toAdminArray(),
                'domains' => array_map(fn (MasjidDomain $row) => ($row->fresh() ?? $row)->toAdminArray(), $rows),
            ],
        ], Response::HTTP_CREATED);
    }

    /**
     * "Check now": advance the row synchronously, which always ends in the
     * probe when there is no token. A `failed` row starts again from `pending`,
     * because the operator pressing this is saying the cause is fixed.
     *
     * While another writer holds the row (the job, the schedule, another Check
     * now or a DELETE) nothing is checked, and the answer is the 409 a DELETE
     * gives for a held row: a 200 with the row unchanged would read as "checked,
     * nothing new" when no check was made.
     */
    public function refresh($masjid_id, $domain_id, DomainAttacher $attacher)
    {
        $domain = $this->domain($masjid_id, $domain_id);

        if (! $attacher->checkNow($domain)) {
            return response()->json([
                'status' => 'error',
                'message' => "Studio is already checking {$domain->host}. Try again in a moment.",
                'manual_steps' => [],
            ], Response::HTTP_CONFLICT);
        }

        return response()->json([
            'status' => 'success',
            'data' => ['domain' => $domain->fresh()->toAdminArray()],
        ], Response::HTTP_OK);
    }

    /**
     * Remove a row only when nothing about it exists outside this table (R28):
     * no Cloudflare id, no zone Studio created, not imported. Otherwise 409
     * with what to remove by hand; for a row Studio owns, Detach (below) is
     * the way to take what Studio made off Cloudflare. DELETE itself never
     * sends anything to Cloudflare.
     *
     * Judged under the attacher's own lock, on the row re-read inside it: a
     * step in flight holds what it made in Cloudflare only in memory until its
     * save, so the stored row still looks deletable while its CNAME, Pages
     * domain or zone is being made. While the lock is held the answer is a 409
     * to try again; once released, the row says what it now holds.
     */
    public function destroy($masjid_id, $domain_id)
    {
        $domain = $this->domain($masjid_id, $domain_id);
        $lock = DomainAttacher::lockFor($domain->id);

        if (! $lock->get()) {
            return response()->json([
                'status' => 'error',
                'message' => "Studio is setting up {$domain->host} in Cloudflare right now. Try again in a minute or two.",
                'manual_steps' => [],
            ], Response::HTTP_CONFLICT);
        }

        try {
            $domain->refresh();

            if (! $domain->deletableThroughStudio()) {
                return response()->json([
                    'status' => 'error',
                    'message' => ! $domain->ownedByStudio()
                        ? "{$domain->host} was imported from the live host map and cannot be removed through Studio."
                        : "{$domain->host} has records in Cloudflare that Studio made or found, and Studio does not remove anything there.",
                    'manual_steps' => $domain->removalSteps(),
                ], Response::HTTP_CONFLICT);
            }

            $domain->delete();
        } finally {
            $lock->release();
        }

        return response()->noContent();
    }

    /**
     * Detach a host Studio attached (W2 S3): stop serving it at once, remove
     * from Cloudflare the objects Studio's own POSTs created, and forget the
     * row. What Studio did not create is left and listed in `manual_steps`.
     *
     * 202 with the result, whether it finished (`detached`) or stopped
     * part-way (`pending`: the row stays `detaching`, unserved, and
     * `domains:reconcile` finishes it). 409 for a row that came from the live
     * host map (imported or adopted: R28 holds, and S6 is their tool) and for
     * a row another writer holds.
     */
    public function detach($masjid_id, $domain_id, DomainDetacher $detacher)
    {
        $domain = $this->domain($masjid_id, $domain_id);
        $result = $detacher->detach($domain, Auth::id());

        if ($result->outcome === DetachResult::REFUSED || $result->outcome === DetachResult::BUSY) {
            return response()->json([
                'status' => 'error',
                'message' => $result->error,
                'manual_steps' => $result->manualSteps,
            ], Response::HTTP_CONFLICT);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'result' => $result->toArray(),
                'domain' => MasjidDomain::find($domain->id)?->toAdminArray(),
            ],
        ], Response::HTTP_ACCEPTED);
    }

    private function domain($masjidId, $domainId): MasjidDomain
    {
        $masjid = Masjid::findOrFail($masjidId);

        return MasjidDomain::query()->where('masjid_id', $masjid->id)->findOrFail($domainId);
    }
}
