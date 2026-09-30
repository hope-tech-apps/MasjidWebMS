<?php

namespace Tests\Feature;

use App\Enums\GroupNotificationEvent;
use App\Jobs\SendGroupNotificationJob;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupMessage;
use App\Models\GroupMessageSchedule;
use App\Models\GroupPost;
use App\Models\GroupStaff;
use App\Models\GroupThread;
use App\Models\GroupThreadRead;
use App\Models\User;
use App\Console\Commands\PublishDueGroupItems;
use App\Services\Groups\GroupThreadWriter;
use App\Services\Groups\ScheduledSendGate;
use App\Support\TenantContext;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsClassStoryFixture;
use Tests\TestCase;

/**
 * `groups:publish-due` (T-002.4): what releases a scheduled class story and opens a
 * scheduled conversation.
 *
 * STORIES. A story is visible by the clock alone (GroupPost::scopePublished), so the
 * sweep does the two things the clock cannot: it refuses, BEFORE the time arrives, a
 * story whose author left the class (S15), and it announces a story once it is out, at
 * most once. It never publishes anything itself.
 *
 * CONVERSATIONS. A scheduled conversation exists only as a schedule row, so the sweep is
 * what writes it: claim it, ask the gates again with the tenant bound to its own school
 * (the author still teaches the class; the child is still a current participant), write
 * it through the same GroupThreadWriter a live conversation uses with the `sent` stamp
 * inside that transaction, and email the families. A refusal or an error is `failed`
 * with a reason and nothing written.
 *
 * And the claims that make a per-minute sweep safe: a second sweep, a fresh claim held
 * by another run, a stale one handed back, the schedule registration, the one warning
 * line per run, and the tenant left as it was found.
 *
 * Mutation-proved (see DECISIONS.md 2026-09-29, school side quest W5).
 */
class ScheduledSweepTest extends TestCase
{
    use RefreshDatabase;
    use BuildsClassStoryFixture;

    private const NOW = '2026-10-01 12:00:00';

    /** 10:00 on 5 October in New York, in UTC. */
    private const DUE = '2026-10-05 14:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->useSqliteInMemory();
        Carbon::setTestNow(self::NOW);
        Storage::fake((string) config('groups.media.disk'));
        Bus::fake([SendGroupNotificationJob::class]);
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->buildStoryWorld();
        $this->school->forceFill(['timezone' => 'America/New_York'])->save();
        $this->consent($this->parentA);
        $this->consent($this->parentB);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ================================================================ fixtures

    private function scheduledPost(string $when = '+2 days', ?User $author = null, string $body = 'Tomorrow we visit the garden.'): GroupPost
    {
        return GroupPost::create([
            'masjid_id' => $this->class->masjid_id,
            'group_id' => $this->class->id,
            'author_user_id' => ($author ?? $this->teacher)->id,
            'title' => 'Coming up',
            'body' => $body,
            'published_at' => now()->modify($when),
        ]);
    }

    /** @return array<string,mixed> */
    private function payload(array $over = []): array
    {
        return $over + [
            'subject' => 'Term dates',
            'scope' => GroupThread::SCOPE_GROUP,
            'body' => 'School resumes on Sunday.',
            'send_at' => '2026-10-05T10:00',
        ];
    }

    private function schedule(?User $as = null, array $over = []): GroupMessageSchedule
    {
        $this->asTeacher($as)
            ->postJson($this->teacherUrl('/scheduled-messages'), $this->payload($over))
            ->assertCreated();

        return GroupMessageSchedule::withoutMasjidScope()->latest('id')->firstOrFail();
    }

    private function aboutChild(array $over = []): array
    {
        return $over + ['scope' => GroupThread::SCOPE_PARTICIPANT, 'about_membership_id' => $this->childA->id, 'subject' => 'About Amina'];
    }

    private function coTeacher(): User
    {
        return $this->makeTeacher($this->school, $this->class, 'Ustadha Sara');
    }

    private function office(): User
    {
        $admin = $this->makeAdmin();
        $admin->syncRoles([]);
        $admin->givePermissionTo(['view contacts', 'manage contacts']);

        return $admin;
    }

    private function sweep(array $args = []): string
    {
        Artisan::call('groups:publish-due', $args);

        return Artisan::output();
    }

    private function goTo(string $utc): void
    {
        Carbon::setTestNow($utc);
    }

    private function familyIds(): array
    {
        return array_map('intval', $this->asParent($this->parentA)
            ->getJson($this->familyUrl('/posts'))->assertOk()->json('data.data.*.id'));
    }

    private function classStoryJobs(): int
    {
        return Bus::dispatched(SendGroupNotificationJob::class, fn ($job) => $job->event === GroupNotificationEvent::CLASS_STORY)->count();
    }

    private function nothingWritten(): void
    {
        $this->assertSame(0, GroupThread::withoutMasjidScope()->withTrashed()->count(), 'a thread was written');
        $this->assertSame(0, GroupMessage::withoutMasjidScope()->count(), 'a message was written');
        Bus::assertNotDispatched(SendGroupNotificationJob::class);
    }

    // ==================================================================== tests

    #[Test]
    public function an_ordinary_story_is_out_now_and_announced_now_and_stamped_so_the_sweep_leaves_it_alone(): void
    {
        $response = $this->asTeacher()->postJson($this->teacherUrl('/posts'), ['body' => 'Hello'])->assertCreated();

        $post = GroupPost::withoutMasjidScope()->sole();

        $this->assertSame('published', $response->json('data.status'));
        $this->assertSame(self::NOW, $post->published_at->toDateTimeString());
        $this->assertSame(self::NOW, $post->announced_at->toDateTimeString());
        $this->assertSame(1, $this->classStoryJobs());

        $this->sweep();
        $this->assertSame(1, $this->classStoryJobs(), 'the sweep announced a story that had already been announced');
    }

    #[Test]
    public function send_now_publishes_the_story_and_announces_it_exactly_once(): void
    {
        $post = $this->scheduledPost('+2 days');
        $this->assertNotContains($post->id, $this->familyIds());

        $this->asTeacher()
            ->putJson($this->teacherUrl("/posts/{$post->id}"), ['send_now' => true])
            ->assertOk()
            ->assertJsonPath('data.status', 'published');

        $this->assertContains($post->id, $this->familyIds());
        $this->assertSame(1, $this->classStoryJobs());

        // The sweep, and a second click, add nothing.
        $this->sweep();
        $this->asTeacher()->putJson($this->teacherUrl("/posts/{$post->id}"), ['title' => 'Edited'])->assertOk();
        $this->assertSame(1, $this->classStoryJobs());
    }

