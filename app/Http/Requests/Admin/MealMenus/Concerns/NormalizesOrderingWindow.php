<?php

namespace App\Http\Requests\Admin\MealMenus\Concerns;

use App\Support\MasjidTime;

/**
 * Reads the ordering window as the masjid's wall clock, stores it as UTC.
 *
 * See App\Support\MasjidTime for why. Only keys actually present are touched:
 * both fields are `nullable`, not `sometimes`, so an absent key must stay absent
 * through validated() or a partial update would wipe a window it never mentioned.
 */
trait NormalizesOrderingWindow
{
    protected function prepareForValidation(): void
    {
        $tz = MasjidTime::zoneFor($this->route('masjid_id'));

        foreach (['ordering_opens_at', 'ordering_closes_at'] as $field) {
            if (! $this->has($field)) {
                continue;
            }

            $this->merge([$field => MasjidTime::toUtc($this->input($field), $tz)]);
        }
    }
}
