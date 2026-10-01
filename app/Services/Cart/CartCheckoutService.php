<?php

namespace App\Services\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Form;
use App\Models\Masjid;
use App\Models\MealMenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Stripe\FormChargeAccount;
use App\Services\Stripe\FormResponseCheckoutService;
use App\Support\FormPayment;
use App\Support\FormSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\InvalidRequestException;
use Stripe\StripeClient;
use Throwable;

/**
 * Sends a priced basket to ONE Stripe Checkout page (universal cart, design §11).
 *
 * A sibling of the four single-item services, not a refactor of them — the
 * locked payment design defers any shared extraction. It follows their rules to
 * the letter, and the ones that matter most are:
 *
 *   - DIRECT charge on the ONE connected account CartPricer chose, passed as the
 *     `stripe_account` request option. assertConnectedAccount() runs before every
 *     Stripe call, copied from the form service: an empty account would send the
 *     call to the PLATFORM, making it merchant of record.
 *   - The idempotency key is minted and SAVED on the order before Stripe is asked.
 *   - Nothing here marks anything paid. Only the signature-verified webhook does
 *     (slice 4b), and only on payment_status 'paid'.
 *   - `application_fee_amount` is present only when above zero; Stripe rejects 0.
 *   - On the org's OWN account the routing key `cart_order_uuid` rides on both the
 *     session and the payment intent. On a HOLDER's account (a linked org) only an
 *     opaque `cart_charge_ref` does — the holder's Stripe users read that metadata,
 *     and the uuid is the bearer handle for the status read (the form service's rule).
 *
 * And the basket's own rules, all from the checkout review (design/checkout-review-
 * 2026-09-28.json):
 *
 *   - A basket that changed since the shopper last looked is REFUSED, the changes
 *     named, with a fingerprint of what they were shown. acknowledge() applies the
 *     changes only while the basket still prices that way.
 *   - An open page is handed back only for the SAME basket — same lines, answers,
 *     prices and payee (PricedBasket::chargeFingerprint), not merely the same total —
 *     and the SAME buyer email. A different $50 must never be sent to the old $50's page,
 *     and neither may a corrected address: the email is locked into the Stripe page and
 *     is where settlement mails the receipt, so a page opened for the typo is closed and a
 *     new one is opened for what the shopper typed last.
 *   - Stripe's charge bounds are checked before anything is written.
 *
 * Records are created ONLY ONCE PAID (owner decision, design §12), by the webhook
 * (CartSettlementService). So checkout also FREEZES what settlement will need onto each
 * order item (`payload`, `price_snapshot` — see snapshotFor()): the webhook never
 * re-quotes, because a quote depends on the date and on the card switch, and one that
 * came back null would throw AFTER the money was taken.
 *
 * A product line's stock is decided HERE, not at pricing (shop slice B1, ProductStock): the basket's
 * sizes are read BEFORE the transaction opens and locked by primary key, in ascending id order, as
 * its first statements after the cart's own lock (before any plain read, so the held-quantity sum
 * is read after the locks are won; never a locking range read of `cart_items`, whose gap locks
 * would make other baskets' adds wait on this checkout's Stripe calls). After pricing, a size the
 * basket gained in between runs the checkout once more. After it has made the order and its lines
 * it counts what other pending orders hold EXCLUDING the order it just made. A shortfall refuses
 * the whole checkout with a sentence naming the line, the transaction rolls the order back, and no
 * Stripe page is opened. An open page that is handed back instead of replaced is not counted a
 * second time: it made no new order, and its own units were held when it was opened.
 *
 * An order this checkout found dead (its page expired on Stripe or closed by replacing it) stays
 * expired even when the transaction then rolls back: the Stripe side is not undone by a rollback,
 * and a dead page left `pending` would hold its sizes' units for up to 46 minutes.
 *
 * A basket that has been PAID is closed (`Cart::STATUS_CHECKED_OUT`, by the settlement
 * transaction) and checkout and acknowledge() refuse it; checkout also refuses a basket
 * whose fingerprint already has a paid order on this cart, so a page for the same lines
 * can never be opened after they were paid for, even on a cart that was left open.
 *
 * Reached from CartsController (the public basket endpoints, POST /api/v1/cart/checkout and
 * /cart/acknowledge), which is behind the `cart.enabled` gate and dark until config/cart.php
 * switches it on.
 */
class CartCheckoutService
{
    /** Stripe's minimum is 30 minutes; the 60 s keeps us clear of its clock. */
    public const PAGE_LIFETIME_SECONDS = 30 * 60 + 60;

    /** The routing key on the org's own account. */
    public const METADATA_KEY = 'cart_order_uuid';

    /** The routing key on a holder's account — opaque, and the only one there. */
    public const CHARGE_REF_KEY = 'cart_charge_ref';

    private const PAID_MESSAGE = 'This basket has already been paid for.';

