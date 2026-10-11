<?php

namespace App\Support;

/** Bind the exact normalized submission and displayed warnings; list order is immaterial. */
final class TimetableClashConfirmation
{
    public static function fingerprint(array $submission, array $clashes): string
    {
        unset($submission['confirm_clashes'], $submission['clash_fingerprint']);
        if (isset($submission['teacher_ids'])) sort($submission['teacher_ids']);
        $warnings = array_map(fn ($c) => self::canonical($c), $clashes);
        usort($warnings, fn ($a, $b) => strcmp(json_encode($a), json_encode($b)));
        return hash_hmac('sha256', json_encode([self::canonical($submission), $warnings], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private static function canonical(array $value): array
    {
        if (! array_is_list($value)) ksort($value);
        foreach ($value as &$item) if (is_array($item)) $item = self::canonical($item);
        return $value;
    }
}
