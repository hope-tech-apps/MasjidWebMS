<?php

namespace Tests\Feature;

use App\Jobs\SendGroupNotificationJob;
use App\Models\Group;
use App\Models\GroupMessage;
use App\Models\GroupMessageSchedule;
use App\Models\GroupStaff;
use App\Models\GroupThread;
use App\Models\GroupThreadRead;
use App\Models\User;
use App\Support\GroupThreadUnread;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsClassStoryFixture;
use Tests\TestCase;

/**
 * The unread-messages count a teacher (or an office leader) sees: on the Messages
 * tab, beside the class name, on each My Classes card, on each conversation row.
 *
 * What these tests pin, and why each one matters:
 *
 *   - the count is MESSAGES FROM OTHER PEOPLE a staff member has not seen, per
 *     conversation, computed by ONE grouped query (a count that costs a query per
 *     class would make My Classes slower with every class a teacher gains);
 *   - a staff member with no bookmark is counted from a floor, never from the
 *     start of time, and computing the count NEVER writes a bookmark: staff
 *     bookmarks are shown to families as read receipts, so a seeded row would tell
 *     every parent "seen by the teacher" about messages nobody opened;
 *   - opening a conversation the way the teacher screen opens it clears it, even
 *     when it is longer than one page of messages;
 *   - a reply from a screen that was open while a parent wrote does not carry the
 *     bookmark past that message;
 *   - a scheduled conversation that has not been sent, a deleted conversation, and
 *     another organisation's conversations never count.
 */
class GroupThreadUnreadTest extends TestCase
{
    use RefreshDatabase;
    use BuildsClassStoryFixture;

    private const FLOOR = '2026-09-28 00:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->useSqliteInMemory();
        Bus::fake([SendGroupNotificationJob::class]);
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        // The floor is explicit in tests: null would mean "no floor".
        config(['groups.messaging.unread_since' => null]);

        $this->buildStoryWorld();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ================================================================ fixtures

    private function thread(?Group $class = null, string $subject = 'About Amina'): GroupThread
    {
        $class ??= $this->class;

        return GroupThread::create([
            'masjid_id' => $class->masjid_id,
            'group_id' => $class->id,
            'created_by_user_id' => $this->teacher->id,
            'subject' => $subject,
            'scope' => GroupThread::SCOPE_GROUP,
        ]);
    }

    /** A message from a parent, optionally at a stated time. */
    private function fromParent(GroupThread $thread, ?string $at = null, ?int $contactId = null): GroupMessage
    {
        return GroupMessage::factory()->create([
            'masjid_id' => $thread->masjid_id,
            'group_thread_id' => $thread->id,
            'author_contact_id' => $contactId ?? $this->parentA->id,
            'body' => 'From a parent',
        ] + ($at !== null ? ['created_at' => $at, 'updated_at' => $at] : []));
    }

    private function fromStaff(GroupThread $thread, User $user, ?string $at = null): GroupMessage
    {
        return GroupMessage::factory()->create([
            'masjid_id' => $thread->masjid_id,
            'group_thread_id' => $thread->id,
            'author_user_id' => $user->id,
            'body' => 'From staff',
        ] + ($at !== null ? ['created_at' => $at, 'updated_at' => $at] : []));
    }

    private function coTeacher(): User
    {
        return $this->makeTeacher($this->school, $this->class, 'Ustadha Sara');
    }

    private function marker(GroupThread $thread, User $user, ?int $messageId, ?string $at = null): GroupThreadRead
    {
        $read = GroupThreadRead::create([
            'masjid_id' => $thread->masjid_id,
            'group_thread_id' => $thread->id,
            'user_id' => $user->id,
            'last_read_at' => $at ?? now(),
        ]);
        $read->forceFill(['last_read_message_id' => $messageId])->save();

        return $read;
    }

    /** @return array<int,array<string,mixed>> the teacher's thread list rows, by thread id */
    private function listRows(?User $as = null, string $query = ''): array
    {
        $response = $this->asTeacher($as)->getJson($this->teacherUrl('/threads').$query)->assertOk();

        return collect($response->json('data.data'))->keyBy('id')->all();
    }

