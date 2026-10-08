<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * MySQL 8.4 up: nullable column ALGORITHM=INSTANT (metadata only; LOCK must
 * remain DEFAULT for INSTANT); secondary index INPLACE/LOCK=NONE; foreign
 * key INPLACE/LOCK=NONE with foreign_key_checks=0 for that statement only.
 * The new column is entirely NULL, so no existing row needs FK validation.
 * Explicit algorithms refuse unsupported DDL instead of silently copying.
 * down: FK and index removal INPLACE/LOCK=NONE; column removal INSTANT.
 * All ALTERs still require brief metadata locks. SQLite uses Blueprint.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `report_cards` ADD COLUMN `school_term_id` BIGINT UNSIGNED NULL, ALGORITHM=INSTANT, LOCK=DEFAULT');
            DB::statement('ALTER TABLE `report_cards` ADD INDEX `report_card_school_term_idx` (`school_term_id`), ALGORITHM=INPLACE, LOCK=NONE');
            $checks = (int) DB::scalar('SELECT @@SESSION.foreign_key_checks');
            try {
                DB::statement('SET SESSION foreign_key_checks=0');
                DB::statement('ALTER TABLE `report_cards` ADD CONSTRAINT `report_cards_school_term_id_foreign` FOREIGN KEY (`school_term_id`) REFERENCES `school_terms` (`id`) ON DELETE SET NULL, ALGORITHM=INPLACE, LOCK=NONE');
            } finally {
                DB::statement('SET SESSION foreign_key_checks='.$checks);
            }
            return;
        }
        Schema::table('report_cards', function (Blueprint $table) {
            $table->foreignId('school_term_id')->nullable()->index('report_card_school_term_idx')
                ->constrained('school_terms')->nullOnDelete();
        });
    }

    public function down(): void
    {
        \App\Support\SchoolCalendarSchemaGuard::assertUnused();
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `report_cards` DROP FOREIGN KEY `report_cards_school_term_id_foreign`, DROP INDEX `report_card_school_term_idx`, ALGORITHM=INPLACE, LOCK=NONE');
            DB::statement('ALTER TABLE `report_cards` DROP COLUMN `school_term_id`, ALGORITHM=INSTANT, LOCK=DEFAULT');
            return;
        }
        Schema::table('report_cards', function (Blueprint $table) {
            $table->dropForeign(['school_term_id']);
            $table->dropIndex('report_card_school_term_idx');
            $table->dropColumn('school_term_id');
        });
    }
};
