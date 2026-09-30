<?php

namespace Tests\Feature\Cart;

use App\Mail\DonationReceiptMail;
use App\Mail\FormResponseSubmitted;
use App\Models\CartItem;
use App\Models\Donation;
use App\Models\DonationReceipt;
use App\Models\Form;
use App\Models\FormResponse;
use App\Models\Fund;
use App\Models\MealMenu;
use App\Models\MealMenuItem;
use App\Models\MealOrder;
use App\Models\MealOrderItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StripeWebhookEvent;
use App\Services\Cart\CartSettlementService;
use App\Services\Crm\DonorContactService;
use App\Services\Forms\FormResponseWriter;
use App\Services\Lunch\LunchOrderMailer;
use App\Services\Lunch\MealOrderCreator;
use App\Services\Receipts\ReceiptService;
use App\Services\Stripe\DonationService;
use App\Support\ZakatDesignation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Settling a paid basket from the signed webhook (slice 4b; design §12).
 *
 * Records are created ONLY ONCE PAID: before the payment lands there is no form
 * response, no meal order and no donation. Every event here is signed the way Stripe
 * signs it and posted through the real route (SignsCartWebhooks), and every order is
 * made by CartCheckoutService, so what is settled is the snapshot checkout really wrote.
 *
 * What is pinned:
 *
 *  - checkout freezes what settlement needs, and writes nothing else;
 *  - a mixed basket settles all three records, once, and links each line to its record;
 *  - a replay, and the other success event in either order, create nothing twice;
 *  - an amount or currency that does not match settles nothing and leaves the order
 *    pending; an unpaid completion settles nothing until the money lands;
 *  - a payment after a form closed or filled, a menu closed, a fund deactivated, a dish
 *    deleted or a menu removed is STILL recorded;
 *  - a holder's (linked) basket routes by its opaque reference and its pinned account;
 *  - tenancy is the account's: a uuid on another organisation's account records nothing;
 *  - expiry closes an unpaid order and never touches a paid one;
 *  - a failure on a later line rolls back every earlier one and emails nobody, and the
 *    retry then settles cleanly; emails and receipts run only after the commit.
 */
class CartSettlementTest extends TestCase
{
    use BuildsBaskets;
    use RefreshDatabase;
    use SignsCartWebhooks;

    private const OFFICE = 'festival-office@mec.test';

    protected function setUp(): void
    {
        parent::setUp();

        $this->armWebhooks();
    }

    /** Two $15 tickets, two $12 dishes and a $50 gift: $104.00. */
    private function mixedBasket(): array
    {
        $org = $this->org();
        $form = $this->ticketForm($org, ['settings' => [
            'fee' => ['amount' => 15, 'currency' => 'USD', 'perEntryOfSection' => 'tickets'],
            'payment' => ['online' => true, 'officePayment' => false],
            'notifyEmails' => [self::OFFICE],
        ]]);
        $dish = $this->dish($org);
        $fund = $this->fund($org);
        $cart = $this->cart($org);

        $items = [
            'form' => $this->add($cart, CartItem::TYPE_FORM, $form->id, 1500, 2, $this->twoTickets()),
            'meal' => $this->add($cart, CartItem::TYPE_MEAL, $dish->id, 1200, 2),
            'gift' => $this->add($cart, CartItem::TYPE_DONATION, $fund->id, 5000),
        ];

        return [$org, $cart, $form, $dish, $fund, $items];
    }

    private function recordCounts(): array
    {
        return [
            'forms' => FormResponse::query()->count(),
            'meals' => MealOrder::withoutMasjidScope()->count(),
            'gifts' => Donation::withoutMasjidScope()->count(),
        ];
    }

    private function line(Order $order, string $type): OrderItem
    {
        return OrderItem::withoutMasjidScope()->where('order_id', $order->id)->where('buyable_type', $type)->firstOrFail();
    }

    /** @return array{0: ?string, 1: int} the record a line now points at */
    private function recordOf(Order $order, string $type): array
    {
        $line = $this->line($order, $type);

        return [$line->record_type, (int) $line->record_id];
    }

    // ------------------------------------------------------------ what checkout freezes

