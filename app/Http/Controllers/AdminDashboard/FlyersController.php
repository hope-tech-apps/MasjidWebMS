<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Flyers\StoreFlyerRequest;
use App\Http\Requests\Admin\Flyers\UpdateFlyerRequest;
use App\Models\Flyer;
use App\Support\Errors;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Flyer Studio's saved flyers — admin CRUD over one masjid's designs-in-progress.
 *
 * Tenant isolation is not enforced here by hand. The route keeps the
 * /masjids/{masjid_id}/... prefix by convention, but the `tenant` middleware binds
 * TenantContext and the BelongsToMasjid trait scopes every Flyer query to it, so
 * another masjid's flyer id resolves to a 404. See .claude/rules/tenant-scoping.md.
 *
 * A flyer is never rendered on the server: the SPA composites it in the browser from
 * the template's HTML and this row's content + palette. What lives here is the source
 * of truth for that render, plus the state of the photo's background removal.
 */
class FlyersController extends Controller
{
    /**
     * Uploaded photos and their cutouts are unpublished tenant content — a janazah
     * photo must not be reachable by guessing a /storage URL — so they live on the
     * private `local` disk and are served back through FlyerCutoutController::image
     * under the admin session. Kept in step with the same constant there.
     */
    private const IMAGE_DISK = 'local';

    /**
     * GET /api/admin/masjids/{masjid_id}/flyers
     *
     * Newest first, matching the (masjid_id, created_at) index.
     */
    public function index(Request $request, $masjid_id)
    {
        $flyers = Flyer::query()
            // `schema` is in the select because serialize() reports missing_slots, which
            // reads the design's slots. Drop it and every row in the list claims nothing
            // is missing — the failure looks like an answer, not an error.
            ->with('template:id,key,name,kind,schema')
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
            ->when(
                $request->query('template_id'),
                fn ($query, $templateId) => $query->where('flyer_template_id', $templateId)
            )
            ->when(
                $request->query('search'),
                fn ($query, $search) => $query->where('title', 'like', "%{$search}%")
            )
            ->orderBy('created_at', 'desc')
            ->paginate($request->query('per_page', 15))
            ->through(fn (Flyer $flyer) => $this->serialize($flyer));

        return response()->json([
            'status' => 'success',
            'data' => $flyers,
        ], Response::HTTP_OK);
    }

