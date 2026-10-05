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
            // `extensions` pins the file's NAME to the kinds `mimes` holds its BYTES to: the
            // media library keeps the client's file name on the public disk, where `x.html`
            // would be served as a page (Concerns\ValidatesVideoSection::sectionUploadRules).
            // `bail` stops at the first failure, so a file that is not an image is not also
            // told to rename it.
            'avatar' => 'bail|required|image|mimes:jpeg,png,jpg,gif,webp|extensions:jpeg,jpg,png,gif,webp|max:25600',
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

    public function messages(): array
    {
        return [
            'avatar.extensions' => 'The avatar\'s file name must end in .jpg, .jpeg, .png, .gif or .webp. Rename the file and upload it again.',
        ];
    }
}
