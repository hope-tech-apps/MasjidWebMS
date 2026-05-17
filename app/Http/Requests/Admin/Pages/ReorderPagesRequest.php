<?php

namespace App\Http\Requests\Admin\Pages;

use App\Http\Requests\BaseFormRequest;

class ReorderPagesRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'pages' => 'required|array',
            'pages.*.id' => 'required|integer|exists:pages,id',
            'pages.*.order' => 'required|integer|min:1',
        ];
    }
}
