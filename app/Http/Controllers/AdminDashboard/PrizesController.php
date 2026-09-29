<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\ClassStore\SavePrizeRequest;
use App\Models\Group;
use App\Models\Masjid;
use App\Models\Prize;
use App\Support\ClassStorePayload;
use App\Support\ClassStoreReconciliation;
use App\Support\GroupAudience;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The class store, for the OFFICE (T-003.4, R4): the school-wide prize list and the
 * reconciliation view.
 *
 * `{masjid_id}` stays in the path by convention and isolation comes from the `tenant`
 * middleware and BelongsToMasjid, never a hand-written filter. Every route sits behind
 * `capability:class_store` (the store is OFF for every organisation until a SuperAdmin
 * decides) and the CONTACTS permissions, exactly like the behaviour vocabulary beside it.
 *
 * WHAT THE OFFICE MAY DO: keep the SCHOOL-WIDE prize list (a prize with no class), and read the
 * reconciliation of the store's totals. WHAT IT MAY NOT: read one child's balance, or write
 * a class's own prize (a teacher's), or touch the ledger. The reconciliation is CLASS
 * TOTALS ONLY with no child named, because the office administers the store but does not
 * stand in a class (GroupAudience::mayReceiveClassStoreTotals). There is no ledger route
 * here at all, and no delete route for a prize: a prize is retired with `is_active`.
 */
class PrizesController extends Controller
{
    public function __construct(private GroupAudience $audience)
    {
    }

    /** GET .../prizes: the school-wide list, retired ones included and marked */
    public function index($masjid_id): JsonResponse
    {
        $prizes = Prize::query()->schoolWide()->inShelfOrder()->get();

        return response()->json([
            'status' => 'success',
            'data' => $prizes->map(fn (Prize $p): array => ClassStorePayload::prize($p, null))->values(),
        ], Response::HTTP_OK);
    }

    /** POST .../prizes: a school-wide prize */
    public function store(SavePrizeRequest $request, $masjid_id): JsonResponse
    {
        $prize = Prize::create([
            // No class: the route has no {group_id}, so the request checks uniqueness on the
            // school-wide list, and nothing in the body can name a class.
            'group_id' => null,
            'title' => (string) $request->input('title'),
            'description' => $request->input('description'),
            'cost_bucks' => $request->integer('cost_bucks'),
            'stock' => $request->filled('stock') ? $request->integer('stock') : null,
            'is_active' => $request->has('is_active') ? (bool) $request->boolean('is_active') : true,
            'created_by_user_id' => $request->user()?->id,
        ]);

        return response()->json(['status' => 'success', 'data' => ClassStorePayload::prize($prize, null)], Response::HTTP_CREATED);
    }

    /** PUT .../prizes/{prize_id}: edit or retire a school-wide prize */
    public function update(SavePrizeRequest $request, $masjid_id, $prize_id): JsonResponse
    {
        // Only the school-wide list: a class's own prize is its teachers', and is a MISS here.
        $prize = Prize::query()->schoolWide()->findOrFail($prize_id);

        foreach (['title', 'description', 'cost_bucks', 'stock', 'is_active'] as $field) {
            if (! $request->exists($field)) {
                continue;
            }

            $prize->{$field} = match ($field) {
                'cost_bucks' => $request->integer('cost_bucks'),
                'stock' => $request->filled('stock') ? $request->integer('stock') : null,
                'is_active' => (bool) $request->boolean('is_active'),
                default => $request->input($field),
            };
        }

        $prize->save();

        return response()->json(['status' => 'success', 'data' => ClassStorePayload::prize($prize->fresh(), null)], Response::HTTP_OK);
    }

    /**
     * GET .../prize-reconciliation[?weeks=8]
     *
     * Class totals and whether the ledger agrees with the points, for the classes this
     * caller may read the totals of. Never a child.
     */
    public function reconciliation(Request $request, $masjid_id): JsonResponse
    {
        // The school the SERVER bound for this request (never the URL's claim).
        $masjidId = app(TenantContext::class)->get();

        if ($masjidId === null) {
            abort(Response::HTTP_FORBIDDEN);
        }

        $masjid = Masjid::findOrFail((int) $masjidId);

        $weeks = $request->query('weeks');

        if ($weeks !== null && $weeks !== '' && (! is_string($weeks) || ! ctype_digit($weeks) || (int) $weeks < 1)) {
            return response()->json(['status' => 'error', 'message' => 'weeks must be a whole number of at least 1.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $groups = Group::query()
            ->inDisplayOrder()
            ->get()
            ->filter(fn (Group $g) => $this->audience->mayReceiveClassStoreTotals($request->user(), $g))
            ->values();

        return response()->json([
            'status' => 'success',
            'data' => ClassStoreReconciliation::forSchool(
                $masjid,
                $groups,
                $weeks === null || $weeks === '' ? ClassStoreReconciliation::DEFAULT_WEEKS : (int) $weeks,
            ),
        ], Response::HTTP_OK);
    }
}