    /** The kitchen rings the buyer about a dish; both meal doors refuse an order with no phone. */
    public const PHONE_REQUIRED = 'Please add a phone number so the kitchen can reach you about your meal order.';

    /** The width of orders.buyer_name and orders.buyer_phone. */
    private const BUYER_NAME_MAX = 120;
    private const BUYER_PHONE_MAX = 32;

    /** The two parameters that carry the buyer's address to Stripe; a refusal naming either drops both. */
    private const EMAIL_PARAMS = ['customer_email', 'payment_intent_data[receipt_email]'];

    /** Refused when the basket's sizes moved under two attempts in a row. */
    public const SIZES_MOVED = 'Your basket changed while it was being checked out. Please try again.';

    /**
     * The orders markExpired() expired during the current checkout attempt, re-applied after a
     * rollback (keepExpired()).
     *
     * @var list<int>
     */
    private array $expiredHere = [];

    public function __construct(
        private readonly StripeClient $stripe,
        private readonly CartPricer $pricer = new CartPricer,
    ) {}

    /**
     * @param  string  $returnBase  an origin+path FormPaymentReturn::base() has already checked
     * @param  string|null  $buyerName  what the shopper typed; kept on the order for the office and for
     *                                  settlement (a meal order's name, a gift's donor)
     * @param  string|null  $buyerPhone  likewise (a meal order's phone)
     * @param  bool  $requirePhoneForMeals  the public door's rule, decided HERE under the basket lock: refuse
     *                                      a basket that prices a payable dish when `$buyerPhone` is empty.
     *                                      The endpoint checks the same before the lock, from a read that a
     *                                      dish added by another tab can overtake; this is the check that
     *                                      holds. Off for a caller that collects no buyer details (an order
     *                                      opened without a phone settles under the placeholder).
     * @return array{order: Order, url: string}
     */
    public function checkout(
        Cart $cart,
        string $returnBase,
        ?string $buyerEmail = null,
        ?string $buyerName = null,
        ?string $buyerPhone = null,
        bool $requirePhoneForMeals = false,
    ): array {
        for ($attempt = 1; ; $attempt++) {
            $this->expiredHere = [];

            // Read OUTSIDE the transaction, where a plain read fixes no snapshot and takes no lock
            // (ProductStock's docblock): an ordinary basket names none and locks nothing extra.
            $sizes = ProductStock::basketVariantIds($cart);

            try {
                return $this->checkoutWith($sizes, $cart, $returnBase, $buyerEmail, $buyerName, $buyerPhone, $requirePhoneForMeals);
            } catch (Throwable $e) {
                $this->keepExpired();

                if (! $e instanceof BasketSizesMoved) {
                    throw $e;
                }

                if ($attempt >= 2) {
                    throw new CartCheckoutRefused(self::SIZES_MOVED);
                }
            }
        }
    }

    /**
     * One checkout attempt, in one transaction, with `$sizes` (ascending ids) as the product sizes
     * the basket was read to hold just before it opened.
     *
     * @param  list<int>  $sizes
     * @return array{order: Order, url: string}
     */
    private function checkoutWith(
        array $sizes,
        Cart $cart,
        string $returnBase,
        ?string $buyerEmail,
        ?string $buyerName,
        ?string $buyerPhone,
        bool $requirePhoneForMeals,
    ): array {
        return DB::transaction(function () use ($sizes, $cart, $returnBase, $buyerEmail, $buyerName, $buyerPhone, $requirePhoneForMeals): array {
            // Re-read under a lock: two tabs pressing "pay" must not open two pages.
            $locked = Cart::withoutMasjidScope()->whereKey($cart->id)->lockForUpdate()->firstOrFail();

            self::assertOpen($locked);

            // The sizes of any product line, locked by primary key BEFORE the first plain read of
            // this transaction: a locking read sees the latest committed rows and fixes no snapshot,
            // so everything the pricer and the stock check read after this is read after the locks
            // are won, and a checkout that waited behind another sees the units it took. No locking
            // read of cart_items: its gap locks would stall other baskets' adds (ProductStock).
            ProductStock::lock((int) $locked->masjid_id, $sizes);

            $priced = $this->pricer->price($locked);

            // A size added to the basket after `$sizes` was read was priced from a snapshot taken
            // without its lock. Nothing has been written yet: roll back and run once more.
            if (array_diff(ProductStock::pricedVariantIds($priced), $sizes) !== []) {
                throw new BasketSizesMoved;
            }

            if ($priced->refusal !== null) {
                throw new CartCheckoutRefused($priced->refusal);
            }

            if ($priced->notices() !== []) {
                throw CartCheckoutRefused::basketChanged($priced);
            }

            if (! $priced->isPayable()) {
                throw new CartCheckoutRefused('Your basket has nothing to pay for.');
            }

            // Asked of the basket AS PRICED under the lock, never of a read taken before it: a
            // dish another tab added in between is in `$priced`, and without this it would be
            // charged with no number for the kitchen to ring.
            if ($requirePhoneForMeals
                && self::hasPayableMeal($priced)
                && self::usableText($buyerPhone, self::BUYER_PHONE_MAX) === null) {
                throw new CartCheckoutRefused(self::PHONE_REQUIRED);
            }

            // Belt and braces for a cart left open with a paid order behind it (one paid
            // before settlement closed baskets): the same lines are never charged twice.
            // Scoped to THIS cart, so a later basket with the same contents (a monthly
            // gift, say) is a new purchase and is not caught.
            if ($this->alreadyPaid($locked, $priced)) {
                throw new CartCheckoutRefused(self::PAID_MESSAGE);
            }

            // Before any write and before an old page is closed: a total Stripe would
            // refuse must be a message, not a raw exception after a stale page was expired.
            $bound = self::amountRefusal($priced->totalMinor);
            if ($bound !== null) {
                throw new CartCheckoutRefused($bound);
            }

            $account = (string) $priced->destinationAccountId;
            self::assertConnectedAccount($account);

            $reused = $this->reuseOpenPage($locked, $priced, $account, $buyerEmail);
            if ($reused !== null) {
                // The same page, but the shopper may have corrected a phone number or a name on
                // the way back to it: the office rings what they typed LAST. Neither is on the
                // Stripe page. The email is, so a different one never gets here: reuseOpenPage()
                // closed that page and this call opened a new one for it.
                $this->refreshBuyer($reused['order'], $buyerName, $buyerPhone);

                return $reused;
            }

            $order = $this->createPendingOrder($locked, $priced, $account, $buyerEmail, $buyerName, $buyerPhone);

            $url = $this->openPage($order, $priced, $account, $returnBase, $buyerEmail);

            return ['order' => $order, 'url' => $url];
        });
    }

