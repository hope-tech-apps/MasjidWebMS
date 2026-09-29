<?php

namespace Tests\Feature;

use App\Models\Masjid;
use App\Services\Receipts\Letterhead;
use App\Services\Schools\ReportCardPdfService;
use App\Support\PdfLogo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The image decoding audit (2026-09-29): every PDF embeds one image, the
 * organisation's logo, and both renderers decode it through the system libgd,
 * outside memory_limit. PdfLogo is the one door; report cards read the NEWEST
 * logo; the teacher PDF route is throttled.
 */
class PdfLogoGuardTest extends TestCase
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

        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        foreach ($this->temp as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    private function org(): Masjid
    {
        return Masjid::create([
            'name' => 'Logo Test ' . uniqid(),
            'email' => 'logo' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0,
        ]);
    }

    /** A small real PNG of one colour, so two logos can be told apart. */
    private function png(array $rgb): string
    {
        $image = imagecreatetruecolor(40, 20);
        imagefill($image, 0, 0, imagecolorallocate($image, ...$rgb));
        $path = tempnam(sys_get_temp_dir(), 'pdflogo') . '.png';
        imagepng($image, $path);
        $this->temp[] = $path;

        return $path;
    }

    /** A file over the cap. Its bytes are never decoded, so they need not be an image. */
    private function oversized(string $extension = 'png'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pdflogo-big') . '.' . $extension;
        file_put_contents($path, str_repeat("\0", PdfLogo::MAX_BYTES + 1));
        $this->temp[] = $path;

        return $path;
    }

    private function reportCardLogo(Masjid $org): ?string
    {
        $method = new ReflectionMethod(ReportCardPdfService::class, 'logoDataUri');

        return $method->invoke(app(ReportCardPdfService::class), $org);
    }

    #[Test]
    public function a_small_logo_is_embedded_as_it_was(): void
    {
        $path = $this->png([10, 120, 60]);

        $this->assertSame('data:image/png;base64,' . base64_encode((string) file_get_contents($path)), PdfLogo::dataUri($path, 'image/png', 'test'));
    }

    #[Test]
    public function a_logo_over_the_cap_is_left_out_with_one_warning_an_hour(): void
    {
        $path = $this->oversized();
        Log::spy();

        $this->assertNull(PdfLogo::dataUri($path, 'image/png', 'report card', ['masjid_id' => 7]));
        $this->assertNull(PdfLogo::dataUri($path, 'image/png', 'report card', ['masjid_id' => 7]));

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []) => str_contains($message, 'left out of a report card')
                && $context['masjid_id'] === 7 && $context['bytes'] === PdfLogo::MAX_BYTES + 1)
            ->once();

        // Once an hour, not once ever: still quiet at 59 minutes, said again after the hour.
        $this->travel(59)->minutes();
        $this->assertNull(PdfLogo::dataUri($path, 'image/png', 'report card', ['masjid_id' => 7]));
        Log::shouldHaveReceived('warning')->once();
        $this->travel(2)->minutes();
        $this->assertNull(PdfLogo::dataUri($path, 'image/png', 'report card', ['masjid_id' => 7]));
        Log::shouldHaveReceived('warning')->twice();

        $this->assertNull(PdfLogo::dataUri('/nonexistent/logo.png', 'image/png', 'report card'));
    }

    #[Test]
    public function a_report_card_prints_the_newest_logo_like_the_apps(): void
    {
        $org = $this->org();
        $old = $this->png([200, 0, 0]);
        $new = $this->png([0, 0, 200]);
        $org->addMedia($old)->preservingOriginal()->toMediaCollection('logos');
        $this->travel(1)->minutes();
        $org->addMedia($new)->preservingOriginal()->toMediaCollection('logos');

        $this->assertSame('data:image/png;base64,' . base64_encode((string) file_get_contents($new)), $this->reportCardLogo($org->fresh()));
        $this->assertSame($org->fresh()->logo->id, $org->fresh()->getMedia('logos')->last()->id, 'the premise: two rows, newest last');
    }

    #[Test]
    public function a_report_card_whose_logo_is_too_large_prints_the_name_instead(): void
    {
        $org = $this->org();
        $org->addMedia($this->oversized())->preservingOriginal()->usingFileName('logo.png')->toMediaCollection('logos');
        $media = $org->fresh()->logo;
        $media->forceFill(['mime_type' => 'image/png'])->save();

        $this->assertNull($this->reportCardLogo($org->fresh()));
    }

    #[Test]
    public function the_receipt_letterhead_caps_both_of_its_sources(): void
    {
        $org = $this->org();
        $letterhead = app(Letterhead::class);
        $dir = storage_path('app/statement-assets');
        @mkdir($dir, 0775, true);
        $asset = "{$dir}/masjid-{$org->id}-logo.png";
        $this->temp[] = $asset;

        // The curated statement asset, small and then oversized.
        copy($this->png([0, 90, 0]), $asset);
        $this->assertStringStartsWith('data:image/png;base64,', (string) $letterhead->logoDataUri($org));
        // A small media logo too, which a refused curated asset falling through would print.
        $org->addMedia($this->png([0, 0, 90]))->preservingOriginal()->usingFileName('small.png')->toMediaCollection('logo');
        file_put_contents($asset, str_repeat("\0", PdfLogo::MAX_BYTES + 1));
        $this->assertNull($letterhead->logoDataUri($org->fresh()), 'a refused curated asset does not fall through to the media logo');
        @unlink($asset);
        $this->assertStringStartsWith('data:image/png;base64,', (string) $letterhead->logoDataUri($org->fresh()), 'the premise: without the asset, the media logo prints');
        $org->fresh()->clearMediaCollection('logo');

        // The media branch ('logo', singular, which nothing writes today).
        $org->addMedia($this->oversized())->preservingOriginal()->usingFileName('logo.png')->toMediaCollection('logo');
        $org->fresh()->getFirstMedia('logo')->forceFill(['mime_type' => 'image/png'])->save();
        $this->assertNull($letterhead->logoDataUri($org->fresh()));
    }

    #[Test]
    public function the_teacher_report_card_pdf_route_is_throttled(): void
    {
        $route = collect(Route::getRoutes())->first(fn ($r) => str_ends_with($r->uri(), 'members/{membership_id}/report-card/pdf') && in_array('GET', $r->methods(), true));

        $this->assertNotNull($route, 'the teacher PDF route exists');
        $this->assertContains('throttle:report-card-pdf', $route->gatherMiddleware());

        // 30 a minute, in a bucket of its own: an inline throttle:30,1 keys on the
        // bare auth id, which a family Contact with the same number shares.
        $teacher = new \App\Models\User;
        $teacher->id = 42;
        $request = Request::create('/');
        $request->setUserResolver(fn () => $teacher);
        $limit = RateLimiter::limiter('report-card-pdf')($request);
        $this->assertSame(30, $limit->maxAttempts);
        $this->assertSame(60, $limit->decaySeconds);
        $this->assertSame('report-card-pdf:42', $limit->key);
    }
}
