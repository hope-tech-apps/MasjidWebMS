<?php

namespace Tests\Feature;

use App\Exceptions\RosterMoveRefused;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupStaff;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use App\Support\AcademicRecordsHeld;
use App\Support\RosterMove;
use App\Support\RosterMovePlan;
use App\Support\SchoolSettings;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\Support\BuildsSchoolRosters;
use Tests\Support\LogsLikeProduction;
use Tests\Support\PlantsRosterRecords;
use Tests\TestCase;

/**
 * MOVING A STUDENT TO ANOTHER CLASS (App\Support\RosterMove).
 *
 * A roster row never changes class. The old place gets a leaving day and keeps
 * everything recorded on it; a new place opens in the new class, or the place
 * the student held there before opens again. The four rules this suite holds
 * the move to are in .claude/rules/groups.md, "Moving a student to another
 * class": records never change class, a move never widens anybody's access, a
 * move destroys nothing, locks first.
 *
 * Every test of a move that succeeds ends with `assertNothingWasDestroyed`:
 * every roster row that existed still exists, and no consent column went from
 * set to null. It also runs the other direction
 * (`assertNothingWasGrantedOnAnExistingRow`): a move writes consent only onto
 * an entry it created, as it was recorded, and marks it.
 *
 * Since 2026-10-05 consent is CARRIED AS IT IS (rules R8 and R10 in the class
 * docblock of RosterMove): the second half of this file is about what is
 * carried, what is not and why, and what a move must not bring back.
 *
 * "Today" is pinned to Sunday 4 October 2026 on the school's clock.
 */
class RosterMoveTest extends TestCase
{
    use BuildsSchoolRosters;
    use LogsLikeProduction;
    use PlantsRosterRecords;
    use RefreshDatabase;

    private const TODAY = '2026-10-04';

    private Group $first;

    private Group $second;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        // 11:00 on Sunday 4 October in New York, the clock a school with no
        // timezone of its own is read on.
        Carbon::setTestNow('2026-10-04 15:00:00');

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->school = $this->makeSchool();
        $this->admin = $this->makeAdmin($this->school);
        $this->school->user_id = $this->admin->id;
        $this->school->save();

