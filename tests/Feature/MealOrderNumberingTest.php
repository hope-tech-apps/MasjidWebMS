<?php

namespace Tests\Feature;

use App\Models\Masjid;
use App\Models\MealMenu;
use App\Models\MealMenuItem;
use App\Models\MealOrder;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Order numbers on a menu whose rows have MOVED.
 *
 * This is written from a live failure, not from a hypothetical. On 2026-09-18
 * Burlington's open menu held orders 001-024. Two orders from one customer were
 * combined into one at her request, which removed a row. The number was handed
 * out by COUNTING the rows and adding one, so the next order asked for "024" —
 * a number already on the board — and the unique index refused it. Every order
 * after that, staff-entered and public alike, died with a database error, and it
 * could never recover on its own: the count was permanently one behind the
 * highest number.
 *
 * So the rule is: the next number follows the HIGHEST number on the menu. A
 * removed order leaves a gap, and a gap is fine. The thing that must never
 * happen is two orders answering to one number, because the number is what the
 * kitchen and the customer use to identify the food.
 */
class MealOrderNumberingTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjid;

    private MealMenu $menu;

    private MealMenuItem $plate;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->forgetTenant();

        $this->masjid = $this->makeMasjid();
        $this->menu = MealMenu::factory()->forMasjid($this->masjid)->open()->create();
        $this->plate = MealMenuItem::factory()->create([
            'masjid_id' => $this->masjid->id,
            'meal_menu_id' => $this->menu->id,
            'name' => 'Lasagna',
            'price_minor' => 800,
        ]);
    }

    private function makeMasjid(): Masjid
    {
        return Masjid::create([
            'name' => 'Numbering Test ' . uniqid(),
            'email' => 'office' . uniqid() . '@masjid.test',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'org_type' => 'masjid',
        ]);
    }

    private function placeOrder(): MealOrder
    {
        $order = new MealOrder;
        $order->masjid_id = $this->masjid->id;
        $order->meal_menu_id = $this->menu->id;
        $order->order_number = MealOrder::nextOrderNumber((int) $this->masjid->id, (int) $this->menu->id);
        $order->customer_name = 'Someone';
        $order->customer_phone = '3365550000';
        $order->status = MealOrder::STATUS_PENDING;
        $order->payment_method = MealOrder::METHOD_PICKUP;
        $order->payment_status = MealOrder::PAYMENT_UNPAID;
        $order->subtotal_minor = 800;
        $order->total_minor = 800;
        $order->save();

        return $order;
    }

    #[Test]
    public function numbers_run_in_sequence_while_nothing_is_removed(): void
    {
        $this->assertSame('001', $this->placeOrder()->order_number);
        $this->assertSame('002', $this->placeOrder()->order_number);
        $this->assertSame('003', $this->placeOrder()->order_number);
    }

    #[Test]
    public function an_order_removed_from_the_middle_does_not_make_the_next_one_reuse_a_number(): void
    {
        $first = $this->placeOrder();   // 001
        $second = $this->placeOrder();  // 002
        $third = $this->placeOrder();   // 003

        // What happened live: two of the customer's orders were combined, so one row went.
        $second->delete();

        $next = $this->placeOrder();

        $this->assertSame('004', $next->order_number, 'the next number must follow the highest, not the count');
        $this->assertSame(
            ['001', '003', '004'],
            MealOrder::withoutMasjidScope()->where('meal_menu_id', $this->menu->id)
                ->orderBy('order_number')->pluck('order_number')->all(),
            'a removed order leaves a gap; no number is ever handed out twice'
        );
        $this->assertSame('001', $first->fresh()->order_number);
        $this->assertSame('003', $third->fresh()->order_number);
    }

    #[Test]
    public function the_last_order_being_removed_also_leaves_its_number_spent(): void
    {
        $this->placeOrder();                 // 001
        $this->placeOrder()->delete();       // 002, gone

        $this->assertSame('003', $this->placeOrder()->order_number);
    }

    #[Test]
    public function orders_on_another_menu_or_another_masjid_do_not_move_this_menu_s_numbering(): void
    {
        $this->placeOrder();   // 001 here

        $otherMenu = MealMenu::factory()->forMasjid($this->masjid)->open()->create([
            'service_date' => $this->menu->service_date->copy()->addWeek(),
        ]);
        $otherMasjid = $this->makeMasjid();
        $foreignMenu = MealMenu::factory()->forMasjid($otherMasjid)->open()->create();

        $this->assertSame('001', MealOrder::nextOrderNumber((int) $this->masjid->id, (int) $otherMenu->id));
        $this->assertSame('001', MealOrder::nextOrderNumber((int) $otherMasjid->id, (int) $foreignMenu->id));
        $this->assertSame('002', MealOrder::nextOrderNumber((int) $this->masjid->id, (int) $this->menu->id));
    }

    #[Test]
    public function a_number_that_is_not_a_plain_figure_is_ignored_rather_than_refusing_the_order(): void
    {
        // Nothing writes one today; this pins that a stray value cannot stop a menu.
        $order = $this->placeOrder();   // 001, and the menu has now issued 1
        $order->order_number = 'X1';
        $order->save();

        $this->assertSame('002', MealOrder::nextOrderNumber((int) $this->masjid->id, (int) $this->menu->id));
    }

    #[Test]
    public function the_menu_remembers_what_it_issued_even_when_every_order_is_removed(): void
    {
        $this->placeOrder();                        // 001
        $this->placeOrder();                        // 002
        MealOrder::withoutMasjidScope()->where('meal_menu_id', $this->menu->id)->delete();

        $this->assertSame('003', $this->placeOrder()->order_number,
            'an emptied menu must not start again at 001 and reuse numbers people were given');
    }

    #[Test]
    public function an_order_written_outside_this_method_is_not_shadowed_by_the_counter(): void
    {
        // A row inserted by hand (a repair, an import) carries a number the
        // counter never saw. The next order must clear BOTH.
        $order = $this->placeOrder();               // 001
        $order->order_number = '042';
        $order->save();

        $this->assertSame('043', MealOrder::nextOrderNumber((int) $this->masjid->id, (int) $this->menu->id));
    }
}
