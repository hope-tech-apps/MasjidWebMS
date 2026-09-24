<?php

namespace App\Support;

use App\Models\Masjid;
use App\Models\MobileAppFeature;

/**
 * The legacy Mobile App Features pivot (`masjid_mobile_app_features`) as an
 * organisation's switches say it should be (docs/manara-studio-w1.md S4, S8).
 *
 * Installed Android builds still draw their tab bar and menu from that pivot
 * through GET /features, while /menu reads the switches, so a new organisation
 * whose pivot came from anything else would show one set of tabs on Android and
 * another on iOS. Studio's preview reads rowsFor() for the Android frame, and
 * S8 seeds a new organisation's pivot from the same rows (seedFromSwitches), so
 * the mockup and the real app cannot disagree.
 *
 * BY ID, NEVER BY KEY. Production's Qur'an row is keyed `qur’an` (U+2019), not
 * `quran`, and matching a module key against that column is how every new
 * masjid was once born with Qur'an off (.claude/rules/verticals.md). The ids
 * are fixed; AppMenu's registry maps each one to its switches.
 */
final class AppFeaturePivot
{
    /**
     * Every catalogue feature's id => whether this organisation has it, judged
     * by the switches (AppMenu::legacyAvailability). Works on an unsaved
     * organisation: nothing is read but the catalogue's ids.
     *
     * @return array<int, bool>
     */
    public static function rowsFor(Masjid $m): array
    {
        $rows = [];

        foreach (MobileAppFeature::query()->orderBy('id')->pluck('id') as $id) {
            $rows[(int) $id] = AppMenu::legacyAvailability($m, (int) $id);
        }

        return $rows;
    }
}
