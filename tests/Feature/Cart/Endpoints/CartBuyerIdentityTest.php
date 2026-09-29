<?php

namespace Tests\Feature\Cart\Endpoints;

use App\Models\CartItem;
use App\Models\Contact;
use App\Models\Donation;
use App\Models\MealOrder;
use App\Models\Order;
use App\Services\Member\MemberAccountDeletion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Cart\BuildsBaskets;
use Tests\Feature\Cart\SignsCartWebhooks;
use Tests\TestCase;

/**
 * The buyer's name and phone, from the basket page to the office's records (brief 5,
 * section 3). Until now a basket collected only an e-mail address, so a paid meal order
 * reached the kitchen board as "Online order 1A2B3C4D" with no phone, and a gift's donor
 * was "Donor" unless Stripe happened to say more (ASSUMPTIONS #25).
 *
 * What is pinned: checkout stores the three on the order; settlement writes them onto the
 * meal order (over Stripe's, and over the placeholder) and falls back to them for a gift's
 * donor when Stripe's payer details lack them; an order opened without them still settles
 * under the placeholder; and the two personal columns are cleared with an abandoned checkout
 * and anonymised on staging, as buyer_email always was.
 */
class CartBuyerIdentityTest extends TestCase
{
    use BuildsBaskets;
    use RefreshDatabase;
    use SignsCartWebhooks;

    private const NAME = 'Zaynab Buyer';

    private const PHONE = '+1 555 010 0100';

    private const EMAIL = 'zaynab@example.org';

    protected function setUp(): void
    {
        parent::setUp();

        $this->armWebhooks();
    }

    /** A basket with one dish and the order the buyer's details were opened with. */
    private function mealOrder(?string $name = self::NAME, ?string $phone = self::PHONE, ?string $email = self::EMAIL): Order
    {
        $org = $this->org();
        $cart = $this->cart($org);
        $this->add($cart, CartItem::TYPE_MEAL, $this->dish($org)->id, 1200, 2);

        return $this->checkoutService()->checkout($cart, self::RETURN_BASE, $email, $name, $phone)['order']->fresh();
    }

    // ------------------------------------------------------------------- checkout

    #[Test]
    public function checkout_keeps_what_the_buyer_typed_on_the_order(): void
    {
        $order = $this->mealOrder();

        $this->assertSame(self::NAME, $order->buyer_name);
        $this->assertSame(self::PHONE, $order->buyer_phone);
        $this->assertSame(self::EMAIL, $order->buyer_email);
        $this->assertTrue(Schema::hasColumns('orders', ['buyer_name', 'buyer_phone']));
    }

    #[Test]
    public function blanks_are_kept_as_null_and_a_long_name_is_cut_to_its_column(): void
    {
        $order = $this->mealOrder('   ', '', self::EMAIL);

        $this->assertNull($order->buyer_name, 'a blank name is no name');
        $this->assertNull($order->buyer_phone);

        $long = $this->mealOrder(str_repeat('N', 200), str_repeat('9', 50), self::EMAIL);

        $this->assertSame(120, mb_strlen($long->buyer_name), 'orders.buyer_name is 120 wide');
        $this->assertSame(32, mb_strlen($long->buyer_phone), 'orders.buyer_phone is 32 wide');
    }

    #[Test]
    public function the_old_call_with_only_an_address_still_works(): void
    {
        $org = $this->org();
        $cart = $this->cart($org);
        $this->add($cart, CartItem::TYPE_DONATION, $this->fund($org)->id, 5000);

        $order = $this->placeOrder($cart);

        $this->assertSame('buyer@example.org', $order->buyer_email);
        $this->assertNull($order->buyer_name);
        $this->assertNull($order->buyer_phone);
    }

    #[Test]
    public function a_page_handed_back_takes_the_phone_and_name_the_shopper_typed_last(): void
    {
        $org = $this->org();
        $cart = $this->cart($org);
        $this->add($cart, CartItem::TYPE_MEAL, $this->dish($org)->id, 1200);
        $service = $this->checkoutService();

        $first = $service->checkout($cart, self::RETURN_BASE, self::EMAIL, self::NAME, '+1 555 000 0000');
        $again = $service->checkout($cart, self::RETURN_BASE, 'corrected@example.org', null, self::PHONE);

        $this->assertSame($first['order']->id, $again['order']->id, 'premise: the same page was handed back');
        $this->assertCount(1, $service->created, 'and no second page was opened');

        $order = $first['order']->fresh();
        $this->assertSame(self::PHONE, $order->buyer_phone, 'the corrected phone is what the office rings');
        $this->assertSame(self::NAME, $order->buyer_name, 'a field the shopper did not send is never blanked');
        $this->assertSame(self::EMAIL, $order->buyer_email, 'the email is the one the Stripe page was opened with');
    }

    // ------------------------------------------------------------ settlement: meals

    #[Test]
    public function a_meal_order_is_recorded_under_the_buyers_name_and_phone_not_the_placeholder(): void
    {
        $order = $this->mealOrder();

        // The payment intent carries no payer at all: everything the kitchen sees is the buyer's.
        $this->postWebhook($this->intentEvent($order))->assertOk();

        $meal = MealOrder::withoutMasjidScope()->sole();

        $this->assertSame(self::NAME, $meal->customer_name);
        $this->assertSame(self::PHONE, $meal->customer_phone);
        $this->assertSame(self::EMAIL, $meal->customer_email);
        $this->assertNotSame('Online order ' . $order->order_number, $meal->customer_name);
    }

