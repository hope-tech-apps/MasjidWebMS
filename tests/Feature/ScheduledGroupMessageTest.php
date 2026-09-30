<?php

namespace Tests\Feature;

use App\Enums\GroupNotificationEvent;
use App\Jobs\SendGroupNotificationJob;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupMessage;
use App\Models\GroupMessageSchedule;
use App\Models\GroupStaff;
use App\Models\GroupThread;
use App\Models\GroupThreadRead;
use App\Models\Masjid;
use App\Models\User;
use App\Services\Groups\GroupThreadWriter;
use App\Services\Groups\ScheduledSendGate;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsClassStoryFixture;
use Tests\TestCase;

/**
 * SCHEDULED NEW CONVERSATIONS (T-002.4, owner 2026-09-29).
 *
 * A teacher writes a conversation now and it is opened later. What this file pins:
 *
 *   - UNTIL ITS TIME IT IS ONLY A ROW in `group_message_schedules`: no thread, no
 *     message, no unread marker, nothing a family or a receipt can see;
 *   - AT ITS TIME the sweep writes it through the SAME writer a live conversation
 *     uses, with the tenant bound to its own school, exactly once;
 *   - THE GATES RUN AGAIN AT SEND TIME (S15): the author left the class, the account
 *     was removed, the child left or came off the roster: `failed` with a reason,
 *     and nothing is written or emailed;
 *   - only NEW conversations, text only (S11, S13); a reply or a live conversation
 *     that names a time is refused, not silently sent at once;
 *   - who sees the list, and who may edit, send now or cancel (S14): the author and
 *     the office; a co-teacher only sees;
 *   - the claims: a second sweep, a fresh claim and a stale one.
 *
 * What the sweep does with them (the send, the gates at send time, the claims) is
 * `ScheduledSweepTest`.
 *
 * Mutation-proved (see DECISIONS.md 2026-09-29, school side quest W5).
 */
