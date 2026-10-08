<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\{DB, Schema};
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SchoolCalendarOnlineMigrationTest extends TestCase
{
    #[Test]
    public function report_card_mysql_alters_explicitly_refuse_table_copies_and_restore_foreign_key_checks(): void
    {
        Schema::shouldReceive('table')->andReturnNull();
        Schema::shouldReceive('create')->andReturnNull();
        $sql = [];
        DB::shouldReceive('getDriverName')->andReturn('mysql');
        DB::shouldReceive('scalar')->with('SELECT @@SESSION.foreign_key_checks')->andReturn(1);
        DB::shouldReceive('statement')->andReturnUsing(function ($statement) use (&$sql) { $sql[] = $statement; return true; });
        Schema::shouldReceive('table')->andReturnNull();
        (require database_path('migrations/2026_10_08_210000_add_report_card_school_term_link.php'))->up();
        $this->assertSame([
            'ALTER TABLE `report_cards` ADD COLUMN `school_term_id` BIGINT UNSIGNED NULL, ALGORITHM=INSTANT, LOCK=DEFAULT',
            'ALTER TABLE `report_cards` ADD INDEX `report_card_school_term_idx` (`school_term_id`), ALGORITHM=INPLACE, LOCK=NONE',
            'SET SESSION foreign_key_checks=0',
            'ALTER TABLE `report_cards` ADD CONSTRAINT `report_cards_school_term_id_foreign` FOREIGN KEY (`school_term_id`) REFERENCES `school_terms` (`id`) ON DELETE SET NULL, ALGORITHM=INPLACE, LOCK=NONE',
            'SET SESSION foreign_key_checks=1',
        ], $sql);
    }

    #[Test]
    public function failed_foreign_key_alter_restores_the_session_setting(): void
    {
        Schema::shouldReceive('table')->andReturnNull();
        Schema::shouldReceive('create')->andReturnNull();
        $sql = [];
        DB::shouldReceive('getDriverName')->andReturn('mysql');
        DB::shouldReceive('scalar')->andReturn(1);
        DB::shouldReceive('statement')->andReturnUsing(function ($statement) use (&$sql) {
            $sql[] = $statement;
            if (str_contains($statement, 'ADD CONSTRAINT')) throw new \RuntimeException('Simulated ALTER failure');
            return true;
        });
        Schema::shouldReceive('table')->andReturnNull();
        try {
            (require database_path('migrations/2026_10_08_210000_add_report_card_school_term_link.php'))->up();
            $this->fail('Expected ALTER failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated ALTER failure', $e->getMessage());
        }
        $this->assertSame('SET SESSION foreign_key_checks=1', end($sql));
    }

    #[Test]
    public function school_year_mysql_adds_both_nullable_columns_instantly(): void
    {
        Schema::shouldReceive('table')->andReturnNull();
        Schema::shouldReceive('create')->andReturnNull();
        $sql = [];
        DB::shouldReceive('getDriverName')->andReturn('mysql');
        DB::shouldReceive('statement')->andReturnUsing(function ($statement) use (&$sql) { $sql[] = $statement; return true; });
        Schema::shouldReceive('table')->andReturnNull();
        (require database_path('migrations/2026_10_08_200000_add_school_calendar_configuration.php'))->up();
        $this->assertSame([
            'ALTER TABLE `school_years` ADD COLUMN `meeting_weekdays` JSON NULL, ADD COLUMN `term_system` VARCHAR(12) NULL, ALGORITHM=INSTANT, LOCK=DEFAULT',
            'CREATE TABLE `school_terms` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `masjid_id` BIGINT UNSIGNED NOT NULL, `school_year_id` BIGINT UNSIGNED NOT NULL, `name` VARCHAR(80) NOT NULL, `starts_on` DATE NOT NULL, `ends_on` DATE NOT NULL, `position` TINYINT UNSIGNED NOT NULL, `created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL, UNIQUE KEY `school_term_year_pos_uq` (`school_year_id`, `position`), KEY `school_term_org_dates_idx` (`masjid_id`, `starts_on`, `ends_on`), CONSTRAINT `school_terms_masjid_id_foreign` FOREIGN KEY (`masjid_id`) REFERENCES `masjids` (`id`) ON DELETE CASCADE, CONSTRAINT `school_terms_school_year_id_foreign` FOREIGN KEY (`school_year_id`) REFERENCES `school_years` (`id`) ON DELETE CASCADE) ENGINE=InnoDB',
        ], $sql);
    }
}