    /**
     * Brings the basket into line with what checkout found — drops what went, takes
     * the current price and quantity of what changed — so the shopper's next press of
     * "pay" goes through.
     *
     * `$seen` is the fingerprint the refusal carried (CartCheckoutRefused::seen()). The
     * changes are applied ONLY while the basket still prices exactly the way the
     * shopper was shown; if anything moved again in between — a second price edit, a
     * lowered cap, another line gone — nothing is written and the shopper is shown the
     * new state instead. Their "OK" is to what they saw, never to what came after.
     */
    public function acknowledge(Cart $cart, string $seen): PricedBasket
    {
        return DB::transaction(function () use ($cart, $seen): PricedBasket {
            $locked = Cart::withoutMasjidScope()->whereKey($cart->id)->lockForUpdate()->firstOrFail();

            self::assertOpen($locked);

            $priced = $this->pricer->price($locked);

            if (! hash_equals($priced->viewFingerprint(), $seen)) {
                throw CartCheckoutRefused::basketChanged($priced);
            }

            foreach ($priced->lines as ['item' => $item, 'outcome' => $outcome]) {
                if ($outcome->status === 'gone') {
                    $item->delete();
                } elseif ($outcome->status === 'repriced') {
                    $item->forceFill([
                        'unit_amount_shown_minor' => $outcome->unitAmountMinor,
                        'quantity' => $outcome->quantity,
                    ])->save();
                }
            }

            return $this->pricer->price($locked);
        });
    }

    /**
     * A basket was just paid through ONE of its pages: expire every OTHER page of the same
     * basket that is still pending, so a page holding lines that are already paid for can
     * never charge them again (the expired-but-paid race: page A is paid as it lapses, the
     * shopper adds a line and opens page B, and A's late webhook arrives). It is CartSettlement's
     * step after its commit, so it reuses closePage() and never throws: the money is recorded,
     * and a page Stripe would not close (or could not be asked about) is logged for staff.
     */
    public function closeOtherPages(int $masjidId, int $cartId, int $exceptOrderId): void
    {
        $others = self::otherPendingPages($masjidId, $cartId, $exceptOrderId)->orderBy('id')->get();

        foreach ($others as $other) {
            try {
                $this->closePage($other);
            } catch (Throwable $e) {
                Log::warning(
                    'A basket was paid, and another payment page of the same basket could not be closed afterwards. '
                    . 'It may still be payable; if it is paid, its lines were already paid for and one payment is a double charge to refund.',
                    [
                        'order_id' => (int) $other->id,
                        'paid_order_id' => $exceptOrderId,
                        'masjid_id' => $masjidId,
                        'exception' => $e::class,
                    ]
                );
            }
        }
    }

    /** This basket's pending orders that opened a Stripe page, other than the one that was paid. */
    public static function otherPendingPages(int $masjidId, int $cartId, int $exceptOrderId): Builder
    {
        return Order::withoutMasjidScope()
            ->where('masjid_id', $masjidId)
            ->where('cart_id', $cartId)
            ->where('status', Order::STATUS_PENDING)
            ->whereNotNull('stripe_checkout_session_id')
            ->whereKeyNot($exceptOrderId);
    }

