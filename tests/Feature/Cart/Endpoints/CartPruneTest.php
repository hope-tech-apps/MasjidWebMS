<?php

namespace Tests\Feature\Cart\Endpoints;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Donation;
use App\Models\FormResponse;
use App\Models\Fund;
use App\Models\Masjid;
use App\Models\MealOrder;
use App\Models\Order;
use App\Models\OrderItem;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsBaskets;
use Tests\Feature\Cart\SignsCartWebhooks;
use Tests\TestCase;

/**
 * `cart:prune`, and what a pruned basket costs a payment that is still on its way (brief 5,
 * section 5).
 *
 * A basket holds answers (attendee names) and a token nobody will present once its holder is
 * gone, so open baskets whose expiry is more than a day past are deleted, lines and all. Two
 * things must hold for that to be safe: a page that could still be paid keeps its basket, and
 * a payment that lands on an order whose basket was pruned anyway (the page lapses as it is
 * paid, or Stripe delivers a webhook late) is still settled and records every line, because
 * settlement writes from the order's own frozen snapshot and never from the basket.
 */
class CartPruneTest extends TestCase
{
    use BuildsBaskets;
    use CallsCartApi;
    use RefreshDatabase;
    use SignsCartWebhooks;

    private ?Masjid $home = null;

    private ?Fund $homeFund = null;

