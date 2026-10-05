<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Services\Schools\RosterImportService;
use App\Support\AcademicRecordsHeld;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSchoolRosters;
use Tests\Support\PlantsRosterRecords;
use Tests\TestCase;

/**
 * WHAT A MOVE CHANGED ON THE ROSTER'S OTHER VERBS.
 *
 *   - ONE LIST of every foreign key into a roster row
 *     (App\Support\AcademicRecordsHeld::KEYS), and the two roster deleters
 *     (Remove, and the undo of a roster import) refusing on the eight kinds a
 *     delete would destroy and on nothing else.
 *   - THE ROSTER LIST saying, per row that was moved, whether "Put back" may be
 *     offered (`moved_to_state`), and since consent is carried, which class a
 *     guardian's consent was carried from and what "Put back" would bring back
 *     into force.
 *   - REMOVE naming the same guardian's entries in other classes.
 *   - "ADD TO ROSTER" taking the student's contact lock before it looks.
 *
 * The move itself is tests/Feature/RosterMoveTest.php.
 */
class RosterMoveRosterTest extends TestCase
{
    use BuildsSchoolRosters;
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
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------ the one list

    #[Test]
    public function the_list_is_every_foreign_key_into_a_roster_row_and_nothing_else(): void
    {
        $inSchema = [];

        foreach (Schema::getTables() as $table) {
            foreach (Schema::getForeignKeys($table['name']) as $key) {
                if ($key['foreign_table'] === 'group_memberships') {
                    $inSchema[$table['name']] = $key['columns'][0];
                }
            }
        }

        $listed = array_map(fn (array $key): string => $key[0], AcademicRecordsHeld::KEYS);

        ksort($inSchema);
        ksort($listed);

        // A twelfth key fails here: say what it is before a roster row holding it is moved or removed.
        $this->assertSame($inSchema, $listed);
        $this->assertCount(11, AcademicRecordsHeld::KEYS);

        // What a delete would destroy: the seven RESTRICT kinds and Arabic daily notes.
        $destroyed = array_keys(array_filter(AcademicRecordsHeld::KEYS, fn (array $key): bool => $key[3]));
        $this->assertSame([
            'attendance_records', 'assignment_scores', 'report_cards', 'hifz_entries', 'behavior_awards',
            'arabic_letter_progress', 'prize_ledger_entries', 'arabic_daily_notes',
        ], $destroyed);

        foreach (AcademicRecordsHeld::KEYS as $table => [, , $rule, $isDestroyed]) {
            $this->assertContains($rule, ['restrict', 'cascade', 'set null']);
            $this->assertSame($isDestroyed, $rule === 'restrict' || $table === 'arabic_daily_notes');

            // Records never change class because each one names its class:
            // ten by their own column, the eleventh through its file.
            $this->assertSame($table !== 'group_resource_recipients', Schema::hasColumn($table, 'group_id'));

            // The soft-deleting tables are exactly the listed ones.
            $this->assertSame(
                in_array($table, AcademicRecordsHeld::SOFT_DELETED, true),
                Schema::hasColumn($table, 'deleted_at'),
                "{$table}: SOFT_DELETED is out of step with the schema",
            );
        }
    }

    #[Test]
    public function the_count_is_raw_so_a_deleted_record_still_counts(): void
    {
        $student = $this->enrol($this->first, 'Maryam');

        foreach (AcademicRecordsHeld::SOFT_DELETED as $table) {
            $this->plantRecord($table, $student, ['deleted_at' => now()]);
        }

        $held = AcademicRecordsHeld::counts($student);

        $this->assertSame(1, $held['ḥifẓ entries']);
        $this->assertSame(1, $held['behaviour points']);
        $this->assertSame(1, $held['conversations']);
        $this->assertCount(11, $held);
        $this->assertTrue(AcademicRecordsHeld::onlyDeleted($student));

        $this->assertSame('1 ḥifẓ entry, 1 behaviour point, 1 conversation', AcademicRecordsHeld::describeForPeople($held));
        $this->assertSame('1 ḥifẓ entries, 1 behaviour points, 1 conversations', AcademicRecordsHeld::describe($held));
    }

