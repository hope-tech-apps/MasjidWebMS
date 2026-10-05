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
            // Lunch staff and teachers keep their type (UsersController::update);
            // their access is changed on the Team screen, not here.
            'type' => $this->targetIsScopedLogin() ? ['nullable'] : ['required', new UserTypeRule()],
            // The name is pinned as well as the bytes, and `bail` stops at the first failure:
            // see StoreUserRequest.
            'avatar' => 'bail|image|mimes:jpeg,png,jpg,gif,webp|extensions:jpeg,jpg,png,gif,webp|max:25600',
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

    public function messages(): array
    {
        return [
            'avatar.extensions' => 'The avatar\'s file name must end in .jpg, .jpeg, .png, .gif or .webp. Rename the file and upload it again.',
        ];
    }

    private function targetIsScopedLogin(): bool
    {
        $type = \App\Models\User::whereKey($this->route('user_id'))->value('type');

        return in_array($type, \App\Support\OrganisationAccess::SCOPED_TYPES, true);
    }
}
