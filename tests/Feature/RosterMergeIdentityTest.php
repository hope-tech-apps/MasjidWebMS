<?php

namespace Tests\Feature;

use App\Models\BehaviorAward;
use App\Models\BehaviorSkill;
use App\Models\Contact;
use App\Models\FeePlan;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupThread;
use App\Models\Masjid;
use App\Models\Offering;
use App\Models\User;
use App\Services\Family\FamilyAccessService;
use App\Support\GroupAudience;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ConfirmsRosterClaims;
use Tests\TestCase;

/**
 * WHAT A DE-DUPLICATION IS ALLOWED TO CHANGE ABOUT WHO A ROSTER ROW IS ABOUT.
 *
 * Four defects, all measured on the wire, all in `RosterMergeService::carry()`
 * and the two places that describe what it did.
 *
 * ---------------------------------------------------------------------------
 * F1 — A PENDING CLAIM RE-POINTED BY A MERGE WAS NEITHER RE-OPENED NOR COUNTED
 * ---------------------------------------------------------------------------
 *
 * `unconfirm()` only fires on a row that IS confirmed, so a pending claim that
 * changed HOLDER did nothing and said nothing — and the row id was stable, so
 * the operator's already-drawn Confirm list still named it.
 * `ConfirmGroupMembershipsRequest` binds the agreement to a list of ids, which is
 * airtight against a row INSERTED after the draw and useless against a row that
 * changed underneath one:
 *
 *   1. anon POST …/register  payer {name:"Aisha Ahmed", email:"bilal@evil.test"}
 *      -> directory: #1 Fatima Ahmed | #2 Aisha Ahmed <bilal@evil.test>
 *   2. the real family signs up -> pending claim, membership #3, contact #3
 *   3. operator draws roster row #3:
 *        guardian=Aisha Ahmed <aisha@household.test> ward=Fatima Ahmed
 *   4. registrar de-duplicates two "Aisha Ahmed" rows (real -> the stranger's)
 *      MERGE -> 200 {"moved":1,"dropped":0,"unconfirmed":0}
 *   5. THE SAME ROW #3 NOW READS guardian=Aisha Ahmed <bilal@evil.test>
 *      confirm {"membership_ids":[3]} -> 200 {"confirmed":1,"pending_claims":1}
 *   6. eligible=true; family-login -> 200 login_email=bilal@evil.test
 *   7. his own token: awards 200, ḥifẓ 200, "Safeguarding: incident on 3 Sept"
 *
 * ---------------------------------------------------------------------------
 * F2 — RE-OPENING A ROW LEFT ITS RECORDED CONSENT STANDING
 * ---------------------------------------------------------------------------
 *
 *     EDGE AFTER: prov=self_asserted consent_scope='media' consent_granted_at=…
 *     PUT  …/consent -> 422 "still an unconfirmed claim … Confirm it first"
 *     GET  …/consent -> 200 {"scope":"media","covers_media":true}
 *
 * The server refused to WRITE the state it happily READ, consent obtained about
 * one child governed disclosure about another, and one confirm click opened the
 * photograph bytes.
 *
 * ---------------------------------------------------------------------------
 * F3 — THE `$over` LOOP SILENTLY DESTROYED A CONFIRMED GUARDIANSHIP
 * ---------------------------------------------------------------------------
 *
 *     MERGE (real child -> duplicate) -> 200
 *       roster {"moved":0,"dropped":2,"unconfirmed":0,
 *               "message":"0 roster entries moved onto this member and 2
 *                          duplicates dropped."}
 *     AFTER  /groups -> 200 {"data":[]}   family-login eligible:false
 *
 * `GroupMembershipsController::destroy` narrates the same cascade with three
 * counts; the merge verb had none of them.
 *
 * ---------------------------------------------------------------------------
 * F4 — THE UNCONFIRM FIRED ON ANY ROLE, AND SAID SOMETHING UNTRUE
 * ---------------------------------------------------------------------------
 *
 * A teacher's confirmed `leader` row, merged onto a target CARRYING HER OWN
 * ADDRESS, came out `self_asserted` and 403'd her out of her own classroom —
 * described to her office as "1 guardian entry … a confirmation names one
 * specific person, and this is no longer that person" about a row that named no
 * ward and moved none.
 */
