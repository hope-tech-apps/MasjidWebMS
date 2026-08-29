<?php

namespace Tests\Feature;

use App\Models\BehaviorAward;
use App\Models\BehaviorSkill;
use App\Models\Contact;
use App\Models\ContactLoginEvent;
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
 * GUARDIAN-EDGE PROVENANCE AND THE CREDENTIAL LIFECYCLE (G1, G2, G3).
 *
 * Three defects, one subject: WHO IS RECORDED AS A CHILD'S GUARDIAN, and what
 * that recording is allowed to do to a credential. Production carries 0
 * offerings, 0 registrations, 0 group_memberships, 0 guardian edges and 0
 * contacts with a family login, so none of this was ever a live exposure — it
 * is what the code would have done to the first real school provisioned onto
 * it.
 *
 * ---------------------------------------------------------------------------
 * G1 — AN ANONYMOUS POST MADE ONE PARENT THE GUARDIAN OF ANOTHER FAMILY'S CHILD
 * ---------------------------------------------------------------------------
 *
 * `OfferingRegistrationsController::resolveContact()` keyed on
 * `(masjid_id, email)` and returned any EXISTING row it matched, for the payer
 * and for every registrant alike. `RegistrationService::writeRosterMemberships()`
 * then reused the registrant's existing participant row and wrote a
 * `guardian` edge from the payer over them. No token, no session:
 *
 *     POST /api/v1/offerings/grade-5-enrichment/register   masjid-id: 1
 *       { "fee_plan_id": N,
 *         "payer":       {"name":"Bilal Attacker","email":"bilal@example.test"},
 *         "registrants": [{"name":"Salma Other","email":"salma.other@school.test"}],
 *         "data":        {"full_name":"Salma Other"} }
 *     -> 200
 *
 * Bilal already held a legitimately-issued family credential (guardian of his
 * own child in Grade 3), so with his OWN token the manufactured edge opened
 * Salma's behaviour record, her ḥifẓ record and every participant thread naming
 * her — the channel .claude/rules/groups.md describes as "where a teacher and a
 * guardian discuss a safeguarding concern". `enable()` was no containment: the
 * edge is a genuine guardian edge, so `ineligibilityReason()` returned null and
 * the attacker stayed eligible. The credential door was well defended and the
 * guardianship underneath it was anonymously writable.
 *
 * ---------------------------------------------------------------------------
 * G2 — AND AN ANONYMOUS POST DESTROYED A WORKING CREDENTIAL
 * ---------------------------------------------------------------------------
 *
 * `GroupMembership`'s `created` hook called
 * `FamilyAccessService::revokeIfNowHoldsParticipantStanding()`, so ANY new
 * participant row on a contact holding a live credential revoked it — tokens
 * deleted, parent signed out mid-session, an audit row naming nobody, and no
 * way for the office to put it back short of un-enrolling them. The public
 * registration endpoint documents "absent registrants means the payer registers
 * themselves" and then writes the payer a `member` row, so a stranger holding
 * only the household address printed on a class list could fire it.
 *
 * ---------------------------------------------------------------------------
 * G3 — THE EVIDENCE OF A REVOCATION WAS WRITTEN WHERE NO SCREEN COULD REACH IT
 * ---------------------------------------------------------------------------
 *
 * `ContactsController::destroy` revokes (correctly, writing a `revoked` row)
 * and then soft-deletes; `ContactFamilyLoginController::show()` resolved the
 * contact with a NON-trashed `findOrFail`, so the panel built to answer "who
 * took my access away" answered 404, and no restore route existed anywhere in
 * routes/admin.php.
 */
