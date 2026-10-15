<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('gives each transcript table a primary key classified columns short indexes and restricted academic links', function () {
    foreach(['transcript_settings','transcript_courses','transcript_finals','transcript_prior_years','transcript_issues','transcript_share_requests','transcript_received'] as $table) {
        expect(collect(Schema::getColumns($table))->keyBy('name')['id']['auto_increment'])->toBeTrue();
        expect(collect(Schema::getIndexes($table))->where('primary',true)->first()['columns'])->toBe(['id']);
        foreach(Schema::getIndexes($table) as $index) expect(strlen($index['name']))->toBeLessThanOrEqual(64);
        expect(config('staging_scrub.drop_rows'))->toHaveKey($table);
        foreach(Schema::getForeignKeys($table) as $key) {
            expect($key['foreign_table'])->not->toBe('group_memberships');
            if ($key['foreign_table']==='contacts') expect(strtolower($key['on_delete']))->toBe('restrict');
            if ($key['foreign_table']==='users') expect(strtolower($key['on_delete']))->toBe('set null');
        }
    }
    foreach(['reason'=>'transcript_finals','consent_evidence'=>'transcript_share_requests','school_name'=>'transcript_prior_years'] as $column=>$table) expect(Schema::getColumnType($table,$column))->toBe('text');
    expect(collect(Schema::getForeignKeys('transcript_received'))->pluck('foreign_table')->all())->toBe(['users','contacts','masjids']);
});

it('rolls empty transcript storage down and forward without partial tables',function () {
    $migration=require database_path('migrations/2026_10_15_000100_create_transcripts.php');
    $migration->down();expect(Schema::hasTable('transcript_issues'))->toBeFalse();$migration->up();
    expect(Schema::hasColumn('transcript_courses','on_transcript'))->toBeTrue();
    expect(Schema::hasTable('transcript_issues'))->toBeTrue();
});
