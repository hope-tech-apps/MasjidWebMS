<?php

namespace Tests\Feature\Studio;

use App\Models\Masjid;
use App\Models\StudioDraft;
use App\Support\Studio\LogoDerivatives;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\Feature\Studio\Concerns\ProvisionsStudioDrafts;
use Tests\TestCase;

/**
 * Step 3 turns the draft's private logo into the organisation's public logo and
 * three derivatives (docs/manara-studio-w1.md S8): a 48x48 favicon on
 * transparent padding, a 180x180 opaque touch icon and a 1200x630 share image,
 * which /api/v1/settings and the by-host lookup then serve. The private copy
 * goes; the record of what was uploaded stays.
 */
class StudioProvisionLogoTest extends TestCase
{
    use ProvisionsStudioDrafts;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpProvisioning();
        $this->actAsSuperAdmin();
    }

    protected function tearDown(): void
    {
        // The memory seam is static: put it back for the next test.
        LogoDerivatives::$headroomBytes = null;

        parent::tearDown();
    }

    #[Test]
    public function the_logo_and_its_three_derivatives_are_stored_served_and_the_private_bytes_are_gone(): void
    {
        $draft = $this->draftWith($this->studioAnswers());
        $this->assertNotSame([], $this->storedLogos(), 'the premise: the draft holds a logo');

        $data = $this->provision($draft->id)->assertCreated()->json('data');
        $masjid = Masjid::findOrFail($data['masjid_id']);

        $this->assertNotNull($masjid->logo, 'the organisation has its logo');

        $public = Storage::disk('public');
        $sizes = [];
        foreach (['favicon' => $masjid->favicon, 'touch_icon' => $masjid->touch_icon, 'share_image' => $masjid->share_image] as $name => $media) {
            $this->assertNotNull($media, "no {$name} row");
            $path = $public->path($media->getPathRelativeToRoot());
            [$w, $h] = getimagesize($path);
            $sizes[$name] = [$w, $h];
        }
        $this->assertSame(['favicon' => [48, 48], 'touch_icon' => [180, 180], 'share_image' => [1200, 630]], $sizes);

        // The favicon's padding is transparent; the touch icon has none anywhere.
        $favicon = imagecreatefrompng($public->path($masjid->favicon->getPathRelativeToRoot()));
        $this->assertSame(127, imagecolorsforindex($favicon, imagecolorat($favicon, 0, 0))['alpha']);
        $touch = imagecreatefrompng($public->path($masjid->touch_icon->getPathRelativeToRoot()));
        $this->assertSame(0, imagecolorsforindex($touch, imagecolorat($touch, 0, 0))['alpha']);

        $settings = $this->getJson('/api/v1/settings', ['masjid-id' => (string) $masjid->id])->assertOk()->json('data');
        $this->assertSame($masjid->logo->original_url, $settings['logo_url']);
        $this->assertSame($masjid->favicon->original_url, $settings['favicon_url']);
        $this->assertSame($masjid->touch_icon->original_url, $settings['touch_icon_url']);
        $this->assertSame($masjid->share_image->original_url, $settings['share_image_url']);
        $this->assertSame(
            ['logo_url', 'header_logo_url', 'footer_logo_url', 'favicon_url', 'touch_icon_url', 'share_image_url', 'copyright_text'],
            array_slice(array_keys($settings), 3, 7),
            'the three keys sit after footer_logo_url',
        );

        $this->assertSame([
            'logo_url' => $masjid->logo->original_url,
            'favicon_url' => $masjid->favicon->original_url,
            'touch_icon_url' => $masjid->touch_icon->original_url,
            'share_image_url' => $masjid->share_image->original_url,
        ], $data['brand_assets']);

        $this->assertSame([], $this->storedLogos(), 'the draft\'s private logo bytes are deleted after commit');
        $fresh = StudioDraft::findOrFail($draft->id);
        $this->assertSame('logo.png', $fresh->logo_original_name, 'the record of what was uploaded stays');
        $this->assertSame([], $this->derivativeDirectories($draft->id), 'the temporary directory is gone');
    }

    #[Test]
    public function a_logo_over_the_memory_left_is_a_clean_422_before_anything_is_written_and_a_retry_succeeds(): void
    {
        $draft = $this->draftWith($this->studioAnswers());
        $masjidsBefore = Masjid::count();

        // Under the 8 MiB the derive chain needs besides the logo itself.
        LogoDerivatives::$headroomBytes = 1024 * 1024;

        $response = $this->provision($draft->id)->assertStatus(422)->assertJsonPath('status', 'failed');
        $this->assertStringContainsString('The logo is too large to make the icons from (400×200)', $response->json('data.logo.0'));

        $this->assertSame($masjidsBefore, Masjid::count(), 'no organisation');
        $this->assertSame(0, Media::count(), 'no media rows');
        $this->assertSame([], $this->derivativeDirectories($draft->id), 'no temporary images');
        $fresh = StudioDraft::findOrFail($draft->id);
        $this->assertSame(StudioDraft::STATUS_DRAFT, $fresh->status);
        $this->assertTrue($fresh->logoExists(), 'the draft keeps its logo');

        LogoDerivatives::$headroomBytes = null;
        $this->provision($draft->id)->assertCreated();
    }

    #[Test]
    public function a_logo_whose_header_declares_more_than_the_edge_cap_is_refused_without_a_decode(): void
    {
        $draft = $this->draftWith($this->studioAnswers());

        // The draft's stored bytes replaced by a 45-byte PNG that claims 20000 x 20000.
        $chunk = fn (string $type, string $data) => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        Storage::disk((string) config('studio.logo.disk'))->put($draft->logo_path, "\x89PNG\r\n\x1a\n"
            . $chunk('IHDR', pack('NN', 20000, 20000) . "\x08\x02\x00\x00\x00")
            . $chunk('IEND', ''));

        $this->provision($draft->id)
            ->assertStatus(422)
            ->assertJsonPath('data.logo.0', 'The logo is too large to make the icons from (20000×20000). Upload a smaller logo, at most ' . LogoDerivatives::MAX_EDGE . ' pixels on each side.');

        $this->assertSame(0, Media::count());
        $this->assertSame([], $this->derivativeDirectories($draft->id));
        $this->assertSame(StudioDraft::STATUS_DRAFT, StudioDraft::findOrFail($draft->id)->status);
    }
}
