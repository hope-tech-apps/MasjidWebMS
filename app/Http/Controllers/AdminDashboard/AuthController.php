<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Requests\Admin\Auth\ForgotPasswordRequest;
use App\Http\Requests\Admin\Auth\ResetPasswordRequest;
use App\Services\Auth\AccountAccessService;
use Illuminate\Support\Facades\Password as PasswordBroker;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Auth\LoginRequest;
use App\Http\Requests\Admin\Auth\UpdateProfileRequest;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    /**
     * Abilities stamped on every staff login token.
     *
     * Previously `createToken()` was called with its default, `['*']` — a
     * wildcard that satisfies any future `tokenCan()` check. That is fine while
     * `App\Models\User` is the only tokenable model, but it means staff tokens
     * would also satisfy an ability check written to fence off a SECOND realm
     * (the parent/guardian surface of T-015c onwards). Naming the realm now,
     * while there is exactly one, is what makes such a check possible later
     * without a flag day for every already-issued token.
     *
     * This is INERT today, deliberately: `tokenCan` / the `abilities` middleware
     * appear nowhere in this application, nor in the framework or spatie code
     * paths this app uses, so no request outcome changes. Route-level ability
     * enforcement is assigned to a later slice.
     *
     * See docs/t015-parent-identity-design.md §5 (layer 3).
     */
    public const STAFF_TOKEN_ABILITIES = ['staff'];

    public function login(LoginRequest $request)
    {
        $user = User::where('email', $request->input('email'))->with('avatar')->first();
        if (!Hash::check($request->input('password'), $user->password)) {
            return response()->json(['message' => 'invalid credentials']);
        }

        // --- Additive 2FA gate (NO lockout) ------------------------------------
        // Runs ONLY after valid email+password, and ONLY for users who have
        // CONFIRMED enrollment. Unenrolled users skip this entirely and log in
        // exactly as before — no extra step, no behavior change. A future
        // `crm.require_admin_2fa` flag (default false) can enforce enrollment
        // globally without another code change; it is intentionally NOT consulted
        // here so today's behavior is preserved.
        if ($user->hasTwoFactorEnabled()) {
            $code = $request->input('two_factor_code');

            // No code supplied -> return a clear challenge WITHOUT issuing a
            // token, so the client can prompt for the code and retry.
            if (empty($code)) {
                return response()->json([
                    'status' => 'two_factor_required',
                    'message' => 'A two-factor authentication code is required to continue.',
                ], Response::HTTP_OK);
            }

            // Wrong code -> deny before any token is created.
            if (! app(TwoFactorService::class)->verify($user->two_factor_secret, $code)) {
                return response()->json([
                    'status' => 'failed',
                    'message' => 'The two-factor authentication code is invalid.',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }
        // -----------------------------------------------------------------------

        $token = $user->createToken('login-token', self::STAFF_TOKEN_ABILITIES)->plainTextToken;

        if ($user->type === 'MasjidAdmin') {
            $user->masjid;
            if ($user->masjid) {
                $user->masjid->logo = $user->masjid->logo()->first();
            } else {
                Auth::logout();
                return response()->json([
                    'status' => 'failed',
                    'message' => "Sorry, you don't have a related masjid to your account."
                ], Response::HTTP_OK);
            }
        } elseif ($user->type === 'Teacher') {
            // A teacher owns no masjid (masjids.user_id is never theirs), so the
            // MasjidAdmin `hasOne` above is null for them. Their school is their
            // masjid_user membership, resolved and attached as the `masjid`
            // relation so the SPA reads user.masjid uniformly for both staff types.
            $masjid = $this->teacherMasjid($user);

            if (! $masjid) {
                Auth::logout();
                return response()->json([
                    'status' => 'failed',
                    'message' => "Sorry, you are not assigned to a school yet.",
                ], Response::HTTP_OK);
            }

            $masjid->logo = $masjid->logo()->first();
            $user->setRelation('masjid', $masjid);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'user' => $user,
                'token' => $token,
            ]
        ], Response::HTTP_OK);
    }

    /**
     * The school a teacher belongs to — their sole `masjid_user` membership.
     *
     * `whereHas('masjid')` drops a membership whose organisation has been trashed
     * (Masjid soft-deletes), matching TenantResolver::staffMemberships so the
     * login payload and the tenant binding cannot disagree about which school a
     * teacher has. Runs UNBOUND (login is a public route), which is correct:
     * memberships() is not tenant-scoped, exactly as the resolver reads it.
     */
    private function teacherMasjid(User $user): ?\App\Models\Masjid
    {
        return $user->memberships()
            ->whereHas('masjid')
            ->with('masjid')
            ->orderBy('masjid_id')
            ->first()?->masjid;
    }

    public function user()
    {
        try {
            if (Auth::check() && Auth::user()) {
                $user = Auth::user();

                $user->avatar = $user->avatar()->first();

                if ($user->type === 'MasjidAdmin') {
                    $user->masjid;
                    if ($user->masjid) {
                        $user->masjid->logo = $user->masjid->logo()->first();
                    } else {
                        Auth::logout();
                        return response()->json([
                            'status' => 'failed',
                            'message' => "Sorry, you don't have a related masjid to your account."
                        ], Response::HTTP_OK);
                    }
                } elseif ($user->type === 'Teacher') {
                    $masjid = $this->teacherMasjid($user);

                    if (! $masjid) {
                        Auth::logout();
                        return response()->json([
                            'status' => 'failed',
                            'message' => "Sorry, you are not assigned to a school yet.",
                        ], Response::HTTP_OK);
                    }

                    $masjid->logo = $masjid->logo()->first();
                    $user->setRelation('masjid', $masjid);
                }

                return response()->json([
                    'status' => 'success',
                    'data' => $user
                ], Response::HTTP_OK);
            }
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => \App\Support\Errors::publicMessage($e)
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Ask for a reset link.
     *
     * ALWAYS answers the same thing, whether or not the address belongs to an
     * account. This endpoint is necessarily unauthenticated, and one that
     * distinguishes "sent" from "no such user" is a free list of who has an
     * account on the platform.
     */
    public function forgotPassword(ForgotPasswordRequest $request, AccountAccessService $access)
    {
        $access->sendResetLink((string) $request->validated('email'));

        return response()->json([
            'status' => 'success',
            'message' => 'If that address belongs to an account, a reset link is on its way. '.
                'The link expires in an hour.',
        ], Response::HTTP_OK);
    }

    /**
     * Set a new password from a link. Also used for the FIRST password on an
     * invited account — same token, same expiry, same single use.
     */
    public function resetPassword(ResetPasswordRequest $request, AccountAccessService $access)
    {
        $status = $access->reset($request->only('email', 'password', 'password_confirmation', 'token'));

        if ($status !== PasswordBroker::PASSWORD_RESET) {
            return response()->json([
                'status' => 'error',
                'message' => $status === PasswordBroker::INVALID_TOKEN
                    ? 'That link has already been used or has expired. Ask for a new one.'
                    : 'That link could not be used. Ask for a new one.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Your password is set. You can sign in with it now.',
        ], Response::HTTP_OK);
    }

    public function logout()
    {
        if (Auth::check() && Auth::user()) {
            Auth::guard('sanctum')->user()->tokens()->delete();
        }
    }

    public function updateProfile(UpdateProfileRequest $request)
    {
        try {
            $authUser = Auth::user();
            $user = User::findOrFail($authUser->id);

            $user->update($request->safe()->only([
                'name', 'email', 'phone', 'password',
            ]));

            if ($user && $request->hasFile('avatar')) {
                $user->addMediaFromRequest('avatar')->toMediaCollection('avatars');
            }

            return response()->json([
                'status' => 'success',
                'data' => $user->load('avatar')
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'data' => \App\Support\Errors::publicMessage($e)
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
