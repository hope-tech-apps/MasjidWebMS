<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Validation\ValidationException;

/** Integer hundredths throughout; only the final ratio is rounded, half up. */
final class TranscriptMath
{
    public static function hundredths(mixed $value, string $field='number', int $max=10000): int
    {
        if ((! is_string($value) && ! is_int($value)) || ! preg_match('/^\d{1,5}(?:\.\d{1,2})?$/D', (string) $value)) {
            throw ValidationException::withMessages([$field=>['Enter a non-negative decimal with at most two decimal places.']]);
        }
        [$whole,$fraction]=array_pad(explode('.',(string)$value),2,'');
        $result=(int)$whole*100+(int)str_pad($fraction,2,'0');
        if ($result>$max) throw ValidationException::withMessages([$field=>['This number is above the allowed maximum.']]);
        return $result;
    }
    public static function ratio(int $numerator, int $denominator): ?string
    {
        if ($denominator===0) return null;
        $scaled=intdiv($numerator*100,$denominator);
        if (($numerator*100)%$denominator*2 >= $denominator) $scaled++;
        return intdiv($scaled,100).'.'.str_pad((string)($scaled%100),2,'0',STR_PAD_LEFT);
    }
    public static function decimal(int $hundredths): string { return self::ratio($hundredths,100); }
    public static function letter(int $sum, int $count, array $scale): ?string
    {
        if ($count===0) return null;
        foreach ($scale as $row) {
            // Scale JSON may contain JSON numbers; serialize their decimal spelling, never calculate in float.
            $minimum=self::hundredths((string)$row['minimum'],'scale');
            if ($sum >= $minimum*$count) return $row['letter'];
        }
        return null;
    }
    public static function totals(array $courses): array
    {
        $attempted=0; $graded=0; $earned=0; $plain=0; $weighted=0;
        foreach ($courses as $course) {
            if (($course['on_transcript'] ?? true)===false || $course['credit_hundredths']===null || $course['letter']===null) continue;
            $credit=$course['credit_hundredths']; $attempted+=$credit;
            $graded+=$credit;
            if ($course['passing']) $earned+=$credit;
            $plain+=$credit*$course['points_hundredths'];
            $weighted+=$credit*($course['points_hundredths']+$course['extra_hundredths']);
        }
        return ['attempted'=>self::decimal($attempted),'earned'=>self::decimal($earned),
            'plain'=>self::ratio($plain,$graded*100),'weighted'=>self::ratio($weighted,$graded*100)];
    }
}
