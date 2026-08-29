<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LunchOrders\SubmitLunchOrderRequest;
use App\Models\MealMenu;
use App\Models\MealMenuItem;
use App\Models\MealOrder;
use App\Models\MealOrderItem;
use App\Services\Stripe\MealOrderCheckoutService;
use App\Support\Errors;
use App\Support\PublicTenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The PUBLIC Jummah-lunch ordering surface — the unauthenticated `/api/v1` idiom
 * (AppointmentRequestsController is the template):
 *
 *  - the organisation is the `masjid-id` HEADER and must still exist
 *    (PublicTenant::exists — masjids soft-delete); the same 404 answers a
 *    missing, foreign, or offboarded org, so nothing about tenant ids leaks.
 *  - `/api/v1` never runs the tenant middleware, so masjid_id is stamped
 *    EXPLICITLY here (the BelongsToMasjid hook has nothing bound), and every
 *    read filters masjid_id by hand.
 *  - a honeypot catches naive bots; `throttle:lunch-order` catches the rest.
 *  - PRICES ARE NEVER TRUSTED FROM THE CLIENT — the server re-derives every line
 *    from the menu item's own `price_minor`, exactly as the registration path
 *    re-derives from its snapshot.
 *
 * Payment: `pickup` stores an unpaid order to be settled in person; `online`
 * hands back a Stripe Checkout URL (a direct charge on the org's connected
 * account) and the webhook — never this response — marks the order paid.
 */
class JummahLunchOrdersController extends Controller
{
    public function __construct(private MealOrderCheckoutService $checkout)
    {
    }

    /**
     * GET /api/v1/lunch-menu — the masjid's currently-open menu + its available
     * items, or `{menu: null}` when nothing is open.
     */
    public function menu(Request $request)
    {
        $masjidId = (int) $request->header('masjid-id');

        if ($masjidId <= 0) {
            return response()->api(400, 'A masjid must be specified.', null);
        }

        if (! PublicTenant::exists($masjidId)) {
            return response()->api(404, 'Ordering is not available.', null);
        }

        $menu = MealMenu::withoutMasjidScope()
            ->where('masjid_id', $masjidId)
            ->currentlyOpen()
            ->with(['items' => fn ($q) => $q->where('is_available', true)->orderBy('sort_order')->orderBy('id')])
            ->orderBy('service_date')
            ->first();

        if (! $menu) {
            return response()->api(200, 'No lunch is open for ordering right now.', ['menu' => null]);
        }

        return response()->api(200, 'ok', ['menu' => $this->serializeMenu($menu)]);
    }