    #[Test]
    public function checkout_freezes_what_settlement_needs_and_writes_no_record(): void
    {
        [, $cart, , $dish, $fund] = $this->mixedBasket();

        $order = $this->placeOrder($cart);

        $this->assertSame(['forms' => 0, 'meals' => 0, 'gifts' => 0], $this->recordCounts(), 'nothing exists until the payment lands');
        $this->assertSame(10400, (int) $order->total_minor);

        $form = $this->line($order, CartItem::TYPE_FORM);
        $this->assertSame($this->twoTickets(), $form->payload, 'the answers, as the line carried them');
        $this->assertSame(1500, $form->price_snapshot['unit_minor']);
        $this->assertSame(2, $form->price_snapshot['quantity']);
        $this->assertSame(3000, $form->price_snapshot['amount_due_minor']);
        $this->assertSame(0, $form->price_snapshot['fee_covered_minor']);
        $this->assertSame(3000, $form->price_snapshot['total_minor'], 'quote total = the line\'s charge');
        $this->assertSame('usd', $form->price_snapshot['currency']);

        $meal = $this->line($order, CartItem::TYPE_MEAL);
        $this->assertSame([
            'menu_item_id' => $dish->id,
            'meal_menu_id' => $dish->meal_menu_id,
            'name' => 'Baked Lamb',
            'pickup_at' => null,
        ], $meal->payload);
        $this->assertSame([
            'meal_menu_item_id' => $dish->id,
            'item_name' => 'Baked Lamb',
            'unit_price_minor' => 1200,
            'quantity' => 2,
            'line_total_minor' => 2400,
        ], $meal->price_snapshot, 'the shape LunchOrderLines::price() returns');

        $gift = $this->line($order, CartItem::TYPE_DONATION);
        $this->assertSame(['intended_minor' => 5000], $gift->price_snapshot);
        $this->assertNull($gift->payload, 'no zakat answer was given');
        $this->assertSame($fund->id, (int) $gift->buyable_id);

        $this->assertArrayNotHasKey('payload', $form->toArray(), 'attendee names are never serialised');
        $this->assertArrayNotHasKey('price_snapshot', $form->toArray());
    }

    // ------------------------------------------------------------ settling once

