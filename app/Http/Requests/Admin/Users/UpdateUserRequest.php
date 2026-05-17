<?php

namespace App\Http\Requests\Admin\Users;

use App\Http\Requests\BaseFormRequest;
use App\Rules\MatchOldUserPasswordRule;
use App\Rules\UserTypeRule;

class UpdateUserRequest extends BaseFormRequest
{
    public function rules(): array
    {
        $userId = $this->route('user_id');

        return [
            'name' => 'required|string',
            'email' => 'required|email',
            'phone' => 'required|string|regex:/^\+?[0-9 ]+$/',
            'type' => ['required', new UserTypeRule()],
            'avatar' => 'image|mimes:jpeg,png,jpg,gif,webp|max:25600',
            'old_password' => ['nullable', 'required_with:password', new MatchOldUserPasswordRule($userId)],
            'password' => [
                'nullable',
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
