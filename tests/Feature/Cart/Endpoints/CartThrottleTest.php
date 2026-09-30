<?php

namespace Tests\Feature\Cart\Endpoints;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsBaskets;
use Tests\Feature\Cart\SignsCartWebhooks;
use Tests\TestCase;

/**
 * The basket's named limiters (brief 5, section 4), with the limits turned down so a test
 * can reach them. What is pinned: each limiter's key (a connection and an organisation for
 * `cart-create`; a basket for the three that act on one; an order for the status read), that
 * a token naming no live basket meets a per-connection bucket instead of a fresh allowance,
 * and that a 429 says how long to wait in its BODY (CORS exposes no response header).
 */
class CartThrottleTest extends TestCase
{
    use BuildsBaskets;
    use CallsCartApi;
    use RefreshDatabase;
    use SignsCartWebhooks;

    protected function setUp(): void
    {
        parent::setUp();

        $this->armWebhooks();
        $this->turnCartOn();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9']);
    }

    #[Test]
    public function starting_baskets_is_limited_per_connection_and_organisation(): void
    {
        config(['cart.throttle.create_per_hour' => 3]);
        $org = $this->org();
        $other = $this->org();

        for ($i = 1; $i <= 3; $i++) {
            $this->cartApi('POST', '/api/v1/carts', $org)->assertOk();
        }

        $this->cartApi('POST', '/api/v1/carts', $org)
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'Too many baskets started from this connection. Please try again in 60 minutes.');

