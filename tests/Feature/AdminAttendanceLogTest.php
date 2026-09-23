<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminDashboard\AttendanceLogController;
use App\Models\AttendanceRecord;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\SchoolClosure;
use App\Models\SchoolYear;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The school-wide attendance log.
 *
 * What these pin, in the order of how badly each would hurt a family if it
 * broke: a blank cell is never reported as an absence; a child's totals cannot
 * lose a mark they really hold; the drill-down cannot disagree with the row it
 * was opened from; a window too wide to draw says so instead of showing a short
 * one; and the whole thing carries a child's name and nothing else about them.
 *
 * The window is stated explicitly in almost every test. The default window is
 * the thirty days ending on the school's today, which moves — a suite that let
 * it move would pass in September and fail in November.
 */
class AdminAttendanceLogTest extends TestCase
{
    use RefreshDatabase;

    private const FROM = '2026-09-14';
    private const TO = '2026-09-18';

    private Masjid $school;
    private User $admin;
    private Group $preK;
    private Group $upper;
    private GroupMembership $amina;
    private GroupMembership $bilal;
    private GroupMembership $zara;
    private GroupMembership $yusuf;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->school = $this->makeSchool('Al-Razi Test');

        $this->admin = User::factory()->create([
            'type' => 'MasjidAdmin', 'phone' => '+1'.random_int(1000000000, 9999999999),
        ]);
        $this->school->user_id = $this->admin->id;
        $this->school->save();

        // Two classes with an order the office chose, so the grid's ordering is
        // pinned against something other than the ids' own sequence.
        $this->preK = $this->makeClass('Pre-K & Kindergarten', 1);
        $this->upper = $this->makeClass('1st & 2nd Grade', 2);

        $this->amina = $this->enrol($this->preK, 'Amina', 'Yusuf');
        $this->bilal = $this->enrol($this->preK, 'Bilal', 'Haddad');
        $this->zara = $this->enrol($this->preK, 'Zara', 'Adams');
        $this->yusuf = $this->enrol($this->upper, 'Yusuf', 'Karim');

        // Monday the 15th: Pre-K's register was taken, three marks.
        $this->mark($this->amina, '2026-09-15', AttendanceRecord::STATUS_PRESENT);
        $this->mark($this->bilal, '2026-09-15', AttendanceRecord::STATUS_ABSENT, 'no call from home');
        $this->mark($this->zara, '2026-09-15', AttendanceRecord::STATUS_PRESENT);

        // Tuesday the 16th: the register was taken and BILAL WAS SKIPPED. This
        // is the case the whole screen exists for.
        $this->mark($this->amina, '2026-09-16', AttendanceRecord::STATUS_PRESENT);

        // Zara left after the 15th. Her mark stays; her enrolment stops.
        $this->zara->forceFill(['left_on' => '2026-09-15'])->save();

        Sanctum::actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        // Always, not only on the happy path: a leaked test clock would make an
        // unrelated test fail somewhere else in the suite and look like flake.
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function makeSchool(string $name): Masjid
    {
        // No `timezone`, on purpose: an unset one reads as America/New_York
        // (FormStaffCodesController::timezoneFor), which is the clock
        // `the_today_band_is_the_schools_today` depends on.
        return Masjid::create([
            'name' => $name.' '.uniqid(),
            'email' => 'school-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'crm_enabled' => true,
            'org_type' => 'school',
        ]);
    }

    private function makeClass(string $name, int $position): Group
    {
        return Group::factory()->create([
            'masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS,
            'name' => $name, 'slug' => Str::slug($name).'-'.uniqid(),
            'position' => $position,
        ]);
    }

    private function enrol(Group $group, string $first, string $last): GroupMembership
    {
        $child = Contact::factory()->create([
            'masjid_id' => $this->school->id,
            'first_name' => $first, 'last_name' => $last,
            'email' => strtolower($first).'-'.uniqid().'@example.test',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'notes' => 'Collected by an aunt on Fridays.',
        ]);

        return GroupMembership::create([
            'masjid_id' => $this->school->id, 'group_id' => $group->id,
            'contact_id' => $child->id, 'role' => GroupMembership::ROLE_MEMBER,
            'grade_label' => 'KG',
        ]);
    }

