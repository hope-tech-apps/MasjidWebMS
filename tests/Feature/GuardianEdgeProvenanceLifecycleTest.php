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
use Tests\Support\ConfirmsRosterClaims;
use Tests\TestCase;

/**
 * PROVENANCE ACROSS THE WHOLE LIFE OF A GUARDIAN EDGE.
 *
 * `GuardianEdgeProvenanceTest` pins what one anonymous POST may and may not do.
 * This file pins the rest of the story, because every defect in this area has
 * been a LATER, ORDINARY act walking around a guard aimed at the first one:
 *
 *   - a de-duplication re-pointing a manufactured edge onto the real child;
 *   - a second season's registration meeting an edge the office confirmed;
 *   - an administrator typing in the very entry a pending claim already holds;
 *   - a merge of two adult records quietly taking a working credential with it.
 *
 * It also pins the three regressions the previous round's rule caused, which the
 * revert removes: a teacher 403'd out of her own classroom by one POST, a
 * returning child forked into N people on one roster, and three spellings of one
 * address becoming three contacts.
 *
 * Production carries 0 offerings, 0 registrations, 0 group_memberships, 0
 * guardian edges and 0 family logins across 4 masjids — none of this was ever a
 * live exposure. It is what the code would have done to the first real school.
 */
class GuardianEdgeProvenanceLifecycleTest extends TestCase
{
    use RefreshDatabase;
    use ConfirmsRosterClaims;

    private Masjid $masjid;

    /** The one staff user, who is ALSO the teacher of the club. */
    private User $admin;

    private Contact $teacherContact;

    private Group $grade5;

    private Group $club;

    private Contact $salma;

    private GroupMembership $salmaMembership;

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
        $this->admin->forceFill([
            'name' => 'Maryam Ustadha',
            'email' => 'maryam.ustadha@school.test',
        ])->save();

        $this->grade5 = Group::factory()->create([
            'masjid_id' => $this->masjid->id,
            'kind' => Group::KIND_CLASS,
            'name' => 'Grade 5',
        ]);

        $this->club = Group::factory()->create([
            'masjid_id' => $this->masjid->id,
            'kind' => Group::KIND_CLASS,
            'name' => 'After School Club',
        ]);

        // Her own Contact row — the identity bridge GroupAudience resolves a
        // staff caller through — and the classroom she teaches.
        $this->teacherContact = Contact::factory()->create([
            'masjid_id' => $this->masjid->id,
            'first_name' => 'Maryam',
            'last_name' => 'Ustadha',
            'email' => 'maryam.ustadha@school.test',
        ]);
        $this->seedMembership($this->club, $this->teacherContact, GroupMembership::ROLE_LEADER);

