<?php

namespace App\Support\Studio;

use App\Models\StudioDraft;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Image\Enums\AlignPosition;
use Spatie\Image\Enums\Fit;
use Spatie\Image\Image;
use Throwable;

/**
 * The brand images a website needs, made from the draft's logo before the
 * provision transaction opens (docs/manara-studio-w1.md S8):
 *
 *  - favicon: 48x48, the logo contained, TRANSPARENT padding, so it sits on
 *    any browser tab;
 *  - touch icon: 180x180, the logo contained in 144x144 on an OPAQUE
 *    background colour, because iOS fills a transparent home-screen icon with
 *    black;
 *  - share image: 1200x630 on the background colour, the logo contained in
 *    720x360 at the centre, the card size link previews crop to.
 *
 * spatie/image on GD (config/media-library.php's image_driver), as the rest of
 * the app. No ICO and no SVG: GD writes neither, and every browser takes a PNG
 * favicon. The files go to `storage/app/private/studio-tmp/{draft}-{random}/`
 * (or `org-{random}/` for BrandAssets::regenerate), which is not served;
 * medialibrary copies them to the public disk inside the transaction, and the
 * caller (StudioProvisioning, BrandAssets) deletes the directory afterwards,
 * committed or not.
 *
 * An instance from the container, not static, so a test can stand in for it at
 * the one moment between validation and the draft's lock.
 */
class LogoDerivatives
{
    /**
     * The longest edge, in pixels, of a logo any path here will decode. The
     * Studio logo upload's `dimensions` rule reads this same constant, so the
     * two cannot drift.
     */
    public const MAX_EDGE = 8000;

    /**
     * What decoding costs, per pixel of the source: GD holds it at ~4 bytes
     * plus row overhead, and the resize makes a copy of the contained size
     * (small), so 5 is 4 with a margin.
     */
    private const BYTES_PER_PIXEL = 5;

    /**
     * On top of that: the 1200x630 and 180x180 canvases, which are alive at the
     * same time as the source (about 3 MB and 0.1 MB at 4 bytes a pixel, each
     * copied once by the resize), and the encoder's buffers.
     */
    private const FIXED_ALLOWANCE_BYTES = 8 * 1024 * 1024;

    /**
     * TEST SEAM. When set, this many bytes is the memory left, instead of the
     * ini `memory_limit` minus what this process holds. A test sets it to make a
     * small logo not fit without touching the real limit, and must reset it to
     * null (BrandAssetRegenerationTest does, in setUp and tearDown).
     */
    public static ?int $headroomBytes = null;

    /**
     * @throws RuntimeException when the draft's logo bytes are gone
     */
    public function generate(StudioDraft $draft, string $backgroundColor): LogoFiles
    {
        $bytes = $draft->hasLogo() ? $draft->logoStorage()->get($draft->logo_path) : null;

        if (! is_string($bytes) || $bytes === '') {
            throw new RuntimeException("Studio draft {$draft->id} has no logo bytes to derive from.");
        }

        return $this->derive(
            (string) $draft->id,
            $draft->logo_mime_type === 'image/jpeg' ? 'jpg' : 'png',
            fn (string $logo) => file_put_contents($logo, $bytes),
            $backgroundColor,
        );
    }

    /**
     * The same three images from a logo already on disk, for an organisation
     * that exists (Studio W2 S8, BrandAssets::regenerate). The source is copied
     * into the temporary directory as `logo.{png|jpg}` and never modified.
     *
     * The size is read from the header and checked BEFORE anything is decoded
     * (assertFits): every caller, both admin upload hooks and the regenerate
     * route, comes through here.
     *
     * @throws RuntimeException when the file is not a PNG or JPEG
     * @throws LogoTooLarge when it has too many pixels to decode safely
     */
    public function fromFile(string $absPath, string $backgroundColor): LogoFiles
    {
        $info = is_file($absPath) ? @getimagesize($absPath) : false;
        $extension = match ($info[2] ?? null) {
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_JPEG => 'jpg',
            default => throw new RuntimeException('The logo is not a PNG or JPEG image.'),
        };

        self::assertFits((int) $info[0], (int) $info[1]);

        return $this->derive(
            'org',
            $extension,
            fn (string $logo) => copy($absPath, $logo) ?: throw new RuntimeException('The logo could not be copied.'),
            $backgroundColor,
        );
    }

