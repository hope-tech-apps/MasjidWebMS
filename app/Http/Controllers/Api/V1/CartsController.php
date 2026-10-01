<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\CartItem;
use App\Services\Cart\CartCheckoutRefused;
use App\Services\Cart\CartCheckoutService;
use App\Services\Cart\CartLineAdder;
use App\Services\Cart\CartLineRefused;
use App\Services\Cart\CartPricer;
use App\Services\Cart\PricedBasket;
use App\Services\Cart\Sources\ProductLineSource;
use App\Services\Stripe\FormResponseCheckoutService;
use App\Support\FormPaymentReturn;
use App\Support\PublicTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\ExceptionInterface;

/**
 * The universal basket, over HTTP (brief 5; DECISIONS.md 2026-09-29). It EXPOSES the cart
 * services: pricing, checkout and acknowledgement are CartPricer, CartCheckoutService and
 * their rules, unchanged, and adding a line is CartLineAdder.
 *
 * ## Dark until switched on
 *
 * Every route is behind `cart.enabled` (App\Http\Middleware\EnsureCartEnabled): with the
 * cart off, or off for the organisation the header names, each answers the 404 an unknown
 * route does, before anything here runs.
 *
 * ## Who is asking, and which basket
 *
 * The house `/api/v1` idiom, as FormSubmissionsController and FormResponsePaymentsController
 * run it. The organisation is the `masjid-id` HEADER, int-cast, `<= 0` is 400; then
 * PublicTenant::exists() (live, not soft-deleted). These routes bind no tenant, so the
 * BelongsToMasjid scope adds no filter and its creating hook stamps nothing: every query here
 * is filtered by masjid_id by hand and every create stamps it. The basket is found by the
 * HMAC of the `Cart-Token` header AND that organisation (Cart::findLiveByToken). A wrong
 * token, another organisation's basket, an expired one, a missing header, an offboarded
 * organisation and a basket that never existed are ONE 404, byte for byte.
 *
 * ## What leaves
 *
 * The token is returned ONCE, in the JSON body of POST /carts (CORS exposes no response
 * header, so it could not be read from one), and never again in any response. The priced view
 * has each line's label, status, reason, quantity and unit price, the total, the currency, the
 * notices and the view fingerprint the acknowledge call needs: never a line's payload (the
 * answers, attendee names), never the payee account, and no fingerprint but that one.
 *
 * The three rate limiters and the envelope are the form door's (`{status, message, data}`; a
 * validation error is 422 `{status: 'failed', data: {field: [...]}}`; a 429 says the wait in
 * its body). Nothing here marks anything paid: only the signed webhook does.
 */
class CartsController extends Controller
{
    /** The one answer for every way the organisation or the basket cannot be used. */
    private const NOT_AVAILABLE = 'This basket is not available.';

    private const ITEM_NOT_FOUND = 'That item is not in your basket.';

    /** The public names of a line's type, and what each is called inside the cart. */
    private const TYPES = [
        'form' => CartItem::TYPE_FORM,
        'meal' => CartItem::TYPE_MEAL,
        'donation' => CartItem::TYPE_DONATION,
        'product' => CartItem::TYPE_PRODUCT,
    ];

    /**
     * POST /api/v1/carts
     *
     * Starts a basket and hands back its token, once. 32 random bytes as hex; the database
     * keeps only the HMAC (Cart::hashToken), so the plaintext lives from this line until this
     * response is sent, and nowhere else. It expires in `cart.ttl_days`, and every write pushes
     * that out again.
     */
    public function store(Request $request): JsonResponse
    {
        $masjidId = (int) $request->header('masjid-id');

        if ($masjidId <= 0) {
            return response()->api(400, 'A masjid must be specified.', null);
        }

        if (! PublicTenant::exists($masjidId)) {
            return response()->api(404, self::NOT_AVAILABLE, null);
        }

        $token = bin2hex(random_bytes(32));
        $expiresAt = now()->addDays($this->ttlDays());

        // Unbound: the creating hook stamps nothing, so the organisation is set here.
        Cart::withoutMasjidScope()->create([
            'masjid_id' => $masjidId,
            'contact_id' => null,
            'token_hash' => Cart::hashToken($token),
            'status' => Cart::STATUS_OPEN,
            'expires_at' => $expiresAt,
        ]);

        return response()->api(200, 'ok', [
            'token' => $token,
            'expires_at' => $expiresAt->toIso8601String(),
        ]);
    }

