<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Auth\ConfirmTwoFactorRequest;
use App\Http\Requests\Admin\Auth\DisableTwoFactorRequest;
use App\Http\Requests\Admin\Auth\RegenerateRecoveryCodesRequest;
use App\Http\Requests\Admin\Auth\ResetStrandedTwoFactorRequest;
use App\Mail\TwoFactorResetMail;
use App\Models\TwoFactorResetEvent;
use App\Models\User;
use App\Services\TwoFactorService;
use App\Support\Errors;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin self-service TOTP two-factor authentication
 * (enroll / confirm / disable / re-issue recovery codes), plus the ONE operator
 * door that acts on somebody else's account: resetForUser().
 *
 * The first four endpoints sit behind the existing `auth:sanctum` + `admin`
 * group, so the acting admin manages 2FA for their OWN account. Nothing there is
 * gated by a Spatie permission — enrollment is available to any admin — and
 * nothing there is gated by `crm`, per .claude/rules/auth-permissions.md.
 * `resetForUser()` is the exception in every respect and carries its own
 * docblock explaining what it costs and what pays for it.
 *
 * Enrollment is a two-step handshake so a mistyped secret never locks anyone out:
 *   1. enroll  — generate + persist a secret, return the otpauth URI + QR. NOT
 *                yet active (two_factor_confirmed_at stays null).
 *   2. confirm — verify a live code, then set two_factor_confirmed_at, which is
 *                the flag the login flow checks. Only now is 2FA active, and
 *                only now are recovery codes issued — once.
 *
 * ## Half-enrollment is SAFE BY CONSTRUCTION, and that is not an accident
 *
 * If the browser closes between the QR and the first verification, the account
 * is left holding a pending secret with `two_factor_confirmed_at` still null.
 * That state is INERT: `hasTwoFactorEnabled()` is false, so login is byte-for-
 * byte the login the user had yesterday, and starting enrollment again simply
 * rotates the pending secret. There is no state in which an account is asked for
 * a code it cannot produce.
 *
 * The dangerous half of that used to be real, though: enroll() nulled
 * `two_factor_confirmed_at` unconditionally, so an admin who ALREADY had 2FA on
 * and pressed the button once — or a hijacked session that called the endpoint —
 * silently switched the second factor off and left the account protected by the
 * password alone, while disable() next door demanded a live code to do the same
 * thing. enroll() now refuses while an enrollment is confirmed: changing a live
 * enrollment goes through disable(), which proves possession first.
 *
 * Response envelopes follow the app-wide { status, data|message } convention.
 */
class TwoFactorController extends Controller
{
    public function __construct(private TwoFactorService $twoFactor)
    {
    }

