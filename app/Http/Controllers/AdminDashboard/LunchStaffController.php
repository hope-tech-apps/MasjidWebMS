<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LunchStaff\StoreLunchStaffRequest;
use App\Http\Requests\Admin\LunchStaff\UpdateLunchStaffRequest;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use App\Services\Auth\AccountAccessService;
use App\Support\Errors;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * The masjid onboarding someone to run the Jummah lunch, and nothing else.
 *
 * Mounted inside the masjid-scoped admin group (`admin` + `tenant`), so BOTH a
 * SuperAdmin (via the route masjid) and the masjid's own MasjidAdmin (bound to
 * theirs) can run it — which is exactly the control this was asked for.
 *
 * ## Why no `permission:` gate
 *
 * It sits beside the Jummah-lunch routes, OUTSIDE the `crm` group, and carries
 * no permission for the same reason they don't: a masjid running a food sale
 * must not first have to switch on the member directory. `admin` already means
 * MasjidAdmin-or-SuperAdmin, which is the whole audience for this endpoint, and
 * minting a permission would break the `Permission::count() === 8` invariant
 * four tests pin.
 *
 * ## The escalation this deliberately cannot do
 *
 * `type` is NEVER read from the request. Every login created here is
 * User::TYPE_LUNCH_STAFF, and its entire reach is the `masjid_user` row written
 * in the same transaction — bound to the BOUND tenant, not to any id in the
 * body. So a MasjidAdmin holding this endpoint cannot mint an admin, cannot mint
 * a login at another masjid, and cannot widen one they already made: `update()`
 * touches name and phone only, and `resolve()` refuses any user that is not
 * already lunch staff of this masjid. Ownership (`masjids.user_id`) is never
 * touched.
 *
 * $masjid_id is route-shape; the authoritative masjid is the BOUND tenant.
 */
class LunchStaffController extends Controller
{
    public function __construct(private TenantContext $tenant)
    {
    }

    /** This masjid's lunch logins. */
    public function index($masjid_id)
    {
        $masjidId = (int) $this->tenant->get();

        $userIds = MasjidUser::where('masjid_id', $masjidId)->pluck('user_id');

        $staff = User::whereIn('id', $userIds)
            ->where('type', User::TYPE_LUNCH_STAFF)
            ->orderBy('name')
            ->get()
            ->map(fn (User $u) => $this->serialize($u))
            ->values();

        return response()->json([
            'status' => 'success',
            'data' => $staff,
        ], Response::HTTP_OK);
    }

    public function store(StoreLunchStaffRequest $request, $masjid_id, AccountAccessService $access)
    {
        $masjidId = (int) $this->tenant->get();
        $user = null;

        try {
            DB::transaction(function () use ($request, $masjidId, &$user) {
                $user = User::create([
                    'name' => $request->validated('name'),
                    'email' => $request->validated('email'),
                    // users.phone is NOT NULL; an empty string is a real "none on file".
                    'phone' => $request->validated('phone') ?? '',
                    // NOT from the request. See the class docblock.
                    'type' => User::TYPE_LUNCH_STAFF,
                    // A random password nobody holds — they set their own from
                    // the invite. Never a default or a shared one.
                    'password' => Str::password(40),
                ]);

                // Their one masjid. This membership is the whole of their reach:
                // ResolveMasjidTenant binds LunchStaff from here, never from a
                // URL, so there is no id for them to change.
                MasjidUser::create([
                    'masjid_id' => $masjidId,
                    'user_id' => $user->id,
                    'role' => 'lunch-staff',
                    'is_default' => true,
                ]);
            });
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'failed',
                'data' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // Only after the row is committed — a rolled-back transaction must not
        // send an invite to a user that does not exist.
        $orgName = optional(Masjid::withoutGlobalScopes()->find($masjidId))->name;
        $sent = $access->invite($user, $orgName);

        return response()->json([
            'status' => 'success',
            'message' => $sent
                ? 'Lunch access created. An invitation to set their password is on its way to ' . $user->email . '.'
                : 'Lunch access created, but no invitation could be sent.',
            'data' => $this->serialize($user),
        ], Response::HTTP_CREATED);
    }

    /** Name and phone only — nothing here can widen what the login reaches. */
    public function update(UpdateLunchStaffRequest $request, $masjid_id, $user_id)
    {
        $user = $this->resolve($user_id);

        $user->name = $request->validated('name');
        $user->phone = $request->validated('phone') ?? '';
        $user->save();

        return response()->json([
            'status' => 'success',
            'data' => $this->serialize($user),
        ], Response::HTTP_OK);
    }

    /** Re-send the set-your-own-password invite ("they never got it"). */
    public function invite($masjid_id, $user_id, AccountAccessService $access)
    {
        $user = $this->resolve($user_id);
        $orgName = optional(Masjid::withoutGlobalScopes()->find((int) $this->tenant->get()))->name;

        $sent = $access->invite($user, $orgName);

        return response()->json([
            'status' => $sent ? 'success' : 'failed',
            'message' => $sent
                ? 'Invitation re-sent to ' . $user->email . '.'
                : 'No invitation could be sent.',
        ], $sent ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * Remove the login from this masjid.
     *
     * Tokens are deleted first and unconditionally: a session already sitting in
     * a browser must stop working now, not whenever it happens to expire. The
     * membership is what the tenant binding reads, so removing it alone would
     * already fail the principal closed — deleting the tokens as well means the
     * credential stops existing rather than merely being refused.
     */
    public function destroy($masjid_id, $user_id)
    {
        $user = $this->resolve($user_id);
        $masjidId = (int) $this->tenant->get();

        DB::transaction(function () use ($user, $masjidId) {
            $user->tokens()->delete();

            MasjidUser::where('masjid_id', $masjidId)->where('user_id', $user->id)->delete();

            // Belongs to no organisation any more -> retire the login.
            if (! MasjidUser::where('user_id', $user->id)->exists()) {
                $user->delete();
            }
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Lunch access removed.',
        ], Response::HTTP_OK);
    }

    /**
     * The user, if they really are lunch staff OF THE BOUND MASJID.
     *
     * Both halves matter. Without the type check this endpoint would edit or
     * delete a MasjidAdmin by id; without the membership check it would reach
     * another masjid's volunteer. A miss is a 404, not a 403 — an admin has no
     * business learning that some other id exists.
     */
    private function resolve($userId): User
    {
        $masjidId = (int) $this->tenant->get();

        $isMember = MasjidUser::where('masjid_id', $masjidId)
            ->where('user_id', $userId)
            ->exists();

        abort_unless($isMember, Response::HTTP_NOT_FOUND);

        return User::where('id', $userId)
            ->where('type', User::TYPE_LUNCH_STAFF)
            ->firstOrFail();
    }

    private function serialize(User $user): array
    {
        return [
            'id' => (int) $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone ?: null,
            // Created but never signed in — the office's "did they get it?".
            'invited' => $user->email_verified_at === null,
        ];
    }
}
