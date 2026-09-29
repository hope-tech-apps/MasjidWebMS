<?php

namespace Tests\Feature\Cart\Endpoints;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Contact;
use App\Models\Donation;
use App\Models\Masjid;
use App\Models\MealOrder;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Cart\CartCheckoutService;
use App\Services\Stripe\FormResponseCheckoutService;
use App\Support\FormPaymentReturn;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Stripe\Exception\ApiConnectionException;
use Stripe\StripeClient;
use Tests\Feature\Cart\BuildsBaskets;
use Tests\Feature\Cart\SignsCartWebhooks;
use Tests\TestCase;

/**
 * POST /api/v1/cart/checkout, POST /api/v1/cart/acknowledge and the payment-state read
 * (brief 5, section 1): the buyer's rules, the return address, a basket that changed and is
 * acknowledged, the page that opens, and what the return page is told afterwards.
 */
class CartCheckoutEndpointTest extends TestCase
{
    use BuildsBaskets;
    use CallsCartApi;
    use RefreshDatabase;
    use SignsCartWebhooks;

    private Carbon $t0;

    protected function setUp(): void
    {
        parent::setUp();

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

    /** POST /cart/checkout the way the renderer sends it: JSON, from an allowlisted origin. */
    private function checkout(Masjid $org, string $token, array $body = [], ?string $origin = self::ORIGIN): TestResponse
    {
        return $this->cartApi('POST', '/api/v1/cart/checkout', $org, $token, $body === [] ? $this->checkoutBody() : $body, $origin);
    }

    private function orderOf(string $uuid): Order
    {
        return Order::withoutMasjidScope()->where('uuid', $uuid)->firstOrFail();
    }

    /** A basket of one $50 gift, and its token. */
    private function giftBasket(): array
    {
        $org = $this->org();
        $fund = $this->fund($org);
        $token = $this->startBasket($org);
        $this->addLine($org, $token, $this->giftBody($fund->id))->assertOk();

        return [$org, $token, $fund];
    }

    // ---------------------------------------------------------------- the page opens

    #[Test]
    public function checkout_opens_one_page_for_the_whole_basket_and_answers_its_url_and_the_orders_uuid(): void
    {
        $org = $this->org();
        $token = $this->startBasket($org);
        $this->addLine($org, $token, $this->twoTicketsBody($this->ticketForm($org)->id))->assertOk();
        $this->addLine($org, $token, $this->dishBody($this->dish($org)->id, 2, '2026-10-06T14:00'))->assertOk();
        $this->addLine($org, $token, $this->giftBody($this->fund($org)->id))->assertOk();

        $response = $this->checkout($org, $token)->assertOk()->assertJsonPath('status', 'success');

        $this->assertEqualsCanonicalizing(['checkout_url', 'order_uuid'], array_keys($response->json('data')));
        $this->assertSame('https://checkout.stripe.test/1', $response->json('data.checkout_url'));
        $this->assertMatchesRegularExpression('/\A[0-9a-f-]{36}\z/', $response->json('data.order_uuid'));

        $order = $this->orderOf($response->json('data.order_uuid'));

        $this->assertSame((int) $org->id, (int) $order->masjid_id);
        $this->assertSame(Order::STATUS_PENDING, $order->status);
        $this->assertSame((int) $this->basketOf($token)->id, (int) $order->cart_id);
        $this->assertSame(10400, (int) $order->total_minor, 'two tickets, two dishes and a gift');
        $this->assertSame('Zaynab Buyer', $order->buyer_name);
        $this->assertSame('+1 555 010 0100', $order->buyer_phone);
        $this->assertSame('zaynab@example.org', $order->buyer_email);
        $this->assertSame(3, OrderItem::withoutMasjidScope()->where('order_id', $order->id)->count());

        // ONE page, on the organisation's own account, returning to the allowlisted origin and
        // the path the page sent, carrying the uuid the status read is keyed by.
        $this->assertCount(1, $this->stripe->created);
        $created = $this->stripe->created[0];
        $this->assertSame($org->stripe_account_id, $created['account']);
        $this->assertSame(
            self::ORIGIN . self::RETURN_PATH . '?cart_order_uuid=' . $order->uuid . '&paid=1',
            $created['params']['success_url']
        );
        $this->assertSame(
            self::ORIGIN . self::RETURN_PATH . '?cart_order_uuid=' . $order->uuid . '&cancelled=1',
            $created['params']['cancel_url']
        );
        $this->assertSame('zaynab@example.org', $created['params']['customer_email']);
        $this->assertStringNotContainsString($token, json_encode($created['params']), 'the basket token never goes to Stripe');
    }

    #[Test]
    public function checkout_works_in_the_encoding_the_browser_sends(): void
    {
        // The SPA posts form-encoded; a nested buyer arrives as buyer[name] and so on
        // (.claude/rules/shipping.md: write at least one test in the client's own encoding).
        [$org, $token] = $this->giftBasket();

        $this->post('/api/v1/cart/checkout', [
            'buyer' => ['name' => 'Zaynab Buyer', 'email' => 'zaynab@example.org', 'phone' => ''],
            'return_path' => self::RETURN_PATH,
            'website' => '',
        ], $this->cartHeaders($org, $token, self::ORIGIN) + ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonStructure(['data' => ['checkout_url', 'order_uuid']]);

        $this->assertNull(Order::withoutMasjidScope()->sole()->buyer_phone, 'an empty phone is no phone');
    }

    #[Test]
    public function pressing_pay_again_hands_back_the_same_page_with_the_phone_typed_last(): void
    {
        $org = $this->org();
        $token = $this->startBasket($org);
        $this->addLine($org, $token, $this->dishBody($this->dish($org)->id, 1, '2026-10-06T14:00'))->assertOk();

        $first = $this->checkout($org, $token, $this->checkoutBody(['phone' => '+1 555 000 0000']))->assertOk();
        $again = $this->checkout($org, $token, $this->checkoutBody(['phone' => '+1 555 010 0100']))->assertOk();

        $this->assertSame($first->json('data.order_uuid'), $again->json('data.order_uuid'));
        $this->assertCount(1, $this->stripe->created, 'a second page was not opened');
        $this->assertSame('+1 555 010 0100', Order::withoutMasjidScope()->sole()->buyer_phone, 'the office rings what was typed last');
    }

    #[Test]
    public function pressing_pay_again_with_a_corrected_email_opens_a_page_for_the_new_address(): void
    {
        [$org, $token] = $this->giftBasket();

        $first = $this->checkout($org, $token, $this->checkoutBody(['email' => 'zaynab@exmaple.org']))->assertOk();
        $again = $this->checkout($org, $token, $this->checkoutBody(['email' => 'zaynab@example.org']))->assertOk();

        $this->assertNotSame($first->json('data.order_uuid'), $again->json('data.order_uuid'));
        $this->assertNotSame($first->json('data.checkout_url'), $again->json('data.checkout_url'));
        $this->assertCount(2, $this->stripe->created);
        $this->assertSame('zaynab@example.org', $this->stripe->created[1]['params']['customer_email']);
        $this->assertSame('zaynab@example.org', $this->orderOf($again->json('data.order_uuid'))->buyer_email);
        $this->assertSame(Order::STATUS_EXPIRED, $this->orderOf($first->json('data.order_uuid'))->status, 'the page of the typo is closed');
    }

    #[Test]
    public function an_empty_basket_has_nothing_to_pay_for(): void
    {
        $org = $this->org();
        $token = $this->startBasket($org);

        $this->checkout($org, $token)
            ->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'Your basket has nothing to pay for.');

        $this->assertSame(0, Order::withoutMasjidScope()->count());
        $this->assertSame([], $this->stripe->created);
    }

    // -------------------------------------------------------------------- the buyer

    #[Test]
    public function the_buyer_must_give_a_name_and_a_real_address(): void
    {
        [$org, $token] = $this->giftBasket();

        $bad = [
            'no buyer at all' => [['buyer' => null], 'buyer'],
            'no name' => [['buyer' => ['name' => '']], 'buyer.name'],
            'a name over 120 characters' => [['buyer' => ['name' => str_repeat('N', 121)]], 'buyer.name'],
            'no email' => [['buyer' => ['email' => '']], 'buyer.email'],
            'an email that is not one' => [['buyer' => ['email' => 'not-an-email']], 'buyer.email'],
            'an email with no domain' => [['buyer' => ['email' => 'zaynab@']], 'buyer.email'],
            'an email over 190 characters' => [['buyer' => ['email' => str_repeat('a', 185) . '@x.org']], 'buyer.email'],
            'a phone over 32 characters' => [['buyer' => ['phone' => str_repeat('9', 33)]], 'buyer.phone'],
        ];

        foreach ($bad as $why => [$override, $field]) {
            $body = $this->checkoutBody();

            if ($override['buyer'] === null) {
                unset($body['buyer']);
            } else {
                $body['buyer'] = array_merge($body['buyer'], $override['buyer']);
            }

            $response = $this->checkout($org, $token, $body)->assertStatus(422)->assertJsonPath('status', 'failed');

            $this->assertArrayHasKey($field, $response->json('data'), $why);
        }

        $this->assertSame(0, Order::withoutMasjidScope()->count(), 'nothing was opened for a buyer who was refused');
        $this->assertSame([], $this->stripe->created);
    }

    #[Test]
    public function a_phone_is_required_when_the_basket_has_a_dish_and_optional_otherwise(): void
    {
        // Both meal doors require a phone, so the kitchen can ring the buyer.
        $org = $this->org();
        $meals = $this->startBasket($org);
        $this->addLine($org, $meals, $this->dishBody($this->dish($org)->id, 1, '2026-10-06T14:00'))->assertOk();

        $noPhone = $this->checkoutBody();
        unset($noPhone['buyer']['phone']);

        $response = $this->checkout($org, $meals, $noPhone)->assertStatus(422)->assertJsonPath('status', 'failed');
        $this->assertArrayHasKey('buyer.phone', $response->json('data'));
        $this->assertSame(0, Order::withoutMasjidScope()->count());

        $this->checkout($org, $meals)->assertOk();

        // A basket with no dish in it does not ask.
        [$giftOrg, $gifts] = $this->giftBasket();
        $this->checkout($giftOrg, $gifts, $noPhone)->assertOk();

        $this->assertNull(Order::withoutMasjidScope()->where('masjid_id', $giftOrg->id)->sole()->buyer_phone);
    }

    #[Test]
    public function a_dish_added_after_the_phone_rule_was_read_is_still_refused_under_the_lock(): void
    {
        // Tab 1 posts checkout for a basket that holds only a gift, so the controller's read says
        // the phone is optional. Tab 2's dish lands before checkout takes the basket lock. The
        // service prices the basket as it stands under the lock, dish included, and refuses.
        [$org, $token] = $this->giftBasket();
        $dish = $this->dish($org);
        $cart = $this->basketOf($token);

        $service = new class(new StripeClient('sk_test_offline')) extends CartCheckoutService {
            public ?\Closure $beforeLock = null;

            public array $created = [];

            public function checkout(
                Cart $cart,
                string $returnBase,
                ?string $buyerEmail = null,
                ?string $buyerName = null,
                ?string $buyerPhone = null,
                bool $requirePhoneForMeals = false,
            ): array {
                // Once: the other tab's add happens the first time only.
                $other = $this->beforeLock;
                $this->beforeLock = null;

                if ($other !== null) {
                    $other();
                }

                return parent::checkout($cart, $returnBase, $buyerEmail, $buyerName, $buyerPhone, $requirePhoneForMeals);
            }

            protected function createCheckoutSession(array $params, string $connectedAccountId, string $idempotencyKey): array
            {
                $this->created[] = $params;

                return ['id' => 'cs_race_1', 'url' => 'https://checkout.stripe.test/race', 'payment_intent' => null];
            }
        };
        $service->beforeLock = fn () => $this->add($cart, CartItem::TYPE_MEAL, $dish->id, 1200, 1);
        $this->app->instance(CartCheckoutService::class, $service);

        $body = $this->checkoutBody();
        unset($body['buyer']['phone']);

        $this->checkout($org, $token, $body)
            ->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', CartCheckoutService::PHONE_REQUIRED);

        $this->assertSame(2, CartItem::withoutMasjidScope()->where('cart_id', $cart->id)->count(), 'premise: the dish did land');
        $this->assertSame([], $service->created, 'no page was opened for a dish nobody can ring about');
        $this->assertSame(0, Order::withoutMasjidScope()->count());

        // With a phone the same basket opens.
        $this->checkout($org, $token)->assertOk();
        $this->assertCount(1, $service->created);
    }

    // ------------------------------------------------------------------ return base

    #[Test]
    public function a_return_address_the_form_door_would_refuse_is_refused_with_its_one_message(): void
    {
        [$org, $token] = $this->giftBasket();

        $refused = [
            'no Origin' => [null, self::RETURN_PATH],
            'an Origin that is not on the list' => ['https://evil.example', self::RETURN_PATH],
            'an Origin with a path' => [self::ORIGIN . '/basket', self::RETURN_PATH],
            'no return path' => [self::ORIGIN, null],
            'an absolute return path' => [self::ORIGIN, 'https://evil.example/x'],
            'a protocol-relative return path' => [self::ORIGIN, '//evil.example/x'],
            'a return path with a query' => [self::ORIGIN, '/basket?next=https://evil.example'],
        ];

        foreach ($refused as $why => [$origin, $path]) {
            $body = $this->checkoutBody();

            if ($path === null) {
                unset($body['return_path']);
            } else {
                $body['return_path'] = $path;
            }

            $this->checkout($org, $token, $body, $origin)
                ->assertStatus(422)
                ->assertJsonPath('status', 'error')
                ->assertJsonPath('message', FormPaymentReturn::REFUSED);
        }

        $this->assertSame(0, Order::withoutMasjidScope()->count());
        $this->assertSame([], $this->stripe->created);

        // The list is what decides: the same request from a listed origin opens the page.
        $this->checkout($org, $token, $this->checkoutBody(), self::ORIGIN)->assertOk();
    }

    #[Test]
    public function with_no_allowlist_and_no_confirmed_domain_no_page_opens_at_all(): void
    {
        [$org, $token] = $this->giftBasket();
        config(['forms.payment_return_origins' => []]);

        $this->checkout($org, $token)->assertStatus(422)->assertJsonPath('message', FormPaymentReturn::REFUSED);
        $this->assertSame(0, Order::withoutMasjidScope()->count());
    }

    // -------------------------------------------------- a changed basket: 409 and acknowledge

    #[Test]
    public function a_basket_that_changed_is_a_409_with_its_notices_until_it_is_acknowledged(): void
    {
        $org = $this->org();
        $fund = $this->fund($org);
        $dish = $this->dish($org);
        $token = $this->startBasket($org);
        $this->addLine($org, $token, $this->giftBody($fund->id))->assertOk();
        $this->addLine($org, $token, $this->dishBody($dish->id, 1, '2026-10-06T14:00'))->assertOk();

        // While it sat there: the fund closed and the dish went up.
        $fund->forceFill(['is_active' => false])->save();
        $dish->forceFill(['price_minor' => 1500])->save();

        $notices = [
            ['label' => 'Zakat-ul-Fitr', 'status' => 'gone', 'reason' => 'This fund is no longer collecting.'],
            ['label' => 'Baked Lamb', 'status' => 'repriced', 'reason' => 'The price changed while this was in your basket.'],
        ];

        $changed = $this->checkout($org, $token)
            ->assertStatus(409)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'Some things in your basket changed. Please check them before paying.')
            ->assertJsonPath('data.notices', $notices);

        $seen = $changed->json('data.view_fingerprint');

        $this->assertSame($seen, $this->cartApi('GET', '/api/v1/cart', $org, $token)->json('data.view_fingerprint'), 'the page acknowledges what the basket says now');
        $this->assertSame(0, Order::withoutMasjidScope()->count(), 'no page was opened for a basket the shopper has not seen');
        $this->assertSame([], $this->stripe->created);

        // "OK": what went is dropped, what changed takes its current price.
        $this->cartApi('POST', '/api/v1/cart/acknowledge', $org, $token, ['seen' => $seen])
            ->assertOk()
            ->assertJsonPath('data.notices', [])
            ->assertJsonPath('data.total_minor', 1500)
            ->assertJsonCount(1, 'data.lines')
            ->assertJsonPath('data.lines.0.label', 'Baked Lamb')
            ->assertJsonPath('data.lines.0.status', 'available')
            ->assertJsonPath('data.lines.0.unit_minor', 1500);
        $this->assertSame(1, CartItem::withoutMasjidScope()->count());

        // ...and now the same button works, at the price they agreed to.
        $ok = $this->checkout($org, $token)->assertOk();
        $this->assertSame(1500, (int) $this->orderOf($ok->json('data.order_uuid'))->total_minor);
    }