    // ------------------------------------------------------ the two deleters

    public static function whatADeleteDestroys(): array
    {
        return collect(AcademicRecordsHeld::KEYS)->filter(fn (array $key): bool => $key[3])
            ->map(fn (array $key, string $table): array => [$table, $key[1]])->all();
    }

    public static function whatSurvivesOrLapses(): array
    {
        return collect(AcademicRecordsHeld::KEYS)->reject(fn (array $key): bool => $key[3])
            ->map(fn (array $key, string $table): array => [$table])->all();
    }

    #[Test]
    #[DataProvider('whatADeleteDestroys')]
    public function remove_and_the_import_undo_refuse_on_a_record_a_delete_would_destroy(string $table, string $label): void
    {
        $student = $this->enrol($this->first, 'Maryam');
        $student->contact->forceFill(['import_batch' => 'roster-test'])->save();
        $record = $this->plantRecord($table, $student);

        $refusal = $this->removeFromRoster($student)->assertStatus(409)->json('data.membership.0');

        // Every kind is counted but the class store's ledger, which is named
        // and never counted: how many rows a child's ledger holds says whether
        // Manara Bucks moved with them.
        $ledger = $label === AcademicRecordsHeld::LEDGER_LABEL;
        $held = $ledger ? AcademicRecordsHeld::LEDGER_HISTORY : "1 {$label}";

        $this->assertStringContainsString("({$held})", $refusal);
        // What to do instead, in the screen's own words.
        $this->assertStringContainsString('Use "Left the class" instead', $refusal);
        $this->assertStringContainsString('Removing the roster entry would delete those records.', $refusal);

        $undo = app(RosterImportService::class)->rollback('roster-test');

        $this->assertSame(0, $undo['roster_rows_removed']);
        $this->assertStringContainsString("Maryam Student has school records from this class ({$held})", $undo['refused'][0]);
        $this->assertStringContainsString('Use "Left the class" instead', $undo['refused'][0]);

        if ($ledger) {
            $this->assertDoesNotMatchRegularExpression('/\d/', $refusal, 'Remove printed a figure about a child\'s ledger');
            $this->assertDoesNotMatchRegularExpression('/\d/', $undo['refused'][0], 'the import undo printed a figure about a child\'s ledger');
        }

        $this->assertNotNull($student->fresh());
        $this->assertSame(1, DB::table($table)->where('id', $record)->count(), 'a refused removal still destroyed the record');
    }

    #[Test]
    #[DataProvider('whatSurvivesOrLapses')]
    public function a_conversation_a_scheduled_message_or_an_addressed_file_does_not_block_a_removal(string $table): void
    {
        $student = $this->enrol($this->first, 'Maryam');
        $twin = $this->enrol($this->first, 'Yusuf');
        $twin->contact->forceFill(['import_batch' => 'roster-test'])->save();
        // Even one nobody can see on a screen: a deleted conversation, a cancelled message.
        $with = match ($table) {
            'group_threads' => ['deleted_at' => now()],
            'group_message_schedules' => ['status' => 'cancelled'],
            default => [],
        };
        $this->plantRecord($table, $student, $with);
        $this->plantRecord($table, $twin, $with);

        $this->removeFromRoster($student)->assertOk();
        $this->assertNull($student->fresh());

        $undo = app(RosterImportService::class)->rollback('roster-test');
        $this->assertSame([], $undo['refused']);
        $this->assertNull($twin->fresh());
    }