    /**
     * The memory left for this request, in bytes, or null when there is no
     * limit. The ini limit minus what the process holds now, so a request that
     * is already heavy has less to spend.
     */
    public static function headroomBytes(): ?int
    {
        if (self::$headroomBytes !== null) {
            return self::$headroomBytes;
        }

        $limit = self::bytesFromIni((string) ini_get('memory_limit'));

        return $limit === null ? null : $limit - memory_get_usage(true);
    }

    /**
     * An ini size ("128M", "1G", "512K", "134217728") in bytes; null for -1,
     * which is no limit. A value that is not a size is 0: a guard that cannot
     * read its limit refuses, it does not wave everything through.
     */
    public static function bytesFromIni(string $value): ?int
    {
        $value = trim($value);

        if ($value === '-1') {
            return null;
        }

        if (preg_match('/^(\d+)\s*([KMG]?)$/i', $value, $m) !== 1) {
            return 0;
        }

        return (int) $m[1] * match (strtoupper($m[2])) {
            'K' => 1024,
            'M' => 1024 ** 2,
            'G' => 1024 ** 3,
            default => 1,
        };
    }

    /**
     * Refuse a logo the edge cap or the memory left cannot take. The edge cap
     * alone is not enough: 8000x8000 needs ~256 MB and production PHP-FPM has
     * 128M.
     *
     * @throws LogoTooLarge
     */
    private static function assertFits(int $width, int $height): void
    {
        if ($width > self::MAX_EDGE || $height > self::MAX_EDGE) {
            throw new LogoTooLarge($width, $height, LogoTooLarge::EDGE, self::MAX_EDGE);
        }

        $headroom = self::headroomBytes();

        if ($headroom !== null && $width * $height * self::BYTES_PER_PIXEL + self::FIXED_ALLOWANCE_BYTES > $headroom) {
            // The longest square side the headroom would take, to the nearest
            // hundred down: a number the SuperAdmin can act on.
            $side = (int) floor(sqrt(max(0, $headroom - self::FIXED_ALLOWANCE_BYTES) / self::BYTES_PER_PIXEL));

            throw new LogoTooLarge($width, $height, LogoTooLarge::MEMORY, min(self::MAX_EDGE, intdiv($side, 100) * 100));
        }
    }

    /**
     * The image code both entry points share: write the logo into a fresh
     * directory under `studio-tmp/`, then the favicon, touch icon and share
     * image beside it. A failure deletes the directory.
     *
     * @param  callable(string): mixed  $writeLogo  writes the source to the path it is given
     */
    private function derive(string $prefix, string $extension, callable $writeLogo, string $backgroundColor): LogoFiles
    {
        $directory = storage_path('app/private/studio-tmp/' . $prefix . '-' . Str::lower(Str::random(16)));
        File::ensureDirectoryExists($directory, 0700);

        try {
            $logo = $directory . '/logo.' . $extension;
            $writeLogo($logo);

            $files = new LogoFiles(
                directory: $directory,
                logo: $logo,
                favicon: $directory . '/favicon.png',
                touchIcon: $directory . '/touch-icon.png',
                shareImage: $directory . '/share-image.png',
            );

            $this->image($logo)
                ->fit(Fit::Contain, 48, 48)
                ->resizeCanvas(48, 48, AlignPosition::Center, false, 'rgba(0, 0, 0, 0)')
                ->save($files->favicon);

            $this->onBackground($logo, 180, 180, 144, 144, $backgroundColor, $files->touchIcon);
            $this->onBackground($logo, 1200, 630, 720, 360, $backgroundColor, $files->shareImage);

            return $files;
        } catch (Throwable $e) {
            File::deleteDirectory($directory);

            throw $e;
        }
    }

    /**
     * The logo contained in `$innerW` x `$innerH`, centred on a `$width` x
     * `$height` canvas of `$colour`. `background()` last, so the logo's own
     * transparent pixels become the colour too and the file is fully opaque.
     */
    private function onBackground(string $logo, int $width, int $height, int $innerW, int $innerH, string $colour, string $to): void
    {
        $this->image($logo)
            ->fit(Fit::Contain, $innerW, $innerH)
            ->resizeCanvas($width, $height, AlignPosition::Center, false, $colour)
            ->background($colour)
            ->save($to);
    }

    private function image(string $path): Image
    {
        return Image::useImageDriver((string) config('media-library.image_driver', 'gd'))->loadFile($path);
    }
}
