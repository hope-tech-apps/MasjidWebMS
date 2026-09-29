<?php

namespace Tests\Feature;

use App\Jobs\ProcessFlyerCutout;
use App\Models\Flyer;
use App\Models\Masjid;
use App\Models\User;
use App\Services\Flyer\ImageCutout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The image decoding audit (2026-09-29): the flyer cutout decodes the whole
 * photo in the queue worker, which has no memory cap. A photo with too many
 * pixels is refused from its header at upload, before the job starts the
 * script, on a manual retry, and by the script itself, whose exit code is
 * never retried.
 */
class FlyerCutoutSizeGuardTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjid;

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

        $this->masjid = Masjid::create([
            'name' => 'Flyer Size ' . uniqid(),
            'email' => 'flyer-size-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'timezone' => 'America/New_York',
        ]);
        $this->seed(\Database\Seeders\FlyerTemplateSeeder::class);

        Storage::fake('local');
        Queue::fake();
        config(['flyer.cutout.enabled' => true]);
    }

    protected function tearDown(): void
    {
        foreach ($this->temp as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    private function actAsSuperAdmin(): void
    {
        Sanctum::actingAs(User::factory()->create([
            'type' => 'SuperAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]));
    }

    private function flyerWithSource(int $width, int $height): Flyer
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($image);
        Storage::disk('local')->put('flyers/sources/photo.png', (string) ob_get_clean());

        return Flyer::factory()->create([
            'masjid_id' => $this->masjid->id,
            'source_image_path' => 'flyers/sources/photo.png',
            'cutout_status' => ProcessFlyerCutout::STATUS_FAILED,
        ]);
    }

    /** A stand-in "python" that prints what the real script prints on a size refusal. */
    private function fakeScript(string $stdout, int $exit): void
    {
        $script = (string) tempnam(sys_get_temp_dir(), 'cutout');
        file_put_contents($script, "echo '" . $stdout . "'\nexit {$exit}\n");
        $this->temp[] = $script;
        config(['flyer.cutout.python' => '/bin/sh', 'flyer.cutout.script' => $script]);
    }

    #[Test]
    public function an_upload_over_8000_pixels_a_side_is_refused_in_the_legacy_envelope(): void
    {
        $this->actAsSuperAdmin();
        $flyer = Flyer::factory()->create(['masjid_id' => $this->masjid->id]);

        $refused = $this->post("/api/admin/masjids/{$this->masjid->id}/flyers/{$flyer->id}/photo", [
            'image' => UploadedFile::fake()->image('wide.jpg', 8001, 10),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('status', 'failed')
            ->assertJsonStructure(['data' => ['image']]);

        // The Studio reads `message`, not the field-keyed envelope.
        $this->assertSame($refused->json('data.image.0'), $refused->json('message'));

        Queue::assertNothingPushed();
    }

    #[Test]
    public function an_upload_over_the_pixel_ceiling_is_refused_only_when_it_will_be_cut_out(): void
    {
        $this->actAsSuperAdmin();
        config(['flyer.cutout.max_pixels' => 1000]);
        $flyer = Flyer::factory()->create(['masjid_id' => $this->masjid->id]);

        $refused = $this->post("/api/admin/masjids/{$this->masjid->id}/flyers/{$flyer->id}/photo", [
            'image' => UploadedFile::fake()->image('photo.png', 50, 30),
        ], ['Accept' => 'application/json']);

        $refused->assertStatus(422)->assertJsonPath('status', 'failed');
        $this->assertStringContainsString('more than background removal can safely handle', (string) $refused->json('message'));
        $this->assertStringContainsString('50 × 30 pixels', (string) $refused->json('message'));
        $this->assertSame($refused->json('message'), $refused->json('data.image.0'));
        // The Studio has no "background removal off" control, so the advice names none.
        $this->assertStringNotContainsString('turned off', (string) $refused->json('message'));

        // Used as uploaded, nothing decodes it, so it is accepted.
        $this->post("/api/admin/masjids/{$this->masjid->id}/flyers/{$flyer->id}/photo", [
            'image' => UploadedFile::fake()->image('photo.png', 50, 30),
            'remove_background' => '0',
        ], ['Accept' => 'application/json'])->assertOk();
        Queue::assertNothingPushed();
    }

    #[Test]
    public function the_job_refuses_an_oversized_stored_photo_without_starting_the_script(): void
    {
        config(['flyer.cutout.max_pixels' => 1000]);
        $flyer = $this->flyerWithSource(50, 30);
        $this->app->instance(ImageCutout::class, new class extends ImageCutout
        {
            public function run(string $srcAbsolute, string $dstAbsolute): array
            {
                throw new RuntimeException('the script must not be started for an oversized photo');
            }
        });

        (new ProcessFlyerCutout($flyer->id, 'local'))->handle($this->app->make(ImageCutout::class));
        $flyer->refresh();

        $this->assertSame(ProcessFlyerCutout::STATUS_FAILED, $flyer->cutout_status);
        $this->assertStringContainsString('more than background removal can safely handle', (string) $flyer->cutout_error);
    }

    #[Test]
    public function the_scripts_own_size_refusal_is_final_not_retried(): void
    {
        $this->fakeScript('{"ok": false, "error": "too_large", "w": 9000, "h": 9000, "max_pixels": 50000000}', ImageCutout::EXIT_TOO_LARGE);
        $flyer = $this->flyerWithSource(40, 20);
        $disk = Storage::disk('local');

        $result = (new ImageCutout)->run($disk->path('flyers/sources/photo.png'), $disk->path('flyers/cutouts/out.png'));

        $this->assertFalse($result['ok']);
        $this->assertFalse($result['meta']['retryable']);
        $this->assertTrue($result['meta']['too_large']);
        $this->assertStringContainsString('9,000 × 9,000 pixels (81.0 megapixels)', (string) $result['reason']);

        // Through the job: recorded as failed, and not thrown for a retry.
        (new ProcessFlyerCutout($flyer->id, 'local'))->handle(new ImageCutout);
        $flyer->refresh();
        $this->assertSame(ProcessFlyerCutout::STATUS_FAILED, $flyer->cutout_status);
        $this->assertStringContainsString('9,000 × 9,000 pixels', (string) $flyer->cutout_error);
    }

    #[Test]
    public function any_other_script_failure_is_still_retryable(): void
    {
        $this->fakeScript('Killed', 137);
        $this->flyerWithSource(40, 20);
        $disk = Storage::disk('local');

        $result = (new ImageCutout)->run($disk->path('flyers/sources/photo.png'), $disk->path('flyers/cutouts/out.png'));

        $this->assertFalse($result['ok']);
        $this->assertTrue($result['meta']['retryable']);
        $this->assertArrayNotHasKey('too_large', $result['meta']);
    }

    #[Test]
    public function the_retry_button_refuses_a_photo_too_large_to_decode(): void
    {
        $this->actAsSuperAdmin();
        config(['flyer.cutout.max_pixels' => 1000]);
        $flyer = $this->flyerWithSource(50, 30);

        $this->postJson("/api/admin/masjids/{$this->masjid->id}/flyers/{$flyer->id}/cutout/retry")
            ->assertStatus(422)
            ->assertJsonPath('status', 'error');
        Queue::assertNothingPushed();

        // A photo that fits is retried as before.
        config(['flyer.cutout.max_pixels' => 50_000_000]);
        $this->postJson("/api/admin/masjids/{$this->masjid->id}/flyers/{$flyer->id}/cutout/retry")->assertOk();
        Queue::assertPushed(ProcessFlyerCutout::class, 1);
    }

    #[Test]
    public function the_script_checks_the_header_before_it_decodes(): void
    {
        $script = (string) file_get_contents(base_path('scripts/cutout/cutout.py'));
        $open = strpos($script, 'img = Image.open(src)');
        $check = strpos($script, 'if w * h > max_pixels:');
        $convert = strpos($script, 'img = img.convert("RGB")');

        $this->assertNotFalse($open);
        $this->assertNotFalse($check);
        $this->assertNotFalse($convert);
        $this->assertTrue($open < $check && $check < $convert, 'the size is judged between opening the header and decoding');
        $this->assertStringContainsString('EXIT_TOO_LARGE = ' . ImageCutout::EXIT_TOO_LARGE, $script);
        // Both sides at least max_edge, so a 12 MP photo is decoded at half size;
        // isinstance so a phone JPEG that opens as MPO is reduced too.
        $this->assertStringContainsString('if isinstance(img, JpegImagePlugin.JpegImageFile):', $script);
        $this->assertStringContainsString('img.draft("RGB", (max_edge, max_edge))', $script);
        $this->assertStringNotContainsString('img.format == "JPEG"', $script);
    }
}