    #[Test]
    public function cancelling_a_scheduled_story_removes_it_from_the_list_and_it_is_never_announced(): void
    {
        $post = $this->scheduledPost('+1 hour');

        $this->asTeacher()->deleteJson($this->teacherUrl("/posts/{$post->id}"))->assertOk();

        $this->asTeacher()->getJson($this->teacherUrl('/posts?scheduled=1'))->assertOk()->assertJsonCount(0, 'data.data');

        Carbon::setTestNow(now()->addHours(2));
        $this->sweep();

        $this->assertSame(0, $this->classStoryJobs());
        $this->assertNotContains($post->id, $this->familyIds());
    }

    #[Test]
    public function the_sweep_announces_a_due_story_once_and_leaves_a_future_one_alone(): void
    {
        $due = $this->scheduledPost('+1 hour');
        $future = $this->scheduledPost('+3 days');

        Carbon::setTestNow(now()->addHours(1)->addMinute());

        $output = $this->sweep();

        $this->assertStringContainsString('stories announced=1', $output);
        $this->assertSame(1, $this->classStoryJobs());
        $this->assertNotNull($due->fresh()->announced_at);
        $this->assertNull($future->fresh()->announced_at);

        Bus::assertDispatched(SendGroupNotificationJob::class, fn ($job) => $job->groupId === (int) $this->class->id
            && $job->masjidId === (int) $this->school->id
            && $job->authorUserId === (int) $this->teacher->id);

        // A second run, and a third, are silent.
        $this->sweep();
        $this->sweep();
        $this->assertSame(1, $this->classStoryJobs());
    }

    #[Test]
    public function a_story_whose_author_left_the_class_is_refused_before_it_appears_and_never_announced(): void
    {
        $post = $this->scheduledPost('+1 minute');
        $author = $this->teacher;

        // The author leaves the class (S15) before the send time.
        GroupStaff::withoutMasjidScope()->where('user_id', $author->id)->where('group_id', $this->class->id)->delete();

        // One minute BEFORE its time: inside the look-ahead, so the refusal lands
        // before anybody's screen could show it.
        $output = $this->sweep();

        $this->assertStringContainsString('refused=1', $output);
        $fresh = $post->fresh();
        $this->assertNotNull($fresh->publish_failed_at);
        $this->assertSame('The author no longer teaches this class.', $fresh->publish_failure);
        $this->assertNull($fresh->announced_at);

        // And when the time comes it does not appear.
        Carbon::setTestNow(now()->addMinutes(5));
        $this->sweep();
        $this->assertNotContains($post->id, $this->familyIds());
        $this->assertSame(0, $this->classStoryJobs());

        // The teacher and the office see WHY, in the Scheduled list; here as a colleague.
        $co = $this->coTeacher();
        $this->asTeacher($co)->getJson($this->teacherUrl('/posts?scheduled=1'))
            ->assertOk()
            ->assertJsonPath('data.data.0.status', 'failed')
            ->assertJsonPath('data.data.0.publish_failure', 'The author no longer teaches this class.');
    }

    #[Test]
    public function a_story_inside_the_lookahead_that_passes_its_gate_waits_for_its_time_to_be_announced(): void
    {
        $post = $this->scheduledPost('+60 seconds');

        // Looked at (it is inside the look-ahead) and allowed, but not due: nothing
        // is announced and nothing is refused.
        $output = $this->sweep();

        $this->assertStringContainsString('stories announced=0 refused=0', $output);
        $this->assertNull($post->fresh()->announced_at);
        $this->assertNull($post->fresh()->publish_failed_at);
        $this->assertSame(0, $this->classStoryJobs());

        // Its time comes: now it is announced, once.
        Carbon::setTestNow(now()->addSeconds(61));
        $this->sweep();
        $this->assertSame(1, $this->classStoryJobs());
        $this->assertNotNull($post->fresh()->announced_at);
    }

    #[Test]
    public function the_lookahead_is_what_refuses_a_story_before_it_is_visible(): void
    {
        $post = $this->scheduledPost('+90 seconds');
        GroupStaff::withoutMasjidScope()->where('user_id', $this->teacher->id)->delete();

        // Ninety seconds ahead is inside the default 120-second look-ahead...
        $this->sweep();
        $this->assertNotNull($post->fresh()->publish_failed_at);

        // ...and a story further out than the look-ahead is not looked at yet.
        $far = $this->scheduledPost('+10 minutes');
        $this->sweep();
        $this->assertNull($far->fresh()->publish_failed_at);
    }

    #[Test]
    public function an_archived_authors_story_is_refused_and_so_is_an_administrators_who_lost_the_organisation(): void
    {
        $post = $this->scheduledPost('+1 minute');
        $this->teacher->delete();   // soft delete: "moved to trash"; group_staff rows survive

        $this->assertNotNull(GroupStaff::withoutMasjidScope()->where('user_id', $this->teacher->id)->first());

        $this->sweep();
        $this->assertSame('The account that wrote it has been removed.', $post->fresh()->publish_failure);

        // An office administrator with no membership of THIS school and not its owner.
        $stranger = User::factory()->create(['type' => 'MasjidAdmin', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
        $second = $this->scheduledPost('+1 minute', $stranger);
        $this->sweep();
        $this->assertSame('The author no longer has access to this class.', $second->fresh()->publish_failure);

        // Whereas the school's own owner (who holds manage contacts) still may.
        $owner = $this->makeAdmin();
        $third = $this->scheduledPost('+1 minute', $owner);
        $this->sweep();
        $this->assertNull($third->fresh()->publish_failed_at);
    }

    #[Test]
    public function a_story_of_a_class_that_no_longer_exists_is_refused(): void
    {
        $other = Group::factory()->create([
            'masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS, 'name' => 'Grade 9', 'slug' => 'grade-9',
        ]);
        $post = GroupPost::create([
            'masjid_id' => $this->school->id, 'group_id' => $other->id, 'author_user_id' => $this->teacher->id,
            'body' => 'For a retired class', 'published_at' => now()->addMinute(),
        ]);
        $other->delete();

        $this->sweep();

        $this->assertSame('The class no longer exists.', $post->fresh()->publish_failure);
    }

    #[Test]
    public function a_dry_run_reports_and_changes_nothing(): void
    {
        $due = $this->scheduledPost('+1 minute');
        $gone = $this->scheduledPost('+1 minute');
        Carbon::setTestNow(now()->addMinutes(2));
        GroupStaff::withoutMasjidScope()->delete();

        $output = $this->sweep(['--dry-run' => true]);

        $this->assertStringContainsString('[dry-run]', $output);
        $this->assertNull($due->fresh()->announced_at);
        $this->assertNull($gone->fresh()->publish_failed_at);
        $this->assertSame(0, $this->classStoryJobs());
    }

    #[Test]
    public function the_sweep_can_be_narrowed_to_one_school(): void
    {
        $mine = $this->scheduledPost('+1 minute');

        $otherClass = Group::factory()->create([
            'masjid_id' => $this->otherSchool->id, 'kind' => Group::KIND_CLASS, 'name' => 'Other', 'slug' => 'other',
        ]);
        $otherTeacher = $this->makeTeacher($this->otherSchool, $otherClass, 'Other Teacher');
        $theirs = GroupPost::create([
            'masjid_id' => $this->otherSchool->id, 'group_id' => $otherClass->id, 'author_user_id' => $otherTeacher->id,
            'body' => 'Elsewhere', 'published_at' => now()->addMinute(),
        ]);

        Carbon::setTestNow(now()->addMinutes(2));

        $this->sweep(['--masjid' => $this->otherSchool->id]);

        $this->assertNotNull($theirs->fresh()->announced_at);
        $this->assertNull($mine->fresh()->announced_at);
    }

    #[Test]
    public function every_run_writes_one_info_line_to_the_monitors_channel_and_leaves_the_tenant_as_it_found_it(): void
    {
        // The point's W5 review, item 6: the per-minute proof of run is info on the
        // monitors channel, not a WARNING on the default one.
        $monitors = \Mockery::spy(\Psr\Log\LoggerInterface::class);
        Log::spy();
        Log::shouldReceive('channel')->with('monitors')->andReturn($monitors);
        $tenant = app(TenantContext::class);

        $this->sweep();

        $monitors->shouldHaveReceived('info')->once()->withArgs(
            fn ($message) => str_starts_with((string) $message, 'groups:publish-due:')
        );
        Log::shouldNotHaveReceived('warning', [\Mockery::on(fn ($m) => str_starts_with((string) $m, 'groups:publish-due: stories'))]);

        // Unbound in, unbound out.
        $this->assertNull($tenant->get());

        // Bound in (a request that calls the command), bound out.
        $tenant->set($this->otherSchool->id);
        $this->scheduledPost('+1 minute');
        $this->sweep();
        $this->assertSame($this->otherSchool->id, $tenant->get());
    }

    #[Test]
    public function the_sweep_is_scheduled_every_minute_with_a_mutex_that_expires(): void
    {
        Artisan::call('list', ['--raw' => true]);

        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'groups:publish-due'));

        $this->assertNotNull($event, 'groups:publish-due is not on the schedule, so nothing scheduled ever goes out.');
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        // A killed run must not hold the mutex for the 24-hour default.
        $this->assertLessThan(60, $event->expiresAt);
    }

