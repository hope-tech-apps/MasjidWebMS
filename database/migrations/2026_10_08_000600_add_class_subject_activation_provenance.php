<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Audit-only translated source and office-edit fact. No readiness or live baseline. */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `group_staff` ADD COLUMN `class_subjects_translated_from` JSON NULL, ADD COLUMN `class_subject_ids_edited_at` DATETIME NULL, ALGORITHM=INSTANT');
        } else {
            Schema::table('group_staff', function (Blueprint $t) {
                $t->json('class_subjects_translated_from')->nullable();
                $t->dateTime('class_subject_ids_edited_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        \App\Support\ClassSubjectRollback::assertEmpty();
        Schema::table('group_staff', fn (Blueprint $t) => $t->dropColumn(['class_subjects_translated_from', 'class_subject_ids_edited_at']));
    }
};
