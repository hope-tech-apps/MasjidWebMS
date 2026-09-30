<?php

namespace App\Services\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Contact;
use App\Models\Donation;
use App\Models\Form;
use App\Models\FormResponse;
use App\Models\Fund;
use App\Models\Masjid;
use App\Models\MealMenu;
use App\Models\MealMenuItem;
use App\Models\MealOrder;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Crm\DonorContactService;
use App\Services\Forms\FormResponseWriter;
use App\Services\Lunch\LunchOrderMailer;
use App\Services\Lunch\MealOrderCreator;
use App\Services\Receipts\ReceiptService;
use App\Services\Stripe\DonationService;
use App\Support\FormNotifier;
use App\Support\FormSchema;
use App\Support\GivingSwitch;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

/**
 * Turns a PAID basket into its real records (universal cart, slice 4b; design §12).
 *
 * Records are created ONLY ONCE PAID (owner decision, 2026-09-28): nothing is on the
 * kitchen board, the form responses list or the donations list until the cart's one
 * payment has landed, and no ticket can be paid for twice. So this is where each line's
 * record is BORN, already paid, by the three writers that exist for exactly this
 * (FormResponseWriter, MealOrderCreator, DonationService::createPendingDonation), which
 * are called unchanged and re-check no gate. The money has been taken, so:
 *
 *   A payment that lands after a form closed, a form filled, a menu closed or a fund
 *   was deactivated is STILL recorded. Refusing would turn taken money into a
 *   payment with no record. An oversell is logged at warning, never refused.
 *
 * ## One transaction
 *
 * Everything below happens in ONE outer `DB::transaction` on the default connection (the
 * writers check `DB::transactionLevel()` there, and join it):
 *
 *   1. lock the order row; if it is already paid, return (a DIFFERENT payment intent
 *      on a paid order is a double charge, logged and never recorded over the first);
 *   2. check the paid amount and currency against the order's snapshot; on a mismatch
 *      log a warning, leave the order pending and settle NOTHING;
 *   3. mark the order paid and record its payment intent, once;
 *   4. for each line WITHOUT a `record_id` (the per-line idempotency), write the record
 *      from the snapshot taken at checkout, settle it, and link `record_type`/`record_id`;
 *   5. take the lines this order paid for out of the basket (matched by type, id and
 *      the canonical payload hash), and close it (`Cart::STATUS_CHECKED_OUT`) only when
 *      nothing is left, so the same lines can never be checked out and charged a second
 *      time and a line added meanwhile is never dropped unpaid. The order's lines are
 *      the snapshot; the cart is locked BEFORE the order (checkout's own lock order).
 *      After the commit, the basket's OTHER pending pages are expired (closeOtherPages()).
 *
 * ## The session event after the payment intent's
 *
 * Stripe does not order its events. When `payment_intent.succeeded` settles the order
 * first it carries no payer (no session id, no `customer_details`), so a guest basket is
 * recorded with what the buyer typed at the basket page (the order's `buyer_email`,
 * `buyer_name`, `buyer_phone`: the public endpoints always collect them), and an order opened
 * without them (a late or legacy one) with an anonymous gift and a placeholder meal
 * customer. The session event that
 * follows finds the order PAID and settles nothing, but it BACKFILLS what the intent could
 * not know (backfillLocked()): the donation's contact and session id, and a meal order's
 * placeholder name, phone and e-mail. Then the steps the first settlement had to skip —
 * the donor link, the receipt and its delivery, the meal confirmation — run, once: each
 * is gated on the record still lacking what it needs, and the mailers claim their own
 * sends. Nothing is re-settled.
 *
 * A failure on any line rolls back ALL of it, order included, and is rethrown: the
 * webhook answers 500 and Stripe retries, which is what a paid basket that could not be
 * recorded needs (the order is still pending, so the retry starts clean, and the
 * per-line keys mean it can never write a line twice). Refusals — an unknown account, a
 * uuid outside its organisation, a mismatch — are NOT failures: they are logged at
 * warning by the caller and return normally, because a retry could never succeed.
 *
 * ## Nothing is re-asked at settlement
 *
 * The lines are written from `order_items.payload` and `price_snapshot`, frozen at
 * checkout. A form is never re-quoted here: `FormPayment::quote()` depends on the date
 * (early-bird tiers) and on the card switch, and a null quote would throw after the money
 * was taken. A dish deleted since checkout keeps its snapshot name and loses only its item
 * id; a menu soft-deleted since is read `withTrashed`.
 *
 * ## After the commit, and only for what this call settled
 *
 * The form notification, the lunch confirmation and the donation receipt are built as
 * closures INSIDE the transaction and run only once it has returned (afterCommit()), and
 * only for a line whose settle call returned true (the unpaid-to-paid transition; the
 * mailers claim their own sends besides). Never e-mail from inside the transaction: a
 * rollback would leave an email for nothing. Each step is isolated — one failing after
 * the money is recorded is logged and skipped, never allowed to fail the webhook, whose
 * retry would find the order paid and return early.
 *
 * The donation's `stripe_fee_amount` and `net_amount` stay NULL: the basket's payment
 * intent carries ONE fee that cannot be split honestly per line, and a guessed figure
 * would be a fabricated number in a financial ledger.
 *
 * Runs UNBOUND (the webhook has no tenant), so every read filters `masjid_id` explicitly.
 */
class CartSettlementService
{
    private const NOT_SETTLED = ['settled' => false, 'steps' => []];