    private function mark(GroupMembership $student, string $day, string $status, ?string $note = null): AttendanceRecord
    {
        return AttendanceRecord::create([
            'masjid_id' => $this->school->id,
            'group_id' => $student->group_id,
            'group_membership_id' => $student->id,
            'marked_by_user_id' => $this->admin->id,
            'session_date' => $day,
            'status' => $status,
            'note' => $note,
        ]);
    }

    private function url(string $query = ''): string
    {
        return "/api/admin/masjids/{$this->school->id}/attendance".$query;
    }

    private function window(string $extra = ''): string
    {
        return $this->url('?from='.self::FROM.'&to='.self::TO.$extra);
    }

    /** @return array<string,mixed> the grid row for one child */
    private function row(array $data, string $firstName): array
    {
        $row = collect($data['students'])->first(fn (array $s) => $s['contact']['first_name'] === $firstName);

        $this->assertNotNull($row, "{$firstName} is not in the grid");

        return $row;
    }

    #[Test]
    public function an_unmarked_child_on_a_day_the_register_was_taken_is_unmarked_not_absent(): void
    {
        $data = $this->getJson($this->window())->assertOk()->json('data');

        $bilal = $this->row($data, 'Bilal');

        // The 16th holds no cell for him at all. Not an `absent` cell, not a
        // null status inside a cell — no cell.
        $this->assertArrayNotHasKey('2026-09-16', $bilal['cells']);
        $this->assertSame(AttendanceRecord::STATUS_ABSENT, $bilal['cells']['2026-09-15']['status']);
        $this->assertSame('no call from home', $bilal['cells']['2026-09-15']['note']);

        $this->assertSame(1, $bilal['totals']['absent'], 'one real absence, and only one');
        $this->assertSame(1, $bilal['totals']['unmarked'], 'the 16th is a gap in the record');
        $this->assertSame(1, $bilal['totals']['marked']);
        $this->assertSame(2, $bilal['totals']['registers'], 'his class took two registers in the window');

        // And the payload never offers a rate to divide those into.
        $this->assertArrayNotHasKey('rate', $bilal['totals']);
        $this->assertArrayNotHasKey('percentage', $bilal['totals']);
        $this->assertArrayNotHasKey('days_possible', $bilal['totals']);

        // The column says which classes took a register, so the client can draw
        // the 1st & 2nd Grade's untaken register without asking per class.
        $day = collect($data['days'])->firstWhere('date', '2026-09-16');
        $this->assertSame([(int) $this->preK->id], $day['taken_by']);
        $this->assertNotContains((int) $this->upper->id, $day['taken_by']);
    }

    #[Test]
    public function a_day_nobody_marked_is_not_a_column_when_the_school_has_no_calendar(): void
    {
        $data = $this->getJson($this->window())->assertOk()->json('data');

        $this->assertFalse($data['has_calendar'], 'this school has no school_years row');

        $dates = collect($data['days'])->pluck('date')->all();
        $this->assertSame(['2026-09-15', '2026-09-16'], $dates);

        // The 17th is not a column, and that is the honest answer: with no
        // calendar there is nothing that says the school was supposed to meet.
        $this->assertNotContains('2026-09-17', $dates);
        $this->assertSame(2, $data['meta']['columns']);
    }

    #[Test]
    public function a_meeting_day_nobody_marked_is_a_column_and_a_closed_day_is_not(): void
    {
        // 2026-09-13 is a Sunday, so this year meets on Sundays: the 13th, the
        // 20th and the 27th. The register marks above sit on a Tuesday and a
        // Wednesday — make-up days, which the register accepts and which is why
        // the column set is a union rather than either half.
        $year = SchoolYear::create([
            'masjid_id' => $this->school->id, 'label' => '2026-2027',
            'first_day' => '2026-09-13', 'last_day' => '2026-09-27',
        ]);
        SchoolClosure::create([
            'masjid_id' => $this->school->id, 'school_year_id' => $year->id,
            'closed_on' => '2026-09-20', 'reason' => 'Eid holiday',
        ]);

        $data = $this->getJson($this->url('?from=2026-09-13&to=2026-09-27'))->assertOk()->json('data');

        $this->assertTrue($data['has_calendar']);

        $dates = collect($data['days'])->pluck('date')->all();

        // The 13th: school met, nobody took a register. A column with an empty
        // taken_by, which is the "no register was taken" the office is looking for.
        $this->assertContains('2026-09-13', $dates);
        $this->assertSame([], collect($data['days'])->firstWhere('date', '2026-09-13')['taken_by']);

        // The 20th: closed. Not a column, and named with its reason instead.
        $this->assertNotContains('2026-09-20', $dates);
        $this->assertSame(
            [['date' => '2026-09-20', 'reason' => 'Eid holiday']],
            $data['closures'],
        );

        // A day nobody marked is an obligation on NOBODY: its taken_by is empty,
        // so it cannot land in a child's `unmarked`. Bilal's one gap is still the
        // 16th — the day his own class DID take a register and skipped him — and
        // the closed 20th is not counted against him either.
        $bilal = $this->row($data, 'Bilal');
        $this->assertSame(1, $bilal['totals']['unmarked']);
        $this->assertSame(2, $bilal['totals']['registers'], 'the two days Pre-K actually took a register');
    }