        // The same connection at another organisation: its own allowance.
        $this->cartApi('POST', '/api/v1/carts', $other)->assertOk();
    }

    #[Test]
    public function the_organisation_key_is_the_header_cast_the_way_the_controller_casts_it(): void
    {
        config(['cart.throttle.create_per_hour' => 2]);
        $org = $this->org();

        // `07`, `7x` and `7` are one organisation to the controller, so one bucket to the limiter.
        foreach ([(string) $org->id, '0' . $org->id, $org->id . 'x'] as $i => $header) {
            $response = $this->postJson('/api/v1/carts', [], ['masjid-id' => $header]);

            $this->assertSame($i < 2 ? 200 : 429, $response->getStatusCode(), "masjid-id: {$header}");
        }
    }

    #[Test]
    public function writes_are_limited_per_basket_across_add_acknowledge_and_remove(): void
    {
        config(['cart.throttle.write_per_hour' => 3]);
        $org = $this->org();
        $fund = $this->fund($org);
        $token = $this->startBasket($org);
        $other = $this->startBasket($org);

        $added = $this->addLine($org, $token, $this->giftBody($fund->id))->assertOk();
        $this->cartApi('POST', '/api/v1/cart/acknowledge', $org, $token, ['seen' => $added->json('data.view_fingerprint')])->assertOk();
        $this->cartApi('DELETE', '/api/v1/cart/items/' . $added->json('data.line_id'), $org, $token)->assertOk();

        $this->addLine($org, $token, $this->giftBody($fund->id))
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonPath('message', 'Too many changes to this basket. Please try again in 60 minutes.');

        // Reading it is another limiter, and another basket behind the same address is its own bucket.
        $this->cartApi('GET', '/api/v1/cart', $org, $token)->assertOk();
        $this->addLine($org, $other, $this->giftBody($fund->id))->assertOk();
    }

    #[Test]
    public function reads_and_checkouts_have_their_own_allowances(): void
    {
        config(['cart.throttle.read_per_hour' => 2, 'cart.throttle.checkout_per_hour' => 2]);
        $org = $this->org();
        $token = $this->startBasket($org);

        $this->cartApi('GET', '/api/v1/cart', $org, $token)->assertOk();
        $this->cartApi('GET', '/api/v1/cart', $org, $token)->assertOk();
        $this->cartApi('GET', '/api/v1/cart', $org, $token)
            ->assertStatus(429)
            ->assertJsonPath('message', 'Too many requests for this basket. Please try again in 60 minutes.');

        // A refused checkout is still an attempt: the limit is on presses, not on pages opened.
        $this->cartApi('POST', '/api/v1/cart/checkout', $org, $token, [])->assertStatus(422);
        $this->cartApi('POST', '/api/v1/cart/checkout', $org, $token, [])->assertStatus(422);
        $this->cartApi('POST', '/api/v1/cart/checkout', $org, $token, [])
            ->assertStatus(429)
            ->assertJsonPath('message', 'Too many payment attempts. Please try again in 60 minutes.');
    }

    #[Test]
    public function junk_tokens_share_one_connection_bucket_and_never_stop_a_real_basket(): void
    {
        config(['cart.throttle.write_per_hour' => 4]);
        $org = $this->org();
        $fund = $this->fund($org);
        $real = $this->startBasket($org);

        // A caller spraying tokens gets NO fresh allowance for each: they all meet one bucket.
        for ($i = 1; $i <= 4; $i++) {
            $this->addLine($org, bin2hex(random_bytes(32)), $this->giftBody($fund->id))->assertNotFound();
        }

        $this->addLine($org, bin2hex(random_bytes(32)), $this->giftBody($fund->id))->assertStatus(429);
        $this->cartApi('POST', '/api/v1/cart/items', $org, null, $this->giftBody($fund->id))->assertStatus(429);

        // The junk that filled it never stops a shopper behind the same address.
        for ($i = 1; $i <= 4; $i++) {
            $this->addLine($org, $real, $this->giftBody($fund->id, 100 * $i))->assertOk();
        }

        $this->addLine($org, $real, $this->giftBody($fund->id))->assertStatus(429);
    }

    #[Test]
    public function the_status_read_is_limited_per_order_and_a_made_up_uuid_meets_the_connection_guard(): void
    {
        config(['cart.throttle.order_status_per_hour' => 3, 'cart.throttle.order_status_guard_per_minute' => 5]);
        $org = $this->org();
        $fund = $this->fund($org);

        $uuids = [];
        foreach ([1, 2] as $n) {
            $token = $this->startBasket($org);
            $this->addLine($org, $token, $this->giftBody($fund->id))->assertOk();
            $uuids[$n] = $this->cartApi('POST', '/api/v1/cart/checkout', $org, $token, $this->checkoutBody(), self::ORIGIN)
                ->assertOk()->json('data.order_uuid');
        }

        for ($i = 1; $i <= 3; $i++) {
            $this->cartApi('GET', "/api/v1/cart-orders/{$uuids[1]}", $org)->assertOk();
        }

        $this->cartApi('GET', "/api/v1/cart-orders/{$uuids[1]}", $org)
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonPath('message', 'Too many requests. Please try again in 60 minutes.');

        // The same connection, another order: its own three.
        $this->cartApi('GET', "/api/v1/cart-orders/{$uuids[2]}", $org)->assertOk();

        // Made-up uuids meet the per-connection guard instead of any order's allowance...
        for ($i = 1; $i <= 5; $i++) {
            $this->cartApi('GET', '/api/v1/cart-orders/' . Str::uuid(), $org)->assertNotFound();
        }

        $this->cartApi('GET', '/api/v1/cart-orders/' . Str::uuid(), $org)->assertStatus(429);

        // ...and never stop a payer behind the same address reading their own.
        $this->cartApi('GET', "/api/v1/cart-orders/{$uuids[2]}", $org)->assertOk();
        $this->cartApi('GET', "/api/v1/cart-orders/{$uuids[2]}", $org)->assertOk();
        $this->cartApi('GET', "/api/v1/cart-orders/{$uuids[2]}", $org)->assertStatus(429);
    }

    #[Test]
    public function an_order_of_another_organisation_is_a_made_up_uuid_to_the_limiter(): void
    {
        config(['cart.throttle.order_status_per_hour' => 2, 'cart.throttle.order_status_guard_per_minute' => 2]);
        $org = $this->org();
        $other = $this->org();
        $token = $this->startBasket($org);
        $this->addLine($org, $token, $this->giftBody($this->fund($org)->id))->assertOk();
        $uuid = $this->cartApi('POST', '/api/v1/cart/checkout', $org, $token, $this->checkoutBody(), self::ORIGIN)->assertOk()->json('data.order_uuid');

        // Asked at the wrong organisation the uuid is no handle: it is spent against the guard,
        // never against the order's own allowance, so probing cannot lock the real payer out.
        $this->cartApi('GET', "/api/v1/cart-orders/{$uuid}", $other)->assertNotFound();
        $this->cartApi('GET', "/api/v1/cart-orders/{$uuid}", $other)->assertNotFound();
        $this->cartApi('GET', "/api/v1/cart-orders/{$uuid}", $other)->assertStatus(429);

        $this->cartApi('GET', "/api/v1/cart-orders/{$uuid}", $org)->assertOk();
        $this->cartApi('GET', "/api/v1/cart-orders/{$uuid}", $org)->assertOk();
    }
}
