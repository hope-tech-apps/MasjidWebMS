<?php

namespace App\Http\Requests\Admin\Funds;

use App\Http\Requests\BaseFormRequest;
use App\Models\Fund;
use Illuminate\Validation\Rule;

class StoreFundRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'type' => ['required', Rule::in(Fund::TYPES)],
            'receiptable' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
        ];
    }
}
