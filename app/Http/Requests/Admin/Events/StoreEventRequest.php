<?php

namespace App\Http\Requests\Admin\Events;

use App\Http\Requests\BaseFormRequest;

class StoreEventRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'required|string',
            'details' => 'required|string',
            'place' => 'required|string',
            'start' => 'required|date_format:Y-m-d H:i',
            'end' => 'required|date_format:Y-m-d H:i|after:start',
            'link' => 'string',
        ];
    }
}
