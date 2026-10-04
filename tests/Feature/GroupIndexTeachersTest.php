<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupStaff;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The office's class list says who teaches each class:
 * GET /api/admin/masjids/{masjid_id}/groups, `data.data[].teachers`.
 *
 * Owner, 2026-10-04: "It would be nice if I can see the teacher/s names on the
 * class room list. You can multi line for classes will multiple teachers and put
 * the subject next to it."
 *
 * A teacher is a staff login on `group_staff` with the teacher role, the same
 * rows the Teachers screen reads. Each one is served with what they teach IN
 * THAT CLASS, in the product's own words (GroupStaff::SUBJECT_LABELS), and null
 * for a teacher of the whole class. What this suite pins beyond the shape:
 *
 *   - an archived login is not listed, though its `group_staff` rows remain;
 *   - another organisation's teachers are never read, and a teacher shared with
 *     another organisation shows this one's subjects only;
 *   - the page's teachers are one query, whatever the number of classes;
 *   - the names are on this list and on no other payload: not the same
 *     controller's show/store/update, not the teacher realm, not the family realm.
 */
class GroupIndexTeachersTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $school;
    private Masjid $other;
    private User $admin;
    private User $otherAdmin;

    private Group $grade1;
    private Group $grade2;
    private Group $grade3;

    protected function setUp(): void
    {
        parent::setUp();

        // Force sqlite-in-memory regardless of phpunit.xml.
        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        // Groups reuse the CONTACTS permissions (see routes/admin.php), so the
        // bridged masjid-admin role must be seeded before the admins exist.
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->school = $this->makeMasjid();
        $this->other = $this->makeMasjid();

        $this->admin = $this->makeAdminFor($this->school);
        $this->otherAdmin = $this->makeAdminFor($this->other);

        // Placed, so the list's order is the order written here.
        $this->grade1 = $this->makeClass($this->school, 'Grade 1', 1);
        $this->grade2 = $this->makeClass($this->school, 'Grade 2', 2);
        $this->grade3 = $this->makeClass($this->school, 'Grade 3', 3);
    }

    // ---------- the shape ----------

    #[Test]
    public function each_class_lists_its_teachers_by_name_with_what_they_teach_in_that_class(): void
    {
        // Assigned out of name order, and Zaynab's subjects stored out of the
        // product's order, so neither order can pass by accident.
        $zaynab = $this->makeTeacher('Zaynab Idris');
        $maryam = $this->makeTeacher('Maryam Saleh');
        $amina = $this->makeTeacher('Amina Farouk');

        $this->assign($this->grade1, $zaynab, [GroupStaff::SUBJECT_ARABIC, GroupStaff::SUBJECT_QURAN]);
        $this->assign($this->grade1, $maryam, [GroupStaff::SUBJECT_ISLAMIC_STUDIES]);
        $this->assign($this->grade1, $amina, null);
        // The same teacher teaches something else in another class.
        $this->assign($this->grade2, $maryam, [GroupStaff::SUBJECT_QURAN]);

        $rows = $this->classList($this->admin, $this->school)->json('data.data');

        $this->assertSame(['Grade 1', 'Grade 2', 'Grade 3'], array_column($rows, 'name'));

        $this->assertSame([
            ['id' => $amina->id, 'name' => 'Amina Farouk', 'subjects' => null],
            ['id' => $maryam->id, 'name' => 'Maryam Saleh', 'subjects' => [
                ['value' => 'islamic_studies', 'label' => 'Islamic Studies'],
            ]],
            ['id' => $zaynab->id, 'name' => 'Zaynab Idris', 'subjects' => [
                ['value' => 'quran', 'label' => "Qur'an"],
                ['value' => 'arabic', 'label' => 'Arabic'],
            ]],
        ], $rows[0]['teachers']);

        $this->assertSame([
            ['id' => $maryam->id, 'name' => 'Maryam Saleh', 'subjects' => [
                ['value' => 'quran', 'label' => "Qur'an"],
            ]],
        ], $rows[1]['teachers']);

        // A class nobody teaches still carries the key, as an empty list.
        $this->assertSame([], $rows[2]['teachers']);
    }

    #[Test]
    public function the_subject_words_are_the_products_own(): void
    {
        $teacher = $this->makeTeacher('Huda Rahimi');
        $this->assign($this->grade1, $teacher, GroupStaff::SUBJECTS);

        $served = $this->classList($this->admin, $this->school)->json('data.data.0.teachers.0.subjects');

        $this->assertSame(
            array_map(fn (string $s) => ['value' => $s, 'label' => GroupStaff::SUBJECT_LABELS[$s]], GroupStaff::SUBJECTS),
            $served
        );
    }

    #[Test]
    public function an_empty_subject_list_is_a_teacher_of_the_whole_class_like_null(): void
    {
        // GroupStaff::teaches() reads an empty list as every subject, so the list
        // must not show such a teacher as teaching nothing.
        $teacher = $this->makeTeacher('Khalid Nasser');
        $this->assign($this->grade1, $teacher, []);

        $this->assertSame(
            [['id' => $teacher->id, 'name' => 'Khalid Nasser', 'subjects' => null]],
            $this->classList($this->admin, $this->school)->json('data.data.0.teachers')
        );
    }

    #[Test]
    public function a_stored_subject_this_build_does_not_know_is_kept_as_written_after_the_known_ones(): void
    {
        // Nothing writes one (the request rules refuse it), but a row that held
        // one and lost it here would read as a teacher of the whole class.
        $teacher = $this->makeTeacher('Sawsan Tamimi');
        $this->assign($this->grade1, $teacher, ['science', GroupStaff::SUBJECT_ARABIC]);
        $only = $this->makeTeacher('Wael Jaber');
        $this->assign($this->grade2, $only, ['science']);

        $rows = $this->classList($this->admin, $this->school)->json('data.data');

        $this->assertSame([
            ['value' => 'arabic', 'label' => 'Arabic'],
            ['value' => 'science', 'label' => 'science'],
        ], $rows[0]['teachers'][0]['subjects']);
        $this->assertSame([['value' => 'science', 'label' => 'science']], $rows[1]['teachers'][0]['subjects']);
    }

    // ---------- the order ----------

    #[Test]
    public function the_order_is_by_name_whatever_its_case_and_the_same_on_every_read(): void
    {
        // A lower-case first letter sorts after "Z" byte for byte; the list is
        // for a person to read. Two teachers of one name keep one order (by id).
        $zahra = $this->makeTeacher('Zahra Noor');
        $adam = $this->makeTeacher('adam Zaki');
        $hana = $this->makeTeacher('Hana Aziz');
        $hanaLater = $this->makeTeacher('Hana Aziz');

        foreach ([$zahra, $hanaLater, $adam, $hana] as $teacher) {
            $this->assign($this->grade1, $teacher, null);
        }

        $expected = [$adam->id, $hana->id, $hanaLater->id, $zahra->id];

        foreach ([1, 2, 3] as $read) {
            $this->assertSame(
                $expected,
                array_column($this->classList($this->admin, $this->school)->json('data.data.0.teachers'), 'id'),
                "read {$read}"
            );
        }
    }

    // ---------- who is not listed ----------

    #[Test]
    public function an_archived_teacher_is_not_listed_though_the_assignment_row_remains(): void
    {
        $staying = $this->makeTeacher('Samira Haddad');
        $archived = $this->makeTeacher('Tariq Mahmoud');

        $this->assign($this->grade1, $staying, null);
        $this->assign($this->grade1, $archived, [GroupStaff::SUBJECT_ARABIC]);
        $this->assign($this->grade2, $archived, null);

        $archived->delete();

        // The soft delete leaves group_staff alone, so the list has to do the
        // leaving out itself.
        $this->assertSame(2, GroupStaff::withoutMasjidScope()->where('user_id', $archived->id)->count());

        $response = $this->classList($this->admin, $this->school);

        $this->assertSame(['Samira Haddad'], array_column($response->json('data.data.0.teachers'), 'name'));
        $this->assertSame([], $response->json('data.data.1.teachers'));
        $this->assertStringNotContainsString('Tariq Mahmoud', $response->getContent());
    }

    #[Test]
    public function a_teacher_who_was_invited_and_has_not_signed_in_yet_is_listed(): void
    {
        // Assigned is assigned: the Teachers screen lists them (as "Invited") too.
        $invited = $this->makeTeacher('Ruqayya Hamdan');
        $invited->forceFill(['email_verified_at' => null])->save();
        $this->assign($this->grade1, $invited, null);

        $this->assertSame(
            ['Ruqayya Hamdan'],
            array_column($this->classList($this->admin, $this->school)->json('data.data.0.teachers'), 'name')
        );
    }

    #[Test]
    public function only_staff_with_the_teacher_role_are_listed(): void
    {
        $teacher = $this->makeTeacher('Nadia Suleiman');
        $aide = $this->makeTeacher('Omar Bashir');

        $this->assign($this->grade1, $teacher, null);
        // `role` is a plain column so another kind of staff can be added later
        // (the create_group_staff_table migration); such a row is not a teacher.
        $this->assign($this->grade1, $aide, null, 'aide');

        $this->assertSame(
            ['Nadia Suleiman'],
            array_column($this->classList($this->admin, $this->school)->json('data.data.0.teachers'), 'name')
        );
    }

    #[Test]
    public function another_organisations_teachers_are_never_present_and_a_shared_teacher_shows_this_organisations_subjects_only(): void
    {
        $theirClass = $this->makeClass($this->other, 'Grade 1', 1);

        $ours = $this->makeTeacher('Layla Hassan');
        $theirs = $this->makeTeacher('Idris Mansour');
        // One login, two organisations, a different subject in each.
        $shared = $this->makeTeacher('Salma Rauf');

        $this->assign($this->grade1, $ours, null);
        $this->assign($this->grade1, $shared, [GroupStaff::SUBJECT_ARABIC]);
        $this->assign($theirClass, $theirs, null);
        $this->assign($theirClass, $shared, [GroupStaff::SUBJECT_QURAN]);

        // A row that belongs to the other organisation and names OUR class: no
        // foreign key forbids it, and only the tenant scope keeps it out.
        $stray = $this->makeTeacher('Ghassan Abboud');
        $this->grade1->staff()->attach($stray->id, [
            'masjid_id' => $this->other->id,
            'role' => GroupStaff::ROLE_TEACHER,
            'assigned_at' => now(),
        ]);
        $this->assertSame(
            (int) $this->other->id,
            (int) GroupStaff::withoutMasjidScope()->where('user_id', $stray->id)->value('masjid_id')
        );

        $mine = $this->classList($this->admin, $this->school);

        $this->assertSame([
            ['id' => $ours->id, 'name' => 'Layla Hassan', 'subjects' => null],
            ['id' => $shared->id, 'name' => 'Salma Rauf', 'subjects' => [['value' => 'arabic', 'label' => 'Arabic']]],
        ], $mine->json('data.data.0.teachers'));
        $this->assertStringNotContainsString('Idris Mansour', $mine->getContent());
        $this->assertStringNotContainsString('Ghassan Abboud', $mine->getContent());

        // And the other way round: their list holds their own and none of ours.
        $their = $this->classList($this->otherAdmin, $this->other);

        $this->assertSame([
            ['id' => $theirs->id, 'name' => 'Idris Mansour', 'subjects' => null],
            ['id' => $shared->id, 'name' => 'Salma Rauf', 'subjects' => [['value' => 'quran', 'label' => "Qur'an"]]],
        ], $their->json('data.data.0.teachers'));
        $this->assertStringNotContainsString('Layla Hassan', $their->getContent());
        // The stray row names a class their list does not hold, so it shows nowhere.
        $this->assertStringNotContainsString('Ghassan Abboud', $their->getContent());
    }

    // ---------- the cost ----------

    #[Test]
    public function a_page_of_classes_reads_its_teachers_in_one_query_however_many_classes_it_holds(): void
    {
        // Five more classes, so the page holds eight; two teachers on each of them.
        $classes = [$this->grade1, $this->grade2, $this->grade3];
        foreach (range(4, 8) as $n) {
            $classes[] = $this->makeClass($this->school, "Grade {$n}", $n);
        }
        foreach ($classes as $i => $class) {
            $this->assign($class, $this->makeTeacher("Teacher {$i} A"), null);
            $this->assign($class, $this->makeTeacher("Teacher {$i} B"), [GroupStaff::SUBJECT_QURAN]);
        }

        // The first request also loads what later ones find already loaded (the
        // permissions, and this admin's roles and organisation on the user
        // object), so it is not one of the two compared.
        $this->classList($this->admin, $this->school);

        [$one, $onePage] = $this->countingQueries(fn () => $this->classList($this->admin, $this->school, '?per_page=1'));
        [$eight, $eightPage] = $this->countingQueries(fn () => $this->classList($this->admin, $this->school));

        $this->assertCount(1, $onePage->json('data.data'));
        $this->assertCount(8, $eightPage->json('data.data'));
        foreach ($eightPage->json('data.data') as $i => $row) {
            $this->assertSame(["Teacher {$i} A", "Teacher {$i} B"], array_column($row['teachers'], 'name'), $row['name']);
        }

        // One statement reads the assignments, for one class or for eight...
        $this->assertSame(1, $one['group_staff']);
        $this->assertSame(1, $eight['group_staff']);
        // ...and nothing else is asked once per class either.
        $this->assertSame($one['all'], $eight['all']);
    }

    #[Test]
    public function a_later_page_carries_the_teachers_of_its_own_classes(): void
    {
        $first = $this->makeTeacher('Rania Qasim');
        $second = $this->makeTeacher('Bilal Darwish');

        $this->assign($this->grade1, $first, null);
        $this->assign($this->grade2, $second, null);

        $page = $this->classList($this->admin, $this->school, '?per_page=1&page=2')->json('data.data');

        $this->assertSame(['Grade 2'], array_column($page, 'name'));
        $this->assertSame(['Bilal Darwish'], array_column($page[0]['teachers'], 'name'));
    }

    // ---------- where the names do NOT go ----------

    #[Test]
    public function the_names_are_on_the_offices_class_list_and_on_no_other_class_payload(): void
    {
        $me = $this->makeTeacher('Dalia Mansoor');
        $colleague = $this->makeTeacher('Faisal Karimi');

        $this->assign($this->grade1, $me, null);
        $this->assign($this->grade1, $colleague, [GroupStaff::SUBJECT_ARABIC]);

        // A teacher belongs to the school through a membership row.
        MasjidUser::create([
            'masjid_id' => $this->school->id, 'user_id' => $me->id,
            'role' => 'teacher', 'is_default' => true,
        ]);

        // A family in the same class, with a login.
        $child = Contact::factory()->create(['masjid_id' => $this->school->id, 'email' => null]);
        GroupMembership::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->grade1->id,
            'contact_id' => $child->id, 'role' => GroupMembership::ROLE_MEMBER,
        ]);
        $parent = Contact::factory()->create(['masjid_id' => $this->school->id, 'email' => null]);
        // `forceFill` because the login_* columns are deliberately not fillable.
        $parent->forceFill(['login_email' => 'parent-' . uniqid() . '@test.local', 'login_enabled_at' => now()])->save();
        GroupMembership::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->grade1->id,
            'contact_id' => $parent->id, 'role' => GroupMembership::ROLE_GUARDIAN,
            'guardian_of_contact_id' => $child->id,
        ]);

        // The office's list carries them.
        $list = $this->classList($this->admin, $this->school);
        $this->assertSame(['Dalia Mansoor', 'Faisal Karimi'], array_column($list->json('data.data.0.teachers'), 'name'));

        // The same controller's other answers do not: one class, a new class, an edit.
        $groupsUrl = $this->groupsUrl($this->school);
        $keys = array_keys($this->grade1->fresh()->toArray());

        $show = $this->getJson($groupsUrl . '/' . $this->grade1->id)->assertOk();
        $this->assertSame([...$keys, 'unread_messages', 'memberships'], array_keys($show->json('data')));

        $update = $this->putJson($groupsUrl . '/' . $this->grade1->id, ['description' => 'Room 4'])->assertOk();
        $this->assertSame($keys, array_keys($update->json('data')));

        $store = $this->postJson($groupsUrl, ['name' => 'Grade 9', 'slug' => 'grade-9', 'kind' => Group::KIND_CLASS])->assertCreated();
        $this->assertArrayNotHasKey('teachers', $store->json('data'));

        foreach ([$show, $update, $store] as $response) {
            $this->assertStringNotContainsString('Faisal Karimi', $response->getContent());
        }

        // A teacher cannot ask for the office's list at all...
        $this->freshRequest();
        Sanctum::actingAs($me, ['staff']);
        $this->getJson($groupsUrl)->assertUnauthorized();

        // ...and their own list of classes names no colleague.
        $taught = $this->getJson("/api/teacher/masjids/{$this->school->id}/groups")->assertOk();
        $this->assertCount(1, $taught->json('data'));
        $this->assertArrayNotHasKey('teachers', $taught->json('data.0'));
        $this->assertStringNotContainsString('Faisal Karimi', $taught->getContent());
        $this->assertStringNotContainsString('Dalia Mansoor', $taught->getContent());

        // Nor does a family's.
        $this->freshRequest();
        $family = $this->withHeader('Authorization', 'Bearer ' . $parent->createFamilyToken()->plainTextToken)
            ->getJson("/api/family/masjids/{$this->school->id}/groups")
            ->assertOk();
        $this->assertCount(1, $family->json('data'));
        $this->assertArrayNotHasKey('teachers', $family->json('data.0'));
        $this->assertStringNotContainsString('Faisal Karimi', $family->getContent());
        $this->assertStringNotContainsString('Dalia Mansoor', $family->getContent());
    }

    // ---------- helpers ----------

    /** Create a Masjid row with the minimum columns the schema requires. */
    private function makeMasjid(array $overrides = []): Masjid
    {
        return Masjid::create(array_merge([
            'name' => 'Test School ' . uniqid(),
            'email' => 'school-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            // Groups live inside the CRM route group, which is gated by
            // masjids.crm_enabled (default false; covered by CrmFeatureGateTest).
            'crm_enabled' => true,
        ], $overrides));
    }

    private function makeAdminFor(Masjid $masjid): User
    {
        $admin = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);

        $masjid->user_id = $admin->id;
        $masjid->save();

        return $admin;
    }

    private function makeTeacher(string $name): User
    {
        return User::factory()->create([
            'type' => 'Teacher',
            'name' => $name,
            // users.phone is NOT NULL and the factory does not set it.
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
    }

    private function makeClass(Masjid $masjid, string $name, int $position): Group
    {
        return Group::factory()->create([
            'masjid_id' => $masjid->id,
            'kind' => Group::KIND_CLASS,
            'name' => $name,
            'position' => $position,
        ]);
    }

    /** Assign through the real write path: attach with an explicit masjid_id. */
    private function assign(Group $class, User $teacher, ?array $subjects, string $role = GroupStaff::ROLE_TEACHER): void
    {
        $class->staff()->attach($teacher->id, [
            'masjid_id' => $class->masjid_id,
            'role' => $role,
            'subjects' => $subjects,
            'assigned_at' => now(),
        ]);
    }

    private function groupsUrl(Masjid $masjid): string
    {
        return '/api/admin/masjids/' . $masjid->id . '/groups';
    }

    /** The office's class list, read as this admin in an honest new request. */
    private function classList(User $admin, Masjid $masjid, string $query = ''): TestResponse
    {
        $this->freshRequest();
        Sanctum::actingAs($admin);

        return $this->getJson($this->groupsUrl($masjid) . $query)->assertOk();
    }

    /**
     * Drop what the last request left behind: the guard memoizes its user and
     * TenantContext is a scoped binding nothing clears mid-process, so a second
     * caller in one test would otherwise be answered out of the first one's state.
     */
    private function freshRequest(): void
    {
        Auth::forgetGuards();
        app(TenantContext::class)->forgetTenant();
        $this->flushHeaders();
    }

    /**
     * Run `$request` and count the statements it made: all of them, and those
     * that read `group_staff`.
     *
     * @return array{0: array{all:int, group_staff:int}, 1: TestResponse}
     */
    private function countingQueries(callable $request): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $request();
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        return [[
            'all' => count($log),
            'group_staff' => count(array_filter($log, fn (array $q) => str_contains($q['query'], 'group_staff'))),
        ], $response];
    }
}
