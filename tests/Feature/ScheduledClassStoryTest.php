<?php

namespace Tests\Feature;

use App\Enums\GroupNotificationEvent;
use App\Jobs\SendGroupNotificationJob;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupPost;
use App\Models\GroupPostAttachment;
use App\Models\GroupPostReaction;
use App\Models\GroupPostRead;
use App\Models\GroupStaff;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use App\Support\GroupAudience;
use App\Support\GroupMedia;
use App\Support\GroupPostAttachments;
use App\Support\TenantContext;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Support\BuildsClassStoryFixture;
use Tests\TestCase;

/**
 * SCHEDULED CLASS STORIES (T-002.4, owner 2026-09-29): a teacher writes a story now
 * and it reaches families later.
 *
 * THE HIGHEST LEAK RISK IN THE PLAN, so most of this file is one question asked of
 * every door: **can a family see a story before its time?** A story is out when its
 * `published_at` has come (GroupPost::scopePublished), and every place a parent can
 * reach a post has to say so. There is one test per door, and each one first proves
 * the door is OPEN for an ordinary story (a control), because a test that asks a
 * closed door for a scheduled post proves nothing:
 *
 *   the feed, one story, the "seen" POST (W2), a reaction PUT and DELETE (W2), a
 *   photo download, a playback ticket, and the playback stream a ticket buys.
 *
 * Then who ELSE may see one (a teacher of the class, the office; never a parent who
 * is also an administrator), who may change one (the author and the office, not a
 * co-teacher), what the school clock means, and what the sweep does, including the
 * S15 rule: the author left the class, so it is NOT sent and never appears.
 *
 * The sweep that announces and refuses scheduled stories is `ScheduledSweepTest`.
 *
 * Mutation-proved: each guard named in the comments was removed in turn and a test
 * here went red (see DECISIONS.md 2026-09-29, school side quest W5).
 */
