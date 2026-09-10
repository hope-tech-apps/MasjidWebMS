<?php

namespace Tests\Feature;

use App\Models\Broadcast;
use App\Models\Contact;
use App\Models\Masjid;
use App\Models\MealMenu;
use App\Models\MealMenuItem;
use App\Models\MealOrder;
use App\Models\Service;
use App\Models\SmsSuppression;
use App\Models\User;
use App\Services\Lunch\LunchSmsOptIn;
use App\Services\Sms\SmsConsentService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * "Text me when Jummah lunch ordering opens again" — the opt-in on the order
 * form, and the single announcement when a menu opens.
 *
 * Two properties carry the whole feature, and both are about NOT sending:
 *
 *  1. Opening is a status an admin toggles, and toggling it repeatedly must not
 *     text the list repeatedly. Messaging people twice for one lunch is the
 *     fastest route to a STOP and a suspended 10DLC campaign.
 *  2. A number on the durable suppression list cannot be re-consented by a web
 *     form. Only the subscriber, texting START, can undo their own STOP.
 *
 * Nothing here asserts that a message reached a handset: the SMS provider is
 * unset in tests exactly as it is in production, and SmsChannel's refusal to
 * send without a carrier-approved 10DLC sender is its own tested behaviour. What
 * is asserted is that the right RECORD is written, to the right audience, once.
 */
class LunchSmsNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjid;

    private Service $lunchService;

    private MealMenu $menu;

    private MealMenuItem $plate;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        app(TenantContext::class)->forgetTenant();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->masjid = Masjid::create([
            'name' => 'SMS Lunch ' . uniqid(),
            'email' => 'office' . uniqid() . '@masjid.test',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'crm_enabled' => true, 'org_type' => 'masjid',
        ]);

        $this->lunchService = Service::create([
            'masjid_id' => $this->masjid->id,
            'title' => 'Jummah Lunch',
            'description' => 'Weekly lunch after Jummah.',
            'text' => 'Weekly lunch after Jummah.',
        ]);

        $this->menu = MealMenu::factory()->forMasjid($this->masjid)->open()->create([
            'allow_sms_optin' => true,
            'notify_service_id' => $this->lunchService->id,
        ]);

        $this->plate = MealMenuItem::factory()->create([
            'masjid_id' => $this->masjid->id, 'meal_menu_id' => $this->menu->id,
            'name' => 'Kabsah Plate', 'price_minor' => 800,
        ]);

        $this->admin = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
        $this->masjid->user_id = $this->admin->id;
        $this->masjid->save();
    }

    private function header(): array
    {
        return ['masjid-id' => (string) $this->masjid->id];
    }

    private function orderBody(array $overrides = []): array
    {
        return array_merge([
            'menu_uuid' => $this->menu->uuid,
            'items' => [['item_id' => $this->plate->id, 'quantity' => 1]],
            'customer_name' => 'Aisha Rahman',
            'customer_phone' => '(704) 555-0142',
            'payment_method' => MealOrder::METHOD_PICKUP,
        ], $overrides);
    }

    private function adminBase(): string
    {
        return '/api/admin/masjids/' . $this->masjid->id . '/jummah-lunch';
    }

    // ------------------------------------------------------------- opting in

    #[Test]
    public function ticking_the_box_records_consent_and_the_subscription(): void
    {
        $this->postJson('/api/v1/lunch-orders', $this->orderBody(['notify_sms' => true]), $this->header())
            ->assertOk();

        $contact = Contact::withoutMasjidScope()->where('masjid_id', $this->masjid->id)->first();

        $this->assertNotNull($contact);
        $this->assertTrue((bool) $contact->sms_opt_in);
        $this->assertNotNull($contact->sms_consent_at);
        $this->assertSame('web_form', $contact->sms_consent_source);
        $this->assertSame('jummah_lunch', $contact->signup_source);
        // The number is stored normalised, because consent attaches to a number.
        $this->assertSame('+17045550142', $contact->phone);

        // The EVIDENCE is the sentence the customer agreed to, verbatim.
        $this->assertSame(LunchSmsOptIn::DISCLOSURE, $contact->sms_consent_evidence);
        $this->assertStringContainsString('Consent is not a condition of purchase', $contact->sms_consent_evidence);
        $this->assertStringContainsString('Reply STOP to cancel', $contact->sms_consent_evidence);

        $this->assertDatabaseHas('contact_service_interests', [
            'masjid_id' => $this->masjid->id,
            'contact_id' => $contact->id,
            'service_id' => $this->lunchService->id,
        ]);
    }

    #[Test]
    public function not_ticking_the_box_records_nothing_at_all(): void
    {
        $this->postJson('/api/v1/lunch-orders', $this->orderBody(), $this->header())->assertOk();

        $this->assertSame(0, Contact::withoutMasjidScope()->count());
        $this->assertSame(0, DB::table('contact_service_interests')->count());
    }

    #[Test]
    public function ordering_three_weeks_running_makes_one_contact_and_one_subscription(): void
    {
        foreach (range(1, 3) as $_) {
            $this->postJson('/api/v1/lunch-orders', $this->orderBody(['notify_sms' => true]), $this->header())
                ->assertOk();
        }

        $this->assertSame(1, Contact::withoutMasjidScope()->count());
        $this->assertSame(1, DB::table('contact_service_interests')->count());
        $this->assertSame(3, MealOrder::withoutMasjidScope()->count());
    }

    #[Test]
    public function a_number_that_texted_stop_cannot_be_reopted_in_by_a_web_form(): void
    {
        // The durable list, keyed on the number and outliving any contact row.
        SmsSuppression::create([
            'masjid_id' => $this->masjid->id,
            'phone_e164' => '+17045550142',
            'reason' => SmsSuppression::REASON_STOP_KEYWORD,
            'suppressed_at' => now(),
        ]);

        $this->postJson('/api/v1/lunch-orders', $this->orderBody(['notify_sms' => true]), $this->header())
            ->assertOk();

        // The order still stands; only the consent was refused.
        $this->assertSame(1, MealOrder::withoutMasjidScope()->count());
        $this->assertSame(0, DB::table('contact_service_interests')->count());

        $contact = Contact::withoutMasjidScope()->first();
        $this->assertTrue($contact === null || ! $contact->sms_opt_in);
    }

    #[Test]
    public function a_menu_with_the_offer_off_ignores_a_crafted_opt_in(): void
    {
        $this->menu->update(['allow_sms_optin' => false]);

        $this->postJson('/api/v1/lunch-orders', $this->orderBody(['notify_sms' => true]), $this->header())
            ->assertOk();

        $this->assertSame(0, DB::table('contact_service_interests')->count());
    }

    #[Test]
    public function an_unusable_phone_number_never_costs_the_customer_their_order(): void
    {
        $this->postJson('/api/v1/lunch-orders', $this->orderBody([
            'customer_phone' => '123',
            'notify_sms' => true,
        ]), $this->header())->assertOk();

        $this->assertSame(1, MealOrder::withoutMasjidScope()->count());
        $this->assertSame(0, DB::table('contact_service_interests')->count());
    }

    #[Test]
    public function the_menu_payload_carries_the_offer_and_the_exact_disclosure(): void
    {
        $this->getJson('/api/v1/lunch-menu', $this->header())
            ->assertOk()
            ->assertJsonPath('data.menu.allow_sms_optin', true)
            ->assertJsonPath('data.menu.sms_disclosure', LunchSmsOptIn::DISCLOSURE);
    }

    #[Test]
    public function the_offer_is_withheld_when_no_service_has_been_chosen(): void
    {
        // Consent that could never be acted on should not be collected.
        $this->menu->update(['notify_service_id' => null]);

        $this->getJson('/api/v1/lunch-menu', $this->header())
            ->assertOk()
            ->assertJsonPath('data.menu.allow_sms_optin', false);
    }

    // ------------------------------------------------------- the announcement

    #[Test]
    public function opening_a_menu_announces_it_once_to_the_service_audience(): void
    {
        $menu = MealMenu::factory()->forMasjid($this->masjid)->create([
            'status' => MealMenu::STATUS_DRAFT,
            'service_date' => '2027-03-05',
            'notify_service_id' => $this->lunchService->id,
        ]);

        Sanctum::actingAs($this->admin);

        $this->putJson($this->adminBase() . '/menus/' . $menu->id, [
            'status' => MealMenu::STATUS_OPEN,
        ])->assertOk();

        $broadcasts = Broadcast::withoutGlobalScopes()->get();
        $this->assertCount(1, $broadcasts);

        $b = $broadcasts->first();
        $this->assertSame('service', $b->audience);
        $this->assertSame($this->lunchService->id, (int) $b->audience_service_id);
        $this->assertStringContainsString('/jummah-lunch/' . $this->masjid->id, (string) $b->link);
        $this->assertStringContainsString('open for orders', (string) $b->body);

        $this->assertNotNull($menu->fresh()->opening_notified_at);
    }

    #[Test]
    public function closing_and_reopening_never_sends_a_second_text(): void
    {
        // The reason this feature has a guard at all: an admin fixing a typo
        // goes open -> draft -> open, and that is one lunch, not three.
        $menu = MealMenu::factory()->forMasjid($this->masjid)->create([
            'status' => MealMenu::STATUS_DRAFT,
            'service_date' => '2027-03-12',
            'notify_service_id' => $this->lunchService->id,
        ]);

        Sanctum::actingAs($this->admin);
        $url = $this->adminBase() . '/menus/' . $menu->id;

        $this->putJson($url, ['status' => MealMenu::STATUS_OPEN])->assertOk();
        $this->putJson($url, ['status' => MealMenu::STATUS_DRAFT])->assertOk();
        $this->putJson($url, ['status' => MealMenu::STATUS_OPEN])->assertOk();
        $this->putJson($url, ['status' => MealMenu::STATUS_CLOSED])->assertOk();
        $this->putJson($url, ['status' => MealMenu::STATUS_OPEN])->assertOk();

        $this->assertCount(1, Broadcast::withoutGlobalScopes()->get());
    }

    #[Test]
    public function a_menu_with_no_service_chosen_stays_silent(): void
    {
        // Fail-safe: never guess an audience.
        $menu = MealMenu::factory()->forMasjid($this->masjid)->create([
            'status' => MealMenu::STATUS_DRAFT,
            'service_date' => '2027-03-19',
            'notify_service_id' => null,
        ]);

        Sanctum::actingAs($this->admin);

        $this->putJson($this->adminBase() . '/menus/' . $menu->id, [
            'status' => MealMenu::STATUS_OPEN,
        ])->assertOk();

        $this->assertSame(0, Broadcast::withoutGlobalScopes()->count());
        $this->assertNull($menu->fresh()->opening_notified_at);
    }

    #[Test]
    public function a_draft_menu_announces_nothing(): void
    {
        $menu = MealMenu::factory()->forMasjid($this->masjid)->create([
            'status' => MealMenu::STATUS_DRAFT,
            'service_date' => '2027-03-26',
            'notify_service_id' => $this->lunchService->id,
        ]);

        Sanctum::actingAs($this->admin);

        $this->putJson($this->adminBase() . '/menus/' . $menu->id, [
            'title' => 'Renamed while still a draft',
        ])->assertOk();

        $this->assertSame(0, Broadcast::withoutGlobalScopes()->count());
    }

    #[Test]
    public function another_masjids_service_cannot_be_targeted(): void
    {
        // Otherwise a crafted id aims this masjid's announcement at somebody
        // else's interest list.
        $other = Masjid::create([
            'name' => 'Other ' . uniqid(),
            'email' => 'o' . uniqid() . '@masjid.test',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '2 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'crm_enabled' => true, 'org_type' => 'masjid',
        ]);
        $foreign = Service::create([
            'masjid_id' => $other->id,
            'title' => 'Someone elses service',
            'description' => 'x', 'text' => 'x',
        ]);

        Sanctum::actingAs($this->admin);

        $this->putJson($this->adminBase() . '/menus/' . $this->menu->id, [
            'notify_service_id' => $foreign->id,
        ])->assertStatus(422)->assertJsonStructure(['data' => ['notify_service_id']]);
    }

    // ------------------------------------------------ consent is still consent

    #[Test]
    public function the_consent_timestamp_is_server_time_not_client_supplied(): void
    {
        // A consent date a client can set is a consent date a client can
        // backdate; SmsConsentService owns the clock. The menu is pinned open
        // past the travelled-to date so this tests the clock, not the cutoff.
        $this->menu->update(['service_date' => '2027-01-08', 'ordering_closes_at' => '2027-01-08 16:00:00']);
        $this->travelTo('2027-01-01 12:00:00');

        $this->postJson('/api/v1/lunch-orders', $this->orderBody(['notify_sms' => true]), $this->header())
            ->assertOk();

        $contact = Contact::withoutMasjidScope()->first();
        $this->assertSame('2027-01-01', $contact->sms_consent_at->toDateString());

        $this->travelBack();
    }

    #[Test]
    public function the_evidence_column_can_actually_hold_the_disclosure(): void
    {
        // This is asserted on the column TYPE, not by round-tripping a value,
        // because SQLite does not enforce varchar lengths at all. A green suite
        // once sat on top of a varchar(255) column that MySQL rejected in
        // production — silently, because the opt-in swallows its own failures.
        // A round-trip test would have passed then too, and still would.
        $this->assertSame('text', Schema::getColumnType('contacts', 'sms_consent_evidence'));

        // And this is why it has to be text: any wording carrying the required
        // identity / frequency / not-a-condition / rates / STOP disclosures runs
        // past 255 characters.
        $this->assertGreaterThan(255, strlen(LunchSmsOptIn::DISCLOSURE));
    }

    #[Test]
    public function an_existing_contact_is_reused_rather_than_duplicated(): void
    {
        $existing = new Contact(['first_name' => 'Aisha', 'last_name' => 'Rahman', 'phone' => '+17045550142']);
        $existing->masjid_id = $this->masjid->id;
        $existing->save();

        $this->postJson('/api/v1/lunch-orders', $this->orderBody(['notify_sms' => true]), $this->header())
            ->assertOk();

        $this->assertSame(1, Contact::withoutMasjidScope()->count());
        $this->assertTrue((bool) $existing->fresh()->sms_opt_in);
        // A curated name is not overwritten by whatever was typed on the form.
        $this->assertSame('Aisha', $existing->fresh()->first_name);
    }
}
