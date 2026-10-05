<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\Masjid;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * THE OFFICE'S "STUDENT DETAILS" PANEL IS DRAWN FROM THE ROSTER LISTING, SO THE
 * LISTING'S SHAPE IS PINNED HERE.
 *
 * Tapping a student's name on the office roster opens a panel that makes NO
 * request: it reads the student's own row and the guardian entries that name
 * them, out of `GET …/groups/{group}/members`, which the page already holds
 * (resources/vue-app/core/helpers/studentDetails.ts). The panel added nothing to
 * the server. Two things follow, and each is a test below.
 *
 * 1. THE EXACT KEY SET, NOT THE ABSENCE OF NAMES. The panel shows the office
 *    whatever the row carries about a child and their guardians. A denylist is a
 *    snapshot of what somebody thought was sensitive today, and the next column
 *    added to `group_memberships` or to the narrowed person would publish itself
 *    to this listing with every test green. The whole set is pinned (the house
 *    pattern, MobileDevicePayloadTest), so a new key fails here and has to be
 *    classified by whoever adds it.
 *
 * 2. THE FIELDS THE PANEL DECIDES BY ARE ON THE WIRE UNDER THESE NAMES. Who is
 *    offered as someone to call is decided from `role`, `guardian_of_contact_id`,
 *    `provenance` and `left_on`; the links are built from `contact.phone` and
 *    `contact.email`. TypeScript checks the screen against a type, not against
 *    the wire: a key renamed here would leave the panel listing nobody, or
 *    listing an unconfirmed claimant as confirmed, with the SPA build green.
 *
 * "Open full record" then sends the office to the Member Directory with the
 * person's id in the address, and the id in an address is whatever somebody
 * typed. The last tests pin the three answers that link depends on.
 */
class OfficeStudentDetailsPayloadTest extends TestCase
{
    use RefreshDatabase;

    /**
     * One roster row on the office listing: the model's own columns, the three
     * narrowed people, the evidence of a claim, and `claim`.
     *
     * Classified when the three roster features were put together, each a
     * thing the office is meant to read on this list:
     *
     *   - the four `moved_*` columns, the two classes they name (`moved_to`,
     *     `moved_from`: id, name, deleted_at) and `moved_to_state`: where a
     *     student was moved to or from, by whom, and whether "Put back" may be
     *     offered (RosterMoveRosterTest pins what is inside them);
     *   - `age`: a whole number of years or null. NEVER a date: the date of
     *     birth is read one student at a time from its own endpoint
     *     (StudentBirthDateLeakTest pins that it rides on no list).
     *
     * @var list<string>
     */
    private const ROW = [
        'id', 'masjid_id', 'group_id', 'contact_id', 'role', 'grade_label',
        'guardian_of_contact_id', 'joined_at', 'left_on',
        'consent_granted_at', 'consent_scope',
        'provenance', 'confirmed_at', 'confirmed_by_user_id', 'source_registration_id',
        'left_recorded_by_user_id',
        'moved_from_group_id', 'moved_to_group_id', 'moved_on', 'moved_by_user_id',
        'created_at', 'updated_at',
        'contact', 'guardian_of', 'confirmed_by', 'source_registration',
        'moved_to', 'moved_from', 'moved_to_state',
        'age', 'age_given',
        'claim',
    ];

    /**
     * A person on a roster row, narrowed by the controller to what a roster
     * screen names. `email` and `phone` are how the office reaches a guardian.
     *
     * @var list<string>
     */
    private const PERSON = [
        'id', 'first_name', 'last_name', 'email', 'phone',
        'avatar_character', 'avatar_tone', 'avatar_color',
        'staff_avatar_character', 'staff_avatar_tone', 'staff_avatar_color',
        'avatar',
    ];

    /**
     * The child a guardian entry names. Narrower again: no phone.
     *
     * @var list<string>
     */
    private const WARD = ['id', 'first_name', 'last_name', 'email', 'avatar'];

    /** @var list<string> */
    private const CLAIM = ['fingerprint', 'contested', 'rival_claim_ids', 'origin'];

    private Masjid $masjid;

    private User $admin;

    private Group $class;

    private Contact $student;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        [$this->masjid, $this->admin] = $this->organisation();

        $this->class = Group::factory()->create([
            'masjid_id' => $this->masjid->id,
            'kind' => Group::KIND_CLASS,
            'name' => 'Grade 3',
        ]);