    #[Test]
    public function a_withdrawn_child_is_out_of_the_default_read_and_clipped_when_asked_for(): void
    {
        $names = collect($this->getJson($this->window())->assertOk()->json('data.students'))
            ->pluck('contact.first_name')->all();
        $this->assertNotContains('Zara', $names, 'a child who left is not on the default read');

        $data = $this->getJson($this->window('&include_withdrawn=1'))->assertOk()->json('data');
        $zara = $this->row($data, 'Zara');

        $this->assertSame('2026-09-15', $zara['left_on']);
        $this->assertSame(1, $zara['totals']['present']);
        $this->assertSame(1, $zara['totals']['marked']);

        // THE CLIP. Her class took two registers in this window; she was only
        // enrolled for the first. Counting the 16th against her would invent an
        // obligation for a child who had already left.
        $this->assertSame(1, $zara['totals']['registers']);
        $this->assertSame(0, $zara['totals']['unmarked']);
    }

    #[Test]
    public function registers_never_falls_below_the_marks_a_child_holds(): void
    {
        // A joined_at typed a day late — ordinary, and the office fixes it when
        // somebody notices. Until then it says Amina was not enrolled on the
        // 15th, on which she is marked present.
        $this->amina->forceFill(['joined_at' => '2026-09-16'])->save();

        $amina = $this->row($this->getJson($this->window())->assertOk()->json('data'), 'Amina');

        $this->assertSame(2, $amina['totals']['marked'], 'both marks are still hers');

        // Both ends of the clip travel with the row, so the grid draws the same
        // enrolment the totals were counted against.
        $this->assertSame('2026-09-16', $amina['joined_at']);
        $this->assertNull($amina['left_on']);
        $this->assertSame(
            2,
            $amina['totals']['registers'],
            'the union: a bad enrolment date must not drop a real mark out of the denominator',
        );
        $this->assertGreaterThanOrEqual($amina['totals']['marked'], $amina['totals']['registers']);
    }

    /**
     * The compound case a max() got wrong, and the reason `registers` counts a
     * union instead.
     *
     * A bad enrolment date pushes one real mark outside the clip, and the same
     * child is separately skipped on a day their class DID take a register.
     * max(clipped, marked) refills the hole left by the first with the day from
     * the second and prints "2 of 2" — a complete record — directly above a
     * register with no mark for her. The union counts three days, because three
     * days involved her.
     */
    #[Test]
    public function a_mark_outside_the_clip_and_a_skipped_day_are_both_counted(): void
    {
        $this->amina->forceFill(['joined_at' => '2026-09-16'])->save();

        // Wednesday: Pre-K's register is taken and AMINA is the one skipped.
        $this->mark($this->bilal, '2026-09-17', AttendanceRecord::STATUS_PRESENT);

        $amina = $this->row($this->getJson($this->window())->assertOk()->json('data'), 'Amina');

        $this->assertSame(2, $amina['totals']['marked'], 'the 15th and the 16th are still hers');
        $this->assertSame(1, $amina['totals']['unmarked'], 'the 17th');
        $this->assertSame(
            3,
            $amina['totals']['registers'],
            'three days involved her: one outside the clip she is marked on, one inside, one she was skipped on',
        );

        // The invariant, which is the durable half of this test: a max() cannot
        // satisfy it, a union always can.
        $this->assertGreaterThanOrEqual(
            $amina['totals']['marked'] + $amina['totals']['unmarked'],
            $amina['totals']['registers'],
            'marked + unmarked can never exceed the days counted',
        );

        // And the child's own record says the same thing, because both come
        // through tally().
        $record = $this->getJson($this->url("/members/{$this->amina->id}?from=2026-09-14&to=2026-09-18"))
            ->assertOk()->json('data');

        $this->assertSame($amina['totals'], $record['totals']);
    }

