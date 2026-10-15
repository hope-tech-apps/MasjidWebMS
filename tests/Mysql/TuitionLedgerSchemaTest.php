<?php

use Illuminate\Support\Facades\DB;

it('uses integer money portable primary keys unique claims and restrictive financial foreign keys', function () {
    $tables = ['organisation_billing_settings','tuition_accounts','tuition_children','tuition_guardians','tuition_schedules','tuition_checkouts','tuition_lines','tuition_mails'];
    foreach ($tables as $table) {
        $indexes = collect(DB::select('SELECT INDEX_NAME AS name, COLUMN_NAME AS col FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table]));
        expect($indexes->where('name', 'PRIMARY')->pluck('col')->all())->toBe(['id']);
        foreach ($indexes as $index) expect(strlen($index->name))->toBeLessThanOrEqual(64);
    }
    $columns = collect(DB::select("SELECT COLUMN_NAME AS col, DATA_TYPE AS type FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tuition_lines'"))->keyBy('col');
    expect($columns['amount_minor']->type)->toBe('bigint');
    $indexes = collect(DB::select("SELECT INDEX_NAME AS name, COLUMN_NAME AS col FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tuition_lines' ORDER BY SEQ_IN_INDEX"));
    expect($indexes->where('name', 'tuition_line_key_uq')->pluck('col')->all())->toBe(['masjid_id','idempotency_key']);
    $keys = collect(DB::select("SELECT TABLE_NAME AS t, REFERENCED_TABLE_NAME AS parent, DELETE_RULE AS rule FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IN ('contacts','group_memberships') AND TABLE_NAME LIKE 'tuition_%'"));
    expect($keys)->toHaveCount(6);
    foreach ($keys as $key) expect($key->rule)->toBe('RESTRICT');
});