    #[Test]
    public function a_mixed_basket_settles_all_three_records(): void
    {
        [$org, $cart, $form, $dish, $fund, $items] = $this->mixedBasket();
        $order = $this->placeOrder($cart);
        $event = $this->sessionEvent($order);

        $this->postWebhook($event)->assertOk();

        $order->refresh();
        $this->assertSame(Order::STATUS_PAID, $order->status);
        $this->assertNotNull($order->paid_at);
        $this->assertSame('pi_cart_1', $order->stripe_payment_intent_id);
        $this->assertNotNull(StripeWebhookEvent::where('stripe_event_id', $event['id'])->value('processed_at'));

        // The form response: card, paid, priced from the snapshot, keyed to its line.
        $row = FormResponse::query()->sole();
        $this->assertSame($form->id, (int) $row->form_id);
        $this->assertSame($org->id, (int) $row->masjid_id);
        $this->assertSame(FormResponse::METHOD_ONLINE, $row->payment_method);
        $this->assertSame(FormResponse::PAYMENT_PAID, $row->payment_status);
        $this->assertNotNull($row->paid_at);
        $this->assertSame('pi_cart_1', $row->stripe_payment_intent_id);
        $this->assertSame(3000, (int) $row->total_minor);
        $this->assertSame(1500, (int) $row->unit_price_minor);
        $this->assertSame(2, (int) $row->price_quantity);
        $this->assertSame(2, (int) $row->entry_count);
        $this->assertSame('cart:item:' . $items['form']->id, $row->client_submission_key);
        $this->assertSame(['A', 'B'], array_column($row->data['tickets'], 'attendeeName'));

        // The meal order: the frozen line, paid, on the menu, for the buyer.
        $meal = MealOrder::withoutMasjidScope()->sole();
        $this->assertSame((int) $dish->meal_menu_id, (int) $meal->meal_menu_id);
        $this->assertSame(MealOrder::METHOD_ONLINE, $meal->payment_method);
        $this->assertSame(MealOrder::PAYMENT_PAID, $meal->payment_status);
        $this->assertSame(2400, (int) $meal->total_minor);
        $this->assertSame('pi_cart_1', $meal->stripe_payment_intent_id);
        $this->assertSame('Amal Buyer', $meal->customer_name, 'the payer\'s name from Stripe: a basket collects none');
        $line = MealOrderItem::withoutMasjidScope()->where('meal_order_id', $meal->id)->sole();
        $this->assertSame('Baked Lamb', $line->item_name);
        $this->assertSame(1200, (int) $line->unit_price_minor);
        $this->assertSame(2, (int) $line->quantity);
        $this->assertSame($dish->id, (int) $line->meal_menu_item_id);

        // The donation: succeeded, the basket's payment intent, and NO fabricated fee or net.
        $gift = Donation::withoutMasjidScope()->sole();
        $this->assertSame($fund->id, (int) $gift->fund_id);
        $this->assertSame('succeeded', $gift->status);
        $this->assertSame(5000, (int) $gift->intended_amount);
        $this->assertSame(5000, (int) $gift->charged_amount);
        $this->assertSame('pi_cart_1', $gift->stripe_payment_intent_id);
        $this->assertSame('cart:item:' . $items['gift']->id, $gift->idempotency_key);
        $this->assertNull($gift->stripe_fee_amount, 'the basket\'s one fee cannot be split honestly per line');
        $this->assertNull($gift->net_amount);
        $this->assertSame(ZakatDesignation::resolve(null, $fund)['is_zakat'], (bool) $gift->is_zakat, 'zakat is ZakatDesignation\'s alone');

        // Each line points at what its own service made.
        $this->assertSame([OrderItem::RECORD_FORM_RESPONSE, (int) $row->id], $this->recordOf($order, CartItem::TYPE_FORM));
        $this->assertSame([OrderItem::RECORD_MEAL_ORDER, (int) $meal->id], $this->recordOf($order, CartItem::TYPE_MEAL));
        $this->assertSame([OrderItem::RECORD_DONATION, (int) $gift->id], $this->recordOf($order, CartItem::TYPE_DONATION));

        // After the commit: the office hears about the ticket once, and the gift is receipted.
        Mail::assertQueued(FormResponseSubmitted::class, 1);
        $receipt = DonationReceipt::withoutMasjidScope()->sole();
        $this->assertSame((int) $gift->id, (int) $receipt->donation_id);
        $this->assertSame(5000, (int) $receipt->gross_amount, 'the receipt states what the line charged');
        Mail::assertSent(DonationReceiptMail::class, 1);
    }

    #[Test]
    public function a_replayed_event_creates_nothing_twice(): void
    {
        [, $cart] = $this->mixedBasket();
        $order = $this->placeOrder($cart);
        $event = $this->sessionEvent($order);

        $this->postWebhook($event)->assertOk();
        $paidAt = $order->fresh()->paid_at;
        $counts = $this->recordCounts();
        $this->assertSame(['forms' => 1, 'meals' => 1, 'gifts' => 1], $counts);

        // The very same delivery: the ledger answers it.
        $this->postWebhook($event)->assertOk()->assertJsonPath('message', 'Duplicate event ignored.');
        // A new delivery of the same payment: another event id, the same money.
        $this->postWebhook($this->sessionEvent($order))->assertOk();
        $this->postWebhook($this->sessionEvent($order, ['type' => 'checkout.session.async_payment_succeeded']))->assertOk();
        $this->postWebhook($this->intentEvent($order))->assertOk();

        $this->assertSame($counts, $this->recordCounts());
        $this->assertSame(1, DonationReceipt::withoutMasjidScope()->count());
        $this->assertTrue($paidAt->equalTo($order->fresh()->paid_at), 'paid_at is stamped once');
        Mail::assertQueued(FormResponseSubmitted::class, 1);
        Mail::assertSent(DonationReceiptMail::class, 1);
    }

    #[Test]
    public function the_payment_intent_settles_first_and_the_session_after_it_changes_nothing(): void
    {
        [, $cart] = $this->mixedBasket();
        $order = $this->placeOrder($cart);

        $this->postWebhook($this->intentEvent($order))->assertOk();

        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
        $this->assertSame(['forms' => 1, 'meals' => 1, 'gifts' => 1], $this->recordCounts());

        $this->postWebhook($this->sessionEvent($order))->assertOk();

        $this->assertSame(['forms' => 1, 'meals' => 1, 'gifts' => 1], $this->recordCounts());
    }

