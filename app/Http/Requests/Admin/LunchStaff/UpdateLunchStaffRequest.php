<?php

namespace App\Http\Requests\Admin\LunchStaff;

use App\Http\Requests\BaseFormRequest;

/**
 * Editing a Jummah-lunch staff login.
 *
 * NAME AND PHONE ONLY. The email is the identity the invite and the sign-in are
 * keyed to, and changing it in place would silently move somebody's account to
 * an address they never confirmed — remove the access and issue it again instead.
 *
 * Deliberately NOT StoreLunchStaffRequest with a modified rule: that class
 * carries `unique:users,email`, and a FormRequest validates BEFORE the
 * controller resolves the target — so pointing the endpoint at an admin's id
 * answered 422 ("email taken") rather than 404, quietly confirming that the id
 * existed and that the address belonged to somebody.
 */
class UpdateLunchStaffRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:120',
            'phone' => 'nullable|string|max:32',
        ];
    }
}
