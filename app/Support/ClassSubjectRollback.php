<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class ClassSubjectRollback
{
    /** Every down checks the whole feature before the first DDL statement. */
    public static function assertEmpty(): void
    {
        if (Schema::hasTable('class_subjects') && DB::table('class_subjects')->exists()) self::refuse();
        foreach (['groups' => ['subject_seed_grades', 'class_subjects_initialized_at'],
            'group_staff' => ['class_subject_ids', 'class_subjects_mapped_at', 'class_subject_legacy_snapshot']] as $table => $columns) {
            foreach ($columns as $column) {
                if (Schema::hasColumn($table, $column) && DB::table($table)->whereNotNull($column)->exists()) self::refuse();
            }
        }
        foreach (DB::table('masjids')->whereNotNull('capability_overrides')->pluck('capability_overrides') as $json) {
            $overrides = json_decode($json, true);
            if (! empty($overrides[ClassSubjectInitializer::MARKER]) || ($overrides['class_subjects'] ?? false) === true) self::refuse();
        }
    }

    private static function refuse(): never
    {
        throw new RuntimeException('Refusing to remove populated class subject data. Switch the capability off and retain the schema to roll back.');
    }
}
