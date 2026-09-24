<?php

namespace App\Support\Studio;

use App\Models\Masjid;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * The logo and its derivatives, into the new organisation's public media
 * collections: `logos` (what every client already reads as logo_url),
 * `favicons`, `touch_icons` and `share_images`.
 *
 * `preservingOriginal()` IS MANDATORY. Without it medialibrary's FileAdder
 * deletes the source file once it has copied it, and a provision that then
 * rolled back could not be retried from the same files. The sources are
 * StudioProvisioning's temporary directory, which it deletes itself.
 *
 * A Media row is saved before its file is copied, and a rollback removes the
 * row but not the file; StudioProvisioning deletes the directory of every Media
 * row the organisation had when a failure is caught.
 */
final class ApplyDraftLogo
{
    /** @return list<Media> */
    public static function apply(Masjid $masjid, LogoFiles $files): array
    {
        $extension = pathinfo($files->logo, PATHINFO_EXTENSION);

        return [
            self::add($masjid, $files->logo, 'logos', "logo.{$extension}"),
            self::add($masjid, $files->favicon, Masjid::FAVICONS, 'favicon.png'),
            self::add($masjid, $files->touchIcon, Masjid::TOUCH_ICONS, 'touch-icon.png'),
            self::add($masjid, $files->shareImage, Masjid::SHARE_IMAGES, 'share-image.png'),
        ];
    }

    private static function add(Masjid $masjid, string $path, string $collection, string $fileName): Media
    {
        return $masjid->addMedia($path)
            ->preservingOriginal()
            ->usingFileName($fileName)
            ->usingName(pathinfo($fileName, PATHINFO_FILENAME))
            ->toMediaCollection($collection);
    }
}
