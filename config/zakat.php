<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Zakat calculator (T-031)
    |--------------------------------------------------------------------------
    |
    | Configuration for App\Support\ZakatCalculator, the public arithmetic aid
    | behind POST /api/v1/zakat/calculate.
    |
    | EVERY value here is a FIQH POSITION or a market figure, not a constant. A
    | calculator that quietly picks one and shows a donor a dollar amount is
    | making a religious ruling on their behalf, so each setting is written down
    | with what is assumed and who disagrees, is overridable per deployment, and
    | is echoed back in the response payload so the donor sees what produced
    | their number.
    |
    | Nothing in this file is a live market feed. The calculator never fetches a
    | metal price; the price is supplied per request or configured here.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Nisab — the threshold below which no zakat is owed
    |--------------------------------------------------------------------------
    |
    | Nisab is classically expressed as a weight of gold or of silver, and the
    | two have not tracked each other for centuries: silver's value has collapsed
    | relative to gold, so the SILVER threshold is today far lower — often a
    | tenth of the gold one — and catches many more payers.
    |
    | DEFAULT: 'silver'.
    |
    |   Source note / why: the Hanafi school takes the LOWER of the two
    |   thresholds, and a broad body of contemporary scholarship and of zakat
    |   institutions recommends the silver basis on the same reasoning — it is
    |   the more cautious choice for the payer (more people owe, and zakat is an
    |   obligation, so erring toward paying is the safer side) and the more
    |   beneficial one for the recipients zakat exists for.
    |
    |   THIS IS DISPUTED, and the dispute is substantive rather than technical:
    |   many contemporary scholars and institutions use the GOLD basis, holding
    |   that gold better preserves the purchasing power the classical silver
    |   nisab represented, and that the silver figure now obliges people of very
    |   modest means whom the classical rulings did not intend to oblige.
    |
    | The payer may override the basis per request; a deployment may change the
    | default here. The basis actually used is always named in the response.
    |
    */

    'nisab' => [

        'basis' => env('ZAKAT_NISAB_BASIS', 'silver'),

        /*
         * The classical weights, in grams.
         *
         *   gold   — 20 mithqāl / dinars ≈ 87.48 g
         *   silver — 200 dirhams        ≈ 612.36 g
         *
         * These conversions are themselves contested at the margin: the widely
         * used alternatives are 85 g of gold and 595 g of silver, which come
         * from a slightly different reading of the classical dinar/dirham
         * weights. The difference is a few percent of the threshold, which
         * matters to a payer sitting right on it — hence configurable, and
         * hence reported in the payload rather than buried.
         */
        'gold_grams' => (float) env('ZAKAT_NISAB_GOLD_GRAMS', 87.48),
        'silver_grams' => (float) env('ZAKAT_NISAB_SILVER_GRAMS', 612.36),

        /*
         * Metal price per GRAM in integer MINOR UNITS (cents), used only to turn
         * the weight above into a money threshold.
         *
         * NULL by default, deliberately. A price shipped in a config file is
         * stale the day after it is written, and a stale threshold silently
         * tells a payer they owe nothing when they do. When no price is
         * available the calculator still returns the wealth and the 2.5% figure
         * but reports the threshold as unknown and `meets_nisab` as null — it
         * never guesses, and it never presents an out-of-date figure as today's.
         *
         * Supply it per request (`nisab_price_per_gram`, from whatever spot
         * price the caller's site displays) or configure it per deployment and
         * keep it current.
         */
        'gold_price_per_gram_minor' => env('ZAKAT_GOLD_PRICE_PER_GRAM_MINOR') !== null
            ? (int) env('ZAKAT_GOLD_PRICE_PER_GRAM_MINOR')
            : null,

        'silver_price_per_gram_minor' => env('ZAKAT_SILVER_PRICE_PER_GRAM_MINOR') !== null
            ? (int) env('ZAKAT_SILVER_PRICE_PER_GRAM_MINOR')
            : null,

    ],

    /*
    |--------------------------------------------------------------------------
    | The rate
    |--------------------------------------------------------------------------
    |
    | 2.5% = 1/40, by consensus, on monetary wealth — cash, gold, silver, and
    | trade goods. It is NOT the rate for agricultural produce (5% or 10%
    | depending on irrigation) or for livestock, which carry their own nisab and
    | their own schedules and which this calculator does not attempt.
    |
    | Expressed as an integer fraction, never a float: money here is integer
    | minor units end to end, and 0.025 in binary floating point is not 1/40.
    | Not env-configurable — the rate is not a deployment preference.
    |
    */

    'rate' => [
        'numerator' => 1,
        'denominator' => 40,
    ],

];
