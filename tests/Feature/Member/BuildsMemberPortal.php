<?php

namespace Tests\Feature\Member;

use App\Models\Contact;
use App\Models\Donation;
use App\Models\Form;
use App\Models\FormResponse;
use App\Models\Fund;
use App\Models\HistoricalOrder;
use App\Models\Masjid;
use App\Models\MealOrder;
use App\Models\MealOrderItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Builders for the member portal's fixtures.
 *
 * Every builder drops the tenant first and names its organisation itself. The tenant is a
 * scoped binding that a request leaves BOUND, and BelongsToMasjid's creating hook then
 * overrides `masjid_id` with the bound tenant: a row built for organisation B after a
 * request made as a member of A would silently land in A. Unbound, the explicit id is kept
 * (the same reason the other tenancy suites seed unbound).
 *
 * Fields the portal must never show carry a CANARY value, so a test can search the raw
 * response for it instead of trusting that a key list is the whole story.
 */
trait BuildsMemberPortal
{
    protected function unbound(): void
    {
        app(TenantContext::class)->forgetTenant();
    }

    protected function org(array $overrides = []): Masjid
    {
        $this->unbound();

        return Masjid::create(array_merge([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@example.test',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'crm_enabled' => true,
            'timezone' => 'America/New_York',
        ], $overrides));
    }

    /** A contact who has proved control of an address, so `member.active` passes. */
    protected function member(Masjid $org, ?string $address = null, bool $verified = true): Contact
    {
        $this->unbound();

        $contact = Contact::factory()->create(['masjid_id' => $org->id]);

        $contact->forceFill([
            'login_email' => $address ?? 'member-' . uniqid() . '@example.test',
            'verified_at' => $verified ? now() : null,
        ])->save();

        return $contact->refresh();
    }

    /**
     * Authenticate as a member for the NEXT request: a real member token, with the guards
     * and the tenant dropped first so each call is an honest new request. `RequestGuard`
     * memoizes its user and `TenantContext` is not cleared between calls in one process,
     * so without this a second call inside a test is answered out of the first one's state.
     */
    protected function asMember(Contact $contact): static
    {
        Auth::forgetGuards();
        $this->unbound();

        return $this->withHeader('Authorization', 'Bearer ' . $contact->createMemberToken()->plainTextToken);
    }

    protected function portalUrl(Masjid $org, string $path): string
    {
        return "/api/mobile/masjids/{$org->id}/me/{$path}";
    }

    // ------------------------------------------------------------- cart orders

    /**
     * @param  array<int, array{0: string, 1: int, 2: int}>  $lines  label, quantity, unit in minor units
     */
    protected function cartOrder(Masjid $org, array $attributes = [], array $lines = [['Zakat-ul-Fitr', 1, 5000]]): Order
    {
        $this->unbound();

        $order = Order::withoutMasjidScope()->create(array_merge([
            'masjid_id' => $org->id,
            'uuid' => (string) Str::uuid(),
            'order_number' => strtoupper(Str::random(8)),
            'buyer_email' => null,
            'buyer_name' => 'CANARY-BUYER-NAME',
            'buyer_phone' => '5550100',
            'status' => Order::STATUS_PAID,
            'total_minor' => array_sum(array_map(fn (array $l) => $l[1] * $l[2], $lines)),
            'fee_minor' => 31337,
            'currency' => 'usd',
            'charge_account_id' => 'acct_CANARY_' . uniqid(),
            'basket_fingerprint' => hash('sha256', uniqid('', true)),
            'charge_ref' => 'ref_CANARY_' . uniqid(),
            'idempotency_key' => 'idem_CANARY_' . uniqid(),
            'stripe_checkout_session_id' => 'cs_CANARY_' . uniqid(),
            'stripe_payment_intent_id' => 'pi_CANARY_' . uniqid(),
            'paid_at' => now(),
        ], $attributes));

        foreach ($lines as [$label, $quantity, $unit]) {
            $this->orderLine($order, $label, $quantity, $unit);
        }

        return $order->fresh();
    }

    protected function orderLine(Order $order, string $label, int $quantity, int $unit, array $attributes = []): OrderItem
    {
        $this->unbound();

        return OrderItem::withoutMasjidScope()->create(array_merge([
            'order_id' => $order->id,
            'masjid_id' => $order->masjid_id,
            'buyable_type' => 'donation',
            'buyable_id' => 1,
            'recorded_as' => 'donation',
            'label' => $label,
            'quantity' => $quantity,
            'unit_amount_minor' => $unit,
            'total_minor' => $quantity * $unit,
            'currency' => 'usd',
            'payload' => ['attendee' => 'CANARY-ATTENDEE'],
            'price_snapshot' => ['snapshot' => 'CANARY-SNAPSHOT'],
            'cart_payload_hash' => 'CANARYHASH' . uniqid(),
        ], $attributes));
    }

    /** Say that the order's line created this door record, as settlement does. */
    protected function orderRecords(Order $order, string $recordType, int $recordId): OrderItem
    {
        return $this->orderLine($order, 'Recorded line', 1, 100, [
            'buyable_type' => $recordType,
            'recorded_as' => $recordType === OrderItem::RECORD_MEAL_ORDER ? 'order_only' : 'registration',
            'record_type' => $recordType,
            'record_id' => $recordId,
        ]);
    }

    // ---------------------------------------------------------------- Wix history