class RosterMergeIdentityTest extends TestCase
{
    use RefreshDatabase;
    use ConfirmsRosterClaims;

    private Masjid $masjid;

    private User $admin;

    private Group $grade3;

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

        $this->masjid = $this->makeMasjid();
        $this->admin = $this->makeAdminFor($this->masjid);

        $this->grade3 = Group::factory()->create([
            'masjid_id' => $this->masjid->id,
            'kind' => Group::KIND_CLASS,
            'name' => 'Grade 3',
        ]);
    }

    // ================================================================ F1

    #[Test]
    public function a_merge_that_changes_who_a_pending_claim_names_retires_the_id_the_operator_drew(): void
    {
        // ---- step 1: the real child, already on the roster with a history.
        $fatima = Contact::factory()->create([
            'masjid_id' => $this->masjid->id,
            'first_name' => 'Fatima',
            'last_name' => 'Ahmed',
            'email' => 'fatima@household.test',
        ]);

        $fatimasRow = $this->seedMembership($this->grade3, $fatima, GroupMembership::ROLE_MEMBER);
        $award = $this->seedAward($fatimasRow, 'Left the classroom without permission');
        $this->seedParticipantThread($this->grade3, $fatimasRow, 'Safeguarding: incident on 3 Sept');

        [$offering, $plan] = $this->makeOffering($this->grade3, 'after-school-club');

        // ---- step 2: a stranger seeds the directory with a second "Aisha Ahmed".
        $this->postJson("/api/v1/offerings/{$offering->slug}/register", [
            'fee_plan_id' => $plan->id,
            'payer' => ['name' => 'Aisha Ahmed', 'email' => 'bilal@evil.test'],
            'registrants' => [],
            'data' => ['full_name' => 'Aisha Ahmed'],
        ], ['masjid-id' => (string) $this->masjid->id])->assertStatus(200);

        $stranger = $this->contactAt('bilal@evil.test');

        // ---- step 3: the REAL family signs up. A pending claim, nothing more.
        $this->postJson("/api/v1/offerings/{$offering->slug}/register", [
            'fee_plan_id' => $plan->id,
            'payer' => ['name' => 'Aisha Ahmed', 'email' => 'aisha@household.test'],
            'registrants' => [['name' => 'Fatima Ahmed', 'email' => 'fatima@household.test']],
            'data' => ['full_name' => 'Fatima Ahmed'],
        ], ['masjid-id' => (string) $this->masjid->id])->assertStatus(200);

        $realAisha = $this->contactAt('aisha@household.test');

        // ---- step 4: the operator DRAWS the roster and reads one claim.
        Sanctum::actingAs($this->admin);

        $drawn = $this->getJson($this->adminUrl("/groups/{$this->grade3->id}/members"))
            ->assertStatus(200)
            ->json('data');

        $claimId = collect($drawn)
            ->firstWhere(fn ($row) => (int) $row['contact_id'] === (int) $realAisha->id
                && $row['role'] === GroupMembership::ROLE_GUARDIAN)['id'] ?? null;

        $this->assertNotNull($claimId, 'the fixture must produce the claim the operator reads');
        $this->assertNotNull(
            GroupMembership::withoutMasjidScope()->whereKey($claimId)->value('source_registration_id'),
            'the fixture must carry the signup that asserted the claim — that is the judgeable evidence',
        );

        // What the operator's client is holding when the dialog opens: the id,
        // and the description of the row it drew.
        $drawnBody = $this->confirmBody([$claimId]);

        // ---- step 5: the registrar de-duplicates. Real -> the stranger's row.
        $merge = $this->postJson($this->adminUrl("/contacts/{$realAisha->id}/merge"), [
            'target_contact_id' => $stranger->id,
        ])->assertStatus(200);

        // ---- the id the operator agreed to is GONE, because what they read is
        //      not what is there.
        $this->assertNull(
            GroupMembership::withoutMasjidScope()->whereKey($claimId)->first(),
            'the row the operator drew still exists under the id they drew it by',
        );

        // ---- step 6: the click, exactly as the SPA sends it — the body it
        //      built when the roster was drawn, submitted after the merge.
        $this->postJson($this->adminUrl("/groups/{$this->grade3->id}/members/confirm"), $drawnBody)
            ->assertStatus(200)
            ->assertJsonPath('data.confirmed', 0)
            ->assertJsonPath('data.skipped', 1);

        // ---- step 7: so the stranger's row still opens nothing.
        $reissued = GroupMembership::withoutMasjidScope()
            ->where('group_id', $this->grade3->id)
            ->where('contact_id', $stranger->id)
            ->where('role', GroupMembership::ROLE_GUARDIAN)
            ->firstOrFail();

        $this->assertNotSame((int) $claimId, (int) $reissued->id);
        $this->assertTrue($reissued->isPendingClaim());
        $this->assertSame((int) $fatima->id, (int) $reissued->guardian_of_contact_id);

        // The evidence travels with the re-issued claim — re-opening a claim
        // must not also blind the person being asked to judge it.
        $this->assertNotNull($reissued->source_registration_id);

        app(TenantContext::class)->set($this->masjid->id);
        $this->assertFalse(app(FamilyAccessService::class)->mayHoldAFamilyLogin($stranger->fresh()));
        app(TenantContext::class)->forgetTenant();

        // The response SAYS it, by role, with the ward and the address on it.
        $merge->assertJsonPath('roster.guardian_claims_reissued', 1);

        $message = (string) $merge->json('roster.message');
        $this->assertStringContainsString('guardian', strtolower($message));
        $this->assertStringContainsString('bilal@evil.test', $message);
        $this->assertStringContainsString('Fatima Ahmed', $message);


        // And nothing about the child was destroyed on the way.
        $this->assertNotNull($award->fresh());
    }

    #[Test]
    public function a_merge_between_two_records_on_the_same_address_keeps_the_confirmation_and_the_id(): void
    {
        // THE OVER-REFUSAL GUARD. The previous round re-opened a claim on ANY
        // holder change, so the most ordinary de-duplication there is — one
        // person typed into the directory twice — cost a family their portal and
        // the office a click it was never told to make.
        //
        // Two contacts can only share a non-empty address here because a staff
        // member or an import wrote them: the one unauthenticated writer,
        // `Api\V1\OfferingRegistrationsController::createContact()`, "NEVER takes
        // an address another contact already holds".
        $child = Contact::factory()->create(['masjid_id' => $this->masjid->id]);
        $this->seedMembership($this->grade3, $child, GroupMembership::ROLE_MEMBER);

        $typedTwiceA = Contact::factory()->create([
            'masjid_id' => $this->masjid->id,
            'first_name' => 'Aisha',
            'last_name' => 'Ahmed',
            'email' => 'aisha@household.test',
        ]);

        $typedTwiceB = Contact::factory()->create([
            'masjid_id' => $this->masjid->id,
            'first_name' => 'Aisha',
            'last_name' => 'Ahmed',
            'email' => 'AISHA@Household.test',   // same mailbox, different typing
        ]);

        $edge = $this->seedMembership($this->grade3, $typedTwiceA, GroupMembership::ROLE_GUARDIAN, $child);
        $edge->update([
            'consent_scope' => GroupMembership::CONSENT_MEDIA,
            'consent_granted_at' => now(),
        ]);

        Sanctum::actingAs($this->admin);

        $this->postJson($this->adminUrl("/contacts/{$typedTwiceA->id}/merge"), [
            'target_contact_id' => $typedTwiceB->id,
        ])
            ->assertStatus(200)
            ->assertJsonPath('roster.unconfirmed', 0)
            ->assertJsonPath('roster.guardian_claims_reissued', 0);

        $moved = GroupMembership::withoutMasjidScope()->whereKey($edge->id)->first();

        $this->assertNotNull($moved, 'the same mailbox on both rows still cost the edge its id');
        $this->assertSame((int) $typedTwiceB->id, (int) $moved->contact_id);
        $this->assertTrue($moved->isConfirmed(), 'a de-duplication of one mailbox revoked a real guardianship');
        $this->assertTrue($moved->consentCovers(GroupAudience::DISCLOSURE_MEDIA));
    }

    // ================================================================ F2

    #[Test]
    public function re_opening_an_edge_withdraws_the_consent_recorded_on_it(): void
    {
        // The measured shape: consent banked on a CONFIRMED edge over a phantom
        // child, then the phantom merged into the real one.
        $realChild = Contact::factory()->create([
            'masjid_id' => $this->masjid->id,
            'first_name' => 'Fatima',
            'last_name' => 'Ahmed',
        ]);
        $this->seedMembership($this->grade3, $realChild, GroupMembership::ROLE_MEMBER);

        $phantom = Contact::factory()->create([
            'masjid_id' => $this->masjid->id,
            'first_name' => 'Fatima',
            'last_name' => 'Ahmed',
        ]);
        $this->seedMembership($this->grade3, $phantom, GroupMembership::ROLE_MEMBER);

        $parent = Contact::factory()->create([
            'masjid_id' => $this->masjid->id,
            'email' => 'claimant@evil.test',
        ]);

        $edge = $this->seedMembership($this->grade3, $parent, GroupMembership::ROLE_GUARDIAN, $phantom);

        Sanctum::actingAs($this->admin);

        $this->putJson(
            $this->adminUrl("/groups/{$this->grade3->id}/members/{$edge->id}/consent"),
            ['scope' => GroupMembership::CONSENT_MEDIA]
        )->assertStatus(200);

        $this->postJson($this->adminUrl("/contacts/{$phantom->id}/merge"), [
            'target_contact_id' => $realChild->id,
        ])->assertStatus(200);

        $after = GroupMembership::withoutMasjidScope()
            ->where('group_id', $this->grade3->id)
            ->where('contact_id', $parent->id)
            ->where('role', GroupMembership::ROLE_GUARDIAN)
            ->firstOrFail();

        $this->assertSame((int) $realChild->id, (int) $after->guardian_of_contact_id);
        $this->assertTrue($after->isPendingClaim());

        // THE STATE THE SERVER REFUSES TO WRITE MUST NOT BE READABLE EITHER.
        $this->assertNull($after->consent_granted_at);
        $this->assertNull($after->consent_scope);
        $this->assertFalse($after->hasConsent());

        $this->getJson($this->adminUrl("/groups/{$this->grade3->id}/members/{$after->id}/consent"))
            ->assertStatus(200)
            ->assertJsonPath('data.scope', null)
            ->assertJsonPath('data.covers_feed', false)
            ->assertJsonPath('data.covers_media', false);

        // …and confirming the claim does not silently re-open the photographs:
        // consent about a different child is not consent about this one.
        $this->postJson($this->adminUrl("/groups/{$this->grade3->id}/members/confirm"), $this->confirmBody([$after->id]))
            ->assertStatus(200)->assertJsonPath('data.confirmed', 1);

        $this->assertFalse($after->fresh()->consentCovers(GroupAudience::DISCLOSURE_MEDIA));
        $this->assertFalse($after->fresh()->consentCovers(GroupAudience::DISCLOSURE_FEED));
    }

    #[Test]
    public function unconfirming_cannot_leave_behind_a_row_the_consent_endpoint_refuses_to_create(): void
    {
        // The invariant on its own, asserted where it lives: any row that reads
        // as a pending claim must also read as having no consent, because
        // `GroupConsentController::update()` answers 422 for exactly that pair.
        $child = Contact::factory()->create(['masjid_id' => $this->masjid->id]);
        $parent = Contact::factory()->create(['masjid_id' => $this->masjid->id]);

        $this->seedMembership($this->grade3, $child, GroupMembership::ROLE_MEMBER);
        $edge = $this->seedMembership($this->grade3, $parent, GroupMembership::ROLE_GUARDIAN, $child);

        $edge->update([
            'consent_scope' => GroupMembership::CONSENT_MEDIA,
            'consent_granted_at' => now(),
        ]);

        $edge->unconfirm()->save();

        $this->assertTrue($edge->fresh()->isPendingClaim());
        $this->assertFalse($edge->fresh()->hasConsent());
        $this->assertNull($edge->fresh()->consent_scope);
    }

    // ================================================================ F3

    #[Test]
    public function a_merge_that_destroys_a_confirmed_guardian_edge_says_so_and_says_what_it_costs(): void
    {
        // A real parent, office-confirmed, with a live portal login. The office
        // merges two CRM rows FOR HER CHILD, in the direction that keeps the
        // duplicate — which nothing on any screen ranks.
        $realChild = Contact::factory()->create([
            'masjid_id' => $this->masjid->id,
            'first_name' => 'Ibrahim',
            'last_name' => 'Nur',
        ]);
        $realChildRow = $this->seedMembership($this->grade3, $realChild, GroupMembership::ROLE_MEMBER);
        $award = $this->seedAward($realChildRow, 'Recited Sūrat al-Mulk from memory');

        $duplicateChild = Contact::factory()->create([
            'masjid_id' => $this->masjid->id,
            'first_name' => 'Ibrahim',
            'last_name' => 'Nur',
        ]);
        $this->seedClaim($this->grade3, $duplicateChild, GroupMembership::ROLE_MEMBER);

        $parent = Contact::factory()->create([
            'masjid_id' => $this->masjid->id,
            'first_name' => 'Layla',
            'last_name' => 'Nur',
            'email' => 'layla@example.test',
        ]);

        // Her confirmed edge over the REAL row, and the claim the signup left
        // over the duplicate — the twin the merge drops the confirmed one for.
        $confirmed = $this->seedMembership($this->grade3, $parent, GroupMembership::ROLE_GUARDIAN, $realChild);
        $this->seedClaim($this->grade3, $parent, GroupMembership::ROLE_GUARDIAN, $duplicateChild);

        app(TenantContext::class)->set($this->masjid->id);
        app(FamilyAccessService::class)->enable($parent, 'layla@example.test', $this->admin, '127.0.0.1');
        app(TenantContext::class)->forgetTenant();

        Sanctum::actingAs($this->admin);

        $merge = $this->postJson($this->adminUrl("/contacts/{$realChild->id}/merge"), [
            'target_contact_id' => $duplicateChild->id,
        ])->assertStatus(200);

        $merge->assertJsonPath('roster.confirmed_guardian_edges_dropped', 1);
        $merge->assertJsonPath('roster.family_logins_left_without_a_ward', 1);

        $message = (string) $merge->json('roster.message');
        $this->assertStringContainsString('guardian', strtolower($message));
        $this->assertStringContainsString('sign-in', strtolower($message));

        // The measured harm is still there — the merge cannot carry the
        // confirmation, for the reason the service documents — but it is now on
        // the screen the operator did it on, and the door back is named.
        $this->assertNull($confirmed->fresh(), 'the fixture must exercise the cascade');

        app(TenantContext::class)->set($this->masjid->id);
        $this->assertFalse(app(FamilyAccessService::class)->mayHoldAFamilyLogin($parent->fresh()));
        app(TenantContext::class)->forgetTenant();

        // The credential is NOT burned by a roster edit, and the child's history
        // survived the merge.
        $this->assertNull($parent->fresh()->login_revoked_at);
        $this->assertNotNull($award->fresh());
    }

    #[Test]
    public function the_other_direction_costs_nothing_and_says_nothing_alarming(): void
    {
        // The same two rows, merged the other way. Nothing in the UI ranks them,
        // so the counts must be honest in BOTH directions rather than reading
        // like a defect in one of them.
        $realChild = Contact::factory()->create(['masjid_id' => $this->masjid->id]);
        $this->seedMembership($this->grade3, $realChild, GroupMembership::ROLE_MEMBER);

        $duplicateChild = Contact::factory()->create(['masjid_id' => $this->masjid->id]);
        $this->seedClaim($this->grade3, $duplicateChild, GroupMembership::ROLE_MEMBER);

        $parent = Contact::factory()->create([
            'masjid_id' => $this->masjid->id,
            'email' => 'layla@example.test',
        ]);

        $confirmed = $this->seedMembership($this->grade3, $parent, GroupMembership::ROLE_GUARDIAN, $realChild);
        $this->seedClaim($this->grade3, $parent, GroupMembership::ROLE_GUARDIAN, $duplicateChild);

        app(TenantContext::class)->set($this->masjid->id);
        app(FamilyAccessService::class)->enable($parent, 'layla@example.test', $this->admin, '127.0.0.1');
        app(TenantContext::class)->forgetTenant();

        Sanctum::actingAs($this->admin);

        $this->postJson($this->adminUrl("/contacts/{$duplicateChild->id}/merge"), [
            'target_contact_id' => $realChild->id,
        ])
            ->assertStatus(200)
            ->assertJsonPath('roster.confirmed_guardian_edges_dropped', 0)
            ->assertJsonPath('roster.family_logins_left_without_a_ward', 0);

        $this->assertTrue($confirmed->fresh()->isConfirmed());

        app(TenantContext::class)->set($this->masjid->id);
        $this->assertTrue(app(FamilyAccessService::class)->mayHoldAFamilyLogin($parent->fresh()));
        app(TenantContext::class)->forgetTenant();
    }

    // ================================================================ F4

    #[Test]
    public function a_teachers_leader_row_survives_a_merge_onto_a_record_carrying_her_own_address(): void
    {
        // The measured case: her office de-duplicates her two CRM rows, both of
        // which carry HER address. The previous round unconfirmed the leader row
        // on any holder change, and `GroupAudience::membershipsFor()` applies
        // `confirmed()` to every role — so an ordinary tidy-up 403'd a teacher
        // out of her own classroom.
        $teacher = Contact::factory()->create([
            'masjid_id' => $this->masjid->id,
            'first_name' => 'Amina',
            'last_name' => 'Teacher',
            'email' => 'amina@school.test',
        ]);

        $herOtherRow = Contact::factory()->create([
            'masjid_id' => $this->masjid->id,
            'first_name' => 'Amina',
            'last_name' => 'Teacher',
            'email' => 'amina@school.test',
        ]);

        $leaderRow = $this->seedMembership($this->grade3, $teacher, GroupMembership::ROLE_LEADER);

        $herStaffLogin = User::factory()->create([
            'type' => 'MasjidAdmin',
            'email' => 'amina@school.test',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);

        // BEFORE: she reads NOTHING, and that is WHY the office merges. Two
        // contacts on one address make `GroupAudience::identitiesFor()`
        // ambiguous, and "ambiguous identity is no identity" — so the
        // de-duplication IS the remedy for her 403, not a risk to it.
        app(TenantContext::class)->set($this->masjid->id);
        $this->assertFalse(
            app(GroupAudience::class)->mayReceive($herStaffLogin, $this->grade3, GroupAudience::DISCLOSURE_FEED),
            'the fixture must start from the ambiguity the merge exists to clear',
        );
        app(TenantContext::class)->forgetTenant();

        Sanctum::actingAs($this->admin);

        $merge = $this->postJson($this->adminUrl("/contacts/{$teacher->id}/merge"), [
            'target_contact_id' => $herOtherRow->id,
        ])
            ->assertStatus(200)
            ->assertJsonPath('roster.unconfirmed', 0)
            ->assertJsonPath('roster.participant_rows_reopened', 0);

        $this->assertTrue(
            GroupMembership::withoutMasjidScope()->whereKey($leaderRow->id)->firstOrFail()->isConfirmed(),
            'a de-duplication of one mailbox demoted a teacher out of her own classroom',
        );

        // AFTER: one contact, one identity, and the classroom she teaches opens
        // again. Measured on the previous round, this stayed 403 for good —
        // the merge unconfirmed her leader row and `membershipsFor()` applies
        // `confirmed()` to every role.
        app(TenantContext::class)->set($this->masjid->id);
        $this->assertTrue(
            app(GroupAudience::class)->mayReceive($herStaffLogin, $this->grade3, GroupAudience::DISCLOSURE_FEED),
            'the de-duplication that was supposed to give a teacher her classroom back took it away',
        );
        app(TenantContext::class)->forgetTenant();

        $this->assertStringNotContainsString('guardian', strtolower((string) $merge->json('roster.message')));
    }

    #[Test]
    public function a_participant_row_that_lands_on_a_different_address_is_re_opened_in_words_that_are_true(): void
    {
        // The other half of the decision, said deliberately: a `leader`/`member`
        // row IS re-opened when the address it is read through changes, because
        // that is the only case in which somebody else could be read into the
        // classroom through it. What must not happen is describing it as a
        // guardianship over a named ward — the row names no ward and moved none.
        $teacher = Contact::factory()->create([
            'masjid_id' => $this->masjid->id,
            'first_name' => 'Amina',
            'last_name' => 'Teacher',
            'email' => 'amina@school.test',
        ]);

        $leaderRow = $this->seedMembership($this->grade3, $teacher, GroupMembership::ROLE_LEADER);

        [$offering, $plan] = $this->makeOffering($this->grade3, 'leader-launder');

        $this->postJson("/api/v1/offerings/{$offering->slug}/register", [
            'fee_plan_id' => $plan->id,
            'payer' => ['name' => 'Amina Teacher', 'email' => 'stranger@evil.test'],
            'registrants' => [],
            'data' => ['full_name' => 'Amina Teacher'],
        ], ['masjid-id' => (string) $this->masjid->id])->assertStatus(200);

        $stranger = $this->contactAt('stranger@evil.test');

        Sanctum::actingAs($this->admin);

        $merge = $this->postJson($this->adminUrl("/contacts/{$teacher->id}/merge"), [
            'target_contact_id' => $stranger->id,
        ])
            ->assertStatus(200)
            ->assertJsonPath('roster.participant_rows_reopened', 1)
            ->assertJsonPath('roster.guardian_claims_reissued', 0);

        $moved = GroupMembership::withoutMasjidScope()->whereKey($leaderRow->id)->firstOrFail();

        $this->assertSame((int) $stranger->id, (int) $moved->contact_id);
        $this->assertFalse(
            $moved->isConfirmed(),
            'a stranger-authored row absorbed a confirmed leadership of a classroom',
        );

        // The sentence the office is shown must be true of a leader row.
        $message = strtolower((string) $merge->json('roster.message'));
        $this->assertStringNotContainsString('guardian', $message);
        $this->assertStringContainsString('email address', $message);

        // And the recovery the message names actually works, in one click.
        $this->postJson($this->adminUrl("/groups/{$this->grade3->id}/members/confirm"), $this->confirmBody([$moved->id]))
            ->assertStatus(200)->assertJsonPath('data.confirmed', 1);

        $this->assertTrue($moved->fresh()->isConfirmed());
    }

    // ============================================================== helpers

    private function adminUrl(string $path = ''): string
    {
        return "/api/admin/masjids/{$this->masjid->id}" . $path;
    }

    private function contactAt(string $email): Contact
    {
        Auth::forgetGuards();
        app(TenantContext::class)->forgetTenant();

        return Contact::withoutMasjidScope()
            ->whereRaw('LOWER(TRIM(email)) = ?', [$email])
            ->firstOrFail();
    }

    private function makeMasjid(array $overrides = []): Masjid
    {
        return Masjid::create(array_merge([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'crm_enabled' => true,
            'stripe_account_id' => 'acct_TEST' . uniqid(),
            'stripe_charges_enabled' => true,
        ], $overrides));
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

    /** @return array{0: Offering, 1: FeePlan} */
    private function makeOffering(Group $group, string $slug): array
    {
        $offering = Offering::factory()
            ->forMasjid($this->masjid)
            ->withRoster($group)
            ->create(['slug' => $slug]);

        $plan = FeePlan::factory()->free()->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $offering->id,
        ]);

        return [$offering, $plan];
    }

    private function seedMembership(
        Group $group,
        Contact $contact,
        string $role,
        ?Contact $ward = null,
    ): GroupMembership {
        $membership = new GroupMembership([
            'masjid_id' => $this->masjid->id,
            'group_id' => $group->id,
            'contact_id' => $contact->id,
            'role' => $role,
            'guardian_of_contact_id' => $ward?->id,
        ]);

        $membership->confirmedByStaff($this->admin)->save();

        return $membership;
    }

    private function seedClaim(
        Group $group,
        Contact $contact,
        string $role,
        ?Contact $ward = null,
    ): GroupMembership {
        $membership = new GroupMembership([
            'masjid_id' => $this->masjid->id,
            'group_id' => $group->id,
            'contact_id' => $contact->id,
            'role' => $role,
            'guardian_of_contact_id' => $ward?->id,
        ]);

        $membership->selfAssertedFrom(null)->save();

        return $membership;
    }

    private function seedAward(GroupMembership $subject, string $label): BehaviorAward
    {
        return BehaviorAward::factory()->create([
            'masjid_id' => $this->masjid->id,
            'group_id' => $subject->group_id,
            'group_membership_id' => $subject->id,
            'skill_label' => $label,
            'skill_polarity' => BehaviorSkill::POLARITY_NEGATIVE,
            'points' => -3,
        ]);
    }

    private function seedParticipantThread(Group $group, GroupMembership $about, string $subject): GroupThread
    {
        return GroupThread::factory()->create([
            'masjid_id' => $this->masjid->id,
            'group_id' => $group->id,
            'scope' => GroupThread::SCOPE_PARTICIPANT,
            'about_membership_id' => $about->id,
            'subject' => $subject,
        ]);
    }
}