    #[Test]
    public function what_the_buyer_typed_beats_the_name_stripe_reports(): void
    {
        // Stripe's customer_details name this payer "Amal Buyer" (SignsCartWebhooks::sessionEvent).
        $order = $this->mealOrder();

        $this->postWebhook($this->sessionEvent($order))->assertOk();

        $meal = MealOrder::withoutMasjidScope()->sole();
        $this->assertSame(self::NAME, $meal->customer_name);
        $this->assertSame(self::PHONE, $meal->customer_phone);
    }

    #[Test]
    public function a_session_event_after_the_intent_never_replaces_a_real_name(): void
    {
        $order = $this->mealOrder();

        $this->postWebhook($this->intentEvent($order))->assertOk();
        $this->postWebhook($this->sessionEvent($order))->assertOk();

        $meal = MealOrder::withoutMasjidScope()->sole();
        $this->assertSame(self::NAME, $meal->customer_name, 'the backfill only ever replaces a placeholder');
        $this->assertSame(self::PHONE, $meal->customer_phone);
    }

    #[Test]
    public function an_order_opened_without_a_name_still_settles_under_the_placeholder(): void
    {
        // A late or legacy order: opened before the endpoints collected them.
        $order = $this->mealOrder(null, null, 'buyer@example.org');

        $this->postWebhook($this->intentEvent($order))->assertOk();

        $meal = MealOrder::withoutMasjidScope()->sole();
        $this->assertSame('Online order ' . $order->order_number, $meal->customer_name, 'money taken is recorded, name or no name');
        $this->assertSame('', $meal->customer_phone);

        // The session event that follows fills the placeholder from Stripe, as before.
        $this->postWebhook($this->sessionEvent($order, ['payment_intent' => 'pi_cart_1']))->assertOk();
        $this->assertSame('Amal Buyer', $meal->fresh()->customer_name);
    }

    // ------------------------------------------------------------ settlement: gifts

    #[Test]
    public function a_gifts_donor_is_the_buyer_when_stripe_gave_no_details(): void
    {
        $org = $this->org();
        $cart = $this->cart($org);
        $this->add($cart, CartItem::TYPE_DONATION, $this->fund($org)->id, 5000);
        $order = $this->checkoutService()->checkout($cart, self::RETURN_BASE, self::EMAIL, self::NAME, self::PHONE)['order']->fresh();

        // A payment intent carries no `customer_details`.
        $this->postWebhook($this->intentEvent($order))->assertOk();

        $gift = Donation::withoutMasjidScope()->sole();
        $donor = Contact::withoutMasjidScope()->where('masjid_id', $org->id)->where('email', self::EMAIL)->first();

        $this->assertNotNull($donor, 'the donor is made from the buyer, not left anonymous');
        $this->assertSame('Zaynab', $donor->first_name, 'the name typed at the basket, not "Donor"');
        $this->assertSame('Buyer', $donor->last_name);
        $this->assertSame($donor->id, $gift->contact_id);
    }

    #[Test]
    public function stripes_own_details_win_over_the_buyers_where_it_has_them(): void
    {
        $org = $this->org();
        $cart = $this->cart($org);
        $this->add($cart, CartItem::TYPE_DONATION, $this->fund($org)->id, 5000);
        $order = $this->checkoutService()->checkout($cart, self::RETURN_BASE, self::EMAIL, self::NAME, self::PHONE)['order']->fresh();

        // customer_details: Amal Buyer <buyer@example.org>.
        $this->postWebhook($this->sessionEvent($order))->assertOk();

        $donor = Contact::withoutMasjidScope()->where('masjid_id', $org->id)->where('email', 'buyer@example.org')->first();

        $this->assertNotNull($donor);
        $this->assertSame('Amal', $donor->first_name);
        $this->assertNull(Contact::withoutMasjidScope()->where('email', self::EMAIL)->first(), 'the typed address was not needed');
    }

    // --------------------------------------------- erasure and the staging scrub

    private function member(): Contact
    {
        // As CartOrderDeletionTest::member(): erasable only when nothing is held about them.
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

    private function orderFor(Contact $contact, string $status): Order
    {
        return Order::withoutMasjidScope()->create([
            'masjid_id' => $contact->masjid_id,
            'uuid' => (string) Str::uuid(),
            'order_number' => strtoupper(Str::random(8)),
            'contact_id' => $contact->id,
            'buyer_email' => 'typed@example.org',
            'buyer_name' => self::NAME,
            'buyer_phone' => self::PHONE,
            'status' => $status,
            'total_minor' => 5000,
            'currency' => 'usd',
            'charge_account_id' => 'acct_' . uniqid(),
        ]);
    }

    #[Test]
    public function an_abandoned_checkout_loses_the_name_and_phone_with_the_address(): void
    {
        $contact = $this->member();
        $paid = $this->orderFor($contact, Order::STATUS_PAID);
        $abandoned = $this->orderFor($contact, Order::STATUS_PENDING);
        $expired = $this->orderFor($contact, Order::STATUS_EXPIRED);

        app(MemberAccountDeletion::class)->delete($contact, MemberAccountDeletion::VIA_WEB);

        foreach ([$abandoned, $expired] as $order) {
            $fresh = $order->fresh();
            $this->assertNull($fresh->buyer_email);
            $this->assertNull($fresh->buyer_name);
            $this->assertNull($fresh->buyer_phone);
        }

        // A paid order is the office's record of a sale and keeps its buyer.
        $this->assertSame(self::NAME, $paid->fresh()->buyer_name);
        $this->assertSame(self::PHONE, $paid->fresh()->buyer_phone);
    }

    #[Test]
    public function the_staging_scrub_anonymises_the_name_and_phone_as_it_does_the_address(): void
    {
        $this->assertSame([
            'buyer_email' => 'email',
            'buyer_name' => 'full_name',
            'buyer_phone' => 'phone',
        ], config('staging_scrub.anonymise.orders'));
    }
}