    public function __construct(
        private readonly FormResponseWriter $forms,
        private readonly MealOrderCreator $meals,
        private readonly DonationService $donations,
        private readonly ReceiptService $receipts,
        private readonly DonorContactService $donorContacts,
        private readonly LunchOrderMailer $lunchMail,
        // Optional so a subclass built with the six older arguments still works (closeOtherPages()
        // falls back to the container). The container fills it.
        private readonly ?CartCheckoutService $checkout = null,
    ) {}

    /**
     * Settle one order from a verified payment.
     *
     * The caller (CartPaymentService) has already established WHOSE order this is from
     * the event's connected account, and that the session was paid. This method owns
     * everything from the row lock onward. Call it outside any transaction of your own,
     * or the steps that follow the commit run before the real one.
     *
     * @param  int|null  $amountMinor  what Stripe says was paid, in minor units
     * @param  array<string,mixed>  $customerDetails  the session's customer_details, when there is one
     *
     * @throws Throwable when a line could not be recorded; everything was rolled back
     */
    public function settle(
        int $orderId,
        ?string $paymentIntentId,
        ?int $amountMinor,
        ?string $currency,
        ?string $sessionId = null,
        array $customerDetails = [],
    ): CartSettlementResult {
        try {
            // Read BEFORE the transaction opens, never inside it (see settleLocked() step 1): under
            // InnoDB's REPEATABLE READ the first plain SELECT of a transaction fixes the snapshot
            // every later plain read sees, and this one would fix it before the locks below are won.
            $ref = Order::withoutMasjidScope()->whereKey($orderId)->first(['id', 'masjid_id', 'cart_id']);

            $done = DB::transaction(
                fn (): array => $this->settleLocked($orderId, $ref, $paymentIntentId, $amountMinor, $currency, $sessionId, $customerDetails),
                3, // a deadlock retries the whole (re-entrant) closure rather than 500-ing a paid basket
            );
        } catch (Throwable $e) {
            // Class only: a database exception's message carries the values it choked on.
            Log::error('A paid cart could not be recorded. Nothing was written and the order is still pending; Stripe will retry the event.', [
                'order_id' => $orderId,
                'payment_intent' => $paymentIntentId,
                'exception' => $e::class,
            ]);

            throw $e;
        }

        if (! $done['settled'] && $done['steps'] === []) {
            return CartSettlementResult::none();
        }

        // `settled` stays true only for the call that moved the order to paid; a backfill
        // on a paid order returns its receipts without claiming to have settled anything.
        $receipts = $this->afterCommit($orderId, $done['steps']);

        if ($done['settled'] && isset($done['cart'])) {
            $this->closeOtherPages($orderId, $done['cart'][0], $done['cart'][1]);
        }

        return new CartSettlementResult($done['settled'], $receipts);
    }

    /**
     * The order is paid and committed: expire this basket's OTHER still-pending Stripe pages,
     * so one that contains lines this order already paid for can never charge them again.
     * CartCheckoutService::closeOtherPages() is the close logic (not copied here). Settlement
     * has committed, so nothing here may throw: a retry would find the order paid and stop.
     */
    private function closeOtherPages(int $orderId, int $masjidId, int $cartId): void
    {
        try {
            // The ordinary basket has no other page: do not build a Stripe client to find that out.
            if (! CartCheckoutService::otherPendingPages($masjidId, $cartId, $orderId)->exists()) {
                return;
            }

            ($this->checkout ?? app(CartCheckoutService::class))->closeOtherPages($masjidId, $cartId, $orderId);
        } catch (Throwable $e) {
            Log::warning('A basket was paid, but its other payment pages could not be checked afterwards; one may still be payable.', [
                'order_id' => $orderId,
                'masjid_id' => $masjidId,
                'cart_id' => $cartId,
                'exception' => $e::class,
            ]);
        }
    }

