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
    /**
     * Absolute ceiling on one adjustment, in minor units — 99,999,999, i.e.
     * $999,999.99 in a two-decimal currency, which is also Stripe's documented
     * per-charge maximum.
     *
     * The CHARGE was always safe: RegistrationService floors the adjusted total
     * at zero, so an absurd grant simply makes the registration free, which is
     * the documented free-path carve-out. What was not safe is the LEDGER. With
     * no upper bound, `{"kind":"aid","amount_minor":99999999999999999}` was
     * accepted with a 201 and became the permanent audit trail for that waiver —
     * a row reading "aid: $999,999,999,999,999.99" against a $100 program. The
     * adjustments table IS the record of who waived what and why, so a value it
     * cannot mean is worse there than anywhere else.
     *
     * A tighter bound — the registration's own `list_total_minor`, since aid
     * beyond the price is meaningless — belongs in RegistrationService, which
     * already holds the registration and the floor logic. This is the outer
     * guard that stops nonsense reaching it.
     */
    private const MAX_ADJUSTMENT_MINOR = 99999999;

    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(RegistrationAdjustment::KINDS)],
            'amount_minor' => ['required', 'integer', 'min:0', 'max:' . self::MAX_ADJUSTMENT_MINOR],
            'reason' => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'amount_minor.min' => 'An adjustment is an unsigned reduction — it can never be negative or a surcharge.',
            'amount_minor.max' => 'That adjustment is larger than any real registration fee. Enter the amount being waived, in minor units.',
        ];
    }
}
