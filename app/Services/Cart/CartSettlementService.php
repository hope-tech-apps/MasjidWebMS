<?php

namespace App\Services\Cart;

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
 *      from the snapshot taken at checkout, settle it, and link `record_type`/`record_id`.
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
            $done = DB::transaction(
                fn (): array => $this->settleLocked($orderId, $paymentIntentId, $amountMinor, $currency, $sessionId, $customerDetails),
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

        if (! $done['settled']) {
            return CartSettlementResult::none();
        }

        return new CartSettlementResult(true, $this->afterCommit($orderId, $done['steps']));
    }

    /**
     * @return array{settled: bool, steps: list<Closure(): ?array>}
     */
    private function settleLocked(
        int $orderId,
        ?string $paymentIntentId,
        ?int $amountMinor,
        ?string $currency,
        ?string $sessionId,
        array $customerDetails,
    ): array {
        // 1. The lock every settlement of this order queues behind.
        $order = Order::withoutMasjidScope()->whereKey($orderId)->lockForUpdate()->first();

        if ($order === null) {
            Log::warning('A cart payment named an order that no longer exists; nothing was recorded.', ['order_id' => $orderId]);

            return self::NOT_SETTLED;
        }

        if ($order->isPaid()) {
            $this->noteRepeat($order, $paymentIntentId);

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

        return ['settled' => true, 'steps' => $steps];
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

        $key = 'cart_item_' . $item->id;
        $row = $this->forms->earlier((int) $form->id, $key);

        if ($row === null) {
            $schema = FormSchema::for($form);
            // The door's own cleaning, on the answers frozen at checkout.
            $clean = $schema->only($form->withoutUnusedPriceAnswers((array) ($item->payload ?? [])));

            try {
                // A savepoint of its own: a refused write must leave nothing behind before
                // we go back to the first row.
                $row = DB::transaction(fn (): FormResponse => $this->forms->write(
                    $form,
                    $schema,
                    $clean,
                    FormResponseWriter::LEG_ONLINE,
                    $quote,
                    [],
                    $key,
                    ['cart_order_item' => (int) $item->id],
                ));
            } catch (UniqueConstraintViolationException) {
                $row = $this->forms->earlier((int) $form->id, $key)
                    ?? throw new LogicException("Order {$order->id} line {$item->id}: the key is taken and no row answers to it.");
            }
        }

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
     * key `cart_item_<id>`, then markSucceeded() with the cart's payment intent.
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

        $key = 'cart_item_' . $item->id;
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
            $details = $customerDetails;

            if (! filled($details['email'] ?? null) && filled($order->buyer_email)) {
                $details['email'] = $order->buyer_email;
            }

            $steps[] = function () use ($locked, $details): ?array {
                // Seeds the donor contact from the payer's details when the order had none.
                $this->donorContacts->linkFromCheckoutSession($locked->refresh(), ['customer_details' => $details]);
                $receipt = $this->receipts->issueFor($locked->refresh());

                GivingSwitch::noteArrivalIfOff((int) $locked->masjid_id, 'gift', $locked->id, [
                    'amount_minor' => (int) $locked->charged_amount,
                ]);

                return $receipt === null ? null : [$locked, $receipt];
            };
        }
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
     * Who a basket's meal order is for. The basket collects no name or phone of its own,
     * so it is the buyer's contact when there is one, else what the payer typed into
     * Stripe Checkout, else a plain label — `customer_name` and `customer_phone` are
     * required columns, and money already taken must not fail on a blank.
     *
     * @param  array<string,mixed>  $details  the session's customer_details
     * @return array{name: string, phone: string, email: ?string, notes: null}
     */
    private function mealCustomer(Order $order, array $details): array
    {
        $contact = $order->contact_id === null
            ? null
            : Contact::withoutMasjidScope()->where('masjid_id', $order->masjid_id)->find($order->contact_id);

        $name = trim(trim((string) ($contact?->first_name ?? '')) . ' ' . trim((string) ($contact?->last_name ?? '')));
        $name = $name !== '' ? $name : trim((string) ($details['name'] ?? ''));

        $phone = trim((string) ($contact?->phone ?? ''));
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
            'name' => mb_substr($name !== '' ? $name : 'Online order ' . $order->order_number, 0, 255),
            'phone' => mb_substr($phone, 0, 32),
            'email' => $email,
            'notes' => null,
        ];
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
     * @return list<array{0: Donation, 1: \App\Models\DonationReceipt}>
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
