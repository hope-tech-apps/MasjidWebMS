<?php

namespace App\Http\Requests\Admin\Team;

use App\Http\Controllers\AdminDashboard\TeamController;
use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/** Change what one person can do (PATCH .../team/{user_id}). */
class UpdateTeamMemberRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'access' => ['required', Rule::in(TeamController::CREATABLE_ACCESS)],
        ];
    }

    public function messages(): array
    {
        return ['access.in' => 'Choose Administrator or Friday lunch only.'];
    }
}
