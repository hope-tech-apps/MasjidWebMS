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
    /** The policy on an ordinary page before live preview (SecurityHeaders at 4df5242). */
    private const BASELINE = "default-src 'self'; "
        ."script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net https://*.pusher.com https://js.pusher.com; "
        ."style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://fonts.bunny.net https://cdn.jsdelivr.net; "
        ."font-src 'self' https://fonts.gstatic.com https://fonts.bunny.net data:; "
        ."img-src 'self' data: blob: https://*.supabase.co https://*.supabase.in https://maps.gstatic.com https://maps.googleapis.com; "
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
    public function a_malformed_preview_origin_is_never_written_into_the_policy(): void
    {
        config([
            'services.renderer.secret' => str_repeat('k', 40),
            'services.renderer.preview_origin' => "https://x.dev; script-src *",
        ]);

        $this->assertSame(self::BASELINE, $this->policy());
    }
}
