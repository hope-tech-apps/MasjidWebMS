<?php

namespace App\Http\Requests\Admin\Zakat;

use App\Http\Requests\BaseFormRequest;
use App\Support\ZakatCalculator;
use Illuminate\Validation\Rule;

/**
 * The write boundary for an organization's nisab price (T-043c).
 *
 * Three rules here are not ordinary field validation — they are the honesty
 * contract of the calculator, enforced at the only place a price can enter.
 *
 * ## 1. A price may not be saved undated — and it is ITS OWN date
 *
 * Each metal's `<metal>_price_quoted_on` is `required_with` that metal's price.
 * An undated metal price is the exact failure .claude/rules/zakat.md was written
 * against: nobody, later, can tell a quote taken this morning from one taken
 * last Ramadan, and a threshold built on it will still be presented with quiet
 * confidence. Making the date optional would put the whole staleness mechanism
 * behind a checkbox the busiest office skips.
 *
 * The pairing is PER METAL and that is the load-bearing part. A single
 * `price_quoted_on` for the row validated identically — a date was present, it
 * was not in the future — while describing only whichever price had been edited
 * last. An office re-quoting gold in September would have stamped September onto
 * a silver price read in June, and ZakatCalculator, which resolves the price per
 * metal, would then have called that silver figure current, handed a donor a
 * hard `meets_nisab` verdict off it, and printed a date it was never read on.
 * Every one of those steps passed validation. So the pairing is not a rule the
 * write path remembers; it is the shape of the fields it is given.
 *
 * ## 2. A date may not be in the future
 *
 * A forward-dated quote would read as permanently current and never go stale, so
 * `before_or_equal:today` closes the one way round the review window. A quote is
 * something a person read off a market; you cannot have read tomorrow's.
 *
 * ## 3. Money is an INTEGER in MINOR UNITS
 *
 * Cents per gram, `min:1`, never a decimal. The figure is multiplied by a gram
 * weight and then divided by 40 downstream, so a float entering here would put a
 * rounding error inside a religious obligation — the same reason the public
 * calculator refuses decimals (.claude/rules/stripe-payments.md). `min:1` rather
 * than `min:0`: a metal price of zero is not a cheap market, it is a mistake,
 * and it would produce a threshold of $0 that every payer "meets".
 *
 * That last rule only ever fires if the client actually SENDS the zero. A screen
 * that maps a typed 0 to null before posting turns a mistake into a silent
 * deletion of the organization's published price, and this request never sees
 * it — which is why ZakatCalculatorView posts what was typed and lets the 422
 * come back.
 *
 * `nisab_basis` is validated against the PHP constants on ZakatCalculator, never
 * a DB enum. Clearing a field back to null is a legitimate edit — an office
 * that stops publishing the gold threshold must be able to remove the figure
 * rather than leave a stale one standing — so every field is `nullable` and
 * `present` is not required. Clearing the PRICE also clears the date and
 * citation that described it; that is done in the controller, because it is a
 * statement about what gets stored, not about what may be sent.
 */
class SaveZakatSettingRequest extends BaseFormRequest
{
    public function rules(): array
    {
        // ~$10,000,000 per gram in cents. A sanity bound, not a market opinion:
        // high enough that no real quote approaches it, low enough that
        // multiplying by 612.36 grams cannot approach a 64-bit overflow.
        $ceiling = 1000000000;

        return [
            'nisab_basis' => ['nullable', Rule::in(ZakatCalculator::BASES)],

            'gold_price_per_gram_minor' => ['nullable', 'integer', 'min:1', 'max:' . $ceiling],
            'gold_price_quoted_on' => [
                'nullable',
                'date',
                'before_or_equal:today',
                'required_with:gold_price_per_gram_minor',
            ],
            'gold_price_quoted_from' => ['nullable', 'string', 'max:255'],

            'silver_price_per_gram_minor' => ['nullable', 'integer', 'min:1', 'max:' . $ceiling],
            'silver_price_quoted_on' => [
                'nullable',
                'date',
                'before_or_equal:today',
                'required_with:silver_price_per_gram_minor',
            ],
            'silver_price_quoted_from' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        $undated = 'Enter the date you read this price. A price with no date cannot be checked for '
            . 'staleness, and an out-of-date threshold can tell someone they owe no zakat when they do.';

        return [
            'gold_price_quoted_on.required_with' => $undated,
            'silver_price_quoted_on.required_with' => $undated,
            'gold_price_quoted_on.before_or_equal' => 'The date a price was quoted cannot be in the future.',
            'silver_price_quoted_on.before_or_equal' => 'The date a price was quoted cannot be in the future.',
            'integer' => 'Prices must be whole numbers of cents per gram, not decimals.',
        ];
    }
}
