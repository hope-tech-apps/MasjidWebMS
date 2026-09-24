<?php

namespace App\Support\Studio;

/**
 * Studio's own marker on a starter section, `sections.settings.studio`, and the
 * rule that keeps it off the public API (docs/manara-studio-w1.md S4 §C, S8).
 *
 * WHAT IT RECORDS. Which preset and slot wrote the section, and its
 * placeholders: `{field, kind, hint, essential, source?}` per placeholder, so
 * the page builder can say what is still missing and in the operator's words.
 * Whether a placeholder is OPEN is never stored: it is a function of the
 * content and of the rows the binder reads, and a stored answer would go stale
 * the first time an admin typed the About text. The hint's sentence is not
 * stored either; `hint` is a key into config('studio_layouts.hints').
 *
 * WHY IT IS STRIPPED. The hints are admin-facing and the renderer has no use
 * for the marker, and every live renderer reads `settings` through
 * PageSectionResource. The strip is the first S8 change to that path, and it is
 * byte-neutral for every live row because no code had ever written `studio`
 * before S8 (production count 0, checked 2026-09-24).
 */
final class StarterPlaceholders
{
    public const KEY = 'studio';

    /** Bumped only if the marker's shape changes, so a reader can tell old rows apart. */
    public const VERSION = 1;

    /**
     * A section's `settings` as the public API may serve them.
     *
     * Only an array holding the `studio` key is touched: the key is removed,
     * and when nothing is left the answer is null, which is what a starter
     * section's `settings` would have been without Studio. Anything else (NULL,
     * `{}`, `{bind: …}`, a presentation object, a scalar) is returned exactly as
     * it came, so no live row can serialize differently.
     */
    public static function publicSettings(mixed $settings): mixed
    {
        if (! is_array($settings) || ! array_key_exists(self::KEY, $settings)) {
            return $settings;
        }

        unset($settings[self::KEY]);

        return $settings === [] ? null : $settings;
    }

    /**
     * The `settings` a starter section is written with.
     *
     * @param  array<string, mixed>  $section  one section of a StarterPlan
     * @return array{studio: array{version: int, preset: string, slot: string, placeholders: list<array<string, mixed>>}}
     */
    public static function forSection(string $preset, array $section): array
    {
        $placeholders = [];

        foreach ((array) ($section['placeholders'] ?? []) as $placeholder) {
            $stored = [
                'field' => (string) $placeholder['field'],
                'kind' => (string) $placeholder['kind'],
                'hint' => (string) $placeholder['hint'],
                'essential' => (bool) $placeholder['essential'],
            ];

            if (isset($placeholder['source'])) {
                $stored['source'] = (string) $placeholder['source'];
            }

            $placeholders[] = $stored;
        }

        return [self::KEY => [
            'version' => self::VERSION,
            'preset' => $preset,
            'slot' => (string) $section['slot'],
            'placeholders' => $placeholders,
        ]];
    }
}