        $this->first = $this->makeClass('1st Grade');
        $this->second = $this->makeClass('2nd Grade');
    }

    protected function tearDown(): void
    {
        $this->forgetProductionLogs();
        Carbon::setTestNow();
        // One test drops the marker column, and what was seen is remembered
        // for the life of the process.
        GroupMembership::forgetConsentCarryReady();

        parent::tearDown();
    }

    /** The sentences of a preview or an answer, as one string to search. */
    private function said(TestResponse $response): string
    {
        return implode(' ', $response->json('data.lines'));
    }

    /** The two consent columns of a roster row, as the database holds them. */
    private function rawConsent(int $id): array
    {
        return (array) DB::table('group_memberships')->where('id', $id)->first(['consent_granted_at', 'consent_scope']);
    }

    /** Move through the service itself, for what no request can send. */
    private function moveByService(GroupMembership $row, Group $to, array $options = [], ?string $on = null): RosterMovePlan
    {
        return app(RosterMove::class)->move($row->group, $row, $to->id, $on ?? self::TODAY, $options, $this->admin);
    }

    // ------------------------------------------------- left here, started there

    public static function everyKey(): array
    {
        return collect(array_keys(AcademicRecordsHeld::KEYS))->mapWithKeys(fn (string $t): array => [$t => [$t]])->all();
    }

    #[Test]
    #[DataProvider('everyKey')]
    public function a_record_on_any_of_the_eleven_keys_stays_with_the_old_class(string $table): void
    {
        $student = $this->enrol($this->first, 'Maryam');
        $record = $this->plantRecord($table, $student);
        $before = $this->rosterSnapshot();

        $answer = $this->move($student, $this->second, self::TODAY)->assertOk();

        $answer->assertJsonPath('data.path', RosterMovePlan::LEFT_AND_STARTED)
            ->assertJsonPath('data.old_membership_id', $student->id);

        // The record still names the old roster row and the old class.
        [$column] = AcademicRecordsHeld::KEYS[$table];
        $this->assertSame($student->id, (int) DB::table($table)->where('id', $record)->value($column));
        $this->assertSame($this->first->id, $this->classOfRecord($table, $record));

        // The old row never changed class. It has a leaving day and says where they went.
        $old = $student->fresh();
        $this->assertSame($this->first->id, (int) $old->group_id);
        $this->assertSame('2026-10-03', $old->left_on->toDateString());
        $this->assertSame($this->second->id, (int) $old->moved_to_group_id);
        $this->assertNull($old->moved_from_group_id);
        $this->assertSame(self::TODAY, $old->moved_on->toDateString());
        $this->assertSame($this->admin->id, (int) $old->moved_by_user_id);

        // A new row stands in the new class and says where they came from.
        $new = GroupMembership::findOrFail($answer->json('data.membership_id'));
        $this->assertNotSame($student->id, $new->id);
        $this->assertSame($this->second->id, (int) $new->group_id);
        $this->assertSame((int) $student->contact_id, (int) $new->contact_id);
        $this->assertNull($new->left_on);
        $this->assertSame(self::TODAY, $new->joined_at->toDateString());
        $this->assertSame($this->first->id, (int) $new->moved_from_group_id);
        $this->assertNull($new->moved_to_group_id);
        $this->assertSame(self::TODAY, $new->moved_on->toDateString());
        $this->assertSame($this->admin->id, (int) $new->moved_by_user_id);

        $this->assertNothingWasDestroyed($before);
    }

    #[Test]
    public function a_student_with_nothing_recorded_still_never_changes_class_and_the_old_entry_can_be_removed(): void
    {
        $student = $this->enrol($this->first, 'Maryam');
        $parent = $this->guardian($student, 'Huda');
        $before = $this->rosterSnapshot();

        $this->previewMove($student, $this->second, self::TODAY)->assertOk()
            ->assertJsonPath('data.can_move', true)
            ->assertJsonPath('data.old_entry_removable', true);

        $answer = $this->move($student, $this->second, self::TODAY)->assertOk()
            ->assertJsonPath('data.path', RosterMovePlan::LEFT_AND_STARTED)
            ->assertJsonPath('data.old_entry_removable', true);

        $this->assertStringContainsString('It holds nothing: remove it there', implode(' ', $answer->json('data.lines')));
        $this->assertSame($this->first->id, (int) $student->fresh()->group_id, 'a roster row changed class');
        $this->assertSame($this->first->id, (int) $parent->fresh()->group_id, 'a guardian entry changed class');
        $this->assertNotNull($parent->fresh()->left_on);
        $this->assertNothingWasDestroyed($before);

        // Remove is the existing verb, and it takes the empty old place.
        $this->removeFromRoster($student->fresh())->assertOk();
        $this->assertNull(GroupMembership::find($student->id));
        $this->assertSame(1, GroupMembership::where('contact_id', $student->contact_id)->count());
    }

    #[Test]
    public function a_record_deleted_on_its_own_screen_still_stays_and_the_old_entry_is_not_offered_for_removal(): void
    {
        $student = $this->enrol($this->first, 'Maryam');
        $this->plantRecord('behavior_awards', $student, ['deleted_at' => now()]);
        $before = $this->rosterSnapshot();

        $this->move($student, $this->second, self::TODAY)->assertOk()
            ->assertJsonPath('data.old_entry_removable', false)
            ->assertJsonPath('data.records_staying', ['behaviour points' => 1]);

        $this->assertNothingWasDestroyed($before);
    }

    #[Test]
    public function consent_is_carried_as_it_is_onto_the_entry_the_move_creates_and_stays_on_the_old_one(): void
    {
        $student = $this->enrol($this->first, 'Maryam');
        $parent = $this->guardian($student, 'Huda', consent: 'media');
        $before = $this->rosterSnapshot();

        $preview = $this->previewMove($student, $this->second, self::TODAY)->assertOk()
            ->assertJsonPath('data.consent_recorded_here', true)
            ->assertJsonPath('data.guardians.consent_carried', ['media' => 1, 'feed' => 0])
            ->assertJsonPath('data.guardians.consent_none_recorded', 0)
            ->assertJsonPath('data.guardians.consent_not_carried', [])
            ->assertJsonPath('data.expected_consent', 'm1f0s0n0e0')
            // Remove would take the guardian entry and its consent with it.
            ->assertJsonPath('data.old_entry_removable', false);

        $this->assertArrayNotHasKey('consent_to_record_again', $preview->json('data.guardians'));
        $this->assertContains(
            'Consent is carried as it is for 1 guardian, for the class story and photographs. Nobody is asked again.',
            $preview->json('data.lines'),
        );
        $this->assertContains(
            'From the move on they can open everything 2nd Grade has shared and still keeps, including what it shared '
                .'before Maryam Student joined: its class story, class-wide conversations and class files, and for '
                ."photograph consent its photographs and videos. They also start receiving 2nd Grade's story emails and "
                .'its weekly points email.',
            $preview->json('data.lines'),
        );
        $this->assertContains(
            'Consent recorded in 1st Grade stays on record there and is in force again if Maryam Student goes back. To '
                ."withdraw a family's consent completely, withdraw it in both classes.",
            $preview->json('data.lines'),
        );
        $this->assertStringNotContainsString('Tell 2nd Grade', $this->said($preview));

        $answer = $this->move($student, $this->second, self::TODAY, ['expected_consent' => 'm1f0s0n0e0'])->assertOk()
            ->assertJsonPath('data.consent_carried', ['media' => 1, 'feed' => 0])
            ->assertJsonPath('data.old_entry_removable', false);

        $this->assertArrayNotHasKey('consent_to_record_again', $answer->json('data'));
        $this->assertContains('Consent was carried as it is for 1 guardian, for the class story and photographs.', $answer->json('data.lines'));
        $this->assertStringContainsString('They can now open everything 2nd Grade has shared and still keeps', $this->said($answer));
        $this->assertContains(
            "Tell 2nd Grade's teacher: 1 more guardian now receives its class story, and 1 of them its photographs.",
            $answer->json('data.lines'),
        );
        $this->assertStringNotContainsString('asked again', $this->said($answer));

        // The old entry keeps what was recorded on it, and is not marked.
        $kept = $parent->fresh();
        $this->assertSame('media', $kept->consent_scope);
        $this->assertNotNull($kept->consent_granted_at);
        $this->assertNotNull($kept->left_on);
        $this->assertNull($kept->consent_carried_from_group_id);

        // The new entry holds the SAME two values, byte for byte: the scope
        // and the day the family gave it, not the day of the move. And it says
        // where they came from.
        $carried = $this->entryIn($this->second, $parent);
        $this->assertSame($this->rawConsent($parent->id), $this->rawConsent($carried->id));
        $this->assertSame('2026-09-04 10:00:00', $this->rawConsent($carried->id)['consent_granted_at']);
        $this->assertSame($this->first->id, (int) $carried->consent_carried_from_group_id);
        $this->assertTrue($carried->hasConsent());
        $this->assertNull($carried->left_on);

        // The student's own place is copied through the same door and never holds consent.
        $place = GroupMembership::findOrFail($answer->json('data.membership_id'));
        $this->assertSame(['consent_granted_at' => null, 'consent_scope' => null], $this->rawConsent($place->id));
        $this->assertNull($place->consent_carried_from_group_id);

        $this->assertNothingWasDestroyed($before);
    }

    #[Test]
    public function several_guardians_are_counted_by_scope_and_a_guardian_with_none_on_record_gets_nothing(): void
    {
        $student = $this->enrol($this->first, 'Maryam');
        $media = $this->guardian($student, 'Huda', consent: 'media');
        $feed = $this->guardian($student, 'Gamal', consent: 'feed');
        $none = $this->guardian($student, 'Nadia');
        $claim = $this->guardian($student, 'Stranger', confirmed: false);
        $before = $this->rosterSnapshot();

        $preview = $this->previewMove($student, $this->second, self::TODAY)->assertOk()
            ->assertJsonPath('data.guardians.consent_carried', ['media' => 1, 'feed' => 1])
            ->assertJsonPath('data.guardians.consent_none_recorded', 1)
            ->assertJsonPath('data.guardians.consent_none_but_receives', 0)
            ->assertJsonPath('data.expected_consent', 'm1f1s0n1e0');

        $this->assertContains(
            'Consent is carried as it is for 2 guardians: 1 for the class story and photographs, 1 for the class story '
                .'only. Nobody is asked again.',
            $preview->json('data.lines'),
        );
        $this->assertContains(
            '1 guardian has no consent on record in 1st Grade, so nothing is carried for them. They receive nothing from '
                ."2nd Grade's class story, class-wide conversations and class files until consent is recorded there. "
                .'Files and conversations about their own child still reach them.',
            $preview->json('data.lines'),
        );

        $answer = $this->move($student, $this->second, self::TODAY, ['expected_consent' => 'm1f1s0n1e0'])->assertOk();

        $this->assertContains(
            'Consent was carried as it is for 2 guardians: 1 for the class story and photographs, 1 for the class story only.',
            $answer->json('data.lines'),
        );
        $this->assertContains(
            "Tell 2nd Grade's teacher: 2 more guardians now receive its class story, and 1 of them its photographs.",
            $answer->json('data.lines'),
        );

        $this->assertSame('media', $this->entryIn($this->second, $media)->consent_scope);
        $this->assertSame('feed', $this->entryIn($this->second, $feed)->consent_scope);

        // A blank stays blank and unmarked: nothing is written for an adult who has none on record.
        foreach ([$none, $claim] as $entry) {
            $copy = $this->entryIn($this->second, $entry);
            $this->assertSame(['consent_granted_at' => null, 'consent_scope' => null], $this->rawConsent($copy->id));
            $this->assertNull($copy->consent_carried_from_group_id);
        }

        $this->assertFalse($this->entryIn($this->second, $claim)->isConfirmed());
        $this->assertNothingWasDestroyed($before);

        // Two guardians with one scope are said in the shorter form.
        $other = $this->enrol($this->first, 'Yusuf');
        $this->guardian($other, 'Samira', consent: 'feed');
        $this->guardian($other, 'Tariq', consent: 'feed');

        $this->assertContains(
            'Consent is carried as it is for 2 guardians, all for the class story. Nobody is asked again.',
            $this->previewMove($other, $this->second, self::TODAY)->assertOk()->json('data.lines'),
        );
        $this->assertContains(
            "Tell 2nd Grade's teacher: 2 more guardians now receive its class story.",
            $this->move($other, $this->second, self::TODAY)->assertOk()->json('data.lines'),
        );
    }

    #[Test]
    public function nobody_is_upgraded_and_a_confirmation_keeps_its_own_author_and_time(): void
    {
        $confirmer = $this->makeAdmin($this->school);
        $mover = $this->admin;

        $student = $this->enrol($this->first, 'Maryam', confirmed: false);
        $confirmed = $this->guardian($student, 'Huda');
        $confirmed->forceFill(['confirmed_by_user_id' => $confirmer->id, 'confirmed_at' => '2026-09-02 09:30:00'])->save();
        $claim = $this->guardian($student, 'Stranger', confirmed: false);
        $before = $this->rosterSnapshot();

        $this->move($student, $this->second, self::TODAY, as: $mover)->assertOk()
            ->assertJsonPath('data.student_unconfirmed', true)
            ->assertJsonPath('data.guardian_form_claims', 1)
            ->assertJsonPath('data.guardians_confirmed_in_old_class_only', 0)
            ->assertJsonPath('data.guardians_in_new_class', 2);

        $inSecond = GroupMembership::where('group_id', $this->second->id)->get()->keyBy('contact_id');

        $this->assertFalse($inSecond[$student->contact_id]->isConfirmed());
        $this->assertFalse($inSecond[$claim->contact_id]->isConfirmed());
        $this->assertNull($inSecond[$claim->contact_id]->confirmed_by_user_id);

        $copy = $inSecond[$confirmed->contact_id];
        $this->assertTrue($copy->isConfirmed());
        $this->assertSame($confirmer->id, (int) $copy->confirmed_by_user_id, 'the mover was recorded as the confirmer');
        $this->assertSame('2026-09-02 09:30:00', $copy->confirmed_at->toDateTimeString());
        // Carried with the same first day as the student.
        $this->assertSame(self::TODAY, $copy->joined_at->toDateString());
        $this->assertNull($copy->moved_from_group_id, 'a guardian entry carries no move columns');
        $this->assertNothingWasDestroyed($before);
    }

    #[Test]
    public function a_closed_entry_in_the_class_being_left_never_travels(): void
    {
        $student = $this->enrol($this->first, 'Maryam');
        $current = $this->guardian($student, 'Huda');
        $closed = $this->guardian($student, 'Former');
        $closed->forceFill(['left_on' => '2026-09-20'])->save();
        $before = $this->rosterSnapshot();

        $this->move($student, $this->second, self::TODAY)->assertOk()
            ->assertJsonPath('data.guardians_in_new_class', 1);

        $this->assertSame(
            [(int) $current->contact_id],
            GroupMembership::where('group_id', $this->second->id)->where('role', 'guardian')->pluck('contact_id')->map(fn ($id) => (int) $id)->all(),
        );
        $this->assertNothingWasDestroyed($before);
    }

    // ---------------------------------------------------- the one guarded copy

    #[Test]
    public function a_copy_fails_closed_and_refuses_anything_but_the_same_person_in_another_class(): void
    {
        $student = $this->enrol($this->first, 'Maryam');
        $parent = $this->guardian($student, 'Huda', consent: 'media');
        $other = $this->makePerson('Someone', 'Else');

        $blank = fn (array $with = []): GroupMembership => new GroupMembership(array_merge([
            'masjid_id' => $this->school->id, 'group_id' => $this->second->id,
            'contact_id' => $parent->contact_id, 'role' => GroupMembership::ROLE_GUARDIAN,
            'guardian_of_contact_id' => $student->contact_id,
        ], $with));

        // The half-built row: an unconfirmed claim with no consent, never a grant.
        $half = $blank()->selfAssertedFrom(null);
        $this->assertFalse($half->isConfirmed());
        $this->assertFalse($half->hasConsent());

        // The whole copy: the confirmation, and nothing else. A caller that
        // says nothing about consent copies none.
        $whole = $blank()->selfAssertedFrom(null)->carriedFrom($parent);
        $this->assertTrue($whole->isConfirmed());
        $this->assertSame((int) $parent->confirmed_by_user_id, (int) $whole->confirmed_by_user_id);
        $this->assertNull($whole->consent_scope);
        $this->assertNull($whole->consent_granted_at);
        $this->assertNull($whole->consent_carried_from_group_id);
        $this->assertNull($whole->left_on);

        // WITH CONSENT, when the caller says so: the two values as they were
        // recorded, and the class they came from.
        $with = $blank()->selfAssertedFrom(null)->carriedFrom($parent, withConsent: true);
        $this->assertTrue($with->hasConsent());
        $this->assertSame('media', $with->consent_scope);
        $this->assertTrue($parent->consent_granted_at->equalTo($with->consent_granted_at));
        $this->assertSame($this->first->id, (int) $with->consent_carried_from_group_id);

        // Never from half a record, or from a scope nobody knows: only from
        // consent that grants something.
        $noScope = $this->guardian($student, 'Half');
        $noScope->forceFill(['consent_granted_at' => '2026-09-04 10:00:00'])->save();
        $oddScope = $this->guardian($student, 'Odder', consent: 'everything');

        foreach ([$noScope, $oddScope] as $source) {
            $copy = $blank(['contact_id' => $source->contact_id])->selfAssertedFrom(null)->carriedFrom($source->fresh(), withConsent: true);
            $this->assertTrue($copy->isConfirmed());
            $this->assertFalse($copy->consentColumnsAreSet(), 'half a consent record was copied');
            $this->assertNull($copy->consent_carried_from_group_id);
        }

        // Never onto the student's own place, even from a row that holds the bytes.
        $student->forceFill(['consent_scope' => 'media', 'consent_granted_at' => '2026-09-04 10:00:00'])->save();
        $place = (new GroupMembership([
            'masjid_id' => $this->school->id, 'group_id' => $this->second->id,
            'contact_id' => $student->contact_id, 'role' => GroupMembership::ROLE_MEMBER,
        ]))->selfAssertedFrom(null)->carriedFrom($student->fresh(), withConsent: true);
        $this->assertFalse($place->consentColumnsAreSet(), 'consent was copied onto a student\'s own place');
        $this->assertNull($place->consent_carried_from_group_id);

        // And never in the unconfirmed branch: a claim holds no consent,
        // whatever the caller typed onto the unsaved row (both columns are fillable).
        $claimed = $this->guardian($student, 'Claimed', confirmed: false, consent: 'media');
        $typed = $blank([
            'contact_id' => $claimed->contact_id,
            'consent_scope' => 'media', 'consent_granted_at' => '2026-09-04 10:00:00',
        ])->carriedFrom($claimed, withConsent: true);
        $this->assertFalse($typed->isConfirmed());
        $this->assertFalse($typed->consentColumnsAreSet(), 'a claim kept the consent typed onto it');
        $this->assertNull($typed->consent_carried_from_group_id);

        // A stored provenance nobody can interpret is copied as a claim, with no confirmer.
        $odd = $this->guardian($student, 'Odd');
        $odd->forceFill(['provenance' => 'something_new'])->save();
        $copy = (new GroupMembership([
            'masjid_id' => $this->school->id, 'group_id' => $this->second->id, 'contact_id' => $odd->contact_id,
            'role' => GroupMembership::ROLE_GUARDIAN, 'guardian_of_contact_id' => $student->contact_id,
        ]))->carriedFrom($odd->fresh());
        $this->assertSame(GroupMembership::PROVENANCE_SELF_ASSERTED, $copy->provenance);
        $this->assertNull($copy->confirmed_by_user_id);
        $this->assertNull($copy->confirmed_at);

        $refused = [
            'another person' => $blank(['contact_id' => $other->id]),
            'another role' => $blank(['role' => GroupMembership::ROLE_MEMBER]),
            'another child' => $blank(['guardian_of_contact_id' => $other->id]),
            'another organisation' => $blank(['masjid_id' => $this->school->id + 999]),
            'the same class' => $blank(['group_id' => $this->first->id]),
        ];

        foreach ($refused as $what => $row) {
            try {
                $row->carriedFrom($parent);
                $this->fail("a copy was allowed for {$what}");
            } catch (\LogicException) {
                $this->assertSame(GroupMembership::PROVENANCE_CONFIRMED, $row->provenance);
                $this->assertNull($row->confirmed_by_user_id, "a refused copy still wrote a confirmer ({$what})");
            }
        }

        // A saved row is never the target, and a row that has left is never the source.
        $this->expectException(\LogicException::class);
        $parent->forceFill(['left_on' => '2026-09-20'])->save();
        $blank()->carriedFrom($parent->fresh());
    }

    #[Test]
    public function a_saved_row_cannot_be_the_target_of_a_copy(): void
    {
        $student = $this->enrol($this->first, 'Maryam');
        $parent = $this->guardian($student, 'Huda');
        $saved = $this->guardian($this->enrol($this->second, $student->contact), $parent->contact, confirmed: false);

        $this->expectException(\LogicException::class);
        $saved->carriedFrom($parent);
    }

    #[Test]
    public function a_confirmation_has_two_doors_and_each_is_counted(): void
    {
        $calls = fn (string $needle): array => collect(\Illuminate\Support\Facades\File::allFiles(app_path()))
            ->mapWithKeys(fn ($file): array => [
                str_replace(app_path().'/', '', $file->getPathname()) => substr_count(
                    preg_replace('~^\s*(/\*\*|\*|//).*$~m', '', $file->getContents()),
                    $needle,
                ),
            ])
            ->filter()
            ->all();

        // `carriedFrom` has ONE caller: the move's own copy.
        $this->assertSame(['Support/RosterMove.php' => 1], $calls('->carriedFrom('));

        // `confirmedByStaff` is called from the roster controller (add, the add
        // that confirms a claim, confirm) and from the roster import (students,
        // guardians). A new caller has to be added here, and to the docblock on
        // the method that lists them.
        $this->assertSame([
            'Http/Controllers/AdminDashboard/GroupMembershipsController.php' => 3,
            'Services/Schools/RosterImportService.php' => 2,
        ], $calls('->confirmedByStaff('));

        // There is no re-point: nothing in the application changes a roster row's class.
        $this->assertSame([], $calls('changeClassTo'));

        // The move itself is called by the single verb's controller and, once
        // the whole-class move exists, by its run. Nothing else moves a student.
        $this->assertSame(
            ['Http/Controllers/AdminDashboard/GroupMoveController.php' => 1]
                + (is_file(app_path('Support/RosterClassMove.php')) ? ['Support/RosterClassMove.php' => 1] : []),
            $calls('->move('),
        );
    }

    // ------------------------------------------------------- the guardian rule

    #[Test]
    public function an_adult_removed_where_the_student_is_does_not_come_back_through_a_move(): void
    {
        $student = $this->enrol($this->first, 'Maryam');
        $this->plantRecord('attendance_records', $student);
        $parent = $this->guardian($student, 'Gamal', consent: 'media');
        $third = $this->makeClass('3rd Grade');

        // 1. Move, with records. 2. The office removes the guardian where the student now is.
        $moved = $this->move($student, $this->second, self::TODAY)->assertOk();
        $inSecond = GroupMembership::findOrFail($moved->json('data.membership_id'));
        $entryInSecond = GroupMembership::where('group_id', $this->second->id)->where('contact_id', $parent->contact_id)->sole();

        $removal = $this->removeFromRoster($entryInSecond)->assertOk();
        $removal->assertJsonPath('data.cascade.same_guardian_elsewhere', [
            ['group_id' => $this->first->id, 'name' => '1st Grade', 'left' => true],
        ]);
        $this->assertStringContainsString(
            'Gamal Guardian is still listed as a guardian of Maryam Student in 1 other class: 1st Grade.',
            $removal->json('message'),
        );

        // 3. Moving back is refused, names the guardian, and changes no row.
        $before = DB::table('group_memberships')->orderBy('id')->get()->toArray();

        $refusal = $this->move($inSecond, $this->first, self::TODAY)->assertStatus(409)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('open_group', ['id' => $this->first->id, 'name' => '1st Grade']);
        $this->assertStringContainsString('Gamal Guardian is a confirmed guardian of Maryam Student in 1st Grade but not a confirmed guardian here.', $refusal->json('message'));
        $this->assertStringContainsString('Nothing was moved.', $refusal->json('message'));
        $this->assertEquals($before, DB::table('group_memberships')->orderBy('id')->get()->toArray());

        $this->previewMove($inSecond, $this->first, self::TODAY)->assertOk()
            ->assertJsonPath('data.can_move', false)
            ->assertJsonPath('data.refusal', $refusal->json('message'));

        // The variant: a move on to a third class creates no entry for that adult.
        $onward = $this->move($inSecond, $third, self::TODAY)->assertOk();
        $this->assertSame(0, GroupMembership::where('group_id', $third->id)->where('contact_id', $parent->contact_id)->count());

        // 4. After the entry in the first class is removed, the move back works
        //    and the adult holds no entry anywhere.
        $this->removeFromRoster($parent->fresh())->assertOk();
        $inThird = GroupMembership::findOrFail($onward->json('data.membership_id'));
        $this->move($inThird, $this->first, self::TODAY)->assertOk()->assertJsonPath('data.path', RosterMovePlan::RETURNED);

        $this->assertSame(0, GroupMembership::where('contact_id', $parent->contact_id)->count());
    }

    #[Test]
    public function a_registration_form_cannot_bring_a_removed_adult_back_and_the_refusal_says_which_case_it_is(): void
    {
        $student = $this->enrol($this->first, 'Maryam');
        $this->plantRecord('attendance_records', $student);
        $parent = $this->guardian($student, 'Gamal');

        $moved = $this->move($student, $this->second, self::TODAY)->assertOk();
        $inSecond = GroupMembership::findOrFail($moved->json('data.membership_id'));

        // Removed where the student is, then listed there again by a public form.
        GroupMembership::where('group_id', $this->second->id)->where('contact_id', $parent->contact_id)->sole()->delete();
        $this->guardian($inSecond, $parent->contact, confirmed: false);
        $closed = $parent->fresh();

        $refusal = $this->move($inSecond, $this->first, self::TODAY)->assertStatus(409);

        $this->assertStringContainsString('on this roster is only listed from a registration form', $refusal->json('message'));
        $this->assertNotNull($closed->fresh()->left_on, 'a closed confirmed entry was re-opened by a refused move');
        $this->assertEquals($closed->getAttributes(), $closed->fresh()->getAttributes());
    }

    #[Test]
    public function one_refusal_names_every_guardian_nobody_vouches_for(): void
    {
        $student = $this->enrol($this->first, 'Maryam');
        $back = $this->enrol($this->second, $student->contact);
        $back->forceFill(['left_on' => '2026-09-10'])->save();
        $one = $this->guardian($back, 'Gamal');
        $two = $this->guardian($back, 'Nadia');
        GroupMembership::whereIn('id', [$one->id, $two->id])->update(['left_on' => '2026-09-10']);

        $message = $this->move($student, $this->second, self::TODAY)->assertStatus(409)->json('message');

        $this->assertStringContainsString('Gamal Guardian', $message);
        $this->assertStringContainsString('Nadia Guardian', $message);

        // The pure rule, with a reason per entry.
        $this->guardian($student, $two->contact, confirmed: false);
        $entries = fn (Group $g) => GroupMembership::where('group_id', $g->id)->where('role', 'guardian')->get();

        $this->assertSame(
            [$one->id => RosterMove::NO_ENTRY, $two->id => RosterMove::ONLY_UNCONFIRMED],
            RosterMove::notVouched($entries($this->second), $entries($this->first), true),
        );
    }

    #[Test]
    public function an_entry_already_standing_in_the_new_class_is_reused_as_it_is_never_duplicated_never_upgraded(): void
    {
        $student = $this->enrol($this->first, 'Maryam');
        $parent = $this->guardian($student, 'Huda', consent: 'feed');

        // Old data: a guardian entry in the new class with no student beside
        // it, as a public form writes one. Unconfirmed, so it needs no voucher.
        $claim = new GroupMembership([
            'masjid_id' => $this->school->id, 'group_id' => $this->second->id, 'contact_id' => $parent->contact_id,
            'role' => GroupMembership::ROLE_GUARDIAN, 'guardian_of_contact_id' => $student->contact_id,
        ]);
        $claim->selfAssertedFrom(null)->save();
        $before = $this->rosterSnapshot();
        $asItWas = (array) DB::table('group_memberships')->where('id', $claim->id)->first();

        $preview = $this->previewMove($student, $this->second, self::TODAY)->assertOk()
            ->assertJsonPath('data.guardians.consent_carried', ['media' => 0, 'feed' => 0])
            ->assertJsonPath('data.guardians.consent_left_as_it_was', 1)
            ->assertJsonPath('data.expected_consent', 'm0f0s0n0e1');

        $told = '1 guardian already had an entry for Maryam Student in 2nd Grade. It stays exactly as it was there and '
            ."nothing is copied onto it. Check their consent on 2nd Grade's roster.";
        $this->assertContains($told, $preview->json('data.lines'));
        $this->assertStringNotContainsString('carried', $this->said($preview));

        $answer = $this->move($student, $this->second, self::TODAY)->assertOk()
            ->assertJsonPath('data.guardians_confirmed_in_old_class_only', 1)
            ->assertJsonPath('data.guardian_form_claims', 0)
            ->assertJsonPath('data.consent_left_as_it_was', 1);
        $this->assertContains($told, $answer->json('data.lines'));

        $there = GroupMembership::where('group_id', $this->second->id)->where('contact_id', $parent->contact_id)->get();
        $this->assertCount(1, $there);
        $this->assertSame($claim->id, $there->first()->id);
        $this->assertFalse($there->first()->isConfirmed(), 'a confirmation was copied onto an entry the new class already held');
        // Not one column of the entry the class already held was written.
        $this->assertSame($asItWas, (array) DB::table('group_memberships')->where('id', $claim->id)->first());
        $this->assertNothingWasDestroyed($before);
    }

    #[Test]
    public function a_confirmed_entry_the_new_class_already_holds_is_never_given_the_consent_of_the_class_being_left(): void
    {
        $student = $this->enrol($this->first, 'Maryam');
        $parent = $this->guardian($student, 'Huda', consent: 'media');

        // The student was in the second class before, and the family's entry
        // there holds the class story only (and one holds nothing at all).
        $back = $this->enrol($this->second, $student->contact);
        $twin = $this->guardian($back, $parent->contact, consent: 'feed');
        $blankTwin = $this->guardian($back, 'Gamal');
        $this->guardian($student, $blankTwin->contact, consent: 'media');
        $back->markLeftByStaff($this->admin, '2026-09-10')->save();
        $before = $this->rosterSnapshot();
        $asItWas = fn (GroupMembership $entry): array => array_diff_key(
            (array) DB::table('group_memberships')->where('id', $entry->id)->first(),
            // A return opens the entry again; nothing else about it may change.
            ['left_on' => 1, 'left_recorded_by_user_id' => 1, 'updated_at' => 1],
        );
        $twinWas = $asItWas($twin);
        $blankWas = $asItWas($blankTwin);

        $answer = $this->move($student, $this->second, self::TODAY)->assertOk()
            ->assertJsonPath('data.path', RosterMovePlan::RETURNED)
            ->assertJsonPath('data.consent_carried', ['media' => 0, 'feed' => 0])
            // Only the entry with nothing is "left as it was": the other one holds its own consent.
            ->assertJsonPath('data.consent_left_as_it_was', 1)
            ->assertJsonPath('data.consent_in_force_again', [[
                'guardian' => 'Huda Guardian', 'scope' => 'feed', 'recorded_on' => '2026-09-04',
                'reopens' => true, 'source_gone_in' => null,
            ]]);

        $this->assertSame($twinWas, $asItWas($twin));
        $this->assertSame($blankWas, $asItWas($blankTwin));
        $this->assertSame('feed', $twin->fresh()->consent_scope, 'the wider consent of the class being left was copied onto an existing entry');
        $this->assertFalse($blankTwin->fresh()->consentColumnsAreSet());
        $this->assertNull($twin->fresh()->left_on);
        $this->assertStringContainsString(
            'Consent already recorded in 2nd Grade is in force again: Huda Guardian (class story, recorded 4 Sep 2026).',
            $this->said($answer),
        );
        $this->assertNothingWasDestroyed($before);
    }

    // ------------------------------------------------------- going back

    #[Test]
    public function going_back_reopens_the_same_place_and_leaves_the_place_just_left_in_being(): void
    {
        $student = $this->enrol($this->first, 'Maryam', joined: '2026-09-01');
        $this->plantRecord('attendance_records', $student, ['session_date' => '2026-09-06']);
        $parent = $this->guardian($student, 'Huda', consent: 'media');
        $extra = $this->makePerson('Second', 'Guardian');

        $there = GroupMembership::findOrFail($this->move($student, $this->second, self::TODAY)->assertOk()->json('data.membership_id'));
        // A guardian added in the wrong class travels back; the wrong class holds nothing else.
        $this->guardian($there, $extra);
        $before = $this->rosterSnapshot();

        $preview = $this->previewMove($there, $this->first, self::TODAY)->assertOk()
            ->assertJsonPath('data.path', RosterMovePlan::RETURNED)
            ->assertJsonPath('data.joined_kept', true)
            ->assertJsonPath('data.registers_missed', 0)
            ->assertJsonPath('data.joined_on', '2026-09-01')
            // The first move carried Huda's consent, so the place being left
            // has a consent record beside it and is not offered for removal.
            ->assertJsonPath('data.old_entry_removable', false)
            ->assertJsonPath('data.guardians.consent_none_recorded', 1)
            ->assertJsonPath('data.expected_consent', 'm0f0s0n1e0');
        $this->assertStringContainsString(
            "Their entry in 2nd Grade is kept, shown as moved. A guardian's consent is recorded there, so it stays.",
            $this->said($preview),
        );

        $answer = $this->move($there, $this->first, self::TODAY, [
            'expected_path' => 'returned',
            'expected_first_day' => $preview->json('data.first_day_in_new_class'),
            'expected_joined_on' => $preview->json('data.joined_on'),
            'expected_consent' => $preview->json('data.expected_consent'),
        ])->assertOk();

        $answer->assertJsonPath('data.membership_id', $student->id)
            ->assertJsonPath('data.joined_kept', true)
            ->assertJsonPath('data.old_entry_removable', false)
            ->assertJsonPath('data.consent_in_force_again', [[
                'guardian' => 'Huda Guardian', 'scope' => 'media', 'recorded_on' => '2026-09-04',
                'reopens' => true, 'source_gone_in' => null,
            ]]);
        $this->assertStringContainsString(
            'Consent already recorded in 1st Grade is in force again: Huda Guardian (class story and photographs, recorded 4 Sep 2026).',
            implode(' ', $answer->json('data.lines')),
        );
        // The consent that comes back is the one recorded in the first class,
        // and its copy in the second is still on record there, closed.
        $copy = $this->entryIn($this->second, $parent);
        $this->assertNotNull($copy->left_on);
        $this->assertSame($this->first->id, (int) $copy->consent_carried_from_group_id);
        $this->assertSame($this->rawConsent($parent->id), $this->rawConsent($copy->id));

        $back = $student->fresh();
        $this->assertNull($back->left_on);
        $this->assertSame('2026-09-01', $back->joined_at->toDateString(), 'an undo the same day rewrote the joining day');
        $this->assertSame($this->second->id, (int) $back->moved_from_group_id);
        $this->assertNull($back->moved_to_group_id);

        $this->assertSame(1, GroupMembership::where('group_id', $this->first->id)->where('contact_id', $student->contact_id)->whereNull('left_on')->count());
        $this->assertNull($parent->fresh()->left_on);
        $this->assertTrue($parent->fresh()->hasConsent());
        $this->assertSame(1, GroupMembership::where('group_id', $this->first->id)->where('contact_id', $extra->id)->whereNull('left_on')->count());

        // R3 on a return: the place just left holds nothing, and still exists.
        $left = $there->fresh();
        $this->assertNotNull($left, 'a move deleted the empty place it left');
        $this->assertNotNull($left->left_on);
        $this->assertSame($this->first->id, (int) $left->moved_to_group_id);
        $this->assertNothingWasDestroyed($before);
    }

    #[Test]
    public function a_return_counts_from_the_first_day_back_only_when_the_class_took_a_register_meanwhile(): void
    {
        $this->logLikeProduction();

        $student = $this->enrol($this->first, 'Maryam', joined: '2026-09-01');
        $classmate = $this->enrol($this->first, 'Classmate');
        $there = GroupMembership::findOrFail($this->move($student, $this->second, '2026-09-10')->assertOk()->json('data.membership_id'));

        // The first class met twice while the student was away, and once before.
        $this->plantRecord('attendance_records', $classmate, ['session_date' => '2026-09-06']);
        $this->plantRecord('attendance_records', $classmate, ['session_date' => '2026-09-13']);
        $this->plantRecord('attendance_records', $classmate, ['session_date' => '2026-09-20']);
        $before = $this->rosterSnapshot();

        $this->previewMove($there, $this->first, self::TODAY)->assertOk()
            ->assertJsonPath('data.joined_kept', false)
            ->assertJsonPath('data.registers_missed', 2)
            ->assertJsonPath('data.joined_on', self::TODAY);

        // What the dialog showed is checked: another joining day is not what the office read.
        $this->move($there, $this->first, self::TODAY, ['expected_joined_on' => '2026-09-01'])->assertStatus(409)
            ->assertJsonPath('message', RosterMoveRefused::LOOK_AGAIN);

        $this->move($there, $this->first, self::TODAY, ['expected_joined_on' => self::TODAY])->assertOk()
            ->assertJsonPath('data.joined_kept', false);

        $this->assertSame(self::TODAY, $student->fresh()->joined_at->toDateString());
        $this->assertNothingWasDestroyed($before);

        // The day that was rewritten is kept in the log line, and nowhere else.
        $lines = $this->loggedLines('laravel.log', 'roster.move');
        $this->assertStringContainsString('"previous_joined_at":"2026-09-01"', end($lines));
    }

    #[Test]
    public function an_unconfirmed_place_and_an_unconfirmed_entry_come_back_unconfirmed_and_are_said(): void
    {
        $student = $this->enrol($this->first, 'Maryam');
        $back = $this->enrol($this->second, $student->contact, confirmed: false);
        $claim = $this->guardian($back, 'Stranger', confirmed: false);
        $back->markLeftByStaff($this->admin, '2026-09-10')->save();
        $before = $this->rosterSnapshot();

        $this->move($student, $this->second, self::TODAY)->assertOk()
            ->assertJsonPath('data.path', RosterMovePlan::RETURNED)
            ->assertJsonPath('data.student_unconfirmed', true)
            ->assertJsonPath('data.guardian_form_claims', 1);

        $this->assertFalse($back->fresh()->isConfirmed());
        $this->assertFalse($claim->fresh()->isConfirmed());
        $this->assertNull($claim->fresh()->left_on);
        $this->assertNothingWasDestroyed($before);
    }

    // ------------------------------------------------------------ the move day

    #[Test]
    public function the_move_day_is_owed_to_the_new_class_and_the_old_class_stops_the_day_before(): void
    {
        $student = $this->enrol($this->first, 'Maryam', joined: '2026-09-01');
        $before = $this->rosterSnapshot();

        $this->move($student, $this->second, '2026-09-20')->assertOk()
            ->assertJsonPath('data.first_day_in_new_class', '2026-09-20')
            ->assertJsonPath('data.old_class_keeps_move_day', false);

        $this->assertSame('2026-09-19', $student->fresh()->left_on->toDateString());
        $this->assertSame('2026-09-20', GroupMembership::where('group_id', $this->second->id)->sole()->joined_at->toDateString());
        $this->assertNothingWasDestroyed($before);
    }

    #[Test]
    public function a_student_placed_and_moved_on_one_day_is_owed_to_no_day_of_the_old_register(): void
    {
        $student = $this->enrol($this->first, 'Maryam', joined: self::TODAY);

        $this->move($student, $this->second, self::TODAY)->assertOk();

        $old = $student->fresh();
        $this->assertSame('2026-10-03', $old->left_on->toDateString());
        $this->assertSame(self::TODAY, $old->joined_at->toDateString());
        $this->assertTrue($old->left_on->lt($old->joined_at), 'the leaving day was clamped to the joining day');
    }

    #[Test]
    public function days_the_old_class_already_marked_stay_with_it_decided_from_its_last_mark(): void
    {
        $student = $this->enrol($this->first, 'Maryam', joined: '2026-09-01');
        // The office backdates the move to 27 September; the old class marked
        // them that day and again on 4 October, this morning.
        $this->plantRecord('attendance_records', $student, ['session_date' => '2026-09-27']);
        $this->plantRecord('attendance_records', $student, ['session_date' => self::TODAY]);
        $before = $this->rosterSnapshot();

        $preview = $this->previewMove($student, $this->second, '2026-09-27')->assertOk()
            ->assertJsonPath('data.old_class_keeps_move_day', true)
            ->assertJsonPath('data.first_day_in_new_class', '2026-10-05');
        $this->assertStringContainsString(
            '1st Grade marked Maryam Student up to 4 Oct 2026, so those days stay with 1st Grade. 2nd Grade expects them from 5 Oct 2026.',
            implode(' ', $preview->json('data.lines')),
        );

        // A first day the office did not read is refused.
        $this->move($student, $this->second, '2026-09-27', ['expected_first_day' => '2026-09-27'])->assertStatus(409)
            ->assertJsonPath('message', RosterMoveRefused::LOOK_AGAIN);

        $answer = $this->move($student, $this->second, '2026-09-27', ['expected_first_day' => '2026-10-05'])->assertOk()
            ->assertJsonPath('data.old_class_keeps_move_day', true);

        $old = $student->fresh();
        $new = GroupMembership::findOrFail($answer->json('data.membership_id'));

        $this->assertSame(self::TODAY, $old->left_on->toDateString(), 'the old class lost a day it had marked');
        $this->assertSame('2026-10-05', $new->joined_at->toDateString());
        // "Moved on" is the day the office chose, on both rows.
        $this->assertSame('2026-09-27', $old->moved_on->toDateString());
        $this->assertSame('2026-09-27', $new->moved_on->toDateString());
        $this->assertNothingWasDestroyed($before);

        // A row whose joining day is tomorrow can be moved again today, and the day is not rewritten.
        $third = $this->makeClass('3rd Grade');
        $this->previewMove($new, $third, self::TODAY)->assertOk()->assertJsonPath('data.can_move', true);
        $this->assertSame('2026-10-05', $new->fresh()->joined_at->toDateString());
    }

    #[Test]
    public function the_preview_says_when_the_new_class_already_took_the_register_for_the_first_day(): void
    {
        $student = $this->enrol($this->first, 'Maryam');
        $classmate = $this->enrol($this->second, 'Classmate');
        $this->plantRecord('attendance_records', $classmate, ['session_date' => self::TODAY]);

        $preview = $this->previewMove($student, $this->second, self::TODAY)->assertOk()
            ->assertJsonPath('data.new_class_took_register_on_first_day', true);

        $this->assertStringContainsString(
            '2nd Grade has already taken the register for 4 Oct 2026. Maryam Student will show as not marked there until its teacher marks them.',
            implode(' ', $preview->json('data.lines')),
        );
    }

    /**
     * The Sunday afternoon, undone. The first class marks the student, the
     * office moves them and moves them straight back. The place being re-opened
     * is the row that HOLDS that day's mark, so "will show as not marked there"
     * would send the office after a teacher for a mark that exists.
     */
    #[Test]
    public function a_return_onto_a_place_that_holds_the_days_mark_is_not_told_the_student_is_unmarked(): void
    {
        $student = $this->enrol($this->first, 'Maryam');
        $this->plantRecord('attendance_records', $student, ['session_date' => self::TODAY]);

        $there = GroupMembership::findOrFail(
            $this->move($student, $this->second, self::TODAY)->assertOk()->json('data.membership_id'),
        );

        $preview = $this->previewMove($there, $this->first, self::TODAY)->assertOk()
            ->assertJsonPath('data.path', RosterMovePlan::RETURNED)
            ->assertJsonPath('data.first_day_in_new_class', self::TODAY)
            ->assertJsonPath('data.new_class_took_register_on_first_day', false);
        $this->assertStringNotContainsString('not marked', implode(' ', $preview->json('data.lines')));

        $moved = $this->move($there, $this->first, self::TODAY)->assertOk();
        $this->assertStringNotContainsString('not marked', implode(' ', $moved->json('data.lines')));
        $this->assertSame($student->id, $moved->json('data.membership_id'));
    }

    /** The other half: the class met that day and the returning place has NO mark, so it is said. */
    #[Test]
    public function a_return_onto_a_place_the_class_did_not_mark_that_day_is_still_told(): void
    {
        $student = $this->enrol($this->first, 'Maryam');
        $classmate = $this->enrol($this->first, 'Classmate');

        $there = GroupMembership::findOrFail(
            $this->move($student, $this->second, self::TODAY)->assertOk()->json('data.membership_id'),
        );

        // The first class takes its register after the student has gone: everyone but them.
        $this->plantRecord('attendance_records', $classmate, ['session_date' => self::TODAY]);

        $preview = $this->previewMove($there, $this->first, self::TODAY)->assertOk()
            ->assertJsonPath('data.path', RosterMovePlan::RETURNED)
            ->assertJsonPath('data.new_class_took_register_on_first_day', true);

        $this->assertStringContainsString(
            '1st Grade has already taken the register for 4 Oct 2026. Maryam Student will show as not marked there until its teacher marks them.',
            implode(' ', $preview->json('data.lines')),
        );
    }

    /**
     * The Member Directory's delete is a soft one and leaves the roster row, so
     * the roster still lists it. The preview never loads the contact; the move
     * locks it. A lock that skipped a deleted contact answered 409 "this roster
     * changed" to a move the preview had just allowed, on every retry.
     */
    #[Test]
    public function a_student_whose_contact_was_deleted_gets_the_same_answer_from_the_preview_and_the_move(): void
    {
        $student = $this->enrol($this->first, 'Maryam');
        $before = $this->rosterSnapshot();

        Contact::findOrFail($student->contact_id)->delete();

        $this->previewMove($student, $this->second, self::TODAY)->assertOk()
            ->assertJsonPath('data.can_move', true);

        $moved = $this->move($student, $this->second, self::TODAY)->assertOk();

        $this->assertNotNull($student->fresh()->left_on, 'the old place was not closed');
        $this->assertSame(
            (int) $this->second->id,
            (int) GroupMembership::findOrFail($moved->json('data.membership_id'))->group_id,
        );
        $this->assertNotNull(DB::table('contacts')->where('id', $student->contact_id)->value('deleted_at'), 'the move did not restore the contact');
        $this->assertNothingWasDestroyed($before);
    }

    // ----------------------------------------------------- what the office is told

    #[Test]
    public function the_preview_and_the_move_tell_the_office_the_same_thing(): void
    {
        $student = $this->enrol($this->first, 'Maryam');
        $student->forceFill(['grade_label' => '1st'])->save();
        $this->plantRecord('attendance_records', $student);
        $this->plantRecord('assignment_scores', $student);
        $this->guardian($student, 'Huda', consent: 'media');
        $this->guardian($student, 'Stranger', confirmed: false);

        $preview = $this->previewMove($student, $this->second, self::TODAY)->assertOk()->json('data');

        $this->assertSame('1st', $preview['grade_label']);
        $this->assertSame(['register marks' => 1, 'marks' => 1], $preview['records_staying']);
        $this->assertStringContainsString('Maryam Student has records in 1st Grade: 1 register mark, 1 mark.', $preview['lines'][0]);
        // Marks and no report card: the old teacher cannot start one afterwards.
        $this->assertStringContainsString('1st Grade has not started a report card for Maryam Student.', implode(' ', $preview['lines']));

        $answer = $this->move($student, $this->second, self::TODAY, [
            'grade_label' => '2nd',
            'expected_path' => $preview['path'],
            'expected_first_day' => $preview['first_day_in_new_class'],
            'expected_consent' => $preview['expected_consent'],
        ])->assertOk()->json('data');

        $this->assertSame($preview['path'], $answer['path']);
        $this->assertSame($preview['first_day_in_new_class'], $answer['first_day_in_new_class']);
        $this->assertSame($preview['records_staying'], $answer['records_staying']);
        $this->assertSame($preview['guardians']['travelling'], $answer['guardians_in_new_class']);
        $this->assertSame($preview['guardians']['form_claims'], $answer['guardian_form_claims']);
        $this->assertSame($preview['old_entry_removable'], $answer['old_entry_removable']);

        // Consent: every count the preview gave is the count the answer gives.
        $this->assertSame('m1f0s0n0e0', $preview['expected_consent']);
        foreach (['consent_carried', 'consent_none_recorded', 'consent_none_but_receives', 'consent_not_carried', 'consent_left_as_it_was', 'consent_in_force_again'] as $key) {
            $this->assertSame($preview['guardians'][$key], $answer[$key], "the preview and the answer differ on {$key}");
        }
        $this->assertSame($preview['others_in_new_class'], $answer['others_in_new_class']);
        $this->assertSame($preview['new_class_holds'], $answer['new_class_holds']);
        $this->assertSame(['stories' => 0, 'with_media' => 0], $answer['new_class_holds']);

        // A form claim is never something the office is told to confirm.
        $said = implode(' ', $answer['lines']);
        $this->assertStringContainsString('1 guardian entry in 2nd Grade was typed into the registration form', $said);
        $this->assertStringNotContainsString('Confirm them', $said);

        $this->assertSame('2nd', GroupMembership::findOrFail($answer['membership_id'])->grade_label);
        $this->assertSame('1st', $student->fresh()->grade_label, 'the old place lost its grade');
    }

    #[Test]
    public function the_grade_is_kept_when_the_key_is_absent_and_cleared_when_it_is_empty(): void
    {
        $one = $this->enrol($this->first, 'Maryam');
        $two = $this->enrol($this->first, 'Yusuf');
        GroupMembership::whereIn('id', [$one->id, $two->id])->update(['grade_label' => '1st']);

        $kept = $this->move($one, $this->second, self::TODAY)->assertOk()->json('data.membership_id');
        $cleared = $this->move($two, $this->second, self::TODAY, ['grade_label' => ''])->assertOk()->json('data.membership_id');

        $this->assertSame('1st', GroupMembership::findOrFail($kept)->grade_label);
        $this->assertNull(GroupMembership::findOrFail($cleared)->grade_label);
    }

    #[Test]
    public function a_changed_case_is_refused_and_changes_nothing(): void
    {
        $student = $this->enrol($this->first, 'Maryam');
        $before = DB::table('group_memberships')->orderBy('id')->get()->toArray();

        $this->move($student, $this->second, self::TODAY, ['expected_path' => 'returned'])->assertStatus(409)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', RosterMoveRefused::LOOK_AGAIN);

        $this->assertEquals($before, DB::table('group_memberships')->orderBy('id')->get()->toArray());
    }

    #[Test]
    public function the_office_is_shown_no_figure_about_a_childs_bucks(): void
    {
        $student = $this->enrol($this->first, 'Maryam');
        $this->plantRecord('prize_ledger_entries', $student, ['amount' => 12]);
        $ledger = DB::table('prize_ledger_entries')->count();

        // A school that does not hold the class store is told nothing about it.
        $quiet = $this->previewMove($student, $this->second, self::TODAY)->assertOk();
        $quiet->assertJsonPath('data.bucks_staying', false);
        $this->assertStringNotContainsString('Manara Bucks', $quiet->getContent());

        $this->school->forceFill(['capability_overrides' => [SchoolSettings::CLASS_STORE => true]])->save();

        $preview = $this->previewMove($student, $this->second, self::TODAY)->assertOk()
            ->assertJsonPath('data.bucks_staying', true)
            ->assertJsonPath('data.records_staying', []);
        $this->assertContains(
            'Maryam Student has Manara Bucks in 1st Grade. They stay there for now and cannot be spent in 2nd Grade.',
            $preview->json('data.lines'),
        );

        $answer = $this->move($student, $this->second, self::TODAY)->assertOk()->assertJsonPath('data.bucks_staying', true);

        foreach ([$preview, $answer] as $response) {
            $this->assertStringNotContainsString('12', preg_replace('/"(moved_on|first_day_in_new_class|joined_on)":"[^"]*"|\d+ Oct 2026|_id":\d+|"id":\d+/', '', $response->getContent()));
        }

        // No ledger row is written by a move, and the balance stays on the old row.
        $this->assertSame($ledger, DB::table('prize_ledger_entries')->count());
        $this->assertSame($student->id, (int) DB::table('prize_ledger_entries')->value('group_membership_id'));
    }

    #[Test]
    public function a_message_still_waiting_is_counted_apart_from_the_record_it_also_is(): void
    {
        $sent = $this->enrol($this->first, 'Maryam');
        $waiting = $this->enrol($this->first, 'Yusuf');
        $this->plantRecord('group_message_schedules', $sent, ['status' => 'sent']);
        $this->plantRecord('group_message_schedules', $waiting, ['status' => 'scheduled']);

        $this->previewMove($sent, $this->second, self::TODAY)->assertOk()
            ->assertJsonPath('data.scheduled_messages_stopping', 0)
            ->assertJsonPath('data.records_staying', ['scheduled messages (any state)' => 1]);

        $preview = $this->previewMove($waiting, $this->second, self::TODAY)->assertOk()
            ->assertJsonPath('data.scheduled_messages_stopping', 1);
        $this->assertContains('1 message scheduled about Yusuf Student in 1st Grade will not be sent.', $preview->json('data.lines'));
        $this->assertStringContainsString('1 scheduled message (any state)', $preview->json('data.lines.0'));
    }

    // ------------------------------------------------------------- refusals

    #[Test]
    public function every_refusal_answers_its_status_and_its_sentence_and_changes_no_row(): void
    {
        $student = $this->enrol($this->first, 'Maryam', joined: '2026-09-01');
        $parent = $this->guardian($student, 'Huda');
        $leader = $this->enrol($this->first, 'Legacy');
        $leader->forceFill(['role' => GroupMembership::ROLE_LEADER])->save();
        $left = $this->enrol($this->first, 'Gone');
        $left->markLeftByStaff($this->admin, '2026-09-20')->save();

        $halaqa = $this->makeClass('Evening circle', ['kind' => Group::KIND_HALAQA]);
        $off = $this->makeClass('Switched off', ['is_active' => false]);
        $ended = $this->makeClass('Last year', ['ends_on' => '2026-06-30']);
        $deleted = $this->makeClass('Deleted');
        $deleted->delete();

        $inBoth = $this->enrol($this->first, 'Both');
        $this->enrol($this->second, $inBoth->contact);

        $asLeader = $this->enrol($this->first, 'Helper');
        $this->enrol($this->second, $asLeader->contact)->forceFill(['role' => GroupMembership::ROLE_LEADER, 'left_on' => '2026-09-10'])->save();

        $twiceThere = $this->enrol($this->first, 'Double');
        $this->enrol($this->second, $twiceThere->contact)->forceFill(['left_on' => '2026-09-10'])->save();
        $this->enrol($this->second, $twiceThere->contact)->forceFill(['left_on' => '2026-09-11'])->save();

        $twiceHere = $this->enrol($this->first, 'Twin');
        $this->enrol($this->first, $twiceHere->contact);

        // Three students whose entry in the second class would re-open with a
        // consent the family has since taken back on the other side of a carry
        // (R10). Planted as rows here; the flows that produce them are below.
        $heldBack = function (string $child, array $here, array $there): GroupMembership {
            $row = $this->enrol($this->first, $child);
            $adult = $this->makePerson('Huda', 'Of'.$child);
            $back = $this->enrol($this->second, $row->contact);
            $this->guardian($row, $adult, consent: $here['consent'] ?? null)->forceFill(array_diff_key($here, ['consent' => 1]))->save();
            $this->guardian($back, $adult, consent: 'media')->forceFill($there)->save();
            $back->markLeftByStaff($this->admin, '2026-09-10')->save();

            return $row;
        };
        $copyWithdrawn = $heldBack('Copy', ['consent_carried_from_group_id' => $this->second->id], []);
        $sourceWithdrawn = $heldBack('Source', [], ['consent_carried_from_group_id' => $this->first->id]);
        $sourceNarrowed = $heldBack('Narrow', ['consent' => 'feed'], ['consent_carried_from_group_id' => $this->first->id]);
        $then = fn (string $child): string => "\nNothing was moved. Open 2nd Grade and withdraw that consent on its roster first "
            ."(the Consent button on the guardian's row), then move {$child} Student again. If the family still agrees for "
            .'2nd Grade, record it there after the move.';

        $cases = [
            'a guardian entry' => [$parent, $this->second, self::TODAY, 422, 'Only a student moves between classes. A guardian entry moves with the child it names.'],
            'a legacy leader' => [$leader, $this->second, self::TODAY, 422, 'Only a student moves between classes. A guardian entry moves with the child it names.'],
            'a row that has left' => [$left, $this->second, self::TODAY, 422, 'Gone Student has already left this class. To place them in another class, put them back on this roster first, then move them.'],
            'a class that does not exist' => [$student, 999999, self::TODAY, 422, "Choose one of this school's classes."],
            'a deleted class' => [$student, $deleted, self::TODAY, 422, "Choose one of this school's classes."],
            'this class' => [$student, $this->first, self::TODAY, 422, 'Maryam Student is already in this class.'],
            'not a class' => [$student, $halaqa, self::TODAY, 422, 'Students can only be moved between classes.'],
            'switched off' => [$student, $off, self::TODAY, 422, 'Switched off is not running: it is switched off or has ended. Choose a class that is running.'],
            'ended' => [$student, $ended, self::TODAY, 422, 'Last year is not running: it is switched off or has ended. Choose a class that is running.'],
            'the future' => [$student, $this->second, '2026-10-05', 422, 'The first day in the new class cannot be in the future.'],
            'before they joined' => [$student, $this->second, '2026-08-31', 422, 'Maryam Student joined this class on 1 Sep 2026. Choose that day or a later one.'],
            'already there' => [$inBoth, $this->second, self::TODAY, 409, 'Both Student is already in 2nd Grade. Nothing was moved. If they should no longer be in this class, record them as having left it.'],
            'a leader there' => [$asLeader, $this->second, self::TODAY, 409, 'Helper Student is listed in 2nd Grade as a leader. Remove that entry there first; nothing was moved.'],
            'twice there' => [$twiceThere, $this->second, self::TODAY, 409, "Double Student appears twice on 2nd Grade's roster. Open 2nd Grade and remove the extra entry, then move them again."],
            'twice here' => [$twiceHere, $this->second, self::TODAY, 409, 'Twin Student appears twice on this roster. Remove the extra entry first; nothing was moved.'],
            'the carried copy was withdrawn' => [$copyWithdrawn, $this->second, self::TODAY, 409, 'Huda OfCopy withdrew consent in 1st Grade after it had been carried there from 2nd Grade. The consent recorded in 2nd Grade (class story and photographs, recorded 4 Sep 2026) would come back into force.'.$then('Copy')],
            'the source of a copy was withdrawn' => [$sourceWithdrawn, $this->second, self::TODAY, 409, "Huda OfSource's consent in 2nd Grade (class story and photographs, recorded 4 Sep 2026) was carried there from 1st Grade, and consent in 1st Grade has since been withdrawn. It would come back into force in 2nd Grade.".$then('Source')],
            'the source of a copy was narrowed' => [$sourceNarrowed, $this->second, self::TODAY, 409, "Huda OfNarrow's consent in 2nd Grade for the class story and photographs was carried there from 1st Grade, and consent in 1st Grade is now for the class story only. The photograph consent would come back into force in 2nd Grade.".$then('Narrow')],
        ];

        $before = DB::table('group_memberships')->orderBy('id')->get()->toArray();

        foreach ($cases as $what => [$row, $to, $on, $status, $sentence]) {
            $this->move($row, $to, $on)->assertStatus($status)
                ->assertJsonPath('status', 'error')
                ->assertJsonPath('message', $sentence);

            $this->previewMove($row, $to, $on)->assertOk()
                ->assertJsonPath('data.can_move', false)
                ->assertJsonPath('data.refusal', $sentence);

            $this->assertEquals($before, DB::table('group_memberships')->orderBy('id')->get()->toArray(), "a refused move changed a row ({$what})");
        }

        // A malformed request is the legacy validation envelope, on the read and the write.
        Sanctum::actingAs($this->admin);
        $this->getJson($this->moveUrl($student).'?moved_on=4-10-2026')->assertStatus(422)->assertJsonPath('status', 'failed');
        $this->postJson($this->moveUrl($student), ['to_group_id' => $this->second->id, 'grade_label' => str_repeat('x', 33)])
            ->assertStatus(422)->assertJsonPath('status', 'failed');
    }

    #[Test]
    public function a_row_that_was_moved_says_where_to_move_it_from(): void
    {
        $student = $this->enrol($this->first, 'Maryam');
        $this->move($student, $this->second, self::TODAY)->assertOk();

        $this->move($student->fresh(), $this->second, self::TODAY)->assertStatus(422)
            ->assertJsonPath('message', 'Maryam Student was moved to 2nd Grade on 4 Oct 2026. Open 2nd Grade to move them again.')
            ->assertJsonPath('open_group', ['id' => $this->second->id, 'name' => '2nd Grade']);
    }

    #[Test]
    public function a_row_whose_joining_day_is_tomorrow_can_still_be_moved_today(): void
    {
        // A registration confirmed in the evening stamps tomorrow, on the server's UTC clock.
        $student = $this->enrol($this->first, 'Maryam', joined: '2026-10-05');

        $this->move($student, $this->second, self::TODAY)->assertOk();

        $this->assertSame('2026-10-05', $student->fresh()->joined_at->toDateString(), 'the comparison rewrote the stored joining day');
    }

    // ------------------------------------------------- who may, and where

    #[Test]
    public function another_organisations_class_and_roster_row_do_not_exist_here(): void
    {
        $other = $this->makeSchool();
        $theirAdmin = $this->makeAdmin($other);
        $theirClass = $this->makeClass('Their class', school: $other);
        $theirChild = $this->makePerson('Their', 'Child', $other);
        $theirRow = new GroupMembership([
            'masjid_id' => $other->id, 'group_id' => $theirClass->id, 'contact_id' => $theirChild->id,
            'role' => GroupMembership::ROLE_MEMBER,
        ]);
        $theirRow->confirmedByStaff($theirAdmin)->save();

        $student = $this->enrol($this->first, 'Maryam');

        // Their class id answers exactly as an id that does not exist.
        $missing = $this->move($student, 999999, self::TODAY)->assertStatus(422)->json();
        $this->assertSame($missing, $this->move($student, $theirClass->id, self::TODAY)->assertStatus(422)->json());
        $this->previewMove($student, $theirClass->id, self::TODAY)->assertOk()->assertJsonPath('data.can_move', false);

        // Their roster row under our route is a 404.
        Sanctum::actingAs($this->admin);
        $url = "/api/admin/masjids/{$this->school->id}/groups/{$this->first->id}/members/{$theirRow->id}/move";
        $this->getJson($url.'?to_group_id='.$this->second->id)->assertNotFound();
        $this->postJson($url, ['to_group_id' => $this->second->id])->assertNotFound();

        // Their administrator is refused by the tenant gate, on both verbs.
        $this->previewMove($student, $this->second, self::TODAY, as: $theirAdmin)->assertForbidden();
        $this->move($student, $this->second, self::TODAY, as: $theirAdmin)->assertForbidden();

        $this->assertNull($student->fresh()->left_on);
    }

    #[Test]
    public function only_the_office_moves_a_student(): void
    {
        $student = $this->enrol($this->first, 'Maryam');

        foreach ([['Teacher', 'teacher'], [User::TYPE_LUNCH_STAFF, 'lunch-staff']] as [$type, $role]) {
            $staff = $this->makeAdmin($this->school, $type, $role);
            $this->previewMove($student, $this->second, self::TODAY, as: $staff)->assertUnauthorized();
            $this->move($student, $this->second, self::TODAY, as: $staff)->assertUnauthorized();
        }

        // Seeing the directory is not enough: both verbs take `manage contacts`.
        Role::findByName('masjid-admin', 'web')->revokePermissionTo('manage contacts');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->previewMove($student, $this->second, self::TODAY)->assertForbidden();
        $this->move($student, $this->second, self::TODAY)->assertForbidden();

        $this->assertNull($student->fresh()->left_on);

        // No teacher or family route moves anybody.
        $routes = collect(app('router')->getRoutes())->map->uri()->filter(fn (string $uri): bool => str_ends_with($uri, '/move'));
        $this->assertCount(1, $routes->unique());
        $this->assertStringStartsWith('api/admin/', $routes->first());
    }

    #[Test]
    public function the_teachers_class_payload_carries_none_of_the_move_columns(): void
    {
        $teacher = $this->makeAdmin($this->school, 'Teacher', 'teacher');
        $this->second->staff()->attach($teacher->id, [
            'masjid_id' => $this->school->id, 'role' => GroupStaff::ROLE_TEACHER, 'assigned_at' => now(),
        ]);

        $student = $this->enrol($this->first, 'Maryam');
        $parent = $this->guardian($student, 'Huda', consent: 'media');
        $this->move($student, $this->second, self::TODAY)->assertOk();
        $this->assertNotNull($this->entryIn($this->second, $parent)->consent_carried_from_group_id);

        Sanctum::actingAs($teacher);
        $body = $this->getJson("/api/teacher/masjids/{$this->school->id}/groups/{$this->second->id}")->assertOk()->getContent();

        $this->assertStringContainsString('Maryam', $body);

        // Nor the marker of a carried consent, nor the class it names: a teacher's
        // payload is built by hand and carries no consent field at all.
        foreach (['moved_from_group_id', 'moved_to_group_id', 'moved_by_user_id', 'moved_on', 'moved_to_state', 'consent_carried_from'] as $key) {
            $this->assertStringNotContainsString($key, $body);
        }
    }

    // ------------------------------------------------------------ the history

    #[Test]
    public function a_move_is_logged_where_production_keeps_it_with_the_guardian_entries_it_touched(): void
    {
        $this->logLikeProduction();

        $student = $this->enrol($this->first, 'Maryam');
        $parent = $this->guardian($student, 'Huda', consent: 'feed');
        // Already in the second class for a brother, with nothing recorded
        // there: this guardian's consent is not carried.
        $capped = $this->guardian($student, 'Gamal', consent: 'media');
        $this->guardian($this->enrol($this->second, 'Yusuf'), $capped->contact);

        // A refusal logs nothing.
        $this->move($student, $this->first, self::TODAY)->assertStatus(422);
        $this->assertSame([], $this->loggedLines('laravel.log', 'roster.move'));

        $answer = $this->move($student, $this->second, self::TODAY)->assertOk();
        $carried = GroupMembership::where('group_id', $this->second->id)->where('contact_id', $parent->contact_id)->sole();

        // Production runs LOG_LEVEL=warning: an info line would not be here.
        $lines = $this->loggedLines('laravel.log', 'roster.move');
        $this->assertCount(1, $lines);
        $this->assertStringContainsString('.WARNING: roster.move', $lines[0]);
        $this->assertStringContainsString('"membership":'.$student->id, $lines[0]);
        $this->assertStringContainsString('"new_membership":'.$answer->json('data.membership_id'), $lines[0]);
        $this->assertStringContainsString('"guardian_entries_carried":['.$carried->id.',', $lines[0]);
        $this->assertStringContainsString('"by":'.$this->admin->id, $lines[0]);
        // A carried consent is a pair: the new entry and the entry it was
        // copied from. One left blank for a sibling's sake is its source entry.
        $this->assertStringContainsString('"consent_carried":[['.$carried->id.','.$parent->id.']]', $lines[0]);
        $this->assertStringContainsString('"consent_not_carried":['.$capped->id.']', $lines[0]);
        // A single move is no part of a whole-class run.
        $this->assertStringContainsString('"run":null', $lines[0]);
        // Ids only.
        $this->assertStringNotContainsString('Maryam', $lines[0]);
        $this->assertStringNotContainsString('Huda', $lines[0]);
        $this->assertStringNotContainsString('Gamal', $lines[0]);

        // A run's id is the run's to give, and no request can.
        $other = $this->enrol($this->first, 'Layla');
        $this->moveByService($other, $this->second, ['run' => '01JRUNOFTHISCLASS']);
        $this->move($this->enrol($this->first, 'Bilal'), $this->second, self::TODAY, ['run' => 'typed-into-a-request'])->assertOk();

        $lines = $this->loggedLines('laravel.log', 'roster.move');
        $this->assertCount(3, $lines);
        $this->assertStringContainsString('"run":"01JRUNOFTHISCLASS"', $lines[1]);
        $this->assertStringContainsString('"run":null', $lines[2]);
    }

    // --------------------------------------------------- locks, and failing closed

    /**
     * tests/MysqlLocks commits its fixtures and deletes them by hand, and it
     * runs only on the MySQL job. Its first run there named a table that does
     * not exist (`masjid_users`; the pivot is `masjid_user`), the cleanup threw,
     * and every test in the file was reported as an error whatever its body
     * proved. The names are checked here, where the suite always runs.
     */
    #[Test]
    public function the_lock_suites_cleanup_names_only_tables_that_exist_and_carry_the_organisation(): void
    {
        $source = file_get_contents(base_path('tests/MysqlLocks/RosterMoveLocksTest.php'));

        // The hand-written list: the loop that is not over AcademicRecordsHeld::KEYS.
        $this->assertSame(
            1,
            preg_match('/foreach \(\[([^\]]+)\] as \$table\) \{\s+DB::table\(\$table\)->where\("?\x27?masjid_id/', $source, $m),
            'the cleanup loop was not found',
        );
        preg_match_all("/'([a-z_]+)'/", $m[1], $names);

        $this->assertContains((new MasjidUser())->getTable(), $names[1], 'the administrator\'s pivot row is deleted');

        foreach ([...$names[1], ...array_keys(AcademicRecordsHeld::KEYS)] as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'masjid_id'), "{$table} is deleted by organisation in the lock suite's cleanup");
        }
    }

    #[Test]
    public function every_lock_is_taken_by_primary_key_before_the_first_ordinary_read(): void
    {
        $source = file_get_contents(app_path('Support/RosterMove.php'));
        $start = strpos($source, 'public function move(');
        $move = substr($source, $start, strpos($source, '// ----------------------------------------------------- the guardian rule') - $start);
        $code = preg_replace('~^\s*//.*$~m', '', $move);

        $this->assertSame(3, substr_count($code, '->lockForUpdate()'), 'the move takes three exclusive locks: the contact, the row, the rows about the student');
        $this->assertSame(1, substr_count($code, '->sharedLock()'), 'and one shared lock, on the two class rows');
        $this->assertSame(3, substr_count($code, 'whereKey(') + substr_count($code, "whereIn('id', \$ids)"), 'every exclusive lock is by primary key');

        $transaction = substr($code, strpos($code, 'DB::transaction('));
        // withTrashed: the lock is a mutex, taken whether or not the office deleted the person.
        $firstLock = strpos($transaction, '$contact = Contact::withTrashed()->whereKey($contactId)->lockForUpdate()');
        $secondLock = strpos($transaction, 'GroupMembership::query()->whereKey($rowId)->lockForUpdate()');
        $thirdLock = strpos($transaction, "whereIn('id', \$ids)->orderBy('id')->lockForUpdate()");
        $shared = strpos($transaction, '->sharedLock()');

        $this->assertNotFalse($firstLock);
        $this->assertTrue($firstLock < $secondLock && $secondLock < $thirdLock && $thirdLock < $shared, 'the lock order is contact, row, rows, classes');

        // The contact lock is the first statement of the transaction, and
        // nothing that reads comes before the second lock.
        $this->assertSame('', trim(preg_replace('~^.*?function \(\)[^{]*\{~s', '', substr($transaction, 0, $firstLock))),
            'something runs inside the transaction before the contact lock');
        foreach (['decide(', 'counts(', '->get()', '->pluck(', '->exists()', '->count()', 'rowsAbout('] as $read) {
            $this->assertStringNotContainsString($read, substr($transaction, 0, $secondLock), "{$read} runs before the roster row is locked");
        }

        $this->assertGreaterThan($shared, strpos($transaction, '$this->decide('), 'the decision is made before every lock is held');

        // The class store's own five are untouched.
        $this->assertSame(5, substr_count(file_get_contents(app_path('Support/ClassStore.php')), 'lockForUpdate()'));
    }

    public static function plantedFaults(): array
    {
        return [
            'a second live place in the new class' => ['second_place_there'],
            'a live place left in the old class' => ['live_place_here'],
            'an open confirmed entry nobody vouched for' => ['unvouched_entry'],
        ];
    }

    #[Test]
    #[DataProvider('plantedFaults')]
    public function the_check_after_the_writes_fails_closed(string $fault): void
    {
        $student = $this->enrol($this->first, 'Maryam');
        $this->guardian($student, 'Huda', consent: 'media');
        $stranger = $this->makePerson('Not', 'Vouched');
        $first = $this->first;
        $second = $this->second;
        $admin = $this->admin;

        $this->app->bind(RosterMove::class, fn () => new class($fault, $student, $stranger, $first, $second, $admin) extends RosterMove {
            public function __construct(
                private string $fault, private GroupMembership $student, private $stranger,
                private Group $first, private Group $second, private User $admin,
            ) {
            }

            protected function written(RosterMovePlan $plan): void
            {
                $row = fn (Group $class, array $with): GroupMembership => tap(new GroupMembership(array_merge([
                    'masjid_id' => $class->masjid_id, 'group_id' => $class->id,
                    'contact_id' => $this->student->contact_id, 'role' => GroupMembership::ROLE_MEMBER,
                ], $with)), fn (GroupMembership $m) => $m->confirmedByStaff($this->admin)->save());

                match ($this->fault) {
                    'second_place_there' => $row($this->second, []),
                    'live_place_here' => $row($this->first, []),
                    'unvouched_entry' => $row($this->second, [
                        'contact_id' => $this->stranger->id, 'role' => GroupMembership::ROLE_GUARDIAN,
                        'guardian_of_contact_id' => $this->student->contact_id,
                    ]),
                };
            }
        });

        $before = DB::table('group_memberships')->orderBy('id')->get()->toArray();

        $this->move($student, $this->second, self::TODAY)->assertStatus(409)
            ->assertJsonPath('message', RosterMoveRefused::CHANGED);

        $this->assertEquals($before, DB::table('group_memberships')->orderBy('id')->get()->toArray(),
            'a move the check refused left something written');
    }

    public static function engineRefusals(): array
    {
        $query = fn (int $code, string $text): QueryException => new QueryException(
            'mysql', 'select 1', [],
            tap(new \PDOException($text), fn (\PDOException $e) => $e->errorInfo = ['HY000', $code, $text]),
        );

        return [
            'a lock wait timeout' => [fn () => $query(1205, 'Lock wait timeout exceeded; try restarting transaction')],
            'a deadlock' => [fn () => $query(1213, 'Deadlock found when trying to get lock; try restarting transaction')],
            'a deadlock inside another transaction' => [fn () => new DeadlockException('Deadlock found when trying to get lock')],
        ];
    }

    #[Test]
    #[DataProvider('engineRefusals')]
    public function a_row_somebody_else_holds_is_answered_as_a_changed_roster_never_a_500(\Closure $thrown): void
    {
        $student = $this->enrol($this->first, 'Maryam');

        $this->app->bind(RosterMove::class, fn () => new class($thrown) extends RosterMove {
            public function __construct(private \Closure $thrown)
            {
            }

            protected function locksTaken(): void
            {
                throw ($this->thrown)();
            }
        });

        $this->move($student, $this->second, self::TODAY)->assertStatus(409)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', RosterMoveRefused::CHANGED);

        $this->assertNull($student->fresh()->left_on);
    }

    #[Test]
    public function a_fault_that_is_not_a_held_row_is_still_a_fault(): void
    {
        $student = $this->enrol($this->first, 'Maryam');

        $this->app->bind(RosterMove::class, fn () => new class extends RosterMove {
            protected function locksTaken(): void
            {
                throw new QueryException('mysql', 'select 1', [],
                    tap(new \PDOException('Unknown column'), fn (\PDOException $e) => $e->errorInfo = ['42S22', 1054, 'Unknown column']));
            }
        });

        $this->move($student, $this->second, self::TODAY)->assertStatus(500);
    }

    #[Test]
    public function the_class_is_checked_again_under_its_lock(): void
    {
        $student = $this->enrol($this->first, 'Maryam');
        $second = $this->second;

        // Somebody deletes the class between the read and the lock.
        $this->app->bind(RosterMove::class, fn () => new class($second) extends RosterMove {
            private bool $once = false;

            public function __construct(private Group $second)
            {
            }

            public function preview(Group $from, GroupMembership $row, ?Group $to, string $on, array $options = []): RosterMovePlan
            {
                $plan = parent::preview($from, $row, $to, $on, $options);

                if (! $this->once) {
                    $this->once = true;
                    $this->second->delete();
                }

                return $plan;
            }
        });

        $this->move($student, $this->second, self::TODAY)->assertStatus(422)
            ->assertJsonPath('message', "Choose one of this school's classes.");

        $this->assertNull($student->fresh()->left_on);
        $this->assertSame(0, GroupMembership::where('group_id', $this->second->id)->count());
    }

    // ============================================================ consent is carried
    //
    // R8: as it was recorded, or not at all. The six cases of an adult who may
    // already stand in the class being entered for another child.

    public static function whatTheAdultHoldsThereForAnotherChild(): array
    {
        return [
            'nobody there' => ['media', null, 'media', null],
            'a blank entry there' => ['media', ['consent' => null], null, 'none'],
            'the class story there, photographs to carry' => ['media', ['consent' => 'feed'], null, 'feed'],
            'photographs there, the class story to carry' => ['feed', ['consent' => 'media'], 'feed', null],
            'an unconfirmed entry there is ignored' => ['media', ['consent' => null, 'confirmed' => false], 'media', null],
            'an entry there that has left is ignored' => ['media', ['consent' => null, 'left' => true], 'media', null],
            'the same scope there' => ['feed', ['consent' => 'feed'], 'feed', null],
        ];
    }

    #[Test]
    #[DataProvider('whatTheAdultHoldsThereForAnotherChild')]
    public function a_carry_never_gives_an_adult_more_than_they_hold_in_the_class_entered_for_another_child(
        string $recorded, ?array $there, ?string $carried, ?string $holds,
    ): void {
        $student = $this->enrol($this->first, 'Maryam');
        $parent = $this->guardian($student, 'Huda', consent: $recorded);
        $siblingEntry = null;

        if ($there !== null) {
            $sibling = $this->enrol($this->second, 'Yusuf');
            $siblingEntry = $this->guardian($sibling, $parent->contact, confirmed: $there['confirmed'] ?? true, consent: $there['consent']);

            if ($there['left'] ?? false) {
                $siblingEntry->forceFill(['left_on' => '2026-09-20'])->save();
            }
        }

        $before = $this->rosterSnapshot();
        $siblingWas = $siblingEntry === null ? null : (array) DB::table('group_memberships')->where('id', $siblingEntry->id)->first();

        $preview = $this->previewMove($student, $this->second, self::TODAY)->assertOk();
        $answer = $this->move($student, $this->second, self::TODAY, ['expected_consent' => $preview->json('data.expected_consent')])->assertOk();

        $copy = GroupMembership::where('group_id', $this->second->id)->where('contact_id', $parent->contact_id)
            ->where('guardian_of_contact_id', $student->contact_id)->sole();

        if ($carried !== null) {
            $preview->assertJsonPath("data.guardians.consent_carried.{$carried}", 1)
                ->assertJsonPath('data.guardians.consent_not_carried', []);
            $this->assertSame($this->rawConsent($parent->id), $this->rawConsent($copy->id));
            $this->assertSame($this->first->id, (int) $copy->consent_carried_from_group_id);
        } else {
            $named = [['guardian' => 'Huda Guardian', 'holds' => $holds]];
            $preview->assertJsonPath('data.guardians.consent_carried', ['media' => 0, 'feed' => 0])
                ->assertJsonPath('data.guardians.consent_not_carried', $named)
                ->assertJsonPath('data.expected_consent', 'm0f0s1n0e0');
            $answer->assertJsonPath('data.consent_not_carried', $named);

            // "As it is, or not at all": never a narrowed copy. The new entry is blank and unmarked.
            $this->assertSame(['consent_granted_at' => null, 'consent_scope' => null], $this->rawConsent($copy->id));
            $this->assertNull($copy->consent_carried_from_group_id);
            $this->assertTrue($copy->isConfirmed(), 'the confirmation still travels');

            [$told, $done] = $holds === 'feed'
                ? [
                    'Huda Guardian is already in 2nd Grade for another child, with consent for the class story only: '
                        .'photograph consent is not carried. Record it in 2nd Grade if the family agrees.',
                    "Huda Guardian's photograph consent was not carried: they are already in 2nd Grade for another child, "
                        .'with consent for the class story only. Record it in 2nd Grade if the family agrees.',
                ]
                : [
                    'Huda Guardian is already in 2nd Grade for another child, with no consent recorded there: not carried. '
                        .'Record it in 2nd Grade if the family agrees.',
                    "Huda Guardian's consent was not carried: they are already in 2nd Grade for another child, with no "
                        .'consent recorded there. Record it in 2nd Grade if the family agrees.',
                ];

            $this->assertContains($told, $preview->json('data.lines'));
            $this->assertContains($done, $answer->json('data.lines'));
            $this->assertStringNotContainsString('Consent is carried', $this->said($preview));
            $this->assertStringNotContainsString("Tell 2nd Grade's teacher", $this->said($answer));
        }

        // The other child's entry is outside the move: not one column of it changed.
        if ($siblingEntry !== null) {
            $this->assertSame($siblingWas, (array) DB::table('group_memberships')->where('id', $siblingEntry->id)->first());
        }

        $this->assertNothingWasDestroyed($before);
    }

    #[Test]
    public function a_guardian_with_nothing_to_carry_is_told_apart_by_whether_the_class_story_already_reaches_them(): void
    {
        $student = $this->enrol($this->first, 'Maryam');
        $nothing = $this->guardian($student, 'Huda');
        $receives = $this->guardian($student, 'Gamal');
        $blankThere = $this->guardian($student, 'Nadia');

        $sibling = $this->enrol($this->second, 'Yusuf');
        $this->guardian($sibling, $receives->contact, consent: 'feed');
        $this->guardian($sibling, $blankThere->contact);
        $before = $this->rosterSnapshot();

        $preview = $this->previewMove($student, $this->second, self::TODAY)->assertOk()
            ->assertJsonPath('data.guardians.consent_none_recorded', 2)
            ->assertJsonPath('data.guardians.consent_none_but_receives', 1)
            // Both kinds are "nothing on record" to the fingerprint.
            ->assertJsonPath('data.expected_consent', 'm0f0s0n3e0');

        $this->assertContains(
            '2 guardians have no consent on record in 1st Grade, so nothing is carried for them. They receive nothing '
                ."from 2nd Grade's class story, class-wide conversations and class files until consent is recorded "
                .'there. Files and conversations about their own child still reach them.',
            $preview->json('data.lines'),
        );
        $this->assertContains(
            '1 guardian has no consent on record in 1st Grade for Maryam Student, so nothing is carried for Maryam '
                ."Student. They already receive 2nd Grade's class story through another child there. The weekly points "
                .'email about Maryam Student does not reach them until consent is recorded on their entry for Maryam '
                .'Student in 2nd Grade.',
            $preview->json('data.lines'),
        );

        $this->move($student, $this->second, self::TODAY)->assertOk()
            ->assertJsonPath('data.consent_none_recorded', 2)
            ->assertJsonPath('data.consent_none_but_receives', 1);

        foreach ([$nothing, $receives, $blankThere] as $entry) {
            $copy = GroupMembership::where('group_id', $this->second->id)->where('contact_id', $entry->contact_id)
                ->where('guardian_of_contact_id', $student->contact_id)->sole();
            $this->assertFalse($copy->consentColumnsAreSet(), 'consent was written for an adult who has none on record');
        }

        $this->assertNothingWasDestroyed($before);
    }

    #[Test]
    public function the_preview_says_what_the_class_still_holds_and_who_else_is_in_it(): void
    {
        $story = fn (Group $class, array $with = []) => \App\Models\GroupPost::factory()->create(
            ['masjid_id' => $this->school->id, 'group_id' => $class->id] + $with,
        );
        $photograph = fn (\App\Models\GroupPost $post) => $post->attachments()->create([
            'masjid_id' => $this->school->id, 'disk' => 'private', 'path' => 'group-posts/'.uniqid().'.jpg',
            'original_name' => 'photo.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 1024,
        ]);

        // The second class was emptied by its own move-up: no current student,
        // and three stories it still keeps, one of them with a photograph.
        $gone = $this->enrol($this->second, 'Former');
        $gone->markLeftByStaff($this->admin, '2026-09-20')->save();
        $story($this->second);
        $story($this->second);
        $photograph($story($this->second));
        // Not kept, or not out: a deleted story and one scheduled for later.
        $photograph($story($this->second))->post->delete();
        $photograph($story($this->second, ['published_at' => now()->addDays(3)]));

        // The third class has two current students and no story.
        $third = $this->makeClass('3rd Grade');
        $this->enrol($third, 'Layla');
        $this->enrol($third, 'Bilal');

        $student = $this->enrol($this->first, 'Maryam');
        $this->guardian($student, 'Huda', consent: 'media');

        $held = '2nd Grade still holds 1 story with photographs or videos from before this move. They show students who '
            .'were in 2nd Grade then, and this guardian will see them.';
        $others = "3rd Grade has 2 other students now, so its photographs show other families' children too.";

        $emptied = $this->previewMove($student, $this->second, self::TODAY)->assertOk()
            ->assertJsonPath('data.new_class_holds', ['stories' => 3, 'with_media' => 1])
            ->assertJsonPath('data.others_in_new_class', 0);
        $this->assertContains($held, $emptied->json('data.lines'));
        $this->assertStringNotContainsString('other student', $this->said($emptied));

        $full = $this->previewMove($student, $third, self::TODAY)->assertOk()
            ->assertJsonPath('data.new_class_holds', ['stories' => 0, 'with_media' => 0])
            ->assertJsonPath('data.others_in_new_class', 2);
        $this->assertContains($others, $full->json('data.lines'));
        $this->assertStringNotContainsString('still holds', $this->said($full));

        // Neither line is about a guardian whose consent is for the class story only.
        $other = $this->enrol($this->first, 'Yusuf');
        $this->guardian($other, 'Gamal', consent: 'feed');

        foreach ([$this->second, $third] as $class) {
            $said = $this->said($this->previewMove($other, $class, self::TODAY)->assertOk());
            $this->assertStringContainsString('Consent is carried as it is for 1 guardian, for the class story.', $said);
            $this->assertStringNotContainsString('photographs or videos from before', $said);
            $this->assertStringNotContainsString("other families' children", $said);
        }

        // The answer says it again, counted under the move's locks.
        $answer = $this->move($student, $this->second, self::TODAY)->assertOk()
            ->assertJsonPath('data.new_class_holds', ['stories' => 3, 'with_media' => 1]);
        $this->assertContains($held, $answer->json('data.lines'));
    }

    #[Test]
    public function the_previews_of_one_request_count_what_a_class_holds_once_and_a_move_counts_again(): void
    {
        $one = $this->enrol($this->first, 'Maryam');
        $two = $this->enrol($this->first, 'Yusuf');
        $this->guardian($one, 'Huda', consent: 'media');
        \App\Models\GroupPost::factory()->create(['masjid_id' => $this->school->id, 'group_id' => $this->second->id]);

        $counted = 0;
        DB::listen(function ($query) use (&$counted): void {
            if (str_contains($query->sql, '"group_posts"')) {
                $counted++;
            }
        });

        // A whole-class preview asks one mover about every student: the two
        // counts of stories are the class's, and are made once.
        $mover = app(RosterMove::class);
        $first = $mover->preview($this->first, $one, $this->second, self::TODAY);
        $this->assertSame(2, $counted);
        $second = $mover->preview($this->first, $two, $this->second, self::TODAY);
        $this->assertSame(2, $counted, 'the second preview of one request counted the class again');
        $this->assertSame(['stories' => 1, 'with_media' => 0], $first->newClassHolds);
        $this->assertSame($first->newClassHolds, $second->newClassHolds);
        $this->assertSame($first->othersInNewClass, $second->othersInNewClass);

        // The move's own decision, under its locks, never trusts what a
        // preview remembered: a story published since is counted.
        \App\Models\GroupPost::factory()->create(['masjid_id' => $this->school->id, 'group_id' => $this->second->id]);
        $counted = 0;

        $plan = $mover->move($this->first, $one, $this->second->id, self::TODAY, [], $this->admin);

        $this->assertSame(2, $counted, 'the move did not count the class again under its locks');
        $this->assertSame(['stories' => 2, 'with_media' => 0], $plan->newClassHolds);
    }

    // ------------------------------------------- what the dialog showed, again

    #[Test]
    public function a_consent_withdrawn_between_the_read_and_the_tap_is_asked_about_again_and_nothing_is_moved(): void
    {
        $student = $this->enrol($this->first, 'Maryam');
        $parent = $this->guardian($student, 'Huda', consent: 'media');

        $shown = $this->previewMove($student, $this->second, self::TODAY)->assertOk()->json('data.expected_consent');
        $this->assertSame('m1f0s0n0e0', $shown);

        // The family rings the office while the dialog is open.
        $this->withdrawConsent($parent)->assertOk();
        $before = DB::table('group_memberships')->orderBy('id')->get()->toArray();

        $this->move($student, $this->second, self::TODAY, ['expected_consent' => $shown])->assertStatus(409)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', RosterMoveRefused::LOOK_AGAIN);

        $this->assertEquals($before, DB::table('group_memberships')->orderBy('id')->get()->toArray());

        // The next read says what is true now, and that is what the tap echoes.
        $now = $this->previewMove($student, $this->second, self::TODAY)->assertOk()->json('data.expected_consent');
        $this->assertSame('m0f0s0n1e0', $now);
        $this->move($student, $this->second, self::TODAY, ['expected_consent' => $now])->assertOk();

        $this->assertFalse($this->entryIn($this->second, $parent)->consentColumnsAreSet());
    }

    #[Test]
    public function the_rule_for_bucks_is_accepted_and_handed_on_and_says_nothing_yet(): void
    {
        $student = $this->enrol($this->first, 'Maryam');

        // No move carries a balance yet, so the preview has no rule to show...
        $this->previewMove($student, $this->second, self::TODAY)->assertOk()
            ->assertJsonPath('data.expected_bucks_rule', null);

        // ...a value that is not one of the three is a malformed request...
        $this->move($student, $this->second, self::TODAY, ['expected_bucks_rule' => 'everything'])
            ->assertStatus(422)->assertJsonPath('status', 'failed');
        $this->move($student, $this->second, self::TODAY, ['expected_consent' => str_repeat('m', 33)])
            ->assertStatus(422)->assertJsonPath('status', 'failed');

        // ...and an empty echo, which is what a form-encoded body sends for null, moves.
        $this->move($student, $this->second, self::TODAY, ['expected_bucks_rule' => '', 'expected_consent' => 'm0f0s0n0e0'])
            ->assertOk();
    }

    // -------------------------------------------- what only a class run may set

    #[Test]
    public function siblings_moved_in_one_run_are_judged_against_the_class_as_it_stood_before_the_run(): void
    {
        $parent = $this->makePerson('Huda', 'Guardian');
        $pair = function () use ($parent): array {
            $blank = $this->enrol($this->first, $this->makePerson('Maryam', 'Student'));
            $photographs = $this->enrol($this->first, $this->makePerson('Yusuf', 'Student'));
            $this->guardian($blank, $parent);
            $withConsent = $this->guardian($photographs, $parent, consent: 'media');

            return [$blank, $photographs, $withConsent];
        };
        $copyFor = fn (Group $class, GroupMembership $student): GroupMembership => GroupMembership::where('group_id', $class->id)
            ->where('contact_id', $parent->id)->where('guardian_of_contact_id', $student->contact_id)->sole();

        // TWO SINGLE MOVES are two acts. The blank entry the first one makes
        // stands in the class when the second is decided, and caps it. Less is
        // carried, never more, and the guardian is named.
        [$blank, $photographs] = $pair();
        $this->move($blank, $this->second, self::TODAY)->assertOk();
        $this->move($photographs, $this->second, self::TODAY)->assertOk()
            ->assertJsonPath('data.consent_not_carried', [['guardian' => 'Huda Guardian', 'holds' => 'none']]);
        $this->assertFalse($copyFor($this->second, $photographs)->consentColumnsAreSet());

        // ONE RUN reads the highest roster row id once, before its first
        // student, and hands it to every move. The entry the first child's
        // move made is above it: it did not stand in the class before the run.
        $third = $this->makeClass('3rd Grade');
        GroupMembership::where('group_id', $this->second->id)->get()->each->delete();
        GroupMembership::where('group_id', $this->first->id)->get()->each->delete();
        [$blank, $photographs] = $pair();
        $standing = (int) DB::table('group_memberships')->max('id');

        $this->moveByService($blank, $third, ['standing_before_id' => $standing]);
        $plan = $this->moveByService($photographs, $third, ['standing_before_id' => $standing]);

        $this->assertSame(['media' => 1, 'feed' => 0], $plan->consentCarried);
        $this->assertSame('media', $copyFor($third, $photographs)->consent_scope);
        $this->assertFalse($copyFor($third, $blank)->consentColumnsAreSet());

        // In the other order the result is the same.
        $fourth = $this->makeClass('4th Grade');
        GroupMembership::where('group_id', $this->first->id)->get()->each->delete();
        [$blank, $photographs] = $pair();
        $standing = (int) DB::table('group_memberships')->max('id');

        $this->moveByService($photographs, $fourth, ['standing_before_id' => $standing]);
        $this->moveByService($blank, $fourth, ['standing_before_id' => $standing]);

        $this->assertSame('media', $copyFor($fourth, $photographs)->consent_scope);
        $this->assertFalse($copyFor($fourth, $blank)->consentColumnsAreSet());
    }

    #[Test]
    public function an_entry_withdrawn_after_a_carry_caps_a_later_carry_whatever_its_id(): void
    {
        $parent = $this->makePerson('Huda', 'Guardian');
        $first = $this->enrol($this->first, $this->makePerson('Maryam', 'Student'));
        $second = $this->enrol($this->first, $this->makePerson('Yusuf', 'Student'));
        $this->guardian($first, $parent, consent: 'media');
        $this->guardian($second, $parent, consent: 'media');

        $standing = (int) DB::table('group_memberships')->max('id');

        // The run carries the first child's consent...
        $this->moveByService($first, $this->second, ['standing_before_id' => $standing]);
        $carried = GroupMembership::where('group_id', $this->second->id)->where('contact_id', $parent->id)->sole();
        $this->assertGreaterThan($standing, $carried->id);
        $this->assertSame('media', $carried->consent_scope);

        // ...the office withdraws it on the new entry a moment later...
        $this->withdrawConsent($carried)->assertOk();

        // ...and the second child's move, in the same run, must not carry it again.
        $plan = $this->moveByService($second, $this->second, ['standing_before_id' => $standing]);

        $this->assertSame(['media' => 0, 'feed' => 0], $plan->consentCarried);
        $this->assertSame([['guardian' => 'Huda Guardian', 'holds' => 'none']], $plan->consentNotCarriedForSibling);
        $this->assertFalse(
            GroupMembership::where('group_id', $this->second->id)->where('contact_id', $parent->id)
                ->where('guardian_of_contact_id', $second->contact_id)->sole()->consentColumnsAreSet(),
            'a consent the family had just withdrawn in that class was carried into it again',
        );
    }

    #[Test]
    public function what_only_a_class_run_may_set_cannot_be_sent_by_a_request(): void
    {
        $this->logLikeProduction();

        $parent = $this->makePerson('Huda', 'Guardian');
        $blank = $this->enrol($this->first, $this->makePerson('Maryam', 'Student'));
        $photographs = $this->enrol($this->first, $this->makePerson('Yusuf', 'Student'));
        $this->guardian($blank, $parent);
        $this->guardian($photographs, $parent, consent: 'media');

        $standing = (int) DB::table('group_memberships')->max('id');
        $this->move($blank, $this->second, self::TODAY)->assertOk();

        // `standing_before_id` would switch the cap off; `today` would allow a
        // day that has not come; `run` would write a run into the history.
        $internal = ['standing_before_id' => $standing, 'today' => '2026-10-09', 'attempts' => 9, 'run' => 'typed'];

        $this->move($photographs, $this->second, '2026-10-05', $internal)->assertStatus(422)
            ->assertJsonPath('message', 'The first day in the new class cannot be in the future.');

        Sanctum::actingAs($this->admin);
        $this->getJson($this->moveUrl($photographs).'?'.http_build_query(['to_group_id' => $this->second->id, 'moved_on' => '2026-10-05'] + $internal))
            ->assertOk()->assertJsonPath('data.can_move', false);
        $this->getJson($this->moveUrl($photographs).'?'.http_build_query(['to_group_id' => $this->second->id] + $internal))
            ->assertOk()->assertJsonPath('data.guardians.consent_not_carried', [['guardian' => 'Huda Guardian', 'holds' => 'none']]);

        $this->move($photographs, $this->second, self::TODAY, $internal)->assertOk()
            ->assertJsonPath('data.consent_carried', ['media' => 0, 'feed' => 0])
            ->assertJsonPath('data.consent_not_carried', [['guardian' => 'Huda Guardian', 'holds' => 'none']]);

        $lines = $this->loggedLines('laravel.log', 'roster.move');
        $this->assertStringContainsString('"run":null', end($lines));

        // No Request class has a rule for any of them, and the controller
        // names every option it passes: none is read from the request.
        foreach ([new \App\Http\Requests\Admin\Groups\PreviewMoveRequest(), new \App\Http\Requests\Admin\Groups\MoveStudentRequest()] as $request) {
            $this->assertSame([], array_intersect(array_keys($request->rules()), array_keys($internal)));
        }

        $controller = file_get_contents(app_path('Http/Controllers/AdminDashboard/GroupMoveController.php'));
        foreach (array_keys($internal) as $key) {
            $this->assertStringNotContainsString("'{$key}'", $controller);
        }
        $this->assertStringNotContainsString('$request->all()', $controller);
        $this->assertStringNotContainsString('$request->validated()', $controller);
    }

    #[Test]
    public function a_run_hands_every_move_one_day_and_one_attempt(): void
    {
        $student = $this->enrol($this->first, 'Maryam');
        $mover = app(RosterMove::class);

        // The school's day, read once by the run: a move decided on it does
        // not read the clock again.
        $refused = fn (array $options): ?string => rescue(
            fn () => $mover->preview($this->first, $student, $this->second, '2026-10-05', $options) ? null : null,
            fn (\Throwable $e): string => $e->getMessage(),
            false,
        );

        $this->assertSame('The first day in the new class cannot be in the future.', $refused([]));
        $this->assertNull($refused(['today' => '2026-10-05']));

        $plan = $mover->move($this->first, $student, $this->second->id, '2026-10-05', ['today' => '2026-10-05', 'attempts' => 1], $this->admin);
        $this->assertSame('2026-10-05', $plan->movedOn);
        $this->assertSame('2026-10-05', GroupMembership::findOrFail($plan->membershipId)->joined_at->toDateString());

        // One attempt inside a run, three for a single move: the count is the
        // transaction's own, read from the option and nowhere else.
        $source = file_get_contents(app_path('Support/RosterMove.php'));
        $this->assertStringContainsString("\$attempts = max(1, (int) (\$options['attempts'] ?? self::ATTEMPTS));", $source);
        $this->assertSame(1, preg_match('/return \$plan;\s+\},\s+\$attempts,\s+\)\);/', $source));
        $this->assertSame(3, RosterMove::ATTEMPTS);
    }

    #[Test]
    public function the_checks_that_are_the_same_for_a_whole_class_are_one_public_rule(): void
    {
        $halaqa = $this->makeClass('Evening circle', ['kind' => Group::KIND_HALAQA]);
        $off = $this->makeClass('Switched off', ['is_active' => false]);
        $other = $this->makeClass('Their class', school: $this->makeSchool());

        $refusal = function (?Group $to, string $on = self::TODAY, ?string $name = null): ?array {
            try {
                RosterMove::refuseUnlessClassesAndDayAllow($this->first, $to, $on, self::TODAY, $name);
            } catch (RosterMoveRefused $refused) {
                return [$refused->status(), $refused->getMessage()];
            }

            return null;
        };

        $this->assertNull($refusal($this->second));
        $this->assertSame([422, "Choose one of this school's classes."], $refusal(null));
        $this->assertSame([422, "Choose one of this school's classes."], $refusal($other));
        // For a class the sentence is about the class; for one student, as it always was.
        $this->assertSame([422, '1st Grade cannot be moved into itself.'], $refusal($this->first));
        $this->assertSame([422, 'Maryam Student is already in this class.'], $refusal($this->first, name: 'Maryam Student'));
        $this->assertSame([422, 'Students can only be moved between classes.'], $refusal($halaqa));
        $this->assertSame([422, 'Switched off is not running: it is switched off or has ended. Choose a class that is running.'], $refusal($off));
        $this->assertSame([422, 'The first day in the new class cannot be in the future.'], $refusal($this->second, '2026-10-05'));

        // `decide()` holds no second copy of them.
        $source = file_get_contents(app_path('Support/RosterMove.php'));
        foreach (["Choose one of this school's classes.", 'Students can only be moved between classes.', 'cannot be in the future'] as $sentence) {
            $this->assertSame(1, substr_count($source, $sentence), "the rule \"{$sentence}\" is written twice");
        }
    }

    // ================================================ nothing withdrawn comes back
    //
    // R10. After a carry there are two entries for one adult and child, a
    // source and a copy. A move that would re-open either one with its consent
    // is refused while the family has since withdrawn or narrowed on the other.

    /** The remedy every such refusal ends with, for a move of Maryam into `$class`. */
    private function remedy(string $class): string
    {
        return "Nothing was moved. Open {$class} and withdraw that consent on its roster first (the Consent button on the "
            ."guardian's row), then move Maryam Student again. If the family still agrees for {$class}, record it there "
            .'after the move.';
    }

    /** A move that is refused on both verbs with one sentence, and changes no row. */
    private function assertRefusedForConsent(GroupMembership $row, Group $to, string $sentence, GroupMembership $entry): void
    {
        $before = DB::table('group_memberships')->orderBy('id')->get()->toArray();
        $message = $sentence."\n".$this->remedy($to->name);
        // "Open {class}" lands on the row the remedy is about.
        $open = ['id' => $to->id, 'name' => $to->name, 'membership_id' => $entry->id];

        $this->previewMove($row, $to, self::TODAY)->assertOk()
            ->assertJsonPath('data.can_move', false)
            ->assertJsonPath('data.refusal', $message)
            ->assertJsonPath('data.open_group', $open);

        $this->move($row, $to, self::TODAY)->assertStatus(409)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', $message)
            ->assertJsonPath('open_group', $open);

        $this->assertEquals($before, DB::table('group_memberships')->orderBy('id')->get()->toArray(), 'a refused move changed a row');
    }

    private function movedTo(GroupMembership $row, Group $to): GroupMembership
    {
        return GroupMembership::findOrFail($this->move($row, $to, self::TODAY)->assertOk()->json('data.membership_id'));
    }

    #[Test]
    public function going_back_is_refused_while_the_carried_copy_was_withdrawn_and_goes_through_once_the_old_consent_is_too(): void
    {
        $student = $this->enrol($this->first, 'Maryam');
        $parent = $this->guardian($student, 'Huda', consent: 'media');

        $there = $this->movedTo($student, $this->second);
        $copy = $this->entryIn($this->second, $parent);

        // The family withdraws in the new class. The copy keeps its marker.
        $this->withdrawConsent($copy)->assertOk();
        $this->assertFalse($copy->fresh()->consentColumnsAreSet());
        $this->assertSame($this->first->id, (int) $copy->fresh()->consent_carried_from_group_id);

        // Going back would re-open the first class's entry with the consent it still holds.
        $this->assertRefusedForConsent(
            $there, $this->first,
            'Huda Guardian withdrew consent in 2nd Grade after it had been carried there from 1st Grade. The consent '
                .'recorded in 1st Grade (class story and photographs, recorded 4 Sep 2026) would come back into force.',
            $parent,
        );
        $this->assertTrue($parent->fresh()->hasConsent(), 'a refused move wrote to a consent column');

        // THE REMEDY: the office withdraws the old one on its roster (the
        // entry has left; withdrawal checks no leaving date), then moves.
        $this->withdrawConsent($parent)->assertOk();
        $before = $this->rosterSnapshot();

        $back = $this->move($there, $this->first, self::TODAY)->assertOk()
            ->assertJsonPath('data.path', RosterMovePlan::RETURNED)
            ->assertJsonPath('data.consent_in_force_again', []);

        $this->assertStringNotContainsString('in force again', $this->said($back));
        $this->assertNull($parent->fresh()->left_on);
        $this->assertFalse($parent->fresh()->hasConsent(), 'the withdrawn consent came back');
        $this->assertNothingWasDestroyed($before);

        // NO MEMORY, pinned as designed. The family is asked again and agrees
        // for the first class; the blank, marked copy still exists in the
        // second. Out and back is refused again: it fails towards asking.
        $this->recordConsent($parent->fresh(), 'media', '2026-10-04')->assertOk();
        $again = $this->movedTo($student->fresh(), $this->second);
        $this->assertFalse($copy->fresh()->consentColumnsAreSet(), 'a move wrote consent onto an entry the class already held');

        $this->assertRefusedForConsent(
            $again, $this->first,
            'Huda Guardian withdrew consent in 2nd Grade after it had been carried there from 1st Grade. The consent '
                .'recorded in 1st Grade (class story and photographs, recorded 4 Oct 2026) would come back into force.',
            $parent,
        );
    }

    #[Test]
    public function a_copy_is_not_reopened_while_its_source_was_withdrawn_or_narrowed(): void
    {
        $third = $this->makeClass('3rd Grade');
        $carriedThere = "Huda Guardian's consent in 2nd Grade (class story and photographs, recorded 4 Sep 2026) was carried "
            .'there from 1st Grade, and consent in 1st Grade has since been withdrawn. It would come back into force in 2nd Grade.';

        // Carried into the second class, then the student goes back to the first.
        $student = $this->enrol($this->first, 'Maryam');
        $parent = $this->guardian($student, 'Huda', consent: 'media');
        $there = $this->movedTo($student, $this->second);
        $copy = $this->entryIn($this->second, $parent);
        $this->move($there, $this->first, self::TODAY)->assertOk();
        $this->assertNotNull($copy->fresh()->left_on);

        // NARROWED at the source: photographs become the class story only.
        $this->recordConsent($parent->fresh(), 'feed', '2026-09-04')->assertOk();
        $this->assertRefusedForConsent(
            $student->fresh(), $this->second,
            "Huda Guardian's consent in 2nd Grade for the class story and photographs was carried there from 1st Grade, "
                .'and consent in 1st Grade is now for the class story only. The photograph consent would come back into '
                .'force in 2nd Grade.',
            $copy,
        );

        // WITHDRAWN at the source.
        $this->withdrawConsent($parent->fresh())->assertOk();
        $this->assertRefusedForConsent($student->fresh(), $this->second, $carriedThere, $copy);

        // A move to a class that holds no entry for them is not about this at all.
        $this->previewMove($student->fresh(), $third, self::TODAY)->assertOk()->assertJsonPath('data.can_move', true);

        // The remedy is the same act: withdraw the copy on the second class's roster, then move.
        $this->withdrawConsent($copy->fresh())->assertOk();
        $before = $this->rosterSnapshot();

        $this->move($student->fresh(), $this->second, self::TODAY)->assertOk()
            ->assertJsonPath('data.path', RosterMovePlan::RETURNED)
            ->assertJsonPath('data.consent_in_force_again', [])
            ->assertJsonPath('data.consent_carried', ['media' => 0, 'feed' => 0]);

        $this->assertFalse($copy->fresh()->consentColumnsAreSet());
        $this->assertNothingWasDestroyed($before);
    }

    #[Test]
    public function a_source_that_has_left_still_holds_its_record_and_one_that_is_gone_is_named(): void
    {
        $third = $this->makeClass('3rd Grade');
        $student = $this->enrol($this->first, 'Maryam');
        $parent = $this->guardian($student, 'Huda', consent: 'media');

        // First to second (the copy is marked "from the first"), then on to the third.
        $inSecond = $this->movedTo($student, $this->second);
        $copy = $this->entryIn($this->second, $parent);
        $inThird = $this->movedTo($inSecond, $third);

        // The source in the first class has LEFT and still holds its record:
        // the copy in the second holds no more than it, so nothing is refused.
        $this->assertNotNull($parent->fresh()->left_on);
        $kept = $this->previewMove($inThird, $this->second, self::TODAY)->assertOk()
            ->assertJsonPath('data.can_move', true)
            ->assertJsonPath('data.guardians.consent_in_force_again', [[
                'guardian' => 'Huda Guardian', 'scope' => 'media', 'recorded_on' => '2026-09-04',
                'reopens' => true, 'source_gone_in' => null,
            ]]);
        $this->assertContains(
            'Consent already recorded in 2nd Grade is in force again: Huda Guardian (class story and photographs, '
                ."recorded 4 Sep 2026). To withdraw a family's consent completely, withdraw it in both classes.",
            $kept->json('data.lines'),
        );

        // The place in the first class is removed, and the source entry with
        // it. There is nothing left to check the copy against: the move goes
        // ahead and the guardian is NAMED, with what to do.
        $this->removeFromRoster($student->fresh())->assertOk();
        $this->assertNull($parent->fresh());
        $before = $this->rosterSnapshot();

        $named = "Huda Guardian's consent in 2nd Grade was carried there from 1st Grade earlier, and 1st Grade no longer "
            .'holds an entry for them, so it cannot be checked against it. It is in force again in 2nd Grade (class story '
            .'and photographs, recorded 4 Sep 2026). Withdraw it in 2nd Grade if the family did not mean it for this class.';

        $preview = $this->previewMove($inThird, $this->second, self::TODAY)->assertOk()
            ->assertJsonPath('data.can_move', true)
            ->assertJsonPath('data.guardians.consent_in_force_again.0.source_gone_in', '1st Grade');
        $this->assertContains($named, $preview->json('data.lines'));
        $this->assertStringNotContainsString('Consent already recorded in 2nd Grade is in force again', $this->said($preview));

        $answer = $this->move($inThird, $this->second, self::TODAY)->assertOk();
        $this->assertContains($named, $answer->json('data.lines'));
        $this->assertTrue($copy->fresh()->hasConsent());
        $this->assertNull($copy->fresh()->left_on);
        $this->assertNothingWasDestroyed($before);

        // A class that was deleted since is named as the roster names one.
        $this->first->delete();
        $onward = $this->movedTo($inSecond->fresh(), $third);
        $this->assertStringContainsString(
            "Huda Guardian's consent in 2nd Grade was carried there from a class that was removed earlier, and a class "
                .'that was removed no longer holds an entry for them',
            $this->said($this->previewMove($onward, $this->second, self::TODAY)->assertOk()),
        );
    }

    #[Test]
    public function down_a_chain_the_withdrawn_link_is_refused_and_a_return_past_it_is_named(): void
    {
        $third = $this->makeClass('3rd Grade');
        $student = $this->enrol($this->first, 'Maryam');
        $parent = $this->guardian($student, 'Huda', consent: 'feed');

        // First to second to third, carried each time; the marker names the
        // class each copy was made from.
        $inSecond = $this->movedTo($student, $this->second);
        $inThird = $this->movedTo($inSecond, $third);
        $second = $this->entryIn($this->second, $parent);
        $last = $this->entryIn($third, $parent);

        $this->assertSame($this->first->id, (int) $second->consent_carried_from_group_id);
        $this->assertSame($this->second->id, (int) $last->consent_carried_from_group_id);
        $this->assertSame($this->rawConsent($parent->id), $this->rawConsent($last->id));

        // Withdrawn in the third class.
        $this->withdrawConsent($last)->assertOk();

        // Back to the SECOND, whose copy the third's was made from: refused.
        $this->assertRefusedForConsent(
            $inThird, $this->second,
            'Huda Guardian withdrew consent in 3rd Grade after it had been carried there from 2nd Grade. The consent '
                .'recorded in 2nd Grade (class story, recorded 4 Sep 2026) would come back into force.',
            $second,
        );

        // Straight back to the FIRST: its own copy (in the second) is not
        // blank, so nothing fires. The limit is the design's, pinned: the
        // consent comes back into force and the office reads whose.
        $back = $this->move($inThird, $this->first, self::TODAY)->assertOk()
            ->assertJsonPath('data.path', RosterMovePlan::RETURNED);
        $this->assertStringContainsString(
            'Consent already recorded in 1st Grade is in force again: Huda Guardian (class story, recorded 4 Sep 2026).',
            $this->said($back),
        );
        $this->assertTrue($parent->fresh()->hasConsent());
    }

    #[Test]
    public function what_the_refusal_cannot_see_once_the_withdrawn_copy_is_removed(): void
    {
        $third = $this->makeClass('3rd Grade');
        $student = $this->enrol($this->first, 'Maryam');
        $parent = $this->guardian($student, 'Huda', consent: 'media');

        $inSecond = $this->movedTo($student, $this->second);
        $this->withdrawConsent($this->entryIn($this->second, $parent))->assertOk();

        // While the blank, marked copy exists a return is refused...
        $this->previewMove($inSecond, $this->first, self::TODAY)->assertOk()->assertJsonPath('data.can_move', false);

        // ...the student moves on, and the place that held the copy is removed
        // (Remove takes the guardian entries beside it, and a blank one does
        // not refuse). The state the refusal reads is gone with it.
        $inThird = $this->movedTo($inSecond, $third);
        $this->removeFromRoster($inSecond->fresh())->assertOk();
        $this->assertSame(0, GroupMembership::where('group_id', $this->second->id)->count());

        $back = $this->move($inThird, $this->first, self::TODAY)->assertOk();

        $this->assertStringContainsString(
            'Consent already recorded in 1st Grade is in force again: Huda Guardian (class story and photographs, recorded 4 Sep 2026).',
            $this->said($back),
        );
        $this->assertTrue($parent->fresh()->hasConsent());
    }

    #[Test]
    public function the_rule_about_consent_coming_back_is_one_pure_function_read_from_both_sides(): void
    {
        $student = $this->enrol($this->first, 'Maryam');
        $third = $this->makeClass('3rd Grade');
        $huda = $this->makePerson('Huda', 'Guardian');
        $closed = ['left_on' => '2026-09-20'];

        // Three entries in the first class: closed with consent (the one that
        // would re-open), open with consent, and closed with none.
        $source = $this->guardian($student, 'Gamal', consent: 'media');
        $open = $this->guardian($student, 'Nadia', consent: 'media');
        $blank = $this->guardian($student, 'Samira');
        GroupMembership::whereIn('id', [$source->id, $blank->id])->update($closed);
        $source = $source->fresh();
        $blank = $blank->fresh();

        // An entry in the second class for the same child, held by Gamal unless said otherwise.
        $elsewhere = fn (array $with): GroupMembership => tap(new GroupMembership([
            'masjid_id' => $this->school->id, 'group_id' => $this->second->id, 'contact_id' => $source->contact_id,
            'role' => GroupMembership::ROLE_GUARDIAN, 'guardian_of_contact_id' => $student->contact_id,
        ]), fn (GroupMembership $e) => $e->confirmedByStaff($this->admin)->forceFill($with));
        $withdrawnCopy = ['consent_carried_from_group_id' => $this->first->id];

        // A closed entry with consent and no carry on either side stands.
        $this->assertSame([], RosterMove::consentComingBack(collect([$source]), collect()));

        // Its copy, withdrawn where it was carried.
        $this->assertSame(
            [$source->id => [RosterMove::COPY_WITHDRAWN, $this->second->id]],
            RosterMove::consentComingBack(collect([$source]), collect([$elsewhere($withdrawnCopy)])),
        );

        // ONLY AN ENTRY THAT WOULD RE-OPEN WITH CONSENT IS LOOKED AT. One that
        // is open already, or holds none, is not, even with its own copy
        // withdrawn elsewhere: nothing would come back into force through it.
        foreach ([$open, $blank] as $notReopening) {
            $this->assertSame([], RosterMove::consentComingBack(
                collect([$notReopening]),
                collect([$elsewhere($withdrawnCopy + ['contact_id' => $notReopening->contact_id])]),
            ));
        }
        // And a student's own place never is, whatever bytes it holds.
        $place = tap($source->replicate(), fn (GroupMembership $m) => $m->forceFill(['role' => GroupMembership::ROLE_MEMBER]));
        $this->assertSame([], RosterMove::consentComingBack(collect([$place]), collect([$elsewhere($withdrawnCopy)])));
        // A blank entry that was never a copy of it says nothing; nor does a marker naming another class.
        $this->assertSame([], RosterMove::consentComingBack(collect([$source]), collect([$elsewhere([])])));
        $this->assertSame([], RosterMove::consentComingBack(collect([$source]), collect([$elsewhere(['consent_carried_from_group_id' => $third->id])])));
        // Another adult's withdrawal, or one about another child, is not this entry's.
        $this->assertSame([], RosterMove::consentComingBack(collect([$source]), collect([
            $elsewhere(['consent_carried_from_group_id' => $this->first->id, 'contact_id' => $huda->id]),
            $elsewhere(['consent_carried_from_group_id' => $this->first->id, 'guardian_of_contact_id' => $huda->id]),
        ])));

        // The copy's side: its own marker names the class of its source.
        $copy = tap($source->replicate(), fn (GroupMembership $c) => $c->forceFill(['consent_carried_from_group_id' => $this->second->id]));
        $copy->id = $source->id;
        $held = fn (?string $scope): array => $scope === null
            ? []
            : ['consent_scope' => $scope, 'consent_granted_at' => '2026-09-04 10:00:00'];

        $this->assertSame([$copy->id => [RosterMove::SOURCE_GONE, $this->second->id]], RosterMove::consentComingBack(collect([$copy]), collect()));
        $this->assertSame([$copy->id => [RosterMove::SOURCE_WITHDRAWN, $this->second->id]], RosterMove::consentComingBack(collect([$copy]), collect([$elsewhere($held(null))])));
        $this->assertSame([$copy->id => [RosterMove::SOURCE_NARROWED, $this->second->id]], RosterMove::consentComingBack(collect([$copy]), collect([$elsewhere($held('feed'))])));
        $this->assertSame([], RosterMove::consentComingBack(collect([$copy]), collect([$elsewhere($held('media'))])));
        // A source that has left still holds its record.
        $this->assertSame([], RosterMove::consentComingBack(collect([$copy]), collect([$elsewhere($held('media') + $closed)])));
        // A copy for the class story is not "more" than a source that now holds photographs.
        $feedCopy = tap($copy->replicate(), fn (GroupMembership $c) => $c->forceFill(['consent_scope' => 'feed']));
        $feedCopy->id = $copy->id;
        $this->assertSame([], RosterMove::consentComingBack(collect([$feedCopy]), collect([$elsewhere($held('media'))])));
    }

    // ------------------------------------------------------ the deploy window

    #[Test]
    public function before_the_marker_column_exists_a_move_is_refused_with_a_sentence_and_everything_else_works(): void
    {
        $this->logLikeProduction();

        $student = $this->enrol($this->first, 'Maryam');
        $parent = $this->guardian($student, 'Huda', consent: 'media');
        $moved = $this->enrol($this->first, 'Yusuf');
        $this->guardian($moved, 'Gamal', consent: 'feed');
        $this->move($moved, $this->second, self::TODAY)->assertOk();

        // bin/deploy serves the new code, then migrates.
        Schema::table('group_memberships', fn ($table) => $table->dropColumn('consent_carried_from_group_id'));
        GroupMembership::forgetConsentCarryReady();

        $this->assertFalse(GroupMembership::consentCarryReady());
        $this->assertFalse(RosterMove::ready());

        // The guard is the FIRST statement of the read and of the write, so
        // nothing is read, locked or written before it answers.
        $source = file_get_contents(app_path('Support/RosterMove.php'));
        foreach (['public function preview(', 'public function move('] as $verb) {
            $this->assertMatchesRegularExpression(
                '/'.preg_quote($verb, '/').'[^{]*\{\s+self::refuseUnlessReady\(\);/',
                $source,
                "{$verb} does not begin with the guard for the deploy window",
            );
        }

        $before = DB::table('group_memberships')->orderBy('id')->get()->toArray();

        $this->move($student, $this->second, self::TODAY)->assertStatus(409)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'Manara is being updated. Try this move again in a minute.');

        // ONE line per refused request, at the level production keeps, saying
        // which column is missing: a migration that failed half-way must not
        // leave moves refused "for a minute" for ever with nothing in the log.
        $lines = $this->loggedLines('laravel.log', 'roster.move.not_ready');
        $this->assertCount(1, $lines);
        $this->assertStringContainsString('.WARNING: roster.move.not_ready', $lines[0]);
        $this->assertStringContainsString('"missing":["group_memberships.consent_carried_from_group_id"]', $lines[0]);

        $this->previewMove($student, $this->second, self::TODAY)->assertOk()
            ->assertJsonPath('data.can_move', false)
            ->assertJsonPath('data.refusal', 'Manara is being updated. Try this move again in a minute.')
            ->assertJsonPath('data.lines', []);
        $this->assertCount(2, $this->loggedLines('laravel.log', 'roster.move.not_ready'));

        $this->assertEquals($before, DB::table('group_memberships')->orderBy('id')->get()->toArray());
        // Only the move made before the column went is in the history.
        $this->assertCount(1, $this->loggedLines('laravel.log', 'WARNING: roster.move {'), 'a refused move was logged as a move');

        // The roster list still answers, with no carried-from class and
        // nothing computed that would name the column.
        $roster = $this->roster($this->first)->assertOk();
        $row = collect($roster->json('data'))->firstWhere('id', $moved->id);
        $this->assertSame([], $row['moved_to_state']['consent_blocks']);
        $this->assertSame([], $row['moved_to_state']['consent_lines']);
        $this->assertArrayNotHasKey('consent_carried_from', $row);
        $this->assertArrayNotHasKey('consent_carried_from_group_id', $row);

        // A withdrawal never depends on the new column, and a record writes
        // its two columns as it always did.
        $this->withdrawConsent($parent)->assertOk();
        $this->assertFalse($parent->fresh()->consentColumnsAreSet());
        $this->recordConsent($parent->fresh(), 'feed')->assertOk()->assertJsonPath('data.consent_scope', 'feed');

        // A merge's un-confirm writes no key the table does not have.
        $parent->fresh()->unconfirm()->save();
        $this->assertFalse($parent->fresh()->isConfirmed());

        // Asked again after a short while, and remembered once it is there.
        Schema::table('group_memberships', fn ($table) => $table->unsignedBigInteger('consent_carried_from_group_id')->nullable());
        $this->assertFalse(RosterMove::ready(), 'the answer "not yet" was not remembered for the short while');
        Carbon::setTestNow(now()->addSeconds(31));
        $this->assertTrue(RosterMove::ready());

        $this->previewMove($student, $this->second, self::TODAY)->assertOk()->assertJsonPath('data.can_move', true);
    }

    #[Test]
    public function the_marker_migration_refuses_to_roll_back_while_a_carried_consent_is_on_record(): void
    {
        $migration = require database_path('migrations/2026_10_13_100000_add_consent_carried_from_group_id_to_group_memberships_table.php');

        $student = $this->enrol($this->first, 'Maryam');
        $parent = $this->guardian($student, 'Huda', consent: 'media');
        $this->move($student, $this->second, self::TODAY)->assertOk();

        try {
            $migration->down();
            $this->fail('the marker was dropped while a carried consent was on record');
        } catch (\RuntimeException $refused) {
            $this->assertStringContainsString('Refusing to roll back: 1 guardian entry is marked', $refused->getMessage());
        }

        $this->assertTrue(Schema::hasColumn('group_memberships', 'consent_carried_from_group_id'));

        // A withdrawn copy is a record too: its marker is all that says so.
        $this->withdrawConsent($this->entryIn($this->second, $parent))->assertOk();
        try {
            $migration->down();
            $this->fail('the marker was dropped while a withdrawn carried consent was on record');
        } catch (\RuntimeException) {
            $this->assertTrue(Schema::hasColumn('group_memberships', 'consent_carried_from_group_id'));
        }

        // With nothing marked it drops the column and nothing else. A plain
        // column: no foreign key and no index went with it.
        DB::table('group_memberships')->update(['consent_carried_from_group_id' => null]);
        $migration->down();
        $this->assertFalse(Schema::hasColumn('group_memberships', 'consent_carried_from_group_id'));
        $this->assertTrue(Schema::hasColumn('group_memberships', 'consent_scope'));

        $migration->up();
        $this->assertTrue(Schema::hasColumn('group_memberships', 'consent_carried_from_group_id'));
        $this->assertSame([], array_filter(
            Schema::getIndexes('group_memberships'),
            fn (array $index): bool => in_array('consent_carried_from_group_id', $index['columns'], true),
        ));
        $this->assertSame([], array_filter(
            Schema::getForeignKeys('group_memberships'),
            fn (array $key): bool => in_array('consent_carried_from_group_id', $key['columns'], true),
        ));
    }

    // ==================================================== the marker's writers

    #[Test]
    public function the_marker_is_cleared_by_a_record_kept_by_a_withdrawal_and_follows_a_consent_a_merge_clears(): void
    {
        $student = $this->enrol($this->first, 'Maryam');
        $huda = $this->guardian($student, 'Huda', consent: 'feed');
        $gamal = $this->guardian($student, 'Gamal', consent: 'media');
        $nadia = $this->guardian($student, 'Nadia', consent: 'media');
        $samira = $this->guardian($student, 'Samira', consent: 'media');
        $this->move($student, $this->second, self::TODAY)->assertOk();

        $marker = fn (GroupMembership $of): ?int => DB::table('group_memberships')
            ->where('id', $this->entryIn($this->second, $of)->id)->value('consent_carried_from_group_id');

        foreach ([$huda, $gamal, $nadia, $samira] as $entry) {
            $this->assertSame($this->first->id, $marker($entry), 'carried from the class left');
        }

        // A WITHDRAWAL KEEPS IT: "withdrawn here after it was carried". The
        // verb still writes its two columns and nothing else.
        $this->withdrawConsent($this->entryIn($this->second, $huda))->assertOk()
            ->assertJsonPath('data.consent_scope', null)
            ->assertJsonPath('data.consent_carried_from_group_id', $this->first->id);
        $this->assertSame($this->first->id, $marker($huda));
        $this->assertFalse($this->entryIn($this->second, $huda)->consentColumnsAreSet());

        // A RECORD CLEARS IT: the office is now asserting it for this class.
        $this->recordConsent($this->entryIn($this->second, $huda), 'feed')->assertOk()
            ->assertJsonPath('data.consent_scope', 'feed')
            ->assertJsonPath('data.consent_carried_from_group_id', null);
        $this->assertNull($marker($huda));

        // Also when the office saves the dialog as it stands: same scope, same day.
        $this->recordConsent($this->entryIn($this->second, $gamal), 'media', '2026-09-04')->assertOk()
            ->assertJsonPath('data.consent_scope', 'media')
            ->assertJsonPath('data.consent_carried_from_group_id', null);
        $this->assertNull($marker($gamal));
        $this->assertSame('2026-09-04', $this->entryIn($this->second, $gamal)->consent_granted_at->toDateString());

        // The source entries in the class left were never marked and still are not.
        $this->assertSame(0, DB::table('group_memberships')->where('group_id', $this->first->id)->whereNotNull('consent_carried_from_group_id')->count());

        // A MERGE'S UN-CONFIRM clears the marker together with a consent it
        // clears: the row is no longer that pair, and nobody withdrew.
        $this->entryIn($this->second, $nadia)->unconfirm()->save();
        $this->assertNull($marker($nadia));
        $this->assertFalse($this->entryIn($this->second, $nadia)->consentColumnsAreSet());

        // But an entry that was ALREADY blank keeps it. That state records a
        // family's withdrawal, and a merge is a de-duplication of one child.
        $this->withdrawConsent($this->entryIn($this->second, $samira))->assertOk();
        $this->entryIn($this->second, $samira)->unconfirm()->save();
        $this->assertSame($this->first->id, $marker($samira), 'a merge forgot that a carried consent was withdrawn');

        // Not fillable: no request body and no mass assignment sets it.
        $this->assertNotContains('consent_carried_from_group_id', (new GroupMembership())->getFillable());
        Sanctum::actingAs($this->admin);
        $this->putJson($this->consentUrl($this->entryIn($this->second, $huda)), ['scope' => 'feed', 'consent_carried_from_group_id' => $this->first->id])->assertOk();
        $this->assertNull($marker($huda));
    }

    // ================================================ what a withdrawal answers

    #[Test]
    public function a_withdrawal_says_where_that_parents_consent_still_stands_the_same_class_first(): void
    {
        $third = $this->makeClass('3rd Grade');
        $parent = $this->makePerson('Huda', 'Guardian');
        // A live family sign-in, so what the class opens to them can be asked below.
        $parent->forceFill(['login_email' => 'parent-'.uniqid().'@test.local', 'login_enabled_at' => now()])->save();
        $maryam = $this->enrol($this->first, 'Maryam');
        $this->guardian($maryam, $parent, consent: 'media');

        // The same adult stands in the second class for a brother, with
        // photograph consent recorded there, so Maryam's is carried into it.
        $yusuf = $this->enrol($this->second, 'Yusuf');
        $forYusuf = $this->guardian($yusuf, $parent, consent: 'media');
        // And Maryam is in a third class at the same time, with consent recorded there too.
        $this->guardian($this->enrol($third, $maryam->contact), $parent, consent: 'feed');
        // Another organisation's rows are never named.
        $elsewhere = $this->makeSchool();
        DB::table('group_memberships')->insert([
            'masjid_id' => $elsewhere->id, 'group_id' => $this->makeClass('Their class', school: $elsewhere)->id,
            'contact_id' => $parent->id, 'role' => 'guardian', 'guardian_of_contact_id' => $maryam->contact_id,
            'provenance' => 'confirmed', 'consent_scope' => 'media', 'consent_granted_at' => '2026-09-04 10:00:00',
        ]);

        $this->move($maryam, $this->second, self::TODAY)->assertOk()->assertJsonPath('data.consent_carried', ['media' => 1, 'feed' => 0]);
        $copy = GroupMembership::where('group_id', $this->second->id)->where('contact_id', $parent->id)
            ->where('guardian_of_contact_id', $maryam->contact_id)->sole();

        $answer = $this->withdrawConsent($copy)->assertOk()->assertJsonPath('data.consent_scope', null);

        $this->assertSame([
            // THE SAME CLASS FIRST: an adult is admitted to a class's story on
            // any one of their current entries there.
            "Huda Guardian still receives 2nd Grade's class story through their entry for Yusuf Student (class story and "
                .'photographs). Withdraw that too if the family meant the whole class.',
            // Then the same child's other classes: a closed entry, and a current one.
            'Consent for Huda Guardian about Maryam Student is still on record in 1st Grade (class story and photographs, '
                .'recorded 4 Sep 2026) and comes back into force if Maryam Student returns there. Withdraw it there too '
                .'if the family meant both.',
            'Consent for Huda Guardian about Maryam Student still stands in 3rd Grade (class story, recorded 4 Sep 2026). '
                .'Withdraw it there too if the family meant both.',
        ], $answer->json('notes'));

        // And the first note is true: the class is still open to them.
        $audience = app(\App\Support\GroupAudience::class);
        $this->assertTrue($audience->mayReceive($parent->fresh(), $this->second->fresh(), \App\Support\GroupAudience::DISCLOSURE_FEED));
        $this->assertTrue($audience->mayReceive($parent->fresh(), $this->second->fresh(), \App\Support\GroupAudience::DISCLOSURE_MEDIA));

        // Withdrawing the brother's too closes it, and that answer names only
        // what is left: nothing in this class, and nothing about another child.
        $this->assertSame([], $this->withdrawConsent($forYusuf)->assertOk()->json('notes'));
        $this->assertFalse($audience->mayReceive($parent->fresh(), $this->second->fresh(), \App\Support\GroupAudience::DISCLOSURE_FEED));

        // An entry of that adult in this class that has left, or holds
        // nothing, opens nothing and is not named.
        $this->assertSame(2, count($this->withdrawConsent($copy)->assertOk()->json('notes')));

        // A participant row has nothing to withdraw and nothing to be told.
        $this->withdrawConsent($yusuf)->assertOk()->assertJsonPath('notes', []);
    }

    #[Test]
    public function a_record_that_narrows_names_the_entries_in_that_class_that_still_hold_more(): void
    {
        $parent = $this->makePerson('Huda', 'Guardian');
        $forMaryam = $this->guardian($this->enrol($this->first, 'Maryam'), $parent, consent: 'media');
        $forYusuf = $this->guardian($this->enrol($this->first, 'Yusuf'), $parent, consent: 'media');

        // Recorded as it stands: nothing in this class holds more than it does.
        $this->recordConsent($forMaryam, 'media', '2026-09-04')->assertOk()->assertJsonPath('notes', []);

        // Narrowed to the class story: the brother's entry still opens the photographs.
        $this->recordConsent($forMaryam->fresh(), 'feed', '2026-09-04')->assertOk()->assertJsonPath('notes', [
            "Huda Guardian still receives 1st Grade's class story through their entry for Yusuf Student (class story and "
                .'photographs). Withdraw that too if the family meant the whole class.',
        ]);

        // The brother's narrowed as well: neither holds more than the other now.
        $this->recordConsent($forYusuf->fresh(), 'feed', '2026-09-04')->assertOk()->assertJsonPath('notes', []);

        // A refusal answers no notes and writes nothing.
        $claim = $this->guardian($this->enrol($this->first, 'Layla'), $parent, confirmed: false);
        $refused = $this->recordConsent($claim, 'media')->assertStatus(422);
        $this->assertArrayNotHasKey('notes', $refused->json());
    }

    #[Test]
    public function a_notes_read_that_fails_never_turns_a_withdrawal_into_an_error(): void
    {
        $this->logLikeProduction();

        $student = $this->enrol($this->first, 'Maryam');
        $parent = $this->guardian($student, 'Huda', consent: 'media');

        $this->app->bind(\App\Http\Controllers\AdminDashboard\GroupConsentController::class, fn () => new class extends \App\Http\Controllers\AdminDashboard\GroupConsentController {
            protected function whereConsentStillStands(Group $group, GroupMembership $entry): array
            {
                throw new \RuntimeException('the read behind the notes failed, with something private in its message');
            }
        });

        $this->withdrawConsent($parent)->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.consent_scope', null)
            ->assertJsonPath('notes', []);

        $this->assertFalse($parent->fresh()->consentColumnsAreSet(), 'the withdrawal was not written');

        // Kept where production keeps it (LOG_LEVEL=warning), by the fault's
        // class and the row's id, never by its message.
        $lines = $this->loggedLines('laravel.log', 'group.consent.notes_failed');
        $this->assertCount(1, $lines);
        $this->assertStringContainsString('.WARNING: group.consent.notes_failed', $lines[0]);
        $this->assertStringContainsString('"membership":'.$parent->id, $lines[0]);
        $this->assertStringContainsString('RuntimeException', $lines[0]);
        $this->assertStringNotContainsString('something private', $lines[0]);

        // A record is answered the same way.
        $this->recordConsent($parent->fresh(), 'feed')->assertOk()->assertJsonPath('notes', [])->assertJsonPath('data.consent_scope', 'feed');
        $this->assertCount(2, $this->loggedLines('laravel.log', 'group.consent.notes_failed'));
    }
}
