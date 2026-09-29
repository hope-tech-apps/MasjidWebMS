<?php

namespace Tests\Feature\Cart\Endpoints;

use App\Http\Middleware\EnsureCartEnabled;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\RateLimiter;
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
            'POST api/v1/carts' => 'cart-create',
            'GET api/v1/cart' => 'cart-read',
            'POST api/v1/cart/items' => 'cart-write',
            'DELETE api/v1/cart/items/{id}' => 'cart-write',
            'POST api/v1/cart/acknowledge' => 'cart-write',
            'POST api/v1/cart/checkout' => 'cart-checkout',
            'GET api/v1/cart-orders/{uuid}' => 'cart-order-status',
        ];

        // The middleware aliases, groups and priority list reach the router when the HTTP
        // kernel is built, which the first request does. Without it the names below would
        // stay unresolved and unsorted.
        $this->app->make(HttpKernel::class);
        $router = app('router');

        $found = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/cart')) {
                continue;
            }

            $method = $route->methods()[0];
            // The SORTED, resolved stack: the order a request runs it in. `$route->gatherMiddleware()`
            // is the order the route LISTS it in, and Laravel re-sorts that by its priority list:
            // ThrottleRequests ranks above the `api` group's SubstituteBindings, so an unranked
            // gate was moved behind the throttles while the listed order still looked right.
            $found["{$method} {$route->uri()}"] = $router->gatherRouteMiddleware($route);
        }

        $this->assertEqualsCanonicalizing(array_keys($expected), array_keys($found), 'the cart route table');

        foreach ($expected as $route => $limiter) {
            $stack = $found[$route];
            $gate = array_search(EnsureCartEnabled::class, $stack, true);
            $throttles = array_keys(array_filter(
                $stack,
                static fn ($middleware): bool => is_string($middleware) && str_starts_with($middleware, ThrottleRequests::class . ':')
            ));

            $this->assertNotFalse($gate, "{$route} is behind the cart gate");
            $this->assertContains(ThrottleRequests::class . ':' . $limiter, $stack, "{$route} carries its own named limiter, {$limiter}");
            $this->assertNotSame([], $throttles, "{$route} is throttled");

            foreach ($throttles as $throttle) {
                $this->assertLessThan($throttle, $gate, "{$route}: the gate runs before every throttle, so an off cart costs no query and no rate-limit row");
            }
        }
    }

    #[Test]
    public function with_the_cart_off_the_limiters_never_run_and_the_404_carries_no_rate_limit_header(): void
    {
        $this->armWebhooks();
        config(['app.debug' => false, 'cart.throttle.create_per_hour' => 20]);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9']);

        $org = $this->org();
        $this->assertFalse(config('cart.enabled'), 'premise: off is the shipped default');

        // A limiter's closure reads the database (a basket's token, an order's uuid), so a
        // closure that runs on a dark route is a query the route was meant never to make.
        $ran = [];
        foreach (['cart-create', 'cart-read', 'cart-write', 'cart-checkout', 'cart-order-status'] as $name) {
            RateLimiter::for($name, function () use (&$ran, $name) {
                $ran[] = $name;

                return Limit::none();
            });
        }

        foreach ($this->everyCartRoute($this->fund($org)->id) as [$method, $uri, $body]) {
            $this->cartApi($method, $uri, $org, str_repeat('a', 64), $body, self::ORIGIN)->assertNotFound();
        }

        $this->assertSame([], $ran, 'no limiter closure ran');
    }

    #[Test]
    public function with_the_cart_off_a_flood_of_new_baskets_is_still_a_bare_404_with_no_rate_limit_header(): void
    {
        $this->armWebhooks();
        config(['app.debug' => false, 'cart.throttle.create_per_hour' => 20]);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9']);

        $org = $this->org();
        $this->assertFalse(config('cart.enabled'), 'premise: off is the shipped default');

        $unknown = $this->getJson('/api/v1/no-such-route', $this->cartHeaders($org))->assertNotFound();

        // One past the hour's allowance and then some: were the throttle ahead of the gate, the
        // 21st would be the limiter's 429 and every answer before it would carry its headers.
        for ($attempt = 1; $attempt <= 22; $attempt++) {
            $response = $this->cartApi('POST', '/api/v1/carts', $org)->assertNotFound();

            $response->assertHeaderMissing('X-RateLimit-Limit');
            $response->assertHeaderMissing('X-RateLimit-Remaining');
            $response->assertHeaderMissing('Retry-After');
            $this->assertSame($unknown->getContent(), $response->getContent(), "attempt {$attempt} is the same 404 as a route that does not exist");
        }

        $this->assertSame(0, $this->rowCounts()['carts']);
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