    #[Test]
    public function the_today_band_is_the_schools_today_and_names_who_is_away(): void
    {
        // 01:30 UTC on the 17th is half past nine on the EVENING OF THE 16TH in
        // New York. The band must report the 16th: a log that used the server's
        // day would tell the office that tomorrow's registers are all untaken.
        Carbon::setTestNow(Carbon::parse('2026-09-17 01:30:00', 'UTC'));

        $this->mark($this->bilal, '2026-09-16', AttendanceRecord::STATUS_ABSENT);
        $this->mark($this->yusuf, '2026-09-16', AttendanceRecord::STATUS_LATE);

        $data = $this->getJson($this->window())->assertOk()->json('data');
        $today = $data['today'];

        $this->assertSame('America/New_York', $data['timezone']);
        $this->assertSame('2026-09-16', $today['date']);

        $away = collect($today['away']);
        $this->assertSame(['Bilal', 'Yusuf'], $away->pluck('first_name')->all());
        $this->assertSame(AttendanceRecord::STATUS_ABSENT, $away->firstWhere('first_name', 'Bilal')['status']);
        $this->assertSame('1st & 2nd Grade', $away->firstWhere('first_name', 'Yusuf')['group_name']);

        // Amina was marked present on the 16th, so she is not on the call list.
        $this->assertNotContains('Amina', $away->pluck('first_name')->all());

        $preK = collect($today['classes'])->firstWhere('group_id', $this->preK->id);
        $this->assertTrue($preK['taken']);
        $this->assertSame(2, $preK['marked'], 'Amina present and Bilal absent');
        $this->assertSame(2, $preK['roster'], 'Zara has left, so she is off the roster count');
    }

    #[Test]
    public function a_window_past_the_column_cap_omits_the_grid_and_still_serves_the_class_totals(): void
    {
        // One more day than the grid can draw, every one of them a day somebody
        // took a register on.
        $columns = AttendanceLogController::COLUMN_CAP + 1;
        $day = Carbon::parse('2026-06-01');

        for ($i = 0; $i < $columns; $i++) {
            $this->mark($this->amina, $day->copy()->addDays($i)->toDateString(), AttendanceRecord::STATUS_PRESENT);
        }

        $last = $day->copy()->addDays($columns - 1)->toDateString();

        $data = $this->getJson($this->url("?from=2026-06-01&to={$last}"))->assertOk()->json('data');

        $this->assertSame([], $data['days']);
        $this->assertSame([], $data['students']);
        $this->assertTrue($data['meta']['grid_omitted']);
        $this->assertSame($columns, $data['meta']['columns']);
        $this->assertSame(AttendanceLogController::COLUMN_CAP, $data['meta']['column_cap']);
        $this->assertSame(
            'That window covers 41 school days. The grid shows at most 40 — narrow the dates.',
            $data['meta']['grid_omitted_reason'],
            'the screen says what it refused and how to fix it, rather than showing a short register',
        );

        // The honest whole-set count survives the refusal: the office is told
        // how many children the window covers even though none are drawn.
        $this->assertSame(3, $data['meta']['total']);

        // And the numbers that need no columns are still served.
        $preK = collect($data['classes'])->firstWhere('group_id', $this->preK->id);
        $this->assertSame($columns, $preK['registers_taken']);
        $this->assertSame($columns, $preK['present']);
        $this->assertArrayHasKey('away', $data['today']);
    }

    #[Test]
    public function the_drill_down_agrees_with_the_row_it_was_opened_from(): void
    {
        $row = $this->row($this->getJson($this->window())->assertOk()->json('data'), 'Bilal');

        $drill = $this->getJson(
            $this->url("/members/{$this->bilal->id}?from=".self::FROM.'&to='.self::TO)
        )->assertOk()->json('data');

        // The whole totals object, not a key or two. Two numbers for one child
        // is the worst thing this screen can do: the office would have no way to
        // tell which of them is the record.
        $this->assertSame($row['totals'], $drill['totals']);

        $this->assertSame('Bilal', $drill['student']['contact']['first_name']);
        $this->assertSame('Pre-K & Kindergarten', $drill['student']['group_name']);
        $this->assertSame([['date' => '2026-09-15', 'status' => 'absent', 'note' => 'no call from home']], $drill['entries']);
        $this->assertSame(['2026-09-16'], $drill['not_marked']);
    }

