<?php

namespace App\Support;

use App\Models\MasjidPointsSetting;

/**
 * How a school's points turn into Manara Bucks (T-003.4): the rate, whether paper cash-out
 * is on, and the first day whose points may mint. A new rate applies to weeks minted AFTER
 * the change; a week already minted keeps the rate it was minted at (BucksMinter). The ONE reader, so the command, the
 * redemption and the SuperAdmin endpoint agree.
 *
 * No row, or a row written before these columns existed, reads as the defaults: one point a
 * buck, paper OFF, no start day yet. A stored value that is not usable (a hand-edited row)
 * is read as the default rather than crashing the hourly run.
 */
final class ClassStoreSettings
{
    public const DEFAULT_POINTS_PER_BUCK = 1;

    /** A ceiling that stops a typo (10000) turning a whole week of points into nothing. */
    public const MAX_POINTS_PER_BUCK = 100;

    /**
     * @return array{points_per_buck:int,paper_bucks_enabled:bool,bucks_from:?string}
     */
    public static function for(int $masjidId): array
    {
        $row = MasjidPointsSetting::withoutMasjidScope()->where('masjid_id', $masjidId)->first();

        $rate = $row?->points_per_buck;

        return [
            'points_per_buck' => is_int($rate) && $rate >= 1 && $rate <= self::MAX_POINTS_PER_BUCK
                ? $rate
                : self::DEFAULT_POINTS_PER_BUCK,
            'paper_bucks_enabled' => (bool) ($row?->paper_bucks_enabled ?? false),
            'bucks_from' => $row?->bucks_from?->toDateString(),
        ];
    }

    public static function paperEnabled(int $masjidId): bool
    {
        return self::for($masjidId)['paper_bucks_enabled'];
    }
}
