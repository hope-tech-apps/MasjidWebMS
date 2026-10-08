<?php

use App\Models\ClassSubject;
use App\Models\Group;
use App\Models\Masjid;
use App\Models\SchoolSubject;
use App\Support\ClassSubjectInitializer;
use Illuminate\Support\Facades\DB;

it('serializes concurrent initialization and concurrent requests to hold a tool', function (string $action) {
    $org = null; $workers = [];
    try {
        $org = Masjid::create(['name' => 'Concurrency '.uniqid(), 'email' => uniqid().'@example.invalid', 'phone' => '+1'.random_int(1000000000, 9999999999), 'country_id' => '1', 'city_id' => '1', 'address' => 'Practice', 'latitude' => 0, 'longitude' => 0, 'org_type' => 'school']);
        $group = Group::factory()->create(['masjid_id' => $org->id, 'kind' => 'class']);
        foreach (['Health', 'Science'] as $name) SchoolSubject::create(['masjid_id' => $org->id, 'name' => $name]);
        if ($action === 'holds') ClassSubjectInitializer::run($org, false, true);
        $subjects = ClassSubject::where('group_id', $group->id)->orderBy('id')->pluck('id');
        for ($i = 0; $i < 2; $i++) {
            $process = proc_open([PHP_BINARY, base_path('tests/Support/classSubjectWorker.php')], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            expect(is_resource($process))->toBeTrue();
            $workers[] = [$process, $pipes];
            stream_set_timeout($pipes[1], 15);
            fwrite($pipes[0], json_encode(['connection' => DB::connection()->getConfig(), 'action' => $action, 'masjid' => $org->id, 'group' => $group->id, 'subject' => $subjects[$i] ?? null])."\n");
            expect(trim((string) fgets($pipes[1])))->toBe('ready');
        }
        foreach ($workers as [$process, $pipes]) fwrite($pipes[0], "go\n");
        $results = [];
        foreach ($workers as [$process, $pipes]) $results[] = trim((string) fgets($pipes[1]));
        sort($results);
        expect($results)->toBe($action === 'holds' ? ['ok', 'refused'] : ['ok', 'ok']);
        expect(ClassSubject::where('group_id', $group->id)->count())->toBe(2);
        if ($action === 'holds') expect(ClassSubject::where('group_id', $group->id)->where('tool', 'hifdh')->count())->toBe(1);
        else expect($group->fresh()->class_subjects_initialized_at)->not->toBeNull();
    } finally {
        foreach ($workers as [$process, $pipes]) {
            foreach ($pipes as $pipe) fclose($pipe);
            proc_close($process);
        }
        if ($org !== null) {
            DB::table('masjid_capability_changes')->where('masjid_id', $org->id)->delete();
            DB::table('groups')->where('masjid_id', $org->id)->delete();
            DB::table('school_subjects')->where('masjid_id', $org->id)->delete();
            DB::table('masjids')->where('id', $org->id)->delete();
        }
    }
})->with(['initialize', 'holds']);
