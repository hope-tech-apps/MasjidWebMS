<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The admin SPA may frame the renderer's preview origin — and only when the live
 * preview is configured (docs/live-preview.md, brief rule 4). The production twin: with
 * nothing configured, the Content-Security-Policy is byte-identical to the one this app
 * sent before the feature existed.
 */
class SecurityHeadersPreviewFrameTest extends TestCase
{
    /**
     * The policy on an ordinary page before live preview (SecurityHeaders at 4df5242),
     * plus the `media-src` the video section editor's preview needs
     * (SecurityHeadersMediaSrcTest). Live preview still adds nothing when unconfigured.
     */
    private const BASELINE = "default-src 'self'; "
        ."script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net https://*.pusher.com https://js.pusher.com; "
        ."style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://fonts.bunny.net https://cdn.jsdelivr.net; "
        ."font-src 'self' https://fonts.gstatic.com https://fonts.bunny.net data:; "
        ."img-src 'self' data: blob: https://*.supabase.co https://*.supabase.in https://maps.gstatic.com https://maps.googleapis.com; "
        ."media-src 'self' blob:; "
        ."connect-src 'self' https://*.supabase.co https://*.supabase.in https://*.pusher.com wss://*.pusher.com https://onesignal.com https://*.onesignal.com; "
        ."frame-src 'self' https://www.google.com https://maps.google.com; "
        ."frame-ancestors 'none'; form-action 'self'; base-uri 'self'; object-src 'none'; upgrade-insecure-requests";

    private function policy(): string
    {
        return (string) $this->get('/robots.txt')->headers->get('Content-Security-Policy');
    }

    #[Test]
    public function unconfigured_the_policy_is_byte_identical_to_before(): void
    {
        config(['services.renderer' => ['secret' => '', 'preview_origin' => '', 'purge_origins' => '', 'admin_origins' => '', 'timeout' => 5]]);

        $this->assertSame(self::BASELINE, $this->policy());
    }

    #[Test]
    public function a_preview_origin_without_a_secret_opens_nothing(): void
    {
        config(['services.renderer.secret' => '', 'services.renderer.preview_origin' => 'https://manara-renderer.pages.dev']);

        $this->assertSame(self::BASELINE, $this->policy());
    }

    #[Test]
    public function configured_frame_src_gains_exactly_the_preview_origin(): void
    {
        config([
            'services.renderer.secret' => str_repeat('k', 40),
            'services.renderer.preview_origin' => 'https://manara-renderer.pages.dev/',
        ]);

        $expected = str_replace(
            "frame-src 'self' https://www.google.com https://maps.google.com;",
            "frame-src 'self' https://www.google.com https://maps.google.com https://manara-renderer.pages.dev;",
            self::BASELINE,
        );
        $this->assertSame($expected, $this->policy());
        $this->assertStringContainsString("frame-ancestors 'none'", $this->policy(), 'the SPA itself still cannot be framed');
    }

    #[Test]
    public function a_second_host_names_this_apps_own_origin_and_keeps_the_preview_frame(): void
    {
        // manara.hopetechapps.com serves this app beside APP_URL's host. Its logos,
        // bundle and API are absolute URLs to APP_URL, so the policy must name that
        // origin there, exactly as on the proxied pages, and must still carry the
        // preview frame the page editors need.
        config([
            'app.url' => 'https://masjid.hopetechapps.com',
            'services.renderer.secret' => str_repeat('k', 40),
            'services.renderer.preview_origin' => 'https://preview.manara.hopetechapps.com',
        ]);

        $second = (string) $this->get('https://manara.hopetechapps.com/robots.txt')->headers->get('Content-Security-Policy');
        foreach (['script-src', 'style-src', 'font-src', 'img-src', 'media-src', 'connect-src'] as $directive) {
            $this->assertMatchesRegularExpression("#{$directive} [^;]* https://masjid\\.hopetechapps\\.com(;| )#", $second, "{$directive} names the app's own origin on the second host");
        }
        $this->assertStringContainsString('https://fonts.bunny.net', $second);
        $this->assertStringContainsString("frame-src 'self' https://www.google.com https://maps.google.com https://preview.manara.hopetechapps.com;", $second);

        $own = (string) $this->get('https://masjid.hopetechapps.com/robots.txt')->headers->get('Content-Security-Policy');
        $this->assertStringNotContainsString('img-src \'self\' data: blob: https://masjid.hopetechapps.com', $own, 'APP_URL\'s own host keeps the unwidened policy');
        $this->assertSame(
            str_replace("frame-src 'self' https://www.google.com https://maps.google.com;", "frame-src 'self' https://www.google.com https://maps.google.com https://preview.manara.hopetechapps.com;", self::BASELINE),
            $own,
        );
    }

    #[Test]
    public function a_malformed_preview_origin_is_never_written_into_the_policy(): void
    {
        config([
            'services.renderer.secret' => str_repeat('k', 40),
            'services.renderer.preview_origin' => "https://x.dev; script-src *",
        ]);

        $this->assertSame(self::BASELINE, $this->policy());
    }
}
