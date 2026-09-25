<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MealMenus\CreatePaymentLinkRequest;
use App\Http\Requests\Admin\MealMenus\EditMealOrderItemsRequest;
use App\Http\Requests\Admin\MealMenus\MarkMealOrderPaidRequest;
use App\Http\Requests\Admin\MealMenus\StoreStaffMealOrderRequest;
use App\Http\Requests\Admin\MealMenus\UpdateMealOrderStatusRequest;
use App\Models\Masjid;
use App\Models\MealOrderItem;
use App\Services\Kitchen\KitchenOrderNotifier;
use App\Services\Lunch\MealOrderEditor;
use App\Services\Stripe\MealOrderCheckoutService;
use App\Support\LunchLineRefusal;
use App\Support\LunchOrderExtras;
use App\Support\LunchOrderLines;
use App\Support\MasjidTime;
use Illuminate\Support\Facades\DB;
use App\Models\MealMenu;
use App\Models\MealOrder;
use App\Support\Errors;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin: the order board for one Jummah-lunch menu — the kitchen's live list,
 * plus the two things staff do to an order: mark it paid (money taken by hand:
 * cash, Zelle, the masjid's terminal, another Stripe route) and move its
 * fulfilment status (ready / picked up).
 *
 * Tenant-scoped by the bound tenant. A payment on the order's own Stripe page is
 * recorded by the webhook, never here; `markPaid` records money that came some
 * other way, and closes that page first so the two cannot both be taken.
 */
class MealOrdersController extends Controller
{
    /**
     * Mark paid on an order the webhook has already settled from its own card page:
     * the refusal closePageBeforePaidByHand gives while Stripe's news is on its way,
     * told the same once it has landed.
     */
    private const PAID_ON_ITS_PAGE = 'This order was already paid by card online, so nothing was recorded. If you also took money for it by hand, give that back.';

    /**
     * The customer's own page to pay for adding to a paid order (a top-up) could
     * not be closed before a staff change, or has just been paid.
     */
    private const TOP_UP_NOT_CLOSED = 'The customer has a payment page open to add to this order, and Stripe did not let it be closed, so nothing was changed. Try again in a moment.';

    private const TOP_UP_JUST_PAID = 'The customer has just paid to add to this order, and Stripe is confirming it, so nothing was changed. Try again in a minute.';

    /** An order may not be emptied: cancelling one is its own action. */
    private const EDIT_FLOOR = 'An order must keep at least one plate. Cancel the order instead.';

    /**
     * A line on the order that no body can name: its dish was deleted from the
     * menu, or is marked unavailable, so neither screen offers it as something to
     * keep and saving would drop it without anyone choosing to.
     */
    private const EDIT_ITEM_GONE = 'This order has something on it that is no longer on the menu: %s. Put it back under Menu Items, then change the order.';

    /** The statuses that mean the office has accepted a kitchen order. */
    private const CONFIRMING_STATUSES = [
        MealOrder::STATUS_CONFIRMED,
        MealOrder::STATUS_READY,
        MealOrder::STATUS_PICKED_UP,
    ];

    public function __construct(
        private MealOrderCheckoutService $checkout,
        private MealOrderEditor $editor
    ) {
    }

    /**
     * GET orders for a menu, newest first, with a summary the board header shows.
     * Optional filters: ?status= and ?payment_status=.
     */
    public function index(Request $request, $masjid_id, $menu_id)
    {
        $menu = MealMenu::findOrFail($menu_id);

        $orders = MealOrder::query()
            ->where('meal_menu_id', $menu->id)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('payment_status'), fn ($q) => $q->where('payment_status', $request->string('payment_status')))
            ->with(['items', 'enteredBy:id,name', 'markedPaidBy:id,name', 'confirmedBy:id,name'])
            ->orderByDesc('placed_at')
            ->orderByDesc('id')
            ->get();

        // A kitchen order's pickup, as the office's wall clock reads it: the board
        // shows this string as it is, so a browser in another timezone cannot
        // shift the time the kitchen is cooking for.
        if ($menu->isCatalogue()) {
            $tz = MasjidTime::zoneFor($menu->masjid_id);

            $orders->each(fn (MealOrder $order) => $order->setAttribute(
                'pickup_at_local',
                MasjidTime::toLocalInput($order->pickup_at, $tz)
            ));
        }

        // Board summary over the WHOLE menu (not the filtered slice).
        $all = MealOrder::query()->where('meal_menu_id', $menu->id);
        $paid = (clone $all)->where('payment_status', MealOrder::PAYMENT_PAID);

        // What the kitchen has to make: items on every live order (cancelled
        // ones excluded, the same rule as `expected_total_minor`), in total and
        // per menu item.
        //   - Grouped by the menu item, so a dish renamed after orders came in
        //     is counted once, under its CURRENT name.
        //   - A dish deleted from the menu leaves its lines with a null
        //     meal_menu_item_id; those are split by the name recorded on the
        //     order, so two deleted dishes never merge under one of their names.
        $live = (clone $all)->whereIn('status', [
            MealOrder::STATUS_PENDING,
            MealOrder::STATUS_CONFIRMED,
            MealOrder::STATUS_READY,
            MealOrder::STATUS_PICKED_UP,
        ]);
        $itemsByItem = MealOrderItem::query()
            ->leftJoin('meal_menu_items as mmi', 'mmi.id', '=', 'meal_order_items.meal_menu_item_id')
            ->whereIn('meal_order_items.meal_order_id', (clone $live)->select('id'))
            ->selectRaw('meal_order_items.meal_menu_item_id, COALESCE(MAX(mmi.name), MAX(meal_order_items.item_name)) as item_name, SUM(meal_order_items.quantity) as quantity')
            ->groupByRaw('meal_order_items.meal_menu_item_id, CASE WHEN meal_order_items.meal_menu_item_id IS NULL THEN meal_order_items.item_name END')
            ->orderByDesc('quantity')
            ->get()
            ->map(fn ($row) => [
                'meal_menu_item_id' => $row->meal_menu_item_id === null ? null : (int) $row->meal_menu_item_id,
                'item_name' => (string) $row->item_name,
                'quantity' => (int) $row->quantity,
            ])
            ->values();

        $summary = [
            'orders' => (clone $all)->count(),
            'paid_orders' => (clone $paid)->count(),
            'unpaid_orders' => (clone $all)->where('payment_status', MealOrder::PAYMENT_UNPAID)->count(),
            'picked_up' => (clone $all)->where('status', MealOrder::STATUS_PICKED_UP)->count(),
            'items_ordered' => (int) $itemsByItem->sum('quantity'),
            // Shown beside the item count, so the Orders tile (which includes
            // cancelled orders) and Items ordered (which doesn't) reconcile.
            'cancelled_orders' => (clone $all)->where('status', MealOrder::STATUS_CANCELLED)->count(),
            'items_by_item' => $itemsByItem,
            // What actually SETTLED, which is the order's total until an edit
            // moves it: staff can change a paid order's items, and the money the
            // masjid has is the amount that was paid, not the new price of the
            // food. `settled_total_minor` is NULL on every order nobody has
            // edited since paying, so nothing needed backfilling.
            'revenue_paid_minor' => (int) (clone $paid)->sum(DB::raw('COALESCE(settled_total_minor, total_minor)')),
            // The optional extra, kept separate from food revenue in both
            // columns: what has actually settled, and what is still owed on
            // live orders. `revenue_paid_minor` already includes it.
            'donations_paid_minor' => (int) (clone $paid)->sum('donation_minor'),
            'fees_covered_paid_minor' => (int) (clone $paid)->sum('fee_covered_minor'),
            'expected_total_minor' => (int) (clone $all)
                ->whereIn('status', [
                    MealOrder::STATUS_PENDING,
                    MealOrder::STATUS_CONFIRMED,
                    MealOrder::STATUS_READY,
                    MealOrder::STATUS_PICKED_UP,
                ])->sum('total_minor'),
            'donations_expected_minor' => (int) (clone $all)
                ->whereIn('status', [
                    MealOrder::STATUS_PENDING,
                    MealOrder::STATUS_CONFIRMED,
                    MealOrder::STATUS_READY,
                    MealOrder::STATUS_PICKED_UP,
                ])->sum('donation_minor'),
        ];

        return response()->json([
            'status' => 'success',
            'data' => [
                'menu' => $menu,
                'summary' => $summary,
                'orders' => $orders,
            ],
        ], Response::HTTP_OK);
    }

    /**
     * POST .../menus/{menu_id}/orders — an order taken by STAFF on the board:
     * at the table after Jummah, over the phone, or for someone without the
     * link. Admins, SuperAdmins and lunch volunteers (routes/lunch.php) share it.
     *
     * Same pricing rule as the public page — every price comes from the menu,
     * never the request — with three deliberate differences:
     *   - the online ordering window does not apply: walk-ups happen after
     *     online ordering closes. Only a DRAFT menu (not yet opened) refuses;
     *   - an order never starts paid: it is charged through Stripe like any
     *     public order. The payment page comes back as checkout_url, to open on
     *     this device or send to the customer, and Stripe marks it paid (or staff
     *     do, with Mark paid, when the money comes another way). The
     *     optional extra and covering the card fee are offered on exactly the
     *     public page's rule (LunchOrderExtras);
     *   - no SMS opt-in, ever: consent to texts must come from the customer.
     * The order records who took it (`entered_by_user_id`).
     */
    public function store(StoreStaffMealOrderRequest $request, $masjid_id, $menu_id)
    {
        // Tenant-scoped (BelongsToMasjid): another organisation's menu is a 404.
        $menu = MealMenu::findOrFail($menu_id);

        if ($menu->status === MealMenu::STATUS_DRAFT) {
            return $this->refuse('Open this menu before taking orders on it.');
        }

        $wanted = LunchOrderLines::wanted((array) $request->validated('items'));

        try {
            // The same pricing the public page runs (LunchOrderLines), with the
            // one difference staff need: over the kitchen's cap, say so rather
            // than silently trimming what they were asked for.
            ['lines' => $lines, 'subtotal_minor' => $subtotal] = LunchOrderLines::price(
                $menu,
                $wanted,
                LunchOrderLines::CAP_REFUSE
            );
        } catch (LunchLineRefusal $e) {
            return $this->refuse($this->linesRefusal($e));
        }

        // A kitchen order needs its pickup; the office is not held to the public
        // lead time (it is the one deciding it can make it), only to a time that
        // has not already passed.
        $pickup = null;

        if ($menu->isCatalogue()) {
            try {
                $pickup = filled($request->validated('pickup_at'))
                    ? \Illuminate\Support\Carbon::parse((string) $request->validated('pickup_at'), MasjidTime::zoneFor($menu->masjid_id))->utc()
                    : null;
            } catch (\Throwable) {
                $pickup = null;
            }

            if ($pickup === null || $pickup->isPast()) {
                return $this->refuse('Choose when the customer will pick this order up.');
            }
        }

        // Checked before anything is written, so a refusal leaves no order behind.
        if (! $menu->allow_online_payment) {
            return $this->refuse('Orders added here are paid online through Stripe, and online payment is switched off for this lunch. Switch it on under Edit menu.');
        }

        if (! Masjid::find($menu->masjid_id)?->canAcceptDonations()) {
            return $this->refuse('Orders added here are paid online through Stripe, and this organisation cannot take online payments yet.');
        }

        // An extra on top of the food, and/or the card fee covered so the
        // organisation nets everything: the public page's rule and arithmetic,
        // not a copy of them. Every staff order is online, so the fee can be
        // covered wherever the menu offers it.
        ['donation_minor' => $donation, 'fee_covered_minor' => $feeCovered] = LunchOrderExtras::compute(
            $menu,
            $subtotal,
            (int) ($request->validated('donation_minor') ?? 0),
            $request->boolean('cover_fees'),
            true,
        );

        try {
            $order = DB::transaction(function () use ($request, $menu, $lines, $subtotal, $donation, $feeCovered, $pickup) {
                $order = new MealOrder([
                    'meal_menu_id' => $menu->id,
                    'customer_name' => trim((string) $request->validated('customer_name')),
                    'customer_phone' => trim((string) ($request->validated('customer_phone') ?? '')),
                    'customer_email' => $request->validated('customer_email'),
                    'customer_notes' => $request->validated('customer_notes'),
                    'payment_method' => MealOrder::METHOD_ONLINE,
                ]);
                $order->masjid_id = $menu->masjid_id;
                $order->currency = $menu->currency;
                $order->subtotal_minor = $subtotal;
                $order->donation_minor = $donation;
                $order->fee_covered_minor = $feeCovered;
                $order->total_minor = $subtotal + $donation + $feeCovered;
                $order->order_number = MealOrder::nextOrderNumber((int) $menu->masjid_id, (int) $menu->id);
                $order->placed_at = now();
                $order->source = MealOrder::SOURCE_STAFF;
                $order->entered_by_user_id = $request->user()?->id;
                $order->pickup_at = $pickup;
                // Never paid at creation: Stripe marks it paid when the customer pays.
                $order->save();

                foreach ($lines as $line) {
                    $order->items()->create(array_merge($line, ['masjid_id' => $menu->masjid_id]));
                }

                return $order;
            }, 3); // retried on a deadlock rather than failing the customer
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'failed',
                'data' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // The order stands whatever Stripe says; "Payment link" on the board
        // makes its page again.
        $checkoutUrl = null;
        $retry = 'Use "Payment link" on the order to try again.';

        try {
            $checkoutUrl = $this->checkout->checkout($order->load('items'))['checkout_url'] ?: null;
            $message = "Order #{$order->order_number} added. Open the payment page or send the link to the customer.";
        } catch (\Illuminate\Database\QueryException $e) {
            // Before RuntimeException, which it extends: never show SQL to staff.
            report($e);
            $message = "Order #{$order->order_number} added, but the payment page could not be created. {$retry}";
        } catch (\RuntimeException $e) {
            $message = "Order #{$order->order_number} added, but the payment page could not be created: {$e->getMessage()} {$retry}";
        } catch (\Stripe\Exception\ExceptionInterface $e) {
            report($e);
            $message = "Order #{$order->order_number} added, but Stripe did not answer. {$retry}";
        }

        return response()->json([
            'status' => 'success',
            'message' => $message,
            'data' => $order->load(['items', 'enteredBy:id,name']),
            'checkout_url' => $checkoutUrl,
        ], Response::HTTP_CREATED);
    }

    /**
     * PATCH .../orders/{order_id}/items — staff change what is ON an order.
     *
     * The reason this exists and the customer's own edit is not enough: the
     * cutoff. Ordering closes so the kitchen can count plates, and the requests
     * that arrive after it — "can you make that three?", someone turning up for a
     * friend — are exactly the ones staff have to handle. So there is NO cutoff
     * check here, and a menu that has closed is still editable.
     *
     * A PAID order may be edited too, and this is the part to be careful about:
     *   - nothing here touches `payment_status`, `paid_at`, `paid_via` or who
     *     recorded the payment. An edit is not a payment, and money must never be
     *     marked settled by an edit;
     *   - what was actually paid is remembered the first time (`settled_total_minor`),
     *     so the difference shows as `balance_minor` on the order: POSITIVE is
     *     still owed by the customer, NEGATIVE is owed back to them. The board
     *     shows it, and staff settle it with the customer OUTSIDE this system —
     *     a paid order cannot be given a payment page (the checkout service
     *     refuses one before Stripe is touched) and Mark paid does nothing to an
     *     order that is already paid, so the balance is a note to act on, and it
     *     stays on the board until somebody edits the order back;
     *   - an UNPAID order's open card page is for the old amount, so it is closed
     *     first, and a replacement for the new total is made straight away and
     *     handed back as `checkout_url`. The customer is holding that link; an
     *     edit must not be the thing that takes away their only way to pay. If
     *     Stripe will not close the old page, nothing is changed at all.
     * Prices always come from the menu, the customer's optional extra is left
     * alone, and an order may not be emptied — cancelling is its own action.
     */
    public function updateItems(EditMealOrderItemsRequest $request, $masjid_id, $menu_id, $order_id)
    {
        // Tenant-scoped (BelongsToMasjid): another organisation's menu or order
        // is a 404, never a row this board can touch.
        $menu = MealMenu::findOrFail($menu_id);
        $order = MealOrder::where('meal_menu_id', $menu->id)->with('items')->findOrFail($order_id);

        if (($refusal = self::staffMayEdit($order)) !== null) {
            return $this->refuse($refusal);
        }

        $wanted = LunchOrderLines::wanted((array) $request->validated('items'), 'meal_menu_item_id');

        if ($wanted === []) {
            return $this->refuse(self::EDIT_FLOOR);
        }

        // Staff are allowed to take a line off an order — that is half of what
        // this endpoint is for — so an id left out of the body is a removal. It
        // is NOT a removal when the dish cannot be named any more: the dialog
        // does not render an unavailable or deleted dish, so its line could only
        // ever leave silently, and on a paid order that quietly lowers the total
        // and puts money on the board as owed back. The dialog already says so
        // and disables Save; this is the same answer where it cannot be skipped.
        if (($gone = LunchOrderLines::unreachable($menu, $order->items, $wanted)) !== []) {
            return $this->refuse(sprintf(self::EDIT_ITEM_GONE, implode(', ', $gone)));
        }

        // The customer may be holding a page to pay for a change of their own.
        // Close it first: once staff have changed the order, that change no longer
        // describes it, and paying it would only be money to hand back.
        try {
            $this->checkout->closeOpenTopUps($order);
        } catch (\Stripe\Exception\ExceptionInterface $e) {
            report($e);

            return $this->refuse(self::TOP_UP_NOT_CLOSED);
        } catch (\RuntimeException $e) {
            return $this->refuse($e->getMessage() === MealOrderCheckoutService::TOP_UP_CONFIRMING
                ? self::TOP_UP_JUST_PAID
                : self::TOP_UP_NOT_CLOSED);
        }

        try {
            $result = $this->editor->apply(
                $order,
                $menu,
                $wanted,
                MealOrderEditor::ACTOR_STAFF,
                $request->user()?->id,
                function (MealOrder $locked) use ($menu, $wanted) {
                    // Asked again on the locked row: a cancellation, a refund, or
                    // a dish taken off the menu while this request waited must
                    // still refuse.
                    if (($refusal = self::staffMayEdit($locked)) !== null) {
                        throw new \RuntimeException($refusal);
                    }

                    if (($gone = LunchOrderLines::unreachable($menu, $locked->items, $wanted)) !== []) {
                        throw new \RuntimeException(sprintf(self::EDIT_ITEM_GONE, implode(', ', $gone)));
                    }
                }
            );
        } catch (LunchLineRefusal $e) {
            return $this->refuse($this->linesRefusal($e));
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            // Also a RuntimeException: the order went between the read and the
            // lock. Answered as the miss it is, never with a model's own words.
            return response()->json(['status' => 'failed', 'data' => 'That order is no longer on this menu.'], Response::HTTP_NOT_FOUND);
        } catch (\Illuminate\Database\QueryException $e) {
            // Before RuntimeException, which it extends: never show SQL to staff.
            return $this->failed($e);
        } catch (\Stripe\Exception\ExceptionInterface $e) {
            // Before RuntimeException too: some of the SDK's own errors extend it.
            report($e);

            return $this->refuse('Stripe did not answer, so this order\'s card payment link could not be closed and nothing was changed. Try again in a moment.');
        } catch (\RuntimeException $e) {
            return $this->refuse($e->getMessage());
        } catch (\Exception $e) {
            return $this->failed($e);
        }

        // The order was holding an unpaid card page for the old amount, and the
        // edit closed it. Make the replacement HERE rather than leaving a
        // sentence asking staff to remember to press "Payment link": the customer
        // is holding a link that no longer works, and the only thing standing
        // between them and no way to pay at all was somebody reading a message.
        $checkoutUrl = null;
        $pageFailed = false;

        if ($result['page_closed']) {
            try {
                $checkoutUrl = $this->checkout->paymentLink($result['order'], null)['checkout_url'] ?: null;
            } catch (\Throwable $e) {
                // The edit is committed and stands. Only the new page failed.
                report($e);
                $pageFailed = true;
            }
        }

        $order = $result['order']->fresh()->load(['items', 'enteredBy:id,name', 'markedPaidBy:id,name']);

        return response()->json([
            'status' => 'success',
            'message' => self::editMessage($order, (bool) $result['changed'], (bool) $result['page_closed'], $checkoutUrl, $pageFailed),
            'changed' => (bool) $result['changed'],
            'data' => $order,
            'checkout_url' => $checkoutUrl,
        ], Response::HTTP_OK);
    }

    /** Why staff may not change this order's items right now, or null when they may. */
    private static function staffMayEdit(MealOrder $order): ?string
    {
        if ($order->status === MealOrder::STATUS_CANCELLED) {
            return 'This order was cancelled. Set it back to confirmed first.';
        }

        if ($order->payment_status === MealOrder::PAYMENT_REFUNDED) {
            return 'This order was refunded, so its items cannot be changed.';
        }

        return null;
    }

    /**
     * What the board is told afterwards. When the money moved on an order that
     * was already paid, the sentence SAYS SO — in money, and as something still
     * to be done. Nothing in the system settles a balance.
     */
    private static function editMessage(
        MealOrder $order,
        bool $changed,
        bool $pageClosed,
        ?string $checkoutUrl = null,
        bool $pageFailed = false
    ): string {
        if (! $changed) {
            return "Nothing changed on order #{$order->order_number}.";
        }

        $balance = (int) $order->balance_minor;

        if ($order->payment_status === MealOrder::PAYMENT_PAID && $balance > 0) {
            return "Order #{$order->order_number} updated. The customer still owes " . self::money($balance) . ' — it has not been collected.';
        }

        if ($order->payment_status === MealOrder::PAYMENT_PAID && $balance < 0) {
            return "Order #{$order->order_number} updated. " . self::money(-$balance) . ' is owed back to the customer — refund it in Stripe or by hand.';
        }

        if ($checkoutUrl !== null) {
            return "Order #{$order->order_number} updated. Its old payment link stopped working, so a new one for the new total is ready to send — the customer's old link no longer works.";
        }

        if ($pageFailed) {
            return "Order #{$order->order_number} updated, but its old payment link was closed and a new one could not be made. Press \"Payment link\" to try again — until then the customer has no way to pay.";
        }

        if ($pageClosed) {
            return "Order #{$order->order_number} updated. Its old payment link was closed; press \"Payment link\" to make one for the new total.";
        }

        return "Order #{$order->order_number} updated.";
    }

    /** Minor units as the board writes money. */
    private static function money(int $minor): string
    {
        return '$' . number_format($minor / 100, 2);
    }

    /**
     * POST .../orders/{order_id}/payment-link — a Stripe payment page for an
     * unpaid order: open it on this device, or send it to the customer. An
     * open page is reused, so nobody holds two ways to pay; an expired one is
     * replaced. A pay-at-pickup order becomes an online one (on the locked row,
     * in the service); Mark paid closes the page it gets before recording money
     * taken by hand, so the two can never both be taken.
     * Paid and cancelled orders are refused, as is a lunch with online payment
     * switched off. Stripe marks the order paid when the customer pays
     * (StripeWebhookController).
     */
    public function paymentLink(CreatePaymentLinkRequest $request, $masjid_id, $menu_id, $order_id)
    {
        $menu = MealMenu::findOrFail($menu_id);
        $order = MealOrder::where('meal_menu_id', $menu->id)->with('items')->findOrFail($order_id);

        if ($order->payment_status === MealOrder::PAYMENT_PAID) {
            return $this->refuse('This order is already paid.');
        }

        if ($order->status === MealOrder::STATUS_CANCELLED) {
            return $this->refuse('This order was cancelled. Set it back to confirmed first.');
        }

        if (! $menu->allow_online_payment) {
            return $this->refuse('Online payment is switched off for this lunch.');
        }

        // What staff chose in the dialog, each left out when they were not asked.
        // The service prices them on the locked row; with neither, an open page
        // is reused as it is.
        $choices = null;
        if ($request->has('donation_minor') || $request->has('cover_fees')) {
            $donation = $request->validated('donation_minor');
            $choices = [
                'donation_minor' => $donation === null ? null : (int) $donation,
                'cover_fees' => $request->has('cover_fees') ? $request->boolean('cover_fees') : null,
            ];
        }

        try {
            $result = $this->checkout->paymentLink($order, $choices);
        } catch (\Illuminate\Database\QueryException $e) {
            // Before RuntimeException, which it extends: never show SQL to staff.
            report($e);

            return $this->refuse('That order is busy. Try again in a moment.');
        } catch (\RuntimeException $e) {
            return $this->refuse($e->getMessage());
        } catch (\Stripe\Exception\ExceptionInterface $e) {
            report($e);

            return $this->refuse('Stripe did not answer. Try again in a moment.');
        }

        return response()->json([
            'status' => 'success',
            'message' => "Payment page ready for order #{$order->order_number}.",
            'data' => [
                'checkout_url' => $result['checkout_url'],
                'order' => $order->fresh()->load(['items', 'enteredBy:id,name']),
            ],
        ], Response::HTTP_OK);
    }

    private function refuse(string $message)
    {
        return response()->json(['status' => 'failed', 'data' => $message], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * The board's words for a refusal from the shared pricing (LunchOrderLines).
     * Staff are looking at the menu, so "not on this menu" is the useful fact;
     * the customer's page says "no longer available" for the same refusal.
     */
    private function linesRefusal(LunchLineRefusal $e): string
    {
        return match ($e->kind) {
            LunchLineRefusal::EMPTY_ORDER => 'Add at least one item.',
            LunchLineRefusal::UNAVAILABLE => 'One or more items are not on this menu or are marked unavailable.',
            default => $e->getMessage(),
        };
    }

    public function show($masjid_id, $menu_id, $order_id)
    {
        $order = MealOrder::where('meal_menu_id', $menu_id)
            ->with('items')
            ->findOrFail($order_id);

        return response()->json([
            'status' => 'success',
            'data' => $order,
        ], Response::HTTP_OK);
    }

    /**
     * Move an order's fulfilment status. `picked_up` stamps picked_up_at through
     * the model helper; the others are a plain transition. On the LOCKED row: a
     * cancel waits for a payment page being made that same second and then
     * closes that page, never one read before it existed; a page asked for after
     * the cancel is refused under the same lock (MealOrderCheckoutService).
     */
    public function updateStatus(UpdateMealOrderStatusRequest $request, $masjid_id, $menu_id, $order_id)
    {
        MealOrder::where('meal_menu_id', $menu_id)->findOrFail($order_id);
        $status = (string) $request->validated('status');
        $userId = $request->user()?->id;

        try {
            [$order, $message, $warning, $confirmedNow] = DB::transaction(function () use ($menu_id, $order_id, $status, $userId) {
                $order = MealOrder::where('meal_menu_id', $menu_id)->lockForUpdate()->findOrFail($order_id);

                if ($status === MealOrder::STATUS_PICKED_UP) {
                    $order->markPickedUp();
                } else {
                    $order->status = $status;
                    $order->save();
                }

                // A kitchen order moving forward (confirmed, ready or collected) IS
                // the office's confirmation (owner: "office confirms"); who and when
                // are recorded the first time only, on the locked row.
                $confirmedNow = in_array($status, self::CONFIRMING_STATUSES, true)
                    && $order->isKitchenOrder()
                    && $order->recordOfficeConfirmation($userId);

                [$message, $warning] = $status === MealOrder::STATUS_CANCELLED
                    ? $this->closePageOfCancelled($order)
                    : [null, false];

                return [$order, $message, $warning, $confirmedNow];
            });

            // After the commit, and never able to fail the change: the customer is
            // told the office confirmed — unless it was only recorded at pickup,
            // when they are standing at the counter already.
            if ($confirmedNow && $status !== MealOrder::STATUS_PICKED_UP) {
                app(KitchenOrderNotifier::class)->confirmed($order->load('items'));
            }

            return response()->json([
                'status' => 'success',
                'message' => $message,
                'warning' => $warning,
                'data' => $order->load('items'),
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'failed',
                'data' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * A cancelled order must not stay payable: close its open Stripe page. The
     * cancellation stands whatever Stripe says, and staff are told what
     * happened; `true` marks an answer they must act on. Cancelling again (the
     * board's "Close payment page") retries a close that failed.
     *
     * @return array{0: ?string, 1: bool}
     */
    private function closePageOfCancelled(MealOrder $order): array
    {
        if ($order->payment_status === MealOrder::PAYMENT_PAID) {
            return $this->closeTopUpsOfCancelled($order);
        }

        if (! $order->stripe_checkout_session_id) {
            return [null, false];
        }

        try {
            $closed = $this->checkout->expireOpenSession($order);
        } catch (\RuntimeException|\Stripe\Exception\ExceptionInterface $e) {
            report($e);

            return ['Cancelled, but its payment page could not be closed, so the link still works. Press "Close payment page" on the order to try again.', true];
        }

        if ($closed === 'complete') {
            return ['This order had already been paid on Stripe, so it will show as paid. Refund it in Stripe if it should not stand.', true];
        }

        if ($closed === 'expired') {
            // No live page is left: forget it, so the board stops offering to close one.
            $order->stripe_checkout_session_id = null;
            $order->save();

            return ['Cancelled, and its payment page is closed.', false];
        }

        return [null, false];
    }

    /**
     * A cancelled PAID order must not keep a page where the customer can pay to
     * add plates to it. The cancellation stands whatever Stripe says; a page that
     * was paid a moment ago lands as money owed back, and staff are told.
     *
     * @return array{0: ?string, 1: bool}
     */
    private function closeTopUpsOfCancelled(MealOrder $order): array
    {
        try {
            $this->checkout->closeOpenTopUps($order);
        } catch (\RuntimeException|\Stripe\Exception\ExceptionInterface $e) {
            if ($e->getMessage() === MealOrderCheckoutService::TOP_UP_CONFIRMING) {
                return ['Cancelled. The customer had just paid to add to this order, so that payment will show as owed back — refund it in Stripe.', true];
            }

            report($e);

            return ['Cancelled, but the customer\'s page to pay for adding to this order could not be closed, so it still works. Cancel the order again to retry.', true];
        }

        return [null, false];
    }

    /**
     * Mark an unpaid order paid by hand, saying how the money came (`paid_via`,
     * MarkMealOrderPaidRequest): any unpaid order that is not cancelled, pickup
     * or online, for anyone who runs the board (DECISIONS.md 2026-09-11).
     *
     * All on the locked row, which every payment page being made takes too, the
     * first one included (MealOrderCheckoutService::checkout, paymentLink). The
     * order's own Stripe page is dealt with first (closePageBeforePaidByHand): an
     * open page is closed and forgotten, so the customer cannot pay it as well; a
     * page paid by card, a bank payment still clearing, a page Stripe would not
     * close and a Stripe that did not answer are each refused, and a refusal
     * records nothing.
     *
     * An order already paid on its own page (the webhook got there first, while
     * this board was up to 15 seconds behind) is refused as well, so what staff are
     * told never depends on how fast Stripe delivers. An order already marked paid
     * by hand is a 200 that changes nothing: who and how are written by the first
     * press only (MealOrder::markPaidByHand), and `recorded: false` says so, with
     * `data` showing what was recorded first. The board words its answer from
     * that, never from the method it sent.
     *
     * `payment_method` stays the channel the order came through; `paid_via` says
     * how the money came.
     */
    public function markPaid(MarkMealOrderPaidRequest $request, $masjid_id, $menu_id, $order_id)
    {
        MealOrder::where('meal_menu_id', $menu_id)->findOrFail($order_id);
        $via = (string) $request->validated('paid_via');

        try {
            [$order, $recorded] = DB::transaction(function () use ($request, $menu_id, $order_id, $via) {
                $order = MealOrder::where('meal_menu_id', $menu_id)->lockForUpdate()->findOrFail($order_id);

                if ($order->payment_status === MealOrder::PAYMENT_PAID) {
                    if ($order->paidOnItsOwnPage()) {
                        throw new \RuntimeException(self::PAID_ON_ITS_PAGE);
                    }

                    return [$order, false];
                }

                if ($order->payment_status !== MealOrder::PAYMENT_UNPAID) {
                    throw new \RuntimeException('This order was refunded, so it cannot be marked paid.');
                }

                // Nobody collects for a meal that is not being made: restore it
                // first, the rule the Payment link button follows.
                if ($order->status === MealOrder::STATUS_CANCELLED) {
                    throw new \RuntimeException('This order was cancelled. Restore it before marking it paid.');
                }

                $this->checkout->closePageBeforePaidByHand($order);

                return [$order, $order->markPaidByHand($via, $request->user()?->id)];
            });
        } catch (\Illuminate\Database\QueryException $e) {
            // Before RuntimeException, which it extends: never show SQL to staff.
            return $this->failed($e);
        } catch (\Stripe\Exception\ExceptionInterface $e) {
            // Before RuntimeException too: some of the SDK's own errors extend it,
            // and their words are not for staff.
            report($e);

            return $this->refuse('Stripe did not answer, so this order\'s card payment link could not be closed and nothing was recorded. Try again in a moment.');
        } catch (\RuntimeException $e) {
            return $this->refuse($e->getMessage());
        } catch (\Exception $e) {
            return $this->failed($e);
        }

        return response()->json([
            'status' => 'success',
            'recorded' => $recorded,
            'data' => $order->load(['items', 'markedPaidBy:id,name']),
        ], Response::HTTP_OK);
    }

    private function failed(\Throwable $e)
    {
        return response()->json([
            'status' => 'failed',
            'data' => Errors::publicMessage($e),
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }

}