        $this->salma = Contact::factory()->create([
            'masjid_id' => $this->masjid->id,
            'first_name' => 'Salma',
            'last_name' => 'Other',
            'email' => 'salma.other@school.test',
        ]);
        $this->salmaMembership = $this->seedMembership($this->grade5, $this->salma, GroupMembership::ROLE_MEMBER);
    }

    // ================================ the regressions the revert removes

    #[Test]
    public function one_anonymous_post_cannot_403_a_teacher_out_of_her_own_classroom(): void
    {
        // MEASURED against the previous round's resolver, which never matched a
        // registrant to a pre-existing contact and therefore always CREATED one:
        //
        //   contacts holding her address: 1 -> 2
        //   GET …/groups/{club}/posts             200 -> 403
        //   GET …/groups/{club}/threads           200 -> 403
        //   GET …/groups/{club}/members/{id}/awards  200 -> 403
        //
        // permanently, with nothing on any screen saying why — while she kept
        // `manage contacts`, so she could still publish into the classroom she
        // could no longer read. The cause is two rows: GroupAudience resolves a
        // staff caller by LOWER(email) and requires EXACTLY ONE contact, because
        // "ambiguous identity is no identity".
        [$offering, $plan] = $this->makeOffering($this->club, 'after-school-club');

        Sanctum::actingAs($this->admin);
        $this->getJson($this->rosterReadUrl($this->club))->assertStatus(200);

        $this->anonymously();

        $this->postJson("/api/v1/offerings/{$offering->slug}/register", [
            'fee_plan_id' => $plan->id,
            'payer' => ['name' => 'Random Stranger', 'email' => 'stranger@example.test'],
            'registrants' => [['name' => 'Maryam Ustadha', 'email' => 'maryam.ustadha@school.test']],
            'data' => ['full_name' => 'Maryam Ustadha'],
        ], ['masjid-id' => (string) $this->masjid->id])->assertStatus(200);

        $this->assertSame(1, $this->contactsHolding('maryam.ustadha@school.test'));

        Sanctum::actingAs($this->admin);

        $this->getJson($this->adminUrl("/groups/{$this->club->id}/posts"))->assertStatus(200);
        $this->getJson($this->adminUrl("/groups/{$this->club->id}/threads"))->assertStatus(200);
    }

    #[Test]
    public function the_fork_is_shut_whatever_name_the_caller_pairs_with_her_address(): void
    {
        // Matching on (address, name) alone would leave the lockout ONE WRONG
        // NAME away, which is not a defence at all. The rule that actually holds
        // is on the WRITE: a contact this endpoint creates never carries an
        // address another contact in the tenant already holds.
        [$offering, $plan] = $this->makeOffering($this->club, 'wrong-name');

        $this->postJson("/api/v1/offerings/{$offering->slug}/register", [
            'fee_plan_id' => $plan->id,
            'payer' => ['name' => 'Random Stranger', 'email' => 'stranger@example.test'],
            'registrants' => [['name' => 'Not Her Name', 'email' => 'maryam.ustadha@school.test']],
            'data' => ['full_name' => 'Not Her Name'],
        ], ['masjid-id' => (string) $this->masjid->id])->assertStatus(200);

        $this->assertSame(1, $this->contactsHolding('maryam.ustadha@school.test'));

        // The person the caller named still exists — nothing was refused, so the
        // endpoint is not an existence oracle for a school's addresses. They
        // simply do not get somebody else's mailbox on their record.
        $written = Contact::withoutMasjidScope()->where('first_name', 'Not')->firstOrFail();
        $this->assertNull($written->email);

        Sanctum::actingAs($this->admin);
        $this->getJson($this->adminUrl("/groups/{$this->club->id}/posts"))->assertStatus(200);
    }

    #[Test]
    public function a_returning_child_is_one_person_however_many_times_the_form_is_submitted(): void
    {
        // MEASURED, three byte-identical registrations, same family, same child,
        // same free program, against the previous round's resolver:
        //
        //   contacts: 4 — payer converged (1), child forked into 3
        //   Grade 5 roster: 6 rows — member ×3, guardian 1->2, 1->3, 1->4
        //   the parent portal listed the same child twice
        //
        // …which walks straight past the duplicate-participant refusal
        // .claude/rules/groups.md calls "the guarantee, not the index", because a
        // writer that creates a new PERSON never reaches it.
        [$offering, $plan] = $this->makeOffering($this->club, 'club-signup');

        $body = [
            'fee_plan_id' => $plan->id,
            'payer' => ['name' => 'Hana Household', 'email' => 'hana@example.test'],
            'registrants' => [['name' => 'Zayd Household', 'email' => 'zayd@school.test']],
            'data' => ['full_name' => 'Zayd Household'],
        ];

        for ($i = 0; $i < 3; $i++) {
            $this->postJson("/api/v1/offerings/{$offering->slug}/register", $body,
                ['masjid-id' => (string) $this->masjid->id])->assertStatus(200);
        }

        $this->assertSame(1, Contact::withoutMasjidScope()->where('first_name', 'Zayd')->count());
        $this->assertSame(1, Contact::withoutMasjidScope()->where('first_name', 'Hana')->count());

        $zayd = Contact::withoutMasjidScope()->where('first_name', 'Zayd')->firstOrFail();
        $hana = Contact::withoutMasjidScope()->where('first_name', 'Hana')->firstOrFail();

        $this->assertSame(1, GroupMembership::withoutMasjidScope()
            ->where('group_id', $this->club->id)->where('contact_id', $zayd->id)->count());

        $this->assertSame(1, GroupMembership::withoutMasjidScope()
            ->where('group_id', $this->club->id)
            ->where('contact_id', $hana->id)
            ->where('guardian_of_contact_id', $zayd->id)
            ->count());
    }

    #[Test]
    public function three_spellings_of_one_address_are_one_person(): void
    {
        // `createContact` trimmed but did not lower-case, while every identity
        // comparison in this application is made on LOWER(email). Measured: four
        // contacts on one child's address.
        [$offering, $plan] = $this->makeOffering($this->club, 'club-case');

        foreach ([' Zayd.Household@School.test ', 'ZAYD.HOUSEHOLD@SCHOOL.TEST', 'zayd.household@school.test'] as $spelling) {
            $this->postJson("/api/v1/offerings/{$offering->slug}/register", [
                'fee_plan_id' => $plan->id,
                'payer' => ['name' => 'Hana Household', 'email' => 'hana@example.test'],
                'registrants' => [['name' => 'Zayd Household', 'email' => $spelling]],
                'data' => ['full_name' => 'Zayd Household'],
            ], ['masjid-id' => (string) $this->masjid->id])->assertStatus(200);
        }

        $this->assertSame(1, $this->contactsHolding('zayd.household@school.test'));
    }

    // ================================================ the merge (F1)

    #[Test]
    public function a_merge_does_not_launder_a_self_asserted_edge_onto_the_real_child(): void
    {
        // THE FULL CHAIN, from a stranger with no account. The anonymous POST
        // writes a duplicate child plus a guardian edge over it; a registrar
        // sees two identical rows and merges them, which is exactly what the
        // office is supposed to do with duplicates; `carry()` re-points the edge
        // onto the REAL child. Measured before provenance: family-login answered
        // `"eligible": true`, `enable()` answered 200, and with his own token he
        // read her behaviour record, her ḥifẓ and the participant thread
        // "Safeguarding: incident on 3 Sept".
        $bilal = $this->seedParentWithCredential();

        $this->seedAward($this->salmaMembership, 'Left the classroom without permission');
        $this->seedParticipantThread($this->grade5, $this->salmaMembership, 'Safeguarding: incident on 3 Sept');

        [$offering, $plan] = $this->makeOffering($this->grade5, 'grade-5-enrichment');

        $this->anonymously();

        $this->postJson("/api/v1/offerings/{$offering->slug}/register", [
            'fee_plan_id' => $plan->id,
            'payer' => ['name' => 'Bilal Attacker', 'email' => 'bilal@example.test'],
            // A name the directory does not hold, so a fresh contact is written
            // — the residual duplicate, and the row a registrar would merge.
            'registrants' => [['name' => 'Salma O', 'email' => 'salma.other@school.test']],
            'data' => ['full_name' => 'Salma O'],
        ], ['masjid-id' => (string) $this->masjid->id])->assertStatus(200);

        $duplicate = Contact::withoutMasjidScope()->where('first_name', 'Salma')
            ->whereKeyNot($this->salma->id)->firstOrFail();

        Sanctum::actingAs($this->admin);

        $this->postJson($this->adminUrl("/contacts/{$duplicate->id}/merge"), [
            'target_contact_id' => $this->salma->id,
        ])->assertStatus(200);

        // The edge landed on the real child, exactly as the merge intends —
        // and it is still nobody's word but the form's.
        $edge = GroupMembership::withoutMasjidScope()
            ->where('contact_id', $bilal->id)
            ->where('role', GroupMembership::ROLE_GUARDIAN)
            ->where('guardian_of_contact_id', $this->salma->id)
            ->firstOrFail();

        $this->assertSame(GroupMembership::PROVENANCE_SELF_ASSERTED, $edge->provenance);

        // He REMAINS eligible for a credential, and that is correct rather than a
        // gap: he is the confirmed guardian of his own child, and the model
        // deliberately does not control what a credential READS by controlling
        // who may hold one — the round that tried that refused a parent in the
        // adult ḥalaqa and a teacher who is also a parent. What his credential
        // opens is the question, and the answer is below.
        $this->getJson($this->adminUrl("/contacts/{$bilal->id}/family-login"))
            ->assertStatus(200)
            ->assertJsonPath('data.eligible', true);

        $this->as($bilal)
            ->getJson($this->familyUrl("/groups/{$this->grade5->id}/members/{$this->salmaMembership->id}/awards"))
            ->assertStatus(403);

        $this->as($bilal)
            ->getJson($this->familyUrl("/groups/{$this->grade5->id}/members/{$this->salmaMembership->id}/hifz"))
            ->assertStatus(403);
    }

    #[Test]
    public function a_merge_that_re_points_a_confirmed_edge_at_a_different_ward_returns_it_to_a_claim(): void
    {
        // THE SAME DOOR ONE AUTHENTICATED ACT FURTHER ALONG: confirm the claim
        // over the phantom child, THEN merge the phantom into the real one. A
        // confirmation names one specific person; re-pointing changes the
        // person, so the confirmation stops describing the row it sits on.
        $bilal = $this->seedParentWithCredential();

        $phantom = Contact::factory()->create([
            'masjid_id' => $this->masjid->id,
            'first_name' => 'Salma',
            'last_name' => 'O',
        ]);
        $this->seedMembership($this->grade5, $phantom, GroupMembership::ROLE_MEMBER);
        $edge = $this->seedMembership($this->grade5, $bilal, GroupMembership::ROLE_GUARDIAN, $phantom);

        // …confirmed by a real staff member, looking at a real screen.
        $edge->confirmedByStaff($this->admin)->save();

        Sanctum::actingAs($this->admin);

        $response = $this->postJson($this->adminUrl("/contacts/{$phantom->id}/merge"), [
            'target_contact_id' => $this->salma->id,
        ])->assertStatus(200);

        // THE EDGE IS RETIRED AND RE-ISSUED, not merely un-confirmed in place.
        // The pair it named is gone, so the row that named it is gone with it and
        // the relationship comes back as a fresh claim with a NEW ID — which is
        // what stops a Confirm click carrying ids drawn before the merge from
        // landing on it. `$edge->fresh()` is therefore null BY DESIGN.
        $this->assertNull($edge->fresh(), 'the row that named the old pair kept its id');

        $reissued = GroupMembership::withoutMasjidScope()
            ->where('group_id', $this->grade5->id)
            ->where('contact_id', $bilal->id)
            ->where('role', GroupMembership::ROLE_GUARDIAN)
            ->where('guardian_of_contact_id', $this->salma->id)
            ->firstOrFail();

        $this->assertSame(
            GroupMembership::PROVENANCE_SELF_ASSERTED,
            $reissued->provenance,
            'a merge confirmed a guardianship over a child nobody confirmed it over'
        );
        $this->assertNull($reissued->confirmed_by_user_id);

        // AND THE OPERATOR IS TOLD. `carry()`'s return value used to be
        // discarded, so a merge that re-opened a guardian claim said nothing.
        $this->assertSame(1, $response->json('roster.unconfirmed'));
        $this->assertStringContainsString('unconfirmed claim', $response->json('roster.message'));

        $this->as($bilal)
            ->getJson($this->familyUrl("/groups/{$this->grade5->id}/members/{$this->salmaMembership->id}/awards"))
            ->assertStatus(403);
    }

    #[Test]
    public function a_merge_of_two_adult_records_does_not_quietly_revoke_a_confirmed_guardianship(): void
    {
        // THE OTHER DIRECTION, and the objection is real but it is about
        // SILENCE, not about the confirmation. A parent with two CRM rows: the
        // absorbed one carries the CONFIRMED edge, the survivor an unconfirmed
        // claim over the same child in the same class. Dropping the duplicate
        // leaves the survivor holding a claim that grants nothing — an ordinary
        // de-duplication ending a working parent sign-in.
        //
        // The confirmation is deliberately NOT carried across. "The survivor
        // already held a claim over this same ward" looks like evidence that it
        // is the same relationship and is not: a claim is precisely what an
        // anonymous registration writes, so the caller who authored the survivor
        // row can author its claim over the ward too and collect whatever the
        // merge carries — one extra POST, and the stranger holds a confirmed
        // guardianship. See RosterConfirmationIntegrityTest for that chain
        // measured end to end.
        //
        // What this pins instead is that it is not SILENT: the count is on the
        // response the operator gets, the message names the door back, and one
        // click on the roster restores access. A lost click is recoverable; a
        // stranger reading a child's safeguarding record is not.
        $absorbed = Contact::factory()->create([
            'masjid_id' => $this->masjid->id,
            'first_name' => 'Idris',
            'last_name' => 'Parent',
        ]);
        $survivor = Contact::factory()->create([
            'masjid_id' => $this->masjid->id,
            'first_name' => 'Idris',
            'last_name' => 'Parent',
        ]);

        $confirmed = $this->seedMembership($this->grade5, $absorbed, GroupMembership::ROLE_GUARDIAN, $this->salma);
        $confirmed->confirmedByStaff($this->admin)->save();

        $claim = $this->seedMembership($this->grade5, $survivor, GroupMembership::ROLE_GUARDIAN, $this->salma);
        $claim->selfAssertedFrom(null)->save();

        Sanctum::actingAs($this->admin);

        $response = $this->postJson($this->adminUrl("/contacts/{$absorbed->id}/merge"), [
            'target_contact_id' => $survivor->id,
        ])->assertStatus(200);

        // The confirmation does not ride across…
        $this->assertFalse(
            $claim->fresh()->isConfirmed(),
            'a confirmation rode a de-duplication onto a row nobody vouched for'
        );

        // …and the operator is TOLD, on the response to the act they performed,
        // with the remedy named. This is the whole of the objection this case
        // exists for: not that access paused, but that it used to pause with
        // nothing on any screen.
        $this->assertSame(1, $response->json('roster.unconfirmed'));
        $this->assertStringContainsString('unconfirmed claim', $response->json('roster.message'));

        $this->getJson($this->adminUrl("/contacts/{$survivor->id}/family-login"))
            ->assertStatus(200)
            ->assertJsonPath('data.eligible', false);

        // One click on the roster is the whole remedy.
        $this->postJson($this->adminUrl("/groups/{$this->grade5->id}/members/confirm"), $this->confirmBody([$claim->id]))->assertStatus(200)->assertJsonPath('data.confirmed', 1);

        $this->assertTrue($claim->fresh()->isConfirmed());
        $this->assertSame($this->admin->id, (int) $claim->fresh()->confirmed_by_user_id);

        $this->getJson($this->adminUrl("/contacts/{$survivor->id}/family-login"))
            ->assertStatus(200)
            ->assertJsonPath('data.eligible', true);
    }

    // ============================================== what the office sees

    #[Test]
    public function the_roster_counts_the_pending_claims_and_one_call_confirms_them_all(): void
    {
        // THE BULK AFFORDANCE. A school with 200 camp signups must not face 200
        // modal dialogs, so this is still ONE call — but it NAMES the rows it is
        // vouching for, which is what the screen drew.
        //
        // It used to mean "every pending claim in this group" with no body at
        // all, and that set was re-derived on the server after the operator had
        // already agreed to what they read. Measured: a registration arriving
        // while the confirmation dialog was open was confirmed by a click on the
        // eight rows above it, and that ninth row was a stranger's claim over a
        // named child. See RosterConfirmationIntegrityTest.
        [$offering, $plan] = $this->makeOffering($this->club, 'summer-camp');

        foreach ([['Amina', 'amina@f1.test'], ['Bilqis', 'bilqis@f2.test'], ['Suhayb', 'suhayb@f3.test']] as [$child, $address]) {
            $this->postJson("/api/v1/offerings/{$offering->slug}/register", [
                'fee_plan_id' => $plan->id,
                'payer' => ['name' => $child . ' Parent', 'email' => 'parent-' . $address],
                'registrants' => [['name' => $child . ' Child', 'email' => $address]],
                'data' => ['full_name' => $child . ' Child'],
            ], ['masjid-id' => (string) $this->masjid->id])->assertStatus(200);
        }

        Sanctum::actingAs($this->admin);

        // 3 children + 3 guardian edges; the teacher's own leader row is not one.
        $this->getJson($this->rosterReadUrl($this->club))
            ->assertStatus(200)
            ->assertJsonPath('meta.pending_claims', 6);

        $shown = GroupMembership::withoutMasjidScope()
            ->where('group_id', $this->club->id)
            ->pendingClaims()
            ->pluck('id')
            ->all();

        $this->postJson($this->adminUrl("/groups/{$this->club->id}/members/confirm"), $this->confirmBody($shown))
            ->assertStatus(200)
            ->assertJsonPath('data.confirmed', 6)
            ->assertJsonPath('data.pending_claims', 0);

        $this->getJson($this->rosterReadUrl($this->club))
            ->assertStatus(200)
            ->assertJsonPath('meta.pending_claims', 0);

        // …and the confirmation is an act with an actor on it.
        $edges = GroupMembership::withoutMasjidScope()
            ->where('group_id', $this->club->id)
            ->where('role', GroupMembership::ROLE_GUARDIAN)
            ->get();

        $this->assertCount(3, $edges);

        foreach ($edges as $edge) {
            $this->assertTrue($edge->isConfirmed());
            $this->assertSame($this->admin->id, (int) $edge->confirmed_by_user_id);
            $this->assertNotNull($edge->confirmed_at);
        }

        // AND THE READ FOLLOWS. Confirmation is what turns a roster line into
        // the grant the parent portal was built to serve.
        $parent = Contact::withoutMasjidScope()->where('first_name', 'Amina')
            ->whereRaw('LOWER(email) = ?', ['parent-amina@f1.test'])->firstOrFail();

        app(TenantContext::class)->set($this->masjid->id);
        app(FamilyAccessService::class)->enable($parent, 'amina.parent@f1.test', $this->admin, '127.0.0.1');
        app(TenantContext::class)->forgetTenant();

        $this->as($parent)->getJson($this->familyUrl("/groups/{$this->club->id}"))->assertStatus(200);
    }

    #[Test]
    public function confirming_reaches_only_this_group_and_only_named_rows_when_asked(): void
    {
        [$clubOffering, $clubPlan] = $this->makeOffering($this->club, 'club-intake');
        [$gradeOffering, $gradePlan] = $this->makeOffering($this->grade5, 'grade-intake');

        foreach ([[$clubOffering, $clubPlan, 'Club'], [$gradeOffering, $gradePlan, 'Grade']] as [$offering, $plan, $tag]) {
            $this->postJson("/api/v1/offerings/{$offering->slug}/register", [
                'fee_plan_id' => $plan->id,
                'payer' => ['name' => $tag . ' Parent', 'email' => strtolower($tag) . '.parent@f.test'],
                'registrants' => [['name' => $tag . ' Child', 'email' => strtolower($tag) . '.child@f.test']],
                'data' => ['full_name' => $tag . ' Child'],
            ], ['masjid-id' => (string) $this->masjid->id])->assertStatus(200);
        }

        Sanctum::actingAs($this->admin);

        $clubRows = GroupMembership::withoutMasjidScope()
            ->where('group_id', $this->club->id)
            ->pendingClaims()
            ->pluck('id')
            ->all();

        $this->postJson($this->adminUrl("/groups/{$this->club->id}/members/confirm"), $this->confirmBody($clubRows))
            ->assertStatus(200)
            ->assertJsonPath('data.confirmed', 2);

        $this->assertSame(
            2,
            GroupMembership::withoutMasjidScope()->where('group_id', $this->grade5->id)->pendingClaims()->count(),
            'confirming one roster confirmed another group\'s claims'
        );

        // Named ids: the narrow case, for a roster where one line looks wrong.
        $one = GroupMembership::withoutMasjidScope()
            ->where('group_id', $this->grade5->id)->pendingClaims()->orderBy('id')->firstOrFail();

        $this->postJson($this->adminUrl("/groups/{$this->grade5->id}/members/confirm"), $this->confirmBody([$one->id]))->assertStatus(200)->assertJsonPath('data.confirmed', 1);

        $this->assertSame(1, GroupMembership::withoutMasjidScope()
            ->where('group_id', $this->grade5->id)->pendingClaims()->count());

        // A stale screen re-submitting a list somebody else already confirmed is
        // a no-op with an honest count, not a 422 nobody can act on.
        $this->postJson($this->adminUrl("/groups/{$this->grade5->id}/members/confirm"), $this->confirmBody([$one->id]))->assertStatus(200)->assertJsonPath('data.confirmed', 0);
    }

    #[Test]
    public function confirming_is_gated_and_tenant_scoped(): void
    {
        $other = $this->makeMasjid();
        $strangersGroup = Group::factory()->create(['masjid_id' => $other->id, 'kind' => Group::KIND_CLASS]);

        Sanctum::actingAs($this->admin);

        // Ids are named so the request passes validation and the tenant guard is
        // what answers — an empty body is a 422 from the form request and would
        // prove nothing about scoping.
        $this->postJson($this->adminUrl("/groups/{$strangersGroup->id}/members/confirm"), $this->confirmBody([1]))->assertStatus(404);

        Auth::forgetGuards();

        $this->postJson($this->adminUrl("/groups/{$this->club->id}/members/confirm"), $this->confirmBody([1]))->assertStatus(401);
    }

    #[Test]
    public function typing_in_the_entry_a_claim_already_holds_confirms_it_instead_of_refusing(): void
    {
        // The duplicate refusal is the guarantee .claude/rules/groups.md says it
        // is, and it stays — but once a public form can put an UNCONFIRMED row
        // on a roster it became a refusal aimed at the office's own remedy: an
        // administrator establishing the guardian entry they meant to is told
        // the row exists, while the row that exists grants nothing and the
        // family-login screen says the parent cannot sign in.
        $parent = Contact::factory()->create(['masjid_id' => $this->masjid->id]);

        $claim = $this->seedMembership($this->grade5, $parent, GroupMembership::ROLE_GUARDIAN, $this->salma);
        $claim->selfAssertedFrom(null)->save();

        Sanctum::actingAs($this->admin);

        $this->postJson($this->adminUrl("/groups/{$this->grade5->id}/members"), [
            'contact_id' => $parent->id,
            'role' => GroupMembership::ROLE_GUARDIAN,
            'guardian_of_contact_id' => $this->salma->id,
        ])->assertStatus(200);

        $this->assertTrue($claim->fresh()->isConfirmed());
        $this->assertSame($this->admin->id, (int) $claim->fresh()->confirmed_by_user_id);

        // …and doing it AGAIN is still the duplicate refusal, because now
        // nothing is being asked for that is not already true.
        $this->postJson($this->adminUrl("/groups/{$this->grade5->id}/members"), [
            'contact_id' => $parent->id,
            'role' => GroupMembership::ROLE_GUARDIAN,
            'guardian_of_contact_id' => $this->salma->id,
        ])->assertStatus(422);
    }

    #[Test]
    public function the_family_login_panel_says_a_claim_is_pending_rather_than_denying_it_exists(): void
    {
        // A registrar looking at a roster that plainly shows this person as a
        // guardian, told "this member is not listed as the guardian of any
        // student", concludes the screen is broken and goes looking for a way
        // around it.
        $parent = Contact::factory()->create(['masjid_id' => $this->masjid->id]);

        $claim = $this->seedMembership($this->grade5, $parent, GroupMembership::ROLE_GUARDIAN, $this->salma);
        $claim->selfAssertedFrom(null)->save();

        Sanctum::actingAs($this->admin);

        $panel = $this->getJson($this->adminUrl("/contacts/{$parent->id}/family-login"))
            ->assertStatus(200)
            ->assertJsonPath('data.eligible', false)
            ->json('data.ineligible_reason');

        $this->assertStringContainsString('public registration form', $panel);
        $this->assertStringContainsString('Confirm the guardian entry', $panel);

        // The write refuses with the SAME sentence the preview showed — one
        // method decides both.
        $this->postJson($this->adminUrl("/contacts/{$parent->id}/family-login"), [
            'login_email' => 'claimant@example.test',
        ])->assertStatus(422)->assertJsonPath('message', $panel);
    }

    // ========================================= the later ordinary acts

    #[Test]
    public function a_second_seasons_registration_does_not_downgrade_a_confirmed_edge(): void
    {
        $parent = Contact::factory()->create([
            'masjid_id' => $this->masjid->id,
            'first_name' => 'Hana',
            'last_name' => 'Household',
            'email' => 'hana@example.test',
        ]);

        $edge = $this->seedMembership($this->grade5, $parent, GroupMembership::ROLE_GUARDIAN, $this->salma);
        $edge->confirmedByStaff($this->admin)->save();

        [$offering, $plan] = $this->makeOffering($this->grade5, 'next-season');

        $this->anonymously();

        $this->postJson("/api/v1/offerings/{$offering->slug}/register", [
            'fee_plan_id' => $plan->id,
            'payer' => ['name' => 'Hana Household', 'email' => 'hana@example.test'],
            'registrants' => [['name' => 'Salma Other', 'email' => 'salma.other@school.test']],
            'data' => ['full_name' => 'Salma Other'],
        ], ['masjid-id' => (string) $this->masjid->id])->assertStatus(200);

        $this->assertTrue(
            $edge->fresh()->isConfirmed(),
            'a public registration downgraded an edge the office had confirmed'
        );
        $this->assertSame(1, GroupMembership::withoutMasjidScope()
            ->where('group_id', $this->grade5->id)
            ->where('contact_id', $parent->id)
            ->where('role', GroupMembership::ROLE_GUARDIAN)
            ->count());
    }

    #[Test]
    public function a_pending_enrolment_is_still_a_roster_line_its_teacher_can_work_from(): void
    {
        // THE OVER-REFUSAL THIS MODEL MUST NOT HAVE. Provenance narrows what a
        // row grants ITS HOLDER; it says nothing about the SUBJECT. A school
        // takes a register on the first morning of a camp, not after 200
        // confirmations, so the teacher still lists, awards and records ḥifẓ for
        // a child a pending claim enrolled.
        [$offering, $plan] = $this->makeOffering($this->club, 'camp-day-one');

        $this->postJson("/api/v1/offerings/{$offering->slug}/register", [
            'fee_plan_id' => $plan->id,
            'payer' => ['name' => 'Yasmin Parent', 'email' => 'yasmin@f.test'],
            'registrants' => [['name' => 'Anas Child', 'email' => 'anas@f.test']],
            'data' => ['full_name' => 'Anas Child'],
        ], ['masjid-id' => (string) $this->masjid->id])->assertStatus(200);

        $anas = Contact::withoutMasjidScope()->where('first_name', 'Anas')->firstOrFail();
        $membership = GroupMembership::withoutMasjidScope()
            ->where('group_id', $this->club->id)->where('contact_id', $anas->id)->firstOrFail();

        $this->assertFalse($membership->isConfirmed());

        Sanctum::actingAs($this->admin);

        $skill = BehaviorSkill::factory()->create([
            'masjid_id' => $this->masjid->id,
            'is_active' => true,
        ]);

        $this->postJson($this->adminUrl("/groups/{$this->club->id}/awards"), [
            'membership_id' => $membership->id,
            'behavior_skill_id' => $skill->id,
        ])->assertStatus(201);

        $this->getJson($this->adminUrl("/groups/{$this->club->id}/members/{$membership->id}/awards"))
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.data');
    }

    // ================================================================ F6

    #[Test]
    public function a_first_shot_address_reassignment_says_whose_address_it_took(): void
    {
        // `reassign_address` is `sometimes|boolean` and nothing can enforce that
        // the refusal it "confirms" was ever issued — this is HTTP, and the
        // refusal leaves nothing behind to check. So a caller may send it on the
        // FIRST attempt, and the sentence that names the loser never runs: an
        // address was stripped off a soft-deleted holder with the operator never
        // told whose it was. Requiring the refusal is not available; telling
        // them afterwards is.
        $former = Contact::factory()->create([
            'masjid_id' => $this->masjid->id,
            'first_name' => 'Former',
            'last_name' => 'Holder',
        ]);
        $formerChild = Contact::factory()->create(['masjid_id' => $this->masjid->id]);
        $this->seedMembership($this->grade5, $formerChild, GroupMembership::ROLE_MEMBER);
        $this->seedMembership($this->grade5, $former, GroupMembership::ROLE_GUARDIAN, $formerChild);

        app(TenantContext::class)->set($this->masjid->id);
        app(FamilyAccessService::class)->enable($former, 'household@example.test', $this->admin, '127.0.0.1');
        app(TenantContext::class)->forgetTenant();

        $former->refresh();
        Sanctum::actingAs($this->admin);
        $this->deleteJson($this->adminUrl("/contacts/{$former->id}"))->assertStatus(200);

        $claimant = Contact::factory()->create(['masjid_id' => $this->masjid->id]);
        $this->seedMembership($this->grade5, $claimant, GroupMembership::ROLE_GUARDIAN, $this->salma);

        // FIRST SHOT, no refusal ever seen.
        $this->postJson($this->adminUrl("/contacts/{$claimant->id}/family-login"), [
            'login_email' => 'household@example.test',
            'reassign_address' => true,
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.state', 'enabled')
            // The deleted holder is DESCRIBED, never named — no screen in this
            // application shows a deleted contact, which is why the refusal does
            // not name one either. Same disclosure, both doors.
            ->assertJsonPath('data.address_released_from', 'a member who has since been deleted');

        // An ordinary enable, with no address taken from anybody, reports none.
        $second = Contact::factory()->create(['masjid_id' => $this->masjid->id]);
        $this->seedMembership($this->grade5, $second, GroupMembership::ROLE_GUARDIAN, $this->salma);

        $this->postJson($this->adminUrl("/contacts/{$second->id}/family-login"), [
            'login_email' => 'nobody-had-this@example.test',
            'reassign_address' => true,
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.address_released_from', null);
    }

    // ================================================================ helpers

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

    /** A real parent of a real child, with a legitimately-issued credential. */
    private function seedParentWithCredential(): Contact
    {
        $bilal = Contact::factory()->create([
            'masjid_id' => $this->masjid->id,
            'first_name' => 'Bilal',
            'last_name' => 'Attacker',
            'email' => 'bilal@example.test',
        ]);

        $ownChild = Contact::factory()->create(['masjid_id' => $this->masjid->id]);
        $this->seedMembership($this->club, $ownChild, GroupMembership::ROLE_MEMBER);
        $this->seedMembership($this->club, $bilal, GroupMembership::ROLE_GUARDIAN, $ownChild);

        app(TenantContext::class)->set($this->masjid->id);
        app(FamilyAccessService::class)->enable($bilal, 'bilal@example.test', $this->admin, '127.0.0.1');
        app(TenantContext::class)->forgetTenant();

        return $bilal->refresh();
    }

    private function adminUrl(string $path = ''): string
    {
        return "/api/admin/masjids/{$this->masjid->id}" . $path;
    }

    private function rosterReadUrl(Group $group): string
    {
        return $this->adminUrl("/groups/{$group->id}/members");
    }

    private function familyUrl(string $path = ''): string
    {
        return "/api/family/masjids/{$this->masjid->id}" . $path;
    }

    private function contactsHolding(string $email): int
    {
        return Contact::withoutMasjidScope()
            ->whereNotNull('email')
            ->whereRaw('LOWER(TRIM(email)) = ?', [$email])
            ->count();
    }

    /** Drop every principal and the bound tenant — a stranger with no account. */
    private function anonymously(): void
    {
        Auth::forgetGuards();
        app(TenantContext::class)->forgetTenant();
    }

    /** @return array{0: Offering, 1: FeePlan} */
    private function makeOffering(Group $group, string $slug, bool $free = true): array
    {
        $offering = Offering::factory()
            ->forMasjid($this->masjid)
            ->withRoster($group)
            ->create(['slug' => $slug]);

        $plan = FeePlan::factory()
            ->when($free, fn ($f) => $f->free())
            ->create([
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
        return GroupMembership::create([
            'masjid_id' => $this->masjid->id,
            'group_id' => $group->id,
            'contact_id' => $contact->id,
            'role' => $role,
            'guardian_of_contact_id' => $ward?->id,
        ]);
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

    /** A real bearer token on the `family` guard, one honest request at a time. */
    private function as(Contact $parent): self
    {
        Auth::forgetGuards();
        app(TenantContext::class)->forgetTenant();

        return $this->withHeader(
            'Authorization',
            'Bearer ' . $parent->createFamilyToken()->plainTextToken
        );
    }
}