    /** A basket that is not open (it was paid for) is never priced, paid or changed again. */
    private static function assertOpen(Cart $cart): void
    {
        if ($cart->status !== Cart::STATUS_OPEN) {
            throw new CartCheckoutRefused(self::PAID_MESSAGE);
        }
    }

    /** Whether this cart already has a PAID order for exactly these lines, prices and payee. */
    private function alreadyPaid(Cart $cart, PricedBasket $priced): bool
    {
        return Order::withoutMasjidScope()
            ->where('masjid_id', $cart->masjid_id)
            ->where('cart_id', $cart->id)
            ->where('status', Order::STATUS_PAID)
            ->where('basket_fingerprint', $priced->chargeFingerprint())
            ->exists();
    }

    /** Whether the basket, as priced, has a dish that would be charged for. */
    private static function hasPayableMeal(PricedBasket $priced): bool
    {
        foreach ($priced->lines as ['item' => $item, 'outcome' => $outcome]) {
            if ($item->buyable_type === CartItem::TYPE_MEAL && $outcome->isPayable()) {
                return true;
            }
        }

        return false;
    }

    /** The platform's cut, on the charged total. Present only when above zero. */
    public static function applicationFee(int $totalMinor, ?float $platformPct = null): int
    {
        $pct = $platformPct ?? (float) config('services.stripe.platform_fee_percentage', 0);

        return max(0, (int) round($totalMinor * $pct));
    }

    /** Stripe's bounds, as FormResponseCheckoutService::amountRefusal() words them. */
    private static function amountRefusal(int $totalMinor): ?string
    {
        if ($totalMinor < FormPayment::MIN_CHARGE_MINOR) {
            return 'This basket is below the smallest amount a card can be charged.';
        }

        if ($totalMinor > FormPayment::MAX_CHARGE_MINOR) {
            return 'This basket is more than a card can be charged at once. Please split it up or contact the organisers.';
        }

        return null;
    }

    /** @return array{order: Order, url: string}|null */
    private function reuseOpenPage(Cart $cart, PricedBasket $priced, string $account, ?string $buyerEmail): ?array
    {
        $open = Order::withoutMasjidScope()
            ->where('masjid_id', $cart->masjid_id)
            ->where('cart_id', $cart->id)
            ->where('status', Order::STATUS_PENDING)
            ->whereNotNull('stripe_checkout_session_id')
            ->latest('id')
            ->first();

        if ($open === null) {
            return null;
        }

        // Past its own expiry, a page is closed on Stripe's side too. Stop selecting it
        // without asking Stripe — an account that has since become unreachable must not
        // leave this basket unable to check out ever again.
        if ($open->checkout_expires_at !== null && $open->checkout_expires_at->isPast()) {
            $this->markExpired($open);

            return null;
        }

        // Not this basket's page any more — different lines, answers, prices or payee:
        // close it and open a new one. The TOTAL alone would not do (see the class doc).
        $sameBasket = is_string($open->basket_fingerprint)
            && hash_equals($open->basket_fingerprint, $priced->chargeFingerprint())
            && hash_equals((string) $open->charge_account_id, $account);

        // The same basket for a different address is a changed basket too: the page is locked
        // to the address it opened with, and the order's is where the receipt goes, so a
        // corrected one must not be answered with the page (and the receipt) of the typo.
        $sameBuyer = self::emailKey($open->buyer_email) === self::emailKey($buyerEmail);

        if (! $sameBasket || ! $sameBuyer) {
            $this->closePage($open);

            return null;
        }

        $session = $this->readPage($open);

        if (($session['status'] ?? null) === 'open' && is_string($session['url'] ?? null)) {
            return ['order' => $open, 'url' => $session['url']];
        }

        if (($session['status'] ?? null) === 'complete') {
            // The shopper has paid and the webhook has not recorded it yet. Never
            // offer to take the money a second time.
            throw new CartCheckoutRefused('Your payment is being confirmed. Please wait a moment.');
        }

        // Expired, or unreachable (readPage marked it): open a fresh page.
        return null;
    }

