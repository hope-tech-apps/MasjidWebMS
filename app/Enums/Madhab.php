<?php

namespace App\Enums;

enum Madhab: string
{
    case SHAFI = 'Shafi';
    case HANAFI = 'Hanafi';

    public function label(): string
    {
        return match($this) {
            self::SHAFI => 'Shafi (Earlier Asr)',
            self::HANAFI => 'Hanafi (Later Asr)',
        };
    }
}
