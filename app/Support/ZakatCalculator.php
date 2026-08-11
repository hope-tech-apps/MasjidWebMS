<?php

namespace App\Support;

/**
 * ZakatCalculator — the arithmetic behind the public zakat calculator (T-031).
 *
 * Plain support class, the DonationMetrics / ImpactMetrics idiom: the controller
 * is a thin wrapper, so the same figures can be produced from a console command
 * or the Assistant without a second implementation drifting away from this one.
 *
 * ## What it computes
 *
 *     net zakatable wealth = declared zakatable assets − declared liabilities
 *     zakat at rate        = net × 1/40   (2.5%)
 *     nisab threshold      = weight of gold/silver × price per gram
 *     zakat due            = zakat at rate, when net ≥ nisab; otherwise 0
 *
 * ## What it deliberately does NOT do — and why that is the design
 *
 * A zakat calculator is not a spreadsheet with a mosque logo on it. Every step
 * above rests on a position some qualified scholar disputes, and a tool that
 * silently picks one and shows a donor a dollar figure has issued a ruling on
 * their behalf while looking like arithmetic. So:
 *
 *   - Every disputable choice is a NAMED ASSUMPTION returned in the payload
 *     (see assumptions()), not only a comment in this file. The donor is shown
 *     what produced their number.
 *   - Where the caller can reasonably decide, they decide: the nisab basis is
 *     per request, and the liabilities deducted are exactly what was entered.
 *   - Where a fact is unknown it is reported as unknown. With no metal price
 *     there is no threshold, so `meets_nisab` and `zakat_due_minor` are NULL and
 *     say so — never a default, an estimate, or a stale figure.
 *   - It values nothing for the caller. Gold holdings are entered as a MONEY
 *     value the payer determined, not as grams this class prices, because
 *     pricing someone's jewelry would embed both a market quote and a fiqh
 *     position (is customary jewelry even zakatable?) inside a number that
 *     looked derived.
 *
 * The result is an aid to arithmetic, and the payload says exactly that. It is
 * not a fatwa and must never be presented as one.
 *
 * ## Money
 *
 * Integer minor units end to end (.claude/rules/stripe-payments.md). The rate is
 * applied as the integer fraction 1/40, never as the float 0.025, and the
 * division ROUNDS UP — see zakatAtRate().
 *
 * ## Privacy
 *
 * The input is a person's complete net worth. NOTHING here is persisted and
 * nothing may be logged: this class holds the numbers for the length of one
 * request and returns them to the person who sent them.
 */
class ZakatCalculator
{
    public const BASIS_GOLD = 'gold';

    public const BASIS_SILVER = 'silver';

    public const BASES = [self::BASIS_GOLD, self::BASIS_SILVER];

    /**
     * The zakatable-asset buckets, in the order they are reported.
     *
     * Named categories rather than one "total assets" box because the breakdown
     * IS the derivation the donor is owed: a bare total gives them nothing to
     * check. Each is a money value in integer minor units that the payer
     * determined — this class values nothing (see the class docblock).
     */
    public const ASSET_KEYS = [
        'cash',
        'bank_balances',
        'gold_value',
        'silver_value',
        'business_inventory',
        'receivables',
        'investments',
        'other_assets',
    ];

    /**
     * The deductible-liability buckets.
     *
     * WHICH liabilities may be deducted is one of the most disputed points in
     * contemporary zakat practice, so this class deducts exactly what it was
     * given and states in the payload that it made no such judgment.
     */
    public const LIABILITY_KEYS = [
        'debts_due',
        'business_payables',
        'other_liabilities',
    ];

    /**
     * Where the metal price used for the threshold came from, so the payload can
     * distinguish a figure the caller supplied from one the deployment
     * configured — and "there wasn't one" from "it was zero".
     */
    public const PRICE_SOURCE_REQUEST = 'request';

    public const PRICE_SOURCE_CONFIG = 'config';

    public function __construct(
        private readonly string $defaultBasis,
        private readonly float $goldGrams,
        private readonly float $silverGrams,
        private readonly ?int $goldPricePerGramMinor,
        private readonly ?int $silverPricePerGramMinor,
        private readonly int $rateNumerator,
        private readonly int $rateDenominator,
        private readonly string $currency,
    ) {
    }

    /** Build from config/zakat.php, falling back to the documented defaults. */
    public static function fromConfig(): self
    {
        $basis = (string) config('zakat.nisab.basis', self::BASIS_SILVER);

        return new self(
            // An unrecognised configured basis falls back to the documented
            // default rather than producing a threshold of nothing at all.
            in_array($basis, self::BASES, true) ? $basis : self::BASIS_SILVER,
            (float) config('zakat.nisab.gold_grams', 87.48),
            (float) config('zakat.nisab.silver_grams', 612.36),
            self::nullableInt(config('zakat.nisab.gold_price_per_gram_minor')),
            self::nullableInt(config('zakat.nisab.silver_price_per_gram_minor')),
            (int) config('zakat.rate.numerator', 1),
            (int) config('zakat.rate.denominator', 40),
            strtoupper((string) config('services.stripe.currency', 'usd')),
        );
    }