    private function createPendingOrder(
        Cart $cart,
        PricedBasket $priced,
        string $account,
        ?string $buyerEmail,
        ?string $buyerName = null,
        ?string $buyerPhone = null,
    ): Order {
        // Frozen BEFORE anything is written: a line that cannot be frozen refuses the
        // whole checkout, and there is then no order to clean up.
        $snapshots = [];
        foreach ($priced->lines as $index => ['item' => $item, 'outcome' => $outcome]) {
            $snapshots[$index] = $this->snapshotFor($cart, $item, $outcome, $priced->currency);
        }

        $uuid = (string) Str::uuid();

        $order = Order::withoutMasjidScope()->create([
            'masjid_id' => $cart->masjid_id,
            'uuid' => $uuid,
            'order_number' => strtoupper(substr(str_replace('-', '', $uuid), 0, 8)),
            'cart_id' => $cart->id,
            'contact_id' => $cart->contact_id,
            'buyer_email' => self::usableEmail($buyerEmail),
            'buyer_name' => self::usableText($buyerName, self::BUYER_NAME_MAX),
            'buyer_phone' => self::usableText($buyerPhone, self::BUYER_PHONE_MAX),
            'status' => Order::STATUS_PENDING,
            'total_minor' => $priced->totalMinor,
            'fee_minor' => self::applicationFee($priced->totalMinor),
            'currency' => $priced->currency,
            'charge_account_id' => $account,
            'basket_fingerprint' => $priced->chargeFingerprint(),
            'charge_ref' => $priced->destinationIsLinked ? 'cref_' . Str::random(32) : null,
            // SAVED before Stripe is asked: a retry re-sends this key.
            'idempotency_key' => 'cart_order_' . Str::uuid(),
            'checkout_expires_at' => now()->addSeconds(self::PAGE_LIFETIME_SECONDS),
        ]);

        foreach ($priced->lines as $index => ['item' => $item, 'outcome' => $outcome]) {
            OrderItem::withoutMasjidScope()->create([
                'order_id' => $order->id,
                'masjid_id' => $order->masjid_id,
                'buyable_type' => $item->buyable_type,
                'buyable_id' => $item->buyable_id,
                'recorded_as' => $item->recorded_as,
                'label' => $outcome->label,
                'quantity' => $outcome->quantity,
                'unit_amount_minor' => $outcome->unitAmountMinor,
                'total_minor' => $outcome->totalMinor(),
                'currency' => $priced->currency,
                'payload' => $snapshots[$index]['payload'],
                'price_snapshot' => $snapshots[$index]['price_snapshot'],
                'cart_payload_hash' => PricedBasket::payloadHash($item->payload),
            ]);
        }

        // Stock, decided now: inside the cart lock and this transaction, after the order and its
        // lines exist and before a page is opened. It counts what OTHER pending orders hold (this
        // order is left out, or it would hold units against itself), and a shortfall rolls the order
        // back with everything else and opens no page.
        $shortfall = ProductStock::refusal((int) $cart->masjid_id, $priced, (int) $order->id);

        if ($shortfall !== null) {
            throw new CartCheckoutRefused($shortfall);
        }

        return $order;
    }

