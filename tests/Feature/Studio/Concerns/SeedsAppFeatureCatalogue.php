<?php

namespace Tests\Feature\Studio\Concerns;

use App\Models\MobileAppFeature;

/**
 * The Mobile App Features catalogue as production holds it: MobileAppFeaturesSeeder's
 * eleven rows in id order (1 Qur'an … 11 Contact Us), with row 1 keyed
 * `qur’an` (U+2019), the spelling production actually has
 * (.claude/rules/verticals.md). Without the icons, so no media is written.
 */
trait SeedsAppFeatureCatalogue
{
    protected const PRODUCTION_QURAN_KEY = "qur\u{2019}an";

    protected function seedAppFeatureCatalogue(): void
    {
        $rows = [
            [self::PRODUCTION_QURAN_KEY, "Qur\u{2019}an"],
            ['hadith', 'Hadith'],
            ['adhkar', 'Adhkar'],
            ['qibla', 'Qibla'],
            ['tasbih', 'Tasbih'],
            ['donate', 'Donate'],
            ['about_us', 'About Us'],
            ['gallery', 'Gallery'],
            ['services', 'Services'],
            ['announcements', 'Announcements'],
            ['contact_us', 'Contact Us'],
        ];

        foreach ($rows as $i => [$key, $name]) {
            $feature = MobileAppFeature::create(['name' => $name, 'key' => $key]);

            // The registry maps legacy ids 1-11; the premise must hold.
            if ($feature->id !== $i + 1) {
                throw new \RuntimeException("Seeded {$key} as id {$feature->id}, expected " . ($i + 1));
            }
        }
    }
}
