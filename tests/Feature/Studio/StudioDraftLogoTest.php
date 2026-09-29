<?php

namespace Tests\Feature\Studio;

use App\Models\StudioDraft;
use App\Support\Studio\LogoDerivatives;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    #[Test]
    public function a_logo_provisioning_would_refuse_for_its_edge_is_refused_at_upload_and_not_stored(): void
    {
        $id = $this->newDraft()['id'];

        // A 45-byte PNG that claims 20000 x 20000: a valid signature, IHDR with
        // its CRC, IEND. The sentence is provisioning's own (LogoTooLarge), in the
        // legacy envelope, keyed on the upload field.
        $this->uploadLogo($id, $this->realUpload('huge.png', $this->headerOnlyPngBytes(20000, 20000)))
            ->assertStatus(422)
            ->assertExactJson(['status' => 'failed', 'data' => ['logo' => [
                // The smaller limit is suggested: the roomy 512 MiB seam takes a 6,556 px square.
                'This logo is 20,000 × 20,000 pixels. Upload one no larger than 6,500 × 6,500 (a PNG or JPEG).',
            ]]]);

        $this->assertSame([], $this->storedLogos(), 'nothing was written');
        $this->assertFalse(StudioDraft::findOrFail($id)->hasLogo());
    }

    #[Test]
    public function a_logo_over_the_memory_left_is_refused_at_upload_with_the_same_sentence_and_keeps_the_old_logo(): void
    {
        $id = $this->newDraft()['id'];
        $this->uploadLogo($id, $this->realUpload('ok.png', $this->pngBytes(300, 300)))->assertOk();
        $kept = StudioDraft::findOrFail($id)->logo_path;

        // The test seam for the memory left: 40 MiB, 3000 x 3000 needs 108 MB and
        // the 20 MiB allowance. What would fit is a 1,321 px square, said as 1300.
        LogoDerivatives::$headroomBytes = 40 * 1024 * 1024;

        $this->uploadLogo($id, $this->realUpload('big.png', $this->headerOnlyPngBytes(3000, 3000)))
            ->assertStatus(422)
            ->assertExactJson(['status' => 'failed', 'data' => ['logo' => [
                'This logo is 3,000 × 3,000 pixels. Upload one no larger than 1,300 × 1,300 (a PNG or JPEG).',
            ]]]);

        $this->assertSame([$kept], $this->storedLogos(), 'the draft keeps the logo it had, and the refused one was not stored');
        $this->assertSame($kept, StudioDraft::findOrFail($id)->logo_path);

        // The same logo with the room it needs is taken (a header-only file is
        // not a decodable PNG, but the upload never decodes: it reads the header).
        LogoDerivatives::$headroomBytes = self::ROOMY_HEADROOM_BYTES;
        $this->uploadLogo($id, $this->realUpload('big.png', $this->headerOnlyPngBytes(3000, 3000)))->assertOk();
    }

    #[Test]
    public function the_stored_extension_follows_the_sniffed_type_not_the_clients_name(): void
    {
        $id = $this->newDraft()['id'];

        // A real PNG named as a web page is stored, and served, as a PNG.
        $this->uploadLogo($id, $this->realUpload('logo.html', $this->pngBytes()))
            ->assertOk()
            ->assertJsonPath('data.logo.mime_type', 'image/png');
        $this->assertMatchesRegularExpression('#\.png$#', StudioDraft::findOrFail($id)->logo_path);
        $this->assertStringContainsString(
            "draft-{$id}-logo.png",
            (string) $this->get(self::DRAFTS . "/{$id}/logo")->assertOk()->headers->get('Content-Disposition'),
        );

        // A JPEG named .png is a JPEG.
        $image = imagecreatetruecolor(200, 200);
        ob_start();
        imagejpeg($image);
        $jpeg = (string) ob_get_clean();

        $this->uploadLogo($id, $this->realUpload('logo.png', $jpeg))
            ->assertOk()
            ->assertJsonPath('data.logo.mime_type', 'image/jpeg');
        $this->assertMatchesRegularExpression('#\.jpg$#', StudioDraft::findOrFail($id)->logo_path);
    }

    #[Test]
    public function an_upload_clears_files_nothing_points_at_from_the_drafts_directory(): void
    {
        $id = $this->newDraft()['id'];
        $other = $this->newDraft()['id'];
        $this->uploadLogo($other, $this->realUpload('theirs.png', $this->pngBytes()))->assertOk();
        $theirs = StudioDraft::findOrFail($other)->logo_path;

        // What an overlapping double-submit, or an old logo whose delete
        // failed, leaves behind: a file in the draft's directory no row names.
        $disk = Storage::disk((string) config('studio.logo.disk'));
        $disk->put("studio-drafts/{$id}/" . str_repeat('b', 40) . '.png', $this->pngBytes());

        $this->uploadLogo($id, $this->realUpload('logo.png', $this->pngBytes()))->assertOk();
        $ours = StudioDraft::findOrFail($id)->logo_path;

        $this->assertEqualsCanonicalizing([$ours, $theirs], $this->storedLogos(), 'the stray went; another draft\'s logo did not');
    }

    #[Test]
    public function an_upload_racing_a_discard_or_a_provision_leaves_no_file_behind(): void
    {
        $orgId = $this->org()->id;

        $cases = [
            // Another tab discards the draft once this upload has found it.
            'discarded' => [404, fn (int $id) => DB::table('studio_drafts')->where('id', $id)->delete()],
            // Step 3 provisions it in the same window.
            'provisioned' => [409, fn (int $id) => DB::table('studio_drafts')->where('id', $id)->update([
                'status' => StudioDraft::STATUS_PROVISIONED, 'provisioned_masjid_id' => $orgId, 'provisioned_at' => now(),
            ])],
        ];

        foreach ($cases as $what => [$status, $interleave]) {
            $id = $this->newDraft()['id'];

            $fired = false;
            StudioDraft::retrieved(function (StudioDraft $draft) use ($id, $interleave, &$fired) {
                if (! $fired && $draft->id === $id) {
                    $fired = true;
                    $interleave($id);
                }
            });

            $this->uploadLogo($id, $this->realUpload('logo.png', $this->pngBytes()))->assertStatus($status);

            $this->assertTrue($fired, $what);
            $this->assertSame([], $this->storedLogos(), "{$what}: no file is left for nothing to find");
        }

        $this->assertNull(StudioDraft::findOrFail($id)->logo_path, 'the provisioned draft kept the logo it had');
    }
}
