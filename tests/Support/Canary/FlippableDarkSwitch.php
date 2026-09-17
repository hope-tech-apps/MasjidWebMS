<?php

namespace Tests\Support\Canary;

use App\Support\Canary\DarkLaunchSwitch;

/**
 * A dark-launch switch a test sets by hand, and that counts how often the
 * canary asked it. `$because` null means live; any sentence means dark.
 *
 * Static because the canary calls the interface's static method on a
 * class-string from config, exactly as it does in production. Reset it in
 * tearDown(), or one test's switch is the next test's.
 */
final class FlippableDarkSwitch implements DarkLaunchSwitch
{
    public static ?string $because = null;

    public static int $calls = 0;

    public static function darkBecause(): ?string
    {
        self::$calls++;

        return self::$because;
    }

    public static function reset(): void
    {
        self::$because = null;
        self::$calls = 0;
    }
}