    /**
     * GET /api/v1/cart
     *
     * The basket as CartPricer prices it right now.
     */
    public function show(Request $request): JsonResponse
    {
        $found = $this->resolve($request);

        if ($found instanceof JsonResponse) {
            return $found;
        }

        [, $cart] = $found;

        return response()->api(200, 'ok', $this->present((new CartPricer)->price($cart)));
    }

    /**
     * POST /api/v1/cart/items
     *
     * `{type: form|meal|donation|product, ...}` and an optional `client_line_key`; see CartLineAdder
     * for what each type takes and how a line is validated and priced. Answers the priced
     * basket with the added line's id (`line_id`).
     */
    public function addItem(Request $request): JsonResponse
    {
        $found = $this->resolve($request);

        if ($found instanceof JsonResponse) {
            return $found;
        }

        [, $cart] = $found;

        // A bot filling every input trips this; a human never sees the field. Success is
        // reported so a scripted caller gets no signal to adapt to, and nothing is written.
        if (filled($request->input('website'))) {
            return response()->api(200, 'Thank you — your item has been added.', ['id' => null]);
        }

        // The page posts form-encoded: a checkbox arrives as the strings "true" and "false",
        // which the `boolean` rule refuses (.claude/rules/shipping.md).
        $body = $this->coerceBooleans($request->all(), ['zakat', 'recurring']);

        $validator = Validator::make($body, $this->itemRules($body['type'] ?? null));

        if ($validator->fails()) {
            return $this->failed($validator->errors());
        }

        if (($body['recurring'] ?? false) === true) {
            return response()->api(422, CartLineAdder::RECURRING, null);
        }

        try {
            $item = (new CartLineAdder)->add($cart, $body);
        } catch (CartLineRefused $refused) {
            return $refused->errors() !== null
                ? $this->failed($refused->errors())
                : response()->api($refused->answerStatus(), $refused->getMessage(), null);
        }

        $this->extend($cart);

        return response()->api(200, 'ok', $this->present((new CartPricer)->price($cart)) + [
            'line_id' => (int) $item->id,
        ]);
    }

    /**
     * DELETE /api/v1/cart/items/{id}
     *
     * Removes that line from THIS basket only: a line of another basket, or of another
     * organisation, is the same 404 as one that never existed.
     */
    public function removeItem(Request $request, string $id): JsonResponse
    {
        $found = $this->resolve($request);

        if ($found instanceof JsonResponse) {
            return $found;
        }

        [, $cart] = $found;

        $removed = DB::transaction(function () use ($cart, $id): ?bool {
            // The basket row is the lock a checkout of it takes: a line cannot be removed
            // between that checkout pricing the basket and opening its page.
            $locked = Cart::withoutMasjidScope()
                ->where('masjid_id', $cart->masjid_id)
                ->whereKey($cart->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null || ! $locked->isOpen()) {
                return null;
            }

            return CartItem::withoutMasjidScope()
                ->where('cart_id', $locked->id)
                ->where('masjid_id', $locked->masjid_id)
                ->whereKey((int) $id)
                ->delete() > 0;
        });

        if ($removed === null) {
            return response()->api(422, CartLineAdder::CLOSED, null);
        }

        if (! $removed) {
            return response()->api(404, self::ITEM_NOT_FOUND, null);
        }

        $this->extend($cart);

        return response()->api(200, 'ok', $this->present((new CartPricer)->price($cart)));
    }

    /**
     * POST /api/v1/cart/acknowledge  {seen}
     *
     * "OK" to what the shopper was shown: CartCheckoutService::acknowledge() drops what went
     * and takes the current price and quantity of what changed, but only while the basket still
     * prices exactly the way `seen` (the view fingerprint) says. If it moved again, nothing is
     * written and the new state comes back as a 409, the same answer a checkout gives.
     */
    public function acknowledge(Request $request): JsonResponse
    {
        $found = $this->resolve($request);

        if ($found instanceof JsonResponse) {
            return $found;
        }

        [, $cart] = $found;

        $validator = Validator::make($request->all(), [
            'seen' => ['required', 'string', 'regex:/^[a-f0-9]{64}\z/'],
        ]);

        if ($validator->fails()) {
            return $this->failed($validator->errors());
        }

        try {
            $priced = app(CartCheckoutService::class)->acknowledge($cart, (string) $request->input('seen'));
        } catch (CartCheckoutRefused $refused) {
            return $this->refusedCheckout($refused);
        }

        $this->extend($cart);

        return response()->api(200, 'ok', $this->present($priced));
    }

