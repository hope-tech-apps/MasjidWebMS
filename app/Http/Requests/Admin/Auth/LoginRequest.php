<?php

namespace App\Http\Requests\Admin\Auth;

use App\Http\Requests\BaseFormRequest;

/**
 * Staff sign-in.
 *
 * The two two-factor fields are OPTIONAL and are read only for an account that
 * has CONFIRMED enrollment — see AuthController::login(). An admin who never
 * enrolled posts email + password exactly as they always have and never sees
 * either of these mentioned; that is the guarantee
 * TwoFactorTest::login_without_2fa_is_unchanged exists to pin.
 *
 * They are declared here rather than read straight off the request because a
 * field with no rule is a field a typo can silently rename: `two_factor_cde`
 * would arrive as "no code supplied", which is not a refusal but a challenge, so
 * the user would loop on the code screen forever with no error to explain it.
 *
 * `email`'s `exists:users,email` rule is deliberately left exactly as it is. It
 * is a known existence oracle that the family realm pointedly does not copy, and
 * removing it is its own task with its own test — not a side effect of a 2FA
 * change.
 */
class LoginRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'email' => 'required|email|exists:users,email',
            'password' => 'required|string',
            // 6-digit TOTP code from the authenticator app.
            'two_factor_code' => ['nullable', 'string', 'max:32'],
            // One of the printed single-use codes, for somebody whose
            // authenticator is gone. Bounded like the field above so neither is
            // an unbounded string handed to a comparison loop.
            'two_factor_recovery_code' => ['nullable', 'string', 'max:64'],
        ];
    }
}
