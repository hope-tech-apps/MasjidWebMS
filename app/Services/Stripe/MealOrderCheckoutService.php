<?php

namespace App\Services\Stripe;

use App\Models\Masjid;
use App\Models\MealMenu;
use App\Models\MealOrder;
use App\Support\LunchOrderExtras;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Stripe\StripeClient;
use Throwable;

/**
 * The OUTBOUND Stripe leg of an online meal order — a deliberate, trimmed
 * sibling of RegistrationCheckoutService (which is itself a sibling of the
 * locked DonationService). Same doctrine, one-time payments only:
 *
 *   - Stripe Connect STANDARD account + DIRECT charge: the hosted Checkout
 *     Session is created ON the org's connected account (`stripe_account`
 *     option). Funds land in the ORG's balance; the org is merchant of record.
 *   - The platform takes only `application_fee_amount`, sent ONLY when > 0.
 *   - PCI SAQ A: the card is entered on Stripe's hosted page; this app never
 *     renders a card form.
 *   - Money is integer minor units (the order's `total_minor` snapshot).
 *   - **The webhook is the source of truth.** Nothing here marks the order paid;
 *     the row stays `unpaid` until a signature-verified event lands
 *     (MealOrderPaymentService). The returned URL is a redirect, not a promise.
 *
 * `order_uuid` (distinct from `donation_uuid` / `registration_uuid`) is the
 * routing key, carried on BOTH the session and the payment intent so either
 * event alone resolves the order.
 *
 * The live API is reached only through thin protected seams (create, expire,
 * retrieve a Checkout Session), so tests stub them and never reach Stripe.
 */
class MealOrderCheckoutService
{
    private const PAID_ON_STRIPE = 'This order has been paid on Stripe. The board will show it as paid in a moment.';

    private const CLEARING_ON_STRIPE = 'A bank payment for this order is still clearing. The board will show it as paid when it lands; if it fails, you can send a new link.';

    private const CANCELLED = 'This order was cancelled. Set it back to confirmed first.';

    /** Mark paid's refusals (closePageBeforePaidByHand): nothing was recorded. */
    private const PAID_BY_CARD = 'This order was already paid by card online. It will show as paid in a moment.';

    private const CLEARING_NOT_MARKED = 'A bank payment for this order is still clearing on Stripe, so it was not marked paid. It will show as paid when the money lands; if the payment fails, you can mark it paid then.';

    private const LINK_NOT_CLOSED = 'Stripe would not close this order\'s card payment link, so it was not marked paid. Refresh the board and try again.';

    private const NO_ACCOUNT = 'This organisation\'s Stripe account is not on record, so this order\'s card payment link could not be checked and nothing was recorded.';

    public function __construct(private StripeClient $stripe)
    {
    }

    /** The platform's application fee on a charge, integer minor units. */
    public static function applicationFee(int $chargedMinor, ?float $platformPct = null): int
    {
        $platformPct ??= (float) config('services.stripe.platform_fee_percentage', 0);

        return (int) round($chargedMinor * $platformPct);
    }

    /**
     * Open the hosted Checkout Session for a pending online order; return its URL.
     *
     * On the LOCKED row, the first page as much as every later one (paymentLink()),
     * the rule the forms sibling's onLockedRow() follows: the order is read again
     * under the lock and every refusal is asked there. An order marked paid by hand
     * (MealOrdersController::markPaid) or cancelled after the caller read it gets
     * no page, and a Mark paid that arrives while a page is being made waits on the
     * lock, then finds the page and closes it. Until the 2026-09-11 review the first
     * page was made on the caller's copy after its write had committed, so a hand
     * payment in between left a paid order holding a live page.
     *
     * A page recorded while this waited for the lock (Payment link, pressed on the
     * brand-new order) is the answer, never a second payable one.
     *
     * The caller's copy is brought up to the row as the lock found it, whichever
     * way this ends: a refusal is answered beside what the row really says (the
     * public page serialises it), never the unpaid copy read before the lock.
     *
     * @param  array{success_url?:string,cancel_url?:string}  $options
     * @return array{order: MealOrder, checkout_url: string, session_id: ?string}
     */
    public function checkout(MealOrder $order, array $options = []): array
    {
        $locked = null;

        try {
            $result = DB::transaction(function () use ($order, $options, &$locked): array {
                $row = MealOrder::withoutMasjidScope()->with('items')->lockForUpdate()->findOrFail($order->id);
                $locked = $row->getAttributes();
                $masjid = $this->preflight($row);

                if ($row->status === MealOrder::STATUS_CANCELLED) {
                    throw new RuntimeException(self::CANCELLED);
                }

                if ($row->stripe_checkout_session_id) {
                    return $this->paymentLink($row);
                }

                return $this->openPage($row, $masjid, $options);
            });
        } catch (Throwable $e) {
            // Rolled back, so the row as locked is the row as stored.
            if ($locked !== null) {
                $order->setRawAttributes($locked, true);
            }

            throw $e;
        }

        $order->setRawAttributes($result['order']->getAttributes(), true);

        return $result;
    }