    #[Test]
    public function a_second_payment_on_a_paid_order_is_logged_and_never_recorded_over_the_first(): void
    {
        [, $cart] = $this->mixedBasket();
        $order = $this->placeOrder($cart);
        $this->postWebhook($this->sessionEvent($order))->assertOk();

        $this->postWebhook($this->sessionEvent($order, ['payment_intent' => 'pi_cart_SECOND']))->assertOk();

        $this->assertSame('pi_cart_1', $order->fresh()->stripe_payment_intent_id);
        $this->assertSame(['forms' => 1, 'meals' => 1, 'gifts' => 1], $this->recordCounts());
        $this->assertSame('pi_cart_1', Donation::withoutMasjidScope()->sole()->stripe_payment_intent_id);
        $this->assertWarned('charged a second time');
    }

    // ------------------------------------------------------------ what is not settled

    #[Test]
    public function an_amount_mismatch_settles_nothing_and_leaves_the_order_pending(): void
    {
        [, $cart] = $this->mixedBasket();
        $order = $this->placeOrder($cart);

        $this->postWebhook($this->sessionEvent($order, ['amount' => 10399]))->assertOk();

        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
        $this->assertNull($order->fresh()->paid_at);
        // Pending, but the payment it was refused for is on record, so a refund of it is not lost
        // (CartPreSettlementFlagTest). Having an intent is not being paid: the status says so.
        $this->assertSame('pi_cart_1', $order->fresh()->stripe_payment_intent_id);
        $this->assertFalse($order->fresh()->isPaid());
        $this->assertSame(['forms' => 0, 'meals' => 0, 'gifts' => 0], $this->recordCounts());
        $this->assertWarned('did not match the order');
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    #[Test]
    public function a_currency_mismatch_settles_nothing(): void
    {
        [, $cart] = $this->mixedBasket();
        $order = $this->placeOrder($cart);

        $this->postWebhook($this->sessionEvent($order, ['currency' => 'eur']))->assertOk();
        $this->postWebhook($this->intentEvent($order, ['amount' => 100]))->assertOk();

        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
        $this->assertSame(['forms' => 0, 'meals' => 0, 'gifts' => 0], $this->recordCounts());
    }

    #[Test]
    public function a_completed_page_that_is_not_paid_settles_nothing_until_the_money_lands(): void
    {
        [, $cart] = $this->mixedBasket();
        $order = $this->placeOrder($cart);

        // `status: complete` alone is never enough: a delayed method completes the page first.
        $this->postWebhook($this->sessionEvent($order, ['payment_status' => 'unpaid']))->assertOk();

        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
        $this->assertSame(['forms' => 0, 'meals' => 0, 'gifts' => 0], $this->recordCounts());

        $this->postWebhook($this->intentEvent($order))->assertOk();

        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
        $this->assertSame(['forms' => 1, 'meals' => 1, 'gifts' => 1], $this->recordCounts());
    }

    // ------------------------------------------------------------ money taken is always recorded

    #[Test]
    public function a_late_payment_after_the_form_closed_or_filled_is_still_recorded(): void
    {
        [, $cart, $form, , $fund] = $this->mixedBasket();
        $order = $this->placeOrder($cart);

        // Between the page opening and the payment landing: the form's window ends and it
        // fills, the menu closes and the fund is deactivated. The money is taken regardless.
        Form::query()->whereKey($form->id)->update(['closes_at' => now()->subDay(), 'capacity' => 1, 'response_count' => 1]);
        MealMenu::withoutMasjidScope()->update(['status' => MealMenu::STATUS_CLOSED]);
        Fund::withoutMasjidScope()->whereKey($fund->id)->update(['is_active' => false]);

        $this->postWebhook($this->sessionEvent($order))->assertOk();

        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
        $row = FormResponse::query()->sole();
        $this->assertSame(FormResponse::PAYMENT_PAID, $row->payment_status, 'the writer asks no question');
        $this->assertSame(2, (int) $form->fresh()->response_count, 'the counter moved under the form lock');
        $this->assertSame(MealOrder::PAYMENT_PAID, MealOrder::withoutMasjidScope()->sole()->payment_status);
        $this->assertSame('succeeded', Donation::withoutMasjidScope()->sole()->status);

        // Each oversell is logged, never refused.
        $this->assertWarned('no longer accepts responses');
        $this->assertWarned('can no longer be ordered');
        $this->assertWarned('no longer collecting');
    }

    #[Test]
    public function a_deleted_dish_is_still_recorded_under_its_snapshot_name(): void
    {
        [, $cart, , $dish] = $this->mixedBasket();
        $order = $this->placeOrder($cart);

        MealMenuItem::withoutMasjidScope()->whereKey($dish->id)->delete();

        $this->postWebhook($this->sessionEvent($order))->assertOk();

        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
        $meal = MealOrder::withoutMasjidScope()->sole();
        $this->assertSame(MealOrder::PAYMENT_PAID, $meal->payment_status);
        $line = MealOrderItem::withoutMasjidScope()->where('meal_order_id', $meal->id)->sole();
        $this->assertNull($line->meal_menu_item_id, 'the dish is gone; only its id is lost');
        $this->assertSame('Baked Lamb', $line->item_name);
        $this->assertSame(1200, (int) $line->unit_price_minor, 'the price the shopper paid, not a live one');
        $this->assertSame(2400, (int) $meal->total_minor);
        $this->assertSame(['forms' => 1, 'meals' => 1, 'gifts' => 1], $this->recordCounts());
    }

    #[Test]
    public function a_menu_removed_after_checkout_is_still_recorded_against_it(): void
    {
        [, $cart, , $dish] = $this->mixedBasket();
        $order = $this->placeOrder($cart);

        MealMenu::withoutMasjidScope()->whereKey($dish->meal_menu_id)->delete();

        $this->postWebhook($this->sessionEvent($order))->assertOk();

        $meal = MealOrder::withoutMasjidScope()->sole();
        $this->assertSame((int) $dish->meal_menu_id, (int) $meal->meal_menu_id, 'read withTrashed');
        $this->assertSame(MealOrder::PAYMENT_PAID, $meal->payment_status);
        $this->assertWarned('can no longer be ordered');
    }

    #[Test]
    public function a_form_removed_after_checkout_is_still_recorded(): void
    {
        [, $cart, $form] = $this->mixedBasket();
        $order = $this->placeOrder($cart);

        $form->delete();

        $this->postWebhook($this->sessionEvent($order))->assertOk();

        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
        $this->assertSame(FormResponse::PAYMENT_PAID, FormResponse::query()->sole()->payment_status);
    }

    #[Test]
    public function the_form_is_written_from_the_snapshot_even_if_its_price_moved(): void
    {
        [, $cart, $form] = $this->mixedBasket();
        $order = $this->placeOrder($cart);

        // The price is edited to $20 and the card switch flipped off before the payment
        // lands. A re-quote would now differ, or come back null.
        $settings = $form->settings;
        $settings['fee']['amount'] = 20;
        $settings['payment']['online'] = false;
        $form->forceFill(['settings' => $settings])->save();

        $this->postWebhook($this->sessionEvent($order))->assertOk();

        $row = FormResponse::query()->sole();
        $this->assertSame(1500, (int) $row->unit_price_minor, 'what was quoted at checkout');
        $this->assertSame(3000, (int) $row->total_minor);
        $this->assertSame(FormResponse::PAYMENT_PAID, $row->payment_status);
    }

    #[Test]
    public function a_donors_own_zakat_answer_reaches_the_only_rule_that_decides_it(): void
    {
        $org = $this->org();
        $fund = $this->fund($org);
        $cart = $this->cart($org);
        $this->add($cart, CartItem::TYPE_DONATION, $fund->id, 2500, 1, ['zakat' => true]);
        $order = $this->placeOrder($cart);
        $this->assertSame(['zakat' => true], $this->line($order, CartItem::TYPE_DONATION)->payload);

        $this->postWebhook($this->sessionEvent($order))->assertOk();

        $gift = Donation::withoutMasjidScope()->sole();
        $expected = ZakatDesignation::resolve(true, $fund);
        $this->assertSame($expected['is_zakat'], (bool) $gift->is_zakat);
        $this->assertSame($expected['zakat_source'], $gift->zakat_source);
    }

    // ------------------------------------------------------------ whose order it is

    #[Test]
    public function the_linked_org_route_finds_the_order_by_its_reference_and_pinned_account(): void
    {
        $holder = $this->org(['stripe_account_id' => 'acct_holder_' . uniqid()]);
        $child = $this->org(['name' => 'Burlington Islamic Sunday School', 'stripe_account_id' => null, 'stripe_charges_enabled' => false]);
        DB::table('masjids')->where('id', $child->id)->update(['parent_id' => $holder->id, 'forms_card_via_masjid_id' => $holder->id]);
        $child->refresh();

        $form = $this->ticketForm($child);
        $cart = $this->cart($child);
        $this->add($cart, CartItem::TYPE_FORM, $form->id, 1500, 2, $this->twoTickets());
        $order = $this->placeOrder($cart);

        $this->assertNotNull($order->charge_ref, 'premise: a holder\'s page carries only the opaque reference');
        $this->assertSame($holder->stripe_account_id, $order->charge_account_id);

        // Wrong account for this reference: refused, recorded nowhere.
        $stranger = $this->org(['stripe_account_id' => 'acct_stranger_' . uniqid()]);
        $this->postWebhook($this->sessionEvent($order, ['account' => $stranger->stripe_account_id]))->assertOk();
        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
        $this->assertSame(0, FormResponse::query()->count());

        // A page the order never recorded: refused too (the holder's users can write metadata).
        $this->postWebhook($this->sessionEvent($order, ['session_id' => 'cs_forged']))->assertOk();
        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);

        // The holder's account, the reference, the recorded page: settled, for the CHILD.
        $this->postWebhook($this->sessionEvent($order))->assertOk();

        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
        $row = FormResponse::query()->sole();
        $this->assertSame($child->id, (int) $row->masjid_id, 'the organisation selling, not the account holder');
        $this->assertSame($form->id, (int) $row->form_id);
        $this->assertSame(FormResponse::PAYMENT_PAID, $row->payment_status);
    }

