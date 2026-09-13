<?php

namespace App\Http\Requests\Mobile\Member;

use App\Http\Requests\BaseFormRequest;
use App\Services\Stripe\DonationService;

/**
 * A signed-in donor changing what their own standing monthly/yearly gift
 * charges.
 *
 * `amount` is the donor's INTENDED gift in integer MINOR UNITS (cents), the
 * same unit and the same meaning as `amount` on the public checkout request:
 * what the organisation should receive, before any donor-covers-fees gross-up.
 * The gross-up is re-applied server-side (DonationService::changeSubscriptionAmount)
 * — a client that sent an already-grossed figure would compound the fee every
 * time the donor edited the amount.
 *
 * `integer` and not `numeric`: a float here is how a donation becomes $49.99999
 * or, worse, how "50.00" becomes 50 cents. .claude/rules/stripe-payments.md —
 * all money is integer minor units, never floats.
 *
 * The bounds are DonationService's constants rather than two literals repeated
 * here, so this refusal and the service's own guard cannot drift apart. Both
 * exist on purpose: this one gives the donor a 422 they can read, and the
 * service's protects every other caller (a staff mirror, an artisan command)
 * that does not come through this FormRequest.
 *
 * Deliberately NOT accepted here, however a client asks:
 *   - `fund_id`. Re-pointing an in-flight commitment silently moves restricted
 *     money — zakat into a building fund — with no audit trail and no new
 *     consent. That is a cancel and a new commitment.
 *   - `zakat` / `zakat_source`. .claude/rules/zakat.md: a recurring commitment
 *     is designated ONCE, at checkout. An amount change must not re-open it.
 *   - `interval`. Monthly to yearly changes the size of the next charge as well
 *     as its date; it is a different commitment, not an edit to this one.
 * Adding any of them is a product decision plus a fresh consent, not a rule
 * line.
 */
class UpdateRecurringGiftRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'amount' => [
                'required',
                'integer',
                'min:' . DonationService::MIN_RECURRING_AMOUNT,
                'max:' . DonationService::MAX_RECURRING_AMOUNT,
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.min' => 'The smallest monthly gift is $1.00.',
            'amount.max' => 'That amount is too large to set up here. Please contact the office.',
        ];
    }

    /** The requested gift in integer minor units. */
    public function intendedAmount(): int
    {
        return (int) $this->validated('amount');
    }
}
