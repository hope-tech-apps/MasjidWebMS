<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Contacts\ContactTagContactsRequest;
use App\Http\Requests\Admin\Contacts\SaveContactTagRequest;
use App\Models\Broadcast;
use App\Models\Contact;
use App\Models\ContactTag;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Contact tags — an organisation's own labels on its contacts, and the bulk
 * tag / untag actions on the member directory.
 *
 * Lives in the `crm` route group beside ContactsController and uses the SAME
 * two permissions: reading tags is `view contacts`, creating, renaming,
 * deleting and (un)tagging is `manage contacts`. No permission is minted
 * (`Permission::count()` stays 8, .claude/rules/auth-permissions.md).
 *
 * Tenant isolation follows .claude/rules/tenant-scoping.md for a BelongsToMasjid
 * model: nothing here filters by `$masjid_id`. A tag id from another
 * organisation is a 404 from the scoped `findOrFail`, and contact ids are
 * resolved through the scoped Contact query, so another organisation's contact
 * (or a deleted one) is a 404 for the whole request — nothing is half-applied.
 */
class ContactTagsController extends Controller
{
    /**
     * Every tag of this organisation with how many (non-deleted) contacts carry
     * it. Not paginated: this feeds a picker and a filter, and a tag on page
     * two would be unreachable from both.
     */
    public function index($masjid_id): JsonResponse
    {
        $tags = ContactTag::query()
            ->withCount('contacts')
            ->orderBy('name')
            ->get();

        return response()->json(['status' => 'success', 'data' => $tags], Response::HTTP_OK);
    }

    public function store(SaveContactTagRequest $request, $masjid_id): JsonResponse
    {
        // masjid_id is stamped by the BelongsToMasjid creating hook from the
        // bound tenant; it is never taken from the request.
        $tag = ContactTag::create(['name' => $request->validated('name')]);

        return response()->json(['status' => 'success', 'data' => $tag->loadCount('contacts')], Response::HTTP_CREATED);
    }

    public function update(SaveContactTagRequest $request, $masjid_id, $tag_id): JsonResponse
    {
        $tag = ContactTag::findOrFail($tag_id);
        $tag->update(['name' => $request->validated('name')]);

        return response()->json(['status' => 'success', 'data' => $tag->loadCount('contacts')], Response::HTTP_OK);
    }

    /**
     * Delete a tag. The contacts stay; only the label goes.
     *
     * Refused while a SCHEDULED broadcast is addressed to it. The foreign key
     * would null the broadcast's tag and the resolver would then address nobody
     * — the safe direction, but a send the admin scheduled would silently reach
     * no one. Saying so now lets them cancel or re-address it first.
     */
    public function destroy($masjid_id, $tag_id): JsonResponse
    {
        $tag = ContactTag::findOrFail($tag_id);

        $scheduled = Broadcast::query()
            ->where('audience_tag_id', $tag->id)
            ->where('status', Broadcast::STATUS_SCHEDULED)
            ->exists();

        if ($scheduled) {
            return response()->json([
                'status' => 'failed',
                'message' => 'A scheduled broadcast is addressed to this tag. Send or cancel it before deleting the tag.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $tag->delete();

        return response()->json(['status' => 'success', 'data' => null], Response::HTTP_OK);
    }

    /** Tag one or many contacts. Tagging someone who already carries the tag changes nothing. */
    public function attach(ContactTagContactsRequest $request, $masjid_id, $tag_id): JsonResponse
    {
        $tag = ContactTag::findOrFail($tag_id);
        $ids = $this->ownContactIds($request->contactIds());

        $added = DB::transaction(fn () => count($tag->contacts()->syncWithoutDetaching($ids)['attached']));

        return $this->counts($tag, $added, 'added');
    }

    /** Untag one or many contacts. Untagging someone who does not carry the tag changes nothing. */
    public function detach(ContactTagContactsRequest $request, $masjid_id, $tag_id): JsonResponse
    {
        $tag = ContactTag::findOrFail($tag_id);
        $ids = $this->ownContactIds($request->contactIds());

        $removed = $tag->contacts()->detach($ids);

        return $this->counts($tag, $removed, 'removed');
    }

    /**
     * The requested ids, all of them this organisation's live contacts — or a
     * 404 for the whole request.
     *
     * All-or-nothing on purpose: silently dropping the ids that did not
     * resolve would report success for a selection that was partly somebody
     * else's, and a 404 is what a single foreign id gets everywhere else here.
     *
     * @param  array<int, int>  $requested
     * @return array<int, int>
     */
    private function ownContactIds(array $requested): array
    {
        $found = Contact::query()->whereIn('id', $requested)->pluck('id')->map(fn ($id) => (int) $id)->all();

        abort_if(count($found) !== count($requested), Response::HTTP_NOT_FOUND);

        return $found;
    }

    private function counts(ContactTag $tag, int $changed, string $verb): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'tag' => $tag->loadCount('contacts'),
                $verb => $changed,
            ],
        ], Response::HTTP_OK);
    }
}