    /**
     * @return array{settled: bool, steps: list<Closure(): ?array>, cart?: array{0: int, 1: int}}
     */
    private function settleLocked(
        int $orderId,
        ?Order $ref,
        ?string $paymentIntentId,
        ?int $amountMinor,
        ?string $currency,
        ?string $sessionId,
        array $customerDetails,
    ): array {
        // 1. The locks every settlement of this order queues behind: the CART first, then
        // the order, the order checkout takes them in (it locks the basket, then touches its
        // orders), so a checkout and a settlement of the same basket cannot deadlock.
        //
        // NO PLAIN SELECT BEFORE THE LOCKS. On MySQL (REPEATABLE READ) the first consistent read
        // in a transaction fixes its snapshot, and every later plain read (the order's lines in
        // backfillLocked(), the basket's lines in closeCart()) sees the database as of that
        // moment, however long the lock wait was. A settlement queued behind another one (the
        // payment intent's and the session's events arrive together) would then read lines the
        // winner had not yet linked, and a line added to the basket while it waited would not exist.
        // Locking reads always see the latest committed rows and do not fix a snapshot, so the
        // first statement here is the cart's `FOR UPDATE`, and the first plain read comes after
        // both locks are held. The order's basket id (`$ref`) is read by settle() before the
        // transaction opens: it decides only WHICH cart to lock, and an order's cart_id never
        // moves except to NULL when the basket is pruned, in which case there is nothing to lock
        // (closeCart() then does nothing, exactly as if the read had been made in here). Everything
        // that matters is read below, under the locks.
        $cart = $ref?->cart_id === null
            ? null
            : Cart::withoutMasjidScope()->where('masjid_id', $ref->masjid_id)->whereKey($ref->cart_id)->lockForUpdate()->first();

        $order = Order::withoutMasjidScope()->whereKey($orderId)->lockForUpdate()->first();

        if ($order === null) {
            Log::warning('A cart payment named an order that no longer exists; nothing was recorded.', ['order_id' => $orderId]);

            return self::NOT_SETTLED;
        }

        if ($order->isPaid()) {
            $this->noteRepeat($order, $paymentIntentId);

            // A session event that follows the payment intent's brings the payer the
            // intent could not know; it settles nothing, and fills in what is missing.
            if ($sessionId !== null || $customerDetails !== []) {
                return ['settled' => false, 'steps' => $this->backfillLocked($order, $sessionId, $customerDetails)];
            }

            return self::NOT_SETTLED;
        }

        // 2. What was paid must be what the order snapshot says, in integer minor units.
        if ($amountMinor === null
            || $amountMinor !== (int) $order->total_minor
            || strtolower((string) $currency) !== strtolower((string) $order->currency)) {
            Log::warning(
                'A cart payment did not match the order it names; the order was left pending and NOTHING was recorded. '
                . 'If money moved, the refund is the organisation\'s own action in its Stripe dashboard.',
                [
                    'order_id' => (int) $order->id,
                    'masjid_id' => (int) $order->masjid_id,
                    'expected_minor' => (int) $order->total_minor,
                    'paid_minor' => $amountMinor,
                    'expected_currency' => strtolower((string) $order->currency),
                    'paid_currency' => strtolower((string) $currency),
                    'payment_intent' => $paymentIntentId,
                ]
            );

            return self::NOT_SETTLED;
        }

        if ($order->status === Order::STATUS_EXPIRED) {
            // The page was closed on our side, but the shopper's payment still landed
            // (a race with our own expiry). The money is real; it is recorded.
            Log::warning('A cart order marked expired was paid on Stripe; it is recorded as paid.', $this->context($order));
        }

        $masjid = Masjid::withTrashed()->find($order->masjid_id);
        $quiet = $masjid === null || $masjid->trashed();

        if ($quiet) {
            Log::warning('A cart order for an offboarded organisation was paid; it is recorded and nobody is emailed on its behalf.', $this->context($order));
        }

        // 3. Paid, once. The payment intent is recorded here and never overwritten.
        $order->forceFill([
            'status' => Order::STATUS_PAID,
            'paid_at' => now(),
            'stripe_payment_intent_id' => $order->stripe_payment_intent_id ?? $paymentIntentId,
        ])->save();

        // 4. Every line that has no record yet.
        $items = OrderItem::withoutMasjidScope()
            ->where('order_id', $order->id)
            ->where('masjid_id', $order->masjid_id)
            ->orderBy('id')
            ->get();

        if ($items->isEmpty()) {
            throw new LogicException("Order {$order->id} has no lines to record.");
        }

        $steps = [];

        foreach ($items as $item) {
            if ($item->record_id !== null) {
                continue;
            }

            match ($item->buyable_type) {
                'form' => $this->settleForm($order, $item, $paymentIntentId, $masjid, $quiet, $steps),
                'meal_item' => $this->settleMeal($order, $item, $paymentIntentId, $customerDetails, $quiet, $steps),
                'donation' => $this->settleDonation($order, $item, $paymentIntentId, $sessionId, $customerDetails, $masjid, $steps),
                default => throw new LogicException("Order {$order->id} line {$item->id} is a '{$item->buyable_type}', which nothing can record."),
            };
        }

        // 5. Take what this order paid for out of the basket, and close it when nothing is left.
        $this->closeCart($order, $cart, $items);

        return [
            'settled' => true,
            'steps' => $steps,
            // Where the after-commit page closing looks: this basket's other pending pages.
            'cart' => $order->cart_id === null ? null : [(int) $order->masjid_id, (int) $order->cart_id],
        ];
    }

    /**
     * Take from the basket the lines THIS order paid for, and close it only when nothing is
     * left. A line is the order's when its buyable_type, buyable_id, the canonical hash of
     * its payload (`cart_payload_hash`, stamped at checkout) and its quantity match a line still
     * in the basket; one order line removes one basket line. The order's own lines are the
     * snapshot, so a paid line is dropped rather than left to invite a second "Pay".
     *
     * The quantity is part of the match because `POST /cart/acknowledge` edits a line's
     * quantity in place (CartCheckoutService::acknowledge()) and the payload hash does not
     * see it: page A paid for two, the shopper then acknowledged three and opened page B, and
     * A's late payment must not take the line that now asks for three (ASSUMPTIONS #36). The
     * match is exact because a checkout refuses a basket whose quantities differ from what it
     * priced, so a page's order lines carry exactly the quantities the basket held when it opened.
     *
     * Not "every line": page A (lines X) can be paid just as it expires, after the shopper
     * added Y and opened page B (X + Y). A's late webhook must not silently drop Y, which was
     * never paid for; the basket then stays open with Y, and B's page is closed after the
     * commit (closeOtherPages()). Only an OPEN basket of this organisation, already locked by
     * the caller; anything else is left as it is.
     *
     * @param  Collection<int, OrderItem>  $paidLines
     */
    private function closeCart(Order $order, ?Cart $cart, Collection $paidLines): void
    {
        if ($cart === null || (int) $cart->masjid_id !== (int) $order->masjid_id || $cart->status !== Cart::STATUS_OPEN) {
            return;
        }

        $inBasket = CartItem::withoutMasjidScope()
            ->where('cart_id', $cart->id)
            ->where('masjid_id', $cart->masjid_id)
            ->orderBy('id')
            ->get();

        foreach ($paidLines as $paid) {
            // A line with no stored hash (none is written without one) matches on type, id and quantity alone.
            $key = $inBasket->search(fn (CartItem $line): bool => $line->buyable_type === $paid->buyable_type
                && (int) $line->buyable_id === (int) $paid->buyable_id
                && (int) $line->quantity === (int) $paid->quantity
                && ($paid->cart_payload_hash === null
                    || hash_equals((string) $paid->cart_payload_hash, PricedBasket::payloadHash($line->payload))));

            if ($key === false) {
                continue;
            }

            $inBasket->pull($key)->delete();
        }

        if ($inBasket->isEmpty()) {
            $cart->forceFill(['status' => Cart::STATUS_CHECKED_OUT])->save();
        }
    }

