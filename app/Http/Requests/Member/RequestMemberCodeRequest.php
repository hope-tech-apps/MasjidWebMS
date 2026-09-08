<?php

namespace App\Http\Requests\Member;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation only. It says nothing about whether the address is known here —
 * that is the whole point of the endpoint's fixed 202.
 */
class RequestMemberCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
        ];
    }
}
