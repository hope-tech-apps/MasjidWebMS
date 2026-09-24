<?php

namespace App\Support\Studio;

use App\Models\ThemeSetting;
use App\Support\DesignTokens;
use App\Support\WcagColor;

/**
 * Studio's contrast check on a client's four brand colours: the PaletteReport
 * the draft carries, and later the gate Step 3 refuses to pass
 * (docs/manara-studio-w1.md S2, R16).
 *
 * What gets graded is what the web will actually paint. The pairings come from
 * DesignTokens::resolve(), the tree every client reads, and the ratios from
 * WcagColor, so there is no third copy of the WCAG maths to drift. DesignTokens
 * itself is not changed: its `onX` inks are what every live tenant renders.
 *
 * Its one weakness is a threshold, not a formula: DesignTokens picks white on
 * any colour with luminance up to 0.55, which leaves white on Burlington's green
 * at about 2.8:1. Where that ink fails 4.5:1 and nobody set one by hand, the
 * report picks whichever of DesignTokens' own two inks reads better and returns
 * it under `tokens.color`, which Step 3 writes into the new org's theme
 * `tokens` override. Only a new organisation's theme is ever affected.
 *
 * Blocking pairs are body text (4.5:1). The brand colours as marks on the
 * background are advisory (3:1, WCAG 1.4.11): a pale accent is a design choice,
 * not an unreadable page. Per-platform rows (the iOS header is hard-coded white)
 * are the preview's business in S4 and are advisory there too.
 */
final class PaletteContrast
{
    public const BLOCKING_RATIO = 4.5;

    public const ADVISORY_RATIO = 3.0;

    /** DesignTokens' two inks (DesignTokens::contrastText), the only candidates. */
    public const INKS = [WcagColor::DARK, WcagColor::LIGHT];

    /** A logo wider than this (width / height) will not sit well in a square slot. */
    public const MAX_ASPECT = 2.0;

    /** pair key => [token, brand colour it sits on] */
    private const INK_PAIRS = [
        'on_primary' => ['onPrimary', 'primary_color'],
        'on_secondary' => ['onSecondary', 'secondary_color'],
        'on_accent' => ['onAccent', 'accent_color'],
    ];

    /** pair key => brand colour drawn on the background */
    private const MARK_PAIRS = [
        'primary_on_background' => 'primary_color',
        'accent_on_background' => 'accent_color',
    ];

    private const COLOURS = ['primary_color', 'secondary_color', 'accent_color', 'background_color'];

    /**
     * @param  array<string, mixed>  $brand  the draft's brand section (the four `*_color` keys are read)
     * @param  array<string, mixed>  $inkOverrides  onPrimary / onSecondary / onAccent chosen by hand
     * @param  array{width?: int|null, height?: int|null}|null  $logoDims
     * @return array{valid: bool, blocking_failures: list<string>, pairs: list<array<string, mixed>>, tokens: array{color: array<string, string>}, aspect_warning: bool}
     */
    public static function report(array $brand, array $inkOverrides = [], ?array $logoDims = null): array
    {
        $colours = [];
        foreach (self::COLOURS as $key) {
            $colours[$key] = WcagColor::normalize(is_string($brand[$key] ?? null) ? $brand[$key] : null);
        }

        // Only the colours actually chosen go in. A missing one would otherwise
        // be filled by DesignTokens' default, and its tokens graded instead.
        $resolved = DesignTokens::resolve(new ThemeSetting(array_filter($colours)))['color'];

        $pairs = [];
        $inkTokens = [];

        $background = $colours['background_color'];
        $pairs[] = self::pair(
            'text_on_background',
            $background === null ? null : $resolved['text'],
            $background,
            self::BLOCKING_RATIO,
            null,
        );

        foreach (self::INK_PAIRS as $key => [$token, $colourKey]) {
            $surface = $colours[$colourKey];
            [$ink, $source] = $surface === null
                ? [null, null]
                : self::ink($surface, $resolved[$token], $inkOverrides[$token] ?? null);

            if ($source === 'auto' || $source === 'manual') {
                $inkTokens[$token] = $ink;
            }

            $pairs[] = self::pair($key, $ink, $surface, self::BLOCKING_RATIO, $source);
        }

        foreach (self::MARK_PAIRS as $key => $colourKey) {
            $pairs[] = self::pair($key, $colours[$colourKey], $background, self::ADVISORY_RATIO, null);
        }

        $failures = array_values(array_map(
            fn (array $pair) => $pair['key'],
            array_filter($pairs, fn (array $pair) => $pair['blocking'] && ! $pair['passes']),
        ));

        return [
            'valid' => $failures === [],
            'blocking_failures' => $failures,
            'pairs' => $pairs,
            'tokens' => ['color' => $inkTokens],
            'aspect_warning' => self::tooWide($logoDims),
        ];
    }

    /**
     * The ink for text on one brand colour, and where it came from.
     *
     * A hand-picked ink always wins, even a failing one — the report then says it
     * fails, which is the honest answer. Otherwise DesignTokens' own ink stands
     * when it passes; when it does not, the better of the two inks is chosen.
     *
     * @return array{0: string, 1: string}
     */
    private static function ink(string $surface, string $designInk, mixed $override): array
    {
        $manual = is_string($override) ? WcagColor::normalize($override) : null;

        if ($manual !== null) {
            return [$manual, 'manual'];
        }

        if (WcagColor::ratio($designInk, $surface) >= self::BLOCKING_RATIO) {
            return [$designInk, 'design_tokens'];
        }

        $best = $designInk;
        foreach (self::INKS as $candidate) {
            if (WcagColor::ratio($candidate, $surface) > WcagColor::ratio($best, $surface)) {
                $best = $candidate;
            }
        }

        // Neither ink passes and DesignTokens already holds the better one: there
        // is nothing to override, and the pair reports its failure as it stands.
        return [$best, $best === $designInk ? 'design_tokens' : 'auto'];
    }

    /** @return array<string, mixed> */
    private static function pair(string $key, ?string $foreground, ?string $background, float $required, ?string $inkSource): array
    {
        // A colour not yet chosen has no ratio. It is reported, never guessed.
        $ratio = ($foreground === null || $background === null) ? null : WcagColor::ratio($foreground, $background);

        return [
            'key' => $key,
            'foreground' => $foreground,
            'background' => $background,
            'ratio' => $ratio === null ? null : round($ratio, 2),
            'required' => $required,
            // Judged on the unrounded ratio: 4.495 must not round its way to a pass.
            'passes' => $ratio !== null && $ratio >= $required,
            'blocking' => $required === self::BLOCKING_RATIO,
            'ink_source' => $inkSource,
        ];
    }

    private static function tooWide(?array $logoDims): bool
    {
        $width = (int) ($logoDims['width'] ?? 0);
        $height = (int) ($logoDims['height'] ?? 0);

        return $width > 0 && $height > 0 && $width / $height > self::MAX_ASPECT;
    }
}