    #[Test]
    public function a_uuid_on_another_organisations_account_records_nothing(): void
    {
        [, $cart] = $this->mixedBasket();
        $order = $this->placeOrder($cart);
        $other = $this->org(['stripe_account_id' => 'acct_other_' . uniqid()]);

        $this->postWebhook($this->sessionEvent($order, ['account' => $other->stripe_account_id]))->assertOk();
        $this->postWebhook($this->sessionEvent($order, ['account' => 'acct_nobody_holds_this']))->assertOk();
        $this->postWebhook($this->sessionEvent($order, ['account' => null]))->assertOk();

        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
        $this->assertSame(['forms' => 0, 'meals' => 0, 'gifts' => 0], $this->recordCounts());
        $this->assertWarned('does not belong to the organisation');
        $this->assertWarned('no organisation holds');
        $this->assertWarned('without a connected account');
    }

    #[Test]
    public function a_session_the_order_never_recorded_is_refused(): void
    {
        [, $cart] = $this->mixedBasket();
        $order = $this->placeOrder($cart);

        $this->postWebhook($this->sessionEvent($order, ['session_id' => 'cs_forged']))->assertOk();

        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
        $this->assertSame(['forms' => 0, 'meals' => 0, 'gifts' => 0], $this->recordCounts());
    }

