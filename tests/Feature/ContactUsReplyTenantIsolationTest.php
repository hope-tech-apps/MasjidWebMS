<?php

namespace Tests\Feature;

use App\Models\ContactUsAccount;
use App\Models\ContactUsMessage;
use App\Models\ContactUsReply;
use App\Models\Masjid;
use App\Models\MobileAppUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Cross-TENANT isolation for `ContactUsReply` — what staff wrote back to
 * somebody who used the contact form (T-042d).
 *
 * `.claude/rules/tenant-scoping.md` requires a cross-tenant Feature test for
 * every new model in a tenant's trust domain, hence the file name and the
 * `<Model>TenantIsolationTest` convention. This one differs from its siblings in
 * a way that is the whole point of the file:
 *
 * **`contact_us_replies` carries no `masjid_id` and uses no global scope.** Its
 * tenancy is entirely derived — a reply hangs off a ContactUsMessage, which is
 * hand-scoped three joins deep through contacter -> mobileAppUser ->
 * masjid_id, because the public mobile API never binds a tenant for a global
 * scope to read (MobileAppUser is on TenantScopingCoverageTest's
 * HAND_SCOPED_LEGACY list for exactly that reason).
 *
 * Derived tenancy is weaker than a global scope: there is no database
 * constraint, no `creating` hook, and nothing in MySQL that would notice a
 * controller forgetting the join. So the guarantee has to be asserted at the
 * only place it exists — the HTTP surface — and it is asserted on EVERY verb,
 * because "the new verb forgot the join" is the specific way this breaks.
 *
 * A contact-us reply is a member of the public's name, address and free text,
 * plus the organisation they chose to write to. Leaking one across the boundary
 * discloses all four.
 */
class ContactUsReplyTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $a;
    private Masjid $b;
    private User $adminA;

    private ContactUsMessage $messageB;
    private ContactUsReply $replyB;

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

        $this->a = $this->makeMasjid();
        $this->b = $this->makeMasjid();

        $this->adminA = $this->makeAdminFor($this->a);
        $this->makeAdminFor($this->b);

        $this->messageB = $this->seedMessage($this->b);

        $this->replyB = ContactUsReply::create([
            'contact_us_message_id' => $this->messageB->id,
            'body' => 'Masjid B told this person to come on Friday.',
            'sent_to' => 'sender-b@example.invalid',
            'actor_user_id' => null,
            'actor_name' => 'Masjid B Staff',
            'actor_email' => 'staff-b@example.invalid',
            'idempotency_key' => 'b-one',
            'sent_at' => now(),
        ]);
    }

    #[Test]
    public function the_replies_table_carries_no_masjid_id_so_its_tenancy_is_derived(): void
    {
        // The premise every other assertion in this file rests on, stated so it
        // cannot change silently. If a `masjid_id` is ever added here, this test
        // fails and TenantScopingCoverageTest layer 4 will then demand
        // BelongsToMasjid — whose global scope would rewrite the hand-written
        // queries in ContactRequestsController AND in the two UNAUTHENTICATED
        // intake controllers, where no tenant is ever bound.
        $this->assertFalse(Schema::hasColumn('contact_us_replies', 'masjid_id'));
    }

    #[Test]
    public function an_admin_never_sees_another_organizations_reply_in_the_listing(): void
    {
        Sanctum::actingAs($this->adminA);

        $body = $this->getJson('/api/admin/masjids/' . $this->a->id . '/contact-requests')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Masjid B told this person', $body);
        $this->assertStringNotContainsString('sender-b@example.invalid', $body);
    }

    #[Test]
    public function reading_another_organizations_message_is_a_miss_not_a_filtered_result(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->getJson('/api/admin/masjids/' . $this->a->id . '/contact-requests/' . $this->messageB->id)
            ->assertStatus(404);
    }

    #[Test]
    public function no_write_verb_can_reach_another_organizations_message(): void
    {
        // Every verb, because the way derived tenancy fails is a NEW verb that
        // queries ContactUsMessage without the join. Reply and answered are the
        // two T-042d added.
        Mail::fake();
        Sanctum::actingAs($this->adminA);

        $base = '/api/admin/masjids/' . $this->a->id . '/contact-requests/' . $this->messageB->id;

        $this->postJson($base . '/reply', [
            'reply' => 'Not mine to answer.',
            'idempotency_key' => 'intruder',
        ])->assertStatus(404);

        $this->patchJson($base . '/answered', ['answered' => '1'])->assertStatus(404);

        $this->deleteJson($base)->assertStatus(404);

        Mail::assertNothingSent();

        // Nothing was written, and B's own reply is untouched.
        $this->assertSame(1, ContactUsReply::count());
        $this->assertNull($this->messageB->refresh()->answered_at);
        $this->assertSame('Masjid B told this person to come on Friday.', $this->replyB->refresh()->body);
    }

    #[Test]
    public function targeting_another_organizations_route_is_a_403_before_the_controller_runs(): void
    {
        // The other half of the boundary: a MasjidAdmin naming B in the ROUTE is
        // refused by ResolveMasjidTenant, never by the query.
        Sanctum::actingAs($this->adminA);

        $this->getJson('/api/admin/masjids/' . $this->b->id . '/contact-requests')
            ->assertStatus(403);
    }

    #[Test]
    public function deleting_a_message_takes_its_replies_with_it(): void
    {
        // The FK cascade is the only thing keeping a reply from outliving the
        // message it was scoped through — an orphaned reply would have no tenant
        // at all.
        $messageA = $this->seedMessage($this->a);

        ContactUsReply::create([
            'contact_us_message_id' => $messageA->id,
            'body' => 'Masjid A answered.',
            'sent_to' => 'sender-b@example.invalid',
            'actor_name' => 'Masjid A Staff',
            'idempotency_key' => 'a-one',
            'sent_at' => now(),
        ]);

        Sanctum::actingAs($this->adminA);

        $this->deleteJson('/api/admin/masjids/' . $this->a->id . '/contact-requests/' . $messageA->id)
            ->assertOk();

        $this->assertSame(0, ContactUsReply::where('contact_us_message_id', $messageA->id)->count());
        // B's reply is untouched.
        $this->assertSame(1, ContactUsReply::count());
    }

    // ------------------------------------------------------------- helpers

    private function seedMessage(Masjid $masjid): ContactUsMessage
    {
        $device = MobileAppUser::create([
            'device_id' => 'device-' . uniqid(),
            'masjid_id' => $masjid->id,
            'user_agent' => 'test',
        ]);

        $account = ContactUsAccount::create([
            'mobile_app_user_id' => $device->id,
            'email' => 'sender-b@example.invalid',
            'name' => 'Sender',
            'phone' => '+15550000001',
        ]);

        return ContactUsMessage::create([
            'contact_us_account_id' => $account->id,
            'contact_us_reason_id' => null,
            'message' => 'Assalamu alaikum.',
        ]);
    }

    private function makeMasjid(): Masjid
    {
        return Masjid::create([
            'name' => 'Org ' . uniqid(),
            'email' => 'org-' . uniqid() . '@example.invalid',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
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
}
