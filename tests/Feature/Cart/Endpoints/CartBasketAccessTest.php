<?php

namespace Tests\Feature\Cart\Endpoints;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsBaskets;
use Tests\Feature\Cart\SignsCartWebhooks;
use Tests\TestCase;

/**
 * Who may open a basket, and what a basket says (brief 5, section 1): the token, the
 * organisation, the uniform 404, the sliding expiry and the priced view.
 *
 * The pins that matter most: the token comes back ONCE and only its HMAC is stored; org A's
 * token with org B's header is a 404, byte for byte the same as a wrong token; a line of
 * another basket cannot be removed; an offboarded organisation is a 404; and the view says
 * only what the page draws (no answers, no account, no fingerprint but the view one).
 */
class CartBasketAccessTest extends TestCase
{
    use BuildsBaskets;
    use CallsCartApi;
    use RefreshDatabase;
    use SignsCartWebhooks;

    private const NOT_AVAILABLE = 'This basket is not available.';

    private Carbon $t0;

    protected function setUp(): void
    {
        parent::setUp();

        // Frozen, so an expiry can be asserted to the second and a pickup is always five days out.
        $this->t0 = Carbon::parse('2026-10-01 12:00:00', 'UTC');
        Carbon::setTestNow($this->t0);

        $this->armWebhooks();
        $this->turnCartOn();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // -------------------------------------------------------------------- creating one

    #[Test]
    public function starting_a_basket_hands_back_the_token_once_and_keeps_only_its_digest(): void
    {
        $org = $this->org();

        $response = $this->cartApi('POST', '/api/v1/carts', $org)->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure(['data' => ['token', 'expires_at']]);

        $token = (string) $response->json('data.token');

        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $token, '32 random bytes as hex');
        $this->assertSame('2026-10-08T12:00:00+00:00', $response->json('data.expires_at'), 'seven days, from now');

        $cart = Cart::withoutMasjidScope()->sole();

        $this->assertSame((int) $org->id, (int) $cart->masjid_id, 'the organisation is stamped by hand: nothing binds a tenant here');
        $this->assertSame(Cart::hashToken($token), $cart->token_hash);
        $this->assertNotSame($token, $cart->token_hash);
        $this->assertNotSame(hash('sha256', $token), $cart->token_hash, 'a keyed digest, not a bare SHA-256');
        $this->assertNull($cart->contact_id);
        $this->assertSame(Cart::STATUS_OPEN, $cart->status);
        $this->assertTrue($cart->expires_at->equalTo($this->t0->copy()->addDays(7)));

        foreach (['carts', 'cart_items'] as $table) {
            $this->assertStringNotContainsString($token, (string) json_encode(DB::table($table)->get()), "the plaintext is nowhere in {$table}");
        }
    }

    #[Test]
    public function every_basket_gets_its_own_token(): void
    {
        $org = $this->org();

        $this->assertNotSame($this->startBasket($org), $this->startBasket($org));
        $this->assertSame(2, Cart::withoutMasjidScope()->count());
    }

    #[Test]
    public function the_token_is_in_no_later_response(): void
    {
        $org = $this->org();
        $fund = $this->fund($org);
        $token = $this->startBasket($org);

        $responses = [];
        $responses[] = $this->cartApi('GET', '/api/v1/cart', $org, $token);

        $added = $this->addLine($org, $token, $this->giftBody($fund->id))->assertOk();
        $responses[] = $added;

        $responses[] = $this->cartApi('POST', '/api/v1/cart/acknowledge', $org, $token, [
            'seen' => $added->json('data.view_fingerprint'),
        ])->assertOk();
        $responses[] = $this->cartApi('POST', '/api/v1/cart/checkout', $org, $token, $this->checkoutBody(), self::ORIGIN)->assertOk();
        $responses[] = $this->cartApi('DELETE', '/api/v1/cart/items/' . $added->json('data.line_id'), $org, $token)->assertOk();
        $responses[] = $this->cartApi('GET', '/api/v1/cart', $org, str_repeat('0', 64))->assertNotFound();

        foreach ($responses as $response) {
            $this->assertStringNotContainsString($token, $response->getContent());
            $this->assertStringNotContainsString(Cart::hashToken($token), $response->getContent(), 'nor its digest');
        }
    }

