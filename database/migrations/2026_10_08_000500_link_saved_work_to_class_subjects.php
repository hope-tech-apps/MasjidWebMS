<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stable ownership, without a backfill during deploy. MySQL: INSTANT nullable
 * columns, online INPLACE index builds, then INPLACE foreign keys. Adding an FK
 * online requires session foreign_key_checks=0; these brand-new columns contain
 * only NULL, checked before that short DDL window. Restore the session setting
 * even on failure. Explicit algorithms refuse a fallback to a copying ALTER.
 * RESTRICT preserves both saved work and its authority on attempted hard deletion.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['class_assignments' => 'ca', 'lesson_plans' => 'lp'] as $table => $prefix) {
            if (DB::getDriverName() === 'mysql') {
                DB::statement("ALTER TABLE `{$table}` ADD COLUMN `class_subject_id` BIGINT UNSIGNED NULL, ADD COLUMN `class_subject_link_checked_at` DATETIME NULL, ALGORITHM=INSTANT");
                DB::statement("ALTER TABLE `{$table}` ADD INDEX `{$prefix}_class_subject_idx` (`class_subject_id`), ALGORITHM=INPLACE, LOCK=NONE");
                if (DB::table($table)->whereNotNull('class_subject_id')->exists()) {
                    throw new RuntimeException("Refusing unchecked foreign key on {$table}.class_subject_id.");
                }
                $checks = (int) DB::selectOne('SELECT @@SESSION.foreign_key_checks AS fk_checks_value')->fk_checks_value;
                try {
                    DB::statement('SET SESSION foreign_key_checks=0');
                    DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$prefix}_class_subject_fk` FOREIGN KEY (`class_subject_id`) REFERENCES `class_subjects` (`id`) ON DELETE RESTRICT, ALGORITHM=INPLACE, LOCK=NONE");
                } finally {
                    DB::statement('SET SESSION foreign_key_checks='.$checks);
                }
            } else {
                Schema::table($table, function (Blueprint $t) use ($prefix) {
                    $t->unsignedBigInteger('class_subject_id')->nullable();
                    $t->dateTime('class_subject_link_checked_at')->nullable();
                    $t->index('class_subject_id', $prefix.'_class_subject_idx');
                    $t->foreign('class_subject_id', $prefix.'_class_subject_fk')->references('id')->on('class_subjects')->restrictOnDelete();
                });
            }
        }
    }

    public function down(): void
    {
        \App\Support\ClassSubjectRollback::assertEmpty();
        foreach (['class_assignments' => 'ca', 'lesson_plans' => 'lp'] as $table => $prefix) {
            Schema::table($table, function (Blueprint $t) use ($prefix) {
                $t->dropForeign(DB::getDriverName() === 'sqlite' ? ['class_subject_id'] : $prefix.'_class_subject_fk');
                $t->dropIndex($prefix.'_class_subject_idx');
                $t->dropColumn(['class_subject_id', 'class_subject_link_checked_at']);
            });
        }
    }
};
