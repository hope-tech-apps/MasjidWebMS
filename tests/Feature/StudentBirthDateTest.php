<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupStaff;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use App\Support\StudentAge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A student's date of birth, and the age a roster shows from it.
 *
 * What this file pins, in the order a reader would ask:
 *
 *  1. The value is ciphertext at rest, has ONE writer and ONE reader, and the
 *     list of callers of each is fixed.
 *  2. The office reads and sets it for a STUDENT IN A CLASS only, and can clear
 *     it for anybody, whatever became of the roster row.
 *  3. The age is whole years on the SCHOOL's clock.
 *  4. One value that cannot be read never breaks a roster, an export or a
 *     merge, and is never silent.
 *  5. Who set, changed or removed a date is recorded at a level production
 *     keeps, without the date.
 *  6. The rosters keep working in the seconds between a deploy's checkout and
 *     its migrate, when the column is not there yet.
 *
 * Where the date and the age must NOT appear is StudentBirthDateLeakTest.
 */
class StudentBirthDateTest extends TestCase
{
    use RefreshDatabase;

    /** A day nothing else in the fixture could produce. */
    private const SENTINEL = '2017-03-09';

    private Masjid $school;
    private User $admin;
    private Group $class;
    private Contact $child;
    private GroupMembership $student;
    private Contact $parent;
    private GroupMembership $guardianEntry;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        StudentAge::forget();

        // Noon in New York on a fixed day, so "today" is the same day on the
        // server's clock and the school's unless a test moves it.
        $this->travelTo(Carbon::parse('2026-10-06 16:00:00', 'UTC'));

        $this->school = $this->makeSchool();
        $this->admin = $this->makeAdmin($this->school);

        $this->class = $this->makeGroup(Group::KIND_CLASS, 'Grade Two');

        $this->child = Contact::factory()->create([
            'masjid_id' => $this->school->id, 'first_name' => 'Sami', 'last_name' => 'Example', 'email' => null,
        ]);
        $this->student = $this->enrol($this->class, $this->child, GroupMembership::ROLE_MEMBER);

        $this->parent = Contact::factory()->create([
            'masjid_id' => $this->school->id, 'first_name' => 'Rana', 'last_name' => 'Example', 'email' => null,
        ]);
        $this->guardianEntry = $this->enrol($this->class, $this->parent, GroupMembership::ROLE_GUARDIAN, $this->child);

