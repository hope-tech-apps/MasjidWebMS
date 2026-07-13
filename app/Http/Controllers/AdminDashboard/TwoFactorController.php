<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Auth\ConfirmTwoFactorRequest;
use App\Http\Requests\Admin\Auth\DisableTwoFactorRequest;
use App\Services\TwoFactorService;
use App\Support\Errors;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin self-service TOTP two-factor authentication (enroll / confirm / disable).
 *
 * All three endpoints sit behind the existing `auth:sanctum` + `admin` group, so
 * the acting admin manages 2FA for their OWN account. Nothing here is gated by a
 * Spatie permission — enrollment is available to any admin.
 *
 * Enrollment is a two-step handshake so a mistyped secret never locks anyone out:
 *   1. enroll  — generate + persist a secret, return the otpauth URI + QR. NOT
 *                yet active (two_factor_confirmed_at stays null).
 *   2. confirm — verify a live code, then set two_factor_confirmed_at, which is
 *                the flag the login flow checks. Only now is 2FA active.
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
     * Re-enrolling before confirming simply rotates the pending secret.
     */
    public function enroll()
    {
        try {
            $user = Auth::user();

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
     */
    public function confirm(ConfirmTwoFactorRequest $request)
    {
        $user = Auth::user();

        // No pending secret -> the client skipped enroll(). Treat as a
        // validation failure rather than a 500.
        if (empty($user->two_factor_secret)) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Two-factor authentication has not been set up. Start enrollment first.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (! $this->twoFactor->verify($user->two_factor_secret, $request->input('code'))) {
            return response()->json([
                'status' => 'failed',
                'message' => 'The two-factor authentication code is invalid.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user->two_factor_confirmed_at = now();
        $user->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Two-factor authentication is now enabled.',
        ], Response::HTTP_OK);
    }

    /**
     * Disable 2FA. Requires a valid current code so a hijacked session can't
     * silently strip a victim's second factor.
     */
    public function disable(DisableTwoFactorRequest $request)
    {
        $user = Auth::user();

        if (empty($user->two_factor_secret)
            || ! $this->twoFactor->verify($user->two_factor_secret, $request->input('code'))) {
            return response()->json([
                'status' => 'failed',
                'message' => 'The two-factor authentication code is invalid.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user->two_factor_secret = null;
        $user->two_factor_confirmed_at = null;
        $user->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Two-factor authentication has been disabled.',
        ], Response::HTTP_OK);
    }
}
