<?php

namespace App\Http\Requests\Api\V1\Zakat;

use App\Http\Requests\BaseFormRequest;
use App\Support\ZakatCalculator;
use Illuminate\Validation\Rule;

/**
 * The request boundary for the PUBLIC zakat calculator (T-031).
 *
 * Extends BaseFormRequest so a rejection leaves as the legacy {status:'failed'}
 * 422 field bag, the same contract every other /api/v1 endpoint returns.
 *
 * ## Integers, not decimals
 *
 * Every money field is an INTEGER in MINOR UNITS (cents) — never a float and
 * never a decimal string. This is the same rule the donation path follows
 * (.claude/rules/stripe-payments.md), and it matters more here, not less: the
 * inputs are summed and then divided by 40, so a float entering at the boundary
 * would put a rounding error inside a religious obligation.
 *
 * `min:0` on every bucket: a negative asset or a negative liability is not a
 * meaningful figure, and allowing one would let a caller manufacture any answer
 * they liked out of the other fields. The ceiling is a sanity bound, not a
 * judgment about anyone's wealth.
 *
 * ## Privacy
 *
 * These fields are a person's complete net worth. Nothing here or downstream may
 * hand them to Log::*, and nothing persists them — which is also why the
 * endpoint is a POST: a GET would put a donor's assets in a query string, and
 * from there into access logs and browser history.
 */
class CalculateZakatRequest extends BaseFormRequest
{
    /**
     * Deliberately unauthenticated — this is a public donor-facing tool. Which
     * organization it is shown under comes from the `masjid-id` header in the
     * controller; there is no user here to authorize.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // ~$10 billion in cents. High enough that no honest payer meets it, low
        // enough that summing every bucket cannot approach a 64-bit overflow.
        $ceiling = 1000000000000;

        $rules = [
            // The payer's own fiqh position on the threshold. Absent = the
            // deployment's configured default, which the response names either
            // way so the choice is never invisible.
            'basis' => ['sometimes', Rule::in(ZakatCalculator::BASES)],
            // Price of ONE GRAM of the basis metal, integer minor units. Absent
            // falls back to config; absent from both leaves the threshold
            // unknown, which the response reports rather than papers over.
            'nisab_price_per_gram' => ['sometimes', 'integer', 'min:1', 'max:' . $ceiling],
        ];

        foreach ([...ZakatCalculator::ASSET_KEYS, ...ZakatCalculator::LIABILITY_KEYS] as $key) {
            $rules[$key] = ['sometimes', 'integer', 'min:0', 'max:' . $ceiling];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'integer' => 'Amounts must be whole numbers in minor units (cents), not decimals.',
        ];
    }
}
