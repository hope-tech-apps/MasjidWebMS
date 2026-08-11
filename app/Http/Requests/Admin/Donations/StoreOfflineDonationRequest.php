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
            'check_number' => ['nullable', 'string', 'max:50'],
            'donated_at' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:1000'],
            // The zakat designation the giver stated when they handed the cash
            // over. ABSENT means it was not recorded, and the fund's type then
            // supplies the default; a present `false` is a real "not zakat".
            // Cash zakat is the common case for a masjid, so an admin has to be
            // able to say so without inventing a second fund.
            'zakat' => ['sometimes', 'boolean'],
        ];
    }
}