    /**
     * What settlement will write this line from, frozen at the moment the page is
     * opened (CartSettlementService reads these and asks nothing else about the line).
     *
     *  - form: `payload` is the line's answers. `price_snapshot` is
     *    FormPayment::quote() as it stands NOW, in the exact shape FormResponseWriter is
     *    handed. Its total must equal what the page will charge for the line, or the
     *    checkout is refused: a row written from a snapshot that disagrees with the
     *    charge would state the wrong amount for a ticket already paid for. It also
     *    carries `legacy_amount_due` and `entry_count`, which the row's legacy columns
     *    are written from (the writer would compute them from the live form).
     *  - meal: `payload` is {menu_item_id, meal_menu_id, name, pickup_at} — the menu id is
     *    kept because a deleted dish leaves nothing to find it from — and
     *    `price_snapshot` is the line in LunchOrderLines::price()'s shape, at the price
     *    charged.
     *  - donation: `price_snapshot` is {intended_minor}; `payload` carries the giver's
     *    zakat answer only when they gave one (ZakatDesignation stays the one place
     *    that is decided).
     *  - product: `payload` is null and `price_snapshot` is {product_id, variant_id,
     *    product_name, variant_label, unit_minor, quantity, total_minor}: everything
     *    settlement writes the sale from (CartSettlementService::settleProduct), so a
     *    renamed or removed product, a new price or a trashed size cannot change what was
     *    paid for. Nothing in it is personal data.
     *
     * @return array{payload: ?array<string,mixed>, price_snapshot: ?array<string,mixed>}
     *
     * @throws CartCheckoutRefused
     */
    private function snapshotFor(Cart $cart, CartItem $item, CartLineOutcome $outcome, string $currency): array
    {
        $answers = (array) ($item->payload ?? []);

        switch ($item->buyable_type) {
            case CartItem::TYPE_FORM:
                $form = Form::query()->where('masjid_id', $cart->masjid_id)->find($item->buyable_id);

                // online=true, coverFees=false: a basket is a card payment, and a form
                // that requires the payer to cover the fee is refused by the source.
                $quote = $form === null ? null : FormPayment::quote($form, $answers, false, true);

                if ($quote === null
                    || (int) $quote['total_minor'] !== $outcome->totalMinor()
                    || strtolower((string) $quote['currency']) !== strtolower($currency)) {
                    throw new CartCheckoutRefused('This basket could not be priced just now. Please try again.');
                }

                // The legacy decimal `amount_due` and `entry_count` the writer computes from
                // the LIVE form (price and tier of the moment it runs) — frozen here from the
                // same cleaned answers settlement will write, so a price edited before the
                // payment lands cannot restate what was paid (the confirmation e-mail states
                // it and FormInsights sums it).
                $schema = FormSchema::for($form);
                $clean = $schema->only($form->withoutUnusedPriceAnswers($answers));
                $quote['legacy_amount_due'] = $schema->amountDue($clean);
                $quote['entry_count'] = $schema->entryCount($clean);

                return ['payload' => $answers, 'price_snapshot' => $quote];

            case CartItem::TYPE_MEAL:
                $dish = MealMenuItem::withoutMasjidScope()->where('masjid_id', $cart->masjid_id)->find($item->buyable_id);

                if ($dish === null) {
                    throw new CartCheckoutRefused('This basket could not be priced just now. Please try again.');
                }

                $pickup = $answers['pickup_at'] ?? null;

                return [
                    'payload' => [
                        'menu_item_id' => (int) $dish->id,
                        'meal_menu_id' => (int) $dish->meal_menu_id,
                        'name' => (string) $dish->name,
                        'pickup_at' => is_string($pickup) && $pickup !== '' ? $pickup : null,
                    ],
                    'price_snapshot' => [
                        'meal_menu_item_id' => (int) $dish->id,
                        'item_name' => (string) $dish->name,
                        'unit_price_minor' => $outcome->unitAmountMinor,
                        'quantity' => $outcome->quantity,
                        'line_total_minor' => $outcome->totalMinor(),
                    ],
                ];

            case CartItem::TYPE_DONATION:
                return [
                    'payload' => is_bool($answers['zakat'] ?? null) ? ['zakat' => $answers['zakat']] : null,
                    'price_snapshot' => ['intended_minor' => $outcome->totalMinor()],
                ];

            case CartItem::TYPE_PRODUCT:
                $variant = ProductVariant::withoutMasjidScope()->where('masjid_id', $cart->masjid_id)->find($item->buyable_id);
                $product = $variant === null
                    ? null
                    : Product::withoutMasjidScope()->where('masjid_id', $cart->masjid_id)->find($variant->product_id);

                if ($variant === null || $product === null) {
                    throw new CartCheckoutRefused('This basket could not be priced just now. Please try again.');
                }

                return [
                    'payload' => null,
                    'price_snapshot' => [
                        'product_id' => (int) $product->id,
                        'variant_id' => (int) $variant->id,
                        'product_name' => (string) $product->name,
                        'variant_label' => (string) $variant->label,
                        'unit_minor' => $outcome->unitAmountMinor,
                        'quantity' => $outcome->quantity,
                        'total_minor' => $outcome->totalMinor(),
                    ],
                ];
        }

        // The pricer never lets an unknown type through as payable; this is the backstop.
        throw new CartCheckoutRefused('This basket could not be priced just now. Please try again.');
    }

