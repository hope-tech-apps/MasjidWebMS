<?php

namespace App\Http\Controllers\Teacher;

use App\Enums\GroupNotificationEvent;
use App\Http\Requests\Teacher\StoreGroupResourceRequest;
use App\Jobs\SendGroupNotificationJob;
use App\Models\Group;
use App\Models\GroupResource;
use App\Models\GroupResourceRecipient;
use App\Support\GroupResourceFiles;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Files a teacher keeps for a class — the teacher realm's FIRST file upload.
 *
 * No visibility check on this side: `teacher.leads` has already established that
 * the caller leads this class, and a teacher sees every file in their own room.
 * The visibility flag exists for the FAMILY side, which resolves it through
 * `App\Support\GroupAudience::readableResourcesQuery()`.
 *
 * Bytes are streamed by `download()`. There is deliberately no URL-minting
 * anywhere here — see GroupResource's docblock for why a signed URL would be a
 * hole rather than a convenience.
 *
 * ## THE THIRD AUDIENCE (2026-09-24)
 *
 * A file is for the whole class (`families`), for NAMED STUDENTS (`students`),
 * or for staff only (`staff`). The named set lives in
 * `group_resource_recipients`, written here and read nowhere except
 * `GroupAudience`. Two rules this controller is the only guard for:
 *
 *   1. **A recipient id must be a CURRENT PARTICIPANT row of THIS class.**
 *      `resolveRecipients()` re-reads every id through `$group->memberships()`,
 *      so another class's roster id, a guardian edge's id and a withdrawn
 *      child's id are each a 422 — not a silently dropped element, which would
 *      let a teacher believe a file had been addressed to somebody it had not.
 *   2. **Leaving `students` EMPTIES the set.** A file that stops being targeted
 *      must not keep rows that a later flip back to `students` would silently
 *      re-honour.
 */
