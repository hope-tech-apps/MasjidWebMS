<?php

namespace App\Http\Requests\Admin\Funds;

use App\Http\Requests\BaseFormRequest;
use App\Models\Fund;
use Illuminate\Validation\Rule;

/**
 * Validates a fund update. Same shape as StoreFundRequest — name is required,
 * type must be one of Fund::TYPES, and the two flags are optional booleans. All
 * validation flows through BaseFormRequest so a failure renders as a 422 in the
 * legacy { status, data } envelope (never a raw 500). masjid_id is deliberately
 * NOT accepted: the tenant guardrail owns it.
 */
class UpdateFundRequest extends BaseFormRequest
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
