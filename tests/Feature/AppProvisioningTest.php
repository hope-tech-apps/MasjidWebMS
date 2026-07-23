<?php

namespace Tests\Feature;

use App\Models\Masjid;
use App\Models\ProvisioningJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Portal control plane for app provisioning.
 *
 * Covers the two sides of the contract:
 *   - Dispatch: a SuperAdmin's "Generate apps" fires a GitHub repository_dispatch
 *     per platform (GitHub call MOCKED via Http::fake), creating a provisioning_jobs
 *     row that flips queued -> dispatched, and carries the exact contract payload.
 *   - Callback: the /api/provisioning/callback route is UNAUTHed but gated by the
 *     per-job callback_token (constant-time compared); a correct token advances the
 *     job, a wrong/absent token or unknown job_id is an opaque 404.
 *
 * Sqlite-in-memory is forced in setUp (mirrors CrmFeatureGateTest).
 */
class AppProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjid;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        // Deterministic config for the contract assertions.
        config([
            'services.github.dispatch_token' => 'test-dispatch-token',
            'services.github.ios_repo' => 'hope-tech-apps/burlington-masjid-iOS',
            'services.github.android_repo' => 'hope-tech-apps/burlington-masjid-Android',
            'services.github.development_team' => 'PLATFORMTEAM',
            'services.github.ios_bundle_prefix' => 'com.hopetechapps',
        ]);

        $this->masjid = Masjid::create([
            'name' => 'Test Masjid',
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ]);
    }

    private function makeSuperAdmin(): User
    {
        return User::factory()->create([
            'type' => 'SuperAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
    }

    private function makeMasjidAdmin(): User
    {
        $admin = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
        $this->masjid->user_id = $admin->id;
        $this->masjid->save();

        return $admin;
    }

    // ---------- dispatch ----------

    #[Test]
    public function a_superadmin_dispatches_both_platforms_and_jobs_flip_to_dispatched(): void
    {
        Http::fake(['api.github.com/*' => Http::response('', 204)]);
        Sanctum::actingAs($this->makeSuperAdmin());

        $res = $this->postJson("/api/admin/masjids/{$this->masjid->id}/provision-apps", [
            'platforms' => ['ios', 'android'],
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonCount(2, 'data.jobs');

        $this->assertDatabaseCount('provisioning_jobs', 2);
        $this->assertDatabaseHas('provisioning_jobs', [
            'masjid_id' => $this->masjid->id,
            'platform' => 'ios',
            'status' => ProvisioningJob::STATUS_DISPATCHED,
            'github_repo' => 'hope-tech-apps/burlington-masjid-iOS',
        ]);
        $this->assertDatabaseHas('provisioning_jobs', [
            'platform' => 'android',
            'status' => ProvisioningJob::STATUS_DISPATCHED,
            'github_repo' => 'hope-tech-apps/burlington-masjid-Android',
        ]);

        // Two dispatches sent to the two repos, with the contract shape.
        Http::assertSentCount(2);
        Http::assertSent(function ($request) {
            $isIos = str_contains($request->url(), 'burlington-masjid-iOS/dispatches');
            if (! $isIos) {
                return false;
            }
            $data = $request->data();

            return $request['event_type'] === 'scaffold-masjid'
                && $request->hasHeader('Authorization', 'Bearer test-dispatch-token')
                && $request->hasHeader('Accept', 'application/vnd.github+json')
                && $data['client_payload']['masjid_id'] === $this->masjid->id
                && $data['client_payload']['development_team'] === 'PLATFORMTEAM'
                && $data['client_payload']['bundle_id'] === 'com.hopetechapps.testmasjid'
                && $data['client_payload']['include_tvos'] === false
                && ! empty($data['client_payload']['job_id'])
                && ! empty($data['client_payload']['callback_token'])
                && str_ends_with($data['client_payload']['callback_url'], '/api/provisioning/callback');
        });
    }

    #[Test]
    public function a_failed_dispatch_marks_the_job_failed_with_detail(): void
    {
        // Token missing -> the service fails soft, the job is marked failed and
        // NO GitHub request is attempted.
        config(['services.github.dispatch_token' => null]);
        Http::fake();
        Sanctum::actingAs($this->makeSuperAdmin());

        $this->postJson("/api/admin/masjids/{$this->masjid->id}/provision-apps", [
            'platforms' => ['ios'],
        ])->assertStatus(201);

        $job = ProvisioningJob::where('platform', 'ios')->firstOrFail();
        $this->assertSame(ProvisioningJob::STATUS_FAILED, $job->status);
        $this->assertNotNull($job->detail);
        Http::assertNothingSent();
    }

    #[Test]
    public function a_masjid_admin_cannot_dispatch(): void
    {
        Http::fake();
        Sanctum::actingAs($this->makeMasjidAdmin());

        // The `super` middleware (SuperAdminMiddleware) returns 401 for a
        // non-super caller — it never reaches the controller.
        $this->postJson("/api/admin/masjids/{$this->masjid->id}/provision-apps", [
            'platforms' => ['ios'],
        ])->assertStatus(401);

        $this->assertDatabaseCount('provisioning_jobs', 0);
        Http::assertNothingSent();
    }

    #[Test]
    public function provision_requires_at_least_one_valid_platform(): void
    {
        Http::fake();
        Sanctum::actingAs($this->makeSuperAdmin());

        $this->postJson("/api/admin/masjids/{$this->masjid->id}/provision-apps", [
            'platforms' => [],
        ])->assertStatus(422);

        $this->postJson("/api/admin/masjids/{$this->masjid->id}/provision-apps", [
            'platforms' => ['windows'],
        ])->assertStatus(422);
    }

    // ---------- jobs list ----------

    #[Test]
    public function the_jobs_index_never_leaks_the_callback_token(): void
    {
        Sanctum::actingAs($this->makeSuperAdmin());

        $job = ProvisioningJob::create([
            'masjid_id' => $this->masjid->id,
            'platform' => 'ios',
            'github_repo' => 'hope-tech-apps/burlington-masjid-iOS',
            'status' => ProvisioningJob::STATUS_QUEUED,
        ]);

        $res = $this->getJson("/api/admin/masjids/{$this->masjid->id}/provisioning-jobs")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertStringNotContainsString($job->callback_token, $res->getContent());
        $this->assertStringNotContainsString('callback_token', $res->getContent());
    }

    // ---------- callback auth ----------

    private function makeJob(): ProvisioningJob
    {
        return ProvisioningJob::create([
            'masjid_id' => $this->masjid->id,
            'platform' => 'ios',
            'github_repo' => 'hope-tech-apps/burlington-masjid-iOS',
            'status' => ProvisioningJob::STATUS_DISPATCHED,
        ]);
    }

    #[Test]
    public function a_valid_callback_token_advances_the_job(): void
    {
        $job = $this->makeJob();

        $this->withHeaders(['Authorization' => 'Bearer ' . $job->callback_token])
            ->postJson('/api/provisioning/callback', [
                'job_id' => $job->job_id,
                'platform' => 'ios',
                'status' => 'building',
                'detail' => 'Compiling Release',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $fresh = $job->fresh();
        $this->assertSame('building', $fresh->status);
        $this->assertSame('Compiling Release', $fresh->detail);
    }

    #[Test]
    public function a_valid_callback_can_attach_an_artifact_url_on_success(): void
    {
        $job = $this->makeJob();

        $this->withHeaders(['Authorization' => 'Bearer ' . $job->callback_token])
            ->postJson('/api/provisioning/callback', [
                'job_id' => $job->job_id,
                'status' => 'uploaded',
                'artifact_url' => 'https://appstoreconnect.apple.com/builds/123',
            ])
            ->assertOk();

        $fresh = $job->fresh();
        $this->assertSame('uploaded', $fresh->status);
        $this->assertSame('https://appstoreconnect.apple.com/builds/123', $fresh->artifact_url);
    }

    #[Test]
    public function a_wrong_callback_token_is_rejected_and_the_job_is_unchanged(): void
    {
        $job = $this->makeJob();

        $this->withHeaders(['Authorization' => 'Bearer ' . str_repeat('x', 40)])
            ->postJson('/api/provisioning/callback', [
                'job_id' => $job->job_id,
                'status' => 'failed',
            ])
            ->assertStatus(404);

        $this->assertSame(ProvisioningJob::STATUS_DISPATCHED, $job->fresh()->status);
    }

    #[Test]
    public function a_missing_callback_token_is_rejected(): void
    {
        $job = $this->makeJob();

        $this->postJson('/api/provisioning/callback', [
            'job_id' => $job->job_id,
            'status' => 'failed',
        ])->assertStatus(404);

        $this->assertSame(ProvisioningJob::STATUS_DISPATCHED, $job->fresh()->status);
    }

    #[Test]
    public function an_unknown_job_id_is_an_opaque_404(): void
    {
        $this->withHeaders(['Authorization' => 'Bearer ' . str_repeat('y', 40)])
            ->postJson('/api/provisioning/callback', [
                'job_id' => 'ffffffff-ffff-ffff-ffff-ffffffffffff',
                'status' => 'failed',
            ])
            ->assertStatus(404);
    }

    #[Test]
    public function a_valid_token_with_an_invalid_status_is_422(): void
    {
        $job = $this->makeJob();

        $this->withHeaders(['Authorization' => 'Bearer ' . $job->callback_token])
            ->postJson('/api/provisioning/callback', [
                'job_id' => $job->job_id,
                'status' => 'exploded',
            ])
            ->assertStatus(422);

        $this->assertSame(ProvisioningJob::STATUS_DISPATCHED, $job->fresh()->status);
    }
}