        Sanctum::actingAs($this->admin, ['*']);
    }

    protected function tearDown(): void
    {
        StudentAge::forget();

        parent::tearDown();
    }

    // ------------------------------------------------ 1. at rest, one writer, one reader

    #[Test]
    public function the_stored_value_is_ciphertext_and_reads_back_through_the_one_reader(): void
    {
        $this->assertSame('set', $this->child->recordDateOfBirth(self::SENTINEL, $this->admin, 'roster'));

        $raw = (string) DB::table('contacts')->where('id', $this->child->id)->value('date_of_birth');

        $this->assertNotSame('', $raw);
        $this->assertStringNotContainsString(self::SENTINEL, $raw);
        $this->assertStringNotContainsString('2017', $raw);
        $this->assertSame(self::SENTINEL, Contact::findOrFail($this->child->id)->dateOfBirthOrNull());
    }

    #[Test]
    public function the_date_cannot_be_mass_assigned(): void
    {
        $contact = Contact::create([
            'masjid_id' => $this->school->id, 'first_name' => 'Noor', 'last_name' => 'Example',
            'date_of_birth' => self::SENTINEL,
        ]);
        $contact->update(['date_of_birth' => self::SENTINEL]);

        $this->assertNull(DB::table('contacts')->where('id', $contact->id)->value('date_of_birth'));

        // ...and the office's own contact form cannot carry it in either.
        $this->putJson($this->adminBase()."/contacts/{$this->child->id}", [
            'first_name' => 'Sami', 'last_name' => 'Example', 'date_of_birth' => self::SENTINEL,
        ])->assertOk();

        $this->assertNull(DB::table('contacts')->where('id', $this->child->id)->value('date_of_birth'));
    }

    #[Test]
    public function the_writer_refuses_anything_that_is_not_a_real_day_without_repeating_it(): void
    {
        foreach (['2017-02-30', '09/03/2017', '2017-3-9', 'yesterday', ''] as $bad) {
            try {
                $this->child->recordDateOfBirth($bad, $this->admin, 'roster');
                $this->fail("{$bad} was accepted");
            } catch (\InvalidArgumentException $e) {
                if ($bad !== '') {
                    $this->assertStringNotContainsString($bad, $e->getMessage());
                }
            }
        }

        $this->assertNull(DB::table('contacts')->where('id', $this->child->id)->value('date_of_birth'));
    }

    /**
     * A source pin. The writer and the reader are public methods, so nothing in
     * the language stops a new caller; this is what makes adding one a
     * deliberate edit here.
     */
    #[Test]
    public function the_writer_the_reader_and_the_age_have_exactly_these_callers(): void
    {
        $this->assertSame([
            'app/Http/Controllers/AdminDashboard/ContactsController.php',
            'app/Http/Controllers/AdminDashboard/GroupBirthDateController.php',
        ], $this->filesCalling('->recordDateOfBirth('), 'who may WRITE a date of birth');

        $this->assertSame([
            'app/Http/Controllers/AdminDashboard/ContactsController.php',
            'app/Http/Controllers/AdminDashboard/GroupBirthDateController.php',
            'app/Http/Controllers/AdminDashboard/SchoolRecordsExportController.php',
            'app/Models/Contact.php',
            'app/Support/StudentAge.php',
        ], $this->filesCalling('->dateOfBirthOrNull('), 'who may READ a date of birth');

        $this->assertSame([
            'app/Http/Controllers/AdminDashboard/ContactsController.php',
            'app/Http/Controllers/AdminDashboard/GroupBirthDateController.php',
            'app/Http/Controllers/AdminDashboard/GroupMembershipsController.php',
            'app/Http/Controllers/Teacher/TeacherController.php',
        ], $this->filesCalling('StudentAge::'), 'who may ask for an age');

        // Nobody reads the attribute as a property: that would decrypt outside
        // the one reader and throw on a value written under another key. The
        // model's own two assignments are the writer. (An appointment request
        // has a date of birth of its own, on another model.)
        $propertyReads = array_values(array_filter(
            $this->filesCalling('->date_of_birth'),
            fn (string $file): bool => ! str_contains($file, 'Appointment'),
        ));
        $this->assertSame(['app/Models/Contact.php'], $propertyReads);
    }

    #[Test]
    public function only_a_class_teaches_students(): void
    {
        $this->assertTrue($this->class->teachesStudents());

        foreach ([Group::KIND_HALAQA, Group::KIND_TEAM, Group::KIND_GENERAL] as $kind) {
            $this->assertFalse($this->makeGroup($kind, 'A '.$kind)->teachesStudents(), $kind);
        }
    }

    // ------------------------------------------------ 2. the office's three routes

    #[Test]
    public function the_office_sets_reads_and_changes_a_students_date(): void
    {
        $this->getJson($this->birthDateUrl())->assertOk()->assertExactJson([
            'status' => 'success',
            'data' => ['date_of_birth' => null, 'age' => null, 'age_given' => false, 'unreadable' => false, 'school_today' => '2026-10-06'],
        ]);

        $this->putJson($this->birthDateUrl(), ['date_of_birth' => self::SENTINEL])->assertOk()->assertExactJson([
            'status' => 'success',
            'message' => 'Date of birth saved.',
            'data' => ['date_of_birth' => self::SENTINEL, 'age' => 9, 'age_given' => false, 'unreadable' => false, 'school_today' => '2026-10-06'],
        ]);

        $this->getJson($this->birthDateUrl())->assertOk()
            ->assertJsonPath('data.date_of_birth', self::SENTINEL)
            ->assertJsonPath('data.age', 9);

        $this->putJson($this->birthDateUrl(), ['date_of_birth' => '2018-01-01'])->assertOk()
            ->assertJsonPath('data.date_of_birth', '2018-01-01')
            ->assertJsonPath('data.age', 8);

        // The roster carries the number, on the student's row and no other.
        $rows = collect($this->getJson($this->rosterUrl())->assertOk()->json('data'))->keyBy('id');
        $this->assertSame(8, $rows[$this->student->id]['age']);
        $this->assertNull($rows[$this->guardianEntry->id]['age']);
    }

    #[Test]
    public function a_student_with_no_date_has_a_null_age_on_the_roster(): void
    {
        $rows = collect($this->getJson($this->rosterUrl())->assertOk()->json('data'))->keyBy('id');

        $this->assertArrayHasKey('age', $rows[$this->student->id]);
        $this->assertNull($rows[$this->student->id]['age']);
    }

    #[Test]
    #[DataProvider('refusedDates')]
    public function a_date_that_is_not_a_plain_past_day_is_refused(mixed $value, string $why): void
    {
        // The house envelope for a refused form (BaseFormRequest): the field's
        // own sentence under `data`.
        $this->putJson($this->birthDateUrl(), ['date_of_birth' => $value])
            ->assertStatus(422)
            ->assertJsonPath('status', 'failed')
            ->assertJsonStructure(['data' => ['date_of_birth']]);

        $this->assertNull(DB::table('contacts')->where('id', $this->child->id)->value('date_of_birth'), $why);
    }

    public static function refusedDates(): array
    {
        return [
            'missing' => [null, 'nothing sent'],
            'empty' => ['', 'an empty string is not "remove"'],
            'another format' => ['03/09/2017', 'the encrypted value must be canonical'],
            'no leading zeros' => ['2017-3-9', 'the encrypted value must be canonical'],
            'not a real day' => ['2017-02-30', 'February has no 30th'],
            'with a time' => ['2017-03-09 10:00:00', 'a day, not a moment'],
            'tomorrow' => ['2026-10-07', 'not in the future'],
            'before 1900' => ['1899-12-31', 'not before 1900'],
            'the year 1900 itself' => ['1900-01-01', 'after 1900-01-01, as an appointment request reads it'],
        ];
    }

    /**
     * 01:30 UTC on the 7th is 21:30 on the 6th in New York. The server's
     * `today` would accept the 7th; the school's does not, and the age (which
     * is worked out on the school's clock) would have read it as a future date
     * and shown nothing, with no reason given.
     */
    #[Test]
    public function not_in_the_future_is_judged_on_the_schools_clock(): void
    {
        $this->travelTo(Carbon::parse('2026-10-07 01:30:00', 'UTC'));

        $this->putJson($this->birthDateUrl(), ['date_of_birth' => '2026-10-07'])
            ->assertStatus(422)
            ->assertJsonPath('data.date_of_birth.0', 'The date of birth cannot be in the future.');

        $this->putJson($this->birthDateUrl(), ['date_of_birth' => '2026-10-06'])->assertOk()
            ->assertJsonPath('data.age', 0)
            ->assertJsonPath('data.school_today', '2026-10-06');
    }

    #[Test]
    public function reading_and_setting_are_for_a_student_in_a_class_and_nobody_else(): void
    {
        // The same person holds a date (typed while they were a student in a
        // class) and also sits on rosters where no age is to be shown.
        $this->child->recordDateOfBirth(self::SENTINEL, $this->admin, 'roster');
        $this->parent->recordDateOfBirth('1985-05-05', $this->admin, 'roster');

        $leader = $this->enrol($this->class, $this->parent, GroupMembership::ROLE_LEADER);

        $refused = [
            'a guardian entry' => [$this->class, $this->guardianEntry],
            'a legacy leader row' => [$this->class, $leader],
        ];
        foreach ([Group::KIND_HALAQA, Group::KIND_TEAM, Group::KIND_GENERAL] as $kind) {
            $group = $this->makeGroup($kind, 'A '.$kind);
            $refused["a member of a {$kind}"] = [$group, $this->enrol($group, $this->child, GroupMembership::ROLE_MEMBER)];
        }

        foreach ($refused as $what => [$group, $row]) {
            $url = $this->birthDateUrl($group, $row);

            foreach ([$this->getJson($url), $this->putJson($url, ['date_of_birth' => '2016-06-06'])] as $response) {
                $response->assertStatus(422)->assertExactJson([
                    'status' => 'error',
                    'message' => 'A date of birth is kept only for students in a class.',
                ]);
            }

            // ...and the roster list shows no age for that row, although the
            // contact holds a date.
            $rows = collect($this->getJson($this->rosterUrl($group))->assertOk()->json('data'))->keyBy('id');
            $this->assertNull($rows[$row->id]['age'], $what);
        }

        // Nothing was written by any of the refused PUTs.
        $this->assertSame(self::SENTINEL, Contact::findOrFail($this->child->id)->dateOfBirthOrNull());
        $this->assertSame('1985-05-05', Contact::findOrFail($this->parent->id)->dateOfBirthOrNull());
    }

    #[Test]
    public function an_id_from_somewhere_else_is_a_miss(): void
    {
        $otherClass = $this->makeGroup(Group::KIND_CLASS, 'Grade Five');

        $elsewhere = $this->makeSchool();
        $theirClass = Group::factory()->create(['masjid_id' => $elsewhere->id, 'kind' => Group::KIND_CLASS, 'slug' => 'theirs-'.uniqid()]);
        $theirChild = Contact::factory()->create(['masjid_id' => $elsewhere->id, 'email' => null]);
        $theirStudent = GroupMembership::create([
            'masjid_id' => $elsewhere->id, 'group_id' => $theirClass->id,
            'contact_id' => $theirChild->id, 'role' => GroupMembership::ROLE_MEMBER,
        ]);
        $theirChild->recordDateOfBirth(self::SENTINEL, null, 'roster');

        $misses = [
            // The row is real, but it is not in the class the URL names.
            $this->adminBase()."/groups/{$otherClass->id}/members/{$this->student->id}/birth-date",
            // Another organisation's class and row, under this one's id.
            $this->adminBase()."/groups/{$theirClass->id}/members/{$theirStudent->id}/birth-date",
            $this->adminBase()."/groups/{$this->class->id}/members/{$theirStudent->id}/birth-date",
        ];

        foreach ($misses as $url) {
            $this->getJson($url)->assertNotFound();
            $this->putJson($url, ['date_of_birth' => '2016-06-06'])->assertNotFound();
        }

        $this->deleteJson($this->adminBase()."/contacts/{$theirChild->id}/birth-date")->assertNotFound();

        // ...and naming their organisation in the URL is refused outright.
        $this->getJson("/api/admin/masjids/{$elsewhere->id}/groups/{$theirClass->id}/members/{$theirStudent->id}/birth-date")
            ->assertForbidden();
        $this->deleteJson("/api/admin/masjids/{$elsewhere->id}/contacts/{$theirChild->id}/birth-date")
            ->assertForbidden();

        $this->assertSame(
            self::SENTINEL,
            Contact::withoutMasjidScope()->findOrFail($theirChild->id)->dateOfBirthOrNull(),
            'another organisation\'s date was not touched'
        );
    }

    #[Test]
    public function a_read_only_login_gets_the_age_on_the_roster_and_none_of_the_three_routes(): void
    {
        $this->child->recordDateOfBirth(self::SENTINEL, $this->admin, 'roster');

        Role::findByName('masjid-admin', 'web')->revokePermissionTo('manage contacts');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->getJson($this->birthDateUrl())->assertForbidden();
        $this->putJson($this->birthDateUrl(), ['date_of_birth' => '2016-06-06'])->assertForbidden();
        $this->deleteJson($this->clearUrl())->assertForbidden();

        $rows = collect($this->getJson($this->rosterUrl())->assertOk()->json('data'))->keyBy('id');
        $this->assertSame(9, $rows[$this->student->id]['age']);

        $this->assertSame(self::SENTINEL, Contact::findOrFail($this->child->id)->dateOfBirthOrNull());
    }

    #[Test]
    public function a_teacher_and_lunch_staff_cannot_reach_the_three_routes(): void
    {
        $this->child->recordDateOfBirth(self::SENTINEL, $this->admin, 'roster');

        foreach (['Teacher' => 'teacher', User::TYPE_LUNCH_STAFF => 'lunch-staff'] as $type => $role) {
            $user = User::factory()->create(['type' => $type, 'phone' => '+1'.random_int(1000000000, 9999999999)]);
            MasjidUser::create(['masjid_id' => $this->school->id, 'user_id' => $user->id, 'role' => $role, 'is_default' => true]);

            if ($type === 'Teacher') {
                $this->class->staff()->attach($user->id, [
                    'masjid_id' => $this->school->id, 'role' => GroupStaff::ROLE_TEACHER, 'assigned_at' => now(),
                ]);
            }

            Sanctum::actingAs($user, ['*']);

            // Refused at the realm gate, before any permission is consulted.
            $this->getJson($this->birthDateUrl())->assertUnauthorized();
            $this->putJson($this->birthDateUrl(), ['date_of_birth' => '2016-06-06'])->assertUnauthorized();
            $this->deleteJson($this->clearUrl())->assertUnauthorized();
        }

        $this->assertSame(self::SENTINEL, Contact::findOrFail($this->child->id)->dateOfBirthOrNull());
    }

    // ------------------------------------------------ clearing: by contact, never refused

    #[Test]
    public function clearing_names_the_contact_and_answers_the_same_whether_or_not_a_date_was_held(): void
    {
        $this->child->recordDateOfBirth(self::SENTINEL, $this->admin, 'roster');

        $expected = [
            'status' => 'success',
            'message' => 'Date of birth removed.',
            'data' => ['date_of_birth' => null, 'age' => null, 'age_given' => false, 'unreadable' => false],
        ];

        $this->deleteJson($this->clearUrl())->assertOk()->assertExactJson($expected);
        $this->assertNull(DB::table('contacts')->where('id', $this->child->id)->value('date_of_birth'));

        // Again, with nothing there; and for somebody who never was a student.
        $this->deleteJson($this->clearUrl())->assertOk()->assertExactJson($expected);
        $this->deleteJson($this->clearUrl($this->parent))->assertOk()->assertExactJson($expected);
    }

    /**
     * The date is on the contact, so it outlives the roster row that let the
     * office type it. Each of these used to leave it out of reach.
     */
    #[Test]
    public function a_date_can_still_be_cleared_after_the_roster_row_is_gone(): void
    {
        // Removed from their only class.
        $this->child->recordDateOfBirth(self::SENTINEL, $this->admin, 'roster');
        $this->deleteJson($this->adminBase()."/groups/{$this->class->id}/members/{$this->student->id}")->assertOk();
        $this->getJson($this->birthDateUrl())->assertNotFound();
        $this->deleteJson($this->clearUrl())->assertOk();
        $this->assertNull(DB::table('contacts')->where('id', $this->child->id)->value('date_of_birth'));

        // The class archived under them.
        $second = Contact::factory()->create(['masjid_id' => $this->school->id, 'email' => null]);
        $row = $this->enrol($this->class, $second, GroupMembership::ROLE_MEMBER);
        $second->recordDateOfBirth(self::SENTINEL, $this->admin, 'roster');
        $this->class->delete();
        $this->getJson($this->birthDateUrl($this->class, $row))->assertNotFound();
        $this->deleteJson($this->clearUrl($second))->assertOk();
        $this->assertNull(DB::table('contacts')->where('id', $second->id)->value('date_of_birth'));

        // The class turned into something that is not a class.
        $third = Contact::factory()->create(['masjid_id' => $this->school->id, 'email' => null]);
        $other = $this->makeGroup(Group::KIND_CLASS, 'Was a class');
        $thirdRow = $this->enrol($other, $third, GroupMembership::ROLE_MEMBER);
        $third->recordDateOfBirth(self::SENTINEL, $this->admin, 'roster');
        $other->update(['kind' => Group::KIND_HALAQA]);
        $this->getJson($this->birthDateUrl($other, $thirdRow))->assertStatus(422);
        $this->deleteJson($this->clearUrl($third))->assertOk();
        $this->assertNull(DB::table('contacts')->where('id', $third->id)->value('date_of_birth'));

        // The contact itself deleted by the office (a soft delete keeps every column).
        $fourth = Contact::factory()->create(['masjid_id' => $this->school->id, 'email' => null]);
        $fourth->recordDateOfBirth(self::SENTINEL, $this->admin, 'roster');
        $fourth->delete();
        $this->assertNotNull(DB::table('contacts')->where('id', $fourth->id)->value('date_of_birth'));
        $this->deleteJson($this->clearUrl($fourth))->assertOk();
        $this->assertNull(DB::table('contacts')->where('id', $fourth->id)->value('date_of_birth'));
        $this->assertNotNull(DB::table('contacts')->where('id', $fourth->id)->value('deleted_at'), 'clearing did not restore the contact');
    }

    #[Test]
    public function removing_a_student_from_the_roster_says_when_their_date_is_still_on_file(): void
    {
        $this->child->recordDateOfBirth(self::SENTINEL, $this->admin, 'roster');

        $response = $this->deleteJson($this->adminBase()."/groups/{$this->class->id}/members/{$this->student->id}")->assertOk();

        $this->assertStringEndsWith('Their date of birth is still on their record.', $response->json('message'));
        $response->assertJsonPath('data.birth_date', ['held' => true, 'contact_id' => $this->child->id]);
        $this->assertStringNotContainsString(self::SENTINEL, $response->getContent());

        // The date stayed: Remove takes the roster row, not what the office typed about the child.
        $this->assertSame(self::SENTINEL, Contact::findOrFail($this->child->id)->dateOfBirthOrNull());
    }

    /**
     * The date is on the contact, and another class still shows the age from
     * it. This is the ordinary case after a move, whose own answer invites the
     * office to remove the empty old entry: offering "Remove the date of birth"
     * there wiped the age of a current student.
     */
    #[Test]
    public function removing_one_of_a_students_two_class_rows_names_the_class_that_still_uses_the_date_and_offers_no_clear(): void
    {
        $this->child->recordDateOfBirth(self::SENTINEL, $this->admin, 'roster');

        $third = $this->makeGroup(Group::KIND_CLASS, 'Grade Three');
        $there = $this->enrol($third, $this->child, GroupMembership::ROLE_MEMBER);
        $circle = $this->makeGroup(Group::KIND_HALAQA, 'Evening circle');
        $inCircle = $this->enrol($circle, $this->child, GroupMembership::ROLE_MEMBER);

        $told = fn (string $classes): string => "Their date of birth is still on their record: they are still listed in {$classes}, "
            .'where it gives their age. It can be changed or removed from their details there.';

        // From a group that is not a class, then from one of the two classes: each names what is left.
        foreach ([[$circle, $inCircle, 'Grade Two and Grade Three'], [$this->class, $this->student, 'Grade Three']] as [$group, $row, $classes]) {
            $response = $this->deleteJson($this->adminBase()."/groups/{$group->id}/members/{$row->id}")->assertOk();

            $this->assertStringEndsWith($told($classes), $response->json('message'));
            $this->assertArrayNotHasKey('birth_date', $response->json('data'), 'the clear was offered while a class still uses the date');
            $this->assertStringNotContainsString(self::SENTINEL, $response->getContent());
        }

        $age = collect($this->getJson($this->rosterUrl($third))->assertOk()->json('data'))->firstWhere('id', $there->id)['age'];
        $this->assertSame(9, $age, 'the class the student is still in lost their age');
    }

    /** Marked as left is still listed: that roster shows the age and holds the date form. Current classes are named first. */
    #[Test]
    public function a_class_row_marked_as_left_still_counts_and_a_current_class_is_named_first(): void
    {
        $this->child->recordDateOfBirth(self::SENTINEL, $this->admin, 'roster');

        $left = $this->enrol($this->makeGroup(Group::KIND_CLASS, 'Grade One'), $this->child, GroupMembership::ROLE_MEMBER);
        $left->markLeftByStaff($this->admin, '2026-09-10')->save();
        $third = $this->makeGroup(Group::KIND_CLASS, 'Grade Three');
        $current = $this->enrol($third, $this->child, GroupMembership::ROLE_MEMBER);

        $response = $this->deleteJson($this->adminBase()."/groups/{$this->class->id}/members/{$this->student->id}")->assertOk();
        $this->assertStringContainsString('they are still listed in Grade Three and Grade One, where', $response->json('message'));
        $this->assertArrayNotHasKey('birth_date', $response->json('data'));

        $response = $this->deleteJson($this->adminBase()."/groups/{$third->id}/members/{$current->id}")->assertOk();
        $this->assertStringContainsString('they are still listed in Grade One, where', $response->json('message'));
        $this->assertArrayNotHasKey('birth_date', $response->json('data'));

        // The last class row: now no roster is left to remove it from, and the clear is offered.
        $this->deleteJson($this->adminBase()."/groups/{$left->group_id}/members/{$left->id}")->assertOk()
            ->assertJsonPath('data.birth_date', ['held' => true, 'contact_id' => $this->child->id]);
    }

    /** A row in a group that is not a class, or in a class that was deleted, is no roster to remove the date from. */
    #[Test]
    public function a_row_in_a_group_that_is_not_a_class_or_in_a_deleted_class_does_not_withhold_the_clear(): void
    {
        $this->child->recordDateOfBirth(self::SENTINEL, $this->admin, 'roster');

        $this->enrol($this->makeGroup(Group::KIND_HALAQA, 'Evening circle'), $this->child, GroupMembership::ROLE_MEMBER);
        $gone = $this->makeGroup(Group::KIND_CLASS, 'Removed class');
        $this->enrol($gone, $this->child, GroupMembership::ROLE_MEMBER);
        $gone->delete();

        $response = $this->deleteJson($this->adminBase()."/groups/{$this->class->id}/members/{$this->student->id}")->assertOk();

        $this->assertStringEndsWith('Their date of birth is still on their record.', $response->json('message'));
        $response->assertJsonPath('data.birth_date', ['held' => true, 'contact_id' => $this->child->id]);
    }

    /** A deleted contact's rows read no date anywhere (no age, and the form answers 404), so a second class row withholds nothing. */
    #[Test]
    public function a_deleted_contact_with_another_class_row_is_still_offered_the_clear(): void
    {
        $this->child->recordDateOfBirth(self::SENTINEL, $this->admin, 'roster');

        $next = $this->makeGroup(Group::KIND_CLASS, 'Grade Three');
        $there = $this->enrol($next, $this->child, GroupMembership::ROLE_MEMBER);
        $this->child->delete();

        // What the sentence would have promised is not there: the other roster cannot open the date.
        $this->getJson($this->birthDateUrl($next, $there))->assertNotFound();

        $response = $this->deleteJson($this->adminBase()."/groups/{$this->class->id}/members/{$this->student->id}")->assertOk();

        $this->assertStringEndsWith('Their date of birth is still on their record.', $response->json('message'));
        $this->assertStringNotContainsString('still listed in', $response->json('message'));
        $response->assertJsonPath('data.birth_date', ['held' => true, 'contact_id' => $this->child->id]);
    }

    /** The office deleted the person in the Member Directory first: the roster row is still there, and so is the date. */
    #[Test]
    public function removing_the_row_of_a_contact_the_office_deleted_still_says_a_date_is_held(): void
    {
        $this->child->recordDateOfBirth(self::SENTINEL, $this->admin, 'roster');
        $this->child->delete();

        $this->deleteJson($this->adminBase()."/groups/{$this->class->id}/members/{$this->student->id}")->assertOk()
            ->assertJsonPath('data.birth_date', ['held' => true, 'contact_id' => $this->child->id]);
    }

    #[Test]
    public function removing_a_student_with_no_date_says_nothing_about_one(): void
    {
        $response = $this->deleteJson($this->adminBase()."/groups/{$this->class->id}/members/{$this->student->id}")->assertOk();

        $this->assertStringNotContainsString('date of birth', $response->json('message'));
        $this->assertArrayNotHasKey('birth_date', $response->json('data'));
    }

    #[Test]
    public function removing_a_guardian_entry_says_nothing_about_the_guardians_own_date(): void
    {
        $this->parent->recordDateOfBirth('1985-05-05', $this->admin, 'roster');

        $response = $this->deleteJson($this->adminBase()."/groups/{$this->class->id}/members/{$this->guardianEntry->id}")->assertOk();

        $this->assertStringNotContainsString('date of birth', $response->json('message'));
        $this->assertArrayNotHasKey('birth_date', $response->json('data'));
    }

    // ------------------------------------------------ 3. the age

    #[Test]
    #[DataProvider('ages')]
    public function the_age_is_whole_years_between_two_days(?string $born, string $today, ?int $expected): void
    {
        $this->assertSame($expected, StudentAge::fromDate($born, $today));
    }

    public static function ages(): array
    {
        return [
            'the day before a birthday' => ['2017-03-09', '2026-03-08', 8],
            'on the birthday' => ['2017-03-09', '2026-03-09', 9],
            'the day after' => ['2017-03-09', '2026-03-10', 9],
            'born today' => ['2026-10-06', '2026-10-06', 0],
            'a leap-day child on 28 Feb of a common year' => ['2016-02-29', '2026-02-28', 9],
            'a leap-day child on 1 Mar of a common year' => ['2016-02-29', '2026-03-01', 10],
            'a leap-day child on 29 Feb of a leap year' => ['2016-02-29', '2028-02-29', 12],
            'a future date' => ['2026-10-07', '2026-10-06', null],
            'no date' => [null, '2026-10-06', null],
            'not a day' => ['garbage', '2026-10-06', null],
            'not a real day' => ['2017-02-30', '2026-10-06', null],
            'another format' => ['09/03/2017', '2026-10-06', null],
            'an unreadable today' => ['2017-03-09', 'today', null],
        ];
    }

    /**
     * A birthday turns over at the school's midnight. 03:30 UTC on 9 March is
     * still 8 March in New York (22:30, standard time): the child is 8 until
     * the school's own day begins.
     */
    #[Test]
    public function a_birthday_turns_over_at_the_schools_midnight(): void
    {
        $this->child->recordDateOfBirth('2017-03-09', $this->admin, 'roster');

        $this->travelTo(Carbon::parse('2026-03-09 03:30:00', 'UTC'));
        $this->assertSame(8, $this->ageOnRoster());

        $this->travelTo(Carbon::parse('2026-03-09 05:30:00', 'UTC'));
        $this->assertSame(9, $this->ageOnRoster());
    }

    // ------------------------------------------------ 4. a value that cannot be read

    #[Test]
    public function an_unreadable_date_is_no_age_and_one_error_line_without_the_value(): void
    {
        $this->plantUnreadable($this->child);
        Log::spy();

        $rows = collect($this->getJson($this->rosterUrl())->assertOk()->json('data'))->keyBy('id');
        $this->assertNull($rows[$this->student->id]['age']);

        Log::shouldHaveReceived('error')->once()->withArgs(function (string $message, array $context): bool {
            return $context['contact_id'] === $this->child->id
                && ! str_contains($message.json_encode($context), 'not-a-ciphertext');
        });
    }

    #[Test]
    public function the_office_is_told_an_unreadable_date_is_there_and_typing_it_again_repairs_it(): void
    {
        $this->plantUnreadable($this->child);

        $this->getJson($this->birthDateUrl())->assertOk()
            ->assertJsonPath('data.date_of_birth', null)
            ->assertJsonPath('data.age', null)
            ->assertJsonPath('data.unreadable', true);

        $this->putJson($this->birthDateUrl(), ['date_of_birth' => self::SENTINEL])->assertOk()
            ->assertJsonPath('data.date_of_birth', self::SENTINEL)
            ->assertJsonPath('data.unreadable', false);

        $this->assertSame(self::SENTINEL, Contact::findOrFail($this->child->id)->dateOfBirthOrNull());
    }

    #[Test]
    public function an_unreadable_date_can_be_cleared_and_is_named_when_the_student_is_removed(): void
    {
        $this->plantUnreadable($this->child);

        $this->deleteJson($this->adminBase()."/groups/{$this->class->id}/members/{$this->student->id}")->assertOk()
            ->assertJsonPath('data.birth_date.held', true);

        $this->deleteJson($this->clearUrl())->assertOk();
        $this->assertNull(DB::table('contacts')->where('id', $this->child->id)->value('date_of_birth'));
    }

    // ------------------------------------------------ 5. who did it

    /**
     * Production runs LOG_LEVEL=warning, so an `info` line would be dropped
     * there. The level is part of what is pinned.
     */
    #[Test]
    public function setting_changing_and_removing_are_each_recorded_with_ids_and_the_verb_and_never_the_date(): void
    {
        Log::spy();

        $this->putJson($this->birthDateUrl(), ['date_of_birth' => self::SENTINEL])->assertOk();
        $this->putJson($this->birthDateUrl(), ['date_of_birth' => '2018-01-01'])->assertOk();
        $this->putJson($this->birthDateUrl(), ['date_of_birth' => '2018-01-01'])->assertOk();   // the same again: nothing to record
        $this->deleteJson($this->clearUrl())->assertOk();
        $this->deleteJson($this->clearUrl())->assertOk();                                         // nothing held: nothing to record

        $seen = [];
        Log::shouldHaveReceived('warning')->times(3)->withArgs(function (string $message, array $context) use (&$seen): bool {
            $seen[] = [$context['verb'], $context['through']];

            $line = $message.json_encode($context);

            return $context['contact_id'] === $this->child->id
                && $context['masjid_id'] === $this->school->id
                && $context['actor_user_id'] === $this->admin->id
                && array_keys($context) === ['contact_id', 'masjid_id', 'actor_user_id', 'verb', 'through']
                && ! str_contains($line, self::SENTINEL)
                && ! str_contains($line, '2018-01-01');
        });

        $this->assertSame([['set', 'roster'], ['changed', 'roster'], ['removed', 'contact']], $seen);
        Log::shouldNotHaveReceived('info');
    }

    // ------------------------------------------------ merge

    #[Test]
    public function a_merge_carries_the_date_onto_a_kept_record_that_has_none(): void
    {
        $this->child->recordDateOfBirth(self::SENTINEL, $this->admin, 'roster');
        $kept = Contact::factory()->create(['masjid_id' => $this->school->id, 'first_name' => 'Sami', 'email' => null]);

        $response = $this->postJson($this->adminBase()."/contacts/{$this->child->id}/merge", ['target_contact_id' => $kept->id])
            ->assertOk()
            ->assertJsonPath('birth_date', null);

        $this->assertStringNotContainsString(self::SENTINEL, $response->getContent());
        $this->assertSame(self::SENTINEL, Contact::findOrFail($kept->id)->dateOfBirthOrNull());
        $this->assertNull(Contact::withTrashed()->find($this->child->id), 'the absorbed record is gone');
    }

    #[Test]
    public function a_merge_keeps_the_kept_records_date_when_the_two_differ_and_says_so_without_printing_either(): void
    {
        $this->child->recordDateOfBirth(self::SENTINEL, $this->admin, 'roster');
        $kept = Contact::factory()->create(['masjid_id' => $this->school->id, 'email' => null]);
        $kept->recordDateOfBirth('2016-06-06', $this->admin, 'roster');

        $response = $this->postJson($this->adminBase()."/contacts/{$this->child->id}/merge", ['target_contact_id' => $kept->id])
            ->assertOk()
            ->assertJsonPath('birth_date.message', 'The two records had different dates of birth; the one on the kept record was kept.');

        $this->assertStringNotContainsString(self::SENTINEL, $response->getContent());
        $this->assertStringNotContainsString('2016-06-06', $response->getContent());
        $this->assertSame('2016-06-06', Contact::findOrFail($kept->id)->dateOfBirthOrNull());
    }

    #[Test]
    public function a_merge_of_two_records_with_the_same_date_says_nothing(): void
    {
        $this->child->recordDateOfBirth(self::SENTINEL, $this->admin, 'roster');
        $kept = Contact::factory()->create(['masjid_id' => $this->school->id, 'email' => null]);
        $kept->recordDateOfBirth(self::SENTINEL, $this->admin, 'roster');

        $this->postJson($this->adminBase()."/contacts/{$this->child->id}/merge", ['target_contact_id' => $kept->id])
            ->assertOk()
            ->assertJsonPath('birth_date', null);
    }

    #[Test]
    public function a_merge_of_a_record_whose_date_cannot_be_read_goes_through_and_says_it_was_not_carried(): void
    {
        $this->plantUnreadable($this->child);
        $kept = Contact::factory()->create(['masjid_id' => $this->school->id, 'email' => null]);

        $this->postJson($this->adminBase()."/contacts/{$this->child->id}/merge", ['target_contact_id' => $kept->id])
            ->assertOk()
            ->assertJsonPath('birth_date.message', 'The absorbed record held a date of birth that could not be read, so it was not carried over.');

        $this->assertNull(DB::table('contacts')->where('id', $kept->id)->value('date_of_birth'));
    }

    // ------------------------------------------------ the records export

    #[Test]
    public function the_exports_contacts_file_carries_the_date_for_live_and_deleted_contacts_alike(): void
    {
        $this->child->recordDateOfBirth(self::SENTINEL, $this->admin, 'roster');

        $gone = Contact::factory()->create(['masjid_id' => $this->school->id, 'first_name' => 'Departed', 'email' => null]);
        $this->enrol($this->class, $gone, GroupMembership::ROLE_MEMBER);
        $gone->recordDateOfBirth('2015-11-30', $this->admin, 'roster');
        $gone->delete();

        $rows = $this->contactsFile();

        $this->assertSame('Date of birth', end($rows[0]));
        $this->assertSame(self::SENTINEL, end($rows[$this->child->id]));
        $this->assertSame('2015-11-30', end($rows[$gone->id]));
        $this->assertSame('', end($rows[$this->parent->id]), 'no date on file is an empty cell');
    }

    /**
     * The export is STREAMED: the 200 and the filename are out before the first
     * row. A value that threw mid-file would leave a contacts file that stops
     * at that child and looks complete.
     */
    #[Test]
    public function an_unreadable_date_is_an_empty_cell_and_the_file_carries_on_past_it(): void
    {
        $later = Contact::factory()->create(['masjid_id' => $this->school->id, 'first_name' => 'Later', 'email' => null]);
        $this->enrol($this->class, $later, GroupMembership::ROLE_MEMBER);
        $later->recordDateOfBirth(self::SENTINEL, $this->admin, 'roster');

        $this->plantUnreadable($this->child);   // a lower id than `$later`, so it is written first

        $rows = $this->contactsFile();

        $this->assertSame('', end($rows[$this->child->id]));
        $this->assertSame(self::SENTINEL, end($rows[$later->id]));
    }

    #[Test]
    public function a_read_only_login_cannot_download_the_export(): void
    {
        Role::findByName('masjid-admin', 'web')->revokePermissionTo('manage contacts');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->get($this->adminBase().'/records/export?dataset=contacts')->assertForbidden();
    }

    // ------------------------------------------------ 6. between checkout and migrate

    /**
     * bin/deploy makes new code live BEFORE it runs `migrate`. For those
     * seconds the column does not exist, and a roster read that named it would
     * answer 500 for every class in the school.
     */
    #[Test]
    public function before_the_column_exists_the_rosters_still_answer_and_show_no_ages(): void
    {
        Schema::table('contacts', fn ($table) => $table->dropColumn('date_of_birth'));
        StudentAge::forget();

        // SQLite reads a double-quoted name that is not a column as a string,
        // so a select of the missing column would NOT fail here as it does on
        // MySQL ("Unknown column"). The statements themselves are the proof:
        // none of them may name it.
        $named = [];
        DB::listen(function ($query) use (&$named): void {
            if (str_contains($query->sql, 'date_of_birth') && ! str_contains($query->sql, 'pragma')) {
                $named[] = $query->sql;
            }
        });

        $rows = collect($this->getJson($this->rosterUrl())->assertOk()->json('data'))->keyBy('id');
        $this->assertNull($rows[$this->student->id]['age']);

        // The office is told to wait rather than shown "no date on file".
        $this->getJson($this->birthDateUrl())->assertStatus(503);
        $this->putJson($this->birthDateUrl(), ['date_of_birth' => self::SENTINEL])->assertStatus(503);
        $this->deleteJson($this->clearUrl())->assertOk();

        // Remove, a merge and the export do not name the column either.
        $this->assertNotEmpty($this->contactsFile());

        $kept = Contact::factory()->create(['masjid_id' => $this->school->id, 'email' => null]);
        $this->postJson($this->adminBase()."/contacts/{$this->parent->id}/merge", ['target_contact_id' => $kept->id])
            ->assertOk()->assertJsonPath('birth_date', null);

        $this->deleteJson($this->adminBase()."/groups/{$this->class->id}/members/{$this->student->id}")->assertOk();

        // And the teacher's class.
        $teacher = User::factory()->create(['type' => 'Teacher', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
        MasjidUser::create(['masjid_id' => $this->school->id, 'user_id' => $teacher->id, 'role' => 'teacher', 'is_default' => true]);
        $this->class->staff()->attach($teacher->id, [
            'masjid_id' => $this->school->id, 'role' => GroupStaff::ROLE_TEACHER, 'assigned_at' => now(),
        ]);
        $other = Contact::factory()->create(['masjid_id' => $this->school->id, 'email' => null]);
        $this->enrol($this->class, $other, GroupMembership::ROLE_MEMBER);

        Sanctum::actingAs($teacher, ['staff']);
        $this->getJson("/api/teacher/masjids/{$this->school->id}/groups/{$this->class->id}")->assertOk()
            ->assertJsonPath('data.students.0.age', null);
        $this->getJson("/api/teacher/masjids/{$this->school->id}/groups")->assertOk();

        $this->assertSame([], $named, 'no statement names the column before it exists');
    }

    #[Test]
    public function the_column_is_looked_for_again_once_migrate_has_run(): void
    {
        Schema::table('contacts', fn ($table) => $table->dropColumn('date_of_birth'));
        StudentAge::forget();
        $this->assertFalse(StudentAge::columnExists());

        Schema::table('contacts', fn ($table) => $table->text('date_of_birth')->nullable());

        // Remembered as missing for half a minute, unless asked fresh...
        $this->assertFalse(StudentAge::columnExists());
        $this->assertTrue(StudentAge::columnExists(fresh: true));

        // ...and by itself once the half minute is over.
        StudentAge::forget();
        Schema::table('contacts', fn ($table) => $table->dropColumn('date_of_birth'));
        $this->assertFalse(StudentAge::columnExists());
        Schema::table('contacts', fn ($table) => $table->text('date_of_birth')->nullable());
        $this->travel(31)->seconds();
        $this->assertTrue(StudentAge::columnExists());
    }

    // ------------------------------------------------------------- helpers

    private function adminBase(): string
    {
        return "/api/admin/masjids/{$this->school->id}";
    }

    private function rosterUrl(?Group $group = null): string
    {
        return $this->adminBase().'/groups/'.($group ?? $this->class)->id.'/members';
    }

    private function birthDateUrl(?Group $group = null, ?GroupMembership $row = null): string
    {
        return $this->rosterUrl($group).'/'.($row ?? $this->student)->id.'/birth-date';
    }

    private function clearUrl(?Contact $contact = null): string
    {
        return $this->adminBase().'/contacts/'.($contact ?? $this->child)->id.'/birth-date';
    }

    private function ageOnRoster(): ?int
    {
        return collect($this->getJson($this->rosterUrl())->assertOk()->json('data'))
            ->firstWhere('id', $this->student->id)['age'];
    }

    /** A value in the column that is not this application's ciphertext, as after a restore without its key. */
    private function plantUnreadable(Contact $contact): void
    {
        DB::table('contacts')->where('id', $contact->id)->update(['date_of_birth' => 'not-a-ciphertext']);
    }

    /**
     * The export's contacts file, as rows keyed by contact id (row 0 is the header).
     *
     * @return array<int, list<string>>
     */
    private function contactsFile(): array
    {
        $csv = $this->get($this->adminBase().'/records/export?dataset=contacts')->assertOk()->streamedContent();

        $rows = [];
        foreach (preg_split('/\r\n|\n/', trim(preg_replace('/^\xEF\xBB\xBF/', '', $csv))) as $i => $line) {
            $cells = str_getcsv($line);
            $rows[$i === 0 ? 0 : (int) $cells[0]] = $cells;
        }

        return $rows;
    }

    /**
     * The files under app/ that contain `$needle`, sorted, relative to the project.
     *
     * @return list<string>
     */
    private function filesCalling(string $needle): array
    {
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->getExtension() === 'php' && str_contains((string) file_get_contents($file->getPathname()), $needle)) {
                $found[] = 'app/'.ltrim(str_replace(app_path(), '', $file->getPathname()), '/');
            }
        }

        sort($found);

        return $found;
    }

    private function enrol(Group $group, Contact $contact, string $role, ?Contact $ward = null): GroupMembership
    {
        return GroupMembership::create([
            'masjid_id' => $this->school->id, 'group_id' => $group->id,
            'contact_id' => $contact->id, 'role' => $role,
            'guardian_of_contact_id' => $ward?->id,
        ]);
    }

    private function makeGroup(string $kind, string $name): Group
    {
        return Group::factory()->create([
            'masjid_id' => $this->school->id, 'kind' => $kind,
            'name' => $name, 'slug' => 'g-'.uniqid(),
        ]);
    }

    private function makeSchool(): Masjid
    {
        return Masjid::create([
            'name' => 'Birth Date Test School '.uniqid(),
            'email' => 'school-'.uniqid().'@example.test',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0,
            'crm_enabled' => true, 'org_type' => 'school',
        ]);
    }

    private function makeAdmin(Masjid $school): User
    {
        $admin = User::factory()->create([
            'type' => 'MasjidAdmin', 'phone' => '+1'.random_int(1000000000, 9999999999),
        ]);
        $school->user_id = $admin->id;
        $school->save();
        MasjidUser::create([
            'masjid_id' => $school->id, 'user_id' => $admin->id,
            'role' => 'masjid-admin', 'is_default' => true,
        ]);

        return $admin;
    }
}
