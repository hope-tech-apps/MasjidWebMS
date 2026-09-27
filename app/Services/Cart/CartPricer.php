<?php

namespace App\Services\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Form;
use App\Models\Fund;
use App\Models\Masjid;
use App\Models\MealMenuItem;
use App\Services\Cart\Sources\DonationLineSource;
use App\Services\Cart\Sources\FormLineSource;
use App\Services\Cart\Sources\MealLineSource;
use App\Services\Stripe\FormChargeAccount;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Prices a whole basket at checkout and decides the ONE account it is paid into.
 *
 * ## One destination, and why that holds
 *
 * A Stripe Checkout Session is created on one connected account. Reading the two
 * account rules the payment doors already use, a single organisation's basket can
 * only ever resolve to ONE:
 *
 *   - Forms ask FormChargeAccount::for($org) — "the ONE answer to whose account
 *     takes this organisation's FORM card payments". An UNLINKED org answers with
 *     its own account; a LINKED org answers with its holder's.
 *   - Food and donations ask Masjid::canAcceptDonations(), which FormChargeAccount
 *     documents it "never changes, so a linked organisation still takes none of
 *     those". And a linked org is REQUIRED to have no account of its own
 *     (PROBLEM_HAS_OWN_ACCOUNT) — so its food and donation lines are refused.
 *
 * So: unlinked → everything to its own account; linked → forms to the holder, the
 * rest refused. One payee either way (recurring registrations, which would need a
 * second Session, are out of v1). This class does not trust that reasoning: if a
 * basket ever resolves to two accounts it REFUSES the basket, because the payment
 * doors' own rule is "fail closed, never a different payee". Splitting a basket
 * across payees would need a design of its own, not a quiet default.
 *
 * ## Items are loaded inside the basket's organisation
 *
 * `buyable_id` comes from the browser on the public pages, which run UNBOUND (the
 * tenant scope adds no filter there). Every item is therefore loaded with the
 * scope bypassed and masjid_id filtered explicitly — the MealMenu::findByUuidForMasjid
 * pattern — so another organisation's form, fund or dish is simply not found, and
 * its line is "gone". A foreign id is a miss, never a leak and never a charge.
 */
final class CartPricer
{
    public function __construct(
        private readonly FormLineSource $forms = new FormLineSource,
        private readonly MealLineSource $meals = new MealLineSource,
        private readonly DonationLineSource $donations = new DonationLineSource,
    ) {}

    public function price(Cart $cart, ?CarbonInterface $at = null): PricedBasket
    {
        $at ??= now();
        $currency = strtolower((string) config('services.stripe.currency', 'usd'));

        $org = Masjid::withTrashed()->find($cart->masjid_id);

        if ($org === null || $org->trashed()) {
            return new PricedBasket([], 0, $currency, null, 'This organisation is not taking payments.');
        }

        $lines = [];
        $total = 0;
        $destinations = [];

        // NOT `$cart->items()->withoutMasjidScope()`: withoutMasjidScope() is a STATIC
        // method that returns a fresh query, so calling it off the relation would
        // throw at best — and at worst drop the cart_id constraint and price every
        // basket's lines into this one. Filter both columns explicitly instead.
        $items = CartItem::withoutMasjidScope()
            ->where('cart_id', $cart->id)
            ->where('masjid_id', $cart->masjid_id)
            ->orderBy('id')
            ->get();

        foreach ($items as $item) {
            [$outcome, $destination] = $this->priceLine($org, $item, $at);

            if ($outcome->isPayable()) {
                if ($destination === null) {
                    // Priced fine, but there is nowhere for the money to go. Payable
                    // must never mean "charged to nobody", so the line goes.
                    $outcome = CartLineOutcome::gone(
                        $outcome->label,
                        'Online payment is not available for this right now.',
                    );
                } else {
                    $destinations[$destination] = true;
                    $total += $outcome->totalMinor();
                }
            }

            if (strtolower((string) $item->currency) !== $currency) {
                return new PricedBasket([], 0, $currency, null, 'This basket mixes currencies and cannot be paid in one go.');
            }

            $lines[] = ['item' => $item, 'outcome' => $outcome];
        }

        if (count($destinations) > 1) {
            return new PricedBasket($lines, 0, $currency, null, 'This basket cannot be paid in one go.');
        }

        $destination = array_key_first($destinations);

        return new PricedBasket($lines, $total, $currency, $destination === null ? null : (string) $destination, null);
    }

    /** @return array{0: CartLineOutcome, 1: ?string} the outcome, and the account it would be paid into */
    private function priceLine(Masjid $org, CartItem $item, CarbonInterface $at): array
    {
        $label = (string) $item->label;

        switch ($item->buyable_type) {
            case CartItem::TYPE_FORM:
                // Form carries no tenant scope (no BelongsToMasjid), so there is no
                // scope to bypass — but the explicit masjid_id filter is what makes a
                // foreign form id a miss. SoftDeletes keeps a deleted form out too.
                $form = Form::query()->where('masjid_id', $org->id)->find($item->buyable_id);

                if ($form === null) {
                    return [CartLineOutcome::gone($label, 'This is no longer available.'), null];
                }

                return [
                    $this->forms->reprice($form, (array) ($item->payload ?? []), (int) $item->unit_amount_shown_minor, $at),
                    FormChargeAccount::for($org)?->accountId,
                ];

            case CartItem::TYPE_MEAL:
                $dish = MealMenuItem::withoutMasjidScope()->where('masjid_id', $org->id)->find($item->buyable_id);

                if ($dish === null) {
                    return [CartLineOutcome::gone($label, 'This is no longer available.'), null];
                }

                $pickup = $item->payload['pickup_at'] ?? null;

                return [
                    $this->meals->reprice(
                        $dish,
                        (int) $item->quantity,
                        (int) $item->unit_amount_shown_minor,
                        is_string($pickup) && $pickup !== '' ? Carbon::parse($pickup) : null,
                        $at,
                    ),
                    $this->ownAccount($org),
                ];

            case CartItem::TYPE_DONATION:
                $fund = Fund::withoutMasjidScope()->where('masjid_id', $org->id)->find($item->buyable_id);

                if ($fund === null) {
                    return [CartLineOutcome::gone($label, 'This fund is no longer collecting.'), null];
                }

                return [
                    $this->donations->reprice($fund, (int) $item->unit_amount_shown_minor, $at),
                    $this->ownAccount($org),
                ];
        }

        // An unknown type is refused, never guessed at. A basket must not be able to
        // carry something no source knows how to price.
        return [CartLineOutcome::gone($label, 'This cannot be paid for here.'), null];
    }

    /** The account food and donations go to: the org's own, and only when Stripe says it can charge. */
    private function ownAccount(Masjid $org): ?string
    {
        return $org->canAcceptDonations() ? (string) $org->stripe_account_id : null;
    }
}
