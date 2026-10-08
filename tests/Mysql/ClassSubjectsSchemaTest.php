<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('uses production sized columns a bigint auto increment primary key and named indexes', function () {
    $columns = collect(DB::select('SHOW COLUMNS FROM class_subjects'))->keyBy('Field');
    expect($columns['id']->Type)->toBe('bigint unsigned');
    expect($columns['id']->Key)->toBe('PRI');
    expect($columns['id']->Extra)->toContain('auto_increment');
    expect($columns['name']->Type)->toBe('varchar(64)');
    expect($columns['name_key']->Type)->toBe('varchar(64)');
    expect($columns['guide_subject']->Type)->toBe('varchar(64)');
    expect($columns['tool']->Type)->toBe('varchar(32)');
    expect($columns['position']->Type)->toBe('smallint unsigned');
    expect($columns['previous_name_keys']->Type)->toBe('json');
    expect(Schema::getColumnType('groups', 'subject_seed_grades'))->toBe('json');
    expect(Schema::getColumnType('group_staff', 'class_subject_ids'))->toBe('json');
    expect(Schema::getColumnType('group_staff', 'class_subject_legacy_snapshot'))->toBe('json');
    $indexes = collect(Schema::getIndexes('class_subjects'))->keyBy('name');
    expect($indexes['cs_group_name_uq']['unique'])->toBeTrue();
    expect($indexes['cs_group_tool_uq']['unique'])->toBeTrue();
    expect($indexes['cs_group_position_idx']['columns'])->toBe(['group_id', 'position']);
});
