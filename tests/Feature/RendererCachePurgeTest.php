<?php

namespace Tests\Feature;

use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use App\Support\Renderer\RendererCachePurge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Save goes live immediately (docs/live-preview.md §4.6): every successful write that
 * changes what an organisation's public site shows asks the renderer, with a signed
 * request, to drop that organisation's cached pages. After the response; never failing
 * the save; nothing when unconfigured; nothing on reads or failed writes.
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

    private function fakeRenderer(array ...$answers): void
    {
        $sequence = Http::sequence();
        foreach ($answers ?: [['ok' => true, 'scanned' => 4, 'deleted' => 2, 'remaining' => false]] as $answer) {
            $sequence->push($answer, 200);
        }
        Http::fake([self::ENDPOINT => $sequence]);
    }

    private function assertPurgedOnce(Masjid $masjid): void
    {
        Http::assertSentCount(1);
        Http::assertSent(function (ClientRequest $request) use ($masjid) {
            $timestamp = $request->header('X-Manara-Timestamp')[0] ?? '';

            return $request->url() === self::ENDPOINT
                && $request->method() === 'POST'
                && $request->body() === json_encode(['v' => 1, 'org' => $masjid->id])
                && abs((int) $timestamp - now()->getTimestamp()) <= 5
                && ($request->header('X-Manara-Signature')[0] ?? '') === RendererCachePurge::signature(self::SECRET, $timestamp, $request->body());
        });
    }

    #[Test]
    public function saving_a_page_purges_that_organisation_once_with_a_signed_request(): void
    {
        $this->fakeRenderer();
        $masjid = $this->org();
        $this->actAsAdmin($masjid);

        $this->postJson("/api/admin/masjids/{$masjid->id}/pages", ['slug' => 'ramadan', 'title' => 'Ramadan'])->assertSuccessful();

        $this->assertPurgedOnce($masjid);
    }

    #[Test]
    public function updating_reordering_and_deleting_pages_each_purge(): void
    {
        $masjid = $this->org();
        $this->actAsAdmin($masjid);
        Http::fake([self::ENDPOINT => Http::response(['ok' => true, 'deleted' => 1, 'remaining' => false])]);

        $id = $this->postJson("/api/admin/masjids/{$masjid->id}/pages", ['slug' => 'about', 'title' => 'About'])->assertSuccessful()->json('data.id');
        $this->putJson("/api/admin/masjids/{$masjid->id}/pages/{$id}", ['slug' => 'about', 'title' => 'About us', 'show_in_menu' => false])->assertSuccessful();
        $this->postJson("/api/admin/masjids/{$masjid->id}/pages/reorder", ['pages' => [['id' => $id, 'order' => 3]]])->assertSuccessful();
        $this->deleteJson("/api/admin/masjids/{$masjid->id}/pages/{$id}")->assertSuccessful();

        Http::assertSentCount(4);
    }

    #[Test]
    public function saving_the_theme_purges_and_previewing_it_does_not(): void
    {
        $this->fakeRenderer();
        $masjid = $this->org();
        $this->actAsAdmin($masjid);

        $this->postJson("/api/admin/masjids/{$masjid->id}/theme/preview", ['primary_color' => '#123456'])->assertOk();
        Http::assertNothingSent();

        $this->postJson("/api/admin/masjids/{$masjid->id}/theme", ['primary_color' => '#123456'])->assertOk();
        $this->assertPurgedOnce($masjid);
    }

    #[Test]
    public function a_superadmin_save_purges_the_organisation_in_the_route(): void
    {
        $this->fakeRenderer();
        $masjid = $this->org();
        Sanctum::actingAs(User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+15550000001'])->fresh());

        $this->postJson("/api/admin/masjids/{$masjid->id}/theme", ['primary_color' => '#123456'])->assertOk();

        $this->assertPurgedOnce($masjid);
    }

    #[Test]
    public function reads_and_failed_writes_purge_nothing(): void
    {
        Http::fake();
        $masjid = $this->org();
        $this->actAsAdmin($masjid);

        $this->getJson("/api/admin/masjids/{$masjid->id}/pages")->assertOk();
        $this->getJson("/api/admin/masjids/{$masjid->id}/theme")->assertOk();
        $this->postJson("/api/admin/masjids/{$masjid->id}/pages", ['title' => 'No slug'])->assertStatus(422);
        $this->postJson("/api/admin/masjids/{$masjid->id}/theme", ['primary_color' => 'red'])->assertStatus(422);

        Http::assertNothingSent();
    }

    #[Test]
    public function unconfigured_a_save_sends_nothing_and_still_succeeds(): void
    {
        Http::fake();
        $masjid = $this->org();
        $this->actAsAdmin($masjid);

        foreach ([['secret' => ''], ['secret' => str_repeat('s', 31)], ['purge_origins' => ''], ['purge_origins' => 'not-an-origin']] as $partial) {
            config(['services.renderer' => array_merge(config('services.renderer'), $partial)]);
            $this->postJson("/api/admin/masjids/{$masjid->id}/theme", ['primary_color' => '#123456'])->assertOk();
            config(['services.renderer.secret' => self::SECRET, 'services.renderer.purge_origins' => self::RENDERER]);
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function a_renderer_that_refuses_or_is_down_is_logged_at_warning_and_the_save_stands(): void
    {
        $masjid = $this->org();
        $this->actAsAdmin($masjid);
        Log::spy();

        Http::fake([self::ENDPOINT => Http::response(['ok' => false], 401)]);
        $this->postJson("/api/admin/masjids/{$masjid->id}/theme", ['primary_color' => '#111111'])->assertOk()->assertJsonPath('status', 'success');

        // A Guzzle connect failure, which the HTTP client raises as ConnectionException.
        Http::fake([self::ENDPOINT => fn () => throw new \GuzzleHttp\Exception\ConnectException('timed out', new \GuzzleHttp\Psr7\Request('POST', self::ENDPOINT))]);
        $this->postJson("/api/admin/masjids/{$masjid->id}/theme", ['primary_color' => '#222222'])->assertOk()->assertJsonPath('status', 'success');

        Log::shouldHaveReceived('warning')->with('Renderer cache purge refused', \Mockery::on(fn ($c) => $c['masjid_id'] === $masjid->id && $c['status'] === 401))->once();
        Log::shouldHaveReceived('warning')->with('Renderer cache purge failed', \Mockery::on(fn ($c) => $c['exception'] === ConnectionException::class))->once();
        $this->assertSame('#222222', $masjid->themeSettings()->first()->primary_color);
    }

    #[Test]
    public function remaining_entries_are_followed_up_to_the_cap(): void
    {
        $this->fakeRenderer(
            ['ok' => true, 'deleted' => 800, 'remaining' => true],
            ['ok' => true, 'deleted' => 800, 'remaining' => true],
            ['ok' => true, 'deleted' => 12, 'remaining' => false],
        );
        $this->assertSame(
            [self::RENDERER => ['ok' => true, 'deleted' => 1612, 'calls' => 3]],
            app(RendererCachePurge::class)->purge(13),
        );

        Log::spy();
        Http::fake([self::ENDPOINT => Http::response(['ok' => true, 'deleted' => 800, 'remaining' => true])]);
        $result = app(RendererCachePurge::class)->purge(13);
        $this->assertSame(RendererCachePurge::MAX_CALLS, $result[self::RENDERER]['calls']);
        Log::shouldHaveReceived('warning')->with('Renderer cache purge stopped with entries remaining', \Mockery::any())->once();
    }

    #[Test]
    public function the_writes_that_purge_are_exactly_the_site_editors_writes(): void
    {
        $purging = [];
        foreach (Route::getRoutes() as $route) {
            if (in_array('renderer.purge', $route->gatherMiddleware(), true)) {
                foreach (array_diff($route->methods(), ['GET', 'HEAD']) as $method) {
                    $purging[] = $method.' '.$route->uri();
                }
            }
        }
        sort($purging);

        $prefix = 'api/admin/masjids/{masjid_id}';
        $expected = [
            "DELETE {$prefix}/pages/{page_id}",
            "DELETE {$prefix}/pages/{page_id}/sections/{section_id}",
            "DELETE {$prefix}/sections/{section_id}",
            "POST {$prefix}/general-settings",
            "POST {$prefix}/pages",
            "POST {$prefix}/pages/reorder",
            "POST {$prefix}/pages/{page_id}/sections",
            "POST {$prefix}/pages/{page_id}/sections/attach",
            "POST {$prefix}/sections",
            "POST {$prefix}/theme",
            "PUT {$prefix}/pages/{page_id}",
            "PUT {$prefix}/pages/{page_id}/sections/{section_id}",
            "PUT {$prefix}/sections/{section_id}",
        ];
        sort($expected);

        $this->assertSame($expected, $purging);
    }
}