    /**
     * Compute one person's zakat.
     *
     * @param  array<string,mixed>  $input  validated request data: the asset and
     *   liability keys above in integer minor units, plus optional `basis` and
     *   `nisab_price_per_gram` (minor units).
     * @return array<string,mixed>
     */
    public function calculate(array $input): array
    {
        $assets = $this->bucket(self::ASSET_KEYS, $input);
        $liabilities = $this->bucket(self::LIABILITY_KEYS, $input);

        // Floored at zero: someone whose debts exceed their assets owes no
        // zakat, and a negative "wealth" would make the 2.5% line meaningless.
        $net = max(0, $assets['total_minor'] - $liabilities['total_minor']);

        $nisab = $this->nisab($input);
        // Null threshold stays null here rather than collapsing to false: "we
        // could not tell" and "you are under the threshold" are different
        // answers, and only one of them means you owe nothing.
        $nisab['meets_nisab'] = $nisab['threshold_minor'] === null
            ? null
            : $net >= $nisab['threshold_minor'];

        $atRate = $this->zakatAtRate($net);

        return [
            'currency' => $this->currency,
            'rate' => [
                'fraction' => $this->rateNumerator . '/' . $this->rateDenominator,
                // Display only. The computation never touches this float.
                'percent' => round($this->rateNumerator / $this->rateDenominator * 100, 4),
            ],
            'assets' => $assets,
            'liabilities' => $liabilities,
            'net_zakatable_wealth_minor' => $net,
            'nisab' => $nisab,
            // Always present: the plain 2.5% of net wealth, whether or not the
            // threshold could be evaluated. It is the arithmetic, not the ruling.
            'zakat_at_rate_minor' => $atRate,
            // The answer to "do I owe, and how much" — and NULL, not 0, when the
            // threshold is unknown. Zero would read as "you owe nothing".
            'zakat_due_minor' => match ($nisab['meets_nisab']) {
                true => $atRate,
                false => 0,
                default => null,
            },
            'assumptions' => $this->assumptions($nisab['basis']),
            'disclaimer' => 'This is an arithmetic aid, not a religious ruling. Every assumption it '
                . 'made is listed above; several are matters on which qualified scholars differ. '
                . 'Confirm your own position with a scholar you trust.',
        ];
    }

    /**
     * The nisab reference on its own, for a site that wants to display "the
     * threshold today is X" without asking anyone for their net worth.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function reference(array $input = []): array
    {
        $nisab = $this->nisab($input);
        // No wealth was supplied, so there is nothing to compare the threshold
        // against; saying "you meet it" or "you do not" would be a fabrication.
        $nisab['meets_nisab'] = null;

        return [
            'currency' => $this->currency,
            'rate' => [
                'fraction' => $this->rateNumerator . '/' . $this->rateDenominator,
                'percent' => round($this->rateNumerator / $this->rateDenominator * 100, 4),
            ],
            'nisab' => $nisab,
            'assumptions' => $this->assumptions($nisab['basis']),
        ];
    }

    // ------------------------------------------------------------------ parts

    /**
     * Sum one side of the calculation, reporting every bucket including the
     * empty ones — a donor checking their own figure needs to see the zeroes
     * they left blank as much as the numbers they typed.
     *
     * @param  array<int,string>  $keys
     * @param  array<string,mixed>  $input
     * @return array{items:array<string,int>,total_minor:int}
     */
    private function bucket(array $keys, array $input): array
    {
        $items = [];
        $total = 0;

        foreach ($keys as $key) {
            // Validation has already forced these to non-negative integers; the
            // cast is what keeps a missing key at 0 rather than null.
            $value = (int) ($input[$key] ?? 0);
            $items[$key] = $value;
            $total += $value;
        }

        return ['items' => $items, 'total_minor' => $total];
    }

    /**
     * The threshold, and how it was arrived at.
     *
     * @param  array<string,mixed>  $input
     * @return array{basis:string,grams:float,price_per_gram_minor:?int,price_source:?string,threshold_minor:?int,meets_nisab:?bool}
     */
    private function nisab(array $input): array
    {
        $basis = $input['basis'] ?? null;
        $basis = in_array($basis, self::BASES, true) ? $basis : $this->defaultBasis;

        $grams = $basis === self::BASIS_GOLD ? $this->goldGrams : $this->silverGrams;

        // Request beats config: the caller's site may be showing a live spot
        // price, which is closer to the truth than anything pinned in a file.
        $requestPrice = self::nullableInt($input['nisab_price_per_gram'] ?? null);
        $configPrice = $basis === self::BASIS_GOLD
            ? $this->goldPricePerGramMinor
            : $this->silverPricePerGramMinor;

        $price = $requestPrice ?? $configPrice;
        $priceSource = $requestPrice !== null
            ? self::PRICE_SOURCE_REQUEST
            : ($configPrice !== null ? self::PRICE_SOURCE_CONFIG : null);

        return [
            'basis' => $basis,
            'grams' => $grams,
            'price_per_gram_minor' => $price,
            'price_source' => $priceSource,
            // Rounded to the nearest minor unit; the weight is a float only
            // because grams genuinely are fractional, and this is the single
            // point where it meets the money.
            'threshold_minor' => $price === null ? null : (int) round($grams * $price),
            // Filled by calculate(); a threshold alone answers nothing.
            'meets_nisab' => null,
        ];
    }

