<?php

namespace App\Support;

/**
 * One spelling for a website host, so a lookup, a stored row and the renderer
 * that asks all mean the same string by the same name.
 *
 * The renderer normalises the Host it was reached on and asks the API which
 * organisation serves it (GET /api/v1/organizations/by-host). If the two sides
 * spelled a host differently, `WWW.Example.org.:443` would miss a row stored as
 * `www.example.org`, and a live site would 404. So the rules here are shared
 * with the renderer's `normalizeHost` through one fixture,
 * tests/fixtures/host-normalization.json, which the renderer carries a
 * byte-identical copy of (S10). Change a rule here and the fixture changes, and
 * the renderer's copy then fails until it agrees.
 *
 * That is also why this class decides only what a host IS, never whether we
 * would accept one: refusing `localhost` or `*.pages.dev` is a write-side rule
 * (App\Support\WritableHost), because the renderer's own map holds `localhost`
 * and must still normalise it.
 *
 * Non-ASCII hosts are refused rather than converted (R14): converting needs
 * ext-intl, which is not verified on production, and no client has an IDN. A
 * punycode `xn--` label is an ordinary ASCII label and is kept exactly as typed.
 */
final class HostName
{
    public const MAX_LENGTH = 253;

    // Anchored with \z, not $: PCRE's $ also matches just before a final
    // newline, so "www\n" would pass as a label here while the renderer's JS
    // regex refuses it, and "www\n.example.org" would be stored as a host no
    // lookup can ever match.
    private const LABEL = '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/';

    /**
     * The normalised host, or null when the input names no host we could serve.
     *
     * In order: trim, lowercase, keep the part before the first comma (a proxy's
     * X-Forwarded-Host can carry a list), strip a `:port`, strip one trailing dot.
     * Then null for an empty result, more than 253 characters, an IP literal, a
     * non-ASCII character, or any label outside the DNS label rule.
     */
    public static function normalize(?string $host): ?string
    {
        if ($host === null) {
            return null;
        }

        $host = strtolower(trim($host));

        $comma = strpos($host, ',');
        if ($comma !== false) {
            $host = substr($host, 0, $comma);
        }

        // Anything that is not ASCII is refused outright, before the port or
        // the dot is touched, so no multibyte sequence is ever cut in half.
        if (preg_match('/[^\x00-\x7f]/', $host) === 1) {
            return null;
        }

        $host = preg_replace('/:\d*\z/', '', $host);

        if (str_ends_with($host, '.')) {
            $host = substr($host, 0, -1);
        }

        if ($host === '' || strlen($host) > self::MAX_LENGTH) {
            return null;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        foreach (explode('.', $host) as $label) {
            if (preg_match(self::LABEL, $label) !== 1) {
                return null;
            }
        }

        return $host;
    }

    /** Whether a single DNS label is well formed, by the same rule normalize() applies to each one. */
    public static function isLabel(string $label): bool
    {
        return preg_match(self::LABEL, $label) === 1;
    }
}