    /**
     * @param  array<int, array<string, mixed>>|null  $lines
     */
    protected function wixOrder(Masjid $org, ?Contact $contact, array $attributes = [], ?array $lines = null): HistoricalOrder
    {
        $this->unbound();

        return HistoricalOrder::withoutMasjidScope()->create(array_merge([
            'masjid_id' => $org->id,
            'source' => HistoricalOrder::SOURCE_WIX_STORES,
            'order_number' => (string) random_int(10000, 99999999),
            'provider' => HistoricalOrder::PROVIDER_WIX,
            'payment_method' => 'card',
            'status' => HistoricalOrder::STATUS_PAID,
            'ordered_at' => '2024-03-05 15:30:00',
            'contact_id' => $contact?->id,
            'total_minor' => 3300,
            'discount_minor' => 0,
            'fee_minor' => 7654,
            'currency' => 'usd',
            'lines' => $lines ?? [[
                'name' => 'Ramadan Date Box',
                'quantity' => 2,
                'unit_minor' => 1500,
                'options' => '[CANARY-OPTIONS]',
                'discount_minor' => 0,
                'recorded_as' => HistoricalOrder::RECORDED_AS_ORDER_ONLY,
                'recorded_in' => 'CANARY-RECORDED-IN',
            ]],
            'import_batch' => 'CANARY-BATCH',
        ], $attributes));
    }

    // ------------------------------------------------------------- door purchases

    protected function form(Masjid $org, string $name = 'Fall Festival'): Form
    {
        $this->unbound();

        return Form::factory()->create(['masjid_id' => $org->id, 'name' => $name]);
    }

    /**
     * A row as the form's own submit and webhook leave it: an online card payment, paid,
     * for two attendees at $15.
     *
     * @param  array<string, mixed>  $money  overrides for the money-leg columns
     * @param  array<string, mixed>  $attributes  overrides for the fillable columns
     */
    protected function formResponse(Masjid $org, Form $form, ?string $email, array $money = [], array $attributes = []): FormResponse
    {
        $this->unbound();

        $row = new FormResponse(array_merge([
            'form_id' => $form->id,
            'masjid_id' => $org->id,
            'data' => ['fullName' => 'CANARY-ANSWER', 'attendees' => [['attendeeName' => 'CANARY-ATTENDEE']]],
            'respondent_name' => 'Amal Yusuf',
            'respondent_email' => $email,
            'respondent_phone' => '5550111',
            'entry_count' => 2,
            'amount_due' => 30,
            'status' => 'new',
            'submitted_at' => '2026-09-01 12:00:00',
        ], $attributes));

        $row->forceFill(array_merge([
            'payment_method' => FormResponse::METHOD_ONLINE,
            'payment_status' => FormResponse::PAYMENT_PAID,
            'currency' => 'usd',
            'amount_due_minor' => 3000,
            'fee_covered_minor' => 0,
            'total_minor' => 3000,
            'unit_price_minor' => 1500,
            'price_quantity' => 2,
            'price_label' => 'Adult',
            'paid_at' => '2026-09-01 12:05:00',
            'charge_account_id' => 'acct_CANARY_' . uniqid(),
            'stripe_payment_intent_id' => 'pi_CANARY_' . uniqid(),
            'idempotency_key' => 'form_response_' . uniqid(),
        ], $money))->save();

        return $row->fresh();
    }

    /**
     * A PAID meal order with its lines.
     *
     * @param  array<int, array{0: string, 1: int, 2: int}>  $items  name, quantity, unit in minor units
     */
    protected function mealOrder(Masjid $org, ?string $email, array $attributes = [], array $items = [['Chicken Biryani', 2, 800]]): MealOrder
    {
        $this->unbound();

        $total = array_sum(array_map(fn (array $i) => $i[1] * $i[2], $items));

        $order = MealOrder::factory()->paid()->create(array_merge([
            'masjid_id' => $org->id,
            'contact_id' => null,
            'customer_email' => $email,
            'customer_name' => 'CANARY-CUSTOMER-NAME',
            'customer_phone' => '5550122',
            'subtotal_minor' => $total,
            'total_minor' => $total,
            'stripe_payment_intent_id' => 'pi_CANARY_' . uniqid(),
        ], $attributes));

        foreach ($items as [$name, $quantity, $unit]) {
            MealOrderItem::factory()->create([
                'masjid_id' => $org->id,
                'meal_order_id' => $order->id,
                'item_name' => $name,
                'quantity' => $quantity,
                'unit_price_minor' => $unit,
                'line_total_minor' => $quantity * $unit,
            ]);
        }

        return $order->fresh();
    }

    // -------------------------------------------------------------------- gifts

    protected function fund(Masjid $org, string $name = 'General Fund'): Fund
    {
        $this->unbound();

        return Fund::create([
            'masjid_id' => $org->id,
            'name' => $name,
            'type' => 'general',
            'receiptable' => true,
            'is_active' => true,
        ]);
    }

    protected function gift(Masjid $org, Fund $fund, ?Contact $contact, int $cents = 5000, array $attributes = []): Donation
    {
        $this->unbound();

        return Donation::factory()->create(array_merge([
            'masjid_id' => $org->id,
            'fund_id' => $fund->id,
            'contact_id' => $contact?->id,
            'intended_amount' => $cents,
            'charged_amount' => $cents,
            'status' => 'succeeded',
            'stripe_payment_intent_id' => 'pi_CANARY_' . uniqid(),
            'stripe_charge_id' => 'ch_CANARY_' . uniqid(),
            'donated_at' => '2026-03-04',
        ], $attributes));
    }
}
