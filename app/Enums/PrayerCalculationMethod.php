<?php

namespace App\Enums;

enum PrayerCalculationMethod: string
{
    case MUSLIM_WORLD_LEAGUE = 'MuslimWorldLeague';
    case EGYPTIAN = 'Egyptian';
    case KARACHI = 'Karachi';
    case UMM_AL_QURA = 'UmmAlQura';
    case DUBAI = 'Dubai';
    case MOONSIGHTING_COMMITTEE = 'MoonsightingCommittee';
    case NORTH_AMERICA = 'NorthAmerica';
    case KUWAIT = 'Kuwait';
    case QATAR = 'Qatar';
    case SINGAPORE = 'Singapore';
    case TEHRAN = 'Tehran';
    case TURKEY = 'Turkey';

    public function label(): string
    {
        return match($this) {
            self::MUSLIM_WORLD_LEAGUE => 'Muslim World League',
            self::EGYPTIAN => 'Egyptian General Authority of Survey',
            self::KARACHI => 'University of Islamic Sciences, Karachi',
            self::UMM_AL_QURA => 'Umm Al-Qura University, Makkah',
            self::DUBAI => 'Dubai',
            self::MOONSIGHTING_COMMITTEE => 'Moonsighting Committee Worldwide',
            self::NORTH_AMERICA => 'Islamic Society of North America',
            self::KUWAIT => 'Kuwait',
            self::QATAR => 'Qatar',
            self::SINGAPORE => 'Singapore',
            self::TEHRAN => 'Institute of Geophysics, University of Tehran',
            self::TURKEY => 'Turkey',
        };
    }
}
