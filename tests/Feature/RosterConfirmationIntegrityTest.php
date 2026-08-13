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
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * WHAT AN OPERATOR'S CLICK IS ALLOWED TO MEAN (defects B, C, E, F, H).
 *
 * Provenance made "on whose authority does this row exist" expressible, and the
 * READ side honours it. These four defects are all on the WRITE side — the
 * `self_asserted -> confirmed` transition itself — and they share one shape:
 * authority is granted by an act that is not the decision the authority is
 * defined as recording.
 *
 * ---------------------------------------------------------------------------
 * B — "CONFIRM ALL 8" CONFIRMED 9
 * ---------------------------------------------------------------------------
 *
 * An empty `membership_ids` meant "every pending claim in this group at the
 * moment the request LANDS", not the rows the operator was shown. No `as_of`, no
 * version, no id list. Measured, with no merge, no operator error, and an
 * attacker holding a legitimately-issued live credential as a real parent of a
 * real child:
 *
 *     operator loads roster            meta.pending_claims = 8
 *     attacker POSTs while the dialog is open -> 200, guardian edge
 *                                      Bilal -> Salma, self_asserted
 *                                      (NOT on the screen the operator read)
 *     BEFORE  awards 403  hifz 403  threads 403
 *     operator clicks "Confirm all 8" -> POST .../members/confirm {}
 *         {"confirmed":9,"pending_claims":0}
 *     AFTER   awards 200 ["Left the classroom without permission"]
 *             hifz 200   threads 200 ["Safeguarding: incident on 3 Sept"]
 *
 * Consent is not consulted for a participant thread or a child's records, by a
 * documented decision on `GroupAudience::mayReceiveThread()`, so one click is
 * the child's behaviour file and the safeguarding conversation with no second
 * gate behind it. The fix binds the agreement to what was shown: the caller
 * names the rows.
 *
 * ---------------------------------------------------------------------------
 * C — THE MERGE STILL LAUNDERED AUTHORITY, THROUGH THE OTHER LOOP
 * ---------------------------------------------------------------------------
 *
 * `RosterMergeService::carry()` has two loops. The `$over` loop unconfirmed when
 * the WARD changed. The `$own` loop re-pointed the guardian's own `contact_id`
 * and left `provenance = confirmed`, arguing that "only the guardian's own CRM
 * row changed, which is the thing the merge asserts was always one human".
 * Measured:
 *
 *     1. anon POST …/register { payer: {"Fatima Ahmed","attacker@evil.test"},
 *        registrants: [] } -> 200   (a second "Fatima Ahmed" in the directory)
 *     2. registrar merges the REAL Fatima INTO that row -> 200
 *        {"moved":1,"dropped":0,"unconfirmed":0,"confirmations_carried":0}
 *        grade3: ["member:1>:confirmed", "guardian:3>1:confirmed"]
 *     3. survivor eligible = true
 *     4. office enables sign-in -> login_email attacker@evil.test
 *     5. awards 200 · threads 200 · posts 200 · attachment bytes 200
 *
 * Recorded CONSENT travelled with it, because the consent columns live on the
 * moved row.
 *
 * ---------------------------------------------------------------------------
 * E — REMOVING A CLAIM SILENTLY DESTROYED A CONFIRMED GUARDIAN EDGE
 * ---------------------------------------------------------------------------
 *
 * `GroupMembership`'s `deleting` cascade removes the guardian edges pointing at
 * a participant. `confirm()`'s docblock treats `destroy()` as the reject
 * mechanism. Measured: the office confirms the guardian row, enables sign-in,
 * then removes the child's still-pending ENROLMENT line — 200, and the
 * staff-confirmed guardian edge is gone with no event, the parent is ineligible,
 * and the credential is still live and reading nothing. Nothing on the response
 * mentions it.
 *
 * ---------------------------------------------------------------------------
 * F — CONSENT COULD BE RECORDED ON AN UNCONFIRMED CLAIM
 * ---------------------------------------------------------------------------
 *
 * `GroupConsentController` checked `isGuardian()` and never `isConfirmed()`, so
 * an office banked `media` consent against a claim nobody had stood behind — and
 * the feed and the photograph bytes opened the instant B's button landed.
 */
class RosterConfirmationIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjid;

    private User $admin;

    private Group $grade5;

    private Group $grade3;

    /** The child whose records the anonymous caller went after. */
    private Contact $salma;

    private GroupMembership $salmaMembership;

    /** The attacker: a real parent, with a legitimately-issued credential. */
    private Contact $bilal;

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

        $this->grade5 = Group::factory()->create([
            'masjid_id' => $this->masjid->id,
            'kind' => Group::KIND_CLASS,
            'name' => 'Grade 5',
        ]);

        $this->grade3 = Group::factory()->create([
            'masjid_id' => $this->masjid->id,
            'kind' => Group::KIND_CLASS,
            'name' => 'Grade 3',
        ]);

        $this->salma = Contact::factory()->create([
            'masjid_id' => $this->masjid->id,
            'first_name' => 'Salma',
            'last_name' => 'Other',
            'email' => 'salma.other@school.test',
        ]);
        $this->salmaMembership = $this->seedMembership($this->grade5, $this->salma, GroupMembership::ROLE_MEMBER);

        $this->bilal = Contact::factory()->create([
            'masjid_id' => $this->masjid->id,
            'first_name' => 'Bilal',
            'last_name' => 'Attacker',
            'email' => 'bilal@example.test',
        ]);

        $bilalsChild = Contact::factory()->create(['masjid_id' => $this->masjid->id]);
        $this->seedMembership($this->grade3, $bilalsChild, GroupMembership::ROLE_MEMBER);
        $this->seedMembership($this->grade3, $this->bilal, GroupMembership::ROLE_GUARDIAN, $bilalsChild);

        app(TenantContext::class)->set($this->masjid->id);
        app(FamilyAccessService::class)->enable($this->bilal, 'bilal@example.test', $this->admin, '127.0.0.1');
        $this->bilal->refresh();

        app(TenantContext::class)->forgetTenant();
    }

    // ======================================================== B — the bulk button

    #[Test]
    public function the_bulk_click_as_the_spa_actually_sent_it_confirms_nothing_off_the_screen(): void
    {
        // THE MEASURED REPRODUCTION, on the exact wire shape: `confirmAllClaims`
        // called `runConfirm()` with no ids, so the body was `{}`.
        $award = $this->seedAward($this->salmaMembership, 'Left the classroom without permission');
        $this->seedParticipantThread($this->grade5, $this->salmaMembership, 'Safeguarding: incident on 3 Sept');

        for ($i = 0; $i < 8; $i++) {
            $child = Contact::factory()->create(['masjid_id' => $this->masjid->id]);
            $this->seedClaim($this->grade5, $child, GroupMembership::ROLE_MEMBER);
        }

        // The operator loads the roster and reads eight.
        Sanctum::actingAs($this->admin);
        $this->getJson($this->adminUrl("/groups/{$this->grade5->id}/members"))
            ->assertStatus(200)
            ->assertJsonPath('meta.pending_claims', 8);

        // The attacker POSTs while the dialog is open. No merge, no operator
        // error — an ordinary anonymous registration naming a real child.
        [$offering, $plan] = $this->makeOffering($this->grade5, 'while-the-dialog-is-open');

        Auth::forgetGuards();
        app(TenantContext::class)->forgetTenant();

        $this->postJson("/api/v1/offerings/{$offering->slug}/register", [
            'fee_plan_id' => $plan->id,
            'payer' => ['name' => 'Bilal Attacker', 'email' => 'bilal@example.test'],
            'registrants' => [['name' => 'Salma Other', 'email' => 'salma.other@school.test']],
            'data' => ['full_name' => 'Salma Other'],
        ], ['masjid-id' => (string) $this->masjid->id])->assertStatus(200);

        // BEFORE: the claim grants nothing.
        $this->as($this->bilal)
            ->getJson($this->familyUrl("/groups/{$this->grade5->id}/members/{$this->salmaMembership->id}/awards"))
            ->assertStatus(403);

        // The click. Measured before the fix: 200, {"confirmed":9}.
        Auth::forgetGuards();
        Sanctum::actingAs($this->admin);

        $this->postJson($this->adminUrl("/groups/{$this->grade5->id}/members/confirm"), []);

        // AFTER: the row that was never on the screen is still a claim, and it
        // still opens nothing. Measured before the fix: awards 200, hifz 200,
        // threads 200 ["Safeguarding: incident on 3 Sept"].
        $edge = GroupMembership::withoutMasjidScope()
            ->where('role', GroupMembership::ROLE_GUARDIAN)
            ->where('contact_id', $this->bilal->id)
            ->where('guardian_of_contact_id', $this->salma->id)
            ->first();

        $this->assertNotNull($edge, 'the fixture must exercise the edge, not the absence of one');
        $this->assertSame(
            GroupMembership::PROVENANCE_SELF_ASSERTED,
            $edge->provenance,
            'a click on eight named rows granted authority over a ninth nobody read',
        );

        $this->as($this->bilal)
            ->getJson($this->familyUrl("/groups/{$this->grade5->id}/members/{$this->salmaMembership->id}/awards"))
            ->assertStatus(403);

        $this->as($this->bilal)
            ->getJson($this->familyUrl("/groups/{$this->grade5->id}/members/{$this->salmaMembership->id}/hifz"))
            ->assertStatus(403);

        $this->as($this->bilal)
            ->getJson($this->familyUrl("/groups/{$this->grade5->id}/threads"))
            ->assertStatus(403);

        $this->assertNotNull($award->fresh());
    }

    #[Test]
    public function the_rows_the_operator_was_shown_are_confirmed_by_naming_them(): void
    {
        // The bulk affordance still works and is still ONE click: the client
        // sends the ids it drew, so the 200-signup case is a 200-element array
        // rather than 200 dialogs.
        $shown = [];

        for ($i = 0; $i < 8; $i++) {
            $child = Contact::factory()->create(['masjid_id' => $this->masjid->id]);
            $shown[] = $this->seedClaim($this->grade5, $child, GroupMembership::ROLE_MEMBER)->id;
        }

        Sanctum::actingAs($this->admin);

        $this->postJson($this->adminUrl("/groups/{$this->grade5->id}/members/confirm"), [
            'membership_ids' => $shown,
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.confirmed', 8)
            ->assertJsonPath('data.pending_claims', 0);

        foreach ($shown as $id) {
            $this->assertTrue(GroupMembership::withoutMasjidScope()->findOrFail($id)->isConfirmed());
        }
    }

    #[Test]
    public function an_empty_confirm_body_is_refused_rather_than_meaning_everything(): void
    {
        // The exact wire shape the SPA sent: POST with no ids at all. It used to
        // mean "every pending claim at the moment this LANDS".
        $child = Contact::factory()->create(['masjid_id' => $this->masjid->id]);
        $claim = $this->seedClaim($this->grade5, $child, GroupMembership::ROLE_MEMBER);

        Sanctum::actingAs($this->admin);

        $this->postJson($this->adminUrl("/groups/{$this->grade5->id}/members/confirm"), [])
            ->assertStatus(422);

        $this->assertSame(GroupMembership::PROVENANCE_SELF_ASSERTED, $claim->fresh()->provenance);
    }

    #[Test]
    public function naming_rows_that_are_no_longer_pending_is_reported_and_not_refused(): void
    {
        // A stale screen re-submitting a list somebody else already confirmed
        // must be an honest no-op, not a 422 an operator cannot act on. What is
        // new is that the count of skipped rows is SAID, because "8 confirmed"
        // for a list of 10 is the shape B hid in.
        $ids = [];

        for ($i = 0; $i < 3; $i++) {
            $child = Contact::factory()->create(['masjid_id' => $this->masjid->id]);
            $ids[] = $this->seedClaim($this->grade5, $child, GroupMembership::ROLE_MEMBER)->id;
        }

        GroupMembership::withoutMasjidScope()->whereKey($ids[0])->first()
            ->confirmedByStaff($this->admin)->save();

        Sanctum::actingAs($this->admin);

        $this->postJson($this->adminUrl("/groups/{$this->grade5->id}/members/confirm"), [
            'membership_ids' => array_merge($ids, [999999]),
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.confirmed', 2)
            ->assertJsonPath('data.skipped', 2);
    }

    #[Test]
    public function confirming_still_reaches_only_this_group_and_this_tenant(): void
    {
        $other = $this->makeMasjid();
        $otherGroup = Group::factory()->create(['masjid_id' => $other->id, 'kind' => Group::KIND_CLASS]);
        $otherChild = Contact::factory()->create(['masjid_id' => $other->id]);

        $foreign = new GroupMembership([
            'masjid_id' => $other->id,
            'group_id' => $otherGroup->id,
            'contact_id' => $otherChild->id,
            'role' => GroupMembership::ROLE_MEMBER,
        ]);
        $foreign->selfAssertedFrom(null)->save();

        $child = Contact::factory()->create(['masjid_id' => $this->masjid->id]);
        $mine = $this->seedClaim($this->grade5, $child, GroupMembership::ROLE_MEMBER);

        $elsewhere = Contact::factory()->create(['masjid_id' => $this->masjid->id]);
        $otherRoom = $this->seedClaim($this->grade3, $elsewhere, GroupMembership::ROLE_MEMBER);

        Sanctum::actingAs($this->admin);

        $this->postJson($this->adminUrl("/groups/{$this->grade5->id}/members/confirm"), [
            'membership_ids' => [$mine->id, $otherRoom->id, $foreign->id],
        ])->assertStatus(200)->assertJsonPath('data.confirmed', 1);

        $this->assertTrue($mine->fresh()->isConfirmed());
        $this->assertFalse($otherRoom->fresh()->isConfirmed());
        $this->assertFalse($foreign->fresh()->isConfirmed());
    }

    // ================================================= C — the merge's own loop

    #[Test]
    public function a_merge_does_not_carry_a_confirmation_onto_a_stranger_authored_row(): void
    {
        // 1. an anonymous POST puts a second "Fatima Ahmed" in the directory.
        $fatima = Contact::factory()->create([
            'masjid_id' => $this->masjid->id,
            'first_name' => 'Fatima',
            'last_name' => 'Ahmed',
            'email' => 'fatima@example.test',
        ]);

        $child = Contact::factory()->create([
            'masjid_id' => $this->masjid->id,
            'first_name' => 'Amina',
            'last_name' => 'Ahmed',
        ]);

        $childMembership = $this->seedMembership($this->grade3, $child, GroupMembership::ROLE_MEMBER);
        $edge = $this->seedMembership($this->grade3, $fatima, GroupMembership::ROLE_GUARDIAN, $child);

        // Consent recorded on the confirmed edge — it lives on the moved row.
        $edge->update([
            'consent_scope' => GroupMembership::CONSENT_MEDIA,
            'consent_granted_at' => now(),
        ]);

        $award = $this->seedAward($childMembership, 'Left the classroom without permission');
        $this->seedParticipantThread($this->grade3, $childMembership, 'Safeguarding: incident on 3 Sept');

        [$offering, $plan] = $this->makeOffering($this->grade5, 'directory-seeding');

        $this->postJson("/api/v1/offerings/{$offering->slug}/register", [
            'fee_plan_id' => $plan->id,
            'payer' => ['name' => 'Fatima Ahmed', 'email' => 'attacker@evil.test'],
            'registrants' => [],
            'data' => ['full_name' => 'Fatima Ahmed'],
        ], ['masjid-id' => (string) $this->masjid->id])->assertStatus(200);

        $stranger = Contact::withoutMasjidScope()
            ->whereRaw('LOWER(TRIM(email)) = ?', ['attacker@evil.test'])
            ->firstOrFail();

        // 2. the registrar merges the REAL Fatima INTO that row. Nothing in the
        //    UI ranks the two, so the operator picks the direction.
        Sanctum::actingAs($this->admin);

        $response = $this->postJson($this->adminUrl("/contacts/{$fatima->id}/merge"), [
            'target_contact_id' => $stranger->id,
        ])->assertStatus(200);

        $moved = GroupMembership::withoutMasjidScope()
            ->where('group_id', $this->grade3->id)
            ->where('contact_id', $stranger->id)
            ->where('role', GroupMembership::ROLE_GUARDIAN)
            ->first();

        $this->assertNotNull($moved, 'the edge must MOVE — destroying it is the other failure');
        $this->assertSame(
            GroupMembership::PROVENANCE_SELF_ASSERTED,
            $moved->provenance,
            'a confirmation rode a de-duplication onto a row nobody vouched for',
        );

        // The report says so rather than narrating only the other direction.
        $response->assertJsonPath('roster.unconfirmed', 1);

        // 3. eligibility, which is what the office reads next.
        app(TenantContext::class)->set($this->masjid->id);
        $this->assertFalse(app(FamilyAccessService::class)->mayHoldAFamilyLogin($stranger->fresh()));
        app(TenantContext::class)->forgetTenant();

        $this->assertNotNull($award->fresh());
    }

    #[Test]
    public function the_same_holds_when_the_operator_merges_the_other_way(): void
    {
        // Nothing in the UI ranks the two rows, so the rule must not depend on
        // which one the operator picked as the survivor.
        $fatima = Contact::factory()->create([
            'masjid_id' => $this->masjid->id,
            'first_name' => 'Fatima',
            'last_name' => 'Ahmed',
            'email' => 'fatima@example.test',
        ]);

        $child = Contact::factory()->create(['masjid_id' => $this->masjid->id]);
        $this->seedMembership($this->grade3, $child, GroupMembership::ROLE_MEMBER);
        $this->seedMembership($this->grade3, $fatima, GroupMembership::ROLE_GUARDIAN, $child);

        [$offering, $plan] = $this->makeOffering($this->grade5, 'other-way');

        $this->postJson("/api/v1/offerings/{$offering->slug}/register", [
            'fee_plan_id' => $plan->id,
            'payer' => ['name' => 'Fatima Ahmed', 'email' => 'attacker@evil.test'],
            'registrants' => [],
            'data' => ['full_name' => 'Fatima Ahmed'],
        ], ['masjid-id' => (string) $this->masjid->id])->assertStatus(200);

        $stranger = Contact::withoutMasjidScope()
            ->whereRaw('LOWER(TRIM(email)) = ?', ['attacker@evil.test'])
            ->firstOrFail();

        Sanctum::actingAs($this->admin);

        // stranger INTO the real Fatima — the "right" direction.
        $this->postJson($this->adminUrl("/contacts/{$stranger->id}/merge"), [
            'target_contact_id' => $fatima->id,
        ])->assertStatus(200);

        // The real Fatima's own confirmed edge never moved, so it is untouched…
        $kept = GroupMembership::withoutMasjidScope()
            ->where('group_id', $this->grade3->id)
            ->where('contact_id', $fatima->id)
            ->where('role', GroupMembership::ROLE_GUARDIAN)
            ->firstOrFail();

        $this->assertTrue($kept->isConfirmed(), 'a merge that moved nothing onto this row revoked it');

        // …and the stranger's own self-asserted row moved across as a claim.
        $movedIn = GroupMembership::withoutMasjidScope()
            ->where('contact_id', $fatima->id)
            ->where('group_id', $this->grade5->id)
            ->first();

        if ($movedIn !== null) {
            $this->assertFalse($movedIn->isConfirmed(), 'the merge upgraded a claim it merely moved');
        }
    }

    #[Test]
    public function a_merge_does_not_carry_a_teachers_confirmed_leader_row_either(): void
    {
        $teacher = Contact::factory()->create([
            'masjid_id' => $this->masjid->id,
            'first_name' => 'Amina',
            'last_name' => 'Teacher',
            'email' => 'amina@school.test',
        ]);

        $this->seedMembership($this->grade5, $teacher, GroupMembership::ROLE_LEADER);

        [$offering, $plan] = $this->makeOffering($this->grade3, 'leader-launder');

        $this->postJson("/api/v1/offerings/{$offering->slug}/register", [
            'fee_plan_id' => $plan->id,
            'payer' => ['name' => 'Amina Teacher', 'email' => 'stranger@evil.test'],
            'registrants' => [],
            'data' => ['full_name' => 'Amina Teacher'],
        ], ['masjid-id' => (string) $this->masjid->id])->assertStatus(200);

        $stranger = Contact::withoutMasjidScope()
            ->whereRaw('LOWER(TRIM(email)) = ?', ['stranger@evil.test'])
            ->firstOrFail();

        Sanctum::actingAs($this->admin);

        $this->postJson($this->adminUrl("/contacts/{$teacher->id}/merge"), [
            'target_contact_id' => $stranger->id,
        ])->assertStatus(200);

        $leader = GroupMembership::withoutMasjidScope()
            ->where('group_id', $this->grade5->id)
            ->where('contact_id', $stranger->id)
            ->where('role', GroupMembership::ROLE_LEADER)
            ->firstOrFail();

        $this->assertFalse(
            $leader->isConfirmed(),
            'a stranger-authored row absorbed a confirmed leadership of a classroom',
        );
    }

    #[Test]
    public function a_merge_never_destroys_the_roster_edge_it_declines_to_vouch_for(): void
    {
        // The OTHER failure this file must not re-open: R4 measured a merge that
        // deleted the guardian edge outright and then told the operator to
        // "enable sign-in on them", which answered 422. Moving the row and
        // dropping only the AUTHORITY keeps the remedy — one click on the
        // roster — reachable.
        $p1 = Contact::factory()->create(['masjid_id' => $this->masjid->id, 'email' => 'p1@example.test']);
        $p2 = Contact::factory()->create(['masjid_id' => $this->masjid->id, 'email' => 'p2@example.test']);

        $child = Contact::factory()->create(['masjid_id' => $this->masjid->id]);
        $this->seedMembership($this->grade3, $child, GroupMembership::ROLE_MEMBER);
        $this->seedMembership($this->grade3, $p1, GroupMembership::ROLE_GUARDIAN, $child);

        Sanctum::actingAs($this->admin);

        $this->postJson($this->adminUrl("/contacts/{$p1->id}/merge"), [
            'target_contact_id' => $p2->id,
        ])->assertStatus(200)->assertJsonPath('roster.unconfirmed', 1);

        $edge = GroupMembership::withoutMasjidScope()
            ->where('group_id', $this->grade3->id)
            ->where('contact_id', $p2->id)
            ->where('role', GroupMembership::ROLE_GUARDIAN)
            ->first();

        $this->assertNotNull($edge, 'the merge destroyed the edge instead of moving it');
        $this->assertFalse($edge->isConfirmed());

        // And the remedy the response names actually works.
        $this->postJson($this->adminUrl("/groups/{$this->grade3->id}/members/confirm"), [
            'membership_ids' => [$edge->id],
        ])->assertStatus(200)->assertJsonPath('data.confirmed', 1);

        app(TenantContext::class)->set($this->masjid->id);
        $this->assertTrue(app(FamilyAccessService::class)->mayHoldAFamilyLogin($p2->fresh()));
        app(TenantContext::class)->forgetTenant();
    }

    // ============================================ E — removing a claim

    #[Test]
    public function removing_an_enrolment_says_which_confirmed_guardian_edges_go_with_it(): void
    {
        $child = Contact::factory()->create([
            'masjid_id' => $this->masjid->id,
            'first_name' => 'Ibrahim',
            'last_name' => 'Nur',
        ]);

        $parent = Contact::factory()->create([
            'masjid_id' => $this->masjid->id,
            'first_name' => 'Layla',
            'last_name' => 'Nur',
            'email' => 'layla@example.test',
        ]);

        // The child's enrolment is still a pending claim; the guardian edge over
        // them was confirmed by the office, which then enabled sign-in.
        $enrolment = $this->seedClaim($this->grade5, $child, GroupMembership::ROLE_MEMBER);
        $edge = $this->seedMembership($this->grade5, $parent, GroupMembership::ROLE_GUARDIAN, $child);

        app(TenantContext::class)->set($this->masjid->id);
        app(FamilyAccessService::class)->enable($parent, 'layla@example.test', $this->admin, '127.0.0.1');
        app(TenantContext::class)->forgetTenant();

        Sanctum::actingAs($this->admin);

        $response = $this->deleteJson(
            $this->adminUrl("/groups/{$this->grade5->id}/members/{$enrolment->id}")
        )->assertStatus(200);

        $this->assertNull($edge->fresh(), 'the fixture must exercise the cascade');

        // What is new: the response SAYS it, and says what it costs the parent.
        $response->assertJsonPath('data.cascade.guardian_edges_removed', 1);
        $response->assertJsonPath('data.cascade.confirmed_guardian_edges_removed', 1);

        $body = $response->json('message');
        $this->assertIsString($body);
        $this->assertStringContainsString('guardian', strtolower($body));

        // The credential is deliberately NOT destroyed — a de-duplication or a
        // roster edit must never burn one — but the office is told it now opens
        // nothing.
        $this->assertNull($parent->fresh()->login_revoked_at);
        $response->assertJsonPath('data.cascade.family_logins_left_without_a_ward', 1);
    }

    #[Test]
    public function removing_a_row_with_no_guardian_edges_reports_an_honest_zero(): void
    {
        $child = Contact::factory()->create(['masjid_id' => $this->masjid->id]);
        $enrolment = $this->seedClaim($this->grade5, $child, GroupMembership::ROLE_MEMBER);

        Sanctum::actingAs($this->admin);

        $this->deleteJson($this->adminUrl("/groups/{$this->grade5->id}/members/{$enrolment->id}"))
            ->assertStatus(200)
            ->assertJsonPath('data.cascade.guardian_edges_removed', 0)
            ->assertJsonPath('data.cascade.confirmed_guardian_edges_removed', 0);
    }

    // ============================================ F — consent on a claim

    #[Test]
    public function consent_cannot_be_recorded_against_a_claim_nobody_has_stood_behind(): void
    {
        $child = Contact::factory()->create(['masjid_id' => $this->masjid->id]);
        $parent = Contact::factory()->create(['masjid_id' => $this->masjid->id]);

        $this->seedClaim($this->grade5, $child, GroupMembership::ROLE_MEMBER);
        $claim = $this->seedClaim($this->grade5, $parent, GroupMembership::ROLE_GUARDIAN, $child);

        Sanctum::actingAs($this->admin);

        $this->putJson(
            $this->adminUrl("/groups/{$this->grade5->id}/members/{$claim->id}/consent"),
            ['scope' => GroupMembership::CONSENT_MEDIA]
        )->assertStatus(422);

        $this->assertNull($claim->fresh()->consent_granted_at);

        // …and once the office stands behind the edge, the same call works.
        $this->postJson($this->adminUrl("/groups/{$this->grade5->id}/members/confirm"), [
            'membership_ids' => [$claim->id],
        ])->assertStatus(200);

        $this->putJson(
            $this->adminUrl("/groups/{$this->grade5->id}/members/{$claim->id}/consent"),
            ['scope' => GroupMembership::CONSENT_MEDIA]
        )->assertStatus(200);

        $this->assertNotNull($claim->fresh()->consent_granted_at);
    }

    #[Test]
    public function withdrawing_consent_is_never_refused(): void
    {
        // Withdrawal must work in every state a record can be in. Gating it
        // would leave consent standing on a row the office was trying to undo —
        // a refusal that fails OPEN, which is the one direction this area may
        // never fail in.
        $child = Contact::factory()->create(['masjid_id' => $this->masjid->id]);
        $parent = Contact::factory()->create(['masjid_id' => $this->masjid->id]);

        $this->seedMembership($this->grade5, $child, GroupMembership::ROLE_MEMBER);
        $edge = $this->seedMembership($this->grade5, $parent, GroupMembership::ROLE_GUARDIAN, $child);

        $edge->update([
            'consent_scope' => GroupMembership::CONSENT_MEDIA,
            'consent_granted_at' => now(),
        ]);

        $edge->unconfirm()->save();

        Sanctum::actingAs($this->admin);

        $this->deleteJson($this->adminUrl("/groups/{$this->grade5->id}/members/{$edge->id}/consent"))
            ->assertStatus(200);

        $this->assertNull($edge->fresh()->consent_granted_at);
    }

    // ============================================ H — one definition of pending

    #[Test]
    public function a_null_provenance_is_pending_in_sql_exactly_as_it_is_in_php(): void
    {
        // `scopePendingClaims()` was `where('provenance','!=','confirmed')`,
        // which in SQL never matches NULL, while `isPendingClaim()` treats NULL
        // as pending. The class docblock promises they are one definition.
        //
        // THE NULL ROW CANNOT BE WRITTEN, and that is the finding's own point:
        // the column is NOT NULL with a default, so this is unreachable today
        // and one nullable column or one import away from being an invisible
        // queue — a row every human-facing surface calls pending and the query
        // that exists to clear it cannot see. So the disagreement is asserted
        // where it actually lives, on both definitions, rather than by writing a
        // row the schema correctly refuses. (Measured: attempting the write is
        // `SQLSTATE[23000] … NOT NULL constraint failed`.)
        $child = Contact::factory()->create(['masjid_id' => $this->masjid->id]);
        $row = $this->seedClaim($this->grade5, $child, GroupMembership::ROLE_MEMBER);

        // The PHP side: anything that is not exactly `confirmed` is pending.
        $inMemory = new GroupMembership();
        $inMemory->provenance = null;
        $this->assertTrue($inMemory->isPendingClaim(), 'the PHP side stopped treating NULL as pending');
        $this->assertFalse($inMemory->isConfirmed());

        // The SQL side must say the same thing in SQL, because SQL will not say
        // it for us — `!= 'confirmed'` is UNKNOWN for a NULL and drops the row.
        $sql = GroupMembership::withoutMasjidScope()->pendingClaims()->toSql();
        $this->assertStringContainsString('is null', strtolower($sql), 'the SQL definition cannot see a NULL');

        // And the ordinary case still behaves, in both directions, asserted on
        // the row under test — this group also carries the fixture's own
        // confirmed rows, so a bare count would be measuring the setUp.
        $this->assertSame(
            1,
            GroupMembership::withoutMasjidScope()
                ->where('group_id', $this->grade5->id)
                ->pendingClaims()
                ->count(),
        );

        $row->confirmedByStaff($this->admin)->save();

        $this->assertSame(
            0,
            GroupMembership::withoutMasjidScope()
                ->where('group_id', $this->grade5->id)
                ->pendingClaims()
                ->count(),
        );

        $this->assertSame(
            1,
            GroupMembership::withoutMasjidScope()
                ->whereKey($row->id)
                ->confirmed()
                ->count(),
        );
    }

    #[Test]
    public function a_freshly_created_membership_reads_its_own_provenance(): void
    {
        // A `create()`d row read `provenance = NULL` in memory until refreshed,
        // so a future writer handing an unrefreshed model to a read path failed
        // closed by accident rather than by design.
        $child = Contact::factory()->create(['masjid_id' => $this->masjid->id]);

        $row = GroupMembership::create([
            'masjid_id' => $this->masjid->id,
            'group_id' => $this->grade5->id,
            'contact_id' => $child->id,
            'role' => GroupMembership::ROLE_MEMBER,
        ]);

        $this->assertNotNull($row->provenance, 'the in-memory row carries no provenance at all');
        $this->assertSame($row->fresh()->provenance, $row->provenance);
    }

    // ============================================================== helpers

    private function adminUrl(string $path = ''): string
    {
        return "/api/admin/masjids/{$this->masjid->id}" . $path;
    }

    private function familyUrl(string $path = ''): string
    {
        return "/api/family/masjids/{$this->masjid->id}" . $path;
    }

    private function as(Contact $parent): self
    {
        Auth::forgetGuards();
        app(TenantContext::class)->forgetTenant();

        return $this->withHeader(
            'Authorization',
            'Bearer ' . $parent->createFamilyToken()->plainTextToken
        );
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