    /**
     * POST /api/v1/lunch-orders — place an order against the open menu.
     */
    public function store(SubmitLunchOrderRequest $request)
    {
        try {
            $masjidId = (int) $request->header('masjid-id');

            if ($masjidId <= 0) {
                return response()->api(400, 'A masjid must be specified.', null);
            }

            if (! PublicTenant::exists($masjidId)) {
                return response()->api(404, 'Ordering is not available.', null);
            }

            // A bot filling every input trips this; report success so a scripted
            // submitter gets no signal, while nothing is written.
            if (filled($request->input('website'))) {
                return response()->api(200, 'Thank you — your order has been received.', ['order' => null]);
            }

            $menu = MealMenu::findByUuidForMasjid((string) $request->input('menu_uuid'), $masjidId);

            if (! $menu || ! $menu->isOpenForOrders()) {
                return response()->api(422, 'This lunch is no longer open for ordering.', null);
            }

            $method = (string) $request->input('payment_method');

            if ($method === MealOrder::METHOD_ONLINE && ! $menu->allow_online_payment) {
                return response()->api(422, 'Online payment is not available for this lunch.', null);
            }

            if ($method === MealOrder::METHOD_PICKUP && ! $menu->allow_pay_at_pickup) {
                return response()->api(422, 'Pay at pickup is not available for this lunch.', null);
            }

            // Combine duplicate item ids, then price EVERY line from the item's
            // own price_minor — the client's numbers are never trusted.
            $wanted = [];
            foreach ((array) $request->input('items', []) as $row) {
                $id = (int) ($row['item_id'] ?? 0);
                $qty = (int) ($row['quantity'] ?? 0);
                if ($id > 0 && $qty > 0) {
                    $wanted[$id] = ($wanted[$id] ?? 0) + $qty;
                }
            }

            if ($wanted === []) {
                return response()->api(422, 'Your order is empty.', null);
            }

            $menuItems = MealMenuItem::withoutMasjidScope()
                ->where('masjid_id', $masjidId)
                ->where('meal_menu_id', $menu->id)
                ->where('is_available', true)
                ->whereIn('id', array_keys($wanted))
                ->get()
                ->keyBy('id');

            // Every requested item must resolve to a live item on THIS menu — a
            // missing one means the menu changed under the customer.
            if ($menuItems->count() !== count($wanted)) {
                return response()->api(422, 'One or more items are no longer available — please refresh the menu.', null);
            }

            $lines = [];
            $subtotal = 0;
            foreach ($wanted as $id => $qty) {
                /** @var MealMenuItem $item */
                $item = $menuItems->get($id);

                if ($item->max_quantity !== null && $qty > $item->max_quantity) {
                    $qty = (int) $item->max_quantity; // clamp to the kitchen's cap
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

            $order = DB::transaction(function () use ($masjidId, $menu, $method, $request, $lines, $subtotal) {
                // A pickup number unique within this menu; the count is locked so
                // two concurrent orders can't claim the same one.
                $seq = MealOrder::withoutMasjidScope()
                    ->where('masjid_id', $masjidId)
                    ->where('meal_menu_id', $menu->id)
                    ->lockForUpdate()
                    ->count() + 1;

                $order = new MealOrder([
                    'meal_menu_id' => $menu->id,
                    'customer_name' => trim((string) $request->input('customer_name')),
                    'customer_phone' => trim((string) $request->input('customer_phone')),
                    'customer_email' => $request->input('customer_email'),
                    'customer_notes' => $request->input('customer_notes'),
                    'payment_method' => $method,
                ]);
                // /api/v1 runs UNBOUND, so stamp the tenant explicitly.
                $order->masjid_id = $masjidId;
                $order->currency = $menu->currency;
                $order->subtotal_minor = $subtotal;
                $order->total_minor = $subtotal;
                $order->order_number = str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
                $order->placed_at = now();
                $order->save();

                foreach ($lines as $line) {
                    $order->items()->create(array_merge($line, ['masjid_id' => $masjidId]));
                }

                return $order;
            });

            if ($method === MealOrder::METHOD_ONLINE) {
                try {
                    // Return to the SAME site the order was placed from (this page
                    // is proxied onto masjids' own domains), not always APP_URL —
                    // but only to an origin the CORS allowlist already trusts, so a
                    // spoofed Origin header can never redirect a payer off-platform.
                    $result = $this->checkout->checkout(
                        $order->load('items'),
                        $this->returnUrlsFor($request, $masjidId, $order->uuid)
                    );

                    return response()->api(200, 'ok', [
                        'order' => $this->serializeOrder($result['order']),
                        'checkout_url' => $result['checkout_url'],
                    ]);
                } catch (\RuntimeException $e) {
                    // The order is saved (unpaid); surface why checkout couldn't open.
                    return response()->api(422, $e->getMessage(), [
                        'order' => $this->serializeOrder($order),
                    ]);
                }
            }

            return response()->api(200, 'Thank you — your order is in. Pay when you pick up after Jummah.', [
                'order' => $this->serializeOrder($order->load('items')),
            ]);
        } catch (\Exception $e) {
            return response()->api(500, Errors::publicMessage($e), null);
        }
    }

    /**
     * GET /api/v1/lunch-orders/{uuid} — public order status (the return page
     * after Stripe, or a shareable confirmation link).
     */
    public function show(Request $request, string $uuid)
    {
        $masjidId = (int) $request->header('masjid-id');

        if ($masjidId <= 0) {
            return response()->api(400, 'A masjid must be specified.', null);
        }

        if (! PublicTenant::exists($masjidId)) {
            return response()->api(404, 'Ordering is not available.', null);
        }

        $order = MealOrder::findByUuidForMasjid($uuid, $masjidId);

        if (! $order) {
            return response()->api(404, 'Order not found.', null);
        }

        return response()->api(200, 'ok', ['order' => $this->serializeOrder($order->load('items'))]);
    }

    /**
     * Stripe return URLs pointing back at the SITE the order was placed from.
     *
     * This page is proxied onto masjids' own domains (e.g.
     * burlingtonmasjid.com/jummah-lunch/{id}), so the browser's Origin is that
     * domain. Only an origin already in the CORS allowlist is honoured, so a
     * forged Origin header cannot make Stripe redirect a payer to a stranger's
     * site — anything else returns [] and the checkout service falls back to
     * APP_URL.
     *
     * @return array{success_url?:string,cancel_url?:string}
     */
    private function returnUrlsFor(Request $request, int $masjidId, string $uuid): array
    {
        $origin = rtrim((string) $request->headers->get('Origin'), '/');

        $allowed = array_map(
            fn ($o) => rtrim((string) $o, '/'),
            (array) config('cors.allowed_origins', [])
        );

        if ($origin === '' || ! in_array($origin, $allowed, true)) {
            return [];
        }

        $base = $origin . '/jummah-lunch/' . $masjidId . '/order/' . $uuid;

        return [
            'success_url' => $base . '?paid=1&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $base . '?cancelled=1',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function serializeMenu(MealMenu $menu): array
    {
        return [
            'uuid' => $menu->uuid,
            'title' => $menu->title,
            'title_ar' => $menu->title_ar,
            'service_date' => optional($menu->service_date)->toDateString(),
            'pickup_instructions' => $menu->pickup_instructions,
            'pickup_instructions_ar' => $menu->pickup_instructions_ar,
            'flyer_image_url' => $menu->flyer_image_url,
            'ordering_closes_at' => optional($menu->ordering_closes_at)->toIso8601String(),
            'allow_online_payment' => (bool) $menu->allow_online_payment,
            'allow_pay_at_pickup' => (bool) $menu->allow_pay_at_pickup,
            'currency' => $menu->currency,
            'items' => $menu->items->map(fn (MealMenuItem $i) => [
                'id' => $i->id,
                'name' => $i->name,
                'name_ar' => $i->name_ar,
                'description' => $i->description,
                'description_ar' => $i->description_ar,
                'price_minor' => (int) $i->price_minor,
                'max_quantity' => $i->max_quantity,
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function serializeOrder(MealOrder $order): array
    {
        return [
            'uuid' => $order->uuid,
            'order_number' => $order->order_number,
            'customer_name' => $order->customer_name,
            'status' => $order->status,
            'payment_method' => $order->payment_method,
            'payment_status' => $order->payment_status,
            'subtotal_minor' => (int) $order->subtotal_minor,
            'total_minor' => (int) $order->total_minor,
            'currency' => $order->currency,
            'placed_at' => optional($order->placed_at)->toIso8601String(),
            'items' => $order->relationLoaded('items')
                ? $order->items->map(fn (MealOrderItem $i) => [
                    'item_name' => $i->item_name,
                    'unit_price_minor' => (int) $i->unit_price_minor,
                    'quantity' => (int) $i->quantity,
                    'line_total_minor' => (int) $i->line_total_minor,
                ])->values()->all()
                : [],
        ];
    }
}