    /**
     * Generate a fresh secret for the authenticated admin and return the
     * enrollment payload (secret for manual entry + otpauth URI + inline QR).
     * Re-enrolling before confirming simply rotates the pending secret — say so
     * in the UI, because it invalidates the QR the user may already have scanned.
     */
    public function enroll()
    {
        try {
            /** @var User $user */
            $user = Auth::user();

            // A CONFIRMED enrollment is never rotated from here — see the class
            // docblock. Turning it off requires proving possession, so that is
            // where the user is sent.
            if ($user->hasTwoFactorEnabled()) {
                return response()->json([
                    'status' => 'failed',
                    'message' => 'Two-step sign-in is already on for this account. '
                        . 'Turn it off first (you will need a current code) to set up a new device.',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $secret = $this->twoFactor->generateSecret();

            // Persist the (encrypted) secret but do NOT enable 2FA yet — that
            // only happens on confirm(), once a valid code is proven.
            $user->two_factor_secret = $secret;
            $user->two_factor_confirmed_at = null;
            $user->save();

            $otpauthUri = $this->twoFactor->otpauthUri($user->email, $secret);

            return response()->json([
                'status' => 'success',
                'data' => [
                    'secret' => $secret,
                    'otpauth_uri' => $otpauthUri,
                    'qr_code' => $this->twoFactor->qrCodeDataUri($otpauthUri),
                ],
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'data' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Verify a submitted code against the pending secret and, on success, ENABLE
     * 2FA by stamping two_factor_confirmed_at. A wrong code -> 422 and 2FA stays
     * off.
     *
     * The recovery codes are generated HERE and returned in this one response.
     * They are never in any other payload (User::$hidden), so a client that
     * discards this body has genuinely lost them and must regenerate.
     */
    public function confirm(ConfirmTwoFactorRequest $request)
    {
        /** @var User $user */
        $user = Auth::user();

        if ($user->hasTwoFactorEnabled()) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Two-step sign-in is already on for this account.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // No pending secret -> the client skipped enroll(). Treat as a
        // validation failure rather than a 500.
        if (empty($user->two_factor_secret)) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Two-factor authentication has not been set up. Start enrollment first.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($locked = $this->refuseWhileLockedOut($user)) {
            return $locked;
        }

        if (! $this->twoFactor->verifyAndConsume($user, (string) $request->input('code'))) {
            $this->twoFactor->registerFailure($user);

            return $this->invalidCode();
        }

        $codes = $this->twoFactor->generateRecoveryCodes();

        $user->two_factor_confirmed_at = now();
        $user->two_factor_recovery_codes = $codes;
        $user->save();

        $this->twoFactor->clearFailures($user);

        return response()->json([
            'status' => 'success',
            'message' => 'Two-factor authentication is now enabled.',
            'data' => [
                // Shown ONCE. Nothing on the server will hand these back without
                // a fresh live code (see recoveryCodes below).
                'recovery_codes' => $codes,
            ],
        ], Response::HTTP_OK);
    }

    /**
     * Disable 2FA. Requires a valid current code so a hijacked session can't
     * silently strip a victim's second factor.
     *
     * An unused RECOVERY code is accepted here as well as a TOTP code, and that
     * is the whole point of the recovery path: somebody who signed in from a
     * printout has, by definition, no authenticator to read a live code from, so
     * a TOTP-only rule would let them in and then trap them — permanently signed
     * in with a second factor they can never satisfy again and never remove. A
     * recovery code spent here is spent for good, exactly as it is at sign-in.
     *
     * This grants an attacker holding a recovery sheet nothing new: the same
     * sheet already signs them in.
     */
    public function disable(DisableTwoFactorRequest $request)
    {
        /** @var User $user */
        $user = Auth::user();

        if (empty($user->two_factor_secret)) {
            return $this->invalidCode();
        }

        if ($locked = $this->refuseWhileLockedOut($user)) {
            return $locked;
        }

        $code = (string) $request->input('code');

        if (! $this->twoFactor->verifyAndConsume($user, $code)
            && ! $this->twoFactor->consumeRecoveryCode($user, $code)) {
            $this->twoFactor->registerFailure($user);

            return $this->invalidCode();
        }

        // Clears the secret, the confirmation stamp AND the recovery codes —
        // leaving those behind would leave eight strings that still authenticate
        // a login whose second factor the user just switched off.
        $this->twoFactor->forget($user);

        return response()->json([
            'status' => 'success',
            'message' => 'Two-factor authentication has been disabled.',
        ], Response::HTTP_OK);
    }

    /**
     * Re-issue the recovery codes, invalidating every previous one.
     *
     * This is how a user gets to LOOK at their codes again: the set is
     * replaced, not revealed, because "show me the codes" and "give me a fresh
     * set" are the same request once the old sheet is lost — and a read-only
     * peek would be a screen that prints eight standing credentials on demand
     * for whoever is sitting at the desk.
     *
     * A live TOTP code is required (RegenerateRecoveryCodesRequest says why a
     * recovery code is not accepted in its place).
     */
    public function recoveryCodes(RegenerateRecoveryCodesRequest $request)
    {
        /** @var User $user */
        $user = Auth::user();

        if (! $user->hasTwoFactorEnabled()) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Two-step sign-in is not on for this account.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($locked = $this->refuseWhileLockedOut($user)) {
            return $locked;
        }

        if (! $this->twoFactor->verifyAndConsume($user, (string) $request->input('code'))) {
            $this->twoFactor->registerFailure($user);

            return $this->invalidCode();
        }

        $codes = $this->twoFactor->generateRecoveryCodes();

        $user->two_factor_recovery_codes = $codes;
        $user->save();

        $this->twoFactor->clearFailures($user);

        return response()->json([
            'status' => 'success',
            'message' => 'New recovery codes have been generated. The previous set no longer works.',
            'data' => [
                'recovery_codes' => $codes,
            ],
        ], Response::HTTP_OK);
    }

    /**
     * THE OPERATOR DOOR: clear a STRANDED second factor on another account.
     *
     * ## The hole this fills
     *
     * A confirmed enrolment could brick an account permanently. enroll() refuses
     * while confirmed, recoveryCodes() wants a live code, disable() wants a live
     * code or an unused recovery code, and nothing else in the application ever
     * writes a `two_factor_*` column. Lose the phone, lose the printed sheet —
     * or spend the eighth of eight codes on the login where you discovered the
     * phone was gone — and that administrator is out of the platform forever.
     * Until this endpoint, the only way back was an UPDATE typed into the
     * production database, which is not a recovery path: it is untraceable, it
     * is unreviewable, and it is available to a wider circle of people than this
     * endpoint is.
     *
     * ## Why the three obvious designs were each refused
     *
     *  - A SELF-SERVICE EMAIL RESET makes the mailbox the real second factor.
     *    Everyone who phishes a password phishes the mailbox next.
     *  - LETTING ANY ADMIN CLEAR ANOTHER ADMIN'S makes the weakest admin account
     *    on the platform the real second factor for every other one.
     *  - DOING NOTHING is the status quo, and the status quo permanently locks
     *    people out of their own organisations.
     *
     * ## What actually guards this one
     *
     * A SuperAdmin can already set any staff password (UsersController::update),
     * so the second factor is the ONE thing standing between a stolen SuperAdmin
     * session and every administrator on the platform. This endpoint therefore
     * asks the operator for something a stolen session does not carry:
     *
     *  1. The operator must be a SuperAdmin (route `super`, re-checked below).
     *  2. The operator must have their OWN second factor confirmed, and must
     *     pass a LIVE code from it on this request — possession of their
     *     authenticator, right now, not merely their password or their cookie.
     *     A recovery code is not accepted: a printed line lifted off a desk
     *     should sign that person in, not let the finder disarm a third party.
     *  3. They must type the subject's email address, which is matched against
     *     the row. An id off a list is how you clear the account NEXT TO the one
     *     you meant.
     *  4. They must write a reason, which is kept forever on
     *     `two_factor_reset_events` next to their name, the time and the IP.
     *  5. The subject is EMAILED, always, naming the operator and quoting the
     *     reason. The act is reviewable afterwards and visible on the day.
     *  6. NEVER on yourself. An operator who can pass their own live code is by
     *     definition not stranded and would use disable(); allowing it would
     *     only add a shape where one session strips its own factor. The last
     *     SuperAdmin standing is handled by `php artisan two-factor:reset`,
     *     which writes the same ledger row and demands a human's name.
     *
     * And what it deliberately does NOT do: it mints no token, changes no
     * password, and revokes none of the subject's sessions. It REMOVES A FACTOR
     * and nothing else — the stranded admin still has to know their password to
     * get back in, and the operator still cannot read anybody's data through it.
     */
    public function resetForUser(ResetStrandedTwoFactorRequest $request, $user_id)
    {
        /** @var User $operator */
        $operator = Auth::user();

        // Belt AND braces on the role. The `super` middleware is on the route,
        // and the route file is owned by another agent this wave — an endpoint
        // this dangerous must not be one careless route line away from being
        // open to every MasjidAdmin on the platform.
        if (! $operator instanceof User || $operator->type !== 'SuperAdmin') {
            return response()->json([
                'status' => 'failed',
                'data' => 'Unauthorized.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $subject = User::find($user_id);

        if (! $subject) {
            return response()->json([
                'status' => 'failed',
                'message' => 'That account no longer exists.',
            ], Response::HTTP_NOT_FOUND);
        }

        if ((int) $subject->getKey() === (int) $operator->getKey()) {
            return response()->json([
                'status' => 'failed',
                'message' => 'This is for somebody else\'s account. To change your own second factor, '
                    . 'turn it off from your profile with a current code or a recovery code.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // The operator's own second factor is the price of admission. Without
        // it this endpoint would be reachable with a password alone, and a
        // password is the thing SuperAdmins can already reset for everybody.
        if (! $operator->hasTwoFactorEnabled()) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Turn two-step sign-in on for your own account first. '
                    . 'Clearing somebody else\'s second factor requires a live code from yours.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($locked = $this->refuseWhileLockedOut($operator)) {
            return $locked;
        }

        // Case-insensitive, whitespace-tolerant: the operator is typing an
        // address to confirm WHICH row, not proving they know a secret, so a
        // plain comparison is the honest one — this is a confirmation step, not
        // a credential check, and dressing it as one would suggest otherwise.
        if (mb_strtolower(trim((string) $subject->email))
            !== mb_strtolower(trim((string) $request->input('subject_email')))) {
            return response()->json([
                'status' => 'failed',
                'message' => 'That email address does not match the account you selected.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Checked BEFORE the operator's code is verified, because verifying
        // burns it for the replay window: an operator who lands on the wrong
        // row should not have to wait two minutes for a fresh code to try the
        // right one.
        if (! $subject->hasTwoFactorEnabled() && empty($subject->two_factor_secret)) {
            return response()->json([
                'status' => 'failed',
                'message' => 'That account has no second factor set up, so there is nothing to clear.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (! $this->twoFactor->verifyAndConsume($operator, (string) $request->input('code'))) {
            $this->twoFactor->registerFailure($operator);

            return $this->invalidCode();
        }

        $this->twoFactor->clearFailures($operator);

        $reason = trim((string) $request->input('reason'));
        $subjectEmail = (string) $subject->email;
        $operatorLabel = trim($operator->name . ' <' . $operator->email . '>');

        // The ledger row and the clearing are ONE transaction, in that order:
        // if the record cannot be written the factor is not cleared. An
        // untraceable clear is the thing this whole design exists to prevent,
        // so it must not be the thing that survives a half-failure.
        DB::transaction(function () use ($subject, $operator, $operatorLabel, $reason, $subjectEmail, $request) {
            TwoFactorResetEvent::create([
                'user_id' => $subject->getKey(),
                'user_email' => $subjectEmail,
                'performed_by_user_id' => $operator->getKey(),
                'performed_by_label' => $operatorLabel,
                'channel' => TwoFactorResetEvent::CHANNEL_DASHBOARD,
                'reason' => $reason,
                'ip_address' => $request->ip(),
            ]);

            $this->twoFactor->forget($subject);
        });

        // Told, always — see TwoFactorResetMail. A mail outage must not roll the
        // reset back (the stranded admin is waiting on it and the ledger already
        // has the row), but it must not be swallowed either: the operator is the
        // only one who can pick up the phone instead, so the answer says so.
        $notified = true;

        try {
            if (! empty($subjectEmail)) {
                Mail::to($subjectEmail)->send(new TwoFactorResetMail(
                    $subject,
                    $operatorLabel,
                    $reason,
                    now()->toDayDateTimeString(),
                ));
            } else {
                $notified = false;
            }
        } catch (\Throwable $e) {
            $notified = false;

            // error, not info: LOG_LEVEL is `warning` on production, and a
            // notice about somebody's second factor going missing is not
            // allowed to fall below the floor.
            Log::error('Two-factor reset notice could not be delivered', [
                'user_id' => $subject->getKey(),
                'performed_by_user_id' => $operator->getKey(),
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'status' => 'success',
            'message' => $notified
                ? 'Two-step sign-in has been cleared for ' . $subjectEmail . '. They have been emailed '
                    . 'about it and can sign in with their password, then enrol a new device.'
                : 'Two-step sign-in has been cleared for ' . $subjectEmail . ', but the email telling them '
                    . 'so could not be sent. Contact them directly — the reset is recorded either way.',
            'data' => [
                'user_id' => $subject->getKey(),
                'notified' => $notified,
            ],
        ], Response::HTTP_OK);
    }

    /**
     * The one refusal every bad code gets, whatever was wrong with it.
     *
     * Wrong code, replayed code, unknown recovery code — one message. Telling a
     * caller that the code was right but stale tells them the code space is
     * smaller than they thought.
     */
    private function invalidCode()
    {
        return response()->json([
            'status' => 'failed',
            'message' => 'The two-factor authentication code is invalid.',
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /** A 429 while the account's second factor is locked, or null to continue. */
    private function refuseWhileLockedOut(User $user)
    {
        if (! $this->twoFactor->isLockedOut($user)) {
            return null;
        }

        return response()->json([
            'status' => 'failed',
            'message' => 'Too many incorrect codes. Try again in '
                . $this->twoFactor->lockedForMinutes($user) . ' minute(s).',
        ], Response::HTTP_TOO_MANY_REQUESTS);
    }
}
