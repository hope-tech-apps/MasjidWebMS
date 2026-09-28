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
