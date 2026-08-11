<?php

namespace App\Support;

use App\Models\Fund;

/**
 * ZakatDesignation — the single rule for deciding whether one gift is zakat.
 *
 * Plain support class, the DonationMetrics/ImpactMetrics idiom: the public
 * checkout path, the admin offline-gift path and the reporting layer all resolve
 * the designation through this one method, so a second implementation cannot
 * drift away from it.
 *
 * ## The rule
 *
 * Zakat is a RESTRICTION the donor places on a gift, not a label the
 * organization puts on a bucket. `funds.type = 'zakat'` describes the bucket;
 * `donations.is_zakat` describes the gift. So:
 *
 *   1. If the giver (donor at checkout, or an admin recording a cash gift) SAYS
 *      whether it is zakat, that answer wins — including "no" on a gift into a
 *      zakat-typed fund.
 *   2. If nobody says, the fund's type is the DEFAULT. Giving to a fund the org
 *      set up as its zakat fund is a reasonable declaration of intent, and it is
 *      what today's rows already imply.
 *
 * `source` records which of those two produced the answer, so a treasurer can
 * tell a donor's own declaration from an inference the platform made. It is
 * non-null ONLY when the gift is zakat: there is nothing to attribute about a
 * gift that carries no restriction. That a fund is zakat-typed while its gift is
 * not is still legible — the fund's type is right there on the row's relation.
 *
 * ## What this deliberately does NOT decide
 *
 * Nothing here rules on whether a gift DISCHARGES the giver's zakat obligation,
 * nor on whether a recipient is eligible under the eight categories of Qur'an
 * 9:60. Those are fiqh judgments and case management (a separate slice). This
 * records what the giver said, and nothing more.
 */
class ZakatDesignation
{
    /** The giver said so at checkout. */
    public const SOURCE_DONOR = 'donor';

    /** Nobody said; the gift went to a fund the org typed as zakat. */
    public const SOURCE_FUND_DEFAULT = 'fund_default';

    /** An administrator recording an offline gift said so on the giver's behalf. */
    public const SOURCE_ADMIN = 'admin';

    public const SOURCES = [self::SOURCE_DONOR, self::SOURCE_FUND_DEFAULT, self::SOURCE_ADMIN];

    /**
     * Resolve one gift's designation.
     *
     * @param  ?bool  $declared        what the giver said; null = did not say
     * @param  Fund   $fund            the designation the gift was given to
     * @param  string $declaredSource  who did the declaring when $declared is not null
     * @return array{is_zakat:bool,zakat_source:?string}
     */
    public static function resolve(
        ?bool $declared,
        Fund $fund,
        string $declaredSource = self::SOURCE_DONOR
    ): array {
        if ($declared !== null) {
            return [
                'is_zakat' => $declared,
                // An explicit "not zakat" carries no designation to attribute.
                'zakat_source' => $declared ? $declaredSource : null,
            ];
        }

        $fromFund = self::fundDefault($fund);

        return [
            'is_zakat' => $fromFund,
            'zakat_source' => $fromFund ? self::SOURCE_FUND_DEFAULT : null,
        ];
    }

    /** Whether an undeclared gift to this fund defaults to zakat. */
    public static function fundDefault(Fund $fund): bool
    {
        return $fund->type === 'zakat';
    }

    /**
     * What `is_zakat` means, for any payload that reports a zakat figure.
     *
     * Provenance travels with the number, the way every ImpactMetrics figure
     * carries its definition (.claude/rules/impact-metrics.md): a zakat total an
     * organization publishes to its donors has to say what was counted, or a
     * reader is left to assume it means "gifts to the zakat fund" — which is the
     * one thing it deliberately does not mean.
     */
    public static function definition(): string
    {
        return 'A gift is zakat when donations.is_zakat is true — the restriction the GIVER '
            . 'placed on that gift, recorded when the gift was created and never re-derived. '
            . 'It is NOT "gifts to a fund of type zakat": zakat given to a general fund counts '
            . 'here, and a non-zakat gift into a zakat-typed fund does not. The designation '
            . 'defaults to the fund\'s type only when the giver did not say; zakat_source '
            . 'records which of the two it was. This states what the giver DESIGNATED; it '
            . 'makes no ruling on whether the obligation was discharged or on recipient '
            . 'eligibility.';
    }
}