    /**
     * The order is ALREADY PAID and a session event arrived carrying the payer: fill in
     * what the payment intent's earlier settlement could not know, and queue the steps it
     * had to skip. Nothing is re-settled: no order, line or record changes status, and no
     * line is written again.
     *
     *  - donation: the session id; and, while the gift has no contact, the donor link,
     *    the receipt and its delivery (`linkFromCheckoutSession()` is a no-op once there
     *    is a contact, `issueFor()` returns the receipt it already issued, and the
     *    controller's `deliverReceipt()` is once-only on `receipt_delivered_at`);
     *  - meal: the customer name, phone and e-mail, only where each is still the
     *    placeholder settlement wrote (never over something a person typed or a contact
     *    supplied), then the confirmation, which claims its own send;
     *  - form: nothing; a registration's identity is its own answers, not Stripe's.
     *
     * A replay finds the records already filled, so it queues nothing.
     *
     * @param  array<string,mixed>  $customerDetails
     * @return list<Closure(): ?array>
     */
    private function backfillLocked(Order $order, ?string $sessionId, array $customerDetails): array
    {
        $masjid = Masjid::withTrashed()->find($order->masjid_id);
        $quiet = $masjid === null || $masjid->trashed();

        $steps = [];

        $items = OrderItem::withoutMasjidScope()
            ->where('order_id', $order->id)
            ->where('masjid_id', $order->masjid_id)
            ->whereNotNull('record_id')
            ->orderBy('id')
            ->get();

        foreach ($items as $item) {
            match ($item->record_type) {
                OrderItem::RECORD_DONATION => $this->backfillDonation($order, $item, $sessionId, $customerDetails, $steps),
                OrderItem::RECORD_MEAL_ORDER => $this->backfillMeal($order, $item, $customerDetails, $quiet, $steps),
                default => null,
            };
        }

        return $steps;
    }

    /**
     * @param  array<string,mixed>  $customerDetails
     * @param  list<Closure(): ?array>  $steps
     */
    private function backfillDonation(Order $order, OrderItem $item, ?string $sessionId, array $customerDetails, array &$steps): void
    {
        $donation = Donation::withoutMasjidScope()
            ->where('masjid_id', $order->masjid_id)
            ->whereKey($item->record_id)
            ->lockForUpdate()
            ->first();

        if ($donation === null) {
            return;
        }

        if ($sessionId !== null && $donation->stripe_checkout_session_id === null) {
            $donation->forceFill(['stripe_checkout_session_id' => $sessionId])->save();
        }

        // A gift that already has its contact was linked (and receipted) when it was
        // settled; there is nothing left to run for it.
        if ($donation->contact_id !== null) {
            return;
        }

        $details = $this->detailsWithBuyer($order, $customerDetails);

        if (! filled($details['email'] ?? null)) {
            return;
        }

        $steps[] = $this->donorAndReceiptStep((int) $item->id, (int) $donation->id, $details);
    }

    /**
     * @param  array<string,mixed>  $customerDetails
     * @param  list<Closure(): ?array>  $steps
     */
    private function backfillMeal(Order $order, OrderItem $item, array $customerDetails, bool $quiet, array &$steps): void
    {
        $meal = MealOrder::withoutMasjidScope()
            ->where('masjid_id', $order->masjid_id)
            ->whereKey($item->record_id)
            ->lockForUpdate()
            ->first();

        if ($meal === null) {
            return;
        }

        $customer = $this->mealCustomer($order, $customerDetails);
        $changes = [];

        if ($meal->customer_name === $this->placeholderName($order) && $customer['name'] !== $this->placeholderName($order)) {
            $changes['customer_name'] = $customer['name'];
        }

        if (trim((string) $meal->customer_phone) === '' && $customer['phone'] !== '') {
            $changes['customer_phone'] = $customer['phone'];
        }

        $gainsEmail = trim((string) $meal->customer_email) === '' && $customer['email'] !== null;

        if ($gainsEmail) {
            $changes['customer_email'] = $customer['email'];
        }

        if ($changes !== []) {
            $meal->forceFill($changes)->save();
        }

        // The confirmation the first settlement could not send (no address then). It claims
        // its own send, so this can never mail the customer twice.
        if ($gainsEmail && ! $quiet) {
            $mealId = (int) $meal->id;

            $steps[] = function () use ($mealId): ?array {
                $fresh = MealOrder::withoutMasjidScope()->whereKey($mealId)->first();

                if ($fresh !== null) {
                    $this->lunchMail->confirmation($fresh);
                }

                return null;
            };
        }
    }

