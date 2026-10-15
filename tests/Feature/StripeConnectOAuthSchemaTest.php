<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('uses primary keys short indexes and nullable staff foreign keys on both OAuth tables', function () {
    foreach (['stripe_connect_histories', 'stripe_connect_oauth_states'] as $table) {
        $columns = collect(Schema::getColumns($table))->keyBy('name');
        expect($columns['id']['auto_increment'])->toBeTrue();
        expect($columns['actor_user_id']['nullable'])->toBeTrue();
        expect(collect(Schema::getIndexes($table))->contains(fn ($index) => $index['primary'] && $index['columns'] === ['id']))->toBeTrue();
        foreach (Schema::getIndexes($table) as $index) expect(strlen($index['name']))->toBeLessThanOrEqual(64);
        foreach (Schema::getForeignKeys($table) as $foreign) {
            if ($foreign['foreign_table'] === 'users') expect(strtolower($foreign['on_delete']))->toBe('set null');
        }
    }
});

it('rolls the empty additive OAuth schema down and forward', function () {
    $migration = require database_path('migrations/2026_10_15_100000_create_stripe_connect_oauth_tables.php');
    $migration->down();
    expect(Schema::hasTable('stripe_connect_histories'))->toBeFalse();
    expect(Schema::hasTable('stripe_connect_oauth_states'))->toBeFalse();
    $migration->up();
    expect(Schema::hasTable('stripe_connect_histories'))->toBeTrue();
    expect(Schema::hasTable('stripe_connect_oauth_states'))->toBeTrue();
});
