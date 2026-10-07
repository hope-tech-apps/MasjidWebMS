<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use App\Support\Guides\GuideReleases;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GuidesTest extends TestCase
{
    use RefreshDatabase;

    private string $source;
    private Masjid $org;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]]);
        Storage::fake('local');
        $this->source = sys_get_temp_dir().'/guide-fixture-'.bin2hex(random_bytes(6));
        File::copyDirectory(base_path('tests/fixtures/guides/d1-1234abcd'), $this->source);
        $this->org = Masjid::create([
            'name' => 'Pebble organisation', 'email' => 'pebble@example.invalid', 'phone' => '1000000000',
            'country_id' => '1', 'city_id' => '1', 'address' => 'Pebble', 'latitude' => 0, 'longitude' => 0,
            'crm_enabled' => true,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->source);
        parent::tearDown();
    }

    private function install(): void
    {
        $this->artisan('guides:install', ['folder' => $this->source])->assertExitCode(0);
    }

    private function token(string $kind): ?string
    {
        if ($kind === 'guest') return null;
        if ($kind === 'family') {
            return Contact::factory()->create(['masjid_id' => $this->org->id])->createToken('fixture', ['family'])->plainTextToken;
        }
        $user = User::factory()->create(['type' => $kind, 'name' => 'Pebble Reader', 'phone' => '1000000001']);
        if ($kind !== 'SuperAdmin') {
            MasjidUser::create(['masjid_id' => $this->org->id, 'user_id' => $user->id,
                'role' => match ($kind) { 'Teacher' => 'teacher', 'LunchStaff' => 'lunch-staff', default => 'owner' }, 'is_default' => true]);
            if ($kind === 'MasjidAdmin') $this->org->update(['user_id' => $user->id]);
        }
        return $user->createToken('fixture', ['staff'])->plainTextToken;
    }

    private function url(string $realm, string $suffix = ''): string
    {
        return "/api/{$realm}/masjids/{$this->org->id}/guides{$suffix}";
    }

    public static function matrix(): array
    {
        $cases = [];
        foreach (['MasjidAdmin', 'SuperAdmin', 'Teacher', 'LunchStaff', 'family', 'guest', 'User'] as $kind) {
            foreach (['admin', 'teacher', 'lunch'] as $realm) {
                foreach (['admin', 'school', 'teacher', 'lunch'] as $book) {
                    $roleAllowed = match ($realm) { 'admin' => in_array($kind, ['MasjidAdmin', 'SuperAdmin']), 'teacher' => $kind === 'Teacher', 'lunch' => $kind === 'LunchStaff' };
                    $bookAllowed = match ($realm) { 'admin' => in_array($book, ['admin', 'school']), default => $realm === $book };
                    $cases["{$kind}-{$realm}-{$book}"] = [$kind, $realm, $book, $roleAllowed && $bookAllowed];
                }
            }
        }
        return $cases;
    }

    #[Test, DataProvider('matrix')]
    public function account_book_and_picture_matrix(string $kind, string $realm, string $book, bool $allowed): void
    {
        $this->install();
        $token = $this->token($kind);
        if ($token) $this->withToken($token);
        $page = $this->getJson($this->url($realm, "/{$book}"));
        $picture = $this->getJson($this->url($realm, "/{$book}/d1-1234abcd/pictures/shots/pebble/pixel.jpg"));
        if ($allowed) {
            $offered = $this->getJson($this->url($realm))->assertOk()->json('data');
            $this->assertSame($realm === 'admin' ? ['admin', 'school'] : [$realm], array_column($offered, 'book'));
            foreach ($offered as $entry) { $this->assertSame('d1-1234abcd', $entry['version']); $this->assertNotEmpty($entry['title']); }
            $page->assertOk()->assertJsonPath('data.version', 'd1-1234abcd')->assertJsonCount(2, 'data.tasks')->assertJsonPath('data.title', ucfirst($book).' guide');
            $this->assertStringContainsString('data-book="'.$book.'"', $page->json('data.html'));
            $this->assertStringContainsString('[data-book="'.$book.'"]', $page->json('data.css'));
            $expected = file_get_contents($this->source.'/'.$book.'/shots/pebble/pixel.jpg');
            $this->assertSame($expected, $picture->getContent());
            $picture->assertHeader('ETag', '"'.hash('sha256', $expected).'"');
            $picture->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertHeader('X-Content-Type-Options', 'nosniff');
            $this->assertStringContainsString('private', $picture->headers->get('Cache-Control'));
            $this->assertStringContainsString('no-cache', $picture->headers->get('Cache-Control'));
        } else {
            $this->assertContains($page->status(), [401, 403, 404]);
            $this->assertSame($page->status(), $picture->status());
            $missing = $this->getJson($this->url($realm, '/absent/d999-aaaaaaaa/pictures/shots/none/none.jpg'));
            $this->assertSame($page->status(), $missing->status());
            $this->assertSame($page->json(), $missing->json());
        }
    }

    #[Test]
    public function school_is_refused_for_both_admin_types_without_crm(): void
    {
        $this->install();
        foreach (['MasjidAdmin', 'SuperAdmin'] as $kind) {
            app('auth')->forgetGuards();
            app(TenantContext::class)->forgetTenant();
            $this->withToken($this->token($kind));
            $this->org->update(['crm_enabled' => false]);
            $this->getJson($this->url('admin'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.book', 'admin');
            $this->getJson($this->url('admin', '/school'))->assertNotFound();
            $this->getJson($this->url('admin', '/school/d1-1234abcd/pictures/shots/pebble/pixel.jpg'))->assertNotFound();
            $this->getJson($this->url('admin', '/admin'))->assertOk();
        }
    }

    #[Test]
    public function no_release_is_a_normal_empty_state(): void
    {
        $this->withToken($this->token('MasjidAdmin'));
        $this->getJson($this->url('admin'))->assertOk()->assertExactJson(['status' => 'success', 'data' => []]);
        $this->getJson($this->url('admin', '/admin'))->assertNotFound();
        $this->artisan('guides:status')->expectsOutputToContain('No guide release installed')->assertExitCode(0);
    }

    #[Test]
    public function root_relative_manifest_pages_return_the_exact_installed_bytes(): void
    {
        $manifest = json_decode(file_get_contents($this->source.'/manifest.json'), true);
        $this->assertSame('admin/page.html', $manifest['books']['admin']['page']);
        $this->assertSame('Pebble chapter', $manifest['books']['admin']['tasks'][0]['section']);
        $this->install();
        $this->withToken($this->token('MasjidAdmin'));
        foreach (['admin', 'school'] as $book) {
            $response = $this->getJson($this->url('admin', '/'.$book))->assertOk();
            $meta = $manifest['books'][$book];
            $this->assertSame(Storage::disk('local')->get('guides/d1-1234abcd/'.$meta['page']), $response->json('data.html'));
            $this->assertSame(Storage::disk('local')->get('guides/d1-1234abcd/'.$meta['style']), $response->json('data.css'));
            $this->assertSame($meta['page_bytes'], strlen($response->json('data.html')));
        }
    }

    #[Test]
    public function real_release_when_requested_installs_and_serves_all_four_books_privately(): void
    {
        $source = getenv('GUIDE_REAL_RELEASE');
        if (! $source) $this->markTestSkipped('GUIDE_REAL_RELEASE is unset; CI uses only synthetic fixtures.');
        $root = sys_get_temp_dir().'/guide-real-disk-'.bin2hex(random_bytes(6));
        config(['filesystems.disks.local' => ['driver' => 'local', 'root' => $root, 'serve' => false]]);
        Storage::forgetDisk('local');
        try {
            $manifest = json_decode(file_get_contents($source.'/manifest.json'), true);
            $this->artisan('guides:install', ['folder' => $source])->assertExitCode(0);
            foreach (['admin' => ['MasjidAdmin', 'admin'], 'school' => ['MasjidAdmin', 'admin'], 'teacher' => ['Teacher', 'teacher'], 'lunch' => ['LunchStaff', 'lunch']] as $book => [$kind, $realm]) {
                app('auth')->forgetGuards(); app(TenantContext::class)->forgetTenant();
                $this->withToken($this->token($kind));
                $page = $this->getJson($this->url($realm, '/'.$book))->assertOk();
                $meta = $manifest['books'][$book];
                // Boolean comparisons avoid printing private release content on a failure.
                $this->assertTrue(Storage::disk('local')->get('guides/'.$manifest['version'].'/'.$meta['page']) === $page->json('data.html'), 'Exact installed page bytes must reach the reader.');
                $this->assertTrue(file_get_contents($source.'/'.$meta['style']) === $page->json('data.css'), 'Exact style bytes must reach the reader.');
                $path = array_key_first($meta['files']);
                $picture = $this->getJson($this->url($realm, '/'.$book.'/'.$manifest['version'].'/pictures/'.$path))->assertOk();
                $this->assertTrue(file_get_contents($source.'/'.$book.'/'.$path) === $picture->getContent(), 'Exact installed picture bytes must reach the reader.');
                $picture->assertHeader('X-Content-Type-Options', 'nosniff');
            }
            app('auth')->forgetGuards(); app(TenantContext::class)->forgetTenant();
            $this->withToken($this->token('Teacher'));
            $path = array_key_first($manifest['books']['admin']['files']);
            $this->getJson($this->url('teacher', '/admin'))->assertNotFound();
            $this->getJson($this->url('teacher', '/admin/'.$manifest['version'].'/pictures/'.$path))->assertNotFound();
            $this->getJson($this->url('admin', '/admin'))->assertUnauthorized();
            $this->getJson($this->url('admin', '/admin/'.$manifest['version'].'/pictures/'.$path))->assertUnauthorized();
        } finally {
            Storage::forgetDisk('local');
            File::deleteDirectory($root);
        }
    }

    public static function badPaths(): array
    {
        return array_map(fn ($p) => [$p], ['shots/pebble/missing.jpg', '../page.html', '%2e%2e/page.html', '%252e%252e/page.html', 'school/shots/pebble/pixel.jpg', 'shots/pebble/pixel.jpg%00', '/etc/passwd']);
    }

    #[Test, DataProvider('badPaths')]
    public function only_manifest_picture_paths_are_read(string $path): void
    {
        $this->install();
        $this->withToken($this->token('MasjidAdmin'));
        $this->getJson($this->url('admin', '/admin/d1-1234abcd/pictures/'.$path))->assertNotFound();
        $this->getJson($this->url('admin', '/admin/d2-1234abcd/pictures/shots/pebble/pixel.jpg'))->assertNotFound();
    }

    #[Test]
    public function membership_and_tenant_are_rechecked_before_any_guide_read(): void
    {
        $this->install();
        foreach (['MasjidAdmin' => 'admin', 'Teacher' => 'teacher', 'LunchStaff' => 'lunch'] as $kind => $realm) {
            app('auth')->forgetGuards();
            app(TenantContext::class)->forgetTenant();
            $token = $this->token($kind);
            $this->withToken($token);
            $this->getJson($this->url($realm))->assertOk();
            $this->getJson("/api/{$realm}/masjids/999999/guides")->assertForbidden();
        }
    }

    #[Test]
    public function a_family_web_session_cannot_bypass_the_provider_pin_with_a_staff_type(): void
    {
        $this->install();
        $family = Contact::factory()->create(['masjid_id' => $this->org->id]);
        $family->setAttribute('type', 'SuperAdmin');
        $this->actingAs($family, 'web');
        foreach (['admin', 'teacher', 'lunch'] as $realm) {
            $this->getJson($this->url($realm, '/admin'))->assertUnauthorized();
            $this->getJson($this->url($realm, '/admin/d1-1234abcd/pictures/shots/pebble/pixel.jpg'))->assertUnauthorized();
        }
    }

    #[Test]
    public function picture_revalidation_still_requires_authorization(): void
    {
        $this->install();
        $this->withToken($this->token('MasjidAdmin'));
        $url = $this->url('admin', '/admin/d1-1234abcd/pictures/shots/pebble/pixel.jpg');
        $response = $this->getJson($url)->assertOk();
        $etag = $response->headers->get('ETag');
        $this->withHeader('If-None-Match', $etag)->getJson($url)->assertStatus(304);
        app('auth')->forgetGuards();
        $this->withToken($this->token('Teacher'))->getJson($url)->assertUnauthorized();
    }

    #[Test]
    public function one_whole_page_of_pictures_is_below_the_account_throttle(): void
    {
        $this->install();
        $this->withToken($this->token('MasjidAdmin'));
        for ($i = 0; $i < 350; $i++) {
            $this->getJson($this->url('admin', '/admin/d1-1234abcd/pictures/shots/pebble/pixel.jpg'))->assertOk();
        }
    }

    #[Test]
    public function a_path_only_in_another_books_manifest_is_a_miss_even_when_that_picture_exists(): void
    {
        $manifest = json_decode(file_get_contents($this->source.'/manifest.json'), true);
        rename($this->source.'/teacher/shots/pebble/pixel.jpg', $this->source.'/teacher/shots/pebble/teacher-only.jpg');
        $html = str_replace('shots/pebble/pixel.jpg', 'shots/pebble/teacher-only.jpg', file_get_contents($this->source.'/teacher/page.html'));
        file_put_contents($this->source.'/teacher/page.html', $html);
        $manifest['books']['teacher']['page_sha256'] = hash('sha256', $html);
        $manifest['books']['teacher']['page_bytes'] = strlen($html);
        $manifest['books']['teacher']['files']['shots/pebble/teacher-only.jpg'] = $manifest['books']['teacher']['files']['shots/pebble/pixel.jpg'];
        unset($manifest['books']['teacher']['files']['shots/pebble/pixel.jpg']);
        file_put_contents($this->source.'/manifest.json', json_encode($manifest));
        $this->install();
        $this->withToken($this->token('MasjidAdmin'));
        $this->getJson($this->url('admin', '/admin/d1-1234abcd/pictures/shots/pebble/teacher-only.jpg'))->assertNotFound();
        app('auth')->forgetGuards();
        $this->withToken($this->token('Teacher'));
        $this->getJson($this->url('teacher', '/teacher/d1-1234abcd/pictures/shots/pebble/teacher-only.jpg'))->assertOk();
    }

    private function edit(string $file, string $contents, bool $rehash = true): void
    {
        file_put_contents($this->source.'/'.$file, $contents);
        if (! $rehash) return;
        $m = json_decode(file_get_contents($this->source.'/manifest.json'), true);
        if ($file === 'admin/page.html') { $m['books']['admin']['page_sha256'] = hash('sha256', $contents); $m['books']['admin']['page_bytes'] = strlen($contents); }
        if ($file === 'admin/page.css') { $m['books']['admin']['style_sha256'] = hash('sha256', $contents); $m['books']['admin']['style_bytes'] = strlen($contents); }
        if (isset($m['books']['admin']['files'][substr($file, 6)])) {
            $m['books']['admin']['files'][substr($file, 6)] = ['bytes' => strlen($contents), 'sha256' => hash('sha256', $contents)];
        }
        file_put_contents($this->source.'/manifest.json', json_encode($m));
    }

    public static function invalidContent(): array
    {
        $cases = [];
        foreach (['script', 'style', 'iframe', 'object', 'embed', 'link', 'base', 'form', 'meta', 'svg', 'math'] as $tag) $cases[$tag] = ['html', "<{$tag}>hidden</{$tag}>", 'html'];
        foreach ([
            'event' => '<p onclick="hidden">Hidden</p>', 'event-case' => '<p ONfocus="hidden">Hidden</p>',
            'javascript' => '<a href="java&#x73;cript:alert(1)">Hidden</a>', 'src' => '<img src="shots/pebble/pixel.jpg">',
            'srcset' => '<img srcset="https://example.invalid/pic.jpg">', 'bad-data-src' => '<img data-src="https://example.invalid/pic.jpg" width="1" height="1" alt="hidden">',
            'unlisted-src' => '<img data-src="shots/none/none.jpg" width="1" height="1" alt="hidden">',
            'http' => '<a href="http://example.invalid" rel="noopener noreferrer">Hidden</a>', 'rel' => '<a href="https://example.invalid">Hidden</a>',
            'both-link-targets' => '<a data-guide="school" data-task="sprout" data-faq="faq-pebble">Hidden</a>',
            'words-on-chapter' => '<section data-chapter="bogus" data-words="hidden"><h2>Bogus</h2></section>',
            'words-on-other-details' => '<details data-words="hidden"><summary>Hidden</summary></details>',
            'duplicate-faq-id' => '<details data-faq id="faq-pebble"><summary>Hidden</summary></details>',
            'inline-style' => '<p style="background:url(https://example.invalid)">Hidden</p>',
        ] as $name => $html) $cases[$name] = ['html', $html, 'html'];
        foreach (['@import "https://example.invalid";', '.mg {background:url(a)}', '.mg {width:expression(a)}', '.mg {x:</style>}', 'body { color: red; }', '.mg, body {color:red}', '.mg + body {color:red}', '.mg:not([data-x=")"]) ~ body {color:red}', '.mg.foo+body {color:red}', '.mg {x:u\\72l(a)}', '.mg {background:u/**/rl(a)}', '@font-face {font-family:x}'] as $n => $css) $cases['css'.$n] = ['css', $css, 'css'];
        $cases['css-image-set'] = ['css', '.mg {background:image-set("https://example.invalid/pixel.jpg" 1x)}', 'css'];
        $cases['css-webkit-image-set'] = ['css', '.mg {background:-webkit-image-set("https://example.invalid/pixel.jpg" 1x)}', 'css'];
        $cases['css-image'] = ['css', '.mg {background:image("https://example.invalid/pixel.jpg")}', 'css'];
        $cases['root-event'] = ['root-event', '', 'html-root-attribute'];
        $cases['symlink-directory'] = ['symlink-directory', '', 'symlink'];
        $cases['root'] = ['root', '', 'html-root'];
        $cases['manifest-books'] = ['manifest-books', '', 'manifest-books'];
        $cases['manifest-task-object'] = ['manifest-task-object', '', 'manifest-tasks'];
        $cases['manifest-task'] = ['manifest-task', '', 'html-task-manifest'];
        $cases['version'] = ['version', '', 'manifest-version'];
        $cases['dimensions'] = ['html', '<img data-src="shots/pebble/pixel.jpg" alt="Hidden">', 'html-picture-dimensions'];
        $cases['image-extension'] = ['image-extension', '', 'image-type'];
        $cases['image'] = ['image', 'hidden bytes', 'image'];
        $cases['hash'] = ['hash', 'hidden bytes', 'sha256'];
        $cases['extra'] = ['extra', 'hidden bytes', 'unlisted'];
        $cases['extension'] = ['extension', 'hidden bytes', 'extension'];
        $cases['symlink'] = ['symlink', '', 'symlink'];
        $cases['traversal'] = ['traversal', '', 'path'];
        $cases['missing'] = ['missing', '', 'missing'];
        $cases['bytes'] = ['bytes', '', 'bytes'];
        foreach (['page', 'style'] as $key) {
            $cases[$key.'-bytes'] = [$key.'-bytes', '', 'bytes'];
            $cases[$key.'-bytes-type'] = [$key.'-bytes-type', '', 'manifest-bytes'];
        }
        $cases['task-outside-chapter'] = ['task-outside-chapter', '', 'html-task-manifest'];
        return $cases;
    }

    #[Test, DataProvider('invalidContent')]
    public function invalid_release_is_named_and_current_is_untouched(string $type, string $value, string $rule): void
    {
        $this->install();
        $before = Storage::disk('local')->get('guides/current.json');
        $file = match ($type) { 'css', 'style-bytes', 'style-bytes-type' => 'admin/page.css', 'image', 'bytes' => 'admin/shots/pebble/pixel.jpg', 'extra' => 'admin/extra.html', 'extension' => 'admin/extra.exe', 'symlink', 'symlink-directory' => 'admin/link.jpg', default => 'admin/page.html' };
        if ($type === 'task-outside-chapter') $this->edit($file, str_replace('data-chapter="pebble"', 'class="pebble"', file_get_contents($this->source.'/'.$file)));
        elseif ($type === 'root-event') $this->edit($file, str_replace('data-book="admin"', 'data-book="admin" onclick="hidden"', file_get_contents($this->source.'/'.$file)));
        elseif ($type === 'symlink-directory') symlink($this->source.'/teacher', $this->source.'/'.$file);
        elseif ($type === 'root') $this->edit($file, '<p>Hidden</p>');
        elseif ($type === 'html') $this->edit($file, str_replace('</div>', $value.'</div>', file_get_contents($this->source.'/'.$file)));
        elseif (in_array($type, ['css', 'image', 'extra', 'extension', 'hash'])) $this->edit($file, $value, $type !== 'hash');
        elseif ($type === 'symlink') symlink($this->source.'/admin/page.html', $this->source.'/'.$file);
        elseif ($type === 'missing') unlink($this->source.'/'.$file);
        else {
            $m = json_decode(file_get_contents($this->source.'/manifest.json'), true);
            if ($type === 'manifest-books') $m['books'] = 'invalid';
            elseif ($type === 'manifest-task-object') $m['books']['admin']['tasks'] = ['sprout' => $m['books']['admin']['tasks'][0], 'ripple' => $m['books']['admin']['tasks'][1]];
            elseif ($type === 'manifest-task') $m['books']['admin']['tasks'][0]['section'] = [];
            elseif ($type === 'version') $m['version'] = '../bad';
            elseif (in_array($type, ['page-bytes', 'style-bytes', 'page-bytes-type', 'style-bytes-type'], true)) {
                $key = str_starts_with($type, 'page') ? 'page_bytes' : 'style_bytes';
                $m['books']['admin'][$key] = str_ends_with($type, '-type') ? '123' : $m['books']['admin'][$key] + 1;
            }
            elseif ($type === 'image-extension') {
                rename($this->source.'/admin/shots/pebble/pixel.jpg', $this->source.'/admin/shots/pebble/pixel.png');
                $m['books']['admin']['files']['shots/pebble/pixel.png'] = $m['books']['admin']['files']['shots/pebble/pixel.jpg'];
                unset($m['books']['admin']['files']['shots/pebble/pixel.jpg']);
            }
            elseif ($type === 'traversal') $m['books']['admin']['page'] = '../admin/page.html';
            else $m['books']['admin']['files']['shots/pebble/pixel.jpg']['bytes']++;
            file_put_contents($this->source.'/manifest.json', json_encode($m));
        }
        if ($type === 'hash') {
            $m = json_decode(file_get_contents($this->source.'/manifest.json'), true);
            $m['books']['admin']['page_bytes'] = strlen($value);
            file_put_contents($this->source.'/manifest.json', json_encode($m));
        }
        $output = $this->failedInstall();
        $this->assertStringContainsString($rule, $output);
        $this->assertStringContainsString(in_array($type, ['manifest-books', 'version', 'traversal', 'manifest-task-object'], true) ? 'manifest.json' : ($type === 'image-extension' ? 'admin/shots/pebble/pixel.png' : $file), $output);
        $this->assertStringNotContainsString('hidden bytes', $output);
        $this->assertSame($before, Storage::disk('local')->get('guides/current.json'));
        $this->assertSame(['d1-1234abcd'], app(GuideReleases::class)->status()['installed']);
    }

    #[Test]
    public function a_corrupt_pointer_never_allows_pruning_installed_releases(): void
    {
        $this->install();
        Storage::disk('local')->put('guides/current.json', 'not json');
        $this->artisan('guides:prune')->expectsOutputToContain('pointer-invalid: current.json')->assertExitCode(1);
        Storage::disk('local')->assertExists('guides/d1-1234abcd/admin/page.html');
        $this->assertSame('not json', Storage::disk('local')->get('guides/current.json'));
    }

    #[Test]
    public function task_identifiers_are_opaque_data_and_manifest_order_is_not_html_order(): void
    {
        $this->edit('admin/page.html', str_replace('data-task="ripple"', 'data-task="ripple.v2:blue"', file_get_contents($this->source.'/admin/page.html')));
        $manifest = json_decode(file_get_contents($this->source.'/manifest.json'), true);
        $manifest['books']['admin']['tasks'][1]['id'] = 'ripple.v2:blue';
        $manifest['books']['admin']['tasks'] = array_reverse($manifest['books']['admin']['tasks']);
        file_put_contents($this->source.'/manifest.json', json_encode($manifest));
        $this->install();
    }

    private function failedInstall(): string
    {
        $this->withoutMockingConsoleOutput();
        $output = new BufferedOutput;
        $this->assertSame(1, Artisan::call('guides:install', ['folder' => $this->source], $output));
        return $output->fetch();
    }

    #[Test]
    public function unreadable_pointer_refuses_install_and_cleans_temporary_names(): void
    {
        $this->install();
        $disk = Storage::disk('local');
        rename($disk->path('guides/current.json'), $disk->path('guides/saved-pointer'));
        mkdir($disk->path('guides/current.json'));
        $output = $this->failedInstall();
        $this->assertStringContainsString('pointer-read: current.json', $output);
        rmdir($disk->path('guides/current.json'));
        rename($disk->path('guides/saved-pointer'), $disk->path('guides/current.json'));
        $this->assertSame('d1-1234abcd', app(GuideReleases::class)->status()['current']);
        $this->assertSame([], glob($disk->path('guides/.install-*')));
        $this->assertSame([], glob($disk->path('guides/.pointer-*')));
    }

    #[Test]
    public function install_rerun_immutable_versions_rollback_and_prune(): void
    {
        $this->install();
        $this->install();
        $this->withToken($this->token('MasjidAdmin'));
        foreach (['d2-1234abcd', 'd3-1234abcd'] as $version) {
            $m = json_decode(file_get_contents($this->source.'/manifest.json'), true);
            $m['version'] = $version;
            file_put_contents($this->source.'/manifest.json', json_encode($m));
            $this->install();
        }
        $this->getJson($this->url('admin', '/admin/d1-1234abcd/pictures/shots/pebble/pixel.jpg'))->assertOk();
        $this->artisan('guides:use', ['version' => 'd2-1234abcd'])->assertExitCode(0);
        $this->getJson($this->url('admin', '/admin'))->assertJsonPath('data.version', 'd2-1234abcd');
        $this->artisan('guides:use', ['version' => 'd999-aaaaaaaa'])->assertExitCode(1);
        $this->artisan('guides:prune')->assertExitCode(0);
        $this->assertSame(['d2-1234abcd', 'd3-1234abcd'], app(GuideReleases::class)->status()['installed']);
        $this->getJson($this->url('admin', '/admin/d1-1234abcd/pictures/shots/pebble/pixel.jpg'))->assertNotFound();
        $this->artisan('guides:status')->expectsOutputToContain('d2-1234abcd')->assertExitCode(0);
        $this->edit('admin/page.html', str_replace('Velvet', 'Changed', file_get_contents($this->source.'/admin/page.html')));
        $this->artisan('guides:install', ['folder' => $this->source])->expectsOutputToContain('immutable')->assertExitCode(1);
    }
}
