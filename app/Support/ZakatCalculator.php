<?php

namespace App\Support;

use App\Models\Masjid;
use App\Models\MasjidZakatSetting;
use Illuminate\Support\Carbon;

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
     *
     * Precedence, highest first: REQUEST (the caller asserted a price for this
     * one call, so it is current by construction), ORGANIZATION (this masjid's
     * office typed and dated it — T-043c), CONFIG (a deployment-wide fallback in
     * .env, one price for every tenant on the deploy and carrying no date).
     */
    public const PRICE_SOURCE_REQUEST = 'request';

    public const PRICE_SOURCE_ORGANIZATION = 'organization';

    public const PRICE_SOURCE_CONFIG = 'config';

    /**
     * How old the price behind the threshold is KNOWN to be.
     *
     * Reported beside the figure so no reader has to assume. Neither STALE nor
     * UNDATED is a softer CURRENT: the calculator gives a verdict ONLY on a
     * price whose currency is established, and withholds it on the other two
     * exactly as it does when there is no price at all. A threshold computed
     * from a quote nobody has refreshed — or one whose age cannot be checked —
     * can tell a payer they owe nothing when they do (.claude/rules/zakat.md).
     *
     * UNDATED is not folded into STALE because they are different facts, and the
     * fix differs: a stale quote needs refreshing by the office that owns it, an
     * undated one (a deployment `.env` price) needs replacing with a dated one.
     * Saying which is which is the point of naming them at all.
     */
    public const FRESHNESS_CURRENT = 'current';

    public const FRESHNESS_STALE = 'stale';

    public const FRESHNESS_UNDATED = 'undated';

    /**
     * The trailing arguments are optional so every existing caller — and
     * fromConfig() itself — keeps building the same object it always did. They
     * carry the ORGANIZATION's own quote (T-043c), which is the only price that
     * arrives with a date and a citation attached.
     *
     * Note the shape: gold and silver each get their own price, date and
     * citation, and they are named as three-of-a-kind rather than collected
     * into one date for the object. That is not verbosity — a single
     * `$priceQuotedOn` here would be a field whose meaning depends on which
     * price was written last, and nisab() would have no way to ask "when was
     * THIS number read".
     */
    public function __construct(
        private readonly string $defaultBasis,
        private readonly float $goldGrams,
        private readonly float $silverGrams,
        private readonly ?int $goldPricePerGramMinor,
        private readonly ?int $silverPricePerGramMinor,
        private readonly int $rateNumerator,
        private readonly int $rateDenominator,
        private readonly string $currency,
        private readonly ?int $orgGoldPricePerGramMinor = null,
        private readonly ?int $orgSilverPricePerGramMinor = null,
        /**
         * Y-m-d, the day the organization read THAT METAL's price off a market
         * source, and its own free-text citation of where.
         *
         * One pair per metal, never one pair for the row. The two prices are
         * edited months apart, and a shared date belongs to whichever was saved
         * last: re-quoting gold would re-date a silver price nobody had looked
         * at since spring, and nisab() — which resolves the price per metal —
         * would hand a donor a hard verdict off it while printing a date it was
         * never read on. The date is only evidence while it stays attached to
         * the number it was read with.
         */
        private readonly ?string $orgGoldPriceQuotedOn = null,
        private readonly ?string $orgGoldPriceQuotedFrom = null,
        private readonly ?string $orgSilverPriceQuotedOn = null,
        private readonly ?string $orgSilverPriceQuotedFrom = null,
        private readonly int $priceFreshnessDays = 30,
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
            // No organization price on this path, but the review window still
            // travels: it is reported in every payload, so a deployment that
            // shortened it must not see 30 come back.
            priceFreshnessDays: (int) config('zakat.nisab.price_freshness_days', 30),
        );
    }

    /**
     * Build for ONE organization, overlaying its own quoted price on the
     * deployment's configuration (T-043c).
     *
     * This is what turns a permanently-"unknown" endpoint into a usable one.
     * Before it, the only price a threshold could come from was a `.env` value
     * shared by every tenant on the deploy — one price for every masjid, edited
     * by hand on a production box. Now the office that answers for the figure is
     * the one that types it, dates it, and says where it got it.
     *
     * Read through the documented `withoutMasjidScope()` bypass with an explicit
     * `masjid_id`: the public calculator runs with NO bound tenant (it resolves
     * the masjid itself from the `masjid-id` header), so the global scope would
     * add no constraint and the explicit filter is the actual isolation. On the
     * admin path a tenant IS bound, and going through the bypass keeps this one
     * query behaving identically on both — the ImpactMetrics::withTenant shape
     * (.claude/rules/tenant-scoping.md).
     *
     * An organization with no row, or a row with no price, falls all the way
     * through to config and then to "unknown". Nothing here invents a price.
     */
    public static function forMasjid(Masjid $masjid): self
    {
        $base = self::fromConfig();

        $setting = MasjidZakatSetting::withoutMasjidScope()
            ->where('masjid_id', $masjid->getKey())
            ->first();

        if ($setting === null) {
            return $base;
        }

        $basis = $setting->nisab_basis;

        return new self(
            in_array($basis, self::BASES, true) ? $basis : $base->defaultBasis,
            $base->goldGrams,
            $base->silverGrams,
            $base->goldPricePerGramMinor,
            $base->silverPricePerGramMinor,
            $base->rateNumerator,
            $base->rateDenominator,
            $base->currency,
            orgGoldPricePerGramMinor: $setting->gold_price_per_gram_minor,
            orgSilverPricePerGramMinor: $setting->silver_price_per_gram_minor,
            orgGoldPriceQuotedOn: $setting->gold_price_quoted_on?->format('Y-m-d'),
            orgGoldPriceQuotedFrom: $setting->gold_price_quoted_from,
            orgSilverPriceQuotedOn: $setting->silver_price_quoted_on?->format('Y-m-d'),
            orgSilverPriceQuotedFrom: $setting->silver_price_quoted_from,
            priceFreshnessDays: (int) config('zakat.nisab.price_freshness_days', 30),
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
        //
        // A price whose currency is not ESTABLISHED lands in that same "could
        // not tell" state, and that is the point of dating the price at all
        // (T-043c). The threshold is still reported, with whatever date it has,
        // because it is a fact about what was recorded — but comparing today's
        // wealth against a quote nobody has refreshed, or one whose age cannot
        // be established at all, produces a verdict that LOOKS derived and is
        // not. An out-of-date nisab is worse than none: somebody may pay against
        // it. So the verdict requires FRESHNESS_CURRENT, not merely a number.
        $nisab['meets_nisab'] = ($nisab['threshold_minor'] === null
            || $nisab['price_freshness'] !== self::FRESHNESS_CURRENT)
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
            'assumptions' => $this->assumptions($nisab),
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
            'assumptions' => $this->assumptions($nisab),
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
     * Everything a reader needs to judge the figure travels with it: which metal
     * and weight, the price per gram, WHICH LAYER supplied that price, WHEN it
     * was quoted, WHERE the office says it got it, and how old it is allowed to
     * be. A threshold shown without those is a number nobody can check — and
     * this one may be the number somebody pays an obligation against.
     *
     * Price precedence is request > organization > config, resolved PER METAL:
     * an office that publishes only the silver threshold has no reason to have
     * typed a gold one, and a payer who chooses the gold basis must not be
     * handed the silver office's quote by accident. Whichever layer supplied the
     * figure is the layer named in `price_source`.
     *
     * @param  array<string,mixed>  $input
     * @return array{basis:string,grams:float,price_per_gram_minor:?int,price_source:?string,price_quoted_on:?string,price_quoted_from:?string,price_freshness:?string,price_freshness_days:int,threshold_minor:?int,meets_nisab:?bool}
     */
    private function nisab(array $input): array
    {
        $basis = $input['basis'] ?? null;
        $basis = in_array($basis, self::BASES, true) ? $basis : $this->defaultBasis;

        $grams = $basis === self::BASIS_GOLD ? $this->goldGrams : $this->silverGrams;

        // Request beats everything: the caller's site may be showing a live spot
        // price, which is closer to the truth than anything stored anywhere, and
        // it was asserted for THIS call so it cannot be out of date.
        $requestPrice = self::nullableInt($input['nisab_price_per_gram'] ?? null);
        // The organization's price, its date and its citation are read TOGETHER
        // off the resolved basis. Splitting that resolution — taking the price
        // from one metal and the date from the row — is precisely the bug the
        // per-metal columns exist to make unrepresentable, so the three are
        // picked in one expression and never re-derived further down.
        $orgPrice = $basis === self::BASIS_GOLD
            ? $this->orgGoldPricePerGramMinor
            : $this->orgSilverPricePerGramMinor;
        $orgQuotedOn = $basis === self::BASIS_GOLD
            ? $this->orgGoldPriceQuotedOn
            : $this->orgSilverPriceQuotedOn;
        $orgQuotedFrom = $basis === self::BASIS_GOLD
            ? $this->orgGoldPriceQuotedFrom
            : $this->orgSilverPriceQuotedFrom;
        $configPrice = $basis === self::BASIS_GOLD
            ? $this->goldPricePerGramMinor
            : $this->silverPricePerGramMinor;

        $price = $requestPrice ?? $orgPrice ?? $configPrice;
        $priceSource = match (true) {
            $requestPrice !== null => self::PRICE_SOURCE_REQUEST,
            $orgPrice !== null => self::PRICE_SOURCE_ORGANIZATION,
            $configPrice !== null => self::PRICE_SOURCE_CONFIG,
            default => null,
        };

        $freshness = $this->freshnessOf($priceSource, $orgQuotedOn);

        return [
            'basis' => $basis,
            'grams' => $grams,
            'price_per_gram_minor' => $price,
            'price_source' => $priceSource,
            // Only an organization's quote carries these two; a request price is
            // the caller's own and a config price is a line in a deployment file.
            'price_quoted_on' => $priceSource === self::PRICE_SOURCE_ORGANIZATION
                ? $orgQuotedOn
                : null,
            'price_quoted_from' => $priceSource === self::PRICE_SOURCE_ORGANIZATION
                ? $orgQuotedFrom
                : null,
            'price_freshness' => $freshness,
            'price_freshness_days' => $this->priceFreshnessDays,
            // Rounded to the nearest minor unit; the weight is a float only
            // because grams genuinely are fractional, and this is the single
            // point where it meets the money.
            'threshold_minor' => $price === null ? null : (int) round($grams * $price),
            // Filled by calculate(); a threshold alone answers nothing.
            'meets_nisab' => null,
        ];
    }

    /**
     * How old the chosen price is known to be.
     *
     *   request      — asserted for this call, so current by construction. This
     *                  class does not verify it and says so in the assumptions.
     *   organization — dated by the office. Current until the review window
     *                  passes; STALE after it, and UNDATED if the row somehow
     *                  carries a price with no date (the write path forbids it,
     *                  so such a row predates the requirement or went round it).
     *   config       — a deployment file has no quote date, so how current it is
     *                  cannot be shown. UNDATED, honestly, rather than assumed.
     *                  Note what this costs: the `.env` escape hatch can still
     *                  publish a THRESHOLD, but it can no longer make the
     *                  calculator say whether anyone meets it. That is the
     *                  intended reading of "never ship a hardcoded metal price".
     *
     * A quote dated in the future reads as current; the request rejects such a
     * date at the boundary, and this only has to not misbehave if one exists.
     *
     * `$quotedOn` is passed IN rather than read off the object because this
     * class holds two of them, one per metal, and the answer is only true of the
     * price it was read with. Reading a date off `$this` here would put the
     * choice of metal in two places — nisab() picking the price and freshnessOf()
     * picking the date — and the two would be free to disagree. That exact
     * disagreement is the defect the per-metal columns were introduced to end:
     * a silver quote from June judged current because gold was re-priced in
     * September. The signature makes the pairing the caller's single decision.
     */
    private function freshnessOf(?string $priceSource, ?string $quotedOn): ?string
    {
        return match ($priceSource) {
            null => null,
            self::PRICE_SOURCE_REQUEST => self::FRESHNESS_CURRENT,
            self::PRICE_SOURCE_CONFIG => self::FRESHNESS_UNDATED,
            default => $quotedOn === null
                ? self::FRESHNESS_UNDATED
                : (Carbon::now()->startOfDay()->greaterThan(
                    Carbon::parse($quotedOn)->startOfDay()->addDays($this->priceFreshnessDays)
                ) ? self::FRESHNESS_STALE : self::FRESHNESS_CURRENT),
        };
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
     * Takes the resolved nisab block rather than the basis alone since T-043c:
     * WHERE the metal price came from and HOW OLD it is are facts about this
     * particular answer, so the sentence describing them has to be built from
     * the same resolution the threshold was.
     *
     * @param  array<string,mixed>  $nisab  the resolved block from nisab()
     * @return array<int,array{key:string,statement:string}>
     */
    private function assumptions(array $nisab): array
    {
        $basis = $nisab['basis'];
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
                'key' => 'metal_price_quoted_on',
                'statement' => $this->priceProvenanceStatement($nisab),
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

    /**
     * The `metal_price_quoted_on` assumption: where this threshold's price came
     * from, when it was true, and what is NOT being claimed as a result.
     *
     * Deliberately free of fiqh. Every sentence below is a statement about this
     * software and about a date in a database — which layer supplied a number,
     * what day a person recorded it, and whether the tool is therefore willing
     * to say anything about the threshold. The disputed religious positions are
     * the OTHER assumptions in the list; conflating the two would let a
     * bookkeeping fact borrow the authority of a ruling, or the reverse.
     *
     * @param  array<string,mixed>  $nisab
     */
    private function priceProvenanceStatement(array $nisab): string
    {
        $days = $this->priceFreshnessDays;
        $quotedOn = $nisab['price_quoted_on'] !== null
            ? Carbon::parse($nisab['price_quoted_on'])->format('j F Y')
            : null;

        // The office's own citation, appended verbatim where it exists. A reader
        // checking the threshold needs to be able to go to the same source.
        $citation = $nisab['price_quoted_from'] !== null && $nisab['price_quoted_from'] !== ''
            ? ' The organization gives its source for that price as: ' . $nisab['price_quoted_from'] . '.'
            : ' The organization did not record where it took that price from.';

        if ($nisab['price_source'] === self::PRICE_SOURCE_REQUEST) {
            return 'The metal price used was supplied with this request rather than taken from any '
                . 'stored figure, so it is as current as whatever supplied it. This tool did not '
                . 'verify it against any market.';
        }

        if ($nisab['price_source'] === self::PRICE_SOURCE_ORGANIZATION) {
            if ($nisab['price_freshness'] === self::FRESHNESS_STALE) {
                return "The metal price behind this threshold was recorded by this organization on "
                    . "{$quotedOn}, which is more than {$days} days ago." . $citation . ' The '
                    . 'threshold that price implies is still shown so you can see the figure and its '
                    . 'date — but because the price is out of date, nothing is said here about '
                    . 'whether your wealth reaches the threshold or what you owe. Ask the '
                    . 'organization for a current figure before relying on it.';
            }

            if ($quotedOn === null) {
                return 'The metal price behind this threshold was recorded by this organization, but '
                    . 'no date was stored with it, so how current it is cannot be checked.' . $citation
                    . ' For that reason nothing is said here about whether your wealth reaches the '
                    . 'threshold or what you owe.';
            }

            return "The metal price behind this threshold was recorded by this organization on "
                . "{$quotedOn} and is reviewed every {$days} days." . $citation . ' It is not a live '
                . 'market quote, and this tool did not verify it.';
        }

        if ($nisab['price_source'] === self::PRICE_SOURCE_CONFIG) {
            return 'The metal price used is the one configured for this installation rather than one '
                . 'this organization recorded. It carries no quote date, so how current it is cannot '
                . 'be checked and it is not a live market quote. For that reason the threshold is '
                . 'shown but nothing is said here about whether your wealth reaches it.';
        }

        return 'No metal price is available, so no threshold can be stated. Nothing is said here '
            . 'about whether your wealth reaches the nisab or what you owe — only the one-fortieth '
            . 'of your net wealth is shown, as arithmetic.';
    }

    /** A configured/supplied value that may legitimately be absent. */
    private static function nullableInt(mixed $value): ?int
    {
        return ($value === null || $value === '') ? null : (int) $value;
    }
}