    private function classUnread(?User $as = null): int
    {
        return (int) $this->asTeacher($as)->getJson($this->teacherUrl(''))->assertOk()->json('data.unread_messages');
    }

    private function threadUnread(GroupThread $thread, ?User $as = null): int
    {
        return (int) ($this->listRows($as)[$thread->id]['unread_count'] ?? -1);
    }

    /** Open a thread the way the teacher screen does: the biggest page it may ask for. */
    private function openLikeTheScreen(GroupThread $thread, ?User $as = null)
    {
        return $this->asTeacher($as)->getJson($this->teacherUrl("/threads/{$thread->id}?per_page=200"))->assertOk();
    }

    // ================================================================ 1. who counts

    #[Test]
    public function the_count_is_messages_from_other_people_and_never_your_own(): void
    {
        $coTeacher = $this->coTeacher();
        $thread = $this->thread();

        $this->fromParent($thread);
        $this->fromParent($thread, null, $this->parentB->id);
        $this->fromStaff($thread, $coTeacher);
        $this->fromStaff($thread, $this->teacher);
        $this->fromStaff($thread, $this->teacher);

        $this->assertSame(3, $this->threadUnread($thread));
        $this->assertSame(3, $this->classUnread());

        // The co-teacher wrote one of them, and sees the other four.
        $this->assertSame(4, $this->threadUnread($thread, $coTeacher));
    }

    #[Test]
    public function the_unread_boolean_keeps_its_key_and_means_the_count_is_above_zero(): void
    {
        $quiet = $this->thread(null, 'Quiet');
        $busy = $this->thread(null, 'Busy');
        $this->fromStaff($quiet, $this->teacher);
        $this->fromParent($busy);

        $rows = $this->listRows();

        $this->assertFalse($rows[$quiet->id]['unread']);
        $this->assertSame(0, $rows[$quiet->id]['unread_count']);
        $this->assertTrue($rows[$busy->id]['unread']);
        $this->assertSame(1, $rows[$busy->id]['unread_count']);
    }

    #[Test]
    public function an_account_deleted_author_still_counts_as_somebody_else(): void
    {
        $thread = $this->thread();
        GroupMessage::factory()->create([
            'masjid_id' => $thread->masjid_id, 'group_thread_id' => $thread->id,
            'author_user_id' => null, 'author_contact_id' => null, 'body' => 'From someone since removed',
        ]);

        $this->assertSame(1, $this->threadUnread($thread));
    }

    // ================================================================ 2. the floor

    #[Test]
    public function without_a_bookmark_only_messages_at_or_after_the_floor_count(): void
    {
        config(['groups.messaging.unread_since' => self::FLOOR]);
        $thread = $this->thread();

        $this->fromParent($thread, '2026-09-27 23:59:59');
        $this->fromParent($thread, '2026-09-28 00:00:00');
        $this->fromParent($thread, '2026-09-29 09:30:00');

        $this->assertSame(2, $this->threadUnread($thread));
    }

    #[Test]
    public function with_no_floor_set_every_message_counts(): void
    {
        $thread = $this->thread();
        $this->fromParent($thread, '2025-01-01 00:00:00');
        $this->fromParent($thread, '2026-09-29 09:30:00');

        $this->assertSame(2, $this->threadUnread($thread));
    }

    #[Test]
    public function the_shipped_floor_is_the_monday_literal_and_not_an_env_var(): void
    {
        $this->assertSame('2026-09-28 00:00:00', (require base_path('config/groups.php'))['messaging']['unread_since']);
        $this->assertStringNotContainsString("env('GROUP_MESSAGING_UNREAD", (string) file_get_contents(base_path('config/groups.php')));
    }

    // ================================================================ 3. the bookmark

