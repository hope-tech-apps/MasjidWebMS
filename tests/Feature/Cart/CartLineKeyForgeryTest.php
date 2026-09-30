<?php

namespace Tests\Feature\Cart;

use App\Http\Requests\Api\V1\Forms\SubmitFormResponseRequest;
use App\Models\CartItem;
use App\Models\Donation;
use App\Models\Form;
use App\Models\FormResponse;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Cart\CartSettlementService;
use App\Services\Forms\FormResponseWriter;
use App\Support\FormPayment;
use App\Support\FormSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The key a basket's record is written under cannot be forged through the public form door
 * (pre-merge fix B3).
 *
 * The form line's key used to be `cart_item_<order_item id>`, which is inside the alphabet the
 * PUBLIC door accepts for `client_submission_key` (`^[A-Za-z0-9_-]{8,64}$`). Anyone could submit
 * that key against the same form; settlement's `earlier()` then found THEIR row, marked it paid
 * with the basket's payment and linked the ticket to it. The key is now `cart:item:<id>`, and a
 * colon is outside the door's alphabet.
 */
class CartLineKeyForgeryTest extends TestCase
{
    use BuildsBaskets;
    use RefreshDatabase;
    use SignsCartWebhooks;

    protected function setUp(): void
    {
        parent::setUp();

        $this->armWebhooks();
    }

    /** What the public door does with a `client_submission_key`: the request's own rule. */
    private function doorAccepts(string $key): bool
    {
        $rule = (new SubmitFormResponseRequest)->rules()['client_submission_key'];

        return ! Validator::make(['client_submission_key' => $key], ['client_submission_key' => $rule])->fails();
    }

    /** A public submission's row: written by the same writer the door uses, under a key the caller chose. */
    private function publicSubmission(Form $form, string $key): FormResponse
    {
        return DB::transaction(function () use ($form, $key): FormResponse {
            $locked = Form::whereKey($form->id)->lockForUpdate()->firstOrFail();
            $schema = FormSchema::for($locked);
            $clean = $schema->only($this->twoTickets());
            $quote = FormPayment::quote($locked, $clean, false, true);

            return (new FormResponseWriter)->write(
                $locked,
                $schema,
                $clean,
                FormResponseWriter::LEG_ONLINE,
                $quote,
                [],
                $key,
                ['data' => $clean],
            );
        });
    }

    #[Test]
    public function the_door_can_produce_the_old_key_and_cannot_produce_the_new_one(): void
    {
        $this->assertTrue($this->doorAccepts('cart_item_42'), 'premise: the old key was a key anyone could submit');
        $this->assertFalse($this->doorAccepts('cart:item:42'), 'the colon is outside the door\'s alphabet');
        $this->assertFalse($this->doorAccepts('cart:item:42:'));
    }

    #[Test]
    public function a_public_submission_keyed_like_the_old_line_key_does_not_capture_the_settlements_row(): void
    {
        $org = $this->org();
        $form = $this->ticketForm($org, ['settings' => [
            'identity' => ['name' => 'tickets.0.attendeeName'],
            'fee' => ['amount' => 15, 'currency' => 'USD', 'perEntryOfSection' => 'tickets'],
            'payment' => ['online' => true, 'officePayment' => true],
        ]]);
        $cart = $this->cart($org);
        $this->add($cart, CartItem::TYPE_FORM, $form->id, 1500, 2, $this->twoTickets());
        $order = $this->placeOrder($cart);
        $line = OrderItem::withoutMasjidScope()->where('order_id', $order->id)->sole();

        // The forger reads the order line's id (it is small and sequential), and submits a ticket
        // under the key the OLD scheme would have used for it, before the payment lands.
        $forged = $this->publicSubmission($form, 'cart_item_' . $line->id);
        $this->assertSame(FormResponse::PAYMENT_UNPAID, $forged->fresh()->payment_status, 'premise: an ordinary unpaid public row');

        $this->postWebhook($this->sessionEvent($order))->assertOk();

        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
        $this->assertSame(2, FormResponse::query()->where('form_id', $form->id)->count(), 'the settlement wrote a row of its own');

        $line = $line->fresh();
        $this->assertSame(OrderItem::RECORD_FORM_RESPONSE, $line->record_type);
        $this->assertNotSame((int) $forged->id, (int) $line->record_id, 'the line is not linked to the forged row');

        $forged = $forged->fresh();
        $this->assertSame(FormResponse::PAYMENT_UNPAID, $forged->payment_status, 'the forged row was not marked paid with the basket\'s payment');
        $this->assertNull($forged->stripe_payment_intent_id);
        $this->assertNull($forged->paid_at);

        $own = FormResponse::query()->findOrFail($line->record_id);
        $this->assertSame(FormResponse::PAYMENT_PAID, $own->payment_status);
        $this->assertSame(CartSettlementService::lineKey($line), $own->client_submission_key);
        $this->assertSame('cart:item:' . $line->id, $own->client_submission_key);
    }

    #[Test]
    public function a_gifts_record_is_keyed_the_same_way_and_a_replay_finds_it(): void
    {
        $org = $this->org();
        $cart = $this->cart($org);
        $this->add($cart, CartItem::TYPE_DONATION, $this->fund($org)->id, 5000);
        $order = $this->placeOrder($cart);
        $line = OrderItem::withoutMasjidScope()->where('order_id', $order->id)->sole();

        $this->postWebhook($this->sessionEvent($order))->assertOk();
        $this->postWebhook($this->sessionEvent($order, ['type' => 'checkout.session.async_payment_succeeded']))->assertOk();

        $gift = Donation::withoutMasjidScope()->sole();
        $this->assertSame('cart:item:' . $line->id, $gift->idempotency_key);
        $this->assertSame((int) $gift->id, (int) $line->fresh()->record_id);
    }
}