    #[Test]
    public function a_refusal_about_records_nobody_can_see_says_so(): void
    {
        $student = $this->enrol($this->first, 'Maryam');
        $this->plantRecord('behavior_awards', $student, ['deleted_at' => now()]);

        $refusal = $this->removeFromRoster($student)->assertStatus(409)->json('data.membership.0');

        $this->assertStringContainsString('1 behaviour points', $refusal);
        $this->assertStringContainsString('Those records were deleted and are no longer shown on any screen, but they are still kept', $refusal);
        $this->assertStringContainsString('Use "Left the class" instead', $refusal);
    }

    // ---------------------------------------------- remove, on a guardian entry

    #[Test]
    public function removing_a_guardian_entry_names_the_same_adults_other_entries_for_that_child_and_no_other_schools(): void
    {
        $student = $this->enrol($this->first, 'Maryam');
        $parent = $this->guardian($student, 'Gamal');
        $third = $this->makeClass('3rd Grade');

        // In the second class and current; in the third and marked as left.
        $inSecond = $this->guardian($this->enrol($this->second, $student->contact), $parent->contact);
        $leftThird = $this->enrol($third, $student->contact);
        $this->guardian($leftThird, $parent->contact);
        $leftThird->markLeftByStaff($this->admin, '2026-09-10')->save();

        // Another organisation with the same two people's ids would never be named: its rows are out of scope.
        $other = $this->makeSchool();
        $theirClass = $this->makeClass('Their class', school: $other);
        DB::table('group_memberships')->insert([
            'masjid_id' => $other->id, 'group_id' => $theirClass->id, 'contact_id' => $parent->contact_id,
            'role' => 'guardian', 'guardian_of_contact_id' => $student->contact_id, 'provenance' => 'confirmed',
        ]);

        $removal = $this->removeFromRoster($parent)->assertOk();

        $removal->assertJsonPath('data.cascade.same_guardian_elsewhere', [
            ['group_id' => $this->second->id, 'name' => '2nd Grade', 'left' => false],
            ['group_id' => $third->id, 'name' => '3rd Grade', 'left' => true],
        ]);
        $this->assertSame(
            'Removed from the roster. Gamal Guardian is still listed as a guardian of Maryam Student in 2 other classes: '
                .'2nd Grade, 3rd Grade. Remove those entries too if this person should no longer have access.',
            $removal->json('message'),
        );

        // The last one says nothing more than it always did.
        GroupMembership::where('group_id', $third->id)->where('contact_id', $parent->contact_id)->sole()->delete();
        DB::table('group_memberships')->where('masjid_id', $other->id)->delete();
        $last = $this->removeFromRoster($inSecond)->assertOk()
            ->assertJsonPath('message', 'Removed from the roster.')
            ->assertJsonPath('data.cascade.same_guardian_elsewhere', []);

        // NAMING THE TWO PEOPLE DID NOT PUT THEM IN THE ANSWER. The row that
        // comes back is the same narrow one either way: the roster row's own
        // columns, and no `contact` or `guardian_of` with a whole contact in it
        // (sign-in address, notes, consent evidence, for the adult and the child).
        $row = $removal->json('data.membership');

        $this->assertSame(array_keys($last->json('data.membership')), array_keys($row));
        $this->assertArrayNotHasKey('contact', $row);
        $this->assertArrayNotHasKey('guardian_of', $row);
        $this->assertSame([], array_filter($row, 'is_array'), 'the removed row carries a nested record');
    }

    // ------------------------------- remove, on the old entry a move left behind

