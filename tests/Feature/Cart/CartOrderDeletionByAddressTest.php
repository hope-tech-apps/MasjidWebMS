<?php

namespace Tests\Feature\Cart;

use App\Models\Contact;
use App\Models\Masjid;
use App\Models\Order;
use App\Services\Member\MemberAccountDeletion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FoldsAccentsLikeUnicodeCi;
use Tests\TestCase;

/**
 * Delete account clears the member's own abandoned checkouts, found by the address they typed
 * (pre-merge fix B9).
 *
 * The clean-up was keyed on `orders.contact_id`, but the only door that opens a basket writes
 * `contact_id` NULL (CartsController) and checkout copies that onto the order. So an app member who
 * opened a basket on the website as a guest, typed their address, name and phone and abandoned the
 * Stripe page kept all three on a pending order after pressing "Delete account". An unpaid order
 * whose `buyer_email` is EXACTLY the member's `login_email` or `email`, in the member's organisation,
 * is now cleared; a paid order never is, and a look-alike address (equal only under the
 * `utf8mb4_unicode_ci` collation) is not the member's.
 */
class CartOrderDeletionByAddressTest extends TestCase
{
    use BuildsBaskets;
    use FoldsAccentsLikeUnicodeCi;
    use RefreshDatabase;

    private Masjid $home;

    protected function setUp(): void
    {
        parent::setUp();

        $this->home = $this->org();
    }

    protected function tearDown(): void
    {
        $this->stopFoldingAccents();

        parent::tearDown();
    }

    /** An app member the office holds nothing about (ContactFactory fills phone and notes, which would keep the row). */
    private function member(string $login, ?string $email = null): Contact
    {
        $contact = Contact::factory()->create(['masjid_id' => $this->home->id]);
        $contact->forceFill([
            'phone' => null,
            'notes' => null,
            'signup_source' => 'app',
            'email' => $email ?? $login,
            'login_email' => $login,
        ])->save();

        return $contact->refresh();
    }

    /** A checkout as the public door leaves it: no contact, the buyer's typed details. */
    private function checkout(string $status, ?string $email, ?Masjid $org = null): Order
    {
        return Order::withoutMasjidScope()->create([
            'masjid_id' => ($org ?? $this->home)->id,
            'uuid' => (string) Str::uuid(),
            'order_number' => strtoupper(Str::random(8)),
            'contact_id' => null,
            'buyer_email' => $email,
            'buyer_name' => 'Typed Name',
            'buyer_phone' => '555-0100',
            'status' => $status,
            'total_minor' => 5000,
            'currency' => 'usd',
            'charge_account_id' => 'acct_' . uniqid(),
        ]);
    }

    private function assertCleared(Order $order, string $why): void
    {
        $fresh = $order->fresh();
        $this->assertNotNull($fresh, "{$why}: the order row itself stays");
        $this->assertNull($fresh->buyer_email, "{$why}: address cleared");
        $this->assertNull($fresh->buyer_name, "{$why}: name cleared");
        $this->assertNull($fresh->buyer_phone, "{$why}: phone cleared");
        $this->assertNull($fresh->contact_id);
    }

    private function assertUntouched(Order $order, string $email, string $why): void
    {
        $fresh = $order->fresh();
        $this->assertSame($email, $fresh->buyer_email, "{$why}: address kept");
        $this->assertSame('Typed Name', $fresh->buyer_name, "{$why}: name kept");
        $this->assertSame('555-0100', $fresh->buyer_phone, "{$why}: phone kept");
    }

