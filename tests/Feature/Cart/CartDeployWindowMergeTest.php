<?php

namespace Tests\Feature\Cart;

use App\Models\Contact;
use App\Models\Donation;
use App\Models\HistoricalOrder;
use App\Models\MealOrder;
use App\Models\Masjid;
use App\Models\Order;
use App\Models\User;
use App\Support\CartTables;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Feature\Member\BuildsMemberPortal;
use Tests\TestCase;

/**
 * An admin's contact merge, in the window between "the new code is live" and "migrate has run"
 * (pre-merge fix round 3, item 1), and when the table check cannot be answered at all.
 *
 * `ContactsController::merge` moves the absorbed contact's cart orders onto the survivor before it
 * force-deletes the absorbed row (`orders.contact_id` is `nullOnDelete`). bin/deploy makes that code
 * live BEFORE `migrate`, so for a while there is no `orders` table, and the unguarded move answered
 * 500 to every merge the office attempted. It now asks `CartTables::existsOrFail('orders')` first.
 *
 * The strict question and not the fail-safe one, because this path MOVES data: a check that threw and
 * read as "absent" would skip the move, and the force-delete would null every paid order the absorbed
 * contact held. Here the check throws, and the merge must fail whole (the transaction rolls back).
 */
class CartDeployWindowMergeTest extends TestCase
{
    use BuildsMemberPortal;
    use RefreshDatabase;

    private Masjid $a;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // The memo is per process, and a test that drops tables or breaks the check must not
        // inherit or leave one.
        CartTables::forget();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->a = $this->org();
        $this->admin = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
        $this->a->user_id = $this->admin->id;
        $this->a->save();
    }

    protected function tearDown(): void
    {
        CartTables::forget();

        parent::tearDown();
    }

    /** What migrate has not done yet. SQLite rolls the DDL back with the test's own transaction. */
    private function dropTheCartTables(): void
    {
        foreach (CartTables::NAMES as $table) {
            Schema::dropIfExists($table);
        }

        CartTables::forget();

        foreach (CartTables::NAMES as $table) {
            $this->assertFalse(Schema::hasTable($table), "premise: {$table} does not exist");
        }
    }

    /** The database cannot answer "does `orders` exist?": the SQL Laravel 12.64's SQLite grammar writes for `hasTable`. */
    private function breakTheOrdersCheck(): void
    {
        DB::listen(function ($query): void {
            if (str_contains((string) $query->sql, 'sqlite_master') && str_contains((string) $query->sql, "name = 'orders'")) {
                throw new RuntimeException('the schema is unavailable');
            }
        });
    }

    private function merge(Contact $source, Contact $survivor): TestResponse
    {
        Sanctum::actingAs($this->admin);

        return $this->postJson("/api/admin/masjids/{$this->a->id}/contacts/{$source->id}/merge", [
            'target_contact_id' => $survivor->id,
        ]);
    }

    #[Test]
    public function a_merge_in_the_window_succeeds_and_moves_everything_that_is_not_a_cart_order(): void
    {
        $source = Contact::factory()->create(['masjid_id' => $this->a->id, 'email' => 'work@example.test']);
        $survivor = $this->member($this->a, 'personal@example.test');

        $gift = $this->gift($this->a, $this->fund($this->a), $source);
        $lunch = $this->mealOrder($this->a, 'work@example.test', ['contact_id' => $source->id]);
        $wix = $this->wixOrder($this->a, $source);

        $this->dropTheCartTables();

        // Without the guard the move of `orders.contact_id` answered 500.
        $this->merge($source, $survivor)->assertOk();

        $this->unbound();

        $this->assertNull(Contact::withoutMasjidScope()->withTrashed()->find($source->id), 'the absorbed contact is gone');
        $this->assertSame($survivor->id, Donation::withoutMasjidScope()->findOrFail($gift->id)->contact_id, 'the gift followed');
        $this->assertSame($survivor->id, MealOrder::withoutMasjidScope()->findOrFail($lunch->id)->contact_id, 'meal_orders is an old table and moves unconditionally');
        $this->assertSame($survivor->id, HistoricalOrder::withoutMasjidScope()->findOrFail($wix->id)->contact_id, 'the imported Wix order followed');
    }

    #[Test]
    public function a_merge_whose_orders_check_cannot_be_answered_fails_whole_and_orphans_no_paid_order(): void
    {
        $source = Contact::factory()->create(['masjid_id' => $this->a->id, 'email' => 'work@example.test']);
        $survivor = $this->member($this->a, 'personal@example.test');

        $gift = $this->gift($this->a, $this->fund($this->a), $source);
        $lunch = $this->mealOrder($this->a, 'work@example.test', ['contact_id' => $source->id]);
        $paid = $this->cartOrder($this->a, ['contact_id' => $source->id, 'buyer_email' => 'work@example.test']);

        $this->breakTheOrdersCheck();

        // A fail-safe "absent" would skip the orders move, answer 200, and the force-delete would null the paid order.
        $this->merge($source, $survivor)->assertStatus(500);

        $this->unbound();

        $this->assertNotNull(Contact::withoutMasjidScope()->withTrashed()->find($source->id), 'the absorbed contact was not deleted');
        $this->assertSame($source->id, Order::withoutMasjidScope()->findOrFail($paid->id)->contact_id, 'the paid order is neither moved nor nulled');
        $this->assertSame($source->id, Donation::withoutMasjidScope()->findOrFail($gift->id)->contact_id, 'the moves made before the failure were rolled back');
        $this->assertSame($source->id, MealOrder::withoutMasjidScope()->findOrFail($lunch->id)->contact_id);
        $this->assertSame([], Donation::withoutMasjidScope()->where('contact_id', $survivor->id)->pluck('id')->all(), 'the survivor took nothing');
    }
}
