<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\Masjid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Bulk roster import (R7).
 *
 * The properties asserted here are the ones that make a bulk write into
 * children's records safe rather than merely convenient:
 *
 *  1. Dry run by default — nothing is written until someone says --execute.
 *  2. All or nothing — a file with a bad row writes none of it.
 *  3. Idempotent — the same file twice creates one roster, not two.
 *  4. A CSV cannot grant consent, and cannot invent a class.
 *  5. Reversible — a wrong file is undone by its batch tag.
 */
class ImportSchoolRosterTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $school;
    private Group $class;
    private string $csv;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->school = Masjid::create([
            'name' => 'School ' . uniqid(),
            'email' => 'school-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 St',
            'latitude' => 0.0, 'longitude' => 0.0,
            'crm_enabled' => true, 'org_type' => 'school',
        ]);

        $this->class = Group::factory()->create([
            'masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS,
            'name' => 'Grade 2', 'slug' => 'grade-2-' . uniqid(),
        ]);

        $this->csv = tempnam(sys_get_temp_dir(), 'roster') . '.csv';
    }

    protected function tearDown(): void
    {
        @unlink($this->csv);
        parent::tearDown();
    }

    private function write(string $body): void
    {
        file_put_contents($this->csv,
            "class,student_first_name,student_last_name,grade,guardian_first_name,guardian_last_name,guardian_email,guardian_phone\n"
            . $body);
    }

    private function importCsv(array $opts = []): int
    {
        return $this->artisan('schools:import-roster', array_merge([
            'csv' => $this->csv,
            '--masjid' => $this->school->id,
        ], $opts))->run();
    }

    // ----------------------------------------------------------- 1. dry run

    #[Test]
    public function a_dry_run_writes_nothing(): void
    {
        $this->write("Grade 2,Aalaa,Salim,2nd,Musa,Salim,musa@example.test,555\n");

        $this->importCsv();

        $this->assertSame(0, Contact::count(), 'a dry run must not create a soul');
        $this->assertSame(0, GroupMembership::count());
    }

    #[Test]
    public function execute_creates_the_child_the_guardian_and_the_edge(): void
    {
        $this->write("Grade 2,Aalaa,Salim,2nd,Musa,Salim,musa@example.test,555\n");

        $this->assertSame(0, $this->importCsv(['--execute' => true]));

        $child = Contact::where('first_name', 'Aalaa')->firstOrFail();
        $guardian = Contact::where('email', 'musa@example.test')->firstOrFail();

        $enrolment = GroupMembership::where('contact_id', $child->id)
            ->where('role', GroupMembership::ROLE_MEMBER)->firstOrFail();
        $this->assertSame('2nd', $enrolment->grade_label);

        // Staff-authored, not a claim to be judged later.
        $this->assertTrue($enrolment->isConfirmed());

        $edge = GroupMembership::where('contact_id', $guardian->id)
            ->where('role', GroupMembership::ROLE_GUARDIAN)->firstOrFail();
        $this->assertSame($child->id, $edge->guardian_of_contact_id);
        $this->assertTrue($edge->isConfirmed());
    }

    // ------------------------------------------------- 2 & 4. what it refuses

    #[Test]
    public function a_class_that_does_not_exist_is_refused_and_never_created(): void
    {
        $this->write("Grade 9,Aalaa,Salim,9th,Musa,Salim,musa@example.test,555\n");

        $this->assertSame(1, $this->importCsv(['--execute' => true]), 'the command must fail');

        $this->assertSame(0, Contact::count(), 'nothing written');
        $this->assertSame(1, Group::count(), 'a typo must not found a second classroom');
    }

    /**
     * A file with one bad row writes NONE of it. A roster that imported forty of
     * sixty children is worse than one that imported none, because only the
     * second is obviously unfinished.
     */
    #[Test]
    public function one_unreadable_row_stops_the_whole_import(): void
    {
        $this->write(
            "Grade 2,Aalaa,Salim,2nd,Musa,Salim,musa@example.test,555\n"
            . "Grade 9,Bilal,Khan,2nd,Ali,Khan,ali@example.test,555\n"
        );

        $this->assertSame(1, $this->importCsv(['--execute' => true]));

        $this->assertSame(0, Contact::count(), 'the good row must not land either');
    }

    /** Consent is a parent's act. A spreadsheet the school typed cannot perform it. */
    #[Test]
    public function the_import_cannot_grant_consent(): void
    {
        $this->write("Grade 2,Aalaa,Salim,2nd,Musa,Salim,musa@example.test,555\n");
        $this->importCsv(['--execute' => true]);

        foreach (GroupMembership::all() as $m) {
            $this->assertNull($m->consent_granted_at, 'no imported row may carry consent');
            $this->assertNull($m->consent_scope);
        }
    }

    // ------------------------------------------------------- 3. idempotency

    #[Test]
    public function running_the_same_file_twice_creates_one_roster(): void
    {
        $this->write("Grade 2,Aalaa,Salim,2nd,Musa,Salim,musa@example.test,555\n");

        $this->importCsv(['--execute' => true]);
        $this->importCsv(['--execute' => true]);

        $this->assertSame(2, Contact::count(), 'one child, one guardian');
        $this->assertSame(2, GroupMembership::count(), 'one enrolment, one edge');
    }

    #[Test]
    public function two_guardians_for_one_child_are_two_rows_and_one_child(): void
    {
        $this->write(
            "Grade 2,Aalaa,Salim,2nd,Musa,Salim,musa@example.test,555\n"
            . "Grade 2,Aalaa,Salim,2nd,Huda,Salim,huda@example.test,556\n"
        );

        $this->importCsv(['--execute' => true]);

        $this->assertSame(1, Contact::where('first_name', 'Aalaa')->count(), 'one child');
        $this->assertSame(2, GroupMembership::where('role', GroupMembership::ROLE_GUARDIAN)->count());
    }

    // --------------------------------------------------------- 5. reversible

    #[Test]
    public function a_batch_can_be_rolled_back(): void
    {
        $this->write("Grade 2,Aalaa,Salim,2nd,Musa,Salim,musa@example.test,555\n");
        $this->importCsv(['--execute' => true, '--batch' => 'try-1']);

        $this->assertSame(2, Contact::count());

        $this->artisan('schools:import-roster', [
            'csv' => $this->csv, '--masjid' => $this->school->id, '--rollback' => 'try-1',
        ])->run();

        $this->assertSame(0, Contact::count(), 'the batch is gone');
        $this->assertSame(0, GroupMembership::count(), 'and so are its roster rows');
    }

    /**
     * Our own export writes a leading apostrophe in front of anything
     * formula-shaped. A file that made that round trip must not import a child
     * called "'=Ali".
     */
    #[Test]
    public function a_round_tripped_formula_guard_is_stripped_on_the_way_back_in(): void
    {
        $this->write("Grade 2,'=Ali,Khan,2nd,Musa,Khan,musa2@example.test,555\n");
        $this->importCsv(['--execute' => true]);

        $this->assertSame(1, Contact::where('first_name', '=Ali')->count(),
            'the export guard must be undone on import, not stored');
    }
}
