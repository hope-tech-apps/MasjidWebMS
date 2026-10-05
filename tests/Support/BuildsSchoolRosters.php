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

    protected function consentUrl(GroupMembership $entry): string
    {
        return "/api/admin/masjids/{$entry->masjid_id}/groups/{$entry->group_id}/members/{$entry->id}/consent";
    }

    /** The office records consent on a guardian entry, through its own verb. */
    protected function recordConsent(GroupMembership $entry, string $scope, ?string $grantedOn = null): TestResponse
    {
        Sanctum::actingAs($this->admin);

        return $this->putJson($this->consentUrl($entry), array_filter(['scope' => $scope, 'granted_at' => $grantedOn]));
    }

    /** The office withdraws the consent on a guardian entry, through its own verb. */
    protected function withdrawConsent(GroupMembership $entry): TestResponse
    {
        Sanctum::actingAs($this->admin);

        return $this->deleteJson($this->consentUrl($entry));
    }

    /** The one guardian entry a class holds for this adult and this child. */
    protected function entryIn(Group $class, GroupMembership $like): GroupMembership
    {
        return GroupMembership::query()
            ->where('group_id', $class->id)
            ->where('role', GroupMembership::ROLE_GUARDIAN)
            ->where('contact_id', $like->contact_id)
            ->where('guardian_of_contact_id', $like->guardian_of_contact_id)
            ->sole();
    }

    /**
     * R3, as a photograph: every roster row and every consent column, before a
     * move. `assertNothingWasDestroyed` compares it with what is there after.
     *
     * The third value says whether the row HAD consent that granted something
     * (a confirmed guardian entry, a date, a known scope): what
     * `GroupMembership::hasConsent()` answers, read from the stored columns.
     *
     * @return array<int, array{0: mixed, 1: mixed, 2: bool}>
     */
    protected function rosterSnapshot(): array
    {
        return DB::table('group_memberships')->get(['id', 'consent_granted_at', 'consent_scope', 'provenance', 'role'])
            ->mapWithKeys(fn ($r): array => [(int) $r->id => [
                $r->consent_granted_at,
                $r->consent_scope,
                $r->role === GroupMembership::ROLE_GUARDIAN
                    && $r->provenance === GroupMembership::PROVENANCE_CONFIRMED
                    && $r->consent_granted_at !== null
                    && in_array($r->consent_scope, GroupMembership::CONSENT_SCOPES, true),
            ]])
            ->all();
    }

    /**
     * No roster row disappeared, and no consent column went from set to null.
     * Then the other direction: `assertNothingWasGrantedOnAnExistingRow`. Every
     * test of a move that succeeds ends with both.
     */
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

        $this->assertNothingWasGrantedOnAnExistingRow($before);
    }

    /**
     * A MOVE WRITES CONSENT ONLY ONTO AN ENTRY IT CREATED, AND ONLY AS IT WAS
     * RECORDED.
     *
     *   - No row that existed before has a consent column that went from null
     *     to set, or that changed.
     *   - Every row that did not exist before and holds a consent column
     *     carries the marker, and the class the marker names holds an entry for
     *     the same adult and child that existed before, had consent that
     *     granted something, and holds the SAME two raw values.
     */
    protected function assertNothingWasGrantedOnAnExistingRow(array $before): void
    {
        $rows = DB::table('group_memberships')->get()->keyBy('id');

        foreach ($rows as $id => $row) {
            if (isset($before[$id])) {
                [$grantedAt, $scope] = $before[$id];

                $this->assertTrue(
                    $row->consent_granted_at === null || $row->consent_granted_at === $grantedAt,
                    "a move wrote a consent date onto roster row {$id}, which existed before it",
                );
                $this->assertTrue(
                    $row->consent_scope === null || $row->consent_scope === $scope,
                    "a move wrote a consent scope onto roster row {$id}, which existed before it",
                );

                continue;
            }

            if ($row->consent_granted_at === null && $row->consent_scope === null) {
                continue;
            }

            $this->assertSame(GroupMembership::ROLE_GUARDIAN, $row->role, "roster row {$id} is not a guardian entry and holds consent");
            $this->assertSame(GroupMembership::PROVENANCE_CONFIRMED, $row->provenance, "roster row {$id} is unconfirmed and holds consent");
            $this->assertNotNull($row->consent_carried_from_group_id, "a new roster row {$id} holds consent and is not marked as carried");

            $source = $rows->first(fn ($r): bool => (int) $r->group_id === (int) $row->consent_carried_from_group_id
                && $r->role === GroupMembership::ROLE_GUARDIAN
                && (int) $r->contact_id === (int) $row->contact_id
                && (int) $r->guardian_of_contact_id === (int) $row->guardian_of_contact_id);

            $this->assertNotNull($source, "the consent on new roster row {$id} names a class that holds no entry for that adult and child");
            // A source that is itself new was carried in the same stretch (a
            // chain of two moves), and is held to this rule on its own turn.
            $this->assertTrue(
                $before[(int) $source->id][2] ?? $source->consent_carried_from_group_id !== null,
                "the consent on new roster row {$id} was copied from an entry that had none to give",
            );
            $this->assertSame(
                [$source->consent_granted_at, $source->consent_scope],
                [$row->consent_granted_at, $row->consent_scope],
                "the consent on new roster row {$id} is not the two values that were recorded",
            );
        }
    }
}
