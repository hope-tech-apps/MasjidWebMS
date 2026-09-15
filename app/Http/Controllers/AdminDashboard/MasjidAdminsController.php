<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * The platform-wide administrator surface — SuperAdmin only (routes/admin.php
 * mounts every action here under `admins` + `super`).
 *
 * Since S4 of docs/multi-tenant-admin-design.md it also owns the one act no
 * other door can perform: giving an EXISTING login a membership in a SECOND
 * organisation, and taking one away. Every other staff-provisioning path —
 * TeamController, AdministratorsController, TeachersController — validates
 * `Rule::unique('users','email')`, so all of them can only ever mint a brand-new
 * person. That is correct for them (one login per address, created inside one
 * organisation) and it is exactly why multi-organisation access had no door at
 * all.
 *
 * Both of the new actions are refused while `config('tenancy.multi_membership')`
 * is false, and the refusal is the point rather than caution — see
 * MULTI_MEMBERSHIP_CLOSED below. The single exception is writing the membership
 * row for a masjid the person ALREADY OWNS, which adds no organisation to
 * anybody and is the repair the flag's own opening depends on; grantMembership()
 * carries the argument.
 */
class MasjidAdminsController extends Controller
{
    /**
     * Why a second membership cannot be handed out while the gate is shut.
     *
     * It is not "the feature is unfinished". With the gate shut,
     * `TenantResolver::grantsFor()` gives an owner exactly the organisation they
     * own and gives a NON-owner administrator `staffMemberships()` — every
     * membership row they hold. A second row therefore makes that administrator
     * ambiguous, and `TenantResolver::resolve()` fails closed on ambiguity: they
     * are 403'd on every masjid-scoped route, in both organisations, including
     * the one they were working in this morning. Granting here would take away
     * the access it claims to add.
     *
     * Spelled out for the operator because a SuperAdmin reading "not allowed"
     * would reasonably try the database instead, and a hand-written INSERT does
     * the same damage with no message at all.
     */
    private const MULTI_MEMBERSHIP_CLOSED =
        'Multi-organisation access is switched off, so this would lock the person out rather than let them in. '
        . 'While it is off the tenant resolver grants a non-owner administrator every organisation they hold a '
        . 'membership in, and refuses them outright when that is more than one — so a second membership would '
        . '403 them in BOTH organisations, including the one they use today. Manara turns it on together with '
        . 'the organisation switcher.';

    /** The one realm whose authority IS the membership, so the only one grantable here. */
    private const GRANTABLE_TYPE = 'MasjidAdmin';

    public function index()
    {
        $admins = User::where('type', 'MasjidAdmin')->get();
        return response()->json([
            'status' => 'success',
            'data' => $admins
        ], Response::HTTP_OK);
    }

