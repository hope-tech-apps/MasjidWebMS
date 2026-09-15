<?php

namespace App\Support;

/**
 * Contrast arithmetic for the mobile app menu's per-organisation theme.
 *
 * The apps paint two things out of one stored brand colour: a BAND (the status
 * bar strip and the switch overlay, filled with the brand colour, carrying the
 * organisation's name) and brand-coloured TEXT on the app's white surface (the
 * section titles, the selected tab). Both were being derived on the phone, in
 * two different ways, from `theme.primary` alone — so MAS Youth's green read as
 * white-on-green at 2.8:1 on one platform and dark-on-green on the other.
 *
 * This class is the one place those two decisions are made, so `/menu` and
 * `/orgs` can hand both clients a finished answer:
 *
 *   on_primary            black or white, whichever reads better ON the band
 *   primary_on_surface    the brand colour darkened until it clears 4.5:1 on
 *                         white, so it is safe as body-sized text
 *   band_text_large_only  true when NEITHER black nor white clears 4.5:1 on the
 *                         band, i.e. the name is legible only at large sizes
 *                         (WCAG 1.4.3 large text: 3:1). The clients render the
 *                         organisation's name at semibold 20+ when it is set.
 *
 * Deliberately separate from App\Support\DesignTokens, which serves the WEB
 * header and whose `onPrimary` is white for every green currently in play.
 * Changing that would re-skin every dashboard; this class changes nothing that
 * already exists.
 *
 * Ratios are WCAG 2.1 relative luminance, the same formula DesignTokens uses.
 * Nothing here throws: an unusable colour returns the safe default, because a
 * bad hex in one organisation's row must never 500 the menu for everyone.
 */
class WcagColor
{
    /** The two candidates for text on a filled brand band. */
    public const LIGHT = '#FFFFFF';
    public const DARK = '#111827';

    /** The app's surface behind brand-coloured text. */
    public const SURFACE = '#FFFFFF';

    /** WCAG AA for body-sized text. */
    public const AA_NORMAL = 4.5;

    /**
     * Normalise a stored colour to #RRGGBB (uppercase), or null when it is not
     * a colour we can reason about.
     *
     * Accepts #RGB (expanded), #RRGGBB and #RRGGBBAA (alpha dropped — a brand
     * colour's transparency is meaningless to a client that fills a band with
     * it, and every consumer of this payload wants six digits).
     */
    public static function normalize(?string $hex): ?string
    {
        $hex = is_string($hex) ? trim($hex) : '';

        if ($hex === '' || ! preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $hex)) {
            return null;
        }

        if (strlen($hex) === 4) {
            $hex = '#' . $hex[1] . $hex[1] . $hex[2] . $hex[2] . $hex[3] . $hex[3];
        }

        return strtoupper(substr($hex, 0, 7));
    }

    /**
     * The contrast ratio between two colours, 1.0 .. 21.0.
     *
     * An unusable colour returns 1.0 — "no contrast at all" — which is the
     * answer that makes every caller here choose its safe branch.
     */
    public static function ratio(?string $a, ?string $b): float
    {
        $a = self::normalize($a);
        $b = self::normalize($b);

        if ($a === null || $b === null) {
            return 1.0;
        }

        $la = self::luminance($a);
        $lb = self::luminance($b);

        $lighter = max($la, $lb);
        $darker = min($la, $lb);

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    /**
     * Black or white for text drawn ON the brand colour — whichever of the two
     * reads better. Ties and unusable colours go to white, which is what both
     * apps drew before this existed.
     */
    public static function onPrimary(?string $primary): string
    {
        $primary = self::normalize($primary);

        if ($primary === null) {
            return self::LIGHT;
        }

        return self::ratio($primary, self::DARK) > self::ratio($primary, self::LIGHT)
            ? self::DARK
            : self::LIGHT;
    }

    /**
     * The brand colour as TEXT on the app's white surface: returned unchanged
     * when it already clears 4.5:1, otherwise mixed toward black in fixed 2%
     * steps until it does.
     *
     * Stepping (rather than solving) keeps this deterministic and keeps the
     * result recognisably the organisation's colour — the first step that
     * clears AA wins, so a colour that is already close barely moves. Black is
     * the floor and always clears (21:1), so the loop always terminates.
     */
    public static function primaryOnSurface(?string $primary): string
    {
        $primary = self::normalize($primary);

        if ($primary === null) {
            return self::DARK;
        }

        for ($step = 0; $step <= 50; $step++) {
            $candidate = self::darken($primary, $step * 0.02);

            if (self::ratio($candidate, self::SURFACE) >= self::AA_NORMAL) {
                return $candidate;
            }
        }

        return '#000000';
    }

    /**
     * Is the band's text legible only at large sizes?
     *
     * True when NEITHER candidate clears 4.5:1 on the brand colour. The clients
     * respond by drawing the organisation's name at semibold 20 or larger,
     * where WCAG's 3:1 threshold applies instead — which every brand colour in
     * play clears against one of the two.
     */
    public static function bandTextLargeOnly(?string $primary): bool
    {
        $primary = self::normalize($primary);

        if ($primary === null) {
            return false;
        }

        $best = max(self::ratio($primary, self::LIGHT), self::ratio($primary, self::DARK));

        return $best < self::AA_NORMAL;
    }

    /** WCAG relative luminance (0 = black .. 1 = white) of a #RRGGBB colour. */
    private static function luminance(string $hex): float
    {
        [$r, $g, $b] = self::rgb($hex);

        $lin = fn (float $c) => ($c <= 0.03928) ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;

        return 0.2126 * $lin($r / 255) + 0.7152 * $lin($g / 255) + 0.0722 * $lin($b / 255);
    }

    /** Mix a colour toward black by $amount (0..1). */
    private static function darken(string $hex, float $amount): string
    {
        [$r, $g, $b] = self::rgb($hex);

        $mix = fn (int $c) => (int) round($c * (1 - $amount));

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
}
