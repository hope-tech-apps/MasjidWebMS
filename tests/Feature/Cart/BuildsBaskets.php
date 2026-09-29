<?php

namespace Tests\Feature\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Form;
use App\Models\Fund;
use App\Models\Masjid;
use App\Models\MealMenu;
use App\Models\MealMenuItem;

/**
 * Builders for baskets and the things in them. Everything is created UNBOUND with
 * an explicit masjid_id, exactly as the public basket pages will run.
 */
trait BuildsBaskets
{
    protected function org(array $overrides = []): Masjid
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

    protected function fund(Masjid $org, bool $active = true): Fund
    {
        return Fund::create(['masjid_id' => $org->id, 'name' => 'Zakat-ul-Fitr', 'type' => 'fitra', 'is_active' => $active]);
    }

    protected function ticketForm(Masjid $org, array $overrides = []): Form
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

    /**
     * Iftar sponsorship levels (Ramadan giving, DECISIONS 2026-09-25): "Individual Iftar" is $18 a
     * person and reserves nothing; "Quarter Iftar" is $450 and reserves one of `$dates`.
     *
     * @param  array<int,string>  $dates
     */
    protected function iftarForm(Masjid $org, array $dates = ['2027-02-10', '2027-02-11'], array $overrides = []): Form
    {
        return Form::factory()->create(array_merge([
            'masjid_id' => $org->id,
            'name' => 'Iftar Sponsorship',
            'is_active' => true,
            'closes_at' => null,
            'schema' => ['sections' => [['id' => 'sponsor', 'title' => 'Sponsor', 'fields' => [
                ['name' => 'fullName', 'label' => 'Name', 'type' => 'text', 'required' => true],
                ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true],
                ['name' => 'sponsorship', 'label' => 'Sponsorship', 'type' => 'radio', 'required' => true, 'options' => [
                    ['value' => 'individual', 'label' => 'Individual Iftar'],
                    ['value' => 'quarter', 'label' => 'Quarter Iftar'],
                ]],
                ['name' => 'people', 'label' => 'Number of people', 'type' => 'number', 'min' => 1, 'max' => 50],
                ['name' => 'iftar_date', 'label' => 'Date', 'type' => 'select', 'optionsSource' => 'reservable_dates'],
            ]]]],
            'settings' => [
                'identity' => ['name' => 'fullName', 'email' => 'email'],
                'fee' => [
                    'currency' => 'USD',
                    'perQuantityOf' => 'people',
                    'byChoice' => ['field' => 'sponsorship', 'prices' => [
                        ['value' => 'individual', 'amount' => 18, 'perQuantity' => true],
                        ['value' => 'quarter', 'amount' => 450, 'reservesDate' => true],
                    ]],
                ],
                'reservation' => ['field' => 'iftar_date', 'dates' => $dates],
                'payment' => ['online' => true, 'officePayment' => false],
            ],
        ], $overrides));
    }

    /** @return array<string,mixed> a Quarter Iftar, which reserves `$date` from the form's list */
    protected function quarterIftar(string $date = '2027-02-10'): array
    {
        return [
            'fullName' => 'Amal Sponsor',
            'email' => 'amal@example.test',
            'sponsorship' => 'quarter',
            'iftar_date' => $date,
        ];
    }

    /** @return array<string,mixed> an Individual Iftar for three, which reserves no date */
    protected function individualIftar(int $people = 3): array
    {
        return [
            'fullName' => 'Amal Sponsor',
            'email' => 'amal@example.test',
            'sponsorship' => 'individual',
            'people' => (string) $people,
        ];
    }

    protected function dish(Masjid $org, array $overrides = []): MealMenuItem
    {
        $menu = MealMenu::create([
            'masjid_id' => $org->id, 'title' => 'Halal Kitchen', 'kind' => MealMenu::KIND_CATALOGUE,
            'status' => MealMenu::STATUS_OPEN, 'allow_online_payment' => true,
        ]);

        return MealMenuItem::create(array_merge([
            'masjid_id' => $org->id, 'meal_menu_id' => $menu->id,
            'name' => 'Baked Lamb', 'price_minor' => 1200, 'is_available' => true,
        ], $overrides));
    }

    protected function cart(Masjid $org, ?int $contactId = null): Cart
    {
        return Cart::withoutMasjidScope()->create([
            'masjid_id' => $org->id,
            'contact_id' => $contactId,
            'token_hash' => hash('sha256', uniqid('', true)),
        ]);
    }

    protected function add(Cart $cart, string $type, int $buyableId, int $shown, int $qty = 1, array $payload = []): CartItem
    {
        return CartItem::withoutMasjidScope()->create([
            'cart_id' => $cart->id,
            'masjid_id' => $cart->masjid_id,
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

    protected function twoTickets(): array
    {
        return ['tickets' => [['attendeeName' => 'A'], ['attendeeName' => 'B']]];
    }
}
