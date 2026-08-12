<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactCard;
use App\Models\Masjid;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tenant-isolation guardrail for ContactCard — the mandatory cross-tenant test
 * .claude/rules/tenant-scoping.md requires for every model carrying
 * BelongsToMasjid, which this one shipped without.
 *
 * A card row is donor PII with an unusually high answer-quality: it exists so an
 * admin can ask "who is card 8016?" and be told a member's name. Last-4 digits
 * are not distinctive — two organizations WILL hold the same four digits for two
 * unrelated people — so the tenant column is the only thing standing between
 * that lookup and a wrong, confident answer about somebody else's donor. Every
 * fixture below therefore uses the SAME last4 in both organizations; a leak that
 * only shows up when the values collide is exactly the one a distinct-fixture
 * test would miss.
 *
 * ContactCard has no routes of its own — it reaches HTTP through the contact it
 * belongs to (ContactsController::show discloses `cards`, and ::merge moves them
 * between members). So the HTTP half drives those, and asserts that A's admin
 * can neither read nor absorb B's cards.
 */
class ContactCardTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $tenant;

    private Masjid $masjidA;
    private Masjid $masjidB;

    private User $adminA;

    private Contact $contactA;
    private Contact $contactB;

    private ContactCard $cardA;
    private ContactCard $cardB;

    protected function setUp(): void
    {
        parent::setUp();

        // Force sqlite-in-memory regardless of phpunit.xml.
        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        // The contact routes are behind the granular `permission:` gate.
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->tenant = app(TenantContext::class);
        $this->tenant->forgetTenant();

        $this->masjidA = $this->makeMasjid();
        $this->masjidB = $this->makeMasjid();

        $this->adminA = $this->makeAdminFor($this->masjidA);
        $this->makeAdminFor($this->masjidB);

        // Seeded UNBOUND so the explicit masjid_id is honored.
        $this->contactA = Contact::factory()->create([
            'masjid_id' => $this->masjidA->id,
            'first_name' => 'Amina',
            'last_name' => 'Yusuf',
        ]);
        $this->contactB = Contact::factory()->create([
            'masjid_id' => $this->masjidB->id,
            'first_name' => 'Bilal',
            'last_name' => 'Haddad',
        ]);

        // Same four digits in both organizations, on purpose (see the docblock).
        $this->cardA = $this->makeCard($this->contactA, '8016');
        $this->cardB = $this->makeCard($this->contactB, '8016');
    }

    private function makeMasjid(): Masjid
    {
        return Masjid::create([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'crm_enabled' => true,
        ]);
    }

    private function makeAdminFor(Masjid $masjid): User
    {
        $admin = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);

        $masjid->user_id = $admin->id;
        $masjid->save();

        return $admin;
    }

    private function makeCard(Contact $contact, string $last4, string $brand = 'visa'): ContactCard
    {
        return ContactCard::create([
            'masjid_id' => $contact->masjid_id,
            'contact_id' => $contact->id,
            'last4' => $last4,
            'brand' => $brand,
        ]);
    }

    private function contactsUrl(Masjid $masjid): string
    {
        return "/api/admin/masjids/{$masjid->id}/contacts";
    }

    // =============================================================== model layer

    #[Test]
    public function card_queries_are_scoped_to_the_bound_tenant(): void
    {
        $this->tenant->set($this->masjidA->id);

        $this->assertSame(1, ContactCard::count());
        $this->assertSame($this->cardA->id, ContactCard::first()->id);
    }

    #[Test]
    public function a_bound_tenant_cannot_read_another_organizations_card(): void
    {
        $this->tenant->set($this->masjidA->id);

        $this->assertNull(ContactCard::find($this->cardB->id));
    }

    #[Test]
    public function the_who_is_card_lookup_answers_only_within_the_bound_tenant(): void
    {
        // The reason this model exists. Both organizations hold an 8016; A must
        // be told about Amina and must never be told about Bilal.
        $this->tenant->set($this->masjidA->id);

        $ids = ContactCard::where('last4', '8016')->pluck('contact_id')->all();

        $this->assertSame([$this->contactA->id], $ids);
    }

    #[Test]
    public function card_create_stamps_the_bound_tenant_over_client_input(): void
    {
        $this->tenant->set($this->masjidA->id);

        $card = ContactCard::create([
            // The caller tries to plant the card in masjid B.
            'masjid_id' => $this->masjidB->id,
            'contact_id' => $this->contactA->id,
            'last4' => '3256',
        ]);

        $this->assertSame($this->masjidA->id, $card->masjid_id);
    }

    #[Test]
    public function an_unbound_context_sees_every_organizations_cards(): void
    {
        $this->assertSame(2, ContactCard::count());
    }

    #[Test]
    public function without_masjid_scope_bypasses_the_filter(): void
    {
        $this->tenant->set($this->masjidA->id);

        $this->assertSame(2, ContactCard::withoutMasjidScope()->count());
    }

    #[Test]
    public function the_same_last4_is_allowed_in_two_organizations(): void
    {
        // The unique index is (contact_id, last4), not (last4) — two
        // organizations holding the same four digits is normal, not a conflict.
        $this->assertSame(
            2,
            ContactCard::withoutMasjidScope()->where('last4', '8016')->count()
        );
    }

    #[Test]
    public function a_contacts_cards_relation_never_reaches_across_tenants(): void
    {
        $this->tenant->set($this->masjidA->id);

        $this->assertSame([$this->cardA->id], $this->contactA->cards()->pluck('id')->all());
    }

    // ================================================================ HTTP layer

    #[Test]
    public function show_discloses_only_the_admins_own_contacts_cards(): void
    {
        Sanctum::actingAs($this->adminA);

        $response = $this->getJson($this->contactsUrl($this->masjidA) . "/{$this->contactA->id}")
            ->assertOk();

        $this->assertCount(1, $response->json('data.cards'));
        $this->assertSame($this->cardA->id, $response->json('data.cards.0.id'));
    }

    #[Test]
    public function show_cannot_read_another_masjids_contact_cards(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->getJson($this->contactsUrl($this->masjidA) . "/{$this->contactB->id}")
            ->assertStatus(404);
    }

    #[Test]
    public function naming_another_masjid_in_the_route_is_a_403(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->getJson($this->contactsUrl($this->masjidB) . "/{$this->contactB->id}")
            ->assertStatus(403);
    }

    #[Test]
    public function merge_moves_cards_within_the_tenant(): void
    {
        // The feature the model exists for: an anonymous "Unidentified Card
        // 8016" placeholder is absorbed into a named member, taking its last-4.
        $placeholder = Contact::factory()->create([
            'masjid_id' => $this->masjidA->id,
            'first_name' => 'Unidentified Card',
            'last_name' => '3256',
        ]);
        $placeholderCard = $this->makeCard($placeholder, '3256');

        Sanctum::actingAs($this->adminA);

        $this->postJson($this->contactsUrl($this->masjidA) . "/{$placeholder->id}/merge", [
            'target_contact_id' => $this->contactA->id,
        ])->assertOk();

        // The survivor holds both cards, all of them still masjid A's.
        $cards = ContactCard::withoutMasjidScope()
            ->where('contact_id', $this->contactA->id)
            ->get();

        $this->assertEqualsCanonicalizing(['8016', '3256'], $cards->pluck('last4')->all());
        $this->assertSame([$this->masjidA->id], $cards->pluck('masjid_id')->unique()->values()->all());
        $this->assertDatabaseMissing('contact_cards', ['id' => $placeholderCard->id]);
    }

    #[Test]
    public function merge_cannot_absorb_another_masjids_contact(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->postJson($this->contactsUrl($this->masjidA) . "/{$this->contactB->id}/merge", [
            'target_contact_id' => $this->contactA->id,
        ])->assertStatus(404);

        // B's donor and their card are both untouched.
        $this->assertDatabaseHas('contacts', ['id' => $this->contactB->id]);
        $this->assertDatabaseHas('contact_cards', [
            'id' => $this->cardB->id,
            'contact_id' => $this->contactB->id,
            'masjid_id' => $this->masjidB->id,
        ]);
    }

    #[Test]
    public function merge_cannot_target_another_masjids_contact(): void
    {
        // The other direction: A's own placeholder, but the survivor named in
        // the body belongs to B. Absorbing into it would move A's cards (and
        // giving history) into another organization.
        $placeholder = Contact::factory()->create([
            'masjid_id' => $this->masjidA->id,
            'first_name' => 'Unidentified Card',
            'last_name' => '4477',
        ]);
        $this->makeCard($placeholder, '4477');

        Sanctum::actingAs($this->adminA);

        $this->postJson($this->contactsUrl($this->masjidA) . "/{$placeholder->id}/merge", [
            'target_contact_id' => $this->contactB->id,
        ])->assertStatus(404);

        $this->assertDatabaseHas('contacts', ['id' => $placeholder->id]);
        $this->assertDatabaseHas('contact_cards', [
            'contact_id' => $placeholder->id,
            'last4' => '4477',
            'masjid_id' => $this->masjidA->id,
        ]);
        $this->assertSame(
            0,
            ContactCard::withoutMasjidScope()->where('contact_id', $this->contactB->id)->where('last4', '4477')->count()
        );
    }
}
