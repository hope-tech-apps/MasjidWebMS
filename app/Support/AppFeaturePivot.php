<?php

namespace App\Support;

use App\Models\Masjid;
use App\Models\MasjidMobileAppFeature;
use App\Models\MobileAppFeature;
use LogicException;

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

    /**
     * Write a NEW organisation's pivot from its switches: one row per catalogue
     * feature, `is_available` as rowsFor() says, so /features (installed
     * Android builds) and /menu (the switches) start out agreeing.
     *
     * Creation only. An organisation that already has pivot rows has an
     * installed app reading them, and replacing them is the app-features
     * cutover's decision (app-features:cutover-plan), not provisioning's; so
     * this refuses rather than overwrite or add a second row per feature.
     *
     * @return array<int, bool> the rows written, feature id => is_available
     */
    public static function seedFromSwitches(Masjid $m): array
    {
        if (MasjidMobileAppFeature::query()->where('masjid_id', $m->id)->exists()) {
            throw new LogicException("Organisation {$m->id} already has Mobile App Features rows; seedFromSwitches only seeds a new organisation.");
        }

        $rows = self::rowsFor($m);

        foreach ($rows as $featureId => $available) {
            MasjidMobileAppFeature::create([
                'masjid_id' => $m->id,
                'feature_id' => $featureId,
                'is_available' => $available,
            ]);
        }

        return $rows;
    }
}
