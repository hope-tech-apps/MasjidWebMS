<?php

use Illuminate\Support\Facades\{DB, Schema};

it('rolls transcripts down and forward with primary keys and restricted academic contact links', function () {
    $migration=require database_path('migrations/2026_10_15_000100_create_transcripts.php');
    $migration->down(); expect(Schema::hasTable('transcript_issues'))->toBeFalse(); $migration->up();
    foreach (['transcript_settings','transcript_courses','transcript_finals','transcript_prior_years','transcript_issues','transcript_share_requests','transcript_received'] as $table) {
        $key=DB::selectOne("SELECT COLUMN_KEY AS k, EXTRA AS e FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME='id'",[$table]);
        expect($key->k)->toBe('PRI')->and($key->e)->toContain('auto_increment');
        foreach(Schema::getIndexes($table) as $index) expect(strlen($index['name']))->toBeLessThanOrEqual(64);
        foreach(Schema::getForeignKeys($table) as $foreign) {
            if (in_array($foreign['foreign_table'],['contacts','group_memberships'],true)) expect(strtolower($foreign['on_delete']))->toBe('restrict');
            if ($foreign['foreign_table']==='users') expect(strtolower($foreign['on_delete']))->toBe('set null');
        }
    }
    expect(Schema::getColumnType('transcript_finals','reason'))->toBe('text');
    expect(Schema::getColumnType('transcript_share_requests','consent_evidence'))->toBe('text');
});