    /**
     * A new page on the LOCKED row (checkout(), paymentLink()): the idempotency key
     * persisted BEFORE the call, and only the session id recorded after it.
     *
     * @param  array{success_url?:string,cancel_url?:string}  $options
     * @return array{order: MealOrder, checkout_url: string, session_id: ?string}
     */
    private function openPage(MealOrder $order, Masjid $masjid, array $options = []): array
    {
        // Persist the idempotency key BEFORE talking to Stripe, so a retried
        // request re-sends the same key and Stripe returns the same Session
        // rather than opening a second one.
        $idempotencyKey = $order->idempotency_key ?: ('meal_order_' . Str::uuid());
        $order->idempotency_key = $idempotencyKey;
        $order->save();

        $currency = strtolower((string) ($order->currency ?: config('services.stripe.currency', 'usd')));

        $metadata = [
            'order_uuid' => $order->uuid,
            'masjid_id' => (string) $order->masjid_id,
        ];

        // Itemised so the hosted page shows the breakdown. The line items sum to
        // total_minor by construction (each food line = unit_price × quantity,
        // plus the optional donation line added below).
        $lineItems = [];
        foreach ($order->items as $item) {
            $lineItems[] = [
                'quantity' => (int) $item->quantity,
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => (int) $item->unit_price_minor,
                    'product_data' => ['name' => (string) $item->item_name],
                ],
            ];
        }

