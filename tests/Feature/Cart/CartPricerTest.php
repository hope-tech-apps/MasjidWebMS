<?php

namespace Tests\Feature\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Form;
use App\Models\Fund;
use App\Models\Masjid;
use App\Models\MealMenu;
use App\Models\MealMenuItem;
use App\Services\Cart\CartPricer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pricing a whole basket at checkout (universal cart, DECISIONS 2026-09-26).
 *
 * The rules pinned here are the ones a single line source cannot see: which
 * account the money goes to, that one basket never pays two payees, and that an
 * item id planted from another organisation is never found — the public basket
 * pages run UNBOUND, so the tenant scope is not there to catch it.
 */
class CartPricerTest extends TestCase
{
    use RefreshDatabase;

    private function org(array $overrides = []): Masjid
    {
        return Masjid::create(array_merge([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@example.test',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'stripe_account_id' => 'acct_own_' . uniqid(),
            'stripe_charges_enabled' => true,
            'timezone' => 'America/New_York',
        ], $overrides));
    }

    private function ticketForm(Masjid $org, array $overrides = []): Form
    {
        return Form::factory()->create(array_merge([
            'masjid_id' => $org->id,
            'name' => 'Festival Tickets',
            'is_active' => true,
            'closes_at' => null,
            'schema' => ['sections' => [[
                'id' => 'tickets', 'title' => 'Ticket', 'repeatable' => true, 'minEntries' => 1, 'maxEntries' => 20,
                'fields' => [['name' => 'attendeeName', 'type' => 'text', 'label' => 'Attendee name', 'required' => true]],
            ]]],
            'settings' => [
                'fee' => ['amount' => 15, 'currency' => 'USD', 'perEntryOfSection' => 'tickets'],
                'payment' => ['online' => true, 'officePayment' => false],
            ],
        ], $overrides));
    }

    private function fund(Masjid $org, bool $active = true): Fund
    {
        return Fund::create(['masjid_id' => $org->id, 'name' => 'Zakat-ul-Fitr', 'type' => 'fitra', 'is_active' => $active]);
    }

    private function dish(Masjid $org): MealMenuItem
    {
        $menu = MealMenu::create([
            'masjid_id' => $org->id, 'title' => 'Halal Kitchen', 'kind' => MealMenu::KIND_CATALOGUE,
            'status' => MealMenu::STATUS_OPEN, 'allow_online_payment' => true,
        ]);

        return MealMenuItem::create([
            'masjid_id' => $org->id, 'meal_menu_id' => $menu->id,
            'name' => 'Baked Lamb', 'price_minor' => 1200, 'is_available' => true,
        ]);
    }

    private function cart(Masjid $org): Cart
    {
        return Cart::withoutMasjidScope()->create(['masjid_id' => $org->id, 'token_hash' => hash('sha256', uniqid('', true))]);
    }

    private function add(Cart $cart, string $type, int $buyableId, int $shown, int $qty = 1, array $payload = [], ?int $masjidId = null): void
    {
        CartItem::withoutMasjidScope()->create([
            'cart_id' => $cart->id,
            'masjid_id' => $masjidId ?? $cart->masjid_id,
            'buyable_type' => $type,
            'buyable_id' => $buyableId,
            'recorded_as' => $type === CartItem::TYPE_DONATION ? CartItem::RECORDED_AS_DONATION : CartItem::RECORDED_AS_ORDER_ONLY,
            'label' => ucfirst($type),
            'quantity' => $qty,
            'unit_amount_shown_minor' => $shown,
            'currency' => 'usd',
            'payload' => $payload,
        ]);
    }

    private function twoTickets(): array
    {
        return ['tickets' => [['attendeeName' => 'A'], ['attendeeName' => 'B']]];
    }

    #[Test]
    public function a_mixed_basket_is_one_payment_into_the_organisations_own_account(): void
    {
        $org = $this->org();
        $cart = $this->cart($org);
        $this->add($cart, CartItem::TYPE_FORM, $this->ticketForm($org)->id, 1500, 1, $this->twoTickets());   // $30
        $this->add($cart, CartItem::TYPE_MEAL, $this->dish($org)->id, 1200, 2, ['pickup_at' => now()->addDays(3)->toIso8601String()]); // $24
        $this->add($cart, CartItem::TYPE_DONATION, $this->fund($org)->id, 5000);                                  // $50

        $priced = (new CartPricer)->price($cart);

        $this->assertNull($priced->refusal);
        $this->assertTrue($priced->isPayable());
        $this->assertSame(10400, $priced->totalMinor);
        $this->assertSame($org->stripe_account_id, $priced->destinationAccountId);
        $this->assertSame([], $priced->notices(), 'nothing changed, so there is nothing to tell the shopper');
    }

    #[Test]
    public function an_item_id_from_another_organisation_is_never_found_and_never_charged(): void
    {
        // The public pages run UNBOUND, so the tenant scope is not there to catch this.
        $mine = $this->org();
        $theirs = $this->org();
        $theirForm = $this->ticketForm($theirs, ['name' => 'Their Tickets']);
        $theirFund = $this->fund($theirs);

        $cart = $this->cart($mine);
        $this->add($cart, CartItem::TYPE_FORM, $theirForm->id, 1500, 1, $this->twoTickets());
        $this->add($cart, CartItem::TYPE_DONATION, $theirFund->id, 5000);

        $priced = (new CartPricer)->price($cart);

        $this->assertSame(0, $priced->totalMinor);
        $this->assertFalse($priced->isPayable());
        foreach ($priced->lines as ['outcome' => $outcome]) {
            $this->assertSame('gone', $outcome->status);
        }
        $this->assertNotSame($theirs->stripe_account_id, $priced->destinationAccountId, 'another org must never be the payee');
    }

