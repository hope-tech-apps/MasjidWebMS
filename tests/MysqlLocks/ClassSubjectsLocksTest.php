<?php

use App\Models\ClassSubject;
use App\Models\Group;
use App\Models\Masjid;
use App\Models\SchoolSubject;
use App\Support\ClassSubjectInitializer;
use Illuminate\Support\Facades\DB;

it('serializes concurrent initialization class creation and requests to hold a tool', function (string $action) {
    expect(DB::connection()->transactionLevel())->toBe(0);
    $org = null; $workers = [];
    try {
        $org = Masjid::create(['name' => 'Concurrency '.uniqid(), 'email' => uniqid().'@example.invalid', 'phone' => '+1'.random_int(1000000000, 9999999999), 'country_id' => '1', 'city_id' => '1', 'address' => 'Practice', 'latitude' => 0, 'longitude' => 0, 'org_type' => 'school']);
        $group = Group::factory()->create(['masjid_id' => $org->id, 'kind' => 'class']);
        foreach (['Health', 'Science'] as $name) SchoolSubject::create(['masjid_id' => $org->id, 'name' => $name]);
        if ($action !== 'initialize') ClassSubjectInitializer::run($org, false, true);
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
        foreach ($workers as [$process, $pipes]) {
            $result = trim((string) fgets($pipes[1]));
            if ($result !== 'ok' && $result !== 'refused') {
                stream_set_blocking($pipes[2], false);
                $result .= ' '.trim(stream_get_contents($pipes[2]));
            }
            $results[] = $result;
        }
        sort($results);
        expect($results)->toBe($action === 'holds' ? ['ok', 'refused'] : ['ok', 'ok']);
        expect(ClassSubject::where('group_id', $group->id)->count())->toBe(2);
        if ($action === 'create') {
            expect(Group::where('masjid_id', $org->id)->count())->toBe(3);
            expect(ClassSubject::where('masjid_id', $org->id)->count())->toBe(6);
            expect(ClassSubjectInitializer::ready($org->fresh()))->toBeTrue();
        }
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
})->with(['initialize', 'holds', 'create']);

it('restores a ready class from current restrictions after an older read view was established', function () {
    expect(DB::connection()->transactionLevel())->toBe(0);
    $org = null; $user = null;
    try {
        $org = Masjid::create(['name' => 'Snapshot School', 'email' => uniqid().'@example.invalid', 'phone' => '+1'.random_int(1000000000, 9999999999), 'country_id' => '1', 'city_id' => '1', 'address' => 'Practice', 'latitude' => 0, 'longitude' => 0, 'org_type' => 'school']);
        $group = Group::factory()->create(['masjid_id' => $org->id, 'kind' => 'class']);
        foreach (['Arabic', 'Science'] as $name) SchoolSubject::create(['masjid_id' => $org->id, 'name' => $name]);
        ClassSubjectInitializer::run($org, false, true);
        $user = \App\Models\User::factory()->create(['type' => 'Teacher', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
        $staff = \App\Models\GroupStaff::create(['masjid_id' => $org->id, 'group_id' => $group->id, 'user_id' => $user->id, 'role' => 'teacher']);
        $group->delete();
        config(['database.connections.class_subject_peer' => array_replace(DB::connection()->getConfig(), ['name' => 'class_subject_peer'])]);
        DB::beginTransaction();
        expect(\App\Models\GroupStaff::findOrFail($staff->id)->subjects)->toBeNull();
        // Commit after this transaction's consistent read, before restoration takes its mutex.
        DB::connection('class_subject_peer')->table('group_staff')->where('id', $staff->id)->update(['subjects' => '["arabic"]']);
        $group->restore();
        $arabic = ClassSubject::where('group_id', $group->id)->where('tool', 'arabic_letters')->firstOrFail();
        expect($staff->fresh()->class_subject_ids)->toBe([(int) $arabic->id]);
        DB::commit();
        expect(ClassSubjectInitializer::ready($org->fresh()))->toBeTrue();
    } finally {
        while (DB::connection()->transactionLevel() > 0) DB::rollBack();
        DB::purge('class_subject_peer');
        if ($org !== null) {
            DB::table('masjid_capability_changes')->where('masjid_id', $org->id)->delete();
            DB::table('groups')->where('masjid_id', $org->id)->delete();
            DB::table('school_subjects')->where('masjid_id', $org->id)->delete();
            DB::table('masjids')->where('id', $org->id)->delete();
        }
        if ($user !== null) DB::table('users')->where('id', $user->id)->delete();
    }
});

it('does not gap lock another schools staff insert when initializing an empty class', function () {
    expect(DB::connection()->transactionLevel())->toBe(0);
    $orgs = []; $user = null;
    try {
        foreach (['A', 'B'] as $label) $orgs[] = Masjid::create(['name' => 'Gap Practice '.$label.' '.uniqid(), 'email' => uniqid().'@example.invalid', 'phone' => '+1'.random_int(1000000000, 9999999999), 'country_id' => '1', 'city_id' => '1', 'address' => 'Practice', 'latitude' => 0, 'longitude' => 0, 'org_type' => 'school']);
        $a = Group::factory()->create(['masjid_id' => $orgs[0]->id, 'kind' => 'class']);
        $b = Group::factory()->create(['masjid_id' => $orgs[1]->id, 'kind' => 'class']);
        $user = \App\Models\User::factory()->create(['type' => 'Teacher', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
        config(['database.connections.class_subject_gap_peer' => array_replace(DB::connection()->getConfig(), ['name' => 'class_subject_gap_peer'])]);
        $peer = DB::connection('class_subject_gap_peer');
        $peer->statement('SET SESSION innodb_lock_wait_timeout = 2');
        DB::beginTransaction();
        ClassSubjectInitializer::run($orgs[0]);
        // Keep A's locks open. B must INSERT now, before A commits/rolls back.
        $peer->table('group_staff')->insert(['masjid_id' => $orgs[1]->id, 'group_id' => $b->id, 'user_id' => $user->id, 'role' => 'teacher']);
        expect($peer->table('group_staff')->where('group_id', $b->id)->count())->toBe(1);
        DB::rollBack();
    } finally {
        while (DB::connection()->transactionLevel() > 0) DB::rollBack();
        DB::purge('class_subject_gap_peer');
        foreach ($orgs as $org) {
            DB::table('masjid_capability_changes')->where('masjid_id', $org->id)->delete();
            DB::table('groups')->where('masjid_id', $org->id)->delete();
            DB::table('masjids')->where('id', $org->id)->delete();
        }
        if ($user !== null) DB::table('users')->where('id', $user->id)->delete();
    }
});
