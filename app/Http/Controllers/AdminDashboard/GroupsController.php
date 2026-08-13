<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Groups\StoreGroupRequest;
use App\Http\Requests\Admin\Groups\UpdateGroupRequest;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\Masjid;
use App\Models\Offering;
use App\Support\Errors;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin CRUD for Groups — the org -> group -> member scoping level shared by
 * classrooms (Schools), halaqat / weekend school (Masjids) and volunteer teams
 * (Community). See DECISIONS.md 2026-08-10 and .claude/rules/groups.md.
 *
 * Tenant isolation is NOT hand-rolled here. The route keeps the
 * /masjids/{masjid_id}/... prefix by convention, but the `tenant` middleware
 * binds TenantContext and BelongsToMasjid auto-scopes every Group query — so we
 * never filter by $masjid_id and never set masjid_id from client input (the
 * creating hook stamps it). See .claude/rules/tenant-scoping.md.
 *
 * Nothing here names the concept. "Classroom" / "Halaqat" / "Teams" is the
 * TENANT'S vocabulary, served as `meta.group_label` from the vertical's
 * terminology pack — see .claude/rules/verticals.md.
 */
class GroupsController extends Controller
{
    /**
     * Paginated list of this organization's groups, optionally narrowed by
     * ?search= (name/slug/description), ?kind= and ?active_only=.
     */
    public function index(Request $request, $masjid_id)
    {
        $search = $request->query('search');

        $groups = Group::query()
            ->withCount([
                // Roster size an admin actually recognizes: guardians are
                // attached to a member, not participants in their own right.
                'memberships as participants_count' => fn ($q) => $q->participants(),
            ])
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('slug', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('kind'), fn ($q) => $q->ofKind($request->query('kind')))
            ->when($request->boolean('active_only'), fn ($q) => $q->active())
            ->orderBy('name')
            ->paginate($request->query('per_page', 15));

        return response()->json([
            'status' => 'success',
            'data' => $groups,
            'meta' => $this->meta(),
        ], Response::HTTP_OK);
    }