    #[Test]
    public function an_acknowledgement_of_what_was_seen_applies_nothing_when_the_basket_moved_again(): void
    {
        $org = $this->org();
        $fund = $this->fund($org);
        $dish = $this->dish($org);
        $token = $this->startBasket($org);
        $this->addLine($org, $token, $this->giftBody($fund->id))->assertOk();
        $this->addLine($org, $token, $this->dishBody($dish->id, 1, '2026-10-06T14:00'))->assertOk();

        $fund->forceFill(['is_active' => false])->save();
        $dish->forceFill(['price_minor' => 1500])->save();
        $seen = $this->checkout($org, $token)->assertStatus(409)->json('data.view_fingerprint');

        // A second price edit between the notice and the shopper's "OK".
        $dish->forceFill(['price_minor' => 1600])->save();

        $again = $this->cartApi('POST', '/api/v1/cart/acknowledge', $org, $token, ['seen' => $seen])
            ->assertStatus(409)
            ->assertJsonPath('status', 'error');

        $this->assertNotSame($seen, $again->json('data.view_fingerprint'), 'the new state, to be seen before it is agreed to');
        $this->assertSame(2, CartItem::withoutMasjidScope()->count(), 'nothing was dropped');
        $this->assertSame(1200, (int) CartItem::withoutMasjidScope()->where('buyable_type', CartItem::TYPE_MEAL)->sole()->unit_amount_shown_minor);
    }

