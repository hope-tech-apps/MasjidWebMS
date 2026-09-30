<?php

namespace Tests\Feature\Member;

use App\Models\Contact;
use App\Models\MealOrder;
use App\Models\Masjid;
use App\Models\Order;
use App\Models\User;
use App\Services\Member\MemberPurchases;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * An office merge keeps a member's baskets and lunches (slice 6, fix round 1, m1).
 *
 * `orders.contact_id` and `meal_orders.contact_id` are `nullOnDelete`, so the merge's
 * `forceDelete()` used to null both, and the member portal, which lists a cart order and a
 * lunch by `contact_id` beside the typed address, then hid every purchase whose typed
 * address was not the survivor's verified one. Gifts and Wix orders were moved by the merge
 * already and still showed.
 */
class MemberPurchasesSurviveAMergeTest extends TestCase
{
    use BuildsMemberPortal;
    use RefreshDatabase;

    private Masjid $a;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->a = $this->org();
        $this->admin = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
        $this->a->user_id = $this->admin->id;
        $this->a->save();
    }

    #[Test]
    public function merging_a_contact_carries_its_baskets_and_lunches_to_the_survivor_who_then_lists_them(): void
    {
        // The absorbed contact paid a basket and a lunch under a WORK address; the survivor
        // proved a personal one, so neither purchase is theirs by address.
        $source = Contact::factory()->create(['masjid_id' => $this->a->id, 'email' => 'work@example.test']);
        $survivor = $this->member($this->a, 'personal@example.test');
        $neighbour = $this->member($this->a, 'zaid@example.test');

        $basket = $this->cartOrder($this->a, ['contact_id' => $source->id, 'buyer_email' => 'work@example.test']);
        $lunch = $this->mealOrder($this->a, 'work@example.test', ['contact_id' => $source->id]);
        $neighboursBasket = $this->cartOrder($this->a, ['contact_id' => $neighbour->id, 'buyer_email' => 'zaid@example.test']);

        $purchases = app(MemberPurchases::class);
        $this->assertSame(0, $purchases->orderPage($survivor, 15)->total(), 'premise: before the merge the survivor sees neither');

        Sanctum::actingAs($this->admin);

        $this->postJson("/api/admin/masjids/{$this->a->id}/contacts/{$source->id}/merge", [
            'target_contact_id' => $survivor->id,
        ])->assertOk();

        $this->unbound();

        $this->assertNull(Contact::withoutMasjidScope()->withTrashed()->find($source->id), 'the absorbed contact is gone');
        $this->assertSame($survivor->id, Order::withoutMasjidScope()->findOrFail($basket->id)->contact_id, 'the basket follows its buyer');
        $this->assertSame($survivor->id, MealOrder::withoutMasjidScope()->findOrFail($lunch->id)->contact_id, 'the lunch follows its buyer');
        $this->assertSame($neighbour->id, Order::withoutMasjidScope()->findOrFail($neighboursBasket->id)->contact_id, 'nobody else\'s moved');

        $survivor = $survivor->refresh();

        $this->assertSame([$basket->id], array_map('intval', $purchases->cartOrders($survivor)->pluck('id')->all()));
        $this->assertSame([$lunch->id], array_map('intval', $purchases->mealPurchases($survivor)->pluck('id')->all()));
        $this->assertSame(2, $purchases->orderPage($survivor, 15)->total());
    }
}
