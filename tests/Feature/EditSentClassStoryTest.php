<?php

namespace Tests\Feature;

use App\Jobs\SendGroupNotificationJob;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupPost;
use App\Models\GroupPostReaction;
use App\Models\GroupPostRead;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsClassStoryFixture;
use Tests\TestCase;

/**
 * EDITING A CLASS STORY AFTER IT IS SENT (W7-2a, owner 2026-10-01).
 *
 * The rule that was already there is kept: the class's teachers and the office may change a
 * story that is out (a scheduled one stays the author's). What is new, and pinned here:
 *
 *   - `group_posts.edited_at` says a story that was OUT has since changed, and ONLY then: a
 *     real change of title, body or files, never a scheduled story's edits, a save that
 *     changed nothing, or a retention-only change. Staff payloads carry it with `can_edit`;
 *     the family payload carries `edited_at` and nothing else.
 *   - an edit is notification-free: no job, no mail, no notification; read receipts and
 *     reactions are untouched;
 *   - update() asks the FEED READ gate for a story that is out, because it returns the story.
 *     The hole this closes: an office administrator who is not on the class roster gets 403
 *     on GET /posts, yet used to be able to PUT a sent story and read its words (and media
 *     list) back in the response.
 *
 * Mutation-proved: each guard named above was removed in turn and a test here went red.
 */
class EditSentClassStoryTest extends TestCase
{
    use RefreshDatabase;
    use BuildsClassStoryFixture;

    private const NOW = '2026-10-01 12:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->useSqliteInMemory();
        Carbon::setTestNow(self::NOW);
        Storage::fake((string) config('groups.media.disk'));
        Bus::fake();
        Mail::fake();
        Notification::fake();
        Queue::fake();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->buildStoryWorld();
        $this->consent($this->parentA, GroupMembership::CONSENT_MEDIA);
        $this->consent($this->parentB, GroupMembership::CONSENT_MEDIA);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ================================================================ fixtures

    private function row(GroupPost $post): GroupPost
    {
        return GroupPost::withoutMasjidScope()->findOrFail($post->id);
    }

    private function coTeacher(): User
    {
        return $this->makeTeacher($this->school, $this->class, 'Ustadha Sara');
    }

    private function scheduledPost(): GroupPost
    {
        return GroupPost::create([
            'masjid_id' => $this->class->masjid_id,
            'group_id' => $this->class->id,
            'author_user_id' => $this->teacher->id,
            'title' => 'Coming up',
            'body' => 'Tomorrow we visit the garden.',
            'published_at' => now()->addDays(2),
        ]);
    }

    private function edit(GroupPost $post, array $body = ['body' => 'Corrected: Surah Al-Ikhlas.']): \Illuminate\Testing\TestResponse
    {
        return $this->asTeacher()->putJson($this->teacherUrl("/posts/{$post->id}"), $body);
    }

    /** No email, no push, no queued job of any kind. */
    private function assertNothingWasSent(): void
    {
        Bus::assertNothingDispatched();
        Queue::assertNothingPushed();
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Notification::assertNothingSent();
    }

    // ================================================================ edited_at