    private function openPage(Order $order, PricedBasket $priced, string $account, string $returnBase, ?string $buyerEmail): string
    {
        $lineItems = [];
        $sum = 0;

        foreach ($priced->lines as ['outcome' => $outcome]) {
            $lineItems[] = [
                'quantity' => $outcome->quantity,
                'price_data' => [
                    'currency' => $priced->currency,
                    'unit_amount' => $outcome->unitAmountMinor,
                    'product_data' => ['name' => $outcome->label],
                ],
            ];
            $sum += $outcome->totalMinor();
        }

        // The page must charge exactly what the order recorded, or the webhook's
        // amount check would later refuse a payment the shopper already made.
        if ($sum !== (int) $order->total_minor || $sum < 1) {
            throw new LogicException("Order {$order->id}: lines sum to {$sum}, the order says {$order->total_minor}.");
        }

        if ($priced->destinationIsLinked) {
            // On the HOLDER's account: its Stripe users read this metadata. An opaque
            // reference only — never the uuid (the status read's bearer handle), never
            // a masjid id, no client_reference_id — and a description naming the
            // organisation actually selling, as FormResponseCheckoutService does.
            $metadata = [self::CHARGE_REF_KEY => (string) $order->charge_ref];
            $org = Masjid::withTrashed()->find($order->masjid_id);
            $paymentIntentData = [
                'metadata' => $metadata,
                'description' => ($org?->name ?? 'Order') . ' — order ' . $order->order_number,
            ];
            $suffix = FormResponseCheckoutService::statementSuffix($org?->name);
            if ($suffix !== null) {
                $paymentIntentData['statement_descriptor_suffix'] = $suffix;
            }
        } else {
            $metadata = [self::METADATA_KEY => (string) $order->uuid, 'masjid_id' => (string) $order->masjid_id];
            $paymentIntentData = ['metadata' => $metadata];
        }

        $fee = (int) $order->fee_minor;
        if ($fee > 0) {
            $paymentIntentData['application_fee_amount'] = $fee;
        }

        $query = http_build_query([self::METADATA_KEY => (string) $order->uuid]);

        $params = [
            'mode' => 'payment',
            // Card only: a delayed method would hold tickets and dishes while it
            // cleared, and nothing here can release them yet.
            'payment_method_types' => ['card'],
            // Adaptive Pricing OFF, on the org's own account and a holder's alike. Switched
            // on in a Stripe dashboard it would show and report a converted price, and on the
            // pinned API version (2024-06-20) the payment intent then carries the presentment
            // currency: a basket paid and recorded from the session, but with a false
            // "did not match, refund it" warning from the intent. As the linked form page does.
            'adaptive_pricing' => ['enabled' => false],
            'line_items' => $lineItems,
            'payment_intent_data' => $paymentIntentData,
            'metadata' => $metadata,
            'expires_at' => $order->checkout_expires_at->getTimestamp(),
            // The uuid rides in the payer's own return URL, as forms' does — never in
            // metadata a holder's staff can read.
            'success_url' => "{$returnBase}?{$query}&paid=1",
            'cancel_url' => "{$returnBase}?{$query}&cancelled=1",
        ];

        if (! $priced->destinationIsLinked) {
            $params['client_reference_id'] = (string) $order->uuid;
        }

        $email = self::usableEmail($buyerEmail);
        if ($email !== null) {
            $params['customer_email'] = $email;
            // Stripe's own itemised receipt, to the buyer, on every basket. It is a direct charge on
            // the organisation's account, so without this a receipt goes out only if that account
            // happens to have "successful payments" emails switched on; named here, Stripe sends it
            // in live mode whatever the account's setting. It is the one receipt that lists a mixed
            // basket line by line, and for a shop line it is the only one (settlement mails nothing
            // for a product). A gift in the basket also gets its own DonationReceiptMail: accepted.
            $params['payment_intent_data']['receipt_email'] = $email;
        }

        try {
            $session = $this->createCheckoutSession($params, $account, (string) $order->idempotency_key);
        } catch (InvalidRequestException $e) {
            // Retry ONLY when Stripe named the address, in either place it is sent. Any other
            // refusal (an amount, a parameter) would fail the same way again — and the message
            // is never logged, because Stripe's quotes the address.
            if (! isset($params['customer_email']) || ! in_array($e->getStripeParam(), self::EMAIL_PARAMS, true)) {
                throw $e;
            }

            // The page still opens, without the address in either place: the shopper types it on
            // Stripe's page, and the receipt then follows the account's own setting.
            unset($params['customer_email'], $params['payment_intent_data']['receipt_email']);
            // A NEW key: a refused key belongs to its parameters.
            $order->forceFill(['idempotency_key' => 'cart_order_' . Str::uuid()])->save();
            $session = $this->createCheckoutSession($params, $account, (string) $order->idempotency_key);
        }

        $order->forceFill(['stripe_checkout_session_id' => $session['id']])->save();

        if (! is_string($session['url'] ?? null)) {
            throw new CartCheckoutRefused('The payment page could not be opened. Please try again.');
        }

        return $session['url'];
    }

    /**
     * Close a page that is not this basket's any more. If Stripe refuses the expire,
     * the payer may have just won the race — read it again: paid means "confirming",
     * never a second page.
     */
    private function closePage(Order $order): void
    {
        $session = $this->readPage($order);

        if (($session['status'] ?? null) === 'complete') {
            throw new CartCheckoutRefused('Your payment is being confirmed. Please wait a moment.');
        }

        if (($session['status'] ?? null) !== 'open') {
            $this->markExpired($order);

            return;
        }

        try {
            $this->expireCheckoutSession((string) $order->stripe_checkout_session_id, (string) $order->charge_account_id);
        } catch (Throwable $e) {
            if (FormResponseCheckoutService::isUnreachable($e)) {
                $this->markExpired($order);

                return;
            }

            if (! $e instanceof ApiErrorException) {
                throw $e;
            }

            $again = $this->readPage($order);

            if (($again['status'] ?? null) === 'complete') {
                throw new CartCheckoutRefused('Your payment is being confirmed. Please wait a moment.');
            }

            if (($again['status'] ?? null) === 'open') {
                throw new CartCheckoutRefused('The previous payment page could not be closed. Please try again in a moment.');
            }
        }

        $this->markExpired($order);
    }

