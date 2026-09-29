<?php

namespace App\Http\Requests\Member;

use App\Http\Requests\Concerns\NormalisesSubmittedAddress;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation only. It says nothing about whether the address is known here —
 * that is the whole point of the endpoint's fixed 202.
 */
class RequestMemberCodeRequest extends FormRequest
{
    use NormalisesSubmittedAddress;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normaliseSubmittedAddress();
    }

    public function rules(): array
    {
        return [
            'email' => ['bail', 'required', 'string', 'email:rfc', 'ascii', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['email.ascii' => self::asciiAddressMessage()];
    }
}
