<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('gives every admissions table a primary key short indexes and fully classified staging policy', function () {
    foreach (['school_admission_settings', 'school_reenrolments', 'school_reenrolment_answers', 'school_reenrolment_deposits', 'school_applications', 'school_application_histories', 'school_admission_mails'] as $table) {
        $columns = collect(Schema::getColumns($table))->keyBy('name');
        expect($columns['id']['auto_increment'])->toBeTrue();
        expect(collect(Schema::getIndexes($table))->where('primary', true)->first()['columns'])->toBe(['id']);
        foreach (Schema::getIndexes($table) as $index) expect(strlen($index['name']))->toBeLessThanOrEqual(64);
        expect(config('staging_scrub.drop_rows'))->toHaveKey($table);
        foreach (Schema::getForeignKeys($table) as $fk) {
            expect($fk['foreign_table'])->not->toBe('group_memberships');
            if ($fk['foreign_table'] === 'users') expect(strtolower($fk['on_delete']))->toBe('set null');
        }
    }
    foreach (['accepted_text', 'wait_listed_text', 'declined_text'] as $column) expect(Schema::getColumnType('school_admission_settings', $column))->toBe('text');
    expect(Schema::getColumnType('school_admission_mails', 'body'))->toBe('text');
    expect(Schema::getColumnType('school_admission_mails', 'subject'))->toBe('text');
    expect(Schema::getColumnType('school_application_histories', 'note'))->toBe('text');
});

it('rolls the empty admissions migration down and forward cleanly', function () {
    $migration = require database_path('migrations/2026_10_14_235000_create_school_admissions.php');
    $migration->down(); expect(Schema::hasTable('school_admission_mails'))->toBeFalse(); expect(Schema::hasTable('school_reenrolments'))->toBeFalse();
    $migration->up(); expect(Schema::hasTable('school_admission_mails'))->toBeTrue(); expect(Schema::hasTable('school_reenrolments'))->toBeTrue();
});

it('enforces one deposit response per campaign and child in storage', function () {
    $index = collect(Schema::getIndexes('school_reenrolment_deposits'))->firstWhere('name', 'reenrol_deposit_child_uq');
    expect($index['unique'] ?? false)->toBeTrue();
});

it('rolls the empty deposit guard down and forward without changing the original admissions migration', function () {
    $migration = require database_path('migrations/2026_10_14_235100_guard_school_admission_deposits.php');
    $migration->down(); expect(Schema::hasColumn('school_admission_mails', 'reminder_batch'))->toBeFalse();
    $migration->up(); expect(Schema::hasColumn('school_admission_mails', 'reminder_batch'))->toBeTrue();
    expect(collect(Schema::getIndexes('school_reenrolment_deposits'))->firstWhere('name', 'reenrol_deposit_child_uq')['unique'])->toBeTrue();
});
