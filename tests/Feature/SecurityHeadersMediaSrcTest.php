<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The admin SPA's own Content-Security-Policy must let its <video> previews play.
 *
 * The video section editor (VideoSectionEditor.vue) previews a chosen MP4 as a `blob:`
 * object URL, and a stored one at APP_URL/storage/... With no `media-src`, media fell
 * back to `default-src 'self'`, which never matches `blob:` (CSP needs the scheme named)
 * and, on the second admin host, does not name APP_URL. Both previews were refused and
 * the editor never played a clip. The failure is silent: a black box, and a console line.
 */
class SecurityHeadersMediaSrcTest extends TestCase
{
    /** The response the admin editor is actually served: the SPA shell. */
    private function spaPolicy(string $url): string
    {
        $response = $this->withoutVite()->get($url);
        $response->assertOk();
        $this->assertStringContainsString('id="app"', (string) $response->getContent(), 'this is the SPA shell');

        return (string) $response->headers->get('Content-Security-Policy');
    }

    /** One directive's value, or '' when the policy lacks it. */
    private function directive(string $policy, string $name): string
    {
        foreach (explode(';', $policy) as $part) {
            $part = trim($part);
            if (str_starts_with($part, $name . ' ')) {
                return $part;
            }
        }

        return '';
    }

    #[Test]
    public function the_spa_lets_a_video_preview_play_from_a_blob_url_and_this_origin(): void
    {
        config(['app.url' => 'https://masjid.hopetechapps.com']);

        $media = $this->directive($this->spaPolicy('https://masjid.hopetechapps.com/pages'), 'media-src');

        $this->assertSame("media-src 'self' blob:", $media);
    }

    #[Test]
    public function on_a_second_host_a_stored_video_at_app_url_may_play_too(): void
    {
        // A stored clip's URL is absolute to APP_URL (the public disk), which 'self'
        // does not cover when the SPA is served from another hostname.
        config(['app.url' => 'https://masjid.hopetechapps.com']);

        $media = $this->directive($this->spaPolicy('https://manara.hopetechapps.com/pages'), 'media-src');

        $this->assertSame("media-src 'self' blob: https://masjid.hopetechapps.com", $media);
    }
}