    #[Test]
    public function the_author_edits_a_sent_story_in_the_teacher_realm_and_it_is_marked_edited(): void
    {
        $post = $this->makePost();
        $announced = $this->row($post)->announced_at;
        $this->assertNotNull($announced);
        $this->assertNull($this->row($post)->edited_at);

        Carbon::setTestNow('2026-10-01 15:30:00');

        $this->edit($post, ['title' => 'Our day, corrected', 'body' => 'Corrected: Surah Al-Ikhlas.'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Our day, corrected')
            ->assertJsonPath('data.body', 'Corrected: Surah Al-Ikhlas.')
            ->assertJsonPath('data.edited_at', now()->toIso8601String())
            ->assertJsonPath('data.can_edit', true);

        $fresh = $this->row($post);
        $this->assertSame('Corrected: Surah Al-Ikhlas.', $fresh->body);
        $this->assertSame('2026-10-01 15:30:00', $fresh->edited_at->toDateTimeString());
        $this->assertEquals($announced, $fresh->announced_at, 'an edit does not re-announce the story');

        // The list carries it too.
        $this->asTeacher()->getJson($this->teacherUrl('/posts'))->assertOk()
            ->assertJsonPath('data.data.0.edited_at', now()->toIso8601String())
            ->assertJsonPath('data.data.0.can_edit', true);
    }

    #[Test]
    public function the_office_edits_a_sent_story_in_the_admin_realm_when_it_may_read_the_class_feed(): void
    {
        $post = $this->makePost();
        $office = $this->makeLeadingAdmin();

        $this->asUser($office)->putJson($this->adminUrl("/posts/{$post->id}"), ['body' => 'Office correction.'])
            ->assertOk()
            ->assertJsonPath('data.body', 'Office correction.')
            ->assertJsonPath('data.can_edit', true)
            ->assertJsonPath('data.edited_at', now()->toIso8601String());

        $this->assertNotNull($this->row($post)->edited_at);
        $this->assertSame($this->teacher->id, (int) $this->row($post)->author_user_id, 'authorship is not rewritten');
    }

    #[Test]
    public function a_co_teacher_edits_a_sent_story_and_is_offered_the_control(): void
    {
        $post = $this->makePost();
        $co = $this->coTeacher();

        $this->asTeacher($co)->getJson($this->teacherUrl('/posts'))->assertOk()
            ->assertJsonPath('data.data.0.can_edit', true);

        $this->asTeacher($co)->putJson($this->teacherUrl("/posts/{$post->id}"), ['body' => 'A colleague fixed this.'])
            ->assertOk()->assertJsonPath('data.edited_at', now()->toIso8601String());

        $this->assertSame('A colleague fixed this.', $this->row($post)->body);
    }

    #[Test]
    public function edited_at_is_stamped_only_by_a_real_change_to_a_story_that_is_out(): void
    {
        $post = $this->makePost(body: 'Same words.');
        $url = $this->teacherUrl("/posts/{$post->id}");

        // A save that changes nothing.
        $this->asTeacher()->putJson($url, ['title' => 'Our day', 'body' => 'Same words.'])->assertOk()
            ->assertJsonPath('data.edited_at', null);
        $this->assertNull($this->row($post)->edited_at);

        // A retention-only change is not an edit of what families read.
        $this->asTeacher()->putJson($url, ['retained_until' => '2027-01-01'])->assertOk()
            ->assertJsonPath('data.edited_at', null);
        $this->assertNull($this->row($post)->edited_at);

        // The title alone counts.
        $this->asTeacher()->putJson($url, ['title' => 'A new title'])->assertOk();
        $this->assertNotNull($this->row($post)->edited_at);
    }

    #[Test]
    public function adding_a_file_to_a_sent_story_counts_as_an_edit(): void
    {
        $post = $this->makePost(body: 'Same words.');

        $this->asTeacher()->post($this->teacherUrl("/posts/{$post->id}"), [
            '_method' => 'PUT',
            'body' => 'Same words.',
            'images' => [UploadedFile::fake()->image('garden.jpg')],
        ])->assertOk()->assertJsonPath('data.attachments.0.file_name', 'garden.jpg');

        $this->assertNotNull($this->row($post)->edited_at);
        $this->assertSame(1, $this->row($post)->attachments()->count());
    }

    #[Test]
    public function editing_a_scheduled_story_does_not_mark_it_edited(): void
    {
        $post = $this->scheduledPost();

        $this->edit($post, ['title' => 'Still coming', 'body' => 'We visit the library instead.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.edited_at', null)
            // Not offered through the "sent" control: the Scheduled list edits it.
            ->assertJsonPath('data.can_edit', false);

        $this->assertNull($this->row($post)->edited_at);

        // Moving its time or sending it now is not an edit of what anyone read either.
        $this->edit($post, ['send_at' => '2026-10-05T10:00'])->assertOk();
        $this->assertNull($this->row($post)->edited_at);
        $this->edit($post, ['send_now' => true])->assertOk();
        $this->assertNull($this->row($post)->edited_at);

        // ...but once it is out, the next real change is one.
        $this->edit($post, ['body' => 'Now it has gone out, and then changed.'])->assertOk();
        $this->assertNotNull($this->row($post)->edited_at);
    }

    // ================================================================ nothing is re-sent, nothing is reset

    #[Test]
    public function an_edit_sends_nothing_and_leaves_receipts_and_reactions_alone(): void
    {
        config(['groups.story_reads.enabled' => true]);
        $post = $this->makePost();
        $this->consent($this->parentA);

        $this->asParent($this->parentA)->postJson($this->familyUrl('/posts/seen'), ['post_ids' => [$post->id]])->assertOk();
        $this->asParent($this->parentA)->putJson($this->familyUrl("/posts/{$post->id}/reactions/ameen"))->assertOk();
        $this->asTeacher()->putJson($this->teacherUrl("/posts/{$post->id}/reactions/thumbs_up"))->assertOk();

        $read = GroupPostRead::withoutMasjidScope()->where('group_post_id', $post->id)->sole();
        $reactions = GroupPostReaction::withoutMasjidScope()->where('group_post_id', $post->id)->orderBy('id')->pluck('id', 'reaction')->all();
        $this->assertCount(2, $reactions);

        Carbon::setTestNow('2026-10-02 09:00:00');
        $this->edit($post)->assertOk();

        Bus::assertNotDispatched(SendGroupNotificationJob::class);
        $this->assertNothingWasSent();

        $this->assertEquals(
            $read->only(['id', 'contact_id', 'first_seen_at']),
            GroupPostRead::withoutMasjidScope()->where('group_post_id', $post->id)->sole()->only(['id', 'contact_id', 'first_seen_at']),
            'the parent who read the first version stays "seen"'
        );
        $this->assertSame(
            $reactions,
            GroupPostReaction::withoutMasjidScope()->where('group_post_id', $post->id)->orderBy('id')->pluck('id', 'reaction')->all(),
        );
    }

    // ================================================================ the family payload

    #[Test]
    public function the_family_payload_carries_edited_at_and_nothing_about_who_edited(): void
    {
        config(['groups.story_reads.enabled' => true]);
        $post = $this->makePost();
        $this->consent($this->parentA);

        $before = $this->asParent($this->parentA)->getJson($this->familyUrl('/posts'))->assertOk();
        $row = $before->json('data.data.0');
        $this->assertArrayHasKey('edited_at', $row);
        $this->assertNull($row['edited_at']);

        Carbon::setTestNow('2026-10-01 18:00:00');
        $this->edit($post)->assertOk();

        $list = $this->asParent($this->parentA)->getJson($this->familyUrl('/posts'))->assertOk();
        $show = $this->asParent($this->parentA)->getJson($this->familyUrl("/posts/{$post->id}"))->assertOk();

        foreach ([$list->json('data.data.0'), $show->json('data')] as $payload) {
            $this->assertSame(now()->toIso8601String(), $payload['edited_at']);
            $this->assertSame('Corrected: Surah Al-Ikhlas.', $payload['body']);
            // Staff-only fields stay staff-only.
            foreach (['can_edit', 'seen_by', 'seen_count', 'audience_count', 'edited_by', 'edited_by_user_id', 'updated_at'] as $key) {
                $this->assertArrayNotHasKey($key, $payload, "the family payload must not carry {$key}");
            }
            $this->assertSame(['name'], array_keys($payload['author']), 'the author is a name, never an id');
        }
    }

    // ================================================================ who may, and who may not

    #[Test]
    public function a_teacher_of_another_class_is_refused_and_nothing_changes(): void
    {
        $post = $this->makePost();
        $otherClass = Group::factory()->create([
            'masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS, 'name' => 'Grade 2', 'slug' => 'grade-2',
        ]);
        $otherTeacher = $this->makeTeacher($this->school, $otherClass, 'Ustadh Hamza');

        // At this class's URL: teacher.leads refuses a class she does not lead.
        $this->asTeacher($otherTeacher)
            ->putJson($this->teacherUrl("/posts/{$post->id}"), ['body' => 'Not mine to change.'])
            ->assertForbidden();

        // At her own class's URL with this class's post id: the post is found THROUGH the class.
        $this->asTeacher($otherTeacher)
            ->putJson($this->teacherUrl("/posts/{$post->id}", $otherClass), ['body' => 'Not mine to change.'])
            ->assertNotFound();

        $this->assertSame('We learned Surah Al-Fatiha today.', $this->row($post)->body);
        $this->assertNull($this->row($post)->edited_at);
        $this->assertNothingWasSent();
    }

    #[Test]
    public function a_foreign_organisation_cannot_edit_the_story_in_either_realm(): void
    {
        $post = $this->makePost();
        $foreignClass = Group::factory()->create([
            'masjid_id' => $this->otherSchool->id, 'kind' => Group::KIND_CLASS, 'name' => 'Grade 1', 'slug' => 'grade-1',
        ]);
        $foreignTeacher = $this->makeTeacher($this->otherSchool, $foreignClass, 'Ustadh Elsewhere');
        $foreignPost = $this->makePost($foreignTeacher, 'Their story.', $foreignClass);

        // Their login at OUR school's URL.
        $this->asTeacher($foreignTeacher)
            ->putJson($this->teacherUrl("/posts/{$post->id}"), ['body' => 'Hijacked.'])
            ->assertStatus(403);

        // Our teacher at THEIR class and post.
        $this->asTeacher()
            ->putJson("/api/teacher/masjids/{$this->school->id}/groups/{$foreignClass->id}/posts/{$foreignPost->id}", ['body' => 'Hijacked.'])
            ->assertStatus(404);

        // Our office, with its own school bound, naming their ids: a miss.
        $office = $this->makeLeadingAdmin();
        $this->asUser($office)
            ->putJson("/api/admin/masjids/{$this->school->id}/groups/{$foreignClass->id}/posts/{$foreignPost->id}", ['body' => 'Hijacked.'])
            ->assertStatus(404);

        // Their office at our school's id and ids.
        $foreignOffice = User::factory()->create(['type' => 'MasjidAdmin', 'name' => 'Foreign Office', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
        $this->otherSchool->user_id = $foreignOffice->id;
        $this->otherSchool->save();
        $this->asUser($foreignOffice)
            ->putJson($this->adminUrl("/posts/{$post->id}"), ['body' => 'Hijacked.'])
            ->assertStatus(403);

        $this->assertSame('We learned Surah Al-Fatiha today.', $this->row($post)->body);
        $this->assertSame('Their story.', $this->row($foreignPost)->body);
        $this->assertNull($this->row($post)->edited_at);
        $this->assertNull($this->row($foreignPost)->edited_at);

        // The tenant scope still hides the other school's story from a bound school.
        app(TenantContext::class)->set($this->school->id);
        $this->assertNull(GroupPost::query()->find($foreignPost->id));
        app(TenantContext::class)->forgetTenant();
    }

    // ================================================================ the read gate (the hole)

    #[Test]
    public function an_office_administrator_who_may_not_read_the_class_feed_cannot_edit_or_read_back_a_sent_story(): void
    {
        $post = $this->makePost(body: 'Amina had a hard day and cried at drop-off.');
        $office = $this->makeAdmin();   // `manage contacts`, the school's owner, NOT on the class roster

        // The feed turns this person away...
        $this->asUser($office)->getJson($this->adminUrl('/posts'))->assertForbidden();

        // ...and so must the write that hands the story back. It used to answer 200 with the words.
        $response = $this->asUser($office)
            ->putJson($this->adminUrl("/posts/{$post->id}"), ['body' => 'Changed without ever reading it.'])
            ->assertForbidden();

        $this->assertStringNotContainsString('hard day', $response->getContent());
        $this->assertStringNotContainsString('cried', $response->getContent());
        $this->assertSame('Amina had a hard day and cried at drop-off.', $this->row($post)->body);
        $this->assertNull($this->row($post)->edited_at);
    }

    #[Test]
    public function the_edit_response_is_media_gated_like_the_feed(): void
    {
        $post = $this->makePost();
        \App\Support\GroupPostAttachments::store($post, [UploadedFile::fake()->image('class.jpg')]);

        // An office administrator who reads the class only as a parent with FEED-only consent:
        // may read the words, may not have the pictures.
        $office = $this->makeAdmin();
        $person = Contact::factory()->create(['masjid_id' => $this->school->id, 'email' => $office->email]);
        $child = Contact::factory()->create(['masjid_id' => $this->school->id, 'first_name' => 'Idris', 'last_name' => 'Admin', 'email' => null]);
        GroupMembership::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id,
            'contact_id' => $child->id, 'role' => GroupMembership::ROLE_MEMBER,
        ]);
        GroupMembership::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id,
            'contact_id' => $person->id, 'role' => GroupMembership::ROLE_GUARDIAN,
            'guardian_of_contact_id' => $child->id,
            'consent_granted_at' => now(), 'consent_scope' => GroupMembership::CONSENT_FEED,
        ]);

        $this->asUser($office)->getJson($this->adminUrl('/posts'))->assertOk()
            ->assertJsonPath('data.data.0.attachments', [])
            ->assertJsonPath('data.data.0.media_withheld', true);

        $this->asUser($office)->putJson($this->adminUrl("/posts/{$post->id}"), ['body' => 'Fixed a date.'])
            ->assertOk()
            ->assertJsonPath('data.body', 'Fixed a date.')
            ->assertJsonPath('data.attachments', [])
            ->assertJsonPath('data.media_withheld', true)
            ->assertJsonPath('meta.may_receive_media', false);

        // The teacher of the class has the pictures back.
        $this->edit($post, ['body' => 'Fixed a date again.'])->assertOk()
            ->assertJsonPath('data.attachments.0.file_name', 'class.jpg')
            ->assertJsonPath('meta.may_receive_media', true);
    }

    #[Test]
    public function can_edit_is_true_exactly_when_the_put_would_be_allowed(): void
    {
        $post = $this->makePost();
        $co = $this->coTeacher();
        $leadingOffice = $this->makeLeadingAdmin();

        $this->asTeacher()->getJson($this->teacherUrl("/posts/{$post->id}"))->assertJsonPath('data.can_edit', true);
        $this->asTeacher($co)->getJson($this->teacherUrl("/posts/{$post->id}"))->assertJsonPath('data.can_edit', true);
        $this->asUser($leadingOffice)->getJson($this->adminUrl("/posts/{$post->id}"))->assertJsonPath('data.can_edit', true);

        // An administrator who holds only `view contacts` and reads the class as a consented
        // parent can read the story, and the write route would refuse her: not offered.
        $viewer = $this->makeAdmin();
        $viewer->syncRoles([]);
        $viewer->givePermissionTo(['view contacts']);
        $person = Contact::factory()->create(['masjid_id' => $this->school->id, 'email' => $viewer->email]);
        $child = Contact::factory()->create(['masjid_id' => $this->school->id, 'first_name' => 'Idris', 'last_name' => 'Viewer', 'email' => null]);
        GroupMembership::create(['masjid_id' => $this->school->id, 'group_id' => $this->class->id, 'contact_id' => $child->id, 'role' => GroupMembership::ROLE_MEMBER]);
        GroupMembership::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id, 'contact_id' => $person->id,
            'role' => GroupMembership::ROLE_GUARDIAN, 'guardian_of_contact_id' => $child->id,
            'consent_granted_at' => now(), 'consent_scope' => GroupMembership::CONSENT_FEED,
        ]);

