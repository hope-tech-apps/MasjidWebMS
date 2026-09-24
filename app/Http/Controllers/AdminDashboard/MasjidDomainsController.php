<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Studio\StoreMasjidDomainRequest;
use App\Jobs\AttachMasjidDomain;
use App\Models\Masjid;
use App\Models\MasjidDomain;
use App\Services\Cloudflare\CloudflareResult;
use App\Services\Cloudflare\CloudflareService;
use App\Services\Domains\DomainAttacher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * An organisation's web addresses, for SuperAdmins (Manara Studio W1, S7):
 * list them, add one, "Check now", and remove one that Studio never took past
 * this table.
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
     * made for a row that was not stored.
     */
    public function store(StoreMasjidDomainRequest $request, $masjid_id)
    {
        $masjid = Masjid::findOrFail($masjid_id);
        $kind = $request->validated('kind');

        try {
            $domain = DB::transaction(function () use ($request, $masjid, $kind) {
                $domain = MasjidDomain::create([
                    'masjid_id' => $masjid->id,
                    'host' => $request->domainHost(),
                    'kind' => $kind,
                    'zone_apex' => $request->zoneApex(),
                    'status' => MasjidDomain::STATUS_PENDING,
                    'source' => MasjidDomain::SOURCE_STUDIO,
                    'created_by_user_id' => Auth::id(),
                ]);

                AttachMasjidDomain::dispatch($domain->id);

                return $domain;
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

        return response()->json([
            'status' => 'success',
            'data' => ['domain' => ($domain->fresh() ?? $domain)->toAdminArray()],
        ], Response::HTTP_CREATED);
    }

    /**
     * "Check now": advance the row synchronously, which always ends in the
     * probe when there is no token. A `failed` row starts again from `pending`,
     * because the operator pressing this is saying the cause is fixed.
     */
    public function refresh($masjid_id, $domain_id, DomainAttacher $attacher)
    {
        $domain = $this->domain($masjid_id, $domain_id);

        if ($domain->status === MasjidDomain::STATUS_FAILED) {
            $domain->forceFill([
                'status' => MasjidDomain::STATUS_PENDING,
                'waiting_on' => null,
                'last_error' => null,
                'stage_started_at' => null,
                'next_check_at' => null,
            ])->save();
        }

        $attacher->advance($domain);

        return response()->json([
            'status' => 'success',
            'data' => ['domain' => $domain->fresh()->toAdminArray()],
        ], Response::HTTP_OK);
    }

    /**
     * Remove a row only when nothing about it exists outside this table (R28):
     * no Cloudflare id, no zone Studio created, not imported. Otherwise 409
     * with what to remove by hand, because Studio never deletes anything in
     * Cloudflare.
     */
    public function destroy($masjid_id, $domain_id)
    {
        $domain = $this->domain($masjid_id, $domain_id);

        if (! $domain->deletableThroughStudio()) {
            return response()->json([
                'status' => 'error',
                'message' => $domain->source === MasjidDomain::SOURCE_IMPORTED
                    ? "{$domain->host} was imported from the live host map and cannot be removed through Studio."
                    : "{$domain->host} has records in Cloudflare that Studio made or found, and Studio does not remove anything there.",
                'manual_steps' => $domain->removalSteps(),
            ], Response::HTTP_CONFLICT);
        }

        $domain->delete();

        return response()->noContent();
    }

    private function domain($masjidId, $domainId): MasjidDomain
    {
        $masjid = Masjid::findOrFail($masjidId);

        return MasjidDomain::query()->where('masjid_id', $masjid->id)->findOrFail($domainId);
    }
}
