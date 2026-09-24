<?php

namespace Tests\Feature\Studio;

use App\Models\StudioDraft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\StudioDraftFixtures;
use Tests\TestCase;

/**
 * A draft's logo is a private upload (.claude/rules/private-uploads.md, and
 * docs/manara-studio-w1.md R8): random name on a disk with no URL, type sniffed
 * from the bytes, served only through the authenticated endpoint and never
 * cached on the way.
 */
class StudioDraftLogoTest extends TestCase
{
    use RefreshDatabase;
    use StudioDraftFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpStudio();
        $this->actAsSuperAdmin();
    }

    #[Test]
    public function png_lands_on_the_private_disk_under_a_random_name(): void
    {
        $id = $this->newDraft()['id'];
        $bytes = $this->pngBytes(400, 160);

        $response = $this->uploadLogo($id, $this->realUpload('Our Masjid Logo.png', $bytes))
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.logo.original_name', 'Our Masjid Logo.png')
            ->assertJsonPath('data.logo.mime_type', 'image/png')
            ->assertJsonPath('data.logo.size_bytes', strlen($bytes))
            ->assertJsonPath('data.logo.width', 400)
            ->assertJsonPath('data.logo.height', 160)
            ->assertJsonPath('data.logo.sha256', hash('sha256', $bytes))
            ->assertJsonPath('data.logo.url', "/api/admin/studio/drafts/{$id}/logo");

        $path = StudioDraft::findOrFail($id)->logo_path;

        $this->assertMatchesRegularExpression("#^studio-drafts/{$id}/[A-Za-z0-9]{40}\\.png$#", $path);
        $this->assertSame([$path], $this->storedLogos());
        $this->assertSame($bytes, Storage::disk((string) config('studio.logo.disk'))->get($path));
        $this->assertSame([], Storage::disk('public')->allFiles(), 'nothing reaches the web-exposed disk');

        // The payload names the endpoint, never where the bytes are.
        $this->assertStringNotContainsString(basename($path), $response->getContent());
        $this->assertArrayNotHasKey('logo_path', $response->json('data'));
        $this->assertArrayNotHasKey('logo_disk', $response->json('data'));
    }

    #[Test]
    public function logo_endpoint_streams_with_cache_control_private(): void
    {
        $id = $this->newDraft()['id'];
        $bytes = $this->pngBytes();
        $this->uploadLogo($id, $this->realUpload('logo.png', $bytes))->assertOk();

        $response = $this->get(self::DRAFTS . "/{$id}/logo")->assertOk();

        $this->assertSame($bytes, $response->streamedContent());
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline', (string) $response->headers->get('Content-Disposition'));

        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);

        // A draft with no logo is a 404 in the JSON envelope, not an empty 200.
        $bare = $this->newDraft()['id'];
        $this->getJson(self::DRAFTS . "/{$bare}/logo")->assertNotFound();
    }

    #[Test]
    public function svg_and_renamed_text_are_refused_by_sniffed_type(): void
    {
        $id = $this->newDraft()['id'];

        $svg = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="200" height="200">'
            . '<script>alert(1)</script><rect width="200" height="200" fill="#01B151"/></svg>';
        $uploads = [
            'an SVG' => $this->realUpload('logo.svg', $svg),
            'an SVG renamed .png' => $this->realUpload('logo.png', $svg),
            'text renamed .png' => $this->realUpload('logo.png', "not an image at all\n"),
        ];

        foreach ($uploads as $what => $file) {
            $this->uploadLogo($id, $file)
                ->assertStatus(422)
                ->assertJsonPath('status', 'failed')
                ->assertJsonValidationErrors(['logo'], 'data');
        }

        $this->assertSame([], $this->storedLogos());
        $this->assertNull($this->getJson(self::DRAFTS . "/{$id}")->json('data.logo'));
    }

    #[Test]
    public function replacing_a_logo_deletes_the_old_bytes(): void
    {
        $id = $this->newDraft()['id'];

        $this->uploadLogo($id, $this->realUpload('first.png', $this->pngBytes(200, 200)))->assertOk();
        $first = StudioDraft::findOrFail($id)->logo_path;

        $this->uploadLogo($id, $this->realUpload('second.png', $this->pngBytes(300, 300)))
            ->assertOk()
            ->assertJsonPath('data.logo.original_name', 'second.png')
            ->assertJsonPath('data.logo.width', 300);
        $second = StudioDraft::findOrFail($id)->logo_path;

        $this->assertNotSame($first, $second);
        $this->assertSame([$second], $this->storedLogos(), 'the first logo is gone from the disk');
    }

    #[Test]
    public function a_logo_that_is_too_small_is_refused_and_a_wide_one_is_flagged(): void
    {
        $id = $this->newDraft()['id'];

        $this->uploadLogo($id, $this->realUpload('tiny.png', $this->pngBytes(64, 64)))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['logo'], 'data');

        $this->patchDraft($id, 0, ['brand' => [
            'primary_color' => '#01B151', 'secondary_color' => '#1B1B2E', 'accent_color' => '#FFBA63', 'background_color' => '#F3F8FB',
        ]])->assertOk();

        $this->uploadLogo($id, $this->realUpload('wide.png', $this->pngBytes(1000, 250)))
            ->assertOk()
            ->assertJsonPath('data.palette.aspect_warning', true);
    }
}