        $this->asUser($viewer)->getJson($this->adminUrl("/posts/{$post->id}"))->assertOk()->assertJsonPath('data.can_edit', false);
        $this->asUser($viewer)->putJson($this->adminUrl("/posts/{$post->id}"), ['body' => 'No.'])->assertForbidden();
        $this->assertNull($this->row($post)->edited_at);

        // And never for a story that is not out yet.
        $later = $this->scheduledPost();
        $this->asTeacher()->getJson($this->teacherUrl("/posts/{$later->id}"))->assertJsonPath('data.can_edit', false);
    }

    #[Test]
    public function the_office_reads_a_scheduled_story_as_metadata_with_no_edit_control(): void
    {
        $later = $this->scheduledPost();
        $office = $this->makeAdmin();

        $row = $this->asUser($office)->getJson($this->adminUrl('/posts?scheduled=1'))->assertOk()->json('data.data.0');

        $this->assertTrue($row['content_hidden']);
        $this->assertArrayNotHasKey('can_edit', $row);
        $this->assertArrayNotHasKey('edited_at', $row);
        $this->assertArrayNotHasKey('body', $row);

        // And the office still may not edit it (author only), as before.
        $this->asUser($office)->putJson($this->adminUrl("/posts/{$later->id}"), ['body' => 'Office edit'])->assertForbidden();
    }

    // ================================================================ the deploy window

    #[Test]
    public function before_the_migration_an_edit_that_would_need_the_marker_fails_closed_without_losing_anything(): void
    {
        $post = $this->makePost(body: 'Original words.');

        Schema::table('group_posts', fn ($table) => $table->dropColumn('edited_at'));

        // A read is null-safe.
        $this->asTeacher()->getJson($this->teacherUrl('/posts'))->assertOk()->assertJsonPath('data.data.0.edited_at', null);

        // A real change to a sent story: a clear 503, never a 500, and nothing half-written.
        $this->edit($post)->assertStatus(503);
        $this->assertSame('Original words.', GroupPost::withoutMasjidScope()->findOrFail($post->id)->body);

        // A save that changes nothing needs no marker and still works.
        $this->asTeacher()->putJson($this->teacherUrl("/posts/{$post->id}"), ['body' => 'Original words.'])->assertOk();

        // A scheduled story's edit needs none either.
        $later = $this->scheduledPost();
        $this->edit($later, ['body' => 'Still fine.'])->assertOk();
        $this->assertSame('Still fine.', GroupPost::withoutMasjidScope()->findOrFail($later->id)->body);
    }

    #[Test]
    public function the_migration_adds_one_nullable_datetime_and_drops_cleanly(): void
    {
        $this->assertTrue(Schema::hasColumn('group_posts', 'edited_at'));

        $column = collect(Schema::getColumns('group_posts'))->firstWhere('name', 'edited_at');
        $this->assertTrue($column['nullable']);
        $this->assertNull($column['default']);

        $migration = require base_path('database/migrations/2026_10_08_100000_add_edited_at_to_group_posts_table.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('group_posts', 'edited_at'));
        $migration->up();
        $this->assertTrue(Schema::hasColumn('group_posts', 'edited_at'));
    }
}
