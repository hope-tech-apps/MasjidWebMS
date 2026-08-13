<?php

namespace Tests\Feature;

use App\Models\BehaviorAward;
use App\Models\Contact;
use App\Models\ContactLoginEvent;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\Masjid;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * GUARDIAN STANDING is a fact about a ROSTER, and rosters move.
 *
 * `GuardianOnlyLoginTest` pins the enable-time rule: only a guardian, and never
 * a participant. This file covers what happens to that rule AFTERWARDS — the
 * ordinary admin acts that change a contact's standing once a credential
 * already exists, and the one act (a merge) that used to destroy the standing
 * and then instruct the operator to rely on it.
 *
 * Every case here was measured against the code before the fix:
 *
 *  1. `merge` moved donations, cards and SMS consent and did NOT move
 *     `group_memberships`. The force-delete then DB-cascaded them
 *     (`contact_id` and `guardian_of_contact_id` are both `cascadeOnDelete`).
 *     Measured: merging the guardian row of a duplicated parent into the
 *     duplicate returned 200 with a message telling the operator to enable
 *     sign-in on the survivor, and doing exactly that answered 422 "not listed
 *     as the guardian of any student". The remedy the message named had been
 *     removed by the same request that named it. The same cascade destroyed a
 *     merged-away CHILD's behaviour and ḥifẓ records outright, because
 *     `behavior_awards.group_membership_id` and `hifz_entries.group_membership_id`
 *     are `cascadeOnDelete` too.
 *
 *  2. Nothing re-derived the guardian condition after `enable()`. Measured:
 *     enable on a guardian, then add that same contact to a class roster as a
 *     `member` — the credential stayed live, and `standingIn()` grants a
 *     participant the whole group feed outright. That is the enable-time guard
 *     walked around one roster row at a time.
 *
 *  3. The reassignment's `address_released` half was written onto the LOSING
 *     contact. When that contact is soft-deleted — the case the reassignment
 *     door exists for — no screen in this application can read it, and the
 *     refusal the operator had just accepted promised that "both halves go on
 *     the access history".
 *
 * What this file deliberately does NOT assert is that removing a guardian edge
 * revokes a live credential. See
 * `removing_a_guardian_edge_leaves_the_credential_standing_and_says_so` for the
 * argument.
 */
class GuardianStandingLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjid;
    private User $admin;

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
    }

    // ---------------------------------------------------------------- helpers

    private function makeMasjid(): Masjid
    {
        return Masjid::create([
            'name' => 'Test School ' . uniqid(),
            'email' => 'school-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'crm_enabled' => true,
            'org_type' => Masjid::ORG_TYPE_SCHOOL,
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

    private function makeGroup(string $name): Group
    {
        $name = $name . ' ' . uniqid();

        return Group::withoutMasjidScope()->create([
            'masjid_id' => $this->masjid->id,
            'name' => $name,
            'slug' => Str::slug($name),
            'kind' => 'class',
        ]);
    }

    private function makeContact(string $first, string $last = 'Test'): Contact
    {
        return Contact::withoutMasjidScope()->create([
            'masjid_id' => $this->masjid->id,
            'first_name' => $first,
            'last_name' => $last,
            'email' => Str::slug($first) . '.' . uniqid() . '@test.local',
        ]);
    }

    private function enrol(Group $group, Contact $contact, string $role, ?Contact $ward = null): GroupMembership
    {
        return GroupMembership::withoutMasjidScope()->create([
            'masjid_id' => $this->masjid->id,
            'group_id' => $group->id,
            'contact_id' => $contact->id,
            'role' => $role,
            'guardian_of_contact_id' => $ward?->id,
            'joined_at' => now(),
        ]);
    }

    /** A parent with one child on one roster — the shape the portal is for. */
    private function makeGuardianOf(Contact $child, ?Group $group = null): Contact
    {
        $group ??= $this->makeGroup('Grade 3');
        $parent = $this->makeContact('Parent');

        $this->enrol($group, $child, GroupMembership::ROLE_MEMBER);
        $this->enrol($group, $parent, GroupMembership::ROLE_GUARDIAN, $child);

        return $parent;
    }

    private function loginUrl(Contact $contact): string
    {
        return "/api/admin/masjids/{$this->masjid->id}/contacts/{$contact->id}/family-login";
    }

    private function asANewRequest(): void
    {
        Auth::forgetGuards();
        app(TenantContext::class)->forgetTenant();
    }

    private function asAdmin(): void
    {
        $this->asANewRequest();
        Sanctum::actingAs($this->admin);
    }

    private function raw(Contact $contact): Contact
    {
        return Contact::withoutMasjidScope()->withTrashed()->findOrFail($contact->id);
    }

    private function events(Contact $contact)
    {
        return ContactLoginEvent::withoutMasjidScope()
            ->where('contact_id', $contact->id)
            ->orderBy('id')
            ->get();
    }

    // ==================================================== 1. THE DUAL-ROLE DOOR

    /**
     * F1, END TO END through the route a registrar actually clicks — and the
     * verdict is the one that CHANGED this round.
     *
     * Tariq, 16, is a `member` of the Teens Ḥalaqa AND — because a school
     * records who may collect a child — a `guardian` of his seven-year-old
     * sister on the Kids Class roster. The original guard asked only "is there a
     * guardian edge?", so he was handed a credential whose standing computation
     * granted a participant the whole group feed outright. A previous round
     * answered that by refusing the credential to anybody holding a participant
     * edge; this round moved the property to what the credential READS
     * (`GroupAudience::membershipsFor()`) and dropped the refusal, because the
     * refusal also hit a parent in the adult ḥalaqa and a teacher who is also a
     * parent — and the hook enforcing it destroyed sign-ins those adults already
     * held.
     *
     * So the route now ACCEPTS, and the preview says so before the click, which
     * is the property this file's `the_panel_refuses_before_the_operator_types_an_address`
     * exists to protect in the other direction: PREVIEW ≡ WRITE, whatever the
     * verdict is.
     */
    #[Test]
    public function a_student_who_is_also_a_siblings_guardian_is_accepted_through_the_real_route(): void
    {
        $teens = $this->makeGroup('Teens Halaqa');
        $kids = $this->makeGroup('Kids Class');

        $tariq = $this->makeContact('Tariq', 'Rahman');
        $sister = $this->makeContact('Salma', 'Rahman');

        $this->enrol($teens, $tariq, GroupMembership::ROLE_MEMBER);
        $this->enrol($kids, $sister, GroupMembership::ROLE_MEMBER);
        $this->enrol($kids, $tariq, GroupMembership::ROLE_GUARDIAN, $sister);

        $this->asAdmin();

        // PREVIEW…
        $this->getJson($this->loginUrl($tariq))
            ->assertOk()
            ->assertJsonPath('data.eligible', true)
            ->assertJsonPath('data.ineligible_reason', null);

        // …and WRITE, agreeing.
        $this->postJson($this->loginUrl($tariq), ['login_email' => 'tariq@example.test'])
            ->assertOk()
            ->assertJsonPath('data.state', 'enabled');

        $this->assertNotNull($this->raw($tariq)->login_enabled_at);
        $this->assertSame('tariq@example.test', $this->raw($tariq)->login_email);
    }

    // ================================================= 2. MERGE CARRIES ROSTERS

    /**
     * THE MEASURED REPRODUCTION, step for step.
     *
     *   1. P1 is guardian of C in Grade 3; family sign-in enabled -> 200
     *   2. P2 is the same human's second CRM row, with no roster edge — which is
     *      WHY it is the duplicate
     *   3. merge P1 -> P2 -> 200, "Enable sign-in on them if they should still
     *      have access."
     *   4. doing exactly that -> 422 "not listed as the guardian of any student"
     *   5. group_memberships surviving: 1 of 2
     */
    #[Test]
    public function merging_a_guardian_into_their_duplicate_row_carries_the_roster_edge(): void
    {
        $group = $this->makeGroup('Grade 3');
        $child = $this->makeContact('Child');
        $p1 = $this->makeGuardianOf($child, $group);
        $p2 = $this->makeContact('Parent', 'Duplicate');

        $this->asAdmin();
        $this->postJson($this->loginUrl($p1), ['login_email' => 'household@example.test'])
            ->assertOk();

        $this->asAdmin();
        $this->postJson(
            "/api/admin/masjids/{$this->masjid->id}/contacts/{$p1->id}/merge",
            ['target_contact_id' => $p2->id],
        )->assertOk();

        // The roster edge is a fact about the HUMAN, exactly as the donations and
        // the card last-4 the merge already carries are.
        $this->assertSame(
            2,
            GroupMembership::withoutMasjidScope()->count(),
            'the merge destroyed the guardian edge it then told the operator to rely on',
        );

        $carried = GroupMembership::withoutMasjidScope()
            ->where('contact_id', $p2->id)
            ->where('role', GroupMembership::ROLE_GUARDIAN)
            ->first();

        $this->assertNotNull($carried, 'the survivor did not inherit the guardian edge');
        $this->assertSame((int) $child->id, (int) $carried->guardian_of_contact_id);
        $this->assertSame((int) $group->id, (int) $carried->group_id);

        // The carried edge arrives as a CLAIM, not as a grant: the confirmation
        // named the absorbed record, and nothing records whether a surviving CRM
        // row is one a human vouched for — an anonymous registration can author
        // one that is byte-indistinguishable from a registrar's. So the merge
        // moves the edge (destroying it is the other failure) and hands the
        // authority decision back to the office.
        $this->assertFalse($carried->isConfirmed(), 'a confirmation rode the merge onto another row');

        // And the remedy the merge's own message names actually works — both
        // steps of it, in the order the message gives them.
        $this->asAdmin();
        $this->postJson($this->loginUrl($p2), ['login_email' => 'household@example.test'])
            ->assertStatus(422);

        $this->asAdmin();
        $this->postJson(
            "/api/admin/masjids/{$this->masjid->id}/groups/{$group->id}/members/confirm",
            ['membership_ids' => [$carried->id]],
        )->assertOk()->assertJsonPath('data.confirmed', 1);

        $this->asAdmin();
        $this->postJson($this->loginUrl($p2), ['login_email' => 'household@example.test'])
            ->assertOk()
            ->assertJsonPath('data.state', 'enabled');
    }

    /**
     * The same cascade, outside the family login: a duplicated CHILD.
     *
     * `behavior_awards.group_membership_id` and `hifz_entries.group_membership_id`
     * are both `cascadeOnDelete`, and `group_threads.about_membership_id` nulls.
     * Force-deleting the absorbed child therefore destroyed their whole
     * behaviour and ḥifẓ record, with no model event and nothing in the
     * response. Carrying the membership row keeps every one of those foreign
     * keys pointing at a row that still exists.
     */
    #[Test]
    public function merging_a_duplicate_child_keeps_the_records_hanging_off_their_roster_row(): void
    {
        $group = $this->makeGroup('Grade 3');
        $duplicate = $this->makeContact('Amina', 'Duplicate');
        $survivor = $this->makeContact('Amina', 'Yusuf');

        $membership = $this->enrol($group, $duplicate, GroupMembership::ROLE_MEMBER);
        $parent = $this->makeContact('Her', 'Parent');
        $edge = $this->enrol($group, $parent, GroupMembership::ROLE_GUARDIAN, $duplicate);

        $award = BehaviorAward::withoutMasjidScope()->create([
            'masjid_id' => $this->masjid->id,
            'group_id' => $group->id,
            'group_membership_id' => $membership->id,
            'skill_label' => 'Kindness',
            'skill_polarity' => 'positive',
            'points' => 3,
            'awarded_at' => now(),
        ]);

        $this->asAdmin();
        $this->postJson(
            "/api/admin/masjids/{$this->masjid->id}/contacts/{$duplicate->id}/merge",
            ['target_contact_id' => $survivor->id],
        )->assertOk();

        $this->assertDatabaseHas('behavior_awards', ['id' => $award->id]);

        $moved = GroupMembership::withoutMasjidScope()->find($membership->id);
        $this->assertNotNull($moved, 'the absorbed child\'s roster row was destroyed');
        $this->assertSame((int) $survivor->id, (int) $moved->contact_id);

        // The guardian edge that named the absorbed row as its ward follows it,
        // or the parent is left guarding nobody.
        $movedEdge = GroupMembership::withoutMasjidScope()->find($edge->id);
        $this->assertNotNull($movedEdge);
        $this->assertSame((int) $survivor->id, (int) $movedEdge->guardian_of_contact_id);
    }

    /**
     * A carried edge must not collide with one the survivor already holds.
     *
     * `group_memberships_edge_unique` spans (group, contact, role, ward), and
     * two CRM rows for one parent both recorded against the same child in the
     * same class is precisely the duplication a merge exists to clean up.
     */
    #[Test]
    public function an_edge_the_survivor_already_holds_is_dropped_rather_than_duplicated(): void
    {
        $group = $this->makeGroup('Grade 3');
        $child = $this->makeContact('Child');

        $this->enrol($group, $child, GroupMembership::ROLE_MEMBER);

        $p1 = $this->makeContact('Parent', 'One');
        $p2 = $this->makeContact('Parent', 'Two');

        $this->enrol($group, $p1, GroupMembership::ROLE_GUARDIAN, $child);
        $this->enrol($group, $p2, GroupMembership::ROLE_GUARDIAN, $child);

        $this->asAdmin();
        $this->postJson(
            "/api/admin/masjids/{$this->masjid->id}/contacts/{$p1->id}/merge",
            ['target_contact_id' => $p2->id],
        )->assertOk();

        $this->assertSame(
            1,
            GroupMembership::withoutMasjidScope()
                ->where('contact_id', $p2->id)
                ->where('role', GroupMembership::ROLE_GUARDIAN)
                ->count(),
        );
    }

    // ============================================ 3. STANDING AFTER THE GRANT

    /**
     * F3 — AND THE ANSWER IS NOW "NOTHING HAPPENS TO THE CREDENTIAL".
     *
     * Enrolling a contact who already holds a live parent-portal credential as a
     * PARTICIPANT used to REVOKE it, from a `GroupMembership::created` hook. The
     * concern was real — `standingIn()` set `feed = true` outright for any
     * participant, so a credential granted for one child's records became a
     * credential into a class — but the mechanism was three separate defects:
     *
     *   - AN ANONYMOUS STRANGER COULD FIRE IT. The public registration endpoint
     *     writes the payer a `member` row when `registrants` is absent, so a POST
     *     carrying only the household address off a class list destroyed a
     *     parent's credential and deleted the token in her phone mid-session.
     *   - IT FIRED ON ORDINARY ADMINISTRATION, on the 201 this very test
     *     performs: a parent joining the adult ḥalaqa, a teacher who is also a
     *     parent being given a class.
     *   - THE OFFICE COULD NOT UNDO IT. Re-enabling answered 422 (the contact
     *     now held the participant edge the enable-time rule refused), and the
     *     audit row named no actor at all, so the screen built to answer "who
     *     took my access away" read "Unknown staff member" for an act no staff
     *     member performed.
     *
     * The disclosure is now closed by SCOPING THE READ instead —
     * `GroupAudience::membershipsFor()` narrows a family principal to their
     * guardian edges — so the credential survives the enrolment intact and the
     * class it opened is shut anyway. This test pins the survival; the read
     * scope is pinned in GuardianEdgeProvenanceTest and GuardianOnlyLoginTest.
     */
    #[Test]
    public function enrolling_a_portal_holder_as_a_participant_leaves_their_sign_in_alone(): void
    {
        $child = $this->makeContact('Salma');
        $tariq = $this->makeGuardianOf($child);

        $this->asAdmin();
        $this->postJson($this->loginUrl($tariq), ['login_email' => 'tariq@example.test'])->assertOk();

        $token = $this->raw($tariq)->createFamilyToken()->plainTextToken;
        $this->assertTrue($this->raw($tariq)->familyLoginIsActive());

        $teens = $this->makeGroup('Teens Halaqa');

        $this->asAdmin();
        $this->postJson(
            "/api/admin/masjids/{$this->masjid->id}/groups/{$teens->id}/members",
            ['contact_id' => $tariq->id, 'role' => GroupMembership::ROLE_MEMBER],
        )->assertStatus(201);

        $this->assertTrue(
            $this->raw($tariq)->familyLoginIsActive(),
            'an ordinary roster add destroyed a working parent-portal credential',
        );

        $this->assertNotContains(
            ContactLoginEvent::ACTION_REVOKED,
            $this->events($tariq)->pluck('action')->all(),
            'a revocation was recorded for an act nobody asked for',
        );

        $this->assertSame(1, $this->raw($tariq)->tokens()->count());

        // And the token already in the phone still works — she is not signed out
        // of her own portal by somebody else's roster edit.
        $this->asANewRequest();
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson("/api/family/masjids/{$this->masjid->id}/me")
            ->assertOk();
    }

    /**
     * THE DELIBERATE NON-REVOCATION, and why it is not the same defect.
     *
     * Removing a guardian edge does NOT end the credential. A child changes
     * class, a roster is corrected, an edge is retyped — for the interval
     * between the delete and the re-add the parent holds zero edges, and
     * revoking there would burn a family's sign-in every term. The roster
     * already does the disclosure work: `FamilyPortalTest` pins that a parent
     * with no edges left reads nothing but `/me`.
     *
     * What was missing was the OFFICE being able to see it. The family-login
     * panel now derives eligibility from the same code `enable()` refuses with,
     * so a lapsed grant is legible on the screen the office is already looking
     * at and revoking it stays a deliberate act.
     */
    #[Test]
    public function removing_a_guardian_edge_leaves_the_credential_standing_and_says_so(): void
    {
        $child = $this->makeContact('Amina');
        $parent = $this->makeGuardianOf($child);

        $this->asAdmin();
        $this->postJson($this->loginUrl($parent), ['login_email' => 'parent@example.test'])->assertOk();

        $edge = GroupMembership::withoutMasjidScope()
            ->where('contact_id', $parent->id)
            ->where('role', GroupMembership::ROLE_GUARDIAN)
            ->firstOrFail();

        $this->asAdmin();
        $this->deleteJson(
            "/api/admin/masjids/{$this->masjid->id}/groups/{$edge->group_id}/members/{$edge->id}",
        )->assertOk();

        $this->assertTrue(
            $this->raw($parent)->familyLoginIsActive(),
            'a roster correction silently locked a parent out',
        );

        $this->asAdmin();
        $this->getJson($this->loginUrl($parent))
            ->assertOk()
            ->assertJsonPath('data.state', 'enabled')
            ->assertJsonPath('data.eligible', false)
            ->assertJsonStructure(['data' => ['ineligible_reason']]);
    }

    // ================================================== 4. THE RELEASED HALF

    /**
     * F5. The refusal an operator accepts says "both halves go on the access
     * history". The release half was written onto the LOSING contact, and when
     * that contact is soft-deleted — the case the reassignment door exists for —
     * no screen in this application shows it.
     *
     * Measured: winner's panel carried ONE event, `enabled`.
     */
    #[Test]
    public function reassigning_a_deleted_holders_address_puts_the_release_half_on_the_winners_history(): void
    {
        $child = $this->makeContact('Child');
        $group = $this->makeGroup('Grade 3');
        $mother = $this->makeGuardianOf($child, $group);
        $father = $this->makeContact('Father');
        $this->enrol($group, $father, GroupMembership::ROLE_GUARDIAN, $child);

        $this->asAdmin();
        $this->postJson($this->loginUrl($mother), ['login_email' => 'household@example.test'])->assertOk();

        $this->asAdmin();
        $this->deleteJson("/api/admin/masjids/{$this->masjid->id}/contacts/{$mother->id}")->assertOk();

        $this->asAdmin();
        $this->postJson($this->loginUrl($father), [
            'login_email' => 'household@example.test',
            'reassign_address' => true,
        ])->assertOk();

        $actions = $this->events($father)->pluck('action')->all();

        $this->assertContains(
            ContactLoginEvent::ACTION_ADDRESS_CLAIMED,
            $actions,
            'the release half was written where no screen can read it',
        );
        $this->assertContains(ContactLoginEvent::ACTION_ENABLED, $actions);

        // Read through the panel the operator actually looks at, not the table.
        $this->asAdmin();
        $panel = $this->getJson($this->loginUrl($father))->assertOk()->json('data.events');

        $this->assertContains(
            ContactLoginEvent::ACTION_ADDRESS_CLAIMED,
            array_column($panel, 'action'),
        );
        $this->assertContains(
            'household@example.test',
            array_column($panel, 'login_email'),
        );
    }

    // ========================================== 5. THE SCREEN BEFORE THE CLICK

    /**
     * F8. The registrar reaches a nine-year-old on a school roster, and the
     * screen offered "Enable parent portal sign-in" with nothing on it to say
     * the server would refuse.
     *
     * The panel's `eligible` is computed by the SAME method `enable()` refuses
     * with, so the preview cannot be stricter than the write or looser than it.
     */
    #[Test]
    public function the_panel_refuses_before_the_operator_types_an_address(): void
    {
        $group = $this->makeGroup('Grade 3');
        $child = $this->makeContact('Amina', 'Yusuf');
        $this->enrol($group, $child, GroupMembership::ROLE_MEMBER);

        $this->asAdmin();
        $panel = $this->getJson($this->loginUrl($child))->assertOk()->json('data');

        $this->assertFalse($panel['eligible']);
        $this->assertNotNull($panel['ineligible_reason']);

        // The preview is the write's own sentence, not a second one that agrees
        // today.
        $this->asAdmin();
        $refusal = $this->postJson($this->loginUrl($child), ['login_email' => 'amina@example.test'])
            ->assertStatus(422)
            ->json('message');

        $this->assertSame($panel['ineligible_reason'], $refusal);
    }

    /** The eligible case must still read as eligible — over-refusal is a defect. */
    #[Test]
    public function an_ordinary_guardian_reads_as_eligible(): void
    {
        $child = $this->makeContact('Child');
        $parent = $this->makeGuardianOf($child);

        $this->asAdmin();
        $panel = $this->getJson($this->loginUrl($parent))->assertOk()->json('data');

        $this->assertTrue($panel['eligible']);
        $this->assertNull($panel['ineligible_reason']);
    }
}
