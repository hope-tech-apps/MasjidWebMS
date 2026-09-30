<?php

namespace Tests\Feature\Cart;

use App\Models\CartItem;
use App\Models\Contact;
use App\Models\Order;
use App\Services\Member\MemberAccountDeletion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Baskets and orders under a member's account deletion (checkout review, 2026-09-28).
 *
 * The review found two ways the cart broke erasure: ANY order — even a checkout
 * opened and never paid — counted as a sale and kept the contact, so a member who
 * once pressed "pay" and walked away could never be erased; and on the KEPT path the
 * contact is never force-deleted, so the basket cascade never fired and the unpaid
 * basket, attendee names in it, outlived the request.
 */
class CartOrderDeletionTest extends TestCase
{
    use BuildsBaskets;
    use RefreshDatabase;

    /**
     * A member the office holds nothing about. ContactFactory fills `phone` and `notes`,
     * and both are MemberAccountDeletion::OFFICE_COLUMNS — so a plain factory contact
     * is ALWAYS kept, and a test built on one would pass because of its phone number,
     * not because of the order under test.
     */
    private function member(): Contact
    {
        // Erasable only when app sign-up created it (signup_source = 'app') AND the
        // office holds nothing: no phone or notes, and an email that is the member's
        // own login address rather than one the office typed (reasonsToKeep()).
        // forceFill, not create(): an unfillable column is dropped by create() without
        // a word, and the premise assertions below would then fail for that reason.
        $address = 'member-' . uniqid() . '@example.org';
        $contact = Contact::factory()->create(['masjid_id' => $this->org()->id]);
        $contact->forceFill([
            'phone' => null,
            'notes' => null,
            'signup_source' => 'app',
            'email' => $address,
            'login_email' => $address,
        ])->save();

        return $contact->refresh();
    }

    private function orderFor(Contact $contact, string $status, ?string $email = 'buyer@example.org'): Order
    {
        return Order::withoutMasjidScope()->create([
            'masjid_id' => $contact->masjid_id,
            'uuid' => (string) Str::uuid(),
            'order_number' => strtoupper(Str::random(8)),
            'contact_id' => $contact->id,
            'buyer_email' => $email,
            'status' => $status,
            'total_minor' => 5000,
            'currency' => 'usd',
            'charge_account_id' => 'acct_' . uniqid(),
        ]);
    }

    #[Test]
    public function an_abandoned_checkout_does_not_keep_the_contact(): void
    {
        $contact = $this->member();
        $this->orderFor($contact, Order::STATUS_PENDING);
        $this->orderFor($contact, Order::STATUS_EXPIRED);

        $reasons = app(MemberAccountDeletion::class)->reasonsToKeep($contact);

        $this->assertNotContains('orders', $reasons, 'a page opened and never paid is not a sale');
    }

    #[Test]
    public function a_paid_order_keeps_the_contact_as_a_sale_the_office_holds(): void
    {
        $contact = $this->member();
        $this->orderFor($contact, Order::STATUS_PAID);

        $this->assertContains('orders', app(MemberAccountDeletion::class)->reasonsToKeep($contact));
    }

    #[Test]
    public function a_kept_contact_loses_its_unpaid_basket_and_the_names_in_it(): void
    {
        // Kept (a paid order is office data), so forceDelete never runs and the FK
        // cascade never fires — the basket must be deleted explicitly.
        $contact = $this->member();
        $org = \App\Models\Masjid::find($contact->masjid_id);
        $paid = $this->orderFor($contact, Order::STATUS_PAID);
        $abandoned = $this->orderFor($contact, Order::STATUS_PENDING, 'typed-at-checkout@example.org');
        $cart = $this->cart($org, $contact->id);
        $this->add($cart, CartItem::TYPE_FORM, $this->ticketForm($org)->id, 1500, 2, $this->twoTickets());

        $this->assertSame(['orders'], app(MemberAccountDeletion::class)->reasonsToKeep($contact), '...and ONLY because of the paid order');

        app(MemberAccountDeletion::class)->delete($contact, MemberAccountDeletion::VIA_WEB);

        $this->assertDatabaseHas('contacts', ['id' => $contact->id]);   // premise: it WAS kept...
        $this->assertDatabaseMissing('carts', ['id' => $cart->id]);
        $this->assertSame(0, DB::table('cart_items')->where('cart_id', $cart->id)->count(), 'no attendee name survives');
        $this->assertNull($abandoned->fresh()->buyer_email, 'an abandoned checkout keeps no address');
        $this->assertSame('buyer@example.org', $paid->fresh()->buyer_email, 'a paid order is an office record and keeps its buyer');
    }

    #[Test]
    public function an_erased_contact_leaves_no_basket_and_no_address_on_an_abandoned_checkout(): void
    {
        $contact = $this->member();
        $org = \App\Models\Masjid::find($contact->masjid_id);
        $abandoned = $this->orderFor($contact, Order::STATUS_PENDING, 'typed-at-checkout@example.org');
        $cart = $this->cart($org, $contact->id);
        $this->add($cart, CartItem::TYPE_DONATION, $this->fund($org)->id, 5000);

        app(MemberAccountDeletion::class)->delete($contact, MemberAccountDeletion::VIA_WEB);

        $this->assertDatabaseMissing('contacts', ['id' => $contact->id]);   // premise: erased
        $this->assertDatabaseMissing('carts', ['id' => $cart->id]);
        $fresh = $abandoned->fresh();
        $this->assertNull($fresh->buyer_email);
        $this->assertNull($fresh->contact_id, 'the order outlives the person only as an anonymous row');
    }
}