class ScheduledClassStoryTest extends TestCase
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
        Bus::fake([SendGroupNotificationJob::class]);
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->buildStoryWorld();
        // The school keeps its own clock; a teacher's "10:00" is 10:00 here, not UTC.
        $this->school->forceFill(['timezone' => 'America/New_York'])->save();
        $this->consent($this->parentA, GroupMembership::CONSENT_MEDIA);
        $this->consent($this->parentB, GroupMembership::CONSENT_MEDIA);
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

    private function withPhoto(GroupPost $post): GroupPostAttachment
    {
        return GroupPostAttachments::store($post, [UploadedFile::fake()->create('garden.jpg', 5, 'image/jpeg')])[0];
    }

    private function withVideo(GroupPost $post): GroupPostAttachment
    {
        $copy = tempnam(sys_get_temp_dir(), 'vid').'.mp4';
        copy(base_path('tests/fixtures/tiny.mp4'), $copy);

        return GroupPostAttachments::store($post, [new UploadedFile($copy, 'recital.mp4', null, null, true)])[0];
    }

    private function familyIds(?Contact $parent = null): array
    {
        return array_map('intval', $this->asParent($parent ?? $this->parentA)
            ->getJson($this->familyUrl('/posts'))->assertOk()->json('data.data.*.id'));
    }

    private function coTeacher(): User
    {
        return $this->makeTeacher($this->school, $this->class, 'Ustadha Sara');
    }

    /** An administrator who can read the class ONLY as a consented parent of a child in it. */
    private function guardianOnlyAdmin(bool $manage = false): User
    {
        $admin = $this->makeAdmin();

        // A directly-granted view permission and no role: may reach the routes, holds
        // no `manage contacts`.
        $admin->syncRoles([]);
        $admin->givePermissionTo($manage ? ['view contacts', 'manage contacts'] : ['view contacts']);

        $child = Contact::factory()->create([
            'masjid_id' => $this->school->id, 'first_name' => 'Idris', 'last_name' => 'Admin', 'email' => null,
        ]);
        GroupMembership::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id,
            'contact_id' => $child->id, 'role' => GroupMembership::ROLE_MEMBER,
        ]);
        $person = Contact::factory()->create(['masjid_id' => $this->school->id, 'email' => $admin->email]);
        GroupMembership::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id,
            'contact_id' => $person->id, 'role' => GroupMembership::ROLE_GUARDIAN,
            'guardian_of_contact_id' => $child->id,
            'consent_granted_at' => now(), 'consent_scope' => GroupMembership::CONSENT_MEDIA,
        ]);

        return $admin;
    }

    /** The minute-by-minute sweep, run now: it is what announces a scheduled story (and so makes it visible). */
    private function sweep(array $args = []): string
    {
        Artisan::call('groups:publish-due', $args);

        return Artisan::output();
    }

    private function authorLeaves(?User $author = null): void
    {
        GroupStaff::withoutMasjidScope()
            ->where('user_id', ($author ?? $this->teacher)->id)
            ->where('group_id', $this->class->id)
            ->delete();
    }

    private function classStoryJobs(): int
    {
        return Bus::dispatched(SendGroupNotificationJob::class, fn ($job) => $job->event === GroupNotificationEvent::CLASS_STORY)->count();
    }

    // ============================================== THE LEAK: one test per door

    #[Test]
    public function the_family_feed_lists_a_published_story_and_not_a_scheduled_or_failed_one(): void
    {
        $out = $this->makePost(body: 'Already out');
        $later = $this->scheduledPost('+2 days');
        $failed = $this->scheduledPost('+2 days');
        $failed->forceFill(['publish_failed_at' => now(), 'publish_failure' => 'The author no longer teaches this class.'])->save();

        $response = $this->asParent($this->parentA)->getJson($this->familyUrl('/posts'))->assertOk();

        // The control: the door is open for an ordinary story.
        $this->assertSame([$out->id], array_map('intval', $response->json('data.data.*.id')));
        $this->assertSame(1, $response->json('data.total'));
        $this->assertStringNotContainsString('garden', $response->getContent());
        $this->assertNotContains($later->id, $this->familyIds());
        $this->assertNotContains($failed->id, $this->familyIds());
    }

    #[Test]
    public function one_scheduled_story_is_a_404_for_a_family_and_a_published_one_is_a_200(): void
    {
        $out = $this->makePost();
        $later = $this->scheduledPost();

        $this->asParent($this->parentA)->getJson($this->familyUrl("/posts/{$out->id}"))->assertOk();

        $response = $this->asParent($this->parentA)->getJson($this->familyUrl("/posts/{$later->id}"));
        $response->assertNotFound();
        $this->assertStringNotContainsString('garden', $response->getContent());
    }

    #[Test]
    public function the_seen_post_records_a_published_story_and_ignores_a_scheduled_one_silently(): void
    {
        config(['groups.story_reads.enabled' => true]);
        $out = $this->makePost();
        $later = $this->scheduledPost();

        $response = $this->asParent($this->parentA)
            ->postJson($this->familyUrl('/posts/seen'), ['post_ids' => [$out->id, $later->id]])
            ->assertOk();

        // One recorded: the control (the POST works) and the leak (the other is not
        // recorded), and the reply does not confirm the scheduled story exists.
        $this->assertSame(1, $response->json('data.recorded'));
        $this->assertSame([$out->id], GroupPostRead::withoutMasjidScope()->pluck('group_post_id')->map(fn ($id) => (int) $id)->all());
    }

    #[Test]
    public function a_family_reaction_on_a_published_story_lands_and_on_a_scheduled_one_is_a_404_with_nothing_written(): void
    {
        $out = $this->makePost();
        $later = $this->scheduledPost();

        $this->asParent($this->parentA)->putJson($this->familyUrl("/posts/{$out->id}/reactions/ameen"))->assertOk();
        $this->assertSame(1, GroupPostReaction::withoutMasjidScope()->count());

        $this->asParent($this->parentA)->putJson($this->familyUrl("/posts/{$later->id}/reactions/ameen"))->assertNotFound();
        $this->asParent($this->parentA)->deleteJson($this->familyUrl("/posts/{$later->id}/reactions/ameen"))->assertNotFound();

        $this->assertSame(1, GroupPostReaction::withoutMasjidScope()->count());
        $this->assertSame(0, GroupPostReaction::withoutMasjidScope()->where('group_post_id', $later->id)->count());
    }

    #[Test]
    public function a_family_downloads_a_published_photo_and_is_refused_a_scheduled_ones(): void
    {
        $out = $this->makePost();
        $photo = $this->withPhoto($out);
        $later = $this->scheduledPost();
        $hidden = $this->withPhoto($later);

        $this->asParent($this->parentA)
            ->get($this->familyUrl("/posts/{$out->id}/attachments/{$photo->id}"))
            ->assertOk();

        $this->asParent($this->parentA)
            ->get($this->familyUrl("/posts/{$later->id}/attachments/{$hidden->id}"))
            ->assertNotFound();
    }

    #[Test]
    public function a_family_playback_ticket_is_minted_for_a_published_video_and_refused_for_a_scheduled_one(): void
    {
        $out = $this->makePost();
        $video = $this->withVideo($out);
        $later = $this->scheduledPost();
        $hidden = $this->withVideo($later);

        $this->asParent($this->parentA)
            ->postJson($this->familyUrl("/posts/{$out->id}/attachments/{$video->id}/playback"))
            ->assertOk()
            ->assertJsonStructure(['data' => ['url', 'expires_in']]);

        $this->asParent($this->parentA)
            ->postJson($this->familyUrl("/posts/{$later->id}/attachments/{$hidden->id}/playback"))
            ->assertNotFound();
    }

    #[Test]
    public function the_playback_stream_serves_a_family_ticket_only_once_the_story_is_out(): void
    {
        $later = $this->scheduledPost();
        $video = $this->withVideo($later);

        // A ticket for a story that is not out: however it was obtained (a URL the
        // parent guessed, one minted earlier and the story since pulled back), the
        // stream re-asks and plays nothing.
        $url = GroupMedia::postTicket(
            $this->school->id, $this->class->id, $later->id, $video->id,
            GroupMedia::VIEWER_FAMILY, $this->parentA->id
        );

        $this->flushHeaders()->get($url)->assertNotFound();

        // The same ticket the moment it is out (its time has come and the sweep announced it).
        Carbon::setTestNow(now()->addDays(3));
        $this->sweep();

        // (A ticket is short-lived, so the one asked after the wait is minted after it.)
        $url = GroupMedia::postTicket(
            $this->school->id, $this->class->id, $later->id, $video->id,
            GroupMedia::VIEWER_FAMILY, $this->parentA->id
        );

        $this->flushHeaders()->get($url)->assertOk();
    }

    #[Test]
    public function the_teacher_who_wrote_it_can_preview_a_scheduled_video_but_a_parent_who_is_also_an_admin_cannot(): void
    {
        $later = $this->scheduledPost();
        $video = $this->withVideo($later);

        $ticket = (string) $this->asTeacher()
            ->postJson($this->teacherUrl("/posts/{$later->id}/attachments/{$video->id}/playback"))
            ->assertOk()->json('data.url');

        $this->flushHeaders()->get($ticket)->assertOk();

        // A staff ticket minted for somebody who is only a parent of the class is
        // re-asked at the stream, per range, and refused.
        $admin = $this->guardianOnlyAdmin();
        $forged = GroupMedia::postTicket(
            $this->school->id, $this->class->id, $later->id, $video->id,
            GroupMedia::VIEWER_STAFF, $admin->id
        );

        $this->flushHeaders()->get($forged)->assertNotFound();
    }

    #[Test]
    public function a_story_appears_at_the_second_its_time_comes_and_not_before(): void
    {
        $later = $this->scheduledPost('+1 hour');

        Carbon::setTestNow(now()->addHour()->subSecond());
        $this->sweep();
        $this->assertNotContains($later->id, $this->familyIds());

        Carbon::setTestNow(now()->addSecond());
        $this->sweep();
        $this->assertContains($later->id, $this->familyIds());
        $this->asParent($this->parentA)->getJson($this->familyUrl("/posts/{$later->id}"))->assertOk();
    }

    #[Test]
    public function a_failed_story_never_becomes_visible_when_its_time_passes(): void
    {
        $later = $this->scheduledPost('+1 hour');
        $later->forceFill(['publish_failed_at' => now(), 'publish_failure' => 'The author no longer teaches this class.'])->save();

        Carbon::setTestNow(now()->addDays(3));

        $this->assertNotContains($later->id, $this->familyIds());
        $this->asParent($this->parentA)->getJson($this->familyUrl("/posts/{$later->id}"))->assertNotFound();
    }

    #[Test]
    public function a_row_written_by_code_that_predates_the_column_reads_as_published(): void
    {
        // The deploy seconds: an old-code INSERT that knows nothing of `published_at`.
        $id = DB::table('group_posts')->insertGetId([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id,
            'author_user_id' => $this->teacher->id, 'title' => 'Legacy', 'body' => 'Written before scheduling existed.',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertNull(DB::table('group_posts')->find($id)->published_at);
        $this->assertContains($id, $this->familyIds());
    }

    #[Test]
    public function the_family_feed_is_ordered_by_when_stories_went_out_not_when_they_were_typed(): void
    {
        // Typed FIRST, released LAST.
        $typedFirst = $this->scheduledPost('+1 hour');
        $typedFirst->forceFill(['created_at' => now()->subDays(5)])->save();
        Carbon::setTestNow(now()->addHours(2));
        $typedLater = $this->makePost();   // out at +2h, typed at +2h
        $this->sweep();                    // announces the scheduled one, now due

        // The scheduled one went out at +1h; the other at +2h; newest first is
        // therefore the one typed later, then the scheduled one, both by published_at.
        $this->assertSame([$typedLater->id, $typedFirst->id], $this->familyIds());

        // Swap them: the scheduled story released after the ordinary one comes first,
        // although its `created_at` is five days older.
        $typedFirst->forceFill(['published_at' => now()->addMinute()])->save();
        Carbon::setTestNow(now()->addMinutes(2));
        $this->assertSame([$typedFirst->id, $typedLater->id], $this->familyIds());
    }

    #[Test]
    public function the_family_payload_shows_when_a_story_went_out_and_none_of_the_staff_scheduling_fields(): void
    {
        $post = $this->scheduledPost('+1 hour');
        Carbon::setTestNow(now()->addHours(2));
        $this->sweep();

        $json = $this->asParent($this->parentA)->getJson($this->familyUrl("/posts/{$post->id}"))->assertOk();

        $this->assertNotNull($json->json('data.published_at'));
        foreach (['status', 'publish_failure', 'publish_failed_at', 'announced_at', 'published_at_local', 'can_change_schedule'] as $staffOnly) {
            $this->assertArrayNotHasKey($staffOnly, $json->json('data'), "the family payload carries {$staffOnly}");
        }
    }

    // ============================================================ who sees one

    #[Test]
    public function the_default_staff_feed_shows_what_families_see_and_the_scheduled_list_shows_the_rest(): void
    {
        $out = $this->makePost();
        $later = $this->scheduledPost();
        $failed = $this->scheduledPost('+3 days');
        $failed->forceFill(['publish_failed_at' => now(), 'publish_failure' => 'The author no longer teaches this class.'])->save();

        $feed = $this->asTeacher()->getJson($this->teacherUrl('/posts'))->assertOk();
        $this->assertSame([$out->id], array_map('intval', $feed->json('data.data.*.id')));

        $list = $this->asTeacher()->getJson($this->teacherUrl('/posts?scheduled=1'))->assertOk();
        $this->assertEqualsCanonicalizing([$later->id, $failed->id], array_map('intval', $list->json('data.data.*.id')));

        $byId = collect($list->json('data.data'))->keyBy('id');
        $this->assertSame('scheduled', $byId[$later->id]['status']);
        $this->assertSame('failed', $byId[$failed->id]['status']);
        $this->assertSame('The author no longer teaches this class.', $byId[$failed->id]['publish_failure']);
        // The school's own clock, in the form the Send-later field takes.
        $this->assertSame('2026-10-03T08:00', $byId[$later->id]['published_at_local']);
        $this->assertSame('America/New_York', $list->json('meta.scheduling.timezone'));
        $this->assertSame(30, $list->json('meta.scheduling.max_days_ahead'));
    }

    #[Test]
    public function a_co_teacher_sees_the_scheduled_list_and_a_teacher_of_another_class_does_not(): void
    {
        $later = $this->scheduledPost();
        $co = $this->coTeacher();

        $this->asTeacher($co)->getJson($this->teacherUrl('/posts?scheduled=1'))
            ->assertOk()->assertJsonPath('data.data.0.id', $later->id);
        $this->asTeacher($co)->getJson($this->teacherUrl("/posts/{$later->id}"))->assertOk();

        $other = Group::factory()->create([
            'masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS, 'name' => 'Grade 2', 'slug' => 'grade-2',
        ]);
        $stranger = $this->makeTeacher($this->school, $other, 'Ustadh Omar');

        $this->asTeacher($stranger)->getJson($this->teacherUrl('/posts?scheduled=1'))->assertForbidden();
        $this->asTeacher($stranger)->getJson($this->teacherUrl("/posts/{$later->id}"))->assertForbidden();
    }

    #[Test]
    public function the_office_sees_a_scheduled_story_in_the_list_as_metadata_and_cannot_open_it(): void
    {
        $later = $this->scheduledPost();
        $this->withPhoto($later);
        $office = $this->guardianOnlyAdmin(manage: true);

        // S14 (point, 2026-09-30): the office sees WHEN it goes and who wrote it, never the words.
        $this->asUser($office)->getJson($this->adminUrl('/posts?scheduled=1'))
            ->assertOk()
            ->assertJsonPath('data.data.0.id', $later->id)
            ->assertJsonPath('data.data.0.content_hidden', true)
            ->assertJsonPath('data.data.0.can_cancel', true)
            ->assertJsonPath('data.data.0.can_change_schedule', false)
            ->assertJsonMissingPath('data.data.0.body')
            ->assertJsonMissingPath('data.data.0.title')
            ->assertJsonMissingPath('data.data.0.attachments');
        $this->assertStringNotContainsString(
            'Tomorrow',
            $this->asUser($office)->getJson($this->adminUrl('/posts?scheduled=1'))->getContent(),
            'no part of the words reaches the office before the story goes out'
        );
        // Opening it gives the same metadata, never the words or the photo.
        $one = $this->asUser($office)->getJson($this->adminUrl("/posts/{$later->id}"))
            ->assertOk()
            ->assertJsonPath('data.content_hidden', true)
            ->assertJsonMissingPath('data.body')
            ->assertJsonMissingPath('data.title')
            ->assertJsonMissingPath('data.attachments');
        $this->assertStringNotContainsString('Tomorrow', $one->getContent());

        // The class's teacher still reads it in full.
        $this->asTeacher()->getJson($this->teacherUrl('/posts?scheduled=1'))
            ->assertOk()->assertJsonPath('data.data.0.content_hidden', false)->assertJsonPath('data.data.0.body', $later->body);
    }

    #[Test]
    public function the_office_manages_a_scheduled_story_without_being_on_the_roster_and_still_cannot_read_the_feed(): void
    {
        $out = $this->makePost();
        $later = $this->scheduledPost();
        $office = $this->makeAdmin();   // the school's owner, with `manage contacts`, and NOT on the class roster

        // The feed is disclosure, and an administrator off the roster is refused it, as ever.
        $this->asUser($office)->getJson($this->adminUrl('/posts'))->assertForbidden();
        $this->asUser($office)->getJson($this->adminUrl("/posts/{$out->id}"))->assertForbidden();

        // S14 as decided (point, 2026-09-30): the office sees that a story is waiting and may
        // cancel it, but neither reads it nor moves it. The words stay with the class's teachers.
        $this->asUser($office)->getJson($this->adminUrl('/posts?scheduled=1'))
            ->assertOk()->assertJsonPath('data.data.0.id', $later->id)
            ->assertJsonPath('data.data.0.can_change_schedule', false)
            ->assertJsonPath('data.data.0.content_hidden', true)
            ->assertJsonMissingPath('data.data.0.body');
        $this->asUser($office)->getJson($this->adminUrl("/posts/{$later->id}"))
            ->assertOk()->assertJsonPath('data.content_hidden', true)->assertJsonMissingPath('data.body');
        $this->asUser($office)->putJson($this->adminUrl("/posts/{$later->id}"), ['send_at' => '2026-10-06T09:00'])
            ->assertForbidden();
        $this->assertSame($later->published_at->toDateTimeString(), $later->fresh()->published_at->toDateTimeString());
        $this->asUser($office)->deleteJson($this->adminUrl("/posts/{$later->id}"))->assertOk();
    }

    #[Test]
    public function an_administrator_who_reads_the_class_only_as_a_parent_never_sees_a_scheduled_story(): void
    {
        $out = $this->makePost();
        $later = $this->scheduledPost();
        $photo = $this->withPhoto($later);
        $admin = $this->guardianOnlyAdmin();

        // The control: as a consented parent they DO read the feed.
        $this->assertSame([$out->id], array_map('intval', $this->asUser($admin)
            ->getJson($this->adminUrl('/posts'))->assertOk()->json('data.data.*.id')));

        $this->asUser($admin)->getJson($this->adminUrl("/posts/{$later->id}"))->assertNotFound();
        $this->asUser($admin)->getJson($this->adminUrl('/posts?scheduled=1'))->assertForbidden();
        $this->asUser($admin)->get($this->adminUrl("/posts/{$later->id}/attachments/{$photo->id}"))->assertNotFound();
        $this->asUser($admin)->postJson($this->adminUrl("/posts/{$later->id}/attachments/{$photo->id}/playback"))->assertNotFound();
    }

    #[Test]
    public function reading_an_unsent_item_is_for_the_class_teachers_and_cancelling_it_also_for_the_office(): void
    {
        $audience = app(GroupAudience::class);
        app(TenantContext::class)->set($this->school->id);

        // S14 (point, 2026-09-30): reading the words is the class's teachers only.
        $this->assertTrue($audience->mayReadUnpublished($this->teacher, $this->class));
        $this->assertFalse($audience->mayReadUnpublished($this->guardianOnlyAdmin(manage: true), $this->class));
        $this->assertFalse($audience->mayReadUnpublished($this->guardianOnlyAdmin(), $this->class));
        $this->assertFalse($audience->mayReadUnpublished($this->parentA, $this->class));
        $this->assertFalse($audience->mayReadUnpublished(null, $this->class));

        // Seeing that it waits, and cancelling it: the class's teachers and the office.
        $this->assertTrue($audience->mayCancelScheduled($this->teacher, $this->class));
        $this->assertTrue($audience->mayCancelScheduled($this->guardianOnlyAdmin(manage: true), $this->class));
        $this->assertFalse($audience->mayCancelScheduled($this->guardianOnlyAdmin(), $this->class));
        $this->assertFalse($audience->mayCancelScheduled($this->parentA, $this->class));
        $this->assertFalse($audience->mayCancelScheduled(null, $this->class));
    }

    #[Test]
    public function nobody_reacts_to_a_scheduled_story_not_even_staff(): void
    {
        $later = $this->scheduledPost();

        $this->asTeacher()->putJson($this->teacherUrl("/posts/{$later->id}/reactions/ameen"))->assertNotFound();
        $this->asTeacher()->deleteJson($this->teacherUrl("/posts/{$later->id}/reactions/ameen"))->assertNotFound();

        $this->assertSame(0, GroupPostReaction::withoutMasjidScope()->count());
    }

    #[Test]
    public function the_reaction_digest_waits_for_a_story_that_is_not_out_and_then_announces_it_once(): void
    {
        $later = $this->scheduledPost('+1 day');
        // A row that should not exist (the endpoints refuse it) but does, as when a
        // story that had been out is put back on the schedule.
        GroupPostReaction::create([
            'masjid_id' => $this->school->id, 'group_post_id' => $later->id,
            'reaction' => 'ameen', 'contact_id' => $this->parentA->id,
        ]);
        GroupPostReaction::withoutMasjidScope()->update(['created_at' => now()->subHour()]);

        Artisan::call('groups:notify-reactions', ['--settle' => 0]);

        Bus::assertNotDispatched(SendGroupNotificationJob::class, fn ($job) => $job->event === GroupNotificationEvent::REACTION);
        // Left UNCLAIMED, not forgotten.
        $this->assertNull(GroupPostReaction::withoutMasjidScope()->sole()->notified_at);

        Carbon::setTestNow(now()->addDays(2));
        $this->sweep();   // announces the story, which is what puts it out
        Artisan::call('groups:notify-reactions', ['--settle' => 0]);

        $this->assertSame(1, Bus::dispatched(SendGroupNotificationJob::class, fn ($job) => $job->event === GroupNotificationEvent::REACTION)->count());
        $this->assertNotNull(GroupPostReaction::withoutMasjidScope()->sole()->notified_at);
    }

    // ============================================================== scheduling

    #[Test]
    public function a_story_scheduled_in_the_school_clock_is_stored_in_utc_and_announces_nothing(): void
    {
        // 10:00 on 5 October in New York is 14:00 UTC (EDT).
        $response = $this->asTeacher()
            ->postJson($this->teacherUrl('/posts'), ['body' => 'Garden day', 'send_at' => '2026-10-05T10:00'])
            ->assertCreated();

        $post = GroupPost::withoutMasjidScope()->sole();

        $this->assertSame('2026-10-05 14:00:00', $post->published_at->toDateTimeString());
        $this->assertNull($post->announced_at);
        $this->assertSame('scheduled', $response->json('data.status'));
        $this->assertSame('2026-10-05T10:00', $response->json('data.published_at_local'));
        $this->assertSame($this->teacher->id, (int) $post->author_user_id);
        $this->assertNotContains($post->id, $this->familyIds());

        // NOBODY hears of it before they can read it.
        $this->assertSame(0, $this->classStoryJobs());
    }

    #[Test]
    public function send_at_is_refused_unless_it_is_a_real_time_in_the_future_within_thirty_days(): void
    {
        $refused = [
            'in the past' => '2026-09-30T10:00',
            'right now' => '2026-10-01T08:00',
            'after 30 days' => '2026-11-01T10:00',
            'no time at all' => '2026-10-05',
            'not a date' => 'next tuesday',
            'a day that does not exist' => '2026-02-31T10:00',
            'an hour that does not exist' => '2026-10-05T24:30',
            'a rolled minute' => '2026-10-05T10:61',
        ];

        foreach ($refused as $why => $value) {
            $this->asTeacher()
                ->postJson($this->teacherUrl('/posts'), ['body' => 'x', 'send_at' => $value])
                ->assertStatus(422);
        }

        $this->assertSame(0, GroupPost::withoutMasjidScope()->count(), 'a refused schedule still wrote a story');

        // The edges that ARE allowed: a minute from now, and one minute inside 30 days
        // (NOW is 08:00 in New York; 30 days on is 2026-10-31 08:00).
        $this->asTeacher()->postJson($this->teacherUrl('/posts'), ['body' => 'a', 'send_at' => '2026-10-01T08:01'])->assertCreated();
        $this->asTeacher()->postJson($this->teacherUrl('/posts'), ['body' => 'b', 'send_at' => '2026-10-31T07:59'])->assertCreated();
        $this->asTeacher()->postJson($this->teacherUrl('/posts'), ['body' => 'c', 'send_at' => '2026-10-31T08:01'])->assertStatus(422);
    }

    #[Test]
    public function a_time_that_carries_its_own_offset_is_honoured_as_stated(): void
    {
        $this->asTeacher()
            ->postJson($this->teacherUrl('/posts'), ['body' => 'x', 'send_at' => '2026-10-05T10:00:00+02:00'])
            ->assertCreated();

        $this->assertSame('2026-10-05 08:00:00', GroupPost::withoutMasjidScope()->sole()->published_at->toDateTimeString());
    }

    #[Test]
    public function an_hour_that_does_not_exist_is_moved_on_and_the_server_says_what_it_kept(): void
    {
        // Clocks go forward at 02:00 on 14 March 2027: 02:30 never happens.
        Carbon::setTestNow('2027-03-10 12:00:00');

        $response = $this->asTeacher()
            ->postJson($this->teacherUrl('/posts'), ['body' => 'x', 'send_at' => '2027-03-14T02:30'])
            ->assertCreated();

        $this->assertSame('2027-03-14T03:30', $response->json('data.published_at_local'));
        $this->assertSame('2027-03-14 07:30:00', GroupPost::withoutMasjidScope()->sole()->published_at->toDateTimeString());
    }

    #[Test]
    public function a_form_encoded_client_can_schedule_and_send_now(): void
    {
        // The SPA posts form-encoded, where a checkbox is the STRING "true".
        $this->asTeacher()
            ->post($this->teacherUrl('/posts'), ['body' => 'Form', 'send_at' => '2026-10-05T10:00'])
            ->assertCreated();

        $post = GroupPost::withoutMasjidScope()->sole();

        $this->asTeacher()
            ->put($this->teacherUrl("/posts/{$post->id}"), ['send_now' => 'true'])
            ->assertOk()
            ->assertJsonPath('data.status', 'published');
    }

    #[Test]
    public function the_retention_window_counts_from_the_day_a_story_goes_out(): void
    {
        config(['groups.feed.retention_days' => 365]);

        $this->asTeacher()->postJson($this->teacherUrl('/posts'), ['body' => 'x', 'send_at' => '2026-10-21T10:00'])->assertCreated();

        // 21 Oct 2026 + 365 days, not 1 Oct + 365.
        $this->assertSame('2027-10-21', GroupPost::withoutMasjidScope()->sole()->retained_until->toDateString());
    }

    #[Test]
    public function the_office_may_schedule_a_story_too(): void
    {
        $office = $this->guardianOnlyAdmin(manage: true);

        $this->asUser($office)
            ->postJson($this->adminUrl('/posts'), ['body' => 'From the office', 'send_at' => '2026-10-05T10:00'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.can_change_schedule', true);

        $this->assertSame(0, $this->classStoryJobs());
    }

    // ============================================================ changing one

    #[Test]
    public function the_author_reschedules_a_story_and_it_stays_hidden_until_the_new_time(): void
    {
        $post = $this->scheduledPost('+2 days');
        $retained = $post->retained_until->toDateString();

        $response = $this->asTeacher()
            ->putJson($this->teacherUrl("/posts/{$post->id}"), ['send_at' => '2026-10-20T09:00'])
            ->assertOk();

        $this->assertSame('scheduled', $response->json('data.status'));
        $this->assertSame('2026-10-20 13:00:00', $post->fresh()->published_at->toDateTimeString());
        // The system-stamped window follows the new day.
        $this->assertNotSame($retained, $post->fresh()->retained_until->toDateString());
        $this->assertSame(now()->modify('2026-10-20 13:00:00')->addDays((int) config('groups.feed.retention_days'))->toDateString(), $post->fresh()->retained_until->toDateString());

        Carbon::setTestNow('2026-10-10 12:00:00');
        $this->assertNotContains($post->id, $this->familyIds());
    }

    #[Test]
    public function a_story_that_has_gone_out_cannot_be_rescheduled_or_sent_again(): void
    {
        $post = $this->makePost();

        $this->asTeacher()->putJson($this->teacherUrl("/posts/{$post->id}"), ['send_at' => '2026-10-05T10:00'])
            ->assertStatus(422)->assertJsonStructure(['data' => ['send_at']]);
        $this->asTeacher()->putJson($this->teacherUrl("/posts/{$post->id}"), ['send_now' => true])->assertStatus(422);

        $this->assertSame(self::NOW, $post->fresh()->published_at->toDateTimeString());
        $this->assertSame(0, $this->classStoryJobs());
    }

    #[Test]
    public function a_time_and_send_now_together_are_refused(): void
    {
        $post = $this->scheduledPost();

        $this->asTeacher()
            ->putJson($this->teacherUrl("/posts/{$post->id}"), ['send_at' => '2026-10-05T10:00', 'send_now' => true])
            ->assertStatus(422)->assertJsonStructure(['data' => ['send_now']]);
    }

    #[Test]
    public function a_co_teacher_can_see_a_scheduled_story_but_cannot_edit_send_or_cancel_it(): void
    {
        $post = $this->scheduledPost();
        $co = $this->coTeacher();

        $this->asTeacher($co)->putJson($this->teacherUrl("/posts/{$post->id}"), ['body' => 'Hijacked'])->assertForbidden();
        $this->asTeacher($co)->putJson($this->teacherUrl("/posts/{$post->id}"), ['send_now' => true])->assertForbidden();
        $this->asTeacher($co)->deleteJson($this->teacherUrl("/posts/{$post->id}"))->assertForbidden();

        $fresh = GroupPost::withoutMasjidScope()->findOrFail($post->id);
        $this->assertSame('Tomorrow we visit the garden.', $fresh->body);
        $this->assertTrue($fresh->isScheduled());
        $this->assertNull($fresh->deleted_at);
        $this->assertSame(0, $this->classStoryJobs());

        // The buttons are not offered to somebody who would be refused.
        $this->asTeacher($co)->getJson($this->teacherUrl("/posts/{$post->id}"))
            ->assertOk()->assertJsonPath('data.can_change_schedule', false);
        $this->asTeacher()->getJson($this->teacherUrl("/posts/{$post->id}"))
            ->assertJsonPath('data.can_change_schedule', true);
    }

    #[Test]
    public function the_office_cancels_but_cannot_edit_a_teachers_scheduled_story(): void
    {
        $post = $this->scheduledPost();
        $office = $this->guardianOnlyAdmin(manage: true);

        // S14 (point, 2026-09-30): editing needs the words, and the words are the teachers'.
        $this->asUser($office)->putJson($this->adminUrl("/posts/{$post->id}"), ['body' => 'Office edit'])->assertForbidden();
        $this->assertSame('Tomorrow we visit the garden.', $post->fresh()->body);
        $this->asUser($office)->putJson($this->adminUrl("/posts/{$post->id}"), ['send_now' => true])->assertForbidden();
        $this->assertTrue($post->fresh()->isScheduled());

        $this->asUser($office)->deleteJson($this->adminUrl("/posts/{$post->id}"))->assertOk();
        $this->assertNotNull(GroupPost::withoutMasjidScope()->withTrashed()->find($post->id)->deleted_at);
    }

    #[Test]
    public function a_new_time_puts_a_refused_story_back_and_a_text_edit_alone_does_not(): void
    {
        $post = $this->scheduledPost('+1 day');
        $post->forceFill(['publish_failed_at' => now(), 'publish_failure' => 'The author no longer teaches this class.'])->save();

        $this->asTeacher()->putJson($this->teacherUrl("/posts/{$post->id}"), ['body' => 'Fixed a typo'])
            ->assertOk()->assertJsonPath('data.status', 'failed');

        $this->asTeacher()->putJson($this->teacherUrl("/posts/{$post->id}"), ['send_at' => '2026-10-05T10:00'])
            ->assertOk()->assertJsonPath('data.status', 'scheduled')->assertJsonPath('data.publish_failure', null);

        $this->assertNull($post->fresh()->publish_failed_at);
    }

    #[Test]
    public function a_co_teacher_may_still_edit_a_story_that_is_out_as_before(): void
    {
        $post = $this->makePost();
        $co = $this->coTeacher();

        $this->asTeacher($co)->putJson($this->teacherUrl("/posts/{$post->id}"), ['body' => 'Edited by a colleague'])->assertOk();
        $this->assertSame('Edited by a colleague', $post->fresh()->body);
    }

    // ================================================================ the sweep

    #[Test]
    public function the_scheduling_columns_are_nullable_datetimes_and_every_index_name_fits_mysql(): void
    {
        foreach (['published_at', 'announced_at', 'publish_failed_at'] as $column) {
            $info = collect(Schema::getColumns('group_posts'))->firstWhere('name', $column);

            $this->assertNotNull($info, $column);
            $this->assertTrue($info['nullable'], "{$column} must be nullable (no ->change() on a live table)");
            $this->assertStringContainsString('datetime', strtolower($info['type']), "{$column}: a timestamp() would carry MySQL's implicit ON UPDATE");
        }

        $reason = collect(Schema::getColumns('group_posts'))->firstWhere('name', 'publish_failure');
        $this->assertStringContainsString('varchar', strtolower($reason['type']));

        foreach (['group_posts', 'group_message_schedules'] as $table) {
            foreach (Schema::getIndexes($table) as $index) {
                $this->assertLessThanOrEqual(64, strlen($index['name']), "MySQL caps an index name at 64: {$index['name']}");
            }
        }
    }

    #[Test]
    public function the_migration_rolls_back_and_forward_cleanly(): void
    {
        $migration = require database_path('migrations/2026_10_04_100000_add_scheduling_to_group_posts_table.php');

        $migration->down();
        foreach (['published_at', 'announced_at', 'publish_failed_at', 'publish_failure'] as $column) {
            $this->assertFalse(Schema::hasColumn('group_posts', $column));
        }

        $migration->up();
        foreach (['published_at', 'announced_at', 'publish_failed_at', 'publish_failure'] as $column) {
            $this->assertTrue(Schema::hasColumn('group_posts', $column));
        }
    }

    #[Test]
    public function a_story_is_invisible_to_a_school_that_did_not_write_it(): void
    {
        $a = $this->makeMasjid();
        $b = $this->makeMasjid();

        $mk = function (Masjid $masjid, string $name) {
            $group = Group::factory()->create([
                'masjid_id' => $masjid->id, 'kind' => Group::KIND_CLASS, 'name' => $name, 'slug' => strtolower($name),
            ]);
            $teacher = $this->makeTeacher($masjid, $group, 'Teacher '.$name);

            return GroupPost::create([
                'masjid_id' => $masjid->id, 'group_id' => $group->id, 'author_user_id' => $teacher->id,
                'body' => 'Scheduled in '.$name, 'published_at' => now()->addDay(),
            ]);
        };

        $inA = $mk($a, 'Alpha');
        $inB = $mk($b, 'Beta');

        app(TenantContext::class)->set($a->id);

        $this->assertNull(GroupPost::scheduled()->find($inB->id));
        $this->assertSame(0, GroupPost::query()->where('id', $inB->id)->update(['body' => 'Hijacked']));
        $this->assertSame(1, GroupPost::scheduled()->count());
        $this->assertSame($a->id, (int) GroupPost::scheduled()->sole()->masjid_id);

        app(TenantContext::class)->forgetTenant();
        $this->assertSame('Scheduled in Beta', GroupPost::withoutMasjidScope()->findOrFail($inB->id)->body);
    }

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
    }

    // ====================== OUTAGE: a late sweep delays a story, it never leaks one

    #[Test]
    public function a_sweep_that_is_down_at_the_time_delays_a_story_and_the_next_sweep_releases_it(): void
    {
        $post = $this->scheduledPost('+1 hour');

        // The time comes and passes with no sweep at all (a killed run, a held mutex, cron down).
        Carbon::setTestNow(now()->addHour()->addMinutes(10));

        $this->assertNotContains($post->id, $this->familyIds());
        $this->asParent($this->parentA)->getJson($this->familyUrl("/posts/{$post->id}"))->assertNotFound();
        $this->asParent($this->parentA)->putJson($this->familyUrl("/posts/{$post->id}/reactions/ameen"))->assertNotFound();
        $this->assertSame(0, $this->classStoryJobs());

        // The office still sees it in its Scheduled list, as waiting.
        $this->asTeacher()->getJson($this->teacherUrl('/posts?scheduled=1'))
            ->assertOk()->assertJsonPath('data.data.0.status', 'scheduled');

        $this->sweep();

        $this->assertContains($post->id, $this->familyIds());
        $this->assertSame(1, $this->classStoryJobs());
    }

    #[Test]
    public function a_story_whose_author_left_is_never_on_a_family_screen_however_late_the_sweep_is(): void
    {
        $post = $this->scheduledPost('+1 hour');
        $this->authorLeaves();

        // No sweep runs in the two minutes before the time, nor after it: the story is
        // due, and on the clock alone it would be visible to every family.
        Carbon::setTestNow(now()->addHour()->addMinutes(10));
        $this->assertNotContains($post->id, $this->familyIds(), 'a story whose author left was readable before the sweep asked');
        $this->asParent($this->parentA)->getJson($this->familyUrl("/posts/{$post->id}"))->assertNotFound();

        // The sweep comes back: it refuses, and nobody ever saw it, so nothing is pulled back.
        $this->assertStringContainsString('refused=1', $this->sweep());
        $this->assertNotContains($post->id, $this->familyIds());
        $this->assertSame(0, $this->classStoryJobs());
        $this->assertNull($post->fresh()->announced_at);
        $this->assertNotNull($post->fresh()->publish_failed_at);
    }

    #[Test]
    public function only_the_announcement_makes_a_due_story_visible_and_the_row_agrees_with_the_query(): void
    {
        $due = $this->scheduledPost('+1 hour');
        Carbon::setTestNow(now()->addHours(2));

        // Due but not announced: not out, on the query and on the row, still on the Scheduled list.
        $this->assertFalse($due->fresh()->isPublished());
        $this->assertTrue($due->fresh()->isScheduled());
        $this->assertSame(0, GroupPost::withoutMasjidScope()->published()->count());
        $this->assertSame(1, GroupPost::withoutMasjidScope()->unpublished()->count());
        $this->assertSame(1, GroupPost::withoutMasjidScope()->scheduled()->count());

        $this->sweep();

        $this->assertTrue($due->fresh()->isPublished());
        $this->assertFalse($due->fresh()->isScheduled());
        $this->assertSame(1, GroupPost::withoutMasjidScope()->published()->count());
        $this->assertSame(0, GroupPost::withoutMasjidScope()->unpublished()->count());
        $this->assertSame(0, GroupPost::withoutMasjidScope()->scheduled()->count());
    }

    #[Test]
    public function the_announcement_claim_refuses_a_story_whose_time_has_not_come(): void
    {
        $post = $this->scheduledPost('+1 day');

        $this->assertFalse(app(\App\Services\Groups\GroupStoryPublisher::class)->announce($post));

        $this->assertNull($post->fresh()->announced_at);
        $this->assertSame(0, $this->classStoryJobs());
    }

    #[Test]
    public function a_reschedule_that_races_the_sweep_neither_emails_early_nor_pulls_back_an_announced_story(): void
    {
        $post = $this->scheduledPost('+1 minute');
        Carbon::setTestNow(now()->addMinutes(2));   // due, and waiting for the sweep

        // The sweep announces it in the instant between the controller's first look at the
        // row and the transaction that moves it.
        $once = false;
        DB::connection()->beforeStartingTransaction(function () use ($post, &$once): void {
            if ($once) {
                return;
            }
            $once = true;
            app(\App\Services\Groups\GroupStoryPublisher::class)->announce($post);
        });

        $due = $post->fresh()->published_at->toDateTimeString();

        $this->asTeacher()
            ->putJson($this->teacherUrl("/posts/{$post->id}"), ['send_at' => '2026-10-20T09:00'])
            ->assertStatus(422)->assertJsonStructure(['data' => ['send_at']]);

        $fresh = $post->fresh();
        $this->assertSame($due, $fresh->published_at->toDateTimeString(), 'an announced story was moved back to a later time');
        $this->assertNotNull($fresh->announced_at);
        $this->assertSame(1, $this->classStoryJobs());
        $this->assertContains($post->id, $this->familyIds());
    }

    #[Test]
    public function a_due_story_that_was_not_announced_yet_can_still_be_rescheduled_and_is_then_emailed_at_the_new_time(): void
    {
        $post = $this->scheduledPost('+1 minute');
        Carbon::setTestNow(now()->addMinutes(2));

        $this->asTeacher()->putJson($this->teacherUrl("/posts/{$post->id}"), ['send_at' => '2026-10-20T09:00'])->assertOk();

        $this->sweep();
        $this->assertSame(0, $this->classStoryJobs(), 'a story moved to a later time was emailed early');
        $this->assertNotContains($post->id, $this->familyIds());

        Carbon::setTestNow('2026-10-20 13:30:00');
        $this->sweep();
        $this->assertSame(1, $this->classStoryJobs());
        $this->assertContains($post->id, $this->familyIds());
    }

    // ===================== the family payload's date, and the office story tab's

    #[Test]
    public function the_family_payload_dates_a_released_story_by_when_it_went_out_not_when_it_was_typed(): void
    {
        $post = $this->scheduledPost('+3 days');   // typed 2026-10-01 12:00, out 2026-10-04 12:00
        Carbon::setTestNow(now()->addDays(3)->addMinute());
        $this->sweep();

        $feed = $this->asParent($this->parentA)->getJson($this->familyUrl('/posts'))->assertOk();
        $one = $this->asParent($this->parentA)->getJson($this->familyUrl("/posts/{$post->id}"))->assertOk();

        foreach ([$feed->json('data.data.0'), $one->json('data')] as $payload) {
            $this->assertSame('2026-10-04 12:00:00', Carbon::parse($payload['published_at'])->utc()->toDateTimeString());
            $this->assertSame('2026-10-01 12:00:00', Carbon::parse($payload['created_at'])->utc()->toDateTimeString());
        }
    }

    // ======================= a new time or "Send now" asks the author gate again, at once

    #[Test]
    public function a_new_time_or_send_now_on_a_story_whose_author_left_is_refused_with_the_way_out_and_changes_nothing(): void
    {
        $post = $this->scheduledPost('+1 day');
        $this->authorLeaves();
        $this->sweep();   // not inside the look-ahead: still waiting
        Carbon::setTestNow(now()->addDay()->addMinute());
        $this->sweep();   // refused now
        $this->assertNotNull($post->fresh()->publish_failed_at);

        // Since S14 (point, 2026-09-30) moving a story needs its words, so the one who can reach
        // this gate is an office administrator who ALSO teaches the class (a teacher reads, the
        // office moves). The office alone is refused before the gate, and that is pinned elsewhere.
        $office = $this->makeAdmin();
        $this->class->staff()->attach($office->id, [
            'masjid_id' => $this->school->id,
            'role' => GroupStaff::ROLE_TEACHER,
            'assigned_at' => now(),
        ]);

        foreach ([['send_at' => '2026-10-20T09:00'], ['send_now' => true]] as $move) {
            $response = $this->asUser($office)->putJson($this->adminUrl("/posts/{$post->id}"), $move)->assertStatus(422);
            $this->assertStringContainsString('no longer teaches this class', $response->json('data.send_at.0'));
            $this->assertStringContainsString('cancel it and write it again', $response->json('data.send_at.0'));
        }

        $fresh = $post->fresh();
        $this->assertNotNull($fresh->publish_failed_at, 'a refused story was put back although its author may not send it');
        $this->assertNull($fresh->announced_at);
        $this->assertNotContains($post->id, $this->familyIds());
        $this->assertSame(0, $this->classStoryJobs());

        // Cancelling it, the way out, works.
        $this->asUser($office)->deleteJson($this->adminUrl("/posts/{$post->id}"))->assertOk();
    }

    #[Test]
    public function send_now_on_a_waiting_story_whose_author_left_does_not_put_it_out(): void
    {
        $post = $this->scheduledPost('+2 days');
        $this->authorLeaves();
        $office = $this->guardianOnlyAdmin(manage: true);

        // Since S14 (point, 2026-09-30) the office may not send it now at all: sending now
        // needs the words. Either refusal keeps the story in.
        $this->asUser($office)->putJson($this->adminUrl("/posts/{$post->id}"), ['send_now' => true])->assertForbidden();

        $this->assertNotContains($post->id, $this->familyIds());
        $this->assertSame(0, $this->classStoryJobs());
    }

    // ================================ a kept-until date that closes before the story goes out

    #[Test]
    public function a_retention_date_before_the_day_a_story_goes_out_is_refused_on_create_and_on_edit(): void
    {
        // Create: goes out on the 20th, "keep until" the 7th.
        $this->asTeacher()
            ->postJson($this->teacherUrl('/posts'), ['body' => 'x', 'send_at' => '2026-10-20T10:00', 'retained_until' => '2026-10-07'])
            ->assertStatus(422)->assertJsonStructure(['data' => ['retained_until']]);
        $this->assertSame(0, GroupPost::withoutMasjidScope()->count());

        // The day it goes out is allowed, and so is a later one.
        $this->asTeacher()
            ->postJson($this->teacherUrl('/posts'), ['body' => 'a', 'send_at' => '2026-10-20T10:00', 'retained_until' => '2026-10-20'])
            ->assertCreated();

        // Edit: an explicit window onto a waiting story that closes before it goes out.
        $post = GroupPost::withoutMasjidScope()->sole();
        $this->asTeacher()
            ->putJson($this->teacherUrl("/posts/{$post->id}"), ['retained_until' => '2026-10-07'])
            ->assertStatus(422)->assertJsonStructure(['data' => ['retained_until']]);
        $this->assertSame('2026-10-20', $post->fresh()->retained_until->toDateString());

        // Edit: moving the story past a window the author chose.
        $this->asTeacher()
            ->putJson($this->teacherUrl("/posts/{$post->id}"), ['send_at' => '2026-10-28T10:00'])
            ->assertStatus(422)->assertJsonStructure(['data' => ['retained_until']]);
        $this->assertSame('2026-10-20 14:00:00', $post->fresh()->published_at->toDateTimeString());

        // Moving it and choosing the window together is fine.
        $this->asTeacher()
            ->putJson($this->teacherUrl("/posts/{$post->id}"), ['send_at' => '2026-10-28T10:00', 'retained_until' => '2027-01-01'])
            ->assertOk();
        $this->assertSame('2027-01-01', $post->fresh()->retained_until->toDateString());
    }

    #[Test]
    public function a_story_that_is_already_out_keeps_the_retention_edit_it_always_had(): void
    {
        $post = $this->makePost();

        // A window in the past on a story that is out is not this slice's business.
        $this->asTeacher()->putJson($this->teacherUrl("/posts/{$post->id}"), ['retained_until' => '2026-01-01'])->assertOk();
        $this->assertSame('2026-01-01', $post->fresh()->retained_until->toDateString());
    }

    // ======================================= the update path holds the same bounds as create

    #[Test]
    public function rescheduling_a_story_is_held_to_the_same_bounds_as_scheduling_it(): void
    {
        $post = $this->scheduledPost('+2 days');
        $before = $post->fresh()->published_at->toDateTimeString();

        foreach ([
            'in the past' => '2026-09-30T10:00',
            'right now' => '2026-10-01T08:00',
            'after 30 days' => '2026-10-31T08:01',
            'a day that does not exist' => '2026-09-31T10:00',
            'not a date' => 'next tuesday',
        ] as $why => $value) {
            $this->asTeacher()
                ->putJson($this->teacherUrl("/posts/{$post->id}"), ['send_at' => $value])
                ->assertStatus(422)->assertJsonStructure(['data' => ['send_at']]);
            $this->assertSame($before, $post->fresh()->published_at->toDateTimeString(), "{$why} moved the story");
        }

        // And through the office's realm.
        $this->asUser($this->guardianOnlyAdmin(manage: true))
            ->putJson($this->adminUrl("/posts/{$post->id}"), ['send_at' => '2026-09-30T10:00'])
            ->assertStatus(422)->assertJsonStructure(['data' => ['send_at']]);
        $this->assertSame($before, $post->fresh()->published_at->toDateTimeString());

        // The last allowed minute.
        $this->asTeacher()->putJson($this->teacherUrl("/posts/{$post->id}"), ['send_at' => '2026-10-31T07:59'])->assertOk();
    }

    #[Test]
    public function a_date_that_does_not_exist_is_refused_not_rolled_onto_the_next_month(): void
    {
        // 31 September rolls to 1 October 10:00: inside the window, so only the
        // calendar check stands between this and a story quietly scheduled for a
        // different day than the one that was typed.
        $this->asTeacher()
            ->postJson($this->teacherUrl('/posts'), ['body' => 'x', 'send_at' => '2026-09-31T10:00'])
            ->assertStatus(422)->assertJsonStructure(['data' => ['send_at']]);

        $this->assertSame(0, GroupPost::withoutMasjidScope()->count());
    }

    // ========================================= the Scheduled list is ONE page holding everything

    #[Test]
    public function the_scheduled_list_holds_every_waiting_story_so_the_sixteenth_can_still_be_cancelled(): void
    {
        $ids = [];
        for ($i = 1; $i <= 17; $i++) {
            $ids[] = $this->scheduledPost("+{$i} hours", body: "Story {$i}")->id;
        }

        $list = $this->asTeacher()->getJson($this->teacherUrl('/posts?scheduled=1'))->assertOk();

        $this->assertSame($ids, array_map('intval', $list->json('data.data.*.id')), 'the list stopped at one page');
        $this->assertSame(17, $list->json('data.total'));
        $this->assertSame(1, $list->json('data.last_page'));

        // The one past the old page of 15, editable and cancellable.
        $sixteenth = $ids[15];
        $this->asTeacher()->putJson($this->teacherUrl("/posts/{$sixteenth}"), ['body' => 'Edited'])->assertOk();
        $this->asTeacher()->deleteJson($this->teacherUrl("/posts/{$sixteenth}"))->assertOk();

        $this->asTeacher()->getJson($this->teacherUrl('/posts?scheduled=1'))->assertOk()->assertJsonCount(16, 'data.data');
    }
}