        // The optional extra rides on the SAME charge, as its own line so the
        // hosted page names it rather than inflating the price of a plate — and
        // so the line items keep summing to total_minor, which is what the
        // application fee is computed from.
        if ((int) $order->donation_minor > 0) {
            $lineItems[] = [
                'quantity' => 1,
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => (int) $order->donation_minor,
                    'product_data' => ['name' => 'Additional donation'],
                ],
            ];
        }

        // Named, for the same reason as the extra: the customer agreed to a
        // processing fee, so the hosted page says so rather than quietly
        // inflating the food.
        if ((int) $order->fee_covered_minor > 0) {
            $lineItems[] = [
                'quantity' => 1,
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => (int) $order->fee_covered_minor,
                    'product_data' => ['name' => 'Card processing fee'],
                ],
            ];
        }

        if ($lineItems === []) {
            // Defensive: an order always has items, but never mint a $0 session.
            $lineItems[] = [
                'quantity' => 1,
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => (int) $order->total_minor,
                    'product_data' => ['name' => 'Jummah Lunch order'],
                ],
            ];
        }

        $paymentIntentData = ['metadata' => $metadata];
        $fee = self::applicationFee((int) $order->total_minor);
        if ($fee > 0) {
            $paymentIntentData['application_fee_amount'] = $fee;
        }

        $base = rtrim((string) config('app.url'), '/');
        $orderUrl = $base . '/jummah-lunch/' . $order->masjid_id . '/order/' . $order->uuid;

        $params = [
            'mode' => 'payment',
            'client_reference_id' => $order->uuid,
            'metadata' => $metadata,
            'line_items' => $lineItems,
            'payment_intent_data' => $paymentIntentData,
            'success_url' => $options['success_url']
                ?? ($orderUrl . '?paid=1&session_id={CHECKOUT_SESSION_ID}'),
            'cancel_url' => $options['cancel_url']
                ?? ($orderUrl . '?cancelled=1'),
        ];

        $session = $this->createCheckoutSession(
            $params,
            (string) $masjid->stripe_account_id,
            $idempotencyKey
        );

        // Record the handle only — a session is a redirect, not a payment.
        if (! empty($session['id'])) {
            $order->stripe_checkout_session_id = $session['id'];
            $order->save();
        }

        return [
            'order' => $order,
            'checkout_url' => (string) ($session['url'] ?? ''),
            'session_id' => $session['id'] ?? null,
        ];
    }

    /**
     * The payment page for an unpaid order, for staff to open or send on. An
     * open page is handed back as it is, so a customer never holds two live
     * ways to pay the same order; an expired page is replaced (with a new
     * idempotency key, or Stripe would replay the old one); a completed page
     * is left to the webhook to record.
     *
     * `$choices` is what staff picked in the dialog: the extra, and whether the
     * card fee is covered, each null when staff were not asked. They are priced
     * HERE, on the locked row (LunchOrderExtras::forExistingOrder), so a choice
     * left out keeps what the order carries now, not when the request was read.
     *
     * @param  array{donation_minor: ?int, cover_fees: ?bool}|null  $choices
     * @return array{order: MealOrder, checkout_url: string, session_id: ?string}
     */
    public function paymentLink(MealOrder $order, ?array $choices = null): array
    {
        // Serialised on the order row: when two people press "Payment link" at
        // once, the second waits here, then finds the first one's open session
        // and gets that same page — never a second payable one. Cancelling and
        // Mark paid take the same lock (MealOrdersController).
        return DB::transaction(function () use ($order, $choices) {
            $order = MealOrder::withoutMasjidScope()->with('items')->lockForUpdate()->findOrFail($order->id);
            $masjid = $this->preflight($order);

            // Checked under the lock, not only by the controller: a cancel that
            // committed while this request waited must stop a new page being made.
            if ($order->status === MealOrder::STATUS_CANCELLED) {
                throw new RuntimeException(self::CANCELLED);
            }

            $amounts = $choices === null ? null : LunchOrderExtras::forExistingOrder(
                MealMenu::withoutMasjidScope()->findOrFail($order->meal_menu_id),
                $order,
                $choices['donation_minor'] ?? null,
                $choices['cover_fees'] ?? null,
            );

            // A different amount means a different page: the open one is closed
            // FIRST, so the customer can never pay the old amount as well as the new.
            $reprice = $amounts !== null
                && ((int) $order->donation_minor !== (int) $amounts['donation_minor']
                    || (int) $order->fee_covered_minor !== (int) $amounts['fee_covered_minor']);

            // A page is paid online, so a pay-at-pickup order becomes an online one
            // now, on the locked row. Mark paid takes the same lock and closes this
            // page before it records money taken by hand (closePageBeforePaidByHand),
            // so cash and a card payment can never both be taken for one order.
            $order->payment_method = MealOrder::METHOD_ONLINE;

            if ($order->stripe_checkout_session_id) {
                $session = $this->retrieveCheckoutSession(
                    (string) $order->stripe_checkout_session_id,
                    (string) $masjid->stripe_account_id
                );

                if ($session['status'] === 'complete') {
                    // A bank debit completes its page `unpaid`: the money is on
                    // its way, not here, and a second page could take it twice.
                    throw new RuntimeException(($session['payment_status'] ?? null) === 'unpaid'
                        ? self::CLEARING_ON_STRIPE
                        : self::PAID_ON_STRIPE);
                }

                if ($session['status'] === 'open' && $session['url'] && ! $reprice) {
                    $order->save();

                    return [
                        'order' => $order,
                        'checkout_url' => (string) $session['url'],
                        'session_id' => $order->stripe_checkout_session_id,
                    ];
                }

                if ($session['status'] === 'open'
                    && $this->closeSession($order, (string) $masjid->stripe_account_id) === 'complete') {
                    throw new RuntimeException(self::PAID_ON_STRIPE);
                }

                $order->stripe_checkout_session_id = null;
            }

            if ($reprice) {
                $order->donation_minor = (int) $amounts['donation_minor'];
                $order->fee_covered_minor = (int) $amounts['fee_covered_minor'];
                $order->total_minor = (int) $order->subtotal_minor + $order->donation_minor + $order->fee_covered_minor;
            }

            // A fresh key whenever no live page exists. A key saved by an attempt
            // that never recorded a session would otherwise be replayed: Stripe
            // repeats a saved failure for 24h, and rejects the key outright when
            // the parameters differ (the public page's own return URLs). That
            // attempt handed nobody a page, so a new key cannot create a second
            // payable one — and the row lock above rules out a concurrent one.
            $order->idempotency_key = null;
            $order->save();

            return $this->openPage($order, $masjid);
        });
    }

    /**
     * Close an unpaid order's open payment page, so a cancelled order does not
     * stay payable. Returns Stripe's status for the page: 'expired' once closed
     * (or already), 'complete' when the customer paid first — even a moment
     * before the close landed — which the webhook records; null when there is
     * no page to close.
     *
     * @throws RuntimeException when the page could not be closed and may still be payable
     */
    public function expireOpenSession(MealOrder $order): ?string
    {
        if (! $order->stripe_checkout_session_id || $order->payment_status === MealOrder::PAYMENT_PAID) {
            return null;
        }

        $account = (string) Masjid::find($order->masjid_id)?->stripe_account_id;
        if ($account === '') {
            return null;
        }

        $session = $this->retrieveCheckoutSession((string) $order->stripe_checkout_session_id, $account);
        if ($session['status'] !== 'open') {
            return $session['status'];
        }

        return $this->closeSession($order, $account);
    }

    /**
     * Before staff record money taken by hand (MealOrdersController::markPaid),
     * make sure the order's own payment page cannot take a card payment as well.
     * Call it on the LOCKED row, inside the transaction that records the payment:
     * checkout() and paymentLink() take the same lock, so no page can be made in
     * between, the first one included.
     *
     *   - no page on record: nothing to ask;
     *   - open: closed first. A close Stripe refuses is asked about again, as
     *     closeSession() does, because the customer may have just paid;
     *   - complete and paid: refused, and the webhook records the card payment.
     *     Logged too: if it never lands, the webhook is not arriving;
     *   - complete and unpaid: a bank payment is clearing, so refused. It lands or
     *     fails through the webhook, and a failure forgets the page, after which
     *     the order can be marked paid by hand;
     *   - expired: nothing can be paid on it.
     *
     * A closed or expired page is forgotten on the row (saved inside the caller's
     * transaction), so nothing reopens it. A refusal changes nothing here.
     *
     * @throws RuntimeException when the order must not be marked paid by hand
     * @throws \Stripe\Exception\ExceptionInterface when Stripe did not answer
     */
    public function closePageBeforePaidByHand(MealOrder $order): void
    {
        if (! $order->stripe_checkout_session_id) {
            return;
        }

        // With no account on record there is nobody to ask about a page that may
        // still be payable, so it is refused like a Stripe that did not answer.
        $account = (string) Masjid::find($order->masjid_id)?->stripe_account_id;
        if ($account === '') {
            throw new RuntimeException(self::NO_ACCOUNT);
        }

        $session = $this->retrieveCheckoutSession((string) $order->stripe_checkout_session_id, $account);

        if ($session['status'] === 'open') {
            $session = $this->closeAndSee($order, $account);
        }

        if ($session['status'] === 'complete') {
            // `payment_status`, never `status` alone: a bank debit completes its page unpaid.
            if (($session['payment_status'] ?? null) === 'unpaid') {
                throw new RuntimeException(self::CLEARING_NOT_MARKED);
            }

            // Stripe holds a card payment this order does not. Normally the webhook
            // is seconds behind; a Connect endpoint that stopped delivering (one on a
            // redirecting apex once did, here) looks exactly like this for good, and
            // this is the one moment the server holds the proof. At warning, the level
            // production runs at, by ids only.
            Log::warning('Stripe reports a meal order\'s payment page as paid while the order is unpaid here, so Mark paid was refused. If it still shows unpaid in a few minutes, the Stripe webhook is not arriving.', [
                'order_uuid' => $order->uuid,
                'masjid_id' => (int) $order->masjid_id,
                'checkout_session_id' => (string) $order->stripe_checkout_session_id,
            ]);

            throw new RuntimeException(self::PAID_BY_CARD);
        }

        if ($session['status'] !== 'expired') {
            throw new RuntimeException(self::LINK_NOT_CLOSED);
        }

        $order->stripe_checkout_session_id = null;
        $order->save();
    }

    /**
     * Close an open page: 'expired' once closed, or 'complete' when Stripe
     * refused because the customer had just paid. The two are told apart by
     * asking Stripe again, because "refund it" and "the link still works" are
     * different instructions for staff.
     *
     * @throws RuntimeException when the page is still open after the refusal
     */
    private function closeSession(MealOrder $order, string $account): string
    {
        $now = $this->closeAndSee($order, $account)['status'];

        if ($now === 'complete' || $now === 'expired') {
            return $now;
        }

        throw new RuntimeException('That payment page could not be closed. Refresh the board and try again.');
    }

    /**
     * Close an open page and say what Stripe holds afterwards: 'expired' once it
     * closed; after a refused close, the page as Stripe reports it when asked
     * again, with its payment_status, so a card payment and a bank debit still
     * clearing can be told apart.
     *
     * @return array{status: ?string, payment_status: ?string}
     */
    private function closeAndSee(MealOrder $order, string $account): array
    {
        $sessionId = (string) $order->stripe_checkout_session_id;

        try {
            $this->expireCheckoutSession($sessionId, $account);

            return ['status' => 'expired', 'payment_status' => null];
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            $now = $this->retrieveCheckoutSession($sessionId, $account);

            return ['status' => $now['status'] ?? null, 'payment_status' => $now['payment_status'] ?? null];
        }
    }

    /**
     * Every reason this order may NOT be charged, stated once. Writes nothing.
     *
     * @throws RuntimeException
     */
    private function preflight(MealOrder $order): Masjid
    {
        if ($order->payment_status === MealOrder::PAYMENT_PAID) {
            throw new RuntimeException('This order has already been paid.');
        }

        if ((int) $order->total_minor <= 0) {
            throw new RuntimeException('This order has nothing to pay.');
        }

        $masjid = Masjid::find($order->masjid_id);

        // Connect onboarding must be complete — a direct charge has nowhere to
        // land otherwise (the same gate the donation path uses).
        if (! $masjid || ! $masjid->canAcceptDonations()) {
            throw new RuntimeException('This organization is not able to accept online payments yet.');
        }

        return $masjid;
    }

    /**
     * Create the Checkout Session as a DIRECT charge on the connected account.
     *
     * @return array{id:?string,url:?string,payment_intent:?string}
     */
    protected function createCheckoutSession(
        array $params,
        string $connectedAccountId,
        string $idempotencyKey
    ): array {
        $session = $this->stripe->checkout->sessions->create($params, [
            'stripe_account' => $connectedAccountId,
            'idempotency_key' => $idempotencyKey,
        ]);

        return [
            'id' => $session->id,
            'url' => $session->url,
            'payment_intent' => is_string($session->payment_intent)
                ? $session->payment_intent
                : ($session->payment_intent?->id ?? null),
        ];
    }

    protected function expireCheckoutSession(string $sessionId, string $connectedAccountId): void
    {
        $this->stripe->checkout->sessions->expire($sessionId, [], ['stripe_account' => $connectedAccountId]);
    }

    /** @return array{status: string, payment_status: ?string, url: ?string} Stripe's 'open' | 'complete' | 'expired'; a bank debit's page is 'complete' and 'unpaid' while it clears. */
    protected function retrieveCheckoutSession(string $sessionId, string $connectedAccountId): array
    {
        $session = $this->stripe->checkout->sessions->retrieve($sessionId, [], [
            'stripe_account' => $connectedAccountId,
        ]);

        return [
            'status' => (string) $session->status,
            'payment_status' => is_string($session->payment_status ?? null) ? $session->payment_status : null,
            'url' => $session->url,
        ];
    }
}
