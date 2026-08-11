<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupMessage;
use App\Models\GroupThread;
use App\Models\Masjid;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Group messaging threads over HTTP:
 * /api/admin/masjids/{id}/groups/{id}/threads (T-005c).
 *
 * Mirrors GroupFeedTest for tenancy — B's id under A's own route is a 404, B's
 * masjid in the route is a 403 — and adds the guarantee this slice exists for:
 *
 *   - A PARTICIPANT-SCOPED THREAD IS A PRIVATE CONVERSATION. It is readable by
 *     the group's leaders and the specific member/guardian it concerns, and by
 *     NOBODY else — in particular, another guardian in the same group must be
 *     refused (403), because "guardian here" never meant "guardian of this
 *     child". Same reasoning as the guardian edge itself.
 *   - Consent gates BROADCASTS, not conversations: a guardian with no consent
 *     record still reads the thread about their own ward, while the group-wide
 *     threads stay closed to them exactly as the feed does.
 *   - WRITING A MESSAGE requires being able to read the thread — the deliberate
 *     difference from the feed, where an off-roster admin can publish blind.
 *
 * The acting person resolves as in the feed: the admin's tenant Contact with
 * the same login email — see App\Support\GroupAudience.
 */
class GroupMessagingTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjidA;
    private Masjid $masjidB;
    private User $adminA;

    private Group $groupA;
    private Group $groupB;

    /** The Contact that IS adminA, in masjid A. Not in any group by default. */
    private Contact $personA;

    protected function setUp(): void
    {
        parent::setUp();

        // Force sqlite-in-memory regardless of phpunit.xml.
        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        // The threads reuse the CONTACTS permissions (see routes/admin.php), so
        // the bridged masjid-admin role must be seeded before the admins exist.
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->masjidA = $this->makeMasjid();
        $this->masjidB = $this->makeMasjid();

        $this->adminA = $this->makeAdminFor($this->masjidA);
        $this->makeAdminFor($this->masjidB);

        $this->groupA = Group::factory()->create([
            'masjid_id' => $this->masjidA->id,
            'name' => 'Grade 3',
            'slug' => 'grade-3',
        ]);
        $this->groupB = Group::factory()->create([
            'masjid_id' => $this->masjidB->id,
            'name' => 'Grade 3',
            'slug' => 'grade-3',
        ]);

        $this->personA = Contact::factory()->create([
            'masjid_id' => $this->masjidA->id,
            'email' => $this->adminA->email,
        ]);
    }

    private function makeMasjid(array $overrides = []): Masjid
    {
        return Masjid::create(array_merge([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            // Threads live inside the CRM route group, gated by crm_enabled.
            'crm_enabled' => true,
        ], $overrides));
    }

    private function makeAdminFor(Masjid $masjid): User
    {
        $admin = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);

        $masjid->user_id = $admin->id;
        $masjid->save();

        return $admin;
    }

    private function threadsUrl(?Group $group = null, ?Masjid $masjid = null): string
    {
        return '/api/admin/masjids/' . ($masjid ?? $this->masjidA)->id
            . '/groups/' . ($group ?? $this->groupA)->id . '/threads';
    }

    /** Put a contact in a group directly (unbound), as the roster endpoints would. */
    private function seedMembership(
        Masjid $masjid,
        Group $group,
        Contact $contact,
        string $role,
        ?Contact $ward = null,
        ?string $consentScope = null
    ): GroupMembership {
        return GroupMembership::create([
            'masjid_id' => $masjid->id,
            'group_id' => $group->id,
            'contact_id' => $contact->id,
            'role' => $role,
            'guardian_of_contact_id' => $ward?->id,
            'consent_granted_at' => $consentScope === null ? null : now(),
            'consent_scope' => $consentScope,
        ]);
    }

    /** A child (email-less, so identity can never collide) who is a member of group A. */
    private function seedChildInGroupA(): array
    {
        $child = Contact::factory()->create(['masjid_id' => $this->masjidA->id, 'email' => null]);
        $membership = $this->seedMembership($this->masjidA, $this->groupA, $child, GroupMembership::ROLE_MEMBER);

        return [$child, $membership];
    }

    /** Make adminA's person a guardian of a (new) child in group A. Returns [$child, $childMembership, $edge]. */
    private function makeAdminAGuardian(?string $consentScope = null): array
    {
        [$child, $childMembership] = $this->seedChildInGroupA();

        $edge = $this->seedMembership(
            $this->masjidA, $this->groupA, $this->personA, GroupMembership::ROLE_GUARDIAN, $child, $consentScope
        );

        return [$child, $childMembership, $edge];
    }

    /** A thread written straight to the DB (unbound), as the endpoint would create it. */
    private function seedThread(
        Masjid $masjid,
        Group $group,
        string $scope = GroupThread::SCOPE_GROUP,
        ?GroupMembership $about = null,
        ?string $subject = null
    ): GroupThread {
        return GroupThread::factory()->create([
            'masjid_id' => $masjid->id,
            'group_id' => $group->id,
            'scope' => $scope,
            'about_membership_id' => $about?->id,
            'subject' => $subject ?? 'Week 3 check-in',
        ]);
    }

    private function seedMessage(GroupThread $thread, string $body = 'Salaam — quick update.'): GroupMessage
    {
        return GroupMessage::factory()->create([
            'masjid_id' => $thread->masjid_id,
            'group_thread_id' => $thread->id,
            'body' => $body,
        ]);
    }

    // ---------- auth ----------

    #[Test]
    public function threads_reject_unauthenticated_requests(): void
    {
        $this->getJson($this->threadsUrl())->assertStatus(401);
    }

    // ---------- opening threads ----------

    #[Test]
    public function a_leader_can_open_a_group_wide_thread_with_a_first_message(): void
    {
        $this->seedMembership($this->masjidA, $this->groupA, $this->personA, GroupMembership::ROLE_LEADER);

        Sanctum::actingAs($this->adminA);

        $response = $this->postJson($this->threadsUrl(), [
            'subject' => 'Field trip on Friday',
            'scope' => GroupThread::SCOPE_GROUP,
            'body' => 'Permission slips are due Wednesday.',
        ])->assertStatus(201);

        $this->assertDatabaseHas('group_threads', [
            'masjid_id' => $this->masjidA->id,
            'group_id' => $this->groupA->id,
            'subject' => 'Field trip on Friday',
            'scope' => GroupThread::SCOPE_GROUP,
            // Openership is the ACCOUNT that opened it, never a client claim.
            'created_by_user_id' => $this->adminA->id,
        ]);
        $this->assertDatabaseHas('group_messages', [
            'group_thread_id' => $response->json('data.id'),
            'body' => 'Permission slips are due Wednesday.',
            'author_user_id' => $this->adminA->id,
        ]);
        // The opener has read what they just wrote.
        $this->assertFalse($response->json('data.unread'));
    }

    #[Test]
    public function a_thread_requires_a_subject_and_a_recognized_scope(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->postJson($this->threadsUrl(), [])->assertStatus(422);
        $this->postJson($this->threadsUrl(), [
            'subject' => 'Hm',
            'scope' => 'broadcast',
        ])->assertStatus(422);
    }

    #[Test]
    public function a_participant_thread_requires_its_target_membership(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->postJson($this->threadsUrl(), [
            'subject' => 'About someone',
            'scope' => GroupThread::SCOPE_PARTICIPANT,
        ])->assertStatus(422);
    }

    #[Test]
    public function a_group_wide_thread_must_not_name_a_target(): void
    {
        [, $childMembership] = $this->seedChildInGroupA();

        Sanctum::actingAs($this->adminA);

        // The mirror-image invariant: a target smuggled onto a group-wide
        // thread would sit unread by every access check.
        $this->postJson($this->threadsUrl(), [
            'subject' => 'Announcement',
            'scope' => GroupThread::SCOPE_GROUP,
            'about_membership_id' => $childMembership->id,
        ])->assertStatus(422);
    }

    #[Test]
    public function the_target_must_be_a_participant_of_this_group(): void
    {
        [, , $edge] = $this->makeAdminAGuardian();

        // A membership from ANOTHER organization's group.
        $foreignContact = Contact::factory()->create(['masjid_id' => $this->masjidB->id, 'email' => null]);
        $foreignMembership = $this->seedMembership(
            $this->masjidB, $this->groupB, $foreignContact, GroupMembership::ROLE_MEMBER
        );

        Sanctum::actingAs($this->adminA);

        // A guardian EDGE names a relationship, not a member — refused.
        $this->postJson($this->threadsUrl(), [
            'subject' => 'About an edge',
            'scope' => GroupThread::SCOPE_PARTICIPANT,
            'about_membership_id' => $edge->id,
        ])->assertStatus(422);

        // A foreign membership id is invisible to the scoped lookup — refused
        // the same way, revealing nothing about whether it exists.
        $this->postJson($this->threadsUrl(), [
            'subject' => 'About a stranger',
            'scope' => GroupThread::SCOPE_PARTICIPANT,
            'about_membership_id' => $foreignMembership->id,
        ])->assertStatus(422);
    }

    #[Test]
    public function an_admin_not_in_the_group_can_open_a_thread_but_not_read_it(): void
    {
        // The feed's documented read/write asymmetry, mirrored: opening is
        // administration, reading is disclosure.
        Sanctum::actingAs($this->adminA);

        $this->postJson($this->threadsUrl(), [
            'subject' => 'Reminder',
            'scope' => GroupThread::SCOPE_GROUP,
        ])->assertStatus(201);

        $this->getJson($this->threadsUrl())->assertStatus(403);
    }

    // ---------- who may read: group-wide ----------

    #[Test]
    public function a_member_can_read_a_group_wide_thread(): void
    {
        $this->seedMembership($this->masjidA, $this->groupA, $this->personA, GroupMembership::ROLE_MEMBER);
        $thread = $this->seedThread($this->masjidA, $this->groupA);
        $this->seedMessage($thread);

        Sanctum::actingAs($this->adminA);

        $this->getJson($this->threadsUrl() . '/' . $thread->id)
            ->assertOk()
            ->assertJsonPath('data.thread.id', $thread->id)
            ->assertJsonPath('data.messages.total', 1);
    }

    #[Test]
    public function a_guardian_without_consent_cannot_read_a_group_wide_thread(): void
    {
        // Group-wide threads are announcement-discussion: the SAME audience as
        // the feed, so the same consent gate applies to a guardian.
        $this->makeAdminAGuardian();
        $thread = $this->seedThread($this->masjidA, $this->groupA);

        Sanctum::actingAs($this->adminA);

        $this->getJson($this->threadsUrl() . '/' . $thread->id)->assertStatus(403);
    }

    #[Test]
    public function a_guardian_with_feed_consent_can_read_a_group_wide_thread(): void
    {
        $this->makeAdminAGuardian(GroupMembership::CONSENT_FEED);
        $thread = $this->seedThread($this->masjidA, $this->groupA);

        Sanctum::actingAs($this->adminA);

        $this->getJson($this->threadsUrl() . '/' . $thread->id)->assertOk();
    }

    // ---------- who may read: participant-scoped ----------

    #[Test]
    public function a_guardian_without_consent_can_read_a_thread_about_their_own_ward(): void
    {
        // Consent gates BROADCASTS. A conversation the guardian is a named
        // party to about their own child is not one — requiring feed consent
        // here would block a parent from talking to the teacher.
        [, $childMembership] = $this->makeAdminAGuardian();
        $thread = $this->seedThread(
            $this->masjidA, $this->groupA, GroupThread::SCOPE_PARTICIPANT, $childMembership
        );

        Sanctum::actingAs($this->adminA);

        $this->getJson($this->threadsUrl() . '/' . $thread->id)
            ->assertOk()
            ->assertJsonPath('data.thread.about.membership_id', $childMembership->id);
    }

    #[Test]
    public function another_guardian_cannot_read_a_thread_about_a_different_member(): void
    {
        // THE test this slice exists for. adminA's person is a guardian in this
        // group — with full media consent, even — but of a DIFFERENT child.
        // "Guardian here" never meant "guardian of this child".
        $this->makeAdminAGuardian(GroupMembership::CONSENT_MEDIA);

        // A second family: another child in the same group, another parent.
        [, $otherChildMembership] = $this->seedChildInGroupA();
        $thread = $this->seedThread(
            $this->masjidA, $this->groupA, GroupThread::SCOPE_PARTICIPANT, $otherChildMembership
        );

        Sanctum::actingAs($this->adminA);

        $this->getJson($this->threadsUrl() . '/' . $thread->id)->assertStatus(403);
    }

    #[Test]
    public function the_member_a_thread_concerns_can_read_it(): void
    {
        $membership = $this->seedMembership(
            $this->masjidA, $this->groupA, $this->personA, GroupMembership::ROLE_MEMBER
        );
        $thread = $this->seedThread(
            $this->masjidA, $this->groupA, GroupThread::SCOPE_PARTICIPANT, $membership
        );

        Sanctum::actingAs($this->adminA);

        $this->getJson($this->threadsUrl() . '/' . $thread->id)->assertOk();
    }

    #[Test]
    public function a_leader_reads_every_participant_thread(): void
    {
        $this->seedMembership($this->masjidA, $this->groupA, $this->personA, GroupMembership::ROLE_LEADER);
        [, $childMembership] = $this->seedChildInGroupA();
        $thread = $this->seedThread(
            $this->masjidA, $this->groupA, GroupThread::SCOPE_PARTICIPANT, $childMembership
        );

        Sanctum::actingAs($this->adminA);

        $this->getJson($this->threadsUrl() . '/' . $thread->id)->assertOk();
    }

    // ---------- the list is pre-filtered ----------

    #[Test]
    public function the_list_shows_a_guardian_only_their_own_conversations(): void
    {
        // Guardian of one child, NO consent: the group-wide thread and the
        // other family's thread must both be absent, not merely un-openable.
        [, $childMembership] = $this->makeAdminAGuardian();
        [, $otherChildMembership] = $this->seedChildInGroupA();

        $mine = $this->seedThread($this->masjidA, $this->groupA, GroupThread::SCOPE_PARTICIPANT, $childMembership);
        $this->seedThread($this->masjidA, $this->groupA, GroupThread::SCOPE_PARTICIPANT, $otherChildMembership);
        $this->seedThread($this->masjidA, $this->groupA, GroupThread::SCOPE_GROUP);

        Sanctum::actingAs($this->adminA);

        $response = $this->getJson($this->threadsUrl())->assertOk();

        $this->assertSame(1, $response->json('data.total'));
        $this->assertSame($mine->id, $response->json('data.data.0.id'));
    }

    #[Test]
    public function feed_consent_adds_the_group_wide_threads_to_a_guardians_list(): void
    {
        [, $childMembership] = $this->makeAdminAGuardian(GroupMembership::CONSENT_FEED);
        $this->seedThread($this->masjidA, $this->groupA, GroupThread::SCOPE_PARTICIPANT, $childMembership);
        $this->seedThread($this->masjidA, $this->groupA, GroupThread::SCOPE_GROUP);

        Sanctum::actingAs($this->adminA);

        $this->getJson($this->threadsUrl())
            ->assertOk()
            ->assertJsonPath('data.total', 2);
    }

    #[Test]
    public function the_scope_filter_narrows_the_list(): void
    {
        $this->seedMembership($this->masjidA, $this->groupA, $this->personA, GroupMembership::ROLE_LEADER);
        [, $childMembership] = $this->seedChildInGroupA();
        $participant = $this->seedThread(
            $this->masjidA, $this->groupA, GroupThread::SCOPE_PARTICIPANT, $childMembership
        );
        $this->seedThread($this->masjidA, $this->groupA, GroupThread::SCOPE_GROUP);

        Sanctum::actingAs($this->adminA);

        $response = $this->getJson($this->threadsUrl() . '?scope=participant')->assertOk();

        $this->assertSame(1, $response->json('data.total'));
        $this->assertSame($participant->id, $response->json('data.data.0.id'));

        // A scope nobody recognizes is a validation failure, not an empty list
        // that could be mistaken for "no conversations".
        $this->getJson($this->threadsUrl() . '?scope=everything')->assertStatus(422);
    }

    // ---------- writing messages ----------

    #[Test]
    public function posting_a_message_requires_being_able_to_read_the_thread(): void
    {
        // adminA holds `manage contacts` and is a guardian in this group — but
        // the thread concerns a different member, so they may not read it, and
        // therefore may not speak in it either.
        $this->makeAdminAGuardian(GroupMembership::CONSENT_MEDIA);
        [, $otherChildMembership] = $this->seedChildInGroupA();
        $thread = $this->seedThread(
            $this->masjidA, $this->groupA, GroupThread::SCOPE_PARTICIPANT, $otherChildMembership
        );

        Sanctum::actingAs($this->adminA);

        $this->postJson($this->threadsUrl() . '/' . $thread->id . '/messages', [
            'body' => 'Trying to butt in.',
        ])->assertStatus(403);

        $this->assertDatabaseCount('group_messages', 0);
    }

    #[Test]
    public function an_entitled_reader_can_post_a_message_and_authorship_is_recorded(): void
    {
        $this->seedMembership($this->masjidA, $this->groupA, $this->personA, GroupMembership::ROLE_LEADER);
        $thread = $this->seedThread($this->masjidA, $this->groupA);

        Sanctum::actingAs($this->adminA);

        $this->postJson($this->threadsUrl() . '/' . $thread->id . '/messages', [
            'body' => 'Reminder: bring the slips.',
        ])->assertStatus(201);

        $this->assertDatabaseHas('group_messages', [
            'group_thread_id' => $thread->id,
            'body' => 'Reminder: bring the slips.',
            'author_user_id' => $this->adminA->id,
        ]);
    }

    #[Test]
    public function a_message_requires_a_body(): void
    {
        $this->seedMembership($this->masjidA, $this->groupA, $this->personA, GroupMembership::ROLE_LEADER);
        $thread = $this->seedThread($this->masjidA, $this->groupA);

        Sanctum::actingAs($this->adminA);

        $this->postJson($this->threadsUrl() . '/' . $thread->id . '/messages', [])
            ->assertStatus(422);
    }

    // ---------- closing and reopening ----------

    #[Test]
    public function a_closed_thread_takes_no_messages_until_reopened(): void
    {
        $this->seedMembership($this->masjidA, $this->groupA, $this->personA, GroupMembership::ROLE_LEADER);
        $thread = $this->seedThread($this->masjidA, $this->groupA);

        Sanctum::actingAs($this->adminA);

        $this->postJson($this->threadsUrl() . '/' . $thread->id . '/close')
            ->assertOk()
            ->assertJsonPath('data.is_closed', true);

        $this->postJson($this->threadsUrl() . '/' . $thread->id . '/messages', [
            'body' => 'Too late?',
        ])->assertStatus(422);

        $this->postJson($this->threadsUrl() . '/' . $thread->id . '/reopen')
            ->assertOk()
            ->assertJsonPath('data.is_closed', false);

        $this->postJson($this->threadsUrl() . '/' . $thread->id . '/messages', [
            'body' => 'Not too late.',
        ])->assertStatus(201);
    }

    #[Test]
    public function a_closed_thread_is_still_readable(): void
    {
        // Closing ends the conversation, not the record of it.
        $this->seedMembership($this->masjidA, $this->groupA, $this->personA, GroupMembership::ROLE_MEMBER);
        $thread = $this->seedThread($this->masjidA, $this->groupA);
        $thread->update(['closed_at' => now()]);

        Sanctum::actingAs($this->adminA);

        $this->getJson($this->threadsUrl() . '/' . $thread->id)->assertOk();
    }

    // ---------- soft delete ----------

    #[Test]
    public function a_thread_is_soft_deleted_and_disappears_from_reads(): void
    {
        $this->seedMembership($this->masjidA, $this->groupA, $this->personA, GroupMembership::ROLE_LEADER);
        $thread = $this->seedThread($this->masjidA, $this->groupA);
        $message = $this->seedMessage($thread);

        Sanctum::actingAs($this->adminA);

        $this->deleteJson($this->threadsUrl() . '/' . $thread->id)->assertOk();

        $this->assertSoftDeleted('group_threads', ['id' => $thread->id]);
        // The messages stay: only the retention purge destroys the rows.
        $this->assertDatabaseHas('group_messages', ['id' => $message->id]);
        $this->getJson($this->threadsUrl() . '/' . $thread->id)->assertStatus(404);
    }

    // ---------- unread tracking ----------

    #[Test]
    public function reading_a_thread_marks_it_read(): void
    {
        $this->seedMembership($this->masjidA, $this->groupA, $this->personA, GroupMembership::ROLE_LEADER);
        $thread = $this->seedThread($this->masjidA, $this->groupA);
        $this->seedMessage($thread);

        Sanctum::actingAs($this->adminA);

        // Before viewing: something is there that this reader has never seen.
        $before = $this->getJson($this->threadsUrl())->assertOk();
        $this->assertTrue($before->json('data.data.0.unread'));

        // Viewing IS reading.
        $this->getJson($this->threadsUrl() . '/' . $thread->id)->assertOk();

        $after = $this->getJson($this->threadsUrl())->assertOk();
        $this->assertFalse($after->json('data.data.0.unread'));
        $this->assertNotNull($after->json('data.data.0.last_read_at'));

        $this->assertDatabaseHas('group_thread_reads', [
            'group_thread_id' => $thread->id,
            'user_id' => $this->adminA->id,
        ]);
    }

    // ---------- tenancy ----------

    #[Test]
    public function another_organizations_group_has_no_reachable_threads(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->getJson($this->threadsUrl($this->groupB))->assertStatus(404);
    }

    #[Test]
    public function another_organizations_thread_is_a_404_under_your_own_route(): void
    {
        $this->seedMembership($this->masjidA, $this->groupA, $this->personA, GroupMembership::ROLE_LEADER);
        $foreign = $this->seedThread($this->masjidB, $this->groupB);

        Sanctum::actingAs($this->adminA);

        $this->getJson($this->threadsUrl() . '/' . $foreign->id)->assertStatus(404);
    }

    #[Test]
    public function an_admin_cannot_target_another_masjid_in_the_route(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->getJson($this->threadsUrl($this->groupB, $this->masjidB))->assertStatus(403);
    }

    #[Test]
    public function a_client_supplied_masjid_id_cannot_plant_a_thread_elsewhere(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->postJson($this->threadsUrl(), [
            'masjid_id' => $this->masjidB->id,
            'subject' => 'Planted',
            'scope' => GroupThread::SCOPE_GROUP,
        ])->assertStatus(201);

        $this->assertDatabaseMissing('group_threads', [
            'subject' => 'Planted',
            'masjid_id' => $this->masjidB->id,
        ]);
    }
}
