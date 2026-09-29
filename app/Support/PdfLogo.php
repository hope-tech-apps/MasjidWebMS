<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * An organisation's logo as a data URI for a PDF, or nothing (the image
 * decoding audit, 2026-09-29).
 *
 * Every PDF this application renders embeds exactly one image, the logo, and
 * each renderer decodes it: mPDF (report cards) through GD for a PNG with
 * alpha, interlacing or gamma, a GIF or a WebP; dompdf (receipts, statements)
 * for anything but a JPEG. Production's GD is the system libgd, whose pixel
 * buffers are allocated outside PHP's memory_limit, and a report card is
 * re-rendered on every download by any parent or teacher.
 *
 * So the logo passes one door before it is read:
 *
 *  - a file-size cap (MAX_BYTES). A logo upload may be 25 MB, and embedding
 *    one means holding it as base64 (a third larger) in the HTML and again
 *    inside the renderer, which can exhaust memory_limit on its own, whatever
 *    the pixels.
 *  - (next) the shared pre-decode headroom check being built for Studio's logo
 *    paths, once it is on main: dimensions from the header against an edge
 *    cap and the memory the box has free.
 *
 * On refusal the caller prints without a logo (the documents already fall back
 * to the organisation's name), and a warning says which file was left out, at
 * most once an hour per file, since production logs nothing below warning and
 * a parent's repeated downloads should not repeat the line.
 */
final class PdfLogo
{
    /** A logo is drawn about 52pt tall; 2 MB is far more than any sensible one. */
    public const MAX_BYTES = 2 * 1024 * 1024;

    /**
     * @param  string  $purpose  which document, for the warning ("report card", "receipt letterhead")
     * @param  array<string, mixed>  $context  ids for the warning
     */
    public static function dataUri(string $path, string $mime, string $purpose, array $context = []): ?string
    {
        if (! is_readable($path)) {
            return null;
        }

        $bytes = @filesize($path);

        if ($bytes === false || $bytes > self::MAX_BYTES) {
            if (Cache::add('pdf-logo:too-large:' . sha1($path), true, now()->addHour())) {
                Log::warning("A logo was left out of a {$purpose}: the file is larger than a PDF may embed.", $context + [
                    'file' => basename($path),
                    'bytes' => $bytes === false ? null : $bytes,
                    'limit' => self::MAX_BYTES,
                ]);
            }

            return null;
        }

        return 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($path));
    }
}