    /**
     * Store a new group. masjid_id is intentionally omitted: the BelongsToMasjid
     * creating hook stamps it from the bound tenant, so a client-supplied
     * masjid_id can never plant a row in another organization.
     */
    public function store(StoreGroupRequest $request, $masjid_id)
    {
        try {
            $group = Group::create($request->validated());

            return response()->json([
                'status' => 'success',
                'data' => $group,
                'meta' => $this->meta(),
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'failed',
                'data' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Show one group with its roster. findOrFail is tenant-scoped, so another
     * organization's id resolves to a 404 rather than leaking the row.
     */
    public function show($masjid_id, $group_id)
    {
        $group = Group::with([
            'memberships.contact',
            'memberships.guardianOf',
        ])->findOrFail($group_id);

        return response()->json([
            'status' => 'success',
            'data' => $group,
            'meta' => $this->meta(),
        ], Response::HTTP_OK);
    }

    /**
     * Update a group. The scoped findOrFail runs OUTSIDE the try so a
     * cross-tenant / missing id surfaces as a clean 404 instead of being
     * swallowed into a 500 by the catch below.
     */
    public function update(UpdateGroupRequest $request, $masjid_id, $group_id)
    {
        $group = Group::findOrFail($group_id);

        try {
            $group->update($request->validated());

            return response()->json([
                'status' => 'success',
                'data' => $group,
                'meta' => $this->meta(),
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'failed',
                'data' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Soft-delete a group. Deliberately NOT a hard delete: the roster underneath
     * it can be a list of children, and a mis-click must not be the thing that
     * destroys it. Its memberships are left in place, invisible with the group,
     * so a later retention slice can decide when they actually go.
     *
     * REFUSED while this group is a live offering's ROSTER.
     *
     * `offerings.group_id` is where a confirmed registration materialises its
     * registrants and its guardian edges (RegistrationService::
     * writeRosterMemberships). The column is nullable with `nullOnDelete()`, but
     * `groups` SOFT-deletes, so the FK never fires and the pointer is simply left
     * dangling at a row that no longer resolves.
     *
     * This was the last unguarded reference into this table, and it is the one
     * that takes money. Measured end to end before the guard:
     *
     *     register                                 -> 200 pending, seat 1, live
     *                                                 checkout URL
     *     DELETE .../groups/{roster_group_id}      -> 200, one click, no warning
     *     checkout.session.completed  (x3 retries) -> 500, 500, 500
     *     after the retries   status=pending payment=awaiting payments=0
     *     +46 min, the reaper cancelled the seat   payments=0 memberships=0
     *
     * The family paid $150, holds Stripe's receipt, and forty-five minutes later
     * her registration is cancelled with no local trace of the charge — so nobody
     * can even find her to refund her.
     *
     * Both sibling references from this table are already guarded, and this one is
     * written to match them: `FormsController::destroy` refuses while the form is a
     * live offering's `intake_form_id`, and `OfferingsController::destroy` refuses
     * while an offering holds live registrations. Same shape, same words, and the
     * same pointer at the non-destructive path.
     *
     * The recovery is SOFTER than the intake form's, because a roster is optional
     * where an intake form is not: detach the program from the group (`group_id`
     * is nullable — registrations then confirm with no roster), re-point it at the
     * classroom that replaces this one, or switch the group off with
     * `is_active = false`, which is not a delete at all.
     *
     * The guard is NOT the only defence and is not meant to be. A group deleted
     * before it shipped still dangles, so `writeRosterMemberships()` treats an
     * unresolvable group as "no roster to materialise" rather than throwing —
     * exactly as `no_intake_form` still exists behind the FormsController guard.
     * This is what stops the mistake being made; that is what stops the mistake
     * costing a family her money.
     *
     * The scoped findOrFail runs OUTSIDE the try so a cross-tenant / missing id
     * surfaces as a clean 404 rather than being swallowed into a 500.
     */
    public function destroy($masjid_id, $group_id)
    {
        $group = Group::findOrFail($group_id);

        // Explicit masjid filter AND an explicit scope bypass, mirroring
        // FormsController::destroy: this must not depend on whether
        // TenantContext happens to be bound, because a query that quietly
        // returned nothing here would let the delete through
        // (.claude/rules/tenant-scoping.md — an unbound scope adds NO filter).
        // Soft-deleted offerings do not block: nothing registers into one.
        $blocking = Offering::withoutMasjidScope()
            ->where('masjid_id', $group->masjid_id)
            ->where('group_id', $group->id)
            ->orderBy('name')
            ->pluck('name')
            ->all();

        if ($blocking !== []) {
            $names = implode(', ', array_slice($blocking, 0, 5))
                . (count($blocking) > 5 ? ', and ' . (count($blocking) - 5) . ' more' : '');

            $one = count($blocking) === 1;

            $message = 'This group is the roster for ' . $names . '. Deleting it would leave '
                . ($one ? 'that program' : 'those programs')
                . ' with nowhere to enrol the families who register, including the ones '
                . 'who have already paid. Take '
                . ($one ? 'the program' : 'the programs')
                . ' off this group first (or point '
                . ($one ? 'it' : 'them')
                . ' at another one), or switch this group off instead of deleting it.';

            // `status: failed` + `data` mirrors OfferingsController::destroy,
            // which is the same refusal for the same reason; `message` is what
            // FormsController::destroy returns for the sibling reference, so a
            // client reading either convention sees it.
            return response()->json([
                'status' => 'failed',
                'message' => $message,
                'data' => $message,
                'offerings' => $blocking,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $group->delete();

        return response()->json([
            'status' => 'success',
            'data' => $group,
        ], Response::HTTP_OK);
    }

    /**
     * Vertical-aware labelling. What a group is CALLED comes from the tenant's
     * terminology pack ("Halaqat" / "Classrooms" / "Teams"), never from a
     * hardcoded string. The masjid is read from the bound TenantContext rather
     * than the route parameter, so it stays server-derived.
     *
     * Unbound (no tenant on this request) yields the neutral "Groups" — the
     * absence of a tenant is not a reason to speak another vertical's language.
     */
    private function meta(): array
    {
        $masjidId = app(TenantContext::class)->get();
        $masjid = $masjidId ? Masjid::find($masjidId) : null;

        return [
            'group_label' => $masjid?->term('groups') ?? 'Groups',
            'kinds' => Group::KINDS,
            'roles' => GroupMembership::ROLES,
        ];
    }
}
