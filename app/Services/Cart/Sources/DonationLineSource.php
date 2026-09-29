<?php

namespace App\Services\Cart\Sources;

use App\Models\Fund;
use App\Models\Masjid;
use App\Services\Cart\CartLineOutcome;
use Carbon\CarbonInterface;

/**
 * Re-checks a basket line that is a gift rather than a purchase.
 *
 * A donation is the one line whose amount the DONOR set, so there is no price to
 * re-read and nothing to reprice: a gift cannot "go up" while it sits in a
 * basket. What can change is whether the fund is still collecting — an admin can
 * deactivate a fund between Thursday and Saturday, and money must not be taken
 * for a fund that has closed.
 *
 * Two things this deliberately does NOT do:
 *
 *   - It does not gross up for fees. `DonationService` already decides that from
 *     `donorCoversFees`, and doing it twice would charge the donor the fee twice.
 *     The basket carries the INTENDED amount; the checkout applies the same
 *     grossing rule it always has.
 *   - It does not decide zakat. `ZakatDesignation::resolve()` reads the donor's
 *     choice against the fund at checkout, and it stays the only place that runs,
 *     because a gift recorded as zakat when it was not is a religious error and
 *     not merely a bookkeeping one.
 *
 * Quantity is always 1. A donation of $50 is one line of $50, never 50 lines of
 * $1 — the receipt, and the donor's own statement, have to read the way the
 * donor meant it.
 *
 * The door's own gates are asked here, so checkout re-asks them too (brief 5, section 2):
 *
 *   - the `giving` module switched off (DonationsController::createCheckoutSession):
 *     no gift is opened, and the door's own sentence is the reason;
 *   - `canAcceptDonations()` is NOT asked here. CartPricer::ownAccount() already asks it
 *     (and that the id is really a connected account) when it picks the payee, and a line
 *     with no payee is refused as "Online payment is not available"; asking it twice would
 *     be two places to keep in step.
 */
final readonly class DonationLineSource
{
    /** DonationsController's own refusal when the giving module is off. */
    public const GIVING_OFF = 'This organisation is not taking donations in the app right now.';

    /**
     * @param  Masjid|null  $org  the organisation the fund belongs to. CartPricer passes the one
     *                            it already loaded; a direct caller may omit it and the gate then
     *                            reads it from the fund, so it is never skipped.
     */
    public function reprice(
        Fund $fund,
        int $intendedAmountMinor,
        ?CarbonInterface $at = null,
        ?Masjid $org = null,
    ): CartLineOutcome {
        $label = (string) $fund->name;

        // Fail closed: an organisation that cannot be read is not taking gifts. Masjid::query()
        // leaves out a soft-deleted one.
        $org ??= Masjid::query()->find($fund->masjid_id);

        if ($org === null || $org->moduleIsOff('giving')) {
            return CartLineOutcome::gone($label, self::GIVING_OFF);
        }

        if (! $fund->is_active) {
            return CartLineOutcome::gone($label, 'This fund is no longer collecting.');
        }

        if ($intendedAmountMinor < 1) {
            return CartLineOutcome::gone($label, 'A donation needs an amount.');
        }

        return CartLineOutcome::available($intendedAmountMinor, 1, $label);
    }
}
