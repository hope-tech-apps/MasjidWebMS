<?php

namespace App\Support;

use App\Models\Masjid;

/**
 * Manara's own prayer pushes, and the switch they follow.
 *
 * Three senders wake phones about prayer times: the backstop reminder
 * (`prayers:send-due`), the daily silent refresh (`prayers:daily-resync`) and
 * the silent refresh sent when iqama times are saved
 * (AdminDashboard\IqamaTimeSettingsController). All three stop while the
 * organisation's Prayer times module (config/capabilities.php `prayer_times`)
 * is switched off. `prayers:test-push` is an operator tool and does not ask.
 *
 * What does NOT stop: the website, apps and TV board keep reading the last
 * saved times through the public `prayers/settings` read, which never follows a
 * module, and both apps re-arm their own adhan and iqama alerts locally from it
 * (Android every day from its cache, iOS on every open and in background
 * refresh when iOS grants one).
 *
 * What a congregant can notice: iOS arms only 6 days ahead and counts on the
 * daily silent refresh to re-arm an app nobody opens, so an iPhone left unopened
 * for about 6 days can stop alerting, and iqama times saved while the switch is
 * off reach iPhones only on the next open. The panel and the catalogue say so.
 */
final class PrayerPushes
{
    /**
     * Devices silent at least this many days get the backstop reminder.
     *
     * PUBLIC and held here, not on the command, so a count of "phones that get
     * Manara's backup reminders" is computed from the same number the command
     * sends with and the two can never drift.
     */
    public const STALE_DAYS = 5;

    /**
     * Whether Manara may push prayer reminders or refreshes for this organisation.
     *
     * Fail-open through Masjid::moduleIsOff: a config cache from before the
     * module existed (the bin/deploy window) pushes exactly as before.
     */
    public static function allowedFor(Masjid $masjid): bool
    {
        return ! $masjid->moduleIsOff('prayer_times');
    }
}
