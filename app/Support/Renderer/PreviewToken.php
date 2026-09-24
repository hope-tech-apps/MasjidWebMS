<?php

namespace App\Support\Renderer;

use InvalidArgumentException;

/**
 * Mints the token that lets the renderer show one organisation's site in preview mode.
 *
 *     v1.<base64url(JSON claims)>.<base64url(HMAC-SHA256(secret, "manara-preview|v1|" + payload))>
 *
 * Claims: o (organisation id), s (surface: pages|theme|splash), p (the one path it may
 * render), a (the admin origin allowed to frame it), e (expiry, unix seconds).
 *
 * The renderer verifies it (burlington-masjid-site shared/previewToken.ts). Both sides
 * pin the same vector (tests/Unit/PreviewTokenVectorTest.php and the renderer's
 * tests/preview-token.test.ts), so the two implementations cannot drift apart silently.
 * The purpose prefix inside the MAC keeps a preview token from ever passing as a purge
 * signature, which uses the same key (RendererCachePurge).
 */
final class PreviewToken
{
    public const SURFACES = ['pages', 'theme', 'splash'];

    /** Seconds. Long enough to load a frame; the renderer refuses anything over 900. */
    public const TTL_SECONDS = 300;

    public static function mint(
        string $secret,
        int $organisationId,
        string $surface,
        string $path,
        string $adminOrigin,
        int $expiresAt,
    ): string {
        if ($organisationId < 1 || ! in_array($surface, self::SURFACES, true) || ! self::isSafePath($path)) {
            throw new InvalidArgumentException('Invalid preview token claims.');
        }
        if (RendererConfig::origin($adminOrigin) !== $adminOrigin) {
            throw new InvalidArgumentException('Invalid preview admin origin.');
        }

        $payload = self::base64Url(json_encode([
            'o' => $organisationId,
            's' => $surface,
            'p' => $path,
            'a' => $adminOrigin,
            'e' => $expiresAt,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        $signature = self::base64Url(hash_hmac('sha256', 'manara-preview|v1|'.$payload, $secret, true));

        return "v1.{$payload}.{$signature}";
    }

    /**
     * A path the renderer may be asked to render: absolute, not protocol-relative, no
     * backslash, no dot segment (plain or percent-encoded), no query, fragment,
     * whitespace or control character, at most 512 characters. The renderer applies the
     * same rule (isSafePreviewPath) before it touches the request path.
     */
    public static function isSafePath(mixed $path): bool
    {
        if (! is_string($path) || $path === '' || strlen($path) > 512) {
            return false;
        }
        if (! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return false;
        }
        if (preg_match('/[\\\\?#\s\x00-\x1f\x7f]/', $path) === 1) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if (in_array(strtolower($segment), ['.', '..', '%2e', '%2e%2e', '.%2e', '%2e.'], true)) {
                return false;
            }
        }

        return true;
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
