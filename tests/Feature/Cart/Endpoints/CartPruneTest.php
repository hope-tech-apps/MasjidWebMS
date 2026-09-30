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

    private function orderFor(Cart $cart, string $status, ?Carbon $pageExpires, ?string $paymentIntent = null): Order
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
            'stripe_payment_intent_id' => $paymentIntent,
        ]);
    }

    /** An order's frozen lines, as checkout leaves them: attendee names in the payload. */
    private function withLines(Order $order, int $lines = 2): Order
    {
        for ($i = 1; $i <= $lines; $i++) {
            OrderItem::withoutMasjidScope()->create([
                'order_id' => $order->id,
                'masjid_id' => $order->masjid_id,
                'buyable_type' => CartItem::TYPE_FORM,
                'buyable_id' => 1,
                'recorded_as' => CartItem::RECORDED_AS_ORDER_ONLY,
                'label' => "Ticket {$i}",
                'quantity' => 1,
                'unit_amount_minor' => 2500,
                'total_minor' => 2500,
                'currency' => 'usd',
                'payload' => ['tickets' => [['attendeeName' => "Attendee {$i}"]]],
            ]);
        }

        return $order;
    }

    private function linesOf(Order $order): int
    {
        return OrderItem::withoutMasjidScope()->where('order_id', $order->id)->count();
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

    // ------------------------------------------------- unpaid orders: a page never completed

    #[Test]
    public function an_expired_order_more_than_a_week_past_its_page_goes_with_its_lines_and_no_other_order_does(): void
    {
        $cart = $this->basket(['expires_at' => now()->addDay()]);
        $other = $this->org();
        $otherCart = $this->cart($other);
        $otherCart->forceFill(['expires_at' => now()->addDay()])->save();

        $old = $this->withLines($this->orderFor($cart, Order::STATUS_EXPIRED, now()->subDays(8)));
        $oldElsewhere = $this->withLines($this->orderFor($otherCart, Order::STATUS_EXPIRED, now()->subDays(30)));
        $recent = $this->withLines($this->orderFor($cart, Order::STATUS_EXPIRED, now()->subDays(6)));
        $unaged = $this->withLines($this->orderFor($cart, Order::STATUS_EXPIRED, null));
        // A pending order whose page closed only 3 days ago is not this sweep's yet, a pending order with
        // a payment intent is kept for a month (exactly 30 days is not MORE than 30), and a paid one is a
        // sale: none is touched. (Older pending orders go: see the pending-order tests below.)
        $pending = $this->withLines($this->orderFor($cart, Order::STATUS_PENDING, now()->subDays(3)));
        $paying = $this->withLines($this->orderFor($cart, Order::STATUS_PENDING, now()->subDays(30), 'pi_prune_paying'));
        $paid = $this->withLines($this->orderFor($cart, Order::STATUS_PAID, now()->subDays(30)));

        $this->artisan('cart:prune')
            ->expectsOutputToContain('and 2 expired unpaid order(s)')
            ->assertExitCode(0);

        foreach ([$old, $oldElsewhere] as $gone) {
            $this->assertNull(Order::withoutMasjidScope()->find($gone->id), "order {$gone->id} was deleted");
            $this->assertSame(0, $this->linesOf($gone), "order {$gone->id}: its lines, and the attendee names in them, went with it");
        }

        foreach (['6 days old' => $recent, 'no closing time' => $unaged, 'pending, 3 days' => $pending, 'pending with a payment, 30 days' => $paying, 'paid' => $paid] as $why => $kept) {
            $this->assertNotNull(Order::withoutMasjidScope()->find($kept->id), "{$why}: the order stays");
            $this->assertSame(2, $this->linesOf($kept), "{$why}: and so do its lines");
        }

        $this->assertNotNull(Cart::withoutMasjidScope()->find($cart->id), 'a live basket is not this sweep\'s business');
    }

    #[Test]
    public function the_order_sweep_honours_a_dry_run_and_a_second_run_finds_nothing(): void
    {
        $cart = $this->basket(['expires_at' => now()->addDay()]);
        $old = $this->withLines($this->orderFor($cart, Order::STATUS_EXPIRED, now()->subDays(9)));

        $this->assertSame(0, Artisan::call('cart:prune', ['--dry-run' => true]));
        $output = Artisan::output(); // the buffer empties on read, so read it once
        $this->assertStringContainsString('Would prune 0 open basket(s)', $output);
        $this->assertStringContainsString('and 1 expired unpaid order(s)', $output);
        $this->assertNotNull(Order::withoutMasjidScope()->find($old->id), 'a dry run deletes nothing');
        $this->assertSame(2, $this->linesOf($old));

        $this->artisan('cart:prune')->expectsOutputToContain('and 1 expired unpaid order(s)')->assertExitCode(0);
        $this->artisan('cart:prune')->expectsOutputToContain('and 0 expired unpaid order(s)')->assertExitCode(0);

        $this->assertNull(Order::withoutMasjidScope()->find($old->id));
        $this->assertSame(0, $this->linesOf($old));
    }

    #[Test]
    public function an_expired_order_that_outlives_its_pruned_basket_is_swept_a_week_after_its_page_closed(): void
    {
        // The basket goes first (a day past its week), the order a week after ITS page closed:
        // the frozen copy of the shopper's details does not outlive the policy on the original.
        $cart = $this->basket(['expires_at' => now()->subDays(2)]);
        $order = $this->withLines($this->orderFor($cart, Order::STATUS_EXPIRED, now()->subDays(3)));

        $this->artisan('cart:prune')->assertExitCode(0);

        $this->assertNull(Cart::withoutMasjidScope()->find($cart->id));
        $this->assertNull($order->fresh()->cart_id, 'premise: the order outlived its basket');
        $this->assertSame(2, $this->linesOf($order));

        Carbon::setTestNow(now()->addDays(5));
        $this->artisan('cart:prune')->assertExitCode(0);

        $this->assertNull(Order::withoutMasjidScope()->find($order->id), 'eight days after its page closed');
        $this->assertSame(0, $this->linesOf($order));
    }

    #[Test]
    public function an_expired_order_with_a_payment_intent_waits_thirty_days_and_one_without_waits_a_week(): void
    {
        // A shopper who checks out again closes the earlier page (markExpired), and a delayed debit
        // from it can still be clearing: its payment intent was recorded early (B2), so an EXPIRED
        // order can carry one. It waits like a pending order with a payment does, not a week.
        $cart = $this->basket(['expires_at' => now()->addDay()]);

        $bareOld = $this->withLines($this->orderFor($cart, Order::STATUS_EXPIRED, now()->subDays(8)));
        $bareBoundary = $this->withLines($this->orderFor($cart, Order::STATUS_EXPIRED, now()->subDays(7)));
        $payingOld = $this->withLines($this->orderFor($cart, Order::STATUS_EXPIRED, now()->subDays(31), 'pi_prune_expired_old'));
        $payingBoundary = $this->withLines($this->orderFor($cart, Order::STATUS_EXPIRED, now()->subDays(30), 'pi_prune_expired_boundary'));
        $payingAWeek = $this->withLines($this->orderFor($cart, Order::STATUS_EXPIRED, now()->subDays(8), 'pi_prune_expired_week'));

        $this->artisan('cart:prune')
            ->expectsOutputToContain('and 1 expired unpaid order(s)')
            ->expectsOutputToContain('Pruned 1 expired order(s) with a payment intent')
            ->assertExitCode(0);

        foreach (['no intent, 8 days' => $bareOld, 'intent, 31 days' => $payingOld] as $why => $gone) {
            $this->assertNull(Order::withoutMasjidScope()->find($gone->id), "{$why}: gone");
            $this->assertSame(0, $this->linesOf($gone), "{$why}: with its lines");
        }

        foreach ([
            'no intent, exactly 7 days (not more than 7)' => $bareBoundary,
            'intent, exactly 30 days (not more than 30)' => $payingBoundary,
            'intent, 8 days: a debit may still be clearing' => $payingAWeek,
        ] as $why => $kept) {
            $this->assertNotNull(Order::withoutMasjidScope()->find($kept->id), "{$why}: kept");
            $this->assertSame(2, $this->linesOf($kept), "{$why}: and its lines");
        }

        // What went is on the WARNING, by number, intent and amount, like a pending order with one.
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []) => str_contains($message, 'carry a payment intent')
                && $context['dry_run'] === false
                && $context['listed'] === [[
                    'order_number' => $payingOld->order_number,
                    'masjid_id' => (int) $payingOld->masjid_id,
                    'payment_intent' => 'pi_prune_expired_old',
                    'total_minor' => 5000,
                    'currency' => 'usd',
                ]])
            ->once();

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context = []) => $message === 'Cart retention sweep completed.'
                && $context['orders'] === 1
                && $context['pending_orders_with_payment'] === 0
                && $context['expired_orders_with_payment'] === 1)
            ->once();
    }

    #[Test]
    public function a_dry_run_counts_expired_orders_with_a_payment_intent_and_deletes_none(): void
    {
        $cart = $this->basket(['expires_at' => now()->addDay()]);
        $paying = $this->withLines($this->orderFor($cart, Order::STATUS_EXPIRED, now()->subDays(45), 'pi_prune_expired_dry'));

        $this->assertSame(0, Artisan::call('cart:prune', ['--dry-run' => true]));
        $this->assertStringContainsString('Would prune 1 expired order(s) with a payment intent', Artisan::output());

        $this->assertNotNull(Order::withoutMasjidScope()->find($paying->id), 'a dry run deletes nothing');
        $this->assertSame(2, $this->linesOf($paying));

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []) => str_contains($message, 'would delete') && $context['dry_run'] === true && $context['orders'] === 1)
            ->once();
    }

    // ------------------------------------- pending orders: nothing ever moves them to `expired`
    //
    // Production's Connect endpoint does not subscribe to checkout.session.expired, so an order
    // that was abandoned stays `pending` for ever unless the sweep takes it.

    #[Test]
    public function a_pending_order_with_no_payment_goes_more_than_a_week_after_its_page_closed(): void
    {
        $cart = $this->basket(['expires_at' => now()->addDay()]);

        $old = $this->withLines($this->orderFor($cart, Order::STATUS_PENDING, now()->subDays(8)));
        $boundary = $this->withLines($this->orderFor($cart, Order::STATUS_PENDING, now()->subDays(7)));
        $recent = $this->withLines($this->orderFor($cart, Order::STATUS_PENDING, now()->subDays(6)));
        $open = $this->withLines($this->orderFor($cart, Order::STATUS_PENDING, now()->addMinutes(10)));
        $unaged = $this->withLines($this->orderFor($cart, Order::STATUS_PENDING, null));

        $this->artisan('cart:prune')
            ->expectsOutputToContain('Pruned 1 pending order(s) with no payment')
            ->assertExitCode(0);

        $this->assertNull(Order::withoutMasjidScope()->find($old->id), 'closed 8 days ago: gone');
        $this->assertSame(0, $this->linesOf($old), 'with its lines, and the attendee names in them');

        foreach (['exactly 7 days (not more than 7)' => $boundary, '6 days' => $recent, 'page still open' => $open, 'no closing time' => $unaged] as $why => $kept) {
            $this->assertNotNull(Order::withoutMasjidScope()->find($kept->id), "{$why}: kept");
            $this->assertSame(2, $this->linesOf($kept));
        }
    }

    #[Test]
    public function a_pending_order_with_a_payment_intent_goes_only_after_thirty_days_and_is_logged_by_number(): void
    {
        $cart = $this->basket(['expires_at' => now()->addDay()]);

        $old = $this->withLines($this->orderFor($cart, Order::STATUS_PENDING, now()->subDays(31), 'pi_prune_old'));
        $boundary = $this->withLines($this->orderFor($cart, Order::STATUS_PENDING, now()->subDays(30), 'pi_prune_boundary'));
        $middle = $this->withLines($this->orderFor($cart, Order::STATUS_PENDING, now()->subDays(8), 'pi_prune_middle'));
        $unaged = $this->withLines($this->orderFor($cart, Order::STATUS_PENDING, null, 'pi_prune_unaged'));

        $this->artisan('cart:prune')
            ->expectsOutputToContain('and 1 pending order(s) with a payment intent')
            ->assertExitCode(0);

        $this->assertNull(Order::withoutMasjidScope()->find($old->id), 'closed 31 days ago with a payment intent: gone');
        $this->assertSame(0, $this->linesOf($old));

        foreach (['exactly 30 days (not more than 30)' => $boundary, '8 days: a payment that may still be sorting itself out' => $middle, 'no closing time' => $unaged] as $why => $kept) {
            $this->assertNotNull(Order::withoutMasjidScope()->find($kept->id), "{$why}: kept");
            $this->assertSame(2, $this->linesOf($kept));
        }

        // The rows are gone, so this line is what staff reconcile from: the count, and each order's
        // number, payment intent and amount. No name, address or answer.
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []) => str_contains($message, 'carry a payment intent')
                && $context['orders'] === 1
                && $context['dry_run'] === false
                && $context['listed'] === [[
                    'order_number' => $old->order_number,
                    'masjid_id' => (int) $old->masjid_id,
                    'payment_intent' => 'pi_prune_old',
                    'total_minor' => 5000,
                    'currency' => 'usd',
                ]])
            ->once();
    }

    #[Test]
    public function every_deleted_order_with_an_intent_is_on_a_warning_however_many_there_are_and_none_carries_a_buyer(): void
    {
        // More than one chunk, and more than the 100 an earlier version of the list stopped at.
        $cart = $this->basket(['expires_at' => now()->addDay()]);
        $expected = [];

        for ($i = 1; $i <= 250; $i++) {
            $order = $this->orderFor(
                $cart,
                $i % 2 === 0 ? Order::STATUS_EXPIRED : Order::STATUS_PENDING,
                now()->subDays(40),
                "pi_prune_bulk_{$i}"
            );
            $order->forceFill([
                'buyer_name' => 'CANARY-BUYER-NAME',
                'buyer_email' => 'canary-buyer@example.test',
                'buyer_phone' => '5550199',
            ])->save();

            $expected[] = [
                'order_number' => $order->order_number,
                'masjid_id' => (int) $order->masjid_id,
                'payment_intent' => "pi_prune_bulk_{$i}",
                'total_minor' => 5000,
                'currency' => 'usd',
            ];
        }

        $this->artisan('cart:prune')
            ->expectsOutputToContain('and 125 pending order(s) with a payment intent')
            ->expectsOutputToContain('Pruned 125 expired order(s) with a payment intent')
            ->assertExitCode(0);

        $this->assertSame(0, Order::withoutMasjidScope()->count(), 'all 250 went');

        // One WARNING per chunk of 100, in id order, each listing every order that chunk deleted:
        // together, all 250, none left off.
        $chunks = array_chunk($expected, 100);
        $this->assertCount(3, $chunks);

        foreach ($chunks as $chunk) {
            Log::shouldHaveReceived('warning')
                ->withArgs(fn (string $message, array $context = []) => str_contains($message, 'carry a payment intent')
                    && $context['dry_run'] === false
                    && $context['orders'] === count($chunk)
                    && $context['listed'] === $chunk)
                ->once();
        }

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => str_contains($message, 'carry a payment intent'))
            ->times(3);

        // Numbers, intents and amounts: never a name, an address or a phone.
        Log::shouldNotHaveReceived('warning', fn ($message, $context = []) => str_contains(json_encode([$message, $context]), 'CANARY')
            || str_contains(json_encode([$message, $context]), '5550199'));
    }

    #[Test]
    public function a_paid_order_is_never_pruned_however_old_and_with_or_without_an_intent(): void
    {
        $cart = $this->basket(['expires_at' => now()->addDay()]);

        $paidWithIntent = $this->withLines($this->orderFor($cart, Order::STATUS_PAID, now()->subDays(400), 'pi_prune_paid'));
        $paidBare = $this->withLines($this->orderFor($cart, Order::STATUS_PAID, now()->subDays(400)));

        $this->artisan('cart:prune')
            ->expectsOutputToContain('Pruned 0 pending order(s) with no payment')
            ->expectsOutputToContain('and 0 pending order(s) with a payment intent')
            ->assertExitCode(0);

        foreach ([$paidWithIntent, $paidBare] as $kept) {
            $this->assertNotNull(Order::withoutMasjidScope()->find($kept->id));
            $this->assertSame(2, $this->linesOf($kept));
        }

        Log::shouldNotHaveReceived('warning', fn ($message) => str_contains((string) $message, 'carry a payment intent'));
    }

    #[Test]
    public function the_pending_with_payment_window_is_configurable_and_never_below_a_week(): void
    {
        $cart = $this->basket(['expires_at' => now()->addDay()]);
        $eleven = $this->orderFor($cart, Order::STATUS_PENDING, now()->subDays(11), 'pi_prune_eleven');
        $nine = $this->orderFor($cart, Order::STATUS_PENDING, now()->subDays(9), 'pi_prune_nine');

        config(['cart.prune.pending_with_payment_days' => 10]);
        $this->artisan('cart:prune')->expectsOutputToContain('and 1 pending order(s) with a payment intent')->assertExitCode(0);

        $this->assertNull(Order::withoutMasjidScope()->find($eleven->id));
        $this->assertNotNull(Order::withoutMasjidScope()->find($nine->id));

        // A typo (1 day) is read as the floor, a week: an order 5 days past is kept, 8 days past goes.
        $five = $this->orderFor($cart, Order::STATUS_PENDING, now()->subDays(5), 'pi_prune_five');
        $eight = $this->orderFor($cart, Order::STATUS_PENDING, now()->subDays(8), 'pi_prune_eight');
        $nine->delete();

        config(['cart.prune.pending_with_payment_days' => 1]);
        $this->artisan('cart:prune')->expectsOutputToContain('and 1 pending order(s) with a payment intent')->assertExitCode(0);

        $this->assertNotNull(Order::withoutMasjidScope()->find($five->id));
        $this->assertNull(Order::withoutMasjidScope()->find($eight->id));
    }

    #[Test]
    public function a_dry_run_counts_pending_orders_deletes_none_and_warns_that_it_would(): void
    {
        $cart = $this->basket(['expires_at' => now()->addDay()]);
        $bare = $this->withLines($this->orderFor($cart, Order::STATUS_PENDING, now()->subDays(9)));
        $paying = $this->withLines($this->orderFor($cart, Order::STATUS_PENDING, now()->subDays(40), 'pi_prune_dry'));

        $this->assertSame(0, Artisan::call('cart:prune', ['--dry-run' => true]));
        $output = Artisan::output(); // the buffer empties on read, so read it once
        $this->assertStringContainsString('Would prune 1 pending order(s) with no payment', $output);
        $this->assertStringContainsString('and 1 pending order(s) with a payment intent', $output);

        foreach ([$bare, $paying] as $kept) {
            $this->assertNotNull(Order::withoutMasjidScope()->find($kept->id), 'a dry run deletes nothing');
            $this->assertSame(2, $this->linesOf($kept));
        }

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []) => str_contains($message, 'would delete') && $context['dry_run'] === true && $context['orders'] === 1)
            ->once();

        // ...and the real run then takes both, once.
        $this->artisan('cart:prune')->assertExitCode(0);
        $this->assertNull(Order::withoutMasjidScope()->find($bare->id));
        $this->assertNull(Order::withoutMasjidScope()->find($paying->id));
        $this->artisan('cart:prune')->expectsOutputToContain('Pruned 0 pending order(s) with no payment')->assertExitCode(0);
    }

    #[Test]
    public function the_sweep_log_carries_the_pending_counts(): void
    {
        $cart = $this->basket(['expires_at' => now()->addDay()]);
        $this->orderFor($cart, Order::STATUS_PENDING, now()->subDays(9));
        $this->orderFor($cart, Order::STATUS_PENDING, now()->subDays(9));
        $this->orderFor($cart, Order::STATUS_PENDING, now()->subDays(40), 'pi_prune_log');

        $this->artisan('cart:prune')->assertExitCode(0);

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context = []) => $message === 'Cart retention sweep completed.'
                && $context['orders'] === 0
                && $context['pending_orders_without_payment'] === 2
                && $context['pending_orders_with_payment'] === 1)
            ->once();
    }

    // ------------------------------------------------------------- the trace it leaves

    #[Test]
    public function a_run_logs_its_counts_because_the_scheduler_throws_stdout_away(): void
    {
        $this->basket(['expires_at' => now()->subDays(2)]);
        $this->basket(['expires_at' => now()->subDays(3)]);
        $live = $this->basket(['expires_at' => now()->addDay()]);
        $this->orderFor($live, Order::STATUS_EXPIRED, now()->subDays(10));

        $this->artisan('cart:prune')->assertExitCode(0);

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context = []) => $message === 'Cart retention sweep completed.'
                && $context['dry_run'] === false
                && $context['baskets'] === 2
                && $context['orders'] === 1)
            ->once();
    }

    #[Test]
    public function a_night_that_finds_nothing_still_leaves_a_line_and_a_dry_run_says_so(): void
    {
        $this->artisan('cart:prune')->assertExitCode(0);

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context = []) => $message === 'Cart retention sweep completed.'
                && $context['dry_run'] === false
                && $context['baskets'] === 0
                && $context['orders'] === 0)
            ->once();

        $cart = $this->basket(['expires_at' => now()->subDays(2)]);
        $this->orderFor($cart, Order::STATUS_EXPIRED, now()->subDays(10));
        $this->artisan('cart:prune', ['--dry-run' => true])->assertExitCode(0);

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context = []) => $message === 'Cart retention sweep completed.'
                && $context['dry_run'] === true
                && $context['baskets'] === 1
                && $context['orders'] === 1)
            ->once();
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
