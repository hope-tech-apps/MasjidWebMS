<?php

namespace App\Http\Requests\Admin\ContactRequests;

use App\Http\Requests\BaseFormRequest;

class ReplyContactRequestRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'reply' => 'required|string|min:1|max:5000',
        ];
    }
}
