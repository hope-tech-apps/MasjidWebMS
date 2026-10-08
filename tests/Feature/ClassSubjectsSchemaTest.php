<?php

use App\Models\ClassSubject;
use App\Models\Group;
use App\Models\Masjid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('declares the subject primary key, column types and short named unique indexes', function () {
    $columns = collect(Schema::getColumns('class_subjects'))->keyBy('name');
    expect($columns['id']['auto_increment'])->toBeTrue();
    foreach (['name', 'name_key', 'guide_subject', 'tool'] as $name) expect($columns[$name]['type_name'])->toBeIn(['varchar', 'string']);
    foreach (['previous_name_keys'] as $name) expect($columns[$name]['type_name'])->toBeIn(['text', 'json']);
    $indexes = collect(Schema::getIndexes('class_subjects'))->keyBy('name');
    foreach (['cs_group_name_uq', 'cs_group_tool_uq'] as $name) expect($indexes[$name]['unique'])->toBeTrue();
    expect($indexes['cs_group_position_idx']['columns'])->toBe(['group_id', 'position']);
    foreach (array_keys($indexes->all()) as $name) expect(strlen($name))->toBeLessThanOrEqual(64);
    foreach (['groups' => 'subject_seed_grades', 'group_staff' => 'class_subject_ids'] as $table => $column) {
        expect(Schema::getColumnType($table, $column))->toBeIn(['text', 'json']);
    }
});

it('rolls three empty additive migrations down and up on SQLite', function () {
    $files = glob(database_path('migrations/2026_10_08_000*'));
    foreach (array_reverse($files) as $file) (require $file)->down();
    expect(Schema::hasTable('class_subjects'))->toBeFalse();
    expect(Schema::hasColumn('groups', 'subject_seed_grades'))->toBeFalse();
    expect(Schema::hasColumn('group_staff', 'class_subject_ids'))->toBeFalse();
    foreach ($files as $file) (require $file)->up();
    expect(Schema::hasTable('class_subjects'))->toBeTrue();
});

it('refuses every down with populated feature data', function (string $migration, string $table, array $values) {
    $school = Masjid::create(['name' => 'Schema '.uniqid(), 'email' => uniqid().'@example.invalid', 'phone' => '+1'.random_int(1000000000, 9999999999), 'country_id' => '1', 'city_id' => '1', 'address' => 'Practice', 'latitude' => 0, 'longitude' => 0]);
    $group = Group::factory()->create(['masjid_id' => $school->id]);
    if ($table === 'class_subjects') ClassSubject::create(['masjid_id' => $school->id, 'group_id' => $group->id, 'name' => 'Practice']);
    elseif ($table === 'groups') DB::table('groups')->where('id', $group->id)->update($values);
    else {
        $user = \App\Models\User::factory()->create(['phone' => '+1'.random_int(1000000000, 9999999999)]);
        DB::table('group_staff')->insert(['masjid_id' => $school->id, 'group_id' => $group->id, 'user_id' => $user->id] + $values);
    }
    $file = database_path('migrations/'.$migration.'.php');
    expect(fn () => (require $file)->down())->toThrow(RuntimeException::class, 'Refusing');
})->with([
    ['2026_10_08_000100_create_class_subjects_table', 'class_subjects', []],
    ['2026_10_08_000200_add_class_subject_initialization_to_groups', 'groups', ['subject_seed_grades' => '["1st"]']],
    ['2026_10_08_000300_add_class_subject_assignments_to_group_staff', 'group_staff', ['class_subject_ids' => '[1]']],
]);
