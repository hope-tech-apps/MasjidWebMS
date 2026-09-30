<?php

namespace Tests\Feature\Cart\Endpoints;

use App\Http\Middleware\EnsureCartEnabled;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The universal cart is dark until the owner turns it on (brief 5, section 0; DECISIONS.md
 * 2026-09-29). Other sessions ship production from `main`, so what is pinned here is the
 * one middleware that decides it, on a probe route that carries exactly the middleware
 * the cart routes carry: off is the same 404 an unknown route gets, on is a pass, and the
 * allowlist admits only the organisations it names.
 *
 * The routes themselves are pinned in CartEndpointsGateTest: with the cart off, every one
 * of them is a 404 and writes nothing.
 */
class CartGateMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['api', 'cart.enabled'])
            ->get('/api/v1/cart-gate-probe', fn () => response()->json(['reached' => true]));
    }

    #[Test]
    public function the_cart_is_off_unless_it_is_switched_on(): void
    {
        $this->assertFalse(config('cart.enabled'), 'the shipped default is OFF');
        $this->assertFalse(EnsureCartEnabled::enabledFor(7));

        $this->getJson('/api/v1/cart-gate-probe', ['masjid-id' => '7'])->assertNotFound();
    }

    #[Test]
    public function off_it_answers_the_same_bytes_as_a_route_that_does_not_exist(): void
    {
        // Production runs with debug off: the router's own answer for a path it does not know.
        config(['app.debug' => false]);

        $unknown = $this->getJson('/api/v1/no-such-route', ['masjid-id' => '7'])->assertNotFound();
        $probe = $this->getJson('/api/v1/cart-gate-probe', ['masjid-id' => '7'])->assertNotFound();

        $this->assertSame($unknown->getContent(), $probe->getContent());

        // With debug on the router's message names the path; the gate's is the same sentence.
        config(['app.debug' => true]);

        $unknown = $this->getJson('/api/v1/no-such-route', ['masjid-id' => '7'])->assertNotFound();
        $probe = $this->getJson('/api/v1/cart-gate-probe', ['masjid-id' => '7'])->assertNotFound();

        $this->assertSame(
            str_replace('no-such-route', 'cart-gate-probe', (string) $unknown->json('message')),
            $probe->json('message')
        );
    }

    #[Test]
    public function switched_on_with_no_allowlist_it_passes_for_every_organisation(): void
    {
        config(['cart.enabled' => true, 'cart.masjid_ids' => []]);

        $this->getJson('/api/v1/cart-gate-probe', ['masjid-id' => '7'])->assertOk()->assertJsonPath('reached', true);
        $this->getJson('/api/v1/cart-gate-probe', ['masjid-id' => '4021'])->assertOk();

        // No header names no organisation; the controller answers that with its own 400.
        $this->getJson('/api/v1/cart-gate-probe')->assertOk();
    }

    #[Test]
    public function an_allowlist_admits_only_the_organisations_it_names(): void
    {
        config(['cart.enabled' => true, 'cart.masjid_ids' => [7, 12]]);

        $this->getJson('/api/v1/cart-gate-probe', ['masjid-id' => '7'])->assertOk();
        $this->getJson('/api/v1/cart-gate-probe', ['masjid-id' => '12'])->assertOk();
        $this->getJson('/api/v1/cart-gate-probe', ['masjid-id' => '8'])->assertNotFound();

        // The header is read as the controllers read it, `(int)` cast: `07` and `7x9` are both
        // organisation 7, so the gate and the controller can never disagree about whose it is...
        $this->getJson('/api/v1/cart-gate-probe', ['masjid-id' => '07'])->assertOk();
        $this->getJson('/api/v1/cart-gate-probe', ['masjid-id' => '7x9'])->assertOk();

        // ...and nothing that casts to 0 or to another number gets in.
        $this->getJson('/api/v1/cart-gate-probe')->assertNotFound();
        $this->getJson('/api/v1/cart-gate-probe', ['masjid-id' => 'abc'])->assertNotFound();
        $this->getJson('/api/v1/cart-gate-probe', ['masjid-id' => '87'])->assertNotFound();
    }

    #[Test]
    public function the_master_switch_wins_over_the_allowlist(): void
    {
        config(['cart.enabled' => false, 'cart.masjid_ids' => [7]]);

        $this->getJson('/api/v1/cart-gate-probe', ['masjid-id' => '7'])->assertNotFound();
    }

    #[Test]
    public function a_list_that_failed_closed_admits_nobody(): void
    {
        // config/cart.php turns a malformed CART_MASJID_IDS into [0] (CartConfigTest).
        config(['cart.enabled' => true, 'cart.masjid_ids' => [0]]);

        $this->getJson('/api/v1/cart-gate-probe', ['masjid-id' => '7'])->assertNotFound();
        $this->getJson('/api/v1/cart-gate-probe', ['masjid-id' => '0'])->assertNotFound();
        $this->getJson('/api/v1/cart-gate-probe')->assertNotFound();
    }
}