    #[Test]
    public function the_payload_carries_no_contact_details(): void
    {
        $raw = $this->getJson($this->window('&include_withdrawn=1'))->assertOk()->content();

        // The KEYS, named one by one. A blunt substring sweep is wrong here:
        // an attendance cell legitimately carries a `note`, and a teacher's
        // note about a child is allowed to contain the word "phone".
        foreach (['"email"', '"phone"', '"notes"', 'login_', 'password'] as $key) {
            $this->assertStringNotContainsString($key, $raw, "the log published {$key}");
        }

        // And the VALUES, which is the assertion that would still fail if the
        // keys were renamed on the way out.
        foreach (Contact::query()->get() as $contact) {
            foreach ([$contact->email, $contact->phone, $contact->notes] as $detail) {
                // Skipped when blank: an empty needle is "contained" in every
                // string, and an assertion that can never fail pins nothing.
                if ((string) $detail !== '') {
                    $this->assertStringNotContainsString((string) $detail, $raw);
                }
            }
        }

        // The names ARE there — this is a register, and the office is reading it.
        $this->assertStringContainsString('Bilal', $raw);
    }

    #[Test]
    public function the_grid_is_ordered_by_class_then_by_name(): void
    {
        $data = $this->getJson($this->window())->assertOk()->json('data');

        // Pre-K first because the office put it first (position 1), not because
        // of its name or its id. Then by last name inside the class: Haddad
        // before Yusuf.
        $this->assertSame(
            ['Bilal', 'Amina', 'Yusuf'],
            collect($data['students'])->pluck('contact.first_name')->all(),
        );

        $this->assertSame(3, $data['meta']['total']);
        $this->assertSame(1, $data['meta']['page']);
        $this->assertSame(50, $data['meta']['per_page']);
    }

    #[Test]
    public function a_user_without_the_contacts_permission_is_refused(): void
    {
        // Through the console's own door, holding the seeded `member` set — no
        // CRM permissions at all, which is what a teacher and the lunch staff
        // carry. A register is a record about children and takes the same gate
        // as the roster and the gradebook.
        $other = User::factory()->create([
            'type' => 'MasjidAdmin', 'phone' => '+1'.random_int(1000000000, 9999999999),
        ]);
        MasjidUser::create([
            'masjid_id' => $this->school->id, 'user_id' => $other->id,
            'role' => 'member', 'is_default' => true,
        ]);
        $other->syncRoles(['member']);

        Sanctum::actingAs($other->fresh());

        $this->getJson($this->window())->assertForbidden();
        $this->getJson($this->url("/members/{$this->bilal->id}"))->assertForbidden();
    }

    #[Test]
    public function another_schools_class_and_child_are_not_readable(): void
    {
        $foreign = $this->makeSchool('Other School');
        $theirClass = Group::factory()->create([
            'masjid_id' => $foreign->id, 'kind' => Group::KIND_CLASS,
            'name' => 'Their class', 'slug' => 'their-class-'.uniqid(),
        ]);
        $theirChild = Contact::factory()->create([
            'masjid_id' => $foreign->id, 'first_name' => 'Hana', 'last_name' => 'Noor',
        ]);
        $theirStudent = GroupMembership::create([
            'masjid_id' => $foreign->id, 'group_id' => $theirClass->id,
            'contact_id' => $theirChild->id, 'role' => GroupMembership::ROLE_MEMBER,
        ]);

        // Our admin, our tenant in the URL, their ids in the query and the path.
        // The masjid global scope answers both, not a check in the controller.
        $this->getJson($this->window('&group_id='.$theirClass->id))->assertNotFound();
        $this->getJson($this->url("/members/{$theirStudent->id}"))->assertNotFound();

        // And nothing of theirs leaks into the unnarrowed read either.
        $raw = $this->getJson($this->window())->assertOk()->content();
        $this->assertStringNotContainsString('Hana', $raw);
        $this->assertStringNotContainsString('Their class', $raw);
    }
}