    // ================================================================ the schema

    #[Test]
    public function the_migration_stamps_every_existing_story_published_and_announced_on_the_day_it_was_written(): void
    {
        $migration = require database_path('migrations/2026_10_04_100000_add_scheduling_to_group_posts_table.php');

        $migration->down();
        $this->assertFalse(Schema::hasColumn('group_posts', 'published_at'));

        $wrote = '2026-09-20 09:30:00';
        DB::table('group_posts')->insert([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id, 'author_user_id' => $this->teacher->id,
            'body' => 'Live before the deploy', 'created_at' => $wrote, 'updated_at' => $wrote,
        ]);
        // A soft-deleted one too: the query builder does not apply the scope.
        DB::table('group_posts')->insert([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id, 'author_user_id' => $this->teacher->id,
            'body' => 'Deleted before the deploy', 'created_at' => $wrote, 'updated_at' => $wrote, 'deleted_at' => $wrote,
        ]);

        $migration->up();

        foreach (DB::table('group_posts')->get() as $row) {
            $this->assertSame($wrote, $row->published_at, 'no story may be left without a published_at');
            $this->assertSame($wrote, $row->announced_at, 'the first sweep must not announce a story that is already live');
        }

        // And the first sweep after the deploy says nothing about them.
        $this->sweep();
        $this->assertSame(0, $this->classStoryJobs());
    }

    #[Test]
    public function the_office_can_schedule_a_conversation_through_the_admin_realm(): void
    {
        $office = $this->office();

        $this->asUser($office)->postJson($this->adminUrl('/scheduled-messages'), $this->payload())
            ->assertCreated()->assertJsonPath('data.author.id', $office->id);

        $this->goTo(self::DUE);
        $this->sweep();

        $this->assertSame(1, GroupThread::withoutMasjidScope()->count());
        $this->assertSame($office->id, (int) GroupThread::withoutMasjidScope()->sole()->created_by_user_id);
    }

    #[Test]
    public function the_sweep_opens_a_due_conversation_through_the_same_writer_and_exactly_once(): void
    {
        $item = $this->schedule();

        // A minute early: nothing.
        $this->goTo('2026-10-05 13:59:00');
        $this->sweep();
        $this->nothingWritten();

        // At its time (send_at <= now).
        $this->goTo(self::DUE);
        $output = $this->sweep();

        $this->assertStringContainsString('conversations sent=1', $output);

        $thread = GroupThread::withoutMasjidScope()->sole();
        $message = GroupMessage::withoutMasjidScope()->sole();

        // Written for the right school, class, author and words, by a console run that
        // began UNBOUND: the bound tenant is what stamped masjid_id.
        $this->assertSame($this->school->id, (int) $thread->masjid_id);
        $this->assertSame($this->class->id, (int) $thread->group_id);
        $this->assertSame($this->teacher->id, (int) $thread->created_by_user_id);
        $this->assertSame('Term dates', $thread->subject);
        $this->assertSame(GroupThread::SCOPE_GROUP, $thread->scope);
        $this->assertSame($this->school->id, (int) $message->masjid_id);
        $this->assertSame($this->teacher->id, (int) $message->author_user_id);
        $this->assertSame('School resumes on Sunday.', $message->body);

        $item = $item->fresh();
        $this->assertSame(GroupMessageSchedule::STATUS_SENT, $item->status);
        $this->assertSame($thread->id, (int) $item->sent_thread_id);

        // The opener has read what she wrote: it does not greet her as unread.
        $read = GroupThreadRead::withoutMasjidScope()->where('group_thread_id', $thread->id)->sole();
        $this->assertSame($this->teacher->id, (int) $read->user_id);
        $this->assertSame($message->id, (int) $read->last_read_message_id);

        // The families it reaches are told, through the ordinary job.
        Bus::assertDispatchedTimes(SendGroupNotificationJob::class, 1);
        Bus::assertDispatched(SendGroupNotificationJob::class, fn ($job) => $job->event === GroupNotificationEvent::GUARDIAN_THREAD_MESSAGE
            && $job->aboutContactId === null
            && $job->groupId === (int) $this->class->id
            && $job->authorUserId === (int) $this->teacher->id);

        // A consented parent now finds it in the portal.
        $this->asParent($this->parentA)->getJson($this->familyUrl('/threads'))
            ->assertOk()->assertJsonPath('data.data.0.subject', 'Term dates');

        // A second and third run open nothing more.
        $this->sweep();
        $this->sweep();
        $this->assertSame(1, GroupThread::withoutMasjidScope()->count());
        $this->assertSame(1, GroupMessage::withoutMasjidScope()->count());
        Bus::assertDispatchedTimes(SendGroupNotificationJob::class, 1);
    }

