<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MealMenus\StoreStaffMealOrderRequest;
use App\Http\Requests\Admin\MealMenus\UpdateMealOrderStatusRequest;
use App\Models\MealMenuItem;
use Illuminate\Support\Facades\DB;
use App\Models\MealMenu;
use App\Models\MealOrder;
use App\Support\Errors;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin: the order board for one Jummah-lunch menu — the kitchen's live list,
 * plus the two things staff do to an order: mark it paid (for a pay-at-pickup
 * order settled in person) and move its fulfilment status (ready / picked up).
 *
 * Tenant-scoped by the bound tenant. An online order's PAID state is owned by
 * the Stripe webhook, never set here — `markPaid` is for pay-at-pickup orders.
 */
class MealOrdersController extends Controller
{
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
            ->with(['items', 'enteredBy:id,name'])
            ->orderByDesc('placed_at')
            ->orderByDesc('id')
            ->get();

        // Board summary over the WHOLE menu (not the filtered slice).
        $all = MealOrder::query()->where('meal_menu_id', $menu->id);
        $paid = (clone $all)->where('payment_status', MealOrder::PAYMENT_PAID);

        $summary = [
            'orders' => (clone $all)->count(),
            'paid_orders' => (clone $paid)->count(),
            'unpaid_orders' => (clone $all)->where('payment_status', MealOrder::PAYMENT_UNPAID)->count(),
            'picked_up' => (clone $all)->where('status', MealOrder::STATUS_PICKED_UP)->count(),
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
     *   - payment is always in person (no Stripe, so no card-fee coverage and
     *     no online donation), recorded as paid now or owed at pickup;
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

        $paid = $request->boolean('paid');

        try {
            $order = DB::transaction(function () use ($request, $menu, $lines, $subtotal, $paid) {
                $order = new MealOrder([
                    'meal_menu_id' => $menu->id,
                    'customer_name' => trim((string) $request->validated('customer_name')),
                    'customer_phone' => trim((string) ($request->validated('customer_phone') ?? '')),
                    'customer_email' => $request->validated('customer_email'),
                    'customer_notes' => $request->validated('customer_notes'),
                    'payment_method' => MealOrder::METHOD_PICKUP,
                ]);
                $order->masjid_id = $menu->masjid_id;
                $order->currency = $menu->currency;
                $order->subtotal_minor = $subtotal;
                $order->total_minor = $subtotal;
                $order->order_number = MealOrder::nextOrderNumber((int) $menu->masjid_id, (int) $menu->id);
                $order->placed_at = now();
                $order->source = MealOrder::SOURCE_STAFF;
                $order->entered_by_user_id = $request->user()?->id;

                if ($paid) {
                    $order->payment_status = MealOrder::PAYMENT_PAID;
                    $order->paid_at = now();
                    $order->status = MealOrder::STATUS_CONFIRMED;
                }

                $order->save();

                foreach ($lines as $line) {
                    $order->items()->create(array_merge($line, ['masjid_id' => $menu->masjid_id]));
                }

                return $order;
            });
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'failed',
                'data' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return response()->json([
            'status' => 'success',
            'message' => "Order #{$order->order_number} added" . ($paid ? ' and marked paid.' : ' — to be paid at pickup.'),
            'data' => $order->load(['items', 'enteredBy:id,name']),
        ], Response::HTTP_CREATED);
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
     * the model helper; the others are a plain transition.
     */
    public function updateStatus(UpdateMealOrderStatusRequest $request, $masjid_id, $menu_id, $order_id)
    {
        $order = MealOrder::where('meal_menu_id', $menu_id)->findOrFail($order_id);

        try {
            $status = (string) $request->validated('status');

            if ($status === MealOrder::STATUS_PICKED_UP) {
                $order->markPickedUp();
            } else {
                $order->status = $status;
                $order->save();
            }

            return response()->json([
                'status' => 'success',
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
     * Mark a PAY-AT-PICKUP order paid, in person. Refuses an online order — its
     * paid state is the webhook's to set, and letting staff flip it here would
     * fake a settlement Stripe never confirmed.
     */
    public function markPaid($masjid_id, $menu_id, $order_id)
    {
        $order = MealOrder::where('meal_menu_id', $menu_id)->findOrFail($order_id);

        if ($order->payment_method === MealOrder::METHOD_ONLINE) {
            return response()->json([
                'status' => 'failed',
                'data' => 'An online order is marked paid by Stripe, not by hand.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $order->markPaid();

            return response()->json([
                'status' => 'success',
                'data' => $order->load('items'),
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'failed',
                'data' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
