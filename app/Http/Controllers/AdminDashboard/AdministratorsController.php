<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use App\Services\Auth\AccountAccessService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * A second person in the office (R3).
 *
 * ---------------------------------------------------------------------------
 * THE PREMISE THIS CORRECTS
 * ---------------------------------------------------------------------------
 *
 * The vendor assessment recorded R3 as "one office login is a HARD DATABASE
 * CONSTRAINT. Not a plan tier." Measured 2026-09-09, that is wrong. Two
 * MasjidAdmins on one masjid — the first owning it via `masjids.user_id`, the
 * second holding only a `masjid_user` row — both bind and both reach the admin
 * API, with ownership untouched. `TenantResolver::grantsFor()` falls through to
 * `staffMemberships()` for a principal who owns nothing, and that method filters
 * on membership, not on role.
 *
 * What was actually missing was a way to CREATE the second one. The only staff
 * provisioning path that writes a `masjid_user` row is `TeachersController`, and
 * it hardcodes `type = 'Teacher'`. So an office wanting a second administrator
 * had two options, and the assessment described both accurately even though its
 * diagnosis was wrong: hand the masjid's `user_id` to the new person, which
 * strips the FIRST admin's binding — or create a bare `MasjidAdmin` with no
 * membership row, which owns nothing, holds no grant, and 403s everywhere.
 *
 * This controller is the third option, and it is the one the resolver was
 * already written for.
 *
 * ---------------------------------------------------------------------------
 * OWNERSHIP IS NEVER TOUCHED HERE
 * ---------------------------------------------------------------------------
 *
 * `masjids.user_id` stays with whoever holds it. It is a different fact from
 * "may administer": exactly one row can own an organisation, and that
 * uniqueness is load-bearing for `soleOwnedMembership()`'s fallback, which is
 * what keeps admins working in environments the S2 backfill never reached. A
 * second administrator is a MEMBERSHIP, and memberships are many.
 *
 * That also means removing a second administrator can never strand the first —
 * `destroy()` deletes a membership, and the owner has ownership to fall back on.
 * Refusing to remove the owner through this door is deliberate for the same
 * reason: transferring an organisation is its own act with its own screen.
 *
 * ---------------------------------------------------------------------------
 * NOT the multi-membership flag
 * ---------------------------------------------------------------------------
 *
 * `tenancy.multi_membership` gates ONE USER administering SEVERAL organisations,
 * which needs a switcher that does not exist yet and is correctly still false.
 * This is the opposite shape — several users, one organisation — and needs
 * nothing from that flag. Each administrator here holds exactly one membership,
 * so there is nothing to switch between and no way to be stranded in a tenant
 * they cannot leave.
 */
class AdministratorsController extends Controller
{
    public function __construct(private TenantContext $tenant)
    {
    }

    /** GET .../administrators — who can currently run this office. */
    public function index(Request $request, $masjid_id)
    {
        $masjidId = (int) $this->tenant->get();
        $masjid = Masjid::withoutGlobalScopes()->find($masjidId);

        $rows = MasjidUser::where('masjid_id', $masjidId)
            ->with('user')
            ->get()
            ->filter(fn (MasjidUser $m) => $m->user && $m->user->type === 'MasjidAdmin')
            ->map(fn (MasjidUser $m) => [
                'user_id' => (int) $m->user->id,
                'name' => $m->user->name,
                'email' => $m->user->email,
                // The owner is shown but cannot be removed here — see destroy().
                'is_owner' => (int) $m->user->id === (int) $masjid?->user_id,
                'added_at' => $m->created_at?->toIso8601String(),
            ])->values();

        return response()->json(['status' => 'success', 'data' => $rows], Response::HTTP_OK);
    }

    /**
     * POST .../administrators — add a second person to the office.
     *
     * Mirrors TeachersController::store, which is the proven shape: create the
     * user, write the `masjid_user` row in the same transaction, and only invite
     * once it is committed — a rolled-back transaction must not email an
     * invitation to a user that does not exist.
     */
    public function store(Request $request, $masjid_id, AccountAccessService $access)
    {
        $masjidId = (int) $this->tenant->get();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'max:40'],
        ]);

        $user = null;

        DB::transaction(function () use ($data, $masjidId, &$user) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                // users.phone is NOT NULL; an empty string is a real "none on file".
                'phone' => $data['phone'] ?? '',
                'type' => 'MasjidAdmin',
                // Never a password anyone chose or transmitted. They set their own
                // through the invite, exactly like a teacher.
                'password' => Str::password(40),
            ]);

            MasjidUser::create([
                'masjid_id' => $masjidId,
                'user_id' => $user->id,
                'role' => 'masjid-admin',
                // Safe: a brand-new user holds no other membership.
                'is_default' => true,
            ]);
        });

        $orgName = optional(Masjid::withoutGlobalScopes()->find($masjidId))->name;
        $sent = $access->invite($user, $orgName);

        return response()->json([
            'status' => 'success',
            'message' => $sent
                ? 'Added. They have been emailed a link to set their own password.'
                : 'Added, but no invitation could be sent — check the address.',
            'data' => ['user_id' => (int) $user->id, 'name' => $user->name, 'email' => $user->email],
        ], Response::HTTP_CREATED);
    }

    /**
     * DELETE .../administrators/{user_id} — take the office login away.
     *
     * Deletes the MEMBERSHIP, not the person: a user row may carry history
     * elsewhere, and this verb is "they no longer run this office", not "this
     * human never existed".
     *
     * THE OWNER IS REFUSED. Removing their membership would leave them binding
     * through the ownership fallback anyway — so it would report a removal that
     * did not happen, which is worse than refusing. Transferring an organisation
     * is a different act with a different screen.
     */
    public function destroy(Request $request, $masjid_id, $user_id)
    {
        $masjidId = (int) $this->tenant->get();
        $masjid = Masjid::withoutGlobalScopes()->find($masjidId);

        if ((int) $user_id === (int) $masjid?->user_id) {
            return response()->json([
                'status' => 'failed',
                'data' => ['user_id' => [
                    'That account owns this organisation, so its access cannot be removed here. '
                    . 'Transfer the organisation first.',
                ]],
            ], Response::HTTP_CONFLICT);
        }

        $membership = MasjidUser::where('masjid_id', $masjidId)
            ->where('user_id', (int) $user_id)
            ->first();

        if (! $membership) {
            return response()->json([
                'status' => 'failed',
                'data' => ['user_id' => ['That account does not administer this organisation.']],
            ], Response::HTTP_NOT_FOUND);
        }

        // Their live sessions die with the grant. Leaving a token alive after
        // removing the membership it authorised would be a removal in name only
        // — the same reasoning AccountAccessService::reset() records.
        $user = User::find((int) $user_id);
        $user?->tokens()->delete();

        $membership->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Removed. They can no longer sign in to this organisation.',
        ], Response::HTTP_OK);
    }
}
