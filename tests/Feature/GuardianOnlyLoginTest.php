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
 * group feed, no participant threads — and belongs to its own task. Until that
 * exists, this refusal is the only thing standing between a roster and a child
 * credential.
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

        $name = 'Class '.uniqid();

        $group = Group::create([
            'masjid_id' => $masjid->id,
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name),
            'kind' => 'class',
        ]);

        return [$masjid, $group];
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