    /**
     * POST /api/admin/masjids/{masjid_id}/flyers
     *
     * masjid_id is intentionally not passed: the BelongsToMasjid creating hook stamps
     * it from the bound tenant, so a client-supplied one can never plant a flyer in
     * another masjid. The photo is uploaded separately, to FlyerCutoutController.
     */
    public function store(StoreFlyerRequest $request, $masjid_id)
    {
        try {
            $flyer = Flyer::create([
                'flyer_template_id' => $request->integer('flyer_template_id'),
                'title' => $request->string('title')->toString(),
                'content' => (array) $request->input('content', []),
                // The palette column is NOT NULL; an empty snapshot means "resolve from
                // theme_settings on first open", which is what a flyer created by the
                // Assistant (no browser, no resolver) will have.
                'palette' => (array) $request->input('palette', []),
                'created_by' => $request->user()?->id,
            ]);

            // The design was resolved — and authorized as shared-or-mine — while
            // validating, so hand that instance to the serializer instead of paying for
            // a second lookup. It is null only if validation never reached the lookup,
            // which a passing `required|integer` rule makes unreachable; the load() is
            // there so a future rule change degrades into a query, not a blank template.
            if ($request->resolvedTemplate) {
                $flyer->setRelation('template', $request->resolvedTemplate);
            } else {
                $flyer->load('template');
            }

            return response()->json([
                'status' => 'success',
                'data' => $this->serialize($flyer),
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * GET /api/admin/masjids/{masjid_id}/flyers/{flyer_id}
     *
     * Carries the design's full schema so the Studio can open the editor from one call.
     */
    public function show($masjid_id, $flyer_id)
    {
        $flyer = Flyer::with('template')->findOrFail($flyer_id);

        $data = $this->serialize($flyer);
        $data['template'] = $flyer->template ? [
            'id' => $flyer->template->id,
            'key' => $flyer->template->key,
            'name' => $flyer->template->name,
            'kind' => $flyer->template->kind,
            'is_active' => $flyer->template->is_active,
            'schema' => $flyer->template->schema,
        ] : null;

        return response()->json([
            'status' => 'success',
            'data' => $data,
        ], Response::HTTP_OK);
    }

    /**
     * PUT /api/admin/masjids/{masjid_id}/flyers/{flyer_id}
     *
     * The scoped findOrFail runs OUTSIDE the try so a cross-tenant or missing id
     * surfaces as a clean 404 rather than being swallowed into a 500.
     */
    public function update(UpdateFlyerRequest $request, $masjid_id, $flyer_id)
    {
        $flyer = Flyer::findOrFail($flyer_id);

        try {
            // Only what was actually sent: the Studio saves a single edited slot as
            // often as it saves the whole flyer, and validated() already dropped
            // anything the design does not declare.
            $changes = $request->safe()->only(['flyer_template_id', 'title', 'content']);

            // `palette` deliberately does NOT come through safe(). Laravel's
            // excludeUnvalidatedArrayKeys (on by default) drops any attribute carrying
            // an `array` rule that ALSO has child rules — `palette.grad.*` is one — and
            // keeps only the validated children, so a snapshot round-tripped through
            // validated() comes back as {grad: […]} with the dozen other colours gone.
            // The rules still run; only the READ of the value bypasses validated().
            //
            // The column is NOT NULL, so a null palette means "clear the snapshot" — an
            // empty one, the same state a flyer created outside the browser starts in.
            if ($request->has('palette')) {
                $changes['palette'] = (array) $request->input('palette');
            }

            $flyer->update($changes);

            return response()->json([
                'status' => 'success',
                'data' => $this->serialize($flyer->fresh(['template'])),
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * DELETE /api/admin/masjids/{masjid_id}/flyers/{flyer_id}
     *
     * A hard delete — flyers are not soft-deleted — so the uploaded photo, its cutout
     * and any finished render go with the row. Nothing else references those files, and
     * leaving them behind would slowly fill a 2GB droplet with orphans.
     */
    public function destroy($masjid_id, $flyer_id)
    {
        $flyer = Flyer::findOrFail($flyer_id);

        try {
            $disk = Storage::disk(self::IMAGE_DISK);

            foreach ([$flyer->source_image_path, $flyer->cutout_path, $flyer->rendered_path] as $path) {
                if ($path) {
                    $disk->delete($path);
                }
            }

            $flyer->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'Flyer deleted successfully',
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * The admin-facing shape of a flyer. Storage paths are deliberately absent —
     * the client gets endpoints it can fetch, never a path into the private disk.
     */
    private function serialize(Flyer $flyer): array
    {
        return [
            'id' => $flyer->id,
            'uuid' => $flyer->uuid,
            'masjid_id' => $flyer->masjid_id,
            'flyer_template_id' => $flyer->flyer_template_id,
            'template_key' => $flyer->relationLoaded('template') ? $flyer->template?->key : null,
            'template_name' => $flyer->relationLoaded('template') ? $flyer->template?->name : null,
            'kind' => $flyer->relationLoaded('template') ? $flyer->template?->kind : null,
            'title' => $flyer->title,
            'content' => $flyer->content,
            'palette' => $flyer->palette,
            'status' => $flyer->status,
            'is_rendered' => $flyer->isRendered(),

            // Required slots still empty. `required` is not enforced on save, because
            // the Studio saves as the admin types and a half-filled flyer is a draft —
            // this is how the UI knows the flyer is not yet ready to render.
            'missing_slots' => $this->missingSlots($flyer),

            'cutout_status' => $flyer->cutout_status,
            'cutout_error' => $flyer->cutout_error,
            'cutout_pending' => $flyer->isCutoutPending(),
            'images' => $this->imageUrls($flyer),

            'created_at' => optional($flyer->created_at)->toIso8601String(),
            'updated_at' => optional($flyer->updated_at)->toIso8601String(),
        ];
    }

    /**
     * @return array<int,string> Names of required slots with no value yet.
     */
    private function missingSlots(Flyer $flyer): array
    {
        $template = $flyer->relationLoaded('template') ? $flyer->template : null;

        if (! $template) {
            return [];
        }

        $content = is_array($flyer->content) ? $flyer->content : [];
        $missing = [];

        foreach ($template->slots() as $slot) {
            if (! is_array($slot) || empty($slot['required']) || ! isset($slot['name'])) {
                continue;
            }

            $value = $content[$slot['name']] ?? null;
            $filled = ! ($value === null || $value === '' || $value === []);

            // An image slot is satisfied by the uploaded photo, not by anything in
            // `content`: the picture lives on the flyer row, and the Studio nulls the
            // slot before saving so a data URI never reaches the JSON column.
            if (! $filled && ($slot['type'] ?? null) === 'image') {
                $filled = (bool) $flyer->source_image_path;
            }

            if (! $filled) {
                $missing[] = $slot['name'];
            }
        }

        return $missing;
    }

    /**
     * Fetchable endpoints for this flyer's images, null where there is nothing to
     * fetch. `composite` is the one the renderer wants: it follows Flyer::
     * compositeImagePath(), so a failed or still-queued cutout falls back to the
     * original instead of rendering a hole where the photo goes.
     *
     * These are authenticated API endpoints, not public files, so the SPA must fetch
     * them with its bearer token (axios `responseType: 'blob'`) rather than dropping
     * them straight into an <img src>.
     *
     * @return array<string,?string>
     */
    private function imageUrls(Flyer $flyer): array
    {
        $base = url("/api/admin/masjids/{$flyer->masjid_id}/flyers/{$flyer->id}/image");

        return [
            'source' => $flyer->source_image_path ? "{$base}/source" : null,
            'cutout' => ($flyer->cutout_status === 'done' && $flyer->cutout_path) ? "{$base}/cutout" : null,
            'composite' => $flyer->compositeImagePath() ? "{$base}/composite" : null,
        ];
    }
}
