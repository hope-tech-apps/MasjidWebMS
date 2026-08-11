<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupPost;
use App\Models\Masjid;
use App\Models\User;
use App\Support\GroupPostAttachments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The group feed over HTTP: /api/admin/masjids/{id}/groups/{id}/posts (T-005b).
 *
 * Mirrors GroupCrudTest for tenancy — B's id under A's own route is a 404, B's
 * masjid in the route is a 403 — and adds the two guarantees this slice exists
 * for, both of which are about children:
 *
 *   - DISCLOSURE IS NOT ADMINISTRATION. Holding `permission:view contacts`
 *     (which every masjid admin holds) does not read you into a group's story;
 *     you have to be IN the group. See .claude/rules/groups.md, obligation 4.
 *   - CONSENT IS CHECKED WHERE THE DISCLOSURE HAPPENS. A guardian edge records a
 *     relationship; a guardian with no recorded consent receives neither the
 *     feed nor an image, and one with feed-only consent receives the words and
 *     not the pictures.
 *
 * The acting person: a Contact cannot authenticate anywhere in this application,
 * so the admin's PERSON is their tenant Contact with the same email — see
 * App\Support\GroupAudience.
 */
class GroupFeedTest extends TestCase
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

        // Group media is PRIVATE — fake the disk the feature names, never
        // 'public'. See .claude/rules/private-uploads.md.
        Storage::fake($this->disk());

        // The feed reuses the CONTACTS permissions (see routes/admin.php), so
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

    private function disk(): string
    {
        return (string) config('groups.media.disk');
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
            // The feed lives inside the CRM route group, gated by crm_enabled.
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

    private function postsUrl(?Group $group = null, ?Masjid $masjid = null): string
    {
        return '/api/admin/masjids/' . ($masjid ?? $this->masjidA)->id
            . '/groups/' . ($group ?? $this->groupA)->id . '/posts';
    }

    private function image(string $name = 'today.jpg', int $kilobytes = 40): UploadedFile
    {
        return UploadedFile::fake()->create($name, $kilobytes, 'image/jpeg');
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

    /** A post in a group, written straight to the DB, with one image on disk. */
    private function seedPost(Masjid $masjid, Group $group, bool $withImage = true): GroupPost
    {
        $post = GroupPost::factory()->create([
            'masjid_id' => $masjid->id,
            'group_id' => $group->id,
            'body' => 'We finished Surah al-Fil today.',
        ]);

        if ($withImage) {
            GroupPostAttachments::store($post, [$this->image()]);
        }

        return $post->fresh();
    }

    /** Make adminA's person a guardian of a child who is in group A. */
    private function makeAdminAGuardian(?string $consentScope = null): GroupMembership
    {
        // Explicitly email-less: identity here is resolved BY email, and a
        // faker-generated address must never be able to collide with the
        // admin's and flip one of these assertions.
        $child = Contact::factory()->create(['masjid_id' => $this->masjidA->id, 'email' => null]);
        $this->seedMembership($this->masjidA, $this->groupA, $child, GroupMembership::ROLE_MEMBER);

        return $this->seedMembership(
            $this->masjidA, $this->groupA, $this->personA, GroupMembership::ROLE_GUARDIAN, $child, $consentScope
        );
    }

    // ---------- auth ----------

    #[Test]
    public function the_feed_rejects_unauthenticated_requests(): void
    {
        $this->getJson($this->postsUrl())->assertStatus(401);
    }

    // ---------- writing ----------

    #[Test]
    public function a_leader_can_publish_a_post(): void
    {
        $this->seedMembership($this->masjidA, $this->groupA, $this->personA, GroupMembership::ROLE_LEADER);

        Sanctum::actingAs($this->adminA);

        $this->postJson($this->postsUrl(), [
            'title' => 'Week 3',
            'body' => 'We finished Surah al-Fil today.',
        ])->assertStatus(201);

        $this->assertDatabaseHas('group_posts', [
            'masjid_id' => $this->masjidA->id,
            'group_id' => $this->groupA->id,
            'title' => 'Week 3',
            // Authorship is the ACCOUNT that published, never a client claim.
            'author_user_id' => $this->adminA->id,
        ]);
    }

    #[Test]
    public function a_post_requires_a_body(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->postJson($this->postsUrl(), ['title' => 'Week 3'])->assertStatus(422);
    }

    #[Test]
    public function an_image_is_written_to_the_private_disk_and_recorded(): void
    {
        Sanctum::actingAs($this->adminA);

        $response = $this->post($this->postsUrl(), [
            'body' => 'Eid party.',
            'images' => [$this->image('eid party.jpg')],
        ], ['Accept' => 'application/json'])->assertStatus(201);

        $postId = $response->json('data.id');

        $attachment = \App\Models\GroupPostAttachment::withoutMasjidScope()
            ->where('group_post_id', $postId)->firstOrFail();

        Storage::disk($this->disk())->assertExists($attachment->path);
        $this->assertSame('eid party.jpg', $attachment->original_name);
        // Tenant-scoped directory, random stored name.
        $this->assertStringStartsWith(
            'group-media/' . $this->masjidA->id . '/' . $this->groupA->id . '/',
            $attachment->path
        );
        $this->assertStringNotContainsString('eid', $attachment->path);
        // The only link that exists points back at the authenticated endpoint.
        $this->assertStringContainsString(
            '/attachments/' . $attachment->id,
            $response->json('data.attachments.0.download_path')
        );
    }

    #[Test]
    public function a_file_that_is_not_an_accepted_image_is_refused(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->post($this->postsUrl(), [
            'body' => 'Here is a document.',
            'images' => [UploadedFile::fake()->create('notes.pdf', 20, 'application/pdf')],
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertDatabaseCount('group_posts', 0);
        $this->assertEmpty(Storage::disk($this->disk())->allFiles());
    }

    #[Test]
    public function an_oversized_image_is_refused(): void
    {
        Sanctum::actingAs($this->adminA);

        $overCeiling = (int) config('groups.media.max_size_kb') + 1;

        $this->post($this->postsUrl(), [
            'body' => 'Huge.',
            'images' => [$this->image('huge.jpg', $overCeiling)],
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertEmpty(Storage::disk($this->disk())->allFiles());
    }

    #[Test]
    public function a_post_can_be_edited(): void
    {
        $post = $this->seedPost($this->masjidA, $this->groupA, withImage: false);

        Sanctum::actingAs($this->adminA);

        $this->putJson($this->postsUrl() . '/' . $post->id, ['body' => 'Corrected.'])
            ->assertOk();

        $this->assertDatabaseHas('group_posts', ['id' => $post->id, 'body' => 'Corrected.']);
    }

    #[Test]
    public function a_post_is_soft_deleted_and_its_bytes_survive(): void
    {
        $post = $this->seedPost($this->masjidA, $this->groupA);
        $path = $post->attachments()->first()->path;

        Sanctum::actingAs($this->adminA);

        $this->deleteJson($this->postsUrl() . '/' . $post->id)->assertOk();

        $this->assertSoftDeleted('group_posts', ['id' => $post->id]);
        // A mis-click removes the post from the feed; only the retention purge
        // reaches the disk.
        Storage::disk($this->disk())->assertExists($path);
    }

    #[Test]
    public function a_soft_deleted_post_is_no_longer_readable(): void
    {
        $this->seedMembership($this->masjidA, $this->groupA, $this->personA, GroupMembership::ROLE_LEADER);
        $post = $this->seedPost($this->masjidA, $this->groupA);
        $post->delete();

        Sanctum::actingAs($this->adminA);

        $this->getJson($this->postsUrl() . '/' . $post->id)->assertStatus(404);
    }

    // ---------- who may read ----------

    #[Test]
    public function a_member_can_read_the_feed(): void
    {
        $this->seedMembership($this->masjidA, $this->groupA, $this->personA, GroupMembership::ROLE_MEMBER);
        $this->seedPost($this->masjidA, $this->groupA);

        Sanctum::actingAs($this->adminA);

        $this->getJson($this->postsUrl())
            ->assertOk()
            ->assertJsonPath('data.total', 1);
    }

    #[Test]
    public function a_contact_who_is_not_in_the_group_cannot_read_the_feed(): void
    {
        // personA exists in this organization and this admin holds
        // `view contacts` — and that is deliberately NOT enough. A group-scoped
        // read is never visible to the whole tenant.
        $this->seedPost($this->masjidA, $this->groupA);

        Sanctum::actingAs($this->adminA);

        $this->getJson($this->postsUrl())->assertStatus(403);
    }

    #[Test]
    public function an_admin_with_no_person_in_this_organization_cannot_read_the_feed(): void
    {
        $this->personA->forceDelete();
        $this->seedPost($this->masjidA, $this->groupA);

        Sanctum::actingAs($this->adminA);

        $this->getJson($this->postsUrl())->assertStatus(403);
    }

    #[Test]
    public function an_ambiguous_identity_is_no_identity(): void
    {
        // Two contacts sharing one email cannot say WHO is asking, and an
        // ambiguity about identity resolves to no identity — even though the
        // first of them is a leader here.
        $this->seedMembership($this->masjidA, $this->groupA, $this->personA, GroupMembership::ROLE_LEADER);
        Contact::factory()->create([
            'masjid_id' => $this->masjidA->id,
            // Same address, deliberately — a duplicate congregant record.
            'email' => $this->adminA->email,
        ]);
        $this->seedPost($this->masjidA, $this->groupA);

        Sanctum::actingAs($this->adminA);

        $this->getJson($this->postsUrl())->assertStatus(403);
    }

    #[Test]
    public function an_admin_who_cannot_read_the_feed_can_still_publish_to_it(): void
    {
        // Administering a roster and being disclosed to are different things.
        Sanctum::actingAs($this->adminA);

        $this->postJson($this->postsUrl(), ['body' => 'Reminder: trip on Friday.'])
            ->assertStatus(201);

        $this->getJson($this->postsUrl())->assertStatus(403);
    }

    // ---------- guardian consent, at the point of disclosure ----------

    #[Test]
    public function a_guardian_without_consent_cannot_read_the_feed(): void
    {
        $this->makeAdminAGuardian();
        $this->seedPost($this->masjidA, $this->groupA);

        Sanctum::actingAs($this->adminA);

        // The edge records a relationship; absence of a consent record means no
        // consent, never "unknown, assume yes".
        $this->getJson($this->postsUrl())->assertStatus(403);
    }

    #[Test]
    public function a_guardian_without_consent_cannot_fetch_an_image(): void
    {
        $this->makeAdminAGuardian();
        $post = $this->seedPost($this->masjidA, $this->groupA);
        $attachment = $post->attachments()->first();

        Sanctum::actingAs($this->adminA);

        $this->getJson($this->postsUrl() . '/' . $post->id . '/attachments/' . $attachment->id)
            ->assertStatus(403);
    }

    #[Test]
    public function a_guardian_with_feed_consent_reads_the_words_and_not_the_pictures(): void
    {
        $this->makeAdminAGuardian(GroupMembership::CONSENT_FEED);
        $this->seedPost($this->masjidA, $this->groupA);

        Sanctum::actingAs($this->adminA);

        $response = $this->getJson($this->postsUrl())->assertOk();

        // The images are OMITTED, not merely un-downloadable: a filename and a
        // size are themselves a disclosure about a child.
        $this->assertSame([], $response->json('data.data.0.attachments'));
        $this->assertTrue($response->json('data.data.0.media_withheld'));
        $this->assertFalse($response->json('meta.may_receive_media'));
    }

    #[Test]
    public function a_guardian_with_feed_consent_still_cannot_fetch_an_image(): void
    {
        $this->makeAdminAGuardian(GroupMembership::CONSENT_FEED);
        $post = $this->seedPost($this->masjidA, $this->groupA);
        $attachment = $post->attachments()->first();

        Sanctum::actingAs($this->adminA);

        $this->getJson($this->postsUrl() . '/' . $post->id . '/attachments/' . $attachment->id)
            ->assertStatus(403);
    }

    #[Test]
    public function a_guardian_with_media_consent_receives_the_image(): void
    {
        $this->makeAdminAGuardian(GroupMembership::CONSENT_MEDIA);
        $post = $this->seedPost($this->masjidA, $this->groupA);
        $attachment = $post->attachments()->first();

        Sanctum::actingAs($this->adminA);

        $response = $this->get(
            $this->postsUrl() . '/' . $post->id . '/attachments/' . $attachment->id
        )->assertOk();

        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
        // No proxy may hold one group's photograph and serve it to the next
        // person who asks for the URL.
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
    }

    #[Test]
    public function a_member_receives_the_image_without_any_consent_record(): void
    {
        // A participant IS the person; nobody consents on their behalf.
        $this->seedMembership($this->masjidA, $this->groupA, $this->personA, GroupMembership::ROLE_MEMBER);
        $post = $this->seedPost($this->masjidA, $this->groupA);
        $attachment = $post->attachments()->first();

        Sanctum::actingAs($this->adminA);

        $this->get($this->postsUrl() . '/' . $post->id . '/attachments/' . $attachment->id)
            ->assertOk();
    }

    // ---------- recording consent ----------

    #[Test]
    public function consent_can_be_recorded_against_a_guardian_edge(): void
    {
        $edge = $this->makeAdminAGuardian();

        Sanctum::actingAs($this->adminA);

        $this->putJson($this->consentUrl($edge), ['scope' => GroupMembership::CONSENT_MEDIA])
            ->assertOk();

        $this->assertTrue($edge->fresh()->consentCovers(GroupMembership::CONSENT_MEDIA));
    }

    #[Test]
    public function consent_can_be_withdrawn(): void
    {
        $edge = $this->makeAdminAGuardian(GroupMembership::CONSENT_MEDIA);

        Sanctum::actingAs($this->adminA);

        $this->deleteJson($this->consentUrl($edge))->assertOk();

        $this->assertDatabaseHas('group_memberships', [
            'id' => $edge->id,
            'consent_granted_at' => null,
            'consent_scope' => null,
        ]);
        $this->assertFalse($edge->fresh()->hasConsent());
    }

    #[Test]
    public function consent_cannot_be_recorded_against_a_participant(): void
    {
        $membership = $this->seedMembership(
            $this->masjidA, $this->groupA, $this->personA, GroupMembership::ROLE_MEMBER
        );

        Sanctum::actingAs($this->adminA);

        $this->putJson($this->consentUrl($membership), ['scope' => GroupMembership::CONSENT_MEDIA])
            ->assertStatus(422);
    }

    #[Test]
    public function an_unknown_consent_scope_is_refused(): void
    {
        $edge = $this->makeAdminAGuardian();

        Sanctum::actingAs($this->adminA);

        $this->putJson($this->consentUrl($edge), ['scope' => 'everything'])->assertStatus(422);
    }

    #[Test]
    public function consent_on_another_organizations_membership_is_a_404(): void
    {
        $foreignContact = Contact::factory()->create(['masjid_id' => $this->masjidB->id, 'email' => null]);
        $foreign = $this->seedMembership(
            $this->masjidB, $this->groupB, $foreignContact, GroupMembership::ROLE_MEMBER
        );

        Sanctum::actingAs($this->adminA);

        $url = $this->postsUrlBase() . '/groups/' . $this->groupA->id
            . '/members/' . $foreign->id . '/consent';

        $this->putJson($url, ['scope' => GroupMembership::CONSENT_FEED])->assertStatus(404);
    }

    // ---------- tenancy ----------

    #[Test]
    public function another_organizations_group_has_no_reachable_feed(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->getJson($this->postsUrl($this->groupB))->assertStatus(404);
    }

    #[Test]
    public function another_organizations_post_is_a_404_under_your_own_route(): void
    {
        $this->seedMembership($this->masjidA, $this->groupA, $this->personA, GroupMembership::ROLE_LEADER);
        $foreign = $this->seedPost($this->masjidB, $this->groupB, withImage: false);

        Sanctum::actingAs($this->adminA);

        $this->getJson($this->postsUrl() . '/' . $foreign->id)->assertStatus(404);
    }

    #[Test]
    public function another_organizations_attachment_is_a_404(): void
    {
        $this->seedMembership($this->masjidA, $this->groupA, $this->personA, GroupMembership::ROLE_LEADER);
        $mine = $this->seedPost($this->masjidA, $this->groupA);
        $theirs = $this->seedPost($this->masjidB, $this->groupB);
        $foreignAttachment = $theirs->attachments()->first();

        Sanctum::actingAs($this->adminA);

        // My own post in the path, their attachment id -> a MISS, not a filter.
        $this->getJson($this->postsUrl() . '/' . $mine->id . '/attachments/' . $foreignAttachment->id)
            ->assertStatus(404);
    }

    #[Test]
    public function an_admin_cannot_target_another_masjid_in_the_route(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->getJson($this->postsUrl($this->groupB, $this->masjidB))->assertStatus(403);
    }

    #[Test]
    public function a_client_supplied_masjid_id_cannot_plant_a_post_elsewhere(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->postJson($this->postsUrl(), [
            'masjid_id' => $this->masjidB->id,
            'body' => 'Planted.',
        ])->assertStatus(201);

        $this->assertDatabaseMissing('group_posts', [
            'body' => 'Planted.',
            'masjid_id' => $this->masjidB->id,
        ]);
    }

    private function postsUrlBase(): string
    {
        return '/api/admin/masjids/' . $this->masjidA->id;
    }

    private function consentUrl(GroupMembership $membership): string
    {
        return $this->postsUrlBase() . '/groups/' . $this->groupA->id
            . '/members/' . $membership->id . '/consent';
    }
}