    /**
     * The flow the move itself invites: "It holds nothing: remove it there if
     * you do not need it." The date of birth is on the contact and the new
     * class shows the age from it, so tidying the old entry away must not offer
     * to delete it.
     */
    #[Test]
    public function removing_the_old_entry_after_a_move_does_not_offer_to_clear_the_date_the_new_class_uses(): void
    {
        $student = $this->enrol($this->first, 'Maryam');
        $student->contact->recordDateOfBirth('2017-03-09', $this->admin, 'roster');

        $moved = $this->move($student, $this->second, '2026-10-04')->assertOk()
            ->assertJsonPath('data.old_entry_removable', true);
        $this->assertStringContainsString('It holds nothing: remove it there if you do not need it.', implode(' ', $moved->json('data.lines')));

        $age = fn (): ?int => collect($this->roster($this->second)->assertOk()->json('data'))
            ->firstWhere('id', $moved->json('data.membership_id'))['age'];
        $this->assertSame(9, $age());

        $removal = $this->removeFromRoster($student)->assertOk();

        $this->assertSame(
            'Removed from the roster. Their date of birth is still on their record: they are still listed in 2nd Grade, '
                .'where it gives their age. It can be changed or removed from their details there.',
            $removal->json('message'),
        );
        $this->assertArrayNotHasKey('birth_date', $removal->json('data'), 'the clear was offered for a date the new class uses');
        $this->assertSame(9, $age());
    }

    // ------------------------------------------------------- the roster list

    #[Test]
    public function the_list_says_whether_this_is_a_class_what_day_it_is_and_names_the_classes_of_a_move(): void
    {
        $student = $this->enrol($this->first, 'Maryam');
        $moved = $this->move($student, $this->second, self::TODAY)->assertOk()->json('data.membership_id');

        $old = $this->roster($this->first)->assertOk();
        $old->assertJsonPath('meta.teaches_students', true)
            ->assertJsonPath('meta.school_today', self::TODAY)
            ->assertJsonPath('meta.group_name', '1st Grade')
            ->assertJsonPath('meta.move_note', null);

        $row = collect($old->json('data'))->firstWhere('id', $student->id);
        $this->assertSame($this->second->id, $row['moved_to_group_id']);
        $this->assertSame('2nd Grade', $row['moved_to']['name']);
        $this->assertNull($row['moved_to']['deleted_at']);
        $this->assertSame([
            'student_there' => 'current',
            'open_group' => ['id' => $this->second->id, 'name' => '2nd Grade'],
            'guardians_not_vouched' => [],
            'consent_blocks' => [],
            'consent_lines' => [],
            'bucks_line' => null,
        ], $row['moved_to_state']);

        $new = collect($this->roster($this->second)->assertOk()->json('data'))->firstWhere('id', $moved);
        $this->assertSame('1st Grade', $new['moved_from']['name']);
        $this->assertNull($new['moved_to_state'], 'a row that was not moved out carries no state');

        // A class deleted since is still named, and says it was deleted.
        $this->second->delete();
        $row = collect($this->roster($this->first)->json('data'))->firstWhere('id', $student->id);
        $this->assertSame('2nd Grade', $row['moved_to']['name']);
        $this->assertNotNull($row['moved_to']['deleted_at']);
        $this->assertSame('none', $row['moved_to_state']['student_there']);
        $this->assertSame([], $row['moved_to_state']['guardians_not_vouched']);
    }

    #[Test]
    public function a_school_is_told_when_a_group_holding_students_is_not_a_class_and_nobody_else_is(): void
    {
        $circle = $this->makeClass('Evening circle', ['kind' => Group::KIND_HALAQA]);

        // No student, no note.
        $this->roster($circle)->assertOk()->assertJsonPath('meta.teaches_students', false)->assertJsonPath('meta.move_note', null);

        $this->enrol($circle, 'Maryam');
        $this->roster($circle)->assertOk()->assertJsonPath(
            'meta.move_note',
            'Move, ages and dates of birth are for classes. This group is set up as "Halaqa". Change its kind on the '
                .$this->school->term('groups').' page if it is a class.',
        );

        // A masjid's ḥalaqa is not missing anything. (The requests above left
        // this school bound as the tenant, and a row created while it is bound
        // is stamped with it.)
        app(\App\Support\TenantContext::class)->forgetTenant();
        $masjid = $this->makeSchool('masjid');
        $theirAdmin = $this->makeAdmin($masjid);
        $theirCircle = $this->makeClass('Their circle', ['kind' => Group::KIND_HALAQA], $masjid);
        $member = new GroupMembership([
            'masjid_id' => $masjid->id, 'group_id' => $theirCircle->id,
            'contact_id' => $this->makePerson('A', 'Member', $masjid)->id, 'role' => GroupMembership::ROLE_MEMBER,
        ]);
        $member->confirmedByStaff($theirAdmin)->save();

        Sanctum::actingAs($theirAdmin);
        $this->getJson("/api/admin/masjids/{$masjid->id}/groups/{$theirCircle->id}/members")->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.teaches_students', false)
            ->assertJsonPath('meta.move_note', null);
    }

