<?php

namespace App\Support\Studio;

/**
 * Which of a client's two hosts serves and which redirects (Manara Studio W2,
 * S5; owner decision 2026-09-24: `www` serves, the apex redirects).
 *
 * The pair exists only for a zone's apex and its `www`: `example.org` and
 * `www.example.org`. Any other host (`school.example.org`) has no pair, and a
 * request naming a canonical for it is refused. One copy, read by the domain
 * store request and by provisioning, so the two can never pair differently.
 */
final class CanonicalPair
{
    public const WWW = 'www';
    public const APEX = 'apex';
    public const CHOICES = [self::WWW, self::APEX];

    /**
     * @return array{serving: string, redirect: string}|null  null when the host is neither the apex nor its www
     */
    public static function for(string $host, string $apex, string $canonical): ?array
    {
        $host = strtolower($host);
        $apex = strtolower($apex);
        $www = 'www.' . $apex;

        if ($host !== $apex && $host !== $www) {
            return null;
        }

        return $canonical === self::APEX
            ? ['serving' => $apex, 'redirect' => $www]
            : ['serving' => $www, 'redirect' => $apex];
    }
}
