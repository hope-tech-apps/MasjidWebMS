<?php

namespace Tests\Feature;

use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\ThemeSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Live preview sessions (docs/live-preview.md §4.3): who gets a token, for which
 * organisation, and what the token and URL say.
 *
 *  - Each surface is gated exactly as its SAVE: pages by web_pages + website, theme by
 *    admin + tenant, splash by the splash module. SuperAdmins pass capability gates.
 *  - The organisation is the bound tenant; nothing in the body can name another.
 *  - The URL comes from configuration, never this request's Host.
 *  - Unconfigured, or asked from an origin the renderer would not let frame it, the
 *    answer is enabled:false, never a token.
 */
class LivePreviewSessionTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'session-secret-0123456789abcdef-0123456789';
    private const PREVIEW = 'https://manara-renderer.pages.dev';
    private const ADMIN = 'https://masjid.hopetechapps.com';

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
            'preview_origin' => self::PREVIEW,
            'purge_origins' => '',
            'admin_origins' => self::ADMIN.',https://manara.hopetechapps.com',
            'timeout' => 5,
        ]]);
    }

    private function org(string $orgType = 'masjid'): Masjid
    {
        return Masjid::create([
            'name' => 'Preview Org '.uniqid(),
            'email' => 'preview'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'org_type' => $orgType,
        ]);
    }

    private function admin(Masjid $masjid): User
    {
        $user = User::factory()->create(['type' => 'MasjidAdmin', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
        MasjidUser::create(['masjid_id' => $masjid->id, 'user_id' => $user->id, 'role' => 'masjid-admin', 'is_default' => true]);

        return $user->fresh();
    }

    private function superAdmin(): User
    {
        return User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+15550000000'])->fresh();
    }

    private function set(Masjid $masjid, string $key, bool $on): void
    {
        $overrides = $masjid->capability_overrides ?? [];
        $overrides[$key] = $on;
        $masjid->forceFill(['capability_overrides' => $overrides])->save();
    }

    private function url(Masjid $masjid, string $surface): string
    {
        return match ($surface) {
            'pages' => "/api/admin/masjids/{$masjid->id}/pages/preview-session",
            'theme' => "/api/admin/masjids/{$masjid->id}/theme/preview-session",
            'splash' => "/api/admin/masjids/{$masjid->id}/splash-announcements/preview-session",
        };
    }

    private function ask(User $user, Masjid $masjid, string $surface, array $body = [], ?string $origin = self::ADMIN)
    {
        Sanctum::actingAs($user);
        // withHeaders() persists for every later request in the same test; start clean.
        $this->flushHeaders();
        $headers = $origin === null ? [] : ['Origin' => $origin];

        return $this->withHeaders($headers)->postJson($this->url($masjid, $surface), $body);
    }

    /** @return array{o:int,s:string,p:string,a:string,e:int} */
    private function claims(string $url): array
    {
        $this->assertStringStartsWith(self::PREVIEW.'/__manara/preview/', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        [$version, $payload, $signature] = explode('.', $query['mp']);
        $this->assertSame('v1', $version);
        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', 'manara-preview|v1|'.$payload, self::SECRET, true)), '+/', '-_'), '=');
        $this->assertSame($expected, $signature, 'signed with the configured secret');

        return json_decode(base64_decode(strtr($payload, '-_', '+/')), true, flags: JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function a_page_editor_gets_a_signed_five_minute_session_for_their_own_organisation(): void
    {
        $masjid = $this->org();
        $this->set($masjid, 'web_pages', true);

        $response = $this->ask($this->admin($masjid), $masjid, 'pages', ['path' => '/about'])->assertOk();

        $response->assertJsonPath('status', 'success')
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.origin', self::PREVIEW)
            ->assertJsonPath('data.surface', 'pages')
            ->assertJsonPath('data.path', '/about');
        $this->assertStringStartsWith(self::PREVIEW.'/__manara/preview/about?mp=v1.', $response->json('data.url'));

        $claims = $this->claims($response->json('data.url'));
        $this->assertSame($masjid->id, $claims['o']);
        $this->assertSame('pages', $claims['s']);
        $this->assertSame('/about', $claims['p']);
        $this->assertSame(self::ADMIN, $claims['a']);
        $this->assertEqualsWithDelta(now()->getTimestamp() + 300, $claims['e'], 5);
    }

    #[Test]
    public function the_home_page_is_the_default_path(): void
    {
        $masjid = $this->org();
        $this->set($masjid, 'web_pages', true);

        $url = $this->ask($this->admin($masjid), $masjid, 'pages')->assertOk()->json('data.url');

        $this->assertStringStartsWith(self::PREVIEW.'/__manara/preview/?mp=', $url);
        $this->assertSame('/', $this->claims($url)['p']);
    }

    #[Test]
    public function the_token_names_the_bound_organisation_whatever_the_body_says(): void
    {
        $mine = $this->org();
        $theirs = $this->org();
        $this->set($mine, 'web_pages', true);

        $url = $this->ask($this->admin($mine), $mine, 'pages', ['masjid_id' => $theirs->id, 'o' => $theirs->id, 'org' => $theirs->id])
            ->assertOk()->json('data.url');

        $this->assertSame($mine->id, $this->claims($url)['o']);
    }

    #[Test]
    public function another_organisations_admin_is_refused_on_every_surface(): void
    {
        $mine = $this->org();
        $theirs = $this->org();
        $this->set($theirs, 'web_pages', true);
        $outsider = $this->admin($mine);

        foreach (['pages', 'theme', 'splash'] as $surface) {
            $this->ask($outsider, $theirs, $surface)->assertForbidden();
        }
    }

    #[Test]
    public function pages_follow_the_page_editors_gate_and_theme_follows_the_theme_save(): void
    {
        // web_pages is off by default: this admin may save the theme but not pages
        // (Burlington's arrangement), so they may preview the theme and not pages.
        $masjid = $this->org();
        $admin = $this->admin($masjid);

        $this->ask($admin, $masjid, 'pages')->assertForbidden();
        $this->ask($admin, $masjid, 'theme')->assertOk()->assertJsonPath('data.enabled', true);

        $this->set($masjid, 'web_pages', true);
        $this->ask($admin, $masjid, 'pages')->assertOk()->assertJsonPath('data.enabled', true);

        // The website module off refuses page editors, as it refuses their saves.
        $this->set($masjid, 'website', false);
        $this->ask($admin, $masjid, 'pages')->assertForbidden();
    }

    #[Test]
    public function a_superadmin_may_preview_any_organisation(): void
    {
        $masjid = $this->org();
        $this->set($masjid, 'website', false);

        $url = $this->ask($this->superAdmin(), $masjid, 'pages')->assertOk()->json('data.url');

        $this->assertSame($masjid->id, $this->claims($url)['o']);
    }

    #[Test]
    public function splash_previews_follow_the_splash_module(): void
    {
        $masjid = $this->org();
        $admin = $this->admin($masjid);

        $url = $this->ask($admin, $masjid, 'splash')->assertOk()->json('data.url');
        $this->assertSame('splash', $this->claims($url)['s']);

        $this->set($masjid, 'splash', false);
        $this->ask($admin, $masjid, 'splash')->assertForbidden();
    }

    #[Test]
    public function unconfigured_it_answers_enabled_false_and_issues_nothing(): void
    {
        $masjid = $this->org();
        $admin = $this->admin($masjid);

        foreach ([
            ['secret' => '', 'preview_origin' => self::PREVIEW],
            ['secret' => str_repeat('x', 31), 'preview_origin' => self::PREVIEW],
            ['secret' => self::SECRET, 'preview_origin' => ''],
            ['secret' => self::SECRET, 'preview_origin' => 'manara-renderer.pages.dev'],
        ] as $partial) {
            config(['services.renderer.secret' => $partial['secret'], 'services.renderer.preview_origin' => $partial['preview_origin']]);

            $this->ask($admin, $masjid, 'theme')->assertOk()
                ->assertExactJson(['status' => 'success', 'data' => ['enabled' => false, 'reason' => 'not_configured']]);
        }
    }

    #[Test]
    public function an_admin_origin_outside_the_allowlist_gets_no_token(): void
    {
        $masjid = $this->org();
        $admin = $this->admin($masjid);

        foreach (['https://evil.example', 'https://masjid.hopetechapps.com.evil.example', 'null', null] as $origin) {
            $this->ask($admin, $masjid, 'theme', [], $origin)->assertOk()
                ->assertExactJson(['status' => 'success', 'data' => ['enabled' => false, 'reason' => 'origin_not_allowed']]);
        }

        // The second listed admin origin works, and a Referer stands in for a missing Origin.
        $url = $this->ask($admin, $masjid, 'theme', [], 'https://manara.hopetechapps.com')->assertOk()->json('data.url');
        $this->assertSame('https://manara.hopetechapps.com', $this->claims($url)['a']);

        Sanctum::actingAs($admin);
        $this->flushHeaders();
        $url = $this->withHeaders(['Referer' => self::ADMIN.'/masjid/theme'])
            ->postJson($this->url($masjid, 'theme'))->assertOk()->json('data.url');
        $this->assertSame(self::ADMIN, $this->claims($url)['a']);
    }

    #[Test]
    public function with_no_admin_origins_configured_only_this_deployments_own_origin_may_frame(): void
    {
        config(['services.renderer.admin_origins' => '', 'app.url' => self::ADMIN]);
        $masjid = $this->org();
        $admin = $this->admin($masjid);

        $this->ask($admin, $masjid, 'theme')->assertOk()->assertJsonPath('data.enabled', true);
        $this->ask($admin, $masjid, 'theme', [], 'https://manara.hopetechapps.com')->assertOk()->assertJsonPath('data.enabled', false);
    }

    #[Test]
    public function the_url_is_built_from_configuration_never_from_the_request_host(): void
    {
        $masjid = $this->org();
        Sanctum::actingAs($this->admin($masjid));

        // The host goes in the URI: withHeader('Host') is overwritten by the test
        // harness (.claude/rules/generated-urls.md).
        $url = $this->withHeaders(['Origin' => self::ADMIN])
            ->postJson("https://evil.example/api/admin/masjids/{$masjid->id}/theme/preview-session")
            ->assertOk()->json('data.url');

        $this->assertStringStartsWith(self::PREVIEW.'/__manara/preview/', $url);
        $this->assertStringNotContainsString('evil.example', $url);
    }

    #[Test]
    public function an_unsafe_path_is_a_422_in_the_legacy_envelope(): void
    {
        $masjid = $this->org();
        $admin = $this->admin($masjid);

        foreach (['//evil.example/x', '/a/../b', 'about', '/about?x=1', '/a\\b', ['/about'], '/50%off', "/a\u{00a0}b"] as $path) {
            $this->ask($admin, $masjid, 'theme', ['path' => $path])->assertStatus(422)
                ->assertJsonPath('status', 'failed')
                ->assertJsonStructure(['data' => ['path']]);
        }
    }

    #[Test]
    public function an_arabic_slug_is_signed_decoded_and_sent_encoded(): void
    {
        $masjid = $this->org();
        $this->set($masjid, 'web_pages', true);

        $url = $this->ask($this->admin($masjid), $masjid, 'pages', ['path' => '/حول'])->assertOk()->json('data.url');

        $this->assertStringStartsWith(self::PREVIEW.'/__manara/preview/%D8%AD%D9%88%D9%84?mp=v1.', $url);
        $this->assertSame('/حول', $this->claims($url)['p']);
    }

    #[Test]
    public function a_form_encoded_request_works_as_the_spa_may_send_it(): void
    {
        $masjid = $this->org();
        Sanctum::actingAs($this->admin($masjid));

        $url = $this->withHeaders(['Origin' => self::ADMIN, 'Accept' => 'application/json'])
            ->post($this->url($masjid, 'theme'), ['path' => '/contact'])
            ->assertOk()->json('data.url');

        $this->assertSame('/contact', $this->claims($url)['p']);
    }

    #[Test]
    public function opening_a_preview_never_purges_the_renderer(): void
    {
        config(['services.renderer.purge_origins' => self::PREVIEW]);
        Http::fake();
        $masjid = $this->org();
        $this->set($masjid, 'web_pages', true);
        $admin = $this->admin($masjid);

        $this->ask($admin, $masjid, 'pages')->assertOk();
        $this->ask($admin, $masjid, 'theme')->assertOk();
        $this->ask($admin, $masjid, 'splash')->assertOk();
        Sanctum::actingAs($admin);
        $this->withHeaders(['Origin' => self::ADMIN])->postJson("/api/admin/masjids/{$masjid->id}/theme/preview", ['primary_color' => '#123456'])->assertOk();

        Http::assertNothingSent();
    }

    #[Test]
    public function the_theme_preview_is_the_saved_theme_the_site_would_serve_and_saves_nothing(): void
    {
        $masjid = $this->org();
        $masjid->themeSettings()->create(['primary_color' => '#01B151', 'secondary_color' => '#0B7A3B', 'tokens' => ['layout' => ['header' => 'overlay']]]);
        Sanctum::actingAs($this->admin($masjid));

        $theme = $this->postJson("/api/admin/masjids/{$masjid->id}/theme/preview", ['primary_color' => '#C0392B'])
            ->assertOk()->json('data.theme');

        $this->assertSame('#C0392B', $theme['primary']);
        $this->assertSame('#0B7A3B', $theme['secondary'], 'unsent colours keep their saved values');
        $this->assertSame('#C0392B', $theme['tokens']['color']['primary'], 'derived by the server, as the renderer prefers');
        $this->assertSame('overlay', $theme['tokens']['layout']['header'], 'saved token overrides survive');

        $this->assertSame(1, ThemeSetting::count());
        $this->assertSame('#01B151', $masjid->themeSettings()->first()->primary_color, 'nothing was saved');
    }

    #[Test]
    public function the_theme_preview_validates_like_the_save(): void
    {
        $masjid = $this->org();
        Sanctum::actingAs($this->admin($masjid));

        $this->postJson("/api/admin/masjids/{$masjid->id}/theme/preview", ['primary_color' => 'red'])
            ->assertStatus(422)->assertJsonPath('status', 'failed');
        $this->assertSame(0, ThemeSetting::count());
    }
}
