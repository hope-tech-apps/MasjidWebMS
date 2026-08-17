<?php

namespace Tests\Feature;

use App\Models\BehaviorAward;
use App\Models\BehaviorSkill;
use App\Models\Contact;
use App\Models\FeePlan;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupThread;
use App\Models\HifzEntry;
use App\Models\Masjid;
use App\Models\Offering;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ConfirmsRosterClaims;
use Tests\TestCase;

/**
 * AN ABSENT VALUE IS NEVER AN IDENTITY MATCH.
 *
 * `RosterMergeService` asks one question of a merge — "did the identity this
 * row's authority is read through change?" — and the previous round answered it
 * on the HOLDER end with
 *
 *     $holderIdentityChanged = $this->addressOf($source) !== $this->addressOf($target);
 *
 * where `addressOf()` returns `''` for a contact with no email. Two address-less
 * adults therefore compared EQUAL, the merge called them the same person, and a
 * CONFIRMED guardian edge moved onto a different human carrying its
 * confirmation, its `confirmed_by_user_id` and its recorded media consent —
 * reporting `unconfirmed: 0`.
 *
 * The same file refuses exactly this on the WARD end and says why (`carry()`,
 * "most children have none … so equal addresses on the ward side is usually
 * `'' == ''`"). The holder end got the opposite treatment twenty lines later:
 * `addressOf()`'s own docblock notices `''` and reasons only about
 * `'' != 'real@x'`, never about `'' == ''`.
 *
 * THE TRIGGER IS AN ORDINARY SCHOOL DAY. A school imports its parents by phone
 * with no email; children have none by construction. An unauthenticated
 * `POST /api/v1/offerings/{slug}/register` names a registrant with no address at
 * all (`registrants.*.email` is `nullable`), which plants a second, address-less
 * contact under a real parent's name. The registrar then merges what looks like
 * the same person, and:
 *
 *     [P11] planted phantom #4 email=NULL phone=NULL
 *     [P11] roster block: {"moved":1,"dropped":0,"unconfirmed":0,
 *             "guardian_claims_reissued":0,"confirmed_guardian_edges_dropped":0,
 *             "family_logins_left_without_a_ward":0,"guardian_claims":[],
 *             "message":"1 roster entry moved onto this member and 0 duplicates dropped."}
 *     [P11] edge now: {"id":2,"contact_id":4,"guardian_of_contact_id":1,
 *                      "provenance":"confirmed","consent_scope":"media",
 *                      "confirmed_by_user_id":1}
 *     [P11] family-login panel: {"eligible":true,"ineligible_reason":null}
 *     [P11] enable at the attacker address -> 200
 *     [P11] attacker token: /groups 200 (is_guardian, children:[Fatima Ahmed]) ·
 *           /threads 200 "Safeguarding: incident on 3 Sept" ·
 *           /members/1/awards 200 "Left the classroom without permission" ·
 *           /hifz 200 sabak 78:1-10
 *
 * Every assertion below is one line of that transcript.
 */