    /**
     * Administrators who are not placed in any organisation yet.
     *
     * The filter used to be `doesntHave('masjid')` — ownership alone. That was
     * the right question while `masjids.user_id` was the only authorization
     * fact; since S3 it is not. An administrator added by
     * AdministratorsController or TeamController owns nothing and holds only a
     * `masjid_user` row, so ownership-only listed them as free and invited a
     * SuperAdmin to attach them to a masjid they are already running —
     * re-pointing `masjids.user_id` and stripping the previous owner's binding
     * in the process.
     *
     * "Available" therefore means what the resolver means: it would grant this
     * login nothing. Both halves are required, and dropping either is a real
     * regression:
     *
     *  - no LIVE membership — mirrors `TenantResolver::staffMemberships()`,
     *    including its `whereHas('masjid')`, so a membership in a trashed
     *    organisation (which grants nothing today) does not make somebody look
     *    placed;
     *  - no owned masjid — mirrors `soleOwnedMembership()`'s ownership
     *    fallback, which still binds an owner whose `masjid_user` row the S2
     *    backfill never wrote. `masjid()` is soft-delete scoped, so a trashed
     *    masjid does not pin its former owner here either.
     */
    public function availableAdmins()
    {
        $admins = User::where('type', 'MasjidAdmin')
            ->doesntHave('masjid')
            ->whereDoesntHave('memberships', fn (Builder $query) => $query->whereHas('masjid'))
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $admins
        ], Response::HTTP_OK);
    }

    /**
     * POST .../admins/masjid/{masjid_id}/memberships — give an EXISTING
     * administrator access to this organisation as well.
     *
     * Body: `user_id` (required), `make_default` (optional).
     *
     * OWNERSHIP IS NEVER TOUCHED. `masjids.user_id` stays with whoever holds it;
     * S0 made it unique among live rows and `soleOwnedMembership()`'s fallback
     * depends on that. A second organisation is a MEMBERSHIP, and memberships
     * are many — which is the whole reason this table exists.
     *
     * `role` is DERIVED from `users.type` and never read from the request. The
     * design's "Roles" section requires memberships to stay homogeneous with the
     * global bridged role; a request-supplied role would be a per-tenant
     * authorization claim, and authorization stays on the `users.type` bridge so
     * that `Permission::count() === 8` holds.
     */
    public function grantMembership(Request $request, $masjid_id)
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer'],
        ]);

        // A grant into a trashed organisation would be inert the moment it was
        // written (every resolver path carries `whereHas('masjid')`), so it is
        // refused rather than silently doing nothing. `find()` is soft-delete
        // scoped, which is exactly the predicate wanted here.
        $masjid = Masjid::find((int) $masjid_id);

        if (! $masjid) {
            return $this->refuse('That organisation does not exist, or has been archived.', Response::HTTP_NOT_FOUND);
        }

        $user = User::find((int) $data['user_id']);

        if (! $user) {
            return $this->refuse('That login does not exist.', Response::HTTP_NOT_FOUND);
        }

        // Administrators only, and the two exclusions are different in kind:
        //
        //  - a SuperAdmin is bound from the ROUTE, never from a grant
        //    (ResolveMasjidTenant's second branch, and S2 gave them no rows on
        //    purpose), so a membership would grant them nothing while making
        //    every "which organisations does this person belong to" screen
        //    misreport their reach;
        //  - a Teacher or a LunchStaff login is scoped by something narrower
        //    than the membership — group_staff classes, or the one lunch board
        //    — so a bare membership here would be an organisation they can
        //    enter with nothing in it. Those are provisioned on the screens that
        //    own that narrower fact;
        //  - `users.type = 'User'` is not a staff principal at all; `admin`
        //    401s them at the door.
        if ($user->type !== self::GRANTABLE_TYPE) {
            return $this->refuse(
                'Only an administrator login can be given a second organisation here. '
                . 'Teachers and Friday-lunch logins are added on that organisation\'s own screen, '
                . 'and a Manara SuperAdmin already reaches every organisation.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if (MasjidUser::where('masjid_id', $masjid->id)->where('user_id', $user->id)->exists()) {
            return $this->refuse('That login already administers this organisation.', Response::HTTP_CONFLICT);
        }

        // THE GATE — with one exception, and the exception is what makes the
        // gate flippable at all.
        //
        // Writing the row for a masjid the person ALREADY OWNS adds no
        // organisation to anybody. `TenantResolver::soleOwnedMembership()`
        // already grants an owner that masjid, preferring a persisted row and
        // synthesising an identical UNSAVED one (`membershipFromOwnership()`,
        // same masjid_id, same bridged role) when there is none. So this write
        // turns an implicit grant into an explicit one and leaves the grant SET
        // unchanged — nobody becomes ambiguous, nobody is locked out.
        //
        // Without that exception the flag could never be turned on. Opening it
        // drops the ownership fallback (config/tenancy.php says so in as many
        // words), so every owner whose masjid was provisioned after S2's
        // backfill needs a real row FIRST — and this is the only door that
        // writes one. A gate that refuses the repair its own opening requires is
        // a gate with no key.
        $repairingOwnGrant = (int) $masjid->user_id === (int) $user->id;

        if (! $repairingOwnGrant && ! $this->multiMembershipEnabled()) {
            return $this->refuse(self::MULTI_MEMBERSHIP_CLOSED, Response::HTTP_FORBIDDEN);
        }

        // `make_default` is read with `$request->boolean()` rather than
        // validated with the `boolean` rule: form-encoded clients send the
        // STRINGS "true"/"false", which that rule rejects, and this endpoint is
        // not worth a 422 over a checkbox. Absent means "leave their default
        // where it is".
        $makeDefault = $request->boolean('make_default');
        $becameDefault = false;

        DB::transaction(function () use ($masjid, $user, $makeDefault, &$becameDefault) {
            // The database enforces one default per user
            // (`masjid_user_default_unique`), so moving the default has to clear
            // the old row in the SAME transaction or the insert dies on a
            // constraint violation — a 500 where the operator asked for a
            // switch. This is the assumption the other doors' hardcoded
            // `'is_default' => true` rests on ("safe: a brand-new user holds no
            // other membership"), and it is precisely the assumption this
            // endpoint breaks.
            if ($makeDefault) {
                MasjidUser::where('user_id', $user->id)->where('is_default', true)->update(['is_default' => false]);
            }

            // Default only when they were asked to be, or when the person holds
            // no default at all — an owner whose backfill row was never written,
            // for instance. Never an unconditional true. Computed into a
            // variable so the response reports what was WRITTEN rather than what
            // was requested; those differ exactly in the no-existing-default
            // case, which is the repair case.
            $becameDefault = $makeDefault
                || ! MasjidUser::where('user_id', $user->id)->where('is_default', true)->exists();

            MasjidUser::create([
                'masjid_id' => $masjid->id,
                'user_id' => $user->id,
                // Homogeneous with the global bridged role, by construction.
                'role' => User::TYPE_ROLE_MAP[$user->type],
                'is_default' => $becameDefault,
            ]);
        });

        // Their live sessions are deliberately NOT ended. A grant only widens
        // what they may reach, the tokens they hold are still valid for the
        // organisation they were working in, and the new one appears on their
        // next GET /api/admin/user — which the SPA calls on every page load.
        return response()->json([
            'status' => 'success',
            'message' => $user->name . ' can now administer ' . $masjid->name
                . '. It appears in their organisation switcher the next time they load the dashboard.',
            'data' => [
                'user_id' => (int) $user->id,
                'masjid_id' => (int) $masjid->id,
                'is_default' => $becameDefault,
            ],
        ], Response::HTTP_CREATED);
    }

    /**
     * DELETE .../admins/masjid/{masjid_id}/memberships/{user_id} — take one
     * organisation back off an administrator who holds several.
     *
     * The mirror of grantMembership(), and deliberately no wider than that: it
     * removes a SECOND organisation. Removing somebody's only organisation is
     * the Team & Access screen's job (TeamController::destroy), which also
     * refuses the owner and self, and retires a login left belonging nowhere.
     * Duplicating half of that here would give an operator a door that strands
     * a login with a password, a token and no tenant.
     */
    public function revokeMembership(Request $request, $masjid_id, $user_id)
    {
        if (! $this->multiMembershipEnabled()) {
            return $this->refuse(self::MULTI_MEMBERSHIP_CLOSED, Response::HTTP_FORBIDDEN);
        }

        // `withTrashed()` here where grantMembership() insists on a live masjid:
        // the asymmetry is intentional. A grant into a dead organisation would
        // be inert, but a grant already sitting in one must be removable —
        // TeamController::update refuses an access change while ANY other
        // membership exists, archived ones included, so an unremovable row in a
        // trashed organisation would block that person's access changes forever.
        $masjid = Masjid::withTrashed()->find((int) $masjid_id);

        if (! $masjid) {
            return $this->refuse('That organisation does not exist.', Response::HTTP_NOT_FOUND);
        }

        // Refused for the same reason AdministratorsController::destroy refuses
        // it: `soleOwnedMembership()` would keep binding them through
        // `masjids.user_id` afterwards, so the removal would be reported and not
        // have happened. Transferring an organisation is its own act.
        if ((int) $user_id === (int) $masjid->user_id) {
            return $this->refuse(
                'That account owns this organisation, so its access cannot be removed here. Transfer the organisation first.',
                Response::HTTP_CONFLICT,
            );
        }

        $membership = MasjidUser::where('masjid_id', $masjid->id)->where('user_id', (int) $user_id)->first();

        if (! $membership) {
            return $this->refuse('That login does not administer this organisation.', Response::HTTP_NOT_FOUND);
        }

        if ($this->remainingGrants($membership) === 0) {
            return $this->refuse(
                'This is their only organisation. Remove them on that organisation\'s Team & Access screen, '
                . 'which also ends their sessions and retires a login that belongs nowhere.',
                Response::HTTP_CONFLICT,
            );
        }

        // `withTrashed()`: a retired login still holds live Sanctum tokens —
        // nothing revokes them on soft delete — and a token is exactly what this
        // method is here to invalidate. Scoping it out would leave the one case
        // where the grant outlives its removal silently intact.
        $user = User::withTrashed()->find((int) $user_id);

        DB::transaction(function () use ($membership, $user) {
            $wasDefault = (bool) $membership->is_default;

            $membership->delete();

            // A user must not be left holding memberships with no default: the
            // switcher reads `is_default` to decide which organisation to open
            // on, and "none of them" renders as no organisation at all. Lowest
            // masjid_id is the same deterministic tie-break the resolver and the
            // login payload use.
            if ($wasDefault) {
                $next = MasjidUser::where('user_id', $membership->user_id)
                    ->whereHas('masjid')
                    ->orderBy('masjid_id')
                    ->first();
                $next?->update(['is_default' => true]);
            }

            // Their sessions end. A token minted while the grant existed is a
            // grant that outlived its removal — the same reasoning
            // AdministratorsController::destroy records — and here it is
            // sharper: the SPA may be sitting IN the organisation just revoked,
            // and its next request would be the first it hears of it.
            $user?->tokens()->delete();
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Removed. They can no longer act in ' . $masjid->name
                . ', and have been signed out of every organisation.',
        ], Response::HTTP_OK);
    }

    /**
     * How many organisations this person would still be able to act in once the
     * given membership is gone.
     *
     * Their other LIVE memberships, and ONLY those. Ownership is deliberately
     * not counted, even though `soleOwnedMembership()`'s fallback binds an owner
     * who has no pivot row: that fallback lives on the gate-SHUT path, and this
     * method is only ever reached with the gate OPEN, where `grantsFor()` is
     * `everyLiveMembership()` and ownership grants nothing by itself
     * (config/tenancy.php spells that out as the cost of flipping the flag).
     * Counting it would let the last real grant be revoked from an owner whose
     * S2 backfill row was never written, which is the unsafe direction: they
     * would keep `masjids.user_id` and lose every route.
     */
    private function remainingGrants(MasjidUser $membership): int
    {
        return MasjidUser::where('user_id', $membership->user_id)
            ->where('id', '!=', $membership->id)
            ->whereHas('masjid')
            ->count();
    }

    /**
     * config/tenancy.php, read here and nowhere else in this controller. The
     * resolver reads it in exactly one place too; this is the write side of the
     * same gate.
     */
    private function multiMembershipEnabled(): bool
    {
        return (bool) config('tenancy.multi_membership', false);
    }

    /**
     * The refusal envelope the admin SPA already switches on
     * (`{status:'failed', data:{field:[...]}}`), matching TeamController and
     * AdministratorsController so a caller cannot tell which door refused it by
     * the shape of the answer.
     */
    private function refuse(string $message, int $status)
    {
        return response()->json([
            'status' => 'failed',
            'data' => ['user_id' => [$message]],
        ], $status);
    }
}