class GuardianEdgeProvenanceTest extends TestCase
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

        // ---- the other family's child, already on the Grade 5 roster ----
        $this->salma = Contact::factory()->create([
            'masjid_id' => $this->masjid->id,
            'first_name' => 'Salma',
            'last_name' => 'Other',
            'email' => 'salma.other@school.test',
        ]);
        $this->salmaMembership = $this->seedMembership($this->grade5, $this->salma, GroupMembership::ROLE_MEMBER);

        // ---- the attacker: a real parent of a real child in Grade 3 ----
        $this->bilal = Contact::factory()->create([
            'masjid_id' => $this->masjid->id,
            'first_name' => 'Bilal',
            'last_name' => 'Attacker',
            'email' => 'bilal@example.test',
        ]);

        $bilalsChild = Contact::factory()->create(['masjid_id' => $this->masjid->id]);
        $this->seedMembership($this->grade3, $bilalsChild, GroupMembership::ROLE_MEMBER);
        $this->seedMembership($this->grade3, $this->bilal, GroupMembership::ROLE_GUARDIAN, $bilalsChild);

        // A legitimately-issued credential, through the real service.
        app(TenantContext::class)->set($this->masjid->id);
        app(FamilyAccessService::class)->enable($this->bilal, 'bilal@example.test', $this->admin, '127.0.0.1');
        $this->bilal->refresh();

        app(TenantContext::class)->forgetTenant();
    }

    // ===================================================== G1 — provenance

    #[Test]
    public function an_anonymous_registration_cannot_make_a_stranger_the_guardian_of_an_existing_child(): void
    {
        [$offering, $plan] = $this->makeOffering($this->grade5, 'grade-5-enrichment');

        $award = $this->seedAward($this->salmaMembership, 'Disruptive in class');

        $this->postJson("/api/v1/offerings/{$offering->slug}/register", [
            'fee_plan_id' => $plan->id,
            'payer' => ['name' => 'Bilal Attacker', 'email' => 'bilal@example.test'],
            'registrants' => [['name' => 'Salma Other', 'email' => 'salma.other@school.test']],
            'data' => ['full_name' => 'Salma Other'],
        ], ['masjid-id' => (string) $this->masjid->id])->assertStatus(200);

        // THE EDGE IS WRITTEN — and this assertion INVERTED on purpose.
        //
        // An earlier round answered this POST by refusing to let a registrant
        // ever match a pre-existing contact, so the edge did not exist. That
        // refusal has been reverted (it 403'd a teacher out of her own
        // classroom, forked a returning child into N people on one roster, and
        // still left the identical hole one field away in `payer`). What stops
        // the disclosure now is not the absence of the row but the AUTHORITY on
        // it: an anonymous POST writes a CLAIM, and a claim grants nothing.
        $edge = GroupMembership::withoutMasjidScope()
            ->where('role', GroupMembership::ROLE_GUARDIAN)
            ->where('contact_id', $this->bilal->id)
            ->where('guardian_of_contact_id', $this->salma->id)
            ->first();

        $this->assertNotNull($edge, 'the fixture must exercise the edge, not the absence of one');
        $this->assertSame(GroupMembership::PROVENANCE_SELF_ASSERTED, $edge->provenance);
        $this->assertFalse($edge->isConfirmed());
        $this->assertNull($edge->confirmed_by_user_id);

        // THE READ. The whole point of the edge — with Bilal's own token.
        $this->as($this->bilal)
            ->getJson($this->familyUrl("/groups/{$this->grade5->id}/members/{$this->salmaMembership->id}/awards"))
            ->assertStatus(403);

        $this->as($this->bilal)
            ->getJson($this->familyUrl("/groups/{$this->grade5->id}/members/{$this->salmaMembership->id}/hifz"))
            ->assertStatus(403);

        // …and the group itself, because a self-asserted edge is no standing at
        // all — the same 403 a group he is not in answers.
        $this->as($this->bilal)
            ->getJson($this->familyUrl("/groups/{$this->grade5->id}/threads"))
            ->assertStatus(403);

        // The listing carries only the group his CONFIRMED edge names.
        $this->as($this->bilal)
            ->getJson($this->familyUrl('/groups'))
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->grade3->id);

        $this->assertNotNull($award->fresh(), 'the award fixture must still exist for the read above to be meaningful');
    }

    #[Test]
    public function the_payer_field_is_the_same_writer_one_field_away_and_grants_no_more(): void
    {
        // `normalizeRegistrants()` turns an ABSENT registrants array into
        // `[$payer]`, and the payer is find-or-create on (masjid, LOWER(email)).
        // So the identical act is available with no `registrants` key at all:
        // measured, this enrolled the REAL child in a class she was never in and
        // bound her as a registrant, while every guard shipped so far watched
        // the other field.
        //
        // The provenance rule is not per-field, so this needs no second guard:
        // whatever this endpoint writes is a claim.
        [$offering, $plan] = $this->makeOffering($this->grade3, 'payer-door');

        $this->postJson("/api/v1/offerings/{$offering->slug}/register", [
            'fee_plan_id' => $plan->id,
            'payer' => ['name' => 'Salma Other', 'email' => 'salma.other@school.test'],
            'data' => ['full_name' => 'Salma Other'],
        ], ['masjid-id' => (string) $this->masjid->id])->assertStatus(200);

        $planted = GroupMembership::withoutMasjidScope()
            ->where('group_id', $this->grade3->id)
            ->where('contact_id', $this->salma->id)
            ->firstOrFail();

        $this->assertSame(
            GroupMembership::PROVENANCE_SELF_ASSERTED,
            $planted->provenance,
            'the payer door wrote a roster row nobody has to stand behind'
        );

        // AND IT IS VISIBLE. The office is not asked to notice a row; it is
        // counted for them on the roster the button lives on.
        Sanctum::actingAs($this->admin);

        $this->getJson("/api/admin/masjids/{$this->masjid->id}/groups/{$this->grade3->id}/members")
            ->assertStatus(200)
            ->assertJsonPath('meta.pending_claims', 1);
    }

    #[Test]
    public function an_anonymous_post_cannot_read_a_staff_member_into_a_class_they_are_not_in(): void
    {
        // The sharpest thing the payer door bought, and the one the pending
        // queue is not a sufficient answer to: `GroupAudience::identitiesFor()`
        // bridges a STAFF login to a Contact by email, and `standingIn()` grants
        // any participant the feed outright. So an anonymous POST naming a
        // masjid admin's own address used to hand them a class feed —
        // photographs of children — that .claude/rules/groups.md obligation 4
        // exists to keep from the whole tenant, including from admins who hold
        // `view contacts`.
        Contact::factory()->create([
            'masjid_id' => $this->masjid->id,
            'first_name' => 'Nosy',
            'last_name' => 'Admin',
            'email' => $this->admin->email,
        ]);

        [$offering, $plan] = $this->makeOffering($this->grade5, 'reader-door');

        Sanctum::actingAs($this->admin);
        $this->getJson("/api/admin/masjids/{$this->masjid->id}/groups/{$this->grade5->id}/posts")
            ->assertStatus(403);

        Auth::forgetGuards();
        app(TenantContext::class)->forgetTenant();

        $this->postJson("/api/v1/offerings/{$offering->slug}/register", [
            'fee_plan_id' => $plan->id,
            'payer' => ['name' => 'Nosy Admin', 'email' => $this->admin->email],
            'data' => ['full_name' => 'Nosy Admin'],
        ], ['masjid-id' => (string) $this->masjid->id])->assertStatus(200);

        Auth::forgetGuards();
        Sanctum::actingAs($this->admin);

        $this->getJson("/api/admin/masjids/{$this->masjid->id}/groups/{$this->grade5->id}/posts")
            ->assertStatus(403, 'an anonymous POST read a staff member into a class feed');
    }

    #[Test]
    public function the_paid_path_mints_only_a_claim_when_the_webhook_confirms_it(): void
    {
        // The free path confirms in-request; a PRICED one materialises its
        // roster minutes later, from `RegistrationService::confirm()` called by
        // a webhook with no request and no principal. THAT IS WHY PROVENANCE IS
        // SET EXPLICITLY BY THE WRITER RATHER THAN READ OFF THE AMBIENT
        // PRINCIPAL: an anonymous POST and a Stripe callback and an admin
        // re-driving the same path are three different ambient answers to one
        // question — the registrant list came from a public form in all three.
        [$offering, $plan] = $this->makeOffering($this->grade5, 'grade-5-priced', free: false);

        $uuid = $this->postJson("/api/v1/offerings/{$offering->slug}/register", [
            'fee_plan_id' => $plan->id,
            'payer' => ['name' => 'Bilal Attacker', 'email' => 'bilal@example.test'],
            'registrants' => [['name' => 'Salma Other', 'email' => 'salma.other@school.test']],
            'data' => ['full_name' => 'Salma Other'],
        ], ['masjid-id' => (string) $this->masjid->id])
            ->assertStatus(200)
            ->json('data.registration_uuid');

        $registration = \App\Models\Registration::withoutMasjidScope()
            ->where('uuid', $uuid)
            ->firstOrFail();

        $this->assertTrue($registration->isPending(), 'the fixture must exercise the deferred path');

        app(TenantContext::class)->forgetTenant();
        app(\App\Services\Registrations\RegistrationService::class)->confirm($registration);

        // The deferred write inherits the rule rather than being a second way in.
        $this->assertSame(
            0,
            GroupMembership::withoutMasjidScope()
                ->where('role', GroupMembership::ROLE_GUARDIAN)
                ->where('guardian_of_contact_id', $this->salma->id)
                ->confirmed()
                ->count(),
            'a webhook confirmed a guardianship nobody at the school stood behind'
        );

        // And the real child was not enrolled a second time by the deferred write.
        $this->assertSame(
            1,
            GroupMembership::withoutMasjidScope()
                ->where('group_id', $this->grade5->id)
                ->where('contact_id', $this->salma->id)
                ->count(),
        );
    }

    #[Test]
    public function a_returning_family_still_registers_and_still_gets_its_guardian_edge(): void
    {
        // THE LEGITIMATE CASE THE FIX MUST NOT BREAK. A parent who registered
        // last season is already a Contact; they type the same address again
        // and must attach to the SAME record rather than spawning a second one,
        // and their guardian edge over the child on this form must still be
        // written by the registration.
        [$offering, $plan] = $this->makeOffering($this->grade5, 'returning-family');

        $payerRowsBefore = Contact::withoutMasjidScope()->where('email', 'bilal@example.test')->count();

        $this->postJson("/api/v1/offerings/{$offering->slug}/register", [
            'fee_plan_id' => $plan->id,
            'payer' => ['name' => 'Bilal Attacker', 'email' => 'bilal@example.test'],
            'registrants' => [['name' => 'Yusuf Attacker']],
            'data' => ['full_name' => 'Yusuf Attacker'],
        ], ['masjid-id' => (string) $this->masjid->id])->assertStatus(200);

        $this->assertSame(
            $payerRowsBefore,
            Contact::withoutMasjidScope()->where('email', 'bilal@example.test')->count(),
            'the returning payer was duplicated instead of attaching to their own record'
        );

        $yusuf = Contact::withoutMasjidScope()->where('first_name', 'Yusuf')->firstOrFail();

        $this->assertSame(
            1,
            GroupMembership::withoutMasjidScope()
                ->where('group_id', $this->grade5->id)
                ->where('contact_id', $this->bilal->id)
                ->where('role', GroupMembership::ROLE_GUARDIAN)
                ->where('guardian_of_contact_id', $yusuf->id)
                ->count(),
            'the ordinary guardian edge a registration exists to write was not written'
        );

        $this->assertSame(
            1,
            GroupMembership::withoutMasjidScope()
                ->where('group_id', $this->grade5->id)
                ->where('contact_id', $yusuf->id)
                ->whereIn('role', GroupMembership::PARTICIPANT_ROLES)
                ->count(),
            'the child was not enrolled'
        );
    }

    #[Test]
    public function two_children_on_one_household_address_stay_two_people(): void
    {
        // Matching registrants on `contacts.email` also collapsed two siblings
        // sharing a household mailbox into ONE person: the second registrant
        // resolved to the first one's row and `normalizeRegistrants()` deduped
        // it away, so a family that signed up two children got one enrolled.
        [$offering, $plan] = $this->makeOffering($this->grade5, 'two-siblings');

        $this->postJson("/api/v1/offerings/{$offering->slug}/register", [
            'fee_plan_id' => $plan->id,
            'payer' => ['name' => 'Hana Household', 'email' => 'household@example.test'],
            'registrants' => [
                ['name' => 'Zayd Household', 'email' => 'household@example.test'],
                ['name' => 'Ruqayya Household', 'email' => 'household@example.test'],
            ],
            'data' => ['full_name' => 'Zayd Household'],
        ], ['masjid-id' => (string) $this->masjid->id])->assertStatus(200);

        $this->assertSame(
            2,
            Contact::withoutMasjidScope()->whereIn('first_name', ['Zayd', 'Ruqayya'])->count(),
            'two siblings on one household address were collapsed into one contact'
        );

        // …and the parent is not one of them, nor their own guardian.
        $hana = Contact::withoutMasjidScope()->where('first_name', 'Hana')->firstOrFail();

        $this->assertSame(
            2,
            GroupMembership::withoutMasjidScope()
                ->where('group_id', $this->grade5->id)
                ->where('contact_id', $hana->id)
                ->where('role', GroupMembership::ROLE_GUARDIAN)
                ->count(),
            'the household did not end up with one guardian edge per child'
        );
    }

    #[Test]
    public function a_payer_who_lists_themselves_as_the_registrant_is_not_duplicated(): void
    {
        // The clause the household case must not cost: an adult who fills in
        // the registrant row with their OWN name and address is the payer, and
        // must resolve to the payer's record rather than to a second copy of
        // themselves that the payer is then made the guardian of.
        $halaqa = Group::factory()->create([
            'masjid_id' => $this->masjid->id,
            'kind' => Group::KIND_HALAQA,
            'name' => 'Adult Tafsir',
        ]);

        [$offering, $plan] = $this->makeOffering($halaqa, 'adult-tafsir-self');

        $this->postJson("/api/v1/offerings/{$offering->slug}/register", [
            'fee_plan_id' => $plan->id,
            'payer' => ['name' => 'Rania Selfsign', 'email' => 'rania@example.test'],
            'registrants' => [['name' => 'Rania Selfsign', 'email' => 'rania@example.test']],
            'data' => ['full_name' => 'Rania Selfsign'],
        ], ['masjid-id' => (string) $this->masjid->id])->assertStatus(200);

        $this->assertSame(
            1,
            Contact::withoutMasjidScope()->where('first_name', 'Rania')->count(),
            'the payer registering themselves was written twice'
        );

        $this->assertSame(
            0,
            GroupMembership::withoutMasjidScope()
                ->where('group_id', $halaqa->id)
                ->where('role', GroupMembership::ROLE_GUARDIAN)
                ->count(),
            'nobody is their own guardian'
        );
    }

    // ============================================ G2 — the credential's life

    #[Test]
    public function an_anonymous_self_registration_does_not_destroy_a_parents_credential(): void
    {
        $halaqa = Group::factory()->create([
            'masjid_id' => $this->masjid->id,
            'kind' => Group::KIND_HALAQA,
            'name' => 'Adult Tafsir',
        ]);

        [$offering, $plan] = $this->makeOffering($halaqa, 'adult-tafsir', free: true);

        $tokenBefore = $this->bilal->createFamilyToken()->accessToken->id;

        // "Absent registrants means the payer registers themselves"
        // (RegisterForOfferingRequest) — one `member` row, on a stranger's POST.
        $this->postJson("/api/v1/offerings/{$offering->slug}/register", [
            'fee_plan_id' => $plan->id,
            'payer' => ['name' => 'Bilal Attacker', 'email' => 'bilal@example.test'],
            'data' => ['full_name' => 'Bilal Attacker'],
        ], ['masjid-id' => (string) $this->masjid->id])->assertStatus(200);

        $this->assertNull($this->bilal->fresh()->login_revoked_at, 'an anonymous POST revoked a parent credential');
        $this->assertTrue($this->bilal->fresh()->familyLoginIsActive());
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $tokenBefore]);

        // And no audit row blaming a staff member for an act no staff member
        // performed.
        $this->assertSame(
            0,
            ContactLoginEvent::withoutMasjidScope()
                ->where('contact_id', $this->bilal->id)
                ->where('action', ContactLoginEvent::ACTION_REVOKED)
                ->count(),
        );
    }

    #[Test]
    public function adding_a_parent_to_an_adult_halaqa_through_the_admin_door_keeps_their_sign_in(): void
    {
        $halaqa = Group::factory()->create([
            'masjid_id' => $this->masjid->id,
            'kind' => Group::KIND_HALAQA,
            'name' => 'Adult Tafsir',
        ]);

        Sanctum::actingAs($this->admin);

        $this->postJson("/api/admin/masjids/{$this->masjid->id}/groups/{$halaqa->id}/members", [
            'contact_id' => $this->bilal->id,
            'role' => GroupMembership::ROLE_MEMBER,
        ])->assertStatus(201);

        $this->assertTrue(
            $this->bilal->fresh()->familyLoginIsActive(),
            'joining the adult halaqa ended a parent portal sign-in'
        );
    }

    #[Test]
    public function making_a_parent_the_teacher_of_a_class_keeps_their_sign_in(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/admin/masjids/{$this->masjid->id}/groups/{$this->grade5->id}/members", [
            'contact_id' => $this->bilal->id,
            'role' => GroupMembership::ROLE_LEADER,
        ])->assertStatus(201);

        $this->assertTrue(
            $this->bilal->fresh()->familyLoginIsActive(),
            'a teacher who is also a parent lost their portal sign-in on being given a class'
        );
    }

    #[Test]
    public function a_parent_who_is_also_on_a_roster_can_be_given_a_sign_in_in_the_first_place(): void
    {
        // The other direction of the same requirement: the enable-time refusal.
        $halaqa = Group::factory()->create([
            'masjid_id' => $this->masjid->id,
            'kind' => Group::KIND_HALAQA,
            'name' => 'Adult Tafsir',
        ]);

        $parent = Contact::factory()->create(['masjid_id' => $this->masjid->id]);
        $child = Contact::factory()->create(['masjid_id' => $this->masjid->id]);

        $this->seedMembership($this->grade3, $child, GroupMembership::ROLE_MEMBER);
        $this->seedMembership($this->grade3, $parent, GroupMembership::ROLE_GUARDIAN, $child);
        $this->seedMembership($halaqa, $parent, GroupMembership::ROLE_MEMBER);

        Sanctum::actingAs($this->admin);

        // The PREVIEW and the WRITE must agree — one method decides both.
        $this->getJson("/api/admin/masjids/{$this->masjid->id}/contacts/{$parent->id}/family-login")
            ->assertStatus(200)
            ->assertJsonPath('data.eligible', true);

        $this->postJson("/api/admin/masjids/{$this->masjid->id}/contacts/{$parent->id}/family-login", [
            'login_email' => 'halaqa-parent@example.test',
        ])->assertStatus(200)->assertJsonPath('data.state', 'enabled');
    }

    #[Test]
    public function a_dual_role_credential_reads_the_ward_and_not_the_holder(): void
    {
        // THE PROPERTY THAT REPLACES THE BLANKET REFUSAL. A parent may hold a
        // roster place of their own; what the credential READS is scoped to
        // their wards, so the group they are a participant of stays shut to it.
        $halaqa = Group::factory()->create([
            'masjid_id' => $this->masjid->id,
            'kind' => Group::KIND_HALAQA,
            'name' => 'Adult Tafsir',
        ]);

        $ownMembership = $this->seedMembership($halaqa, $this->bilal, GroupMembership::ROLE_MEMBER);
        $threadAboutThem = $this->seedParticipantThread($halaqa, $ownMembership, 'About Bilal');
        $this->seedAward($ownMembership, 'Late to halaqa');

        // Their own participant standing opens nothing through the portal.
        $this->as($this->bilal)->getJson($this->familyUrl("/groups/{$halaqa->id}"))->assertStatus(403);
        $this->as($this->bilal)->getJson($this->familyUrl("/groups/{$halaqa->id}/posts"))->assertStatus(403);
        $this->as($this->bilal)
            ->getJson($this->familyUrl("/groups/{$halaqa->id}/threads/{$threadAboutThem->id}"))
            ->assertStatus(403);
        $this->as($this->bilal)
            ->getJson($this->familyUrl("/groups/{$halaqa->id}/members/{$ownMembership->id}/awards"))
            ->assertStatus(403);

        // …and their WARD's group still answers, which is what the credential
        // was issued for.
        $this->as($this->bilal)
            ->getJson($this->familyUrl("/groups/{$this->grade3->id}"))
            ->assertStatus(200);

        // The listing they can reach carries only their own group.
        $this->as($this->bilal)
            ->getJson($this->familyUrl('/groups'))
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->grade3->id);
    }

    // ============================================ G3 — reachable evidence

    #[Test]
    public function the_revoked_row_a_deletion_writes_is_still_readable_afterwards(): void
    {
        Sanctum::actingAs($this->admin);

        $this->deleteJson("/api/admin/masjids/{$this->masjid->id}/contacts/{$this->bilal->id}")
            ->assertStatus(200);

        $this->assertNotNull(Contact::withTrashed()->find($this->bilal->id)->deleted_at);

        $response = $this->getJson(
            "/api/admin/masjids/{$this->masjid->id}/contacts/{$this->bilal->id}/family-login"
        )->assertStatus(200);

        $actions = array_column($response->json('data.events'), 'action');

        $this->assertContains(ContactLoginEvent::ACTION_REVOKED, $actions);
        $this->assertContains(ContactLoginEvent::ACTION_ENABLED, $actions);
    }

    #[Test]
    public function a_deleted_member_can_be_found_and_restored(): void
    {
        Sanctum::actingAs($this->admin);

        $this->deleteJson("/api/admin/masjids/{$this->masjid->id}/contacts/{$this->bilal->id}")
            ->assertStatus(200);

        // The ordinary listing still hides them.
        $ids = array_column(
            $this->getJson("/api/admin/masjids/{$this->masjid->id}/contacts")->json('data.data'),
            'id'
        );
        $this->assertNotContains($this->bilal->id, $ids);

        // …and the deleted listing finds them.
        $ids = array_column(
            $this->getJson("/api/admin/masjids/{$this->masjid->id}/contacts?trashed=only")->json('data.data'),
            'id'
        );
        $this->assertContains($this->bilal->id, $ids);

        $this->postJson("/api/admin/masjids/{$this->masjid->id}/contacts/{$this->bilal->id}/restore")
            ->assertStatus(200);

        $this->assertNull(Contact::withTrashed()->find($this->bilal->id)->deleted_at);

        // A RESTORE IS NOT A RE-GRANT. The sign-in stays revoked until somebody
        // deliberately re-opens it.
        $this->assertNotNull(Contact::find($this->bilal->id)->login_revoked_at);
        $this->assertFalse(Contact::find($this->bilal->id)->familyLoginIsActive());
    }

    #[Test]
    public function restoring_is_gated_and_tenant_scoped(): void
    {
        $other = $this->makeMasjid();
        $stranger = Contact::factory()->create(['masjid_id' => $other->id]);
        $stranger->delete();

        Sanctum::actingAs($this->admin);

        $this->postJson("/api/admin/masjids/{$this->masjid->id}/contacts/{$stranger->id}/restore")
            ->assertStatus(404);

        Auth::forgetGuards();

        $this->postJson("/api/admin/masjids/{$this->masjid->id}/contacts/{$this->bilal->id}/restore")
            ->assertStatus(401);
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

    /**
     * FREE by default, deliberately: a free total is the one path that confirms
     * synchronously in-request (the carve-out RegistrationService documents), so
     * `writeRosterMemberships()` — the roster and guardian-edge writer this file
     * is about — actually runs inside the POST. A priced plan leaves the
     * registration `pending` and materialises the roster only when a webhook
     * confirms it, through the SAME seam.
     *
     * @return array{0: Offering, 1: FeePlan}
     */
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

    private function familyUrl(string $path = ''): string
    {
        return "/api/family/masjids/{$this->masjid->id}" . $path;
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