class RosterMergeAbsentIdentityTest extends TestCase
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

    // ========================================================== F1, the chain

    #[Test]
    public function a_merge_between_two_address_less_adults_does_not_carry_a_confirmed_guardianship(): void
    {
        [$fatima, $fatimasRow, $realAisha, $edge] = $this->seedTheRealFamily();

        $phantom = $this->plantAnAddressLessPhantomNamed('Aisha', 'Ahmed');

        // Both adults are address-less. That is the whole of the input.
        $this->assertNull($realAisha->fresh()->email);
        $this->assertNull($phantom->email);

        // The registrar de-duplicates two identically-named parents, in the
        // direction that puts the stranger's row on top. An ordinary act.
        Sanctum::actingAs($this->admin);

        $merge = $this->postJson($this->adminUrl("/contacts/{$realAisha->id}/merge"), [
            'target_contact_id' => $phantom->id,
        ])->assertStatus(200);

        $moved = GroupMembership::withoutMasjidScope()->whereKey($edge->id)->first();

        // ---------------------------------------------------------------
        // THE ROW MAY MOVE. ITS AUTHORITY MAY NOT.
        // ---------------------------------------------------------------
        $live = $moved ?? GroupMembership::withoutMasjidScope()
            ->where('contact_id', $phantom->id)
            ->where('role', GroupMembership::ROLE_GUARDIAN)
            ->where('guardian_of_contact_id', $fatima->id)
            ->first();

        $this->assertNotNull($live, 'the guardian edge vanished instead of being re-opened');

        $this->assertFalse(
            $live->isConfirmed(),
            'a merge between two address-less adults carried a staff confirmation onto a different human',
        );
        $this->assertNull($live->confirmed_by_user_id, 'the confirming staff member followed the row');
        $this->assertNull($live->consent_scope, 'media consent about one child followed the row');
        $this->assertNull($live->consent_granted_at);

        // ---------------------------------------------------------------
        // AND IT IS SAID OUT LOUD. `unconfirmed: 0` was the whole defect.
        // ---------------------------------------------------------------
        $this->assertGreaterThan(
            0,
            (int) $merge->json('roster.unconfirmed'),
            'the merge reported nothing at all about re-opening a confirmed guardianship',
        );
        $this->assertNotEmpty(
            (array) $merge->json('roster.guardian_claims'),
            'the operator was told nothing about which guardianship changed hands',
        );

        // ---------------------------------------------------------------
        // THE DOOR AT THE FAR END OF THE CHAIN IS SHUT.
        // ---------------------------------------------------------------
        $panel = $this->getJson($this->adminUrl("/contacts/{$phantom->id}/family-login"))
            ->assertStatus(200);

        $this->assertFalse(
            (bool) $panel->json('data.eligible'),
            'a stranger-planted row was advertised as ready for a parent portal sign-in',
        );

        $this->postJson($this->adminUrl("/contacts/{$phantom->id}/family-login"), [
            'login_email' => 'bilal@evil.test',
        ])->assertStatus(422);

        // No credential, so nothing to read with. The four reads that answered
        // 200 in the transcript above are unreachable.
        $this->assertNull($phantom->fresh()->login_enabled_at);

        // The real child's records still exist — a de-duplication must not be
        // the thing that destroys them either.
        $this->assertSame(1, $fatimasRow->behaviorAwards()->count());
        $this->assertSame(1, $fatimasRow->hifzEntries()->count());
    }

    #[Test]
    public function the_real_parent_is_still_reachable_in_one_click_after_the_merge_re_opens_her_edge(): void
    {
        // The other direction of the same guard: what it REFUSES has to be
        // recoverable by the office that did the merge, on the screen they did
        // it on. A row re-opened as a claim is one Confirm press from working.
        [$fatima, , $realAisha] = $this->seedTheRealFamily();

        $phantom = $this->plantAnAddressLessPhantomNamed('Aisha', 'Ahmed');

        Sanctum::actingAs($this->admin);

        $this->postJson($this->adminUrl("/contacts/{$realAisha->id}/merge"), [
            'target_contact_id' => $phantom->id,
        ])->assertStatus(200);

        $claim = GroupMembership::withoutMasjidScope()
            ->where('contact_id', $phantom->id)
            ->where('role', GroupMembership::ROLE_GUARDIAN)
            ->where('guardian_of_contact_id', $fatima->id)
            ->firstOrFail();

        $this->postJson(
            $this->adminUrl("/groups/{$this->grade3->id}/members/confirm"),
            $this->confirmBody([$claim->id]),
        )->assertStatus(200)->assertJsonPath('data.confirmed', 1);

        $this->assertTrue($claim->fresh()->isConfirmed());
    }

    #[Test]
    public function two_adults_on_one_real_address_still_keep_their_confirmation(): void
    {
        // THE LEGITIMATE ACT THIS GUARD MUST NOT REFUSE. Two CRM rows a staff
        // member typed for one parent, both carrying her real address, are the
        // case the same-address exemption exists for: a credential opened on
        // either reaches the same mailbox, so nothing a read path can see has
        // changed. This must keep working, or the guard has traded a laundering
        // hole for a school re-confirming its whole roster after every tidy-up.
        [$fatima, , , $edge] = $this->seedTheRealFamily();

        $first = Contact::factory()->create([
            'masjid_id' => $this->masjid->id,
            'first_name' => 'Khadija',
            'last_name' => 'Ahmed',
            'email' => 'khadija@household.test',
        ]);

        $second = Contact::factory()->create([
            'masjid_id' => $this->masjid->id,
            'first_name' => 'Khadija',
            'last_name' => 'Ahmed',
            'email' => 'KHADIJA@Household.test',
        ]);

        $hers = new GroupMembership([
            'masjid_id' => $this->masjid->id,
            'group_id' => $this->grade3->id,
            'contact_id' => $first->id,
            'role' => GroupMembership::ROLE_GUARDIAN,
            'guardian_of_contact_id' => $fatima->id,
            'consent_scope' => GroupMembership::CONSENT_MEDIA,
            'consent_granted_at' => now(),
        ]);
        $hers->confirmedByStaff($this->admin)->save();

        Sanctum::actingAs($this->admin);

        $this->postJson($this->adminUrl("/contacts/{$first->id}/merge"), [
            'target_contact_id' => $second->id,
        ])->assertStatus(200)->assertJsonPath('roster.unconfirmed', 0);

        $after = GroupMembership::withoutMasjidScope()->whereKey($hers->id)->firstOrFail();

        $this->assertSame((int) $second->id, (int) $after->contact_id);
        $this->assertTrue($after->isConfirmed(), 'a same-address tidy-up wrongly re-opened a confirmed edge');
        $this->assertSame(GroupMembership::CONSENT_MEDIA, $after->consent_scope);

        // And the unrelated edge is untouched.
        $this->assertTrue(GroupMembership::withoutMasjidScope()->whereKey($edge->id)->firstOrFail()->isConfirmed());
    }

    #[Test]
    public function a_teacher_with_no_address_on_file_is_not_locked_out_of_her_own_classroom(): void
    {
        // THE OTHER LEGITIMATE ACT. A participant row is the person's own place
        // in a group; re-opening one 403s a teacher out of her classroom. The
        // rule "an absent value is never an identity match" makes an
        // address-less merge a CHANGE of identity, which re-opens participant
        // rows too — so this measures what that costs and proves it is a lost
        // click rather than a lost classroom.
        $teacher = Contact::factory()->create([
            'masjid_id' => $this->masjid->id,
            'first_name' => 'Amina',
            'last_name' => 'Teacher',
            'email' => null,
            'phone' => '+15550001111',
        ]);

        $leaderRow = new GroupMembership([
            'masjid_id' => $this->masjid->id,
            'group_id' => $this->grade3->id,
            'contact_id' => $teacher->id,
            'role' => GroupMembership::ROLE_LEADER,
        ]);
        $leaderRow->confirmedByStaff($this->admin)->save();

        $phantom = $this->plantAnAddressLessPhantomNamed('Amina', 'Teacher');

        Sanctum::actingAs($this->admin);

        $merge = $this->postJson($this->adminUrl("/contacts/{$teacher->id}/merge"), [
            'target_contact_id' => $phantom->id,
        ])->assertStatus(200);

        $moved = GroupMembership::withoutMasjidScope()->whereKey($leaderRow->id)->firstOrFail();

        $this->assertFalse($moved->isConfirmed());
        $this->assertSame(1, (int) $merge->json('roster.participant_rows_reopened'));

        // The sentence must be true OF A LEADER ROW: no ward, no guardianship.
        $this->assertStringNotContainsString(
            'guardian',
            strtolower((string) $merge->json('roster.message')),
        );

        // One press, and she is back.
        $this->postJson(
            $this->adminUrl("/groups/{$this->grade3->id}/members/confirm"),
            $this->confirmBody([$moved->id]),
        )->assertStatus(200)->assertJsonPath('data.confirmed', 1);

        $this->assertTrue($moved->fresh()->isConfirmed());
    }

    // ============================================================== helpers

    /**
     * The real family, as a school that imports parents by phone actually holds
     * it: a child with no address (children have none by construction), a
     * mother with no address (the import had only her phone), a staff-confirmed
     * guardian edge between them carrying media consent, and the records that
     * edge opens.
     *
     * @return array{0: Contact, 1: GroupMembership, 2: Contact, 3: GroupMembership}
     */
    private function seedTheRealFamily(): array
    {
        $fatima = Contact::factory()->create([
            'masjid_id' => $this->masjid->id,
            'first_name' => 'Fatima',
            'last_name' => 'Ahmed',
            'email' => null,
        ]);

        $fatimasRow = new GroupMembership([
            'masjid_id' => $this->masjid->id,
            'group_id' => $this->grade3->id,
            'contact_id' => $fatima->id,
            'role' => GroupMembership::ROLE_MEMBER,
        ]);
        $fatimasRow->confirmedByStaff($this->admin)->save();

        BehaviorAward::factory()->create([
            'masjid_id' => $this->masjid->id,
            'group_id' => $this->grade3->id,
            'group_membership_id' => $fatimasRow->id,
            'skill_label' => 'Left the classroom without permission',
            'skill_polarity' => BehaviorSkill::POLARITY_NEGATIVE,
            'points' => -3,
        ]);

        HifzEntry::factory()->create([
            'masjid_id' => $this->masjid->id,
            'group_id' => $this->grade3->id,
            'group_membership_id' => $fatimasRow->id,
        ]);

        GroupThread::factory()->create([
            'masjid_id' => $this->masjid->id,
            'group_id' => $this->grade3->id,
            'scope' => GroupThread::SCOPE_PARTICIPANT,
            'about_membership_id' => $fatimasRow->id,
            'subject' => 'Safeguarding: incident on 3 Sept',
        ]);

        $realAisha = Contact::factory()->create([
            'masjid_id' => $this->masjid->id,
            'first_name' => 'Aisha',
            'last_name' => 'Ahmed',
            'email' => null,
            'phone' => '+15559990000',
        ]);

        $edge = new GroupMembership([
            'masjid_id' => $this->masjid->id,
            'group_id' => $this->grade3->id,
            'contact_id' => $realAisha->id,
            'role' => GroupMembership::ROLE_GUARDIAN,
            'guardian_of_contact_id' => $fatima->id,
            'consent_scope' => GroupMembership::CONSENT_MEDIA,
            'consent_granted_at' => now(),
        ]);
        $edge->confirmedByStaff($this->admin)->save();

        return [$fatima, $fatimasRow, $realAisha, $edge];
    }

    /**
     * A stranger with no account, no token and no session puts a second contact
     * of a given name into the directory with NO ADDRESS AT ALL.
     *
     * `registrants.*.email` is `nullable`, so this needs nothing but a public
     * offering slug and a name — and the name is on any class list.
     */
    private function plantAnAddressLessPhantomNamed(string $first, string $last): Contact
    {
        [$offering, $plan] = $this->makeOffering($this->grade3, 'after-school-' . strtolower($first));

        $this->anonymously();

        $this->postJson("/api/v1/offerings/{$offering->slug}/register", [
            'fee_plan_id' => $plan->id,
            'payer' => ['name' => 'Bilal Attacker', 'email' => 'bilal@evil.test'],
            'registrants' => [['name' => "{$first} {$last}"]],
            'data' => ['full_name' => "{$first} {$last}"],
        ], ['masjid-id' => (string) $this->masjid->id])->assertStatus(200);

        $this->anonymously();

        $phantom = Contact::withoutMasjidScope()
            ->where('first_name', $first)
            ->where('last_name', $last)
            ->whereNull('email')
            ->orderByDesc('id')
            ->firstOrFail();

        return $phantom;
    }

    private function adminUrl(string $path = ''): string
    {
        return "/api/admin/masjids/{$this->masjid->id}" . $path;
    }

    /** Drop every principal and the bound tenant — a stranger with no account. */
    private function anonymously(): void
    {
        Auth::forgetGuards();
        app(TenantContext::class)->forgetTenant();
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
}
