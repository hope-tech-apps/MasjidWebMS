<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\Masjid;
use App\Services\Family\FamilyAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Family sign-in may be enabled for a GUARDIAN and for nobody else.
 *
 * ## Why this is not a validation nicety
 *
 * `App\Support\GroupAudience` wrote the guard condition down before the invite
 * flow existed, and it is worth quoting because it is the whole test:
 *
 *   "ON A CHILD'S CONTACT ROW IS A STUDENT LOGIN. standingIn() sets feed = true
 *    outright for any participant, so that child would read the whole class feed
 *    — every classmate's photograph, with nobody's consent — plus the
 *    participant threads about themselves, which are where a teacher and a
 *    guardian discuss a safeguarding concern. […] When it is [built], it must
 *    issue invites for GUARDIAN EDGES only."
 *
 * The first version of the invite flow had no such check. Measured against it,
 * enabling a nine-year-old's own contact row and signing in as them returned
 * 200 for the class feed, 200 for an attachment's BYTES, and 200 for the
 * participant thread about that child. The admin screen said, on that very row,
 * that the login would only see "the children they are listed as a guardian
 * for".
 *
 * A student login is a DIFFERENT standing computation — own record only, no
 * group feed, no participant threads — and belongs to its own task.
 *
 * ## What this file stopped asserting, and why
 *
 * It used to end "this refusal is the only thing standing between a roster and
 * a child credential", and a previous round widened the refusal to cover any
 * contact holding a participant edge ANYWHERE. That is gone. It refused the two
 * commonest adults in a school — a parent enrolled in the adult ḥalaqa, a
 * teacher who is also a parent — and the `GroupMembership::created` hook that
 * enforced it after the fact destroyed credentials they already held, on an
 * ordinary 201, reachable by an anonymous POST to the public registration
 * endpoint.
 *
 * The guarantee did not weaken, it MOVED: `GroupAudience::membershipsFor()`
 * narrows a family principal to their guardian edges, so a credential reads
 * wards and nothing else — the nine-year-old measured above is still refused
 * one (they are nobody's guardian, the condition below that never moved), and
 * a dual-role holder now gets one that opens their ward's records and nothing
 * about themselves. Controlling what a credential READS turned out to be the
 * property; controlling who may hold one was a proxy for it that refused real
 * people and still had to be patched from behind.
 */
class GuardianOnlyLoginTest extends TestCase
{
    use RefreshDatabase;

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
    }

    #[Test]
    public function a_student_cannot_be_given_a_family_login(): void
    {
        [$masjid, $group] = $this->makeClassroom();

        $child = $this->makeContact($masjid, 'Amina', 'Yusuf');

        // On the roster as a participant — exactly how a school's students are
        // recorded, and exactly the row a registrar would work down.
        GroupMembership::create([
            'masjid_id' => $masjid->id,
            'group_id' => $group->id,
            'contact_id' => $child->id,
            'role' => GroupMembership::ROLE_MEMBER,
            'joined_at' => now(),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/guardian/i');

        app(FamilyAccessService::class)->enable($child, 'amina.the.child@example.test');
    }

    #[Test]
    public function a_contact_on_no_roster_at_all_cannot_be_given_a_family_login(): void
    {
        [$masjid] = $this->makeClassroom();

        // A donor, a volunteer, an imported CRM row — none of them is anybody's
        // guardian, and none of them should hold a credential into a child's
        // records.
        $bystander = $this->makeContact($masjid, 'Unrelated', 'Person');

        $this->expectException(RuntimeException::class);

        app(FamilyAccessService::class)->enable($bystander, 'bystander@example.test');
    }

    #[Test]
    public function a_guardian_can_be_given_a_family_login(): void
    {
        [$masjid, $group] = $this->makeClassroom();

        $child = $this->makeContact($masjid, 'Amina', 'Yusuf');
        $parent = $this->makeContact($masjid, 'Yusuf', 'Ibrahim');

        GroupMembership::create([
            'masjid_id' => $masjid->id,
            'group_id' => $group->id,
            'contact_id' => $child->id,
            'role' => GroupMembership::ROLE_MEMBER,
            'joined_at' => now(),
        ]);

        GroupMembership::create([
            'masjid_id' => $masjid->id,
            'group_id' => $group->id,
            'contact_id' => $parent->id,
            'role' => GroupMembership::ROLE_GUARDIAN,
            'guardian_of_contact_id' => $child->id,
            'joined_at' => now(),
        ]);

        $updated = app(FamilyAccessService::class)->enable($parent, 'Yusuf.Parent@Example.test');

        $this->assertNotNull($updated->login_enabled_at, 'a real guardian was refused a login');
        // Normalised on write — the lookup compares LOWER(login_email) and
        // production MySQL is case-sensitive, so an un-normalised value is how
        // two rows match and a parent is locked out forever.
        $this->assertSame('yusuf.parent@example.test', $updated->login_email);
    }

    /**
     * THE DUAL-ROLE CASE — HELD A GUARDIAN EDGE **AND** A PARTICIPANT EDGE.
     *
     * Tariq is sixteen. He is a `member` of the Teens Ḥalaqa — classmates on the
     * roster, a published feed, and a participant thread about him where a
     * teacher and his guardian discuss a safeguarding concern. He is also
     * recorded as a `guardian` of his seven-year-old sister on the Kids Class
     * roster, because a school records who may collect a child.
     *
     * Measured before ANY of this: `enable()` succeeded, and with the resulting
     * token the teens feed answered 200 and so did the safeguarding thread about
     * him.
     *
     * A previous round answered that by REFUSING HIM A CREDENTIAL. That refusal
     * is gone, and this test now pins what replaced it, because the refusal was
     * aimed at the wrong noun: it decided WHO MAY HOLD a credential in order to
     * control WHAT A CREDENTIAL MAY READ, and the two come apart on the most
     * ordinary adults a school has — a parent enrolled in the adult ḥalaqa, a
     * teacher who is also a parent. Both were refused outright, and the
     * `GroupMembership::created` hook that enforced the same rule after the fact
     * DESTROYED credentials those adults already held, on an ordinary 201.
     *
     * `GroupAudience::membershipsFor()` narrows a family principal to their
     * guardian edges, so Tariq may hold a credential and it reads his sister's
     * records and NOTHING about himself. `a_dual_role_credential_reads_the_ward_and_not_the_holder`
     * in tests/Feature/GuardianEdgeProvenanceTest.php walks the same shape
     * end-to-end over HTTP; this one pins the enable-time verdict.
     */
    #[Test]
    public function a_student_who_is_also_a_siblings_guardian_may_hold_a_login_scoped_to_their_ward(): void
    {
        [$masjid, $teens] = $this->makeClassroom();
        $kids = $this->makeGroupIn($masjid);

        $tariq = $this->makeContact($masjid, 'Tariq', 'Rahman');
        $sister = $this->makeContact($masjid, 'Salma', 'Rahman');

        // A student on a class roster.
        GroupMembership::create([
            'masjid_id' => $masjid->id,
            'group_id' => $teens->id,
            'contact_id' => $tariq->id,
            'role' => GroupMembership::ROLE_MEMBER,
            'joined_at' => now(),
        ]);

        // …and an ordinary pickup-authorisation row on his sister's class.
        GroupMembership::create([
            'masjid_id' => $masjid->id,
            'group_id' => $kids->id,
            'contact_id' => $sister->id,
            'role' => GroupMembership::ROLE_MEMBER,
            'joined_at' => now(),
        ]);
        GroupMembership::create([
            'masjid_id' => $masjid->id,
            'group_id' => $kids->id,
            'contact_id' => $tariq->id,
            'role' => GroupMembership::ROLE_GUARDIAN,
            'guardian_of_contact_id' => $sister->id,
            'joined_at' => now(),
        ]);

        $service = app(FamilyAccessService::class);

        $this->assertNull(
            $service->ineligibilityReason($tariq),
            'a guardian was refused a credential for also being on a roster'
        );

        $updated = $service->enable($tariq, 'tariq@example.test');
        $this->assertNotNull($updated->login_enabled_at);

        // AND THE READ SCOPE IS WHAT MAKES THAT SAFE. His own participant row in
        // the Teens Ḥalaqa buys the credential no standing there at all, so the
        // feed and the safeguarding thread about him — the two things measured
        // at 200 — are refused at the source rather than at the door.
        app(\App\Support\TenantContext::class)->set((int) $masjid->id);

        $audience = app(\App\Support\GroupAudience::class);
        $principal = $updated->refresh();

        $this->assertTrue(
            $audience->membershipsFor($principal, $teens->refresh())->isEmpty(),
            'the credential still speaks through its holder\'s own participant row'
        );
        $this->assertFalse($audience->mayReceive(
            $principal, $teens->refresh(), \App\Support\GroupAudience::DISCLOSURE_FEED
        ));

        // …while the ward's class, which is what the credential was issued for,
        // still answers.
        $this->assertFalse(
            $audience->membershipsFor($principal, $kids->refresh())->isEmpty(),
            'the guardian edge stopped carrying the credential'
        );
    }

    /**
     * THE SAME, WITH A LEADER — and a leader's participant standing is the WIDER
     * one: `standingIn()` gives a leader every participant thread in the group,
     * every award and every ḥifẓ record for every child in it.
     *
     * A teacher who is also a parent is the second adult the blanket refusal hit,
     * and the `created` hook hit them harder: giving Ustadha Maryam a class ended
     * the parent-portal sign-in she already held, on a 201, with an audit row
     * naming nobody. She may hold one now, and it carries her own child and not
     * her classroom — which she reads through the STAFF surface and the identity
     * bridge, as she always did.
     */
    #[Test]
    public function a_group_leader_who_is_also_a_guardian_may_hold_a_login_scoped_to_their_ward(): void
    {
        [$masjid, $group] = $this->makeClassroom();

        $child = $this->makeContact($masjid, 'Amina', 'Yusuf');
        $teacherParent = $this->makeContact($masjid, 'Ustadha', 'Maryam');

        GroupMembership::create([
            'masjid_id' => $masjid->id,
            'group_id' => $group->id,
            'contact_id' => $child->id,
            'role' => GroupMembership::ROLE_MEMBER,
            'joined_at' => now(),
        ]);

        GroupMembership::create([
            'masjid_id' => $masjid->id,
            'group_id' => $group->id,
            'contact_id' => $teacherParent->id,
            'role' => GroupMembership::ROLE_GUARDIAN,
            'guardian_of_contact_id' => $child->id,
            'joined_at' => now(),
        ]);

        // She teaches the class as well.
        GroupMembership::create([
            'masjid_id' => $masjid->id,
            'group_id' => $group->id,
            'contact_id' => $teacherParent->id,
            'role' => GroupMembership::ROLE_LEADER,
            'joined_at' => now(),
        ]);

        $service = app(FamilyAccessService::class);

        $this->assertNull($service->ineligibilityReason($teacherParent));
        $this->assertNotNull(
            $service->enable($teacherParent, 'maryam@example.test')->login_enabled_at
        );

        // Her LEADER row buys the credential nothing: the group carries her
        // guardian edge over Amina and that is all it speaks through, so she has
        // no leader standing through the parent portal.
        app(\App\Support\TenantContext::class)->set((int) $masjid->id);

        $rows = app(\App\Support\GroupAudience::class)
            ->membershipsFor($teacherParent->refresh(), $group->refresh());

        $this->assertCount(1, $rows);
        $this->assertSame(GroupMembership::ROLE_GUARDIAN, $rows->first()->role);
        $this->assertSame($child->id, (int) $rows->first()->guardian_of_contact_id);
    }

    /**
     * A GUARDIAN OF A DELETED WARD IS NOBODY'S GUARDIAN.
     *
     * `contacts` soft-delete, so nothing cascades when a member is removed from
     * the directory and the edge row survives intact. Measured: create the edge,
     * delete the child, and `enable()` still wrote a FRESH grant, dated today,
     * to somebody who is nobody's guardian in the directory the office is
     * looking at.
     *
     * A trashed ward also has no readable records — `identitiesFor()` and every
     * listing query run through the SoftDeletes scope — so the credential this
     * used to mint opened nothing at all.
     */
    #[Test]
    public function a_guardian_whose_only_ward_has_been_deleted_cannot_be_given_a_family_login(): void
    {
        [$masjid, $group] = $this->makeClassroom();

        $child = $this->makeContact($masjid, 'Amina', 'Yusuf');
        $parent = $this->makeContact($masjid, 'Yusuf', 'Ibrahim');

        GroupMembership::create([
            'masjid_id' => $masjid->id,
            'group_id' => $group->id,
            'contact_id' => $child->id,
            'role' => GroupMembership::ROLE_MEMBER,
            'joined_at' => now(),
        ]);

        GroupMembership::create([
            'masjid_id' => $masjid->id,
            'group_id' => $group->id,
            'contact_id' => $parent->id,
            'role' => GroupMembership::ROLE_GUARDIAN,
            'guardian_of_contact_id' => $child->id,
            'joined_at' => now(),
        ]);

        $child->delete();   // soft — the edge row is untouched

        $this->expectException(RuntimeException::class);

        app(FamilyAccessService::class)->enable($parent, 'yusuf@example.test');
    }

    /**
     * A guardian edge naming nobody is not a guardian edge.
     *
     * `StoreGroupMembershipRequest` already refuses to CREATE one ("a guardian
     * of nobody"), so this asserts the two ends agree rather than trusting that
     * they do — a row planted by an import or a migration must not open a door
     * the request layer would have refused.
     */
    #[Test]
    public function a_guardian_role_naming_no_ward_is_not_a_guardian_edge(): void
    {
        [$masjid, $group] = $this->makeClassroom();

        $orphanEdge = $this->makeContact($masjid, 'Guardian', 'OfNobody');

        GroupMembership::withoutEvents(function () use ($masjid, $group, $orphanEdge) {
            GroupMembership::create([
                'masjid_id' => $masjid->id,
                'group_id' => $group->id,
                'contact_id' => $orphanEdge->id,
                'role' => GroupMembership::ROLE_GUARDIAN,
                'guardian_of_contact_id' => null,
                'joined_at' => now(),
            ]);
        });

        $this->expectException(RuntimeException::class);

        app(FamilyAccessService::class)->enable($orphanEdge, 'nobody@example.test');
    }

    /**
     * The edge must be in the contact's OWN organisation.
     *
     * Guardianship at one school says nothing about standing at another, and the
     * check must not depend on the caller having bound the tenant correctly —
     * this decides whether a child gets a credential.
     */
    #[Test]
    public function a_guardian_edge_in_another_organisation_does_not_count(): void
    {
        [$masjidA, $groupA] = $this->makeClassroom();
        [$masjidB] = $this->makeClassroom();

        $childA = $this->makeContact($masjidA, 'Child', 'AtA');
        $parentAtB = $this->makeContact($masjidB, 'Parent', 'AtB');

        // A guardian edge that names masjid A's group but a masjid B contact.
        GroupMembership::withoutEvents(function () use ($masjidA, $groupA, $parentAtB, $childA) {
            GroupMembership::create([
                'masjid_id' => $masjidA->id,
                'group_id' => $groupA->id,
                'contact_id' => $parentAtB->id,
                'role' => GroupMembership::ROLE_GUARDIAN,
                'guardian_of_contact_id' => $childA->id,
                'joined_at' => now(),
            ]);
        });

        $this->expectException(RuntimeException::class);

        app(FamilyAccessService::class)->enable($parentAtB, 'cross@example.test');
    }

    // ----------------------------------------------------------------- helpers

    /** @return array{0: Masjid, 1: Group} */
    private function makeClassroom(): array
    {
        $masjid = Masjid::create([
            'name' => 'School '.uniqid(),
            'email' => 'school-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'crm_enabled' => true,
            'org_type' => Masjid::ORG_TYPE_SCHOOL,
        ]);

        return [$masjid, $this->makeGroupIn($masjid)];
    }

    private function makeGroupIn(Masjid $masjid): Group
    {
        $name = 'Class '.uniqid();

        return Group::create([
            'masjid_id' => $masjid->id,
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name),
            'kind' => 'class',
        ]);
    }

    private function makeContact(Masjid $masjid, string $first, string $last): Contact
    {
        return Contact::create([
            'masjid_id' => $masjid->id,
            'first_name' => $first,
            'last_name' => $last,
            'email' => strtolower($first).'.'.uniqid().'@test.local',
        ]);
    }
}
