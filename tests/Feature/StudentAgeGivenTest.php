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
use Tests\TestCase;

/**
 * The age a family GAVE for a student (`contacts.age_given`), and the age a
 * roster shows from it when no date of birth is on file.
 *
 * A registration form that asks "how old is your child?" gives a school an age
 * for every student and a date of birth for none. So:
 *
 *  - the value is `{age}@{day}`, ciphertext at rest, with one writer and one
 *    reader on the model, hidden from every payload;
 *  - a roster shows that age brought up to today, and the OFFICE roster says
 *    which ages came from it (`age_given`), because one can be a year behind
 *    after a birthday;
 *  - a date of birth, once typed, wins; when it is removed the family's age
 *    comes back;
 *  - a teacher gets the number and nothing about where it came from;
 *  - a merge carries it; the deploy window (code before migrate) reads nothing.
 *
 * The date of birth itself is StudentBirthDateTest.
 */
class StudentAgeGivenTest extends TestCase
{
    use RefreshDatabase;

    /** The day the family answered. Nothing else in the fixture produces it. */
    private const GIVEN_ON = '2026-09-14';

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

        // Noon in New York on 6 October 2026: three weeks after the family answered.
        $this->travelTo(Carbon::parse('2026-10-06 16:00:00', 'UTC'));

        $this->school = $this->makeSchool();
        $this->admin = $this->makeAdmin($this->school);
        $this->class = $this->makeGroup(Group::KIND_CLASS, 'Grade One');

        $this->child = Contact::factory()->create([
            'masjid_id' => $this->school->id, 'first_name' => 'Tariq', 'last_name' => 'Example', 'email' => null,
        ]);
        $this->student = $this->enrol($this->class, $this->child, GroupMembership::ROLE_MEMBER);

        $this->parent = Contact::factory()->create([
            'masjid_id' => $this->school->id, 'first_name' => 'Huda', 'last_name' => 'Example', 'email' => null,
        ]);
        $this->guardianEntry = $this->enrol($this->class, $this->parent, GroupMembership::ROLE_GUARDIAN, $this->child);