        $this->student = $this->person($this->masjid, 'Maryam', 'Testwood');
        $this->confirmedRow($this->student, GroupMembership::ROLE_MEMBER);
    }

    // ====================================================== the exact shape

    #[Test]
    public function a_student_row_on_the_office_roster_is_exactly_these_keys(): void
    {
        $row = $this->rowFor($this->student);

        $this->assertSameKeys(self::ROW, $row, 'student row');
        $this->assertSameKeys(self::PERSON, $row['contact'], 'student row, contact');
        $this->assertSameKeys(self::CLAIM, $row['claim'], 'student row, claim');
        $this->assertSameKeys(['id', 'name'], $row['confirmed_by'], 'student row, confirmed_by');

        // A student is the person themselves: nobody is their ward here, and no
        // signup stands behind a row the office typed.
        $this->assertNull($row['guardian_of']);
        $this->assertNull($row['source_registration']);

        // Nobody moved this student and no date of birth is on file: each of
        // the new keys is present and null, never absent.
        foreach (['moved_from_group_id', 'moved_to_group_id', 'moved_on', 'moved_by_user_id',
            'moved_to', 'moved_from', 'moved_to_state', 'age'] as $key) {
            $this->assertNull($row[$key], $key);
        }

        // And no age was given by the family either: a plain false, never absent.
        $this->assertFalse($row['age_given']);
    }

    #[Test]
    public function a_guardian_entry_is_the_same_row_with_the_child_it_names(): void
    {
        $mother = $this->person($this->masjid, 'Salma', 'Testwood', [
            'email' => 'salma@household.test',
            'phone' => '+15550100001',
        ]);
        $this->confirmedRow($mother, GroupMembership::ROLE_GUARDIAN, $this->student);

        $row = $this->rowFor($mother);

        $this->assertSameKeys(self::ROW, $row, 'guardian row');
        $this->assertSameKeys(self::PERSON, $row['contact'], 'guardian row, contact');
        $this->assertSameKeys(self::WARD, $row['guardian_of'], 'guardian row, guardian_of');
        $this->assertSameKeys(self::CLAIM, $row['claim'], 'guardian row, claim');
    }

    #[Test]
    public function nothing_else_the_directory_holds_about_a_guardian_rides_on_the_roster(): void
    {
        // VALUES, not only key names: a withheld column republished under another
        // key passes a key check. Each one is planted with a sentinel and the raw
        // body is searched for it.
        $mother = $this->person($this->masjid, 'Salma', 'Testwood', [
            'email' => 'salma@household.test',
            'phone' => '+15550100001',
            'notes' => 'SENTINEL-office-note',
        ]);
        $mother->forceFill([
            'login_email' => 'sentinel-sign-in@household.test',
            'login_enabled_at' => now(),
        ])->save();
        $this->confirmedRow($mother, GroupMembership::ROLE_GUARDIAN, $this->student);

        Sanctum::actingAs($this->admin);

        $body = (string) $this->getJson($this->adminUrl("/groups/{$this->class->id}/members"))
            ->assertOk()
            ->getContent();

        // The control: the listing really did serve this guardian.
        $this->assertStringContainsString('salma@household.test', $body);

        $this->assertStringNotContainsString('SENTINEL-office-note', $body);
        $this->assertStringNotContainsString('sentinel-sign-in@household.test', $body);
    }

    // ============================================ what the panel decides by

    #[Test]
    public function the_three_kinds_of_guardian_entry_are_told_apart_on_the_wire(): void
    {
        // The panel offers a tap-to-call link for the first of these and for
        // nobody else. It can only tell them apart by what is asserted here.
        $mother = $this->person($this->masjid, 'Salma', 'Testwood', [
            'email' => 'salma@household.test',
            'phone' => '+15550100001',
        ]);
        $confirmed = $this->confirmedRow($mother, GroupMembership::ROLE_GUARDIAN, $this->student);
        $confirmed->forceFill(['consent_scope' => 'media', 'consent_granted_at' => '2026-09-05 00:00:00'])->save();

        $claimant = $this->person($this->masjid, 'Idris', 'Formfield', ['email' => 'idris@elsewhere.test']);
        $claim = new GroupMembership([
            'masjid_id' => $this->masjid->id,
            'group_id' => $this->class->id,
            'contact_id' => $claimant->id,
            'role' => GroupMembership::ROLE_GUARDIAN,
            'guardian_of_contact_id' => $this->student->id,
        ]);
        $claim->selfAssertedFrom(null)->save();

        $uncle = $this->person($this->masjid, 'Harun', 'Testwood', ['phone' => '+15550100002']);
        $this->confirmedRow($uncle, GroupMembership::ROLE_GUARDIAN, $this->student)
            ->markLeftByStaff($this->admin, '2026-09-20')
            ->save();

        $current = $this->rowFor($mother);
        $this->assertSame('guardian', $current['role']);
        $this->assertSame($this->student->id, $current['guardian_of_contact_id']);
        $this->assertSame('confirmed', $current['provenance']);
        $this->assertNull($current['left_on']);
        $this->assertSame('+15550100001', $current['contact']['phone']);
        $this->assertSame('salma@household.test', $current['contact']['email']);
        $this->assertSame('media', $current['consent_scope']);
        $this->assertStringStartsWith('2026-09-05', (string) $current['consent_granted_at']);

        $pending = $this->rowFor($claimant);
        $this->assertSame('guardian', $pending['role']);
        $this->assertSame($this->student->id, $pending['guardian_of_contact_id']);
        $this->assertNotSame('confirmed', $pending['provenance']);
        $this->assertNull($pending['left_on']);
        $this->assertNull($pending['consent_scope']);

        $departed = $this->rowFor($uncle);
        $this->assertSame('confirmed', $departed['provenance']);
        // A stored DAY. The screen reads the first ten characters and never
        // passes it through a timezone.
        $this->assertStringStartsWith('2026-09-20', (string) $departed['left_on']);
    }

    #[Test]
    public function a_student_who_left_says_so_on_their_own_row(): void
    {
        // The panel then shows no link at all, whoever their guardians are.
        GroupMembership::query()
            ->where('contact_id', $this->student->id)
            ->firstOrFail()
            ->markLeftByStaff($this->admin, '2026-09-28')
            ->save();

        $row = $this->rowFor($this->student);

        $this->assertSame('member', $row['role']);
        $this->assertStringStartsWith('2026-09-28', (string) $row['left_on']);
    }

    // ================================================== "Open full record"

    #[Test]
    public function the_full_record_link_opens_a_person_of_this_organisation(): void
    {
        Sanctum::actingAs($this->admin);

        $this->getJson($this->adminUrl("/contacts/{$this->student->id}"))
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.id', $this->student->id);
    }

    #[Test]
    public function the_full_record_link_cannot_open_another_organisations_person(): void
    {
        // The id sits in an address bar. Somebody else's id under this
        // organisation's route is a 404, which the screen answers with "That
        // record could not be opened." and no dialog.
        [$other] = $this->organisation();
        $theirs = $this->person($other, 'Nadia', 'Elsewhere');

        Sanctum::actingAs($this->admin);

        $this->getJson($this->adminUrl("/contacts/{$theirs->id}"))->assertStatus(404);
    }

    #[Test]
    public function the_full_record_link_does_not_open_a_deleted_person(): void
    {
        // A link kept in a tab from before the record was deleted. The same 404,
        // so the same sentence, rather than a dialog over a record that is gone.
        $gone = $this->person($this->masjid, 'Bilal', 'Removed');
        $gone->delete();

        Sanctum::actingAs($this->admin);

        $this->getJson($this->adminUrl("/contacts/{$gone->id}"))->assertStatus(404);
    }

    // ============================================================== helpers

    /** @return array{0: Masjid, 1: User} */
    private function organisation(): array
    {
        $masjid = Masjid::create([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'crm_enabled' => true,
        ]);

        $admin = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
        $masjid->user_id = $admin->id;
        $masjid->save();

        return [$masjid, $admin];
    }

    /** @param array<string,mixed> $more */
    private function person(Masjid $masjid, string $first, string $last, array $more = []): Contact
    {
        return Contact::factory()->create(array_merge([
            'masjid_id' => $masjid->id,
            'first_name' => $first,
            'last_name' => $last,
            'email' => null,
            'phone' => null,
        ], $more));
    }

    private function confirmedRow(Contact $contact, string $role, ?Contact $ward = null): GroupMembership
    {
        $row = new GroupMembership([
            'masjid_id' => $this->masjid->id,
            'group_id' => $this->class->id,
            'contact_id' => $contact->id,
            'role' => $role,
            'guardian_of_contact_id' => $ward?->id,
        ]);
        $row->confirmedByStaff($this->admin)->save();

        return $row;
    }

    /**
     * The office listing's row for this person, as served.
     *
     * @return array<string,mixed>
     */
    private function rowFor(Contact $contact): array
    {
        Sanctum::actingAs($this->admin);

        $rows = $this->getJson($this->adminUrl("/groups/{$this->class->id}/members"))
            ->assertOk()
            ->json('data');

        $row = collect($rows)->firstWhere('contact_id', $contact->id);
        $this->assertIsArray($row, "the listing has no row for contact {$contact->id}");

        return $row;
    }

    /**
     * @param  list<string>  $expected
     * @param  array<string,mixed>|null  $actual
     */
    private function assertSameKeys(array $expected, ?array $actual, string $what): void
    {
        $this->assertIsArray($actual, "{$what} is missing");

        $keys = array_keys($actual);
        sort($keys);
        sort($expected);

        $this->assertSame(
            $expected,
            $keys,
            "The {$what} on the office roster changed shape. The office's Student details panel is drawn "
            . 'from this listing: decide whether the office should see the new key there, then update this list.',
        );
    }

    private function adminUrl(string $path = ''): string
    {
        return "/api/admin/masjids/{$this->masjid->id}" . $path;
    }
}