    #[Test]
    public function a_conversation_about_one_child_reaches_that_childs_family_and_no_other(): void
    {
        $this->schedule(null, $this->aboutChild());

        $this->goTo(self::DUE);
        $this->sweep();

        $thread = GroupThread::withoutMasjidScope()->sole();
        $this->assertSame(GroupThread::SCOPE_PARTICIPANT, $thread->scope);
        $this->assertSame($this->childA->id, (int) $thread->about_membership_id);

        Bus::assertDispatched(SendGroupNotificationJob::class, fn ($job) => $job->aboutContactId === (int) $this->childA->contact_id);

        $this->asParent($this->parentA)->getJson($this->familyUrl('/threads'))
            ->assertOk()->assertJsonPath('data.data.0.subject', 'About Amina');
        // Another family in the same class does not see it.
        $this->assertSame([], $this->asParent($this->parentB)->getJson($this->familyUrl('/threads'))->assertOk()->json('data.data'));
    }

    #[Test]
    public function two_schools_due_in_one_run_are_each_written_in_their_own_school(): void
    {
        $mine = $this->schedule();
        // The request above left this process bound to the first school; a row for the
        // second must be written unbound, or the bound tenant (rightly) overrides it.
        app(TenantContext::class)->forgetTenant();

        $otherClass = Group::factory()->create([
            'masjid_id' => $this->otherSchool->id, 'kind' => Group::KIND_CLASS, 'name' => 'Theirs', 'slug' => 'theirs',
        ]);
        $foreign = $this->makeTeacher($this->otherSchool, $otherClass, 'Foreign Teacher');
        $theirs = GroupMessageSchedule::create([
            'masjid_id' => $this->otherSchool->id, 'group_id' => $otherClass->id, 'author_user_id' => $foreign->id,
            'scope' => GroupThread::SCOPE_GROUP, 'subject' => 'Theirs', 'body' => 'Their words', 'send_at' => now()->addDays(4),
        ]);

        $this->goTo(self::DUE);
        $this->sweep();

        $this->assertSame($this->school->id, (int) GroupThread::withoutMasjidScope()->where('subject', 'Term dates')->sole()->masjid_id);
        $this->assertSame($this->otherSchool->id, (int) GroupThread::withoutMasjidScope()->where('subject', 'Theirs')->sole()->masjid_id);
        $this->assertSame(GroupMessageSchedule::STATUS_SENT, $mine->fresh()->status);
        $this->assertSame(GroupMessageSchedule::STATUS_SENT, $theirs->fresh()->status);
        $this->assertNull(app(TenantContext::class)->get());
    }

    #[Test]
    public function the_sweep_can_be_narrowed_to_one_school_and_a_dry_run_changes_nothing(): void
    {
        $item = $this->schedule();
        $this->goTo(self::DUE);

        $this->assertStringContainsString('[dry-run]', $this->sweep(['--dry-run' => true]));
        $this->assertSame(GroupMessageSchedule::STATUS_SCHEDULED, $item->fresh()->status);
        $this->nothingWritten();

        $this->sweep(['--masjid' => $this->otherSchool->id]);
        $this->assertSame(GroupMessageSchedule::STATUS_SCHEDULED, $item->fresh()->status);
        $this->nothingWritten();

        $this->sweep(['--masjid' => $this->school->id]);
        $this->assertSame(GroupMessageSchedule::STATUS_SENT, $item->fresh()->status);
    }

    // ==================================================== the gates, at send time

    #[Test]
    public function an_author_who_left_the_class_before_the_send_time_is_not_sent_and_shown_as_failed(): void
    {
        $item = $this->schedule();
        GroupStaff::withoutMasjidScope()->where('user_id', $this->teacher->id)->where('group_id', $this->class->id)->delete();

        $this->goTo(self::DUE);
        $this->sweep();

        $item = $item->fresh();
        $this->assertSame(GroupMessageSchedule::STATUS_FAILED, $item->status);
        $this->assertSame('The author no longer teaches this class.', $item->failure_reason);
        $this->assertNull($item->sent_thread_id);
        $this->nothingWritten();

        // A colleague sees why.
        $this->asTeacher($this->coTeacher())->getJson($this->teacherUrl('/scheduled-messages'))
            ->assertOk()
            ->assertJsonPath('data.data.0.status', 'failed')
            ->assertJsonPath('data.data.0.failure_reason', 'The author no longer teaches this class.');

        // And a later sweep does not retry a failure on its own.
        $this->goTo('2026-10-06 14:00:00');
        $this->sweep();
        $this->nothingWritten();
    }

    #[Test]
    public function an_archived_or_deleted_authors_conversation_is_not_sent(): void
    {
        $archived = $this->schedule();
        $this->teacher->delete();   // moved to trash; group_staff rows survive

        $this->goTo(self::DUE);
        $this->sweep();

        $this->assertSame('The account that wrote it has been removed.', $archived->fresh()->failure_reason);
        $this->nothingWritten();

        // A deleted account nulls the author (nullOnDelete): still not sent.
        $second = $this->coTeacher();
        $this->goTo(self::NOW);
        $orphan = $this->schedule($second, ['subject' => 'Orphan']);
        $second->forceDelete();
        $this->assertNull($orphan->fresh()->author_user_id);

        $this->goTo(self::DUE);
        $this->sweep();

        $this->assertSame(GroupMessageSchedule::STATUS_FAILED, $orphan->fresh()->status);
        $this->assertSame('The account that wrote it has been removed.', $orphan->fresh()->failure_reason);
        $this->nothingWritten();
    }

    #[Test]
    public function a_conversation_about_a_child_who_left_or_came_off_the_roster_is_not_sent(): void
    {
        $left = $this->schedule(null, $this->aboutChild());
        $removed = $this->schedule(null, $this->aboutChild(['about_membership_id' => $this->childB->id, 'subject' => 'About Zayd']));

        GroupMembership::withoutMasjidScope()->whereKey($this->childA->id)->update(['left_on' => now()->addDay()->toDateString()]);
        GroupMembership::withoutMasjidScope()->whereKey($this->childB->id)->delete();

        $this->goTo(self::DUE);
        $this->sweep();

        $this->assertSame(GroupMessageSchedule::STATUS_FAILED, $left->fresh()->status);
        $this->assertSame('The child has left the class or is no longer on its roster.', $left->fresh()->failure_reason);
        $this->assertSame(GroupMessageSchedule::STATUS_FAILED, $removed->fresh()->status);
        $this->assertNull($removed->fresh()->about_membership_id);
        $this->assertSame('The child is no longer on the class roster.', $removed->fresh()->failure_reason);
        $this->nothingWritten();
    }

