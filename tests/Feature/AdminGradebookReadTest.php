<?php

namespace Tests\Feature;

use App\Models\AssignmentScore;
use App\Models\ClassAssignment;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The gradebook from the OFFICE's side: three GETs, and nothing that writes.
 *
 * The school asked to see the gradebook as administration. What they got is the
 * teacher realm's own controller mounted read-only in the admin realm — so the
 * office reads exactly what the teacher marked, through the same code, with no
 * second implementation to drift.
 *
 * The four writes (set work, correct work, withdraw work, enter marks) stay out
 * of this realm on purpose: entering a mark mails the family and stamps the
 * marker's user id, and an office screen that could do it would put a teacher's
 * name on a judgement nobody in the room made. `the_office_cannot_write`
 * below is what stops a later hand from "completing the CRUD".
 */
class AdminGradebookReadTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjid;
    private Group $class;
    private User $admin;
    private GroupMembership $student;
    private ClassAssignment $points;
    private ClassAssignment $levels;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->masjid = Masjid::create([
            'name' => 'Al-Razi Test '.uniqid(),
            'email' => 'school-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'crm_enabled' => true,
            'org_type' => 'school',
        ]);

        $this->admin = User::factory()->create([
            'type' => 'MasjidAdmin', 'phone' => '+1'.random_int(1000000000, 9999999999),
        ]);
        $this->masjid->user_id = $this->admin->id;
        $this->masjid->save();

        $this->class = Group::factory()->create([
            'masjid_id' => $this->masjid->id, 'kind' => Group::KIND_CLASS,
            'name' => 'Pre-K & Kindergarten', 'slug' => 'pre-k-kindergarten-'.uniqid(),
        ]);

        $child = Contact::factory()->create([
            'masjid_id' => $this->masjid->id, 'first_name' => 'Amina', 'last_name' => 'Yusuf',
        ]);
        $this->student = GroupMembership::create([
            'masjid_id' => $this->masjid->id, 'group_id' => $this->class->id,
            'contact_id' => $child->id, 'role' => GroupMembership::ROLE_MEMBER,
        ]);

        $this->points = ClassAssignment::create([
            'masjid_id' => $this->masjid->id, 'group_id' => $this->class->id,
            'created_by_user_id' => $this->admin->id,
            'title' => 'Spelling test', 'points_possible' => 10,
            'scale' => ClassAssignment::SCALE_POINTS, 'assigned_on' => '2026-09-15',
        ]);
        $this->levels = ClassAssignment::create([
            'masjid_id' => $this->masjid->id, 'group_id' => $this->class->id,
            'created_by_user_id' => $this->admin->id,
            'title' => 'Reading rubric', 'points_possible' => 4,
            'scale' => ClassAssignment::SCALE_LEVELS, 'assigned_on' => '2026-09-16',
        ]);

        AssignmentScore::create([
            'masjid_id' => $this->masjid->id, 'group_id' => $this->class->id,
            'class_assignment_id' => $this->points->id,
            'group_membership_id' => $this->student->id,
            'scored_by_user_id' => $this->admin->id,
            'status' => AssignmentScore::STATUS_SCORED, 'points_earned' => 8,
        ]);

        Sanctum::actingAs($this->admin);
    }

    private function url(string $path = ''): string
    {
        return "/api/admin/masjids/{$this->masjid->id}/groups/{$this->class->id}".$path;
    }

    #[Test]
    public function the_office_sees_the_work_the_class_was_set(): void
    {
        $body = $this->getJson($this->url('/assignments'))->assertOk()->json();

        $this->assertCount(2, $body['data']);
        // Newest first, as the teacher's own list orders it.
        $this->assertSame('Reading rubric', $body['data'][0]['title']);

        $spelling = collect($body['data'])->firstWhere('title', 'Spelling test');
        $this->assertSame(1, $spelling['scored'], 'one child marked');
        $this->assertSame(1, $spelling['roster'], 'out of a roster of one');

        // The levels key travels with the list, so the office reads the school's
        // own words for a 3 rather than a bare number.
        $this->assertNotEmpty($body['performance_levels']);
    }

    #[Test]
    public function the_office_sees_one_piece_of_work_marked(): void
    {
        $data = $this->getJson($this->url("/assignments/{$this->points->id}"))
            ->assertOk()->json('data');

        $this->assertCount(1, $data['students']);
        $this->assertSame('Amina', $data['students'][0]['contact']['first_name']);
        $this->assertSame('scored', $data['students'][0]['status']);
        // Cast: JSON has no float/int distinction, so 8.0 arrives as 8.
        $this->assertSame(8.0, (float) $data['students'][0]['points_earned']);
    }

    #[Test]
    public function an_unmarked_child_reads_as_unmarked_and_never_as_a_zero(): void
    {
        $data = $this->getJson($this->url("/assignments/{$this->levels->id}"))
            ->assertOk()->json('data');

        // Nobody has been marked on the rubric. NULL, not 0 — a blank is not a
        // judgement, and the office screen prints the difference.
        $this->assertNull($data['students'][0]['status']);
        $this->assertNull($data['students'][0]['points_earned']);
    }

    #[Test]
    public function the_office_sees_one_childs_whole_record(): void
    {
        $data = $this->getJson($this->url("/members/{$this->student->id}/grades"))
            ->assertOk()->json('data');

        $this->assertSame('Amina', $data['student']['contact']['first_name']);
        $this->assertSame(8.0, (float) $data['summary']['points_earned']);
        $this->assertSame(10.0, (float) $data['summary']['points_possible']);
        $this->assertCount(1, $data['scores']);
    }

    #[Test]
    public function the_payload_carries_no_contact_details(): void
    {
        // The teacher realm's names-only boundary comes along with the
        // controller. The office has other screens for a family's phone number;
        // a gradebook is not one of them, and widening this payload later would
        // widen it for the teacher realm too.
        $raw = $this->getJson($this->url("/assignments/{$this->points->id}"))->assertOk()->content();

        foreach (['email', 'phone', 'login_', 'notes'] as $leak) {
            $this->assertStringNotContainsString($leak, $raw);
        }
    }

    #[Test]
    public function the_office_cannot_write(): void
    {
        // 405 where the URI exists for GET only, 404 where the URI is not
        // mounted in this realm at all. Either way: no admin route reaches a
        // write on a child's marks.
        $writes = [
            ['post', $this->url('/assignments'), ['title' => 'Sneaky', 'points_possible' => 10, 'assigned_on' => '2026-09-20']],
            ['put', $this->url("/assignments/{$this->points->id}"), ['title' => 'Renamed', 'points_possible' => 10, 'assigned_on' => '2026-09-20']],
            ['delete', $this->url("/assignments/{$this->points->id}"), []],
            ['put', $this->url("/assignments/{$this->points->id}/scores"), ['scores' => [['membership_id' => $this->student->id, 'status' => 'scored', 'points_earned' => 10]]]],
        ];

        foreach ($writes as [$verb, $url, $payload]) {
            $status = $this->json(strtoupper($verb), $url, $payload)->getStatusCode();
            $this->assertContains($status, [404, 405], "{$verb} {$url} answered {$status}");
        }

        // And the mark it tried to change is untouched.
        $this->assertSame(8.0, (float) AssignmentScore::query()
            ->where('group_membership_id', $this->student->id)->value('points_earned'));
        $this->assertSame('Spelling test', $this->points->fresh()->title);
    }

    #[Test]
    public function a_user_without_the_contacts_permission_is_refused(): void
    {
        // Through the console's own door (`type` passes the `admin` gate) but
        // holding a role with no CRM permissions — the seeded `member` set,
        // which is what a teacher and the lunch staff also carry. The gradebook
        // is a disclosure about children and is gated exactly like the roster
        // and the letter tracker beside it.
        $other = User::factory()->create([
            'type' => 'MasjidAdmin', 'phone' => '+1'.random_int(1000000000, 9999999999),
        ]);
        MasjidUser::create([
            'masjid_id' => $this->masjid->id, 'user_id' => $other->id,
            'role' => 'member', 'is_default' => true,
        ]);
        $other->syncRoles(['member']);

        Sanctum::actingAs($other->fresh());

        $this->getJson($this->url('/assignments'))->assertForbidden();
    }

    #[Test]
    public function another_schools_class_is_not_readable(): void
    {
        $foreign = Masjid::create([
            'name' => 'Other School '.uniqid(),
            'email' => 'other-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '2 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'crm_enabled' => true,
            'org_type' => 'school',
        ]);
        $theirClass = Group::factory()->create([
            'masjid_id' => $foreign->id, 'kind' => Group::KIND_CLASS,
            'name' => 'Their class', 'slug' => 'their-class-'.uniqid(),
        ]);

        // Our admin, our tenant in the URL, their group id in the path. The
        // `masjid_id` global scope on Group is what answers, not a check in the
        // controller — which is why the SAME controller is safe in both realms.
        $this->getJson("/api/admin/masjids/{$this->masjid->id}/groups/{$theirClass->id}/assignments")
            ->assertNotFound();
    }
}
