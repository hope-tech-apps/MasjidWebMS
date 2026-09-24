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
 * favicon. The files go to `storage/app/private/studio-tmp/{draft}-{random}/`,
 * which is not served; medialibrary copies them to the public disk inside the
 * transaction, and StudioProvisioning deletes the directory afterwards,
 * committed or not.
 *
 * An instance from the container, not static, so a test can stand in for it at
 * the one moment between validation and the draft's lock.
 */
class LogoDerivatives
{
    /**
     * @throws RuntimeException when the draft's logo bytes are gone
     */
    public function generate(StudioDraft $draft, string $backgroundColor): LogoFiles
    {
        $bytes = $draft->hasLogo() ? $draft->logoStorage()->get($draft->logo_path) : null;

        if (! is_string($bytes) || $bytes === '') {
            throw new RuntimeException("Studio draft {$draft->id} has no logo bytes to derive from.");
        }

        $directory = storage_path('app/private/studio-tmp/' . $draft->id . '-' . Str::lower(Str::random(16)));
        File::ensureDirectoryExists($directory, 0700);

        try {
            $logo = $directory . '/logo.' . ($draft->logo_mime_type === 'image/jpeg' ? 'jpg' : 'png');
            file_put_contents($logo, $bytes);

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
