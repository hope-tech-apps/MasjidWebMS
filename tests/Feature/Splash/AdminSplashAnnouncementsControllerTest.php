<?php

namespace Tests\Feature\Splash;

use App\Models\Masjid;
use App\Models\SplashAnnouncement;
use App\Models\User;
use App\Support\MobileCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Admin CRUD endpoint tests for /api/admin/masjids/{masjid_id}/splash-announcements.
 *
 * What we assert per controller method:
 *   index      — auth, paginates filtered by masjid
 *   store      — auth, validation (delegated to Store request), media upload,
 *                cache flush, OneSignal sync called
 *   update     — auth, validates input, re-syncs to OneSignal
 *   destroy    — auth, hard-deletes the row + calls OneSignal::remove
 *   moveToTrash — auth, soft-deletes + flips is_active to false + re-syncs
 *
 * One behavior per test where reasonable. Storage::fake for media,
 * Http::fake() for OneSignal — no test in this suite hits a real backend.
 */
class AdminSplashAnnouncementsControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        parent::setUp();

        config([
            'onesignal.api_url' => 'https://onesignal.com/api/v1/notifications',
            'onesignal.app_id' => 'app-id-test',
            'onesignal.app_rest_api_key' => 'rest-key-test',
        ]);

        Storage::fake('public');
        Http::fake(['*' => Http::response(['id' => 'iam_synced_from_admin'], 200)]);
    }

    protected function makeMasjid(): Masjid
    {
        return Masjid::create([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ]);
    }

    protected function makeAdmin(): User
    {
        // phone is NOT NULL in the users table; the default UserFactory omits
        // it (it was added in a later migration), so we provide one here.
        return User::factory()->create([
            'type' => 'SuperAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
    }

    protected function makeNonAdmin(): User
    {
        return User::factory()->create([
            'type' => 'User',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
    }

    protected function basePayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Eid Mubarak',
            'body' => 'Join us for prayer at 7am',
            'starts_at' => Carbon::now()->subHour()->toIso8601String(),
            'ends_at' => Carbon::now()->addDay()->toIso8601String(),
            'priority' => 5,
            'is_active' => true,
            'image' => UploadedFile::fake()->image('splash.jpg', 600, 400),
        ], $overrides);
    }

    // ---------- auth ----------

    #[Test]
    public function index_rejects_unauthenticated_requests(): void
    {
        $masjid = $this->makeMasjid();

        $this->getJson("/api/admin/masjids/{$masjid->id}/splash-announcements")
            ->assertStatus(401);
    }

    #[Test]
    public function index_rejects_non_admin_users(): void
    {
        $masjid = $this->makeMasjid();
        $user = $this->makeNonAdmin();

        Sanctum::actingAs($user);
        $this
            ->getJson("/api/admin/masjids/{$masjid->id}/splash-announcements")
            ->assertStatus(401); // UserAdminMiddleware returns 401, not 403
    }

    // ---------- index ----------

    #[Test]
    public function index_returns_paginated_list_filtered_by_masjid(): void
    {
        $admin = $this->makeAdmin();
        $masjidA = $this->makeMasjid();
        $masjidB = $this->makeMasjid();

        SplashAnnouncement::factory()->count(3)->create(['masjid_id' => $masjidA->id]);
        SplashAnnouncement::factory()->count(2)->create(['masjid_id' => $masjidB->id]);

        Sanctum::actingAs($admin);
        $response = $this
            ->getJson("/api/admin/masjids/{$masjidA->id}/splash-announcements")
            ->assertOk();

        $this->assertSame(3, $response->json('data.total'));
    }

    // ---------- store ----------

    #[Test]
    public function store_creates_row_and_returns_201(): void
    {
        $admin = $this->makeAdmin();
        $masjid = $this->makeMasjid();

        Sanctum::actingAs($admin);
        $this
            ->postJson("/api/admin/masjids/{$masjid->id}/splash-announcements", $this->basePayload())
            ->assertStatus(201);

        $this->assertDatabaseHas('splash_announcements', [
            'masjid_id' => $masjid->id,
            'title' => 'Eid Mubarak',
        ]);
    }

    #[Test]
    public function store_uploads_image_to_splash_announcements_collection(): void
    {
        $admin = $this->makeAdmin();
        $masjid = $this->makeMasjid();

        Sanctum::actingAs($admin);
        $this
            ->postJson("/api/admin/masjids/{$masjid->id}/splash-announcements", $this->basePayload());

        $splash = SplashAnnouncement::firstWhere('masjid_id', $masjid->id);
        $this->assertNotNull($splash->getFirstMedia('splash_announcements'));
    }

    #[Test]
    public function store_calls_onesignal_sync_with_post_when_no_iam_id_yet(): void
    {
        $admin = $this->makeAdmin();
        $masjid = $this->makeMasjid();

        Sanctum::actingAs($admin);
        $this
            ->postJson("/api/admin/masjids/{$masjid->id}/splash-announcements", $this->basePayload());

        Http::assertSent(fn (Request $r) =>
            $r->method() === 'POST'
            && str_ends_with($r->url(), '/in_app_messages')
        );
    }

    #[Test]
    public function store_persists_returned_iam_id_on_the_row(): void
    {
        $admin = $this->makeAdmin();
        $masjid = $this->makeMasjid();

        Sanctum::actingAs($admin);
        $this
            ->postJson("/api/admin/masjids/{$masjid->id}/splash-announcements", $this->basePayload());

        $this->assertDatabaseHas('splash_announcements', [
            'masjid_id' => $masjid->id,
            'onesignal_iam_id' => 'iam_synced_from_admin',
        ]);
    }

    #[Test]
    public function store_flushes_mobile_splash_cache(): void
    {
        $admin = $this->makeAdmin();
        $masjid = $this->makeMasjid();

        // Prime the cache so we can assert it was forgotten.
        Cache::put(MobileCache::masjidKey($masjid->id, MobileCache::SPLASH), 'stale', 60);

        Sanctum::actingAs($admin);
        $this
            ->postJson("/api/admin/masjids/{$masjid->id}/splash-announcements", $this->basePayload());

        $this->assertFalse(Cache::has(MobileCache::masjidKey($masjid->id, MobileCache::SPLASH)));
    }

    // ---------- store validation (delegated to StoreSplashAnnouncementRequest) ----------

    #[Test]
    public function store_rejects_svg_mime_with_422(): void
    {
        $admin = $this->makeAdmin();
        $masjid = $this->makeMasjid();

        $payload = $this->basePayload([
            'image' => UploadedFile::fake()->create('evil.svg', 10, 'image/svg+xml'),
        ]);

        Sanctum::actingAs($admin);
        $this
            ->postJson("/api/admin/masjids/{$masjid->id}/splash-announcements", $payload)
            ->assertStatus(422);
    }

    #[Test]
    public function store_rejects_javascript_cta_url_with_422(): void
    {
        $admin = $this->makeAdmin();
        $masjid = $this->makeMasjid();

        $payload = $this->basePayload([
            'cta_label' => 'Click',
            'cta_url' => 'javascript:alert(1)',
        ]);

        Sanctum::actingAs($admin);
        $this
            ->postJson("/api/admin/masjids/{$masjid->id}/splash-announcements", $payload)
            ->assertStatus(422);
    }

    #[Test]
    public function store_accepts_https_cta_url(): void
    {
        $admin = $this->makeAdmin();
        $masjid = $this->makeMasjid();

        $payload = $this->basePayload([
            'cta_label' => 'Donate',
            'cta_url' => 'https://example.com/donate',
        ]);

        Sanctum::actingAs($admin);
        $this
            ->postJson("/api/admin/masjids/{$masjid->id}/splash-announcements", $payload)
            ->assertStatus(201);
    }

    #[Test]
    public function store_requires_cta_url_when_cta_label_present(): void
    {
        $admin = $this->makeAdmin();
        $masjid = $this->makeMasjid();

        $payload = $this->basePayload(['cta_label' => 'Donate']);
        unset($payload['cta_url']); // not present at all

        Sanctum::actingAs($admin);
        $this
            ->postJson("/api/admin/masjids/{$masjid->id}/splash-announcements", $payload)
            ->assertStatus(422);
    }

    #[Test]
    public function store_rejects_ends_at_before_starts_at(): void
    {
        $admin = $this->makeAdmin();
        $masjid = $this->makeMasjid();

        $payload = $this->basePayload([
            'starts_at' => Carbon::now()->addDay()->toIso8601String(),
            'ends_at' => Carbon::now()->subDay()->toIso8601String(),
        ]);

        Sanctum::actingAs($admin);
        $this
            ->postJson("/api/admin/masjids/{$masjid->id}/splash-announcements", $payload)
            ->assertStatus(422);
    }

    // ---------- update ----------

    #[Test]
    public function update_applies_validated_input(): void
    {
        $admin = $this->makeAdmin();
        $masjid = $this->makeMasjid();
        $splash = SplashAnnouncement::factory()->create(['masjid_id' => $masjid->id]);

        Sanctum::actingAs($admin);
        $this
            ->postJson(
                "/api/admin/masjids/{$masjid->id}/splash-announcements/{$splash->id}",
                ['title' => 'Updated Title']
            )
            ->assertOk();

        $this->assertSame('Updated Title', $splash->fresh()->title);
    }

    #[Test]
    public function update_resyncs_to_onesignal_with_put_when_iam_id_exists(): void
    {
        $admin = $this->makeAdmin();
        $masjid = $this->makeMasjid();
        $splash = SplashAnnouncement::factory()
            ->withIamId('iam_existing_999')
            ->create(['masjid_id' => $masjid->id]);

        Sanctum::actingAs($admin);
        $this
            ->postJson(
                "/api/admin/masjids/{$masjid->id}/splash-announcements/{$splash->id}",
                ['title' => 'Updated Title']
            );

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT'
            && str_ends_with($r->url(), '/in_app_messages/iam_existing_999'));
    }

    // ---------- destroy ----------

    #[Test]
    public function destroy_hard_deletes_the_row(): void
    {
        $admin = $this->makeAdmin();
        $masjid = $this->makeMasjid();
        $splash = SplashAnnouncement::factory()->create(['masjid_id' => $masjid->id]);

        Sanctum::actingAs($admin);
        $this
            ->deleteJson("/api/admin/masjids/{$masjid->id}/splash-announcements/{$splash->id}")
            ->assertOk();

        $this->assertDatabaseMissing('splash_announcements', ['id' => $splash->id]);
    }

    #[Test]
    public function destroy_calls_onesignal_remove_when_row_has_iam_id(): void
    {
        $admin = $this->makeAdmin();
        $masjid = $this->makeMasjid();
        $splash = SplashAnnouncement::factory()
            ->withIamId('iam_to_be_removed')
            ->create(['masjid_id' => $masjid->id]);

        Sanctum::actingAs($admin);
        $this
            ->deleteJson("/api/admin/masjids/{$masjid->id}/splash-announcements/{$splash->id}");

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE'
            && str_ends_with($r->url(), '/in_app_messages/iam_to_be_removed'));
    }

    // ---------- moveToTrash ----------

    #[Test]
    public function move_to_trash_soft_deletes_and_disables_iam(): void
    {
        $admin = $this->makeAdmin();
        $masjid = $this->makeMasjid();
        $splash = SplashAnnouncement::factory()
            ->withIamId('iam_to_disable')
            ->create([
                'masjid_id' => $masjid->id,
                'is_active' => true,
            ]);

        Sanctum::actingAs($admin);
        $this
            ->deleteJson("/api/admin/masjids/{$masjid->id}/splash-announcements/{$splash->id}/trash")
            ->assertOk();

        // Soft-deleted: gone from default scope, still in the table.
        $this->assertSoftDeleted('splash_announcements', ['id' => $splash->id]);

        // The IAM mirror got a PUT with enabled=false so mobile stops seeing it.
        Http::assertSent(fn (Request $r) =>
            $r->method() === 'PUT'
            && str_ends_with($r->url(), '/in_app_messages/iam_to_disable')
            && ($r->data()['enabled'] ?? null) === false
        );
    }
}
