<?php

namespace Tests\Feature\Cart;

use App\Models\CartItem;
use App\Models\FormResponse;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A linked registration is pinned to the organisation that HOLDS the account, live before
 * trashed (pre-merge fix B8).
 *
 * pinToHolder() used to read `Masjid::withTrashed()->where('stripe_account_id', ...)->pluck('id')`
 * and take the first, with no ordering and no preference for a live organisation. A trashed
 * organisation that once held the same account id (the unique index covers live rows only) could
 * therefore be named the registration's `charge_masjid_id`, and the receipt and the refund
 * instruction would send staff to the wrong dashboard. It now asks CartPaymentService::accountHolder(),
 * the lookup a refund of the same basket makes.
 */
class CartPinToHolderTest extends TestCase
{
    use BuildsBaskets;
    use RefreshDatabase;
    use SignsCartWebhooks;

    protected function setUp(): void
    {
        parent::setUp();

        $this->armWebhooks();
    }

    /**
     * An offboarded organisation and a live one holding ONE account id, the trashed one with the
     * lower id, and a child whose form card payments go through the live one.
     *
     * @return array{0: \App\Models\Masjid, 1: \App\Models\Masjid, 2: \App\Models\Masjid, 3: Order}
     */
    private function linkedOrderWithATrashedNamesake(): array
    {
        $account = 'acct_shared_' . uniqid();

        $trashed = $this->org(['name' => 'Offboarded Masjid', 'stripe_account_id' => $account]);
        $trashed->delete();
        $holder = $this->org(['name' => 'Live Holder', 'stripe_account_id' => $account]);
        $this->assertGreaterThan($trashed->id, $holder->id, 'premise: the trashed organisation comes first in any unordered lookup');

        $child = $this->org(['name' => 'Burlington Islamic Sunday School', 'stripe_account_id' => null, 'stripe_charges_enabled' => false]);
        DB::table('masjids')->where('id', $child->id)->update(['parent_id' => $holder->id, 'forms_card_via_masjid_id' => $holder->id]);
        $child->refresh();

        $cart = $this->cart($child);
        $this->add($cart, CartItem::TYPE_FORM, $this->ticketForm($child)->id, 1500, 2, $this->twoTickets());
        $order = $this->placeOrder($cart);

        $this->assertNotNull($order->charge_ref, 'premise: a holder\'s page');
        $this->assertSame($account, $order->charge_account_id);

        return [$trashed, $holder, $child, $order];
    }

    #[Test]
    public function a_live_holder_is_named_before_a_trashed_one_when_the_link_no_longer_says(): void
    {
        [$trashed, $holder, $child, $order] = $this->linkedOrderWithATrashedNamesake();

        // The webhook is late (its first attempt failed) and meanwhile the office unlinked the child,
        // so the link cannot say who holds the account any more.
        DB::table('masjids')->where('id', $child->id)->update(['forms_card_via_masjid_id' => null]);

        $this->postWebhook($this->sessionEvent($order))->assertOk();

        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
        $row = FormResponse::query()->sole();
        $this->assertTrue($row->hasChargePin());
        $this->assertSame($order->charge_account_id, $row->charge_account_id);
        $this->assertSame($holder->id, (int) $row->charge_masjid_id, 'the live organisation holding the account');
        $this->assertNotSame($trashed->id, (int) $row->charge_masjid_id);
    }

    #[Test]
    public function the_links_own_holder_still_wins_when_it_holds_the_account(): void
    {
        [, $holder, , $order] = $this->linkedOrderWithATrashedNamesake();

        $this->postWebhook($this->sessionEvent($order))->assertOk();

        $this->assertSame($holder->id, (int) FormResponse::query()->sole()->charge_masjid_id);
    }

    #[Test]
    public function an_offboarded_holder_is_still_named_when_no_live_one_holds_the_account(): void
    {
        [$trashed, $holder, $child, $order] = $this->linkedOrderWithATrashedNamesake();

        // The live namesake goes too and the link is gone: only trashed organisations hold the id.
        DB::table('masjids')->where('id', $child->id)->update(['forms_card_via_masjid_id' => null]);
        $holder->delete();

        $this->postWebhook($this->sessionEvent($order))->assertOk();

        $row = FormResponse::query()->sole();
        $this->assertContains((int) $row->charge_masjid_id, [(int) $trashed->id, (int) $holder->id], 'its money is still recorded against an offboarded organisation');
        $this->assertSame((int) $trashed->id, (int) $row->charge_masjid_id, 'the lowest id, the same answer every time');
    }
}