    #[Test]
    public function a_missing_organisation_is_a_400_and_an_unknown_one_the_uniform_404(): void
    {
        $this->cartApi('POST', '/api/v1/carts', null)->assertStatus(400)->assertJsonPath('message', 'A masjid must be specified.');
        $this->cartApi('GET', '/api/v1/cart', null, str_repeat('a', 64))->assertStatus(400);

        $this->getJson('/api/v1/cart', ['masjid-id' => 'abc', 'Cart-Token' => str_repeat('a', 64)])->assertStatus(400);

        $this->cartApi('POST', '/api/v1/carts', $this->org())->assertOk();
        $this->postJson('/api/v1/carts', [], ['masjid-id' => '999999'])
            ->assertNotFound()
            ->assertJsonPath('message', self::NOT_AVAILABLE);
        $this->assertSame(1, Cart::withoutMasjidScope()->count(), 'no basket was made for an organisation that does not exist');
    }

    // ------------------------------------------------------------------- opening one

    #[Test]
    public function every_way_a_basket_cannot_be_opened_is_one_404_byte_for_byte(): void
    {
        $orgA = $this->org();
        $orgB = $this->org();
        $fund = $this->fund($orgA);
        $token = $this->startBasket($orgA);
        $this->addLine($orgA, $token, $this->giftBody($fund->id))->assertOk();

        $expired = str_repeat('e', 64);
        Cart::withoutMasjidScope()->create([
            'masjid_id' => $orgA->id,
            'token_hash' => Cart::hashToken($expired),
            'expires_at' => $this->t0->copy()->subMinute(),
        ]);

        $cases = [
            'a wrong token' => [$orgA, str_repeat('0', 64)],
            'no token at all' => [$orgA, null],
            'a malformed token' => [$orgA, 'not-a-token'],
            'the right token in capitals' => [$orgA, strtoupper($token)],
            "org A's token with org B's header" => [$orgB, $token],
            'an expired basket' => [$orgA, $expired],
        ];

        $routes = [
            ['GET', '/api/v1/cart', []],
            ['POST', '/api/v1/cart/items', $this->giftBody($fund->id)],
            ['DELETE', '/api/v1/cart/items/1', []],
            ['POST', '/api/v1/cart/acknowledge', ['seen' => str_repeat('a', 64)]],
            ['POST', '/api/v1/cart/checkout', $this->checkoutBody()],
        ];

        $bodies = [];

        foreach ($cases as $why => [$org, $sent]) {
            foreach ($routes as [$method, $uri, $body]) {
                $response = $this->cartApi($method, $uri, $org, $sent, $body, self::ORIGIN);

                $this->assertSame(404, $response->getStatusCode(), "{$why}: {$method} {$uri}");
                $bodies[$response->getContent()] = "{$why}: {$method} {$uri}";
            }
        }

        $this->assertCount(1, $bodies, 'these answers differ: ' . json_encode(array_values($bodies)));
        $this->assertSame(self::NOT_AVAILABLE, json_decode((string) array_key_first($bodies), true)['message']);

        // And none of them touched the basket.
        $this->assertSame(1, CartItem::withoutMasjidScope()->where('cart_id', $this->basketOf($token)->id)->count());
        $this->assertSame(0, Order::withoutMasjidScope()->count());
    }

    #[Test]
    public function another_organisations_header_never_opens_or_changes_a_basket(): void
    {
        $orgA = $this->org();
        $orgB = $this->org();
        $fund = $this->fund($orgA);
        $token = $this->startBasket($orgA);
        $line = $this->addLine($orgA, $token, $this->giftBody($fund->id))->json('data.line_id');

        $this->cartApi('DELETE', "/api/v1/cart/items/{$line}", $orgB, $token)->assertNotFound();
        $this->cartApi('POST', '/api/v1/cart/items', $orgB, $token, $this->giftBody($fund->id))->assertNotFound();

        $this->assertDatabaseHas('cart_items', ['id' => $line]);
        $this->assertSame(1, CartItem::withoutMasjidScope()->count());
        $this->assertSame(0, Cart::withoutMasjidScope()->where('masjid_id', $orgB->id)->count());

        // Org A, with its own header, still has it.
        $this->cartApi('GET', '/api/v1/cart', $orgA, $token)->assertOk()->assertJsonCount(1, 'data.lines');
    }

