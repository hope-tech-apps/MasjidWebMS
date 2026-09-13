<?php

namespace App\Http\Requests\Admin\Auth;

use App\Http\Requests\BaseFormRequest;

/**
 * Re-issuing the recovery codes for the acting admin's own account.
 *
 * A current TOTP code is REQUIRED, exactly as it is for disabling — and for the
 * same reason. Handing back a printable set of strings that each replace the
 * second factor is not a lesser act than switching it off; a hijacked session
 * that could regenerate freely would simply take the new codes and keep them.
 * Proving live possession of the device is what stops a stolen session from
 * minting itself a permanent way in.
 *
 * There is no `count` field. Eight is the number, decided by
 * TwoFactorService::RECOVERY_CODE_COUNT, and a client-chosen count is a client
 * that can ask for one.
 */
class RegenerateRecoveryCodesRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            // 6-digit TOTP code from the authenticator app. A recovery code is
            // deliberately NOT accepted here: somebody holding a printout and
            // nothing else should sign in with it (which spends it) and then
            // re-enroll, not quietly mint themselves eight fresh ones.
            'code' => ['required', 'string', 'max:32'],
        ];
    }
}
