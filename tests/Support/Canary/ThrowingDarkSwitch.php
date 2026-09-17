<?php

namespace Tests\Support\Canary;

use App\Support\Canary\DarkLaunchSwitch;

/**
 * A switch whose table cannot be read — the moment between deploying the code
 * and running the migration. The message carries SQL on purpose: the canary
 * must report the exception's CLASS and never its message, because the detail
 * lands in a log line.
 */
final class ThrowingDarkSwitch implements DarkLaunchSwitch
{
    public static function darkBecause(): ?string
    {
        throw new \RuntimeException('SQLSTATE[HY000]: no such table: app_menu_settings');
    }
}
