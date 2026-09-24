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
     * A path the renderer may be asked to render. It becomes the renderer's request path,
     * so anything looser is a request-smuggling surface.
     *
     * The canonical form is the DECODED path, as the page's slug reads (`/حول`): that is
     * what is signed, and h3 decodes the request path before the renderer compares them.
     * The URL carries an encoded copy (encodePath()).
     *
     * CHARACTER FOR CHARACTER THE RENDERER'S RULE (shared/previewToken.ts
     * isSafePreviewPath): absolute, not protocol-relative, at most 512 UTF-16 code units
     * (JavaScript's length), no dot segment, and none of `\ ? # %`, ASCII controls, or the
     * Unicode whitespace JavaScript's `\s` matches, written out: PCRE's `\s` is ASCII-only,
     * and the two sides once disagreed on U+00A0. `%` is refused because h3 keeps an encoded
     * `%25` encoded while decoding the rest, so such a slug has no form both sides agree on.
     */
    public const FORBIDDEN = '/[\\\\?#%\x{0000}-\x{0020}\x{007f}\x{00a0}\x{1680}\x{2000}-\x{200a}\x{2028}\x{2029}\x{202f}\x{205f}\x{3000}\x{feff}]/u';

    public static function isSafePath(mixed $path): bool
    {
        if (! is_string($path) || $path === '' || ! mb_check_encoding($path, 'UTF-8')) {
            return false;
        }
        if (strlen(mb_convert_encoding($path, 'UTF-16LE', 'UTF-8')) / 2 > 512) {
            return false;
        }
        if (! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return false;
        }
        if (preg_match(self::FORBIDDEN, $path) !== 0) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    /** The URL form of a safe path: every segment percent-encoded, the slashes kept. */
    public static function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
