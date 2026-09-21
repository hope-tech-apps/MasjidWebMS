<?php

namespace Tests\Feature;

use App\Jobs\SendGroupNotificationJob;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupMessage;
use App\Models\GroupMessageReaction;
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
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Reactions (🤲 👍 💯 ❓) and read receipts on teacher <-> family messages
 * (owner, 2026-09-21).
 *
 * What this file pins:
 *
 *   - the set is FOUR keys and the server refuses anything else, in every realm;
 *   - one of each per person per message; PUT adds, DELETE removes, both
 *     idempotent;
 *   - a reaction goes through REPLYING's gate: another family's private
 *     conversation is a 403 with nothing written and nothing of theirs in the
 *     response, a message from another conversation is a 404, a class-wide
 *     conversation needs the feed consent it always needed, a closed one takes
 *     nothing, and another school's ids are a miss;
 *   - receipts are the bookmark, moved only by OPENING a thread (never by the
 *     list), and only up to the messages that were actually served;
 *   - a teacher and the office see every name; a parent sees the school's names
 *     and never another parent's — counted, not named, for reactions; absent
 *     for reading.
 */
class GroupMessageReactionsTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $school;
    private Masjid $otherSchool;
    private User $teacher;
    private Group $class;

    private Contact $parentA;
    private GroupMembership $childA;
    private Contact $parentB;
    private GroupMembership $childB;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        Bus::fake([SendGroupNotificationJob::class]);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->school = $this->makeMasjid();
        $this->otherSchool = $this->makeMasjid();

        $this->teacher = User::factory()->create([
            'type' => 'Teacher',
            'name' => 'Ustadh Bilal',
            'phone' => '+1'.random_int(1000000000, 9999999999),
        ]);
        MasjidUser::create([
            'masjid_id' => $this->school->id, 'user_id' => $this->teacher->id,
            'role' => 'teacher', 'is_default' => true,
        ]);

        $this->class = Group::factory()->create([
            'masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS,
            'name' => 'Grade 1', 'slug' => 'grade-1',
        ]);
        $this->class->staff()->attach($this->teacher->id, [
            'masjid_id' => $this->school->id,
            'role' => GroupStaff::ROLE_TEACHER,
            'assigned_at' => now(),
        ]);

        [$this->parentA, $this->childA] = $this->makeFamily('Amina', 'Huda', 'Yusuf');
        [$this->parentB, $this->childB] = $this->makeFamily('Zayd', 'Maryam', 'Karimi');
    }

    // ------------------------------------------------------------ the set

    #[Test]
    public function the_payload_carries_all_four_reactions_in_order(): void
    {
        $thread = $this->privateThread();
        $this->teacherMessage($thread);

        $keys = $this->asTeacher()
            ->getJson($this->teacherUrl("/threads/{$thread->id}"))
            ->assertOk()
            ->assertJsonPath('meta.reactions.0.emoji', '🤲')
            ->json('data.messages.data.0.reactions.*.key');

        $this->assertSame(['ameen', 'thumbs_up', 'hundred', 'question'], $keys);
        $this->assertSame(['🤲', '👍', '💯', '❓'], array_values(GroupMessageReaction::REACTIONS));
    }

    #[Test]
    public function a_reaction_outside_the_four_is_refused_in_every_realm(): void
    {
        $thread = $this->privateThread();
        $message = $this->teacherMessage($thread);

        foreach (['heart', 'AMEEN', '🤲', '🎉', 'ameen ', 'thumbs-up'] as $bad) {
            $this->asTeacher()
                ->putJson($this->teacherUrl("/threads/{$thread->id}/messages/{$message->id}/reactions/".rawurlencode($bad)))
                ->assertStatus(422)
                ->assertJsonStructure(['data' => ['reaction']]);

            $this->asParent($this->parentA)
                ->putJson($this->familyUrl("/threads/{$thread->id}/messages/{$message->id}/reactions/".rawurlencode($bad)))
                ->assertStatus(422);
        }

        $this->assertSame(0, GroupMessageReaction::withoutMasjidScope()->count());
    }

    #[Test]
    public function the_model_refuses_a_key_outside_the_set_even_when_a_controller_would_not(): void
    {
        $message = $this->teacherMessage($this->privateThread());

        $this->expectException(\LogicException::class);

        GroupMessageReaction::create([
            'masjid_id' => $this->school->id,
            'group_message_id' => $message->id,
            'reaction' => 'heart',
            'user_id' => $this->teacher->id,
        ]);
    }

    // ------------------------------------------------------------ toggling

    #[Test]
    public function a_teacher_reacts_once_and_can_take_it_back(): void
    {
        $thread = $this->privateThread();
        $message = $this->parentMessage($thread, $this->parentA);
        $url = $this->teacherUrl("/threads/{$thread->id}/messages/{$message->id}/reactions/ameen");

        $this->asTeacher()->putJson($url)
            ->assertOk()
            ->assertJsonPath('data.reactions.0.key', 'ameen')
            ->assertJsonPath('data.reactions.0.count', 1)
            ->assertJsonPath('data.reactions.0.mine', true);

        // A second tap (or a second tab) does not add a second row.
        $this->asTeacher()->putJson($url)->assertOk()->assertJsonPath('data.reactions.0.count', 1);
        $this->assertSame(1, GroupMessageReaction::withoutMasjidScope()->count());

        $row = GroupMessageReaction::withoutMasjidScope()->sole();
        $this->assertSame($this->teacher->id, (int) $row->user_id);
        $this->assertNull($row->contact_id);
        $this->assertSame($this->school->id, (int) $row->masjid_id);

        $this->asTeacher()->deleteJson($url)
            ->assertOk()
            ->assertJsonPath('data.reactions.0.count', 0)
            ->assertJsonPath('data.reactions.0.mine', false);
        $this->asTeacher()->deleteJson($url)->assertOk();

        $this->assertSame(0, GroupMessageReaction::withoutMasjidScope()->count());

        // A reaction is an acknowledgement, not a message: nobody is notified.
        Bus::assertNotDispatched(SendGroupNotificationJob::class);
    }

    #[Test]
    public function one_person_may_use_several_different_reactions_on_one_message(): void
    {
        $thread = $this->privateThread();
        $message = $this->teacherMessage($thread);

        foreach (['ameen', 'hundred'] as $key) {
            $this->asParent($this->parentA)
                ->putJson($this->familyUrl("/threads/{$thread->id}/messages/{$message->id}/reactions/{$key}"))
                ->assertOk();
        }

        $this->assertSame(2, GroupMessageReaction::withoutMasjidScope()->where('contact_id', $this->parentA->id)->count());
    }

    #[Test]
    public function a_parent_reacts_to_the_teacher_and_the_teacher_sees_who(): void
    {
        $thread = $this->privateThread();
        $message = $this->teacherMessage($thread);

        // Sent the way the portal's axios instance can send it: a bare PUT, no
        // JSON body at all.
        $this->asParent($this->parentA)
            ->put($this->familyUrl("/threads/{$thread->id}/messages/{$message->id}/reactions/ameen"))
            ->assertOk()
            ->assertJsonPath('data.reactions.0.mine', true);

        $row = GroupMessageReaction::withoutMasjidScope()->sole();
        $this->assertSame($this->parentA->id, (int) $row->contact_id);
        $this->assertNull($row->user_id);

        $this->asTeacher()
            ->getJson($this->teacherUrl("/threads/{$thread->id}"))
            ->assertOk()
            ->assertJsonPath('data.messages.data.0.reactions.0.count', 1)
            ->assertJsonPath('data.messages.data.0.reactions.0.mine', false)
            ->assertJsonPath('data.messages.data.0.reactions.0.by.0.name', 'Huda Yusuf')
            ->assertJsonPath('data.messages.data.0.reactions.0.by.0.is_parent', true);
    }

    // ------------------------------------------------ who may react at all

    #[Test]
    public function another_family_cannot_react_in_a_private_conversation_and_learns_nothing(): void
    {
        $thread = $this->privateThread();
        $message = $this->teacherMessage($thread, 'Amina had a hard morning — can we talk?');
        // Somebody has already reacted, so a leak would have something to show.
        $this->asTeacher()
            ->putJson($this->teacherUrl("/threads/{$thread->id}/messages/{$message->id}/reactions/question"))
            ->assertOk();

        foreach (['putJson', 'deleteJson'] as $verb) {
            $response = $this->asParent($this->parentB)
                ->{$verb}($this->familyUrl("/threads/{$thread->id}/messages/{$message->id}/reactions/ameen"))
                ->assertStatus(403);

            $response->assertJsonMissingPath('data');
            $this->assertStringNotContainsString('Amina had a hard morning', $response->getContent());
            $this->assertStringNotContainsString('Ustadh Bilal', $response->getContent());
            $this->assertStringNotContainsString('question', $response->getContent());
        }

        $this->assertSame(0, GroupMessageReaction::withoutMasjidScope()->where('contact_id', $this->parentB->id)->count());
        $this->assertSame(1, GroupMessageReaction::withoutMasjidScope()->count());
    }

    #[Test]
    public function another_familys_message_cannot_be_reached_through_a_thread_you_may_read(): void
    {
        $theirs = $this->privateThread();
        $theirMessage = $this->teacherMessage($theirs, 'Only for the Yusufs');
        $mine = $this->privateThread($this->childB);

        $response = $this->asParent($this->parentB)
            ->putJson($this->familyUrl("/threads/{$mine->id}/messages/{$theirMessage->id}/reactions/ameen"))
            ->assertNotFound();

        $this->assertStringNotContainsString('Only for the Yusufs', $response->getContent());
        $this->assertSame(0, GroupMessageReaction::withoutMasjidScope()->count());
    }

    #[Test]
    public function a_class_wide_conversation_needs_the_consent_it_always_needed(): void
    {
        $thread = $this->classThread();
        $message = $this->teacherMessage($thread);
        $url = $this->familyUrl("/threads/{$thread->id}/messages/{$message->id}/reactions/thumbs_up");

        $this->asParent($this->parentA)->putJson($url)->assertStatus(403);
        $this->assertSame(0, GroupMessageReaction::withoutMasjidScope()->count());

        $this->consent($this->parentA, 'feed');

        $this->asParent($this->parentA)->putJson($url)->assertOk();
        $this->assertSame(1, GroupMessageReaction::withoutMasjidScope()->count());
    }

    #[Test]
    public function a_closed_conversation_takes_no_reactions_from_anyone(): void
    {
        $thread = $this->privateThread();
        $message = $this->teacherMessage($thread);
        $thread->forceFill(['closed_at' => now()])->save();

        $this->asParent($this->parentA)
            ->putJson($this->familyUrl("/threads/{$thread->id}/messages/{$message->id}/reactions/ameen"))
            ->assertStatus(422);
        $this->asTeacher()
            ->putJson($this->teacherUrl("/threads/{$thread->id}/messages/{$message->id}/reactions/ameen"))
            ->assertStatus(422);

        $this->assertSame(0, GroupMessageReaction::withoutMasjidScope()->count());
    }

    #[Test]
    public function another_schools_conversation_is_a_miss(): void
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
            'body' => 'Another school entirely',
        ]);

        // Their ids under OUR school's URL: nothing resolves.
        $this->asParent($this->parentA)
            ->putJson("/api/family/masjids/{$this->school->id}/groups/{$foreignClass->id}/threads/{$foreignThread->id}/messages/{$foreignMessage->id}/reactions/ameen")
            ->assertNotFound();
        $status = $this->asTeacher()
            ->putJson("/api/teacher/masjids/{$this->school->id}/groups/{$foreignClass->id}/threads/{$foreignThread->id}/messages/{$foreignMessage->id}/reactions/ameen")
            ->status();
        // teacher.leads refuses a class the teacher does not lead in this school.
        $this->assertContains($status, [403, 404]);

        $this->assertSame(0, GroupMessageReaction::withoutMasjidScope()->count());
    }

    #[Test]
    public function a_teacher_cannot_react_in_a_class_they_do_not_lead(): void
    {
        $otherClass = Group::factory()->create([
            'masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS,
            'name' => 'Grade 2', 'slug' => 'grade-2',
        ]);
        $thread = GroupThread::create([
            'masjid_id' => $this->school->id, 'group_id' => $otherClass->id,
            'subject' => 'Not yours', 'scope' => GroupThread::SCOPE_GROUP,
        ]);
        $message = GroupMessage::create([
            'masjid_id' => $this->school->id, 'group_thread_id' => $thread->id, 'body' => 'x',
        ]);

        $this->asTeacher()
            ->putJson("/api/teacher/masjids/{$this->school->id}/groups/{$otherClass->id}/threads/{$thread->id}/messages/{$message->id}/reactions/ameen")
            ->assertStatus(403);

        $this->assertSame(0, GroupMessageReaction::withoutMasjidScope()->count());
    }

    // --------------------------------------------- what a parent is shown

    #[Test]
    public function a_parent_is_shown_the_schools_names_and_never_another_familys(): void
    {
        $this->consent($this->parentA, 'feed');
        $this->consent($this->parentB, 'feed');
        $thread = $this->classThread();
        $message = $this->teacherMessage($thread, 'Eid party on Friday');

        $this->asParent($this->parentB)
            ->putJson($this->familyUrl("/threads/{$thread->id}/messages/{$message->id}/reactions/thumbs_up"))
            ->assertOk();
        $this->asParent($this->parentB)->getJson($this->familyUrl("/threads/{$thread->id}"))->assertOk();

        $colleague = User::factory()->create(['type' => 'Teacher', 'name' => 'Ustadha Safiya', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
        MasjidUser::create(['masjid_id' => $this->school->id, 'user_id' => $colleague->id, 'role' => 'teacher', 'is_default' => false]);
        $this->class->staff()->attach($colleague->id, ['masjid_id' => $this->school->id, 'role' => GroupStaff::ROLE_TEACHER, 'assigned_at' => now()]);
        $this->asUser($colleague, ['staff'])
            ->putJson($this->teacherUrl("/threads/{$thread->id}/messages/{$message->id}/reactions/thumbs_up"))
            ->assertOk();
        $this->asUser($colleague, ['staff'])->getJson($this->teacherUrl("/threads/{$thread->id}"))->assertOk();

        $response = $this->asParent($this->parentA)
            ->getJson($this->familyUrl("/threads/{$thread->id}"))
            ->assertOk()
            ->assertJsonPath('data.messages.data.0.reactions.1.key', 'thumbs_up')
            ->assertJsonPath('data.messages.data.0.reactions.1.count', 2)
            ->assertJsonPath('data.messages.data.0.reactions.1.mine', false)
            // The colleague is named; the other family is counted, not named.
            ->assertJsonPath('data.messages.data.0.reactions.1.by', [['name' => 'Ustadha Safiya', 'is_parent' => false]])
            // The colleague has read it; the other parent's reading is not shown.
            ->assertJsonPath('data.messages.data.0.read_by', [['name' => 'Ustadha Safiya', 'is_parent' => false]]);

        $this->assertStringNotContainsString('Maryam', $response->getContent());
        $this->assertStringNotContainsString('Karimi', $response->getContent());

        // The teacher, by contrast, is shown both.
        $this->asTeacher()
            ->getJson($this->teacherUrl("/threads/{$thread->id}"))
            ->assertOk()
            ->assertJsonPath('data.messages.data.0.reactions.1.by', [
                ['name' => 'Maryam Karimi', 'is_parent' => true],
                ['name' => 'Ustadha Safiya', 'is_parent' => false],
            ])
            // Both families have opened it by now, and the teacher is told so.
            ->assertJsonPath('data.messages.data.0.read_by', [
                ['name' => 'Maryam Karimi', 'is_parent' => true],
                ['name' => 'Ustadha Safiya', 'is_parent' => false],
                ['name' => 'Huda Yusuf', 'is_parent' => true],
            ]);
    }

    // ------------------------------------------------------------ receipts

    #[Test]
    public function the_teacher_sees_the_parent_read_it_only_once_the_parent_opens_the_thread(): void
    {
        $thread = $this->privateThread();
        $this->teacherMessage($thread, 'Please sign the trip form');

        // The list is not reading.
        $this->asParent($this->parentA)->getJson($this->familyUrl('/threads'))->assertOk();
        $this->assertSame(0, GroupThreadRead::withoutMasjidScope()->whereNotNull('contact_id')->count());

        $this->asTeacher()
            ->getJson($this->teacherUrl("/threads/{$thread->id}"))
            ->assertJsonPath('data.messages.data.0.read_by', []);

        // Opening it is.
        $this->asParent($this->parentA)->getJson($this->familyUrl("/threads/{$thread->id}"))->assertOk();

        $this->asTeacher()
            ->getJson($this->teacherUrl("/threads/{$thread->id}"))
            ->assertJsonPath('data.messages.data.0.read_by', [['name' => 'Huda Yusuf', 'is_parent' => true]]);
    }

    #[Test]
    public function the_parent_sees_the_teacher_read_their_reply(): void
    {
        $thread = $this->privateThread();
        $this->teacherMessage($thread);

        $this->asParent($this->parentA)
            ->postJson($this->familyUrl("/threads/{$thread->id}/messages"), ['body' => 'Signed, jazakAllah khair'])
            ->assertCreated()
            ->assertJsonPath('data.read_by', []);

        $this->asParent($this->parentA)
            ->getJson($this->familyUrl("/threads/{$thread->id}"))
            ->assertJsonPath('data.messages.data.1.is_mine', true)
            ->assertJsonPath('data.messages.data.1.read_by', []);

        $this->asTeacher()->getJson($this->teacherUrl("/threads/{$thread->id}"))->assertOk();

        $this->asParent($this->parentA)
            ->getJson($this->familyUrl("/threads/{$thread->id}"))
            ->assertJsonPath('data.messages.data.1.read_by', [['name' => 'Ustadh Bilal', 'is_parent' => false]]);
    }

    #[Test]
    public function another_family_cannot_read_mark_a_private_conversation(): void
    {
        $thread = $this->privateThread();
        $this->teacherMessage($thread, 'About the incident at lunch');

        $response = $this->asParent($this->parentB)
            ->getJson($this->familyUrl("/threads/{$thread->id}"))
            ->assertStatus(403);

        $this->assertStringNotContainsString('About the incident at lunch', $response->getContent());
        $this->assertSame(0, GroupThreadRead::withoutMasjidScope()->where('contact_id', $this->parentB->id)->count());

        // And so nothing tells the teacher it was read.
        $this->asTeacher()
            ->getJson($this->teacherUrl("/threads/{$thread->id}"))
            ->assertJsonPath('data.messages.data.0.read_by', []);
    }

    #[Test]
    public function a_receipt_covers_only_the_messages_that_were_served(): void
    {
        $thread = $this->privateThread();
        $first = $this->teacherMessage($thread, 'First');
        $second = $this->teacherMessage($thread, 'Second');

        // One message per page: the parent was shown the first and not the second.
        $this->asParent($this->parentA)
            ->getJson($this->familyUrl("/threads/{$thread->id}?per_page=1"))
            ->assertOk()
            ->assertJsonPath('data.messages.data.0.body', 'First');

        $read = GroupThreadRead::withoutMasjidScope()->where('contact_id', $this->parentA->id)->sole();
        $this->assertSame($first->id, (int) $read->last_read_message_id);

        $teacherView = $this->asTeacher()->getJson($this->teacherUrl("/threads/{$thread->id}"))->assertOk();
        $this->assertSame([['name' => 'Huda Yusuf', 'is_parent' => true]], $teacherView->json('data.messages.data.0.read_by'));
        $this->assertSame([], $teacherView->json('data.messages.data.1.read_by'));

        // Page two shows the second; going back to page one does not un-read it.
        $this->asParent($this->parentA)->getJson($this->familyUrl("/threads/{$thread->id}?per_page=1&page=2"))->assertOk();
        $this->asParent($this->parentA)->getJson($this->familyUrl("/threads/{$thread->id}?per_page=1&page=1"))->assertOk();
        $this->assertSame($second->id, (int) $read->fresh()->last_read_message_id);
    }

    #[Test]
    public function a_bookmark_from_before_receipts_answers_by_time(): void
    {
        $thread = $this->privateThread();
        $message = $this->teacherMessage($thread);

        GroupThreadRead::create([
            'masjid_id' => $this->school->id,
            'group_thread_id' => $thread->id,
            'contact_id' => $this->parentA->id,
            'last_read_at' => now()->addMinute(),
        ]);

        $this->asTeacher()
            ->getJson($this->teacherUrl("/threads/{$thread->id}"))
            ->assertJsonPath('data.messages.data.0.read_by', [['name' => 'Huda Yusuf', 'is_parent' => true]]);

        $this->assertTrue(GroupThreadRead::withoutMasjidScope()->where('contact_id', $this->parentA->id)->sole()->covers($message));
    }

    // ------------------------------------------------------------ the office

    #[Test]
    public function the_office_sees_read_status_and_reacts_through_the_admin_realm(): void
    {
        $admin = $this->makeAdmin();
        // The office admin's person leads the class, which is what lets them
        // read its conversations at all (GroupAudience).
        $person = Contact::factory()->create(['masjid_id' => $this->school->id, 'email' => $admin->email]);
        GroupMembership::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id,
            'contact_id' => $person->id, 'role' => GroupMembership::ROLE_LEADER,
        ]);

        $thread = $this->privateThread();
        $message = $this->teacherMessage($thread);
        $this->asParent($this->parentA)->getJson($this->familyUrl("/threads/{$thread->id}"))->assertOk();

        $this->asUser($admin)
            ->getJson($this->adminUrl("/threads/{$thread->id}"))
            ->assertOk()
            ->assertJsonPath('data.messages.data.0.read_by', [['name' => 'Huda Yusuf', 'is_parent' => true]]);

        $this->asUser($admin)
            ->putJson($this->adminUrl("/threads/{$thread->id}/messages/{$message->id}/reactions/hundred"))
            ->assertOk()
            ->assertJsonPath('data.reactions.2.mine', true);
    }

    #[Test]
    public function an_office_admin_who_cannot_read_the_conversation_cannot_react_to_it(): void
    {
        $admin = $this->makeAdmin();
        $thread = $this->privateThread();
        $message = $this->teacherMessage($thread);

        $this->asUser($admin)
            ->putJson($this->adminUrl("/threads/{$thread->id}/messages/{$message->id}/reactions/ameen"))
            ->assertStatus(403);

        $this->assertSame(0, GroupMessageReaction::withoutMasjidScope()->count());
    }

    // ------------------------------------------------------------ schema

    #[Test]
    public function the_schema_is_what_the_code_assumes(): void
    {
        $this->assertTrue(Schema::hasColumns('group_message_reactions', [
            'masjid_id', 'group_message_id', 'reaction', 'user_id', 'contact_id',
        ]));
        $this->assertTrue(Schema::hasColumn('group_thread_reads', 'last_read_message_id'));

        foreach (Schema::getIndexes('group_message_reactions') as $index) {
            $this->assertLessThanOrEqual(64, strlen($index['name']), "MySQL caps an index name at 64: {$index['name']}");
        }
    }

    // ------------------------------------------------------------ helpers

    private function makeMasjid(): Masjid
    {
        return Masjid::create([
            'name' => 'Reaction School '.uniqid(),
            'email' => 'school-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0,
            'crm_enabled' => true, 'org_type' => 'school',
        ]);
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
            'login_email' => 'parent-'.uniqid().'@test.local',
            'login_enabled_at' => now(),
        ])->save();

        GroupMembership::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id,
            'contact_id' => $parent->id, 'role' => GroupMembership::ROLE_GUARDIAN,
            'guardian_of_contact_id' => $child->id,
        ]);

        return [$parent->refresh(), $membership];
    }

    private function consent(Contact $parent, string $scope): void
    {
        GroupMembership::withoutMasjidScope()
            ->where('group_id', $this->class->id)
            ->where('contact_id', $parent->id)
            ->where('role', GroupMembership::ROLE_GUARDIAN)
            ->update(['consent_granted_at' => now(), 'consent_scope' => $scope]);
    }

    private function privateThread(?GroupMembership $about = null): GroupThread
    {
        return GroupThread::create([
            'masjid_id' => $this->school->id,
            'group_id' => $this->class->id,
            'created_by_user_id' => $this->teacher->id,
            'subject' => 'About '.($about ?? $this->childA)->id,
            'scope' => GroupThread::SCOPE_PARTICIPANT,
            'about_membership_id' => ($about ?? $this->childA)->id,
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

    private function teacherMessage(GroupThread $thread, string $body = 'Salaam — a quick update.'): GroupMessage
    {
        return GroupMessage::create([
            'masjid_id' => $this->school->id,
            'group_thread_id' => $thread->id,
            'author_user_id' => $this->teacher->id,
            'body' => $body,
        ]);
    }

    private function parentMessage(GroupThread $thread, Contact $parent, string $body = 'Thank you, ustadh.'): GroupMessage
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