class ResourcesController extends TeacherController
{
    public function index(Request $request, $masjid_id, $group_id): JsonResponse
    {
        $group = Group::findOrFail($group_id);

        // `recipients` eager-loaded: toStaffArray() reads it per row, and a
        // lazy read would be one query per file on a screen built to show many.
        $resources = $group->resources()
            ->with('recipients')
            ->orderByDesc('created_at')->orderByDesc('id')->get();

        return response()->json([
            'status' => 'success',
            'data' => $resources->map(fn (GroupResource $r): array => $r->toStaffArray())->values(),
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

        $visibility = $request->validated('visibility') ?? GroupResource::VISIBILITY_STAFF;

        // BEFORE a byte is written. A 422 that arrives after the upload has
        // landed leaves a file on disk that nothing points at, and the ceiling
        // above already established that this endpoint checks first and writes
        // second.
        $recipients = $visibility === GroupResource::VISIBILITY_STUDENTS
            ? $this->resolveRecipients($group, (array) $request->validated('recipient_membership_ids', []))
            : [];

        $resource = GroupResourceFiles::store($group, $request->file('file'), [
            'title' => $request->validated('title'),
            'description' => $request->validated('description'),
            // Absent means the model's default, which is `staff` — a payload
            // that forgets to say produces a private file, never a published one.
            'visibility' => $visibility,
            'uploaded_by_user_id' => Auth::id(),
        ]);

        $this->writeRecipients($resource, $recipients);

        $this->announce($group, $resource, $recipients);

        return response()->json([
            'status' => 'success',
            'data' => $resource->toStaffArray(),
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
            'recipient_membership_ids' => ['sometimes', 'array', 'max:500'],
            'recipient_membership_ids.*' => ['integer', 'min:1'],
        ]);

        // The audience AFTER this edit, which is what every decision below is
        // about — an omitted `visibility` means "leave it", not "staff".
        $visibility = $validated['visibility'] ?? $resource->visibility;

        if ($visibility === GroupResource::VISIBILITY_STUDENTS) {
            // An edit that leaves a targeted file targeted without naming a set
            // keeps the set it has. Naming one REPLACES it — there is no
            // "add one student" verb, because a partial write to an audience is
            // how a file ends up addressed to a child nobody chose.
            $named = array_key_exists('recipient_membership_ids', $validated)
                ? $this->resolveRecipients($group, $validated['recipient_membership_ids'])
                : $resource->recipients()->pluck('group_membership_id')->map(fn ($id): int => (int) $id)->all();

            if ($named === []) {
                throw new HttpResponseException(response()->json([
                    'status' => 'failed',
                    'data' => ['recipient_membership_ids' => ['Choose at least one student for this file.']],
                ], Response::HTTP_UNPROCESSABLE_ENTITY));
            }
        } else {
            if (array_key_exists('recipient_membership_ids', $validated) && $validated['recipient_membership_ids'] !== []) {
                throw new HttpResponseException(response()->json([
                    'status' => 'failed',
                    'data' => ['recipient_membership_ids' => ['Students can only be named when the file is for specific students.']],
                ], Response::HTTP_UNPROCESSABLE_ENTITY));
            }

            $named = [];
        }

        $before = $resource->visibility;
        $wereNamed = $resource->recipients()->pluck('group_membership_id')->map(fn ($id): int => (int) $id)->all();

        unset($validated['recipient_membership_ids']);
        $validated['visibility'] = $visibility;

        DB::transaction(function () use ($resource, $validated, $named): void {
            $resource->update($validated);
            // Unconditional, including when the file has just STOPPED being
            // targeted: a leftover recipient row would be re-honoured in silence
            // by any later flip back to `students`.
            $this->writeRecipients($resource, $named);
        });

        $this->announceEdit($group, $resource->fresh(), $before, $visibility, $wereNamed, $named);

        return response()->json([
            'status' => 'success',
            'data' => $resource->fresh()->toStaffArray(),
        ], Response::HTTP_OK);
    }

    /**
     * Every id in `$ids`, confirmed to be a CURRENT PARTICIPANT of THIS class.
     *
     * The whole request is refused when any one id fails, rather than the bad
     * ids being dropped: a teacher who picked six children and is told "saved"
     * must not have addressed the file to five. The refusal names no id it did
     * not receive, so it is not an existence oracle for another class's roster.
     *
     * `participants()` excludes guardian edges (a handout is addressed to a
     * child, and the guardians follow from the edge) and `current()` excludes
     * the withdrawn (see DECISIONS.md, 2026-09-24: withdrawal narrows).
     *
     * @param  array<int,mixed>  $ids
     * @return array<int,int>
     */
    private function resolveRecipients(Group $group, array $ids): array
    {
        $wanted = collect($ids)->map(fn ($id): int => (int) $id)->unique()->values();

        if ($wanted->isEmpty()) {
            return [];
        }

        $found = $group->memberships()
            ->participants()
            ->current()
            ->whereIn('id', $wanted->all())
            ->pluck('id')
            ->map(fn ($id): int => (int) $id);

        if ($found->count() !== $wanted->count()) {
            throw new HttpResponseException(response()->json([
                'status' => 'failed',
                'data' => ['recipient_membership_ids' => [
                    'One of the students chosen is not on this class\'s roster. Reload the class and try again.',
                ]],
            ], Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        return $found->all();
    }

    /**
     * Make `$membershipIds` the file's recipient set, exactly.
     *
     * `masjid_id` is taken from the RESOURCE, never from the route: the route's
     * id is the caller's and the file's is the server's.
     *
     * @param  array<int,int>  $membershipIds
     */
    private function writeRecipients(GroupResource $resource, array $membershipIds): void
    {
        $resource->recipients()->whereNotIn('group_membership_id', $membershipIds ?: [0])->delete();

        $existing = $resource->recipients()->pluck('group_membership_id')->map(fn ($id): int => (int) $id)->all();

        foreach (array_diff($membershipIds, $existing) as $membershipId) {
            GroupResourceRecipient::create([
                'masjid_id' => (int) $resource->masjid_id,
                'group_resource_id' => (int) $resource->id,
                'group_membership_id' => (int) $membershipId,
            ]);
        }

        $resource->unsetRelation('recipients');
    }

    /**
     * Tell the people a new file is actually FOR.
     *
     * Two shapes, and the audience picks between them — the same split
     * SendGroupNotificationJob already makes between a class story and a mark:
     *
     *   - `families` -> the feed audience, one nudge, consent-gated. Unchanged.
     *   - `students` -> ONE nudge per named child, addressed to that child's
     *     guardians and nobody else. A class-wide nudge would tell every family
     *     that a file had been filed for somebody, which is the disclosure this
     *     whole feature exists to avoid.
     *   - `staff`    -> nobody, which is the whole point of the default.
     *
     * @param  array<int,int>  $membershipIds
     */
    private function announce(Group $group, GroupResource $resource, array $membershipIds): void
    {
        if ($resource->visibility === GroupResource::VISIBILITY_FAMILIES) {
            $this->nudge($group, null);

            return;
        }

        if ($resource->visibility !== GroupResource::VISIBILITY_STUDENTS) {
            return;
        }

        foreach ($this->wardContactIds($group, $membershipIds) as $contactId) {
            $this->nudge($group, $contactId);
        }
    }

    /**
     * The edit's announcement: only what this edit newly SHARED.
     *
     * Renaming a file families can already see announces nothing, as it always
     * did; and adding a child to a targeted file nudges THAT child's guardians
     * and only them, because for that family the edit is the share.
     *
     * @param  array<int,int>  $wereNamed
     * @param  array<int,int>  $named
     */
    private function announceEdit(
        Group $group,
        GroupResource $resource,
        string $before,
        string $after,
        array $wereNamed,
        array $named
    ): void {
        if ($after === GroupResource::VISIBILITY_FAMILIES) {
            if ($before !== GroupResource::VISIBILITY_FAMILIES) {
                $this->nudge($group, null);
            }

            return;
        }

        if ($after !== GroupResource::VISIBILITY_STUDENTS) {
            return;
        }

        // A file that arrives here from `families` was already readable by every
        // one of these families, so only genuinely new names are announced.
        $newlyNamed = $before === GroupResource::VISIBILITY_STUDENTS
            ? array_values(array_diff($named, $wereNamed))
            : $named;

        foreach ($this->wardContactIds($group, $newlyNamed) as $contactId) {
            $this->nudge($group, $contactId);
        }
    }

    /**
     * The CONTACT behind each named membership — what a nudge is addressed to.
     *
     * @param  array<int,int>  $membershipIds
     * @return array<int,int>
     */
    private function wardContactIds(Group $group, array $membershipIds): array
    {
        if ($membershipIds === []) {
            return [];
        }

        return $group->memberships()
            ->whereIn('id', $membershipIds)
            ->pluck('contact_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function nudge(Group $group, ?int $aboutContactId): void
    {
        SendGroupNotificationJob::dispatch(
            (int) $group->masjid_id,
            (int) $group->id,
            GroupNotificationEvent::RESOURCE_SHARED,
            aboutContactId: $aboutContactId,
            authorUserId: Auth::id(),
            authorContactId: null,
        )->afterCommit();
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