class ScheduledGroupMessageTest extends TestCase
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

    private function office(bool $manage = true): User
    {
        $admin = $this->makeAdmin();
        $admin->syncRoles([]);
        $admin->givePermissionTo($manage ? ['view contacts', 'manage contacts'] : ['view contacts']);

        return $admin;
    }

    private function nothingWritten(): void
    {
        $this->assertSame(0, GroupThread::withoutMasjidScope()->withTrashed()->count(), 'a thread was written');
        $this->assertSame(0, GroupMessage::withoutMasjidScope()->count(), 'a message was written');
        Bus::assertNotDispatched(SendGroupNotificationJob::class);
    }

    // ============================================== waiting: nobody can see it

    #[Test]
    public function a_scheduled_conversation_is_only_a_schedule_row_and_no_reader_can_see_it(): void
    {
        $item = $this->schedule();

        $this->assertSame(GroupMessageSchedule::STATUS_SCHEDULED, $item->status);
        $this->assertSame(self::DUE, $item->send_at->toDateTimeString());
        $this->assertSame($this->teacher->id, (int) $item->author_user_id);
        $this->assertSame($this->school->id, (int) $item->masjid_id);

        // Not a thread, not a message, no marker, no email: the words are not in any
        // table a receipt, an unread count, a digest or a family endpoint reads.
        $this->nothingWritten();
        $this->assertSame(0, GroupThreadRead::withoutMasjidScope()->count());

        $this->assertSame([], $this->asParent($this->parentA)->getJson($this->familyUrl('/threads'))->assertOk()->json('data.data'));
        $this->assertSame([], $this->asTeacher()->getJson($this->teacherUrl('/threads'))->assertOk()->json('data.data'));
    }

    #[Test]
    public function the_response_names_the_school_clock_and_the_stored_utc_instant(): void
    {
        $response = $this->asTeacher()
            ->postJson($this->teacherUrl('/scheduled-messages'), $this->payload())
            ->assertCreated();

        $response->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.send_at_local', '2026-10-05T10:00')
            ->assertJsonPath('data.can_change', true)
            ->assertJsonPath('meta.scheduling.timezone', 'America/New_York')
            ->assertJsonPath('meta.scheduling.max_days_ahead', 30);

        $this->assertSame('2026-10-05T14:00:00+00:00', $response->json('data.send_at'));
    }

    #[Test]
    public function a_conversation_about_one_child_must_name_a_participant_of_this_class_who_is_still_in_it(): void
    {
        // Accepted: a participant of the class.
        $this->asTeacher()->postJson($this->teacherUrl('/scheduled-messages'), $this->payload($this->aboutChild()))
            ->assertCreated()->assertJsonPath('data.about.contact.first_name', 'Amina');

        $before = GroupMessageSchedule::withoutMasjidScope()->count();

        // Refused: a guardian edge (a relationship, not a person), a child of another
        // class, a child who has left, a group-wide conversation naming one, and a
        // participant conversation naming nobody.
        $guardianEdge = GroupMembership::withoutMasjidScope()->where('role', GroupMembership::ROLE_GUARDIAN)->firstOrFail();

        $otherClass = Group::factory()->create([
            'masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS, 'name' => 'Grade 2', 'slug' => 'grade-2',
        ]);
        [, $strangerChild] = $this->makeFamily('Yusuf', 'Aisha', 'Noor', $otherClass);

        $left = GroupMembership::withoutMasjidScope()->findOrFail($this->childB->id);
        $left->forceFill(['left_on' => now()->subDay()->toDateString()])->save();

        foreach ([
            $guardianEdge->id, $strangerChild->id, $this->childB->id, 999999,
        ] as $id) {
            $this->asTeacher()->postJson($this->teacherUrl('/scheduled-messages'), $this->payload($this->aboutChild(['about_membership_id' => $id])))
                ->assertStatus(422);
        }

        $this->asTeacher()->postJson($this->teacherUrl('/scheduled-messages'), $this->payload(['about_membership_id' => $this->childA->id]))
            ->assertStatus(422);
        $this->asTeacher()->postJson($this->teacherUrl('/scheduled-messages'), $this->payload(['scope' => GroupThread::SCOPE_PARTICIPANT]))
            ->assertStatus(422);

        $this->assertSame($before, GroupMessageSchedule::withoutMasjidScope()->count());
    }

    #[Test]
    public function the_time_the_words_and_the_subject_are_required_and_the_time_is_bounded(): void
    {
        foreach ([
            ['send_at' => null], ['send_at' => '2026-09-30T10:00'], ['send_at' => '2026-11-15T10:00'],
            ['send_at' => 'soon'], ['send_at' => '2026-02-31T10:00'],
            ['body' => ''], ['body' => null], ['subject' => ''], ['scope' => 'everyone'],
        ] as $bad) {
            $this->asTeacher()
                ->postJson($this->teacherUrl('/scheduled-messages'), $bad + $this->payload())
                ->assertStatus(422);
        }

        $this->assertSame(0, GroupMessageSchedule::withoutMasjidScope()->count());
    }

    #[Test]
    public function a_scheduled_conversation_is_text_only_and_a_photo_is_refused_not_dropped(): void
    {
        $this->asTeacher()
            ->post($this->teacherUrl('/scheduled-messages'), $this->payload() + [
                'images' => [UploadedFile::fake()->create('a.jpg', 5, 'image/jpeg')],
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['images']]);

        $this->asTeacher()
            ->post($this->teacherUrl('/scheduled-messages'), $this->payload() + [
                'videos' => [UploadedFile::fake()->create('a.mp4', 5, 'video/mp4')],
            ], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->assertSame(0, GroupMessageSchedule::withoutMasjidScope()->count());
    }

    #[Test]
    public function only_a_new_conversation_can_be_scheduled_a_reply_or_a_live_one_that_names_a_time_is_refused(): void
    {
        $thread = GroupThread::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id, 'created_by_user_id' => $this->teacher->id,
            'subject' => 'Open', 'scope' => GroupThread::SCOPE_GROUP,
        ]);

        // A reply that names a time would otherwise be sent AT ONCE and answered 201:
        // the client believing it scheduled something it did not.
        $this->asTeacher()
            ->postJson($this->teacherUrl("/threads/{$thread->id}/messages"), ['body' => 'Later', 'send_at' => '2026-10-05T10:00'])
            ->assertStatus(422);
        $this->asTeacher()
            ->postJson($this->teacherUrl("/threads/{$thread->id}/messages"), ['body' => 'Now', 'send_now' => true])
            ->assertStatus(422);

        // The live endpoint likewise: opening a conversation is `POST /threads`,
        // scheduling one is `POST /scheduled-messages`.
        $this->asTeacher()
            ->postJson($this->teacherUrl('/threads'), ['subject' => 'x', 'scope' => 'group', 'body' => 'x', 'send_at' => '2026-10-05T10:00'])
            ->assertStatus(422);

        $this->assertSame(0, GroupMessage::withoutMasjidScope()->count());
        $this->assertSame(1, GroupThread::withoutMasjidScope()->count());

        // And the ordinary paths are untouched.
        $this->asTeacher()->postJson($this->teacherUrl("/threads/{$thread->id}/messages"), ['body' => 'Plain reply'])->assertCreated();
        $this->asTeacher()->postJson($this->teacherUrl('/threads'), ['subject' => 'Live', 'scope' => 'group', 'body' => 'Hello'])->assertCreated();
    }

    // ============================================================ who may what

    #[Test]
    public function the_class_teachers_and_the_office_see_the_scheduled_list_and_nobody_else_does(): void
    {
        $item = $this->schedule();
        $other = Group::factory()->create([
            'masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS, 'name' => 'Grade 2', 'slug' => 'grade-2',
        ]);
        $stranger = $this->makeTeacher($this->school, $other, 'Ustadh Omar');

        // The author, and a colleague who teaches the same class.
        $this->asTeacher()->getJson($this->teacherUrl('/scheduled-messages'))
            ->assertOk()->assertJsonPath('data.data.0.id', $item->id)->assertJsonPath('data.data.0.body', 'School resumes on Sunday.');
        $this->asTeacher($this->coTeacher())->getJson($this->teacherUrl('/scheduled-messages'))
            ->assertOk()->assertJsonPath('data.data.0.id', $item->id);

        // A teacher of a different class, an administrator who cannot manage contacts.
        $this->asTeacher($stranger)->getJson($this->teacherUrl('/scheduled-messages'))->assertForbidden();
        $this->asUser($this->office(manage: false))->getJson($this->adminUrl('/scheduled-messages'))->assertForbidden();

        // The office: that it waits, when, and who wrote it, never the words (S14, point, 2026-09-30).
        $officeList = $this->asUser($this->office())->getJson($this->adminUrl('/scheduled-messages'))
            ->assertOk()
            ->assertJsonPath('data.data.0.id', $item->id)
            ->assertJsonPath('data.data.0.content_hidden', true)
            ->assertJsonPath('data.data.0.can_cancel', true)
            ->assertJsonPath('data.data.0.can_change', false)
            ->assertJsonMissingPath('data.data.0.body')
            ->assertJsonMissingPath('data.data.0.subject');
        $this->assertStringNotContainsString('School resumes on Sunday.', $officeList->getContent());

        // A parent has no route to it at all.
        $this->asParent($this->parentA)->getJson($this->familyUrl('/scheduled-messages'))->assertNotFound();
    }

    #[Test]
    public function the_controller_refuses_the_list_itself_when_a_route_lets_the_wrong_person_in(): void
    {
        // The realm's routes gate this controller (`teacher.leads`, `manage contacts`), and
        // the controller asks GroupAudience again, so a route that ever lost its
        // middleware could not widen who reads a message written about a child. Mounted
        // here WITHOUT the permission gate to prove the controller answers for itself.
        \Illuminate\Support\Facades\Route::middleware(['auth:sanctum', 'admin', 'tenant'])
            ->get('/api/admin/masjids/{masjid_id}/groups/{group_id}/ungated-scheduled', [
                \App\Http\Controllers\AdminDashboard\GroupMessageSchedulesController::class, 'index',
            ]);

        $item = $this->schedule();

        // Reaches the controller (the route lets everybody in) and is refused there.
        $this->asUser($this->office(manage: false))->getJson($this->adminUrl('/ungated-scheduled'))->assertForbidden();

        // The control: the same route, the office.
        $this->asUser($this->office())->getJson($this->adminUrl('/ungated-scheduled'))
            ->assertOk()->assertJsonPath('data.data.0.id', $item->id);
    }

    #[Test]
    public function the_list_holds_only_what_is_still_ahead(): void
    {
        $waiting = $this->schedule();
        foreach ([GroupMessageSchedule::STATUS_SENT, GroupMessageSchedule::STATUS_CANCELLED] as $done) {
            $this->schedule()->forceFill(['status' => $done])->save();
        }
        $failed = $this->schedule();
        $failed->forceFill(['status' => GroupMessageSchedule::STATUS_FAILED, 'failure_reason' => 'The child has left the class or is no longer on its roster.'])->save();

        $list = $this->asTeacher()->getJson($this->teacherUrl('/scheduled-messages'))->assertOk();

        $this->assertEqualsCanonicalizing([$waiting->id, $failed->id], array_map('intval', $list->json('data.data.*.id')));
        $byId = collect($list->json('data.data'))->keyBy('id');
        $this->assertSame('The child has left the class or is no longer on its roster.', $byId[$failed->id]['failure_reason']);
    }

    #[Test]
    public function a_co_teacher_can_see_an_item_but_cannot_edit_send_now_or_cancel_it(): void
    {
        $item = $this->schedule();
        $co = $this->coTeacher();
        $url = $this->teacherUrl("/scheduled-messages/{$item->id}");

        $this->asTeacher($co)->putJson($url, ['body' => 'Hijacked'])->assertForbidden();
        $this->asTeacher($co)->putJson($url, ['send_now' => true])->assertForbidden();
        $this->asTeacher($co)->deleteJson($url)->assertForbidden();

        $fresh = $item->fresh();
        $this->assertSame('School resumes on Sunday.', $fresh->body);
        $this->assertSame(self::DUE, $fresh->send_at->toDateTimeString());
        $this->assertSame(GroupMessageSchedule::STATUS_SCHEDULED, $fresh->status);

        $this->asTeacher($co)->getJson($this->teacherUrl('/scheduled-messages'))
            ->assertJsonPath('data.data.0.can_change', false);
    }

    #[Test]
    public function the_author_edits_and_the_office_only_cancels(): void
    {
        $item = $this->schedule();
        $url = $this->teacherUrl("/scheduled-messages/{$item->id}");

        $this->asTeacher()->putJson($url, ['subject' => 'New subject', 'body' => 'New words', 'send_at' => '2026-10-06T09:15'])
            ->assertOk()
            ->assertJsonPath('data.subject', 'New subject')
            ->assertJsonPath('data.send_at_local', '2026-10-06T09:15');
        $this->assertSame('2026-10-06 13:15:00', $item->fresh()->send_at->toDateTimeString());

        // S14 (point, 2026-09-30): the office sees that it waits and may cancel it, but neither
        // reads, rewrites, moves nor sends it now: the words stay with the class's teachers.
        $office = $this->office();
        $adminUrl = $this->adminUrl("/scheduled-messages/{$item->id}");
        $this->asUser($office)->putJson($adminUrl, ['body' => 'Office words'])->assertForbidden();
        $this->asUser($office)->putJson($adminUrl, ['send_at' => '2026-10-07T09:00'])->assertForbidden();
        $this->asUser($office)->putJson($adminUrl, ['send_now' => true])->assertForbidden();
        $this->assertSame('New words', $item->fresh()->body);
        $this->assertSame('2026-10-06 13:15:00', $item->fresh()->send_at->toDateTimeString());

        $this->asUser($office)->deleteJson($adminUrl)->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame(GroupMessageSchedule::STATUS_CANCELLED, $item->fresh()->status);
    }

    #[Test]
    public function an_office_administrator_without_manage_contacts_may_not_write_or_change_one(): void
    {
        $item = $this->schedule();
        $viewer = $this->office(manage: false);

        $this->asUser($viewer)->postJson($this->adminUrl('/scheduled-messages'), $this->payload())->assertForbidden();
        $this->asUser($viewer)->putJson($this->adminUrl("/scheduled-messages/{$item->id}"), ['body' => 'x'])->assertForbidden();
        $this->asUser($viewer)->deleteJson($this->adminUrl("/scheduled-messages/{$item->id}"))->assertForbidden();

        $this->assertSame(1, GroupMessageSchedule::withoutMasjidScope()->count());
        $this->assertSame(GroupMessageSchedule::STATUS_SCHEDULED, $item->fresh()->status);
    }

    #[Test]
    public function an_item_addressed_through_another_class_or_school_is_a_404(): void
    {
        $item = $this->schedule();

        // Another class the same teacher leads: the item is not that class's.
        $other = Group::factory()->create([
            'masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS, 'name' => 'Grade 2', 'slug' => 'grade-2',
        ]);
        $other->staff()->attach($this->teacher->id, [
            'masjid_id' => $this->school->id, 'role' => GroupStaff::ROLE_TEACHER, 'assigned_at' => now(),
        ]);

        $this->asTeacher()->putJson($this->teacherUrl("/scheduled-messages/{$item->id}", $other), ['body' => 'x'])->assertNotFound();
        $this->asTeacher()->deleteJson($this->teacherUrl("/scheduled-messages/{$item->id}", $other))->assertNotFound();

        // Another school's teacher, reaching for this school's class and item.
        $foreignClass = Group::factory()->create([
            'masjid_id' => $this->otherSchool->id, 'kind' => Group::KIND_CLASS, 'name' => 'Theirs', 'slug' => 'theirs',
        ]);
        $foreign = $this->makeTeacher($this->otherSchool, $foreignClass, 'Foreign Teacher');

        $status = $this->asTeacher($foreign)
            ->getJson("/api/teacher/masjids/{$this->school->id}/groups/{$this->class->id}/scheduled-messages")
            ->status();
        $this->assertContains($status, [403, 404]);

        $this->assertSame('School resumes on Sunday.', $item->fresh()->body);
    }

    #[Test]
    public function a_bound_tenant_cannot_read_update_or_delete_another_organizations_schedule(): void
    {
        $a = $this->makeMasjid();
        $b = $this->makeMasjid();

        $mk = function (Masjid $masjid, string $name) {
            $group = Group::factory()->create([
                'masjid_id' => $masjid->id, 'kind' => Group::KIND_CLASS, 'name' => $name, 'slug' => strtolower($name),
            ]);
            $teacher = $this->makeTeacher($masjid, $group, 'Teacher '.$name);

            return GroupMessageSchedule::create([
                'masjid_id' => $masjid->id, 'group_id' => $group->id, 'author_user_id' => $teacher->id,
                'scope' => GroupThread::SCOPE_GROUP, 'subject' => 'S '.$name, 'body' => 'Body '.$name,
                'send_at' => now()->addDay(),
            ]);
        };

        $inA = $mk($a, 'Alpha');
        $inB = $mk($b, 'Beta');

        app(TenantContext::class)->set($a->id);

        $this->assertNull(GroupMessageSchedule::find($inB->id));
        $this->assertSame(0, GroupMessageSchedule::query()->where('id', $inB->id)->update(['body' => 'Hijacked']));
        $this->assertSame(0, GroupMessageSchedule::query()->where('id', $inB->id)->delete());
        $this->assertSame(1, GroupMessageSchedule::query()->count());

        // create() stamps the BOUND tenant over a client-supplied masjid_id.
        $stamped = GroupMessageSchedule::create([
            'masjid_id' => $b->id, 'group_id' => $inA->group_id, 'author_user_id' => $inA->author_user_id,
            'scope' => GroupThread::SCOPE_GROUP, 'subject' => 's', 'body' => 'b', 'send_at' => now()->addDay(),
        ]);
        $this->assertSame($a->id, (int) $stamped->masjid_id);

        app(TenantContext::class)->forgetTenant();
        $this->assertSame('Body Beta', GroupMessageSchedule::withoutMasjidScope()->findOrFail($inB->id)->body);
    }

    // ================================================================ the send

    #[Test]
    public function a_form_encoded_client_can_send_now_with_the_string_true(): void
    {
        $item = $this->schedule();

        $this->asTeacher()
            ->put($this->teacherUrl("/scheduled-messages/{$item->id}"), ['send_now' => 'true'])
            ->assertOk();
        $this->assertSame(self::NOW, $item->fresh()->send_at->toDateTimeString());

        $this->asTeacher()
            ->post($this->teacherUrl('/scheduled-messages'), $this->payload(['subject' => 'Form encoded']))
            ->assertCreated();
    }

    #[Test]
    public function a_time_and_send_now_together_are_refused(): void
    {
        $item = $this->schedule();

        $this->asTeacher()
            ->putJson($this->teacherUrl("/scheduled-messages/{$item->id}"), ['send_at' => '2026-10-06T10:00', 'send_now' => true])
            ->assertStatus(422);
    }

    #[Test]
    public function an_item_that_is_being_sent_or_has_been_sent_or_cancelled_can_no_longer_be_changed(): void
    {
        foreach ([GroupMessageSchedule::STATUS_SENDING, GroupMessageSchedule::STATUS_SENT, GroupMessageSchedule::STATUS_CANCELLED] as $status) {
            $item = $this->schedule(null, ['subject' => $status]);
            $item->forceFill(['status' => $status])->save();
            $url = $this->teacherUrl("/scheduled-messages/{$item->id}");

            $this->asTeacher()->putJson($url, ['body' => 'Too late'])->assertStatus(422);
            $this->asTeacher()->putJson($url, ['send_now' => true])->assertStatus(422);
            $this->asTeacher()->deleteJson($url)->assertStatus(422);

            $fresh = $item->fresh();
            $this->assertSame($status, $fresh->status);
            $this->assertSame('School resumes on Sunday.', $fresh->body);
        }
    }

    #[Test]
    public function editing_does_not_change_who_it_is_for(): void
    {
        $item = $this->schedule(null, $this->aboutChild());

        $this->asTeacher()->putJson($this->teacherUrl("/scheduled-messages/{$item->id}"), [
            'scope' => GroupThread::SCOPE_GROUP, 'about_membership_id' => $this->childB->id, 'author_user_id' => 999,
        ])->assertOk();

        $fresh = $item->fresh();
        $this->assertSame(GroupThread::SCOPE_PARTICIPANT, $fresh->scope);
        $this->assertSame($this->childA->id, (int) $fresh->about_membership_id);
        $this->assertSame($this->teacher->id, (int) $fresh->author_user_id);
    }

    // ================================================================= retention

    #[Test]
    public function the_retention_window_follows_the_send_day_and_the_purge_takes_only_finished_rows(): void
    {
        config(['groups.messaging.retention_days' => 365]);
        $item = $this->schedule();

        $this->assertSame('2027-10-05', $item->retained_until->toDateString());

        $sent = $this->schedule(null, ['subject' => 'Sent long ago']);
        $sent->forceFill(['status' => 'sent', 'retained_until' => '2026-01-01'])->save();
        $failed = $this->schedule(null, ['subject' => 'Failed long ago']);
        $failed->forceFill(['status' => 'failed', 'retained_until' => '2026-01-01'])->save();
        $waiting = $this->schedule(null, ['subject' => 'Still waiting']);
        $waiting->forceFill(['retained_until' => '2026-01-01'])->save();   // however odd its window
        $sending = $this->schedule(null, ['subject' => 'Being sent']);
        $sending->forceFill(['status' => 'sending', 'retained_until' => '2026-01-01'])->save();

        Artisan::call('groups:purge-feed', ['--dry-run' => true]);
        $this->assertSame(5, GroupMessageSchedule::withoutMasjidScope()->count());

        Artisan::call('groups:purge-feed');

        $this->assertNull(GroupMessageSchedule::withoutMasjidScope()->find($sent->id));
        $this->assertNull(GroupMessageSchedule::withoutMasjidScope()->find($failed->id));
        $this->assertNotNull(GroupMessageSchedule::withoutMasjidScope()->find($waiting->id));
        $this->assertNotNull(GroupMessageSchedule::withoutMasjidScope()->find($sending->id));
        $this->assertNotNull(GroupMessageSchedule::withoutMasjidScope()->find($item->id));
    }

    // ==================================================================== schema

    #[Test]
    public function the_columns_have_the_right_types_and_every_index_name_fits_mysql(): void
    {
        $columns = collect(Schema::getColumns('group_message_schedules'))->keyBy('name');

        $this->assertStringContainsString('text', strtolower($columns['body']['type']), 'the words are text, not varchar: SQLite would not notice the difference');
        $this->assertStringContainsString('varchar', strtolower($columns['subject']['type']));
        $this->assertStringContainsString('varchar', strtolower($columns['status']['type']));
        $this->assertStringContainsString('datetime', strtolower($columns['send_at']['type']), 'a timestamp() would carry MySQL\'s implicit ON UPDATE');
        $this->assertTrue($columns['sent_thread_id']['nullable']);
        $this->assertTrue($columns['author_user_id']['nullable']);
        $this->assertTrue($columns['about_membership_id']['nullable']);

        foreach (Schema::getIndexes('group_message_schedules') as $index) {
            $this->assertLessThanOrEqual(64, strlen($index['name']), "MySQL caps an index name at 64: {$index['name']}");
        }
    }

    #[Test]
    public function the_migration_rolls_back_and_forward_cleanly(): void
    {
        $migration = require database_path('migrations/2026_10_04_110000_create_group_message_schedules_table.php');

        $migration->down();
        $this->assertFalse(Schema::hasTable('group_message_schedules'));

        $migration->up();
        $this->assertTrue(Schema::hasTable('group_message_schedules'));
    }

    #[Test]
    public function the_staging_scrub_covers_the_words_a_schedule_holds(): void
    {
        $policy = config('staging_scrub.anonymise.group_message_schedules');

        $this->assertSame('free_text', $policy['body'] ?? null);
        $this->assertSame('free_text', $policy['subject'] ?? null);
    }

    #[Test]
    public function deleting_the_class_takes_its_schedules_with_it(): void
    {
        $item = $this->schedule();

        $this->class->forceDelete();

        $this->assertNull(GroupMessageSchedule::withoutMasjidScope()->find($item->id));
        $this->assertSame(0, DB::table('group_message_schedules')->count());
    }

    // ============================== the update path holds the same bounds as create

    #[Test]
    public function rescheduling_a_conversation_is_held_to_the_same_bounds_as_scheduling_it(): void
    {
        $item = $this->schedule();
        $url = $this->teacherUrl("/scheduled-messages/{$item->id}");

        foreach ([
            'in the past' => '2026-09-30T10:00',
            'right now' => '2026-10-01T08:00',
            'after 30 days' => '2026-10-31T08:01',
            'a day that does not exist' => '2026-09-31T10:00',
            'not a date' => 'soon',
        ] as $why => $value) {
            $this->asTeacher()->putJson($url, ['send_at' => $value])
                ->assertStatus(422)->assertJsonStructure(['data' => ['send_at']]);
            $this->assertSame(self::DUE, $item->fresh()->send_at->toDateTimeString(), "{$why} moved the conversation");
        }

        // The office's realm asks the same.
        $this->asUser($this->office())
            ->putJson($this->adminUrl("/scheduled-messages/{$item->id}"), ['send_at' => '2026-09-30T10:00'])
            ->assertStatus(422)->assertJsonStructure(['data' => ['send_at']]);
        $this->assertSame(self::DUE, $item->fresh()->send_at->toDateTimeString());

        // The last allowed minute.
        $this->asTeacher()->putJson($url, ['send_at' => '2026-10-31T07:59'])->assertOk();
    }

    #[Test]
    public function rescheduling_moves_the_retention_window_to_the_new_send_day(): void
    {
        config(['groups.messaging.retention_days' => 365]);
        $item = $this->schedule();
        $this->assertSame('2027-10-05', $item->retained_until->toDateString());

        $this->asTeacher()
            ->putJson($this->teacherUrl("/scheduled-messages/{$item->id}"), ['send_at' => '2026-10-20T09:00'])
            ->assertOk();

        // 20 Oct 2026 + 365 days, counted from the day it goes out, not from the edit.
        $this->assertSame('2027-10-20', $item->fresh()->retained_until->toDateString());
    }

    // ============================== the Scheduled list is ONE page holding everything

    #[Test]
    public function the_scheduled_list_holds_every_pending_conversation_so_the_fifty_first_can_still_be_cancelled(): void
    {
        app(TenantContext::class)->forgetTenant();

        $ids = [];
        for ($i = 1; $i <= 52; $i++) {
            $ids[] = GroupMessageSchedule::create([
                'masjid_id' => $this->school->id, 'group_id' => $this->class->id, 'author_user_id' => $this->teacher->id,
                'scope' => GroupThread::SCOPE_GROUP, 'subject' => "Conversation {$i}", 'body' => 'Words',
                'send_at' => now()->addDays(2)->addMinutes($i), 'status' => GroupMessageSchedule::STATUS_SCHEDULED,
            ])->id;
        }

        $list = $this->asTeacher()->getJson($this->teacherUrl('/scheduled-messages'))->assertOk();

        $this->assertSame($ids, array_map('intval', $list->json('data.data.*.id')), 'the list stopped at one page');
        $this->assertSame(52, $list->json('data.total'));
        $this->assertSame(1, $list->json('data.last_page'));

        $this->asTeacher()->deleteJson($this->teacherUrl("/scheduled-messages/{$ids[50]}"))
            ->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    // ============================ a new time or "Send now" asks the gates again, at once

    #[Test]
    public function a_new_time_or_send_now_for_an_author_who_left_is_refused_with_the_way_out_and_changes_nothing(): void
    {
        $item = $this->schedule();
        GroupStaff::withoutMasjidScope()->where('user_id', $this->teacher->id)->where('group_id', $this->class->id)->delete();

        // Fail it the way the sweep does.
        Carbon::setTestNow(self::DUE);
        Artisan::call('groups:publish-due');
        $this->assertSame(GroupMessageSchedule::STATUS_FAILED, $item->fresh()->status);

        // Since S14 (point, 2026-09-30) changing a conversation needs its words, so the one who can
        // reach this gate is an office administrator who ALSO teaches the class. The office alone is
        // refused before the gate (the_author_edits_and_the_office_only_cancels).
        $office = $this->office();
        $this->class->staff()->attach($office->id, [
            'masjid_id' => $this->school->id,
            'role' => GroupStaff::ROLE_TEACHER,
            'assigned_at' => now(),
        ]);
        $url = $this->adminUrl("/scheduled-messages/{$item->id}");
        Carbon::setTestNow(self::NOW);

        foreach ([['send_at' => '2026-10-06T10:00'], ['send_now' => true]] as $move) {
            $response = $this->asUser($office)->putJson($url, $move)->assertStatus(422);
            $this->assertStringContainsString('no longer teaches this class', $response->json('data.send_at.0'));
            $this->assertStringContainsString('cancel it and write it again', $response->json('data.send_at.0'));
        }

        $fresh = $item->fresh();
        $this->assertSame(GroupMessageSchedule::STATUS_FAILED, $fresh->status, 'a refused conversation was put back in the queue');
        $this->assertSame('The author no longer teaches this class.', $fresh->failure_reason);

        // A text edit alone is still allowed, and cancelling is the way out.
        $this->asUser($office)->putJson($url, ['body' => 'Rewritten'])->assertOk();
        $this->asUser($office)->deleteJson($url)->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    #[Test]
    public function a_new_time_for_a_conversation_about_a_child_who_left_is_refused_at_once(): void
    {
        $item = $this->schedule(null, $this->aboutChild());
        GroupMembership::withoutMasjidScope()->whereKey($this->childA->id)->update(['left_on' => now()->subDay()->toDateString()]);

        $response = $this->asTeacher()
            ->putJson($this->teacherUrl("/scheduled-messages/{$item->id}"), ['send_at' => '2026-10-06T10:00'])
            ->assertStatus(422);

        $this->assertStringContainsString('child', $response->json('data.send_at.0'));
        $this->assertSame(self::DUE, $item->fresh()->send_at->toDateTimeString());
    }

    // ================================================ what the list offers a sending item

    #[Test]
    public function a_conversation_being_sent_is_not_offered_edit_or_cancel(): void
    {
        $item = $this->schedule();

        $this->asTeacher()->getJson($this->teacherUrl('/scheduled-messages'))
            ->assertJsonPath('data.data.0.can_change', true);

        $item->forceFill(['status' => GroupMessageSchedule::STATUS_SENDING])->save();

        $this->asTeacher()->getJson($this->teacherUrl('/scheduled-messages'))
            ->assertJsonPath('data.data.0.status', 'sending')
            ->assertJsonPath('data.data.0.can_change', false);
    }

    // ============================================================ the purge window

    #[Test]
    public function the_purge_honours_each_finished_rows_own_window_and_keeps_a_failed_row_inside_it(): void
    {
        $finish = function (string $subject, string $status, ?string $until): GroupMessageSchedule {
            $row = $this->schedule(null, ['subject' => $subject]);
            $row->forceFill(['status' => $status, 'retained_until' => $until])->save();

            return $row;
        };

        $sentPast = $finish('Sent, window closed', GroupMessageSchedule::STATUS_SENT, '2026-01-01');
        $cancelledPast = $finish('Cancelled, window closed', GroupMessageSchedule::STATUS_CANCELLED, '2026-01-01');
        $failedPast = $finish('Failed, window closed', GroupMessageSchedule::STATUS_FAILED, '2026-01-01');
        $sentFuture = $finish('Sent, window open', GroupMessageSchedule::STATUS_SENT, '2027-01-01');
        $failedFuture = $finish('Failed, window open', GroupMessageSchedule::STATUS_FAILED, '2027-01-01');
        $cancelledNull = $finish('Cancelled, no window', GroupMessageSchedule::STATUS_CANCELLED, null);
        $failedNull = $finish('Failed, no window', GroupMessageSchedule::STATUS_FAILED, null);

        Artisan::call('groups:purge-feed');

        foreach ([$sentPast, $cancelledPast, $failedPast] as $gone) {
            $this->assertNull(GroupMessageSchedule::withoutMasjidScope()->find($gone->id), "{$gone->subject} outlived its window");
        }
        foreach ([$sentFuture, $failedFuture, $cancelledNull, $failedNull] as $kept) {
            $this->assertNotNull(GroupMessageSchedule::withoutMasjidScope()->find($kept->id), "{$kept->subject} was purged inside its window");
        }
    }

    // ============== the child is resolved once (the point's W5 review, item 1)

    #[Test]
    public function a_child_who_leaves_between_the_gate_and_the_write_fails_the_item_and_never_nudges_the_class(): void
    {
        $item = $this->schedule(null, $this->aboutChild());

        // The race: the sweep's own look at the child finds nobody (they left a moment
        // ago). Before the fix the write resolved the child a second time, got null, and
        // opened a participant conversation about nobody, whose notice went to the whole
        // class. Now that one answer is the one the write uses, so the item fails.
        $gate = \Mockery::mock(ScheduledSendGate::class, [app(\App\Support\GroupAudience::class), app(GroupThreadWriter::class)])->makePartial();
        $gate->shouldReceive('aboutMembership')->andReturnNull();
        $this->app->instance(ScheduledSendGate::class, $gate);

        Carbon::setTestNow(self::DUE);
        Artisan::call('groups:publish-due');

        $fresh = $item->fresh();
        $this->assertSame(GroupMessageSchedule::STATUS_FAILED, $fresh->status);
        $this->assertNull($fresh->sent_thread_id);
        $this->assertSame(0, GroupThread::withoutMasjidScope()->where('group_id', $this->class->id)->count(), 'no conversation about nobody');
        Bus::assertNotDispatched(SendGroupNotificationJob::class);
    }

    #[Test]
    public function the_writer_refuses_a_conversation_about_one_child_with_no_child(): void
    {
        app(TenantContext::class)->set($this->school->id);

        try {
            app(GroupThreadWriter::class)->open($this->class, (int) $this->teacher->id, 'About nobody', GroupThread::SCOPE_PARTICIPANT, null, 'Words');
            $this->fail('a participant conversation with no participant was written');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('needs that child', $e->getMessage());
        }

        $this->assertSame(0, GroupThread::withoutMasjidScope()->where('group_id', $this->class->id)->count());
        Bus::assertNotDispatched(SendGroupNotificationJob::class);
    }

    #[Test]
    public function a_transient_database_error_while_writing_hands_the_item_back_and_the_next_run_sends_it(): void
    {
        // The point's W5 review, item 3: a deadlock used to fail the item for good.
        $item = $this->schedule();

        $pdo = new \PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock');
        $pdo->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'];
        $deadlock = new \Illuminate\Database\QueryException('mysql', 'insert into group_threads ...', [], $pdo);

        $writer = \Mockery::mock(GroupThreadWriter::class)->makePartial();
        $writer->shouldReceive('open')->once()->andThrow($deadlock)->ordered();
        $writer->shouldReceive('open')->passthru()->ordered();
        $this->app->instance(GroupThreadWriter::class, $writer);

        Carbon::setTestNow(self::DUE);
        Artisan::call('groups:publish-due');

        $this->assertSame(GroupMessageSchedule::STATUS_SCHEDULED, $item->fresh()->status, 'a deadlock is not a failure');
        $this->assertNull($item->fresh()->failure_reason);
        $this->assertSame(0, GroupThread::withoutMasjidScope()->where('group_id', $this->class->id)->count(), 'the rolled-back write left nothing');

        Carbon::setTestNow(Carbon::parse(self::DUE)->addMinute());
        Artisan::call('groups:publish-due');

        $this->assertSame(GroupMessageSchedule::STATUS_SENT, $item->fresh()->status);
        $this->assertSame(1, GroupThread::withoutMasjidScope()->where('group_id', $this->class->id)->count());
    }

    #[Test]
    public function any_other_error_while_writing_fails_the_item_with_a_reason_the_author_sees(): void
    {
        $item = $this->schedule();

        $writer = \Mockery::mock(GroupThreadWriter::class)->makePartial();
        $writer->shouldReceive('open')->andThrow(new \RuntimeException('disk full'));
        $this->app->instance(GroupThreadWriter::class, $writer);

        Carbon::setTestNow(self::DUE);
        Artisan::call('groups:publish-due');

        $fresh = $item->fresh();
        $this->assertSame(GroupMessageSchedule::STATUS_FAILED, $fresh->status);
        $this->assertStringContainsString('could not be sent', (string) $fresh->failure_reason);
        // The author's Scheduled list shows it, with the reason.
        $this->asTeacher()->getJson($this->teacherUrl('/scheduled-messages'))
            ->assertOk()->assertJsonPath('data.data.0.status', 'failed');
    }

    #[Test]
    public function a_roster_merge_treats_a_conversation_scheduled_about_a_child_as_a_record_about_them(): void
    {
        // The point's W5 review, e: without this, a merge could drop the child's row and the
        // pending conversation would fail silently at its time.
        $item = $this->schedule(null, $this->aboutChild());
        $membership = GroupMembership::withoutMasjidScope()->findOrFail($this->childA->id);
        $merge = app(\App\Services\Groups\RosterMergeService::class);
        $carries = new \ReflectionMethod($merge, 'carriesRecordsAboutAChild');

        $this->assertTrue($carries->invoke($merge, $membership), 'a waiting conversation about the child is a record');

        $item->forceFill(['status' => GroupMessageSchedule::STATUS_FAILED])->save();
        $this->assertTrue($carries->invoke($merge, $membership), 'a failed one can be moved again, so it is still a record');

        $item->forceFill(['status' => GroupMessageSchedule::STATUS_CANCELLED])->save();
        $this->assertFalse($carries->invoke($merge, $membership), 'a cancelled one is not');
    }
}
