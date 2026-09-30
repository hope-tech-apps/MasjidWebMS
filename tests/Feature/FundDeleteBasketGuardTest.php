<?php

namespace Tests\Feature;

use App\Models\CartItem;
use App\Models\Fund;
use App\Models\Masjid;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * DELETE /api/admin/masjids/{masjid_id}/funds/{fund_id} while a basket line still needs
 * the fund. Funds are hard-deleted and settlement of a gift line throws once its fund is
 * gone, so a fund is refused (409, one sentence) while a donation line for it sits on a
 * PENDING order (its payment page can still be paid) or on a PAID order whose line has no
 * record yet (money taken, gift not written). Every other fund still deletes.
 */
class FundDeleteBasketGuardTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjid;
    private User $admin;
    private Fund $fund;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->masjid = Masjid::create([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'crm_enabled' => true,
        ]);

        $this->admin = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
        $this->masjid->user_id = $this->admin->id;
        $this->masjid->save();

        $this->fund = Fund::factory()->create(['masjid_id' => $this->masjid->id]);
    }

    /** One order with one line for $buyableId, built unbound with an explicit organisation. */
    private function basketLine(string $status, ?int $recordId, string $type = CartItem::TYPE_DONATION, ?int $buyableId = null): OrderItem
    {
        $order = Order::withoutMasjidScope()->create([
            'masjid_id' => $this->masjid->id,
            'uuid' => (string) Str::uuid(),
            'order_number' => strtoupper(Str::random(8)),
            'status' => $status,
            'total_minor' => 5000,
            'currency' => 'usd',
            'charge_account_id' => 'acct_' . uniqid(),
        ]);

        return OrderItem::withoutMasjidScope()->create([
            'order_id' => $order->id,
            'masjid_id' => $this->masjid->id,
            'buyable_type' => $type,
            'buyable_id' => $buyableId ?? $this->fund->id,
            'recorded_as' => 'donation',
            'label' => 'Zakat',
            'quantity' => 1,
            'unit_amount_minor' => 5000,
            'total_minor' => 5000,
            'currency' => 'usd',
            'record_type' => $recordId === null ? null : OrderItem::RECORD_DONATION,
            'record_id' => $recordId,
        ]);
    }

    private function destroy(): \Illuminate\Testing\TestResponse
    {
        Sanctum::actingAs($this->admin);

        return $this->deleteJson("/api/admin/masjids/{$this->masjid->id}/funds/{$this->fund->id}");
    }

    private function assertRefused(\Illuminate\Testing\TestResponse $response): void
    {
        $response->assertStatus(409)->assertJsonPath('status', 'failed');
        $this->assertStringContainsString('cannot be deleted', (string) $response->json('data'));
        $this->assertDatabaseHas('funds', ['id' => $this->fund->id]);
    }

    #[Test]
    public function a_fund_on_an_unpaid_basket_line_is_refused(): void
    {
        $this->basketLine(Order::STATUS_PENDING, null);

        $this->assertRefused($this->destroy());
    }

    #[Test]
    public function a_fund_on_a_paid_line_that_has_no_record_yet_is_refused(): void
    {
        $this->basketLine(Order::STATUS_PAID, null);

        $this->assertRefused($this->destroy());
    }

    #[Test]
    public function a_fund_with_no_line_that_needs_it_still_deletes(): void
    {
        // A paid line whose gift is written, an expired order, another fund's pending line and
        // a meal line that happens to carry the same number: none of them needs this fund row.
        $this->basketLine(Order::STATUS_PAID, 123);
        $this->basketLine(Order::STATUS_EXPIRED, null);
        $this->basketLine(Order::STATUS_PENDING, null, CartItem::TYPE_DONATION, $this->fund->id + 1000);
        $this->basketLine(Order::STATUS_PENDING, null, CartItem::TYPE_MEAL);

        $this->destroy()->assertOk();

        $this->assertDatabaseMissing('funds', ['id' => $this->fund->id]);
    }

    #[Test]
    public function a_fund_with_no_basket_at_all_still_deletes(): void
    {
        $this->destroy()->assertOk();

        $this->assertDatabaseMissing('funds', ['id' => $this->fund->id]);
    }
}