    /**
     * POST /api/v1/cart/checkout  {buyer: {name, email, phone}, return_path, website}
     *
     * Opens ONE Stripe page for the whole basket and answers `{checkout_url, order_uuid}`. The
     * return address is FormPaymentReturn::base() exactly as the form door builds it (the
     * request's Origin and `return_path`; a refusal is its one message). A basket that changed
     * since the shopper last looked is a 409 carrying the priced basket (the shape of GET /cart, with its
     * `notices` and `view_fingerprint`), so the page can show what it is asked to accept and
     * acknowledge; any other refusal is a 422 with its sentence.
     *
     * The buyer's rules are the doors' floor: a name, a real address (receipts, and the future
     * portal, key on it), and a phone when the basket has a dish, because both meal doors
     * require one for the kitchen to ring.
     */
    public function checkout(Request $request): JsonResponse
    {
        $found = $this->resolve($request);

        if ($found instanceof JsonResponse) {
            return $found;
        }

        [$masjidId, $cart] = $found;

        if (filled($request->input('website'))) {
            return response()->api(200, 'ok', ['checkout_url' => null, 'order_uuid' => null]);
        }

        $hasMeal = CartItem::withoutMasjidScope()
            ->where('cart_id', $cart->id)
            ->where('masjid_id', $masjidId)
            ->where('buyable_type', CartItem::TYPE_MEAL)
            ->exists();

        $validator = Validator::make($request->all(), [
            'buyer' => ['required', 'array'],
            'buyer.name' => ['required', 'string', 'max:120'],
            'buyer.email' => ['required', 'email:rfc', 'max:190'],
            'buyer.phone' => [$hasMeal ? 'required' : 'nullable', 'string', 'max:32'],
        ]);

        if ($validator->fails()) {
            return $this->failed($validator->errors());
        }

        $buyer = (array) $validator->validated()['buyer'];

        // The form door's own rule: an allowlisted Origin (or a confirmed domain of THIS
        // organisation) and a relative return_path. One message whichever half was wrong.
        $returnBase = FormPaymentReturn::base($request, $masjidId, [
            'masjid_id' => $masjidId,
            'cart_id' => (int) $cart->id,
        ]);

        if ($returnBase === null) {
            return response()->api(422, FormPaymentReturn::REFUSED, null);
        }

        try {
            $page = app(CartCheckoutService::class)->checkout(
                $cart,
                $returnBase,
                (string) $buyer['email'],
                (string) $buyer['name'],
                isset($buyer['phone']) ? (string) $buyer['phone'] : null,
                // The rule above was decided from a read taken before the basket's lock; the
                // service asks it again under the lock, so a dish added in between is refused.
                requirePhoneForMeals: true,
            );
        } catch (CartCheckoutRefused $refused) {
            return $this->refusedCheckout($refused);
        } catch (ExceptionInterface $e) {
            // The detail is logged (never the message, which can quote the address) and never shown.
            Log::warning('Stripe did not open a cart payment page.', [
                'masjid_id' => $masjidId,
                'cart_id' => (int) $cart->id,
                'exception' => $e::class,
                'stripe_code' => $e instanceof ApiErrorException ? $e->getStripeCode() : null,
                'http_status' => $e instanceof ApiErrorException ? $e->getHttpStatus() : null,
            ]);

            return response()->api(422, FormResponseCheckoutService::COULD_NOT_OPEN, null);
        }

        // The page outlives the request: a shopper on Stripe's screen is not idle.
        $this->extend($cart);

        return response()->api(200, 'ok', [
            'checkout_url' => $page['url'],
            'order_uuid' => (string) $page['order']->uuid,
        ]);
    }

    // ------------------------------------------------------------------------- helpers

    /**
     * The organisation and its basket for this request, or the answer to give instead.
     *
     * @return array{0: int, 1: Cart}|JsonResponse
     */
    private function resolve(Request $request): array|JsonResponse
    {
        $masjidId = (int) $request->header('masjid-id');

        if ($masjidId <= 0) {
            return response()->api(400, 'A masjid must be specified.', null);
        }

        if (! PublicTenant::exists($masjidId)) {
            return response()->api(404, self::NOT_AVAILABLE, null);
        }

        $token = $request->header('Cart-Token');
        $cart = is_string($token) ? Cart::findLiveByToken($token, $masjidId) : null;

        if ($cart === null) {
            return response()->api(404, self::NOT_AVAILABLE, null);
        }

        return [$masjidId, $cart];
    }

