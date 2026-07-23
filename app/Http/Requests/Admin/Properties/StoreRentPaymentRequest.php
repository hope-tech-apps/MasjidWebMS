<?php

namespace App\Http\Requests\Admin\Properties;

use App\Http\Requests\BaseFormRequest;

/**
 * Record a rent payment against a property. `amount` is DOLLARS (signed — a
 * negative value records a vacancy/adjustment, matching the ledger). Converted to
 * integer cents in the controller.
 */
class StoreRentPaymentRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'paid_on' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:-1000000', 'max:1000000'],
            'payment_method' => ['nullable', 'string', 'max:30'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
