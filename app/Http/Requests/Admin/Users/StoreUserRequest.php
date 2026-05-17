<?php

namespace App\Http\Requests\Admin\Users;

use App\Http\Requests\BaseFormRequest;
use App\Rules\UserTypeRule;

class StoreUserRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required|string',
            'email' => 'required|email|unique:users,email',
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
