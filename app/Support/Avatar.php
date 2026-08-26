<?php

namespace App\Support;

/**
 * The forty drawings a person can choose as their avatar, and the rules for
 * naming one.
 *
 * Ported from DeenQuest, where the same artwork already ships. The catalogue is
 * a constant rather than a table because these are FILES on disk: adding a
 * colour means shipping twenty images, which is a deploy, not a row.
 *
 * ## Why this is not a media upload
 *
 * Nothing here is uploaded. A choice is three short strings, and the image is
 * already in `public/images/avatars`. Routing it through MediaLibrary would mint
 * a media row and a stored file per child for a picture the server ships anyway
 * — and would put every child's face inside the same `media` table that a
 * runaway sweep has already emptied once on this platform.
 */
class Avatar
{
    public const CHARACTERS = ['ameer', 'ameera'];

    public const CHARACTER_LABELS = [
        'ameer' => 'Amir',
        'ameera' => 'Amira',
    ];

    public const TONES = ['tone1', 'tone2', 'tone3', 'tone4'];

    /** Swatches for the picker, matching the drawn skin tones. */
    public const TONE_SWATCHES = [
        'tone1' => '#E8C89B',
        'tone2' => '#FCEDCC',
        'tone3' => '#55350D',
        'tone4' => '#976621',
    ];

    /** The hijab (Amira) or kufi (Amir) colour. */
    public const COLORS = ['black', 'blue', 'green', 'pink', 'white'];

    public const COLOR_SWATCHES = [
        'black' => '#1F1A1A',
        'blue' => '#65DCF2',
        'green' => '#9FDF9C',
        'pink' => '#EEB5E1',
        'white' => '#FDF1EC',
    ];

    public static function isComplete(?string $character, ?string $tone, ?string $color): bool
    {
        return in_array($character, self::CHARACTERS, true)
            && in_array($tone, self::TONES, true)
            && in_array($color, self::COLORS, true);
    }

    /**
     * The public URL of a chosen avatar, or null when the choice is absent or
     * not one this platform ships. Null is a real answer — the client draws
     * initials — and is deliberately preferred over silently substituting a
     * default, which would show a child somebody else's face.
     */
    public static function imageUrl(?string $character, ?string $tone, ?string $color): ?string
    {
        if (! self::isComplete($character, $tone, $color)) {
            return null;
        }

        return asset("images/avatars/{$character}_{$tone}_{$color}.webp");
    }

    /** The whole catalogue, for a picker to render without hard-coding it. */
    public static function catalogue(): array
    {
        $options = [];

        foreach (self::CHARACTERS as $character) {
            foreach (self::TONES as $tone) {
                foreach (self::COLORS as $color) {
                    $options[] = [
                        'character' => $character,
                        'tone' => $tone,
                        'color' => $color,
                        'url' => self::imageUrl($character, $tone, $color),
                    ];
                }
            }
        }

        return [
            'characters' => array_map(
                static fn (string $c): array => ['id' => $c, 'label' => self::CHARACTER_LABELS[$c]],
                self::CHARACTERS
            ),
            'tones' => array_map(
                static fn (string $t): array => ['id' => $t, 'swatch' => self::TONE_SWATCHES[$t]],
                self::TONES
            ),
            'colors' => array_map(
                static fn (string $c): array => ['id' => $c, 'label' => ucfirst($c), 'swatch' => self::COLOR_SWATCHES[$c]],
                self::COLORS
            ),
            'options' => $options,
        ];
    }
}
