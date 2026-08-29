<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactLoginEvent;
use App\Models\Masjid;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * T-015d — what happens to a parent's sign-in when the RECORD it hangs on moves.
 *
 * FamilyLoginEnablementTest covers the credential's own life: enabled, revoked,
 * re-addressed, and who is on the record for each. This file covers the four
 * places where the CONTACT changes underneath it — merged away, deleted,
 * competing for one household mailbox, or being written by two admins at once —
 * every one of which used to end with a parent silently unable to sign in.
 *
 * Each case here is a measured reproduction first and a fix second:
 *
 *  1. `merge` force-deleted a live parent login. The `contact_login_events`
 *     migration chose `contact_id … nullOnDelete` PRECISELY so a merge would not
 *     erase the trail — and nothing carried it across, so it was preserved
 *     exactly where no screen could read it. Measured: 1 orphaned audit row, 0
 *     rows on the source, survivor `never_enabled`, no `revoked` event, no
 *     token deleted, and nobody told.
 *  2. Freeing a sign-in address required RE-ENABLING the login holding it.
 *     `enable()` is the only writer of `login_email`, `revoke()` never clears
 *     it, and the pre-check matched any other holder, revoked or not. Measured:
 *     the only way to give a father the household mailbox was to re-grant the
 *     mother access to a child's file at a throwaway address first.
 *  3. A soft-deleted contact burned its address forever. The documented escapes
 *     ("restore the contact, or clear the column") do not exist in this
 *     application. Measured: `422 already used by Fatima Ali (deleted)`, which
 *     also disclosed a deleted member's NAME to any holder of `manage contacts`
 *     — a name no screen in this application will show them.
 *  4. Two admins enabling one address in the same second: the pre-check is a
 *     SELECT, not a lock, so both cleared it and the unique index refused the
 *     loser as an uncaught QueryException — a 500 for a collision this endpoint
 *     already had a sentence written for.
 *
 * The property that ties 1 and 3 together and is asserted repeatedly: a contact
 * leaving the directory takes its portal access with it, LOUDLY. Never silently,
 * and never by handing that access to somebody else.
 */
class FamilyLoginLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjidA;
    private Masjid $masjidB;
    private User $adminA;

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

        $this->masjidA = $this->makeMasjid();
        $this->masjidB = $this->makeMasjid();

        $this->adminA = $this->makeAdminFor($this->masjidA);
        $this->makeAdminFor($this->masjidB);
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
     * A contact who is somebody's GUARDIAN — the only kind that may hold a
     * family login (FamilyAccessService::ineligibilityReason; a login on a
     * child's own row is a student login and grants the whole class feed).
     */
    private function makeGuardian(Masjid $masjid, array $attributes = []): Contact
    {
        $guardian = Contact::factory()->create(array_merge([
            'masjid_id' => $masjid->id,
        ], $attributes));

        $name = 'Class ' . uniqid();

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

        return $guardian;
    }

    private function loginUrl(Masjid $masjid, Contact $contact): string
    {
        return "/api/admin/masjids/{$masjid->id}/contacts/{$contact->id}/family-login";
    }

    private function asANewRequest(): void
    {
        Auth::forgetGuards();
        app(TenantContext::class)->forgetTenant();
    }

    private function asAdmin(User $admin): void
    {
        $this->asANewRequest();
        Sanctum::actingAs($admin);
    }

    /** Raw column state, ignoring both the tenant scope and SoftDeletes. */
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

    private function orphanedEventCount(): int
    {
        return ContactLoginEvent::withoutMasjidScope()->whereNull('contact_id')->count();
    }

    // ============================================================== 1. MERGE

    /**
     * THE MEASURED REPRODUCTION, assertion for assertion.
     *
     * before: source has login_email amina@example.com, state enabled, 1 audit row
     * POST   .../contacts/{source}/merge {"target_contact_id": {target}} -> 200
     * after:  orphaned audit rows (contact_id NULL) = 1
     *         audit rows still linked to the source  = 0
     *         survivor state = never_enabled
     */
    #[Test]
    public function merging_a_member_with_a_live_login_no_longer_destroys_it_silently(): void
    {
        $source = $this->makeGuardian($this->masjidA, ['first_name' => 'Amina', 'last_name' => 'Yusuf']);
        $target = $this->makeGuardian($this->masjidA, ['first_name' => 'Fatima', 'last_name' => 'Ali']);

        $this->asAdmin($this->adminA);
        $this->postJson($this->loginUrl($this->masjidA, $source), ['login_email' => 'amina@example.com'])
            ->assertOk()
            ->assertJsonPath('data.state', 'enabled');

        $this->assertCount(1, $this->events($source));

        $this->asAdmin($this->adminA);
        $response = $this->postJson(
            "/api/admin/masjids/{$this->masjidA->id}/contacts/{$source->id}/merge",
            ['target_contact_id' => $target->id],
        )->assertOk();

        // ---- the trail is READABLE, not merely undeleted. `nullOnDelete` alone
        // left rows nothing could find: the history panel reads
        // `where('contact_id', …)`, so an orphan is a deleted row with extra
        // steps.
        $this->assertSame(0, $this->orphanedEventCount(), 'the absorbed record\'s history must not be orphaned');

        $carried = $this->events($target);
        $this->assertSame(
            [
                ContactLoginEvent::ACTION_ENABLED,
                ContactLoginEvent::ACTION_REVOKED,
                ContactLoginEvent::ACTION_MERGED,
            ],
            $carried->pluck('action')->all(),
        );

        // Every carried row still says exactly what it said, about the address
        // the ABSORBED record held. Only the subject moved.
        foreach ($carried as $event) {
            $this->assertSame('amina@example.com', $event->login_email);
            $this->assertSame($this->adminA->name, $event->actor_name);
            $this->assertSame($this->masjidA->id, (int) $event->masjid_id);
        }

        // ---- a `revoked` event EXISTS, because access ended.
        $this->assertTrue(
            $carried->contains(fn (ContactLoginEvent $e) => $e->action === ContactLoginEvent::ACTION_REVOKED),
            'access ended and nothing said so',
        );

        // ---- the survivor does NOT inherit the credential. Deliberately: they
        // have passed neither the guardian check nor the placeholder check that
        // `enable()` applies, and a grant no administrator typed is exactly what
        // the unfillable login_* columns exist to prevent.
        $this->asAdmin($this->adminA);
        $this->getJson($this->loginUrl($this->masjidA, $target))
            ->assertOk()
            ->assertJsonPath('data.state', 'never_enabled')
            ->assertJsonPath('data.login_email', null)
            // …but the history is right there on their screen.
            ->assertJsonCount(3, 'data.events');

        // ---- and the operator is TOLD. An audit row nobody is told about is
        // not "loudly": this is the difference between the fix and the bug.
        $response
            ->assertJsonPath('family_login.access_ended', true)
            ->assertJsonPath('family_login.login_email', 'amina@example.com');
        $this->assertStringContainsString(
            'does NOT inherit',
            $response->json('family_login.message'),
        );
    }

    #[Test]
    public function a_merge_that_ends_portal_access_kills_the_token_already_in_the_parents_phone(): void
    {
        $source = $this->makeGuardian($this->masjidA);
        $target = $this->makeGuardian($this->masjidA);

        $this->asAdmin($this->adminA);
        $this->postJson($this->loginUrl($this->masjidA, $source), ['login_email' => 'live.merge@example.test'])
            ->assertOk();

        $token = $this->raw($source)->createFamilyToken()->plainTextToken;

        $this->asANewRequest();
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson("/api/family/masjids/{$this->masjidA->id}/me")
            ->assertOk();

        $this->asAdmin($this->adminA);
        $this->postJson(
            "/api/admin/masjids/{$this->masjidA->id}/contacts/{$source->id}/merge",
            ['target_contact_id' => $target->id],
        )->assertOk();

        $this->asANewRequest();
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson("/api/family/masjids/{$this->masjidA->id}/me")
            ->assertStatus(401);

        // The force-delete makes the token inert (Sanctum's tokenable lookup
        // resolves a destroyed contact to null). It must also stop EXISTING —
        // a merge silently leaving a bearer credential in the table for the rest
        // of its life is the merge doing the one thing revocation prevents.
        $this->assertSame(
            0,
            DB::table('personal_access_tokens')
                ->where('tokenable_type', Contact::class)
                ->where('tokenable_id', $source->id)
                ->count(),
        );
    }

    #[Test]
    public function the_absorbed_address_is_free_so_the_survivor_can_be_given_it_deliberately(): void
    {
        $source = $this->makeGuardian($this->masjidA);
        $target = $this->makeGuardian($this->masjidA);

        $this->asAdmin($this->adminA);
        $this->postJson($this->loginUrl($this->masjidA, $source), ['login_email' => 'household@example.test'])
            ->assertOk();

        $this->asAdmin($this->adminA);
        $this->postJson(
            "/api/admin/masjids/{$this->masjidA->id}/contacts/{$source->id}/merge",
            ['target_contact_id' => $target->id],
        )->assertOk();

        // This is the whole argument for ending the credential rather than
        // carrying it: undoing a wrong END costs one deliberate click, and the
        // grant it produces is a real one — typed by an administrator, checked
        // against the guardian rule, and on the record naming who did it.
        // Undoing a wrong GRANT costs a child's file.
        $this->asAdmin($this->adminA);
        $this->postJson($this->loginUrl($this->masjidA, $target), ['login_email' => 'household@example.test'])
            ->assertOk()
            ->assertJsonPath('data.state', 'enabled');

        $this->assertTrue($this->raw($target)->familyLoginIsActive());
    }

    #[Test]
    public function an_ordinary_placeholder_merge_still_writes_nothing_at_all(): void
    {
        $placeholder = Contact::factory()->create([
            'masjid_id' => $this->masjidA->id,
            'first_name' => 'Unidentified Card',
            'last_name' => '4242',
            'is_placeholder' => true,
        ]);
        $member = $this->makeGuardian($this->masjidA);

        $this->asAdmin($this->adminA);
        $response = $this->postJson(
            "/api/admin/masjids/{$this->masjidA->id}/contacts/{$placeholder->id}/merge",
            ['target_contact_id' => $member->id],
        )->assertOk();

        // The flow exists for card stubs and a placeholder is refused a
        // credential outright, so the overwhelmingly common merge must stay a
        // no-op here. An audit table that logs non-events is one nobody reads.
        $this->assertCount(0, $this->events($member));
        $this->assertSame(0, ContactLoginEvent::withoutMasjidScope()->count());
        $this->assertNull($response->json('family_login'));
    }

    #[Test]
    public function carrying_a_trail_is_the_only_mutation_an_audit_row_permits(): void
    {
        $source = $this->makeGuardian($this->masjidA);
        $target = $this->makeGuardian($this->masjidA);

        $this->asAdmin($this->adminA);
        $this->postJson($this->loginUrl($this->masjidA, $source), ['login_email' => 'immutable@example.test'])
            ->assertOk();

        $this->asAdmin($this->adminA);
        $this->postJson(
            "/api/admin/masjids/{$this->masjidA->id}/contacts/{$source->id}/merge",
            ['target_contact_id' => $target->id],
        )->assertOk();

        $this->asANewRequest();
        $event = ContactLoginEvent::withoutMasjidScope()->orderBy('id')->firstOrFail();

        // The allowance is a SHAPE — `contact_id` alone, non-null to non-null —
        // not a caller the model trusts. Everything else still throws, so a
        // future "just fix this row" fails loudly.
        foreach ([['action' => ContactLoginEvent::ACTION_REVOKED], ['login_email' => 'rewritten@example.test'], ['actor_name' => 'Somebody Else']] as $attempt) {
            try {
                $event->update($attempt);
                $this->fail('an audit row was rewritten: ' . json_encode($attempt));
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }
        }

        // …including a change that would ERASE the subject rather than move it.
        try {
            $event->update(['contact_id' => null]);
            $this->fail('an audit row was orphaned through the model');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        try {
            $event->delete();
            $this->fail('an audit row was deleted');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
    }

    // ================================================ 2. THE HOUSEHOLD MAILBOX

    /**
     * THE MEASURED REPRODUCTION.
     *
     * enable mother household@example.com     -> 200
     * revoke mother                           -> 200 (revoked)
     * enable father household@example.com     -> 422 "already used by Amina Yusuf"
     * enable mother throwaway@example.invalid -> 200, state ENABLED  <-- the footgun
     * enable father household@example.com     -> 200
     *
     * Giving the father the household address required RE-GRANTING the mother
     * access to a child's file. That is now a confirmation, and the mother's
     * access stays ended.
     */
    #[Test]
    public function a_household_address_moves_to_the_other_parent_without_re_granting_the_first_one_access(): void
    {
        $mother = $this->makeGuardian($this->masjidA, ['first_name' => 'Amina', 'last_name' => 'Yusuf']);
        $father = $this->makeGuardian($this->masjidA, ['first_name' => 'Yusuf', 'last_name' => 'Karim']);

        $this->asAdmin($this->adminA);
        $this->postJson($this->loginUrl($this->masjidA, $mother), ['login_email' => 'household@example.test'])
            ->assertOk();

        $this->asAdmin($this->adminA);
        $this->deleteJson($this->loginUrl($this->masjidA, $mother))
            ->assertOk()
            ->assertJsonPath('data.state', 'revoked');

        // Still refused by DEFAULT — nothing is inherited silently, which is the
        // property the pre-check exists for. What changed is that the refusal
        // now names a door instead of being one.
        $this->asAdmin($this->adminA);
        $refusal = $this->postJson($this->loginUrl($this->masjidA, $father), ['login_email' => 'household@example.test'])
            ->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('reassignable', true);

        $this->assertStringContainsString('Amina Yusuf', $refusal->json('message'));
        $this->assertNull($this->raw($father)->login_enabled_at);

        // The confirmation. One act: the address comes off the mother and is
        // granted to the father, in one transaction.
        $this->asAdmin($this->adminA);
        $this->postJson($this->loginUrl($this->masjidA, $father), [
            'login_email' => 'household@example.test',
            'reassign_address' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.state', 'enabled')
            ->assertJsonPath('data.login_email', 'household@example.test');

        // THE POINT: the mother was NOT re-enabled to get here. Her access
        // stayed ended for the whole operation.
        $mother = $this->raw($mother);
        $this->assertNull($mother->login_email, 'the address must be released from the previous holder');
        $this->assertNotNull($mother->login_revoked_at);
        $this->assertFalse($mother->familyLoginIsActive());

        // …and both halves are on the record. "Which mailbox used to open this
        // child's file" is answered by the trail, which snapshots the address on
        // every act — not by a column that had to be kept forever to stand in
        // for it.
        $this->assertSame(
            [
                ContactLoginEvent::ACTION_ENABLED,
                ContactLoginEvent::ACTION_REVOKED,
                ContactLoginEvent::ACTION_ADDRESS_RELEASED,
            ],
            $this->events($mother)->pluck('action')->all(),
        );
        $this->assertSame(
            'household@example.test',
            $this->events($mother)->last()->login_email,
        );
        // …and the CLAIMANT's own history carries both halves too, which is the
        // half the refusal actually promises ("both halves are written to THIS
        // member's access history"). It used to carry only `enabled`: the release
        // rows sit on the loser, and when the loser is soft-deleted — the case
        // this door exists for — no screen in this application can reach them
        // (`ContactsController::index/show` use the non-trashed scope, and the
        // panel reads `where('contact_id', …)`). That is the "orphaned beyond
        // every screen" failure `absorbOnMerge` was written to fix, and it had
        // been reintroduced here.
        $this->assertSame(
            [
                ContactLoginEvent::ACTION_ADDRESS_CLAIMED,
                ContactLoginEvent::ACTION_ENABLED,
            ],
            $this->events($father)->pluck('action')->all(),
        );
        $this->assertSame(
            'household@example.test',
            $this->events($father)->first()->login_email,
        );
    }

    #[Test]
    public function a_holder_who_can_sign_in_right_now_is_refused_even_with_the_confirmation(): void
    {
        $first = $this->makeGuardian($this->masjidA, ['first_name' => 'Amina', 'last_name' => 'Yusuf']);
        $second = $this->makeGuardian($this->masjidA);

        $this->asAdmin($this->adminA);
        $this->postJson($this->loginUrl($this->masjidA, $first), ['login_email' => 'shared@example.test'])
            ->assertOk();

        // The confirmation is NOT a force flag. Two live logins on one address
        // is the ambiguity `FamilyLoginService::resolveContact()` answers by
        // resolving to NOBODY, silently, behind the same 202 a stranger gets —
        // both parents locked out with nothing in any log. No checkbox may buy
        // that; the operator must revoke first, which is a different act with a
        // different record.
        $this->asAdmin($this->adminA);
        $refusal = $this->postJson($this->loginUrl($this->masjidA, $second), [
            'login_email' => 'shared@example.test',
            'reassign_address' => true,
        ])
            ->assertStatus(422)
            ->assertJsonPath('reassignable', false);

        $this->assertStringContainsString('right now', $refusal->json('message'));

        $this->assertSame('shared@example.test', $this->raw($first)->login_email);
        $this->assertTrue($this->raw($first)->familyLoginIsActive());
        $this->assertNull($this->raw($second)->login_enabled_at);
        $this->assertCount(1, $this->events($first));
    }

    // ============================================ 3. THE SOFT-DELETED CONTACT

    /**
     * THE MEASURED REPRODUCTION.
     *
     * enable fatima@example.com on X -> delete X -> enable the same address on a
     * re-imported Y -> 422 "already used by Fatima Ali (deleted)".
     *
     * With no contact-restore route and no way to clear `login_email`, that
     * address was dead for the tenant forever short of a database console.
     */
    #[Test]
    public function a_deleted_members_address_can_be_reissued_by_an_operator_who_confirms_it(): void
    {
        $gone = $this->makeGuardian($this->masjidA, ['first_name' => 'Fatima', 'last_name' => 'Ali']);
        $reimported = $this->makeGuardian($this->masjidA, ['first_name' => 'Fatima', 'last_name' => 'Ali']);

        $this->asAdmin($this->adminA);
        $this->postJson($this->loginUrl($this->masjidA, $gone), ['login_email' => 'fatima@example.test'])
            ->assertOk();

        $this->asAdmin($this->adminA);
        $this->deleteJson("/api/admin/masjids/{$this->masjidA->id}/contacts/{$gone->id}")->assertOk();

        // Still refused by default: the `withTrashed` span exists so a new
        // contact cannot SILENTLY inherit a mailbox that used to open a specific
        // child's records, and that property is untouched.
        $this->asAdmin($this->adminA);
        $refusal = $this->postJson($this->loginUrl($this->masjidA, $reimported), ['login_email' => 'fatima@example.test'])
            ->assertStatus(422)
            ->assertJsonPath('reassignable', true);

        // …and the refusal does not DISCLOSE the deleted member. No screen in
        // this application shows a deleted contact — not the index, not `show` —
        // so naming one in an error message hands a member to staff who were
        // never shown them.
        $this->assertStringNotContainsString('Fatima Ali', $refusal->json('message'));
        $this->assertStringContainsString('deleted', $refusal->json('message'));

        // The way through, which is now an act an operator can actually perform
        // rather than a database console.
        $this->asAdmin($this->adminA);
        $this->postJson($this->loginUrl($this->masjidA, $reimported), [
            'login_email' => 'fatima@example.test',
            'reassign_address' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.state', 'enabled');

        $this->assertNull($this->raw($gone)->login_email);
        $this->assertTrue($this->raw($reimported)->familyLoginIsActive());
        $this->assertContains(
            ContactLoginEvent::ACTION_ADDRESS_RELEASED,
            $this->events($gone)->pluck('action')->all(),
        );
    }

    #[Test]
    public function deleting_a_member_revokes_their_portal_access_on_the_record(): void
    {
        $parent = $this->makeGuardian($this->masjidA);

        $this->asAdmin($this->adminA);
        $this->postJson($this->loginUrl($this->masjidA, $parent), ['login_email' => 'leaving@example.test'])
            ->assertOk();

        $token = $this->raw($parent)->createFamilyToken()->plainTextToken;

        $this->asAdmin($this->adminA);
        $this->deleteJson("/api/admin/masjids/{$this->masjidA->id}/contacts/{$parent->id}")->assertOk();

        // The access died here either way — `familyLoginIsActive()` is false for
        // a trashed row. What it used to do was die with `login_revoked_at` null,
        // so the record read "enabled" forever, no `revoked` row was ever
        // written, and the token stayed in the table for the rest of its life.
        $gone = $this->raw($parent);
        $this->assertNotNull($gone->login_revoked_at, 'a delete that ends access must say so');
        $this->assertFalse($gone->familyLoginIsActive());

        $this->assertSame(
            [ContactLoginEvent::ACTION_ENABLED, ContactLoginEvent::ACTION_REVOKED],
            $this->events($parent)->pluck('action')->all(),
        );
        $this->assertSame($this->adminA->name, $this->events($parent)->last()->actor_name);

        $this->assertSame(
            0,
            DB::table('personal_access_tokens')
                ->where('tokenable_type', Contact::class)
                ->where('tokenable_id', $parent->id)
                ->count(),
        );

        $this->asANewRequest();
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson("/api/family/masjids/{$this->masjidA->id}/me")
            ->assertStatus(401);

        // The address is still RESERVED to them — a delete does not free it.
        // That is still a separate, confirmed act.
        $this->assertSame('leaving@example.test', $gone->login_email);
    }

    #[Test]
    public function a_reassignment_never_reaches_another_organisations_holder(): void
    {
        $atB = $this->makeGuardian($this->masjidB);
        $atA = $this->makeGuardian($this->masjidA);

        $this->asAdmin(User::whereKeyNot($this->adminA->id)->firstOrFail());
        $this->postJson($this->loginUrl($this->masjidB, $atB), ['login_email' => 'crosstenant@example.test'])
            ->assertOk();

        $this->asAdmin($this->adminA);
        $this->deleteJson($this->loginUrl($this->masjidB, $atB))->assertStatus(403);

        // Uniqueness is per TENANT, so this is not a conflict at all — and the
        // confirmation must not turn a cross-tenant row into one that A's admin
        // can strip. The pre-check is tenant-scoped by BelongsToMasjid; if it
        // ever were not, this would release B's address from A's request.
        $this->asAdmin($this->adminA);
        $this->postJson($this->loginUrl($this->masjidA, $atA), [
            'login_email' => 'crosstenant@example.test',
            'reassign_address' => true,
        ])->assertOk();

        $this->assertSame('crosstenant@example.test', $this->raw($atB)->login_email);
        $this->assertTrue($this->raw($atB)->familyLoginIsActive());
        $this->assertTrue($this->raw($atA)->familyLoginIsActive());
    }

    // =========================================== 4. THE CONCURRENT ENABLEMENT

    /**
     * Two admins, one address, the same second.
     *
     * The pre-check runs outside the transaction — and moving it inside would
     * not make it atomic either, because SELECT-then-UPDATE is not a lock. Both
     * requests clear it; `contacts_masjid_login_email_unique` refuses the loser.
     * That is the index doing its job and no corruption results. What the loser
     * must not get is a 500 for a collision this endpoint already had a sentence
     * written for.
     *
     * The race is reproduced rather than described: a one-shot `saving` listener
     * plants the competing row through the QUERY BUILDER (no model events, no
     * recursion) in the instant between the pre-check and the write — exactly
     * where the other request's commit would land.
     *
     * WHAT THE REPRODUCTION SHOWED, and it is not the 500 it was reported as:
     * `Illuminate\Database\QueryException extends PDOException extends
     * RuntimeException`, so the controller's existing `catch (RuntimeException)`
     * — written for refusals — already swallowed it and answered 422 with the
     * driver's message as the body:
     *
     *   {"status":"error","message":"SQLSTATE[23000]: Integrity constraint
     *    violation: 19 UNIQUE constraint failed: contacts.masjid_id,
     *    contacts.login_email (Connection: sqlite, … SQL: update \"contacts\"
     *    set \"login_email\" = race@example.test, …)"}
     *
     * So the status code was never the defect. The defect is that an admin was
     * shown a raw SQL statement, table and column names and a bound value in
     * place of the sentence this endpoint already had for the collision — and
     * that the swallowing catch would have done the same for ANY integrity
     * error. Both halves are asserted below.
     */
    #[Test]
    public function a_concurrent_enable_is_a_refusal_not_a_five_hundred(): void
    {
        $subject = $this->makeGuardian($this->masjidA);
        $rival = $this->makeGuardian($this->masjidA);

        $planted = false;

        Contact::saving(function (Contact $contact) use ($subject, $rival, &$planted) {
            if ($planted || (int) $contact->getKey() !== (int) $subject->id) {
                return;
            }

            $planted = true;

            Contact::withoutMasjidScope()
                ->whereKey($rival->id)
                ->update(['login_email' => 'race@example.test', 'login_enabled_at' => now()]);
        });

        $this->asAdmin($this->adminA);
        $response = $this->postJson($this->loginUrl($this->masjidA, $subject), ['login_email' => 'race@example.test']);

        $this->assertTrue($planted, 'the race was never actually created');

        $response
            ->assertStatus(422)
            ->assertJsonPath('status', 'error');

        $this->assertStringContainsString('just taken', $response->json('message'));

        // …and the driver's message does not reach the admin. A refusal that
        // prints the failing UPDATE hands an operator table names, column names
        // and a bound value, and tells them nothing they can act on.
        foreach (['SQLSTATE', 'Integrity constraint', 'update "contacts"', 'Connection:'] as $leak) {
            $this->assertStringNotContainsString($leak, (string) $response->json('message'));
        }

        // No corruption, because the index did its job: the loser holds nothing,
        // and the rolled-back transaction left no audit row claiming otherwise.
        //
        // Deliberately NOT asserted: the rival's address. The plant above shares
        // this request's connection and therefore its transaction, so the
        // rollback takes it too — an artifact of simulating two connections with
        // one. What the fix is about is entirely on the loser's side: the status
        // code and the sentence, both asserted above.
        $this->assertNull($this->raw($subject)->login_email);
        $this->assertNull($this->raw($subject)->login_enabled_at);
        $this->assertCount(0, $this->events($subject));
    }
}
