<?php

namespace App\Enums;

enum IqamaType: string
{
    case MINUTES_AFTER_ADHAN = 'minutes_after_adhan';
    case SPECIFIC_TIME_RANGES = 'specific_time_ranges';

    public function label(): string
    {
        return match($this) {
            self::MINUTES_AFTER_ADHAN => 'Minutes After Adhan',
            self::SPECIFIC_TIME_RANGES => 'Specific Time Ranges',
        };
    }
}

