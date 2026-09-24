<?php

namespace App\Http\Controllers\Family;

use App\Models\GroupResource;
use App\Support\GroupAudience;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The handouts a class has chosen to share with this family.
 *
 * ## VISIBILITY IS A QUERY CONSTRAINT, NOT A RESPONSE FILTER
 *
 * The audience is applied to the QUERY — `GroupAudience::readableResourcesQuery()`
 * — and never to the response after the fact. Three consequences, all deliberate:
 *
 *   - A file this family may not have is a 404, never a 403. A 403 would confirm
 *     that a file with that id exists in their child's class.
 *   - Its `original_name` and `size_bytes` never enter a family payload at all —
 *     not even as a greyed-out row. A filename and a file size are themselves a
 *     disclosure ("Progress reports Sept.pdf, 2.1 MB" says plenty), which is why
 *     the row is never fetched rather than merely hidden. The 404 body says
 *     nothing about the file either.
 *   - The listing and the download ask the SAME question of the same builder, so
 *     "it was in my list" and "I may fetch its bytes" cannot come apart.
 *
 * ## WHY THE DECISION IS NOT HERE (2026-09-24)
 *
 * This controller used to apply `GroupResource::visibleToFamilies()` itself. It
 * no longer may: a file can now be addressed to NAMED STUDENTS, and which
 * parents that reaches is answered from guardian edges — the same question
 * `GroupAudience` already answers for a participant thread, a behaviour award
 * and a ḥifẓ entry. A second implementation living in a controller is exactly
 * the drift that class exists to prevent (.claude/rules/groups.md).
 *
 * The consent gate below is DISCLOSURE_FEED — the same call a group-wide thread
 * makes, and it is kept here on top of the query constraint rather than replaced
 * by it. A family with no feed consent already sees no class story, so handouts
 * behave identically and this introduces no new class of exclusion. See
 * DECISIONS.md, 2026-09-24, for why a targeted file is consent-gated where a
 * participant thread about the same child is not.
 */
class ResourcesController extends FamilyController
{
    public function index(Request $request, $masjid_id, $group_id): JsonResponse
    {
        $group = $this->group($group_id);
        $this->authorizeDisclosure($group, GroupAudience::DISCLOSURE_FEED);

        // Never null here — authorizeDisclosure has already refused a caller
        // with no standing — but this method promises a query either way, and
        // "cannot happen" is not a reason to dereference a null.
        $readable = $this->audience->readableResourcesQuery($this->contact(), $group);

        $resources = $readable === null
            ? collect()
            : $readable->orderByDesc('created_at')->orderByDesc('id')->get();

        return response()->json([
            'status' => 'success',
            // toAudienceArray(), never toStaffArray(): the staff shape carries
            // WHO a file was addressed to, and the names of the other children a
            // handout went to are exactly what a parent must not be told.
            'data' => $resources->map(fn (GroupResource $r): array => $r->toAudienceArray())->values(),
        ], Response::HTTP_OK);
    }

    public function download(Request $request, $masjid_id, $group_id, $resource_id)
    {
        $group = $this->group($group_id);

        $readable = $this->audience->readableResourcesQuery($this->contact(), $group);

        if ($readable === null) {
            // No standing in this group AT ALL — a 403, matching index() and the
            // rest of the realm. The 404s below are for a file that exists in a
            // class this family IS in and may not have; that difference is the
            // one the 403/404 split has always meant here.
            $this->authorizeDisclosure($group, GroupAudience::DISCLOSURE_FEED);

            abort(Response::HTTP_FORBIDDEN);
        }

        // Constrain BEFORE findOrFail: a file this family may not have resolves
        // to nothing, so the miss is indistinguishable from an id that was never
        // real. findOrFail stays OUTSIDE any try/catch so the JSON renderer turns
        // it into a clean 404 (private-uploads rule 5).
        $resource = $readable->whereKey($resource_id)->firstOrFail();

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
