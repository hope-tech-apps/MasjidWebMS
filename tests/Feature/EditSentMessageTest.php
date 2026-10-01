<?php

namespace Tests\Feature;

use App\Jobs\SendGroupNotificationJob;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupMessage;
use App\Models\GroupMessageAttachment;
use App\Models\GroupMessageEdit;
use App\Models\GroupMessageReaction;
use App\Models\GroupMessageSchedule;
use App\Models\GroupStaff;
use App\Models\GroupThread;
use App\Models\GroupThreadRead;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Editing the words of a message already sent (W7, 2026-10-01).
 *
 * What this file pins:
 *
 *   - WHO: only the AUTHOR, a staff account that can still read the thread, in
 *     either staff realm. A colleague, the office when it did not write it, a
 *     parent, a teacher no longer on the class, a message whose author account
 *     is gone, and another school are all refused with nothing changed;
 *   - WHAT: the body only, at sending's ceiling; an empty body only for a
 *     message that carries an attachment; an unchanged body is a no-op;
 *   - WHEN: not in a closed conversation; a scheduled conversation is not a
 *     message and is a 404 here;
 *   - TRACE: one append-only audit row per real edit holding the old text, the
 *     office alone can read them, and they go when their message goes;
 *   - QUIET: no notification, the thread's activity clock and everybody's read
 *     marker stay put, reactions stay;
 *   - families see `edited_at` and nothing else of an edit.
 */
class EditSentMessageTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $school;
    private Masjid $otherSchool;
    private User $teacher;
    private User $coTeacher;
    private Group $class;

    private Contact $parentA;
    private GroupMembership $childA;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        Bus::fake([SendGroupNotificationJob::class]);
        Mail::fake();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->school = $this->makeMasjid();
        $this->otherSchool = $this->makeMasjid();

        $this->teacher = $this->makeTeacher('Ustadh Bilal');
        $this->coTeacher = $this->makeTeacher('Ustadha Sumaya');

        $this->class = Group::factory()->create([
            'masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS,
            'name' => 'Grade 1', 'slug' => 'grade-1',
        ]);

        foreach ([$this->teacher, $this->coTeacher] as $staff) {
            $this->class->staff()->attach($staff->id, [
                'masjid_id' => $this->school->id,
                'role' => GroupStaff::ROLE_TEACHER,
                'assigned_at' => now(),
            ]);
        }

        [$this->parentA, $this->childA] = $this->makeFamily('Amina', 'Huda', 'Yusuf');
    }

    // ------------------------------------------------------------ the author edits

    #[Test]
    public function the_author_edits_their_message_in_the_teacher_realm_and_leaves_one_audit_row(): void
    {
        $thread = $this->privateThread();
        $message = $this->message($thread, $this->teacher, 'Salaam, Amina did well today.');

        $this->asTeacher()
            ->putJson($this->teacherUrl("/threads/{$thread->id}/messages/{$message->id}"), ['body' => 'Salaam, Amina did very well today.'])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.id', $message->id)
            ->assertJsonPath('data.body', 'Salaam, Amina did very well today.')
            ->assertJsonPath('data.is_mine', true)
            ->assertJsonPath('data.can_edit', true);

        $message->refresh();
        $this->assertSame('Salaam, Amina did very well today.', $message->body);
        $this->assertNotNull($message->edited_at);

        $edit = GroupMessageEdit::withoutMasjidScope()->sole();
        $this->assertSame('Salaam, Amina did well today.', $edit->previous_body);
        $this->assertSame($this->teacher->id, (int) $edit->editor_user_id);
        $this->assertSame($message->id, (int) $edit->group_message_id);
        $this->assertSame($this->school->id, (int) $edit->masjid_id);
        $this->assertNotNull($edit->created_at);
    }

    #[Test]
    public function the_response_carries_edited_at_as_a_timestamp_and_an_unedited_message_carries_null(): void
    {
        $thread = $this->privateThread();
        $edited = $this->message($thread, $this->teacher, 'First words');
        $this->message($thread, $this->teacher, 'Untouched');

        $this->asTeacher()
            ->putJson($this->teacherUrl("/threads/{$thread->id}/messages/{$edited->id}"), ['body' => 'Second words'])
            ->assertOk();

        $rows = $this->asTeacher()
            ->getJson($this->teacherUrl("/threads/{$thread->id}"))
            ->assertOk()
            ->json('data.messages.data');

        $this->assertNotNull($rows[0]['edited_at']);
        $this->assertNotFalse(strtotime($rows[0]['edited_at']));
        $this->assertNull($rows[1]['edited_at']);
        $this->assertArrayHasKey('edited_at', $rows[1]);
    }

    #[Test]
    public function the_office_edits_a_message_it_wrote_through_the_admin_realm(): void
    {
        $admin = $this->makeOfficeLeader();
        $thread = $this->privateThread();
        $message = $this->message($thread, $admin, 'From the office');

        $this->asUser($admin)
            ->putJson($this->adminUrl("/threads/{$thread->id}/messages/{$message->id}"), ['body' => 'From the office, corrected'])
            ->assertOk()
            ->assertJsonPath('data.body', 'From the office, corrected')
            ->assertJsonPath('data.can_edit', true);

        $this->assertSame($admin->id, (int) GroupMessageEdit::withoutMasjidScope()->sole()->editor_user_id);
    }

    #[Test]
    public function two_edits_leave_two_rows_that_rebuild_every_version(): void
    {
        $thread = $this->privateThread();
        $message = $this->message($thread, $this->teacher, 'one');
        $url = $this->teacherUrl("/threads/{$thread->id}/messages/{$message->id}");

        $this->asTeacher()->putJson($url, ['body' => 'two'])->assertOk();
        $this->asTeacher()->putJson($url, ['body' => 'three'])->assertOk();

        $this->assertSame(
            ['one', 'two'],
            GroupMessageEdit::withoutMasjidScope()->orderBy('id')->pluck('previous_body')->all()
        );
        $this->assertSame('three', $message->refresh()->body);
    }

    // ------------------------------------------------------------ who may not

    #[Test]
    public function a_co_teacher_cannot_edit_a_colleagues_message(): void
    {
        $thread = $this->privateThread();
        $message = $this->message($thread, $this->teacher, 'Mine, not yours');

        $this->asUser($this->coTeacher, ['staff'])
            ->putJson($this->teacherUrl("/threads/{$thread->id}/messages/{$message->id}"), ['body' => 'Rewritten'])
            ->assertStatus(403);

        $this->assertUntouched($message, 'Mine, not yours');
    }

    #[Test]
    public function the_office_cannot_edit_a_message_it_did_not_write(): void
    {
        $admin = $this->makeOfficeLeader();
        $thread = $this->privateThread();
        $message = $this->message($thread, $this->teacher, 'The teacher wrote this');

        // Able to read the conversation, holding `manage contacts`, still not the author.
        $this->asUser($admin)
            ->putJson($this->adminUrl("/threads/{$thread->id}/messages/{$message->id}"), ['body' => 'Rewritten by the office'])
            ->assertStatus(403);

        $this->assertUntouched($message, 'The teacher wrote this');
    }

    #[Test]
    public function nobody_edits_a_parents_message_and_the_family_realm_has_no_route(): void
    {
        $thread = $this->privateThread();
        $message = $this->parentMessage($thread, $this->parentA, 'Thank you, ustadh.');

        $this->asTeacher()
            ->putJson($this->teacherUrl("/threads/{$thread->id}/messages/{$message->id}"), ['body' => 'Not theirs'])
            ->assertStatus(403);

        $admin = $this->makeOfficeLeader();
        $this->asUser($admin)
            ->putJson($this->adminUrl("/threads/{$thread->id}/messages/{$message->id}"), ['body' => 'Not theirs'])
            ->assertStatus(403);

        $status = $this->asParent($this->parentA)
            ->putJson($this->familyUrl("/threads/{$thread->id}/messages/{$message->id}"), ['body' => 'Editing my own'])
            ->status();
        $this->assertContains($status, [404, 405]);

        $this->assertUntouched($message, 'Thank you, ustadh.');
    }

    #[Test]
    public function a_message_whose_author_account_is_gone_is_never_editable(): void
    {
        $thread = $this->privateThread();
        $leaver = $this->makeTeacher('Ustadh Gone');
        $message = $this->message($thread, $leaver, 'Said before leaving');

        $leaver->forceDelete();
        $this->assertNull($message->refresh()->author_user_id);

        $this->asTeacher()
            ->putJson($this->teacherUrl("/threads/{$thread->id}/messages/{$message->id}"), ['body' => 'Rewritten'])
            ->assertStatus(403);

        $this->assertUntouched($message, 'Said before leaving');
    }

    #[Test]
    public function an_author_removed_from_the_class_can_no_longer_edit(): void
    {
        $thread = $this->privateThread();
        $message = $this->message($thread, $this->teacher, 'Written while I taught here');

        $this->class->staff()->detach($this->teacher->id);

        $this->asTeacher()
            ->putJson($this->teacherUrl("/threads/{$thread->id}/messages/{$message->id}"), ['body' => 'Still here?'])
            ->assertStatus(403);

        $this->assertUntouched($message, 'Written while I taught here');
    }

    #[Test]
    public function an_author_who_can_no_longer_read_the_thread_cannot_edit_it(): void
    {
        // The office account wrote in a conversation as a leader; the leadership
        // then ended, so the read gate (not the route's permission) refuses.
        $admin = $this->makeOfficeLeader();
        $thread = $this->privateThread();
        $message = $this->message($thread, $admin, 'Written as a leader');

        GroupMembership::withoutMasjidScope()
            ->where('group_id', $this->class->id)
            ->where('role', GroupMembership::ROLE_LEADER)
            ->delete();

        $this->asUser($admin)
            ->putJson($this->adminUrl("/threads/{$thread->id}/messages/{$message->id}"), ['body' => 'Rewritten'])
            ->assertStatus(403);

        $this->assertUntouched($message, 'Written as a leader');
    }

    #[Test]
    public function another_schools_ids_are_a_miss_in_both_realms_and_nothing_changes(): void
    {
        $foreignClass = Group::factory()->create([
            'masjid_id' => $this->otherSchool->id, 'kind' => Group::KIND_CLASS,
            'name' => 'Grade 1', 'slug' => 'grade-1',
        ]);
        $foreignThread = GroupThread::create([
            'masjid_id' => $this->otherSchool->id, 'group_id' => $foreignClass->id,
            'subject' => 'Elsewhere', 'scope' => GroupThread::SCOPE_GROUP,
        ]);
        $foreignMessage = GroupMessage::create([
            'masjid_id' => $this->otherSchool->id, 'group_thread_id' => $foreignThread->id,
            'author_user_id' => $this->teacher->id, 'body' => 'Another school entirely',
        ]);

        $admin = $this->makeOfficeLeader();
        $path = "/groups/{$foreignClass->id}/threads/{$foreignThread->id}/messages/{$foreignMessage->id}";

        $this->asUser($admin)
            ->putJson("/api/admin/masjids/{$this->school->id}{$path}", ['body' => 'Hijacked'])
            ->assertNotFound();

        // teacher.leads refuses a class the teacher does not lead in this school.
        $status = $this->asTeacher()
            ->putJson("/api/teacher/masjids/{$this->school->id}{$path}", ['body' => 'Hijacked'])
            ->status();
        $this->assertContains($status, [403, 404]);

        // Their school's URL is a 403 from the tenant middleware.
        $this->asUser($admin)
            ->putJson("/api/admin/masjids/{$this->otherSchool->id}{$path}", ['body' => 'Hijacked'])
            ->assertStatus(403);

        $this->assertSame('Another school entirely', GroupMessage::withoutMasjidScope()->find($foreignMessage->id)->body);
        $this->assertSame(0, GroupMessageEdit::withoutMasjidScope()->count());
    }

    #[Test]
    public function a_message_id_from_another_conversation_or_another_class_is_a_miss(): void
    {
        $thread = $this->privateThread();
        $elsewhere = $this->classThread();
        $stray = $this->message($elsewhere, $this->teacher, 'In a different conversation');

        $this->asTeacher()
            ->putJson($this->teacherUrl("/threads/{$thread->id}/messages/{$stray->id}"), ['body' => 'Moved'])
            ->assertNotFound();

        $this->assertUntouched($stray, 'In a different conversation');
    }

    // ------------------------------------------------------------ what and when

    #[Test]
    public function a_closed_conversation_refuses_an_edit(): void
    {
        $thread = $this->privateThread();
        $message = $this->message($thread, $this->teacher, 'Before closing');
        $thread->forceFill(['closed_at' => now()])->save();

        $this->asTeacher()
            ->putJson($this->teacherUrl("/threads/{$thread->id}/messages/{$message->id}"), ['body' => 'After closing'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This conversation is closed; reopen it to continue.');

        $this->assertUntouched($message, 'Before closing');

        // The payload says so too, so the screen offers no Edit.
        $this->asTeacher()
            ->getJson($this->teacherUrl("/threads/{$thread->id}"))
            ->assertJsonPath('data.messages.data.0.can_edit', false)
            ->assertJsonPath('data.messages.data.0.is_mine', true);
    }

    #[Test]
    public function an_unchanged_body_is_a_no_op_that_stamps_and_audits_nothing(): void
    {
        $thread = $this->privateThread();
        $message = $this->message($thread, $this->teacher, 'Exactly this');
        $url = $this->teacherUrl("/threads/{$thread->id}/messages/{$message->id}");

        $this->asTeacher()->putJson($url, ['body' => 'Exactly this'])
            ->assertOk()
            ->assertJsonPath('data.body', 'Exactly this')
            ->assertJsonPath('data.edited_at', null);

        // Only surrounding whitespace differs: not a change either.
        $this->asTeacher()->putJson($url, ['body' => "  Exactly this \n"])->assertOk();

        $this->assertUntouched($message, 'Exactly this');
    }

    #[Test]
    public function a_difference_of_line_endings_alone_is_not_an_edit(): void
    {
        $thread = $this->privateThread();

        // Sent the way the SPA sends a message: a multipart form, whose line breaks are
        // "\r\n". It is stored with "\n".
        $id = $this->asTeacher()
            ->post($this->teacherUrl("/threads/{$thread->id}/messages"), ['body' => "Line one\r\nLine two"])
            ->assertSuccessful()
            ->json('data.id');

        $sent = GroupMessage::withoutMasjidScope()->findOrFail($id);
        $this->assertSame("Line one\nLine two", $sent->body);

        // Saved untouched from the editor, which sends "\n".
        $this->asTeacher()->putJson($this->teacherUrl("/threads/{$thread->id}/messages/{$id}"), ['body' => "Line one\nLine two"])
            ->assertOk()->assertJsonPath('data.edited_at', null);
        $this->assertUntouched($sent, "Line one\nLine two");

        // A message sent BEFORE this still holds "\r\n": the same untouched save, in
        // either line ending, writes no history row and no marker.
        $old = $this->message($thread, $this->teacher, "Old one\r\nOld two");
        $url = $this->teacherUrl("/threads/{$thread->id}/messages/{$old->id}");

        foreach (["Old one\nOld two", "Old one\r\nOld two"] as $same) {
            $this->asTeacher()->putJson($url, ['body' => $same])->assertOk()->assertJsonPath('data.edited_at', null);
        }

        $this->assertUntouched($old, "Old one\r\nOld two");

        // A change of words still leaves its one row, holding what was replaced.
        $this->asTeacher()->putJson($url, ['body' => "Old one\nOld three"])->assertOk();

        $this->assertSame("Old one\nOld three", $old->refresh()->body);
        $this->assertSame("Old one\r\nOld two", GroupMessageEdit::withoutMasjidScope()->sole()->previous_body);
    }

    #[Test]
    public function a_text_message_cannot_be_emptied_but_a_photo_message_may_lose_its_caption(): void
    {
        $thread = $this->privateThread();
        $text = $this->message($thread, $this->teacher, 'Only words');

        foreach ([null, '', '   '] as $empty) {
            $this->asTeacher()
                ->putJson($this->teacherUrl("/threads/{$thread->id}/messages/{$text->id}"), ['body' => $empty])
                ->assertStatus(422);
        }
        $this->asTeacher()
            ->putJson($this->teacherUrl("/threads/{$thread->id}/messages/{$text->id}"), [])
            ->assertStatus(422);

        $this->assertUntouched($text, 'Only words');

        $photo = $this->message($thread, $this->teacher, 'A caption');
        GroupMessageAttachment::create([
            'masjid_id' => $this->school->id, 'group_message_id' => $photo->id,
            'original_name' => 'p.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 10,
            'disk' => 'local', 'path' => 'group-messages/p.jpg',
        ]);

        // A request that does not name the words at all is not "empty them": the
        // caption stays, nothing is stamped and no earlier version is written.
        $this->asTeacher()
            ->putJson($this->teacherUrl("/threads/{$thread->id}/messages/{$photo->id}"), [])
            ->assertStatus(422)
            ->assertJsonPath('data.body.0', 'Send the words of the message.');
        $this->assertUntouched($photo, 'A caption');

        $this->asTeacher()
            ->putJson($this->teacherUrl("/threads/{$thread->id}/messages/{$photo->id}"), ['body' => ''])
            ->assertOk()
            ->assertJsonPath('data.body', '');

        $this->assertSame('', $photo->refresh()->body);
        $this->assertSame('A caption', GroupMessageEdit::withoutMasjidScope()->sole()->previous_body);
        // The attachment is untouched by an edit.
        $this->assertSame(1, GroupMessageAttachment::withoutMasjidScope()->where('group_message_id', $photo->id)->count());
    }

    #[Test]
    public function the_body_keeps_the_ceiling_sending_has(): void
    {
        config(['groups.messaging.max_message_length' => 20]);
        $thread = $this->privateThread();
        $message = $this->message($thread, $this->teacher, 'short');
        $url = $this->teacherUrl("/threads/{$thread->id}/messages/{$message->id}");

        $this->asTeacher()->putJson($url, ['body' => str_repeat('a', 21)])->assertStatus(422);
        $this->assertUntouched($message, 'short');

        $this->asTeacher()->putJson($url, ['body' => str_repeat('a', 20)])->assertOk();
        $this->assertSame(str_repeat('a', 20), $message->refresh()->body);
    }

    #[Test]
    public function nothing_but_the_words_can_be_edited(): void
    {
        $thread = $this->privateThread();
        $message = $this->message($thread, $this->teacher, 'Words');
        $url = $this->teacherUrl("/threads/{$thread->id}/messages/{$message->id}");

        $refused = [
            'images' => ['x'],
            'videos' => ['x'],
            'subject' => 'New subject',
            'scope' => 'group',
            'send_at' => '2030-01-01 10:00:00',
            'send_now' => true,
            'author_user_id' => $this->coTeacher->id,
            'author_contact_id' => $this->parentA->id,
            'masjid_id' => $this->otherSchool->id,
            'group_thread_id' => 9999,
        ];

        foreach ($refused as $field => $value) {
            $this->asTeacher()
                ->putJson($url, ['body' => 'Changed', $field => $value])
                ->assertStatus(422)
                ->assertJsonStructure(['data' => [$field]]);
        }

        $this->assertUntouched($message, 'Words');
        $this->assertSame($this->teacher->id, (int) $message->author_user_id);
    }

    #[Test]
    public function a_scheduled_conversation_is_not_a_message_and_is_a_miss_here(): void
    {
        $thread = $this->privateThread();

        $schedule = GroupMessageSchedule::create([
            'masjid_id' => $this->school->id,
            'group_id' => $this->class->id,
            'author_user_id' => $this->teacher->id,
            'kind' => GroupMessageSchedule::KIND_THREAD,
            'scope' => GroupThread::SCOPE_PARTICIPANT,
            'about_membership_id' => $this->childA->id,
            'subject' => 'Later',
            'body' => 'Waiting for its time',
            'send_at' => now()->addDay(),
            'status' => GroupMessageSchedule::STATUS_SCHEDULED,
        ]);
        $this->assertSame(0, GroupMessage::withoutMasjidScope()->count());

        $this->asTeacher()
            ->putJson($this->teacherUrl("/threads/{$thread->id}/messages/{$schedule->id}"), ['body' => 'Sent early'])
            ->assertNotFound();

        $this->assertSame('Waiting for its time', $schedule->refresh()->body);
        $this->assertSame(0, GroupMessageEdit::withoutMasjidScope()->count());
    }

    // ------------------------------------------------------------ quiet

    #[Test]
    public function an_edit_notifies_nobody(): void
    {
        $thread = $this->privateThread();
        $message = $this->message($thread, $this->teacher, 'Before');

        $this->asTeacher()
            ->putJson($this->teacherUrl("/threads/{$thread->id}/messages/{$message->id}"), ['body' => 'After'])
            ->assertOk();

        Bus::assertNotDispatched(SendGroupNotificationJob::class);
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    #[Test]
    public function an_edit_does_not_touch_the_thread_clock_or_anyones_read_marker(): void
    {
        $thread = $this->privateThread();
        $message = $this->message($thread, $this->teacher, 'Before');

        // A parent and the teacher have both opened the conversation.
        $this->asParent($this->parentA)->getJson($this->familyUrl("/threads/{$thread->id}"))->assertOk();
        $this->asTeacher()->getJson($this->teacherUrl("/threads/{$thread->id}"))->assertOk();

        // An old conversation, long quiet.
        $old = now()->subMonths(2)->startOfSecond();
        GroupThread::withoutMasjidScope()->whereKey($thread->id)->toBase()->update(['updated_at' => $old]);
        $reads = GroupThreadRead::withoutMasjidScope()->orderBy('id')->get()->map->getAttributes()->all();
        $this->assertCount(2, $reads);

        $this->asTeacher()
            ->putJson($this->teacherUrl("/threads/{$thread->id}/messages/{$message->id}"), ['body' => 'After'])
            ->assertOk();

        $this->assertSame(
            $old->toDateTimeString(),
            GroupThread::withoutMasjidScope()->find($thread->id)->updated_at->toDateTimeString(),
            'Editing must not float an old conversation to the top of every list.'
        );
        $this->assertSame(
            $reads,
            GroupThreadRead::withoutMasjidScope()->orderBy('id')->get()->map->getAttributes()->all(),
            'Editing must not move anyone\'s read marker.'
        );
    }

    #[Test]
    public function an_edited_thread_keeps_its_place_in_the_list(): void
    {
        $older = $this->privateThread();
        $olderMessage = $this->message($older, $this->teacher, 'In the older one');
        $newer = $this->classThread();
        $this->message($newer, $this->teacher, 'In the newer one');

        GroupThread::withoutMasjidScope()->whereKey($older->id)->toBase()->update(['updated_at' => now()->subDays(3)]);
        GroupThread::withoutMasjidScope()->whereKey($newer->id)->toBase()->update(['updated_at' => now()->subDay()]);

        $before = $this->asTeacher()->getJson($this->teacherUrl('/threads'))->assertOk()->json('data.data.*.id');
        $this->assertSame([$newer->id, $older->id], $before);

        $this->asTeacher()
            ->putJson($this->teacherUrl("/threads/{$older->id}/messages/{$olderMessage->id}"), ['body' => 'Edited'])
            ->assertOk();

        $this->assertSame(
            $before,
            $this->asTeacher()->getJson($this->teacherUrl('/threads'))->assertOk()->json('data.data.*.id')
        );
    }

    #[Test]
    public function reactions_and_seen_by_stay_as_they_were(): void
    {
        $thread = $this->privateThread();
        $message = $this->message($thread, $this->teacher, 'Before');

        $this->asParent($this->parentA)
            ->putJson($this->familyUrl("/threads/{$thread->id}/messages/{$message->id}/reactions/ameen"))
            ->assertOk();
        $this->asParent($this->parentA)->getJson($this->familyUrl("/threads/{$thread->id}"))->assertOk();

        $payload = $this->asTeacher()
            ->putJson($this->teacherUrl("/threads/{$thread->id}/messages/{$message->id}"), ['body' => 'After'])
            ->assertOk()
            ->json('data');

        $this->assertSame(1, GroupMessageReaction::withoutMasjidScope()->count());
        $this->assertSame(1, $payload['reactions'][0]['count']);
        $this->assertSame([['name' => 'Huda Yusuf', 'is_parent' => true]], $payload['read_by']);
    }

    // ------------------------------------------------------------ what families see

    #[Test]
    public function the_family_payload_carries_edited_at_and_nothing_else_of_an_edit(): void
    {
        $thread = $this->privateThread();
        $edited = $this->message($thread, $this->teacher, 'The earlier wording');
        $plain = $this->message($thread, $this->teacher, 'Never touched');

        $this->asTeacher()
            ->putJson($this->teacherUrl("/threads/{$thread->id}/messages/{$edited->id}"), ['body' => 'The later wording'])
            ->assertOk();

        $response = $this->asParent($this->parentA)
            ->getJson($this->familyUrl("/threads/{$thread->id}"))
            ->assertOk();

        $rows = $response->json('data.messages.data');
        $this->assertSame('The later wording', $rows[0]['body']);
        $this->assertNotNull($rows[0]['edited_at']);
        $this->assertNull($rows[1]['edited_at']);
        $this->assertArrayHasKey('edited_at', $rows[1]);

        $raw = $response->getContent();
        $this->assertStringNotContainsString('The earlier wording', $raw);
        $this->assertStringNotContainsString('previous_body', $raw);
        $this->assertStringNotContainsString('editor', $raw);
        $this->assertArrayNotHasKey('can_edit', $rows[0]);
        $this->assertSame($plain->id, $rows[1]['id']);
    }

    #[Test]
    public function the_staff_payload_never_carries_an_earlier_text(): void
    {
        $thread = $this->privateThread();
        $message = $this->message($thread, $this->teacher, 'The earlier wording');

        $this->asTeacher()
            ->putJson($this->teacherUrl("/threads/{$thread->id}/messages/{$message->id}"), ['body' => 'The later wording'])
            ->assertOk();

        $raw = $this->asTeacher()->getJson($this->teacherUrl("/threads/{$thread->id}"))->assertOk()->getContent();
        $this->assertStringNotContainsString('The earlier wording', $raw);
        $this->assertStringNotContainsString('previous_body', $raw);
    }

    #[Test]
    public function can_edit_is_true_only_on_the_viewers_own_message(): void
    {
        $thread = $this->privateThread();
        $this->message($thread, $this->teacher, 'Teacher one');
        $this->message($thread, $this->coTeacher, 'Teacher two');
        $this->parentMessage($thread, $this->parentA, 'A parent');

        $this->assertSame(
            [true, false, false],
            $this->asTeacher()->getJson($this->teacherUrl("/threads/{$thread->id}"))->json('data.messages.data.*.can_edit')
        );
        $this->assertSame(
            [false, true, false],
            $this->asUser($this->coTeacher, ['staff'])->getJson($this->teacherUrl("/threads/{$thread->id}"))->json('data.messages.data.*.can_edit')
        );
    }

    // ------------------------------------------------------------ the office reads the history

    #[Test]
    public function the_office_reads_the_earlier_versions_of_an_edited_message(): void
    {
        $admin = $this->makeOfficeLeader();
        $thread = $this->privateThread();
        $message = $this->message($thread, $this->teacher, 'v1');
        $url = $this->teacherUrl("/threads/{$thread->id}/messages/{$message->id}");

        $this->asTeacher()->putJson($url, ['body' => 'v2'])->assertOk();
        $this->asTeacher()->putJson($url, ['body' => 'v3'])->assertOk();

        $response = $this->asUser($admin)
            ->getJson($this->adminUrl("/threads/{$thread->id}/messages/{$message->id}/edits"))
            ->assertOk()
            ->assertJsonPath('data.message_id', $message->id)
            ->assertJsonPath('data.current_body', 'v3')
            ->assertJsonCount(2, 'data.edits')
            ->assertJsonPath('data.edits.0.previous_body', 'v1')
            ->assertJsonPath('data.edits.1.previous_body', 'v2')
            ->assertJsonPath('data.edits.0.edited_by', 'Ustadh Bilal');

        $this->assertArrayNotHasKey('editor_user_id', $response->json('data.edits.0'));
        $this->assertNotNull($response->json('data.edits.0.replaced_at'));

        // An unedited message has an empty history.
        $plain = $this->message($thread, $this->teacher, 'Never touched');
        $this->asUser($admin)
            ->getJson($this->adminUrl("/threads/{$thread->id}/messages/{$plain->id}/edits"))
            ->assertOk()
            ->assertJsonCount(0, 'data.edits');
    }

    #[Test]
    public function the_history_has_no_teacher_or_family_route(): void
    {
        $thread = $this->privateThread();
        $message = $this->message($thread, $this->teacher, 'v1');
        $this->asTeacher()
            ->putJson($this->teacherUrl("/threads/{$thread->id}/messages/{$message->id}"), ['body' => 'v2'])
            ->assertOk();

        $this->asTeacher()
            ->getJson($this->teacherUrl("/threads/{$thread->id}/messages/{$message->id}/edits"))
            ->assertNotFound();
        $this->asParent($this->parentA)
            ->getJson($this->familyUrl("/threads/{$thread->id}/messages/{$message->id}/edits"))
            ->assertNotFound();
    }

    #[Test]
    public function an_office_admin_who_cannot_read_the_conversation_cannot_read_its_history(): void
    {
        $admin = $this->makeAdmin();
        $thread = $this->privateThread();
        $message = $this->message($thread, $this->teacher, 'v1');
        $this->asTeacher()
            ->putJson($this->teacherUrl("/threads/{$thread->id}/messages/{$message->id}"), ['body' => 'v2'])
            ->assertOk();

        $this->asUser($admin)
            ->getJson($this->adminUrl("/threads/{$thread->id}/messages/{$message->id}/edits"))
            ->assertStatus(403)
            ->assertDontSee('v1');
    }

    #[Test]
    public function the_history_is_bound_to_its_organisation_and_its_conversation(): void
    {
        $admin = $this->makeOfficeLeader();
        $thread = $this->privateThread();
        $message = $this->message($thread, $this->teacher, 'v1');
        // Made before any request: a request leaves the tenant bound, and a model made
        // after it would be stamped with that organisation, not this one.
        $foreignClass = Group::factory()->create([
            'masjid_id' => $this->otherSchool->id, 'kind' => Group::KIND_CLASS,
            'name' => 'Grade 1', 'slug' => 'grade-1',
        ]);
        $this->asTeacher()
            ->putJson($this->teacherUrl("/threads/{$thread->id}/messages/{$message->id}"), ['body' => 'v2'])
            ->assertOk();

        $this->asUser($admin)
            ->getJson("/api/admin/masjids/{$this->school->id}/groups/{$foreignClass->id}/threads/{$thread->id}/messages/{$message->id}/edits")
            ->assertNotFound();

        $other = $this->classThread();
        $this->asUser($admin)
            ->getJson($this->adminUrl("/threads/{$other->id}/messages/{$message->id}/edits"))
            ->assertNotFound();

        $this->asUser($admin)
            ->getJson("/api/admin/masjids/{$this->otherSchool->id}/groups/{$this->class->id}/threads/{$thread->id}/messages/{$message->id}/edits")
            ->assertStatus(403);
    }

    // ------------------------------------------------------------ tenancy and append-only

    #[Test]
    public function the_edit_model_is_tenant_scoped_and_refuses_another_organisations_rows(): void
    {
        $thread = $this->privateThread();
        $message = $this->message($thread, $this->teacher, 'Mine');
        $mine = GroupMessageEdit::create([
            'masjid_id' => $this->school->id, 'group_message_id' => $message->id,
            'editor_user_id' => $this->teacher->id, 'previous_body' => 'Mine, earlier',
        ]);

        $foreignClass = Group::factory()->create([
            'masjid_id' => $this->otherSchool->id, 'kind' => Group::KIND_CLASS,
            'name' => 'Grade 1', 'slug' => 'grade-1',
        ]);
        $foreignThread = GroupThread::create([
            'masjid_id' => $this->otherSchool->id, 'group_id' => $foreignClass->id,
            'subject' => 'Elsewhere', 'scope' => GroupThread::SCOPE_GROUP,
        ]);
        $foreignMessage = GroupMessage::create([
            'masjid_id' => $this->otherSchool->id, 'group_thread_id' => $foreignThread->id, 'body' => 'Theirs',
        ]);
        $foreign = GroupMessageEdit::create([
            'masjid_id' => $this->otherSchool->id, 'group_message_id' => $foreignMessage->id,
            'previous_body' => 'Theirs, earlier',
        ]);

        app(TenantContext::class)->set($this->school->id);

        $this->assertSame(1, GroupMessageEdit::count());
        $this->assertNull(GroupMessageEdit::find($foreign->id));
        $this->assertNotNull(GroupMessageEdit::find($mine->id));

        // A bound tenant stamps its own id whatever the caller supplies.
        $stamped = GroupMessageEdit::create([
            'masjid_id' => $this->otherSchool->id, 'group_message_id' => $message->id,
            'previous_body' => 'Stamped',
        ]);
        $this->assertSame($this->school->id, (int) $stamped->masjid_id);
    }

    #[Test]
    public function an_edit_row_is_append_only_through_the_model(): void
    {
        $thread = $this->privateThread();
        $message = $this->message($thread, $this->teacher, 'Now');
        $edit = GroupMessageEdit::create([
            'masjid_id' => $this->school->id, 'group_message_id' => $message->id,
            'editor_user_id' => $this->teacher->id, 'previous_body' => 'Then',
        ]);

        try {
            $edit->update(['previous_body' => 'Rewritten history']);
            $this->fail('An edit row must not be updatable.');
        } catch (\LogicException) {
            // expected
        }

        try {
            $edit->delete();
            $this->fail('An edit row must not be deletable on its own.');
        } catch (\LogicException) {
            // expected
        }

        $this->assertSame('Then', GroupMessageEdit::withoutMasjidScope()->sole()->previous_body);
    }

    // ------------------------------------------------------------ erasure

    #[Test]
    public function purging_a_thread_removes_its_edit_rows(): void
    {
        [$thread, $message] = $this->editedMessage();
        [$keptThread, $keptMessage] = $this->editedMessage(true);
        $this->assertSame(2, GroupMessageEdit::withoutMasjidScope()->count());

        GroupThread::withoutMasjidScope()->findOrFail($thread->id)->purge();

        $this->assertDatabaseMissing('group_messages', ['id' => $message->id]);
        $this->assertSame(0, GroupMessageEdit::withoutMasjidScope()->where('group_message_id', $message->id)->count());
        $this->assertSame(1, GroupMessageEdit::withoutMasjidScope()->where('group_message_id', $keptMessage->id)->count());
    }

    #[Test]
    public function the_retention_sweep_removes_the_edit_rows_of_an_expired_conversation(): void
    {
        [$thread, $message] = $this->editedMessage();
        GroupThread::withoutMasjidScope()->whereKey($thread->id)->toBase()->update(['retained_until' => now()->subDay()->toDateString()]);

        $this->artisan('groups:purge-feed')->assertSuccessful();

        $this->assertDatabaseMissing('group_threads', ['id' => $thread->id]);
        $this->assertSame(0, GroupMessageEdit::withoutMasjidScope()->count());
    }

    #[Test]
    public function deleting_a_group_removes_the_edit_rows_of_its_conversations(): void
    {
        [, $message] = $this->editedMessage();

        Group::withTrashed()->findOrFail($this->class->id)->forceDelete();

        $this->assertDatabaseMissing('group_messages', ['id' => $message->id]);
        $this->assertSame(0, GroupMessageEdit::withoutMasjidScope()->count());
    }

    #[Test]
    public function deleting_a_message_through_the_model_removes_its_edit_rows(): void
    {
        [, $message] = $this->editedMessage();

        GroupMessage::withoutMasjidScope()->findOrFail($message->id)->delete();

        $this->assertSame(0, GroupMessageEdit::withoutMasjidScope()->count());
    }

    #[Test]
    public function erasing_the_editors_account_keeps_the_history_with_the_attribution_softened(): void
    {
        [$thread, $message] = $this->editedMessage();
        $this->assertSame($this->teacher->id, (int) GroupMessageEdit::withoutMasjidScope()->sole()->editor_user_id);

        $this->teacher->forceDelete();

        $edit = GroupMessageEdit::withoutMasjidScope()->sole();
        $this->assertNull($edit->editor_user_id);
        $this->assertSame('first words', $edit->previous_body);
    }

    // ------------------------------------------------------------ deploy window

    #[Test]
    public function before_the_migration_an_edit_refuses_with_a_503_and_loses_no_text(): void
    {
        $thread = $this->privateThread();
        $message = $this->message($thread, $this->teacher, 'Before');

        Schema::drop('group_message_edits');

        $this->asTeacher()
            ->putJson($this->teacherUrl("/threads/{$thread->id}/messages/{$message->id}"), ['body' => 'After'])
            ->assertStatus(503);

        $this->assertSame('Before', $message->refresh()->body);

        // And reading the conversation still works without the new column.
        Schema::table('group_messages', fn ($table) => $table->dropColumn('edited_at'));

        $this->asTeacher()
            ->getJson($this->teacherUrl("/threads/{$thread->id}"))
            ->assertOk()
            ->assertJsonPath('data.messages.data.0.edited_at', null);
        $this->asParent($this->parentA)
            ->getJson($this->familyUrl("/threads/{$thread->id}"))
            ->assertOk()
            ->assertJsonPath('data.messages.data.0.edited_at', null);
    }

    #[Test]
    public function the_schema_is_what_the_code_assumes(): void
    {
        $this->assertTrue(Schema::hasColumn('group_messages', 'edited_at'));
        $this->assertTrue(Schema::hasColumns('group_message_edits', [
            'masjid_id', 'group_message_id', 'editor_user_id', 'previous_body', 'created_at',
        ]));
        $this->assertFalse(Schema::hasColumn('group_message_edits', 'updated_at'));

        foreach (Schema::getIndexes('group_message_edits') as $index) {
            $this->assertLessThanOrEqual(64, strlen($index['name']), "MySQL caps an index name at 64: {$index['name']}");
        }
    }

    // ------------------------------------------------------------ helpers

    /** @return array{0: GroupThread, 1: GroupMessage} */
    private function editedMessage(bool $classWide = false): array
    {
        $thread = $classWide ? $this->classThread() : $this->privateThread();
        $message = $this->message($thread, $this->teacher, 'first words');

        $this->asTeacher()
            ->putJson($this->teacherUrl("/threads/{$thread->id}/messages/{$message->id}"), ['body' => 'second words'])
            ->assertOk();

        return [$thread, $message];
    }

    private function assertUntouched(GroupMessage $message, string $body): void
    {
        $message->refresh();

        $this->assertSame($body, $message->body);
        $this->assertNull($message->edited_at);
        $this->assertSame(0, GroupMessageEdit::withoutMasjidScope()->where('group_message_id', $message->id)->count());
    }

    private function makeMasjid(): Masjid
    {
        return Masjid::create([
            'name' => 'Edit School '.uniqid(),
            'email' => 'school-'.uniqid().'@example.test',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0,
            'crm_enabled' => true, 'org_type' => 'school',
        ]);
    }

    private function makeTeacher(string $name): User
    {
        $teacher = User::factory()->create([
            'type' => 'Teacher',
            'name' => $name,
            'phone' => '+1'.random_int(1000000000, 9999999999),
        ]);
        MasjidUser::create([
            'masjid_id' => $this->school->id, 'user_id' => $teacher->id,
            'role' => 'teacher', 'is_default' => true,
        ]);

        return $teacher;
    }

    private function makeAdmin(): User
    {
        $admin = User::factory()->create([
            'type' => 'MasjidAdmin',
            'name' => 'Office Admin',
            'phone' => '+1'.random_int(1000000000, 9999999999),
        ]);
        $this->school->user_id = $admin->id;
        $this->school->save();

        return $admin;
    }

    /** An office admin whose person leads the class: the standing that lets them read its conversations. */
    private function makeOfficeLeader(): User
    {
        $admin = $this->makeAdmin();
        $person = Contact::factory()->create(['masjid_id' => $this->school->id, 'email' => $admin->email]);
        GroupMembership::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id,
            'contact_id' => $person->id, 'role' => GroupMembership::ROLE_LEADER,
        ]);

        return $admin;
    }

    /** @return array{0: Contact, 1: GroupMembership} */
    private function makeFamily(string $childName, string $parentFirst, string $parentLast): array
    {
        $child = Contact::factory()->create([
            'masjid_id' => $this->school->id, 'first_name' => $childName, 'last_name' => $parentLast, 'email' => null,
        ]);
        $membership = GroupMembership::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id,
            'contact_id' => $child->id, 'role' => GroupMembership::ROLE_MEMBER,
        ]);

        $parent = Contact::factory()->create([
            'masjid_id' => $this->school->id, 'first_name' => $parentFirst, 'last_name' => $parentLast,
        ]);
        $parent->forceFill([
            'login_email' => 'parent-'.uniqid().'@example.test',
            'login_enabled_at' => now(),
        ])->save();

        GroupMembership::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id,
            'contact_id' => $parent->id, 'role' => GroupMembership::ROLE_GUARDIAN,
            'guardian_of_contact_id' => $child->id,
        ]);

        return [$parent->refresh(), $membership];
    }

    private function privateThread(): GroupThread
    {
        return GroupThread::create([
            'masjid_id' => $this->school->id,
            'group_id' => $this->class->id,
            'created_by_user_id' => $this->teacher->id,
            'subject' => 'About '.$this->childA->id,
            'scope' => GroupThread::SCOPE_PARTICIPANT,
            'about_membership_id' => $this->childA->id,
        ]);
    }

    private function classThread(): GroupThread
    {
        return GroupThread::create([
            'masjid_id' => $this->school->id,
            'group_id' => $this->class->id,
            'created_by_user_id' => $this->teacher->id,
            'subject' => 'Our week',
            'scope' => GroupThread::SCOPE_GROUP,
        ]);
    }

    private function message(GroupThread $thread, User $author, string $body): GroupMessage
    {
        return GroupMessage::create([
            'masjid_id' => $this->school->id,
            'group_thread_id' => $thread->id,
            'author_user_id' => $author->id,
            'body' => $body,
        ]);
    }

    private function parentMessage(GroupThread $thread, Contact $parent, string $body): GroupMessage
    {
        return GroupMessage::create([
            'masjid_id' => $this->school->id,
            'group_thread_id' => $thread->id,
            'author_contact_id' => $parent->id,
            'body' => $body,
        ]);
    }

    private function asTeacher(): self
    {
        return $this->asUser($this->teacher, ['staff']);
    }

    private function asUser(User $user, array $abilities = ['*']): self
    {
        Auth::forgetGuards();
        app(TenantContext::class)->forgetTenant();
        Sanctum::actingAs($user, $abilities);

        return $this->flushHeaders()->withHeader('Accept', 'application/json');
    }

    /** A real bearer token, so the family guard's own provider check runs. */
    private function asParent(Contact $parent): self
    {
        Auth::forgetGuards();
        app(TenantContext::class)->forgetTenant();

        return $this->flushHeaders()
            ->withHeader('Accept', 'application/json')
            ->withHeader('Authorization', 'Bearer '.$parent->createFamilyToken()->plainTextToken);
    }

    private function teacherUrl(string $path): string
    {
        return "/api/teacher/masjids/{$this->school->id}/groups/{$this->class->id}".$path;
    }

    private function familyUrl(string $path): string
    {
        return "/api/family/masjids/{$this->school->id}/groups/{$this->class->id}".$path;
    }

    private function adminUrl(string $path): string
    {
        return "/api/admin/masjids/{$this->school->id}/groups/{$this->class->id}".$path;
    }
}