    #[Test]
    public function the_409_shows_the_basket_the_shopper_is_asked_to_accept(): void
    {
        // A notice is a sentence with no amount. Acknowledging adopts the new price and quantity, so
        // the 409 must carry them: the shopper agrees to what they were shown, never to a sentence.
        $org = $this->org();
        $fund = $this->fund($org);
        $dish = $this->dish($org);
        $token = $this->startBasket($org);
        $this->addLine($org, $token, $this->giftBody($fund->id))->assertOk();
        $this->addLine($org, $token, $this->dishBody($dish->id, 2, '2026-10-06T14:00'))->assertOk();

        $dish->forceFill(['price_minor' => 1800])->save();

        $changed = $this->checkout($org, $token)->assertStatus(409)->assertJsonPath('status', 'error');

        // The repriced dish, at its NEW unit price and the quantity the shopper asked for.
        $dishLine = collect($changed->json('data.lines'))->firstWhere('label', 'Baked Lamb');
        $this->assertSame('repriced', $dishLine['status']);
        $this->assertSame(1800, $dishLine['unit_minor'], 'the new unit amount is in the body');
        $this->assertSame(2, $dishLine['quantity']);
        $this->assertSame(5000 + 2 * 1800, $changed->json('data.total_minor'), 'and so is the new total');
        $this->assertSame('usd', $changed->json('data.currency'));

        // Exactly what GET /cart returns, which is what acknowledge will apply.
        $this->assertSame($this->cartApi('GET', '/api/v1/cart', $org, $token)->json('data'), $changed->json('data'));
        $this->assertNotEmpty($changed->json('data.notices'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $changed->json('data.view_fingerprint'));

        // Nothing that /cart withholds is in it either.
        foreach (['answers', 'payload', 'acct_', 'basket_fingerprint'] as $private) {
            $this->assertStringNotContainsString($private, $changed->getContent(), "the 409 leaks '{$private}'");
        }

        // A basket that moved again under the acknowledge is the same 409, with its new prices.
        $dish->forceFill(['price_minor' => 2000])->save();
        $again = $this->cartApi('POST', '/api/v1/cart/acknowledge', $org, $token, ['seen' => $changed->json('data.view_fingerprint')])
            ->assertStatus(409);
        $this->assertSame(
            2000,
            collect($again->json('data.lines'))->firstWhere('label', 'Baked Lamb')['unit_minor'],
            'the acknowledge 409 shows the price it moved to'
        );
        $this->assertSame(5000 + 2 * 2000, $again->json('data.total_minor'));
    }

    #[Test]
    public function acknowledge_needs_what_was_seen(): void
    {
        [$org, $token] = $this->giftBasket();

        foreach ([[], ['seen' => ''], ['seen' => 'abc'], ['seen' => strtoupper(str_repeat('a', 64))], ['seen' => [str_repeat('a', 64)]]] as $body) {
            $response = $this->cartApi('POST', '/api/v1/cart/acknowledge', $org, $token, $body)->assertStatus(422);

            $this->assertArrayHasKey('seen', $response->json('data'));
        }
    }

    // --------------------------------------------------------------- Stripe and the honeypot

    #[Test]
    public function stripe_failing_is_a_422_with_a_sentence_no_order_and_a_log_line_without_the_buyer(): void
    {
        [$org, $token] = $this->giftBasket();

        $this->app->instance(CartCheckoutService::class, new class(new StripeClient('sk_test_offline')) extends CartCheckoutService {
            protected function createCheckoutSession(array $params, string $connectedAccountId, string $idempotencyKey): array
            {
                throw new ApiConnectionException('Could not connect to Stripe for zaynab@example.org');
            }
        });

        $response = $this->checkout($org, $token)
            ->assertStatus(422)
            ->assertJsonPath('message', FormResponseCheckoutService::COULD_NOT_OPEN);

        $this->assertStringNotContainsString('zaynab', $response->getContent(), 'the detail is logged, never shown');
        $this->assertSame(0, Order::withoutMasjidScope()->count(), 'the order rolled back with the page that never opened');

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => $message === 'Stripe did not open a cart payment page.'
                && ($context['exception'] ?? null) === ApiConnectionException::class
                && ! str_contains(json_encode($context), 'zaynab'))
            ->once();
    }

