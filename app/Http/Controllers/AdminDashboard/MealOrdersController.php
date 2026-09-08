<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MealMenus\UpdateMealOrderStatusRequest;
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
            ->with('items')
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
            'expected_total_minor' => (int) (clone $all)
                ->whereIn('status', [
                    MealOrder::STATUS_PENDING,
                    MealOrder::STATUS_CONFIRMED,
                    MealOrder::STATUS_READY,
                    MealOrder::STATUS_PICKED_UP,
                ])->sum('total_minor'),
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