    /**
     * A form line: one card response row, written UNPAID by the writer from the checkout
     * snapshot, then settled by markPaid().
     *
     * The form row is locked first (the writer's contract: `forms.response_count` moves
     * under the lock every capacity check is read under). `earlier()` under that lock
     * answers a replay with the first row; the unique (form_id, client_submission_key)
     * index is the backstop and is answered the same way. No `reserveOn`: a date hold could
     * refuse a paid ticket (FormDateTaken) and roll its money's record back.
     *
     * @param  list<Closure(): ?array>  $steps
     */
    private function settleForm(Order $order, OrderItem $item, ?string $pi, ?Masjid $masjid, bool $quiet, array &$steps): void
    {
        // withTrashed: a form deleted since checkout must not make a paid ticket unrecordable.
        $form = Form::withTrashed()
            ->where('masjid_id', $order->masjid_id)
            ->whereKey($item->buyable_id)
            ->lockForUpdate()
            ->first();

        if ($form === null) {
            throw new LogicException("Order {$order->id} line {$item->id}: form {$item->buyable_id} no longer exists.");
        }

        $quote = $item->price_snapshot;

        if (! is_array($quote) || ! isset($quote['total_minor'], $quote['unit_minor'], $quote['quantity'], $quote['amount_due_minor'], $quote['fee_covered_minor'], $quote['currency'])) {
            throw new LogicException("Order {$order->id} line {$item->id} has no price snapshot to record the registration from.");
        }

        if ($form->trashed() || ! $form->acceptsSubmissions()) {
            Log::warning(
                'A cart payment is being recorded for a form that no longer accepts responses (closed, full, inactive or removed); '
                . 'the money was taken, so the registration is recorded.',
                $this->context($order) + ['form_id' => (int) $form->id, 'reason' => $form->closedReason()]
            );
        }

        $key = self::lineKey($item);
        $row = $this->forms->earlier((int) $form->id, $key);

        if ($row === null) {
            $schema = FormSchema::for($form);
            // The door's own cleaning, on the answers frozen at checkout.
            $clean = $schema->only($form->withoutUnusedPriceAnswers((array) ($item->payload ?? [])));

            try {
                // A savepoint of its own: a refused write must leave nothing behind before
                // we go back to the first row.
                $row = DB::transaction(function () use ($form, $schema, $clean, $quote, $key, $item): FormResponse {
                    $written = $this->forms->write(
                        $form,
                        $schema,
                        $clean,
                        FormResponseWriter::LEG_ONLINE,
                        $quote,
                        [],
                        $key,
                        ['cart_order_item' => (int) $item->id],
                    );

                    return $this->keepWhatWasPaidFor($written, $quote);
                });
            } catch (UniqueConstraintViolationException) {
                $row = $this->forms->earlier((int) $form->id, $key)
                    ?? throw new LogicException("Order {$order->id} line {$item->id}: the key is taken and no row answers to it.");
            }
        }

        $this->pinToHolder($order, $row, $masjid);

        $settled = $row->markPaid($pi);

        $this->link($item, OrderItem::RECORD_FORM_RESPONSE, (int) $row->id);

        if ($settled && ! $quiet) {
            $steps[] = static function () use ($form, $row, $masjid): ?array {
                FormNotifier::submitted($form->setRelation('masjid', $masjid), $row);

                return null;
            };
        }
    }

    /**
     * The legacy decimal `amount_due` and `entry_count` are computed by the writer from
     * the LIVE form, at the instant it runs: a price edited (or a tier crossed) between
     * checkout and the payment landing would store a figure nobody paid, which the
     * confirmation e-mail then states and FormInsights sums. So they are overwritten from
     * what checkout froze. The writer is unchanged. An order opened before the snapshot
     * carried them keeps the writer's figures.
     *
     * @param  array<string,mixed>  $quote  the line's price snapshot
     */
    private function keepWhatWasPaidFor(FormResponse $row, array $quote): FormResponse
    {
        $fields = [];

        if (array_key_exists('legacy_amount_due', $quote)) {
            $fields['amount_due'] = is_numeric($quote['legacy_amount_due']) ? round((float) $quote['legacy_amount_due'], 2) : null;
        }

        if (isset($quote['entry_count']) && is_numeric($quote['entry_count'])) {
            $fields['entry_count'] = max(1, (int) $quote['entry_count']);
        }

        if ($fields !== []) {
            $row->forceFill($fields)->save();
        }

        return $row;
    }

    /**
     * A basket paid on a HOLDER's account (a linked organisation's forms, `charge_ref` set)
     * leaves its registration pinned to that account, in the same transaction, the way
     * FormResponseCheckoutService pins a linked row: `charge_account_id` and
     * `charge_masjid_id` (the organisation holding the account). Unpinned, the row's charge
     * would be read as sitting on its OWN organisation's account, the receipt would omit
     * "processed by <holder>", and the admin API would say the card was not charged through
     * anyone; pinned, FormChargeAccount::refundInstruction tells staff where to refund it.
     *
     * The pin no longer drives a per-row refund or dispute flag: a basket's one charge is
     * flagged on the ORDER (CartPaymentService::handleChargeFlag), because the event says how
     * much was refunded and never which line, and FormResponsePaymentService::handleChargeFlag
     * skips rows a cart settled.
     *
     * The row's own `charge_ref` is NOT set: it is unique per row and a basket's one
     * reference is shared by all of its lines. An already-pinned row is left as it is.
     */
    private function pinToHolder(Order $order, FormResponse $row, ?Masjid $masjid): void
    {
        if ($order->charge_ref === null || $row->hasChargePin()) {
            return;
        }

        $account = (string) $order->charge_account_id;
        $holders = Masjid::withTrashed()->where('stripe_account_id', $account)->pluck('id')->map(fn ($id): int => (int) $id);
        $via = $masjid?->forms_card_via_masjid_id === null ? null : (int) $masjid->forms_card_via_masjid_id;

        // The link's own holder when it still holds the pinned account, else whoever does.
        $holder = $via !== null && $holders->contains($via) ? $via : ($holders->first() ?? $via);

        if ($holder === null) {
            Log::error('A linked cart registration was paid on an account no organisation holds; it is recorded pinned to the account alone.', $this->context($order));
        }

        $row->forceFill([
            'charge_account_id' => $account,
            'charge_masjid_id' => $holder,
        ])->save();
    }

