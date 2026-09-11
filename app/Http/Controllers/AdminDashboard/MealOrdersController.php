<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MealMenus\CreatePaymentLinkRequest;
use App\Http\Requests\Admin\MealMenus\MarkMealOrderPaidRequest;
use App\Http\Requests\Admin\MealMenus\StoreStaffMealOrderRequest;
use App\Http\Requests\Admin\MealMenus\UpdateMealOrderStatusRequest;
use App\Models\Masjid;
use App\Models\MealMenuItem;
use App\Models\MealOrderItem;
use App\Services\Stripe\MealOrderCheckoutService;
use App\Support\LunchOrderExtras;
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

    public function __construct(private MealOrderCheckoutService $checkout)
    {
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
            ->with(['items', 'enteredBy:id,name', 'markedPaidBy:id,name'])
            ->orderByDesc('placed_at')
            ->orderByDesc('id')
            ->get();

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
            'revenue_paid_minor' => (int) (clone $paid)->sum('total_minor'),
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

        $wanted = [];
        foreach ((array) $request->validated('items') as $row) {
            $id = (int) ($row['item_id'] ?? 0);
            $qty = (int) ($row['quantity'] ?? 0);
            if ($id > 0 && $qty > 0) {
                $wanted[$id] = ($wanted[$id] ?? 0) + $qty;
            }
        }

        if ($wanted === []) {
            return $this->refuse('Add at least one item.');
        }

        $menuItems = MealMenuItem::where('meal_menu_id', $menu->id)
            ->where('is_available', true)
            ->whereIn('id', array_keys($wanted))
            ->get()
            ->keyBy('id');

        if ($menuItems->count() !== count($wanted)) {
            return $this->refuse('One or more items are not on this menu or are marked unavailable.');
        }

        $lines = [];
        $subtotal = 0;
        foreach ($wanted as $id => $qty) {
            $item = $menuItems->get($id);

            // Staff see the cap on the board; say so rather than silently
            // trimming what they were asked for, as the public page does.
            if ($item->max_quantity !== null && $qty > $item->max_quantity) {
                return $this->refuse("Only {$item->max_quantity} × {$item->name} per order.");
            }

            $lineTotal = (int) $item->price_minor * $qty;
            $subtotal += $lineTotal;
            $lines[] = [
                'meal_menu_item_id' => $item->id,
                'item_name' => $item->name,
                'unit_price_minor' => (int) $item->price_minor,
                'quantity' => $qty,
                'line_total_minor' => $lineTotal,
            ];
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
            $order = DB::transaction(function () use ($request, $menu, $lines, $subtotal, $donation, $feeCovered) {
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

        try {
            [$order, $message, $warning] = DB::transaction(function () use ($menu_id, $order_id, $status) {
                $order = MealOrder::where('meal_menu_id', $menu_id)->lockForUpdate()->findOrFail($order_id);

                if ($status === MealOrder::STATUS_PICKED_UP) {
                    $order->markPickedUp();
                } else {
                    $order->status = $status;
                    $order->save();
                }

                [$message, $warning] = $status === MealOrder::STATUS_CANCELLED
                    ? $this->closePageOfCancelled($order)
                    : [null, false];

                return [$order, $message, $warning];
            });

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
        if ($order->payment_status === MealOrder::PAYMENT_PAID || ! $order->stripe_checkout_session_id) {
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
