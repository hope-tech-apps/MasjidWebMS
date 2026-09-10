<?php

namespace App\Http\Requests\Admin\LunchStaff;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * Creating a Jummah-lunch staff login.
 *
 * NOTE WHAT IS ABSENT: `type`. The login is always created as
 * User::TYPE_LUNCH_STAFF by the controller, so no request body can ask for a
 * MasjidAdmin — which is what makes it safe for a MasjidAdmin to hold this
 * endpoint at all.
 */
class StoreLunchStaffRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:120',
            // Unique across the whole users table, not per masjid: one address
            // is one login, and a duplicate would make the invite ambiguous.
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')],
            'phone' => 'nullable|string|max:32',
        ];
    }
}
