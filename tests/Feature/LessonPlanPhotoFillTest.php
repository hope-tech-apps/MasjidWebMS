<?php

namespace Tests\Feature;

use App\Models\{CurriculumWeek, Group, GroupStaff, LessonPlan, Masjid, MasjidUser, User};
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Http, Storage};
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LessonPlanPhotoFillTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $school;
    private Group $group;
    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        Storage::fake('local');
        Http::preventStrayRequests();
        config(['lesson_plan_photo.enabled' => true, 'services.anthropic.key' => 'invented-test-key']);
        $this->school = $this->schoolFixture('Invented School');
        $this->teacher = User::factory()->create(['type' => 'Teacher', 'name' => 'Invented Teacher', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
        MasjidUser::create(['masjid_id' => $this->school->id, 'user_id' => $this->teacher->id, 'role' => 'teacher', 'is_default' => true]);
        app(TenantContext::class)->set($this->school->id);
        $this->group = Group::create(['masjid_id' => $this->school->id, 'name' => 'Invented Class', 'slug' => 'invented', 'kind' => 'class', 'is_active' => true]);
        $this->group->staff()->attach($this->teacher->id, ['masjid_id' => $this->school->id, 'role' => GroupStaff::ROLE_TEACHER, 'assigned_at' => now()]);
        Sanctum::actingAs($this->teacher, ['staff']);
        $this->enable();
    }

    private function schoolFixture(string $name): Masjid
    {
        return Masjid::create(['name' => $name, 'email' => uniqid().'@example.test', 'phone' => '+1'.random_int(1000000000, 9999999999), 'country_id' => '1', 'city_id' => '1', 'address' => 'Invented address', 'latitude' => 0, 'longitude' => 0, 'user_id' => User::factory()->create(['name' => 'Invented Owner', 'phone' => '+1'.random_int(1000000000, 9999999999)])->id, 'crm_enabled' => true]);
    }

    private function enable(bool $on = true, bool $listed = true): void
    {
        $this->school->forceFill(['capability_overrides' => ['lesson_plan_photo_fill' => $on, 'lesson_plan_photo_fill_teacher_ids' => $listed ? [$this->teacher->id] : []]])->save();
    }

    private function url(): string
    {
        return "/api/teacher/masjids/{$this->school->id}/groups/{$this->group->id}/lesson-plans/photo-fill";
    }

    private function read(array $extra = [])
    {
        return $this->post($this->url(), $extra + ['session_date' => '2026-10-06', 'subject' => 'Mathematics', 'grade_label' => '2', 'photos' => [UploadedFile::fake()->image('invented.png', 32, 32)]], ['Accept' => 'application/json']);
    }

    private function provider(array $days): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::preventStrayRequests();
        Http::fake(['api.anthropic.com/*' => Http::response(['stop_reason' => 'tool_use', 'content' => [['type' => 'tool_use', 'name' => 'read_lesson_pages', 'input' => ['days' => $days]]]])]);
    }

    private function day(string $date = '2026-10-06', string $written = 'Tuesday 6 Oct'): array
    {
        return ['date' => $date, 'date_written' => $written, 'fields' => ['body' => 'Count blocks', 'objective' => 'Read [cut off in the photo] [unreadable]', 'standard_code' => 'TEST.2', 'learning_outcomes' => ['Count five'], 'teaching_methods' => ['modeling', 'invented_method'], 'standard_description' => 'Model invention', 'grade_label' => 'Model grade', 'subject' => 'Model subject']];
    }

    public function test_switch_and_pilot_and_kill_switch_refuse_without_provider(): void
    {
        $this->enable(false);
        $this->read()->assertForbidden();
        $this->enable(true, false);
        $this->read()->assertForbidden();
        $this->enable();
        config(['lesson_plan_photo.enabled' => false]);
        $this->read()->assertForbidden();
        Http::assertNothingSent();
        $this->assertArrayNotHasKey('lesson_plan_photo_fill_teacher_ids', $this->school->toArray()['capability_overrides']);
    }

    public function test_written_fields_markers_suggestions_and_privacy(): void
    {
        $this->provider([$this->day()]);
        $this->read()->assertOk()->assertJsonPath('data.fields.body', 'Count blocks')
            ->assertJsonPath('data.fields.objective', 'Read [cut off in the photo] [unreadable]')
            ->assertJsonPath('data.fields.teaching_methods', ['modeling'])
            ->assertJsonPath('data.fields.standard_description', '')
            ->assertJsonPath('data.suggested', ['learning_outcomes', 'teaching_methods']);
        Http::assertSent(function ($request) {
            $body = json_encode($request->data());
            foreach (['Invented School', 'Invented Class', 'Invented Teacher', 'Mathematics', 'Model subject', 'grade_label', 'student_name'] as $private) {
                $this->assertStringNotContainsString($private, $body);
            }
            $this->assertSame(8192, $request['max_tokens']);
            $this->assertSame(\App\Services\LessonPlanPhotoReader::prompt(), $request['system']);
            $this->assertSame(\App\Services\LessonPlanPhotoReader::schema(), $request['tools'][0]['input_schema']);
            $this->assertSame(['type' => 'tool', 'name' => 'read_lesson_pages', 'disable_parallel_tool_use' => true], $request['tool_choice']);
            $this->assertSame('image', $request['messages'][0]['content'][0]['type']);
            $this->assertSame('image/png', $request['messages'][0]['content'][0]['source']['media_type']);
            $this->assertSame('Date being planned: 2026-10-06', $request['messages'][0]['content'][1]['text']);
            return true;
        });
        Http::assertSentCount(1);
        $this->assertSame(0, LessonPlan::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_standard_description_comes_only_from_same_school_guide(): void
    {
        CurriculumWeek::create(['masjid_id' => $this->school->id, 'grade_label' => '2', 'subject' => 'Mathematics', 'week_no' => 1, 'focus' => 'Focus is not description', 'standard_code' => 'TEST.2', 'standard_description' => 'School supplied description']);
        $this->provider([$this->day()]);
        $this->read()->assertOk()->assertJsonPath('data.fields.standard_description', 'School supplied description');
        CurriculumWeek::query()->delete();
        $other = $this->schoolFixture('Other Invented School');
        app(TenantContext::class)->set($other->id);
        CurriculumWeek::create(['masjid_id' => $other->id, 'grade_label' => '2', 'subject' => 'Mathematics', 'week_no' => 1, 'focus' => 'Other', 'standard_code' => 'TEST.2', 'standard_description' => 'Other school description']);
        $this->read()->assertOk()->assertJsonPath('data.fields.standard_description', '');
    }

    public function test_multiple_days_and_no_match_and_override(): void
    {
        $this->provider([$this->day(), $this->day('2026-10-07', 'Wednesday 7 Oct')]);
        $this->read()->assertOk()->assertJsonPath('data.fields.body', 'Count blocks')->assertJsonPath('data.other_dates', ['Wednesday 7 Oct']);
        $this->provider([$this->day('2026-10-07', 'Wednesday 7 Oct')]);
        $this->read()->assertOk()->assertJsonPath('data.fields', [])->assertJsonPath('data.days.0.date_written', 'Wednesday 7 Oct');
        $this->assertSame('Count blocks', $this->read()->assertOk()->json('data.days.0.fields.body'));
        Http::assertSentCount(2);
    }

    public function test_failures_are_clean_and_never_retry(): void
    {
        foreach ([Http::response(['error' => ['message' => 'secret provider words']], 529), Http::response(['content' => [['type' => 'text', 'text' => 'bad reply']]]), Http::response(['stop_reason' => 'refusal', 'content' => []]), Http::response(['stop_reason' => 'tool_use', 'content' => [['type' => 'tool_use', 'name' => 'read_lesson_pages', 'input' => ['days' => []]]]])] as $response) {
            Http::swap(new \Illuminate\Http\Client\Factory());
        Http::preventStrayRequests();
        Http::fake(['api.anthropic.com/*' => $response]);
            $this->read()->assertStatus(503)->assertJsonPath('message', 'The photos could not be read; please try again.');
            Http::assertSentCount(1);
        }
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake();
        config(['services.anthropic.key' => null]);
        $this->read()->assertStatus(503);
        Http::assertNothingSent();
    }

    public function test_type_count_size_dimensions_and_throttle(): void
    {
        $this->read(['photos' => []])->assertUnprocessable();
        $this->read(['photos' => array_map(fn () => UploadedFile::fake()->image('blank.png'), range(1, 5))])->assertUnprocessable();
        $this->read(['photos' => [UploadedFile::fake()->create('fake.jpg', 1, 'text/plain')]])->assertUnprocessable();
        $this->read(['photos' => [UploadedFile::fake()->image('wide.png', 2001, 10)]])->assertUnprocessable();
        $this->read(['photos' => [UploadedFile::fake()->image('big.png')->size(3073)]])->assertUnprocessable();
        Http::assertNothingSent();
        $this->provider([$this->day()]);
        for ($i = 0; $i < 20; $i++) $this->read()->assertOk();
        $this->read()->assertStatus(429);
        Http::assertSentCount(20);
    }

    public function test_another_organisation_teacher_and_plan_are_refused(): void
    {
        $other = $this->schoolFixture('Other Invented School');
        app(TenantContext::class)->set($other->id);
        $plan = LessonPlan::create(['masjid_id' => $other->id, 'group_id' => Group::create(['masjid_id' => $other->id, 'name' => 'Other invented class', 'slug' => 'other', 'kind' => 'class'])->id, 'session_date' => '2026-10-06', 'body' => 'Private']);
        $this->read(['plan_id' => $plan->id])->assertNotFound();
        $stranger = User::factory()->create(['type' => 'Teacher', 'name' => 'Invented Stranger', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
        MasjidUser::create(['masjid_id' => $other->id, 'user_id' => $stranger->id, 'role' => 'teacher', 'is_default' => true]);
        Sanctum::actingAs($stranger, ['staff']);
        $this->read()->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_html_and_oversize_values_are_cleaned_to_save_limits(): void
    {
        $day = $this->day();
        $day['fields']['objective'] = '<b>' . str_repeat('a', 2200) . '</b>';
        $day['fields']['learning_outcomes'] = array_fill(0, 12, str_repeat('b', 600));
        $this->provider([$day]);
        $fields = $this->read()->assertOk()->json('data.fields');
        $this->assertSame(2000, mb_strlen($fields['objective']));
        $this->assertStringEndsWith('[truncated]', $fields['objective']);
        $this->assertCount(10, $fields['learning_outcomes']);
        $this->assertSame(500, mb_strlen($fields['learning_outcomes'][0]));
    }
    public function test_photos_save_with_plan_staff_only_and_attach_what_fits(): void
    {
        $url = str_replace('/photo-fill', '', $this->url());
        $payload = ['session_date' => '2026-10-06', 'subject' => 'Mathematics', 'body' => 'My draft',
            'resource_ids' => '[]', 'photos' => [UploadedFile::fake()->image('invented.png', 32, 32)]];
        $saved = $this->post($url, $payload, ['Accept' => 'application/json'])->assertOk();
        $id = $saved->json('data.id');
        $this->assertCount(1, $saved->json('data.attachments'));
        $resource = \App\Models\GroupResource::firstOrFail();
        $this->assertSame('staff', $resource->visibility);
        $this->assertSame($this->school->id, $resource->masjid_id);
        $this->assertSame($this->group->id, $resource->group_id);
        Storage::disk('local')->assertExists($resource->path);
        $this->assertCount(1, Storage::disk('local')->allFiles());
        config(['groups.lessons.max_attachments' => 2]);
        $payload['_method'] = 'PUT';
        $payload['resource_ids'] = [$resource->id];
        $payload['photos'] = [UploadedFile::fake()->image('one.png'), UploadedFile::fake()->image('two.png')];
        $this->post($url.'/'.$id, $payload, ['Accept' => 'application/json'])->assertOk()
            ->assertJsonCount(2, 'data.attachments')->assertJsonPath('data.photo_message', 'The Files limit was reached; attached 1 of 2 photos.');
        $this->assertCount(2, Storage::disk('local')->allFiles());
        Http::assertNothingSent();
    }

    public function test_refused_save_and_storage_failure_leave_no_orphans(): void
    {
        $url = str_replace('/photo-fill', '', $this->url());
        $payload = ['session_date' => '2026-10-06', 'body' => '', 'photos' => [UploadedFile::fake()->image('blank.png')]];
        $this->post($url, $payload, ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertSame([], Storage::disk('local')->allFiles());
        $payload['body'] = 'Draft';
        $this->enable(false);
        $this->post($url, $payload, ['Accept' => 'application/json'])->assertForbidden();
        $this->assertSame(0, LessonPlan::count());
        $this->enable();
        \App\Models\GroupResource::creating(function ($resource) { if ($resource->title === 'Lesson-plan photo 2') throw new \RuntimeException('invented failure'); });
        $payload['photos'][] = UploadedFile::fake()->image('blank2.png');
        $this->post($url, $payload, ['Accept' => 'application/json'])->assertStatus(500);
        $this->assertSame(0, LessonPlan::count());
        $this->assertSame(0, \App\Models\GroupResource::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_legacy_subject_fence_and_availability_and_class_subject_mode(): void
    {
        $this->getJson(str_replace('/lesson-plans/photo-fill', '', $this->url()))->assertOk()->assertJsonPath('data.lesson_plan_photo_fill', true);
        $this->group->staff()->updateExistingPivot($this->teacher->id, ['subjects' => json_encode(['quran'])]);
        $this->read()->assertForbidden();
        Http::assertNothingSent();
        $this->group->staff()->updateExistingPivot($this->teacher->id, ['subjects' => null]);
        $overrides = $this->school->capability_overrides;
        $overrides['class_subjects'] = true;
        $this->school->forceFill(['capability_overrides' => $overrides])->save();
        $subject = \App\Models\ClassSubject::create(['masjid_id' => $this->school->id, 'group_id' => $this->group->id, 'name' => 'Mathematics', 'name_key' => 'mathematics', 'sort_order' => 0]);
        $staff = \App\Models\GroupStaff::where('group_id', $this->group->id)->where('user_id', $this->teacher->id)->firstOrFail();
        \App\Models\GroupStaff::withOfficeSubjectChoice(fn () => $staff->update(['class_subject_ids' => [$subject->id]]));
        $this->provider([$this->day()]);
        $this->read(['class_subject_id' => $subject->id])->assertOk()->assertJsonPath('data.fields.body', 'Count blocks');
        $this->enable(false);
        $this->getJson(str_replace('/lesson-plans/photo-fill', '', $this->url()))->assertOk()->assertJsonMissingPath('data.lesson_plan_photo_fill');
    }

    public function test_timeouts_unreadable_pages_and_malformed_values_are_clean(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) { $calls++; throw new \Illuminate\Http\Client\ConnectionException('sensitive timeout words'); });
        $this->read()->assertStatus(503)->assertJsonPath('message', 'The photos could not be read; please try again.');
        $this->assertSame(1, $calls);
        foreach ([['date' => null, 'date_written' => 'No date written', 'fields' => ['body' => '[unreadable]']],
            ['date' => 'not a date', 'date_written' => 'Unknown', 'fields' => ['body' => 'Read']],
            ['date' => '2026-10-06', 'date_written' => '6 Oct', 'fields' => ['body' => ['wrong type']]]] as $day) {
            $this->provider([$day]);
            $this->read()->assertStatus(503)->assertJsonPath('message', 'The photos could not be read; please try again.');
            Http::assertSentCount(1);
        }
    }

}