    #[Test]
    public function a_guests_abandoned_checkout_at_the_members_own_address_is_cleared_and_nothing_else_is(): void
    {
        $member = $this->member('member.one@example.org');
        $elsewhere = $this->org();

        $pending = $this->checkout(Order::STATUS_PENDING, 'member.one@example.org');
        $expired = $this->checkout(Order::STATUS_EXPIRED, 'Member.One@Example.org');   // capitals: the same mailbox
        $paid = $this->checkout(Order::STATUS_PAID, 'member.one@example.org');          // a sale: the office keeps its buyer
        $otherOrg = $this->checkout(Order::STATUS_PENDING, 'member.one@example.org', $elsewhere);   // another organisation's
        $someoneElse = $this->checkout(Order::STATUS_PENDING, 'member.two@example.org');

        app(MemberAccountDeletion::class)->delete($member, MemberAccountDeletion::VIA_WEB);

        $this->assertCleared($pending, 'pending at the login address');
        $this->assertCleared($expired, 'expired at the login address, in capitals');
        $this->assertUntouched($paid, 'member.one@example.org', 'a paid order');
        $this->assertUntouched($otherOrg, 'member.one@example.org', 'another organisation\'s order');
        $this->assertUntouched($someoneElse, 'member.two@example.org', 'somebody else\'s order');
    }

    #[Test]
    public function the_members_office_email_is_matched_too_and_a_kept_contact_is_cleaned_the_same(): void
    {
        // An `email` that differs from `login_email` was put there by the office, which keeps the contact:
        // the abandoned checkout is cleared all the same.
        $member = $this->member('login@example.org', 'office@example.org');
        $atOfficeAddress = $this->checkout(Order::STATUS_PENDING, 'office@example.org');
        $atLoginAddress = $this->checkout(Order::STATUS_PENDING, 'login@example.org');

        $result = app(MemberAccountDeletion::class)->delete($member, MemberAccountDeletion::VIA_WEB);

        $this->assertSame(MemberAccountDeletion::OUTCOME_LOGIN_REMOVED, $result['outcome'], 'premise: the contact was kept');
        $this->assertCleared($atOfficeAddress, 'pending at the office address');
        $this->assertCleared($atLoginAddress, 'pending at the login address');
    }

    #[Test]
    public function an_order_typed_at_a_look_alike_address_keeps_its_buyer(): void
    {
        // Production compares these columns under utf8mb4_unicode_ci, where 'gmail' = 'gmaíl'.
        $this->foldAccentsLikeUnicodeCi();

        $member = $this->member('person@gmail.com');
        $exact = $this->checkout(Order::STATUS_PENDING, 'person@gmail.com');
        $lookAlike = $this->checkout(Order::STATUS_PENDING, 'person@gmaíl.com');

        $this->assertTrue(
            DB::table('orders')->whereRaw('LOWER(buyer_email) = ?', ['person@gmail.com'])->where('id', $lookAlike->id)->exists(),
            'premise: the collation stand-in makes the SQL find the look-alike, as production would'
        );

        app(MemberAccountDeletion::class)->delete($member, MemberAccountDeletion::VIA_WEB);

        $this->assertCleared($exact, 'the exact address');
        $this->assertUntouched($lookAlike, 'person@gmaíl.com', 'the look-alike address');
    }

    #[Test]
    public function the_lookup_also_holds_when_the_member_is_the_one_with_the_accent(): void
    {
        $this->foldAccentsLikeUnicodeCi();

        $member = $this->member('person@gmaíl.com');
        $exact = $this->checkout(Order::STATUS_PENDING, 'person@gmaíl.com');
        $plain = $this->checkout(Order::STATUS_PENDING, 'person@gmail.com');

        app(MemberAccountDeletion::class)->delete($member, MemberAccountDeletion::VIA_WEB);

        $this->assertCleared($exact, 'the member\'s own accented address');
        $this->assertUntouched($plain, 'person@gmail.com', 'the plain look-alike of it');
    }

    #[Test]
    public function an_order_with_no_address_is_left_alone(): void
    {
        $member = $this->member('member.one@example.org');
        $noAddress = $this->checkout(Order::STATUS_PENDING, null);

        app(MemberAccountDeletion::class)->delete($member, MemberAccountDeletion::VIA_WEB);

        $fresh = $noAddress->fresh();
        $this->assertNull($fresh->buyer_email);
        $this->assertSame('Typed Name', $fresh->buyer_name, 'nobody proved this checkout was theirs');
    }
}
