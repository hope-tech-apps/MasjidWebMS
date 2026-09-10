<?php

namespace App\Http\Requests\Admin\Team;

use App\Http\Controllers\AdminDashboard\TeamController;
use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * Add a person to an organisation's team (POST .../team).
 *
 * `access` picks one of the two levels this endpoint can create — it is never a
 * users.type, and `type` itself is not accepted at all (see TeamController).
 * Email uniqueness matches the administrator and lunch-staff doors: one login
 * per address across the whole platform.
 */
class StoreTeamMemberRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:190'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'max:40'],
            'access' => ['required', Rule::in(TeamController::CREATABLE_ACCESS)],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'That email already has a login. Use a different address, or ask Manara to move the existing login.',
            'access.in' => 'Choose Administrator or Friday lunch only.',
        ];
    }
}