    #[Test]
    public function a_line_of_another_basket_cannot_be_removed(): void
    {
        $org = $this->org();
        $fund = $this->fund($org);
        $one = $this->startBasket($org);
        $two = $this->startBasket($org);
        $lineOne = $this->addLine($org, $one, $this->giftBody($fund->id))->json('data.line_id');
        $lineTwo = $this->addLine($org, $two, $this->giftBody($fund->id, 2500))->json('data.line_id');

        // Basket two names basket one's line: the same 404 as a line that never existed.
        $theirs = $this->cartApi('DELETE', "/api/v1/cart/items/{$lineOne}", $org, $two)
            ->assertNotFound()
            ->assertJsonPath('message', 'That item is not in your basket.');
        $never = $this->cartApi('DELETE', '/api/v1/cart/items/999999', $org, $two)->assertNotFound();

        $this->assertSame($never->getContent(), $theirs->getContent());
        $this->assertDatabaseHas('cart_items', ['id' => $lineOne]);

        // Its own line goes, and only that one.
        $this->cartApi('DELETE', "/api/v1/cart/items/{$lineTwo}", $org, $two)
            ->assertOk()
            ->assertJsonPath('data.lines', [])
            ->assertJsonPath('data.total_minor', 0);
        $this->assertDatabaseMissing('cart_items', ['id' => $lineTwo]);
        $this->assertDatabaseHas('cart_items', ['id' => $lineOne]);

        // A second delete of the same line is the same 404, not a second success.
        $this->cartApi('DELETE', "/api/v1/cart/items/{$lineTwo}", $org, $two)->assertNotFound();
    }

    #[Test]
    public function an_offboarded_organisation_is_the_same_404_and_writes_nothing(): void
    {
        $org = $this->org();
        $fund = $this->fund($org);
        $token = $this->startBasket($org);
        $line = $this->addLine($org, $token, $this->giftBody($fund->id))->json('data.line_id');

        $uniform = $this->cartApi('GET', '/api/v1/cart', $org, str_repeat('0', 64))->assertNotFound()->getContent();

        $org->delete();

        foreach ([
            ['GET', '/api/v1/cart', []],
            ['POST', '/api/v1/carts', []],
            ['POST', '/api/v1/cart/items', $this->giftBody($fund->id)],
            ['DELETE', "/api/v1/cart/items/{$line}", []],
            ['POST', '/api/v1/cart/acknowledge', ['seen' => str_repeat('a', 64)]],
            ['POST', '/api/v1/cart/checkout', $this->checkoutBody()],
        ] as [$method, $uri, $body]) {
            $response = $this->cartApi($method, $uri, $org, $token, $body, self::ORIGIN);

            $this->assertSame(404, $response->getStatusCode(), "{$method} {$uri}");
            $this->assertSame($uniform, $response->getContent(), "{$method} {$uri} is not the uniform 404");
        }

        $this->assertSame(1, Cart::withoutMasjidScope()->count(), 'no basket was made for a deleted organisation');
        $this->assertSame(1, CartItem::withoutMasjidScope()->count(), 'and the old line was not removed');
        $this->assertSame(0, Order::withoutMasjidScope()->count());
    }

    // ---------------------------------------------------------------- the sliding expiry