    #[Test]
    public function pricing_one_basket_never_includes_another_baskets_lines(): void
    {
        // Guards the bug a relation-plus-static-scope call would have caused:
        // dropping cart_id and pricing every basket's lines into this one.
        $org = $this->org();
        $fund = $this->fund($org);
        $mine = $this->cart($org);
        $someoneElses = $this->cart($org);
        $this->add($mine, CartItem::TYPE_DONATION, $fund->id, 1000);
        $this->add($someoneElses, CartItem::TYPE_DONATION, $fund->id, 99900);

        $priced = (new CartPricer)->price($mine);

        $this->assertCount(1, $priced->lines);
        $this->assertSame(1000, $priced->totalMinor);
    }

    #[Test]
    public function a_line_that_closed_is_dropped_named_and_left_out_of_the_total(): void
    {
        $org = $this->org();
        $cart = $this->cart($org);
        $this->add($cart, CartItem::TYPE_FORM, $this->ticketForm($org, ['closes_at' => now()->subMinute()])->id, 1500, 1, $this->twoTickets());
        $this->add($cart, CartItem::TYPE_DONATION, $this->fund($org)->id, 5000);

        $priced = (new CartPricer)->price($cart);

        $this->assertTrue($priced->isPayable(), 'the rest of the basket can still be paid');
        $this->assertSame(5000, $priced->totalMinor, 'the closed tickets must not be in the total');
        $this->assertCount(1, $priced->notices());
        $this->assertSame('gone', $priced->notices()[0]['status']);
    }

    #[Test]
    public function an_organisation_that_cannot_take_payments_gets_no_payee(): void
    {
        $org = $this->org(['stripe_charges_enabled' => false]);
        $cart = $this->cart($org);
        $this->add($cart, CartItem::TYPE_DONATION, $this->fund($org)->id, 5000);
        $this->add($cart, CartItem::TYPE_FORM, $this->ticketForm($org)->id, 1500, 1, $this->twoTickets());

        $priced = (new CartPricer)->price($cart);

        $this->assertFalse($priced->isPayable());
        $this->assertNull($priced->destinationAccountId);
        $this->assertSame(0, $priced->totalMinor, 'nothing is payable when there is nowhere for the money to go');
    }

    #[Test]
    public function a_linked_organisations_forms_go_to_its_holder_and_its_donations_are_refused(): void
    {
        // A linked org is REQUIRED to have no account of its own; its FORM card
        // payments land on its parent (FormChargeAccount), while donations use
        // canAcceptDonations(), which a linked org never passes.
        $holder = $this->org(['stripe_account_id' => 'acct_holder_' . uniqid()]);
        $child = $this->org(['stripe_account_id' => null, 'stripe_charges_enabled' => false]);

        // The link is "set only by a SuperAdmin", so neither column is fillable and
        // Masjid::create() drops them WITHOUT a word — which made the first version of
        // this test build an ordinary unlinked org and pass/fail for the wrong reason.
        // Written straight to the table, as FormLinkedWebhookTest does.
        DB::table('masjids')->where('id', $child->id)->update([
            'parent_id' => $holder->id,
            'forms_card_via_masjid_id' => $holder->id,
        ]);
        $child->refresh();
        $this->assertSame($holder->id, (int) $child->forms_card_via_masjid_id, 'premise: the child really is linked');

        $cart = $this->cart($child);
        $this->add($cart, CartItem::TYPE_FORM, $this->ticketForm($child)->id, 1500, 1, $this->twoTickets());
        $this->add($cart, CartItem::TYPE_DONATION, $this->fund($child)->id, 5000);

        $priced = (new CartPricer)->price($cart);

        $this->assertSame($holder->stripe_account_id, $priced->destinationAccountId, 'forms are paid to the holder');
        $this->assertSame(3000, $priced->totalMinor, 'only the tickets; the donation cannot be taken');
        $statuses = array_map(fn ($l) => $l['outcome']->status, $priced->lines);
        $this->assertSame(['available', 'gone'], $statuses);
    }

    #[Test]
    public function an_unknown_line_type_is_refused_rather_than_guessed(): void
    {
        $org = $this->org();
        $cart = $this->cart($org);
        $this->add($cart, 'mystery', 1, 5000);

        $priced = (new CartPricer)->price($cart);

        $this->assertSame(0, $priced->totalMinor);
        $this->assertSame('gone', $priced->lines[0]['outcome']->status);
    }

    #[Test]
    public function a_deleted_organisation_refuses_the_whole_basket(): void
    {
        $org = $this->org();
        $cart = $this->cart($org);
        $this->add($cart, CartItem::TYPE_DONATION, $this->fund($org)->id, 5000);
        $org->delete();

        $priced = (new CartPricer)->price($cart);

        $this->assertNotNull($priced->refusal);
        $this->assertFalse($priced->isPayable());
    }
}
