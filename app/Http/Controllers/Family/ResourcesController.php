<?php

namespace App\Http\Controllers\Family;

use App\Models\GroupResource;
use App\Support\GroupAudience;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The handouts a class has chosen to share with its families.
 *
 * ## VISIBILITY IS A SCOPE, NOT A FILTER
 *
 * `visibleToFamilies()` is applied to the QUERY, not to the response after the
 * fact. Two consequences, both deliberate:
 *
 *   - A staff-only file is a 404 to a family, never a 403. A 403 would confirm
 *     that a file with that id exists in their child's class.
 *   - A staff-only file's `original_name` and `size_bytes` never enter a family
 *     payload at all — not even as a greyed-out row. A filename and a file size
 *     are themselves a disclosure ("Progress reports Sept.pdf, 2.1 MB" says
 *     plenty), which is why the row is never fetched rather than merely hidden.
 *
 * The consent gate is DISCLOSURE_FEED — the same call a group-wide thread makes.
 * A family with no feed consent already sees no class story, so handouts behave
 * identically and this introduces no new class of exclusion.
 */
class ResourcesController extends FamilyController
{
    public function index(Request $request, $masjid_id, $group_id): JsonResponse
    {
        $group = $this->group($group_id);
        $this->authorizeDisclosure($group, GroupAudience::DISCLOSURE_FEED);

        $resources = $group->resources()
            ->visibleToFamilies()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $resources->map(fn (GroupResource $r): array => $r->toAudienceArray())->values(),
        ], Response::HTTP_OK);
    }

    public function download(Request $request, $masjid_id, $group_id, $resource_id)
    {
        $group = $this->group($group_id);

        // Scope BEFORE findOrFail: a staff-only id resolves to nothing.
        $resource = $group->resources()->visibleToFamilies()->findOrFail($resource_id);

        $this->authorizeDisclosure($group, GroupAudience::DISCLOSURE_FEED);

        if (! $resource->exists()) {
            return response()->json([
                'status' => 'failed',
                'data' => 'That file is no longer available.',
            ], Response::HTTP_NOT_FOUND);
        }

        return $resource->storage()->download($resource->path, $resource->original_name, [
            'Content-Type' => $resource->mime_type,
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }
}
