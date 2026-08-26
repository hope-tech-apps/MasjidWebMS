<?php

namespace App\Http\Requests\Family;

use App\Http\Requests\BaseFormRequest;

/**
 * A parent's reply. Body only — a message carries no media, no subject and no
 * recipients, because the thread already decided all three.
 *
 * A FormRequest rather than an inline `$request->validate()`: BaseFormRequest
 * throws HttpResponseException, which the app-wide JSON renderer returns
 * verbatim, whereas a bare ValidationException is not one of the shapes that
 * renderer knows and reaches the client as a 500.
 */
class StoreFamilyMessageRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            // TrimStrings runs first, so a body of spaces arrives empty and is
            // refused here rather than being stored as a blank message.
            'body' => ['required', 'string', 'max:5000'],
        ];
    }
}