    #[Test]
    public function put_back_is_withheld_while_it_would_bring_back_an_adult_removed_where_the_student_is(): void
    {
        $student = $this->enrol($this->first, 'Maryam');
        $this->plantRecord('attendance_records', $student);
        $parent = $this->guardian($student, 'Gamal', consent: 'media');
        $other = $this->guardian($student, 'Nadia');

        $inSecond = GroupMembership::findOrFail($this->move($student, $this->second, self::TODAY)->assertOk()->json('data.membership_id'));
        $entry = fn (Group $class, GroupMembership $of): GroupMembership => GroupMembership::where('group_id', $class->id)
            ->where('contact_id', $of->contact_id)->where('role', 'guardian')->sole();
        $state = fn (): array => collect($this->roster($this->first)->json('data'))->firstWhere('id', $student->id)['moved_to_state'];

        // Everybody is still a confirmed guardian where the student is.
        $this->assertSame([], $state()['guardians_not_vouched']);

        // Removed where the student is: named, with the sentence the screen prints.
        $entry($this->second, $parent)->delete();
        $this->assertSame([[
            'membership_id' => $parent->id,
            'reason' => 'no_entry',
            'sentence' => 'Putting Maryam Student back would also give Gamal Guardian access to this class again. '
                .'Gamal Guardian is not a confirmed guardian of Maryam Student in 2nd Grade.',
        ]], $state()['guardians_not_vouched']);

        // Listed there again by a public form: still not vouched, and said differently.
        $claim = $this->guardian($inSecond, $parent->contact, confirmed: false);
        $this->assertSame('only_unconfirmed', $state()['guardians_not_vouched'][0]['reason']);
        $this->assertStringContainsString('is only listed from a registration form in 2nd Grade', $state()['guardians_not_vouched'][0]['sentence']);
        $claim->delete();

        // AFTER TWO MOVES the question is where the student is NOW, not the
        // class this row was moved to: the second guardian is removed in the
        // third class and is still on the second class's list, marked as left.
        $third = $this->makeClass('3rd Grade');
        $this->move($inSecond, $third, self::TODAY)->assertOk();
        $entry($third, $other)->delete();

        $now = $state();
        $this->assertSame('left', $now['student_there']);
        $this->assertSame(['id' => $third->id, 'name' => '3rd Grade'], $now['open_group']);
        $this->assertEqualsCanonicalizing([$parent->id, $other->id], array_column($now['guardians_not_vouched'], 'membership_id'));

        // The server's own undo stays ungated: an undo that can be refused is
        // the one direction that verb must never have. The guard is the screen's.
        Sanctum::actingAs($this->admin);
        $this->deleteJson("/api/admin/masjids/{$this->school->id}/groups/{$this->first->id}/members/{$student->id}/withdrawal")->assertOk();
        $this->assertNull($student->fresh()->left_on);
        $this->assertSame($this->second->id, (int) $student->fresh()->moved_to_group_id, 'a hand "put back" keeps the fact that they were moved');
    }

