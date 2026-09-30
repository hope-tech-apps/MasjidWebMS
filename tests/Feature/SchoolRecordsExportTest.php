<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Contact;
use App\Models\Donation;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\LessonPlan;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use App\Support\SchoolRecordsCsv;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The school can take its records with it (R1).
 *
 * The assertions here are the four an adversarial review said the obvious
 * implementation would fail:
 *
 *  1. The contacts file is bounded by the SCHOOL ROSTER, not the tenant. The
 *     masjid is the mosque; `contacts` is its whole CRM table. A tenant-scoped
 *     dump would hand a departing madrasah every donor in the congregation.
 *  2. It carries no credentials and no SMS consent. Consent belongs to the party
 *     that obtained it.
 *  3. A teacher cannot reach it.
 *  4. It survives the encoding and spreadsheet traps that make an export look
 *     fine and arrive corrupt.
 */
class SchoolRecordsExportTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $school;
    private User $admin;
    private Group $class;
    private GroupMembership $student;
    private Contact $donorOnly;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->school = $this->makeMasjid();

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
            'name' => 'Combined A', 'slug' => 'combined-a-' . uniqid(),
        ]);

        // A child ON the school roster.
        $child = Contact::factory()->create([
            'masjid_id' => $this->school->id, 'first_name' => 'Aalaa', 'last_name' => 'Test',
            'email' => 'aalaa@example.test',
        ]);
        $this->student = GroupMembership::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id,
            'contact_id' => $child->id, 'role' => GroupMembership::ROLE_MEMBER,
        ]);

        // A congregant who has NEVER been near the school — a donor.
        $this->donorOnly = Contact::factory()->create([
            'masjid_id' => $this->school->id, 'first_name' => 'Yusuf', 'last_name' => 'Donor',
            'email' => 'donor-only@example.test',
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

    private function url(string $dataset): string
    {
        return "/api/admin/masjids/{$this->school->id}/records/export?dataset={$dataset}";
    }

    private function body(string $dataset): string
    {
        $res = $this->get($this->url($dataset));
        $res->assertOk();

        ob_start();
        $res->sendContent();

        return ob_get_clean();
    }

    // ------------------------------------------------- 1. the school, not the mosque

    /**
     * THE FINDING THIS PINS: `contacts` is the masjid's CRM table, shared with
     * donations, meal orders and event registrations. Exporting it tenant-scoped
     * would give a departing school the whole congregation.
     */
    #[Test]
    public function the_contacts_file_holds_the_school_roster_and_not_the_congregation(): void
    {
        $csv = $this->body('contacts');

        $this->assertStringContainsString('Aalaa', $csv, 'a child on the roster belongs in the export');
        $this->assertStringNotContainsString('donor-only@example.test', $csv,
            'a congregant who never enrolled a child must not be in a SCHOOL records export');
        $this->assertStringNotContainsString('Yusuf', $csv);
    }

    #[Test]
    public function a_guardian_named_against_a_child_is_included(): void
    {
        $parent = Contact::factory()->create([
            'masjid_id' => $this->school->id, 'first_name' => 'Huda', 'last_name' => 'Parent',
        ]);
        GroupMembership::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id,
            'contact_id' => $parent->id, 'role' => GroupMembership::ROLE_GUARDIAN,
            'guardian_of_contact_id' => $this->student->contact_id,
        ]);

        $this->assertStringContainsString('Huda', $this->body('contacts'));
    }

    // ------------------------------------------------- 2. what must never travel

    #[Test]
    public function the_export_carries_no_credentials_and_no_sms_consent(): void
    {
        $this->student->contact->forceFill([
            'login_email' => 'parent-login@example.test',
            'login_enabled_at' => now(),
            'password' => bcrypt('a-real-password-here'),
            'notes' => 'PASTORAL NOTE ABOUT THE FAMILY',
        ])->save();

        $csv = $this->body('contacts');

        foreach (['parent-login@example.test', '$2y$', 'PASTORAL NOTE'] as $secret) {
            $this->assertStringNotContainsString($secret, $csv, "{$secret} must never leave");
        }

        $header = strtok($csv, "\n");
        foreach (['SMS', 'consent', 'Notes', 'password'] as $column) {
            $this->assertStringNotContainsStringIgnoringCase($column, $header,
                "the contacts header must not offer a {$column} column");
        }
    }

    // ------------------------------------------------------------- 3. who may run it

    #[Test]
    public function a_teacher_cannot_export_the_school(): void
    {
        $teacher = User::factory()->create([
            'type' => 'Teacher', 'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
        MasjidUser::create([
            'masjid_id' => $this->school->id, 'user_id' => $teacher->id,
            'role' => 'teacher', 'is_default' => true,
        ]);

        Sanctum::actingAs($teacher, ['*']);

        // 401, not 403, and that is the stronger answer: `UserAdminMiddleware`
        // admits only SuperAdmin and MasjidAdmin, so a Teacher is refused at the
        // REALM gate before `tenant` binds or any permission is consulted. They
        // are not an admin who lacks a grant; they are not in this realm at all.
        $this->get($this->url('contacts'))->assertUnauthorized();
    }

    #[Test]
    public function an_anonymous_caller_cannot_export_the_school(): void
    {
        app('auth')->forgetGuards();

        $this->getJson($this->url('contacts'))->assertUnauthorized();
    }

    /**
     * T-004.2: an English drill id is now `a.upper`, which a receiving school can
     * decode but should not have to. The readable form is APPENDED (after
     * 'Alphabet') so positional readers keep their columns, and 'Drill id' keeps
     * the stored identity.
     */
    #[Test]
    public function the_letters_file_spells_an_english_drill_out_and_keeps_the_stored_id_where_it_was(): void
    {
        foreach ([['english', 'a.upper'], ['english', 'a.lower'], ['arabic', 'ba']] as [$alphabet, $drill]) {
            \App\Models\ArabicLetterProgress::withoutMasjidScope()->create([
                'masjid_id' => $this->school->id, 'group_id' => $this->class->id,
                'group_membership_id' => $this->student->id, 'alphabet' => $alphabet,
                'drill_id' => $drill, 'status' => 'mastered',
            ]);
        }

        $lines = array_values(array_filter(explode("\n", str_replace("\r", '', $this->body('arabic_progress')))));
        $header = str_getcsv(ltrim($lines[0], "\xEF\xBB\xBF"));

        // Existing positions are unchanged; the new column is last.
        $this->assertSame(['Drill id', 'Alphabet', 'Letter'], [$header[4], $header[7], $header[8]]);

        $byDrill = [];
        foreach (array_slice($lines, 1) as $line) {
            $cells = str_getcsv($line);
            $byDrill[$cells[4]] = $cells;
        }

        $this->assertSame('Capital A', $byDrill['a.upper'][8]);
        $this->assertSame('Lower case a', $byDrill['a.lower'][8]);
        $this->assertSame('', $byDrill['ba'][8], 'Arabic rows keep a blank Letter column');
    }

    /** A row written before the split, and one the migration has not reached yet, must not export a blank Letter. */
    #[Test]
    public function the_letters_file_explains_a_pre_split_bare_english_row_instead_of_leaving_it_blank(): void
    {
        \App\Models\ArabicLetterProgress::withoutMasjidScope()->create([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id,
            'group_membership_id' => $this->student->id, 'alphabet' => 'english',
            'drill_id' => 'a', 'status' => 'mastered',
        ]);

        $lines = array_values(array_filter(explode("\n", str_replace("\r", '', $this->body('arabic_progress')))));
        $cells = str_getcsv($lines[1]);

        $this->assertSame('a', $cells[4], 'the stored id is unchanged');
        $this->assertSame('Letter a (recorded before capitals and lower case were tracked separately)', $cells[8]);
    }

    // --------------------------------------------- 4. the traps that corrupt quietly

    /**
     * Arabic is the point of this system — letter drills and sūrah names are
     * Arabic by definition. Excel reads a BOM-less file as CP-1252.
     */
    #[Test]
    public function every_file_opens_with_a_utf8_bom(): void
    {
        foreach (['manifest', 'contacts', 'attendance'] as $dataset) {
            $this->assertStringStartsWith("\xEF\xBB\xBF", $this->body($dataset),
                "{$dataset} must carry a BOM or Arabic arrives as mojibake");
        }
    }

    /** A teacher's note must not execute in the receiving school's spreadsheet. */
    #[Test]
    public function a_note_that_looks_like_a_formula_is_neutralised(): void
    {
        AttendanceRecord::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id,
            'group_membership_id' => $this->student->id,
            'session_date' => '2026-09-01', 'status' => 'present',
            'note' => '=HYPERLINK("http://evil.test","click")',
        ]);

        $csv = $this->body('attendance');

        $this->assertStringContainsString("'=HYPERLINK", $csv, 'the formula must be prefixed');
        $this->assertStringNotContainsString(',=HYPERLINK', $csv, 'and must not sit raw in a cell');
    }

    /**
     * The guard must NOT touch numbers: a negative behaviour score written as
     * text makes a receiving school's column total silently wrong.
     */
    #[Test]
    public function a_negative_number_is_not_turned_into_text(): void
    {
        $this->assertSame('-2', SchoolRecordsCsv::num(-2));
        $this->assertSame("'-2", SchoolRecordsCsv::text('-2'),
            'text() still guards — the point is that numbers do not go through it');
    }

    /**
     * chunkById paginates on the primary key. Any other ORDER BY survives as the
     * primary sort and makes the cursor skip and duplicate rows — silently. The
     * donation export documents that in a comment; here it is enforced.
     */
    #[Test]
    public function a_query_carrying_an_order_by_is_refused_rather_than_silently_short(): void
    {
        $this->expectException(\LogicException::class);

        SchoolRecordsCsv::each(
            Contact::query()->orderBy('first_name'),
            fn () => null
        );
    }

    #[Test]
    public function the_manifest_names_every_dataset_and_says_what_is_missing(): void
    {
        $csv = $this->body('manifest');

        foreach (['contacts', 'attendance', 'report_cards', 'hifz', 'lesson_plans'] as $d) {
            $this->assertStringContainsString($d, $csv);
        }

        $this->assertStringContainsString('Not included', $csv,
            'a school must be told what the export deliberately leaves out');
        $this->assertStringContainsString('messages', $csv);
    }

    #[Test]
    public function the_bucks_ledger_travels_with_the_school_without_the_teachers_note(): void
    {
        $row = fn (string $kind, int $amount, ?string $week, ?string $note = null) => \App\Models\PrizeLedgerEntry::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id, 'group_membership_id' => $this->student->id,
            'kind' => $kind, 'amount' => $amount, 'week_start' => $week, 'note' => $note, 'prize_title' => $kind === 'redeemed' ? 'Sticker' : null,
        ]);
        $row('earned', 7, '2026-10-04');
        $row('redeemed', -3, null, 'PRIVATE-TEACHER-NOTE about the child');

        $csv = $this->body('bucks_ledger');
        $rows = array_map('str_getcsv', array_filter(explode("\n", preg_replace('/^\xEF\xBB\xBF/', '', $csv))));

        $this->assertSame(['Entry id', 'Class id', 'Membership id', 'Kind', 'Amount', 'Week start', 'Prize', 'Corrects entry id', 'Occurred at'], $rows[0]);
        $this->assertCount(3, $rows);
        $this->assertSame(['earned', '7', '2026-10-04'], [$rows[1][3], $rows[1][4], $rows[1][5]]);
        $this->assertSame(['redeemed', '-3', 'Sticker'], [$rows[2][3], $rows[2][4], $rows[2][6]]);
        $this->assertSame((string) $this->student->id, $rows[1][2], 'the membership id re-links it to the enrollments file');
        $this->assertStringNotContainsString('PRIVATE-TEACHER-NOTE', $csv);
        $this->assertStringContainsString('bucks_ledger', $this->body('manifest'));
    }

    #[Test]
    public function the_lesson_plans_file_says_which_subject_each_plan_of_a_day_is(): void
    {
        foreach (['Math' => 'Count to twenty.', 'Science' => 'Sink or float.'] as $subject => $body) {
            LessonPlan::create([
                'masjid_id' => $this->school->id, 'group_id' => $this->class->id,
                'session_date' => '2026-09-14', 'subject' => $subject, 'body' => $body,
            ]);
        }

        $rows = array_map('str_getcsv', array_filter(explode("\n", $this->body('lesson_plans'))));
        $header = array_shift($rows);

        $this->assertContains('Subject', $header);
        $subject = array_search('Subject', $header, true);
        $this->assertEqualsCanonicalizing(['Math', 'Science'], array_column($rows, $subject),
            'two plans for one class on one day are told apart by their subject');
    }

    #[Test]
    public function an_unknown_dataset_is_refused(): void
    {
        $this->getJson("/api/admin/masjids/{$this->school->id}/records/export?dataset=everything")
            ->assertUnprocessable();
    }
}
