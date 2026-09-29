<?php

namespace Tests\Feature\Cart\Endpoints;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsBaskets;
use Tests\Feature\Cart\SignsCartWebhooks;
use Tests\TestCase;

/**
 * The basket is DARK until the owner turns it on (brief 5, section 0): with the cart off,
 * every cart route is a 404 and writes nothing. The middleware that decides is pinned on a
 * probe route in CartGateMiddlewareTest; here it is pinned on the real routes.
 */
class CartEndpointsGateTest extends TestCase
{
    use BuildsBaskets;
    use CallsCartApi;
    use RefreshDatabase;
    use SignsCartWebhooks;

    private const UUID = '00000000-0000-4000-8000-000000000000';

    /**
     * Every cart route, with a body that would work were the cart on.
     *
     * @return list<array{0: string, 1: string, 2: array<string,mixed>}>
     */
    private function everyCartRoute(int $fundId): array
    {
        return [
            ['POST', '/api/v1/carts', []],
            ['GET', '/api/v1/cart', []],
            ['POST', '/api/v1/cart/items', $this->giftBody($fundId)],
            ['DELETE', '/api/v1/cart/items/1', []],
            ['POST', '/api/v1/cart/acknowledge', ['seen' => str_repeat('a', 64)]],
            ['POST', '/api/v1/cart/checkout', $this->checkoutBody()],
            ['GET', '/api/v1/cart-orders/' . self::UUID, []],
        ];
    }

    /** @return array{carts: int, lines: int, orders: int} */
    private function rowCounts(): array
    {
        return [
            'carts' => Cart::withoutMasjidScope()->count(),
            'lines' => CartItem::withoutMasjidScope()->count(),
            'orders' => Order::withoutMasjidScope()->count(),
        ];
    }

    #[Test]
    public function with_the_cart_off_every_cart_route_is_a_404_that_writes_nothing(): void
    {
        $this->armWebhooks();
        config(['app.debug' => false]);

        $org = $this->org();
        $fund = $this->fund($org);

        // A real basket with a real token: were the cart on, these calls would work.
        $token = str_repeat('a', 64);
        $cart = $this->cart($org);
        $cart->forceFill(['token_hash' => Cart::hashToken($token), 'expires_at' => now()->addDay()])->save();
        $this->add($cart, CartItem::TYPE_DONATION, $fund->id, 5000);

        $before = $this->rowCounts();
        $unknown = $this->getJson('/api/v1/no-such-route', $this->cartHeaders($org))->assertNotFound()->getContent();

        $this->assertFalse(config('cart.enabled'), 'premise: off is the shipped default');

        foreach ($this->everyCartRoute($fund->id) as [$method, $uri, $body]) {
            $response = $this->cartApi($method, $uri, $org, $token, $body, self::ORIGIN);

            $response->assertNotFound();
            $this->assertSame($unknown, $response->getContent(), "{$method} {$uri} is not the same 404 as a route that does not exist");
        }

        $this->assertSame($before, $this->rowCounts(), 'nothing was written');
        $this->assertSame(1, $this->rowCounts()['lines'], 'and nothing was removed');
    }

    #[Test]
    public function every_cart_route_is_registered_behind_the_gate_and_its_own_named_limiter(): void
    {
        $expected = [
            'POST api/v1/carts' => 'throttle:cart-create',
            'GET api/v1/cart' => 'throttle:cart-read',
            'POST api/v1/cart/items' => 'throttle:cart-write',
            'DELETE api/v1/cart/items/{id}' => 'throttle:cart-write',
            'POST api/v1/cart/acknowledge' => 'throttle:cart-write',
            'POST api/v1/cart/checkout' => 'throttle:cart-checkout',
            'GET api/v1/cart-orders/{uuid}' => 'throttle:cart-order-status',
        ];

        $found = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/cart')) {
                continue;
            }

            $method = $route->methods()[0];
            $found["{$method} {$route->uri()}"] = $route->gatherMiddleware();
        }

        $this->assertEqualsCanonicalizing(array_keys($expected), array_keys($found), 'the cart route table');

        foreach ($expected as $route => $limiter) {
            $gate = array_search('cart.enabled', $found[$route], true);
            $throttle = array_search($limiter, $found[$route], true);

            $this->assertNotFalse($gate, "{$route} is behind the cart gate");
            $this->assertNotFalse($throttle, "{$route} carries its own named limiter, {$limiter}");
            $this->assertLessThan($throttle, $gate, "{$route}: the gate runs before the throttle, so an off cart costs no query");
        }
    }

    #[Test]
    public function switched_on_the_same_routes_answer(): void
    {
        $this->armWebhooks();
        $this->turnCartOn();
        $org = $this->org();

        $token = $this->startBasket($org);

        $this->cartApi('GET', '/api/v1/cart', $org, $token)->assertOk();
        $this->assertSame(1, $this->rowCounts()['carts']);
    }

    #[Test]
    public function an_allowlist_leaves_every_other_organisation_dark_and_untouched(): void
    {
        $this->armWebhooks();
        $this->turnCartOn();
        $listed = $this->org();
        $other = $this->org();
        config(['cart.masjid_ids' => [$listed->id]]);

        $token = $this->startBasket($listed);
        $this->assertSame(1, $this->rowCounts()['carts']);

        // The unlisted organisation cannot start a basket, and cannot use a listed one's.
        config(['app.debug' => false]);
        $unknown = $this->getJson('/api/v1/no-such-route')->assertNotFound()->getContent();

        $this->cartApi('POST', '/api/v1/carts', $other)->assertNotFound();
        $this->assertSame($unknown, $this->cartApi('POST', '/api/v1/carts', $other)->getContent());
        $this->assertSame($unknown, $this->cartApi('GET', '/api/v1/cart', $other, $token)->getContent());
        $this->assertSame(1, $this->rowCounts()['carts'], 'no basket was made for the organisation that is not listed');

        // No header at all names no organisation, so an allowlist does not admit it either.
        $this->assertSame($unknown, $this->cartApi('POST', '/api/v1/carts', null)->getContent());

        // The listed one carries on.
        $this->cartApi('GET', '/api/v1/cart', $listed, $token)->assertOk();
    }

    #[Test]
    public function with_no_allowlist_it_is_on_for_everyone_and_a_missing_header_is_the_controllers_400(): void
    {
        $this->armWebhooks();
        $this->turnCartOn();

        $this->cartApi('POST', '/api/v1/carts', null)
            ->assertStatus(400)
            ->assertJsonPath('message', 'A masjid must be specified.');

        $this->assertSame(0, $this->rowCounts()['carts']);
    }
}
