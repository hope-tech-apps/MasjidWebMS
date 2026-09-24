<?php

namespace Tests\Feature;

use App\Jobs\SendGroupNotificationJob;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupMessage;
use App\Models\GroupMessageAttachment;
use App\Models\GroupPost;
use App\Models\GroupPostAttachment;
use App\Models\GroupStaff;
use App\Models\GroupThread;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use App\Support\GroupMessageAttachments;
use App\Support\GroupPostAttachments;
use App\Support\PrivateMediaStream;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * VIDEO on the two surfaces that already take photos — the class story and the
 * teacher↔parent conversation.
 *
 * WHY THIS FILE USES A REAL MP4 AND NOT `UploadedFile::fake()`. Every other
 * upload test here builds its fixture with `UploadedFile::fake()->create($name,
 * $kb, $mime)`, which writes ZERO REAL BYTES and merely CLAIMS a mime type. The
 * production rule is `mimetypes:`, which sniffs the type FROM THE BYTES — so a
 * faked "video/mp4" exercises the claim and not the sniff, and a rule that had
 * silently degraded to `mimes:` (the extension) would still pass. Worse, there
 * is nothing to range-request: the whole playback design is about bytes 100–199
 * of a real file. `tests/fixtures/tiny.mp4` is a genuine 1.6KB H.264 clip, and
 * every assertion below runs against it.
 *
 * What this pins:
 *
 *   - the video bag is SEPARATE from the image bag — a video sent as an image is
 *     refused and a photo sent as a video is refused, so widening one allowlist
 *     can never widen the other;
 *   - a parent still uploads NOTHING, and must still be able to WATCH;
 *   - playback is a short-lived, viewer-bound signed ticket whose handler
 *     re-resolves the chain and re-asks consent on EVERY range — consent
 *     withdrawn after the ticket was minted stops the next request;
 *   - Range actually works: 206, Content-Range, and the right bytes;
 *   - video dies on its OWN 90-day window, leaving the post and its photos.
 */
class GroupVideoAttachmentsTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $school;
    private Masjid $otherSchool;
    private User $teacher;
    private Group $class;

    private Contact $parentA;
    private GroupMembership $childA;
    private Contact $parentB;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        // Private disk, faked — never 'public' (.claude/rules/private-uploads.md).
        Storage::fake($this->disk());

        Bus::fake([SendGroupNotificationJob::class]);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->school = $this->makeMasjid();
        $this->otherSchool = $this->makeMasjid();

        $this->teacher = User::factory()->create([
            'type' => 'Teacher',
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

        [$this->parentA, $this->childA] = $this->makeFamily('Amina');
        [$this->parentB] = $this->makeFamily('Bilal');
    }

    // --------------------------------------------------------------- the bags

    #[Test]
    public function a_teacher_posts_a_video_to_the_class_story(): void
    {
        $response = $this->asTeacher()
            ->post($this->teacherUrl('/posts'), [
                'body' => 'Amina finished her surah',
                'videos' => [$this->video()],
            ])
            ->assertCreated()
            ->assertJsonPath('data.attachments.0.file_name', 'recital.mp4')
            ->assertJsonPath('data.attachments.0.mime_type', 'video/mp4')
            ->assertJsonPath('data.attachments.0.is_video', true);

        // The path to ASK for a ticket, in the realm the teacher signed in to.
        $this->assertStringStartsWith(
            "/api/teacher/masjids/{$this->school->id}/groups/{$this->class->id}/posts/",
            (string) $response->json('data.attachments.0.playback_ticket_path')
        );
        $this->assertStringEndsWith('/playback', (string) $response->json('data.attachments.0.playback_ticket_path'));

        $attachment = GroupPostAttachment::withoutMasjidScope()->sole();
        $this->assertSame($this->school->id, (int) $attachment->masjid_id);
        $this->assertStringNotContainsString('recital', $attachment->path);
        // Extension from the SNIFFED type, not the uploader's name.
        $this->assertStringEndsWith('.mp4', $attachment->path);
        Storage::disk($this->disk())->assertExists($attachment->path);

        // The owner's 90 days, on the ATTACHMENT — not the post's 365.
        $this->assertSame(
            now()->addDays(90)->toDateString(),
            $attachment->retained_until->toDateString()
        );
        $post = GroupPost::withoutMasjidScope()->sole();
        $this->assertSame(now()->addDays(365)->toDateString(), $post->retained_until->toDateString());
    }

    #[Test]
    public function a_photo_still_carries_no_window_of_its_own(): void
    {
        $this->asTeacher()
            ->post($this->teacherUrl('/posts'), [
                'body' => 'Planting day',
                'images' => [UploadedFile::fake()->create('garden.jpg', 5, 'image/jpeg')],
            ])
            ->assertCreated()
            ->assertJsonPath('data.attachments.0.is_video', false)
            ->assertJsonPath('data.attachments.0.playback_ticket_path', null)
            ->assertJsonPath('data.attachments.0.retained_until', null);

        $this->assertNull(GroupPostAttachment::withoutMasjidScope()->sole()->retained_until);
    }

    #[Test]
    public function a_video_sent_in_the_image_bag_is_refused_and_nothing_is_kept(): void
    {
        // THE POINT OF THE SEPARATE BAG. If video had been added to
        // `groups.media.mime_types` instead, this request would succeed — and so
        // would a 100MB "image", everywhere that config is read.
        $this->asTeacher()
            ->post($this->teacherUrl('/posts'), [
                'body' => 'Wrong bag',
                'images' => [$this->video()],
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['images.0']]);

        $this->assertSame(0, GroupPost::withoutMasjidScope()->count());
        $this->assertSame([], Storage::disk($this->disk())->allFiles());
    }

    #[Test]
    public function a_photo_sent_in_the_video_bag_is_refused(): void
    {
        $this->asTeacher()
            ->post($this->teacherUrl('/posts'), [
                'body' => 'Wrong bag',
                'videos' => [UploadedFile::fake()->create('garden.jpg', 5, 'image/jpeg')],
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['videos.0']]);

        $this->assertSame([], Storage::disk($this->disk())->allFiles());
    }

    #[Test]
    public function a_video_over_the_size_limit_is_refused(): void
    {
        // The real fixture is ~1.6KB, so a 1KB ceiling refuses it. The IMAGE
        // ceiling is left alone on purpose: if the two shared a key, lowering
        // one here would prove nothing about the other.
        config(['groups.media.video.max_size_kb' => 1]);

        $this->asTeacher()
            ->post($this->teacherUrl('/posts'), [
                'body' => 'Too big',
                'videos' => [$this->video()],
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['videos.0']]);

        $this->assertSame(0, GroupPost::withoutMasjidScope()->count());
        $this->assertSame([], Storage::disk($this->disk())->allFiles());
    }

    #[Test]
    public function more_videos_than_the_limit_are_refused(): void
    {
        $this->asTeacher()
            ->post($this->teacherUrl('/posts'), [
                'body' => 'Two clips',
                'videos' => [$this->video('a.mp4'), $this->video('b.mp4')],
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['videos']]);

        $this->assertSame([], Storage::disk($this->disk())->allFiles());
    }

    #[Test]
    public function a_message_may_be_a_video_with_no_text(): void
    {
        $thread = $this->privateThread();

        $this->asTeacher()
            ->post($this->teacherUrl("/threads/{$thread->id}/messages"), [
                'videos' => [$this->video()],
            ])
            ->assertCreated()
            ->assertJsonPath('data.body', '')
            ->assertJsonPath('data.attachments.0.is_video', true);

        $attachment = GroupMessageAttachment::withoutMasjidScope()->sole();
        $this->assertSame(
            now()->addDays(90)->toDateString(),
            $attachment->retained_until->toDateString()
        );
    }

    // ------------------------------------------------------- parents upload nothing

    #[Test]
    public function a_parent_cannot_attach_a_video_to_their_reply(): void
    {
        $thread = $this->privateThread();

        // The family request validates `body` only and the controller reads no
        // file bag at all, so the extra field is ignored rather than stored.
        // Asserted as "nothing was written", not as "422" — the guarantee is
        // about what reaches the disk, not about which error shape says so.
        $this->asParent($this->parentA)
            ->post($this->familyUrl("/threads/{$thread->id}/messages"), [
                'body' => 'Thank you!',
                'videos' => [$this->video()],
            ])
            ->assertSuccessful();

        $this->assertSame(0, GroupMessageAttachment::withoutMasjidScope()->count());
        $this->assertSame([], Storage::disk($this->disk())->allFiles());
    }

    // ------------------------------------------------------------- playback

    #[Test]
    public function a_teacher_plays_their_own_class_story_video_with_range_requests(): void
    {
        $ticketPath = (string) $this->asTeacher()
            ->post($this->teacherUrl('/posts'), [
                'body' => 'Recital',
                'videos' => [$this->video()],
            ])
            ->assertCreated()
            ->json('data.attachments.0.playback_ticket_path');

        $url = (string) $this->asTeacher()->post($ticketPath)->assertOk()->json('data.url');

        // Relative, so it is same-origin on whichever hostname serves the SPA.
        $this->assertStringStartsWith('/api/group-media/masjids/', $url);
        $this->assertStringContainsString('signature=', $url);

        $bytes = file_get_contents($this->fixture());

        // 1. The whole file, and the header without which no browser offers a scrubber.
        $full = $this->flushHeaders()->get($url)->assertOk();
        $this->assertSame('bytes', $full->headers->get('Accept-Ranges'));
        $this->assertSame('video/mp4', $full->headers->get('Content-Type'));
        $this->assertStringContainsString('inline', (string) $full->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $full->headers->get('Cache-Control'));
        $this->assertSame($bytes, $full->streamedContent());

        // 2. A RANGE — the thing Storage::download() cannot do, and the reason
        //    this endpoint exists. 206, the right Content-Range, the right bytes.
        $partial = $this->flushHeaders()->get($url, ['Range' => 'bytes=100-199']);
        $partial->assertStatus(206);
        $this->assertSame('bytes 100-199/'.strlen($bytes), $partial->headers->get('Content-Range'));
        $this->assertSame('100', $partial->headers->get('Content-Length'));
        $this->assertSame(substr($bytes, 100, 100), $partial->streamedContent());

        // 3. An open-ended range, which is what a player asks for first.
        $tail = $this->flushHeaders()->get($url, ['Range' => 'bytes=1000-']);
        $tail->assertStatus(206);
        $this->assertSame(substr($bytes, 1000), $tail->streamedContent());

        // 4. A range past the end is 416, never a silent whole-file 200 that
        //    would make the player believe it had seeked.
        $this->flushHeaders()
            ->get($url, ['Range' => 'bytes=999999-1000000'])
            ->assertStatus(416)
            ->assertHeader('Content-Range', 'bytes */'.strlen($bytes));
    }

    #[Test]
    public function a_consented_parent_watches_and_an_unconsented_one_cannot(): void
    {
        $post = $this->seedVideoPost();
        $attachment = $post->attachments()->sole();

        // No consent record at all: the class story discloses nothing.
        $this->asParent($this->parentA)
            ->postJson($this->familyPlaybackUrl($post, $attachment))
            ->assertStatus(403);

        // FEED consent is the words, not the pictures.
        $this->consent($this->parentA, GroupMembership::CONSENT_FEED);
        $this->asParent($this->parentA)
            ->postJson($this->familyPlaybackUrl($post, $attachment))
            ->assertStatus(403);

        // MEDIA consent opens it.
        $this->consent($this->parentA, GroupMembership::CONSENT_MEDIA);
        $url = (string) $this->asParent($this->parentA)
            ->postJson($this->familyPlaybackUrl($post, $attachment))
            ->assertOk()
            ->json('data.url');

        $this->flushHeaders()->get($url, ['Range' => 'bytes=0-9'])->assertStatus(206);
    }

    #[Test]
    public function withdrawing_consent_stops_the_next_range_on_a_ticket_already_minted(): void
    {
        // THE PROPERTY THE WHOLE DESIGN EXISTS FOR. A signed URL that were
        // merely "issued to someone who was allowed at the time" would keep
        // playing for its whole lifetime; this one re-resolves the chain and
        // re-asks GroupAudience on every request it buys.
        $post = $this->seedVideoPost();
        $attachment = $post->attachments()->sole();

        $this->consent($this->parentA, GroupMembership::CONSENT_MEDIA);

        $url = (string) $this->asParent($this->parentA)
            ->postJson($this->familyPlaybackUrl($post, $attachment))
            ->assertOk()
            ->json('data.url');

        $this->flushHeaders()->get($url, ['Range' => 'bytes=0-9'])->assertStatus(206);

        // The office withdraws media consent a second later.
        GroupMembership::withoutMasjidScope()
            ->where('group_id', $this->class->id)
            ->where('contact_id', $this->parentA->id)
            ->where('role', GroupMembership::ROLE_GUARDIAN)
            ->update(['consent_granted_at' => null, 'consent_scope' => null]);

        // The SAME url, still inside its window, still correctly signed.
        $this->flushHeaders()->get($url, ['Range' => 'bytes=10-19'])->assertStatus(403);
    }

    #[Test]
    public function a_ticket_cannot_be_edited_into_someone_elses(): void
    {
        $post = $this->seedVideoPost();
        $attachment = $post->attachments()->sole();

        $url = (string) $this->asTeacher()
            ->post($this->teacherPlaybackUrl($post, $attachment))
            ->assertOk()
            ->json('data.url');

        // parentB is in the class but has no consent, so naming them would be a
        // 403 if the swap were honoured. The signature covers viewer_id, so it
        // never reaches the handler: an invalid signature is refused outright.
        $swapped = preg_replace(
            '/viewer_id=\d+/',
            'viewer_id='.$this->parentB->id,
            $url
        );

        $this->flushHeaders()->get($swapped)->assertStatus(403);

        // And the path itself cannot be re-aimed at another post.
        $other = $this->seedVideoPost();
        $this->flushHeaders()
            ->get(str_replace("/posts/{$post->id}/", "/posts/{$other->id}/", $url))
            ->assertStatus(403);
    }

    #[Test]
    public function an_expired_ticket_is_refused(): void
    {
        $post = $this->seedVideoPost();
        $attachment = $post->attachments()->sole();

        $url = (string) $this->asTeacher()
            ->post($this->teacherPlaybackUrl($post, $attachment))
            ->assertOk()
            ->json('data.url');

        $this->travel((int) config('groups.media.video.playback_ttl_minutes') + 1)->minutes();

        $this->flushHeaders()->get($url)->assertStatus(403);

        $this->travelBack();
    }

    #[Test]
    public function another_school_reaches_neither_the_ticket_nor_the_stream(): void
    {
        $post = $this->seedVideoPost();
        $attachment = $post->attachments()->sole();

        $url = (string) $this->asTeacher()
            ->post($this->teacherPlaybackUrl($post, $attachment))
            ->assertOk()
            ->json('data.url');

        $outsider = User::factory()->create([
            'type' => 'Teacher',
            'phone' => '+1'.random_int(1000000000, 9999999999),
        ]);
        MasjidUser::create([
            'masjid_id' => $this->otherSchool->id, 'user_id' => $outsider->id,
            'role' => 'teacher', 'is_default' => true,
        ]);

        Auth::forgetGuards();
        app(TenantContext::class)->forgetTenant();
        Sanctum::actingAs($outsider, ['staff']);

        // The other school's teacher, naming this school's ids under their own
        // masjid: a MISS, not a filtered row.
        $this->flushHeaders()
            ->withHeader('Accept', 'application/json')
            ->post("/api/teacher/masjids/{$this->otherSchool->id}/groups/{$this->class->id}"
                ."/posts/{$post->id}/attachments/{$attachment->id}/playback")
            ->assertStatus(404);

        // And the signed stream is bound to the masjid in its own path, so
        // rewriting that id invalidates the signature rather than crossing.
        $this->flushHeaders()
            ->get(str_replace(
                "/masjids/{$this->school->id}/",
                "/masjids/{$this->otherSchool->id}/",
                $url
            ))
            ->assertStatus(403);
    }

    #[Test]
    public function a_photo_is_not_playable_through_the_ticket_endpoint(): void
    {
        // Photos keep the bearer-token blob fetch. Minting a ticket for one
        // would quietly create the durable-ish URL the photo arrangement avoids.
        $post = GroupPost::create([
            'masjid_id' => $this->school->id,
            'group_id' => $this->class->id,
            'author_user_id' => $this->teacher->id,
            'body' => 'Planting day',
        ]);
        $attachment = GroupPostAttachments::store(
            $post, [UploadedFile::fake()->create('garden.jpg', 5, 'image/jpeg')]
        )[0];

        $this->asTeacher()
            ->post($this->teacherPlaybackUrl($post, $attachment))
            ->assertStatus(422);
    }

    // ------------------------------------------------------------- retention

    #[Test]
    public function retention_removes_the_video_and_its_bytes_while_the_post_stays(): void
    {
        $post = $this->seedVideoPost();
        $video = $post->attachments()->sole();

        $photo = GroupPostAttachments::store(
            $post, [UploadedFile::fake()->create('garden.jpg', 5, 'image/jpeg')]
        )[0];

        $disk = Storage::disk($this->disk());
        $disk->assertExists($video->path);
        $disk->assertExists($photo->path);

        // 90 days later — the video is due, the post (365) and the photo are not.
        GroupPostAttachment::withoutMasjidScope()
            ->whereKey($video->id)
            ->update(['retained_until' => now()->subDay()->toDateString()]);

        Artisan::call('groups:purge-feed');

        $disk->assertMissing($video->path);
        $disk->assertExists($photo->path);
        $this->assertNull(GroupPostAttachment::withoutMasjidScope()->find($video->id));
        $this->assertNotNull(GroupPost::withoutMasjidScope()->find($post->id));
        $this->assertStringContainsString('1 video(s)', Artisan::output());
    }

    #[Test]
    public function retention_removes_a_conversation_video_too(): void
    {
        $thread = $this->privateThread();
        $message = GroupMessage::create([
            'masjid_id' => $this->school->id,
            'group_thread_id' => $thread->id,
            'author_user_id' => $this->teacher->id,
            'body' => 'From today',
        ]);
        $attachment = GroupMessageAttachments::store($message, [$this->video()])[0];

        GroupMessageAttachment::withoutMasjidScope()
            ->whereKey($attachment->id)
            ->update(['retained_until' => now()->subDay()->toDateString()]);

        Artisan::call('groups:purge-feed');

        Storage::disk($this->disk())->assertMissing($attachment->path);
        $this->assertSame(0, GroupMessageAttachment::withoutMasjidScope()->count());
        // The conversation itself is untouched: only the clip's window closed.
        $this->assertNotNull(GroupThread::withoutMasjidScope()->find($thread->id));
        $this->assertNotNull(GroupMessage::withoutMasjidScope()->find($message->id));
    }

    #[Test]
    public function a_dry_run_deletes_no_video(): void
    {
        $post = $this->seedVideoPost();
        $video = $post->attachments()->sole();

        GroupPostAttachment::withoutMasjidScope()
            ->whereKey($video->id)
            ->update(['retained_until' => now()->subDay()->toDateString()]);

        Artisan::call('groups:purge-feed', ['--dry-run' => true]);

        Storage::disk($this->disk())->assertExists($video->path);
        $this->assertNotNull(GroupPostAttachment::withoutMasjidScope()->find($video->id));
        $this->assertStringContainsString('Would purge', Artisan::output());
    }

    // ------------------------------------------------- the range arithmetic itself

    #[Test]
    public function the_range_parser_answers_each_shape_of_header(): void
    {
        // The parser is the fallback path's arithmetic and the reason a seek
        // lands where the player asked. Asserted directly so a regression names
        // itself instead of surfacing as "the scrubber jumps".
        $this->assertNull(PrivateMediaStream::parseRange(null, 100));
        $this->assertNull(PrivateMediaStream::parseRange('', 100));
        // Not a byte range, and a multi-range request, are both answered whole —
        // legal, and never the WRONG bytes under a 206.
        $this->assertNull(PrivateMediaStream::parseRange('items=0-10', 100));
        $this->assertNull(PrivateMediaStream::parseRange('bytes=0-10,20-30', 100));

        $this->assertSame([0, 99], PrivateMediaStream::parseRange('bytes=0-', 100));
        $this->assertSame([10, 19], PrivateMediaStream::parseRange('bytes=10-19', 100));
        // An end past the file is clamped, not refused: players routinely ask
        // for more than is there.
        $this->assertSame([10, 99], PrivateMediaStream::parseRange('bytes=10-500', 100));
        // Suffix ranges, including one longer than the file.
        $this->assertSame([90, 99], PrivateMediaStream::parseRange('bytes=-10', 100));
        $this->assertSame([0, 99], PrivateMediaStream::parseRange('bytes=-500', 100));

        $this->assertFalse(PrivateMediaStream::parseRange('bytes=100-', 100));
        $this->assertFalse(PrivateMediaStream::parseRange('bytes=50-20', 100));
        $this->assertFalse(PrivateMediaStream::parseRange('bytes=-', 100));
    }

    // --------------------------------------------------------------- helpers

    private function disk(): string
    {
        return (string) config('groups.media.disk');
    }

    private function fixture(): string
    {
        return base_path('tests/fixtures/tiny.mp4');
    }

    /**
     * A REAL mp4, copied so the fixture in the repo is never moved away by a
     * passing upload. `test: true` is what lets a non-HTTP request move it.
     */
    private function video(string $name = 'recital.mp4'): UploadedFile
    {
        $copy = tempnam(sys_get_temp_dir(), 'vid').'.mp4';
        copy($this->fixture(), $copy);

        return new UploadedFile($copy, $name, null, null, true);
    }

    private function makeMasjid(): Masjid
    {
        return Masjid::create([
            'name' => 'Video School '.uniqid(),
            'email' => 'school-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0,
            'crm_enabled' => true, 'org_type' => 'school',
        ]);
    }

    /** @return array{0: Contact, 1: GroupMembership} */
    private function makeFamily(string $childName): array
    {
        $child = Contact::factory()->create([
            'masjid_id' => $this->school->id, 'first_name' => $childName, 'email' => null,
        ]);
        $membership = GroupMembership::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id,
            'contact_id' => $child->id, 'role' => GroupMembership::ROLE_MEMBER,
        ]);

        $parent = Contact::factory()->create(['masjid_id' => $this->school->id]);
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

    private function privateThread(): GroupThread
    {
        return GroupThread::create([
            'masjid_id' => $this->school->id,
            'group_id' => $this->class->id,
            'created_by_user_id' => $this->teacher->id,
            'subject' => 'About Amina',
            'scope' => GroupThread::SCOPE_PARTICIPANT,
            'about_membership_id' => $this->childA->id,
        ]);
    }

    /** A story post with one real video on the faked disk, written unbound. */
    private function seedVideoPost(): GroupPost
    {
        $post = GroupPost::create([
            'masjid_id' => $this->school->id,
            'group_id' => $this->class->id,
            'author_user_id' => $this->teacher->id,
            'body' => 'Recital',
        ]);

        GroupPostAttachments::store($post, [$this->video()]);

        return $post->load('attachments');
    }

    private function asTeacher(): self
    {
        Auth::forgetGuards();
        app(TenantContext::class)->forgetTenant();
        Sanctum::actingAs($this->teacher, ['staff']);

        return $this->flushHeaders()->withHeader('Accept', 'application/json');
    }

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

    private function teacherPlaybackUrl(GroupPost $post, GroupPostAttachment $attachment): string
    {
        return $this->teacherUrl("/posts/{$post->id}/attachments/{$attachment->id}/playback");
    }

    private function familyPlaybackUrl(GroupPost $post, GroupPostAttachment $attachment): string
    {
        return $this->familyUrl("/posts/{$post->id}/attachments/{$attachment->id}/playback");
    }
}
