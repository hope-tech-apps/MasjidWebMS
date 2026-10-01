<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The upload ceiling PHP actually enforces must cover what the app advertises.
 *
 * `public/.user.ini` is read per-directory by PHP-FPM and OVERRIDES the server's
 * php.ini. It ships in the repo, so it is the real limit on every deploy — and it
 * is invisible from `config/groups.php`, which is where a developer raising a file
 * size looks. On 2026-09-24 the two disagreed (config promised 100 MB, the ini
 * allowed 30 MB) and the failure was a bare 413 with nothing in any application
 * log: the request never reached PHP's userland at all.
 *
 * This test is the only thing that ties them together.
 */
class UploadCeilingTest extends TestCase
{
    #[Test]
    public function php_accepts_the_largest_request_the_media_config_promises(): void
    {
        $ini = parse_ini_file(base_path('public/.user.ini'));

        $this->assertIsArray($ini, 'public/.user.ini must be parseable — PHP reads it on every request');

        $uploadKb = $this->toKilobytes($ini['upload_max_filesize'] ?? '0');
        $postKb = $this->toKilobytes($ini['post_max_size'] ?? '0');

        $videoKb = (int) config('groups.media.video.max_size_kb');
        $imageKb = (int) config('groups.media.max_size_kb');
        $images = (int) config('groups.media.max_per_post');
        $videos = (int) config('groups.media.video.max_per_post');

        $this->assertGreaterThanOrEqual($videoKb, $uploadKb,
            'upload_max_filesize is below the video size the app offers — uploads die as a 413 before any code runs');
        $this->assertGreaterThanOrEqual($imageKb, $uploadKb,
            'upload_max_filesize is below the image size the app offers');

        // The worst legal body is every slot filled at once, plus room for the
        // fields and multipart boundaries. The videos of one post are bounded
        // by their COUNT and by their combined size (`max_total_kb`, 0 = off);
        // whichever is smaller is what a legal request can carry.
        $totalKb = (int) config('groups.media.video.max_total_kb');
        $videosKb = $totalKb > 0 ? min($videoKb * $videos, $totalKb) : $videoKb * $videos;
        $worstCaseKb = $videosKb + ($imageKb * $images);

        $this->assertGreaterThanOrEqual($worstCaseKb, $postKb,
            'post_max_size cannot carry one full post (videos + images); a teacher attaching both gets a 413');

        // Fields and multipart boundaries need room too: a body that is exactly
        // post_max_size is refused. 4MB is far more than a post's text.
        $this->assertGreaterThanOrEqual($worstCaseKb + 4096, $postKb,
            'post_max_size leaves no room beside a full post for its fields and multipart boundaries');
    }

    #[Test]
    public function one_full_size_video_still_fits_inside_the_combined_total(): void
    {
        $totalKb = (int) config('groups.media.video.max_total_kb');

        if ($totalKb > 0) {
            $this->assertGreaterThanOrEqual((int) config('groups.media.video.max_size_kb'), $totalKb,
                'max_total_kb is below max_size_kb: one clip of the advertised size would be refused by the total');
        }
    }

    /** Accept the ini shorthand (K/M/G) PHP itself accepts. */
    private function toKilobytes(string $value): int
    {
        $value = trim($value);
        $unit = strtoupper(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'G' => $number * 1024 * 1024,
            'M' => $number * 1024,
            'K' => $number,
            default => (int) ($number / 1024),
        };
    }
}
