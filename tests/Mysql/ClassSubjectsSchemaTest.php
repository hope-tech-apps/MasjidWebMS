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

it('pins required insert columns against the real mysql migrations', function () {
    $required = [
        'groups' => ['masjid_id', 'name', 'slug'],
        'masjids' => ['name', 'email', 'phone', 'country_id', 'city_id', 'address', 'latitude', 'longitude'],
        'users' => ['name', 'email', 'password', 'phone'],
        'group_staff' => ['masjid_id', 'group_id', 'user_id'],
        'school_subjects' => ['masjid_id', 'name', 'name_key'],
        'class_subjects' => ['masjid_id', 'group_id', 'name', 'name_key'],
        'masjid_user' => ['masjid_id', 'user_id', 'role'],
        'group_memberships' => ['masjid_id', 'group_id', 'contact_id', 'role'],
        'contacts' => ['masjid_id', 'first_name', 'last_name'],
        'masjid_capability_changes' => ['masjid_id', 'capability', 'enabled_before', 'enabled_after'],
        'account_invite_tokens' => ['email', 'token'],
        'password_reset_tokens' => ['email', 'token'],
        'lesson_plans' => ['masjid_id', 'group_id', 'session_date', 'body'],
        'class_assignments' => ['masjid_id', 'group_id', 'title', 'points_possible', 'assigned_on'],
        'assignment_scores' => ['masjid_id', 'group_id', 'class_assignment_id', 'group_membership_id', 'status'],
        'model_has_roles' => ['role_id', 'model_type', 'model_id'],
    ];
    foreach ($required as $table => $names) {
        $actual = collect(DB::select('SHOW COLUMNS FROM `'.$table.'`'))->filter(fn ($column) =>
            $column->Null === 'NO' && $column->Default === null && ! str_contains($column->Extra, 'auto_increment') && ! str_contains($column->Extra, 'GENERATED'))
            ->pluck('Field')->all();
        sort($actual); sort($names);
        expect($actual, $table)->toBe($names);
    }
});

it('uses nullable unsigned linked ids indexed restrict foreign keys and instant provenance types', function () {
    foreach (['class_assignments' => 'ca_class_subject_idx', 'lesson_plans' => 'lp_class_subject_idx'] as $table => $index) {
        $columns = collect(DB::select('SHOW COLUMNS FROM `'.$table.'`'))->keyBy('Field');
        expect($columns['class_subject_id']->Type, $table.'.class_subject_id')->toBe('bigint unsigned');
        expect($columns['class_subject_id']->Null, $table.'.class_subject_id')->toBe('YES');
        expect($columns['class_subject_link_checked_at']->Type, $table.'.class_subject_link_checked_at')->toBe('datetime');
        $indexes = collect(Schema::getIndexes($table))->keyBy('name');
        expect($indexes[$index]['columns'], $table.'.'.$index)->toBe(['class_subject_id']);
        $foreign = collect(Schema::getForeignKeys($table))->first(fn ($fk) => $fk['columns'] === ['class_subject_id']);
        expect($foreign['foreign_table'], $table.'.class_subject_id')->toBe('class_subjects');
        expect(strtolower($foreign['on_delete']), $table.'.class_subject_id')->toBe('restrict');
    }
    $columns = collect(DB::select('SHOW COLUMNS FROM `group_staff`'))->keyBy('Field');
    foreach (['class_subjects_translated_from' => 'json', 'class_subject_ids_edited_at' => 'datetime'] as $name => $type) {
        expect($columns[$name]->Type, 'group_staff.'.$name)->toBe($type);
        expect($columns[$name]->Null, 'group_staff.'.$name)->toBe('YES');
    }
});
