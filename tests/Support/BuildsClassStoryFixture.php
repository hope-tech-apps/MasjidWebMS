<?php

namespace Tests\Support;

use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupPost;
use App\Models\GroupStaff;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Sanctum;

/**
 * One school, one class, one teacher and two families — the fixture the class
 * story engagement suites (reactions, read receipts, the reaction digest) share,
 * so each pins the rule under test rather than re-deriving who is in the room.
 *
 * `$this->school` / `$this->otherSchool` are built by the using test's setUp
 * (`makeMasjid()` is called at two sites THERE, which is what
 * TenantScopingCoverageTest looks for in a file that claims a cross-tenant test).
 *
 * The parents are `parentA` (Huda Yusuf, child Amina) and `parentB` (Maryam
 * Karimi, child Zayd). Both have a LIVE family login. Neither has consented until
 * a test calls `consent()`.
 */
trait BuildsClassStoryFixture
{
    private Masjid $school;
    private Masjid $otherSchool;
    private User $teacher;
    private Group $class;

    private Contact $parentA;
    private GroupMembership $childA;
    private Contact $parentB;
    private GroupMembership $childB;

    protected function useSqliteInMemory(): void
    {
        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
    }

    private function makeMasjid(): Masjid
    {
        return Masjid::create([
            'name' => 'Story School '.uniqid(),
            'email' => 'school-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0,
            'crm_enabled' => true, 'org_type' => 'school',
        ]);
    }

    /** The teacher of $group in $masjid, as a staff login (group_staff). */
    private function makeTeacher(Masjid $masjid, Group $group, string $name, ?string $email = null): User
    {
        $teacher = User::factory()->create([
            'type' => 'Teacher',
            'name' => $name,
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'email' => $email ?? 'teacher-'.uniqid().'@test.local',
        ]);
        MasjidUser::create([
            'masjid_id' => $masjid->id, 'user_id' => $teacher->id,
            'role' => 'teacher', 'is_default' => true,
        ]);
        $group->staff()->attach($teacher->id, [
            'masjid_id' => $masjid->id,
            'role' => GroupStaff::ROLE_TEACHER,
            'assigned_at' => now(),
        ]);

        return $teacher;
    }

    /** Builds the school, the class, the teacher and both families. Call from setUp. */
    private function buildStoryWorld(): void
    {
        $this->school = $this->makeMasjid();
        $this->otherSchool = $this->makeMasjid();

        $this->class = Group::factory()->create([
            'masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS,
            'name' => 'Grade 1', 'slug' => 'grade-1',
        ]);
        $this->teacher = $this->makeTeacher($this->school, $this->class, 'Ustadh Bilal');

        [$this->parentA, $this->childA] = $this->makeFamily('Amina', 'Huda', 'Yusuf');
        [$this->parentB, $this->childB] = $this->makeFamily('Zayd', 'Maryam', 'Karimi');
    }

    /** @return array{0: Contact, 1: GroupMembership} the guardian and the CHILD's roster row */
    private function makeFamily(string $childName, string $parentFirst, string $parentLast, ?Group $class = null, bool $login = true): array
    {
        $class ??= $this->class;
        $masjidId = $class->masjid_id;

        $child = Contact::factory()->create([
            'masjid_id' => $masjidId, 'first_name' => $childName, 'last_name' => $parentLast, 'email' => null,
        ]);
        $membership = GroupMembership::create([
            'masjid_id' => $masjidId, 'group_id' => $class->id,
            'contact_id' => $child->id, 'role' => GroupMembership::ROLE_MEMBER,
        ]);

        $parent = Contact::factory()->create([
            'masjid_id' => $masjidId, 'first_name' => $parentFirst, 'last_name' => $parentLast,
        ]);

        if ($login) {
            $parent->forceFill([
                'login_email' => 'parent-'.uniqid().'@test.local',
                'login_enabled_at' => now(),
            ])->save();
        }

        GroupMembership::create([
            'masjid_id' => $masjidId, 'group_id' => $class->id,
            'contact_id' => $parent->id, 'role' => GroupMembership::ROLE_GUARDIAN,
            'guardian_of_contact_id' => $child->id,
        ]);

        return [$parent->refresh(), $membership];
    }

