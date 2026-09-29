<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * An architecture test from the image decoding audit (2026-09-29).
 *
 * Today no upload is ever decoded by the media library: no model registers a
 * conversion and nothing asks for responsive images, so a 25 MB logo is stored
 * and served as it came. The moment one does, every upload into that model is
 * decoded by spatie/image through GD, whose buffers are the system libgd's,
 * outside memory_limit, and a small file can declare an enormous picture.
 *
 * So a conversion (or responsive images) may only be added together with a
 * `dimensions:` rule on every request that uploads into that model. This test
 * fails the day one appears without it, and says what to do.
 */
class MediaConversionsNeedDimensionsTest extends TestCase
{
    /**
     * Each file (relative to app/) that registers conversions or responsive
     * images => the request classes (relative to app/) that upload into it.
     * Empty today: nothing converts.
     *
     * @var array<string, list<string>>
     */
    private const DECODING_UPLOADS = [];

    private const DECODES = ['registerMediaConversions', 'addMediaConversion', 'withResponsiveImages'];

    /** @return array<string, string> path relative to app/ => source */
    private function appSources(): array
    {
        $root = app_path();
        $sources = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $sources[substr($file->getPathname(), strlen($root) + 1)] = (string) file_get_contents($file->getPathname());
            }
        }

        return $sources;
    }

    #[Test]
    public function no_upload_is_decoded_by_the_media_library_without_a_dimensions_rule(): void
    {
        $sources = $this->appSources();

        // The premise: the scan reaches the models that hold media at all.
        $withMedia = array_filter($sources, fn (string $source) => str_contains($source, 'InteractsWithMedia'));
        $this->assertGreaterThanOrEqual(5, count($withMedia), 'the scan found the models that use the media library');

        foreach ($sources as $path => $source) {
            $decodes = array_values(array_filter(self::DECODES, fn (string $token) => str_contains($source, $token)));

            if ($decodes === []) {
                continue;
            }

            $this->assertArrayHasKey($path, self::DECODING_UPLOADS, sprintf(
                "app/%s uses %s, so the media library will decode every upload into it through GD, outside memory_limit. "
                    . 'Add a `dimensions:` rule to each request that uploads into it, then list them for this file in '
                    . '%s::DECODING_UPLOADS.',
                $path,
                implode(', ', $decodes),
                self::class,
            ));

            $this->assertNotEmpty(self::DECODING_UPLOADS[$path], "app/{$path}: name the requests that upload into it.");

            foreach (self::DECODING_UPLOADS[$path] as $request) {
                $this->assertArrayHasKey($request, $sources, "app/{$request} (listed for app/{$path}) exists");
                $this->assertStringContainsString('dimensions:', $sources[$request], "app/{$request} bounds the picture it uploads into app/{$path}");
            }
        }

        // A listed file that no longer decodes is stale: take it off the list.
        foreach (array_keys(self::DECODING_UPLOADS) as $path) {
            $this->assertArrayHasKey($path, $sources, "app/{$path} is listed but does not exist");
            $this->assertNotEmpty(array_filter(self::DECODES, fn (string $token) => str_contains($sources[$path], $token)), "app/{$path} is listed but no longer decodes anything");
        }
    }
}
