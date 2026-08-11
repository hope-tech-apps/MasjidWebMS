<?php

namespace App\Http\Requests\Admin\Registrations;

use App\Http\Requests\BaseFormRequest;
use App\Models\RegistrationAdjustment;
use Illuminate\Validation\Rule;

/**
 * Grant one auditable reduction (aid / discount / code) against a registration
 * (T-006d).
 *
 * `amount_minor` is an UNSIGNED REDUCTION MAGNITUDE, integer minor units — the
 * table cannot express a surcharge and never will
 * (.claude/rules/registration-billing-data.md), so a negative amount is a 422
 * here and RegistrationService refuses one again behind this boundary.
 *
 * `granted_by_user_id` is NOT accepted from the body: the audit trail's "who"
 * is the authenticated admin, taken from the request, never from client input.
 * Whether the grant is allowed AT ALL (strictly pre-checkout) is the service's
 * decision, not this request's.
 */
class GrantAdjustmentRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(RegistrationAdjustment::KINDS)],
            'amount_minor' => ['required', 'integer', 'min:0'],
            'reason' => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'amount_minor.min' => 'An adjustment is an unsigned reduction — it can never be negative or a surcharge.',
        ];
    }
}
