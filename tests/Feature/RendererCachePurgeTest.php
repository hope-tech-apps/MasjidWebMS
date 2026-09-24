<?php

namespace Tests\Feature;

use App\Jobs\PurgeRendererCache;
use App\Jobs\PurgeRendererCacheAgain;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use App\Support\Renderer\RendererCachePurge;
use App\Support\Renderer\RendererPurgeScheduler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Save goes live (docs/live-preview.md §4.6): a successful write that changes what an
 * organisation's public site shows QUEUES a purge of that organisation's cached pages —
 * one first pass a few seconds after a burst, one second pass after its last save — and
 * the queued jobs make the signed call. Nothing is sent from the request itself; nothing
 * when unconfigured; nothing on reads or failed writes.
 */
class RendererCachePurgeTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'purge-secret-0123456789abcdef-0123456789ab';
    private const RENDERER = 'https://manara-renderer.pages.dev';
    private const ENDPOINT = self::RENDERER.'/__manara/purge';

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        config(['services.renderer' => [
            'secret' => self::SECRET,
            'preview_origin' => self::RENDERER,
            'purge_origins' => self::RENDERER,
            'admin_origins' => 'https://masjid.hopetechapps.com',
            'timeout' => 5,
        ]]);

        Queue::fake();
        Http::fake();
    }

    private function org(): Masjid
    {
        $masjid = Masjid::create([
            'name' => 'Purge Org '.uniqid(),
            'email' => 'purge'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0,
        ]);
        $masjid->forceFill(['capability_overrides' => ['web_pages' => true]])->save();

        return $masjid;
    }

    private function actAsAdmin(Masjid $masjid): User
    {
        $user = User::factory()->create(['type' => 'MasjidAdmin', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
        MasjidUser::create(['masjid_id' => $masjid->id, 'user_id' => $user->id, 'role' => 'masjid-admin', 'is_default' => true]);
        Sanctum::actingAs($user->fresh());

        return $user;
    }

    private function assertQueuedFor(Masjid $masjid): void
    {
        Queue::assertPushed(PurgeRendererCache::class, 1);
        Queue::assertPushed(PurgeRendererCache::class, fn (PurgeRendererCache $job) => $job->organisationId === $masjid->id
            && now()->diffInSeconds($job->delay, false) <= RendererPurgeScheduler::FIRST_PASS_DELAY
            && now()->diffInSeconds($job->delay, false) >= RendererPurgeScheduler::FIRST_PASS_DELAY - 2);
        Queue::assertPushed(PurgeRendererCacheAgain::class, 1);
        Queue::assertPushed(PurgeRendererCacheAgain::class, fn (PurgeRendererCacheAgain $job) => $job->organisationId === $masjid->id);
    }

    private function fakeRenderer(array ...$answers): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        $sequence = Http::sequence();
        foreach ($answers ?: [['ok' => true, 'scanned' => 4, 'deleted' => 2, 'remaining' => false, 'cursor' => null]] as $answer) {
            $sequence->push($answer, 200);
        }
        Http::fake([self::ENDPOINT => $sequence]);
    }

    private function assertSignedPurge(ClientRequest $request, array $body): bool
    {
        $timestamp = $request->header('X-Manara-Timestamp')[0] ?? '';

        return $request->url() === self::ENDPOINT
            && $request->method() === 'POST'
            && $request->body() === json_encode($body, JSON_UNESCAPED_SLASHES)
            && abs((int) $timestamp - now()->getTimestamp()) <= 5
            && ($request->header('X-Manara-Signature')[0] ?? '') === RendererCachePurge::signature(self::SECRET, $timestamp, $request->body());
    }

    #[Test]
    public function a_save_queues_both_passes_and_sends_nothing_from_the_request(): void
    {
        $masjid = $this->org();
        $this->actAsAdmin($masjid);

        $this->postJson("/api/admin/masjids/{$masjid->id}/pages", ['slug' => 'ramadan', 'title' => 'Ramadan'])->assertSuccessful();

        $this->assertQueuedFor($masjid);
        Http::assertNothingSent();
    }

    #[Test]
    public function a_burst_of_writes_queues_one_first_pass_and_one_second_pass(): void
    {
        $masjid = $this->org();
        $this->actAsAdmin($masjid);

        $id = $this->postJson("/api/admin/masjids/{$masjid->id}/pages", ['slug' => 'about', 'title' => 'About'])->assertSuccessful()->json('data.id');
        $this->putJson("/api/admin/masjids/{$masjid->id}/pages/{$id}", ['title' => 'About us'])->assertSuccessful();
        $this->postJson("/api/admin/masjids/{$masjid->id}/pages/reorder", ['pages' => [['id' => $id, 'order' => 3]]])->assertSuccessful();
        $this->deleteJson("/api/admin/masjids/{$masjid->id}/pages/{$id}")->assertSuccessful();

        // Four writes (a reorder is one request per row): one purge of each kind, not four.
        $this->assertQueuedFor($masjid);
        Http::assertNothingSent();
    }

    #[Test]
    public function the_first_pass_makes_one_signed_call_for_its_organisation(): void
    {
        $this->fakeRenderer();

        (new PurgeRendererCache(13))->handle(app(RendererCachePurge::class));

        Http::assertSentCount(1);
        Http::assertSent(fn (ClientRequest $request) => $this->assertSignedPurge($request, ['v' => 1, 'org' => 13]));
    }

    #[Test]
    public function the_second_pass_trails_the_last_save_not_the_first(): void
    {
        $this->fakeRenderer();
        $this->travelTo(now()->startOfMinute());
        $t0 = now()->getTimestamp();

        RendererPurgeScheduler::afterSave(13);                       // t0: first save queues the job
        $this->travel(70)->seconds();
        RendererPurgeScheduler::afterSave(13);                       // t0+70: a later save in the window
        Queue::assertPushed(PurgeRendererCacheAgain::class, 1);      // still one job

        $this->travel(5)->seconds();                                 // t0+75: the job's first run
        $job = (new PurgeRendererCacheAgain(13))->withFakeQueueInteractions();
        $job->handle(app(RendererCachePurge::class));
        $job->assertReleased(delay: 70);                             // waits for t0+145
        Http::assertNothingSent();

        $this->travelTo(\Illuminate\Support\Carbon::createFromTimestamp($t0 + 145));
        $job = (new PurgeRendererCacheAgain(13))->withFakeQueueInteractions();
        $job->handle(app(RendererCachePurge::class));
        $job->assertNotReleased();
        Http::assertSentCount(1);                                    // purged at t0+145, 75 s after the last save
    }

    #[Test]
    public function the_second_pass_lock_outlives_a_whole_editing_session_and_releases_do_not_exhaust_it(): void
    {
        $job = new PurgeRendererCacheAgain(13);
        $this->assertSame('renderer-purge-again-13', $job->uniqueId());
        $this->assertNotSame($job->uniqueId(), (new PurgeRendererCacheAgain(14))->uniqueId());
        $this->assertGreaterThanOrEqual(1800, $job->uniqueFor);
        $this->assertGreaterThan(now()->addMinutes(29)->getTimestamp(), $job->retryUntil()->getTimestamp());
        $this->assertSame('renderer-purge-first-13', (new PurgeRendererCache(13))->uniqueId());
    }

    #[Test]
    public function a_super_admin_save_purges_the_organisation_in_the_route(): void
    {
        $masjid = $this->org();
        Sanctum::actingAs(User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+15550000001'])->fresh());

        $this->postJson("/api/admin/masjids/{$masjid->id}/theme", ['primary_color' => '#123456'])->assertOk();

        $this->assertQueuedFor($masjid);
    }

    #[Test]
    public function saving_the_theme_queues_a_purge_and_previewing_it_does_not(): void
    {
        $masjid = $this->org();
        $this->actAsAdmin($masjid);

        $this->postJson("/api/admin/masjids/{$masjid->id}/theme/preview", ['primary_color' => '#123456'])->assertOk();
        Queue::assertNothingPushed();

        $this->postJson("/api/admin/masjids/{$masjid->id}/theme", ['primary_color' => '#123456'])->assertOk();
        $this->assertQueuedFor($masjid);
    }

    #[Test]
    public function reads_and_failed_writes_queue_nothing(): void
    {
        $masjid = $this->org();
        $this->actAsAdmin($masjid);

        $this->getJson("/api/admin/masjids/{$masjid->id}/pages")->assertOk();
        $this->getJson("/api/admin/masjids/{$masjid->id}/theme")->assertOk();
        $this->postJson("/api/admin/masjids/{$masjid->id}/pages", ['title' => 'No slug'])->assertStatus(422);
        $this->postJson("/api/admin/masjids/{$masjid->id}/theme", ['primary_color' => 'red'])->assertStatus(422);

        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    #[Test]
    public function unconfigured_a_save_queues_nothing_and_still_succeeds(): void
    {
        $masjid = $this->org();
        $this->actAsAdmin($masjid);

        foreach ([['secret' => ''], ['secret' => str_repeat('s', 31)], ['purge_origins' => ''], ['purge_origins' => 'not-an-origin']] as $partial) {
            config(['services.renderer' => array_merge(config('services.renderer'), $partial)]);
            $this->postJson("/api/admin/masjids/{$masjid->id}/theme", ['primary_color' => '#123456'])->assertOk();
            config(['services.renderer.secret' => self::SECRET, 'services.renderer.purge_origins' => self::RENDERER]);
        }

        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    #[Test]
    public function a_renderer_that_refuses_or_is_down_is_logged_at_warning(): void
    {
        Log::spy();

        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake([self::ENDPOINT => Http::response(['ok' => false], 401)]);
        $this->assertFalse(app(RendererCachePurge::class)->purge(13)[self::RENDERER]['ok']);

        Http::swap(new \Illuminate\Http\Client\Factory());
        // A Guzzle connect failure, which the HTTP client raises as ConnectionException.
        Http::fake([self::ENDPOINT => fn () => throw new \GuzzleHttp\Exception\ConnectException('timed out', new \GuzzleHttp\Psr7\Request('POST', self::ENDPOINT))]);
        $this->assertFalse(app(RendererCachePurge::class)->purge(13)[self::RENDERER]['ok']);

        Log::shouldHaveReceived('warning')->with('Renderer cache purge refused', \Mockery::on(fn ($c) => $c['masjid_id'] === 13 && $c['status'] === 401))->once();
        Log::shouldHaveReceived('warning')->with('Renderer cache purge failed', \Mockery::on(fn ($c) => $c['exception'] === ConnectionException::class))->once();
    }

    #[Test]
    public function remaining_entries_are_fetched_by_cursor_in_the_signed_body(): void
    {
        $this->fakeRenderer(
            ['ok' => true, 'deleted' => 800, 'remaining' => true, 'cursor' => 'nitro:routes:b1:p.x:host.y.json'],
            ['ok' => true, 'deleted' => 12, 'remaining' => false, 'cursor' => null],
        );

        $this->assertSame(
            [self::RENDERER => ['ok' => true, 'deleted' => 812, 'calls' => 2]],
            app(RendererCachePurge::class)->purge(13),
        );
        Http::assertSentCount(2);
        Http::assertSent(fn (ClientRequest $request) => $this->assertSignedPurge($request, ['v' => 1, 'org' => 13, 'after' => 'nitro:routes:b1:p.x:host.y.json']));
    }

    #[Test]
    public function a_renderer_that_always_has_more_is_called_exactly_max_calls_times_and_logged(): void
    {
        Log::spy();
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake([self::ENDPOINT => Http::response(['ok' => true, 'deleted' => 800, 'remaining' => true, 'cursor' => 'nitro:routes:b1:z.json'])]);

        $result = app(RendererCachePurge::class)->purge(13);

        Http::assertSentCount(RendererCachePurge::MAX_CALLS);
        $this->assertSame(RendererCachePurge::MAX_CALLS, $result[self::RENDERER]['calls']);
        $this->assertSame(800 * RendererCachePurge::MAX_CALLS, $result[self::RENDERER]['deleted']);
        Log::shouldHaveReceived('warning')->with('Renderer cache purge stopped with entries remaining', \Mockery::any())->once();
    }

    #[Test]
    public function the_writes_that_purge_are_exactly_the_site_editors_writes_and_their_bound_sources(): void
    {
        $prefix = 'api/admin/masjids/{masjid_id}/';
        // Route groups whose every write purges, and the writes inside them that must not.
        $groups = ['pages', 'sections', 'general-settings', 'details', 'about', 'donation-link',
            'contact-reasons', 'forms', 'offerings'];
        $exempt = ['pages/preview-session', 'forms/{form_id}/responses', 'forms/{form_id}/staff-codes',
            'offerings/{offering_id}/registrations'];

        $purging = [];
        $expected = [];
        foreach (Route::getRoutes() as $route) {
            $writes = array_diff($route->methods(), ['GET', 'HEAD']);
            if ($writes === [] || ! str_starts_with($route->uri(), $prefix)) {
                continue;
            }
            $rest = substr($route->uri(), strlen($prefix));
            $inGroup = false;
            foreach ($groups as $group) {
                $inGroup = $inGroup || $rest === $group || str_starts_with($rest, $group.'/');
            }
            foreach ($exempt as $skip) {
                $inGroup = $inGroup && $rest !== $skip && ! str_starts_with($rest, $skip.'/') && ! str_starts_with($rest, $skip);
            }
            $inGroup = $inGroup || $rest === 'theme';

            foreach ($writes as $method) {
                if (in_array('renderer.purge', $route->gatherMiddleware(), true)) {
                    $purging[] = "{$method} {$rest}";
                }
                if ($inGroup) {
                    $expected[] = "{$method} {$rest}";
                }
            }
        }
        sort($purging);
        sort($expected);

        $this->assertSame($expected, $purging);
        $this->assertContains('POST pages/{page_id}/sections', $purging, 'control: page sections purge');
        $this->assertContains('POST offerings/{offering_id}/fee-plans', $purging, 'control: fee plans purge');
        $this->assertNotContains('POST theme/preview', $purging);
        $this->assertNotContains('POST theme/preview-session', $purging);
        $this->assertNotContains('POST splash-announcements', $purging, 'splash is fetched in the browser; its cache is Laravel\'s');
    }
}
