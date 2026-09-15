<?php

namespace Tests\Feature;

use App\Models\Masjid;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The SPA's copy of the module catalogue (resources/vue-app/core/types/data/
 * Capability.ts) must match Masjid::MODULE_KEYS and Masjid::MODULE_DEFAULTS.
 *
 * There is no SPA test runner, so the PHP suite reads the file. A key the TS
 * list lacks is a module whose menu flag the SPA's types refuse; a default that
 * drifted is a switch panel describing the wrong org types. Both copies are
 * written by hand, so this is the only thing that keeps them honest.
 *
 * The file's layout is part of the contract: `export const MODULE_KEYS = [ ... ]
 * as const;`, `export const MODULE_DEFAULTS: Record<ModuleKey, Record<OrgType,
 * boolean>> = { key: { masjid: true, school: false, community: false }, ... };`
 * one module per line, and `export const APP_SURFACE_MODULES ... = [ ... ];`.
 */
class CapabilityTsMirrorTest extends TestCase
{
    private function source(): string
    {
        $path = base_path('resources/vue-app/core/types/data/Capability.ts');

        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    #[Test]
    public function the_spa_module_keys_are_the_php_module_keys_in_order(): void
    {
        $this->assertSame(
            1,
            preg_match('/export const MODULE_KEYS\s*=\s*\[(.*?)\]\s*as const;/s', $this->source(), $block),
            'Capability.ts has no `export const MODULE_KEYS = [ ... ] as const;` block'
        );

        preg_match_all("/'([a-z_]+)'/", $block[1], $keys);

        $this->assertSame(Masjid::MODULE_KEYS, $keys[1]);
    }

    #[Test]
    public function the_spa_module_defaults_are_the_php_module_defaults_in_order(): void
    {
        $this->assertSame(
            1,
            preg_match('/export const MODULE_DEFAULTS\b[^=]*=\s*\{(.*?)\n\};/s', $this->source(), $block),
            'Capability.ts has no `export const MODULE_DEFAULTS ... = { ... };` block'
        );

        preg_match_all(
            '/^\s*([a-z_]+)\s*:\s*\{\s*masjid\s*:\s*(true|false)\s*,\s*school\s*:\s*(true|false)\s*,\s*community\s*:\s*(true|false)\s*,?\s*\}\s*,?\s*$/m',
            $block[1],
            $rows,
            PREG_SET_ORDER
        );

        $parsed = [];

        foreach ($rows as $row) {
            $parsed[$row[1]] = [
                'masjid' => $row[2] === 'true',
                'school' => $row[3] === 'true',
                'community' => $row[4] === 'true',
            ];
        }

        // assertSame on arrays compares order too: a row out of MODULE_KEYS order,
        // or one written in a shape the pattern does not read, fails here.
        $this->assertSame(Masjid::MODULE_DEFAULTS, $parsed);
    }

    #[Test]
    public function the_spa_app_surface_modules_are_the_catalogues_app_only_modules_in_order(): void
    {
        // The SPA uses this list twice: to place a row with "Where: Mobile app
        // menu", and to keep an app-only module out of the staff sentences about
        // switched-off SCREENS. A key missing here would tell an administrator
        // that Qur’an — a screen they never had — was taken away from them; a key
        // that should not be here would hide a real screen's switch-off notice.
        $this->assertSame(
            1,
            preg_match('/export const APP_SURFACE_MODULES\b[^=]*=\s*\[(.*?)\];/s', $this->source(), $block),
            'Capability.ts has no `export const APP_SURFACE_MODULES ... = [ ... ];` block'
        );

        preg_match_all("/'([a-z_]+)'/", $block[1], $keys);

        $fromConfig = array_keys(array_filter(
            config('capabilities', []),
            fn ($definition) => is_array($definition) && ($definition['surface'] ?? null) === 'app'
        ));

        $this->assertSame(['quran', 'hadith', 'adhkar', 'qibla', 'tasbih'], $fromConfig);
        $this->assertSame($fromConfig, $keys[1]);
    }
}