        Sanctum::actingAs($this->admin, ['*']);
    }

    protected function tearDown(): void
    {
        StudentAge::forget();

        parent::tearDown();
    }

    // ------------------------------------------------ at rest, one writer, one reader

    #[Test]
    public function the_stored_value_is_ciphertext_and_reads_back_as_the_age_and_the_day(): void
    {
        $this->assertSame('set', $this->child->recordAgeGiven(6, self::GIVEN_ON, $this->admin, 'registration'));

        $raw = (string) DB::table('contacts')->where('id', $this->child->id)->value('age_given');
        $this->assertNotSame('', $raw);
        $this->assertStringNotContainsString(self::GIVEN_ON, $raw);
        $this->assertStringNotContainsString('6@', $raw);

        $fresh = Contact::findOrFail($this->child->id);
        $this->assertSame(['age' => 6, 'on' => self::GIVEN_ON], $fresh->ageGivenOrNull());
        $this->assertTrue($fresh->holdsAgeGiven());

        // The same again writes nothing; a different answer is a change; null removes it.
        $this->assertSame('unchanged', $fresh->recordAgeGiven(6, self::GIVEN_ON, $this->admin, 'registration'));
        $this->assertSame('changed', $fresh->recordAgeGiven(7, self::GIVEN_ON, $this->admin, 'registration'));
        $this->assertSame('removed', $fresh->recordAgeGiven(null, null, $this->admin, 'registration'));
        $this->assertSame('unchanged', $fresh->recordAgeGiven(null, null, $this->admin, 'registration'));
        $this->assertNull(Contact::findOrFail($this->child->id)->ageGivenOrNull());
    }

    #[Test]
    public function it_cannot_be_mass_assigned_and_is_on_no_serialised_contact(): void
    {
        $this->child->fill(['age_given' => '6@'.self::GIVEN_ON])->save();
        $this->assertNull(DB::table('contacts')->where('id', $this->child->id)->value('age_given'), 'not fillable');

        $this->child->recordAgeGiven(6, self::GIVEN_ON, $this->admin, 'registration');

        $this->assertArrayNotHasKey('age_given', Contact::findOrFail($this->child->id)->toArray());

        // The staff contact endpoints serialise the model whole: the key rides on none of them.
        $shown = $this->getJson($this->adminBase()."/contacts/{$this->child->id}")->assertOk();
        $this->assertStringNotContainsString('age_given', $shown->getContent());
        $this->assertStringNotContainsString(self::GIVEN_ON, $shown->getContent());
    }

    /** @return iterable<string, array{0: ?int, 1: ?string}> */
    public static function badAnswers(): iterable
    {
        yield 'a negative age' => [-1, self::GIVEN_ON];
        yield 'an age nobody has on a class list' => [Contact::AGE_GIVEN_MAX + 1, self::GIVEN_ON];
        yield 'no day' => [6, null];
        yield 'a day that does not exist' => [6, '2026-02-30'];
        yield 'a day in another format' => [6, '14/09/2026'];
    }

    #[Test]
    #[DataProvider('badAnswers')]
    public function the_writer_refuses_an_answer_that_is_not_an_age_and_a_real_day(?int $age, ?string $on): void
    {
        try {
            $this->child->recordAgeGiven($age, $on, $this->admin, 'registration');
            $this->fail('accepted');
        } catch (\InvalidArgumentException $e) {
            // The message does not repeat the day it was handed: it would end up in a log.
            if ($on !== null) {
                $this->assertStringNotContainsString($on, $e->getMessage());
            }
        }

        $this->assertNull(DB::table('contacts')->where('id', $this->child->id)->value('age_given'));
    }

    #[Test]
    public function the_writer_and_the_reader_have_exactly_these_callers(): void
    {
        $this->assertSame([
            'app/Http/Controllers/AdminDashboard/ContactsController.php',
        ], $this->filesCalling('->recordAgeGiven('), 'who may WRITE the age a family gave (the registration copy is a console command or an office script, never a request)');

        $this->assertSame([
            'app/Http/Controllers/AdminDashboard/ContactsController.php',
            'app/Http/Controllers/AdminDashboard/GroupBirthDateController.php',
            'app/Models/Contact.php',
            'app/Support/StudentAge.php',
        ], $this->filesCalling('->ageGivenOrNull('), 'who may READ it');

        // Nobody reads the attribute as a property but the model's own writer.
        $this->assertSame(['app/Models/Contact.php'], $this->filesCalling('->age_given'));
    }

    #[Test]
    public function setting_changing_and_removing_are_each_recorded_with_ids_and_the_verb_and_never_the_answer(): void
    {
        Log::spy();

        $this->child->recordAgeGiven(6, self::GIVEN_ON, $this->admin, 'registration');
        $this->child->recordAgeGiven(6, self::GIVEN_ON, $this->admin, 'registration');   // the same again: nothing to record
        $this->child->recordAgeGiven(7, self::GIVEN_ON, $this->admin, 'registration');
        $this->child->recordAgeGiven(null, null, null, 'registration');

        $seen = [];
        Log::shouldHaveReceived('warning')->times(3)->withArgs(function (string $message, array $context) use (&$seen): bool {
            $seen[] = [$context['verb'], $context['through'], $context['actor_user_id']];

            $line = $message.json_encode($context);

            return $context['contact_id'] === $this->child->id
                && $context['masjid_id'] === $this->school->id
                && array_keys($context) === ['contact_id', 'masjid_id', 'actor_user_id', 'verb', 'through']
                && ! str_contains($line, self::GIVEN_ON)
                && ! str_contains($line, '6@')
                && ! str_contains($line, '7@');
        });

        $this->assertSame([
            ['set', 'registration', $this->admin->id],
            ['changed', 'registration', $this->admin->id],
            ['removed', 'registration', null],
        ], $seen);
    }

    // ------------------------------------------------ what a roster shows

    #[Test]
    public function the_office_roster_shows_the_age_the_family_gave_and_says_that_it_is_that(): void
    {
        $this->assertSame(['age' => null, 'age_given' => false], $this->onRoster());

        $this->child->recordAgeGiven(6, self::GIVEN_ON, $this->admin, 'registration');

        $this->assertSame(['age' => 6, 'age_given' => true], $this->onRoster());

        // On the student's row and no other: the guardian entry has no age.
        $rows = collect($this->getJson($this->rosterUrl())->assertOk()->json('data'))->keyBy('id');
        $this->assertNull($rows[$this->guardianEntry->id]['age']);
        $this->assertFalse($rows[$this->guardianEntry->id]['age_given']);

        // The roster carries the number and the flag, never the answer or its day.
        $payload = $this->getJson($this->rosterUrl())->assertOk()->getContent();
        $this->assertStringNotContainsString(self::GIVEN_ON, $payload);
        $this->assertStringNotContainsString('6@', $payload);
    }

    #[Test]
    public function the_age_given_grows_by_the_whole_years_since_the_day_it_was_given_on_the_schools_clock(): void
    {
        $this->child->recordAgeGiven(6, self::GIVEN_ON, $this->admin, 'registration');
        $contact = Contact::findOrFail($this->child->id);

        // The day before a year is up, the day itself, and two years on.
        $this->assertSame(['age' => 6, 'given' => true], StudentAge::shown($contact, '2027-09-13'));
        $this->assertSame(['age' => 7, 'given' => true], StudentAge::shown($contact, '2027-09-14'));
        $this->assertSame(['age' => 8, 'given' => true], StudentAge::shown($contact, '2028-10-01'));

        // Given late on a day that, on the school's clock, has not come yet: shown as given, never blank.
        $this->assertSame(['age' => 6, 'given' => true], StudentAge::shown($contact, '2026-09-13'));

        // A today that is not a day gives nothing rather than a guess.
        $this->assertSame(['age' => null, 'given' => false], StudentAge::shown($contact, 'not-a-day'));
        $this->assertNull(StudentAge::fromGiven(null, '2026-10-06'));

        // And through the roster, a year and a day after the fixture's today.
        $this->travelTo(Carbon::parse('2027-10-07 16:00:00', 'UTC'));
        $this->assertSame(['age' => 7, 'age_given' => true], $this->onRoster());
    }

    #[Test]
    public function a_date_of_birth_wins_and_the_familys_age_comes_back_when_the_date_is_removed(): void
    {
        $this->child->recordAgeGiven(6, self::GIVEN_ON, $this->admin, 'registration');

        // The form reads what the roster shows, and no date.
        $this->getJson($this->birthDateUrl())->assertOk()->assertExactJson([
            'status' => 'success',
            'data' => ['date_of_birth' => null, 'age' => 6, 'age_given' => true, 'unreadable' => false, 'school_today' => '2026-10-06'],
        ]);

        // The office types the date: the family was a year out, and the exact age replaces theirs.
        $this->putJson($this->birthDateUrl(), ['date_of_birth' => '2019-03-09'])->assertOk()
            ->assertJsonPath('data.age', 7)
            ->assertJsonPath('data.age_given', false);
        $this->assertSame(['age' => 7, 'age_given' => false], $this->onRoster());

        // The family's answer is still on the record, unread while a date is there.
        $this->assertSame(['age' => 6, 'on' => self::GIVEN_ON], Contact::findOrFail($this->child->id)->ageGivenOrNull());

        // Removing the date: the clear answers what it answers for everybody, and the
        // row's own route and the roster say what is shown now. Not a dash.
        $this->deleteJson($this->clearUrl())->assertOk()->assertExactJson([
            'status' => 'success',
            'message' => 'Date of birth removed.',
            'data' => ['date_of_birth' => null, 'age' => null, 'age_given' => false, 'unreadable' => false],
        ]);
        $this->getJson($this->birthDateUrl())->assertOk()
            ->assertJsonPath('data.age', 6)
            ->assertJsonPath('data.age_given', true);
        $this->assertSame(['age' => 6, 'age_given' => true], $this->onRoster());
    }

    /**
     * The clear takes ANY contact of the organisation, so its answer must not
     * depend on what that contact holds: an age in it would tell the office
     * that somebody who is not a student, or is on no roster any more, has one
     * on file.
     */
    #[Test]
    public function clearing_a_date_answers_the_same_whoever_holds_an_age_given(): void
    {
        $expected = [
            'status' => 'success',
            'message' => 'Date of birth removed.',
            'data' => ['date_of_birth' => null, 'age' => null, 'age_given' => false, 'unreadable' => false],
        ];

        // A student holding one; a guardian (not a student) holding one; a deleted contact on no roster holding one.
        $this->child->recordAgeGiven(6, self::GIVEN_ON, $this->admin, 'registration');
        $this->parent->recordAgeGiven(7, self::GIVEN_ON, $this->admin, 'registration');
        $gone = Contact::factory()->create(['masjid_id' => $this->school->id, 'email' => null]);
        $gone->recordAgeGiven(9, self::GIVEN_ON, $this->admin, 'registration');
        $gone->delete();
        $nothing = Contact::factory()->create(['masjid_id' => $this->school->id, 'email' => null]);

        foreach ([$this->child, $this->parent, $gone, $nothing] as $contact) {
            $this->deleteJson($this->adminBase()."/contacts/{$contact->id}/birth-date")->assertOk()->assertExactJson($expected);
        }

        // The row's own route answers for a student in a class and nobody else.
        $this->getJson($this->rosterUrl().'/'.$this->guardianEntry->id.'/birth-date')->assertStatus(422);
    }

    #[Test]
    public function a_date_of_birth_that_cannot_be_read_falls_back_to_the_familys_age_on_the_roster(): void
    {
        $this->child->recordAgeGiven(6, self::GIVEN_ON, $this->admin, 'registration');
        DB::table('contacts')->where('id', $this->child->id)->update(['date_of_birth' => 'not-a-ciphertext']);

        Log::spy();
        $this->assertSame(['age' => 6, 'age_given' => true], $this->onRoster());
        Log::shouldHaveReceived('error')->once();
    }

    #[Test]
    public function an_answer_that_cannot_be_read_is_no_age_and_one_error_line_without_the_value(): void
    {
        DB::table('contacts')->where('id', $this->child->id)->update(['age_given' => 'not-a-ciphertext']);

        Log::spy();
        $this->assertSame(['age' => null, 'age_given' => false], $this->onRoster());

        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context): bool => str_contains($message, 'age given by a family could not be read')
            && $context === ['contact_id' => $this->child->id, 'masjid_id' => $this->school->id]);

        // Still "something is held", so it can be written over.
        $contact = Contact::findOrFail($this->child->id);
        $this->assertTrue($contact->holdsAgeGiven());
        $this->assertSame('changed', $contact->recordAgeGiven(6, self::GIVEN_ON, $this->admin, 'registration'));
        $this->assertSame(['age' => 6, 'age_given' => true], $this->onRoster());
    }

    #[Test]
    public function a_group_that_is_not_a_class_shows_no_age_from_it(): void
    {
        $this->child->recordAgeGiven(6, self::GIVEN_ON, $this->admin, 'registration');

        $circle = $this->makeGroup(Group::KIND_HALAQA, 'Evening circle');
        $row = $this->enrol($circle, $this->child, GroupMembership::ROLE_MEMBER);

        $rows = collect($this->getJson($this->rosterUrl($circle))->assertOk()->json('data'))->keyBy('id');
        $this->assertNull($rows[$row->id]['age']);
        $this->assertFalse($rows[$row->id]['age_given']);
    }

    #[Test]
    public function a_teacher_gets_the_number_and_nothing_about_where_it_came_from(): void
    {
        $this->child->recordAgeGiven(6, self::GIVEN_ON, $this->admin, 'registration');

        $teacher = User::factory()->create(['type' => 'Teacher', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
        MasjidUser::create(['masjid_id' => $this->school->id, 'user_id' => $teacher->id, 'role' => 'teacher', 'is_default' => true]);
        $this->class->staff()->attach($teacher->id, [
            'masjid_id' => $this->school->id, 'role' => GroupStaff::ROLE_TEACHER, 'assigned_at' => now(),
        ]);

        Sanctum::actingAs($teacher, ['staff']);

        $response = $this->getJson("/api/teacher/masjids/{$this->school->id}/groups/{$this->class->id}")->assertOk();
        $response->assertJsonPath('data.students.0.age', 6);

        $content = $response->getContent();
        $this->assertStringNotContainsString('age_given', $content);
        $this->assertStringNotContainsString(self::GIVEN_ON, $content);
    }

    // ------------------------------------------------ merge

    #[Test]
    public function a_merge_carries_the_familys_age_onto_a_kept_record_that_has_none(): void
    {
        $this->child->recordAgeGiven(6, self::GIVEN_ON, $this->admin, 'registration');
        $kept = Contact::factory()->create(['masjid_id' => $this->school->id, 'first_name' => 'Tariq', 'email' => null]);

        $response = $this->postJson($this->adminBase()."/contacts/{$this->child->id}/merge", ['target_contact_id' => $kept->id])->assertOk();

        $this->assertStringNotContainsString(self::GIVEN_ON, $response->getContent());
        $this->assertSame(['age' => 6, 'on' => self::GIVEN_ON], Contact::findOrFail($kept->id)->ageGivenOrNull());
        $this->assertNull(Contact::withTrashed()->find($this->child->id), 'the absorbed record is gone');
    }

    #[Test]
    public function a_merge_keeps_the_kept_records_own_answer(): void
    {
        $this->child->recordAgeGiven(6, self::GIVEN_ON, $this->admin, 'registration');
        $kept = Contact::factory()->create(['masjid_id' => $this->school->id, 'first_name' => 'Tariq', 'email' => null]);
        $kept->recordAgeGiven(7, '2026-09-20', $this->admin, 'registration');

        $this->postJson($this->adminBase()."/contacts/{$this->child->id}/merge", ['target_contact_id' => $kept->id])->assertOk();

        $this->assertSame(['age' => 7, 'on' => '2026-09-20'], Contact::findOrFail($kept->id)->ageGivenOrNull());
    }

    // ------------------------------------------------ the deploy window

    /**
     * bin/deploy serves this code before `migrate` adds the column. For that
     * window a roster still answers, with the ages dates of birth give, and no
     * statement names the missing column (SQLite would read the name as a
     * string and pass, so the statements themselves are the proof).
     */
    #[Test]
    public function before_the_column_exists_the_rosters_and_the_date_form_still_answer(): void
    {
        $this->child->recordDateOfBirth('2019-03-09', $this->admin, 'roster');

        Schema::table('contacts', fn ($table) => $table->dropColumn('age_given'));
        StudentAge::forget();

        $named = [];
        DB::listen(function ($query) use (&$named): void {
            if (str_contains($query->sql, 'age_given') && ! str_contains($query->sql, 'pragma')) {
                $named[] = $query->sql;
            }
        });

        $this->assertSame(['age' => 7, 'age_given' => false], $this->onRoster());
        $this->getJson($this->birthDateUrl())->assertOk()->assertJsonPath('data.age', 7)->assertJsonPath('data.age_given', false);
        $this->deleteJson($this->clearUrl())->assertOk()->assertJsonPath('data.age', null)->assertJsonPath('data.age_given', false);
        $this->assertSame(['age' => null, 'age_given' => false], $this->onRoster());

        $kept = Contact::factory()->create(['masjid_id' => $this->school->id, 'email' => null]);
        $this->postJson($this->adminBase()."/contacts/{$this->parent->id}/merge", ['target_contact_id' => $kept->id])->assertOk();

        $this->assertSame([], $named, 'no statement names the column before it exists');
        $this->assertFalse(StudentAge::givenColumnExists());
        $this->assertTrue(StudentAge::columnExists(), 'the date of birth has its own answer');
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

    private function birthDateUrl(): string
    {
        return $this->rosterUrl().'/'.$this->student->id.'/birth-date';
    }

    private function clearUrl(): string
    {
        return $this->adminBase().'/contacts/'.$this->child->id.'/birth-date';
    }

    /** @return array{age: ?int, age_given: bool} what the office roster says on the student's row */
    private function onRoster(): array
    {
        $row = collect($this->getJson($this->rosterUrl())->assertOk()->json('data'))->firstWhere('id', $this->student->id);

        return ['age' => $row['age'], 'age_given' => $row['age_given']];
    }

    /** @return list<string> the files under app/ that contain `$needle`, sorted */
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
            'name' => 'Age Given Test School '.uniqid(),
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