    #[Test]
    public function a_filled_website_field_gets_a_fake_200_and_opens_nothing(): void
    {
        [$org, $token] = $this->giftBasket();

        $this->checkout($org, $token, $this->checkoutBody([], ['website' => 'https://spam.example']))
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.checkout_url', null)
            ->assertJsonPath('data.order_uuid', null);

        $this->assertSame(0, Order::withoutMasjidScope()->count());
        $this->assertSame([], $this->stripe->created);
    }

    // ------------------------------------------------------------- the payment-state read

    #[Test]
    public function the_status_read_says_the_payment_state_and_nothing_else(): void
    {
        $org = $this->org();
        $token = $this->startBasket($org);
        $this->addLine($org, $token, $this->twoTicketsBody($this->ticketForm($org)->id))->assertOk();
        $this->addLine($org, $token, $this->giftBody($this->fund($org)->id))->assertOk();
        $uuid = $this->checkout($org, $token)->assertOk()->json('data.order_uuid');
        $order = $this->orderOf($uuid);

        $read = fn () => $this->cartApi('GET', "/api/v1/cart-orders/{$uuid}", $org);

        // Pending: the page is open.
        $response = $read()->assertOk()->assertJsonPath('status', 'success');
        $this->assertSame(
            ['status' => 'pending', 'order_number' => $order->order_number, 'total_minor' => 8000, 'currency' => 'usd'],
            $response->json('data')
        );

        // No name, e-mail, phone, line or answer, however the order was made.
        $content = $response->getContent();
        foreach (['Zaynab', 'zaynab@', '010 0100', 'Festival', 'Zakat', 'attendee', 'acct_', 'lines', $uuid, 'buyer'] as $private) {
            $this->assertStringNotContainsString($private, $content, "the status read leaks '{$private}'");
        }

        // Paid: only the signed webhook moves it.
        $this->postWebhook($this->sessionEvent($order->fresh()))->assertOk();
        $read()->assertOk()->assertJsonPath('data.status', 'paid');
        $this->assertEqualsCanonicalizing(['status', 'order_number', 'total_minor', 'currency'], array_keys($read()->json('data')));

        // Expired.
        $order->forceFill(['status' => Order::STATUS_EXPIRED])->save();
        $read()->assertOk()->assertJsonPath('data.status', 'expired');
    }