    #[Test]
    public function a_class_that_no_longer_exists_fails_its_conversation(): void
    {
        $other = Group::factory()->create([
            'masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS, 'name' => 'Grade 9', 'slug' => 'grade-9',
        ]);
        $item = GroupMessageSchedule::create([
            'masjid_id' => $this->school->id, 'group_id' => $other->id, 'author_user_id' => $this->teacher->id,
            'scope' => GroupThread::SCOPE_GROUP, 'subject' => 's', 'body' => 'b', 'send_at' => now()->addDay(),
        ]);
        $other->delete();

        $this->goTo('2026-10-03 12:00:00');
        $this->sweep();

        $this->assertSame('The class no longer exists.', $item->fresh()->failure_reason);
        $this->nothingWritten();
    }

    #[Test]
    public function an_administrator_authors_conversation_is_sent_while_they_still_belong_and_not_after(): void
    {
        $owner = $this->makeAdmin();
        $ok = GroupMessageSchedule::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id, 'author_user_id' => $owner->id,
            'scope' => GroupThread::SCOPE_GROUP, 'subject' => 'From the office', 'body' => 'b', 'send_at' => now()->addDay(),
        ]);
        $stranger = User::factory()->create(['type' => 'MasjidAdmin', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
        $no = GroupMessageSchedule::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id, 'author_user_id' => $stranger->id,
            'scope' => GroupThread::SCOPE_GROUP, 'subject' => 'Not ours', 'body' => 'b', 'send_at' => now()->addDay(),
        ]);

        $this->goTo('2026-10-03 12:00:00');
        $this->sweep();

        $this->assertSame(GroupMessageSchedule::STATUS_SENT, $ok->fresh()->status);
        $this->assertSame(GroupMessageSchedule::STATUS_FAILED, $no->fresh()->status);
        $this->assertSame('The author no longer has access to this class.', $no->fresh()->failure_reason);
        $this->assertSame(['From the office'], GroupThread::withoutMasjidScope()->pluck('subject')->all());
    }

    #[Test]
    public function an_unexpected_error_fails_the_item_with_nothing_written_and_leaks_no_words_into_the_log(): void
    {
        $item = $this->schedule(null, ['body' => 'A confidential sentence about a child']);

        $this->partialMock(GroupThreadWriter::class, function ($mock) {
            $mock->shouldReceive('open')->andThrow(new \RuntimeException('SQLSTATE: insert ... values (A confidential sentence about a child)'));
        });

        Log::spy();
        Log::shouldReceive('channel')->with('monitors')->andReturn(\Mockery::spy(\Psr\Log\LoggerInterface::class));
        $this->goTo(self::DUE);
        $this->sweep();

        $item = $item->fresh();
        $this->assertSame(GroupMessageSchedule::STATUS_FAILED, $item->status);
        $this->assertSame('It could not be sent because of an error, so nothing was sent. Edit it to try again.', $item->failure_reason);
        $this->assertSame(0, GroupThread::withoutMasjidScope()->count());

        // A class name, never the exception message: a database error carries its
        // bindings, and the bindings are what a teacher wrote about a child.
        Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains((string) $m, 'RuntimeException'));
        Log::shouldNotHaveReceived('warning', [\Mockery::on(fn ($m) => str_contains((string) $m, 'confidential'))]);
    }

    // ==================================================================== claims

    #[Test]
    public function a_conversation_whose_claim_was_lost_leaves_no_thread_behind(): void
    {
        $item = $this->schedule();

        // Between the claim and the write, another actor takes the item from this run (its
        // status moves off `sending`). The sent stamp is inside the writer's transaction and
        // is guarded by that status, so it finds nothing to stamp and the thread it had just
        // written is rolled back: never a conversation whose schedule does not say sent.
        \Illuminate\Support\Facades\Event::listen('eloquent.created: '.GroupThread::class, function () use ($item): void {
            GroupMessageSchedule::withoutMasjidScope()->whereKey($item->id)->update(['status' => GroupMessageSchedule::STATUS_CANCELLED]);
        });

        $this->goTo(self::DUE);
        $this->sweep();

        $this->assertSame(0, GroupThread::withoutMasjidScope()->withTrashed()->count(), 'a thread survived a lost claim');
        $this->assertSame(0, GroupMessage::withoutMasjidScope()->count());
        $this->assertNotSame(GroupMessageSchedule::STATUS_SENT, $item->fresh()->status);
        Bus::assertNotDispatched(SendGroupNotificationJob::class);
    }

    #[Test]
    public function an_item_another_sweep_has_claimed_is_left_alone_and_a_stale_claim_is_handed_back(): void
    {
        $fresh = $this->schedule(null, ['subject' => 'Being sent']);
        $stale = $this->schedule(null, ['subject' => 'Stuck']);

        $this->goTo(self::DUE);
        // Another sweep is writing $fresh right now; a killed one abandoned $stale.
        GroupMessageSchedule::withoutMasjidScope()->whereKey($fresh->id)->update(['status' => 'sending', 'updated_at' => now()->subMinutes(5)]);
        GroupMessageSchedule::withoutMasjidScope()->whereKey($stale->id)->update(['status' => 'sending', 'updated_at' => now()->subMinutes(11)]);

        $output = $this->sweep();

        $this->assertStringContainsString('reclaimed=1', $output);
        $this->assertSame('sending', $fresh->fresh()->status, 'a fresh claim was taken from the run that holds it');
        $this->assertSame(GroupMessageSchedule::STATUS_SENT, $stale->fresh()->status);
        $this->assertSame(['Stuck'], GroupThread::withoutMasjidScope()->pluck('subject')->all());
    }

    #[Test]
    public function sent_cancelled_and_failed_items_are_never_sent_by_a_sweep(): void
    {
        foreach ([GroupMessageSchedule::STATUS_SENT, GroupMessageSchedule::STATUS_CANCELLED, GroupMessageSchedule::STATUS_FAILED] as $status) {
            $this->schedule(null, ['subject' => $status])->forceFill(['status' => $status])->save();
        }

        $this->goTo('2026-10-20 12:00:00');
        $this->sweep();

        $this->nothingWritten();
    }

    // ============================================== send now, edit, cancel

    #[Test]
    public function send_now_sets_the_time_to_now_and_the_sweep_sends_it_within_the_minute(): void
    {
        $item = $this->schedule();

        $this->asTeacher()
            ->putJson($this->teacherUrl("/scheduled-messages/{$item->id}"), ['send_now' => true])
            ->assertOk()->assertJsonPath('data.status', 'scheduled');

        $this->assertSame(self::NOW, $item->fresh()->send_at->toDateTimeString());

        $this->sweep();

        $this->assertSame(GroupMessageSchedule::STATUS_SENT, $item->fresh()->status);
        $this->assertSame(1, GroupThread::withoutMasjidScope()->count());
    }

    #[Test]
    public function cancelling_keeps_the_row_as_cancelled_and_nothing_is_ever_sent(): void
    {
        $item = $this->schedule();

        $this->asTeacher()->deleteJson($this->teacherUrl("/scheduled-messages/{$item->id}"))
            ->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->asTeacher()->getJson($this->teacherUrl('/scheduled-messages'))->assertOk()->assertJsonCount(0, 'data.data');
        $this->asTeacher()->deleteJson($this->teacherUrl("/scheduled-messages/{$item->id}"))->assertStatus(422);

        $this->goTo(self::DUE);
        $this->sweep();

        $this->nothingWritten();
        $this->assertSame(GroupMessageSchedule::STATUS_CANCELLED, $item->fresh()->status);
    }

    #[Test]
    public function a_failed_item_is_put_back_by_a_new_time_and_stays_failed_after_a_text_edit(): void
    {
        $item = $this->schedule();
        $item->forceFill([
            'status' => GroupMessageSchedule::STATUS_FAILED,
            'failure_reason' => 'The child has left the class or is no longer on its roster.',
        ])->save();
        $url = $this->teacherUrl("/scheduled-messages/{$item->id}");

        $this->asTeacher()->putJson($url, ['body' => 'Fixed a typo'])
            ->assertOk()->assertJsonPath('data.status', 'failed');

        $this->asTeacher()->putJson($url, ['send_at' => '2026-10-07T10:00'])
            ->assertOk()->assertJsonPath('data.status', 'scheduled')->assertJsonPath('data.failure_reason', null);

        $this->goTo('2026-10-07 14:00:00');
        $this->sweep();

        $this->assertSame(GroupMessageSchedule::STATUS_SENT, $item->fresh()->status);
    }

    // ============================================ a refusal is made once, not every minute

    #[Test]
    public function a_refused_story_is_refused_once_and_later_runs_leave_it_and_its_reason_alone(): void
    {
        $post = $this->scheduledPost('+1 minute');
        GroupStaff::withoutMasjidScope()->where('user_id', $this->teacher->id)->delete();

        $this->assertStringContainsString('stories announced=0 refused=1', $this->sweep());
        $first = $post->fresh()->publish_failed_at->toDateTimeString();

        Carbon::setTestNow(now()->addMinutes(5));

        $this->assertStringContainsString('stories announced=0 refused=0', $this->sweep());
        $this->assertSame($first, $post->fresh()->publish_failed_at->toDateTimeString(), 'a refused story was refused again');
    }

    // ================================================================ the batch cap

    #[Test]
    public function stories_already_announced_do_not_crowd_a_due_story_out_of_a_full_batch(): void
    {
        config(['groups.scheduling.batch' => 2]);

        // Three stories long since out (announced when written), older than the due one.
        $this->makePost(body: 'One');
        $this->makePost(body: 'Two');
        $this->makePost(body: 'Three');
        $due = $this->scheduledPost('+1 hour');

        Carbon::setTestNow(now()->addHours(2));

        $this->assertStringContainsString('stories announced=1', $this->sweep());
        $this->assertNotNull($due->fresh()->announced_at, 'a due story was never reached: old stories fill the batch');
        $this->assertSame(1, $this->classStoryJobs());
    }

    #[Test]
    public function one_run_announces_no_more_stories_than_the_batch_and_the_next_run_takes_the_rest(): void
    {
        config(['groups.scheduling.batch' => 2]);
        foreach (['A', 'B', 'C'] as $body) {
            $this->scheduledPost('+1 hour', body: $body);
        }
        Carbon::setTestNow(now()->addHours(2));

        $this->assertStringContainsString('stories announced=2', $this->sweep());
        $this->assertSame(2, $this->classStoryJobs());

        $this->assertStringContainsString('stories announced=1', $this->sweep());
        $this->assertSame(3, $this->classStoryJobs());
    }

    #[Test]
    public function one_run_sends_no_more_conversations_than_the_batch_and_the_next_run_sends_the_rest(): void
    {
        config(['groups.scheduling.batch' => 2]);
        $items = [];
        foreach (['One', 'Two', 'Three'] as $subject) {
            $items[] = $this->schedule(null, ['subject' => $subject]);
        }
        $this->goTo(self::DUE);

        $this->assertStringContainsString('conversations sent=2', $this->sweep());
        $this->assertSame(2, GroupThread::withoutMasjidScope()->count());
        $this->assertSame(GroupMessageSchedule::STATUS_SCHEDULED, $items[2]->fresh()->status);

        $this->assertStringContainsString('conversations sent=1', $this->sweep());
        $this->assertSame(3, GroupThread::withoutMasjidScope()->count());
    }

    // ================================================================ dry runs and --masjid

    #[Test]
    public function a_dry_run_counts_a_due_story_its_author_may_still_send_and_announces_nothing(): void
    {
        $due = $this->scheduledPost('+1 minute');
        Carbon::setTestNow(now()->addMinutes(2));

        $output = $this->sweep(['--dry-run' => true]);

        $this->assertStringContainsString('[dry-run]', $output);
        $this->assertStringContainsString('stories announced=1', $output);
        $this->assertNull($due->fresh()->announced_at, 'a dry run claimed the story');
        $this->assertSame(0, $this->classStoryJobs(), 'a dry run sent the class-story email');

        // The control: the same story, a real run.
        $this->sweep();
        $this->assertNotNull($due->fresh()->announced_at);
        $this->assertSame(1, $this->classStoryJobs());
    }

    #[Test]
    public function a_dry_run_and_a_narrowed_sweep_do_not_hand_back_a_claim_they_were_not_asked_about(): void
    {
        $mine = $this->schedule(null, ['subject' => 'Mine']);
        app(TenantContext::class)->forgetTenant();

        $otherClass = Group::factory()->create([
            'masjid_id' => $this->otherSchool->id, 'kind' => Group::KIND_CLASS, 'name' => 'Theirs', 'slug' => 'theirs',
        ]);
        $foreign = $this->makeTeacher($this->otherSchool, $otherClass, 'Foreign Teacher');
        $theirs = GroupMessageSchedule::create([
            'masjid_id' => $this->otherSchool->id, 'group_id' => $otherClass->id, 'author_user_id' => $foreign->id,
            'scope' => GroupThread::SCOPE_GROUP, 'subject' => 'Theirs', 'body' => 'Their words', 'send_at' => self::DUE,
        ]);

        $this->goTo(self::DUE);
        foreach ([$mine, $theirs] as $row) {
            GroupMessageSchedule::withoutMasjidScope()->whereKey($row->id)
                ->update(['status' => 'sending', 'updated_at' => now()->subMinutes(30)]);
        }

        // A dry run reports both and hands back neither.
        $this->assertStringContainsString('reclaimed=2', $this->sweep(['--dry-run' => true]));
        $this->assertSame('sending', $mine->fresh()->status, 'a dry run handed a claim back');
        $this->assertSame('sending', $theirs->fresh()->status, 'a dry run handed a claim back');

        // A sweep narrowed to the other school touches only that school's claim.
        $this->assertStringContainsString('reclaimed=1', $this->sweep(['--masjid' => $this->otherSchool->id]));
        $this->assertSame('sending', $mine->fresh()->status, 'a sweep for another school handed this school\'s claim back');
        $this->assertSame(GroupMessageSchedule::STATUS_SENT, $theirs->fresh()->status);
    }

    // ================================================================ the gate itself

    #[Test]
    public function a_super_administrator_author_may_send_to_any_class(): void
    {
        $super = User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+1'.random_int(1000000000, 9999999999)]);

        $story = $this->scheduledPost('+1 minute', $super);
        $this->assertNull(app(ScheduledSendGate::class)->authorRefusal($super->id, $this->class));

        Carbon::setTestNow(now()->addMinutes(2));
        $this->assertStringContainsString('stories announced=1 refused=0', $this->sweep());
        $this->assertNotNull($story->fresh()->announced_at);
        $this->assertNull($story->fresh()->publish_failed_at);
    }

    #[Test]
    public function the_gate_binds_the_items_school_for_its_questions_and_puts_back_what_it_found(): void
    {
        $gate = app(ScheduledSendGate::class);
        $tenant = app(TenantContext::class);

        // Unbound (a console run): the author's standing is still read in the class's own school.
        $tenant->forgetTenant();
        $this->assertNull($gate->authorRefusal($this->teacher->id, $this->class), 'the gate read the author unbound');
        $this->assertNull($gate->aboutRefusal($this->class, $this->childA->id, true), 'the gate read the child unbound');
        $this->assertNull($tenant->get(), 'the gate left a school bound');

        // Bound to somebody else's school (a request that runs the command): the questions
        // are still asked in the CLASS's school, and what was bound is put back exactly.
        $tenant->set($this->otherSchool->id);
        $this->assertNull($gate->authorRefusal($this->teacher->id, $this->class), 'the gate read the author under another school');
        $this->assertNull($gate->aboutRefusal($this->class, $this->childA->id, true), 'the gate read the child under another school');
        $this->assertSame($this->otherSchool->id, $tenant->get(), 'the gate did not restore the tenant it found');
    }

    // ================================================== the claim's own guards, without the list's help

    /** @return array<string,int> */
    private function callSendMessage(int $id, ?ScheduledSendGate $gate = null): array
    {
        $command = app(PublishDueGroupItems::class);
        $method = new \ReflectionMethod($command, 'sendMessage');
        $method->setAccessible(true);
        $run = ['announced' => 0, 'refused' => 0, 'sent' => 0, 'failed' => 0, 'reclaimed' => 0, 'errors' => 0];

        $method->invokeArgs($command, [$id, now(), $gate ?? app(ScheduledSendGate::class), app(GroupThreadWriter::class), false, &$run]);

        return $run;
    }

    #[Test]
    public function the_claim_refuses_an_item_whose_time_has_not_come_even_when_handed_its_id(): void
    {
        $item = $this->schedule();   // due on the 5th; it is the 1st

        $run = $this->callSendMessage($item->id);

        $this->assertSame(0, $run['sent'] + $run['failed']);
        $this->assertSame(GroupMessageSchedule::STATUS_SCHEDULED, $item->fresh()->status);
        $this->nothingWritten();
    }

    #[Test]
    public function the_claim_refuses_an_item_that_is_no_longer_waiting_even_when_handed_its_id(): void
    {
        $item = $this->schedule();
        $item->forceFill(['status' => GroupMessageSchedule::STATUS_CANCELLED])->save();
        $this->goTo(self::DUE);

        $this->callSendMessage($item->id);

        $this->assertSame(GroupMessageSchedule::STATUS_CANCELLED, $item->fresh()->status);
        $this->nothingWritten();
    }

    #[Test]
    public function a_refusal_does_not_overwrite_an_item_somebody_cancelled_while_the_gates_were_being_asked(): void
    {
        $item = $this->schedule();
        $this->goTo(self::DUE);

        $gate = \Mockery::mock(ScheduledSendGate::class);
        $gate->shouldReceive('aboutMembership')->andReturn(null);
        $gate->shouldReceive('authorRefusal')->andReturnUsing(function () use ($item): string {
            // The author cancels in the instant between the claim and the answer.
            GroupMessageSchedule::withoutMasjidScope()->whereKey($item->id)->update(['status' => GroupMessageSchedule::STATUS_CANCELLED]);

            return 'The author no longer teaches this class.';
        });
        $gate->shouldReceive('aboutRefusal')->andReturn(null);

        $this->callSendMessage($item->id, $gate);

        $fresh = $item->fresh();
        $this->assertSame(GroupMessageSchedule::STATUS_CANCELLED, $fresh->status, 'a cancelled item was marked failed');
        $this->assertNull($fresh->failure_reason);
    }

    // ============================== sweep liveness: a different command (P5)

    #[Test]
    public function a_story_stuck_more_than_ten_minutes_past_its_time_is_an_error_somebody_sees(): void
    {
        // The point's W5 review, item 6, moved out of the sweep (the point's W5/W6 delta review, P5):
        // a dead sweep cannot report itself. Simulate a story that keeps failing to be released.
        $post = $this->scheduledPost('+1 minute');
        $this->partialMock(\App\Services\Groups\GroupStoryPublisher::class, function ($mock) {
            $mock->shouldReceive('announce')->andThrow(new \RuntimeException('queue down'));
        });

        $monitors = \Mockery::spy(\Psr\Log\LoggerInterface::class);
        Log::spy();
        Log::shouldReceive('channel')->with('monitors')->andReturn($monitors);

        $this->goTo(now()->addMinutes(5)->toDateTimeString());
        $this->sweep();
        Artisan::call('groups:sweep-health');
        Log::shouldNotHaveReceived('error');
        $monitors->shouldNotHaveReceived('error');
        $monitors->shouldHaveReceived('info')->withArgs(fn ($m) => str_starts_with((string) $m, 'groups:sweep-health:'));

        $this->goTo(now()->addMinutes(10)->toDateTimeString());
        $this->sweep();
        // The sweep itself no longer raises it.
        Log::shouldNotHaveReceived('error');

        Artisan::call('groups:sweep-health');
        $expected = fn ($m) => str_contains((string) $m, '1 scheduled stories and 0 scheduled conversations are more than 10 minutes past their time');
        Log::shouldHaveReceived('error')->once()->withArgs($expected);
        $monitors->shouldHaveReceived('error')->once()->withArgs($expected);
        $this->assertNull($post->fresh()->announced_at);
    }

    #[Test]
    public function the_health_check_counts_a_waiting_story_and_a_stuck_conversation_but_never_a_refused_cancelled_or_not_yet_late_item_and_names_no_one(): void
    {
        // The stories are written in the FUTURE and the clock moved afterwards: a story created
        // with a past `published_at` is stamped `announced_at` at birth (GroupPost's creating
        // hook), which would exclude it on that alone and leave the refused and soft-delete
        // exclusions in GroupsSweepHealth untested. Here all of them really wait (announced_at NULL).
        $waiting = $this->scheduledPost('+1 minute');
        $refused = $this->scheduledPost('+1 minute');
        $cancelled = $this->scheduledPost('+1 minute');
        $announced = $this->scheduledPost('+1 minute');
        $notYetLate = $this->scheduledPost('+5 minutes');

        $this->goTo(now()->addMinutes(12)->toDateTimeString());
        $now = now();

        foreach ([$waiting, $refused, $cancelled, $announced, $notYetLate] as $post) {
            $this->assertNull($post->fresh()->announced_at, 'the fixture must really be waiting');
        }
        $refused->forceFill(['publish_failed_at' => $now])->save();
        $cancelled->delete();
        $announced->forceFill(['announced_at' => $now])->save();

        // Stuck: a conversation still `scheduled` 11 minutes late, and one wedged in `sending`
        // 30 minutes late (a failure in the sweep's gate phase leaves exactly that).
        foreach ([[GroupMessageSchedule::STATUS_SCHEDULED, 11, 'Secret subject'], [GroupMessageSchedule::STATUS_SENDING, 30, 'Secret subject two']] as [$status, $minutes, $subject]) {
            GroupMessageSchedule::create([
                'masjid_id' => $this->class->masjid_id, 'group_id' => $this->class->id, 'author_user_id' => $this->teacher->id,
                'scope' => \App\Models\GroupThread::SCOPE_GROUP, 'subject' => $subject, 'body' => 'Secret body',
                'send_at' => $now->copy()->subMinutes($minutes), 'status' => $status,
            ]);
        }
        // Not stuck: late by nine minutes (scheduled or being sent), late but failed, late but cancelled.
        foreach ([[GroupMessageSchedule::STATUS_SCHEDULED, 9], [GroupMessageSchedule::STATUS_SENDING, 9],
            [GroupMessageSchedule::STATUS_FAILED, 30], [GroupMessageSchedule::STATUS_CANCELLED, 30]] as [$status, $minutes]) {
            GroupMessageSchedule::create([
                'masjid_id' => $this->class->masjid_id, 'group_id' => $this->class->id, 'author_user_id' => $this->teacher->id,
                'scope' => \App\Models\GroupThread::SCOPE_GROUP, 'subject' => 'x', 'body' => 'x',
                'send_at' => $now->copy()->subMinutes($minutes), 'status' => $status,
            ]);
        }

        $monitors = \Mockery::spy(\Psr\Log\LoggerInterface::class);
        Log::spy();
        Log::shouldReceive('channel')->with('monitors')->andReturn($monitors);

        $this->assertSame(0, Artisan::call('groups:sweep-health'));

        $expected = fn ($m) => str_contains((string) $m, '1 scheduled stories and 2 scheduled conversations are more than 10 minutes')
            && ! str_contains((string) $m, 'Secret');
        Log::shouldHaveReceived('error')->once()->withArgs($expected);
        $monitors->shouldHaveReceived('error')->once()->withArgs($expected);
    }

    #[Test]
    public function a_conversation_that_keeps_failing_inside_the_sweeps_gate_phase_is_reported_as_stuck(): void
    {
        // PublishDueGroupItems claims the row BEFORE its gates run, and the gates sit outside
        // the write's try: a gate that throws leaves the row `sending`, the stale-claim handback
        // returns it after ten minutes and the same run claims it again. So it is `sending`
        // whenever the health check looks, and the check must count it.
        $item = GroupMessageSchedule::create([
            'masjid_id' => $this->class->masjid_id, 'group_id' => $this->class->id, 'author_user_id' => $this->teacher->id,
            'scope' => \App\Models\GroupThread::SCOPE_GROUP, 'subject' => 'Secret subject', 'body' => 'Secret body',
            'send_at' => now()->addMinute(),
        ]);
        $this->partialMock(ScheduledSendGate::class, function ($mock) {
            $mock->shouldReceive('authorRefusal')->andThrow(new \RuntimeException('gate down'));
        });

        $monitors = \Mockery::spy(\Psr\Log\LoggerInterface::class);
        Log::spy();
        Log::shouldReceive('channel')->with('monitors')->andReturn($monitors);

        foreach ([2, 12, 24] as $minutes) {
            $this->goTo(Carbon::parse($item->send_at)->subMinute()->addMinutes($minutes)->toDateTimeString());
            $this->sweep();
            $this->assertSame(GroupMessageSchedule::STATUS_SENDING, $item->fresh()->status, "at +{$minutes} minutes");
        }

        Artisan::call('groups:sweep-health');

        $expected = fn ($m) => str_contains((string) $m, '0 scheduled stories and 1 scheduled conversations are more than 10 minutes')
            && ! str_contains((string) $m, 'Secret');
        Log::shouldHaveReceived('error')->once()->withArgs($expected);
        $monitors->shouldHaveReceived('error')->once()->withArgs($expected);
    }

    #[Test]
    public function the_health_check_is_quiet_with_one_info_line_when_nothing_is_late_and_the_sweep_no_longer_raises_it(): void
    {
        $monitors = \Mockery::spy(\Psr\Log\LoggerInterface::class);
        Log::spy();
        Log::shouldReceive('channel')->with('monitors')->andReturn($monitors);

        Artisan::call('groups:sweep-health');

        $monitors->shouldHaveReceived('info')->once()->withArgs(fn ($m) => str_starts_with((string) $m, 'groups:sweep-health:'));
        $monitors->shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('error');
    }

    #[Test]
    public function the_sweep_itself_no_longer_carries_the_staleness_check(): void
    {
        // Two hours late and unannounced: the sweep (whatever it does with the item) does not raise the alarm.
        // (Written in the future and then left to go late: a row created already in the past is
        // announced at birth, which would leave nothing stuck to report.)
        $post = $this->scheduledPost('+1 minute');
        $this->partialMock(\App\Services\Groups\GroupStoryPublisher::class, function ($mock) {
            $mock->shouldReceive('announce')->andThrow(new \RuntimeException('queue down'));
        });
        Log::spy();
        Log::shouldReceive('channel')->with('monitors')->andReturn(\Mockery::spy(\Psr\Log\LoggerInterface::class));

        $this->goTo(now()->addHours(2)->toDateTimeString());
        $this->sweep();
        $this->assertNull($post->fresh()->announced_at, 'the story is still unannounced, so it is stuck');

        Log::shouldNotHaveReceived('error');
    }

    #[Test]
    public function the_health_check_is_scheduled_every_ten_minutes_and_cannot_overlap_itself(): void
    {
        Artisan::call('list', ['--raw' => true]);
        $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'groups:sweep-health'));

        $this->assertNotNull($event, 'groups:sweep-health is not scheduled');
        $this->assertSame('*/10 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }
}
