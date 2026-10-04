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
 * set to null.
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

        parent::tearDown();
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
    public function consent_does_not_move_it_stays_on_the_old_entry_and_is_asked_again(): void
    {
        $student = $this->enrol($this->first, 'Maryam');
        $parent = $this->guardian($student, 'Huda', consent: 'media');
        $before = $this->rosterSnapshot();

        $this->previewMove($student, $this->second, self::TODAY)->assertOk()
            ->assertJsonPath('data.consent_recorded_here', true)
            ->assertJsonPath('data.guardians.consent_to_record_again', 1)
            // Remove would take the guardian entry and its consent with it.
            ->assertJsonPath('data.old_entry_removable', false);

        $this->move($student, $this->second, self::TODAY)->assertOk()
            ->assertJsonPath('data.consent_to_record_again', 1)
            ->assertJsonPath('data.old_entry_removable', false);

        $kept = $parent->fresh();
        $this->assertSame('media', $kept->consent_scope);
        $this->assertNotNull($kept->consent_granted_at);
        $this->assertNotNull($kept->left_on);

        $carried = GroupMembership::where('group_id', $this->second->id)->where('contact_id', $parent->contact_id)->sole();
        $this->assertNull($carried->consent_scope);
        $this->assertNull($carried->consent_granted_at);
        $this->assertFalse($carried->hasConsent());
        $this->assertNothingWasDestroyed($before);
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

        // The whole copy: the confirmation, and nothing else.
        $whole = $blank()->selfAssertedFrom(null)->carriedFrom($parent);
        $this->assertTrue($whole->isConfirmed());
        $this->assertSame((int) $parent->confirmed_by_user_id, (int) $whole->confirmed_by_user_id);
        $this->assertNull($whole->consent_scope);
        $this->assertNull($whole->left_on);

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

        $this->move($student, $this->second, self::TODAY)->assertOk()
            ->assertJsonPath('data.guardians_confirmed_in_old_class_only', 1)
            ->assertJsonPath('data.guardian_form_claims', 0)
            ->assertJsonPath('data.consent_to_record_again', 1);

        $there = GroupMembership::where('group_id', $this->second->id)->where('contact_id', $parent->contact_id)->get();
        $this->assertCount(1, $there);
        $this->assertSame($claim->id, $there->first()->id);
        $this->assertFalse($there->first()->isConfirmed(), 'a confirmation was copied onto an entry the new class already held');
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
            ->assertJsonPath('data.old_entry_removable', true);

        $answer = $this->move($there, $this->first, self::TODAY, [
            'expected_path' => 'returned',
            'expected_first_day' => $preview->json('data.first_day_in_new_class'),
            'expected_joined_on' => $preview->json('data.joined_on'),
        ])->assertOk();

        $answer->assertJsonPath('data.membership_id', $student->id)
            ->assertJsonPath('data.joined_kept', true)
            ->assertJsonPath('data.consent_in_force_again', [
                ['guardian' => 'Huda Guardian', 'scope' => 'media', 'recorded_on' => '2026-09-04'],
            ]);
        $this->assertStringContainsString(
            'Consent already recorded in 1st Grade is in force again: Huda Guardian (class story and photographs, recorded 4 Sep 2026).',
            implode(' ', $answer->json('data.lines')),
        );

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
        ])->assertOk()->json('data');

        $this->assertSame($preview['path'], $answer['path']);
        $this->assertSame($preview['first_day_in_new_class'], $answer['first_day_in_new_class']);
        $this->assertSame($preview['records_staying'], $answer['records_staying']);
        $this->assertSame($preview['guardians']['travelling'], $answer['guardians_in_new_class']);
        $this->assertSame($preview['guardians']['form_claims'], $answer['guardian_form_claims']);
        $this->assertSame($preview['guardians']['consent_to_record_again'], $answer['consent_to_record_again']);
        $this->assertSame($preview['old_entry_removable'], $answer['old_entry_removable']);

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
        $this->move($student, $this->second, self::TODAY)->assertOk();

        Sanctum::actingAs($teacher);
        $body = $this->getJson("/api/teacher/masjids/{$this->school->id}/groups/{$this->second->id}")->assertOk()->getContent();

        $this->assertStringContainsString('Maryam', $body);

        foreach (['moved_from_group_id', 'moved_to_group_id', 'moved_by_user_id', 'moved_on', 'moved_to_state'] as $key) {
            $this->assertStringNotContainsString($key, $body);
        }
    }

    // ------------------------------------------------------------ the history

    #[Test]
    public function a_move_is_logged_where_production_keeps_it_with_the_guardian_entries_it_touched(): void
    {
        $this->logLikeProduction();

        $student = $this->enrol($this->first, 'Maryam');
        $parent = $this->guardian($student, 'Huda');

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
        $this->assertStringContainsString('"guardian_entries_carried":['.$carried->id.']', $lines[0]);
        $this->assertStringContainsString('"by":'.$this->admin->id, $lines[0]);
        // Ids only.
        $this->assertStringNotContainsString('Maryam', $lines[0]);
        $this->assertStringNotContainsString('Huda', $lines[0]);
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

            public function preview(Group $from, GroupMembership $row, ?Group $to, string $on): RosterMovePlan
            {
                $plan = parent::preview($from, $row, $to, $on);

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
}
