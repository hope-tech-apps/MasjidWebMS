<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\Masjid;
use App\Services\Schools\RosterImportService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FoldsAccentsLikeUnicodeCi;
use Tests\TestCase;

/**
 * A roster import links a guardian by the address in the file, EXACTLY.
 *
 * THE DEFECT (the point's review of b9f11d4c, 2026-09-29). `apply()` matched a
 * guardian with `LOWER(email) = ?` and took `->first()`. Production's
 * `contacts.email` is utf8mb4_unicode_ci, where `parent@gmail.com` =
 * `parent@gmaíl.com`. Somebody who registers through the ANONYMOUS
 * offering-registration door with the look-alike address gets a contact there,
 * and the next roster import linked that contact as the staff-CONFIRMED
 * guardian of the real parent's children.
 *
 * HOW THESE TESTS REACH IT ON SQLITE (Tests\Support\FoldsAccentsLikeUnicodeCi):
 * the look-alike's address is stored ACCENTED, the plain one is in the file, and
 * `LOWER()` is overridden to fold accents, so the SQL returns the look-alike as
 * MySQL would. Every test asserts that premise first.
 */
class RosterImportLookAlikeAddressTest extends TestCase
{
    use FoldsAccentsLikeUnicodeCi;
    use RefreshDatabase;

    /** What the look-alike contact holds: the accented form. */
    private const STORED = 'parent@gmaíl.com';

    /** What the roster names: the real parent's address. */
    private const TYPED = 'parent@gmail.com';

    private Masjid $school;

    private Group $class;

    private string $csv;

    protected function setUp(): void
    {
        parent::setUp();

        $this->foldAccentsLikeUnicodeCi();

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
        $this->stopFoldingAccents();

        parent::tearDown();
    }

    #[Test]
    public function a_look_alike_contact_does_not_become_the_confirmed_guardian_of_the_real_parents_children(): void
    {
        $lookAlike = $this->contact(self::STORED);
        $this->assertTheSqlStillFinds([$lookAlike]);
        $before = $this->stored($lookAlike);

        $this->write('parent@gmail.com');
        $this->assertSame(0, $this->importCsv());

        // The real parent gets a contact of their own, at the address in the file.
        $guardian = Contact::withoutMasjidScope()->where('email', self::TYPED)->sole();
        $this->assertNotSame($lookAlike->id, $guardian->id);
        $this->assertSame($guardian->id, $this->guardianOfTheChild(), 'The look-alike was linked as the guardian.');

        // ...and the look-alike has no membership, no edge, and no write.
        $this->assertSame(0, DB::table('group_memberships')->where('contact_id', $lookAlike->id)->count());
        $this->assertSame($before, $this->stored($lookAlike), 'The look-alike contact was written to.');
    }

    #[Test]
    public function the_preview_does_not_call_a_look_alike_already_on_record(): void
    {
        $lookAlike = $this->contact(self::STORED);
        $this->assertTheSqlStillFinds([$lookAlike]);

        $this->write('parent@gmail.com');
        app(TenantContext::class)->set($this->school->id);

        $importer = app(RosterImportService::class);
        ['rows' => $rows] = $importer->read($this->csv);
        $plan = $importer->plan($rows);

        $this->assertFalse($plan['guardians'][self::TYPED]['existing'], 'The preview reported a look-alike as this parent, and apply() would have linked it.');
    }

    #[Test]
    public function the_importers_first_holder_rule_reads_the_exact_holders_only(): void
    {
        // The look-alike has the LOWEST id, so an unfiltered `first()` returns it.
        // The importer's rule is unchanged, "the first contact holding the
        // address", and it now ranges over the exact holders alone.
        $lookAlike = $this->contact(self::STORED);
        $firstExact = $this->contact('Parent@Gmail.com');
        $secondExact = $this->contact(self::TYPED);
        $this->assertTheSqlStillFinds([$lookAlike, $firstExact, $secondExact]);

        $this->write('parent@gmail.com');
        $this->assertSame(0, $this->importCsv());

        $this->assertSame($firstExact->id, $this->guardianOfTheChild());
        $this->assertSame(0, DB::table('group_memberships')->where('contact_id', $lookAlike->id)->count());
        $this->assertSame(
            3,
            Contact::withoutMasjidScope()->where('first_name', '!=', 'Aalaa')->count(),
            'A contact was created for an address that contacts already hold.',
        );
    }

    #[Test]
    public function an_address_that_differs_only_in_case_still_links_the_existing_parent(): void
    {
        $parent = $this->contact('Parent@GMAIL.com');

        $this->write(' PARENT@gmail.com ');
        $this->assertSame(0, $this->importCsv());

        $this->assertSame($parent->id, $this->guardianOfTheChild());
        $this->assertSame(1, Contact::withoutMasjidScope()->where('first_name', '!=', 'Aalaa')->count(), 'A second contact was made for the same parent.');
    }

    // ---------------------------------------------------------------- helpers

    private function contact(string $email): Contact
    {
        $contact = new Contact();
        $contact->forceFill([
            'masjid_id' => $this->school->id,
            'first_name' => 'On',
            'last_name' => 'File',
            'email' => $email,
        ])->save();

        return $contact->refresh();
    }

    /** @param  list<Contact>  $expected */
    private function assertTheSqlStillFinds(array $expected): void
    {
        $this->assertSame(
            array_map(fn (Contact $c) => $c->id, $expected),
            Contact::withoutMasjidScope()->whereRaw('LOWER(email) = ?', [self::TYPED])->orderBy('id')->pluck('id')->all(),
            'PREMISE: LOWER(email) = the typed address must return the look-alike too, or this test proves nothing.',
        );
    }

    /** @return array<string, mixed> */
    private function stored(Contact $contact): array
    {
        return Contact::withoutMasjidScope()->withTrashed()->whereKey($contact->getKey())->firstOrFail()->getAttributes();
    }

    private function write(string $guardianEmail): void
    {
        file_put_contents(
            $this->csv,
            "class,student_first_name,student_last_name,grade,guardian_first_name,guardian_last_name,guardian_email,guardian_phone\n"
            . "Grade 2,Aalaa,Salim,2nd,Musa,Salim,{$guardianEmail},555\n",
        );
    }

    private function importCsv(): int
    {
        return $this->artisan('schools:import-roster', [
            'csv' => $this->csv,
            '--masjid' => $this->school->id,
            '--execute' => true,
        ])->run();
    }

    /** The contact the import made the child's guardian. */
    private function guardianOfTheChild(): int
    {
        return (int) DB::table('group_memberships')
            ->where('role', GroupMembership::ROLE_GUARDIAN)
            ->sole()
            ->contact_id;
    }
}
