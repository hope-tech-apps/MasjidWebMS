<?php

namespace App\Http\Controllers\Family;

use App\Models\PrizeLedgerEntry;
use App\Support\ClassStore;
use App\Support\ClassStorePayload;
use App\Support\ClassStoreSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A parent's view of their own child's Manara Bucks: the balance and the history (T-003.4).
 *
 * GET only. This realm's counted write list is unchanged: a parent cannot spend, redeem or
 * reverse anything, and the store is run by the class's teachers.
 *
 * TWO GATES, BOTH REQUIRED, exactly as for an award (the same audience, on purpose):
 *   1. the ENDPOINT asks `subject()`, which is `mayReceiveAwardsAbout()`: another family's
 *      child is an honest 403, never a confusing empty page;
 *   2. the QUERY is `readablePrizeLedgerQuery()`, which constrains to this caller's own wards
 *      BEFORE a row is fetched, so a forbidden row cannot surface in a page or a SUM.
 * Consent is not consulted: a parent reading their own child's record is not a broadcast.
 *
 * ADDRESSED BY ONE MEMBERSHIP ID, with no group-wide variant: no rank, no comparison, no
 * prize wall, and nothing here puts two children's balances side by side. The payload is the
 * narrow one (ClassStorePayload::entryForFamily): no teacher's note, no author, no paper-note
 * breakdown.
 */
class ClassStoreController extends FamilyController
{
    /** GET .../groups/{group_id}/members/{membership_id}/bucks */
    public function forMember(Request $request, $masjid_id, $group_id, $membership_id): JsonResponse
    {
        $group = $this->group($group_id);
        $membership = $this->subject($group, $membership_id);

        $readable = $this->audience->readablePrizeLedgerQuery($this->contact(), $group);

        if ($readable === null) {
            abort(Response::HTTP_FORBIDDEN, 'You are not entitled to this class\'s Manara Bucks.');
        }

        $page = $readable->clone()
            ->where('group_membership_id', $membership->id)
            ->orderByDesc('id')
            ->paginate($this->perPage($request, 25));

        $ids = $page->getCollection()->pluck('id')->all();
        $reversed = $ids === [] ? [] : $readable->clone()
            ->whereIn('reverses_entry_id', $ids)
            ->pluck('reverses_entry_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $page->through(fn (PrizeLedgerEntry $e): array => ClassStorePayload::entryForFamily($e, in_array((int) $e->id, $reversed, true)));

        return response()->json([
            'status' => 'success',
            'data' => $page,
            'meta' => $this->meta([
                'student' => $this->student($membership),
                'balance' => (int) (ClassStore::balances($readable, [(int) $membership->id])[$membership->id] ?? 0),
                // Said once, so the screen can explain where bucks come from without a second call.
                'points_per_buck' => ClassStoreSettings::for((int) $group->masjid_id)['points_per_buck'],
            ]),
        ], Response::HTTP_OK);
    }
}