    // ------------------------------------------------------------ expiry

    #[Test]
    public function an_expired_page_closes_an_unpaid_order_and_never_touches_a_paid_one(): void
    {
        [, $cart] = $this->mixedBasket();
        $order = $this->placeOrder($cart);

        $this->postWebhook($this->sessionEvent($order, ['type' => 'checkout.session.expired', 'payment_status' => 'unpaid']))->assertOk();

        $this->assertSame(Order::STATUS_EXPIRED, $order->fresh()->status);
        $this->assertSame(['forms' => 0, 'meals' => 0, 'gifts' => 0], $this->recordCounts());

        // A basket that was paid, then heard about an expiry: stays paid.
        [, $cart2] = $this->mixedBasket();
        $paid = $this->placeOrder($cart2);
        $this->postWebhook($this->sessionEvent($paid))->assertOk();

        $this->postWebhook($this->sessionEvent($paid, ['type' => 'checkout.session.expired', 'payment_status' => 'unpaid']))->assertOk();

        $this->assertSame(Order::STATUS_PAID, $paid->fresh()->status);
    }

    #[Test]
    public function a_payment_that_lands_on_an_order_marked_expired_is_still_recorded(): void
    {
        [, $cart] = $this->mixedBasket();
        $order = $this->placeOrder($cart);
        Order::withoutMasjidScope()->whereKey($order->id)->update(['status' => Order::STATUS_EXPIRED]);

        $this->postWebhook($this->sessionEvent($order))->assertOk();

        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
        $this->assertSame(['forms' => 1, 'meals' => 1, 'gifts' => 1], $this->recordCounts());
        $this->assertWarned('marked expired was paid');
    }