    #[Test]
    public function when_the_student_is_current_nowhere_the_class_they_were_moved_to_is_what_is_compared(): void
    {
        $student = $this->enrol($this->first, 'Maryam');
        $kept = $this->guardian($student, 'Huda');
        $removed = $this->guardian($student, 'Gamal');

        $inSecond = GroupMembership::findOrFail($this->move($student, $this->second, self::TODAY)->assertOk()->json('data.membership_id'));
        GroupMembership::where('group_id', $this->second->id)->where('contact_id', $removed->contact_id)->sole()->delete();
        $inSecond->markLeftByStaff($this->admin, self::TODAY)->save();

        $state = collect($this->roster($this->first)->json('data'))->firstWhere('id', $student->id)['moved_to_state'];

        // A confirmed entry that left with the student there still vouches.
        $this->assertSame('left', $state['student_there']);
        $this->assertSame([$removed->id], array_column($state['guardians_not_vouched'], 'membership_id'));
        $this->assertNotContains($kept->id, array_column($state['guardians_not_vouched'], 'membership_id'));

        // No row there at all: nothing to compare against, so nobody is named
        // and the screen lists every guardian instead.
        $inSecond->delete();
        $state = collect($this->roster($this->first)->json('data'))->firstWhere('id', $student->id)['moved_to_state'];
        $this->assertSame([
            'student_there' => 'none',
            'open_group' => ['id' => $this->second->id, 'name' => '2nd Grade'],
            'guardians_not_vouched' => [],
            'consent_blocks' => [],
            'consent_lines' => [],
            'bucks_line' => null,
        ], $state);
    }

    // ------------------------------------------- a consent that was carried

    #[Test]
    public function the_list_names_the_class_a_consent_was_carried_from_and_keeps_naming_it_after_a_withdrawal(): void
    {
        $student = $this->enrol($this->first, 'Maryam');
        $parent = $this->guardian($student, 'Huda', consent: 'media');
        $recordedHere = $this->guardian($this->enrol($this->second, 'Yusuf'), 'Gamal', consent: 'feed');
        $this->move($student, $this->second, self::TODAY)->assertOk();

        $copy = $this->entryIn($this->second, $parent);
        $row = fn (GroupMembership $entry): array => collect($this->roster($this->second)->assertOk()->json('data'))->firstWhere('id', $entry->id);

        // Carried, untouched since: the marker and the two consent columns.
        $carried = $row($copy);
        $this->assertSame($this->first->id, $carried['consent_carried_from_group_id']);
        $this->assertSame(['id' => $this->first->id, 'name' => '1st Grade', 'deleted_at' => null], $carried['consent_carried_from']);
        $this->assertSame('media', $carried['consent_scope']);

        // Recorded by the office for this class: no marker, and the key is still there.
        $recorded = $row($recordedHere);
        $this->assertNull($recorded['consent_carried_from_group_id']);
        $this->assertArrayHasKey('consent_carried_from', $recorded);
        $this->assertNull($recorded['consent_carried_from']);

        // Withdrawn here after it was carried: the marker stays, the columns are blank.
        $this->withdrawConsent($copy)->assertOk();
        $withdrawn = $row($copy);
        $this->assertNull($withdrawn['consent_scope']);
        $this->assertNull($withdrawn['consent_granted_at']);
        $this->assertSame('1st Grade', $withdrawn['consent_carried_from']['name']);

        // A class deleted since is still named, and says it was deleted.
        $this->first->delete();
        $this->assertNotNull($row($copy)['consent_carried_from']['deleted_at']);

        // The old entry was never a copy.
        $this->assertNull(collect($this->roster($this->second)->json('data'))->firstWhere('id', $copy->id)['moved_from_group_id'], 'a guardian entry carries no move columns');
    }

