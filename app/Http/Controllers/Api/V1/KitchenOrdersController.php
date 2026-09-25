<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\KitchenOrders\SubmitKitchenOrderRequest;
use App\Models\Masjid;
use App\Models\MealMenu;
use App\Models\MealMenuItem;
use App\Models\MealOrder;
use App\Models\MealOrderItem;
use App\Services\Kitchen\KitchenOrderNotifier;
use App\Services\Stripe\MealOrderCheckoutService;
use App\Support\AcceptedPaymentMethods;
use App\Support\Errors;
use App\Support\LunchLineRefusal;
use App\Support\LunchOrderLines;
use App\Support\LunchOrderLink;
use App\Support\MasjidTime;
use App\Support\PaymentMethods;
use App\Support\PublicTenant;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The PUBLIC kitchen ordering surface: a standing catalogue (MealMenu kind
 * `catalogue`), pickup at the organisation only, booked at least the menu's lead
 * time ahead, confirmed by the office (owner, 2026-09-21: "Build ordering in
 * Manara" — "Pickup at MEC, 48h, office confirms"). The organisation's own
 * website renders it (the renderer's /kitchen/{uuid} page).
 *
 * It follows the lunch door's rules (JummahLunchOrdersController) and reuses its
 * parts rather than restating them:
 *
 *  - the organisation is the `masjid-id` header and must exist with its lunch
 *    module on (the kitchen rides the `jummah_lunch` capability; DECISIONS.md
 *    2026-09-25) — one 404 for missing, foreign, offboarded or switched off;
 *  - /api/v1 runs UNBOUND, so masjid_id is filtered and stamped by hand;
 *  - every price comes from the catalogue (LunchOrderLines), never the body;
 *  - a card order is a direct charge on the organisation's own Stripe Connect
 *    account (MealOrderCheckoutService) and ONLY the webhook marks it paid;
 *  - an offline order is unpaid until staff record how it was paid (Mark paid).
 *
 * What the kitchen adds, each decided HERE and never by the page:
 *
 *  - the pickup must be at least `pickup_lead_hours` ahead (and at most
 *    MealMenu::MAX_PICKUP_DAYS_AHEAD), measured on the server's clock and read
 *    in the organisation's timezone — and a card order must be PAID that far
 *    ahead too, since payment is when the office hears of it (checkout());
 *  - the payment methods are the organisation's accepted ones
 *    (AcceptedPaymentMethods), narrowed by the menu's two switches: card only
 *    while the menu allows online payment and the account can take charges,
 *    offline methods only while the menu allows paying other than online. An
 *    organisation that lists no method takes no kitchen order;
 *  - every order is `pending` until the office confirms it — paying does not
 *    confirm it (MealOrder::markPaid) — and the office is emailed when an order
 *    becomes real (KitchenOrderNotifier).
 */
class KitchenOrdersController extends Controller
{
    private const NOT_AVAILABLE = 'Ordering is not available.';

    private const CLOSED = 'This kitchen is not taking orders right now.';

    private const NO_METHODS = 'This kitchen is not taking orders online yet. Please contact the office.';

    private const METHOD_REFUSED = 'That way of paying is not available for this kitchen. Please choose another.';

    private const CARD_NEEDS_SITE = 'Card payment is available only on the organisation\'s own website.';

    public function __construct(
        private MealOrderCheckoutService $checkout,
        private KitchenOrderNotifier $notifier
    ) {
    }

    /**
     * GET /api/v1/kitchen-menus/{uuid} — one catalogue, its available dishes, the
     * ways to pay and the pickup window. A draft or dated menu is a 404: only a
     * published catalogue is served here.
     */
    public function menu(Request $request, string $uuid)
    {
        $masjid = $this->organisation($request);

        if ($masjid === null) {
            return response()->api(404, self::NOT_AVAILABLE, null);
        }

        $menu = $this->catalogue($uuid, (int) $masjid->id);

        if ($menu === null) {
            return response()->api(404, 'This menu was not found.', null);
        }

        return response()->api(200, 'ok', ['menu' => $this->serializeMenu($menu, $masjid)]);
    }

    /**
     * POST /api/v1/kitchen-orders — place an order on a catalogue.
     */
    public function store(SubmitKitchenOrderRequest $request)
    {
        try {
            $masjid = $this->organisation($request);

            if ($masjid === null) {
                return response()->api(404, self::NOT_AVAILABLE, null);
            }

            // A bot filling every input trips this; it is told nothing.
            if (filled($request->input('website'))) {
                return response()->api(200, 'Thank you — your order has been received.', ['order' => null]);
            }

            $menu = $this->catalogue((string) $request->input('menu_uuid'), (int) $masjid->id);

            if ($menu === null || ! $menu->isOpenForOrders()) {
                return response()->api(422, self::CLOSED, null);
            }

            $methods = $this->methodsFor($menu, $masjid);

            if ($methods === []) {
                return response()->api(422, self::NO_METHODS, null);
            }

            $method = (string) $request->input('payment_method');

            if (! in_array($method, array_column($methods, 'method'), true)) {
                return response()->api(422, self::METHOD_REFUSED, null);
            }

            $online = $method === PaymentMethods::CARD;
            $origin = LunchOrderLink::siteOrigin($request);

            // Stripe must send the payer back to the page that shows a kitchen
            // order. Without a trusted origin the only fallback is the admin app's
            // Friday-lunch page, which would show this order as a lunch.
            if ($online && $origin === null) {
                return response()->api(422, self::CARD_NEEDS_SITE, null);
            }

            $pickup = $this->pickupFrom((string) $request->input('pickup_at'), $menu);

            if (is_string($pickup)) {
                return response()->api(422, $pickup, null);
            }

            // A card order must be payable when it is placed: its page stops taking
            // payment the lead time before pickup, and Stripe will not make one that
            // lives under half an hour. Refused before anything is written, naming
            // the earliest pickup card can take, so no unpayable order is left behind.
            if ($online && MealOrderCheckoutService::kitchenPageExpiresAt($pickup->copy()->subHours($menu->pickupLeadHours())) === null) {
                return response()->api(422, $this->cardTooSoon($menu), null);
            }

            try {
                ['lines' => $lines, 'subtotal_minor' => $subtotal] = LunchOrderLines::price(
                    $menu,
                    LunchOrderLines::wanted((array) $request->input('items', [])),
                    // Catering quantities are the whole order: over a dish's cap
                    // the customer is told, never silently given fewer trays.
                    LunchOrderLines::CAP_REFUSE
                );
            } catch (LunchLineRefusal $e) {
                return response()->api(422, $e->getMessage(), null);
            }

            $order = DB::transaction(function () use ($masjid, $menu, $request, $lines, $subtotal, $online, $method, $pickup, $origin) {
                $order = new MealOrder([
                    'meal_menu_id' => $menu->id,
                    'customer_name' => trim((string) $request->input('customer_name')),
                    'customer_phone' => trim((string) $request->input('customer_phone')),
                    // Dropped when the organisation turned the field off, as on
                    // the lunch door: a crafted body may still carry one.
                    'customer_email' => $menu->collect_customer_email ? $request->input('customer_email') : null,
                    'customer_notes' => $request->input('customer_notes'),
                    'payment_method' => $online ? MealOrder::METHOD_ONLINE : MealOrder::METHOD_PICKUP,
                ]);
                $order->masjid_id = (int) $masjid->id;
                $order->currency = $menu->currency;
                $order->subtotal_minor = $subtotal;
                $order->total_minor = $subtotal;
                $order->order_number = MealOrder::nextOrderNumber((int) $masjid->id, (int) $menu->id);
                $order->placed_at = now();
                $order->pickup_at = $pickup;
                $order->preferred_payment = $online ? null : $method;
                $order->site_origin = $origin;
                $order->save();

                foreach ($lines as $line) {
                    $order->items()->create(array_merge($line, ['masjid_id' => (int) $masjid->id]));
                }

                return $order;
            }, 3);

            if ($online) {
                try {
                    $result = $this->checkout->checkout($order->load('items'), $this->pageOptions($origin, $order));

                    return response()->api(200, 'ok', [
                        'order' => $this->serializeOrder($result['order'], $menu, $masjid),
                        'checkout_url' => $result['checkout_url'],
                    ]);
                } catch (\RuntimeException $e) {
                    // The order is saved (unpaid). Its uuid comes back with the
                    // reason, so the page sends the customer to that order, where
                    // "Pay now" tries again, instead of letting them place a second.
                    return response()->api(422, $e->getMessage(), [
                        'order' => $this->serializeOrder($order, $menu, $masjid),
                    ]);
                }
            }

            // An offline order is real once placed: the office hears now.
            $this->notifier->placed($order->load('items'));

            return response()->api(200, 'Thank you — your order is in. The office will confirm it with you.', [
                'order' => $this->serializeOrder($order->load('items'), $menu, $masjid),
            ]);
        } catch (\Exception $e) {
            return response()->api(500, Errors::publicMessage($e), null);
        }
    }

    /**
     * GET /api/v1/kitchen-orders/{uuid} — the order page (and Stripe's return).
     * The uuid is the capability, exactly as for a lunch order. Stays readable
     * when the module is switched off: a customer can always see what they have.
     */
    public function show(Request $request, string $uuid)
    {
        $masjidId = (int) $request->header('masjid-id');

        if ($masjidId <= 0 || ! PublicTenant::exists($masjidId)) {
            return response()->api(404, self::NOT_AVAILABLE, null);
        }

        [$order, $menu] = $this->kitchenOrder($uuid, $masjidId);

        if ($order === null) {
            return response()->api(404, 'Order not found.', null);
        }

        return response()->api(200, 'ok', [
            'order' => $this->serializeOrder($order->load('items'), $menu, Masjid::find($masjidId)),
        ]);
    }

    /**
     * POST /api/v1/kitchen-orders/{uuid}/checkout — the payment page for an unpaid
     * CARD order, for a customer who left Stripe before paying. The open page is
     * handed back as it is; an expired one is replaced (MealOrderCheckoutService::
     * checkout, which locks the row and refuses a paid or cancelled order).
     *
     * Paying is when the office first hears of a card order, so paying late is
     * placing late: refused once the menu has stopped taking orders, and — on the
     * locked row, in the service — once the lead time before pickup has begun. A
     * page it makes stops taking payment at that same moment.
     */
    public function checkout(Request $request, string $uuid)
    {
        $masjid = $this->organisation($request);

        if ($masjid === null) {
            return response()->api(404, self::NOT_AVAILABLE, null);
        }

        [$order, $menu] = $this->kitchenOrder($uuid, (int) $masjid->id);

        if ($order === null) {
            return response()->api(404, 'Order not found.', null);
        }

        if (! $order->isOnline()) {
            return response()->api(422, 'This order is paid to the office, not online.', null);
        }

        $origin = LunchOrderLink::siteOrigin($request);

        if ($origin === null) {
            return response()->api(422, self::CARD_NEEDS_SITE, null);
        }

        if (! $menu->isOpenForOrders() || $menu->trashed()) {
            return response()->api(422, self::CLOSED, [
                'order' => $this->serializeOrder($order->load('items'), $menu, $masjid),
            ]);
        }

        try {
            $result = $this->checkout->checkout($order->load('items'), $this->pageOptions($origin, $order));
        } catch (\RuntimeException $e) {
            return response()->api(422, $e->getMessage(), [
                'order' => $this->serializeOrder($order, $menu, $masjid),
            ]);
        }

        return response()->api(200, 'ok', [
            'order' => $this->serializeOrder($result['order'], $menu, $masjid),
            'checkout_url' => $result['checkout_url'],
        ]);
    }

    /** The organisation named by the header, when it exists and runs the module. */
    private function organisation(Request $request): ?Masjid
    {
        $masjidId = (int) $request->header('masjid-id');

        if ($masjidId <= 0 || ! PublicTenant::exists($masjidId)) {
            return null;
        }

        $masjid = Masjid::find($masjidId);

        return $masjid?->hasCapability('jummah_lunch') ? $masjid : null;
    }

    /** A PUBLISHED catalogue of this organisation (open or closed, never draft), else null. */
    private function catalogue(string $uuid, int $masjidId): ?MealMenu
    {
        $menu = MealMenu::findByUuidForMasjid($uuid, $masjidId);

        return $menu !== null && $menu->isCatalogue() && $menu->status !== MealMenu::STATUS_DRAFT ? $menu : null;
    }

    /**
     * An order of this organisation that is a KITCHEN order, with its menu. A
     * Friday-lunch order's uuid is a miss here, as a kitchen order's is on the
     * lunch page's edit path (JummahLunchOrdersController::EDIT_KITCHEN).
     *
     * @return array{0: ?MealOrder, 1: ?MealMenu}
     */
    private function kitchenOrder(string $uuid, int $masjidId): array
    {
        $order = MealOrder::findByUuidForMasjid($uuid, $masjidId);

        if ($order === null) {
            return [null, null];
        }

        $menu = MealMenu::withoutMasjidScope()
            ->withTrashed()
            ->where('masjid_id', $masjidId)
            ->whereKey($order->meal_menu_id)
            ->first();

        return $menu !== null && $menu->isCatalogue() ? [$order, $menu] : [null, null];
    }

    /**
     * The ways this catalogue can be paid right now: the organisation's accepted
     * methods, narrowed by the menu's switches.
     *
     * @return list<array{method: string, label: string, instructions: ?string, online: bool}>
     */
    private function methodsFor(MealMenu $menu, Masjid $masjid): array
    {
        return array_values(array_filter(
            AcceptedPaymentMethods::publicList($masjid),
            fn (array $m) => $m['online'] ? (bool) $menu->allow_online_payment : (bool) $menu->allow_pay_at_pickup
        ));
    }

    /**
     * The pickup as a UTC instant, or the sentence refusing it.
     *
     * Read in the organisation's timezone (a datetime-local value carries none),
     * and held to the lead time from THIS moment on the server's clock — the one
     * the office keeps, not the customer's.
     */
    private function pickupFrom(string $value, MealMenu $menu): Carbon|string
    {
        $tz = MasjidTime::zoneFor($menu->masjid_id);

        try {
            $pickup = Carbon::parse($value, $tz)->utc();
        } catch (\Throwable) {
            return 'Please choose a pickup date and time.';
        }

        $now = Carbon::now();
        $earliest = $menu->earliestPickup($now);

        if ($pickup->lt($earliest)) {
            return 'Orders need at least ' . $menu->pickupLeadHours() . ' hours\' notice. The earliest pickup is '
                . $earliest->copy()->timezone($tz)->format('l, F j \a\t g:i A') . '.';
        }

        if ($pickup->gt($menu->latestPickup($now))) {
            return 'Please choose a pickup within the next ' . MealMenu::MAX_PICKUP_DAYS_AHEAD . ' days.';
        }

        return $pickup;
    }

    /**
     * What this door asks of the checkout: Stripe's return to the kitchen page, and
     * the customer's lead time held at payment too (MealOrderCheckoutService::checkout).
     *
     * @return array{success_url: string, cancel_url: string, kitchen_lead_time: true}
     */
    private function pageOptions(string $origin, MealOrder $order): array
    {
        $base = $origin . '/kitchen/order/' . $order->uuid;

        return [
            'success_url' => $base . '?paid=1&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $base . '?cancelled=1',
            'kitchen_lead_time' => true,
        ];
    }

    /** The refusal of a card order whose pickup leaves no time for a payment page, naming the earliest that does. */
    private function cardTooSoon(MealMenu $menu): string
    {
        $earliest = $menu->earliestPickup(Carbon::now())->addMinutes(MealOrderCheckoutService::KITCHEN_PAGE_MIN_MINUTES);

        // The picker chooses whole minutes, so the earliest is the next whole one.
        if ($earliest->second > 0 || $earliest->micro > 0) {
            $earliest = $earliest->startOfMinute()->addMinute();
        }

        return 'To pay by card online, please choose a pickup on or after '
            . $earliest->timezone(MasjidTime::zoneFor($menu->masjid_id))->format('l, F j \a\t g:i A')
            . ', or choose another way to pay.';
    }

    /**
     * Whether the customer's door would open a card page for this order now: an
     * unpaid, live card order, on a menu still taking orders, with time left to pay
     * before the lead time begins. The endpoint decides again on the locked row;
     * this only keeps the page from offering a button that is sure to be refused.
     */
    private function canPayOnline(MealOrder $order, ?MealMenu $menu): bool
    {
        if (! $order->isOnline()
            || $order->payment_status !== MealOrder::PAYMENT_UNPAID
            || $order->status === MealOrder::STATUS_CANCELLED
            || $menu === null
            || $menu->trashed()
            || ! $menu->isOpenForOrders()) {
            return false;
        }

        $deadline = MealOrderCheckoutService::kitchenPaymentDeadline($order, true);

        return $deadline === null || MealOrderCheckoutService::kitchenPageExpiresAt($deadline) !== null;
    }

    /** @return array<string, mixed> */
    private function serializeMenu(MealMenu $menu, Masjid $masjid): array
    {
        $tz = MasjidTime::zoneFor($masjid->id);
        $now = Carbon::now();
        $methods = $this->methodsFor($menu, $masjid);

        $items = MealMenuItem::withoutMasjidScope()
            ->where('masjid_id', $masjid->id)
            ->where('meal_menu_id', $menu->id)
            ->where('is_available', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return [
            'uuid' => $menu->uuid,
            'title' => $menu->title,
            'title_ar' => $menu->title_ar,
            'pickup_instructions' => $menu->pickup_instructions,
            'pickup_instructions_ar' => $menu->pickup_instructions_ar,
            'flyer_image_url' => $menu->flyer_image_url,
            // Taking orders at all: open, and at least one way to pay.
            'accepting_orders' => $menu->isOpenForOrders() && $methods !== [],
            'pickup_lead_hours' => $menu->pickupLeadHours(),
            // The window, in the organisation's wall clock as a datetime-local
            // input reads it, plus the zone to label it with. The server enforces
            // it again on submit; these only set the picker's bounds.
            'earliest_pickup_local' => $menu->earliestPickup($now)->copy()->timezone($tz)->format('Y-m-d\TH:i'),
            'latest_pickup_local' => $menu->latestPickup($now)->copy()->timezone($tz)->format('Y-m-d\TH:i'),
            'timezone' => $tz,
            'collect_customer_email' => (bool) $menu->collect_customer_email,
            'currency' => $menu->currency,
            'payment_methods' => $methods,
            'items' => $items->map(fn (MealMenuItem $i) => [
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
     * The public order payload, built field by field (never the model's own
     * serialisation), so nothing the office records — who confirmed it, who
     * marked it paid, the Stripe ids — reaches the page.
     *
     * @return array<string, mixed>
     */
    private function serializeOrder(MealOrder $order, ?MealMenu $menu, ?Masjid $masjid): array
    {
        $howToPay = null;

        if ($masjid !== null
            && ! $order->isOnline()
            && $order->payment_status === MealOrder::PAYMENT_UNPAID
            && $order->preferred_payment !== null) {
            foreach (AcceptedPaymentMethods::publicList($masjid) as $method) {
                if ($method['method'] === $order->preferred_payment) {
                    $howToPay = ['label' => $method['label'], 'instructions' => $method['instructions']];
                }
            }
        }

        return [
            'uuid' => $order->uuid,
            'order_number' => $order->order_number,
            'customer_name' => $order->customer_name,
            'menu_title' => $menu?->title,
            'status' => $order->status,
            'confirmed' => $order->confirmed_at !== null,
            'payment_method' => $order->payment_method,
            'preferred_payment' => $order->preferred_payment,
            'payment_status' => $order->payment_status,
            'total_minor' => (int) $order->total_minor,
            'currency' => $order->currency,
            'pickup_at' => optional($order->pickup_at)->toIso8601String(),
            'pickup_label' => KitchenOrderNotifier::pickupLabel($order),
            'pickup_instructions' => $menu?->pickup_instructions,
            'how_to_pay' => $howToPay,
            'can_pay_online' => $this->canPayOnline($order, $menu),
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
