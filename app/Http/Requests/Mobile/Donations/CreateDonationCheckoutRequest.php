<?php

namespace App\Http\Requests\Mobile\Donations;

use App\Http\Requests\BaseFormRequest;

/**
 * Public (unauthenticated) request to open a donation Checkout Session.
 *
 * `amount` is the intended gift in integer MINOR UNITS (cents) — never a float.
 * Minimum 100 (= $1.00) keeps us above Stripe's minimum charge. The fund is
 * validated against the masjid in the controller (the route is unbound, so we
 * can't lean on the tenant scope here).
 */
class CreateDonationCheckoutRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'fund_id' => 'required|integer',
            'amount' => 'required|integer|min:100|max:99999999',
            'donor_covers_fees' => 'sometimes|boolean',
            'success_url' => 'sometimes|url',
            'cancel_url' => 'sometimes|url',
        ];
    }
}
