<?php

namespace Tests\Unit;

use App\Support\Renderer\PreviewToken;
use App\Support\Renderer\RendererCachePurge;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The API mints preview tokens and signs purges; the renderer verifies both. These two
 * vectors are asserted byte for byte on BOTH sides (the renderer's
 * tests/preview-token.test.ts and tests/page-cache-purge.test.ts), and were cross-checked
 * with a third, independent HMAC (Python's) on 2026-09-24. If either side changes the
 * format, one of the two suites goes red instead of every preview silently failing.
 */
class PreviewTokenVectorTest extends TestCase
{
    private const SECRET = 'vector-secret-0123456789abcdef-0123456789abcdef';

    #[Test]
    public function the_token_for_fixed_inputs_is_the_cross_language_vector(): void
    {
        $this->assertSame(
            'v1.eyJvIjoxMywicyI6InBhZ2VzIiwicCI6Ii9hYm91dCIsImEiOiJodHRwczovL21hc2ppZC5ob3BldGVjaGFwcHMuY29tIiwiZSI6MTc5MDAwMDAwMH0.cBkzaGl-IslKp6TuoyvGrt5p3O15mXfvueb1NK7LtAE',
            PreviewToken::mint(self::SECRET, 13, 'pages', '/about', 'https://masjid.hopetechapps.com', 1790000000),
        );
    }

    #[Test]
    public function an_arabic_slug_token_is_the_cross_language_vector(): void
    {
        // The canonical path is the DECODED one; the renderer's tests/preview-token.test.ts
        // asserts this same token.
        $this->assertSame(
            'v1.eyJvIjoxMywicyI6InBhZ2VzIiwicCI6Ii_YrdmI2YQiLCJhIjoiaHR0cHM6Ly9tYXNqaWQuaG9wZXRlY2hhcHBzLmNvbSIsImUiOjE3OTAwMDAwMDB9.o_5sH32adRZicmHnViMI7aaRHWYIMS1fL4s0BzRMiW4',
            PreviewToken::mint(self::SECRET, 13, 'pages', '/حول', 'https://masjid.hopetechapps.com', 1790000000),
        );
        $this->assertSame('/%D8%AD%D9%88%D9%84', PreviewToken::encodePath('/حول'));
        $this->assertSame('/faq%7B2%7D/o%27neil', PreviewToken::encodePath("/faq{2}/o'neil"));
    }

    #[Test]
    public function the_purge_signature_for_fixed_inputs_is_the_cross_language_vector(): void
    {
        $this->assertSame(
            'v1=7ecd39e80d239396dde215a93378e4336953e71b43cb74c2c4c2d3c8f3bb1786',
            RendererCachePurge::signature(self::SECRET, '1790000000', '{"v":1,"org":13}'),
        );
    }

    #[Test]
    public function a_preview_token_is_not_a_purge_signature(): void
    {
        // Same key, different purpose prefix inside the MAC.
        $token = PreviewToken::mint(self::SECRET, 13, 'pages', '/about', 'https://masjid.hopetechapps.com', 1790000000);
        [, $payload] = explode('.', $token);
        $this->assertNotSame(
            RendererCachePurge::signature(self::SECRET, '1790000000', '{"v":1,"org":13}'),
            'v1='.hash_hmac('sha256', 'manara-preview|v1|'.$payload, self::SECRET),
        );
    }

    #[Test]
    public function paths_follow_the_renderers_rule(): void
    {
        // The same lists as the renderer's tests/preview-token.test.ts.
        foreach (['/', '/about', '/services/12', '/a-b_c.d~e', '/حول', '/faq{2}', "/o'neil", '/'.str_repeat('ح', 511)] as $path) {
            $this->assertTrue(PreviewToken::isSafePath($path), $path);
        }
        foreach (['', 'about', '//evil.example', '/a/../b', '/a/./b', '/%2e%2e/x', '/.%2E/x', '/%D8%B9', '/50%off', '/a%2Fb', '/a\\b', '/a?b', '/a#b', "/a\tb", '/a b', "/a\u{00a0}b", "/a\u{2028}b", "/a\u{feff}b", "/a\xffb", '/'.str_repeat('x', 512), '/'.str_repeat('😀', 256), null, 13] as $path) {
            $this->assertFalse(PreviewToken::isSafePath($path), var_export($path, true));
        }
    }

    #[Test]
    public function it_refuses_to_mint_bad_claims(): void
    {
        foreach ([
            [0, 'pages', '/', 'https://masjid.hopetechapps.com'],
            [13, 'admin', '/', 'https://masjid.hopetechapps.com'],
            [13, 'pages', '//x', 'https://masjid.hopetechapps.com'],
            [13, 'pages', '/', 'https://masjid.hopetechapps.com/'],
            [13, 'pages', '/', '*'],
        ] as [$org, $surface, $path, $origin]) {
            try {
                PreviewToken::mint(self::SECRET, $org, $surface, $path, $origin, 1790000000);
                $this->fail("minted for {$org} {$surface} {$path} {$origin}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
