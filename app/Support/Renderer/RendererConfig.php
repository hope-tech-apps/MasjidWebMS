<?php

namespace App\Support\Renderer;

use App\Support\SiteUrl;

/**
 * The renderer integration's configuration, read in one place and validated.
 *
 * Every accessor fails CLOSED: a missing or malformed value turns the feature off
 * rather than guessing. See config/services.php 'renderer' and docs/live-preview.md §4.1.
 */
final class RendererConfig
{
    public const SECRET_MIN_LENGTH = 32;

    /** The shared HMAC key, or null when unset or too short to trust. */
    public static function secret(): ?string
    {
        $secret = trim((string) config('services.renderer.secret'));

        return strlen($secret) >= self::SECRET_MIN_LENGTH ? $secret : null;
    }

    /** The preview iframe's origin, or null when unset or not an origin. */
    public static function previewOrigin(): ?string
    {
        return self::origin(config('services.renderer.preview_origin'));
    }

    /** @return list<string> renderer deployments to purge on save */
    public static function purgeOrigins(): array
    {
        return self::origins(config('services.renderer.purge_origins'));
    }

    /**
     * Admin SPA origins a preview token may name.
     *
     * @return list<string>
     */
    public static function adminOrigins(): array
    {
        $listed = self::origins(config('services.renderer.admin_origins'));
        if ($listed !== []) {
            return $listed;
        }

        $own = self::origin(SiteUrl::to());

        return $own === null ? [] : [$own];
    }

    public static function timeout(): int
    {
        return max(1, min(30, (int) config('services.renderer.timeout', 5)));
    }

    public static function previewEnabled(): bool
    {
        return self::secret() !== null && self::previewOrigin() !== null;
    }

    public static function purgeEnabled(): bool
    {
        return self::secret() !== null && self::purgeOrigins() !== [];
    }

    /**
     * An origin exactly as a browser reports it (scheme://host[:port], lower case, no
     * path), or null. The same rule as the renderer's isOrigin().
     */
    public static function origin(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = rtrim(trim($value), '/');

        return preg_match('#^https?://[a-z0-9.-]+(:\d{1,5})?$#', $value) === 1 ? $value : null;
    }

    /** @return list<string> */
    private static function origins(mixed $value): array
    {
        $items = is_array($value) ? $value : explode(',', (string) $value);

        return array_values(array_unique(array_filter(array_map(
            fn ($item) => self::origin($item),
            $items,
        ))));
    }
}