    #[Test]
    public function put_back_says_whose_consent_it_would_bring_back_and_is_withheld_for_one_the_family_took_back(): void
    {
        $student = $this->enrol($this->first, 'Maryam');
        $parent = $this->guardian($student, 'Huda', consent: 'media');
        $this->guardian($student, 'Gamal');

        $this->move($student, $this->second, self::TODAY)->assertOk();
        $copy = $this->entryIn($this->second, $parent);
        $state = fn (Group $class, GroupMembership $row): array => collect($this->roster($class)->assertOk()->json('data'))->firstWhere('id', $row->id)['moved_to_state'];

        // Nothing was withdrawn: "Put back" is offered, and says whose consent
        // in this class comes back into force with it. A guardian with none is not named.
        $now = $state($this->first, $student);
        $this->assertSame([], $now['consent_blocks']);
        $this->assertSame(
            ["Putting Maryam Student back brings Huda Guardian's consent in this class into force again (class story and photographs, recorded 4 Sep 2026)."],
            $now['consent_lines'],
        );
        $this->assertNull($now['bucks_line']);
        $this->assertSame([], $now['guardians_not_vouched']);

        // THE COPY WAS WITHDRAWN where it had been carried. One sentence for
        // the entry, then once what to do; and the entry is no longer among
        // those that would simply come back.
        $this->withdrawConsent($copy)->assertOk();
        $now = $state($this->first, $student);
        $this->assertSame([
            'Huda Guardian withdrew consent in 2nd Grade after it had been carried there from this class. Putting Maryam '
                .'Student back would bring the consent recorded here (class story and photographs, recorded 4 Sep 2026) '
                .'into force again.',
            "Withdraw it on this roster first (the Consent button on the guardian's row), then put Maryam Student back.",
        ], $now['consent_blocks']);
        $this->assertSame([], $now['consent_lines']);

        // The remedy, on this roster: the block goes and nothing is left to come back.
        $this->withdrawConsent($parent)->assertOk();
        $now = $state($this->first, $student);
        $this->assertSame([], $now['consent_blocks']);
        $this->assertSame([], $now['consent_lines']);

        // THE OTHER SIDE, with another family. Carried into the second class,
        // then the student goes back to the first: the copy in the second
        // closes with what it holds, beside a row that says "moved to".
        $second = $this->enrol($this->first, 'Yusuf');
        $nadia = $this->guardian($second, 'Nadia', consent: 'media');
        $there = GroupMembership::findOrFail($this->move($second, $this->second, self::TODAY)->assertOk()->json('data.membership_id'));
        $this->move($there, $this->first, self::TODAY)->assertOk();

        $this->assertSame(
            ["Putting Yusuf Student back brings Nadia Guardian's consent in this class into force again (class story and photographs, recorded 4 Sep 2026)."],
            $state($this->second, $there)['consent_lines'],
        );

        // The family narrows in the first class, where the copy came from...
        $this->recordConsent($nadia->fresh(), 'feed', '2026-09-04')->assertOk();
        $this->assertSame([
            "Nadia Guardian's consent here (class story and photographs, recorded 4 Sep 2026) was carried from 1st Grade, and "
                .'consent in 1st Grade is now for the class story only. Putting Yusuf Student back would bring it into force again.',
            "Withdraw it on this roster first (the Consent button on the guardian's row), then put Yusuf Student back.",
        ], $state($this->second, $there)['consent_blocks']);

        // ...and then withdraws there.
        $this->withdrawConsent($nadia->fresh())->assertOk();
        $blocked = $state($this->second, $there);
        $this->assertSame(
            "Nadia Guardian's consent here (class story and photographs, recorded 4 Sep 2026) was carried from 1st Grade, and "
                .'consent in 1st Grade has since been withdrawn. Putting Yusuf Student back would bring it into force again.',
            $blocked['consent_blocks'][0],
        );
        $this->assertCount(2, $blocked['consent_blocks']);
        $this->assertSame([], $blocked['consent_lines']);

        // THE VERB ITSELF STAYS UNGATED, as it is for the guardian rule: the
        // guard is the screen's. A row that is current again re-opens nothing more.
        Sanctum::actingAs($this->admin);
        $this->deleteJson("/api/admin/masjids/{$this->school->id}/groups/{$this->second->id}/members/{$there->id}/withdrawal")->assertOk();
        $current = $state($this->second, $there);
        $this->assertSame([], $current['consent_blocks']);
        $this->assertSame([], $current['consent_lines']);
    }

