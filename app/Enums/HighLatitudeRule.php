<?php

namespace App\Enums;

enum HighLatitudeRule: string
{
    case MIDDLE_OF_THE_NIGHT = 'MiddleOfTheNight';
    case SEVENTH_OF_THE_NIGHT = 'SeventhOfTheNight';
    case TWILIGHT_ANGLE = 'TwilightAngle';

    public function label(): string
    {
        return match($this) {
            self::MIDDLE_OF_THE_NIGHT => 'Middle of the Night',
            self::SEVENTH_OF_THE_NIGHT => 'Seventh of the Night',
            self::TWILIGHT_ANGLE => 'Twilight Angle',
        };
    }
}