    #[Test]
    public function every_write_pushes_the_expiry_out_from_now_and_a_read_does_not(): void
    {
        $org = $this->org();
        $fund = $this->fund($org);
        $token = $this->startBasket($org);
        $expiry = fn (): Carbon => $this->basketOf($token)->expires_at;

        $this->assertTrue($expiry()->equalTo($this->t0->copy()->addDays(7)));

        // Day 3: a read leaves it; an add pushes it to day 3 + 7.
        Carbon::setTestNow($this->t0->copy()->addDays(3));
        $this->cartApi('GET', '/api/v1/cart', $org, $token)->assertOk();
        $this->assertTrue($expiry()->equalTo($this->t0->copy()->addDays(7)), 'a read is not a write');

        $added = $this->addLine($org, $token, $this->giftBody($fund->id))->assertOk();
        $this->assertTrue($expiry()->equalTo($this->t0->copy()->addDays(10)), 'add');

        // Day 4: acknowledge.
        Carbon::setTestNow($this->t0->copy()->addDays(4));
        $this->cartApi('POST', '/api/v1/cart/acknowledge', $org, $token, ['seen' => $added->json('data.view_fingerprint')])->assertOk();
        $this->assertTrue($expiry()->equalTo($this->t0->copy()->addDays(11)), 'acknowledge');

        // Day 5: checkout (a shopper on Stripe's screen is not idle).
        Carbon::setTestNow($this->t0->copy()->addDays(5));
        $this->cartApi('POST', '/api/v1/cart/checkout', $org, $token, $this->checkoutBody(), self::ORIGIN)->assertOk();
        $this->assertTrue($expiry()->equalTo($this->t0->copy()->addDays(12)), 'checkout');

        // Day 6: remove.
        Carbon::setTestNow($this->t0->copy()->addDays(6));
        $this->cartApi('DELETE', '/api/v1/cart/items/' . $added->json('data.line_id'), $org, $token)->assertOk();
        $this->assertTrue($expiry()->equalTo($this->t0->copy()->addDays(13)), 'remove');
    }

    #[Test]
    public function a_refused_write_does_not_extend_it(): void
    {
        $org = $this->org();
        $token = $this->startBasket($org);

        Carbon::setTestNow($this->t0->copy()->addDays(2));
        $this->addLine($org, $token, ['type' => 'donation', 'fund_id' => 999999, 'amount_minor' => 5000])->assertStatus(422);

        $this->assertTrue($this->basketOf($token)->expires_at->equalTo($this->t0->copy()->addDays(7)));
    }

    #[Test]
    public function a_basket_untouched_for_a_week_is_gone_to_its_holder(): void
    {
        $org = $this->org();
        $token = $this->startBasket($org);

        Carbon::setTestNow($this->t0->copy()->addDays(7)->subSecond());
        $this->cartApi('GET', '/api/v1/cart', $org, $token)->assertOk();

        Carbon::setTestNow($this->t0->copy()->addDays(7)->addSecond());
        $this->cartApi('GET', '/api/v1/cart', $org, $token)->assertNotFound()->assertJsonPath('message', self::NOT_AVAILABLE);
    }

    // ------------------------------------------------------------------- the priced view

    #[Test]
    public function the_view_says_only_what_the_page_draws(): void
    {
        $org = $this->org();
        $form = $this->ticketForm($org);
        $dish = $this->dish($org);
        $fund = $this->fund($org);
        $token = $this->startBasket($org);

        $this->addLine($org, $token, [
            'type' => 'form',
            'form_id' => $form->id,
            'answers' => ['tickets' => [['attendeeName' => 'Yusuf Khan'], ['attendeeName' => 'Maryam Khan']]],
        ])->assertOk();
        $this->addLine($org, $token, $this->dishBody($dish->id, 2, '2026-10-06T14:00'))->assertOk();
        $this->addLine($org, $token, $this->giftBody($fund->id))->assertOk();

        $response = $this->cartApi('GET', '/api/v1/cart', $org, $token)->assertOk();
        $data = $response->json('data');

        $this->assertEqualsCanonicalizing(
            ['currency', 'lines', 'notices', 'refusal', 'total_minor', 'view_fingerprint'],
            array_keys($data)
        );
        $this->assertSame(10400, $data['total_minor']);
        $this->assertSame('usd', $data['currency']);
        $this->assertSame([], $data['notices']);
        $this->assertNull($data['refusal']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $data['view_fingerprint']);

        $this->assertCount(3, $data['lines']);

        foreach ($data['lines'] as $line) {
            $this->assertEqualsCanonicalizing(['id', 'label', 'quantity', 'reason', 'status', 'type', 'unit_minor'], array_keys($line));
            $this->assertSame('available', $line['status']);
            $this->assertNull($line['reason']);
        }

        [$tickets, $lamb, $gift] = $data['lines'];

        $this->assertSame(['form', 'Festival Tickets', 2, 1500], [$tickets['type'], $tickets['label'], $tickets['quantity'], $tickets['unit_minor']]);
        $this->assertSame(['meal', 'Baked Lamb', 2, 1200], [$lamb['type'], $lamb['label'], $lamb['quantity'], $lamb['unit_minor']]);
        $this->assertSame(['donation', 'Zakat-ul-Fitr', 1, 5000], [$gift['type'], $gift['label'], $gift['quantity'], $gift['unit_minor']]);

        // Never the answers, the pickup, the payee account or any fingerprint but the view one.
        $content = $response->getContent();

        foreach (['Yusuf', 'Maryam', 'attendeeName', 'pickup_at', 'payload', 'acct_', 'fingerprint_', 'basket_fingerprint', 'charge_', 'token'] as $secret) {
            $this->assertStringNotContainsString($secret, $content, "the view leaks '{$secret}'");
        }

        $this->assertSame(1, substr_count($content, $data['view_fingerprint']), 'exactly one fingerprint is exposed');
    }

