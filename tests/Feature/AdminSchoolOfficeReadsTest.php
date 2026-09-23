<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupResource;
use App\Models\LessonPlan;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use App\Support\GroupResourceFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Two more of the teacher realm's reads, mounted for the OFFICE: the week a
 * class is planned to cover, and the files its teacher keeps.
 *
 * Both are the teacher's own controllers unchanged, GETs only, for the reason
 * the gradebook mount states: the write side of each stamps a teacher's identity
 * on what it writes. A lesson plan carries `author_user_id`, and saving one is an
 * upsert of the WHOLE object, so an office save would not just sign a teacher's
 * week with an administrator's name — it would replace the prose with an empty
 * form. Uploading a file stamps `uploaded_by_user_id`, and sharing one mails the
 * class's families.
 *
 * THE VISIBILITY DECISION IS PINNED HERE, not just commented in the routes. The
 * office list carries STAFF-ONLY files as well as the shared ones, because the
 * office IS the school's staff. The family realm applies
 * GroupResource::visibleToFamilies() as a query scope and gets the other answer.
 * A reader meeting those two mounts side by side must not be able to conclude
 * that this one forgot its scope, so `the_office_sees_and_downloads_a_staff_only_file`
 * fails the day somebody adds one.
 */
class AdminSchoolOfficeReadsTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjid;
    private Group $class;
    private User $admin;
    private LessonPlan $monday;
    private GroupResource $staffOnly;
    private GroupResource $shared;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        Storage::fake('local');

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->masjid = $this->makeSchool('Al-Razi Test');

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
        GroupMembership::create([
            'masjid_id' => $this->masjid->id, 'group_id' => $this->class->id,
            'contact_id' => $child->id, 'role' => GroupMembership::ROLE_MEMBER,
        ]);

        $this->monday = $this->plan('2026-09-14', 'Letters: alif to jeem');
        $this->plan('2026-09-16', 'Numbers to twenty');
        // Outside the week the office asks for, so the window is doing work.
        $this->plan('2026-09-28', 'A fortnight later');

        $this->staffOnly = $this->upload('Marking scheme', GroupResource::VISIBILITY_STAFF);
        $this->shared = $this->upload('Homework sheet', GroupResource::VISIBILITY_FAMILIES);

        Sanctum::actingAs($this->admin);
    }

    private function makeSchool(string $name): Masjid
    {
        return Masjid::create([
            'name' => $name.' '.uniqid(),
            'email' => 'school-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'crm_enabled' => true,
            'org_type' => 'school',
        ]);
    }

    private function plan(string $day, string $title): LessonPlan
    {
        return LessonPlan::create([
            'masjid_id' => $this->masjid->id, 'group_id' => $this->class->id,
            'author_user_id' => $this->admin->id,
            'session_date' => $day, 'title' => $title,
            'body' => 'Circle time, then the worksheet.',
        ]);
    }

    private function upload(string $title, string $visibility): GroupResource
    {
        return GroupResourceFiles::store(
            $this->class,
            UploadedFile::fake()->create(strtolower(str_replace(' ', '-', $title)).'.pdf', 12, 'application/pdf'),
            [
                'title' => $title,
                'description' => null,
                'visibility' => $visibility,
                'uploaded_by_user_id' => $this->admin->id,
            ],
        );
    }

    private function url(string $path = ''): string
    {
        return "/api/admin/masjids/{$this->masjid->id}/groups/{$this->class->id}".$path;
    }

    #[Test]
    public function the_office_reads_a_week_of_lesson_plans(): void
    {
        $data = $this->getJson($this->url('/lesson-plans?from=2026-09-14&to=2026-09-20'))
            ->assertOk()->json('data');

        $this->assertSame('2026-09-14', $data['from']);
        $this->assertSame('2026-09-20', $data['to']);

        $titles = collect($data['plans'])->pluck('title')->all();
        $this->assertSame(['Letters: alif to jeem', 'Numbers to twenty'], $titles,
            'the week, in date order, and nothing from outside it');

        // The teacher's own payload comes along whole: every template field is
        // present even when null, which is what lets one screen render the plan
        // without knowing which keys the server happened to omit.
        foreach (LessonPlan::TEMPLATE_FIELDS as $field) {
            $this->assertArrayHasKey($field, $data['plans'][0]);
        }
    }

    #[Test]
    public function the_office_sees_and_downloads_a_staff_only_file(): void
    {
        $data = $this->getJson($this->url('/resources'))->assertOk()->json('data');

        $visibilities = collect($data)->pluck('visibility')->sort()->values()->all();
        $this->assertSame(
            [GroupResource::VISIBILITY_FAMILIES, GroupResource::VISIBILITY_STAFF],
            $visibilities,
            'the office list is not scoped to what families may see',
        );

        // And the bytes, not just the row. This is the assertion that would fail
        // if somebody later added visibleToFamilies() to this mount thinking the
        // family realm's scope had been forgotten here.
        $this->get($this->url("/resources/{$this->staffOnly->id}/download"))->assertOk();
        $this->get($this->url("/resources/{$this->shared->id}/download"))->assertOk();
    }

    #[Test]
    public function the_office_cannot_write_a_plan_or_a_file(): void
    {
        // 405 where the URI is mounted for GET only, 404 where it is not mounted
        // in this realm at all. Either way no admin route reaches a write.
        $writes = [
            ['put', $this->url('/lesson-plans'), [
                'session_date' => '2026-09-14', 'title' => 'Rewritten by the office', 'body' => 'Nothing.',
            ]],
            ['delete', $this->url('/lesson-plans?date=2026-09-14'), []],
            ['post', $this->url('/resources'), ['title' => 'Sneaky']],
            ['put', $this->url("/resources/{$this->staffOnly->id}"), [
                'title' => 'Renamed', 'visibility' => GroupResource::VISIBILITY_FAMILIES,
            ]],
            ['delete', $this->url("/resources/{$this->staffOnly->id}"), []],
        ];

        foreach ($writes as [$verb, $url, $payload]) {
            $status = $this->json(strtoupper($verb), $url, $payload)->getStatusCode();
            $this->assertContains($status, [404, 405], "{$verb} {$url} answered {$status}");
        }

        // The rows those calls aimed at, afterwards.
        $this->assertSame('Letters: alif to jeem', $this->monday->fresh()->title);
        $this->assertSame(3, LessonPlan::query()->where('group_id', $this->class->id)->count());

        $file = $this->staffOnly->fresh();
        $this->assertSame('Marking scheme', $file->title);
        $this->assertSame(GroupResource::VISIBILITY_STAFF, $file->visibility,
            'a staff-only handout must not be publishable to families from here');
        Storage::disk('local')->assertExists($file->path);
    }

    #[Test]
    public function a_user_without_the_contacts_permission_is_refused(): void
    {
        // Through the console's own door (`type` passes the `admin` gate) while
        // holding the seeded `member` set, which is what a teacher and the lunch
        // staff carry: no CRM permissions at all. A class's plans and its files
        // are gated exactly like the roster and the gradebook beside them.
        $other = User::factory()->create([
            'type' => 'MasjidAdmin', 'phone' => '+1'.random_int(1000000000, 9999999999),
        ]);
        MasjidUser::create([
            'masjid_id' => $this->masjid->id, 'user_id' => $other->id,
            'role' => 'member', 'is_default' => true,
        ]);
        $other->syncRoles(['member']);

        Sanctum::actingAs($other->fresh());

        $this->getJson($this->url('/lesson-plans'))->assertForbidden();
        $this->getJson($this->url('/resources'))->assertForbidden();
    }

    #[Test]
    public function another_schools_class_is_not_readable(): void
    {
        $foreign = $this->makeSchool('Other School');
        $theirClass = Group::factory()->create([
            'masjid_id' => $foreign->id, 'kind' => Group::KIND_CLASS,
            'name' => 'Their class', 'slug' => 'their-class-'.uniqid(),
        ]);
        $theirFile = GroupResourceFiles::store(
            $theirClass,
            UploadedFile::fake()->create('theirs.pdf', 12, 'application/pdf'),
            [
                'title' => 'Theirs', 'description' => null,
                'visibility' => GroupResource::VISIBILITY_FAMILIES,
                'uploaded_by_user_id' => $this->admin->id,
            ],
        );

        // Our admin, our tenant in the URL, their ids in the path. The masjid
        // global scope on Group answers, not a check in the controller — which
        // is exactly why the same controller is safe in both realms.
        $base = "/api/admin/masjids/{$this->masjid->id}/groups/{$theirClass->id}";

        $this->getJson($base.'/lesson-plans')->assertNotFound();
        $this->getJson($base.'/resources')->assertNotFound();
        $this->get($base."/resources/{$theirFile->id}/download")->assertNotFound();

        // And their file is not reachable by hanging its id off OUR class either,
        // which is the chain ResourcesController::download re-resolves per request.
        $this->get($this->url("/resources/{$theirFile->id}/download"))->assertNotFound();
    }
}