    /**
     * The priced basket as the page draws it, and nothing it does not: the payload (the
     * answers), the payee account and every fingerprint but the view one stay on the server.
     *
     * @return array<string,mixed>
     */
    private function present(PricedBasket $priced): array
    {
        $lines = [];

        foreach ($priced->lines as ['item' => $item, 'outcome' => $outcome]) {
            $lines[] = [
                'id' => (int) $item->id,
                'type' => (string) (array_search($item->buyable_type, self::TYPES, true) ?: $item->buyable_type),
                'label' => $outcome->label,
                'status' => $outcome->status,
                'reason' => $outcome->reason,
                'quantity' => $outcome->quantity,
                'unit_minor' => $outcome->unitAmountMinor,
            ];
        }

        return [
            'lines' => $lines,
            'total_minor' => $priced->totalMinor,
            'currency' => $priced->currency,
            // A reason the WHOLE basket cannot be paid, when there is one (not one line).
            'refusal' => $priced->refusal,
            'notices' => $priced->notices(),
            'view_fingerprint' => $priced->viewFingerprint(),
        ];
    }

    /**
     * What a refused checkout or acknowledge answers: a basket that changed is a 409 whose body
     * is the priced basket exactly as GET /cart draws it (every line's quantity, unit price and
     * status, the total, the `notices` and the `view_fingerprint` to hand back), because the
     * shopper is asked to accept the basket at its new prices and a notice alone carries no
     * amount; anything else is one sentence.
     */
    private function refusedCheckout(CartCheckoutRefused $refused): JsonResponse
    {
        if ($refused->priced() !== null) {
            return response()->api(409, $refused->getMessage(), $this->present($refused->priced()));
        }

        return response()->api(422, $refused->getMessage(), null);
    }

    /**
     * The shape rules for an add, by type. Whether the form, dish, fund or size exists and is on sale
     * is decided in CartLineAdder from the database; a request body never prices anything.
     *
     * @return array<string, list<mixed>>
     */
    private function itemRules(mixed $type): array
    {
        $rules = [
            'type' => ['required', 'string', Rule::in(array_keys(self::TYPES))],
            // The form door's key, so a page mints it the same way.
            'client_line_key' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_-]{8,64}\z/'],
        ];

        return $rules + match ($type) {
            'form' => [
                'form_id' => ['required', 'integer', 'min:1'],
                'answers' => ['present', 'array'],
            ],
            'meal' => [
                'item_id' => ['required', 'integer', 'min:1'],
                // The doors' bound: 1..99 a line.
                'quantity' => ['required', 'integer', 'min:1', 'max:99'],
                'pickup_at' => ['nullable', 'string', 'max:40'],
            ],
            'donation' => [
                'fund_id' => ['required', 'integer', 'min:1'],
                // The donation door's own range, in minor units.
                'amount_minor' => ['required', 'integer', 'min:100', 'max:99999999'],
                'zakat' => ['nullable', 'boolean'],
                'recurring' => ['nullable', 'boolean'],
            ],
            'product' => [
                // A SIZE of a product, never the product: it carries the price and the stock.
                'variant_id' => ['required', 'integer', 'min:1'],
                'quantity' => ['required', 'integer', 'min:1', 'max:' . ProductLineSource::MAX_QUANTITY],
            ],
            default => [],
        };
    }

    /**
     * "true"/"false" and "1"/"0" strings read as the booleans they mean; anything else is left
     * as sent, so `boolean` still refuses it rather than it being read as false.
     *
     * @param  array<string,mixed>  $body
     * @param  list<string>  $fields
     * @return array<string,mixed>
     */
    private function coerceBooleans(array $body, array $fields): array
    {
        foreach ($fields as $field) {
            if (! array_key_exists($field, $body) || $body[$field] === null || is_bool($body[$field])) {
                continue;
            }

            $coerced = filter_var($body[$field], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if ($coerced !== null) {
                $body[$field] = $coerced;
            }
        }

        return $body;
    }

    /** The validation-error envelope every door uses. */
    private function failed(mixed $errors): JsonResponse
    {
        return response()->json(['status' => 'failed', 'data' => $errors], 422);
    }

    /** Every write pushes the basket's expiry out again from now: it is sliding. */
    private function extend(Cart $cart): void
    {
        Cart::withoutMasjidScope()
            ->where('masjid_id', $cart->masjid_id)
            ->whereKey($cart->id)
            ->update(['expires_at' => now()->addDays($this->ttlDays())]);
    }

    private function ttlDays(): int
    {
        return max(1, (int) config('cart.ttl_days', 7));
    }
}
