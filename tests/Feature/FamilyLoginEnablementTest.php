<?php

namespace Tests\Feature;

use App\Mail\FamilyLoginCodeMail;
use App\Models\BehaviorAward;
use App\Models\BehaviorSkill;
use App\Models\Contact;
use App\Models\ContactLoginEvent;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\Masjid;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * T-015d (admin half) — THE ON-SWITCH, and everything it must not become.
 *
 * Before this slice the parent portal was complete and unreachable: the `family`
 * guard, `contact_login_codes`, the OTP endpoints, `GroupAudience`'s guardian
 * branch and the whole read surface all existed, but NOTHING in the application
 * ever wrote `contacts.login_enabled_at`. Production carried 487 contacts, 0
 * with a `login_email`, 0 enabled — not one parent could sign in.
 *
 * Four properties are load-bearing here and each is asserted rather than
 * described:
 *
 *  1. **The credential address is CHOSEN, never derived.** Enabling must not
 *     copy `contacts.email` — an imported, shared, unverified household address
 *     that `GroupAudience` already reads as a STAFF identity bridge.
 *  2. **One address, one contact, per tenant, case-insensitively.**
 *     `FamilyLoginService::resolveContact()` requires EXACTLY ONE match and
 *     resolves an ambiguity to NOBODY — silently, with the same 202 a stranger
 *     gets. A duplicate therefore does not produce an error anybody sees; it
 *     produces a parent who can never sign in and an office that cannot tell
 *     why. Production MySQL is utf8mb4_bin, so the unique index alone does not
 *     stop `Parent@x.com` beside `parent@x.com`.
 *  3. **Revocation reaches a token that is already in a phone.** Twice over,
 *     and the two mechanisms are tested separately so neither can pass on the
 *     other's behalf.
 *  4. **Every act is on the record.** What is being granted is a stranger's
 *     view of a specific child's photographs and safeguarding conversations;
 *     "it was on" is not an answer to "who turned it on?".
 *
 * The last case is an END-TO-END pass through both realms in one test: an admin
 * enables a contact, that contact requests a code, redeems it, reads their own
 * child's records — and is refused the other family's child in the same
 * classroom.
 */
class FamilyLoginEnablementTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjidA;
    private Masjid $masjidB;
    private User $adminA;
    private User $adminB;

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

        // Seed the bridged roles BEFORE the admins, so each MasjidAdmin is
        // bridged to `masjid-admin` (which holds the full CRM permission set) on
        // save — exactly as production does after the seeder runs.
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->masjidA = $this->makeMasjid();
        $this->masjidB = $this->makeMasjid();

        $this->adminA = $this->makeAdminFor($this->masjidA);
        $this->adminB = $this->makeAdminFor($this->masjidB);
    }

    // ---------------------------------------------------------------- helpers

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

    /**
     * A contact who is somebody's GUARDIAN, with no login yet.
     *
     * Every test here enables a login, and family sign-in is guardian-only —
     * see FamilyAccessService::ineligibilityReason() and
     * GuardianOnlyLoginTest for why (enabling a child's own row is a student
     * login, which grants the whole class feed).
     *
     * This helper used to build a bare contact, which meant the whole file was
     * exercising a path that must not exist. The guardian edge is part of the
     * fixture now rather than an afterthought: a contact that cannot legally
     * hold a login is not a useful subject for a test about holding one.
     */
    private function makeContact(Masjid $masjid, array $attributes = []): Contact
    {
        $guardian = Contact::factory()->create(array_merge([
            'masjid_id' => $masjid->id,
        ], $attributes));

        $this->attachGuardianEdge($masjid, $guardian);

        return $guardian;
    }

    /** A contact on no roster at all — cannot hold a login. */
    private function makeNonGuardianContact(Masjid $masjid, array $attributes = []): Contact
    {
        return Contact::factory()->create(array_merge([
            'masjid_id' => $masjid->id,
        ], $attributes));
    }

    /** Make `$guardian` the guardian of a freshly-created ward in `$masjid`. */
    private function attachGuardianEdge(Masjid $masjid, Contact $guardian): void
    {
        $name = 'Class '.uniqid();

        $group = \App\Models\Group::withoutMasjidScope()->create([
            'masjid_id' => $masjid->id,
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name),
            'kind' => 'class',
        ]);

        $ward = Contact::factory()->create(['masjid_id' => $masjid->id]);

        \App\Models\GroupMembership::withoutMasjidScope()->create([
            'masjid_id' => $masjid->id,
            'group_id' => $group->id,
            'contact_id' => $ward->id,
            'role' => \App\Models\GroupMembership::ROLE_MEMBER,
            'joined_at' => now(),
        ]);

        \App\Models\GroupMembership::withoutMasjidScope()->create([
            'masjid_id' => $masjid->id,
            'group_id' => $group->id,
            'contact_id' => $guardian->id,
            'role' => \App\Models\GroupMembership::ROLE_GUARDIAN,
            'guardian_of_contact_id' => $ward->id,
            'joined_at' => now(),
        ]);
    }

    private function url(Masjid $masjid, Contact $contact): string
    {
        return "/api/admin/masjids/{$masjid->id}/contacts/{$contact->id}/family-login";
    }

    /**
     * Put the process back into the state a genuinely NEW request starts from.
     *
     * `RequestGuard::user()` memoizes and `TenantContext` is a scoped binding
     * nothing clears mid-process, so without this a later call inside one test
     * would be answered out of the earlier call's state — and would keep passing
     * with the check under test deleted. This matters more here than anywhere
     * else in the suite because these tests deliberately cross from the STAFF
     * realm into the FAMILY realm inside a single test.
     */
    private function asANewRequest(): void
    {
        Auth::forgetGuards();
        app(TenantContext::class)->forgetTenant();
    }

    /** Authenticate as staff for the next request. */
    private function asAdmin(User $admin): void
    {
        $this->asANewRequest();
        Sanctum::actingAs($admin);
    }

    /** Read a contact ignoring the tenant scope, to assert on raw column state. */
    private function raw(Contact $contact): Contact
    {
        return Contact::withoutMasjidScope()->withTrashed()->findOrFail($contact->id);
    }

    /** Audit rows for one contact, oldest first, read unscoped. */
    private function events(Contact $contact)
    {
        return ContactLoginEvent::withoutMasjidScope()
            ->where('contact_id', $contact->id)
            ->orderBy('id')
            ->get();
    }

    // ================================================================ 1. round trip

    #[Test]
    public function enable_then_revoke_is_a_complete_round_trip(): void
    {
        $parent = $this->makeContact($this->masjidA);

        $this->asAdmin($this->adminA);

        $this->postJson($this->url($this->masjidA, $parent), [
            'login_email' => 'umm.amina@example.test',
        ])
            ->assertOk()
            ->assertJsonPath('data.state', 'enabled')
            ->assertJsonPath('data.login_email', 'umm.amina@example.test');

        $enabled = $this->raw($parent);
        $this->assertNotNull($enabled->login_enabled_at);
        $this->assertNull($enabled->login_revoked_at);
        $this->assertTrue($enabled->familyLoginIsActive());

        $this->asAdmin($this->adminA);

        $this->deleteJson($this->url($this->masjidA, $parent))
            ->assertOk()
            ->assertJsonPath('data.state', 'revoked')
            // The address SURVIVES revocation. Clearing it would free it for
            // another contact to inherit, and would erase which mailbox used to
            // open this child's file.
            ->assertJsonPath('data.login_email', 'umm.amina@example.test');

        $revoked = $this->raw($parent);
        $this->assertNotNull($revoked->login_enabled_at, 'enabled_at is the record of the grant and must survive revocation');
        $this->assertNotNull($revoked->login_revoked_at);
        $this->assertFalse($revoked->familyLoginIsActive());

        // …and re-opening CLEARS the revocation, rather than leaving a login
        // that reads as enabled and refuses every request.
        $this->asAdmin($this->adminA);

        $this->postJson($this->url($this->masjidA, $parent), [
            'login_email' => 'umm.amina@example.test',
        ])
            ->assertOk()
            ->assertJsonPath('data.state', 'enabled');

        $this->assertNull($this->raw($parent)->login_revoked_at);
        $this->assertTrue($this->raw($parent)->familyLoginIsActive());
    }

    #[Test]
    public function a_contact_with_no_login_reads_as_never_enabled(): void
    {
        $parent = $this->makeContact($this->masjidA);

        $this->asAdmin($this->adminA);

        $this->getJson($this->url($this->masjidA, $parent))
            ->assertOk()
            ->assertJsonPath('data.state', 'never_enabled')
            ->assertJsonPath('data.login_email', null)
            ->assertJsonCount(0, 'data.events');
    }

    #[Test]
    public function revoking_a_login_that_was_never_enabled_is_refused_rather_than_silently_accepted(): void
    {
        $parent = $this->makeContact($this->masjidA);

        $this->asAdmin($this->adminA);

        $this->deleteJson($this->url($this->masjidA, $parent))
            ->assertStatus(422)
            ->assertJsonPath('status', 'error');

        // No audit row: nothing happened, so nothing is recorded. An audit trail
        // that logs non-events is one nobody reads.
        $this->assertCount(0, $this->events($parent));
    }

    // ============================================== 2. the address is CHOSEN, not derived

    #[Test]
    public function enabling_never_copies_the_imported_contact_email_into_the_credential(): void
    {
        $parent = $this->makeContact($this->masjidA, ['email' => 'household@example.test']);

        $this->asAdmin($this->adminA);

        // Omitting the address is a validation failure, NOT a fallback to
        // `contacts.email`. That column is imported in bulk, is routinely a
        // household address shared by both parents, was verified by nobody, and
        // is already read as a STAFF identity bridge by GroupAudience.
        $this->postJson($this->url($this->masjidA, $parent), [])
            ->assertStatus(422)
            ->assertJsonPath('status', 'failed');

        $this->assertNull($this->raw($parent)->login_email);
        $this->assertNull($this->raw($parent)->login_enabled_at);

        // And the address an admin DOES choose does not have to be the roster
        // one — nor does choosing one touch the roster column.
        $this->asAdmin($this->adminA);

        $this->postJson($this->url($this->masjidA, $parent), [
            'login_email' => 'father.only@example.test',
        ])->assertOk();

        $fresh = $this->raw($parent);
        $this->assertSame('father.only@example.test', $fresh->login_email);
        $this->assertSame('household@example.test', $fresh->email);
    }

    #[Test]
    public function the_login_columns_stay_unreachable_from_the_ordinary_contact_endpoints(): void
    {
        $parent = $this->makeContact($this->masjidA);

        $this->asAdmin($this->adminA);

        // The whole reason the four login_* columns are absent from
        // Contact::$fillable: no request body may enable, re-address or
        // un-revoke a login as a side effect of editing a phone number.
        $this->putJson("/api/admin/masjids/{$this->masjidA->id}/contacts/{$parent->id}", [
            'first_name' => 'Aisha',
            'last_name' => 'Rahman',
            'login_email' => 'smuggled@example.test',
            'login_enabled_at' => now()->toDateTimeString(),
        ])->assertOk();

        $fresh = $this->raw($parent);
        $this->assertNull($fresh->login_email);
        $this->assertNull($fresh->login_enabled_at);
        $this->assertFalse($fresh->familyLoginIsActive());
    }

    // ==================================================== 3. the uniqueness rule

    #[Test]
    public function a_second_contact_cannot_take_an_address_already_in_use(): void
    {
        $first = $this->makeContact($this->masjidA);
        $second = $this->makeContact($this->masjidA, ['first_name' => 'Yusuf', 'last_name' => 'Karim']);

        $this->asAdmin($this->adminA);
        $this->postJson($this->url($this->masjidA, $first), ['login_email' => 'shared@example.test'])
            ->assertOk();

        $this->asAdmin($this->adminA);
        $this->postJson($this->url($this->masjidA, $second), ['login_email' => 'shared@example.test'])
            ->assertStatus(422)
            ->assertJsonPath('status', 'error');

        $this->assertNull($this->raw($second)->login_enabled_at);
    }

    #[Test]
    public function a_case_differing_address_is_refused_because_the_lookup_is_case_insensitive(): void
    {
        $first = $this->makeContact($this->masjidA);
        $second = $this->makeContact($this->masjidA);

        $this->asAdmin($this->adminA);
        $this->postJson($this->url($this->masjidA, $first), ['login_email' => 'parent@example.test'])
            ->assertOk();

        // THIS is the case the database index cannot catch. Production MySQL is
        // utf8mb4_bin, so `Parent@Example.test` and `parent@example.test` are two
        // distinct values to `unique(masjid_id, login_email)` — and TWO MATCHES
        // to FamilyLoginService::resolveContact(), which requires exactly one and
        // answers an ambiguity with the same silent 202 a stranger gets. Both
        // parents would be locked out forever with nothing in any log.
        $this->asAdmin($this->adminA);
        $this->postJson($this->url($this->masjidA, $second), ['login_email' => 'Parent@Example.test'])
            ->assertStatus(422);

        $this->assertNull($this->raw($second)->login_enabled_at);
    }

    #[Test]
    public function the_stored_address_is_normalised_so_the_index_and_the_lookup_agree(): void
    {
        $parent = $this->makeContact($this->masjidA);

        $this->asAdmin($this->adminA);
        $this->postJson($this->url($this->masjidA, $parent), ['login_email' => '  Umm.Bilal@Example.TEST '])
            ->assertOk()
            ->assertJsonPath('data.login_email', 'umm.bilal@example.test');

        // The structural half of the rule: the value the unique index is
        // computed over is the same form resolveContact() compares against, so
        // the collision is impossible rather than merely reported.
        $this->assertSame('umm.bilal@example.test', $this->raw($parent)->login_email);
    }

    #[Test]
    public function a_soft_deleted_contact_still_pins_its_address(): void
    {
        $gone = $this->makeContact($this->masjidA, ['first_name' => 'Former', 'last_name' => 'Parent']);
        $newcomer = $this->makeContact($this->masjidA);

        $this->asAdmin($this->adminA);
        $this->postJson($this->url($this->masjidA, $gone), ['login_email' => 'recycled@example.test'])
            ->assertOk();

        $this->asAdmin($this->adminA);
        $this->deleteJson("/api/admin/masjids/{$this->masjidA->id}/contacts/{$gone->id}")->assertOk();

        // Freeing the address on delete would let a new contact silently inherit
        // a mailbox that used to open a specific child's records. Re-issuing it
        // is an operator's decision, not a side effect of a soft delete.
        $this->asAdmin($this->adminA);
        $this->postJson($this->url($this->masjidA, $newcomer), ['login_email' => 'recycled@example.test'])
            ->assertStatus(422);

        $this->assertNull($this->raw($newcomer)->login_enabled_at);
    }

    #[Test]
    public function the_same_address_may_be_used_by_a_different_organisation(): void
    {
        $atA = $this->makeContact($this->masjidA);
        $atB = $this->makeContact($this->masjidB);

        $this->asAdmin($this->adminA);
        $this->postJson($this->url($this->masjidA, $atA), ['login_email' => 'one.parent@example.test'])
            ->assertOk();

        // Uniqueness is per TENANT and never global: a globally unique
        // credential address would answer "is this parent also at that other
        // school?", which is the correlation channel the design rejected
        // outright. One human, one login per organisation.
        $this->asAdmin($this->adminB);
        $this->postJson($this->url($this->masjidB, $atB), ['login_email' => 'one.parent@example.test'])
            ->assertOk();

        $this->assertTrue($this->raw($atA)->familyLoginIsActive());
        $this->assertTrue($this->raw($atB)->familyLoginIsActive());
    }

    #[Test]
    public function re_enabling_the_same_contact_at_the_same_address_is_not_a_self_collision(): void
    {
        $parent = $this->makeContact($this->masjidA);

        $this->asAdmin($this->adminA);
        $this->postJson($this->url($this->masjidA, $parent), ['login_email' => 'steady@example.test'])->assertOk();

        $this->asAdmin($this->adminA);
        $this->postJson($this->url($this->masjidA, $parent), ['login_email' => 'steady@example.test'])
            ->assertOk()
            ->assertJsonPath('data.state', 'enabled');
    }

    #[Test]
    public function a_placeholder_card_stub_cannot_be_given_a_login(): void
    {
        $placeholder = $this->makeContact($this->masjidA, [
            'first_name' => 'Unidentified Card',
            'last_name' => '4242',
            'is_placeholder' => true,
        ]);

        $this->asAdmin($this->adminA);

        // A placeholder names no person and is the row the merge flow
        // force-deletes. A credential on one is a login belonging to nobody that
        // survives until somebody notices.
        $this->postJson($this->url($this->masjidA, $placeholder), ['login_email' => 'nobody@example.test'])
            ->assertStatus(422)
            ->assertJsonPath('status', 'error');

        $this->assertNull($this->raw($placeholder)->login_enabled_at);
    }

    // ============================================ 4. what revocation does to a live token

    #[Test]
    public function revoking_through_the_endpoint_kills_a_token_already_in_a_parents_phone(): void
    {
        $parent = $this->makeContact($this->masjidA);

        $this->asAdmin($this->adminA);
        $this->postJson($this->url($this->masjidA, $parent), ['login_email' => 'live@example.test'])->assertOk();

        // A token minted BEFORE the revocation — the session already sitting in
        // a phone's keychain, which is the only case that matters.
        $token = $this->raw($parent)->createFamilyToken()->plainTextToken;

        $this->asANewRequest();
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson("/api/family/masjids/{$this->masjidA->id}/me")
            ->assertOk();

        $this->asAdmin($this->adminA);
        $this->deleteJson($this->url($this->masjidA, $parent))->assertOk();

        $this->asANewRequest();
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson("/api/family/masjids/{$this->masjidA->id}/me")
            ->assertStatus(401);

        // Mechanism 2: the credential does not merely stop working, it stops
        // existing. A revoked guardian must not leave a live bearer token in
        // `personal_access_tokens` for the rest of its 8-hour life.
        $this->assertSame(0, $this->raw($parent)->tokens()->count());
    }

    #[Test]
    public function the_middleware_alone_refuses_a_revoked_login_even_with_the_token_row_intact(): void
    {
        $parent = $this->makeContact($this->masjidA);

        $this->asAdmin($this->adminA);
        $this->postJson($this->url($this->masjidA, $parent), ['login_email' => 'inert@example.test'])->assertOk();

        $token = $this->raw($parent)->createFamilyToken()->plainTextToken;

        // Revoke by writing the COLUMN only, leaving the token row untouched.
        // This is what isolates `EnsureFamilyLoginActive` from the token delete:
        // without it, the test above would keep passing with the middleware
        // dropped from routes/family.php, because the deleted token would be
        // doing all the work.
        $this->raw($parent)->forceFill(['login_revoked_at' => now()])->save();

        $this->assertSame(1, $this->raw($parent)->tokens()->count(), 'the token row must still exist for this test to mean anything');

        $this->asANewRequest();
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson("/api/family/masjids/{$this->masjidA->id}/me")
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthorized.');
    }

    #[Test]
    public function changing_the_address_ends_sessions_opened_under_the_old_one(): void
    {
        $parent = $this->makeContact($this->masjidA);

        $this->asAdmin($this->adminA);
        $this->postJson($this->url($this->masjidA, $parent), ['login_email' => 'old.mailbox@example.test'])->assertOk();

        $token = $this->raw($parent)->createFamilyToken()->plainTextToken;

        // The usual reason to re-address a login is that the old mailbox was
        // wrong or is no longer the right person's — a separation, a guardian
        // handover, a typo that reached a stranger. In every one of those, a
        // session opened under the old address is a session the change meant to
        // end.
        $this->asAdmin($this->adminA);
        $this->postJson($this->url($this->masjidA, $parent), ['login_email' => 'new.mailbox@example.test'])
            ->assertOk();

        $this->assertSame(0, $this->raw($parent)->tokens()->count());

        $this->asANewRequest();
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson("/api/family/masjids/{$this->masjidA->id}/me")
            ->assertStatus(401);
    }

    #[Test]
    public function re_typing_the_same_address_does_not_sign_the_parent_out(): void
    {
        $parent = $this->makeContact($this->masjidA);

        $this->asAdmin($this->adminA);
        $this->postJson($this->url($this->masjidA, $parent), ['login_email' => 'unchanged@example.test'])->assertOk();

        $token = $this->raw($parent)->createFamilyToken()->plainTextToken;

        $this->asAdmin($this->adminA);
        $this->postJson($this->url($this->masjidA, $parent), ['login_email' => 'unchanged@example.test'])->assertOk();

        $this->asANewRequest();
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson("/api/family/masjids/{$this->masjidA->id}/me")
            ->assertOk();
    }

    #[Test]
    public function revoking_twice_is_allowed_and_does_not_rewrite_when_access_ended(): void
    {
        $parent = $this->makeContact($this->masjidA);

        $this->asAdmin($this->adminA);
        $this->postJson($this->url($this->masjidA, $parent), ['login_email' => 'twice@example.test'])->assertOk();

        $this->asAdmin($this->adminA);
        $this->deleteJson($this->url($this->masjidA, $parent))->assertOk();
        $firstRevocation = $this->raw($parent)->login_revoked_at;

        $this->travel(5)->minutes();

        // A revoke button must never refuse: the one time it matters is the time
        // somebody is clicking it twice in a hurry. But the ORIGINAL timestamp is
        // when access actually ended, and moving it forward would rewrite that.
        $this->asAdmin($this->adminA);
        $this->deleteJson($this->url($this->masjidA, $parent))->assertOk();

        $this->assertEquals(
            $firstRevocation->toDateTimeString(),
            $this->raw($parent)->login_revoked_at->toDateTimeString()
        );

        $this->travelBack();
    }

    // ==================================================== 5. cross-tenant isolation

    #[Test]
    public function an_admin_of_one_organisation_cannot_enable_a_contact_of_another(): void
    {
        $foreign = $this->makeContact($this->masjidB);

        // Under A's own route the id simply does not exist: the BelongsToMasjid
        // scope makes findOrFail miss the row, so it is a 404 and not a
        // "forbidden" that would confirm the contact is real.
        $this->asAdmin($this->adminA);
        $this->postJson($this->url($this->masjidA, $foreign), ['login_email' => 'poach@example.test'])
            ->assertStatus(404);

        // Naming B's masjid in the path is a 403 from ResolveMasjidTenant,
        // before this controller runs at all.
        $this->asAdmin($this->adminA);
        $this->postJson($this->url($this->masjidB, $foreign), ['login_email' => 'poach@example.test'])
            ->assertStatus(403);

        $this->asAdmin($this->adminA);
        $this->deleteJson($this->url($this->masjidA, $foreign))->assertStatus(404);

        $this->asAdmin($this->adminA);
        $this->getJson($this->url($this->masjidA, $foreign))->assertStatus(404);

        $fresh = $this->raw($foreign);
        $this->assertNull($fresh->login_email);
        $this->assertNull($fresh->login_enabled_at);
        $this->assertCount(0, $this->events($foreign));
    }

    #[Test]
    public function the_audit_trail_is_tenant_scoped(): void
    {
        $atA = $this->makeContact($this->masjidA);
        $atB = $this->makeContact($this->masjidB);

        $this->asAdmin($this->adminA);
        $this->postJson($this->url($this->masjidA, $atA), ['login_email' => 'a@example.test'])->assertOk();

        $this->asAdmin($this->adminB);
        $this->postJson($this->url($this->masjidB, $atB), ['login_email' => 'b@example.test'])->assertOk();

        $this->asANewRequest();
        app(TenantContext::class)->set($this->masjidA->id);

        $visible = ContactLoginEvent::query()->get();

        $this->assertCount(1, $visible);
        $this->assertSame($atA->id, (int) $visible->first()->contact_id);
        $this->assertSame($this->masjidA->id, (int) $visible->first()->masjid_id);
    }

    // ============================================================ 6. permissions

    #[Test]
    public function reading_and_writing_are_gated_by_the_contacts_permissions(): void
    {
        $parent = $this->makeContact($this->masjidA);

        Role::findByName('masjid-admin')->revokePermissionTo('manage contacts');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->asAdmin($this->adminA);
        $this->postJson($this->url($this->masjidA, $parent), ['login_email' => 'nope@example.test'])
            ->assertStatus(403);

        $this->asAdmin($this->adminA);
        $this->deleteJson($this->url($this->masjidA, $parent))->assertStatus(403);

        // Reading is the weaker permission and is still open.
        $this->asAdmin($this->adminA);
        $this->getJson($this->url($this->masjidA, $parent))->assertOk();

        $this->assertNull($this->raw($parent)->login_enabled_at);
    }

    #[Test]
    public function the_endpoints_reject_an_unauthenticated_caller(): void
    {
        $parent = $this->makeContact($this->masjidA);

        $this->postJson($this->url($this->masjidA, $parent), ['login_email' => 'x@example.test'])
            ->assertStatus(401);
        $this->deleteJson($this->url($this->masjidA, $parent))->assertStatus(401);
        $this->getJson($this->url($this->masjidA, $parent))->assertStatus(401);
    }

    // =========================================================== 7. the audit trail

    #[Test]
    public function every_act_writes_an_audit_row_naming_who_did_it(): void
    {
        $parent = $this->makeContact($this->masjidA);

        $this->asAdmin($this->adminA);
        $this->postJson($this->url($this->masjidA, $parent), ['login_email' => 'first@example.test'])->assertOk();

        $this->asAdmin($this->adminA);
        $this->postJson($this->url($this->masjidA, $parent), ['login_email' => 'second@example.test'])->assertOk();

        $this->asAdmin($this->adminA);
        $this->deleteJson($this->url($this->masjidA, $parent))->assertOk();

        $events = $this->events($parent);

        $this->assertCount(3, $events);
        $this->assertSame(
            [ContactLoginEvent::ACTION_ENABLED, ContactLoginEvent::ACTION_ENABLED, ContactLoginEvent::ACTION_REVOKED],
            $events->pluck('action')->all()
        );

        // The address AS OF EACH ACT, so a re-addressing is visible in the
        // history it produced — `contacts.login_email` holds only the latest.
        $this->assertSame(
            ['first@example.test', 'second@example.test', 'second@example.test'],
            $events->pluck('login_email')->all()
        );

        foreach ($events as $event) {
            $this->assertSame($this->adminA->id, (int) $event->actor_user_id);
            $this->assertSame($this->adminA->name, $event->actor_name);
            $this->assertSame($this->adminA->email, $event->actor_email);
            $this->assertSame($this->masjidA->id, (int) $event->masjid_id);
            $this->assertNotNull($event->created_at);
        }

        // …and it is on the screen, newest first, not only in the database.
        $this->asAdmin($this->adminA);
        $this->getJson($this->url($this->masjidA, $parent))
            ->assertOk()
            ->assertJsonCount(3, 'data.events')
            ->assertJsonPath('data.events.0.action', ContactLoginEvent::ACTION_REVOKED)
            ->assertJsonPath('data.events.0.actor_name', $this->adminA->name);
    }

    #[Test]
    public function the_audit_trail_survives_the_staff_member_who_wrote_it(): void
    {
        $parent = $this->makeContact($this->masjidA);

        $actor = $this->adminA;

        $this->asAdmin($actor);
        $this->postJson($this->url($this->masjidA, $parent), ['login_email' => 'kept@example.test'])->assertOk();

        // The organisation is handed to a NEW owner first: `masjids.user_id`
        // carries a unique-owner index among live rows, so it cannot be parked
        // on an admin who already owns one (add_owner_uniqueness_to_masjids_table).
        $successor = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
        $this->masjidA->forceFill(['user_id' => $successor->id])->save();

        // ---- the ORDINARY departure: `users` SOFT-deletes, so the foreign key
        // is still intact and `nullOnDelete` never fires. The relation is what
        // goes dark — the SoftDeletes scope resolves `actor()` to null — and a
        // panel that read the actor's name THROUGH the key would print nothing
        // at all. This is the case the snapshot columns exist for, and it is the
        // common one.
        User::query()->whereKey($actor->id)->delete();

        $event = $this->events($parent)->first();

        $this->assertNull($event->actor, 'a soft-deleted staff member is not resolvable through the key');
        $this->assertSame($actor->name, $event->actor_name);
        $this->assertSame($actor->email, $event->actor_email);

        // ---- and the HARD one: a purge force-deletes the row. Now the key
        // nulls (a cascade here would have erased every grant that person ever
        // made) and the snapshot is the only thing left saying who acted.
        User::withTrashed()->whereKey($actor->id)->first()->forceDelete();

        $event = $this->events($parent)->first();

        $this->assertNull($event->actor_user_id);
        $this->assertSame($actor->name, $event->actor_name);
        $this->assertSame($actor->email, $event->actor_email);
    }

    #[Test]
    public function an_audit_row_cannot_be_edited_or_deleted_through_the_model(): void
    {
        $parent = $this->makeContact($this->masjidA);

        $this->asAdmin($this->adminA);
        $this->postJson($this->url($this->masjidA, $parent), ['login_email' => 'fixed@example.test'])->assertOk();

        $this->asANewRequest();
        $event = ContactLoginEvent::withoutMasjidScope()->firstOrFail();

        // An audit trail the application can rewrite is a log, not an audit
        // trail.
        try {
            $event->update(['action' => ContactLoginEvent::ACTION_REVOKED]);
            $this->fail('an audit row was updated');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        try {
            $event->delete();
            $this->fail('an audit row was deleted');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        $this->assertSame(
            ContactLoginEvent::ACTION_ENABLED,
            ContactLoginEvent::withoutMasjidScope()->firstOrFail()->action
        );
    }

    // ====================================================== 8. END TO END, both realms

    #[Test]
    public function an_enabled_parent_signs_in_and_reads_their_own_child_and_no_other(): void
    {
        // ---- one classroom, two families. A fixture where each parent had a
        // group to themselves would pass with every disclosure rule deleted.
        $group = Group::factory()->create([
            'masjid_id' => $this->masjidA->id,
            'kind' => Group::KIND_CLASS,
            'name' => 'Grade 3',
        ]);

        // makeNonGuardianContact, NOT makeContact: this test seeds its own
        // guardian edges below, and the helper's convenience edge would put each
        // parent in a SECOND classroom — quietly turning "reads their own child
        // and no other" into a two-group assertion that no longer means what its
        // name says.
        $parentA = $this->makeNonGuardianContact($this->masjidA, ['first_name' => 'Khadija']);
        $childA = $this->makeNonGuardianContact($this->masjidA, ['first_name' => 'Amina']);
        $parentB = $this->makeNonGuardianContact($this->masjidA, ['first_name' => 'Yusuf']);
        $childB = $this->makeNonGuardianContact($this->masjidA, ['first_name' => 'Bilal']);

        $childAMembership = $this->seedMembership($group, $childA, GroupMembership::ROLE_MEMBER);
        $childBMembership = $this->seedMembership($group, $childB, GroupMembership::ROLE_MEMBER);
        $this->seedMembership($group, $parentA, GroupMembership::ROLE_GUARDIAN, $childA);
        $this->seedMembership($group, $parentB, GroupMembership::ROLE_GUARDIAN, $childB);

        $this->seedAward($group, $childAMembership, 'Kindness');
        $this->seedAward($group, $childBMembership, 'Helpfulness');

        // ---- STEP 1: an admin turns the login on. Before this line, nothing in
        // the application had ever written login_enabled_at.
        $this->asAdmin($this->adminA);
        $this->postJson($this->url($this->masjidA, $parentA), ['login_email' => 'khadija@example.test'])
            ->assertOk()
            ->assertJsonPath('data.state', 'enabled');

        // ---- STEP 2: the parent asks for a code, at the address the admin
        // chose. Always 202, whoever asks.
        $this->asANewRequest();
        Mail::fake();

        $this->postJson("/api/family/masjids/{$this->masjidA->id}/auth/request-code", [
            'email' => 'khadija@example.test',
        ])->assertStatus(202);

        $code = null;
        Mail::assertSent(FamilyLoginCodeMail::class, function (FamilyLoginCodeMail $mail) use (&$code) {
            if (! $mail->hasTo('khadija@example.test')) {
                return false;
            }
            $code = $mail->code;

            return true;
        });
        $this->assertNotNull($code, 'the enabled address received no code');

        // ---- STEP 3: redeem it for a token.
        $this->asANewRequest();
        $token = $this->postJson("/api/family/masjids/{$this->masjidA->id}/auth/verify-code", [
            'email' => 'khadija@example.test',
            'code' => $code,
        ])->assertOk()->json('data.token');

        $this->assertNotEmpty($token);

        // ---- STEP 4: the token opens this parent's own surface…
        $this->asANewRequest();
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson("/api/family/masjids/{$this->masjidA->id}/me")
            ->assertOk()
            ->assertJsonPath('data.id', $parentA->id);

        $this->asANewRequest();
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson("/api/family/masjids/{$this->masjidA->id}/groups")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->asANewRequest();
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson("/api/family/masjids/{$this->masjidA->id}/groups/{$group->id}/members/{$childAMembership->id}/awards")
            ->assertOk()
            // `data.data` — the awards listing is paginated, and the paginator is
            // itself part of the guarantee: the query is constrained to this
            // parent's own ward, so a forbidden row is never fetched and cannot
            // surface in a page count or a total.
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.skill_label', 'Kindness');

        // ---- …and NOT the other family's child, in the same classroom.
        // "Guardian here" never meant "guardian of this child".
        $this->asANewRequest();
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson("/api/family/masjids/{$this->masjidA->id}/groups/{$group->id}/members/{$childBMembership->id}/awards")
            ->assertStatus(403);

        // ---- STEP 5: the office revokes. The token in the phone stops working
        // on the very next request.
        $this->asAdmin($this->adminA);
        $this->deleteJson($this->url($this->masjidA, $parentA))->assertOk();

        $this->asANewRequest();
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson("/api/family/masjids/{$this->masjidA->id}/groups/{$group->id}/members/{$childAMembership->id}/awards")
            ->assertStatus(401);

        // …and the address stops producing codes, silently.
        $this->asANewRequest();
        Mail::fake();
        $this->postJson("/api/family/masjids/{$this->masjidA->id}/auth/request-code", [
            'email' => 'khadija@example.test',
        ])->assertStatus(202);
        Mail::assertNothingSent();
    }

    // ------------------------------------------------- end-to-end fixture helpers

    private function seedMembership(
        Group $group,
        Contact $contact,
        string $role,
        ?Contact $ward = null,
    ): GroupMembership {
        return GroupMembership::create([
            'masjid_id' => $this->masjidA->id,
            'group_id' => $group->id,
            'contact_id' => $contact->id,
            'role' => $role,
            'guardian_of_contact_id' => $ward?->id,
        ]);
    }

    private function seedAward(Group $group, GroupMembership $subject, string $label): BehaviorAward
    {
        return BehaviorAward::factory()->create([
            'masjid_id' => $this->masjidA->id,
            'group_id' => $group->id,
            'group_membership_id' => $subject->id,
            'skill_label' => $label,
            'skill_polarity' => BehaviorSkill::POLARITY_POSITIVE,
            'points' => 3,
            'note' => $label . ' note',
        ]);
    }
}
