<?php

namespace Tests\Feature;

use App\Jobs\PurgeRendererCache;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\ThemeSetting;
use App\Models\User;
use App\Support\BrandAssets;
use App\Support\Studio\LogoDerivatives;
use App\Support\Studio\LogoFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

/**
 * Studio W2 S8: an organisation's favicon, touch icon and share image, rebuilt
 * from the logo it has now, on a SuperAdmin's explicit action; and a logo
 * upload that keeps them in step, for an organisation that already has them.
 */
class BrandAssetRegenerationTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $temp = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        foreach ($this->temp as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    /** A real PNG of one flat colour, on disk. */
    private function png(array $rgb, int $width = 200, int $height = 100): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, ...$rgb));
        $path = tempnam(sys_get_temp_dir(), 'brand') . '.png';
        imagepng($image, $path);
        $this->temp[] = $path;

        return $path;
    }

    private function org(?string $background = '#123456', ?array $logoRgb = [200, 30, 30]): Masjid
    {
        $org = Masjid::create([
            'name' => 'Brand Org ' . uniqid(),
            'email' => 'brand' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'org_type' => 'masjid', 'timezone' => 'America/Toronto',
        ]);

        if ($background !== null) {
            ThemeSetting::create([
                'masjid_id' => $org->id,
                'primary_color' => '#01B151', 'secondary_color' => '#222222',
                'accent_color' => '#F5A623', 'background_color' => $background,
            ]);
        }

        if ($logoRgb !== null) {
            $org->addMedia($this->png($logoRgb))->preservingOriginal()->toMediaCollection('logos');
        }

        return $org->fresh();
    }

    private function superAdmin(): User
    {
        return User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+1' . random_int(1000000000, 9999999999)])->fresh();
    }

    private function regenerate(Masjid $org, array $body = [])
    {
        return $this->post("/api/admin/masjids/{$org->id}/brand-assets/regenerate", $body, ['Accept' => 'application/json']);
    }

    /** @return array<string, int> collection => the row's id */
    private function derivativeIds(Masjid $org): array
    {
        return Media::query()->where('model_type', Masjid::class)->where('model_id', $org->id)
            ->whereIn('collection_name', BrandAssets::COLLECTIONS)
            ->orderBy('collection_name')->pluck('id', 'collection_name')->map(fn ($id) => (int) $id)->all();
    }

    private function pathOf(Media $media): string
    {
        return Storage::disk('public')->path($media->getPathRelativeToRoot());
    }

    /** Every file on the public disk, so a stray one cannot hide. */
    private function publicFiles(): array
    {
        $files = Storage::disk('public')->allFiles();
        sort($files);

        return $files;
    }

    private function upload(Masjid $org, string $png)
    {
        return $this->post("/api/admin/masjids/{$org->id}/details", [
            'name' => $org->name, 'email' => $org->email, 'phone' => '+15550001234',
            'timezone' => 'America/Toronto', 'latitude' => '0', 'longitude' => '0',
            'logo' => new UploadedFile($png, 'new-logo.png', 'image/png', null, true),
        ], ['Accept' => 'application/json']);
    }

    #[Test]
    public function it_writes_the_three_sizes_from_the_current_logo(): void
    {
        $org = $this->org('#123456');
        Sanctum::actingAs($this->superAdmin());

        $data = $this->regenerate($org)->assertOk()->json('data');
        $org->refresh();

        $this->assertSame([
            'logo_url' => $org->logo->original_url,
            'favicon_url' => $org->favicon->original_url,
            'touch_icon_url' => $org->touch_icon->original_url,
            'share_image_url' => $org->share_image->original_url,
        ], $data);

        $sizes = [];
        foreach (['favicon' => $org->favicon, 'touch_icon' => $org->touch_icon, 'share_image' => $org->share_image] as $name => $media) {
            [$w, $h] = getimagesize($this->pathOf($media));
            $sizes[$name] = [$w, $h];
        }
        $this->assertSame(['favicon' => [48, 48], 'touch_icon' => [180, 180], 'share_image' => [1200, 630]], $sizes);

        // The theme's background by default: the touch icon's corner is #123456.
        $touch = imagecreatefrompng($this->pathOf($org->touch_icon));
        $corner = imagecolorsforindex($touch, imagecolorat($touch, 0, 0));
        $this->assertSame([0x12, 0x34, 0x56, 0], [$corner['red'], $corner['green'], $corner['blue'], $corner['alpha']]);

        // A colour sent wins over the theme's.
        $this->regenerate($org, ['background_color' => '#ABCDEF'])->assertOk();
        $touch = imagecreatefrompng($this->pathOf($org->fresh()->touch_icon));
        $corner = imagecolorsforindex($touch, imagecolorat($touch, 0, 0));
        $this->assertSame([0xAB, 0xCD, 0xEF], [$corner['red'], $corner['green'], $corner['blue']]);

        $settings = $this->getJson('/api/v1/settings', ['masjid-id' => (string) $org->id])->assertOk()->json('data');
        $this->assertSame($org->fresh()->favicon->original_url, $settings['favicon_url']);
        $this->assertSame($org->fresh()->share_image->original_url, $settings['share_image_url']);
    }

    #[Test]
    public function it_replaces_rather_than_appends(): void
    {
        $org = $this->org();
        Sanctum::actingAs($this->superAdmin());

        $this->regenerate($org)->assertOk();
        $first = $this->derivativeIds($org);
        $firstPaths = Media::whereIn('id', $first)->get()->map(fn (Media $m) => $this->pathOf($m))->all();

        $this->regenerate($org)->assertOk();
        $second = $this->derivativeIds($org);

        $this->assertCount(3, $second, 'one row per collection');
        $this->assertSame([], array_intersect($first, $second), 'the rows are new');
        foreach ($firstPaths as $path) {
            $this->assertFileDoesNotExist($path, 'the previous files went with their rows');
        }

        // The logo is the source and is never touched.
        $this->assertSame(1, Media::where('model_id', $org->id)->where('collection_name', 'logos')->count());
        $this->assertSame([], File::glob(storage_path('app/private/studio-tmp/org-*')), 'the temporary folder is gone');
    }

    #[Test]
    public function a_failure_leaves_the_previous_derivatives_and_no_stray_files(): void
    {
        $org = $this->org();
        $actor = (int) $this->superAdmin()->id;
        BrandAssets::regenerate($org, null, $actor);
        $before = $this->derivativeIds($org);
        $filesBefore = $this->publicFiles();

        // Two rows are added and copied, then the third source is missing.
        $this->app->instance(LogoDerivatives::class, new class extends LogoDerivatives
        {
            public function fromFile(string $absPath, string $backgroundColor): LogoFiles
            {
                $files = parent::fromFile($absPath, $backgroundColor);
                unlink($files->shareImage);

                return $files;
            }
        });

        try {
            BrandAssets::regenerate($org, null, $actor);
            $this->fail('a regeneration with a missing file succeeded');
        } catch (\Throwable $e) {
            $this->assertNotInstanceOf(ValidationException::class, $e);
        }

        $this->assertSame($before, $this->derivativeIds($org), 'the previous derivatives are the ones served');
        $this->assertSame($filesBefore, $this->publicFiles(), 'no file from the failed attempt is left, and none of the old ones went');
        $this->assertSame([], File::glob(storage_path('app/private/studio-tmp/org-*')));
    }

    #[Test]
    public function the_renderer_purge_is_scheduled_after_commit(): void
    {
        config(['services.renderer' => [
            'secret' => 'test-renderer-secret-0123456789abcdef', // RendererConfig needs 32+ characters
            'preview_origin' => 'https://renderer.example.test',
            'purge_origins' => 'https://renderer.example.test',
            'admin_origins' => 'https://masjid.hopetechapps.com',
            'timeout' => 5,
        ]]);
        $org = $this->org();
        $actor = (int) $this->superAdmin()->id;
        $bare = $this->org(null);
        Queue::fake();

        // A refused regeneration writes nothing and purges nothing.
        try {
            BrandAssets::regenerate($bare, null, $actor);
        } catch (ValidationException) {
        }
        Queue::assertNothingPushed();

        BrandAssets::regenerate($org, null, $actor);

        Queue::assertPushed(PurgeRendererCache::class, fn (PurgeRendererCache $job) => $job->organisationId === (int) $org->id);
    }

    #[Test]
    public function an_org_without_derivatives_is_untouched_by_a_logo_upload(): void
    {
        $org = $this->org();
        Sanctum::actingAs($this->superAdmin());
        $keys = array_keys($this->getJson('/api/v1/settings', ['masjid-id' => (string) $org->id])->assertOk()->json('data'));

        $this->upload($org, $this->png([10, 200, 10]))->assertOk();

        $this->assertSame([], $this->derivativeIds($org), 'a live organisation gets derivatives only by an explicit regenerate');
        $this->assertSame($keys, array_keys($this->getJson('/api/v1/settings', ['masjid-id' => (string) $org->id])->assertOk()->json('data')));
    }

    #[Test]
    public function a_studio_orgs_logo_upload_regenerates_its_derivatives(): void
    {
        $org = $this->org('#FFFFFF', [200, 30, 30]);
        $super = $this->superAdmin();
        BrandAssets::regenerate($org, null, (int) $super->id);
        $before = $this->derivativeIds($org);
        Sanctum::actingAs($super);

        // This screen APPENDS to `logos` and `logo()` takes the latest by
        // created_at; in one second SQLite would break the tie by rowid and
        // hand back the old logo, which is not what production ever sees.
        $this->travel(5)->seconds();

        $this->upload($org, $this->png([10, 30, 200]))->assertOk();
        $org->refresh();

        $after = $this->derivativeIds($org);
        $this->assertCount(3, $after);
        $this->assertSame([], array_intersect($before, $after), 'rebuilt, not kept');

        // Made from the NEW logo: the favicon's centre is the new blue.
        $favicon = imagecreatefrompng($this->pathOf($org->favicon));
        $centre = imagecolorsforindex($favicon, imagecolorat($favicon, 24, 24));
        $this->assertSame([10, 30, 200], [$centre['red'], $centre['green'], $centre['blue']]);
    }

    #[Test]
    public function a_failed_regeneration_after_an_upload_leaves_the_upload_response_unchanged(): void
    {
        $org = $this->org();
        $super = $this->superAdmin();
        BrandAssets::regenerate($org, null, (int) $super->id);
        $before = $this->derivativeIds($org);

        $this->app->instance(LogoDerivatives::class, Mockery::mock(LogoDerivatives::class, function ($mock) {
            $mock->shouldReceive('fromFile')->andThrow(new \RuntimeException('GD could not read it'));
        }));
        Log::spy();
        Sanctum::actingAs($super);

        $response = $this->upload($org, $this->png([10, 30, 200]))->assertOk();

        $expected = Masjid::with('logo', 'footer_logo', 'socialMediaLinks')->findOrFail($org->id);
        $this->assertSame(
            json_encode(['status' => 'success', 'data' => $expected]),
            $response->getContent(),
            'the upload answers what it answered before the hook existed',
        );
        $this->assertSame($before, $this->derivativeIds($org), 'the previous derivatives stay');

        // Production runs LOG_LEVEL=warning.
        Log::shouldHaveReceived('warning')
            ->with('Brand assets were not regenerated after a logo upload; the previous favicon, touch icon and share image stay', Mockery::on(fn ($context) => $context['masjid_id'] === (int) $org->id))
            ->once();
    }

    #[Test]
    public function without_a_raster_logo_or_a_background_colour_it_is_a_422_and_writes_nothing(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $this->regenerate($this->org('#123456', null))
            ->assertStatus(422)
            ->assertExactJson(['status' => 'failed', 'data' => ['logo' => [BrandAssets::NO_LOGO]]]);

        $svg = $this->org('#123456', null);
        $path = tempnam(sys_get_temp_dir(), 'brand') . '.svg';
        file_put_contents($path, '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"/>');
        $this->temp[] = $path;
        $svg->addMedia($path)->preservingOriginal()->toMediaCollection('logos');
        $this->regenerate($svg)->assertStatus(422)->assertJsonPath('data.logo.0', BrandAssets::NO_LOGO);

        $this->regenerate($this->org(null))
            ->assertStatus(422)
            ->assertExactJson(['status' => 'failed', 'data' => ['background_color' => [BrandAssets::NO_BACKGROUND]]]);

        $this->regenerate($this->org(), ['background_color' => 'red'])->assertStatus(422)->assertJsonPath('status', 'failed');

        $this->assertSame(0, Media::whereIn('collection_name', BrandAssets::COLLECTIONS)->count());
    }

    #[Test]
    public function a_non_super_gets_403(): void
    {
        $org = $this->org();
        $admin = User::factory()->create(['type' => 'MasjidAdmin', 'phone' => '+1' . random_int(1000000000, 9999999999)]);
        MasjidUser::create(['masjid_id' => $org->id, 'user_id' => $admin->id, 'role' => 'masjid-admin', 'is_default' => true]);

        $this->regenerate($org)->assertUnauthorized();

        Sanctum::actingAs($admin->fresh());
        $this->regenerate($org)->assertForbidden()->assertJsonPath('status', 'error');

        $this->assertSame([], $this->derivativeIds($org));
    }
}
