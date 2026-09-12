<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The office's roster import, over HTTP (T-043b).
 *
 * `schools:import-roster` was already safe and already tested
 * (tests/Feature/ImportSchoolRosterTest.php). What is new here is a SECOND
 * caller with a browser in front of it, and the properties worth pinning are
 * the ones that only exist because of that:
 *
 *  1. THE PREVIEW IS A PREVIEW. Reading a file writes nothing, and nothing can
 *     be committed that has not been previewed as those exact bytes.
 *  2. THE COMMIT DOES WHAT THE PREVIEW SAID. Not approximately — the same rows,
 *     because both come from one `plan()`.
 *  3. THE SAME FILE TWICE IS ONE ROSTER. A double-click on Import cannot double
 *     a class.
 *  4. WHAT IT WILL NOT DO, IT DOES NOT DO. No consent, no family login, and a
 *     class it has never heard of stays uncreated.
 *  5. ONE SCHOOL, ONE ROSTER. Another organisation's classes, children and
 *     contacts are unreachable and unmatchable from here.
 *  6. THERE IS NO UNDO, AND NO ROUTE THAT COULD BECOME ONE. A screen that
 *     creates children's records in bulk must not also delete them in bulk:
 *     those rows carry attendance, marks and report cards.
 *
 * WHERE THIS SUITE IS BLIND, AND WHAT IS DONE ABOUT IT. `setUp()` pins the
 * connection to SQLite, whose default BINARY collation makes `=` and `IN`
 * case-SENSITIVE — production MySQL's utf8mb4_*_ci is not, and migration
 * 2026_09_09_040000 (academic-record foreign keys, RESTRICT) returns early on
 * SQLite entirely. Two of the tests below exist specifically because of that:
 * the mixed-case guardian is written so it fails on BOTH engines when the
 * matching is wrong, rather than agreeing with itself on the one engine CI runs.
 */
class RosterImportTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $school;
    private Masjid $otherSchool;
    private User $admin;
    private Group $class;

    private const HEADER = 'class,student_first_name,student_last_name,grade,'
        . "guardian_first_name,guardian_last_name,guardian_email,guardian_phone\n";

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        app(TenantContext::class)->forgetTenant();

        $this->school = $this->makeMasjid();
        $this->otherSchool = $this->makeMasjid();

        $this->admin = User::factory()->create([
            'type' => 'MasjidAdmin', 'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
        $this->school->user_id = $this->admin->id;
        $this->school->save();
        MasjidUser::create([
            'masjid_id' => $this->school->id, 'user_id' => $this->admin->id,
            'role' => 'masjid-admin', 'is_default' => true,
        ]);

        $this->class = Group::factory()->create([
            'masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS,
            'name' => 'Grade 2', 'slug' => 'grade-2-' . uniqid(),
        ]);

        Sanctum::actingAs($this->admin, ['*']);
    }

    private function makeMasjid(): Masjid
    {
        return Masjid::create([
            'name' => 'School ' . uniqid(),
            'email' => 'school-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0,
            'crm_enabled' => true, 'org_type' => 'school',
        ]);
    }

    private function url(string $suffix = ''): string
    {
        return "/api/admin/masjids/{$this->school->id}/records/roster-import{$suffix}";
    }

    private function csv(string $body, string $name = 'roster.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, self::HEADER . $body);
    }

    /** @return array<string, mixed> the preview payload */
    private function previewOf(UploadedFile $file): array
    {
        $response = $this->post($this->url('/preview'), ['file' => $file], ['Accept' => 'application/json']);
        $response->assertOk();

        return $response->json('data');
    }

    // -------------------------------------------- 1. the preview is a preview

    #[Test]
    public function a_preview_writes_nothing(): void
    {
        $data = $this->previewOf($this->csv(
            "Grade 2,Aalaa,Salim,2nd,Musa,Salim,musa@example.test,555\n"
            . "Grade 2,Bilal,Khan,2nd,Huda,Khan,huda@example.test,556\n"
        ));

        $this->assertSame(2, $data['totals']['students']['to_create']);
        $this->assertSame(2, $data['totals']['guardians']['to_create']);

        // The payload says four people are coming. None of them exists.
        $this->assertSame(0, Contact::withoutGlobalScopes()->count(),
            'reading a roster file must not create a soul');
        $this->assertSame(0, GroupMembership::withoutGlobalScopes()->count());
    }

    /**
     * A file nobody has previewed cannot be written, whatever the client sends.
     * The receipt is issued by `preview()` and is the only thing that opens the
     * commit — a caller who hashes a file themselves has still not read it.
     */
    #[Test]
    public function a_commit_without_a_preview_receipt_is_refused(): void
    {
        $this->post(
            $this->url(),
            ['file' => $this->csv("Grade 2,Aalaa,Salim,2nd,Musa,Salim,musa@example.test,555\n")],
            ['Accept' => 'application/json']
        )->assertStatus(422);

        $this->assertSame(0, Contact::withoutGlobalScopes()->count());
    }

    #[Test]
    public function a_commit_whose_receipt_belongs_to_a_different_file_is_refused(): void
    {
        $previewed = $this->previewOf($this->csv("Grade 2,Aalaa,Salim,2nd,Musa,Salim,musa@example.test,555\n"));

        // A second, DIFFERENT roster — one the office never looked at.
        $swapped = $this->csv("Grade 2,Yusuf,Iqbal,2nd,Sara,Iqbal,sara@example.test,557\n");

        $response = $this->post(
            $this->url(),
            ['file' => $swapped, 'receipt' => $previewed['receipt']],
            ['Accept' => 'application/json']
        );

        $response->assertStatus(422);
        $this->assertStringContainsString('not the file you previewed', json_encode($response->json()));

        $this->assertSame(0, Contact::withoutGlobalScopes()->count(),
            'the swapped file must not be written under the previewed file\'s approval');
    }

    // ------------------------------- 2. the commit does what the preview said

    #[Test]
    public function a_commit_creates_exactly_what_the_preview_promised(): void
    {
        $body = "Grade 2,Aalaa,Salim,2nd,Musa,Salim,musa@example.test,555\n"
            . "Grade 2,Aalaa,Salim,2nd,Huda,Salim,huda@example.test,556\n"
            . "Grade 2,Bilal,Khan,2nd,Musa,Salim,musa@example.test,555\n";

        $preview = $this->previewOf($this->csv($body));

        $this->assertTrue($preview['can_commit']);
        $this->assertSame(2, $preview['totals']['students']['to_create'], 'two children over three rows');
        $this->assertSame(2, $preview['totals']['guardians']['to_create'], 'one parent named twice is one parent');
        $this->assertSame(3, $preview['totals']['edges']);

        $response = $this->post(
            $this->url(),
            ['file' => $this->csv($body), 'receipt' => $preview['receipt']],
            ['Accept' => 'application/json']
        );

        $response->assertStatus(201);

        // The server's own account of the write, against the promise.
        $this->assertSame($preview['totals']['students']['to_create'], $response->json('data.created.students'));
        $this->assertSame($preview['totals']['guardians']['to_create'], $response->json('data.created.guardians'));
        $this->assertSame($preview['totals']['edges'], $response->json('data.created.edges'));

        // And the database's, which is the one that matters.
        $this->assertSame(4, Contact::withoutGlobalScopes()->count(), 'two children, two guardians');

        foreach ($preview['students'] as $student) {
            $this->assertSame(1, Contact::withoutGlobalScopes()
                ->where('first_name', explode(' ', $student['name'])[0])->count(),
                "the preview named {$student['name']} and the import must have created exactly one");
        }

        $this->assertSame(2, GroupMembership::withoutGlobalScopes()
            ->where('role', GroupMembership::ROLE_MEMBER)->count());
        $this->assertSame(3, GroupMembership::withoutGlobalScopes()
            ->where('role', GroupMembership::ROLE_GUARDIAN)->count());
    }

    /**
     * Every row this writes is `provenance = confirmed` and names the signed-in
     * administrator. The HTTP path is STRONGER than the console's `--actor`
     * flag here: a flag is a claim about who authorised the file and a session
     * is a fact.
     */
    #[Test]
    public function an_import_records_the_signed_in_admin_as_the_confirmer(): void
    {
        $this->commitOnce("Grade 2,Aalaa,Salim,2nd,Musa,Salim,musa@example.test,555\n");

        $rows = GroupMembership::withoutGlobalScopes()->get();

        $this->assertCount(2, $rows);

        foreach ($rows as $row) {
            $this->assertTrue($row->isConfirmed());
            $this->assertSame($this->admin->id, $row->confirmed_by_user_id,
                'the person who pressed Import is the person who vouched for the row');
        }
    }

    // --------------------------------------- 3. the same file twice is one roster

    #[Test]
    public function a_second_commit_of_the_same_file_adds_nobody(): void
    {
        $body = "Grade 2,Aalaa,Salim,2nd,Musa,Salim,musa@example.test,555\n"
            . "Grade 2,Bilal,Khan,2nd,Huda,Khan,huda@example.test,556\n";

        $preview = $this->previewOf($this->csv($body));

        $this->post($this->url(), ['file' => $this->csv($body), 'receipt' => $preview['receipt']],
            ['Accept' => 'application/json'])->assertStatus(201);

        $contactsAfterFirst = Contact::withoutGlobalScopes()->count();
        $rowsAfterFirst = GroupMembership::withoutGlobalScopes()->count();

        // The office presses Import again — a double click, a refreshed tab, a
        // colleague repeating the job. The receipt is still valid for these
        // bytes, so this is a genuine replay and not a validation failure.
        $second = $this->post($this->url(), ['file' => $this->csv($body), 'receipt' => $preview['receipt']],
            ['Accept' => 'application/json']);

        $second->assertStatus(201);
        $this->assertSame(0, $second->json('data.created.students'));
        $this->assertSame(0, $second->json('data.created.guardians'));
        $this->assertSame(0, $second->json('data.created.memberships'));
        $this->assertSame(0, $second->json('data.created.edges'));
        $this->assertSame(2, $second->json('data.matched.students'));

        $this->assertSame($contactsAfterFirst, Contact::withoutGlobalScopes()->count(),
            'a second import of one file must not double a class');
        $this->assertSame($rowsAfterFirst, GroupMembership::withoutGlobalScopes()->count());
    }

    // -------------------------------------- 4. what it will not do, it does not do

    #[Test]
    public function a_malformed_row_is_refused_with_a_sentence_naming_the_row(): void
    {
        $file = $this->csv(
            "Grade 2,Aalaa,Salim,2nd,Musa,Salim,musa@example.test,555\n"
            . ",Bilal,Khan,2nd,Huda,Khan,huda@example.test,556\n"
        );

        $preview = $this->previewOf($file);

        $this->assertFalse($preview['can_commit']);
        $this->assertCount(1, $preview['refused']);
        $this->assertSame(3, $preview['refused'][0]['line'], 'the header is line 1, so the bad row is line 3');
        $this->assertStringContainsString('class', $preview['refused'][0]['why']);

        // And the write refuses independently of the flag, in the same words the
        // console uses, naming the same line.
        $response = $this->post(
            $this->url(),
            [
                'file' => $this->csv(
                    "Grade 2,Aalaa,Salim,2nd,Musa,Salim,musa@example.test,555\n"
                    . ",Bilal,Khan,2nd,Huda,Khan,huda@example.test,556\n"
                ),
                'receipt' => $preview['receipt'],
            ],
            ['Accept' => 'application/json']
        );

        $response->assertStatus(422);

        $body = json_encode($response->json());
        $this->assertStringContainsString('line 3', $body);
        $this->assertStringContainsString('partly-imported roster is worse than none', $body);

        $this->assertSame(0, Contact::withoutGlobalScopes()->count(),
            'the good row must not land either — all or nothing');
    }

    #[Test]
    public function a_file_naming_a_class_that_does_not_exist_cannot_be_committed(): void
    {
        $body = "Grade 9,Aalaa,Salim,9th,Musa,Salim,musa@example.test,555\n";

        $preview = $this->previewOf($this->csv($body));

        $this->assertFalse($preview['can_commit']);
        $this->assertStringContainsString('no class named "Grade 9"', $preview['refused'][0]['why']);

        $this->post($this->url(), ['file' => $this->csv($body), 'receipt' => $preview['receipt']],
            ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertSame(1, Group::withoutGlobalScopes()->count(),
            'a typo must not found a second classroom');
        $this->assertSame(0, Contact::withoutGlobalScopes()->count());
    }

    /** Consent is a parent's act. A spreadsheet the school typed cannot perform it. */
    #[Test]
    public function an_import_cannot_grant_consent(): void
    {
        $response = $this->commitOnce("Grade 2,Aalaa,Salim,2nd,Musa,Salim,musa@example.test,555\n");

        foreach (GroupMembership::withoutGlobalScopes()->get() as $row) {
            $this->assertNull($row->consent_granted_at, 'no imported row may carry consent');
            $this->assertNull($row->consent_scope);
        }

        // …and the response says so, so no screen can imply otherwise.
        $this->assertStringContainsString('consent', strtolower((string) $response->json('meta.consent_note')));
    }

    #[Test]
    public function an_import_cannot_enable_a_family_login(): void
    {
        $response = $this->commitOnce("Grade 2,Aalaa,Salim,2nd,Musa,Salim,musa@example.test,555\n");

        $guardian = Contact::withoutGlobalScopes()->where('email', 'musa@example.test')->firstOrFail();

        $this->assertNull($guardian->login_enabled_at, 'importing an address is not enabling an account');
        $this->assertNull($guardian->login_email);
        $this->assertNull($guardian->password);

        $this->assertStringContainsString('login', strtolower((string) $response->json('meta.login_note')));
    }

    /**
     * A spreadsheet exported from Excel carries a UTF-8 BOM on the first header.
     * Unhandled it makes `class` unmatchable and refuses every row for a reason
     * nobody can see on screen.
     */
    #[Test]
    public function an_excel_exported_csv_with_a_bom_is_accepted(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'roster.csv',
            "\xEF\xBB\xBF" . self::HEADER . "Grade 2,Aalaa,Salim,2nd,Musa,Salim,musa@example.test,555\n"
        );

        $preview = $this->previewOf($file);

        $this->assertTrue($preview['can_commit']);
        $this->assertSame([], $preview['refused']);
        $this->assertSame(1, $preview['totals']['students']['to_create']);
    }

    /**
     * NO ROUTE CAN DELETE A BATCH OF CHILDREN'S RECORDS.
     *
     * A draft of this feature carried `DELETE .../records/roster-import/{batch}`
     * behind an "Undo this import" button. It deleted every contact carrying the
     * tag and swept their `group_memberships` with a mass query — the same rows
     * migration 2026_09_09_040000 and `GroupMembershipsController::destroy()`
     * exist to protect, reached with none of that controller's guard. The tag
     * never expires and sits copyable on screen, so an office could fire it in
     * October against a term of register marks: on MySQL a bare 1451 naming
     * nobody, on SQLite (where that migration returns early, so no test could
     * ever have seen it) a silent erasure.
     *
     * The endpoint is gone. This test is what stops it coming back by habit:
     * the batch tag is a provenance stamp, not a delete button, and the API says
     * so before the import as well as after it.
     */
    #[Test]
    public function there_is_no_endpoint_that_can_delete_an_imported_batch(): void
    {
        $response = $this->commitOnce(
            "Grade 2,Aalaa,Salim,2nd,Musa,Salim,musa@example.test,555\n"
            . "Grade 2,Bilal,Khan,2nd,Huda,Khan,huda@example.test,556\n"
        );

        $batch = $response->json('data.batch');
        $this->assertStringStartsWith('roster-', $batch);

        $contacts = Contact::withoutGlobalScopes()->count();
        $rows = GroupMembership::withoutGlobalScopes()->count();

        $undo = $this->deleteJson("{$this->url()}/{$batch}");

        $this->assertContains($undo->status(), [404, 405],
            'no HTTP verb may remove an imported batch — the console is the only reversal there is');

        $this->assertSame($contacts, Contact::withoutGlobalScopes()->count(),
            'the people this import created are still here');
        $this->assertSame($rows, GroupMembership::withoutGlobalScopes()->count());

        // And the office is told BEFORE the write, not after it: the caution
        // rides on the preview response as well as the commit's, because "you
        // can always undo it" is the assumption that makes somebody skip reading
        // the rows.
        $this->assertStringContainsString('cannot be undone',
            (string) $response->json('meta.undo_note'));

        $preview = $this->post(
            $this->url('/preview'),
            ['file' => $this->csv("Grade 2,Zayd,Omar,2nd,,,,\n")],
            ['Accept' => 'application/json']
        );

        $this->assertStringContainsString('cannot be undone',
            (string) $preview->json('meta.undo_note'));
    }

    /**
     * A FILE WITH NO ROSTER ROWS IS REFUSED BY THE SERVER, not only by a
     * disabled button.
     *
     * `planPayload()` computes `can_commit = refused === [] && students !== []`
     * and its comment promises that `commit()` refuses independently. Only the
     * first half of that was true: a header-only CSV — an empty export, a
     * double-submit after the file was cleared, a script — answered 201 with a
     * live batch tag and every count zero, which is a success screen for an
     * import that wrote nothing.
     */
    #[Test]
    public function a_file_with_no_roster_rows_cannot_be_committed(): void
    {
        $headerOnly = fn (): UploadedFile => UploadedFile::fake()
            ->createWithContent('roster.csv', self::HEADER);

        $preview = $this->previewOf($headerOnly());

        $this->assertFalse($preview['can_commit']);
        $this->assertSame(0, $preview['totals']['students']['in_file']);

        $response = $this->post(
            $this->url(),
            ['file' => $headerOnly(), 'receipt' => $preview['receipt']],
            ['Accept' => 'application/json']
        );

        $response->assertStatus(422);
        $this->assertStringContainsString('nothing in this file to import',
            json_encode($response->json()));
        $this->assertNull($response->json('data.batch'),
            'an import that wrote nothing must not mint a batch tag');
        $this->assertSame(0, Contact::withoutGlobalScopes()->count());
    }

    /**
     * EXCEL'S DEFAULT "CSV (Comma delimited)" ON WINDOWS IS REFUSED WITH THE
     * REMEDY, not with a 500.
     *
     * That export writes the ANSI codepage, so one accented name is a lone
     * high byte. Those bytes reached `response()->json()`, `json_encode` failed
     * with JSON_ERROR_UTF8 and Laravel threw — the office saw "Server Error",
     * which does not say the fix is Save As → CSV UTF-8. The refusal has to name
     * the cause AND the remedy, because the cause is invisible in a spreadsheet.
     */
    #[Test]
    public function a_csv_that_is_not_utf8_is_refused_with_the_remedy_instead_of_a_500(): void
    {
        // "Zoë" as Windows-1252: a bare 0xEB where UTF-8 would use two bytes.
        $file = UploadedFile::fake()->createWithContent(
            'roster.csv',
            self::HEADER . "Grade 2,Zo\xEB,Salim,2nd,Musa,Salim,musa@example.test,555\n"
        );

        $response = $this->post($this->url('/preview'), ['file' => $file], ['Accept' => 'application/json']);

        $response->assertStatus(422);

        $body = json_encode($response->json());
        $this->assertStringContainsString('CSV UTF-8', $body,
            'the refusal must name the Save As option that fixes it');
        $this->assertStringContainsString('not saved as UTF-8', $body);

        // No receipt is issued for a file that could not be read, so there is
        // nothing to commit either.
        $this->assertNull($response->json('data.receipt'));
        $this->assertSame(0, Contact::withoutGlobalScopes()->count());
    }

    /**
     * A GUARDIAN ALREADY IN THE CRM UNDER A DIFFERENT CAPITALISATION IS THE SAME
     * PERSON, and the preview must say so BEFORE the commit does.
     *
     * Nothing lower-cases `contacts.email` — a donor typed by an admin, or one
     * that arrived from Stripe, is stored as it was written. `plan()` keys
     * guardians on a lower-cased address and asked the database with `whereIn`;
     * on MySQL's case-INSENSITIVE collation that matched and returned the stored
     * spelling, which a strict `in_array` then failed to recognise. The preview
     * said "1 parent will be created", `apply()` matched the existing contact,
     * and the commit answered "0 created, 1 already in contacts" — the exact
     * preview/commit divergence this screen exists to prevent, and the office
     * never saw the "they will be linked, not duplicated" disclosure that hangs
     * off `totals.guardians.existing`.
     *
     * WRITTEN SO IT FAILS ON BOTH ENGINES. Under SQLite's BINARY collation the
     * unfixed code does not match either, so preview and commit agree and CI
     * could see nothing; asserting `existing === true` on the preview payload
     * catches the bug on SQLite and on MySQL alike, and the LOWER() comparisons
     * in the service are what make the two engines behave the same way.
     */
    #[Test]
    public function a_guardian_stored_under_a_different_capitalisation_is_matched_not_created(): void
    {
        Contact::factory()->create([
            'masjid_id' => $this->school->id,
            'first_name' => 'Musa', 'last_name' => 'Salim',
            'email' => 'Musa.Salim@Gmail.com',
        ]);

        $body = "Grade 2,Aalaa,Salim,2nd,Musa,Salim,musa.salim@gmail.com,555\n";

        $preview = $this->previewOf($this->csv($body));

        $this->assertTrue($preview['guardians'][0]['existing'],
            'a parent already in the CRM is not "will be created" because they typed their email in caps');
        $this->assertSame(1, $preview['totals']['guardians']['existing']);
        $this->assertSame(0, $preview['totals']['guardians']['to_create']);

        $response = $this->post(
            $this->url(),
            ['file' => $this->csv($body), 'receipt' => $preview['receipt']],
            ['Accept' => 'application/json']
        );

        $response->assertStatus(201);

        // THE PROPERTY: what the preview promised is what the commit reports.
        $this->assertSame($preview['totals']['guardians']['to_create'],
            $response->json('data.created.guardians'),
            'the commit must create exactly the number of parents the preview promised');
        $this->assertSame(1, $response->json('data.matched.guardians'));

        // One child, one parent — the donor was linked, not duplicated.
        $this->assertSame(2, Contact::withoutGlobalScopes()->count());
        $this->assertSame(1, Contact::withoutGlobalScopes()
            ->whereRaw('LOWER(email) = ?', ['musa.salim@gmail.com'])->count());
    }

    // ------------------------------------------------------- who may run it

    #[Test]
    public function a_teacher_cannot_import_a_roster(): void
    {
        $teacher = User::factory()->create([
            'type' => 'Teacher', 'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
        MasjidUser::create([
            'masjid_id' => $this->school->id, 'user_id' => $teacher->id,
            'role' => 'teacher', 'is_default' => true,
        ]);

        Sanctum::actingAs($teacher, ['*']);

        // 401, not 403: UserAdminMiddleware admits only SuperAdmin and
        // MasjidAdmin, so a Teacher is refused at the REALM gate before `tenant`
        // binds or any permission is consulted.
        $this->post(
            $this->url('/preview'),
            ['file' => $this->csv("Grade 2,Aalaa,Salim,2nd,Musa,Salim,musa@example.test,555\n")],
            ['Accept' => 'application/json']
        )->assertUnauthorized();
    }

    #[Test]
    public function an_anonymous_caller_cannot_import_a_roster(): void
    {
        app('auth')->forgetGuards();

        $this->post(
            $this->url('/preview'),
            ['file' => $this->csv("Grade 2,Aalaa,Salim,2nd,Musa,Salim,musa@example.test,555\n")],
            ['Accept' => 'application/json']
        )->assertUnauthorized();
    }

    /**
     * A MASJIDADMIN WHO HAS HAD `manage contacts` TAKEN AWAY IS REFUSED.
     *
     * The two authorization tests above both stop at the REALM gate — a Teacher
     * and an anonymous caller are 401s from `UserAdminMiddleware`, before
     * `tenant` binds or any permission is consulted. That left the permission
     * middleware on these routes untested: deleting
     * `->middleware('permission:manage contacts')` from both route lines kept
     * every test in this file and in ImportSchoolRosterTest green, while an
     * administrator whose CRM access was withdrawn through Users & Access could
     * still bulk-create children's contact rows.
     */
    #[Test]
    public function an_admin_without_manage_contacts_cannot_preview_or_import(): void
    {
        // The guard is NAMED rather than defaulted. `Sanctum::actingAs()` in
        // setUp() rewrites `auth.defaults.guard` to `sanctum` for the rest of
        // the request, and Spatie resolves an unqualified role name against that
        // default — so `findByName('masjid-admin')` would hunt a role under a
        // guard the seeder never wrote one for. `User::$guard_name` pins `web`,
        // which is where the eight permissions live.
        Role::findByName('masjid-admin', 'web')->revokePermissionTo('manage contacts');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Sanctum::actingAs($this->admin, ['*']);

        $this->post(
            $this->url('/preview'),
            ['file' => $this->csv("Grade 2,Aalaa,Salim,2nd,Musa,Salim,musa@example.test,555\n")],
            ['Accept' => 'application/json']
        )->assertStatus(403);

        // The write half, refused by the same gate — the middleware runs before
        // the FormRequest, so no receipt is needed to prove it.
        $this->post(
            $this->url(),
            [
                'file' => $this->csv("Grade 2,Aalaa,Salim,2nd,Musa,Salim,musa@example.test,555\n"),
                'receipt' => 'anything at all',
            ],
            ['Accept' => 'application/json']
        )->assertStatus(403);

        $this->assertSame(0, Contact::withoutGlobalScopes()->count(),
            'a withdrawn permission means no children\'s rows were written');
    }

    // ------------------------------------------- the receipt, refusal by refusal

    /**
     * THE RECEIPT IS BOUND TO THE PERSON WHO READ THE PREVIEW.
     *
     * `commit()` stamps `confirmed_by_user_id` on every row it writes, and the
     * name on those rows has to be the name of somebody who read them. Without
     * this test the whole `user` branch of `receiptRefusal()` could be deleted —
     * one colleague previewing and another importing under their approval — and
     * nothing would fail.
     */
    #[Test]
    public function a_receipt_read_by_a_different_administrator_is_refused(): void
    {
        $body = "Grade 2,Aalaa,Salim,2nd,Musa,Salim,musa@example.test,555\n";

        $preview = $this->previewOf($this->csv($body));

        $colleague = User::factory()->create([
            'type' => 'MasjidAdmin', 'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
        MasjidUser::create([
            'masjid_id' => $this->school->id, 'user_id' => $colleague->id,
            'role' => 'masjid-admin', 'is_default' => true,
        ]);

        Sanctum::actingAs($colleague, ['*']);

        $response = $this->post(
            $this->url(),
            ['file' => $this->csv($body), 'receipt' => $preview['receipt']],
            ['Accept' => 'application/json']
        );

        $response->assertStatus(422);
        $this->assertStringContainsString('different administrator', json_encode($response->json()),
            'the refusal has to say WHICH of the five things went wrong');
        $this->assertSame(0, Contact::withoutGlobalScopes()->count());
    }

    /**
     * A PREVIEW GOES STALE, AND A STALE ONE CANNOT WRITE.
     *
     * The receipt is good for thirty minutes precisely so an abandoned tab
     * cannot be committed tomorrow against a roster that has moved on. Delete
     * the `expires` comparison and last week's tab still imports; nothing in the
     * suite noticed until now.
     */
    #[Test]
    public function a_receipt_older_than_its_ttl_is_refused(): void
    {
        $body = "Grade 2,Aalaa,Salim,2nd,Musa,Salim,musa@example.test,555\n";

        $preview = $this->previewOf($this->csv($body));

        $this->travel(31)->minutes();

        $response = $this->post(
            $this->url(),
            ['file' => $this->csv($body), 'receipt' => $preview['receipt']],
            ['Accept' => 'application/json']
        );

        $response->assertStatus(422);
        $this->assertStringContainsString('expired', json_encode($response->json()));
        $this->assertSame(0, Contact::withoutGlobalScopes()->count());
    }

    /**
     * A RECEIPT READ AGAINST ONE SCHOOL DOES NOT OPEN A WRITE INTO ANOTHER.
     *
     * Driven as a SuperAdmin because that is the only principal who can hold a
     * preview for one organisation and post it at another's URL — a MasjidAdmin
     * is stopped a layer earlier, by `ResolveMasjidTenant` (see
     * `an_import_into_another_school_is_refused`). Both layers matter: this one
     * is what keeps the school binding in the receipt honest for the principal
     * who is allowed through the first.
     */
    #[Test]
    public function a_receipt_issued_for_another_school_is_refused_here(): void
    {
        $super = User::factory()->create([
            'type' => 'SuperAdmin', 'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);

        Sanctum::actingAs($super, ['*']);

        $body = "Grade 2,Aalaa,Salim,2nd,Musa,Salim,musa@example.test,555\n";

        $elsewhere = $this->post(
            "/api/admin/masjids/{$this->otherSchool->id}/records/roster-import/preview",
            ['file' => $this->csv($body)],
            ['Accept' => 'application/json']
        );

        $elsewhere->assertOk();

        $response = $this->post(
            $this->url(),
            ['file' => $this->csv($body), 'receipt' => $elsewhere->json('data.receipt')],
            ['Accept' => 'application/json']
        );

        $response->assertStatus(422);
        $this->assertStringContainsString('different school', json_encode($response->json()));
        $this->assertSame(0, Contact::withoutGlobalScopes()->count());
    }

    // --------------------------------------------- 5. one school, one roster

    #[Test]
    public function an_import_into_another_school_is_refused(): void
    {
        $response = $this->post(
            "/api/admin/masjids/{$this->otherSchool->id}/records/roster-import/preview",
            ['file' => $this->csv("Grade 2,Aalaa,Salim,2nd,Musa,Salim,musa@example.test,555\n")],
            ['Accept' => 'application/json']
        );

        $this->assertContains($response->status(), [401, 403],
            'a MasjidAdmin is confined to their own masjid by ResolveMasjidTenant');

        $this->assertSame(0, Contact::withoutGlobalScopes()->count());
    }

    /**
     * The three ways another organisation's data could leak into this import,
     * all in one test because they are one property: the importer sees only this
     * school.
     */
    #[Test]
    public function another_schools_classes_children_and_contacts_are_untouchable(): void
    {
        $tenant = app(TenantContext::class);

        [$otherClass, $otherChild, $otherParent] = $tenant->runWithout(function () {
            $class = Group::factory()->create([
                'masjid_id' => $this->otherSchool->id, 'kind' => Group::KIND_CLASS,
                'name' => 'Grade 5', 'slug' => 'grade-5-' . uniqid(),
            ]);

            $child = Contact::factory()->create([
                'masjid_id' => $this->otherSchool->id, 'first_name' => 'Aalaa', 'last_name' => 'Salim',
            ]);
            GroupMembership::create([
                'masjid_id' => $this->otherSchool->id, 'group_id' => $class->id,
                'contact_id' => $child->id, 'role' => GroupMembership::ROLE_MEMBER,
            ]);

            $parent = Contact::factory()->create([
                'masjid_id' => $this->otherSchool->id, 'first_name' => 'Musa', 'last_name' => 'Salim',
                'email' => 'musa@example.test',
            ]);

            return [$class, $child, $parent];
        });

        // (a) Another school's CLASS is not a class here — the row is refused
        //     rather than importing a child into somebody else's classroom.
        $foreignClass = $this->previewOf($this->csv("Grade 5,Zayd,Omar,5th,,,,\n"));
        $this->assertFalse($foreignClass['can_commit']);
        $this->assertStringContainsString('no class named "Grade 5"', $foreignClass['refused'][0]['why']);

        // (b) A child with the same name on another school's roster is not this
        //     child, and (c) a guardian with the same email is not this guardian.
        $body = "Grade 2,Aalaa,Salim,2nd,Musa,Salim,musa@example.test,555\n";
        $preview = $this->previewOf($this->csv($body));

        $this->assertFalse($preview['students'][0]['existing'],
            'another school\'s pupil must not be reported as already enrolled here');
        $this->assertFalse($preview['guardians'][0]['existing'],
            'another school\'s contact must not be matched as this school\'s parent');

        $this->post($this->url(), ['file' => $this->csv($body), 'receipt' => $preview['receipt']],
            ['Accept' => 'application/json'])->assertStatus(201);

        // The other school is exactly as it was: no new rows, no re-pointed ones.
        $this->assertSame(2, Contact::withoutGlobalScopes()
            ->where('masjid_id', $this->otherSchool->id)->count());
        $this->assertSame(1, GroupMembership::withoutGlobalScopes()
            ->where('masjid_id', $this->otherSchool->id)->count());
        $this->assertSame($this->otherSchool->id,
            Contact::withoutGlobalScopes()->find($otherParent->id)->masjid_id);
        $this->assertSame($otherClass->id, GroupMembership::withoutGlobalScopes()
            ->where('contact_id', $otherChild->id)->firstOrFail()->group_id);

        // And this school got its own four people.
        $this->assertSame(2, Contact::withoutGlobalScopes()
            ->where('masjid_id', $this->school->id)->count());
    }

    // ---------------------------------- the reason the service exists at all

    /**
     * THE TEST THAT JUSTIFIES THE EXTRACTION. The screen's preview and the
     * console command's write are the same computation over the same file, so
     * what one promises is what the other does. If these two ever disagree,
     * somebody has re-implemented `read()` or `plan()` in one of the two callers
     * and children's rows are about to differ by which door they came through.
     */
    #[Test]
    public function the_command_and_the_screen_produce_the_same_plan_for_the_same_file(): void
    {
        $body = "Grade 2,Aalaa,Salim,2nd,Musa,Salim,musa@example.test,555\n"
            . "Grade 2,Aalaa,Salim,2nd,Huda,Salim,huda@example.test,556\n"
            . "Grade 2,Bilal,Khan,2nd,Musa,Salim,musa@example.test,555\n"
            . "Grade 9,Zayd,Omar,9th,,,,\n";

        $preview = $this->previewOf($this->csv($body));

        $path = tempnam(sys_get_temp_dir(), 'roster') . '.csv';
        file_put_contents($path, self::HEADER . $body);

        try {
            // The console's dry run prints the same three totals it would apply.
            // Running it with --execute and counting the rows is the sharper
            // comparison: it asks whether the SCREEN'S promise is what the
            // COMMAND'S write actually does.
            $this->artisan('schools:import-roster', [
                'csv' => $path, '--masjid' => $this->school->id, '--execute' => true,
            ])->run();

            // The file carries a refused row, so all-or-nothing holds on both
            // paths: the screen would not let it be committed, and the command
            // wrote none of it.
            $this->assertFalse($preview['can_commit']);
            $this->assertSame(0, Contact::withoutGlobalScopes()->count());

            // Now the same file with the bad row removed.
            $fixed = "Grade 2,Aalaa,Salim,2nd,Musa,Salim,musa@example.test,555\n"
                . "Grade 2,Aalaa,Salim,2nd,Huda,Salim,huda@example.test,556\n"
                . "Grade 2,Bilal,Khan,2nd,Musa,Salim,musa@example.test,555\n";

            $fixedPreview = $this->previewOf($this->csv($fixed));
            file_put_contents($path, self::HEADER . $fixed);

            $this->artisan('schools:import-roster', [
                'csv' => $path, '--masjid' => $this->school->id, '--execute' => true,
            ])->run();

            $this->assertSame(
                $fixedPreview['totals']['students']['to_create'],
                Contact::withoutGlobalScopes()->whereNull('email')->count(),
                'the screen promised this many children and the command created exactly that many'
            );
            $this->assertSame(
                $fixedPreview['totals']['guardians']['to_create'],
                Contact::withoutGlobalScopes()->whereNotNull('email')->count()
            );
            $this->assertSame(
                $fixedPreview['totals']['edges'],
                GroupMembership::withoutGlobalScopes()->where('role', GroupMembership::ROLE_GUARDIAN)->count()
            );
        } finally {
            @unlink($path);
        }
    }

    // ------------------------------------------------------------- helper

    /** Preview then commit one file, the way the screen does. */
    private function commitOnce(string $body): \Illuminate\Testing\TestResponse
    {
        $preview = $this->previewOf($this->csv($body));

        $response = $this->post(
            $this->url(),
            ['file' => $this->csv($body), 'receipt' => $preview['receipt']],
            ['Accept' => 'application/json']
        );

        $response->assertStatus(201);

        return $response;
    }
}
