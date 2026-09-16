<?php

namespace Tests\Feature;

use App\Jobs\SendGroupNotificationJob;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupMessage;
use App\Models\GroupMessageAttachment;
use App\Models\GroupStaff;
use App\Models\GroupThread;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use App\Support\GroupMessageAttachments;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Teachers sending photos — in a conversation with a family, and in the class
 * story — and who can open them afterwards.
 *
 * These are photographs of children, so most of this file is about the second
 * half. The rules it pins:
 *
 *   - a photo in a PRIVATE conversation reaches the teacher and that child's
 *     own family, with no consent record needed (it is not a broadcast), and
 *     nobody else — another family in the same class is refused;
 *   - a photo in a CLASS-WIDE conversation is a broadcast, so it needs the same
 *     photo consent as the class story; a family without it is told a photo was
 *     withheld and is refused the bytes;
 *   - the file is checked by its bytes and size before anything is kept, and a
 *     refused upload leaves nothing on disk;
 *   - the bytes survive a soft delete (the mis-click guard) and are removed when
 *     retention purges the conversation or the class is force-deleted;
 *   - every download link points at the realm the reader signed in to — the
 *     class-story link used to be /api/admin for teachers too, which the admin
 *     realm refuses.
 *
 * Requests are sent the way the browser sends them: multipart form fields plus
 * files, not JSON.
 */
