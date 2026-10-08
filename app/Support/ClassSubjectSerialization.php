<?php

namespace App\Support;

use App\Models\Masjid;

/** A boundary for new private keys; original JSON storage/casting stays untouched. */
final class ClassSubjectSerialization
{
    public static function overrides(Masjid $model, mixed $value): mixed
    {
        if (! is_array($value)) return $value;
        $marker = $value[ClassSubjectInitializer::MARKER] ?? null;
        $removed = $marker !== null || array_key_exists('class_subjects', $value);
        unset($value[ClassSubjectInitializer::MARKER]);
        if (! SchoolSettings::classSubjects($model)) unset($value['class_subjects']);
        return $value === [] && $removed && (! is_array($marker) || ($marker['overrides_were_null'] ?? true)) ? null : $value;
    }
}