    /**
     * A SECOND child of an existing guardian in the same class: their own roster
     * row, and a second guardian edge for the same contact. Call `consent()` again
     * afterwards if the guardian has consented, since it stamps the edges that
     * exist at the time.
     *
     * @return array{0: GroupMembership, 1: GroupMembership} the child's roster row and the guardian edge
     */
    private function addSibling(Contact $parent, string $childName, ?Group $class = null): array
    {
        $class ??= $this->class;

        $child = Contact::factory()->create([
            'masjid_id' => $class->masjid_id, 'first_name' => $childName, 'last_name' => $parent->last_name, 'email' => null,
        ]);
        $membership = GroupMembership::create([
            'masjid_id' => $class->masjid_id, 'group_id' => $class->id,
            'contact_id' => $child->id, 'role' => GroupMembership::ROLE_MEMBER,
        ]);
        $edge = GroupMembership::create([
            'masjid_id' => $class->masjid_id, 'group_id' => $class->id,
            'contact_id' => $parent->id, 'role' => GroupMembership::ROLE_GUARDIAN,
            'guardian_of_contact_id' => $child->id,
        ]);

        return [$membership, $edge];
    }

    /** ONE guardian edge leaves the class (a parent's other child's edge stays). */
    private function guardianEdgeLeaves(GroupMembership $edge): void
    {
        GroupMembership::withoutMasjidScope()->whereKey($edge->id)->update(['left_on' => now()->toDateString()]);
    }

    /** Record feed (or media) consent on a guardian's edge in $class. */
    private function consent(Contact $parent, string $scope = GroupMembership::CONSENT_FEED, ?Group $class = null): void
    {
        GroupMembership::withoutMasjidScope()
            ->where('group_id', ($class ?? $this->class)->id)
            ->where('contact_id', $parent->id)
            ->where('role', GroupMembership::ROLE_GUARDIAN)
            ->update(['consent_granted_at' => now(), 'consent_scope' => $scope]);
    }

    private function withdrawConsent(Contact $parent): void
    {
        GroupMembership::withoutMasjidScope()
            ->where('group_id', $this->class->id)
            ->where('contact_id', $parent->id)
            ->where('role', GroupMembership::ROLE_GUARDIAN)
            ->update(['consent_granted_at' => null, 'consent_scope' => null]);
    }

    /** The guardian edge leaves the class on a stated day. */
    private function familyLeaves(Contact $parent): void
    {
        GroupMembership::withoutMasjidScope()
            ->where('group_id', $this->class->id)
            ->where('contact_id', $parent->id)
            ->where('role', GroupMembership::ROLE_GUARDIAN)
            ->update(['left_on' => now()->toDateString()]);
    }

    private function makePost(?User $author = null, string $body = 'We learned Surah Al-Fatiha today.', ?Group $class = null): GroupPost
    {
        $class ??= $this->class;

        return GroupPost::create([
            'masjid_id' => $class->masjid_id,
            'group_id' => $class->id,
            'author_user_id' => ($author ?? $this->teacher)->id,
            'title' => 'Our day',
            'body' => $body,
        ]);
    }

    private function asTeacher(?User $teacher = null): self
    {
        return $this->asUser($teacher ?? $this->teacher, ['staff']);
    }

    private function asUser(User $user, array $abilities = ['*']): self
    {
        Auth::forgetGuards();
        app(TenantContext::class)->forgetTenant();
        Sanctum::actingAs($user, $abilities);

        return $this->flushHeaders()->withHeader('Accept', 'application/json');
    }

    /** A real bearer token, so the family guard's own provider check runs. */
    private function asParent(Contact $parent): self
    {
        Auth::forgetGuards();
        app(TenantContext::class)->forgetTenant();

        return $this->flushHeaders()
            ->withHeader('Accept', 'application/json')
            ->withHeader('Authorization', 'Bearer '.$parent->createFamilyToken()->plainTextToken);
    }

    private function teacherUrl(string $path, ?Group $class = null): string
    {
        $class ??= $this->class;

        return "/api/teacher/masjids/{$class->masjid_id}/groups/{$class->id}".$path;
    }

    private function familyUrl(string $path, ?Group $class = null): string
    {
        $class ??= $this->class;

        return "/api/family/masjids/{$class->masjid_id}/groups/{$class->id}".$path;
    }

    private function adminUrl(string $path, ?Group $class = null): string
    {
        $class ??= $this->class;

        return "/api/admin/masjids/{$class->masjid_id}/groups/{$class->id}".$path;
    }

    private function makeAdmin(): User
    {
        $admin = User::factory()->create([
            'type' => 'MasjidAdmin',
            'name' => 'Office Admin',
            'phone' => '+1'.random_int(1000000000, 9999999999),
        ]);
        $this->school->user_id = $admin->id;
        $this->school->save();

        return $admin;
    }

    /** An office admin who ALSO leads the class (a Contact leader row), so they may read its feed. */
    private function makeLeadingAdmin(): User
    {
        $admin = $this->makeAdmin();
        $person = Contact::factory()->create(['masjid_id' => $this->school->id, 'email' => $admin->email]);
        GroupMembership::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id,
            'contact_id' => $person->id, 'role' => GroupMembership::ROLE_LEADER,
        ]);

        return $admin;
    }
}
