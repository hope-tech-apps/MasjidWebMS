<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\Masjid;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * WHAT `POST …/groups/{id}/members` ANSWERS, AND WHAT IT MUST NOT SERVE.
 *
 * ---------------------------------------------------------------------------
 * F3 — THE COMPENSATING SENTENCE FOR AN ACKNOWLEDGED BYPASS
 * ---------------------------------------------------------------------------
 *
 * `store()` is the OTHER door onto the grant `confirm()` guards: typing an entry
 * that already exists as a pending claim CONFIRMS it. That is deliberate and
 * argued in the controller — an administrator who picked one contact out of an
 * addressed list and named the ward has made the individual decision the
 * contested path asks for. What they have NOT necessarily been told is that a
 * second adult of the same name is also claiming this child, so the response
 * carries a warning, and `.claude/rules/groups.md` promises "that response now
 * SAYS when the entry it confirmed has a same-named rival over the same child."
 *
 * The server said it. `groupsStore.ts::addMembership` returned `res.data.data`
 * and dropped `message`; `GroupRosterTab.vue` fired a hardcoded
 * `{icon:'success', title:'Added'}` on a 1600ms timer. A promise kept on the
 * wire and broken on the screen is not kept. This file pins the WIRE half — the
 * client half is `groupsStore.ts` returning `AddMembershipResult` and
 * `submitAdd()` rendering it, which `npm run build` type-checks.
 *
 * ---------------------------------------------------------------------------
 * F4 — THE ROUND'S OWN FIX, HALF-APPLIED TWENTY LINES FROM ITSELF
 * ---------------------------------------------------------------------------
 *
 * `index()` narrows the related people to `id,first_name,last_name,email,phone`
 * with a docblock about credential columns leaking. `store()` still served
 * `->load(['contact', …])` on both of its responses: `login_email`,
 * `login_enabled_at`, `login_revoked_at`, `last_login_at`, the CRM notes and all
 * four SMS-consent fields. Same audience and the same tenant, so never an
 * escalation — and .claude/rules/credentials.md keeps those columns out of
 * request bodies, so a roster verb is not where they belong on the way out.
 */
class RosterAddResponseTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjid;

    private User $admin;

    private Group $grade3;

    private Contact $child;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->masjid = Masjid::create([
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

        $this->admin = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
        $this->masjid->user_id = $this->admin->id;
        $this->masjid->save();

        $this->grade3 = Group::factory()->create([
            'masjid_id' => $this->masjid->id,
            'kind' => Group::KIND_CLASS,
            'name' => 'Grade 3',
        ]);

        $this->child = Contact::factory()->create([
            'masjid_id' => $this->masjid->id,
            'first_name' => 'Fatima',
            'last_name' => 'Ahmed',
            'email' => null,
        ]);

        $row = new GroupMembership([
            'masjid_id' => $this->masjid->id,
            'group_id' => $this->grade3->id,
            'contact_id' => $this->child->id,
            'role' => GroupMembership::ROLE_MEMBER,
        ]);
        $row->confirmedByStaff($this->admin)->save();
    }

    // ==================================================================== F3

    #[Test]
    public function confirming_a_claim_through_the_add_verb_names_the_same_named_rival(): void
    {
        $mother = $this->guardianClaim('Aisha', 'Ahmed', 'aisha@household.test');
        $stranger = $this->guardianClaim('Aisha', 'Ahmed', 'bilal@evil.test');

        Sanctum::actingAs($this->admin);

        // The sweep refuses these two: one ward, two claimants, one displayed
        // name. That refusal is the thing this response has to compensate for.
        $this->assertContains((int) $stranger->id, $this->contestedIds());
        $this->assertContains((int) $mother->id, $this->contestedIds());

        $response = $this->postJson($this->adminUrl("/groups/{$this->grade3->id}/members"), [
            'contact_id' => $stranger->contact_id,
            'role' => GroupMembership::ROLE_GUARDIAN,
            'guardian_of_contact_id' => $this->child->id,
        ])->assertStatus(200);

        $message = (string) $response->json('message');

        $this->assertStringContainsString('now confirmed by you', $message);
        $this->assertStringContainsString('same person under the same name', $message);
        $this->assertStringContainsString('Check the addresses', $message);

        // The row really was confirmed — the warning is a warning, not a refusal.
        $this->assertTrue($stranger->fresh()->isConfirmed());
        $this->assertTrue($mother->fresh()->isPendingClaim());
    }

    #[Test]
    public function an_uncontested_add_says_nothing_extra(): void
    {
        // WHAT THIS MUST NOT DO: turn every ordinary roster add into a dialog
        // somebody has to dismiss. A row with no same-named rival gets the
        // 201 and no message, which is what keeps the warning meaning something.
        $parent = Contact::factory()->create([
            'masjid_id' => $this->masjid->id,
            'first_name' => 'Khadija',
            'last_name' => 'Ahmed',
            'email' => 'khadija@household.test',
        ]);

        Sanctum::actingAs($this->admin);

        $this->postJson($this->adminUrl("/groups/{$this->grade3->id}/members"), [
            'contact_id' => $parent->id,
            'role' => GroupMembership::ROLE_GUARDIAN,
            'guardian_of_contact_id' => $this->child->id,
        ])
            ->assertStatus(201)
            ->assertJsonMissingPath('message');
    }

    #[Test]
    public function confirming_a_lone_claim_through_the_add_verb_carries_no_rival_sentence(): void
    {
        $only = $this->guardianClaim('Aisha', 'Ahmed', 'aisha@household.test');

        Sanctum::actingAs($this->admin);

        $message = (string) $this->postJson($this->adminUrl("/groups/{$this->grade3->id}/members"), [
            'contact_id' => $only->contact_id,
            'role' => GroupMembership::ROLE_GUARDIAN,
            'guardian_of_contact_id' => $this->child->id,
        ])->assertStatus(200)->json('message');

        $this->assertStringContainsString('now confirmed by you', $message);
        $this->assertStringNotContainsString('same person under the same name', $message);
    }

    // ==================================================================== F4

    #[Test]
    public function neither_add_response_serves_a_contacts_credential_columns(): void
    {
        $parent = Contact::factory()->create([
            'masjid_id' => $this->masjid->id,
            'first_name' => 'Khadija',
            'last_name' => 'Ahmed',
            'email' => 'khadija@household.test',
            'notes' => 'Do not call before 9am — CRM note',
        ]);

        // A live credential, so the columns actually carry something and an
        // assertion on their absence is not passing on a null.
        $parent->forceFill([
            'login_email' => 'khadija@household.test',
            'login_enabled_at' => now(),
            'last_login_at' => now(),
        ])->save();

        Sanctum::actingAs($this->admin);

        $created = $this->postJson($this->adminUrl("/groups/{$this->grade3->id}/members"), [
            'contact_id' => $parent->id,
            'role' => GroupMembership::ROLE_GUARDIAN,
            'guardian_of_contact_id' => $this->child->id,
        ])->assertStatus(201);

        $this->assertNarrowed($created->json('data.contact'));

        // …and on the 200 branch, which is the one that CONFIRMS a claim and so
        // is the one an operator reaches most often on a contested roster.
        $claim = $this->guardianClaim('Aisha', 'Ahmed', 'aisha@household.test');

        $claimant = Contact::withoutMasjidScope()->whereKey($claim->contact_id)->firstOrFail();
        $claimant->forceFill([
            'login_email' => 'aisha@household.test',
            'login_enabled_at' => now(),
        ])->save();

        $confirmed = $this->postJson($this->adminUrl("/groups/{$this->grade3->id}/members"), [
            'contact_id' => $claim->contact_id,
            'role' => GroupMembership::ROLE_GUARDIAN,
            'guardian_of_contact_id' => $this->child->id,
        ])->assertStatus(200);

        $this->assertNarrowed($confirmed->json('data.contact'));
    }

    #[Test]
    public function the_add_response_still_carries_what_the_screen_renders(): void
    {
        // The other half of the same guard: narrowing must not take the bytes
        // the roster actually draws. `email` and `phone` are load-bearing —
        // they are the only rendered thing separating two claims over one child
        // made under one name.
        $parent = Contact::factory()->create([
            'masjid_id' => $this->masjid->id,
            'first_name' => 'Khadija',
            'last_name' => 'Ahmed',
            'email' => 'khadija@household.test',
            'phone' => '+15551239876',
        ]);

        Sanctum::actingAs($this->admin);

        $this->postJson($this->adminUrl("/groups/{$this->grade3->id}/members"), [
            'contact_id' => $parent->id,
            'role' => GroupMembership::ROLE_GUARDIAN,
            'guardian_of_contact_id' => $this->child->id,
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.contact.first_name', 'Khadija')
            ->assertJsonPath('data.contact.email', 'khadija@household.test')
            ->assertJsonPath('data.contact.phone', '+15551239876')
            ->assertJsonPath('data.guardian_of.first_name', 'Fatima')
            ->assertJsonPath('data.confirmed_by.name', $this->admin->name);
    }

    // ============================================================== helpers

    /** @param array<string,mixed>|null $contact */
    private function assertNarrowed(?array $contact): void
    {
        $this->assertIsArray($contact);

        foreach ([
            'login_email',
            'login_enabled_at',
            'login_revoked_at',
            'last_login_at',
            'notes',
            'sms_consent_status',
            'sms_consent_at',
            'sms_consent_source',
            'sms_consent_ip',
        ] as $column) {
            $this->assertArrayNotHasKey(
                $column,
                $contact,
                "the roster add response served contacts.{$column}",
            );
        }
    }

    /** @return array<int,int> */
    private function contestedIds(): array
    {
        return collect($this->getJson($this->adminUrl("/groups/{$this->grade3->id}/members"))->json('data'))
            ->filter(fn (array $row): bool => ($row['claim']['contested'] ?? false) === true)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function guardianClaim(string $first, string $last, ?string $email): GroupMembership
    {
        $contact = Contact::factory()->create([
            'masjid_id' => $this->masjid->id,
            'first_name' => $first,
            'last_name' => $last,
            'email' => $email,
        ]);

        $claim = new GroupMembership([
            'masjid_id' => $this->masjid->id,
            'group_id' => $this->grade3->id,
            'contact_id' => $contact->id,
            'role' => GroupMembership::ROLE_GUARDIAN,
            'guardian_of_contact_id' => $this->child->id,
        ]);

        $claim->selfAssertedFrom(null)->save();

        return $claim;
    }

    private function adminUrl(string $path = ''): string
    {
        return "/api/admin/masjids/{$this->masjid->id}" . $path;
    }
}