    // ------------------------------------------------------------ nothing before the commit

    #[Test]
    public function a_failure_on_a_later_line_rolls_everything_back_emails_nobody_and_the_retry_settles(): void
    {
        [$org, $cart, , $dish] = $this->mixedBasket();
        $order = $this->placeOrder($cart);
        $event = $this->sessionEvent($order);

        // The meal line (the second one) now names a menu of another organisation: it cannot
        // be recorded. The form line before it IS written inside the transaction first.
        $foreign = $this->dish($this->org());
        $meal = $this->line($order, CartItem::TYPE_MEAL);
        $good = $meal->payload;
        $meal->forceFill(['payload' => array_merge($good, ['meal_menu_id' => $foreign->meal_menu_id])])->save();

        $this->postWebhook($event)->assertStatus(500);

        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status, 'the order is not left half-paid');
        $this->assertNull($order->fresh()->paid_at);
        $this->assertSame(['forms' => 0, 'meals' => 0, 'gifts' => 0], $this->recordCounts(), 'the form row written first is gone too');
        $this->assertSame(0, (int) Form::query()->where('masjid_id', $org->id)->sum('response_count'), 'and so is its counter');
        $this->assertNull(OrderItem::withoutMasjidScope()->where('order_id', $order->id)->whereNotNull('record_id')->value('id'), 'no line points at a rolled-back record');
        $this->assertNull(StripeWebhookEvent::where('stripe_event_id', $event['id'])->value('processed_at'), 'left for Stripe to retry');
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Log::shouldHaveReceived('error')->atLeast()->once();

        // The retry, once the line can be recorded: everything, exactly once.
        $meal->forceFill(['payload' => $good])->save();
        $this->postWebhook($event)->assertOk();

        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
        $this->assertSame(['forms' => 1, 'meals' => 1, 'gifts' => 1], $this->recordCounts());
        Mail::assertQueued(FormResponseSubmitted::class, 1);
    }

    #[Test]
    public function the_emails_and_the_receipt_run_only_after_the_transaction_has_closed(): void
    {
        [, $cart] = $this->mixedBasket();
        $order = $this->placeOrder($cart);

        $svc = new class(
            app(FormResponseWriter::class),
            app(MealOrderCreator::class),
            app(DonationService::class),
            app(ReceiptService::class),
            app(DonorContactService::class),
            app(LunchOrderMailer::class),
        ) extends CartSettlementService {
            /** @var list<int> */
            public array $levels = [];

            /** @var list<int> */
            public array $steps = [];

            protected function afterCommit(int $orderId, array $steps): array
            {
                $this->levels[] = DB::transactionLevel();
                $this->steps[] = count($steps);

                return parent::afterCommit($orderId, $steps);
            }
        };
        $this->app->instance(CartSettlementService::class, $svc);

        // The level a request runs at here (RefreshDatabase wraps every test in one).
        $outside = DB::transactionLevel();

        $this->postWebhook($this->sessionEvent($order))->assertOk();

        $this->assertSame([$outside], $svc->levels, 'the steps ran once, with the settlement\'s own transaction closed');
        $this->assertSame([3], $svc->steps, 'one step for each line that settled');
        Mail::assertQueued(FormResponseSubmitted::class, 1);
        Mail::assertSent(DonationReceiptMail::class, 1);
    }

    #[Test]
    public function only_a_line_that_settled_is_notified(): void
    {
        [, $cart] = $this->mixedBasket();
        $order = $this->placeOrder($cart);
        $this->postWebhook($this->sessionEvent($order))->assertOk();

        // Everything is recorded and paid. A later delivery of the same payment settles no
        // line, so nothing more goes out.
        $this->postWebhook($this->intentEvent($order))->assertOk();
        $this->postWebhook($this->sessionEvent($order, ['type' => 'checkout.session.async_payment_succeeded']))->assertOk();

        Mail::assertQueued(FormResponseSubmitted::class, 1);
        Mail::assertSent(DonationReceiptMail::class, 1);
    }
}
