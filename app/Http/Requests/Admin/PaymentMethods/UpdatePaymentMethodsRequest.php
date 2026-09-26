<?php

namespace App\Http\Requests\Admin\PaymentMethods;

use App\Http\Requests\BaseFormRequest;
use App\Support\PaymentMethods;
use Illuminate\Validation\Rule;

/**
 * The whole set of accepted payment methods, replaced in one save.
 *
 * `methods` must be PRESENT: an empty list is a deliberate "we accept nothing
 * listed here", but an ABSENT key is a client that lost the field, and reading
 * that as "clear everything" would wipe an organisation's instructions on a
 * serialiser bug (.claude/rules/shipping.md, "A form field that is not in the
 * serialiser is silently discarded"). The SPA sends this body as JSON for that
 * reason: form encoding cannot express an empty list at all.
 */
class UpdatePaymentMethodsRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'methods' => 'present|array|max:' . count(PaymentMethods::KEYS),
            'methods.*.method' => ['required', 'string', 'distinct', Rule::in(PaymentMethods::KEYS)],
            // "Other" says nothing to a customer until it is named ("PayPal").
            'methods.*.label' => 'nullable|required_if:methods.*.method,' . PaymentMethods::OTHER . '|string|max:64',
            'methods.*.instructions' => 'nullable|string|max:2000',
        ];
    }
}
