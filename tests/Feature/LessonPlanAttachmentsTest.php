<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupResource;
use App\Models\GroupStaff;
use App\Models\LessonPlan;
use App\Models\LessonPlanResource;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * T-004.1 — files under a lesson plan's Activities.
 *
 * WHAT THIS FILE PINS, most damaging first:
 *
 *  1. EVERY attached id belongs to the SAME CLASS and the SAME SCHOOL as the plan.
 *     Another class's file and another school's file each get a 422, on create
 *     and on update, and the WHOLE request is refused (a plan is never saved with
 *     some of its files): a plan is a place a teacher's colleague reads, and a
 *     file from a room she does not lead must not become readable through it.
 *  2. Attachments are STAFF information: in the teacher's and the office's plan
 *     payloads, in NO family payload (and no family route mentions a lesson plan).
 *  3. A plan owns no bytes: removing a link leaves the file in Files; removing the
 *     file from Files removes it from every plan; removing the plan keeps the file.
 *  4. Absent `resource_ids` keeps the plan's files (an older screen), `[]` clears
 *     them, a list is exact and ordered.
 *  5. It adds NO teacher write verb: the upload is the existing `POST /resources`
 *     and the attach rides the plan's own save (TeacherRealmTest's list is left
 *     unedited and this file names the plan routes it must equal).
 *  6. The new table's index names fit MySQL's 64 characters (its tenant isolation is
 *     `LessonPlanResourceTenantIsolationTest`).
 */
class LessonPlanAttachmentsTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $school;
    private Masjid $otherSchool;
    private User $teacher;
    private Group $mine;
    private Group $notMine;
    private Group $otherSchoolGroup;
    private Contact $parent;

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

        $this->school = $this->newSchool('Al-Razi Test');
        $this->otherSchool = $this->newSchool('Other School');

        $this->teacher = User::factory()->create([
            'type' => 'Teacher', 'phone' => '+1'.random_int(1000000000, 9999999999),
        ]);
        MasjidUser::create([
            'masjid_id' => $this->school->id, 'user_id' => $this->teacher->id,
            'role' => 'teacher', 'is_default' => true,
        ]);

        $this->mine = $this->newClass($this->school, 'Pre-K & Kindergarten');
        $this->notMine = $this->newClass($this->school, '1st & 2nd Grade');
        $this->otherSchoolGroup = $this->newClass($this->otherSchool, 'Pre-K & Kindergarten');

        $this->mine->staff()->attach($this->teacher->id, [
            'masjid_id' => $this->mine->masjid_id,
            'role' => GroupStaff::ROLE_TEACHER, 'assigned_at' => now(),
        ]);

        $child = Contact::factory()->create(['masjid_id' => $this->school->id, 'first_name' => 'Kareem']);
        GroupMembership::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->mine->id,
            'contact_id' => $child->id, 'role' => GroupMembership::ROLE_MEMBER,
        ]);
        $this->parent = Contact::factory()->create([
            'masjid_id' => $this->school->id, 'first_name' => 'Huda',
            'login_email' => 'parent-'.uniqid().'@example.test', 'login_enabled_at' => now(),
        ]);
        GroupMembership::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->mine->id,
            'contact_id' => $this->parent->id, 'role' => GroupMembership::ROLE_GUARDIAN,
            'guardian_of_contact_id' => $child->id, 'confirmed_at' => now(),
            'consent_granted_at' => now(), 'consent_scope' => GroupMembership::CONSENT_MEDIA,
        ]);

        Sanctum::actingAs($this->teacher, ['staff']);
    }

    // ---------------------------------------------------------------- fixtures

    private function newSchool(string $name): Masjid
    {
        return Masjid::create([
            'name' => $name.' '.uniqid(),
            'email' => 'school-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0,
            'crm_enabled' => true, 'org_type' => 'school',
        ]);
    }

    private function newClass(Masjid $school, string $name): Group
    {
        return Group::factory()->create([
            'masjid_id' => $school->id, 'kind' => Group::KIND_CLASS,
            'name' => $name, 'slug' => \Illuminate\Support\Str::slug($name).'-'.uniqid(),
        ]);
    }

    /** A file row written straight to the DB for ANY class or school, as the upload would create it. */
    private function fileIn(Group $group, string $title = 'Worksheet', string $visibility = GroupResource::VISIBILITY_STAFF): int
    {
        return app(TenantContext::class)->runWithout(fn () => GroupResource::create([
            'masjid_id' => $group->masjid_id, 'group_id' => $group->id,
            'title' => $title, 'visibility' => $visibility,
            'original_name' => $title.'.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 2048,
            'disk' => 'local', 'path' => "group-resources/{$group->id}/".uniqid().'.pdf',
        ])->id);
    }

    /** The real upload endpoint, staff-only by default: how a teacher's file actually arrives. */
    private function upload(string $title = 'Worksheet'): int
    {
        return (int) $this->post($this->url().'/resources', [
            'file' => UploadedFile::fake()->create('worksheet.pdf', 12, 'application/pdf'),
            'title' => $title,
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
    }

    private function day(int $offset = 3): string
    {
        return now()->addDays($offset)->toDateString();
    }

    private function savePlan(array $extra = [], string $subject = 'Math', ?string $day = null): \Illuminate\Testing\TestResponse
    {
        return $this->postJson($this->url().'/lesson-plans', $extra + [
            'session_date' => $day ?? $this->day(), 'subject' => $subject, 'body' => 'Count to ten.',
        ]);
    }

    private function url(): string
    {
        return "/api/teacher/masjids/{$this->school->id}/groups/{$this->mine->id}";
    }

    private function asTeacher(): void
    {
        Auth::forgetGuards();
        app(TenantContext::class)->forgetTenant();
        Sanctum::actingAs($this->teacher, ['staff']);
    }

    private function asParent(): self
    {
        Auth::forgetGuards();
        app(TenantContext::class)->forgetTenant();

        return $this->withHeader('Authorization', 'Bearer '.$this->parent->createFamilyToken()->plainTextToken);
    }

    private function week(): array
    {
        $from = now()->startOfWeek()->toDateString();
        $to = now()->addDays(20)->toDateString();

        return $this->getJson($this->url()."/lesson-plans?from={$from}&to={$to}")->assertOk()->json('data.plans');
    }

    // ------------------------------------------------------ 1. same class, same school

    #[Test]
    public function a_teacher_uploads_a_staff_only_file_and_lists_it_under_the_plans_activities(): void
    {
        $first = $this->upload('Counting mat');
        $second = $this->upload('Answer key');

        $this->assertSame(GroupResource::VISIBILITY_STAFF, GroupResource::find($first)->visibility,
            'a lesson file must default to PRIVATE, not be published by being attached');

        $response = $this->savePlan(['resource_ids' => [$second, $first]])->assertOk();

        // The order sent is the order kept.
        $this->assertSame([$second, $first], array_column($response->json('data.attachments'), 'id'));
        $this->assertSame(['Answer key', 'Counting mat'], array_column($response->json('data.attachments'), 'title'));

        $read = $this->week();
        $this->assertSame([$second, $first], array_column($read[0]['attachments'], 'id'));

        // The file shape carries no way to reach the bytes but the download route.
        foreach (['url', 'path', 'disk'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $read[0]['attachments'][0]);
        }
        $this->get($this->url()."/resources/{$first}/download")->assertOk();
    }

    #[Test]
    public function another_classs_file_is_refused_on_create_and_on_update_and_nothing_is_written(): void
    {
        $theirs = $this->fileIn($this->notMine, 'Other class worksheet');

        $this->savePlan(['resource_ids' => [$theirs]])
            ->assertStatus(422)
            ->assertJsonPath('status', 'failed')
            ->assertJsonStructure(['data' => ['resource_ids']]);

        // The plan itself was NOT saved: a 422 after a write is a lie.
        $this->assertSame(0, LessonPlan::withoutMasjidScope()->count());
        $this->assertSame(0, LessonPlanResource::withoutMasjidScope()->count());

        // ...and the same on UPDATE of a plan that exists.
        $mine = $this->fileIn($this->mine, 'My worksheet');
        $planId = $this->savePlan(['resource_ids' => [$mine]])->assertOk()->json('data.id');

        $this->putJson($this->url()."/lesson-plans/{$planId}", [
            'session_date' => $this->day(), 'subject' => 'Math', 'body' => 'Rewritten.',
            'resource_ids' => [$mine, $theirs],
        ])->assertStatus(422);

        $plan = LessonPlan::withoutMasjidScope()->find($planId);
        $this->assertSame('Count to ten.', $plan->body, 'the refused update must not have saved its prose');
        $this->assertSame(
            [$mine],
            LessonPlanResource::withoutMasjidScope()->where('lesson_plan_id', $planId)->pluck('group_resource_id')->all()
        );
    }

    #[Test]
    public function another_schools_file_is_refused_too(): void
    {
        $foreign = $this->fileIn($this->otherSchoolGroup, 'Other school worksheet');

        $this->savePlan(['resource_ids' => [$foreign]])->assertStatus(422)
            ->assertJsonStructure(['data' => ['resource_ids']]);

        $this->assertSame(0, LessonPlan::withoutMasjidScope()->count());
        $this->assertSame(0, LessonPlanResource::withoutMasjidScope()->count());

        // Even where the file sits in a class whose ID is this class's: a file
        // is judged by its own school, not by a class number.
        $sameGroupIdOtherSchool = app(TenantContext::class)->runWithout(fn () => GroupResource::create([
            'masjid_id' => $this->otherSchool->id, 'group_id' => $this->mine->id,
            'title' => 'Forged', 'visibility' => 'staff', 'original_name' => 'f.pdf',
            'mime_type' => 'application/pdf', 'size_bytes' => 1, 'disk' => 'local', 'path' => 'group-resources/forged.pdf',
        ])->id);
        $this->asTeacher();

        $this->savePlan(['resource_ids' => [$sameGroupIdOtherSchool]], 'Science')->assertStatus(422);
        $this->assertSame(0, LessonPlan::withoutMasjidScope()->count());
    }

    #[Test]
    public function one_bad_id_refuses_the_whole_list_and_a_missing_id_is_no_different(): void
    {
        $mine = $this->fileIn($this->mine);

        $this->savePlan(['resource_ids' => [$mine, 999999]])->assertStatus(422);
        $this->assertSame(0, LessonPlan::withoutMasjidScope()->count(), 'no plan saved with some of its files');

        // The refusal is not an existence oracle: another class's id and an id
        // that exists nowhere get the SAME answer.
        $theirs = $this->fileIn($this->notMine);
        $a = $this->savePlan(['resource_ids' => [$theirs]])->assertStatus(422)->json('data.resource_ids');
        $b = $this->savePlan(['resource_ids' => [888888]])->assertStatus(422)->json('data.resource_ids');
        $this->assertSame($a, $b);
    }

    #[Test]
    public function the_list_is_capped_distinct_and_made_of_whole_numbers(): void
    {
        $cap = (int) config('groups.lessons.max_attachments');
        $this->assertSame(10, $cap);

        $ids = array_map(fn ($i) => $this->fileIn($this->mine, "File {$i}"), range(1, $cap + 1));

        $this->savePlan(['resource_ids' => $ids])->assertStatus(422)->assertJsonStructure(['data' => ['resource_ids']]);
        $this->savePlan(['resource_ids' => array_slice($ids, 0, $cap)])->assertOk();

        $this->savePlan(['resource_ids' => [$ids[0], $ids[0]]], 'Science')->assertStatus(422);
        $this->savePlan(['resource_ids' => ['abc']], 'Art')->assertStatus(422);
        $this->savePlan(['resource_ids' => 'nope'], 'Islamic Studies')->assertStatus(422);
    }

    // ------------------------------------------------------------ 4. absent / [] / list

    #[Test]
    public function an_absent_resource_ids_keeps_the_files_and_an_empty_list_clears_them(): void
    {
        $file = $this->fileIn($this->mine);
        $planId = $this->savePlan(['resource_ids' => [$file]])->assertOk()->json('data.id');

        // An older screen (or the by-day PUT) sends no resource_ids: it must not detach.
        $this->putJson($this->url()."/lesson-plans/{$planId}", [
            'session_date' => $this->day(), 'subject' => 'Math', 'body' => 'Edited by an old screen.',
        ])->assertOk()->assertJsonCount(1, 'data.attachments');

        $this->putJson($this->url().'/lesson-plans', [
            'session_date' => $this->day(), 'subject' => 'Math', 'body' => 'By-day upsert.',
        ])->assertOk()->assertJsonCount(1, 'data.attachments');

        // A present, empty list is the exact list: none.
        $this->putJson($this->url()."/lesson-plans/{$planId}", [
            'session_date' => $this->day(), 'subject' => 'Math', 'body' => 'Cleared.', 'resource_ids' => [],
        ])->assertOk()->assertJsonCount(0, 'data.attachments');

        $this->assertNotNull(GroupResource::find($file), 'detaching a file never deletes it from Files');
    }

    #[Test]
    public function an_update_replaces_and_reorders_the_list_exactly(): void
    {
        [$a, $b, $c] = [$this->fileIn($this->mine, 'A'), $this->fileIn($this->mine, 'B'), $this->fileIn($this->mine, 'C')];
        $planId = $this->savePlan(['resource_ids' => [$a, $b]])->assertOk()->json('data.id');

        $response = $this->putJson($this->url()."/lesson-plans/{$planId}", [
            'session_date' => $this->day(), 'subject' => 'Math', 'body' => 'Again.',
            'resource_ids' => [$c, $b],
        ])->assertOk();

        $this->assertSame([$c, $b], array_column($response->json('data.attachments'), 'id'));
        $this->assertSame(2, LessonPlanResource::withoutMasjidScope()->where('lesson_plan_id', $planId)->count());
    }

    // ------------------------------------------------- 3. a plan owns no bytes

    #[Test]
    public function removing_a_file_takes_it_off_every_plan_and_removing_a_plan_keeps_the_file(): void
    {
        $file = $this->upload('Shared worksheet');
        $monday = $this->savePlan(['resource_ids' => [$file]], 'Math', $this->day(3))->assertOk()->json('data.id');
        $tuesday = $this->savePlan(['resource_ids' => [$file]], 'Math', $this->day(4))->assertOk()->json('data.id');

        // The Files tab says how many plans use it.
        $row = collect($this->getJson($this->url().'/resources')->assertOk()->json('data'))->firstWhere('id', $file);
        $this->assertSame(2, $row['lesson_plan_count']);

        // Removing a plan keeps the file and the other plan's link.
        $this->deleteJson($this->url()."/lesson-plans/{$monday}")->assertOk();
        $this->assertNotNull(GroupResource::find($file));
        $this->assertSame(1, GroupResource::find($file)->toStaffArray()['lesson_plan_count']);

        // Removing the file removes it from the plan that still listed it.
        $path = GroupResource::find($file)->path;
        $this->deleteJson($this->url()."/resources/{$file}")->assertOk();
        Storage::disk('local')->assertMissing($path);
        $plan = collect($this->week())->firstWhere('id', $tuesday);
        $this->assertSame([], $plan['attachments']);
        $this->assertSame(0, LessonPlanResource::withoutMasjidScope()->count());
    }

    // ------------------------------------------------------------ 2. staff information only

    #[Test]
    public function the_office_reads_the_attachments_in_the_same_payload(): void
    {
        $file = $this->fileIn($this->mine, 'Office can see this');
        $this->savePlan(['resource_ids' => [$file]])->assertOk();

        $admin = User::factory()->create(['type' => 'MasjidAdmin', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
        $this->school->user_id = $admin->id;
        $this->school->save();
        MasjidUser::create([
            'masjid_id' => $this->school->id, 'user_id' => $admin->id,
            'role' => 'masjid-admin', 'is_default' => true,
        ]);

        Auth::forgetGuards();
        app(TenantContext::class)->forgetTenant();
        Sanctum::actingAs($admin, ['*']);

        $from = now()->startOfWeek()->toDateString();
        $to = now()->addDays(20)->toDateString();
        $plans = $this->getJson("/api/admin/masjids/{$this->school->id}/groups/{$this->mine->id}/lesson-plans?from={$from}&to={$to}")
            ->assertOk()->json('data.plans');

        $this->assertSame([$file], array_column($plans[0]['attachments'], 'id'));
        $this->assertSame('Office can see this', $plans[0]['attachments'][0]['title']);
    }

    #[Test]
    public function no_family_payload_carries_a_plans_attachments_or_the_plan_count(): void
    {
        // A file the teacher ALSO shared with families, and attached to a plan.
        $shared = $this->fileIn($this->mine, 'Shared handout', GroupResource::VISIBILITY_FAMILIES);
        $staffOnly = $this->fileIn($this->mine, 'Staff worksheet');
        $this->savePlan(['resource_ids' => [$shared, $staffOnly]])->assertOk();

        $family = "/api/family/masjids/{$this->school->id}/groups/{$this->mine->id}";
        $listed = $this->asParent()->getJson($family.'/resources')->assertOk()->json('data');

        // Only what the class shared, and the STAFF-only keys are absent.
        $this->assertSame([$shared], array_column($listed, 'id'));
        $this->assertSame(array_keys(GroupResource::find($shared)->toAudienceArray()), array_keys($listed[0]));
        foreach (['lesson_plan_count', 'attachments', 'lesson_plans', 'recipient_count'] as $key) {
            $this->assertArrayNotHasKey($key, $listed[0]);
        }
        $this->assertArrayHasKey('lesson_plan_count', GroupResource::find($shared)->toStaffArray());

        // The staff-only file stays a 404 to the family however many plans list it.
        $this->asParent()->get($family."/resources/{$staffOnly}/download")->assertNotFound();

        // And the family API has no lesson-plan surface for an attachment to ride on.
        $familyPlanRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'api/family') && str_contains($r->uri(), 'lesson'))
            ->map(fn ($r) => $r->uri())->values()->all();
        $this->assertSame([], $familyPlanRoutes);

        // The body text of the plan is not in any family response either.
        $this->assertStringNotContainsString('Count to ten.', $this->asParent()->getJson($family.'/resources')->getContent());
    }

    // ------------------------------------------------------------- 5. no new verb

    #[Test]
    public function attaching_files_adds_no_teacher_write_verb(): void
    {
        // TeacherRealmTest::the_teacher_realm_exposes_exactly_these_writes is
        // left UNEDITED by T-004.1 and must stay green. This names the lesson
        // plan routes it relies on, so a future "attach" endpoint has to be a
        // deliberate edit in BOTH places rather than a quiet extra route.
        $lessonWrites = [];
        $attachRoutes = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/teacher')) {
                continue;
            }
            // Only routes that could attach a file to a PLAN: the class story and
            // the conversations have attachment routes of their own, unrelated.
            if (preg_match('/attach/i', $route->uri())
                && (str_contains($route->uri(), 'lesson-plans') || str_contains($route->uri(), '/resources'))) {
                $attachRoutes[] = $route->uri();
            }
            if (! str_contains($route->uri(), 'lesson-plans')) {
                continue;
            }
            foreach ($route->methods() as $method) {
                if (! in_array($method, ['GET', 'HEAD'], true)) {
                    $lessonWrites[] = $method.' /'.$route->uri();
                }
            }
        }
        sort($lessonWrites);

        $base = 'api/teacher/masjids/{masjid_id}/groups/{group_id}/lesson-plans';
        $expected = [
            "DELETE /{$base}", "DELETE /{$base}/{plan_id}", "POST /{$base}",
            "PUT /{$base}", "PUT /{$base}/{plan_id}",
        ];
        sort($expected);

        $this->assertSame($expected, $lessonWrites);
        $this->assertSame([], $attachRoutes);
    }

    // ------------------------------------------------------ 6. the table itself

    #[Test]
    public function the_table_has_the_shape_the_migration_documents_and_short_index_names(): void
    {
        $this->assertTrue(Schema::hasTable('lesson_plan_resources'));
        $this->assertSame(
            ['id', 'masjid_id', 'lesson_plan_id', 'group_resource_id', 'position', 'created_at', 'updated_at'],
            Schema::getColumnListing('lesson_plan_resources')
        );

        $names = array_column(Schema::getIndexes('lesson_plan_resources'), 'name');
        $this->assertContains('lesson_plan_resource_unique', $names);
        $this->assertContains('lesson_plan_res_masjid_res_idx', $names);

        // MySQL refuses an identifier over 64 characters; SQLite does not.
        foreach ($names as $name) {
            $this->assertLessThanOrEqual(64, strlen($name), "{$name} would abort on MySQL");
        }

        // Same file twice on one plan is refused by the database itself.
        $file = $this->fileIn($this->mine);
        $planId = $this->savePlan(['resource_ids' => [$file]])->assertOk()->json('data.id');
        $this->expectException(\Illuminate\Database\QueryException::class);
        app(TenantContext::class)->runWithout(fn () => LessonPlanResource::create([
            'masjid_id' => $this->school->id, 'lesson_plan_id' => $planId,
            'group_resource_id' => $file, 'position' => 1,
        ]));
    }
}