    /**
     * The rate applied to net wealth, ROUNDED UP to the next minor unit.
     *
     * Integer arithmetic throughout: `$net * 1 / 40` as a float would be exactly
     * the class of error the minor-units rule exists to prevent. Ceiling rather
     * than nearest is a deliberate choice on the payer's side — the most a
     * rounding-up can cost is a fraction of a cent, while rounding down means a
     * shortfall in an obligation. Stated in the returned assumptions.
     */
    private function zakatAtRate(int $net): int
    {
        if ($net <= 0) {
            return 0;
        }

        $numerator = $net * $this->rateNumerator;

        return intdiv($numerator + $this->rateDenominator - 1, $this->rateDenominator);
    }

    /**
     * Every position this calculation took that a scholar could dispute.
     *
     * Returned in the payload, not merely commented here: an assumption the
     * reader cannot see is an assumption made FOR them. Machine `key` so a
     * client can render or translate them; `statement` is written to be read by
     * the donor as-is.
     *
     * @return array<int,array{key:string,statement:string}>
     */
    private function assumptions(string $basis): array
    {
        $other = $basis === self::BASIS_GOLD ? 'silver' : 'gold';

        return [
            [
                'key' => 'not_a_ruling',
                'statement' => 'This tool performs arithmetic on figures you supplied. It does not '
                    . 'issue a religious ruling, and it cannot judge your particular situation.',
            ],
            [
                'key' => 'nisab_basis',
                'statement' => "The nisab threshold was taken on the {$basis} basis. The {$other} "
                    . 'basis gives a materially different threshold — silver is far lower today, so '
                    . 'it obliges many more payers. The Hanafi school takes the lower of the two, and '
                    . 'much contemporary practice follows silver as the more cautious choice for the '
                    . 'payer and the more beneficial one for recipients; other scholars and '
                    . 'institutions use gold, holding that it better preserves what the classical '
                    . 'threshold represented. You may choose the basis yourself.',
            ],
            [
                'key' => 'nisab_weights',
                'statement' => 'Nisab weights are the classical 87.48 g of gold (20 mithqāl) and '
                    . '612.36 g of silver (200 dirhams) unless this installation configured others. '
                    . 'Some institutions use 85 g and 595 g instead, from a slightly different '
                    . 'reading of the dinar and dirham weights.',
            ],
            [
                'key' => 'metal_price_not_live',
                'statement' => 'The metal price used is the one supplied with this request or '
                    . 'configured by this organization. It is not a live market quote. When no price '
                    . 'is available the threshold is reported as unknown rather than guessed, and no '
                    . 'claim is made about whether you owe zakat.',
            ],
            [
                'key' => 'rate_scope',
                'statement' => 'The 2.5% (one fortieth) rate applies to monetary wealth: cash, gold, '
                    . 'silver and trade goods. Agricultural produce (5% or 10% depending on '
                    . 'irrigation) and livestock have their own thresholds and rates and are not '
                    . 'covered here.',
            ],
            [
                'key' => 'hawl_not_verified',
                'statement' => 'Zakat falls due on wealth held for a full lunar year (hawl). This '
                    . 'tool does not and cannot verify that; it assumes you are calculating on your '
                    . 'own zakat due date.',
            ],
            [
                'key' => 'nisab_compared_to_net',
                'statement' => 'The threshold was compared against your wealth AFTER deducting the '
                    . 'liabilities you entered. Some scholars compare the threshold against wealth '
                    . 'before deductions, which can make zakat due where this result says it is not.',
            ],
            [
                'key' => 'liabilities_as_entered',
                'statement' => 'Exactly the liabilities you entered were deducted. Which debts are '
                    . 'deductible is disputed: positions range from immediately-due debts only, to '
                    . 'the coming year of instalments on a long-term debt such as a mortgage, to the '
                    . 'full outstanding balance. This tool made no such judgment for you.',
            ],
            [
                'key' => 'holdings_valued_by_you',
                'statement' => 'Gold, silver, business and investment holdings are counted at the '
                    . 'values you entered; nothing was valued for you. Whether personal jewelry in '
                    . 'customary use is zakatable is itself disputed — the Hanafi school includes it, '
                    . 'while the majority exempt a woman\'s customary jewelry — so include or omit it '
                    . 'according to the position you follow.',
            ],
            [
                'key' => 'rounding_up',
                'statement' => 'The 2.5% figure is rounded UP to the next whole minor unit of '
                    . $this->currency . ', so a fractional remainder is never left unpaid.',
            ],
            [
                'key' => 'not_stored',
                'statement' => 'The figures you submitted are used to compute this response and are '
                    . 'not stored.',
            ],
        ];
    }

    /** A configured/supplied value that may legitimately be absent. */
    private static function nullableInt(mixed $value): ?int
    {
        return ($value === null || $value === '') ? null : (int) $value;
    }
}
