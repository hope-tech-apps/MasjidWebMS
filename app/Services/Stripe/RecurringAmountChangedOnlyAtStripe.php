<?php

namespace App\Services\Stripe;

use RuntimeException;

/**
 * Stripe accepted a new recurring amount and we then failed to record it.
 *
 * The one outcome of `DonationService::changeSubscriptionAmount` that is neither
 * a success nor a "nothing happened". The Stripe call is first and it is the one
 * that moves money: once the subscription item is repriced, the card WILL be
 * charged the new figure from the next invoice, whatever our database then does.
 * If the local write fails at that instant — a deadlock on
 * `donation_subscriptions`, a dropped connection, a read-only replica after a
 * failover — the truth is "Stripe changed, we did not", and neither of the two
 * sentences the surface already had can say it: 200 would claim a row we do not
 * hold, and the generic 503 ("nothing has changed") would tell the donor the
 * opposite of what their bank will show.
 *
 * Its own type so the donor surface can answer that third case in words, exactly
 * as `FormCheckoutRefused` exists so a public controller never prints a
 * QueryException's SQL. It carries the figure Stripe accepted, in integer minor
 * units, because that is the number the donor's statement will carry and the
 * number an operator has to reconcile the row to.
 *
 * WHY THERE IS NO SELF-HEALING PATH. A cancel repairs itself: the donor's retry
 * finds Stripe already cancelled and writes the row, and
 * `customer.subscription.deleted` arrives regardless. An amount has no such
 * event — `invoice.payment_succeeded` books `amount_paid` but copies
 * `intended_amount` off our stale row onto the donation and its receipt. So this
 * is logged at CRITICAL by `changeSubscriptionAmount` and surfaced here; it is
 * not something a donor's next tap quietly fixes.
 */
final class RecurringAmountChangedOnlyAtStripe extends RuntimeException
{
    public function __construct(
        public readonly int $acceptedAmount,
        public readonly int $subscriptionId,
        ?\Throwable $previous = null
    ) {
        parent::__construct(
            'Stripe accepted a new recurring amount that could not be recorded locally.',
            0,
            $previous
        );
    }
}