    #[Test]
    public function the_view_names_what_changed_and_the_fingerprint_moves_with_it(): void
    {
        $org = $this->org();
        $fund = $this->fund($org);
        $dish = $this->dish($org);
        $token = $this->startBasket($org);

        $this->addLine($org, $token, $this->giftBody($fund->id))->assertOk();
        $this->addLine($org, $token, $this->dishBody($dish->id, 1, '2026-10-06T14:00'))->assertOk();
        $before = $this->cartApi('GET', '/api/v1/cart', $org, $token)->assertOk()->json('data');

        // The price moves while the basket sits, and the fund closes.
        $dish->forceFill(['price_minor' => 1500])->save();
        $fund->forceFill(['is_active' => false])->save();

        $after = $this->cartApi('GET', '/api/v1/cart', $org, $token)->assertOk()->json('data');

        $this->assertNotSame($before['view_fingerprint'], $after['view_fingerprint']);
        $this->assertSame(1500, $after['total_minor'], 'only the dish is still on sale, at its new price');
        // In the order the lines were added: the gift, then the dish.
        $this->assertSame(
            [
                ['label' => 'Zakat-ul-Fitr', 'status' => 'gone', 'reason' => 'This fund is no longer collecting.'],
                ['label' => 'Baked Lamb', 'status' => 'repriced', 'reason' => 'The price changed while this was in your basket.'],
            ],
            $after['notices']
        );
    }

    // ------------------------------------------------------------------ a closed basket

    #[Test]
    public function a_basket_that_was_paid_for_reads_but_takes_no_more_changes(): void
    {
        $org = $this->org();
        $fund = $this->fund($org);
        $token = $this->startBasket($org);
        $line = $this->addLine($org, $token, $this->giftBody($fund->id))->json('data.line_id');

        $this->basketOf($token)->forceFill(['status' => Cart::STATUS_CHECKED_OUT])->save();

        $paid = 'This basket has already been paid for.';

        $this->cartApi('GET', '/api/v1/cart', $org, $token)->assertOk();
        $this->addLine($org, $token, $this->giftBody($fund->id))->assertStatus(422)->assertJsonPath('message', $paid);
        $this->cartApi('DELETE', "/api/v1/cart/items/{$line}", $org, $token)->assertStatus(422)->assertJsonPath('message', $paid);
        $this->cartApi('POST', '/api/v1/cart/acknowledge', $org, $token, ['seen' => str_repeat('a', 64)])->assertStatus(422)->assertJsonPath('message', $paid);
        $this->cartApi('POST', '/api/v1/cart/checkout', $org, $token, $this->checkoutBody(), self::ORIGIN)->assertStatus(422)->assertJsonPath('message', $paid);

        $this->assertDatabaseHas('cart_items', ['id' => $line]);
        $this->assertSame(1, CartItem::withoutMasjidScope()->count());
        $this->assertSame(0, Order::withoutMasjidScope()->count());
    }
}