    /**
     * A meal line: one meal order, written from the FROZEN line (never re-priced), joined
     * to this transaction, then settled with markPaid() under a row lock.
     *
     * The menu is read `withTrashed`. A dish deleted since checkout has no row left, so
     * the frozen line loses its item id and keeps its name and price
     * (`meal_order_items.meal_menu_item_id` is a foreign key: naming a deleted id would
     * fail the insert and lose the record of a paid order).
     *
     * @param  array<string,mixed>  $customerDetails
     * @param  list<Closure(): ?array>  $steps
     */
    private function settleMeal(Order $order, OrderItem $item, ?string $pi, array $customerDetails, bool $quiet, array &$steps): void
    {
        $payload = (array) ($item->payload ?? []);
        $frozen = $item->price_snapshot;

        if (! is_array($frozen) || ! isset($frozen['item_name'], $frozen['unit_price_minor'], $frozen['quantity'], $frozen['line_total_minor'])) {
            throw new LogicException("Order {$order->id} line {$item->id} has no frozen line to record the meal order from.");
        }

        $menu = MealMenu::withoutMasjidScope()
            ->withTrashed()
            ->where('masjid_id', $order->masjid_id)
            ->find((int) ($payload['meal_menu_id'] ?? 0));

        if ($menu === null) {
            throw new LogicException("Order {$order->id} line {$item->id}: its menu no longer exists.");
        }

        $dish = MealMenuItem::withoutMasjidScope()
            ->where('masjid_id', $order->masjid_id)
            ->where('meal_menu_id', $menu->id)
            ->find((int) ($payload['menu_item_id'] ?? 0));

        if ($dish === null) {
            $frozen['meal_menu_item_id'] = null;
        }

        if ($menu->trashed() || ! $menu->isOpenForOrders() || $dish === null || ! $dish->is_available) {
            Log::warning(
                'A cart payment is being recorded for a menu or dish that can no longer be ordered (closed, removed or unavailable); '
                . 'the money was taken, so the order is recorded.',
                $this->context($order) + ['meal_menu_id' => (int) $menu->id, 'dish_removed' => $dish === null]
            );
        }

        $catalogue = [];

        if ($menu->isCatalogue()) {
            $catalogue = ['pickup_at' => $this->pickupFrom($order, $payload['pickup_at'] ?? null), 'preferred_payment' => null];
        }

        $created = $this->meals->create(
            $menu,
            (int) $order->masjid_id,
            ['lines' => [$frozen], 'subtotal_minor' => (int) $frozen['line_total_minor']],
            $this->mealCustomer($order, $customerDetails),
            MealOrder::METHOD_ONLINE,
            null,
            0,
            0,
            $catalogue,
        );

        $locked = MealOrder::withoutMasjidScope()->whereKey($created->id)->lockForUpdate()->firstOrFail();
        $settled = $locked->payment_status !== MealOrder::PAYMENT_PAID;
        $locked->markPaid($pi);

        $this->link($item, OrderItem::RECORD_MEAL_ORDER, (int) $locked->id);

        if ($settled && ! $quiet) {
            $steps[] = function () use ($locked): ?array {
                $this->lunchMail->confirmation($locked);

                return null;
            };
        }
    }

    /**
     * A donation line: the pending row from createPendingDonation() under the cart's own
     * key `cart:item:<id>`, then markSucceeded() with the cart's payment intent.
     *
     * `fee`/`net` are left null (see the class doc). `markSucceeded()` has no status
     * guard, so the pending check is here, under a row lock. ZakatDesignation stays the
     * only place zakat is decided: the giver's own answer, if the line carried one, is
     * handed to createPendingDonation(), which alone runs the rule.
     *
     * The receipt's gross is the donation's `charged_amount`; it must equal what the
     * basket charged for the line, or the tax receipt would state the wrong figure.
     *
     * @param  array<string,mixed>  $customerDetails
     * @param  list<Closure(): ?array>  $steps
     */
    private function settleDonation(Order $order, OrderItem $item, ?string $pi, ?string $sessionId, array $customerDetails, ?Masjid $masjid, array &$steps): void
    {
        $snapshot = $item->price_snapshot;
        $intended = is_array($snapshot) ? (int) ($snapshot['intended_minor'] ?? 0) : 0;

        if ($intended < 1 || $intended !== (int) $item->total_minor) {
            throw new LogicException("Order {$order->id} line {$item->id}: the gift's snapshot ({$intended}) is not what the line charged ({$item->total_minor}).");
        }

        $fund = Fund::withoutMasjidScope()->where('masjid_id', $order->masjid_id)->find($item->buyable_id);

        if ($fund === null || $masjid === null) {
            throw new LogicException("Order {$order->id} line {$item->id}: the fund no longer exists.");
        }

        if (! $fund->is_active || ! $masjid->canAcceptDonations()) {
            Log::warning(
                'A cart payment is being recorded as a gift to a fund that is no longer collecting (or an organisation that can no longer take gifts); '
                . 'the money was taken, so the gift is recorded.',
                $this->context($order) + ['fund_id' => (int) $fund->id]
            );
        }

        $key = self::lineKey($item);
        $answers = (array) ($item->payload ?? []);
        $existing = Donation::withoutMasjidScope()->where('idempotency_key', $key)->first();

        $donation = $existing ?? $this->donations->createPendingDonation($masjid, $fund, $intended, false, [
            'contact_id' => $order->contact_id === null ? null : (int) $order->contact_id,
            'zakat' => is_bool($answers['zakat'] ?? null) ? $answers['zakat'] : null,
            'application_fee_amount' => $this->apportionedFee($order, $intended),
            'idempotency_key' => $key,
        ]);

        if ((int) $donation->charged_amount !== (int) $item->total_minor) {
            throw new LogicException("Order {$order->id} line {$item->id}: the gift's charged amount is not what the line charged.");
        }

        $locked = Donation::withoutMasjidScope()->whereKey($donation->id)->lockForUpdate()->firstOrFail();
        $settled = $locked->status === 'pending';

        if ($settled) {
            $this->donations->markSucceeded($locked, [
                'payment_intent_id' => $pi,
                'checkout_session_id' => $sessionId,
            ]);
        }

        $this->link($item, OrderItem::RECORD_DONATION, (int) $locked->id);

        if ($settled) {
            $steps[] = $this->donorAndReceiptStep((int) $item->id, (int) $locked->id, $this->detailsWithBuyer($order, $customerDetails), true);
        }
    }

