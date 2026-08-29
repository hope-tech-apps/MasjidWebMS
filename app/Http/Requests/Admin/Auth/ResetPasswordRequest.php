<?php

namespace App\Http\Requests\Admin\Auth;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rules\Password;

class ResetPasswordRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'email' => ['required', 'email', 'max:255'],
            // `confirmed` needs password_confirmation. The strength floor is
            // Laravel's own default plus a length the school's staff will
            // actually be able to use — this is the credential that opens
            // minors' records, so it is not the place for a 6-character minimum.
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()],
        ];
    }
}
