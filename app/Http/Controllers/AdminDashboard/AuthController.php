<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Requests\Admin\Auth\ForgotPasswordRequest;
use App\Http\Requests\Admin\Auth\ResetPasswordRequest;
use App\Services\Auth\AccountAccessService;
use Illuminate\Support\Facades\Password as PasswordBroker;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Auth\LoginRequest;
use App\Http\Requests\Admin\Auth\UpdateProfileRequest;
use App\Models\MasjidUser;
use App\Models\User;
use App\Services\TwoFactorService;
use App\Support\TenantResolver;
use Illuminate\Support\Collection;
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

    public function __construct(private TenantResolver $resolver)
    {
    }

    public function login(LoginRequest $request)
    {
        $user = User::where('email', $request->input('email'))->with('avatar')->first();

        // No user is a WRONG PASSWORD, not a 500. LoginRequest's `exists` rule
        // catches an unknown address, but it does not exclude SOFT-DELETED rows —
        // so every address the office has ever removed still passes validation,
        // arrives here as null, and used to die on `$user->password` with
        // "Attempt to read property password on null". A removed teacher trying
        // their old login got a server error instead of a refusal.
        if (! $user || ! Hash::check($request->input('password'), $user->password)) {
            return response()->json(['message' => 'invalid credentials']);
        }

        // --- Additive 2FA gate (NO permanent lockout) --------------------------
        // Runs ONLY after valid email+password, and ONLY for users who have
        // CONFIRMED enrollment. Unenrolled users skip this entirely and log in
        // exactly as before — no extra step, no behavior change. Nothing inside
        // this block may move above it or grow a condition that a user with
        // two_factor_confirmed_at === null can satisfy: that is the difference
        // between an opt-in second factor and locking every administrator on the
        // platform out at once. A future
        // `crm.require_admin_2fa` flag (default false) can enforce enrollment
        // globally without another code change; it is intentionally NOT consulted
        // here so today's behavior is preserved.
        if ($user->hasTwoFactorEnabled()) {
            $twoFactor = app(TwoFactorService::class);
            $code = $request->input('two_factor_code');
            $recoveryCode = $request->input('two_factor_recovery_code');

            // Repeated wrong codes lock the SECOND FACTOR for a few minutes.
            // This is a row-level counter, deliberately duplicating the
            // `throttle:login` limiter rather than trusting it: that one is
            // keyed on email+IP and lives in the cache, so an attacker who
            // already holds the password and rotates addresses is not slowed by
            // it, and a cache flush re-arms them. The lock always EXPIRES on its
            // own — a permanent lock on somebody else's second factor is a free
            // denial of service, and there is no honest answer to "who unlocks
            // me?" that is not the clock.
            if ($twoFactor->isLockedOut($user)) {
                return response()->json([
                    'status' => 'failed',
                    'message' => 'Too many incorrect codes. Try again in '
                        . $twoFactor->lockedForMinutes($user) . ' minute(s).',
                ], Response::HTTP_TOO_MANY_REQUESTS);
            }

            // No code supplied -> return a clear challenge WITHOUT issuing a
            // token, so the client can prompt for the code and retry. HTTP 200
            // with no token is the shape the SPA switches on; do not "improve"
            // it to a 401/403.
            if (empty($code) && empty($recoveryCode)) {
                return response()->json([
                    'status' => 'two_factor_required',
                    'message' => 'A two-factor authentication code is required to continue.',
                ], Response::HTTP_OK);
            }

            // A recovery code is the way back in for somebody whose
            // authenticator is gone. It is SPENT here — consumeRecoveryCode()
            // removes it and saves before this method mints anything — so the
            // same printed line cannot be replayed, by them or by whoever found
            // the paper. On success the flow continues to token minting exactly
            // as a valid TOTP code does; there is no second-class session.
            $accepted = ! empty($recoveryCode)
                ? $twoFactor->consumeRecoveryCode($user, (string) $recoveryCode)
                : $twoFactor->verifyAndConsume($user, (string) $code);

            // Wrong code -> deny before any token is created. One message for
            // every kind of wrong (bad, replayed, unknown recovery code): a
            // refusal that distinguishes them tells an attacker which guesses
            // were close.
            if (! $accepted) {
                $twoFactor->registerFailure($user);

                return response()->json([
                    'status' => 'failed',
                    'message' => 'The two-factor authentication code is invalid.',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $twoFactor->clearFailures($user);
        }
        // -----------------------------------------------------------------------

        $token = $user->createToken('login-token', self::STAFF_TOKEN_ABILITIES)->plainTextToken;

        if ($user->type === 'MasjidAdmin') {
            // `masjid()` is a hasOne over masjids.user_id — OWNERSHIP. An admin
            // added by AdministratorsController owns nothing; their organisation
            // is a masjid_user row, which is exactly what TenantResolver binds
            // them from on every subsequent request. Without the fallback below
            // they set a password from their invite and were then refused here
            // with "you don't have a related masjid", so the whole "second
            // person in the office" feature produced logins that could not log
            // in. Strictly additive: an owner-admin still takes the first branch
            // unchanged, and the refusal still stands for an admin with neither
            // — fail-closed rather than unkind, since ResolveMasjidTenant 403s
            // such a principal on every subsequent request anyway.
            //
            // THIS IS OWNERSHIP-FIRST, and S4 leaves it that way. Once
            // `tenancy.multi_membership` opens, an owner who also holds a
            // membership elsewhere still resolves here to the organisation they
            // OWN, even when their default grant is the other one. That is why
            // the SPA must paint its chrome from `memberships[]` plus the echoed
            // `X-Manara-Tenant` (App\Http\Middleware\EchoResolvedTenant) and
            // treat `user.masjid` as a first-paint convenience, never as the
            // answer to "which organisation am I looking at?".
            $masjid = $user->masjid ?: $this->staffMembershipMasjid($user);

            if ($masjid) {
                $masjid->logo = $masjid->logo()->first();
                $user->setRelation('masjid', $masjid);
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
            $masjid = $this->staffMembershipMasjid($user);

            if (! $masjid) {
                Auth::logout();
                return response()->json([
                    'status' => 'failed',
                    'message' => "Sorry, you are not assigned to a school yet.",
                ], Response::HTTP_OK);
            }

            $masjid->logo = $masjid->logo()->first();
            $user->setRelation('masjid', $masjid);
        } elseif ($user->type === User::TYPE_LUNCH_STAFF) {
            // Same shape as Teacher and for the same reason: lunch staff own no
            // masjid, so the hasOne above is null and their organisation is their
            // membership. Refusing the login when there is none is not politeness
            // — ResolveMasjidTenant fails a memberless principal closed on every
            // request, so a token here would be one that can do nothing at all.
            $masjid = $this->staffMembershipMasjid($user);

            if (! $masjid) {
                Auth::logout();

                return response()->json([
                    'status' => 'failed',
                    'message' => 'Sorry, your account is not linked to a masjid yet.',
                ], Response::HTTP_OK);
            }

            $masjid->logo = $masjid->logo()->first();
            $user->setRelation('masjid', $masjid);
        }

        $this->attachMemberships($user);

        return response()->json([
            'status' => 'success',
            'data' => [
                'user' => $user,
                'token' => $token,
            ]
        ], Response::HTTP_OK);
    }

    /**
     * The organisation of a staff principal resolved from their `masjid_user`
     * membership — the same source ResolveMasjidTenant binds them from.
     *
     * Used by every principal who may own no masjid: a Teacher, a LunchStaff,
     * and a MasjidAdmin added by AdministratorsController rather than one who
     * owns the row. Deliberately ONE method — it briefly existed twice, byte for
     * byte, and two copies of "which organisation does this login belong to" is
     * how the login payload and the tenant binding start disagreeing.
     *
     * `whereHas('masjid')` drops a membership whose organisation has been
     * trashed (Masjid soft-deletes), matching TenantResolver::staffMemberships.
     * Runs UNBOUND (login is a public route), which is correct: memberships()
     * is not tenant-scoped, exactly as the resolver reads it.
     */
    private function staffMembershipMasjid(User $user): ?\App\Models\Masjid
    {
        return $user->memberships()
            ->whereHas('masjid')
            ->with('masjid')
            // The DEFAULT membership first (S4), then the lowest masjid_id this
            // method has always tie-broken on. The ordering used to be masjid_id
            // alone: deterministic, but arbitrary the moment a person holds two
            // memberships — the login payload would name whichever organisation
            // sorts first while `memberships[].is_default` and the S5 switcher
            // name another, which is the chrome-over-the-wrong-organisation's-
            // data mismatch this slice exists to remove. Identical output for
            // anybody holding exactly one membership, i.e. everybody, because
            // the one-membership gate is shut.
            ->orderByDesc('is_default')
            ->orderBy('masjid_id')
            ->first()?->masjid;
    }

    /**
     * The organisations this login may actually act in — what the S5 switcher
     * lists — attached to the payload as `memberships[]`.
     *
     * Shape per row: `masjid_id`, `role` (advisory; authorization stays on the
     * global `users.type` bridge), `is_default`, and a nested `masjid`
     * (`id`, `name`, `org_type`). It is the shape the switcher already types —
     * resources/vue-app/core/types/data/Membership.ts.
     *
     * The list is derived from App\Support\TenantResolver, NOT from a second
     * reading of the pivot, and that is the whole point of the method. A
     * switcher built from `masjid_user` rows would offer every organisation a
     * row exists for; the resolver grants a strict subset of those (the
     * one-membership gate, soft-deleted organisations, the ownership fallback
     * for masjids the S2 backfill never reached). Offering an organisation the
     * server will then 403 is not a cosmetic bug: the SPA switches its chrome
     * first and discovers the refusal a request later, which is exactly the
     * state where organisation A's name sits above organisation B's data.
     *
     * So each candidate is put to the resolver as the route would put it — "may
     * this user act on masjid N?" — and only the ones it binds are listed. The
     * gate is therefore read in exactly one place, still
     * `TenantResolver::grantsFor()`, and this list cannot drift from it.
     *
     * A SuperAdmin gets NO `memberships` KEY AT ALL — not an empty array, and
     * the difference is a visible bug rather than a nicety. They hold no
     * memberships (S2's backfill gave them none) and ResolveMasjidTenant binds
     * them from the ROUTE, so they reach every organisation through the masjid
     * picker rather than through a switcher. But the switcher store reads an
     * `memberships: []` ARRAY as "the server described this principal and
     * granted them nothing" (`hasNoOrganisation`, tenantSwitchStore.ts) — and a
     * SuperAdmin also has `user.masjid === null`, so every Manara SuperAdmin
     * would load the dashboard behind a notice telling them they belong to no
     * organisation. An ABSENT key is the one the SPA reads as "this principal
     * switches nothing", which is exactly true of them.
     *
     * READ THE QUESTION PRECISELY: this is "which organisations would the
     * resolver bind if the ROUTE named them?". That is exactly the admin SPA's
     * switcher contract, because every admin screen is `/masjids/{id}/…`. It is
     * NOT the question the principal-bound realms ask — `user()` is reused by
     * routes/teacher.php and routes/lunch.php, whose URLs name no masjid at all,
     * so for those shells the list is informational rather than a switcher. It
     * cannot mislead them either way: the resolver already refuses a Teacher or
     * a LunchStaff login holding more than one membership on every route in
     * those realms, with or without this list.
     */
    private function attachMemberships(User $user): void
    {
        // See the docblock: absent, not empty. Returning before the setAttribute
        // is the whole implementation of that distinction.
        if ($user->type === 'SuperAdmin') {
            return;
        }

        // `memberships` is also the name of a hasMany on User. An attribute and
        // a LOADED relation of the same name both reach toArray(), and the
        // relation wins — so a future `->load('memberships')` anywhere upstream
        // would silently replace this list with raw pivot rows, which is the
        // superset the paragraph above refuses to publish. Drop it first.
        $user->unsetRelation('memberships');

        $user->setAttribute('memberships', $this->grantedMemberships($user)->map(fn (MasjidUser $grant) => [
            // The one field the switcher cannot work without, and the key
            // everything downstream joins on.
            'masjid_id' => (int) $grant->masjid_id,
            // Advisory. Mirrors User::TYPE_ROLE_MAP and authorizes nothing on
            // its own (.claude/rules/tenant-scoping.md).
            'role' => $grant->role,
            'is_default' => (bool) $grant->is_default,
            // NESTED, because that is what the switcher reads to NAME the
            // organisation (core/types/data/Membership.ts, membershipLabel()):
            // a flat `name` here renders every menu row as "Organisation #14".
            // `logo` is deliberately omitted — it is a separate media query per
            // grant on an endpoint the SPA calls on every page load, and the
            // switcher falls back to the name without it.
            'masjid' => [
                'id' => (int) $grant->masjid->id,
                'name' => $grant->masjid->name,
                'org_type' => $grant->masjid->org_type,
            ],
            // `masjid_user.id` is deliberately NOT published. A grant is
            // sometimes an UNSAVED row — TenantResolver::membershipFromOwnership()
            // synthesises one for an owner the S2 backfill never reached — so
            // the id would be a real number for some administrators and null for
            // others, and anything keying a switcher on it would collapse all of
            // the latter onto one entry. Absent for everybody cannot be keyed on
            // by mistake; the grant/revoke endpoints address a membership by
            // (masjid_id, user_id) for the same reason.
        ])->values()->all());
    }

    /**
     * @return Collection<int, MasjidUser> the grants the resolver would bind,
     *         each with its masjid loaded
     */
    private function grantedMemberships(User $user): Collection
    {
        // ASK THE RESOLVER ABOUT A PRINCIPAL WITH NO RELATIONS LOADED, and this
        // is not defensive tidiness — it is load-bearing.
        //
        // Both callers reach this AFTER `setRelation('masjid', …)`, which pins
        // the `masjid` relation to the organisation resolved from a MEMBERSHIP
        // for anybody who owns nothing. `TenantResolver::ownedMasjidId()` reads
        // `$user->masjid?->id` to mean OWNERSHIP, and a loaded relation is
        // returned without a query — so the resolver would be told a pivot-only
        // administrator owns the masjid they merely belong to, take the
        // single-owned-membership branch, and refuse every OTHER organisation
        // they hold. The list would come back empty for exactly the people the
        // switcher exists for. A clone with no relations makes the resolver load
        // the fact itself, which is what it does on a real request.
        $principal = $user->withoutRelations();

        return $this->candidateMasjidIds($principal)
            ->map(function (int $masjidId) use ($principal): ?MasjidUser {
                // Asked with a ROUTE masjid id, which is how ResolveMasjidTenant
                // asks it — so the verdict here is the same one the switcher's
                // very next request will get. `denied` and `unbound` both answer
                // with a null membership and are dropped by the filter below.
                return $this->resolver->resolve($principal, $masjidId)->membership();
            })
            ->filter()
            // `membershipFromOwnership()` hands back an UNSAVED MasjidUser, but
            // it carries `masjid_id`, so the belongsTo resolves for it exactly
            // as it does for a persisted row. The null check is the backstop for
            // the one case that would otherwise publish a nameless entry: an
            // organisation trashed between the resolver's verdict and this read.
            ->filter(fn (MasjidUser $grant): bool => $grant->masjid !== null)
            ->values();
    }

    /**
     * Every organisation worth ASKING the resolver about: the masjids this user
     * holds a membership row in, plus the one they own.
     *
     * Deliberately a superset — it decides nothing. Ownership is included
     * because `TenantResolver::soleOwnedMembership()` still binds an owner whose
     * `masjid_user` row the S2 backfill never wrote (every masjid provisioned
     * since), and such an admin would otherwise see an empty switcher on the
     * very screen that is supposed to name their organisation.
     *
     * @return Collection<int, int>
     */
    private function candidateMasjidIds(User $user): Collection
    {
        return $user->memberships()
            ->pluck('masjid_id')
            ->push($user->masjid?->id)
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->sort()
            ->values();
    }

    public function user()
    {
        try {
            if (Auth::check() && Auth::user()) {
                $user = Auth::user();

                $user->avatar = $user->avatar()->first();

                if ($user->type === 'MasjidAdmin') {
                    // Same fallback as login(): ownership first, membership
                    // second. This runs on EVERY page load, so an admin who can
                    // sign in but not be re-identified here is signed straight
                    // back out on their first refresh.
                    $masjid = $user->masjid ?: $this->staffMembershipMasjid($user);

                    if ($masjid) {
                        $masjid->logo = $masjid->logo()->first();
                        $user->setRelation('masjid', $masjid);
                    } else {
                        Auth::logout();

                        return response()->json([
                            'status' => 'failed',
                            'message' => "Sorry, you don't have a related masjid to your account."
                        ], Response::HTTP_OK);
                    }
                } elseif ($user->type === User::TYPE_LUNCH_STAFF) {
                    $masjid = $this->staffMembershipMasjid($user);

                    if (! $masjid) {
                        Auth::logout();

                        return response()->json([
                            'status' => 'failed',
                            'message' => 'Sorry, your account is not linked to a masjid yet.',
                        ], Response::HTTP_OK);
                    }

                    $masjid->logo = $masjid->logo()->first();
                    $user->setRelation('masjid', $masjid);
                } elseif ($user->type === 'Teacher') {
                    $masjid = $this->staffMembershipMasjid($user);

                    if (! $masjid) {
                        Auth::logout();
                        return response()->json([
                            'status' => 'failed',
                            'message' => "Sorry, you are not assigned to a school yet.",
                        ], Response::HTTP_OK);
                    }

                    $logo = $masjid->logo()->first();
                    $masjid->logo = $logo;
                    // The teacher shell reads `logo_url` and falls back to the
                    // Manara mark. It carried the whole Media object and no
                    // `logo_url`, so the school's own logo could never appear in
                    // the header however many were uploaded. Flattened here rather
                    // than as an $appends on Masjid, which would widen the public
                    // and mobile payloads too.
                    $masjid->logo_url = $logo?->original_url;
                    $user->setRelation('masjid', $masjid);

                    // Whether the teacher shell offers its Calendar link: the
                    // school has at least one school year. Teacher branch only,
                    // additive, and a fact about the school rather than the
                    // teacher. This route binds no tenant, so the school is named.
                    $user->school_calendar_published = \App\Models\SchoolYear::query()
                        ->where('masjid_id', (int) $masjid->id)
                        ->exists();
                }

                // The switcher's list, on the endpoint the SPA calls on every
                // page load. `instanceof` rather than a bare call because a
                // principal that is not a staff User must keep getting exactly
                // the payload it got before S4 — `admin` already 401s them one
                // layer earlier, and a TypeError here would turn that into a 500.
                if ($user instanceof User) {
                    $this->attachMemberships($user);
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