    /**
     * The payer's details, with what the buyer typed at the basket page (the order's own
     * `buyer_email`, `buyer_name`, `buyer_phone`) standing in for anything Stripe's
     * `customer_details` lack. Stripe's own value always wins where it has one.
     *
     * @param  array<string,mixed>  $customerDetails
     * @return array<string,mixed>
     */
    private function detailsWithBuyer(Order $order, array $customerDetails): array
    {
        foreach (['email' => $order->buyer_email, 'name' => $order->buyer_name, 'phone' => $order->buyer_phone] as $key => $typed) {
            if (! filled($customerDetails[$key] ?? null) && filled($typed)) {
                $customerDetails[$key] = $typed;
            }
        }

        return $customerDetails;
    }

    /**
     * After the commit, for one gift: seed the donor contact from the payer's details
     * (a no-op once the gift has one), issue the receipt (returns the one already issued)
     * and hand it back for the controller's delivery. The arrival note is the settlement's
     * alone (`$noteArrival`), and is itself once per donation.
     *
     * THE LINE'S RECEIPT IS CLAIMED ATOMICALLY. `payment_intent.succeeded` and
     * `checkout.session.completed` arrive together, and each may queue this step for the
     * same gift (the second reads the gift before the first has linked its donor). The
     * controller's deliverReceipt() is check-then-send, so two steps that both hand back a
     * receipt mail it twice, and two findOrCreate calls can link different contacts. So a
     * step that can deliver first claims the line (`receipt_claimed_at`, one UPDATE ... WHERE
     * NULL), and only the process that changed 1 row links the donor and returns the receipt.
     *
     * A step with nobody to deliver to (a guest's intent, before the session's details: no
     * address and no contact on the gift) claims nothing and delivers nothing: it may still
     * issue the receipt (issuing is idempotent), but it must not use up the claim the session
     * event's step needs. A claim that ends with no contact or no receipt, or with a failure,
     * is released for the next step.
     *
     * A claim that reaches delivery is kept only if the delivery worked. deliverReceipt() is
     * best-effort (a mail failure is logged and `receipt_delivered_at` stays null), so the
     * step hands back the line's id with the receipt and the controller, which delivers,
     * releases the claim when the gift is still undelivered afterwards
     * (`releaseReceiptClaim()`); otherwise a failed send would leave the line claimed and
     * every later step would get 0 rows.
     *
     * @param  array<string,mixed>  $details
     * @return Closure(): ?array{0: Donation, 1: \App\Models\DonationReceipt, 2: int}
     */
    private function donorAndReceiptStep(int $orderItemId, int $donationId, array $details, bool $noteArrival = false): Closure
    {
        return function () use ($orderItemId, $donationId, $details, $noteArrival): ?array {
            $donation = Donation::withoutMasjidScope()->whereKey($donationId)->firstOrFail();

            // Once per donation on its own, so it does not depend on who wins the claim.
            if ($noteArrival) {
                GivingSwitch::noteArrivalIfOff((int) $donation->masjid_id, 'gift', $donation->id, [
                    'amount_minor' => (int) $donation->charged_amount,
                ]);
            }

            // Nobody to deliver to yet: no address, and no contact on the gift already.
            if (! filled($details['email'] ?? null) && $donation->contact_id === null) {
                $this->receipts->issueFor($donation->refresh());

                return null;
            }

            $claimed = OrderItem::withoutMasjidScope()
                ->whereKey($orderItemId)
                ->whereNull('receipt_claimed_at')
                ->update(['receipt_claimed_at' => now()]);

            if ($claimed !== 1) {
                return null;
            }

            try {
                $this->donorContacts->linkFromCheckoutSession($donation, ['customer_details' => $details]);
                $receipt = $this->receipts->issueFor($donation->refresh());
            } catch (Throwable $e) {
                self::releaseReceiptClaim($orderItemId);

                throw $e;
            }

            // Nothing to deliver (no contact could be made, or the gift takes no receipt): give
            // the claim back and hand nothing on, so no delivery can race a later step's.
            if ($receipt === null || $donation->contact_id === null) {
                self::releaseReceiptClaim($orderItemId);

                return null;
            }

            return [$donation, $receipt, $orderItemId];
        };
    }

    /**
     * Give the claim back: nothing was delivered, so a later step may still try. Public and
     * static because the controller, which delivers the receipt, is the one that learns the
     * send failed.
     */
    public static function releaseReceiptClaim(int $orderItemId): void
    {
        OrderItem::withoutMasjidScope()->whereKey($orderItemId)->update(['receipt_claimed_at' => null]);
    }

    /**
     * The platform's cut of this line, apportioned from the order's own `fee_minor`
     * (what the basket's single payment intent actually carried). Rounded down, so the
     * lines never claim more than the order took. Null-stored when zero, as the door does.
     */
    private function apportionedFee(Order $order, int $lineMinor): int
    {
        $fee = (int) $order->fee_minor;
        $total = (int) $order->total_minor;

        return $fee > 0 && $total > 0 ? intdiv($fee * $lineMinor, $total) : 0;
    }

