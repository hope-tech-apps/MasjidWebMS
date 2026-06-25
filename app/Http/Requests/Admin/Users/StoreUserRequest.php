<?php

namespace App\Http\Requests\Admin\Users;

use App\Http\Requests\BaseFormRequest;
use App\Rules\UserTypeRule;
use Illuminate\Validation\Rule;

class StoreUserRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required|string',
            // Ignore soft-deleted (archived) users so an archived user's email can be reused.
            // The controller restores the archived account when the email matches one.
            'email' => ['required', 'email', Rule::unique('users', 'email')->whereNull('deleted_at')],
            'phone' => 'required|string|regex:/^\+?[0-9 ]+$/',
            'type' => ['required', new UserTypeRule()],
            'avatar' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:25600',
            'password' => [
                'required',
                'string',
                'min:8',
                'max:20',
                'regex:/[A-Z]/',
                'regex:/[a-z]/',
                'regex:/[0-9]/',
                'regex:/[@$!%*?&#]/',
                'confirmed',
            ],
        ];
    }
}
