<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Groups\StoreGroupRequest;
use App\Http\Requests\Admin\Groups\UpdateGroupRequest;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\Masjid;
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
     */
    public function destroy($masjid_id, $group_id)
    {
        $group = Group::findOrFail($group_id);
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