    /**
     * Who a basket's meal order is for: what the buyer typed at the basket page (the order's
     * `buyer_name` and `buyer_phone`, which is what the office needs to ring them), else the
     * buyer's contact when there is one, else what the payer typed into Stripe Checkout, else
     * a plain label. `customer_name` and `customer_phone` are required columns, and money
     * already taken must not fail on a blank, so an order opened without a name (a late or
     * legacy one) still settles, under the placeholder.
     *
     * @param  array<string,mixed>  $details  the session's customer_details
     * @return array{name: string, phone: string, email: ?string, notes: null}
     */
    private function mealCustomer(Order $order, array $details): array
    {
        $contact = $order->contact_id === null
            ? null
            : Contact::withoutMasjidScope()->where('masjid_id', $order->masjid_id)->find($order->contact_id);

        $name = trim((string) ($order->buyer_name ?? ''));
        $name = $name !== '' ? $name : trim(trim((string) ($contact?->first_name ?? '')) . ' ' . trim((string) ($contact?->last_name ?? '')));
        $name = $name !== '' ? $name : trim((string) ($details['name'] ?? ''));

        $phone = trim((string) ($order->buyer_phone ?? ''));
        $phone = $phone !== '' ? $phone : trim((string) ($contact?->phone ?? ''));
        $phone = $phone !== '' ? $phone : trim((string) ($details['phone'] ?? ''));

        $email = null;

        foreach ([$order->buyer_email, $contact?->email, $details['email'] ?? null] as $candidate) {
            $candidate = is_string($candidate) ? trim($candidate) : '';

            if ($candidate !== '' && strlen($candidate) <= 190 && filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                $email = $candidate;

                break;
            }
        }

        return [
            'name' => $name !== '' ? mb_substr($name, 0, 255) : $this->placeholderName($order),
            'phone' => mb_substr($phone, 0, 32),
            'email' => $email,
            'notes' => null,
        ];
    }

    /** The label a meal order carries until a real name is known: `customer_name` is required. */
    private function placeholderName(Order $order): string
    {
        return mb_substr('Online order ' . $order->order_number, 0, 255);
    }

    /** The pickup instant a catalogue line was ordered for, or null when there is none or it cannot be read. */
    private function pickupFrom(Order $order, mixed $raw): ?Carbon
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw)->utc();
        } catch (Throwable) {
            Log::warning('A cart meal line carried a pickup time that could not be read; the order is recorded without one.', $this->context($order));

            return null;
        }
    }

    /**
     * The key a line's record is written under: `form_responses.client_submission_key` for a
     * ticket, `donations.idempotency_key` for a gift. It is what makes a replayed event answer with
     * the first row instead of writing a second.
     *
     * The colon is the point. The PUBLIC form door takes any `client_submission_key` matching
     * `^[A-Za-z0-9_-]{8,64}$`, so a key made only of those characters (the old `cart_item_<id>`)
     * could be submitted by anyone against the same form, and settlement's `earlier()` would then
     * find THEIR row, mark it paid with this order's payment and link the ticket to it. `:` is
     * outside that alphabet, so the door cannot produce this key however it is asked.
     * (form_responses.client_submission_key is 64 wide; this is 10 characters and an id.)
     */
    public static function lineKey(OrderItem $item): string
    {
        return 'cart:item:' . $item->id;
    }

    /** Stamp the record on the line — the per-line idempotency marker. */
    private function link(OrderItem $item, string $type, int $id): void
    {
        $item->forceFill(['record_type' => $type, 'record_id' => $id])->save();
    }

    /**
     * An order that is already paid, seen again. The other success event or a redelivery
     * says nothing; a DIFFERENT payment intent is a second charge, logged so the
     * organisation can refund it and never recorded over the first.
     */
    private function noteRepeat(Order $order, ?string $paymentIntentId): void
    {
        if ($paymentIntentId === null) {
            return;
        }

        $recorded = $order->stripe_payment_intent_id;

        if ($recorded === null) {
            // Paid without a payment intent on record: this one is the first we hear of.
            $order->forceFill(['stripe_payment_intent_id' => $paymentIntentId])->save();

            return;
        }

        if (! hash_equals((string) $recorded, $paymentIntentId)) {
            Log::warning(
                'A cart order that is already paid was charged a second time; the second payment was NOT recorded over the first. '
                . 'The organisation should refund it in its Stripe dashboard.',
                $this->context($order) + ['recorded_payment_intent' => $recorded, 'second_payment_intent' => $paymentIntentId]
            );
        }
    }

    /**
     * Run the steps that follow the commit: the form notification, the lunch
     * confirmation and the donation receipt. Each is isolated (see the class doc).
     * Protected so a test can observe that it runs with the transaction closed.
     *
     * @param  list<Closure(): ?array>  $steps
     * @return list<array{0: Donation, 1: \App\Models\DonationReceipt, 2: int}>
     */
    protected function afterCommit(int $orderId, array $steps): array
    {
        $receipts = [];

        foreach ($steps as $step) {
            try {
                $out = $step();

                if (is_array($out)) {
                    $receipts[] = $out;
                }
            } catch (Throwable $e) {
                Log::error('A cart order was recorded, but a step after it (an email or a receipt) failed and was not retried.', [
                    'order_id' => $orderId,
                    'exception' => $e::class,
                ]);
            }
        }

        return $receipts;
    }

    /** Ids only: never a name, an email or an answer. @return array<string,int|string> */
    private function context(Order $order): array
    {
        return [
            'order_id' => (int) $order->id,
            'order_number' => (string) $order->order_number,
            'masjid_id' => (int) $order->masjid_id,
        ];
    }
}
