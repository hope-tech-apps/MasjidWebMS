<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Masjid;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LunchOrders\EditLunchOrderRequest;
use App\Http\Requests\Api\V1\LunchOrders\SubmitLunchOrderRequest;
use App\Models\MealMenu;
use App\Models\MealMenuItem;
use App\Models\MealOrder;
use App\Models\MealOrderItem;
use App\Services\Stripe\MealOrderCheckoutService;
use App\Support\Errors;
use App\Services\Lunch\LunchSmsOptIn;
use App\Services\Lunch\MealOrderEditor;
use App\Support\LunchLineRefusal;
use App\Support\LunchOrderExtras;
use App\Support\LunchOrderLines;
use App\Support\StripeFees;
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
    /**
     * The sentences a customer is given when their order cannot be changed. Each
     * one says what is true and what they can do about it; none of them mention
     * an endpoint, a status column or a menu id.
     */
    private const EDIT_CLOSED = 'Orders for this menu are closed.';

    private const EDIT_PAID = 'This order is already paid. Please contact the masjid to change it.';

    private const EDIT_REFUNDED = 'This order was refunded. Please contact the masjid to change it.';

    private const EDIT_CANCELLED = 'This order was cancelled. Please contact the masjid to change it.';

    private const EDIT_FLOOR = 'An order must keep at least one plate. Please contact the masjid to cancel it.';

    /**
     * A line whose menu item has since been DELETED (meal_order_items.
     * meal_menu_item_id goes null, the snapshotted name and price stand). The
     * edit body names lines by item id, so such a line cannot be sent back — an
     * edit would silently drop it and quietly reduce the order. The page is told
     * not to offer the controls at all, and says why.
     */
    private const EDIT_ITEM_GONE = 'Part of this order is no longer on the menu. Please contact the masjid to change it.';

    public function __construct(
        private MealOrderCheckoutService $checkout,
        private MealOrderEditor $editor
    ) {
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

        if (! PublicTenant::exists($masjidId) || ! self::lunchIsOn($masjidId)) {
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

            if (! PublicTenant::exists($masjidId) || ! self::lunchIsOn($masjidId)) {
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
            // own price_minor — the client's numbers are never trusted. The loop
            // lives in LunchOrderLines, shared with the staff board and with both
            // edit endpoints, so no two doors can price a plate differently.
            $wanted = LunchOrderLines::wanted((array) $request->input('items', []));

            try {
                // The public page TRIMS an order over the kitchen's cap rather
                // than refusing it, which is what it has always done.
                ['lines' => $lines, 'subtotal_minor' => $subtotal] = LunchOrderLines::price(
                    $menu,
                    $wanted,
                    LunchOrderLines::CAP_CLAMP
                );
            } catch (LunchLineRefusal $e) {
                return response()->api(422, $e->getMessage(), null);
            }

            // The optional extra (the one figure the CUSTOMER sets) and Stripe's
            // fee if they cover it — on the rule the staff board shares
            // (LunchOrderExtras): the extra clamped, and zeroed when this menu
            // does not offer it; the fee a yes/no whose amount comes from the
            // published rate, never the body, and only on an online order.
            ['donation_minor' => $donation, 'fee_covered_minor' => $feeCovered] = LunchOrderExtras::compute(
                $menu,
                $subtotal,
                (int) $request->input('donation_minor', 0),
                $request->boolean('cover_fees'),
                $method === MealOrder::METHOD_ONLINE,
            );

            $order = DB::transaction(function () use ($masjidId, $menu, $method, $request, $lines, $subtotal, $donation, $feeCovered) {
                // A pickup number unique within this menu; the count is locked so
                // two concurrent orders can't claim the same one.
                $orderNumber = MealOrder::nextOrderNumber($masjidId, $menu->id);

                $order = new MealOrder([
                    'meal_menu_id' => $menu->id,
                    'customer_name' => trim((string) $request->input('customer_name')),
                    'customer_phone' => trim((string) $request->input('customer_phone')),
                    // Dropped when the masjid turned the field off. Hiding an
                    // input does not stop a crafted request from carrying one,
                    // and storing an address the organisation deliberately chose
                    // not to ask for is the whole thing they were avoiding.
                    'customer_email' => $menu->collect_customer_email
                        ? $request->input('customer_email')
                        : null,
                    'customer_notes' => $request->input('customer_notes'),
                    'payment_method' => $method,
                ]);
                // /api/v1 runs UNBOUND, so stamp the tenant explicitly.
                $order->masjid_id = $masjidId;
                $order->currency = $menu->currency;
                $order->subtotal_minor = $subtotal;
                $order->donation_minor = $donation;
                $order->fee_covered_minor = $feeCovered;
                $order->total_minor = $subtotal + $donation + $feeCovered;
                $order->order_number = $orderNumber;
                $order->placed_at = now();
                $order->save();

                foreach ($lines as $line) {
                    $order->items()->create(array_merge($line, ['masjid_id' => $masjidId]));
                }

                return $order;
            }, 3); // retried on a deadlock rather than failing the customer

            // After the order is safely written, never before, and never in a
            // way that can fail it. See LunchSmsOptIn.
            if ($request->boolean('notify_sms')) {
                app(LunchSmsOptIn::class)->attempt(
                    $menu,
                    $masjidId,
                    $order->customer_phone,
                    $order->customer_name,
                    LunchSmsOptIn::DISCLOSURE,
                );
            }

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
                        'order' => $this->serializeOrder($result['order'], $menu),
                        'checkout_url' => $result['checkout_url'],
                    ]);
                } catch (\RuntimeException $e) {
                    // The order is saved (unpaid); surface why checkout couldn't open.
                    return response()->api(422, $e->getMessage(), [
                        'order' => $this->serializeOrder($order, $menu),
                    ]);
                }
            }

            return response()->api(200, 'Thank you — your order is in. Pay when you pick up after Jummah.', [
                'order' => $this->serializeOrder($order->load('items'), $menu),
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

        // Read ONLY to answer "may this be changed?" (can_edit / edit_notice).
        // Looked up only while the organisation's lunch is on, because that is
        // what `update` requires: switched off it answers 404, and a page that
        // offered the controls would be refused. Reading the order itself stays
        // reachable either way, which is the whole point of this endpoint.
        $menu = self::lunchIsOn($masjidId)
            ? MealMenu::withoutMasjidScope()
                ->where('masjid_id', $masjidId)
                ->whereKey($order->meal_menu_id)
                ->first()
            : null;

        return response()->api(200, 'ok', ['order' => $this->serializeOrder($order->load('items'), $menu)]);
    }

    /**
     * PATCH /api/v1/lunch-orders/{uuid} — the customer changes what they ordered,
     * on the same link they already hold. The uuid is the capability, exactly as
     * it is for `show`: nothing else identifies them, and nothing else has to.
     *
     * The body carries the FULL set of lines after the edit; a quantity of 0
     * removes one. Every price is re-derived from the menu (MealOrderEditor), the
     * optional extra they chose is left alone, and the card fee is recomputed only
     * if they were already covering it.
     *
     * Refused while there is money or a deadline in the way — each with a sentence
     * the customer can act on, and each asked AGAIN on the locked row, because the
     * cutoff can pass and a payment can land while this request is in flight:
     *   - ordering for this menu has closed (the whole point of a cutoff is that
     *     the kitchen counts plates after it);
     *   - the order is paid, or was refunded: changing what was paid for is the
     *     masjid's decision, not a public endpoint's;
     *   - the order was cancelled.
     * An order may never drop to zero plates here: cancelling is a conversation
     * with the masjid, not a PATCH with an empty basket.
     */
    public function update(EditLunchOrderRequest $request, string $uuid)
    {
        try {
            $masjidId = (int) $request->header('masjid-id');

            if ($masjidId <= 0) {
                return response()->api(400, 'A masjid must be specified.', null);
            }

            // An edit WRITES, so it stops when the organisation's lunch is
            // switched off, exactly as placing an order does. Reading an existing
            // order's status (show) deliberately stays reachable.
            if (! PublicTenant::exists($masjidId) || ! self::lunchIsOn($masjidId)) {
                return response()->api(404, 'Ordering is not available.', null);
            }

            $order = MealOrder::findByUuidForMasjid($uuid, $masjidId);

            if (! $order) {
                return response()->api(404, 'Order not found.', null);
            }

            $menu = MealMenu::withoutMasjidScope()
                ->where('masjid_id', $masjidId)
                ->whereKey($order->meal_menu_id)
                ->first();

            if (! $menu) {
                return response()->api(409, self::EDIT_CLOSED, null);
            }

            if (($refusal = self::customerMayEdit($order, $menu)) !== null) {
                return response()->api(409, $refusal, null);
            }

            $wanted = LunchOrderLines::wanted((array) $request->validated('items'), 'meal_menu_item_id');

            if ($wanted === []) {
                return response()->api(422, self::EDIT_FLOOR, null);
            }

            try {
                $result = $this->editor->apply(
                    $order,
                    $menu,
                    $wanted,
                    MealOrderEditor::ACTOR_CUSTOMER,
                    null,
                    function (MealOrder $locked) use ($menu) {
                        if (($refusal = self::customerMayEdit($locked, $menu)) !== null) {
                            throw new \RuntimeException($refusal);
                        }
                    }
                );
            } catch (LunchLineRefusal $e) {
                return response()->api(422, $e->getMessage(), null);
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
                // Also a RuntimeException: the order went between the read and the
                // lock. Answered as the miss it is, never with a model's own words.
                return response()->api(404, 'Order not found.', null);
            } catch (\Illuminate\Database\QueryException $e) {
                // Before RuntimeException, which it extends: never show SQL.
                report($e);

                return response()->api(500, 'Your order could not be changed just now. Please try again in a moment.', null);
            } catch (\Stripe\Exception\ExceptionInterface $e) {
                // Before RuntimeException too: some of the SDK's own errors extend it.
                report($e);

                return response()->api(409, 'Your order could not be changed just now. Please try again in a moment.', null);
            } catch (\RuntimeException $e) {
                return response()->api(409, $e->getMessage(), null);
            }

            $order = $result['order'];

            if (! $result['changed']) {
                return response()->api(200, 'Your order is unchanged.', [
                    'order' => $this->serializeOrder($order->load('items'), $menu),
                ]);
            }

            // Their card page was for the old amount and has been closed, so make
            // them a new one for the new amount — otherwise an edit would quietly
            // take away the only way they had to pay. The order stands whatever
            // Stripe says; it is never marked paid here.
            $checkoutUrl = null;
            $pageFailed = false;

            // Whether the order says online or at-pickup: what decides this is
            // that a page WAS closed, which only happens when the order was
            // holding one. Staff send a payment link to pay-at-pickup customers
            // too, and an order that says `pickup` while carrying a live session
            // is a real shape on the live board — reading the method instead of
            // the fact left exactly those customers with a dead link and nothing
            // in its place. Making the page marks the order as paying online,
            // which is what pressing "Payment link" on the board has always done
            // — an order holding a live page is one somebody may pay on it.
            if ($result['page_closed']) {
                try {
                    $checkoutUrl = $this->checkout->paymentLink(
                        $order,
                        null
                    )['checkout_url'] ?: null;
                } catch (\Throwable $e) {
                    report($e);
                    $pageFailed = true;
                }
            }

            $message = match (true) {
                $checkoutUrl !== null => 'Your order has been updated. Use the new payment link to pay the new total — your old one no longer works.',
                $pageFailed => 'Your order has been updated, but a new payment link could not be made. Please contact the masjid to pay.',
                default => 'Your order has been updated.',
            };

            $payload = ['order' => $this->serializeOrder($order->fresh()->load('items'), $menu)];

            if ($checkoutUrl !== null) {
                $payload['checkout_url'] = $checkoutUrl;
            }

            return response()->api(200, $message, $payload);
        } catch (\Exception $e) {
            return response()->api(500, Errors::publicMessage($e), null);
        }
    }

    /**
     * Why this customer may not change this order right now, or null when they
     * may. Asked before the work starts so the answer is cheap, and again on the
     * LOCKED row so a payment or the cutoff landing mid-request still refuses.
     *
     * The order is deliberate: someone who has paid is told they have paid, even
     * when ordering has also closed, because that is the fact they need in order
     * to know what to ask the masjid for.
     */
    private static function customerMayEdit(MealOrder $order, MealMenu $menu): ?string
    {
        if ($order->status === MealOrder::STATUS_CANCELLED) {
            return self::EDIT_CANCELLED;
        }

        if ($order->payment_status === MealOrder::PAYMENT_PAID) {
            return self::EDIT_PAID;
        }

        if ($order->payment_status !== MealOrder::PAYMENT_UNPAID) {
            return self::EDIT_REFUNDED;
        }

        if (! $menu->isOpenForOrders()) {
            return self::EDIT_CLOSED;
        }

        return null;
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
            'collect_customer_email' => (bool) $menu->collect_customer_email,
            'allow_donation' => (bool) $menu->allow_donation,
            'max_donation_minor' => MealOrder::MAX_DONATION_MINOR,
            'allow_fee_coverage' => (bool) $menu->allow_fee_coverage,
            // Offered only once a service has been chosen to subscribe people
            // to; without one there is no audience and the box would collect
            // consent that could never be acted on.
            'allow_sms_optin' => (bool) $menu->allow_sms_optin && $menu->notify_service_id !== null,
            'sms_disclosure' => LunchSmsOptIn::DISCLOSURE,
            // Stripe's published rate, so the form can show the exact surcharge
            // before submitting. The server recomputes it regardless.
            'stripe_fee_percentage' => StripeFees::percentage(),
            'stripe_fee_fixed_minor' => StripeFees::fixed(),
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
     * The public order payload.
     *
     * `$menu` is the order's own menu when the caller already has it. It decides
     * two fields the ORDER PAGE cannot work out for itself:
     *
     *  - `can_edit` — whether the customer may change this order right now. The
     *    page has the payment status and the cancellation, but nothing about the
     *    cutoff, and an editor it offers after the cutoff would only 409;
     *  - `edit_notice` — WHY not, in the same sentence `update` would answer with,
     *    so the page shows the server's own words and never invents its own.
     *
     * Each line carries `meal_menu_item_id` because the edit body names lines by
     * it. It is null on a line whose menu item was deleted, which is exactly the
     * case `can_edit` refuses.
     *
     * @return array<string,mixed>
     */
    private function serializeOrder(MealOrder $order, ?MealMenu $menu = null): array
    {
        $editNotice = self::editNotice($order, $menu);

        return [
            'uuid' => $order->uuid,
            'order_number' => $order->order_number,
            'customer_name' => $order->customer_name,
            'status' => $order->status,
            'payment_method' => $order->payment_method,
            'payment_status' => $order->payment_status,
            'subtotal_minor' => (int) $order->subtotal_minor,
            'donation_minor' => (int) $order->donation_minor,
            'fee_covered_minor' => (int) $order->fee_covered_minor,
            'total_minor' => (int) $order->total_minor,
            'currency' => $order->currency,
            'placed_at' => optional($order->placed_at)->toIso8601String(),
            'can_edit' => $editNotice === null,
            'edit_notice' => $editNotice,
            'items' => $order->relationLoaded('items')
                ? $order->items->map(fn (MealOrderItem $i) => [
                    // Null when the menu item was deleted; the snapshotted name
                    // and price below still say what was ordered.
                    'meal_menu_item_id' => $i->meal_menu_item_id === null ? null : (int) $i->meal_menu_item_id,
                    'item_name' => $i->item_name,
                    'unit_price_minor' => (int) $i->unit_price_minor,
                    'quantity' => (int) $i->quantity,
                    'line_total_minor' => (int) $i->line_total_minor,
                ])->values()->all()
                : [],
        ];
    }

    /**
     * Why the customer may not change this order through the page, or null when
     * they may — the same answers `update` gives, asked ahead of time so the page
     * shows a sentence instead of controls that would be refused.
     *
     * This is a DISPLAY answer, never an authorisation: `update` asks all of it
     * again, and once more on the locked row. A page that offered the controls
     * anyway would still be refused there.
     */
    private static function editNotice(MealOrder $order, ?MealMenu $menu): ?string
    {
        // No menu in hand means the caller could not resolve one (deleted, or the
        // organisation's lunch is switched off, where `update` answers 404). The
        // customer's fact either way is that they cannot change it here.
        if ($menu === null) {
            return self::EDIT_CLOSED;
        }

        if (($refusal = self::customerMayEdit($order, $menu)) !== null) {
            return $refusal;
        }

        // Every line must be able to travel back in the edit body, or saving would
        // drop the one that cannot and reduce the order without anyone asking.
        if ($order->relationLoaded('items')
            && $order->items->contains(fn (MealOrderItem $i) => $i->meal_menu_item_id === null)) {
            return self::EDIT_ITEM_GONE;
        }

        return null;
    }

    /**
     * Friday lunch is an organisation capability (config/capabilities.php).
     * Switched off, its staff board is closed (EnsureOrgCapability), so the
     * public page must not keep taking — and charging — orders nobody at the
     * organisation can see. An existing order's status (show) stays reachable.
     */
    private static function lunchIsOn(int $masjidId): bool
    {
        return (bool) Masjid::find($masjidId)?->hasCapability('jummah_lunch');
    }
}