    #[Test]
    public function a_bookmark_with_a_message_id_and_a_bookmark_with_only_a_time_are_both_honoured(): void
    {
        config(['groups.messaging.unread_since' => self::FLOOR]);
        $byId = $this->thread(null, 'By id');
        $byTime = $this->thread(null, 'By time');

        // Both bookmarks sit on a message from before the floor: with a bookmark the
        // floor no longer applies, and what is NEWER than the bookmark counts.
        $first = $this->fromParent($byId, '2026-09-01 10:00:00');
        $this->fromParent($byId, '2026-09-02 10:00:00');
        $this->fromParent($byId, '2026-09-03 10:00:00');
        $this->marker($byId, $this->teacher, (int) $first->id, '2026-09-01 10:00:00');

        $this->fromParent($byTime, '2026-09-01 10:00:00');
        $this->fromParent($byTime, '2026-09-02 10:00:00');
        $this->fromParent($byTime, '2026-09-03 10:00:00');
        $this->marker($byTime, $this->teacher, null, '2026-09-02 10:00:00');

        $rows = $this->listRows();

        $this->assertSame(2, $rows[$byId->id]['unread_count'], 'the id bookmark covers only the first message');
        $this->assertSame(1, $rows[$byTime->id]['unread_count'], 'the time bookmark covers the first two');
    }

    // ================================================================ 4. what moves it

    #[Test]
    public function opening_a_conversation_clears_that_one_and_leaves_the_others(): void
    {
        $opened = $this->thread(null, 'Opened');
        $other = $this->thread(null, 'Other');
        $this->fromParent($opened);
        $this->fromParent($opened);
        $this->fromParent($other);

        $this->assertSame(3, $this->classUnread());

        $response = $this->openLikeTheScreen($opened);
        $response->assertJsonPath('data.thread.unread_count', 0);
        $response->assertJsonPath('data.thread.unread', false);

        $rows = $this->listRows();
        $this->assertSame(0, $rows[$opened->id]['unread_count']);
        $this->assertSame(1, $rows[$other->id]['unread_count']);
        $this->assertSame(1, $this->classUnread());
    }

    #[Test]
    public function replying_to_a_conversation_you_have_opened_keeps_it_at_zero_and_one_you_have_not_stays_unread(): void
    {
        $opened = $this->thread(null, 'Opened');
        $this->fromParent($opened);
        $this->openLikeTheScreen($opened);

        $unopened = $this->thread(null, 'Never opened');
        $this->fromParent($unopened);
        $this->fromParent($unopened);

        foreach ([$opened, $unopened] as $thread) {
            $this->asTeacher()->postJson($this->teacherUrl("/threads/{$thread->id}/messages"), ['body' => 'Walaikum salaam'])
                ->assertCreated();
        }

        $this->assertSame(0, $this->threadUnread($opened));
        $this->assertSame(2, $this->threadUnread($unopened), 'a reply never reads what the teacher was not shown');
        $this->assertSame(2, $this->classUnread());
    }

    #[Test]
    public function a_new_message_from_somebody_else_raises_it_again(): void
    {
        $thread = $this->thread();
        $this->fromParent($thread);
        $this->openLikeTheScreen($thread);
        $this->assertSame(0, $this->classUnread());

        $this->fromParent($thread);

        $this->assertSame(1, $this->classUnread());
    }

    #[Test]
    public function a_co_teachers_bookmark_is_separate(): void
    {
        $coTeacher = $this->coTeacher();
        $thread = $this->thread();
        $this->fromParent($thread);

        $this->openLikeTheScreen($thread);

        $this->assertSame(0, $this->classUnread());
        $this->assertSame(1, $this->classUnread($coTeacher));
    }

    // ================================================================ 5 and 11. computing it writes nothing

    #[Test]
    public function listing_and_counting_never_create_or_move_a_bookmark(): void
    {
        $thread = $this->thread();
        $this->fromParent($thread);

        $this->listRows();
        $this->classUnread();
        $this->asTeacher()->getJson("/api/teacher/masjids/{$this->school->id}/groups")->assertOk();
        GroupThreadUnread::byGroup((int) $this->teacher->id, [(int) $this->class->id]);

        $this->assertDatabaseMissing('group_thread_reads', ['group_thread_id' => $thread->id]);
        $this->assertSame(0, GroupThreadRead::withoutMasjidScope()->count());
    }

