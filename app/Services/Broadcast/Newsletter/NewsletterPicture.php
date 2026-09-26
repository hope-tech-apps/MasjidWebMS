<?php

namespace App\Services\Broadcast\Newsletter;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Spatie\Image\Enums\Fit;
use Spatie\Image\Enums\ImageDriver;
use Spatie\Image\Image;

/**
 * Turns an uploaded newsletter picture into the file that is stored and emailed.
 *
 * Every picture is decoded and written out again before it is stored, and the
 * original upload is never kept. That one step does two jobs:
 *
 *  - PRIVACY. A phone photo carries EXIF: where it was taken (GPS), when, and on
 *    which device. The stored file is public and its address goes into every
 *    recipient's email, so the metadata would be published with it — for photos
 *    like "children at the bouncy castle". A re-encoded image carries only
 *    pixels. The file is also stored under a generated name, because the
 *    original name ("aisha-and-yusuf-eid.jpg") would otherwise be part of that
 *    public address.
 *  - WEIGHT. An upload may be 8 MB and a newsletter can carry ten. The email
 *    shows a picture at most CONTENT_WIDTH (552px) wide, so the stored copy is
 *    at most twice that, for sharp high-density screens, at JPEG/WebP quality 80.
 *
 * Why not a Spatie media conversion: a conversion is written NEXT TO the
 * original, under a name derived from it (`{name}-email.jpg`), so the untouched
 * original — metadata included — would stay public one edit away from the
 * address in the email. Re-encoding before storage leaves nothing to find.
 *
 * Orientation is applied from the EXIF before it is dropped (Spatie's GD driver
 * auto-rotates on load), so a portrait phone photo stays upright.
 *
 * An ANIMATED GIF is stored as uploaded, under a generated name: GD keeps only
 * the first frame, and a GIF has no EXIF block to strip.
 */
final class NewsletterPicture
{
    /** Twice NewsletterRenderer::CONTENT_WIDTH: sharp on high-density screens, no wider. */
    public const MAX_WIDTH = 1104;

    public const QUALITY = 80;

    /**
     * The largest picture that will be decoded, in pixels (36 megapixels, e.g.
     * 6000 × 6000). Checked from the file's header BEFORE decoding, because GD
     * holds every pixel in memory: a 200-megapixel phone original, or a tiny
     * PNG that merely declares huge dimensions, would otherwise exhaust PHP's
     * memory in the middle of a send. Covers every ordinary phone photo (12–24
     * megapixels) and a letter-size scan at 600 dpi (~34 megapixels).
     */
    public const MAX_PIXELS = 36_000_000;

    /**
     * Memory to reserve per pixel while decoding: GD's truecolour buffer (4
     * bytes) plus a second buffer when a photo is rotated upright. An estimate
     * with headroom, not a measurement of GD internals.
     */
    private const BYTES_PER_PIXEL = 9;

    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    /** Why this upload cannot be used, worded for the admin, or null when it can. */
    public static function problem(UploadedFile $file): ?string
    {
        $size = @getimagesize((string) $file->getRealPath());

        if ($size === false || ! isset(self::EXTENSIONS[$size['mime'] ?? ''])) {
            return 'A picture could not be read. Choose it again, or save it as a JPEG or PNG first.';
        }

        $pixels = (int) $size[0] * (int) $size[1];
        if ($pixels > self::MAX_PIXELS) {
            return 'A picture is ' . (int) round($pixels / 1_000_000) . ' megapixels, which is too large to send. '
                . 'Save a smaller copy (6000 × 6000 pixels at most) and choose it again.';
        }

        return null;
    }

    /**
     * Write the picture that will be stored: re-encoded, metadata gone, at most
     * MAX_WIDTH wide. Returns a temporary file (which Spatie moves into the
     * media disk) and the generated name to store it under.
     *
     * @return array{path: string, name: string}
     */
    public static function prepare(UploadedFile $file): array
    {
        $source = (string) $file->getRealPath();
        $size = getimagesize($source);
        $extension = self::EXTENSIONS[$size['mime'] ?? ''] ?? 'jpg';
        $name = Str::uuid()->toString() . '.' . $extension;
        $target = sys_get_temp_dir() . '/newsletter-' . $name;

        if ($extension === 'gif' && self::isAnimatedGif($source)) {
            copy($source, $target);

            return ['path' => $target, 'name' => $name];
        }

        $limit = ini_get('memory_limit');
        $needed = memory_get_usage(true) + (int) $size[0] * (int) $size[1] * self::BYTES_PER_PIXEL + 32 * 1024 * 1024;
        if ($limit !== false && $limit !== '-1' && self::bytes($limit) < $needed) {
            ini_set('memory_limit', (string) $needed);
        }

        try {
            Image::useImageDriver(ImageDriver::Gd)
                ->loadFile($source)
                ->fit(Fit::Max, self::MAX_WIDTH)
                ->quality(self::QUALITY)
                ->save($target);
        } finally {
            if ($limit !== false) {
                ini_set('memory_limit', $limit);
            }
        }

        return ['path' => $target, 'name' => $name];
    }

    /** More than one frame: a GIF with more than one image descriptor after a graphic control block. */
    private static function isAnimatedGif(string $path): bool
    {
        $bytes = (string) file_get_contents($path);

        return preg_match_all('/\x00\x21\xF9\x04.{4}\x00[\x2C\x21]/s', $bytes) > 1;
    }

    /** php.ini shorthand ("128M") in bytes. */
    private static function bytes(string $value): int
    {
        $value = trim($value);
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
