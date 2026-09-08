<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Requests\Teacher\StoreGroupResourceRequest;
use App\Models\Group;
use App\Models\GroupResource;
use App\Support\GroupResourceFiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Files a teacher keeps for a class — the teacher realm's FIRST file upload.
 *
 * No visibility check on this side: `teacher.leads` has already established that
 * the caller leads this class, and a teacher sees every file in their own room.
 * The visibility flag exists for the FAMILY side, which resolves it as a scope.
 *
 * Bytes are streamed by `download()`. There is deliberately no URL-minting
 * anywhere here — see GroupResource's docblock for why a signed URL would be a
 * hole rather than a convenience.
 */
class ResourcesController extends TeacherController
{
    public function index(Request $request, $masjid_id, $group_id): JsonResponse
    {
        $group = Group::findOrFail($group_id);

        $resources = $group->resources()->orderByDesc('created_at')->orderByDesc('id')->get();

        return response()->json([
            'status' => 'success',
            'data' => $resources->map(fn (GroupResource $r): array => $r->toAudienceArray())->values(),
        ], Response::HTTP_OK);
    }

    /**
     * Upload one file.
     *
     * The per-class ceiling is checked BEFORE anything is written. A feed is
     * bounded by how many posts somebody bothers to write; a resource library is
     * an unbounded append surface with no retention sweep behind it, so it needs
     * a stated end.
     */
    public function store(StoreGroupResourceRequest $request, $masjid_id, $group_id): JsonResponse
    {
        $group = Group::findOrFail($group_id);

        $ceiling = (int) config('groups.resources.max_per_class', 200);

        if ($ceiling > 0 && $group->resources()->count() >= $ceiling) {
            return response()->json([
                'status' => 'failed',
                'data' => ['file' => ["This class already has {$ceiling} files. Remove one before adding another."]],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $resource = GroupResourceFiles::store($group, $request->file('file'), [
            'title' => $request->validated('title'),
            'description' => $request->validated('description'),
            // Absent means the model's default, which is `staff` — a payload
            // that forgets to say produces a private file, never a published one.
            'visibility' => $request->validated('visibility') ?? GroupResource::VISIBILITY_STAFF,
            'uploaded_by_user_id' => Auth::id(),
        ]);

        return response()->json([
            'status' => 'success',
            'data' => $resource->toAudienceArray(),
        ], Response::HTTP_CREATED);
    }

    /**
     * Edit the label or the audience. NEVER the bytes.
     *
     * Replacing a file under a stable id would mean a family that fetched
     * resource 7 yesterday gets different bytes at the same id today. A new file
     * is a new row.
     */
    public function update(Request $request, $masjid_id, $group_id, $resource_id): JsonResponse
    {
        $group = Group::findOrFail($group_id);
        $resource = $group->resources()->findOrFail($resource_id);

        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'visibility' => ['sometimes', 'required', Rule::in(GroupResource::VISIBILITIES)],
        ]);

        $resource->update($validated);

        return response()->json([
            'status' => 'success',
            'data' => $resource->fresh()->toAudienceArray(),
        ], Response::HTTP_OK);
    }

    public function destroy(Request $request, $masjid_id, $group_id, $resource_id): JsonResponse
    {
        $group = Group::findOrFail($group_id);
        $resource = $group->resources()->findOrFail($resource_id);

        // The model's `deleting` hook takes the bytes with the row.
        $resource->delete();

        return response()->json(['status' => 'success', 'data' => ['id' => (int) $resource_id]], Response::HTTP_OK);
    }

    /**
     * Stream the bytes.
     *
     * The chain is re-resolved from the route on every request — masjid, group,
     * resource — so a file from another class or another organization is a 404
     * rather than a filtered row. findOrFail stays OUTSIDE any try/catch so the
     * JSON renderer turns it into a clean 404 (private-uploads rule 5).
     */
    public function download(Request $request, $masjid_id, $group_id, $resource_id)
    {
        $group = Group::findOrFail($group_id);
        $resource = $group->resources()->findOrFail($resource_id);

        if (! $resource->exists()) {
            return response()->json([
                'status' => 'failed',
                'data' => 'That file is no longer on disk.',
            ], Response::HTTP_NOT_FOUND);
        }

        return $resource->storage()->download($resource->path, $resource->original_name, [
            'Content-Type' => $resource->mime_type,
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }
}
