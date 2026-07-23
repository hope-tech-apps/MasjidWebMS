<?php

namespace App\Http\Requests\Admin\Donations;

use App\Http\Requests\BaseFormRequest;

/**
 * Record a manual/offline donation (cash, check, Zelle, Venmo, PayPal, Square).
 * `amount` is DOLLARS from the form; converted to integer cents in the controller.
 * A contact is optional (a general/anonymous gift books with no donor).
 */
class StoreOfflineDonationRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'fund_id' => ['required', 'integer'],
            'contact_id' => ['nullable', 'integer'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:1000000'],
            'payment_method' => ['required', 'in:cash,check,zelle,venmo,paypal,square,credit,giftcard,other'],
            'donated_at' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
