<?php

use Illuminate\Support\Facades\{DB, Schema};

it('rolls student sign in down and forward with production keys and restrictive academic retention',function () {
    $migration=require database_path('migrations/2026_10_15_030000_create_student_sign_in.php');
    $migration->down(); expect(Schema::hasTable('student_accounts'))->toBeFalse();
    $migration->up();
    foreach(['student_accounts','student_sign_in_settings','student_sign_in_logs','student_work_settings','student_hand_ins'] as $table) {
        $id=DB::selectOne("SELECT COLUMN_KEY AS k, EXTRA AS e FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'id'",[$table]);
        expect($id->k)->toBe('PRI')->and($id->e)->toContain('auto_increment');
        foreach(Schema::getIndexes($table) as $i) expect(strlen($i['name']))->toBeLessThanOrEqual(64);
    }
    $fk=collect(Schema::getForeignKeys('student_hand_ins'))->firstWhere('foreign_table','group_memberships');
    expect(strtolower($fk['on_delete']))->toBe('restrict');
    expect(Schema::getColumnType('student_hand_ins','link'))->toBe('text');
    expect(DB::selectOne("SELECT COLLATION_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'student_accounts' AND COLUMN_NAME = 'username'")->c)->toEndWith('_ci');
});
