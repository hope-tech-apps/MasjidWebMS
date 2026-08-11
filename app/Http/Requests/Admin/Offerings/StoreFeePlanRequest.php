<?php

namespace App\Http\Requests\Admin\Offerings;

use App\Http\Requests\BaseFormRequest;
use App\Models\FeePlan;
use Illuminate\Validation\Rule;

/**
 * Create a fee plan for one offering (T-006d).
 *
 * There is no update sibling on purpose: a plan is IMMUTABLE once created —
 * deactivate-and-replace. See FeePlansController::update, which refuses edits
 * explicitly.
 *
 * Neither `masjid_id` nor `offering_id` is accepted from the body. The tenant
 * is stamped by the BelongsToMasjid creating hook and the offering comes from
 * the route's already-scoped lookup, so a plan can never be planted under
 * another organization's offering.
 *
 * MONEY KINDS NEVER DEGRADE (.claude/rules/registration-billing-data.md): the
 * shape each kind requires is enforced here, loudly. A free plan may not carry
 * an amount, a one-time plan may not carry an interval, an installment plan
 * must say how many charges it is, and an unrecognized kind is a 422 — never a
 * row that later has to be guessed at when money moves.
 */
class StoreFeePlanRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->filled('currency')) {
            // Lowercase ISO, the donations convention the column stores.
            $this->merge(['currency' => strtolower((string) $this->input('currency'))]);
        }
    }

    public function rules(): array
    {
        $kind = $this->input('kind');
        $isFree = $kind === FeePlan::KIND_FREE;
        $needsInterval = in_array($kind, [FeePlan::KIND_INSTALLMENT, FeePlan::KIND_RECURRING], true);
        $needsCount = $kind === FeePlan::KIND_INSTALLMENT;

        return [
            'kind' => ['required', Rule::in(FeePlan::KINDS)],
            // Integer MINOR UNITS, never a float. A free plan is exactly 0; a
            // paid plan of 0 would mint a $0 Stripe session, which the design
            // forbids outright.
            'amount_minor' => $isFree
                ? ['required', 'integer', 'min:0', 'max:0']
                : ['required', 'integer', 'min:1'],
            'currency' => 'sometimes|string|size:3',
            'billing_interval' => $needsInterval
                ? ['required', Rule::in(FeePlan::BILLING_INTERVALS)]
                : ['prohibited'],
            // A one-iteration "installment plan" is a one-time payment wearing
            // the wrong hat; the schedule needs at least two.
            'installment_count' => $needsCount
                ? ['required', 'integer', 'min:2']
                : ['prohibited'],
            // NOT NULL: the plan's admin/public-facing name.
            'label' => 'required|string|max:255',
            'is_active' => 'sometimes|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'amount_minor.max' => 'A free plan has no amount — use a one-time plan to charge something.',
            'amount_minor.min' => 'A paid plan must charge more than zero; a $0 charge is the free plan instead.',
            'billing_interval.prohibited' => 'Only installment and recurring plans have a billing interval.',
            'billing_interval.required' => 'An installment or recurring plan must say how often it charges.',
            'installment_count.prohibited' => 'Only an installment plan has a number of payments.',
            'installment_count.min' => 'An installment plan needs at least two payments.',
        ];
    }
}
