<?php

namespace App\Services\PrayerTimes;

/**
 * Port of adhan-js `Rounding.js`. The literal values matter: they are echoed
 * verbatim into the stored `prayers_data.calculationParameters`.
 */
final class Rounding
{
    public const NEAREST = 'nearest';
    public const UP = 'up';
    public const NONE = 'none';
}
