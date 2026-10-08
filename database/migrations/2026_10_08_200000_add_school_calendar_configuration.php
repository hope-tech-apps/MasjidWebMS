<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Additive, no backfill: only an audited switch-on initializes years.
 * MySQL 8.4 school_years ADD and DROP columns: ALGORITHM=INSTANT,
 * LOCK=DEFAULT (INSTANT rejects explicit LOCK=NONE). Metadata only, with brief
 * metadata locks; fails instead of falling back to COPY. SQLite: Blueprint.
 * school_terms CREATE/DROP: new/unused table only, no existing-table ALTER,
 * with table metadata locks. MySQL uses one CREATE with inline indexes/FKs
 * (Blueprint emits separate FK ALTERs on MySQL); no table copy, even empty.
 * The new table inherits the database charset/collation. SQLite: Blueprint.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `school_years` ADD COLUMN `meeting_weekdays` JSON NULL, ADD COLUMN `term_system` VARCHAR(12) NULL, ALGORITHM=INSTANT, LOCK=DEFAULT');
        } else {
            Schema::table('school_years', function (Blueprint $table) {
                $table->json('meeting_weekdays')->nullable();
                $table->string('term_system', 12)->nullable();
            });
        }
        if (DB::getDriverName() === 'mysql') {
            DB::statement('CREATE TABLE `school_terms` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `masjid_id` BIGINT UNSIGNED NOT NULL, `school_year_id` BIGINT UNSIGNED NOT NULL, `name` VARCHAR(80) NOT NULL, `starts_on` DATE NOT NULL, `ends_on` DATE NOT NULL, `position` TINYINT UNSIGNED NOT NULL, `created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL, UNIQUE KEY `school_term_year_pos_uq` (`school_year_id`, `position`), KEY `school_term_org_dates_idx` (`masjid_id`, `starts_on`, `ends_on`), CONSTRAINT `school_terms_masjid_id_foreign` FOREIGN KEY (`masjid_id`) REFERENCES `masjids` (`id`) ON DELETE CASCADE, CONSTRAINT `school_terms_school_year_id_foreign` FOREIGN KEY (`school_year_id`) REFERENCES `school_years` (`id`) ON DELETE CASCADE) ENGINE=InnoDB');
            return;
        }
        Schema::create('school_terms', function (Blueprint $table) {
            $table->id(); // Required by production's sql_require_primary_key.
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();
            $table->foreignId('school_year_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->unsignedTinyInteger('position');
            $table->timestamps();
            $table->unique(['school_year_id', 'position'], 'school_term_year_pos_uq');
            $table->index(['masjid_id', 'starts_on', 'ends_on'], 'school_term_org_dates_idx');
        });
    }

    /** Check the whole feature before any destructive DDL, on both drivers. */
    public function down(): void
    {
        \App\Support\SchoolCalendarSchemaGuard::assertUnused();
        Schema::drop('school_terms');
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `school_years` DROP COLUMN `meeting_weekdays`, DROP COLUMN `term_system`, ALGORITHM=INSTANT, LOCK=DEFAULT');
        } else {
            Schema::table('school_years', fn (Blueprint $table) => $table->dropColumn(['meeting_weekdays', 'term_system']));
        }
    }
};
