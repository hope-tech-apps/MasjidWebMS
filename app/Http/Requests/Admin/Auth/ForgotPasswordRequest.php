<?php

namespace App\Http\Requests\Admin\Auth;

use App\Http\Requests\BaseFormRequest;

class ForgotPasswordRequest extends BaseFormRequest
{
    public function rules(): array
    {
        // Deliberately only `email` shape, never `exists`. An `exists` rule here
        // would turn a validation error into an account-enumeration oracle on an
        // unauthenticated endpoint.
        return [
            'email' => ['required', 'email', 'max:255'],
        ];
    }
}