    #[Test]
    public function the_status_read_is_one_404_for_an_unknown_foreign_or_offboarded_order(): void
    {
        $org = $this->org();
        $other = $this->org();
        $fund = $this->fund($org);
        $token = $this->startBasket($org);
        $this->addLine($org, $token, $this->giftBody($fund->id))->assertOk();
        $uuid = $this->checkout($org, $token)->assertOk()->json('data.order_uuid');

        $unknown = $this->cartApi('GET', '/api/v1/cart-orders/' . Str::uuid(), $org)->assertNotFound();
        $foreign = $this->cartApi('GET', "/api/v1/cart-orders/{$uuid}", $other)->assertNotFound();

        $this->assertSame($unknown->getContent(), $foreign->getContent());

        // A 400 with no organisation; a path that is not a uuid never reaches the controller.
        $this->cartApi('GET', "/api/v1/cart-orders/{$uuid}", null)->assertStatus(400);
        $this->cartApi('GET', '/api/v1/cart-orders/not-a-uuid', $org)->assertNotFound();

        $org->delete();
        $this->assertSame($unknown->getContent(), $this->cartApi('GET', "/api/v1/cart-orders/{$uuid}", $org)->assertNotFound()->getContent());
    }

    // ------------------------------------------------- what was typed reaches the records

