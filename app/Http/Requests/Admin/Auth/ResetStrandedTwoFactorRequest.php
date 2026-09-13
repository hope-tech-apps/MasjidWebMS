<?php

namespace App\Http\Requests\Admin\Auth;

use App\Http\Requests\BaseFormRequest;

/**
 * A platform operator clearing the second factor on somebody ELSE'S account.
 *
 * Three fields, and each one is a lock on the door rather than a form nicety.
 *
 * `code` — the ACTING operator's own live TOTP code. This is what stops the
 * door being the weakest link: a SuperAdmin can already set any staff
 * password (UsersController::update), so their session is a skeleton key for
 * the first factor, and if this endpoint took only that session then a phished
 * SuperAdmin password would strip the second factor off every administrator on
 * the platform. Requiring a live code means opening the door needs POSSESSION
 * of the operator's authenticator at that moment, not just their credentials.
 * A recovery code is deliberately NOT accepted (contrast disable(), where it is)
 * — a printed line lifted off somebody's desk should sign that person in, not
 * let the finder disarm a third party.
 *
 * `subject_email` — typed, and matched against the target row by the
 * controller. The id comes from a list; the email comes from the operator's
 * fingers. Clearing the second factor of the account NEXT TO the one you meant
 * is not a mistake anybody notices until that admin cannot sign in, so the act
 * asks to be spelled out the way a repository deletion does.
 *
 * `reason` — at least ten characters, kept forever on `two_factor_reset_events`.
 * The point of the ledger is that a reviewer months later can tell a support
 * case from a quiet takeover, and a blank field cannot be told from either.
 *
 * There is no `notify` flag. The affected admin is always emailed; a door that
 * can be opened silently is a different door.
 */
class ResetStrandedTwoFactorRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            // The operator's own 6-digit code from their authenticator.
            'code' => ['required', 'string', 'max:32'],

            // Whose factor is being cleared, in the operator's own typing.
            'subject_email' => ['required', 'string', 'email', 'max:255'],

            // Why. Stored verbatim, never shown to the subject's colleagues,
            // always available to whoever audits this later.
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.min' => 'Say what happened in a sentence — this is kept on the account record.',
            'subject_email.required' => 'Type the email address of the account you are clearing.',
        ];
    }
}
