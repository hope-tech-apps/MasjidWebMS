<?php

namespace Tests\Support;

use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/**
 * A school with an office administrator and classes to put students in, for
 * the suites about moving a student between classes.
 *
 * Every contact is created with `email => null`: App\Support\GroupAudience
 * finds a caller's person by matching the login email to exactly one contact,
 * and a chance collision from the factory's non-unique address would flip an
 * authorization assertion (tests/CLAUDE.md).
 */
trait BuildsSchoolRosters
{
    protected Masjid $school;

    protected User $admin;

    protected function makeSchool(string $orgType = 'school'): Masjid
    {
        return Masjid::create([
            'name' => 'Cedar Test School '.uniqid(),
            'email' => 'school-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0,
            'crm_enabled' => true, 'org_type' => $orgType,
        ]);
    }

    protected function makeAdmin(Masjid $school, string $type = 'MasjidAdmin', string $role = 'masjid-admin'): User
    {
        $user = User::factory()->create([
            'type' => $type,
            'phone' => '+1'.random_int(1000000000, 9999999999),
        ]);

        MasjidUser::create([
            'masjid_id' => $school->id, 'user_id' => $user->id,
            'role' => $role, 'is_default' => true,
        ]);

        return $user;
    }

    protected function makeClass(string $name, array $with = [], ?Masjid $school = null): Group
    {
        return Group::factory()->create(array_merge([
            'masjid_id' => ($school ?? $this->school)->id,
            'kind' => Group::KIND_CLASS,
            'name' => $name,
            'is_active' => true,
        ], $with));
    }

    protected function makePerson(string $first, string $last, ?Masjid $school = null): Contact
    {
        return Contact::factory()->create([
            'masjid_id' => ($school ?? $this->school)->id,
            'first_name' => $first, 'last_name' => $last, 'email' => null,
        ]);
    }

    /** A confirmed student row. `$joined` defaults to a day well before any move. */
    protected function enrol(Group $class, Contact|string $who, string $joined = '2026-09-01', bool $confirmed = true): GroupMembership
    {
        $child = $who instanceof Contact ? $who : $this->makePerson($who, 'Student');

        $row = new GroupMembership([
            'masjid_id' => $class->masjid_id,
            'group_id' => $class->id,
            'contact_id' => $child->id,
            'role' => GroupMembership::ROLE_MEMBER,
            'joined_at' => $joined,
        ]);

        $confirmed ? $row->confirmedByStaff($this->admin) : $row->selfAssertedFrom(null);
        $row->save();

        return $row->fresh();
    }

    /** A guardian entry beside a student row. Confirmed unless said otherwise; consent only when a scope is given. */
    protected function guardian(GroupMembership $student, Contact|string $who, bool $confirmed = true, ?string $consent = null): GroupMembership
    {
        $adult = $who instanceof Contact ? $who : $this->makePerson($who, 'Guardian');

        $entry = new GroupMembership([
            'masjid_id' => $student->masjid_id,
            'group_id' => $student->group_id,
            'contact_id' => $adult->id,
            'role' => GroupMembership::ROLE_GUARDIAN,
            'guardian_of_contact_id' => $student->contact_id,
            'joined_at' => $student->joined_at,
        ]);

        $confirmed ? $entry->confirmedByStaff($this->admin) : $entry->selfAssertedFrom(null);

        if ($consent !== null) {
            $entry->forceFill(['consent_scope' => $consent, 'consent_granted_at' => '2026-09-04 10:00:00']);
        }

        $entry->save();

        return $entry->fresh();
    }

    protected function moveUrl(GroupMembership $row): string
    {
        return "/api/admin/masjids/{$row->masjid_id}/groups/{$row->group_id}/members/{$row->id}/move";
    }

    protected function previewMove(GroupMembership $row, Group|int $to, ?string $on = null, ?User $as = null): TestResponse
    {
        Sanctum::actingAs($as ?? $this->admin);

        $query = http_build_query(array_filter([
            'to_group_id' => $to instanceof Group ? $to->id : $to,
            'moved_on' => $on,
        ], fn ($v): bool => $v !== null));

        return $this->getJson($this->moveUrl($row).'?'.$query);
    }

    protected function move(GroupMembership $row, Group|int $to, ?string $on = null, array $body = [], ?User $as = null): TestResponse
    {
        Sanctum::actingAs($as ?? $this->admin);

        return $this->postJson($this->moveUrl($row), array_filter([
            'to_group_id' => $to instanceof Group ? $to->id : $to,
            'moved_on' => $on,
        ], fn ($v): bool => $v !== null) + $body);
    }

    protected function roster(Group $class): TestResponse
    {
        Sanctum::actingAs($this->admin);

        return $this->getJson("/api/admin/masjids/{$class->masjid_id}/groups/{$class->id}/members");
    }

    protected function removeFromRoster(GroupMembership $row): TestResponse
    {
        Sanctum::actingAs($this->admin);

        return $this->deleteJson("/api/admin/masjids/{$row->masjid_id}/groups/{$row->group_id}/members/{$row->id}");
    }

    /**
     * R3, as a photograph: every roster row and every consent column, before a
     * move. `assertNothingWasDestroyed` compares it with what is there after.
     *
     * @return array<int, array{0: mixed, 1: mixed}>
     */
    protected function rosterSnapshot(): array
    {
        return DB::table('group_memberships')->get(['id', 'consent_granted_at', 'consent_scope'])
            ->mapWithKeys(fn ($r): array => [(int) $r->id => [$r->consent_granted_at, $r->consent_scope]])
            ->all();
    }

    /** No roster row disappeared, and no consent column went from set to null. */
    protected function assertNothingWasDestroyed(array $before): void
    {
        $after = $this->rosterSnapshot();

        foreach ($before as $id => [$grantedAt, $scope]) {
            $this->assertArrayHasKey($id, $after, "a move deleted roster row {$id}");

            if ($grantedAt !== null) {
                $this->assertNotNull($after[$id][0], "a move cleared the consent date on roster row {$id}");
            }

            if ($scope !== null) {
                $this->assertNotNull($after[$id][1], "a move cleared the consent scope on roster row {$id}");
            }
        }
    }
}
