<?php

namespace Tests\Feature\Cart;

use App\Models\Masjid;
use App\Models\MealMenu;
use App\Models\MealMenuItem;
use App\Models\MealOrder;
use App\Models\MealOrderItem;
use App\Services\Lunch\MealOrderCreator;
use App\Support\LunchOrderLines;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The write half of a meal order (universal cart, DECISIONS 2026-09-26).
 *
 * The two public doors gate and then write; the cart pays first and then writes.
 * So the write must record what it is handed even where a door would have
 * refused: a payment that landed after ordering closed, at a dish's cap, or for
 * a dish since pulled has already taken the money. Nothing here is a gate, and
 * nothing here is a price: the lines are the FROZEN ones the shopper paid.
 */
class MealOrderCreatorTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjid;

    protected function setUp(): void
    {
        parent::setUp();

        // Unbound, as /api/v1 and the webhook run: masjid_id is stamped by hand.
        app(TenantContext::class)->forgetTenant();

        $this->masjid = Masjid::create([
            'name' => 'Creator Test ' . uniqid(),
            'email' => 'office' . uniqid() . '@masjid.test',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'org_type' => 'masjid',
        ]);
    }

    private function creator(): MealOrderCreator
    {
        return app(MealOrderCreator::class);
    }

    private function dated(array $state = []): MealMenu
    {
        return MealMenu::factory()->forMasjid($this->masjid)->open()->create($state);
    }

    private function catalogue(array $state = []): MealMenu
    {
        return MealMenu::factory()->forMasjid($this->masjid)->catalogue()->open()->create($state);
    }

    private function dish(MealMenu $menu, array $state = []): MealMenuItem
    {
        return MealMenuItem::factory()->create(array_merge([
            'masjid_id' => $this->masjid->id,
            'meal_menu_id' => $menu->id,
            'name' => 'Baked Lamb',
            'price_minor' => 1200,
        ], $state));
    }

    /** Exactly the shape LunchOrderLines::price returns. */
    private function priced(MealMenu $menu, array $wanted, string $cap = LunchOrderLines::CAP_CLAMP): array
    {
        return LunchOrderLines::price($menu, $wanted, $cap);
    }

    private function customer(array $over = []): array
    {
        return array_merge([
            'name' => '  Amina Yusuf ',
            'phone' => ' 3365550101 ',
            'email' => 'amina@example.test',
            'notes' => 'No onions',
        ], $over);
    }

    #[Test]
    public function a_dated_online_order_is_recorded_with_its_extras_lines_and_number(): void
    {
        $menu = $this->dated(['collect_customer_email' => true]);
        $dish = $this->dish($menu);

        $order = $this->creator()->create(
            $menu,
            (int) $this->masjid->id,
            $this->priced($menu, [$dish->id => 2]),
            $this->customer(),
            MealOrder::METHOD_ONLINE,
            'https://mec.example.test',
            500,
            87,
        );

        $row = MealOrder::withoutMasjidScope()->findOrFail($order->id);

        $this->assertSame((int) $this->masjid->id, (int) $row->masjid_id);
        $this->assertEquals($menu->id, $row->meal_menu_id);
        $this->assertSame('001', $row->order_number);
        $this->assertSame('Amina Yusuf', $row->customer_name);
        $this->assertSame('3365550101', $row->customer_phone);
        $this->assertSame('amina@example.test', $row->customer_email);
        $this->assertSame('No onions', $row->customer_notes);
        $this->assertSame(MealOrder::METHOD_ONLINE, $row->payment_method);
        $this->assertSame(MealOrder::STATUS_PENDING, $row->status);
        $this->assertSame(MealOrder::PAYMENT_UNPAID, $row->payment_status);
        $this->assertSame($menu->currency, $row->currency);
        $this->assertSame(2400, $row->subtotal_minor);
        $this->assertSame(500, $row->donation_minor);
        $this->assertSame(87, $row->fee_covered_minor);
        $this->assertSame(2987, $row->total_minor);
        $this->assertNotNull($row->placed_at);
        $this->assertNotEmpty($row->uuid);
        $this->assertSame('https://mec.example.test', $row->site_origin);
        $this->assertNull($row->pickup_at);
        $this->assertNull($row->preferred_payment);

        $items = MealOrderItem::withoutMasjidScope()->where('meal_order_id', $row->id)->get();
        $this->assertCount(1, $items);
        $this->assertSame((int) $this->masjid->id, (int) $items[0]->masjid_id);
        $this->assertEquals($dish->id, $items[0]->meal_menu_item_id);
        $this->assertSame('Baked Lamb', $items[0]->item_name);
        $this->assertSame(1200, $items[0]->unit_price_minor);
        $this->assertSame(2, $items[0]->quantity);
        $this->assertSame(2400, $items[0]->line_total_minor);
    }

    #[Test]
    public function a_kitchen_order_carries_its_pickup_and_the_offline_method_promised(): void
    {
        $menu = $this->catalogue();
        $dish = $this->dish($menu);
        $pickup = now()->addDays(4)->startOfHour();

        $offline = $this->creator()->create(
            $menu, (int) $this->masjid->id, $this->priced($menu, [$dish->id => 1], LunchOrderLines::CAP_REFUSE),
            $this->customer(), MealOrder::METHOD_PICKUP, null,
            catalogue: ['pickup_at' => $pickup, 'preferred_payment' => 'zelle'],
        );
        $card = $this->creator()->create(
            $menu, (int) $this->masjid->id, $this->priced($menu, [$dish->id => 1], LunchOrderLines::CAP_REFUSE),
            $this->customer(), MealOrder::METHOD_ONLINE, 'https://mec.example.test',
            catalogue: ['pickup_at' => $pickup, 'preferred_payment' => null],
        );

        $offline = MealOrder::withoutMasjidScope()->findOrFail($offline->id);
        $card = MealOrder::withoutMasjidScope()->findOrFail($card->id);

        $this->assertTrue($pickup->equalTo($offline->pickup_at));
        $this->assertSame('zelle', $offline->preferred_payment);
        $this->assertSame(MealOrder::METHOD_PICKUP, $offline->payment_method);
        $this->assertNull($offline->site_origin);
        $this->assertSame(1200, $offline->total_minor);
        $this->assertSame(0, $offline->donation_minor);
        $this->assertSame(0, $offline->fee_covered_minor);

        $this->assertTrue($pickup->equalTo($card->pickup_at));
        $this->assertNull($card->preferred_payment);
        $this->assertSame(MealOrder::METHOD_ONLINE, $card->payment_method);
        $this->assertSame('001', $offline->order_number);
        $this->assertSame('002', $card->order_number);
    }

    #[Test]
    public function the_write_records_what_a_door_would_have_refused_because_the_money_is_already_taken(): void
    {
        // Closed, past its cutoff, the dish pulled, and over its cap: every gate a
        // door asks. A cart order is written after payment, so none may re-run.
        $menu = $this->dated(['status' => MealMenu::STATUS_CLOSED, 'ordering_closes_at' => now()->subDay()]);
        $dish = $this->dish($menu, ['max_quantity' => 3]);
        $priced = $this->priced($menu, [$dish->id => 3]);

        $dish->update(['is_available' => false, 'max_quantity' => 1, 'price_minor' => 9999]);
        $this->assertFalse($menu->fresh()->isOpenForOrders());

        $order = $this->creator()->create(
            $menu->fresh(), (int) $this->masjid->id, $priced, $this->customer(), MealOrder::METHOD_ONLINE, null,
        );

        $item = MealOrderItem::withoutMasjidScope()->where('meal_order_id', $order->id)->firstOrFail();

        // The FROZEN price and quantity the shopper paid, not today's.
        $this->assertSame(1200, $item->unit_price_minor);
        $this->assertSame(3, $item->quantity);
        $this->assertSame(3600, $order->fresh()->total_minor);
    }

    #[Test]
    public function a_draft_menu_and_a_catalogue_at_capacity_still_record_a_paid_order(): void
    {
        $draft = $this->catalogue(['status' => MealMenu::STATUS_DRAFT]);
        $dish = $this->dish($draft, ['max_quantity' => 2]);

        // 5 of a dish capped at 2: the door refuses (CAP_REFUSE); a frozen line is written as given.
        $line = ['meal_menu_item_id' => $dish->id, 'item_name' => 'Baked Lamb', 'unit_price_minor' => 1200, 'quantity' => 5, 'line_total_minor' => 6000];

        $order = $this->creator()->create(
            $draft, (int) $this->masjid->id, ['lines' => [$line], 'subtotal_minor' => 6000],
            $this->customer(), MealOrder::METHOD_ONLINE, 'https://mec.example.test',
            catalogue: ['pickup_at' => now()->addHours(2), 'preferred_payment' => null],
        );

        $this->assertSame(6000, $order->fresh()->total_minor);
        $this->assertSame(5, MealOrderItem::withoutMasjidScope()->where('meal_order_id', $order->id)->value('quantity'));
    }

    #[Test]
    public function it_never_settles_or_touches_stripe_or_claims_an_email(): void
    {
        $menu = $this->catalogue();
        $dish = $this->dish($menu);

        $order = $this->creator()->create(
            $menu, (int) $this->masjid->id, $this->priced($menu, [$dish->id => 1]),
            $this->customer(), MealOrder::METHOD_ONLINE, 'https://mec.example.test',
            catalogue: ['pickup_at' => now()->addDays(3), 'preferred_payment' => null],
        );

        $row = MealOrder::withoutMasjidScope()->findOrFail($order->id);

        $this->assertSame(MealOrder::PAYMENT_UNPAID, $row->payment_status);
        $this->assertNull($row->paid_at);
        $this->assertNull($row->paid_via);
        $this->assertNull($row->stripe_checkout_session_id);
        $this->assertNull($row->stripe_payment_intent_id);
        // The claim columns the cart's settlement will use are untouched, so nothing is double-emailed.
        $this->assertNull($row->confirmation_sent_at);
        $this->assertNull($row->office_notified_at);
        $this->assertNull($row->customer_confirmed_sent_at);
    }

    #[Test]
    public function the_cart_can_settle_what_it_wrote_with_mark_paid(): void
    {
        $dated = $this->dated();
        $datedOrder = $this->creator()->create(
            $dated, (int) $this->masjid->id, $this->priced($dated, [$this->dish($dated)->id => 1]),
            $this->customer(), MealOrder::METHOD_ONLINE, null,
        );

        $kitchen = $this->catalogue();
        $kitchenOrder = $this->creator()->create(
            $kitchen, (int) $this->masjid->id, $this->priced($kitchen, [$this->dish($kitchen)->id => 1]),
            $this->customer(), MealOrder::METHOD_ONLINE, null,
            catalogue: ['pickup_at' => now()->addDays(3), 'preferred_payment' => null],
        );

        MealOrder::withoutMasjidScope()->findOrFail($datedOrder->id)->markPaid('pi_dated');
        MealOrder::withoutMasjidScope()->findOrFail($kitchenOrder->id)->markPaid('pi_kitchen');

        $dated = MealOrder::withoutMasjidScope()->findOrFail($datedOrder->id);
        $kitchen = MealOrder::withoutMasjidScope()->findOrFail($kitchenOrder->id);

        $this->assertSame(MealOrder::PAYMENT_PAID, $dated->payment_status);
        $this->assertSame(MealOrder::STATUS_CONFIRMED, $dated->status);
        $this->assertSame('pi_dated', $dated->stripe_payment_intent_id);
        // The office, not the payment, confirms a kitchen order.
        $this->assertSame(MealOrder::PAYMENT_PAID, $kitchen->payment_status);
        $this->assertSame(MealOrder::STATUS_PENDING, $kitchen->status);
    }

    #[Test]
    public function order_numbers_follow_the_menu_and_a_rolled_back_order_gives_its_number_back(): void
    {
        $menu = $this->dated();
        $other = $this->dated();
        $dish = $this->dish($menu);
        $otherDish = $this->dish($other);

        $one = $this->creator()->create($menu, (int) $this->masjid->id, $this->priced($menu, [$dish->id => 1]), $this->customer(), MealOrder::METHOD_PICKUP, null);

        try {
            DB::transaction(function () use ($menu, $dish): void {
                $lost = $this->creator()->create($menu, (int) $this->masjid->id, $this->priced($menu, [$dish->id => 1]), $this->customer(), MealOrder::METHOD_PICKUP, null);
                $this->assertSame('002', $lost->order_number);

                throw new \RuntimeException('roll back');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $two = $this->creator()->create($menu, (int) $this->masjid->id, $this->priced($menu, [$dish->id => 1]), $this->customer(), MealOrder::METHOD_PICKUP, null);
        $elsewhere = $this->creator()->create($other, (int) $this->masjid->id, $this->priced($other, [$otherDish->id => 1]), $this->customer(), MealOrder::METHOD_PICKUP, null);

        $this->assertSame('001', $one->order_number);
        $this->assertSame('002', $two->order_number);
        $this->assertSame('001', $elsewhere->order_number);
        $this->assertSame(2, MealOrder::withoutMasjidScope()->where('meal_menu_id', $menu->id)->count());
    }

    #[Test]
    public function an_email_is_dropped_when_the_menu_does_not_collect_one(): void
    {
        $menu = $this->dated(['collect_customer_email' => false]);
        $dish = $this->dish($menu);

        $order = $this->creator()->create(
            $menu, (int) $this->masjid->id, $this->priced($menu, [$dish->id => 1]),
            $this->customer(['email' => 'crafted@example.test']), MealOrder::METHOD_PICKUP, null,
        );

        $this->assertNull(MealOrder::withoutMasjidScope()->findOrFail($order->id)->customer_email);
    }

    #[Test]
    public function it_refuses_arguments_no_honest_caller_produces_and_writes_nothing(): void
    {
        $menu = $this->dated();
        $dish = $this->dish($menu);
        $good = $this->priced($menu, [$dish->id => 1]);
        $stranger = Masjid::create([
            'name' => 'Stranger ' . uniqid(),
            'email' => 'stranger' . uniqid() . '@masjid.test',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '2 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'org_type' => 'masjid',
        ]);

        $cases = [
            'another org on this menu' => fn () => $this->creator()->create($menu, (int) $stranger->id, $good, $this->customer(), MealOrder::METHOD_PICKUP, null),
            'no lines' => fn () => $this->creator()->create($menu, (int) $this->masjid->id, ['lines' => [], 'subtotal_minor' => 0], $this->customer(), MealOrder::METHOD_PICKUP, null),
            'lines that do not add up' => fn () => $this->creator()->create($menu, (int) $this->masjid->id, ['lines' => $good['lines'], 'subtotal_minor' => 1], $this->customer(), MealOrder::METHOD_PICKUP, null),
            'unknown method' => fn () => $this->creator()->create($menu, (int) $this->masjid->id, $good, $this->customer(), 'barter', null),
            'negative donation' => fn () => $this->creator()->create($menu, (int) $this->masjid->id, $good, $this->customer(), MealOrder::METHOD_ONLINE, null, -1),
        ];

        foreach ($cases as $label => $attempt) {
            try {
                $attempt();
                $this->fail("Expected a refusal: {$label}");
            } catch (\InvalidArgumentException) {
                // expected
            }
        }

        $this->assertSame(0, MealOrder::withoutMasjidScope()->count());
        $this->assertSame(0, MealOrderItem::withoutMasjidScope()->count());
        $this->assertSame(0, (int) $menu->fresh()->order_number_sequence);
    }
}
