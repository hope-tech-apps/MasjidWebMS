<?php

namespace Tests\Feature\Studio;

use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\StudioDraft;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Manara Studio provisions organisations, so every one of its endpoints is the
 * platform operator's alone. The routes are read FROM THE ROUTER, so an endpoint
 * a later slice adds under api/admin/studio is checked for the refusals with no
 * edit here; its SuperAdmin call must be added to calls(), or the test says so.
 */
class StudioAccessTest extends TestCase
{
    use RefreshDatabase;

    private const PREFIX = 'api/admin/studio';

    private ?array $fixtures = null;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        Storage::fake((string) config('studio.logo.disk'));
    }

    /**
     * A SuperAdmin's working call for each Studio route, keyed "METHOD uri" as
     * the router names it: [method, url, body].
     *
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
     */
    private function calls(): array
    {
        $drafts = '/' . self::PREFIX . '/drafts';
        $f = $this->fixtures();

        return [
            'GET ' . self::PREFIX . '/catalogue' => ['GET', '/' . self::PREFIX . '/catalogue?org_type=school', []],
            'GET ' . self::PREFIX . '/drafts' => ['GET', "{$drafts}?status=all", []],
            'POST ' . self::PREFIX . '/drafts' => ['POST', $drafts, ['org_type' => 'masjid', 'name' => 'Access Check']],
            'GET ' . self::PREFIX . '/drafts/{draft_id}' => ['GET', "{$drafts}/{$f['working']}", []],
            'PATCH ' . self::PREFIX . '/drafts/{draft_id}' => ['PATCH', "{$drafts}/{$f['working']}", ['lock_version' => 0, 'answers' => ['content' => ['about' => 'x']]]],
            'DELETE ' . self::PREFIX . '/drafts/{draft_id}' => ['DELETE', "{$drafts}/{$f['discard']}", []],
            'POST ' . self::PREFIX . '/drafts/{draft_id}/logo' => ['POST', "{$drafts}/{$f['working']}/logo", ['logo' => $f['upload']]],
            'GET ' . self::PREFIX . '/drafts/{draft_id}/logo' => ['GET', "{$drafts}/{$f['with_logo']}/logo", []],
            'DELETE ' . self::PREFIX . '/drafts/{draft_id}/logo' => ['DELETE', "{$drafts}/{$f['with_logo_to_remove']}/logo", []],
            'POST ' . self::PREFIX . '/domains/check' => ['POST', '/' . self::PREFIX . '/domains/check', ['kind' => 'managed_subdomain', 'label' => 'studio-access']],
            'GET ' . self::PREFIX . '/layout-presets' => ['GET', '/' . self::PREFIX . '/layout-presets?org_type=community', []],
            'POST ' . self::PREFIX . '/drafts/{draft_id}/preview' => ['POST', "{$drafts}/{$f['working']}/preview", ['answers' => ['layout' => ['preset' => 'masjid.essentials']]]],
        ];
    }

    /**
     * Drafts for the id-bearing calls, one per route that changes or needs
     * state, so the order the routes are walked in cannot matter (DELETE sorts
     * before GET). Made once per test.
     *
     * @return array{working: int, discard: int, with_logo: int, with_logo_to_remove: int, upload: UploadedFile}
     */
    private function fixtures(): array
    {
        if ($this->fixtures !== null) {
            return $this->fixtures;
        }

        $disk = Storage::disk((string) config('studio.logo.disk'));
        $withLogo = function () use ($disk): int {
            $draft = StudioDraft::create(['status' => StudioDraft::STATUS_DRAFT]);
            $path = "studio-drafts/{$draft->id}/" . str_repeat('a', 40) . '.png';
            $disk->put($path, $this->png());
            $draft->update(['logo_disk' => (string) config('studio.logo.disk'), 'logo_path' => $path, 'logo_mime_type' => 'image/png']);

            return $draft->id;
        };

        $upload = tempnam(sys_get_temp_dir(), 'studio-access-');
        file_put_contents($upload, $this->png());

        return $this->fixtures = [
            'working' => StudioDraft::create(['status' => StudioDraft::STATUS_DRAFT])->id,
            'discard' => StudioDraft::create(['status' => StudioDraft::STATUS_DRAFT])->id,
            'with_logo' => $withLogo(),
            'with_logo_to_remove' => $withLogo(),
            'upload' => new UploadedFile($upload, 'logo.png', null, null, true),
        ];
    }

    private function png(): string
    {
        $image = imagecreatetruecolor(120, 120);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /** @return list<string> "METHOD uri" for every route under the Studio prefix */
    private function studioRoutes(): array
    {
        $out = [];

        foreach (Route::getRoutes() as $route) {
            if ($route->uri() !== self::PREFIX && ! str_starts_with($route->uri(), self::PREFIX . '/')) {
                continue;
            }

            foreach ($route->methods() as $method) {
                if ($method !== 'HEAD') {
                    $out[] = "{$method} {$route->uri()}";
                }
            }
        }

        sort($out);

        return $out;
    }

    /** The call for a route, or one with every parameter filled by a placeholder. */
    private function callFor(string $route): array
    {
        [$method, $uri] = explode(' ', $route, 2);

        return $this->calls()[$route] ?? [$method, '/' . preg_replace('/\{[^}]+\}/', '1', $uri), []];
    }

    private function org(): Masjid
    {
        return Masjid::create([
            'name' => 'Studio Access Org ' . uniqid(),
            'email' => 'studio' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'crm_enabled' => true, 'org_type' => 'school',
        ]);
    }

    private function member(Masjid $org, string $type, string $role): User
    {
        $user = User::factory()->create(['type' => $type, 'phone' => '+1' . random_int(1000000000, 9999999999)]);
        MasjidUser::create(['masjid_id' => $org->id, 'user_id' => $user->id, 'role' => $role, 'is_default' => true]);

        return $user->fresh();
    }

    private function actAs(?User $user): void
    {
        $this->app['auth']->forgetGuards();

        if ($user !== null) {
            Sanctum::actingAs($user);
        }
    }

    #[Test]
    public function every_studio_route_refuses_a_masjid_admin_a_teacher_and_a_guest_with_401(): void
    {
        $routes = $this->studioRoutes();
        $this->assertContains('GET ' . self::PREFIX . '/catalogue', $routes, 'the router has no Studio routes to check');

        $org = $this->org();
        $actors = [
            'a guest' => null,
            'a MasjidAdmin' => $this->member($org, 'MasjidAdmin', 'masjid-admin'),
            'a Teacher' => $this->member($org, 'Teacher', 'teacher'),
        ];

        foreach ($routes as $route) {
            [$method, $url, $body] = $this->callFor($route);

            foreach ($actors as $who => $user) {
                $this->actAs($user);
                $response = $this->json($method, $url, $body);

                $this->assertSame(401, $response->getStatusCode(), "{$route} answered {$who} with {$response->getStatusCode()}");

                // A signed-in non-super gets the admin gates' envelope; a guest
                // is refused by the token guard before either runs.
                if ($user !== null) {
                    $response->assertExactJson(['status' => 'failed', 'data' => 'Unauthorized.']);
                }
            }
        }
    }

    #[Test]
    public function every_studio_route_answers_a_super_admin(): void
    {
        $routes = $this->studioRoutes();
        $this->assertContains('GET ' . self::PREFIX . '/catalogue', $routes, 'the router has no Studio routes to check');

        $this->actAs(User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+15550000000'])->fresh());

        foreach ($routes as $route) {
            $this->assertArrayHasKey($route, $this->calls(), "{$route} has no SuperAdmin call in StudioAccessTest::calls()");

            [$method, $url, $body] = $this->callFor($route);
            $response = $this->json($method, $url, $body);

            $this->assertTrue($response->isSuccessful(), "{$route} answered a SuperAdmin with {$response->getStatusCode()}");
        }
    }
}