    /**
     * Read a page's status. An account the platform can no longer reach (disconnected,
     * or the pin is stale) cannot be asked, so its page is treated as closed rather than
     * blocking this basket forever.
     *
     * @return array{status: ?string, url: ?string}
     */
    private function readPage(Order $order): array
    {
        try {
            $session = $this->retrieveCheckoutSession((string) $order->stripe_checkout_session_id, (string) $order->charge_account_id);
        } catch (Throwable $e) {
            if (! FormResponseCheckoutService::isUnreachable($e)) {
                throw $e;
            }

            $this->markExpired($order);

            return ['status' => 'expired', 'url' => null];
        }

        if (($session['status'] ?? null) === 'expired') {
            $this->markExpired($order);
        }

        return $session;
    }

    /**
     * Stop selecting this order as the basket's open page. Pending → expired only:
     * a paid order is never touched here — only the webhook ever sets or reads "paid".
     */
    private function markExpired(Order $order): void
    {
        Order::withoutMasjidScope()
            ->whereKey($order->id)
            ->where('status', Order::STATUS_PENDING)
            ->update(['status' => Order::STATUS_EXPIRED]);

        $this->expiredHere[] = (int) $order->id;
    }

    /**
     * After a checkout attempt failed: what markExpired() did inside its transaction was rolled
     * back, but the page it found dead stays dead on Stripe (expired by closePage(), or already
     * expired). Mark those orders expired again, outside the transaction, with the same guard
     * (pending → expired only, never a paid order). Otherwise a dead page reads as `pending` and
     * holds its sizes' units until its expiry plus the grace.
     */
    private function keepExpired(): void
    {
        $ids = array_values(array_unique($this->expiredHere));
        $this->expiredHere = [];

        if ($ids === []) {
            return;
        }

        Order::withoutMasjidScope()
            ->whereKey($ids)
            ->where('status', Order::STATUS_PENDING)
            ->update(['status' => Order::STATUS_EXPIRED]);
    }

    /**
     * The buyer's name and phone on a page that is being handed back, as the shopper typed them
     * this time. Only what was given: an absent field never blanks what the order already holds.
     */
    private function refreshBuyer(Order $order, ?string $name, ?string $phone): void
    {
        $changes = array_filter([
            'buyer_name' => self::usableText($name, self::BUYER_NAME_MAX),
            'buyer_phone' => self::usableText($phone, self::BUYER_PHONE_MAX),
        ], static fn (?string $value): bool => $value !== null);

        if ($changes !== []) {
            $order->forceFill($changes)->save();
        }
    }

    /** A name or phone the shopper typed, trimmed and cut to its column, or null when there is none. */
    private static function usableText(?string $text, int $max): ?string
    {
        $text = is_string($text) ? trim($text) : '';

        return $text === '' ? null : mb_substr($text, 0, $max);
    }

    /**
     * What an address is compared by: the one the page would carry, trimmed and lower-cased, or
     * an empty string when there is none. Both sides go through it, so an order opened with no
     * usable address matches a call that has none.
     */
    private static function emailKey(?string $email): string
    {
        return strtolower((string) self::usableEmail($email));
    }

    /** A plausible address, or null — an obviously bad one would make Stripe refuse the page. */
    private static function usableEmail(?string $email): ?string
    {
        $email = is_string($email) ? trim($email) : '';

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return str_contains(substr($email, strrpos($email, '@') + 1), '.') ? $email : null;
    }

    private static function assertConnectedAccount(string $connectedAccountId): void
    {
        if (! FormChargeAccount::isAccount($connectedAccountId)) {
            throw new LogicException('A cart payment page is only ever opened, read or closed on a connected account.');
        }
    }

    // ---- The three Stripe seams, overridden in tests (as the siblings are). ----

    /** @return array{id: ?string, url: ?string, payment_intent: ?string} */
    protected function createCheckoutSession(array $params, string $connectedAccountId, string $idempotencyKey): array
    {
        self::assertConnectedAccount($connectedAccountId);

        $session = $this->stripe->checkout->sessions->create($params, [
            'stripe_account' => $connectedAccountId,
            'idempotency_key' => $idempotencyKey,
        ]);

        $pi = $session->payment_intent ?? null;

        return [
            'id' => $session->id ?? null,
            'url' => $session->url ?? null,
            'payment_intent' => is_string($pi) ? $pi : ($pi->id ?? null),
        ];
    }

    /** @return array{status: ?string, url: ?string} */
    protected function retrieveCheckoutSession(string $sessionId, string $connectedAccountId): array
    {
        self::assertConnectedAccount($connectedAccountId);

        $session = $this->stripe->checkout->sessions->retrieve($sessionId, [], ['stripe_account' => $connectedAccountId]);

        return ['status' => $session->status ?? null, 'url' => $session->url ?? null];
    }

    protected function expireCheckoutSession(string $sessionId, string $connectedAccountId): void
    {
        self::assertConnectedAccount($connectedAccountId);

        $this->stripe->checkout->sessions->expire($sessionId, [], ['stripe_account' => $connectedAccountId]);
    }
}
