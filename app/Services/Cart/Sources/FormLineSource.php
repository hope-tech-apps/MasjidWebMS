<?php

namespace App\Services\Cart\Sources;

use App\Models\Form;
use App\Services\Cart\CartLineOutcome;
use Carbon\CarbonInterface;

/**
 * Re-checks a basket line that buys a place on a form — MEC's festival tickets,
 * Zakat-ul-Fitr, iftar sponsorships, Qurbani.
 *
 * Every question here is asked by calling the FORM's own methods, never by
 * reimplementing them:
 *
 *   - `acceptsSubmissions()` is documented as "the single question the public
 *     submit endpoint asks before accepting anything" — is_active, inside the
 *     opens/closes window, and not at capacity. A basket must not be a way
 *     around it.
 *   - `closedReason()` already writes the sentence the public renderer shows on a
 *     closed form, so the shopper gets the same wording here as they would there.
 *   - `priceFor()` prices the answers, including per-entry pricing (form 126 is
 *     $15 x the number of ticket rows) and count tiers. The basket's stored
 *     amount is never trusted.
 *
 * The one thing this does NOT re-check is capacity as a reservation. A form with
 * one place left and two shoppers holding it in their baskets will sell to
 * whoever pays first; the other is told at checkout. Reserving places from a
 * basket would need a hold with an expiry, which nothing in the payment path has
 * today, and quietly overselling would be worse than telling the second shopper.
 */
final readonly class FormLineSource
{
    /**
     * @param  array<string,mixed>  $payload  the answers this line will submit
     */
    public function reprice(
        Form $form,
        array $payload,
        int $unitAmountShownMinor,
        ?CarbonInterface $at = null,
    ): CartLineOutcome {
        // `name`, not `title`: forms have no title column, and reading one gives
        // null, which would quietly label every dropped line with its slug.
        $label = (string) ($form->name ?: $form->slug);

        if (! $form->acceptsSubmissions($at)) {
            return CartLineOutcome::gone(
                $label,
                $form->closedReason($at) ?? 'This is no longer accepting responses.',
            );
        }

        $price = $form->priceFor($payload, $at);

        if ($price === null) {
            // A form whose fee rule cannot be read prices nothing. Refusing is the
            // only safe answer: charging a guessed amount is worse than dropping
            // the line and saying so.
            return CartLineOutcome::gone($label, 'This is no longer priced, so it cannot be paid for here.');
        }

        $unitMinor = (int) round(((float) $price['unit']) * 100);
        $quantity = (int) $price['quantity'];

        if ($quantity < 1) {
            return CartLineOutcome::gone($label, 'This no longer has anything to pay for.');
        }

        if ($unitMinor !== $unitAmountShownMinor) {
            return CartLineOutcome::repriced(
                $unitMinor,
                $quantity,
                $label,
                'The price changed while this was in your basket.',
            );
        }

        return CartLineOutcome::available($unitMinor, $quantity, $label);
    }
}