    #[Test]
    public function what_the_buyer_typed_at_the_page_reaches_the_kitchen_and_the_donor_on_settlement(): void
    {
        $org = $this->org();
        $token = $this->startBasket($org);
        $this->addLine($org, $token, $this->dishBody($this->dish($org)->id, 2, '2026-10-06T14:00'))->assertOk();
        $this->addLine($org, $token, $this->giftBody($this->fund($org)->id))->assertOk();

        $uuid = $this->checkout($org, $token, $this->checkoutBody([
            'name' => 'Zaynab Buyer', 'email' => 'zaynab@example.org', 'phone' => '+1 555 010 0100',
        ]))->assertOk()->json('data.order_uuid');

        // The payment intent carries no payer: whatever the records hold came from the basket page.
        $this->postWebhook($this->intentEvent($this->orderOf($uuid)))->assertOk();

        $meal = MealOrder::withoutMasjidScope()->sole();
        $this->assertSame('Zaynab Buyer', $meal->customer_name, 'not "Online order 1A2B3C4D"');
        $this->assertSame('+1 555 010 0100', $meal->customer_phone);
        $this->assertSame('zaynab@example.org', $meal->customer_email);

        $donor = Contact::withoutMasjidScope()->where('masjid_id', $org->id)->where('email', 'zaynab@example.org')->first();
        $this->assertNotNull($donor);
        $this->assertSame('Zaynab', $donor->first_name);
        $this->assertSame((int) $donor->id, (int) Donation::withoutMasjidScope()->sole()->contact_id);

        $this->cartApi('GET', "/api/v1/cart-orders/{$uuid}", $org)->assertOk()->assertJsonPath('data.status', 'paid');
    }
}
