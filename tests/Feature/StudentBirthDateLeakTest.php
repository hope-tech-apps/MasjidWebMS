<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupStaff;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\Offering;
use App\Models\Registration;
use App\Models\User;
use App\Support\StudentAge;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Where a student's date of birth, and the age worked out from it, must NOT be.
 *
 * The date is for the office, in exactly two places: the birth-date routes and
 * the contacts file of the school records export. The whole-number age is for
 * the office roster and the teacher's class. Nothing else carries either: no
 * other office answer, no family payload, no member (mobile) payload, no
 * public page.
 *
 * Two kinds of proof, because each is blind where the other sees:
 *
 *  - SENTINEL WALKS. One child holds a date no other fixture could produce, and
 *    the raw body of every answer in a realm is searched for it. A walk reads
 *    the realm's GET routes out of the router, so a route added later is walked
 *    without anybody remembering to add it here.
 *  - EXACT KEY SETS, where a shape is the contract (the teacher's student, the
 *    family's student): an added key is a failure whatever its value.
 *
 * The fixture is one child who is on a class roster, has a guardian, is named
 * on a program registration and can sign in as a member, so that every realm
 * has a reason to serialise this very contact.
 */
class StudentBirthDateLeakTest extends TestCase
{
    use RefreshDatabase;

    /** The child's date. No timestamp, id or amount in the fixture can spell it. */
    private const SENTINEL = '2017-03-09';

    /** The guardian's own date, planted to prove an adult's does not travel either. */
    private const GUARDIAN_SENTINEL = '1984-07-21';

    private Masjid $school;
    private User $admin;
    private User $teacher;
    private Group $class;
    private Contact $child;
    private GroupMembership $student;
    private Contact $parent;
    private GroupMembership $guardianEntry;
    private Contact $otherParent;
    private GroupMembership $otherStudent;

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

        // No moment in the fixture may read as either sentinel.
        $this->travelTo(Carbon::parse('2026-10-06 16:00:00', 'UTC'));

        $this->school = $this->makeSchool();

        $this->admin = User::factory()->create(['type' => 'MasjidAdmin', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
        $this->school->user_id = $this->admin->id;
        $this->school->save();
        MasjidUser::create(['masjid_id' => $this->school->id, 'user_id' => $this->admin->id, 'role' => 'masjid-admin', 'is_default' => true]);

        $this->teacher = User::factory()->create(['type' => 'Teacher', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
        MasjidUser::create(['masjid_id' => $this->school->id, 'user_id' => $this->teacher->id, 'role' => 'teacher', 'is_default' => true]);

        $this->class = $this->makeGroup(Group::KIND_CLASS, 'Grade Two');
        $this->lead($this->class);

        // The child: on the roster, and able to sign in as a member.
        $this->child = Contact::factory()->create([
            'masjid_id' => $this->school->id, 'first_name' => 'Idris', 'last_name' => 'Marlowe',
            'email' => 'idris.child@example.test', 'phone' => '+15551230001',
        ]);
        $this->child->forceFill(['login_email' => 'idris.member@example.test', 'verified_at' => now()])->save();
        $this->student = $this->enrol($this->class, $this->child, GroupMembership::ROLE_MEMBER, null, ['grade_label' => '2nd']);

        // Their guardian: a family login, consent recorded, a distinctive name.
        $this->parent = Contact::factory()->create([
            'masjid_id' => $this->school->id, 'first_name' => 'Zubaydah', 'last_name' => 'Quillfeather',
            'email' => 'guardian.secret@example.test', 'phone' => '+15559998888',
        ]);
        $this->parent->forceFill(['login_email' => 'zq.login@example.test', 'login_enabled_at' => now()])->save();
        $this->guardianEntry = $this->enrol($this->class, $this->parent, GroupMembership::ROLE_GUARDIAN, $this->child, [
            'consent_granted_at' => now(), 'consent_scope' => 'media',
        ]);

        // Another family in the SAME class.
        $otherChild = Contact::factory()->create(['masjid_id' => $this->school->id, 'first_name' => 'Maryam', 'last_name' => 'Okafor', 'email' => null]);
        $this->otherStudent = $this->enrol($this->class, $otherChild, GroupMembership::ROLE_MEMBER);
        $this->otherParent = Contact::factory()->create(['masjid_id' => $this->school->id, 'first_name' => 'Tariq', 'last_name' => 'Okafor', 'email' => null]);
        $this->otherParent->forceFill(['login_email' => 'to.login@example.test', 'login_enabled_at' => now()])->save();
        $this->enrol($this->class, $this->otherParent, GroupMembership::ROLE_GUARDIAN, $otherChild, [
            'consent_granted_at' => now(), 'consent_scope' => 'media',
        ]);

        $this->child->recordDateOfBirth(self::SENTINEL, $this->admin, 'roster');
        $this->parent->recordDateOfBirth(self::GUARDIAN_SENTINEL, $this->admin, 'roster');
        $otherChild->recordDateOfBirth('2016-12-25', $this->admin, 'roster');

        $this->child->refresh();
        $this->parent->refresh();
    }

    protected function tearDown(): void
    {
        StudentAge::forget();

        parent::tearDown();
    }

    // ------------------------------------------------------------ the model

    #[Test]
    public function a_serialised_contact_never_carries_the_date_or_an_age(): void
    {
        $fresh = Contact::findOrFail($this->child->id);

        $this->assertSame(self::SENTINEL, $fresh->dateOfBirthOrNull(), 'the fixture holds the date');

        foreach ([$fresh->toArray(), json_decode($fresh->toJson(), true), $fresh->jsonSerialize()] as $shape) {
            $this->assertArrayNotHasKey('date_of_birth', $shape);
            $this->assertArrayNotHasKey('age', $shape);
        }

        $this->assertClean($fresh->toJson(), 'Contact::toJson()');
        $this->assertNotContains('age', $fresh->getAppends());
    }

    // ------------------------------------------------------------ the office

    /**
     * Every office answer that serialises this child or their roster row. The
     * staff contact endpoints answer the model WHOLE, so `$hidden` on Contact
     * is the only thing between the column and each of these.
     */
    #[Test]
    public function no_office_answer_but_the_two_named_ones_carries_the_date(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $base = "/api/admin/masjids/{$this->school->id}";
        $row = "{$base}/groups/{$this->class->id}/members/{$this->student->id}";
        $guardianRow = "{$base}/groups/{$this->class->id}/members/{$this->guardianEntry->id}";

        $offering = Offering::factory()->forMasjid($this->school)->create(['group_id' => $this->class->id]);
        $registration = Registration::factory()->create([
            'masjid_id' => $this->school->id, 'offering_id' => $offering->id, 'contact_id' => $this->child->id,
        ]);

        // A second record of the same child, with a date of its own, to merge in.
        $duplicate = Contact::factory()->create(['masjid_id' => $this->school->id, 'first_name' => 'Idris', 'last_name' => 'Marlowe', 'email' => null]);
        $duplicate->recordDateOfBirth('2017-03-10', $this->admin, 'roster');

        $answers = [
            'contacts index' => $this->getJson("{$base}/contacts")->assertOk(),
            'contacts search' => $this->getJson("{$base}/contacts?search=Idris")->assertOk(),
            'contact show' => $this->getJson("{$base}/contacts/{$this->child->id}")->assertOk(),
            'contact update' => $this->putJson("{$base}/contacts/{$this->child->id}", ['first_name' => 'Idris', 'last_name' => 'Marlowe'])->assertOk(),
            'contact store' => $this->postJson("{$base}/contacts", ['first_name' => 'New', 'last_name' => 'Person', 'date_of_birth' => '2015-05-05'])->assertSuccessful(),
            'avatar set' => $this->putJson("{$base}/contacts/{$this->child->id}/avatar", ['character' => null, 'tone' => null, 'color' => null])->assertOk(),
            'sms consent' => $this->postJson("{$base}/contacts/{$this->child->id}/sms-consent", [
                'source' => \App\Http\Requests\Admin\Contacts\StoreSmsConsentRequest::adminSelectableSources()[0],
            ])->assertSuccessful(),
            'merge' => $this->postJson("{$base}/contacts/{$duplicate->id}/merge", ['target_contact_id' => $this->child->id])->assertOk(),
            'groups index' => $this->getJson("{$base}/groups")->assertOk(),
            'group show' => $this->getJson("{$base}/groups/{$this->class->id}")->assertOk(),
            'roster list' => $this->getJson("{$base}/groups/{$this->class->id}/members")->assertOk(),
            'consent put' => $this->putJson("{$guardianRow}/consent", ['scope' => 'feed'])->assertOk(),
            'consent delete' => $this->deleteJson("{$guardianRow}/consent")->assertOk(),
            'withdrawal put' => $this->putJson("{$row}/withdrawal", ['left_on' => '2026-10-05'])->assertOk(),
            'withdrawal delete' => $this->deleteJson("{$row}/withdrawal")->assertOk(),
            'registrations index' => $this->getJson("{$base}/offerings/{$offering->id}/registrations")->assertOk(),
            'registration show' => $this->getJson("{$base}/offerings/{$offering->id}/registrations/{$registration->id}")->assertOk(),
            'contact delete' => $this->deleteJson("{$base}/contacts/{$this->child->id}")->assertOk(),
            'contact restore' => $this->postJson("{$base}/contacts/{$this->child->id}/restore")->assertOk(),
        ];

        foreach ($answers as $what => $response) {
            $this->assertLessThan(500, $response->getStatusCode(), $what);
            $this->assertClean($this->bodyOf($response), $what);
            $this->assertStringNotContainsString('date_of_birth', $this->bodyOf($response), "{$what} names the column");
        }

        // The roster list's contact is the columns the roster always carried.
        $rosterRow = collect($answers['roster list']->json('data'))->firstWhere('id', $this->student->id);
        $this->assertEqualsCanonicalizing(
            [
                'id', 'first_name', 'last_name', 'email', 'phone', 'avatar',
                'avatar_character', 'avatar_tone', 'avatar_color',
                'staff_avatar_character', 'staff_avatar_tone', 'staff_avatar_color',
            ],
            array_keys($rosterRow['contact']),
        );
        $this->assertSame(9, $rosterRow['age']);

        // Every dataset of the records export but the contacts file.
        //
        // The class's teacher is taken off first. On main, `class_staff` eager
        // loads a `user` relation that GroupStaff does not have, so that file
        // throws mid-stream for any class with a teacher. That is not this
        // slice's to fix (it is reported); with no staff row the query never
        // resolves the relation, and the other datasets do not read staff.
        $this->class->staff()->detach();

        $datasets = (new \ReflectionClassConstant(\App\Http\Controllers\AdminDashboard\SchoolRecordsExportController::class, 'DATASETS'))->getValue();
        $this->assertContains('contacts', $datasets);

        foreach ($datasets as $dataset) {
            $csv = $this->bodyOf($this->get("{$base}/records/export?dataset={$dataset}")->assertOk());

            if ($dataset === 'contacts') {
                $this->assertStringContainsString(self::SENTINEL, $csv, 'the contacts file is one of the two places');
                continue;
            }

            $this->assertClean($csv, "export dataset {$dataset}");
        }

        // The control: the walk can see the date where it IS meant to be.
        $this->assertStringContainsString(self::SENTINEL, $this->getJson("{$row}/birth-date")->assertOk()->getContent());
    }

    /**
     * Every GET the office can make under contacts, groups and registrations,
     * read out of the router: a route added to one of them later is walked
     * here without anybody listing it.
     */
    #[Test]
    public function every_office_read_under_contacts_groups_and_registrations_is_clean(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $offering = Offering::factory()->forMasjid($this->school)->create(['group_id' => $this->class->id]);
        $registration = Registration::factory()->create([
            'masjid_id' => $this->school->id, 'offering_id' => $offering->id, 'contact_id' => $this->child->id,
        ]);

        $walked = $this->walk(
            fn (RoutingRoute $r): bool => (bool) preg_match('#^api/admin/masjids/\{masjid_id\}/(contacts|groups|offerings/\{offering_id\}/registrations)(/|$)#', $r->uri())
                && ! str_ends_with($r->uri(), '/birth-date'),
            [
                'masjid_id' => $this->school->id, 'contact_id' => $this->child->id, 'group_id' => $this->class->id,
                'membership_id' => $this->student->id, 'offering_id' => $offering->id, 'registration_id' => $registration->id,
            ],
            fn (string $uri) => $this->get($uri, ['Accept' => 'application/json']),
        );

        foreach ($walked as $uri => $body) {
            $this->assertClean($body, "GET {$uri}");
        }

        $this->assertGreaterThan(20, count($walked), 'the walk found the office routes');
    }

    // ------------------------------------------------------------ the teacher

    #[Test]
    public function a_teachers_student_is_exactly_these_keys_and_the_age_is_on_the_class_payload_only(): void
    {
        Sanctum::actingAs($this->teacher, ['staff']);
        $base = "/api/teacher/masjids/{$this->school->id}";

        // The class, and the class as it appears in My Classes.
        foreach ([
            $this->getJson("{$base}/groups/{$this->class->id}")->assertOk()->json('data.students'),
            $this->getJson("{$base}/groups")->assertOk()->json('data.0.students'),
        ] as $students) {
            $student = collect($students)->firstWhere('membership_id', $this->student->id);

            $this->assertSame(['membership_id', 'grade_label', 'contact', 'age'], array_keys($student));
            $this->assertSame(['id', 'first_name', 'last_name', 'avatar'], array_keys($student['contact']));
            $this->assertSame(9, $student['age']);
            $this->assertSame('2nd', $student['grade_label']);
        }

        // student() everywhere else is the shape it was: no `age`.
        $register = collect($this->getJson("{$base}/groups/{$this->class->id}/attendance")->assertOk()->json('data.students'))
            ->firstWhere('membership_id', $this->student->id);
        $this->assertNotNull($register);
        $this->assertArrayNotHasKey('age', $register);
        $this->assertSame(['id', 'first_name', 'last_name', 'avatar'], array_keys($register['contact']));

        $avatar = $this->putJson("{$base}/groups/{$this->class->id}/members/{$this->student->id}/avatar", ['character' => null, 'tone' => null, 'color' => null])->assertOk();
        $this->assertSame(['membership_id', 'grade_label', 'contact'], array_keys($avatar->json('data')));
        $this->assertClean($avatar->getContent(), 'the avatar answer');
    }

    /**
     * Every GET in the teacher realm. No body carries the date, the column's
     * name, or a guardian's name, email or phone; and `age` is in the class
     * payload alone.
     *
     * CONVERSATIONS AND THE CLASS STORY ARE DELIBERATELY NOT WALKED FOR THE
     * NAME. A parent's NAME reaches a teacher there in five places, by the
     * owner's decision of 2026-09-21 that staff see every name: as a message's
     * author, beside a reaction on a message, in "read by", beside a reaction
     * on a class story, and in "seen by". Nobody may widen the name pin to
     * them: it would fail on correct behaviour. They are still walked for the
     * date and the age.
     */
    #[Test]
    public function nothing_in_the_teacher_realm_carries_the_date_a_guardian_or_an_age_outside_the_class_payload(): void
    {
        // The guardian has spoken in the class, so the conversation routes have something to say.
        $thread = \App\Models\GroupThread::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id,
            'scope' => \App\Models\GroupThread::SCOPE_PARTICIPANT, 'about_membership_id' => $this->student->id,
            'subject' => 'About Idris', 'created_by_user_id' => $this->teacher->id,
        ]);

        Sanctum::actingAs($this->teacher, ['staff']);

        $walked = $this->walk(
            fn (RoutingRoute $r): bool => str_starts_with($r->uri(), 'api/teacher/masjids/{masjid_id}'),
            [
                'masjid_id' => $this->school->id, 'group_id' => $this->class->id,
                'membership_id' => $this->student->id, 'thread_id' => $thread->id,
            ],
            fn (string $uri) => $this->get($uri, ['Accept' => 'application/json']),
        );

        $classPayloads = [
            "api/teacher/masjids/{$this->school->id}/groups",
            "api/teacher/masjids/{$this->school->id}/groups/{$this->class->id}",
        ];

        foreach ($walked as $uri => $body) {
            $this->assertClean($body, "GET {$uri}");
            $this->assertStringNotContainsString('date_of_birth', $body, "GET {$uri} names the column");
            $this->assertStringNotContainsString('guardian.secret@example.test', $body, "GET {$uri} carries a guardian's email");
            $this->assertStringNotContainsString('+15559998888', $body, "GET {$uri} carries a guardian's phone");

            if (! preg_match('#/(threads|posts|scheduled-messages)(/|$)#', $uri)) {
                $this->assertStringNotContainsString('Quillfeather', $body, "GET {$uri} carries a guardian's name");
                $this->assertStringNotContainsString('Zubaydah', $body, "GET {$uri} carries a guardian's name");
            }

            if (in_array($uri, $classPayloads, true)) {
                $this->assertStringContainsString('"age":9', $body, "GET {$uri} is where the age is");
            } else {
                $this->assertStringNotContainsString('"age"', $body, "GET {$uri} carries an age");
            }
        }

        foreach ($classPayloads as $uri) {
            $this->assertArrayHasKey($uri, $walked);
        }
        $this->assertGreaterThan(25, count($walked), 'the walk found the teacher routes');
    }

    /**
     * classPayload serves every kind of group a teacher leads, and its roster
     * includes a legacy `leader` row. A contact can hold a date from a class
     * and also sit on one of those, where no age is to be shown.
     */
    #[Test]
    public function a_teacher_sees_an_age_for_a_student_in_a_class_and_for_nobody_else(): void
    {
        $halaqa = $this->makeGroup(Group::KIND_HALAQA, 'Evening circle');
        $this->lead($halaqa);
        $inHalaqa = $this->enrol($halaqa, $this->child, GroupMembership::ROLE_MEMBER);

        $legacyLeader = $this->enrol($this->class, $this->parent, GroupMembership::ROLE_LEADER);

        Sanctum::actingAs($this->teacher, ['staff']);
        $base = "/api/teacher/masjids/{$this->school->id}";

        $circle = collect($this->getJson("{$base}/groups/{$halaqa->id}")->assertOk()->json('data.students'))->keyBy('membership_id');
        $this->assertArrayHasKey('age', $circle[$inHalaqa->id]);
        $this->assertNull($circle[$inHalaqa->id]['age'], 'the same child, in a ḥalaqa');

        $class = collect($this->getJson("{$base}/groups/{$this->class->id}")->assertOk()->json('data.students'))->keyBy('membership_id');
        $this->assertSame(9, $class[$this->student->id]['age'], 'the same child, in a class');
        $this->assertArrayHasKey($legacyLeader->id, $class->all(), 'the legacy leader row is on the teacher\'s roster');
        $this->assertNull($class[$legacyLeader->id]['age'], 'a leader row holds a date and shows no age');
    }

    // ------------------------------------------------------------ families

    #[Test]
    public function nothing_in_the_family_realm_carries_the_date_or_an_age(): void
    {
        $params = ['masjid_id' => $this->school->id, 'group_id' => $this->class->id, 'membership_id' => $this->student->id];
        $isFamily = fn (RoutingRoute $r): bool => str_starts_with($r->uri(), 'api/family/masjids/{masjid_id}');

        foreach (['the child\'s own parent' => $this->parent, 'another family in the class' => $this->otherParent] as $who => $parent) {
            $walked = $this->walk($isFamily, $params, fn (string $uri) => $this->asFamily($parent)->get($uri, ['Accept' => 'application/json']));

            foreach ($walked as $uri => $body) {
                $this->assertClean($body, "GET {$uri} as {$who}");
                $this->assertStringNotContainsString('date_of_birth', $body, "GET {$uri} as {$who} names the column");
                $this->assertStringNotContainsString('"age"', $body, "GET {$uri} as {$who} carries an age");
            }

            $this->assertGreaterThan(10, count($walked), 'the walk found the family routes');
        }

        // The walk reached real answers: the parent's own child is in them.
        $own = $this->asFamily($this->parent)->getJson("/api/family/masjids/{$this->school->id}/groups/{$this->class->id}")->assertOk();
        $this->assertStringContainsString('Idris', $own->getContent());
        $this->asFamily($this->parent)->getJson("/api/family/masjids/{$this->school->id}/me")->assertOk();
    }

    #[Test]
    public function a_familys_student_is_exactly_these_keys(): void
    {
        $controller = new class(app(\App\Support\GroupAudience::class)) extends \App\Http\Controllers\Family\FamilyController {
            public function shape(GroupMembership $m): array
            {
                return $this->student($m);
            }
        };

        $shape = $controller->shape($this->student->load('contact'));

        $this->assertSame(['membership_id', 'contact'], array_keys($shape));
        $this->assertSame(['id', 'first_name', 'last_name', 'avatar'], array_keys($shape['contact']));
    }

    // ------------------------------------------------------------ members and the public

    /**
     * The child can sign in as a member (the native apps' realm). Every GET a
     * member can make is walked as them.
     */
    #[Test]
    public function nothing_in_the_member_realm_carries_the_date_or_an_age(): void
    {
        config(['member_portal.enabled' => true, 'member_portal.masjid_ids' => []]);

        $isMember = fn (RoutingRoute $r): bool => str_starts_with((string) $r->getActionName(), 'App\\Http\\Controllers\\Mobile\\Member\\');

        $walked = $this->walk($isMember, ['masjid_id' => $this->school->id], function (string $uri): TestResponse {
            Auth::forgetGuards();
            app(TenantContext::class)->forgetTenant();

            return $this->withHeader('Authorization', 'Bearer '.$this->child->createMemberToken()->plainTextToken)
                ->get($uri, ['Accept' => 'application/json']);
        });

        foreach ($walked as $uri => $body) {
            $this->assertClean($body, "GET {$uri} as the member");
            $this->assertStringNotContainsString('date_of_birth', $body, "GET {$uri} names the column");
            $this->assertStringNotContainsString('"age"', $body, "GET {$uri} carries an age");
        }

        $this->assertNotEmpty($walked, 'the walk found the member routes');
    }

    #[Test]
    public function the_public_program_page_carries_neither(): void
    {
        $offering = Offering::factory()->forMasjid($this->school)->create([
            'group_id' => $this->class->id, 'slug' => 'grade-two-program',
        ]);
        Registration::factory()->create([
            'masjid_id' => $this->school->id, 'offering_id' => $offering->id, 'contact_id' => $this->child->id,
        ]);

        Auth::forgetGuards();
        app(TenantContext::class)->forgetTenant();

        $response = $this->getJson('/api/v1/offerings/grade-two-program', ['masjid-id' => (string) $this->school->id]);

        $this->assertLessThan(500, $response->getStatusCode());
        $this->assertClean($response->getContent(), 'the public program page');
        $this->assertStringNotContainsString('date_of_birth', $response->getContent());
    }

    /**
     * The structural half of "nowhere else". A walk proves what today's routes
     * answer; this proves no code in the other realms can ask for a date or an
     * age at all. (The callers that MAY are pinned, file by file, in
     * StudentBirthDateTest.)
     */
    #[Test]
    public function no_family_mobile_public_or_lunch_code_can_ask_for_a_date_or_an_age(): void
    {
        $realms = [
            app_path('Http/Controllers/Family'),
            app_path('Http/Controllers/Mobile'),
            app_path('Http/Controllers/Api'),
            app_path('Http/Controllers/Lunch'),
            app_path('Http/Resources'),
        ];

        foreach ($realms as $dir) {
            if (! is_dir($dir)) {
                continue;
            }

            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));

            foreach ($files as $file) {
                $source = (string) file_get_contents($file->getPathname());

                foreach (['StudentAge', 'dateOfBirthOrNull', 'recordDateOfBirth', 'holdsDateOfBirth'] as $forbidden) {
                    $this->assertStringNotContainsString($forbidden, $source, $file->getPathname());
                }
            }
        }

        // The teacher realm asks in one place only, and never for the date.
        foreach (glob(app_path('Http/Controllers/Teacher/*.php')) as $path) {
            $source = (string) file_get_contents($path);

            $this->assertStringNotContainsString('dateOfBirthOrNull', $source, $path);
            $this->assertStringNotContainsString('recordDateOfBirth', $source, $path);

            if (basename($path) !== 'TeacherController.php') {
                $this->assertStringNotContainsString('StudentAge', $source, $path);
            }
        }
    }

    // ------------------------------------------------------------- helpers

    /** Neither sentinel is anywhere in `$body`. */
    private function assertClean(string $body, string $what): void
    {
        $this->assertStringNotContainsString(self::SENTINEL, $body, "{$what} carries the child's date of birth");
        $this->assertStringNotContainsString(self::GUARDIAN_SENTINEL, $body, "{$what} carries the guardian's date of birth");
        // The same day in the other shapes a serialiser could give it.
        $this->assertStringNotContainsString('2017-03-09T', $body, $what);
        $this->assertStringNotContainsString('03\/09\/2017', $body, $what);
    }

    /**
     * Call every GET route that `$wanted` accepts, with `$params` filled in
     * (any other parameter is 1), and hand back each raw body by URI. Files
     * (a PDF, a download, an attachment) are skipped: they are bytes, and the
     * routes that build them from a contact are walked through their JSON twin.
     *
     * @param  callable(RoutingRoute): bool  $wanted
     * @param  array<string, int|string>  $params
     * @param  callable(string): TestResponse  $call
     * @return array<string, string>
     */
    private function walk(callable $wanted, array $params, callable $call): array
    {
        $bodies = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true) || ! $wanted($route)) {
                continue;
            }
            if (preg_match('#/(pdf|download|document|playback)$|/attachments/#', $route->uri())) {
                continue;
            }

            $uri = preg_replace_callback('/\{(\w+)\??\}/', fn (array $m): string => (string) ($params[$m[1]] ?? 1), $route->uri());

            $response = $call('/'.$uri);

            $this->assertLessThan(500, $response->getStatusCode(), "GET /{$uri} failed, so the walk proved nothing about it");

            $bodies[$uri] = $this->bodyOf($response);
        }

        return $bodies;
    }

    /** The body a client would receive, streamed or not. */
    private function bodyOf(TestResponse $response): string
    {
        if ($response->baseResponse instanceof \Symfony\Component\HttpFoundation\StreamedResponse) {
            return $response->streamedContent();
        }
        if ($response->baseResponse instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse) {
            return '';
        }

        return (string) $response->getContent();
    }

    /** A real family token, on an honest new request (FamilyPortalTest::as() says why). */
    private function asFamily(Contact $parent): self
    {
        Auth::forgetGuards();
        app(TenantContext::class)->forgetTenant();

        return $this->withHeader('Authorization', 'Bearer '.$parent->createFamilyToken()->plainTextToken);
    }

    private function lead(Group $group): void
    {
        $group->staff()->attach($this->teacher->id, [
            'masjid_id' => $this->school->id, 'role' => GroupStaff::ROLE_TEACHER, 'assigned_at' => now(),
        ]);
    }

    private function enrol(Group $group, Contact $contact, string $role, ?Contact $ward = null, array $more = []): GroupMembership
    {
        return GroupMembership::create(array_merge([
            'masjid_id' => $this->school->id, 'group_id' => $group->id,
            'contact_id' => $contact->id, 'role' => $role,
            'guardian_of_contact_id' => $ward?->id,
        ], $more));
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
            'name' => 'Leak Test School '.uniqid(),
            'email' => 'school-'.uniqid().'@example.test',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0,
            'crm_enabled' => true, 'org_type' => 'school',
        ]);
    }
}
