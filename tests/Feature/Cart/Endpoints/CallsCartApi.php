<?php

namespace Tests\Feature\Cart\Endpoints;

use App\Models\Cart;
use App\Models\Masjid;
use App\Services\Cart\CartCheckoutService;
use Illuminate\Testing\TestResponse;

/**
 * What the basket endpoint tests share: the cart switched on, the routes called the way the
 * renderer calls them (the `masjid-id` header, the `Cart-Token` header, a JSON body and the
 * browser's own `Origin`), and Stripe answered locally. Use it with BuildsBaskets and
 * SignsCartWebhooks, which supply the organisation, the things to buy and the fake Stripe.
 */
trait CallsCartApi
{
    /** An origin on the return allowlist, as FORMS_PAYMENT_RETURN_ORIGINS would name it. */
    protected const ORIGIN = 'https://mec.example.org';

    protected const RETURN_PATH = '/basket';

    /** The checkout service the endpoint uses, whose Stripe seams touch no network. */
    protected ?CartCheckoutService $stripe = null;

    /** The basket switched on for every organisation, with Stripe answered locally. */
    protected function turnCartOn(): void
    {
        config([
            'cart.enabled' => true,
            'cart.masjid_ids' => [],
            'forms.payment_return_origins' => [self::ORIGIN],
        ]);

        $this->stripe = $this->checkoutService();
        $this->app->instance(CartCheckoutService::class, $this->stripe);
    }

    /**
     * @return array<string,string>
     */
    protected function cartHeaders(?Masjid $org, ?string $token = null, ?string $origin = null): array
    {
        $headers = [];

        if ($org !== null) {
            $headers['masjid-id'] = (string) $org->id;
        }

        if ($token !== null) {
            $headers['Cart-Token'] = $token;
        }

        if ($origin !== null) {
            $headers['Origin'] = $origin;
        }

        return $headers;
    }

    /** @param  array<string,mixed>  $body */
    protected function cartApi(string $method, string $uri, ?Masjid $org, ?string $token = null, array $body = [], ?string $origin = null): TestResponse
    {
        return $this->json($method, $uri, $body, $this->cartHeaders($org, $token, $origin));
    }

    /** POST /carts, and the token it handed back. */
    protected function startBasket(Masjid $org): string
    {
        $response = $this->cartApi('POST', '/api/v1/carts', $org)->assertOk();

        return (string) $response->json('data.token');
    }

    /** The basket row a token opens. */
    protected function basketOf(string $token): Cart
    {
        return Cart::withoutMasjidScope()->where('token_hash', Cart::hashToken($token))->firstOrFail();
    }

    /** POST /cart/items with the given body. */
    protected function addLine(Masjid $org, string $token, array $body): TestResponse
    {
        return $this->cartApi('POST', '/api/v1/cart/items', $org, $token, $body);
    }

    /** @return array{type: string, form_id: int, answers: array<string,mixed>} two $15 tickets */
    protected function twoTicketsBody(int $formId, ?string $key = null): array
    {
        return array_filter([
            'type' => 'form',
            'form_id' => $formId,
            'answers' => $this->twoTickets(),
            'client_line_key' => $key,
        ], static fn ($value): bool => $value !== null);
    }

    /** @return array<string,mixed> */
    protected function giftBody(int $fundId, int $amountMinor = 5000, ?string $key = null): array
    {
        return array_filter([
            'type' => 'donation',
            'fund_id' => $fundId,
            'amount_minor' => $amountMinor,
            'client_line_key' => $key,
        ], static fn ($value): bool => $value !== null);
    }

    /** @return array<string,mixed> */
    protected function dishBody(int $itemId, int $quantity = 2, ?string $pickupAt = null, ?string $key = null): array
    {
        return array_filter([
            'type' => 'meal',
            'item_id' => $itemId,
            'quantity' => $quantity,
            'pickup_at' => $pickupAt,
            'client_line_key' => $key,
        ], static fn ($value): bool => $value !== null);
    }

    /**
     * The buyer and return address a checkout needs.
     *
     * @param  array<string,mixed>  $buyer
     * @return array<string,mixed>
     */
    protected function checkoutBody(array $buyer = [], array $extra = []): array
    {
        return array_merge([
            'buyer' => array_merge([
                'name' => 'Zaynab Buyer',
                'email' => 'zaynab@example.org',
                'phone' => '+1 555 010 0100',
            ], $buyer),
            'return_path' => self::RETURN_PATH,
        ], $extra);
    }
}