class GroupMessagePhotosTest extends TestCase
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

        // The notification job is dispatched, never run: these tests are about
        // the photos, and the job's own suite covers who it reaches.
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

        // Two families in the same class. Neither has a consent record.
        [$this->parentA, $this->childA] = $this->makeFamily('Amina');
        [$this->parentB] = $this->makeFamily('Bilal');
    }

    // ------------------------------------------------------------ sending

    #[Test]
    public function a_teacher_can_send_a_photo_with_no_text_in_a_private_conversation(): void
    {
        $thread = $this->privateThread();

        $response = $this->asTeacher()
            ->post($this->teacherUrl("/threads/{$thread->id}/messages"), [
                'images' => [$this->photo('trip.jpg')],
            ])
            ->assertCreated()
            ->assertJsonPath('data.body', '')
            ->assertJsonPath('data.is_mine', true)
            ->assertJsonPath('data.media_withheld', false)
            ->assertJsonPath('data.attachments.0.file_name', 'trip.jpg')
            ->assertJsonPath('data.attachments.0.mime_type', 'image/jpeg');

        $path = (string) $response->json('data.attachments.0.download_path');
        $this->assertStringStartsWith(
            "/api/teacher/masjids/{$this->school->id}/groups/{$this->class->id}/threads/{$thread->id}/messages/",
            $path
        );

        $attachment = GroupMessageAttachment::withoutMasjidScope()->sole();
        $this->assertSame($this->school->id, (int) $attachment->masjid_id);
        $this->assertStringStartsWith(
            "group-media/{$this->school->id}/{$this->class->id}/threads/{$thread->id}/",
            $attachment->path
        );
        // The stored name is random, never the uploader's.
        $this->assertStringNotContainsString('trip', $attachment->path);
        Storage::disk($this->disk())->assertExists($attachment->path);

        Bus::assertDispatched(SendGroupNotificationJob::class);
    }

    #[Test]
    public function the_teacher_can_open_the_photo_they_sent(): void
    {
        $thread = $this->privateThread();

        $path = $this->asTeacher()
            ->post($this->teacherUrl("/threads/{$thread->id}/messages"), [
                'body' => 'Look who finished the whole page!',
                'images' => [$this->photo()],
            ])
            ->assertCreated()
            ->json('data.attachments.0.download_path');

        $download = $this->asTeacher()->get($path)->assertOk();
        $this->assertPrivateImage($download);

        // And it is listed when the conversation is reopened.
        $this->asTeacher()
            ->get($this->teacherUrl("/threads/{$thread->id}"))
            ->assertOk()
            ->assertJsonPath('data.messages.data.0.body', 'Look who finished the whole page!')
            ->assertJsonPath('data.messages.data.0.is_mine', true)
            ->assertJsonCount(1, 'data.messages.data.0.attachments');
    }

    #[Test]
    public function a_teacher_can_open_a_conversation_whose_first_message_is_a_photo(): void
    {
        $this->asTeacher()
            ->post($this->teacherUrl('/threads'), [
                'subject' => 'Field trip',
                'scope' => GroupThread::SCOPE_PARTICIPANT,
                'about_membership_id' => $this->childA->id,
                'images' => [$this->photo(), $this->photo('second.png', 'image/png')],
            ])
            ->assertCreated();

        $message = GroupMessage::withoutMasjidScope()->sole();
        $this->assertSame('', $message->body);
        $this->assertSame(2, GroupMessageAttachment::withoutMasjidScope()->where('group_message_id', $message->id)->count());

        // A photo-only opener notifies the family, as a text opener does.
        Bus::assertDispatched(SendGroupNotificationJob::class);
    }

    #[Test]
    public function an_empty_message_is_refused(): void
    {
        $thread = $this->privateThread();

        $response = $this->asTeacher()
            ->post($this->teacherUrl("/threads/{$thread->id}/messages"), ['body' => ''])
            ->assertStatus(422);

        $this->assertSame('Write a message or attach a photo.', $response->json('data.body.0'));
        $this->assertSame(0, GroupMessage::withoutMasjidScope()->count());
    }

    #[Test]
    public function a_file_that_is_not_a_photo_is_refused_and_nothing_is_kept(): void
    {
        $thread = $this->privateThread();

        // Named like a photo, but the bytes say otherwise — the type is sniffed.
        $this->asTeacher()
            ->post($this->teacherUrl("/threads/{$thread->id}/messages"), [
                'body' => 'Here it is',
                'images' => [UploadedFile::fake()->create('trip.jpg', 20, 'text/html')],
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['images.0']]);

        $this->assertSame(0, GroupMessage::withoutMasjidScope()->count());
        $this->assertSame([], Storage::disk($this->disk())->allFiles());
    }

    #[Test]
    public function a_photo_over_the_size_limit_is_refused(): void
    {
        config(['groups.media.max_size_kb' => 10]);
        $thread = $this->privateThread();

        $this->asTeacher()
            ->post($this->teacherUrl("/threads/{$thread->id}/messages"), [
                'images' => [$this->photo('big.jpg', 'image/jpeg', 20)],
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['images.0']]);

        $this->assertSame([], Storage::disk($this->disk())->allFiles());
    }

    #[Test]
    public function more_photos_than_the_limit_are_refused(): void
    {
        config(['groups.media.max_per_post' => 2]);
        $thread = $this->privateThread();

        $this->asTeacher()
            ->post($this->teacherUrl("/threads/{$thread->id}/messages"), [
                'images' => [$this->photo('1.jpg'), $this->photo('2.jpg'), $this->photo('3.jpg')],
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['images']]);

        $this->assertSame(0, GroupMessage::withoutMasjidScope()->count());
    }

    #[Test]
    public function a_closed_conversation_takes_no_photos(): void
    {
        $thread = $this->privateThread();
        $thread->forceFill(['closed_at' => now()])->save();

        $this->asTeacher()
            ->post($this->teacherUrl("/threads/{$thread->id}/messages"), [
                'images' => [$this->photo()],
            ])
            ->assertStatus(422);

        $this->assertSame([], Storage::disk($this->disk())->allFiles());
    }

    // ------------------------------------------------------------ who sees it

    #[Test]
    public function the_childs_own_parent_sees_and_opens_the_photo_without_a_consent_record(): void
    {
        $thread = $this->privateThread();
        $attachment = $this->seedPhotoMessage($thread);

        $this->asParent($this->parentA)
            ->getJson($this->familyUrl("/threads/{$thread->id}"))
            ->assertOk()
            ->assertJsonPath('data.messages.data.0.media_withheld', false)
            ->assertJsonPath('data.messages.data.0.attachments.0.id', $attachment->id);

        $download = $this->asParent($this->parentA)
            ->get($this->familyPhotoUrl($thread, $attachment))
            ->assertOk();
        $this->assertPrivateImage($download);
    }

    #[Test]
    public function another_family_in_the_same_class_cannot_open_the_photo(): void
    {
        $thread = $this->privateThread();
        $attachment = $this->seedPhotoMessage($thread);

        $this->asParent($this->parentB)
            ->getJson($this->familyUrl("/threads/{$thread->id}"))
            ->assertStatus(403);

        $this->asParent($this->parentB)
            ->getJson($this->familyPhotoUrl($thread, $attachment))
            ->assertStatus(403);
    }

    #[Test]
    public function a_class_wide_photo_needs_photo_consent(): void
    {
        $thread = $this->classThread();
        $attachment = $this->seedPhotoMessage($thread);

        // Feed consent lets the parent read the conversation, not see its photos.
        $this->consent($this->parentA, GroupMembership::CONSENT_FEED);

        $this->asParent($this->parentA)
            ->getJson($this->familyUrl("/threads/{$thread->id}"))
            ->assertOk()
            ->assertJsonPath('data.messages.data.0.media_withheld', true)
            ->assertJsonCount(0, 'data.messages.data.0.attachments');

        $this->asParent($this->parentA)
            ->getJson($this->familyPhotoUrl($thread, $attachment))
            ->assertStatus(403);

        // With photo consent, both open.
        $this->consent($this->parentA, GroupMembership::CONSENT_MEDIA);

        $this->asParent($this->parentA)
            ->getJson($this->familyUrl("/threads/{$thread->id}"))
            ->assertOk()
            ->assertJsonPath('data.messages.data.0.media_withheld', false)
            ->assertJsonCount(1, 'data.messages.data.0.attachments');

        $this->asParent($this->parentA)
            ->get($this->familyPhotoUrl($thread, $attachment))
            ->assertOk();
    }

    #[Test]
    public function a_photo_addressed_through_another_conversation_is_a_miss(): void
    {
        $thread = $this->privateThread();
        $attachment = $this->seedPhotoMessage($thread);
        $other = $this->privateThread();

        $messageId = $attachment->group_message_id;

        $this->asTeacher()
            ->getJson($this->teacherUrl("/threads/{$other->id}/messages/{$messageId}/attachments/{$attachment->id}"))
            ->assertStatus(404);
    }

    #[Test]
    public function another_school_cannot_see_a_message_photo(): void
    {
        $thread = $this->privateThread();
        $attachment = $this->seedPhotoMessage($thread);

        $tenant = app(TenantContext::class);

        $tenant->set($this->otherSchool->id);
        $this->assertNull(GroupMessageAttachment::find($attachment->id));
        $this->assertCount(0, GroupMessageAttachment::all());

        $tenant->set($this->school->id);
        $this->assertNotNull(GroupMessageAttachment::find($attachment->id));

        $tenant->forgetTenant();

        // And over HTTP: a teacher at the other school, naming this school's
        // ids in their own school's URL, reaches nothing.
        $outsider = User::factory()->create([
            'type' => 'Teacher',
            'phone' => '+1'.random_int(1000000000, 9999999999),
        ]);
        MasjidUser::create([
            'masjid_id' => $this->otherSchool->id, 'user_id' => $outsider->id,
            'role' => 'teacher', 'is_default' => true,
        ]);

        Auth::forgetGuards();
        Sanctum::actingAs($outsider, ['staff']);

        $this->flushHeaders()
            ->withHeader('Accept', 'application/json')
            ->get("/api/teacher/masjids/{$this->otherSchool->id}/groups/{$this->class->id}"
                ."/threads/{$thread->id}/messages/{$attachment->group_message_id}/attachments/{$attachment->id}")
            ->assertStatus(404);
    }

    // ------------------------------------------------------------ lifecycle

    #[Test]
    public function hiding_a_conversation_keeps_the_photo_and_retention_removes_it(): void
    {
        $thread = $this->privateThread();
        $attachment = $this->seedPhotoMessage($thread);
        $disk = Storage::disk($this->disk());

        // The soft delete is the mis-click guard: the photo must survive it.
        $thread->delete();
        $disk->assertExists($attachment->path);

        GroupThread::withoutMasjidScope()->withTrashed()
            ->whereKey($thread->id)
            ->update(['retained_until' => now()->subDay()->toDateString()]);

        Artisan::call('groups:purge-feed');

        $disk->assertMissing($attachment->path);
        $this->assertSame(0, GroupMessageAttachment::withoutMasjidScope()->count());
        $this->assertSame(0, GroupMessage::withoutMasjidScope()->count());
    }

    #[Test]
    public function force_deleting_the_class_removes_the_photos(): void
    {
        $thread = $this->privateThread();
        $attachment = $this->seedPhotoMessage($thread);

        $this->class->forceDelete();

        Storage::disk($this->disk())->assertMissing($attachment->path);
        $this->assertSame([], Storage::disk($this->disk())->allFiles());
    }

    // ------------------------------------------------------------ class story

    #[Test]
    public function a_teachers_class_story_photo_opens_in_the_teacher_realm(): void
    {
        $path = (string) $this->asTeacher()
            ->post($this->teacherUrl('/posts'), [
                'body' => 'Planting day',
                'images' => [$this->photo('garden.jpg')],
            ])
            ->assertCreated()
            ->json('data.attachments.0.download_path');

        $this->assertStringStartsWith("/api/teacher/masjids/{$this->school->id}/groups/{$this->class->id}/posts/", $path);

        $this->assertPrivateImage($this->asTeacher()->get($path)->assertOk());

        // The feed lists it with the same link.
        $this->asTeacher()
            ->get($this->teacherUrl('/posts'))
            ->assertOk()
            ->assertJsonPath('data.data.0.attachments.0.download_path', $path);
    }

    // ------------------------------------------------------------ helpers

    private function disk(): string
    {
        return (string) config('groups.media.disk');
    }

    private function makeMasjid(): Masjid
    {
        return Masjid::create([
            'name' => 'Photo School '.uniqid(),
            'email' => 'school-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0,
            'crm_enabled' => true, 'org_type' => 'school',
        ]);
    }

    /** @return array{0: Contact, 1: GroupMembership} a signed-in-able parent and their child's membership */
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
        // forceFill: the login_* columns are deliberately not fillable.
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

    /** A teacher's message with one photo on the faked disk, written unbound. */
    private function seedPhotoMessage(GroupThread $thread): GroupMessageAttachment
    {
        $message = GroupMessage::create([
            'masjid_id' => $this->school->id,
            'group_thread_id' => $thread->id,
            'author_user_id' => $this->teacher->id,
            'body' => 'From today',
        ]);

        return GroupMessageAttachments::store($message, [$this->photo()])[0];
    }

    private function photo(string $name = 'photo.jpg', string $mime = 'image/jpeg', int $kilobytes = 5): UploadedFile
    {
        return UploadedFile::fake()->create($name, $kilobytes, $mime);
    }

    private function assertPrivateImage(TestResponse $download): void
    {
        $this->assertSame('image/jpeg', $download->headers->get('Content-Type'));
        $cache = (string) $download->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cache);
        $this->assertStringContainsString('private', $cache);
    }

    /** Signed in as the teacher for the next request, with a clean slate. */
    private function asTeacher(): self
    {
        Auth::forgetGuards();
        app(TenantContext::class)->forgetTenant();
        Sanctum::actingAs($this->teacher, ['staff']);

        return $this->flushHeaders()->withHeader('Accept', 'application/json');
    }

    /**
     * Signed in as a parent for the next request — a real bearer token, so the
     * family guard's own provider check runs (see FamilyPortalTest::as()).
     */
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

    private function familyPhotoUrl(GroupThread $thread, GroupMessageAttachment $attachment): string
    {
        return $this->familyUrl(
            "/threads/{$thread->id}/messages/{$attachment->group_message_id}/attachments/{$attachment->id}"
        );
    }
}