    #[Test]
    public function an_existing_bookmark_does_not_move_when_the_list_or_class_is_read(): void
    {
        $thread = $this->thread();
        $seen = $this->fromParent($thread);
        $this->fromParent($thread);
        $this->marker($thread, $this->teacher, (int) $seen->id, '2026-09-30 10:00:00');

        $this->listRows();
        $this->classUnread();

        $read = GroupThreadRead::withoutMasjidScope()->sole();
        $this->assertSame((int) $seen->id, (int) $read->last_read_message_id);
        $this->assertSame('2026-09-30 10:00:00', $read->last_read_at->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function counting_for_another_teacher_creates_no_bookmark_so_no_receipt_reaches_a_family(): void
    {
        $thread = $this->thread();
        $this->fromStaff($thread, $this->teacher);
        $this->fromParent($thread);

        // A bookmark IS the read receipt a family sees, so the co-teacher's count
        // (and the author's) must leave the table exactly as it found it.
        $coTeacher = $this->coTeacher();
        $this->classUnread($coTeacher);
        $this->classUnread();
        $this->listRows($coTeacher);

        $this->assertSame(0, GroupThreadRead::withoutMasjidScope()->count());
    }

    // ================================================================ 6. scheduled, not sent

    #[Test]
    public function a_scheduled_conversation_adds_nothing_until_it_is_sent_and_then_counts_for_the_other_teacher(): void
    {
        Carbon::setTestNow('2026-10-01 12:00:00');
        $this->school->forceFill(['timezone' => 'America/New_York'])->save();
        $coTeacher = $this->coTeacher();

        $this->asTeacher()->postJson($this->teacherUrl('/scheduled-messages'), [
            'subject' => 'Term dates', 'scope' => GroupThread::SCOPE_GROUP,
            'body' => 'School resumes on Sunday.', 'send_at' => '2026-10-05T10:00',
        ])->assertCreated();

        $this->assertSame(GroupMessageSchedule::STATUS_SCHEDULED, GroupMessageSchedule::withoutMasjidScope()->sole()->status);
        $this->assertSame(0, $this->classUnread($coTeacher), 'an unsent conversation is not a message yet');
        $this->assertSame(0, GroupMessage::withoutMasjidScope()->count());

        Carbon::setTestNow('2026-10-05 14:00:00');
        Artisan::call('groups:publish-due');

        $this->assertSame(1, GroupMessage::withoutMasjidScope()->count());
        $this->assertSame(1, $this->classUnread($coTeacher), 'the other teacher has not seen it');
        $this->assertSame(0, $this->classUnread(), 'the author wrote it');
    }

    // ================================================================ 7. the payloads, and the query count

    #[Test]
    public function the_class_payload_carries_the_count_on_my_classes_and_on_one_class(): void
    {
        $thread = $this->thread();
        $this->fromParent($thread);
        $this->fromParent($thread);

        $list = $this->asTeacher()->getJson("/api/teacher/masjids/{$this->school->id}/groups")->assertOk();
        $this->assertSame(2, $list->json('data.0.unread_messages'));

        $this->asTeacher()->getJson($this->teacherUrl(''))->assertOk()->assertJsonPath('data.unread_messages', 2);
    }

    #[Test]
    public function my_classes_counts_all_the_classes_in_one_query(): void
    {
        $second = Group::factory()->create(['masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS, 'name' => 'Grade 2', 'slug' => 'grade-2']);
        $third = Group::factory()->create(['masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS, 'name' => 'Grade 3', 'slug' => 'grade-3']);

        foreach ([$second, $third] as $class) {
            $class->staff()->attach($this->teacher->id, [
                'masjid_id' => $this->school->id, 'role' => GroupStaff::ROLE_TEACHER, 'assigned_at' => now(),
            ]);
        }

        $this->fromParent($this->thread($this->class));
        $this->fromParent($this->thread($second));
        $this->fromParent($this->thread($second));
        $this->fromParent($this->thread($third));

        $this->asTeacher();
        DB::enableQueryLog();
        $response = $this->getJson("/api/teacher/masjids/{$this->school->id}/groups")->assertOk();
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $this->assertCount(3, $response->json('data'));
        $this->assertSame(
            ['Grade 1' => 1, 'Grade 2' => 2, 'Grade 3' => 1],
            collect($response->json('data'))->pluck('unread_messages', 'name')->all()
        );

        $counting = $queries->filter(fn (string $sql) => str_contains($sql, 'from "group_messages"'))->count();
        $this->assertSame(1, $counting, 'the unread query runs once for three classes, not once per class');
    }

    #[Test]
    public function the_thread_list_carries_a_total_for_the_whole_class_even_when_paginated(): void
    {
        $a = $this->thread(null, 'A');
        $b = $this->thread(null, 'B');
        $this->fromParent($a);
        $this->fromParent($a);
        $this->fromParent($b);

        $page = $this->asTeacher()->getJson($this->teacherUrl('/threads?per_page=1'))->assertOk();

        $this->assertCount(1, $page->json('data.data'));
        $this->assertSame(3, $page->json('meta.unread_total'));

        // A scope filter narrows the rows, not the class's number.
        $scoped = $this->asTeacher()->getJson($this->teacherUrl('/threads?scope=participant'))->assertOk();
        $this->assertSame(3, $scoped->json('meta.unread_total'));
    }

    // ================================================================ 8. deleted, and another organisation

    #[Test]
    public function a_deleted_conversation_never_counts_but_a_closed_one_does(): void
    {
        $deleted = $this->thread(null, 'Deleted');
        $closed = $this->thread(null, 'Closed');
        $this->fromParent($deleted);
        $this->fromParent($closed);
        $closed->update(['closed_at' => now()]);

        $this->assertSame(2, $this->classUnread());

        $deleted->delete();

        $this->assertSame(1, $this->classUnread());
        $this->assertArrayNotHasKey($deleted->id, $this->listRows());
    }

    #[Test]
    public function another_organisations_conversations_never_count_and_its_routes_change_nothing(): void
    {
        $foreignClass = Group::factory()->create([
            'masjid_id' => $this->otherSchool->id, 'kind' => Group::KIND_CLASS, 'name' => 'Grade 1', 'slug' => 'grade-1',
        ]);
        $foreignThread = $this->thread($foreignClass);
        $this->fromParent($foreignThread);
        $this->fromParent($foreignThread);

        $mine = $this->thread();
        $this->fromParent($mine);

        $this->assertSame(1, $this->classUnread());

        // Bound to my school, the helper cannot see the foreign class at all.
        app(TenantContext::class)->set($this->school->id);
        $this->assertSame([], GroupThreadUnread::byThread((int) $this->teacher->id, [(int) $foreignClass->id]));
        app(TenantContext::class)->forgetTenant();

        // And the teacher cannot reach it: refused, and no bookmark appears.
        foreach (['/threads', "/threads/{$foreignThread->id}"] as $path) {
            $status = $this->asTeacher()
                ->getJson("/api/teacher/masjids/{$this->otherSchool->id}/groups/{$foreignClass->id}{$path}")
                ->status();
            $this->assertContains($status, [403, 404], "a foreign class answered {$status}");
        }
        $this->assertSame(0, GroupThreadRead::withoutMasjidScope()->count());
    }

    // ================================================================ 9. the admin screen

    #[Test]
    public function an_office_user_who_cannot_read_the_class_conversations_gets_zero_not_an_error(): void
    {
        $thread = $this->thread();
        $this->fromParent($thread);

        $office = $this->makeAdmin();

        $this->asUser($office)->getJson($this->adminUrl('/threads'))->assertForbidden();
        $this->asUser($office)->getJson($this->adminUrl(''))->assertOk()->assertJsonPath('data.unread_messages', 0);
    }

    #[Test]
    public function a_leader_in_the_admin_screen_sees_the_count_and_opening_clears_it(): void
    {
        $thread = $this->thread();
        $this->fromParent($thread);
        $this->fromParent($thread);

        $leader = $this->makeLeadingAdmin();

        $this->asUser($leader)->getJson($this->adminUrl(''))->assertOk()->assertJsonPath('data.unread_messages', 2);

        $list = $this->asUser($leader)->getJson($this->adminUrl('/threads'))->assertOk();
        $this->assertSame(2, $list->json('data.data.0.unread_count'));
        $this->assertSame(2, $list->json('meta.unread_total'));

        $this->asUser($leader)->getJson($this->adminUrl("/threads/{$thread->id}"))->assertOk();

        $this->asUser($leader)->getJson($this->adminUrl(''))->assertOk()->assertJsonPath('data.unread_messages', 0);
    }

    // ================================================================ 10. long conversations

    #[Test]
    public function a_sixty_message_conversation_opened_the_way_the_screen_opens_it_ends_at_zero(): void
    {
        $thread = $this->thread();
        foreach (range(1, 60) as $i) {
            $this->fromParent($thread);
        }

        $this->assertSame(60, $this->threadUnread($thread));

        $opened = $this->openLikeTheScreen($thread);

        $this->assertCount(60, $opened->json('data.messages.data'), 'the teacher can reach every message');
        $this->assertSame(0, $opened->json('data.thread.unread_count'));
        $this->assertSame(0, $this->threadUnread($thread));
        $this->assertSame(0, $this->classUnread());
    }

    #[Test]
    public function the_default_page_still_stalls_a_long_conversation_and_the_next_page_finishes_it(): void
    {
        $thread = $this->thread();
        foreach (range(1, 60) as $i) {
            $this->fromParent($thread);
        }

        // The native apps' default: 50 messages. The bookmark moves to what was SERVED.
        $first = $this->asTeacher()->getJson($this->teacherUrl("/threads/{$thread->id}"))->assertOk();
        $this->assertCount(50, $first->json('data.messages.data'));
        $this->assertSame(10, $this->threadUnread($thread));

        $second = $this->asTeacher()->getJson($this->teacherUrl("/threads/{$thread->id}?page=2"))->assertOk();
        $this->assertCount(10, $second->json('data.messages.data'));
        $this->assertSame(0, $second->json('data.thread.unread_count'));
        $this->assertSame(0, $this->threadUnread($thread));
    }

    #[Test]
    public function a_page_larger_than_the_ceiling_is_cut_to_the_ceiling(): void
    {
        $thread = $this->thread();
        $rows = [];
        foreach (range(1, 205) as $i) {
            $rows[] = [
                'masjid_id' => $thread->masjid_id, 'group_thread_id' => $thread->id,
                'author_contact_id' => $this->parentA->id, 'body' => "m{$i}",
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        GroupMessage::withoutMasjidScope()->insert($rows);

        $page = $this->asTeacher()->getJson($this->teacherUrl("/threads/{$thread->id}?per_page=5000"))->assertOk();

        $this->assertCount(200, $page->json('data.messages.data'));
        $this->assertSame(5, $page->json('data.thread.unread_count'));
    }

    // ================================================================ risk 2: a reply from a stale screen

    #[Test]
    public function a_reply_from_a_stale_screen_does_not_carry_the_bookmark_past_a_message_it_never_showed(): void
    {
        $thread = $this->thread();
        $this->fromParent($thread);
        $this->openLikeTheScreen($thread);

        // The teacher's screen is still open; a parent writes; the teacher replies.
        $arrived = $this->fromParent($thread);
        $this->asTeacher()->postJson($this->teacherUrl("/threads/{$thread->id}/messages"), ['body' => 'Replying to the first'])
            ->assertCreated();

        $this->assertSame(1, $this->threadUnread($thread), 'the parent message that arrived meanwhile is still unread');
        $read = GroupThreadRead::withoutMasjidScope()->sole();
        $this->assertLessThan((int) $arrived->id, (int) $read->last_read_message_id);
    }

    #[Test]
    public function a_reply_with_nothing_new_in_between_advances_the_bookmark_to_the_reply(): void
    {
        $thread = $this->thread();
        $this->fromParent($thread);
        $this->openLikeTheScreen($thread);

        $reply = $this->asTeacher()->postJson($this->teacherUrl("/threads/{$thread->id}/messages"), ['body' => 'Noted'])
            ->assertCreated()->json('data.id');

        $this->assertSame((int) $reply, (int) GroupThreadRead::withoutMasjidScope()->sole()->last_read_message_id);
        $this->assertSame(0, $this->threadUnread($thread));
    }

    #[Test]
    public function a_conversation_the_teacher_opens_themselves_marks_them_as_the_reader_of_their_own_first_message(): void
    {
        $this->asTeacher()->postJson($this->teacherUrl('/threads'), [
            'subject' => 'Hello', 'scope' => GroupThread::SCOPE_GROUP, 'body' => 'Welcome to the class.',
        ])->assertCreated()->assertJsonPath('data.unread_count', 0);

        $this->assertSame(0, $this->classUnread());
        $this->assertSame(1, GroupThreadRead::withoutMasjidScope()->count());
    }
}
