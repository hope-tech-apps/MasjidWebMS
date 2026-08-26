<?php

namespace App\Http\Requests\Admin\Users;

use App\Http\Requests\BaseFormRequest;
use App\Rules\UserTypeRule;
use Illuminate\Validation\Rule;

/**
 * Create a staff account WITHOUT choosing its password.
 *
 * StoreUserRequest requires both a password and an avatar, which is right for
 * the SuperAdmin creating a colleague by hand and wrong for handing an
 * organisation its own portal: it forces somebody at Manara to invent, know and
 * transmit another organisation's credential. Here the account is created with
 * an unusable random secret and the person sets their own from an emailed link.
 */
class InviteUserRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // Archived users are ignored so a retired address can be reused,
            // matching StoreUserRequest.
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->whereNull('deleted_at')],
            'phone' => ['required', 'string', 'regex:/^\+?[0-9 ]+$/'],
            'type' => ['required', new UserTypeRule()],
            // Optional: make this person the owning admin of one organisation in
            // the same step. `masjids.active_owner_user_id` is uniquely indexed,
            // so this can only ever be a brand-new user — which an invited
            // account always is.
            'masjid_id' => ['nullable', 'integer', 'exists:masjids,id'],
        ];
    }
}
