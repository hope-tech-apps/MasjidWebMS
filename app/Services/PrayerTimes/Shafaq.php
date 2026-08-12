<?php

namespace App\Services\PrayerTimes;

/**
 * Port of adhan-js `Shafaq.js` — the twilight the MoonsightingCommittee method
 * uses to place Isha. General is a combination of Ahmer (the red glow) and
 * Abyad (the white glow), and is adhan's default.
 */
final class Shafaq
{
    public const GENERAL = 'general';
    public const AHMER = 'ahmer';
    public const ABYAD = 'abyad';
}
