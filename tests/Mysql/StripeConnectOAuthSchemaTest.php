<?php

use Illuminate\Support\Facades\{DB, Schema};

it('rolls OAuth tables down and forward with primary keys and nullable staff attribution', function () {
    $migration = require database_path('migrations/2026_10_15_100000_create_stripe_connect_oauth_tables.php');
    $migration->down();
    expect(Schema::hasTable('stripe_connect_histories'))->toBeFalse();
    expect(Schema::hasTable('stripe_connect_oauth_states'))->toBeFalse();
    $migration->up();
    foreach (['stripe_connect_histories', 'stripe_connect_oauth_states'] as $table) {
        $key = DB::selectOne('SELECT COLUMN_KEY AS k, EXTRA AS e FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', [$table, 'id']);
        expect($key->k)->toBe('PRI')->and($key->e)->toContain('auto_increment');
        foreach (Schema::getIndexes($table) as $index) expect(strlen($index['name']))->toBeLessThanOrEqual(64);
        foreach (Schema::getForeignKeys($table) as $foreign) {
            if ($foreign['foreign_table'] === 'users') expect(strtolower($foreign['on_delete']))->toBe('set null');
        }
    }
});
