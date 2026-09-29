<?php

namespace Tests\Feature;

use App\Http\Requests\Admin\Studio\StoreStudioDraftLogoRequest;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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

    private float $lockWait;

    /** What the tests that are not about the memory check give the decode: room for anything they make. */
    private const ROOMY = 512 * 1024 * 1024;

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

        // The two static seams, set so no test depends on how much memory this
        // process happens to hold or on a real wait for a lock.
        $this->lockWait = BrandAssets::$lockWaitSeconds;
        BrandAssets::$lockWaitSeconds = 0.0;
        LogoDerivatives::$headroomBytes = self::ROOMY;
    }

    protected function tearDown(): void
    {
        BrandAssets::$lockWaitSeconds = $this->lockWait;
        LogoDerivatives::$headroomBytes = null;

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

    /**
     * The SuperAdmin update route (MasjidsController::update): its own hook,
     * its own multipart body, separate from /details.
     */
    private function superUpload(Masjid $org, string $png)
    {
        // Country and City declare no $fillable, so they go in by query builder.
        $countryId = DB::table('countries')->insertGetId(['name' => 'Canada', 'code' => 'CA']);
        $cityId = DB::table('cities')->insertGetId(['name' => 'Burlington', 'country_id' => $countryId]);

        return $this->post("/api/admin/masjids/{$org->id}", [
            'name' => $org->name, 'email' => $org->email, 'phone' => '+15550001234',
            'longitude' => '0', 'latitude' => '0', 'address' => '1 Test St',
            'country_id' => (string) $countryId, 'city_id' => (string) $cityId,
            'logo' => new UploadedFile($png, 'new-logo.png', 'image/png', null, true),
        ], ['Accept' => 'application/json']);
    }

    /**
     * A PNG whose header declares $width x $height and which has no pixel data:
     * the 8-byte signature, an IHDR with a correct CRC, an empty IEND. 45 bytes.
     * getimagesize reads only the header, so the size can be huge and the file
     * is tiny, and nothing can decode it: a test that gets the "too large"
     * answer proves the decode was never tried.
     */
    private function headerOnlyPng(int $width, int $height): string
    {
        $chunk = fn (string $type, string $data) => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        $bytes = "\x89PNG\r\n\x1a\n"
            . $chunk('IHDR', pack('NN', $width, $height) . "\x08\x02\x00\x00\x00")
            . $chunk('IEND', '');
        $path = tempnam(sys_get_temp_dir(), 'brand') . '.png';
        file_put_contents($path, $bytes);
        $this->temp[] = $path;

        return $path;
    }

    private function orgWithLogoFile(string $path): Masjid
    {
        $org = $this->org('#123456', null);
        $org->addMedia($path)->preservingOriginal()->toMediaCollection('logos');

        return $org->fresh();
    }

    private function derivativeCount(Masjid $org): int
    {
        return Media::query()->where('model_type', Masjid::class)->where('model_id', $org->id)
            ->whereIn('collection_name', BrandAssets::COLLECTIONS)->count();
    }

    /** True when the organisation's regeneration lock is free; takes and gives it back to find out. */
    private function lockIsFree(Masjid $org): bool
    {
        $probe = Cache::lock(BrandAssets::lockKey((int) $org->id), 5);

        if (! $probe->get()) {
            return false;
        }

        $probe->release();

        return true;
    }

    private function tooLargeSentence(int $width, int $height, int $maxSide): string
    {
        return "The logo is too large to make the icons from ({$width}×{$height}). Upload a smaller logo, at most {$maxSide} pixels on each side.";
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
    public function the_super_admin_update_route_regenerates_a_studio_orgs_derivatives(): void
    {
        $org = $this->org('#FFFFFF', [200, 30, 30]);
        $super = $this->superAdmin();
        BrandAssets::regenerate($org, null, (int) $super->id);
        $before = $this->derivativeIds($org);
        Sanctum::actingAs($super);

        // This route clears `logos` first, so it cannot tie on created_at, but
        // the clock is moved anyway so both upload tests read the same way.
        $this->travel(5)->seconds();

        $this->superUpload($org, $this->png([10, 30, 200]))->assertOk()->assertJsonPath('status', 'success');
        $org->refresh();

        $after = $this->derivativeIds($org);
        $this->assertCount(3, $after);
        $this->assertSame([], array_intersect($before, $after), 'rebuilt, not kept');

        $favicon = imagecreatefrompng($this->pathOf($org->favicon));
        $centre = imagecolorsforindex($favicon, imagecolorat($favicon, 24, 24));
        $this->assertSame([10, 30, 200], [$centre['red'], $centre['green'], $centre['blue']], 'made from the new logo');
    }

    #[Test]
    public function the_super_admin_update_route_leaves_an_org_without_derivatives_alone(): void
    {
        $org = $this->org();
        Sanctum::actingAs($this->superAdmin());

        $this->superUpload($org, $this->png([10, 200, 10]))->assertOk()->assertJsonPath('status', 'success');

        $this->assertSame([], $this->derivativeIds($org), 'a live organisation gets derivatives only by an explicit regenerate');
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

    // ---- Pixel and memory cap -------------------------------------------------

    #[Test]
    public function a_logo_over_the_edge_cap_is_a_422_from_the_header_and_writes_nothing(): void
    {
        $org = $this->orgWithLogoFile($this->headerOnlyPng(20000, 20000));
        $filesBefore = $this->publicFiles();
        Sanctum::actingAs($this->superAdmin());

        $this->regenerate($org)
            ->assertStatus(422)
            ->assertExactJson(['status' => 'failed', 'data' => ['logo' => [$this->tooLargeSentence(20000, 20000, LogoDerivatives::MAX_EDGE)]]]);

        $this->assertSame(0, $this->derivativeCount($org));
        $this->assertSame($filesBefore, $this->publicFiles());
        $this->assertSame([], File::glob(storage_path('app/private/studio-tmp/org-*')), 'nothing was even started');
        $this->assertTrue($this->lockIsFree($org), 'a refusal gives the lock back');
    }

    #[Test]
    public function one_long_edge_is_enough_to_refuse(): void
    {
        $org = $this->orgWithLogoFile($this->headerOnlyPng(LogoDerivatives::MAX_EDGE + 1, 10));
        Sanctum::actingAs($this->superAdmin());

        $this->regenerate($org)
            ->assertStatus(422)
            ->assertJsonPath('data.logo.0', $this->tooLargeSentence(LogoDerivatives::MAX_EDGE + 1, 10, LogoDerivatives::MAX_EDGE));
    }

    #[Test]
    public function a_logo_under_the_edge_cap_but_over_the_memory_left_is_a_422_too(): void
    {
        $org = $this->orgWithLogoFile($this->headerOnlyPng(3000, 3000));
        Sanctum::actingAs($this->superAdmin());

        // 3000 x 3000 x 5 is 45 MB and the fixed allowance is 8 MB, against 10
        // MB left. What would have fitted: (10 - 8) MB / 5 bytes is a 647 px
        // square, said to the hundred below.
        LogoDerivatives::$headroomBytes = 10 * 1024 * 1024;

        $this->regenerate($org)
            ->assertStatus(422)
            ->assertExactJson(['status' => 'failed', 'data' => ['logo' => [$this->tooLargeSentence(3000, 3000, 600)]]]);

        $this->assertSame(0, $this->derivativeCount($org));
        $this->assertTrue($this->lockIsFree($org));
    }

    #[Test]
    public function the_memory_budget_is_width_times_height_times_five_plus_eight_megabytes(): void
    {
        $org = $this->org();
        Sanctum::actingAs($this->superAdmin());

        // The 200 x 100 logo: 20,000 pixels x 5 = 100,000 bytes, plus 8 MiB.
        $needed = 100000 + 8 * 1024 * 1024;

        LogoDerivatives::$headroomBytes = $needed - 1;
        $this->regenerate($org)->assertStatus(422);
        $this->assertSame(0, $this->derivativeCount($org));

        LogoDerivatives::$headroomBytes = $needed;
        $this->regenerate($org)->assertOk();
        $this->assertSame(3, $this->derivativeCount($org));
    }

    #[Test]
    public function the_ini_memory_limit_is_read_as_bytes(): void
    {
        $this->assertSame(134217728, LogoDerivatives::bytesFromIni('128M'));
        $this->assertSame(134217728, LogoDerivatives::bytesFromIni('128m'));
        $this->assertSame(1073741824, LogoDerivatives::bytesFromIni('1G'));
        $this->assertSame(524288, LogoDerivatives::bytesFromIni('512K'));
        $this->assertSame(134217728, LogoDerivatives::bytesFromIni('134217728'));
        $this->assertNull(LogoDerivatives::bytesFromIni('-1'), '-1 is no limit');
        $this->assertSame(0, LogoDerivatives::bytesFromIni('plenty'), 'a limit that cannot be read refuses');
    }

    #[Test]
    public function the_studio_logo_upload_and_the_derivatives_share_one_edge_cap(): void
    {
        $this->assertSame(8000, LogoDerivatives::MAX_EDGE);

        $rules = (new StoreStudioDraftLogoRequest())->rules()['logo'];
        $max = LogoDerivatives::MAX_EDGE;

        $this->assertContains("dimensions:min_width=96,min_height=96,max_width={$max},max_height={$max}", $rules);
    }

    #[Test]
    public function a_too_large_logo_upload_succeeds_and_skips_the_regeneration_with_one_warning(): void
    {
        $org = $this->org();
        $super = $this->superAdmin();
        BrandAssets::regenerate($org, null, (int) $super->id);
        $before = $this->derivativeIds($org);
        Log::spy();
        Sanctum::actingAs($super);
        $this->travel(5)->seconds(); // /details appends to `logos`; see the upload test above

        // The logo rule on /details takes a PNG whose header claims 20000 x 20000.
        $response = $this->upload($org, $this->headerOnlyPng(20000, 20000))->assertOk();

        $expected = Masjid::with('logo', 'footer_logo', 'socialMediaLinks')->findOrFail($org->id);
        $this->assertSame(json_encode(['status' => 'success', 'data' => $expected]), $response->getContent(), 'the answer it gives today');
        $this->assertSame($before, $this->derivativeIds($org), 'the previous derivatives stay');

        Log::shouldHaveReceived('warning')
            ->with(Mockery::on(fn ($message) => str_contains($message, 'too large')), Mockery::on(
                fn ($context) => $context['masjid_id'] === (int) $org->id && $context['width'] === 20000 && $context['height'] === 20000
            ))
            ->once();
        Log::shouldNotHaveReceived('warning', ['Brand assets: the logo could not be read', Mockery::any()]);
    }

    #[Test]
    public function the_super_admin_update_route_skips_a_logo_that_does_not_fit_the_memory_left(): void
    {
        $org = $this->org();
        $super = $this->superAdmin();
        BrandAssets::regenerate($org, null, (int) $super->id);
        $before = $this->derivativeIds($org);
        Log::spy();
        Sanctum::actingAs($super);
        $this->travel(5)->seconds();
        LogoDerivatives::$headroomBytes = 10 * 1024 * 1024;

        $this->superUpload($org, $this->headerOnlyPng(3000, 3000))->assertOk()->assertJsonPath('status', 'success');

        $this->assertSame($before, $this->derivativeIds($org), 'the previous derivatives stay');
        Log::shouldHaveReceived('warning')
            ->with(Mockery::on(fn ($message) => str_contains($message, 'too large')), Mockery::on(
                fn ($context) => $context['masjid_id'] === (int) $org->id && $context['width'] === 3000 && $context['height'] === 3000 && $context['limit'] === 'memory'
            ))
            ->once();
    }

    // ---- 403 before 422 -------------------------------------------------------

    #[Test]
    public function a_non_super_with_an_invalid_body_gets_403_not_422(): void
    {
        $org = $this->org();
        $admin = User::factory()->create(['type' => 'MasjidAdmin', 'phone' => '+1' . random_int(1000000000, 9999999999)]);
        MasjidUser::create(['masjid_id' => $org->id, 'user_id' => $admin->id, 'role' => 'masjid-admin', 'is_default' => true]);
        Sanctum::actingAs($admin->fresh());

        // The refusal is the app's usual one for an HttpException: the message
        // is the real one in debug and "Request failed." outside it.
        $message = config('app.debug') ? 'Only a super admin can regenerate an organisation\'s brand images.' : 'Request failed.';

        $this->regenerate($org, ['background_color' => 'not a colour'])
            ->assertForbidden()
            ->assertExactJson(['status' => 'error', 'message' => $message]);

        $this->assertSame(0, $this->derivativeCount($org));
    }

    // ---- One run per organisation ---------------------------------------------

    #[Test]
    public function a_held_lock_makes_the_route_409_and_writes_nothing(): void
    {
        $org = $this->org();
        Sanctum::actingAs($this->superAdmin());

        $held = Cache::lock(BrandAssets::lockKey((int) $org->id), 30);
        $this->assertTrue($held->get());

        $this->regenerate($org)
            ->assertStatus(409)
            ->assertExactJson(['status' => 'error', 'message' => 'The brand images are already being made. Try again in a moment.']);
        $this->assertSame(0, $this->derivativeCount($org));

        // Another organisation is not held up by it.
        $this->regenerate($this->org())->assertOk();

        $held->release();
        $this->regenerate($org)->assertOk();
        $this->assertSame(3, $this->derivativeCount($org));
    }

    #[Test]
    public function a_held_lock_makes_the_upload_hook_skip_and_the_upload_still_succeeds(): void
    {
        $org = $this->org();
        $super = $this->superAdmin();
        BrandAssets::regenerate($org, null, (int) $super->id);
        $before = $this->derivativeIds($org);
        Log::spy();
        Sanctum::actingAs($super);
        $this->travel(5)->seconds();

        $held = Cache::lock(BrandAssets::lockKey((int) $org->id), 30);
        $this->assertTrue($held->get());

        $response = $this->upload($org, $this->png([10, 30, 200]))->assertOk();

        $expected = Masjid::with('logo', 'footer_logo', 'socialMediaLinks')->findOrFail($org->id);
        $this->assertSame(json_encode(['status' => 'success', 'data' => $expected]), $response->getContent());
        $this->assertSame($before, $this->derivativeIds($org), 'the previous derivatives stay');
        Log::shouldHaveReceived('warning')
            ->with(Mockery::on(fn ($message) => str_contains($message, 'another regeneration was still running')), Mockery::on(fn ($context) => $context['masjid_id'] === (int) $org->id))
            ->once();

        $held->release();
    }

    #[Test]
    public function a_normal_run_and_a_refused_one_both_release_the_lock(): void
    {
        $org = $this->org();
        BrandAssets::regenerate($org, null, (int) $this->superAdmin()->id);
        $this->assertTrue($this->lockIsFree($org), 'released after a run');

        $bare = $this->org('#123456', null);
        try {
            BrandAssets::regenerate($bare, null, null);
            $this->fail('a regeneration with no logo succeeded');
        } catch (ValidationException) {
        }
        $this->assertTrue($this->lockIsFree($bare), 'released after a refusal');
    }

    // ---- Lifecycle ------------------------------------------------------------

    #[Test]
    public function the_old_rows_and_files_outlive_the_transaction_and_go_only_when_it_commits(): void
    {
        $org = $this->org();
        $actor = (int) $this->superAdmin()->id;
        BrandAssets::regenerate($org, null, $actor);
        $old = $this->derivativeIds($org);
        $oldPaths = Media::whereIn('id', $old)->get()->map(fn (Media $m) => $this->pathOf($m))->all();

        DB::beginTransaction();
        BrandAssets::regenerate($org, null, $actor);

        // Inside the caller's transaction: old and new side by side, and the lock held.
        $this->assertSame(6, $this->derivativeCount($org));
        foreach ($oldPaths as $path) {
            $this->assertFileExists($path, 'the old files are untouched until the commit');
        }
        $this->assertFalse($this->lockIsFree($org), 'held until the old rows are gone');

        DB::commit();

        $after = $this->derivativeIds($org);
        $this->assertSame(3, $this->derivativeCount($org));
        $this->assertSame([], array_intersect($old, $after), 'the new set is the one left');
        foreach ($oldPaths as $path) {
            $this->assertFileDoesNotExist($path, 'gone once it committed');
        }
        $this->assertTrue($this->lockIsFree($org), 'released after the deletion');
    }

    #[Test]
    public function an_outer_rollback_keeps_the_old_set_and_leaves_no_new_files_or_lock(): void
    {
        $org = $this->org();
        $actor = (int) $this->superAdmin()->id;
        BrandAssets::regenerate($org, null, $actor);
        $old = $this->derivativeIds($org);
        $filesBefore = $this->publicFiles();

        DB::beginTransaction();
        BrandAssets::regenerate($org, null, $actor);
        $this->assertFalse($this->lockIsFree($org));
        DB::rollBack();

        $this->assertSame($old, $this->derivativeIds($org), 'the old rows are the ones served');
        $this->assertSame($filesBefore, $this->publicFiles(), 'the rolled-back files are gone and the old ones are still there');
        $this->assertTrue($this->lockIsFree($org), 'a rollback gives the lock back');
    }

    #[Test]
    public function a_previous_derivative_that_will_not_delete_is_a_warning_and_the_new_set_stays(): void
    {
        $org = $this->org();
        $super = $this->superAdmin();
        BrandAssets::regenerate($org, null, (int) $super->id);
        $old = array_values($this->derivativeIds($org));
        Sanctum::actingAs($super);
        Log::spy();

        // Only the old rows are ever deleted, so every delete fails.
        Event::listen('eloquent.deleting: ' . Media::class, fn () => throw new \RuntimeException('the disk said no'));

        $data = $this->regenerate($org)->assertOk()->json('data');

        $new = Media::query()->where('model_type', Masjid::class)->where('model_id', $org->id)
            ->whereIn('collection_name', BrandAssets::COLLECTIONS)->whereNotIn('id', $old)->get();
        $this->assertCount(3, $new, 'the new set stays');
        $this->assertSame(6, $this->derivativeCount($org), 'the old ones are clutter left behind, not removed');
        $this->assertContains($data['favicon_url'], $new->pluck('original_url')->all(), 'and it is the new one that is answered');

        Log::shouldHaveReceived('warning')
            ->with('Brand assets: a previous derivative was not deleted', Mockery::on(fn ($context) => $context['masjid_id'] === (int) $org->id && in_array($context['media_id'], $old, true)))
            ->times(3);
        Log::shouldHaveReceived('warning')->with('Brand assets regenerated', Mockery::on(fn ($context) => $context['replaced'] === 3))->once();
        $this->assertTrue($this->lockIsFree($org));
    }

    #[Test]
    public function a_failed_regeneration_after_a_super_admin_update_leaves_the_response_unchanged(): void
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
        $this->travel(5)->seconds();

        $response = $this->superUpload($org, $this->png([10, 30, 200]))->assertOk()->assertJsonPath('status', 'success');

        $expected = Masjid::findOrFail($org->id);
        $this->assertSame(
            json_encode(['status' => 'success', 'data' => $expected]),
            $response->getContent(),
            'the update answers what it answered before the hook existed',
        );
        $this->assertSame($before, $this->derivativeIds($org), 'the previous derivatives stay');
        $this->assertTrue($this->lockIsFree($org));

        Log::shouldHaveReceived('warning')
            ->with('Brand assets were not regenerated after a logo upload; the previous favicon, touch icon and share image stay', Mockery::on(fn ($context) => $context['masjid_id'] === (int) $org->id))
            ->once();
    }
}
