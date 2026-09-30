<?php

namespace Tests\Feature\Cart;

use App\Models\Contact;
use App\Models\Masjid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A basket goes with the person it belongs to.
 *
 * MemberAccountDeletionCoverageTest proves `carts` is CLASSIFIED as login
 * plumbing, but it cannot see what the database actually does on deletion. The
 * account deletion ends in `$contact->forceDelete()` and relies on the foreign
 * key to clear plumbing. Declared `nullOnDelete` — as this table first was — the
 * basket survived as an orphan: attendee names still in its items, a working
 * token, no owner, outliving the erasure it should have gone with. Only a test of
 * the behaviour catches that; a test of the list does not.
 */
class CartErasureTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrg(): Masjid
    {
        return Masjid::create([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@example.test',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'timezone' => 'America/New_York',
        ]);
    }

    private function basketFor(Masjid $org, ?int $contactId, string $attendee): int
    {
        $cartId = DB::table('carts')->insertGetId([
            'masjid_id' => $org->id,
            'contact_id' => $contactId,
            'token_hash' => hash('sha256', uniqid('', true)),
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('cart_items')->insert([
            'cart_id' => $cartId,
            'masjid_id' => $org->id,
            'buyable_type' => 'form',
            'buyable_id' => 1,
            'recorded_as' => 'order_only',
            'label' => 'Festival ticket',
            'quantity' => 1,
            'unit_amount_shown_minor' => 1500,
            'payload' => json_encode(['tickets' => [['attendeeName' => $attendee]]]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $cartId;
    }

    #[Test]
    public function erasing_a_person_removes_their_basket_and_the_names_in_it(): void
    {
        $org = $this->makeOrg();
        $contact = Contact::factory()->create(['masjid_id' => $org->id]);
        $cartId = $this->basketFor($org, $contact->id, 'Child Whose Name Must Go');

        $contact->forceDelete();

        $this->assertDatabaseMissing('carts', ['id' => $cartId]);
        $this->assertDatabaseMissing('cart_items', ['cart_id' => $cartId]);
        $this->assertSame(
            0,
            DB::table('cart_items')->where('payload', 'like', '%Child Whose Name Must Go%')->count(),
            'no attendee name may survive the erasure of the person who entered it',
        );
    }

    #[Test]
    public function erasing_one_person_leaves_everyone_elses_basket_alone(): void
    {
        $org = $this->makeOrg();
        $leaving = Contact::factory()->create(['masjid_id' => $org->id]);
        $staying = Contact::factory()->create(['masjid_id' => $org->id]);

        $this->basketFor($org, $leaving->id, 'Leaving');
        $kept = $this->basketFor($org, $staying->id, 'Staying');
        $guest = $this->basketFor($org, null, 'Guest');

        $leaving->forceDelete();

        $this->assertDatabaseHas('carts', ['id' => $kept]);
        // A guest basket belongs to nobody, so no erasure touches it. (No message
        // argument: assertDatabaseHas's third parameter is the CONNECTION name.)
        $this->assertDatabaseHas('carts', ['id' => $guest]);
    }
}