    // -------------------------------------------------------- "Add to roster"

    #[Test]
    public function adding_to_a_roster_locks_the_students_contact_before_it_looks_for_a_duplicate(): void
    {
        $student = $this->makePerson('Maryam', 'Student');
        $parent = $this->makePerson('Huda', 'Guardian');

        $statements = function (array $body): array {
            $seen = null;

            Event::listen(TransactionBeginning::class, function () use (&$seen): void {
                $seen ??= [];
            });
            Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$seen): void {
                if ($seen !== null) {
                    $seen[] = [$query->sql, $query->bindings];
                }
            });

            Sanctum::actingAs($this->admin);
            $this->postJson("/api/admin/masjids/{$this->school->id}/groups/{$this->first->id}/members", $body)
                ->assertCreated();

            Event::forget(TransactionBeginning::class);
            Event::forget(QueryExecuted::class);

            return $seen ?? [];
        };

        // A student: their own contact row, first.
        $first = $statements(['contact_id' => $student->id, 'role' => 'member'])[0];
        $this->assertStringContainsString('from "contacts"', $first[0]);
        $this->assertContains($student->id, $first[1]);

        // A guardian entry: the CHILD's contact row, first, before the ward and duplicate checks.
        $all = $statements(['contact_id' => $parent->id, 'role' => 'guardian', 'guardian_of_contact_id' => $student->id]);
        $this->assertStringContainsString('from "contacts"', $all[0][0]);
        $this->assertContains($student->id, $all[0][1]);
        $this->assertStringContainsString('"group_memberships"', $all[1][0]);

        // And in the source: the lock is the first statement of the
        // transaction, and the duplicate check is an ORDINARY read (a locking
        // read over the roster's index would take next-key locks on the class).
        $source = file_get_contents(app_path('Http/Controllers/AdminDashboard/GroupMembershipsController.php'));
        $code = preg_replace('~^\s*//.*$~m', '', $source);
        $store = substr($code, strpos($code, 'public function store('), strpos($code, 'public function confirm(') - strpos($code, 'public function store('));

        $this->assertMatchesRegularExpression(
            '~DB::transaction\(function \(\) use \([^)]*\) \{\s*Contact::query\(\)->whereKey\(\$wardId \?\? \$contact->id\)->lockForUpdate\(\)->first\(\);~',
            $store,
        );
        $this->assertSame(1, substr_count($store, 'lockForUpdate()'));
        $this->assertSame(0, substr_count($store, 'sharedLock()'));
        $this->assertGreaterThan(strpos($store, 'DB::transaction('), strpos($store, '->exists()'), 'a read runs before the transaction that holds the lock');
    }

    #[Test]
    public function adding_to_a_roster_still_answers_as_it_did(): void
    {
        $student = $this->enrol($this->first, 'Maryam');
        $parent = $this->makePerson('Huda', 'Guardian');
        $url = "/api/admin/masjids/{$this->school->id}/groups/{$this->first->id}/members";

        Sanctum::actingAs($this->admin);

        $this->postJson($url, ['contact_id' => $student->contact_id, 'role' => 'member'])->assertStatus(422)
            ->assertJsonPath('message', 'That person already holds this membership in this group.');

        $this->postJson($url, ['contact_id' => $parent->id, 'role' => 'guardian', 'guardian_of_contact_id' => $parent->id])
            ->assertStatus(422);

        $this->postJson($url, ['contact_id' => $parent->id, 'role' => 'guardian', 'guardian_of_contact_id' => $student->contact_id])
            ->assertCreated()->assertJsonPath('data.provenance', 'confirmed');

        $this->assertSame(1, GroupMembership::where('group_id', $this->first->id)->where('contact_id', $parent->id)->count());
        $this->assertSame(1, Contact::whereKey($student->contact_id)->count());
    }
}
