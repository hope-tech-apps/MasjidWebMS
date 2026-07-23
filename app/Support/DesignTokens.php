<?php

namespace App\Support;

use App\Models\ThemeSetting;

/**
 * Design-tokenization: the single source of truth that turns a masjid's four
 * base brand colors into a FULL canonical design-token tree (semantic colors +
 * typography + shape + spacing), then deep-merges any per-masjid overrides on
 * top. Every client (web, iOS, Android, tvOS) consumes this identical `tokens`
 * object, so a brand change re-skins the whole fleet from one place.
 *
 * Backward-compatible: the legacy {primary,secondary,accent,background} payload
 * is still served untouched; `tokens` is additive.
 */
class DesignTokens
{
    /** App-wide fallbacks when a masjid hasn't set a given color (historical Burlington green). */
    private const DEFAULTS = [
        'primary' => '#01B151',
        'secondary' => '#0B7A3B',
        'accent' => '#F2B705',
        'background' => '#FFFFFF',
    ];

    /** Build the resolved token tree for a masjid's theme (null theme => all defaults). */
    public static function resolve(?ThemeSetting $theme): array
    {
        $primary = self::norm($theme?->primary_color) ?? self::DEFAULTS['primary'];
        $secondary = self::norm($theme?->secondary_color) ?? self::DEFAULTS['secondary'];
        $accent = self::norm($theme?->accent_color) ?? self::DEFAULTS['accent'];
        $background = self::norm($theme?->background_color) ?? self::DEFAULTS['background'];

        $darkBg = self::isDark($background);

        $derived = [
            'color' => [
                'primary' => $primary,
                'secondary' => $secondary,
                'accent' => $accent,
                'background' => $background,
                'surface' => $darkBg ? self::lighten($background, 0.06) : '#FFFFFF',
                'text' => $darkBg ? '#F5F5F5' : '#111827',
                'textMuted' => $darkBg ? '#B8B8B8' : '#6B7280',
                'border' => $darkBg ? self::lighten($background, 0.14) : '#E5E7EB',
                'onPrimary' => self::contrastText($primary),
                'onSecondary' => self::contrastText($secondary),
                'onAccent' => self::contrastText($accent),
                'success' => '#16A34A',
                'warning' => '#D97706',
                'error' => '#DC2626',
            ],
            'typography' => [
                'fontFamily' => 'system',
                'scale' => ['display' => 32, 'title' => 24, 'heading' => 20, 'body' => 16, 'caption' => 12],
                'weight' => ['regular' => 400, 'medium' => 500, 'semibold' => 600, 'bold' => 700],
            ],
            'shape' => [
                'radius' => ['sm' => 8, 'md' => 12, 'lg' => 16, 'pill' => 999],
                'borderWidth' => 1,
            ],
            'spacing' => ['unit' => 4, 'scale' => [0, 4, 8, 12, 16, 24, 32, 48]],
        ];

        $overrides = is_array($theme?->tokens) ? $theme->tokens : [];

        return self::deepMerge($derived, $overrides);
    }

    /** Normalize a hex string to #RRGGBB (or #RRGGBBAA), or null when blank/invalid. */
    private static function norm(?string $hex): ?string
    {
        $hex = is_string($hex) ? trim($hex) : '';
        if ($hex === '') {
            return null;
        }
        if (! preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $hex)) {
            return null;
        }
        // Expand #RGB -> #RRGGBB.
        if (strlen($hex) === 4) {
            $hex = '#' . $hex[1] . $hex[1] . $hex[2] . $hex[2] . $hex[3] . $hex[3];
        }

        return strtoupper($hex);
    }

    /** WCAG relative luminance (0=black .. 1=white) of a #RRGGBB[AA] color. */
    private static function luminance(string $hex): float
    {
        [$r, $g, $b] = self::rgb($hex);
        $lin = fn (float $c) => ($c <= 0.03928) ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;

        return 0.2126 * $lin($r / 255) + 0.7152 * $lin($g / 255) + 0.0722 * $lin($b / 255);
    }

    private static function isDark(string $hex): bool
    {
        return self::luminance($hex) < 0.5;
    }

    /** Black or white text that reads legibly on the given background color. */
    private static function contrastText(string $hex): string
    {
        return self::luminance($hex) > 0.55 ? '#111827' : '#FFFFFF';
    }

    /** Mix a color toward white by $amount (0..1). */
    private static function lighten(string $hex, float $amount): string
    {
        [$r, $g, $b] = self::rgb($hex);
        $mix = fn (int $c) => (int) round($c + (255 - $c) * $amount);

        return sprintf('#%02X%02X%02X', $mix($r), $mix($g), $mix($b));
    }

    /** @return array{0:int,1:int,2:int} */
    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    /** Recursive array merge where override values (incl. scalars) win over base. */
    private static function deepMerge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = self::deepMerge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }
}
