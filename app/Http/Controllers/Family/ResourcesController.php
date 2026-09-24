<?php

namespace App\Http\Controllers\Family;

use App\Models\Group;
use App\Models\GroupResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The handouts a class has shared with this family.
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
 * ## THERE IS NO CONSENT CHECK IN THIS FILE, AND ITS ABSENCE IS THE DESIGN
 *
 * This controller used to apply `GroupResource::visibleToFamilies()` itself, and
 * then `authorizeDisclosure(DISCLOSURE_FEED)` over the whole surface. Both are
 * gone, and the second one had become actively wrong: since the owner's ruling
 * of 2026-09-24 a file addressed to ONE child reaches that child's guardian
 * whether or not they ever consented — like a behaviour award, a ḥifẓ entry and
 * a participant thread, none of which are consent-gated — so a blanket 403 here
 * hid a file the audience was willing to serve. Consent still gates the
 * WHOLE-CLASS shelf, and it does so inside `readableResourcesQuery()` where the
 * listing and the download read it from one place. A consent branch out here
 * could only ever disagree with that one.
 *
 * What is left is the shape `Family\BehaviorAwardsController` already uses: 403
 * when the caller has no standing in the group AT ALL, and otherwise the
 * constrained query — which may legitimately be empty.
 */
class ResourcesController extends FamilyController
{
    public function index(Request $request, $masjid_id, $group_id): JsonResponse
    {
        $group = $this->group($group_id);

        $resources = $this->readable($group)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

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

        // Constrain BEFORE the lookup: a file this family may not have resolves
        // to nothing, so the miss is indistinguishable from an id that was never
        // real. firstOrFail stays OUTSIDE any try/catch so the JSON renderer
        // turns it into a clean 404 (private-uploads rule 5).
        $resource = $this->readable($group)->whereKey($resource_id)->firstOrFail();

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

    /**
     * This family's readable files, or a 403 when they have no standing in the
     * group at all.
     *
     * The 403/404 split, restated because it is the whole interface: 403 means
     * "this class is not yours", which is honest and gives nothing away about
     * its contents; 404 means "no such file for you", which is what every other
     * refusal in here has to look like.
     */
    private function readable(Group $group): Builder
    {
        $query = $this->audience->readableResourcesQuery($this->contact(), $group);

        if ($query === null) {
            abort(Response::HTTP_FORBIDDEN, 'You are not entitled to this group\'s files.');
        }

        return $query;
    }
}