    protected function setUp(): void
    {
        parent::setUp();

        // 2026-10-01 12:00 UTC: a catalogue pickup on the 6th is well inside its 48 hour lead,
        // whenever the suite is run.
        Carbon::setTestNow(Carbon::parse('2026-10-01 12:00:00', 'UTC'));

        $this->armWebhooks();
        $this->turnCartOn();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** A basket at the test's one organisation with a $50 gift in it, its state set as given. */
    private function basket(array $state): Cart
    {
        $this->home ??= $this->org();
        $this->homeFund ??= $this->fund($this->home);

        $cart = $this->cart($this->home);
        $cart->forceFill($state)->save();
        $this->add($cart, CartItem::TYPE_DONATION, $this->homeFund->id, 5000);

        return $cart;
    }

    private function orderFor(Cart $cart, string $status, ?Carbon $pageExpires): Order
    {
        return Order::withoutMasjidScope()->create([
            'masjid_id' => $cart->masjid_id,
            'uuid' => (string) Str::uuid(),
            'order_number' => strtoupper(Str::random(8)),
            'cart_id' => $cart->id,
            'status' => $status,
            'total_minor' => 5000,
            'currency' => 'usd',
            'charge_account_id' => 'acct_prune_test',
            'checkout_expires_at' => $pageExpires,
        ]);
    }

    // -------------------------------------------------------------- which baskets go

    #[Test]
    public function only_open_baskets_expired_more_than_a_day_ago_are_pruned_and_their_lines_go_with_them(): void
    {
        $abandoned = $this->basket(['expires_at' => now()->subDays(2)]);
        $withinGrace = $this->basket(['expires_at' => now()->subHours(23)]);
        $live = $this->basket(['expires_at' => now()->addDay()]);
        $paid = $this->basket(['expires_at' => now()->subDays(5), 'status' => Cart::STATUS_CHECKED_OUT]);
        $noExpiry = $this->basket(['expires_at' => null]);

        $this->assertSame(5, CartItem::withoutMasjidScope()->count());

        $this->artisan('cart:prune')->expectsOutputToContain('Pruned 1 open basket(s)')->assertExitCode(0);

        $this->assertNull(Cart::withoutMasjidScope()->find($abandoned->id));
        $this->assertSame(0, CartItem::withoutMasjidScope()->where('cart_id', $abandoned->id)->count(), 'its answers went with it');

        foreach ([$withinGrace, $live, $paid, $noExpiry] as $kept) {
            $this->assertNotNull(Cart::withoutMasjidScope()->find($kept->id), "basket {$kept->id} was kept");
            $this->assertSame(1, CartItem::withoutMasjidScope()->where('cart_id', $kept->id)->count());
        }
    }

    #[Test]
    public function a_page_that_could_still_be_paid_keeps_its_basket(): void
    {
        $lapsed = now()->subDays(3);

        $carts = [
            'pending, page still open' => [$this->basket(['expires_at' => $lapsed]), Order::STATUS_PENDING, now()->addMinutes(10), true],
            'pending, page lapsed 30 minutes ago' => [$this->basket(['expires_at' => $lapsed]), Order::STATUS_PENDING, now()->subMinutes(30), true],
            'pending, page lapsed 2 hours ago' => [$this->basket(['expires_at' => $lapsed]), Order::STATUS_PENDING, now()->subHours(2), false],
            'expired, however recent' => [$this->basket(['expires_at' => $lapsed]), Order::STATUS_EXPIRED, now()->subMinutes(5), false],
            'paid' => [$this->basket(['expires_at' => $lapsed]), Order::STATUS_PAID, now()->subMinutes(5), false],
        ];

        $orders = [];
        foreach ($carts as $why => [$cart, $status, $pageExpires]) {
            $orders[$why] = $this->orderFor($cart, $status, $pageExpires);
        }

        $this->artisan('cart:prune')->assertExitCode(0);

        foreach ($carts as $why => [$cart, , , $kept]) {
            $this->assertSame(
                $kept,
                Cart::withoutMasjidScope()->find($cart->id) !== null,
                "{$why}: " . ($kept ? 'the basket is kept' : 'the basket is pruned')
            );

            // The order is the sale and outlives its basket either way.
            $order = $orders[$why]->fresh();
            $this->assertNotNull($order, "{$why}: the order survives");
            $this->assertSame($kept ? (int) $cart->id : null, $order->cart_id === null ? null : (int) $order->cart_id, $why);
        }
    }

    #[Test]
    public function a_dry_run_deletes_nothing_and_a_second_run_finds_nothing(): void
    {
        $abandoned = $this->basket(['expires_at' => now()->subDays(2)]);

        $this->artisan('cart:prune', ['--dry-run' => true])->expectsOutputToContain('Would prune 1 open basket(s)')->assertExitCode(0);
        $this->assertNotNull(Cart::withoutMasjidScope()->find($abandoned->id));
        $this->assertSame(1, CartItem::withoutMasjidScope()->count());

        $this->artisan('cart:prune')->expectsOutputToContain('Pruned 1 open basket(s)')->assertExitCode(0);
        $this->artisan('cart:prune')->expectsOutputToContain('Pruned 0 open basket(s)')->assertExitCode(0);
        $this->assertNull(Cart::withoutMasjidScope()->find($abandoned->id));
    }

    #[Test]
    public function it_sweeps_every_organisation_not_just_one(): void
    {
        $one = $this->basket(['expires_at' => now()->subDays(2)]);

        $other = $this->org();
        $two = $this->cart($other);
        $two->forceFill(['expires_at' => now()->subDays(2)])->save();
        $this->add($two, CartItem::TYPE_DONATION, $this->fund($other)->id, 5000);

        $this->artisan('cart:prune')->expectsOutputToContain('Pruned 2 open basket(s)')->assertExitCode(0);

        $this->assertNull(Cart::withoutMasjidScope()->find($one->id));
        $this->assertNull(Cart::withoutMasjidScope()->find($two->id));
    }

    #[Test]
    public function it_is_on_the_schedule_daily(): void
    {
        Artisan::call('list', ['--raw' => true]);

        $events = array_values(array_filter(
            app(Schedule::class)->events(),
            static fn ($event): bool => str_contains((string) $event->command, 'cart:prune')
        ));

        $this->assertCount(1, $events, 'cart:prune is scheduled, once');
        $this->assertSame('41 3 * * *', $events[0]->expression, 'daily at 03:41 UTC');
        $this->assertTrue($events[0]->withoutOverlapping);
    }

    // ------------------------------------------------- a late payment for a pruned basket

    #[Test]
    public function a_late_webhook_for_an_order_whose_basket_was_pruned_still_settles_and_records_every_line(): void
    {
        $org = $this->org();
        $token = $this->startBasket($org);
        $this->addLine($org, $token, $this->twoTicketsBody($this->ticketForm($org)->id))->assertOk();
        $this->addLine($org, $token, $this->dishBody($this->dish($org)->id, 2, '2026-10-06T14:00'))->assertOk();
        $this->addLine($org, $token, $this->giftBody($this->fund($org)->id))->assertOk();
        $uuid = $this->cartApi('POST', '/api/v1/cart/checkout', $org, $token, $this->checkoutBody(), self::ORIGIN)
            ->assertOk()->json('data.order_uuid');

        $order = Order::withoutMasjidScope()->where('uuid', $uuid)->firstOrFail();
        $cart = $this->basketOf($token);
        $this->assertSame(10400, (int) $order->total_minor);
        $this->assertSame(3, CartItem::withoutMasjidScope()->where('cart_id', $cart->id)->count());

        // The shopper walks away; the basket lapses and is swept; the page's window closed hours ago.
        $cart->forceFill(['expires_at' => now()->subDays(3)])->save();
        $order->forceFill(['checkout_expires_at' => now()->subHours(5)])->save();

        $this->artisan('cart:prune')->expectsOutputToContain('Pruned 1 open basket(s)')->assertExitCode(0);

        // The basket, its lines and the answers in them are gone; the sale is not.
        $this->assertNull(Cart::withoutMasjidScope()->find($cart->id));
        $this->assertSame(0, CartItem::withoutMasjidScope()->count());

        $order = $order->fresh();
        $this->assertNull($order->cart_id, 'orders.cart_id is nullOnDelete: the order outlives its basket');
        $this->assertSame(Order::STATUS_PENDING, $order->status);
        $this->assertSame(3, OrderItem::withoutMasjidScope()->where('order_id', $order->id)->count(), 'and so do its frozen lines');

        // THEN the payment lands (Stripe delivers late, or the page lapsed as it was paid).
        $this->postWebhook($this->sessionEvent($order))->assertOk();

        $order = $order->fresh();
        $this->assertTrue($order->isPaid(), 'the payment is recorded against the surviving order');
        $this->assertNull($order->cart_id);

        // Every line was written from its own snapshot, once, and linked.
        $lines = OrderItem::withoutMasjidScope()->where('order_id', $order->id)->orderBy('id')->get();
        $this->assertCount(3, $lines);
        foreach ($lines as $line) {
            $this->assertNotNull($line->record_type, "line {$line->id} ({$line->buyable_type}) was recorded");
            $this->assertNotNull($line->record_id);
        }

        $ticket = FormResponse::query()->sole();
        $this->assertSame(FormResponse::PAYMENT_PAID, $ticket->payment_status);
        $this->assertSame(2, (int) $ticket->entry_count, 'both attendees, from the order\'s own snapshot');

        $meal = MealOrder::withoutMasjidScope()->sole();
        $this->assertSame(MealOrder::PAYMENT_PAID, $meal->payment_status);
        $this->assertSame('Zaynab Buyer', $meal->customer_name);

        $this->assertSame('succeeded', Donation::withoutMasjidScope()->sole()->status);

        Log::shouldNotHaveReceived('error', fn ($message) => str_contains((string) $message, 'could not be recorded'));

        $this->cartApi('GET', "/api/v1/cart-orders/{$uuid}", $org)->assertOk()->assertJsonPath('data.status', 'paid');
    }
}
