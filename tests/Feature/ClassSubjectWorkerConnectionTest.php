<?php

use App\Models\Masjid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('uses the same PDO for the worker mutex and hydrated model saves', function () {
    $original = config('database.default');
    try {
        \Tests\Support\ClassSubjectWorkerConnection::configure(DB::connection()->getConfig());
        expect(DB::connection()->getName())->toBe('class_subject_worker');
        $model = (new Masjid)->newFromBuilder(['id' => 1], DB::connection()->getName());
        expect($model->getConnection()->getPdo())->toBe(DB::connection()->getPdo());
        \Illuminate\Support\Facades\Schema::create('masjids', function ($table) {
            $table->id(); $table->string('name');
        });
        DB::table('masjids')->insert(['id' => 1, 'name' => 'Before']);
        $model->timestamps = false;
        DB::beginTransaction();
        $model->name = 'After'; $model->save();
        expect(DB::table('masjids')->value('name'))->toBe('After');
        DB::rollBack();
        expect(DB::table('masjids')->value('name'))->toBe('Before');
    } finally {
        DB::purge('class_subject_worker');
        config(['database.default' => $original]);
    }
});
