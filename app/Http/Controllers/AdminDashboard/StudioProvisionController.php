<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Studio\StudioProvisionRequest;
use App\Models\Masjid;
use App\Models\MasjidDomain;
use App\Models\StudioDraft;
use App\Support\Errors;
use App\Support\Studio\StudioDraftConflict;
use App\Support\Studio\StudioProvisioning;
use App\Support\Studio\StudioProvisionResult;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /api/admin/studio/drafts/{draft_id}/provision: Step 3's button
 * (docs/manara-studio-w1.md S8). SuperAdmin-only through the studio group's
 * `super` middleware.
 *
 *  - 201: the wizard's `{masjid_id, masjid, app_publishing}`, plus what Studio
 *    added and how the after-commit steps went (see body()).
 *  - 422: the legacy envelope, from the wizard's own request rules or from
 *    Studio's brand gate. Nothing was written.
 *  - 409 `{status: 'conflict', data: {draft_id, provisioned_masjid_id}}`: the
 *    draft is already an organisation. Checked BEFORE validation as well as
 *    under the lock, because a second provision of the same answers would
 *    otherwise fail validation (its email now belongs to the first org) and
 *    read as "fix your answers" instead of "this exists".
 *  - 500: only for a failure before or inside the transaction, and then
 *    nothing was committed. A failure after the commit is a 201 that says so.
 */
class StudioProvisionController extends Controller
{
    public function provision(StudioProvisionRequest $request, int $draft_id, StudioProvisioning $provisioning): JsonResponse
    {
        // Outside the try, so a missing draft is the renderer's clean 404.
        $draft = StudioDraft::findOrFail($draft_id);

        if ($draft->isProvisioned()) {
            return self::conflict($draft);
        }

        try {
            $result = $provisioning->provision($draft, $request->secrets());
        } catch (StudioDraftConflict $e) {
            return self::conflict($e->draft);
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'data' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return response()->json([
            'status' => 'success',
            'data' => self::body($result, $draft->id),
        ], Response::HTTP_CREATED);
    }

    private static function conflict(StudioDraft $draft): JsonResponse
    {
        return response()->json([
            'status' => 'conflict',
            'data' => [
                'draft_id' => $draft->id,
                'provisioned_masjid_id' => $draft->provisioned_masjid_id,
            ],
        ], Response::HTTP_CONFLICT);
    }

    /** @return array<string, mixed> */
    private static function body(StudioProvisionResult $result, int $draftId): array
    {
        $masjid = $result->masjid;
        $domains = array_map(fn (MasjidDomain $d) => $d->fresh() ?? $d, $result->context->domains);

        return OnboardingController::provisionedPayload($masjid) + [
            'draft_id' => $draftId,
            'brand_assets' => self::brandAssets($masjid),
            'capabilities_applied' => $result->context->capabilitiesApplied,
            'starter_site' => $result->context->starterSite,
            'web' => self::web($domains),
            'domains' => array_map(fn (MasjidDomain $d) => $d->toAdminArray(), $domains),
            'after_commit' => $result->afterCommit,
        ];
    }

    /** @return array{logo_url: ?string, favicon_url: ?string, touch_icon_url: ?string, share_image_url: ?string} */
    private static function brandAssets(Masjid $masjid): array
    {
        return [
            'logo_url' => $masjid->logo()->first()?->original_url,
            'favicon_url' => $masjid->favicon()->first()?->original_url,
            'touch_icon_url' => $masjid->touch_icon()->first()?->original_url,
            'share_image_url' => $masjid->share_image()->first()?->original_url,
        ];
    }

    /**
     * The address the website will answer on, as Step 3's domain panel shows
     * it: the client's own domain when one was given, otherwise the managed
     * subdomain; null when no web host was written.
     *
     * @param  list<MasjidDomain>  $domains
     * @return array{host: string, status: string, waiting_on: ?string, live_url: ?string, manual_steps: list<string>}|null
     */
    private static function web(array $domains): ?array
    {
        $row = collect($domains)->firstWhere('kind', MasjidDomain::KIND_CUSTOM) ?? ($domains[0] ?? null);

        if ($row === null) {
            return null;
        }

        return [
            'host' => $row->host,
            'status' => $row->status,
            'waiting_on' => $row->waiting_on,
            'live_url' => $row->liveUrl(),
            'manual_steps' => $row->manualSteps(),
        ];
    }
}
