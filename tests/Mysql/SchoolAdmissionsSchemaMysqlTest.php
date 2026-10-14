<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('rolls empty admissions down and forward on MySQL with production primary keys and safe actor retention', function () {
    $migration = require database_path('migrations/2026_10_14_235000_create_school_admissions.php');
    $migration->down(); expect(Schema::hasTable('school_admission_mails'))->toBeFalse();
    $migration->up();
    foreach (['school_admission_settings', 'school_reenrolments', 'school_reenrolment_answers', 'school_reenrolment_deposits', 'school_applications', 'school_application_histories', 'school_admission_mails'] as $table) {
        $column = DB::selectOne("SELECT COLUMN_KEY AS k, EXTRA AS e FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'id'", [$table]);
        expect($column->k)->toBe('PRI')->and($column->e)->toContain('auto_increment');
        foreach (Schema::getIndexes($table) as $index) expect(strlen($index['name']))->toBeLessThanOrEqual(64);
        foreach (Schema::getForeignKeys($table) as $fk) {
            expect($fk['foreign_table'])->not->toBe('group_memberships');
            if ($fk['foreign_table'] === 'users') expect(strtolower($fk['on_delete']))->toBe('set null');
        }
    }
    expect(Schema::getColumnType('school_admission_mails', 'body'))->toBe('longtext');
    expect(Schema::getColumnType('school_application_histories', 'note'))->toBe('text');
});

it('matches a reminder child object by id without requiring its frozen name in the MySQL JSON subset', function () {
    $children = json_encode([['student_contact_id' => 71, 'name' => 'Practice Student']]);
    expect((int) DB::selectOne('SELECT JSON_CONTAINS(?, ?) AS matched', [$children, json_encode(['student_contact_id' => 71])])->matched)->toBe(1);
    expect((int) DB::selectOne('SELECT JSON_CONTAINS(?, ?) AS matched', [$children, json_encode(['student_contact_id' => 72])])->matched)->toBe(0);
});
