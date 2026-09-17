<?php

namespace Tests\Support\Canary;

/**
 * Has a method with the right NAME and does not implement
 * App\Support\Canary\DarkLaunchSwitch. The canary must refuse to call it: a
 * method config merely names is the shape of the deleteAllMedia incident.
 * `$called` is how a test proves it was never invoked.
 */
final class NotADarkSwitch
{
    public static bool $called = false;

    public static function darkBecause(): ?string
    {
        self::$called = true;

        return 'it would say dark';
    }
}
