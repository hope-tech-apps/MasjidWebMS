<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupPost;
use App\Models\GroupPostAttachment;
use App\Models\Masjid;
use App\Support\GroupPostAttachments;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The model-layer guarantees behind the group feed (PLAN T-005b).
 *
 * MySQL has NO row-level security, so App\Models\Concerns\BelongsToMasjid is the
 * only thing keeping one organization's class story — and the photographs of
 * children attached to it — out of another's queries.
 * .claude/rules/tenant-scoping.md makes a cross-tenant test mandatory for every
 * new tenant-scoped model; this is it for BOTH GroupPost and
 * GroupPostAttachment, binding TenantContext directly the way
 * ResolveMasjidTenant does per request.
 *
 * It also pins the two things the MODEL owns rather than the HTTP boundary,
 * because they must hold for every caller:
 *   - retention: a post is stamped with a window on create, and the purge
 *     reaches the DISK, not just the row (a DB cascade fires no model events);
 *   - consent: what a guardian edge's recorded consent does and does not cover.
 */
class GroupFeedTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $tenant;

    private Masjid $masjidA;
    private Masjid $masjidB;

    private Group $groupA;
    private Group $groupB;

    protected function setUp(): void
    {
        parent::setUp();

        // Force sqlite-in-memory regardless of phpunit.xml — this suite must
        // never need a network DB to run.
        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        // Fake the disk the FEATURE names, not 'public' — group media is private
        // by construction. See .claude/rules/private-uploads.md.
        Storage::fake($this->disk());

        $this->tenant = app(TenantContext::class);
        // Every test starts UNBOUND; each binds explicitly when it needs to.
        $this->tenant->forgetTenant();

        $this->masjidA = $this->makeMasjid();
        $this->masjidB = $this->makeMasjid();

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
        ], $overrides));
    }

    private function makePost(Masjid $masjid, Group $group, array $overrides = []): GroupPost
    {
        return GroupPost::factory()->create(array_merge([
            'masjid_id' => $masjid->id,
            'group_id' => $group->id,
        ], $overrides));
    }

    /** Attach one image through the real writer, so the bytes actually land. */
    private function attachImage(GroupPost $post, string $name = 'today.jpg'): GroupPostAttachment
    {
        $stored = GroupPostAttachments::store($post, [
            UploadedFile::fake()->create($name, 40, 'image/jpeg'),
        ]);

        return $stored[0];
    }

    // ---------- tenant scope: posts ----------

    #[Test]
    public function the_scope_hides_another_organizations_posts(): void
    {
        $this->makePost($this->masjidA, $this->groupA);
        $this->makePost($this->masjidB, $this->groupB);

        $this->tenant->set($this->masjidA->id);

        $this->assertSame(1, GroupPost::count());
        $this->assertSame($this->masjidA->id, GroupPost::first()->masjid_id);
    }

    #[Test]
    public function another_organizations_post_is_not_findable_by_id(): void
    {
        $foreign = $this->makePost($this->masjidB, $this->groupB);

        $this->tenant->set($this->masjidA->id);

        $this->assertNull(GroupPost::find($foreign->id));
    }

    #[Test]
    public function the_creating_hook_stamps_the_bound_tenant_on_a_post(): void
    {
        $this->tenant->set($this->masjidA->id);

        // A client-supplied masjid_id must lose to the bound tenant.
        $post = GroupPost::create([
            'masjid_id' => $this->masjidB->id,
            'group_id' => $this->groupA->id,
            'body' => 'We finished Surah al-Fil.',
        ]);

        $this->assertSame($this->masjidA->id, $post->masjid_id);
    }

    #[Test]
    public function without_masjid_scope_sees_every_organizations_posts(): void
    {
        $this->makePost($this->masjidA, $this->groupA);
        $this->makePost($this->masjidB, $this->groupB);

        $this->tenant->set($this->masjidA->id);

        $this->assertSame(2, GroupPost::withoutMasjidScope()->count());
    }

    // ---------- tenant scope: attachments ----------

    #[Test]
    public function the_scope_hides_another_organizations_attachments(): void
    {
        $this->attachImage($this->makePost($this->masjidA, $this->groupA));
        $foreign = $this->attachImage($this->makePost($this->masjidB, $this->groupB));

        $this->tenant->set($this->masjidA->id);

        $this->assertSame(1, GroupPostAttachment::count());
        $this->assertNull(GroupPostAttachment::find($foreign->id));
    }

    #[Test]
    public function the_creating_hook_stamps_the_bound_tenant_on_an_attachment(): void
    {
        $post = $this->makePost($this->masjidA, $this->groupA);

        $this->tenant->set($this->masjidA->id);

        $attachment = GroupPostAttachment::create([
            'masjid_id' => $this->masjidB->id,
            'group_post_id' => $post->id,
            'original_name' => 'x.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 10,
            'disk' => $this->disk(),
            'path' => 'group-media/x.jpg',
        ]);

        $this->assertSame($this->masjidA->id, $attachment->masjid_id);
    }

    // ---------- where the bytes live ----------

    #[Test]
    public function an_image_lands_on_the_private_disk_under_a_random_name(): void
    {
        $post = $this->makePost($this->masjidA, $this->groupA);

        $attachment = $this->attachImage($post, 'eid party.jpg');

        Storage::disk($this->disk())->assertExists($attachment->path);

        // Tenant-scoped directory, per private-uploads rule 3.
        $this->assertStringStartsWith(
            'group-media/' . $this->masjidA->id . '/' . $this->groupA->id . '/',
            $attachment->path
        );
        // The uploader's filename is DATA. It is kept in the row and never
        // reaches the filesystem, so it cannot collide, traverse or be guessed.
        $this->assertSame('eid party.jpg', $attachment->original_name);
        $this->assertStringNotContainsString('eid', $attachment->path);
    }

    // ---------- deletion reaches the disk (or deliberately does not) ----------

    #[Test]
    public function soft_deleting_a_post_keeps_its_bytes(): void
    {
        $post = $this->makePost($this->masjidA, $this->groupA);
        $attachment = $this->attachImage($post);

        $post->delete();

        $this->assertSoftDeleted('group_posts', ['id' => $post->id]);
        // A mis-click must not destroy a term of classroom photographs.
        $this->assertDatabaseHas('group_post_attachments', ['id' => $attachment->id]);
        Storage::disk($this->disk())->assertExists($attachment->path);
    }

    #[Test]
    public function force_deleting_a_post_removes_its_attachment_rows_and_the_bytes(): void
    {
        $post = $this->makePost($this->masjidA, $this->groupA);
        $attachment = $this->attachImage($post);
        $path = $attachment->path;

        $post->purge();

        $this->assertDatabaseMissing('group_posts', ['id' => $post->id]);
        $this->assertDatabaseMissing('group_post_attachments', ['id' => $attachment->id]);
        Storage::disk($this->disk())->assertMissing($path);
    }

    #[Test]
    public function force_deleting_a_group_purges_its_feed_and_the_bytes(): void
    {
        // The FK cascades group_posts off groups at the DB level, and a DB
        // cascade fires no model events — without Group's deleting hook every
        // photograph here would be orphaned on disk forever.
        $post = $this->makePost($this->masjidA, $this->groupA);
        $path = $this->attachImage($post)->path;

        $this->groupA->forceDelete();

        $this->assertDatabaseMissing('group_posts', ['id' => $post->id]);
        $this->assertDatabaseCount('group_post_attachments', 0);
        Storage::disk($this->disk())->assertMissing($path);
    }

    #[Test]
    public function soft_deleting_a_group_leaves_its_feed_alone(): void
    {
        $post = $this->makePost($this->masjidA, $this->groupA);
        $path = $this->attachImage($post)->path;

        $this->groupA->delete();

        $this->assertDatabaseHas('group_posts', ['id' => $post->id, 'deleted_at' => null]);
        Storage::disk($this->disk())->assertExists($path);
    }

    // ---------- retention ----------

    #[Test]
    public function a_new_post_inherits_the_configured_retention_window(): void
    {
        config(['groups.feed.retention_days' => 30]);

        $post = $this->makePost($this->masjidA, $this->groupA);

        $this->assertSame(
            now()->addDays(30)->toDateString(),
            $post->retained_until->toDateString()
        );
    }

    #[Test]
    public function a_retention_window_the_caller_sets_is_respected(): void
    {
        config(['groups.feed.retention_days' => 30]);

        $post = $this->makePost($this->masjidA, $this->groupA, [
            'retained_until' => now()->addDays(2)->toDateString(),
        ]);

        $this->assertSame(now()->addDays(2)->toDateString(), $post->retained_until->toDateString());
    }

    #[Test]
    public function retention_days_of_zero_leaves_the_window_open(): void
    {
        config(['groups.feed.retention_days' => 0]);

        $post = $this->makePost($this->masjidA, $this->groupA);

        $this->assertNull($post->retained_until);
    }

    #[Test]
    public function the_purge_removes_posts_past_their_retention_date_and_the_bytes(): void
    {
        $due = $this->makePost($this->masjidA, $this->groupA, [
            'retained_until' => now()->subDay()->toDateString(),
        ]);
        $duePath = $this->attachImage($due)->path;

        // Soft-deleted AND past retention: exactly the row that must not linger.
        $removed = $this->makePost($this->masjidA, $this->groupA, [
            'retained_until' => now()->subMonth()->toDateString(),
        ]);
        $removedPath = $this->attachImage($removed)->path;
        $removed->delete();

        Artisan::call('groups:purge-feed');

        $this->assertDatabaseMissing('group_posts', ['id' => $due->id]);
        $this->assertDatabaseMissing('group_posts', ['id' => $removed->id]);
        $this->assertDatabaseCount('group_post_attachments', 0);
        Storage::disk($this->disk())->assertMissing($duePath);
        Storage::disk($this->disk())->assertMissing($removedPath);
    }

    #[Test]
    public function the_purge_leaves_posts_still_inside_their_retention_window(): void
    {
        $keep = $this->makePost($this->masjidA, $this->groupA, [
            'retained_until' => now()->addYear()->toDateString(),
        ]);
        $keepPath = $this->attachImage($keep)->path;

        $open = $this->makePost($this->masjidA, $this->groupA, ['retained_until' => null]);

        Artisan::call('groups:purge-feed');

        $this->assertDatabaseHas('group_posts', ['id' => $keep->id]);
        // A null window means "nobody has decided", never "purge me now".
        $this->assertDatabaseHas('group_posts', ['id' => $open->id]);
        Storage::disk($this->disk())->assertExists($keepPath);
    }

    #[Test]
    public function the_purge_can_be_limited_to_one_organization(): void
    {
        $mine = $this->makePost($this->masjidA, $this->groupA, [
            'retained_until' => now()->subDay()->toDateString(),
        ]);
        $theirs = $this->makePost($this->masjidB, $this->groupB, [
            'retained_until' => now()->subDay()->toDateString(),
        ]);

        Artisan::call('groups:purge-feed', ['--masjid' => $this->masjidA->id]);

        $this->assertDatabaseMissing('group_posts', ['id' => $mine->id]);
        $this->assertDatabaseHas('group_posts', ['id' => $theirs->id]);
    }

    #[Test]
    public function a_dry_run_purge_deletes_nothing(): void
    {
        $due = $this->makePost($this->masjidA, $this->groupA, [
            'retained_until' => now()->subDay()->toDateString(),
        ]);
        $path = $this->attachImage($due)->path;

        Artisan::call('groups:purge-feed', ['--dry-run' => true]);

        $this->assertDatabaseHas('group_posts', ['id' => $due->id]);
        Storage::disk($this->disk())->assertExists($path);
    }

    // ---------- consent, on the edge that records it ----------

    #[Test]
    public function a_guardian_edge_without_a_record_covers_nothing(): void
    {
        $edge = $this->guardianEdge();

        $this->assertFalse($edge->hasConsent());
        $this->assertFalse($edge->consentCovers(GroupMembership::CONSENT_FEED));
        $this->assertFalse($edge->consentCovers(GroupMembership::CONSENT_MEDIA));
    }

    #[Test]
    public function feed_consent_does_not_reach_the_images(): void
    {
        $edge = $this->guardianEdge(GroupMembership::CONSENT_FEED);

        $this->assertTrue($edge->consentCovers(GroupMembership::CONSENT_FEED));
        $this->assertFalse($edge->consentCovers(GroupMembership::CONSENT_MEDIA));
    }

    #[Test]
    public function media_consent_covers_the_feed_as_well(): void
    {
        $edge = $this->guardianEdge(GroupMembership::CONSENT_MEDIA);

        $this->assertTrue($edge->consentCovers(GroupMembership::CONSENT_FEED));
        $this->assertTrue($edge->consentCovers(GroupMembership::CONSENT_MEDIA));
    }

    #[Test]
    public function a_scope_nobody_recognizes_grants_nothing(): void
    {
        // A value we cannot interpret must never be read as permission.
        $edge = $this->guardianEdge('everything');

        $this->assertFalse($edge->hasConsent());
        $this->assertFalse($edge->consentCovers(GroupMembership::CONSENT_FEED));
    }

    #[Test]
    public function consent_on_a_participant_row_still_grants_nothing(): void
    {
        // Belt and braces: the controller refuses to write this, but a row that
        // somehow carried it must not read as a grant either — a participant IS
        // the person, and consent is something a guardian gives.
        $child = Contact::factory()->create(['masjid_id' => $this->masjidA->id]);

        $membership = GroupMembership::create([
            'masjid_id' => $this->masjidA->id,
            'group_id' => $this->groupA->id,
            'contact_id' => $child->id,
            'role' => GroupMembership::ROLE_MEMBER,
            'consent_granted_at' => now(),
            'consent_scope' => GroupMembership::CONSENT_MEDIA,
        ]);

        $this->assertFalse($membership->consentCovers(GroupMembership::CONSENT_MEDIA));
    }

    /** A (guardian, ward, group) edge in masjid A, optionally with consent. */
    private function guardianEdge(?string $scope = null): GroupMembership
    {
        $child = Contact::factory()->create(['masjid_id' => $this->masjidA->id]);
        $parent = Contact::factory()->create(['masjid_id' => $this->masjidA->id]);

        GroupMembership::create([
            'masjid_id' => $this->masjidA->id,
            'group_id' => $this->groupA->id,
            'contact_id' => $child->id,
            'role' => GroupMembership::ROLE_MEMBER,
        ]);

        return GroupMembership::create([
            'masjid_id' => $this->masjidA->id,
            'group_id' => $this->groupA->id,
            'contact_id' => $parent->id,
            'role' => GroupMembership::ROLE_GUARDIAN,
            'guardian_of_contact_id' => $child->id,
            'consent_granted_at' => $scope === null ? null : now(),
            'consent_scope' => $scope,
        ]);
    }
}
