<?php

namespace App\Enums;

enum HadithStrength: string
{
    case VALUE_ONE = 'Sahih';
    case VALUE_TWO = 'Hasan';
    case VALUE_THREE = 'Daaif';
    case VALUE_FOUR = 'Maodua';
    case VALUE_FIVE = 'Not-Hadith';

    public function toJson () : array {
        return match ($this) {
            self::VALUE_ONE => ['en' => 'Sahih', 'ar' => 'صحيح'],
            self::VALUE_TWO => ['en' => 'Hasan', 'ar' => 'حسن'],
            self::VALUE_THREE => ['en' => 'Daaif', 'ar' => 'ضعيف'],
            self::VALUE_FOUR => ['en' => 'Maodua', 'ar' => 'موضوع'],
            self::VALUE_FIVE => ['en' => 'Not-Hadith', 'ar' => 'ليس-حديث']
        };
    }
    
    private static function getInstances(): array
    {
        return [
            self::VALUE_ONE,
            self::VALUE_TWO,
            self::VALUE_THREE,
            self::VALUE_FOUR,
            self::VALUE_FIVE
        ];
    }

    public static function getValues(): array
    {
        return array_column(self::getInstances(), 'value');
    }

}
