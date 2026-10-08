<?php

// A fresh process makes the 128 MB ceiling independent of preceding Pest tests.
// It owns an in-memory SQLite database and never uses the checkout's fake disks.
use App\Models\{Group, GroupStaff, Masjid, SchoolSubject, User};
use App\Support\ClassSubjectInitializer;
use Illuminate\Support\Facades\{Artisan, DB, Event};

require dirname(__DIR__, 2).'/vendor/autoload.php';
foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'PULSE_ENABLED' => 'false', 'BCRYPT_ROUNDS' => '4', 'SMS_DRIVER' => 'none'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
DB::purge('sqlite');
ob_start();
try { Artisan::call('migrate', ['--force' => true]); }
finally { ob_end_clean(); }
$org = Masjid::create(['name' => 'Practice School', 'email' => 'preview@example.invalid', 'phone' => '+15555550100', 'country_id' => '1', 'city_id' => '1', 'address' => 'Practice', 'latitude' => 0, 'longitude' => 0, 'org_type' => 'school', 'crm_enabled' => true]);
$group = Group::factory()->create(['masjid_id' => $org->id, 'kind' => 'class']);
foreach (['Arabic', 'Science', 'ELA', "Qur'an"] as $name) SchoolSubject::create(['masjid_id' => $org->id, 'name' => $name]);
foreach (range(1, 100) as $i) {
    $user = User::factory()->create(['type' => 'Teacher', 'phone' => '+1555555'.str_pad((string) $i, 4, '0', STR_PAD_LEFT)]);
    GroupStaff::create(['masjid_id' => $org->id, 'group_id' => $group->id, 'user_id' => $user->id, 'subjects' => ['quran']]);
}
$row = ['masjid_id' => $org->id, 'group_id' => $group->id, 'title' => 'Practice', 'subject' => "Qur'an & Islamic Studies", 'subject_key' => 'quran & islamic studies', 'assigned_on' => '2026-10-08', 'scale' => 'points', 'points_possible' => 10];
foreach (range(1, 50) as $i) DB::table('class_assignments')->insert(array_fill(0, 200, $row));
$transactions = 0;
Event::listen(Illuminate\Database\Events\TransactionBeginning::class, function () use (&$transactions) { $transactions++; });
$measure = function (callable $read) use (&$transactions) {
    DB::flushQueryLog(); DB::enableQueryLog();
    memory_reset_peak_usage();
    $start = memory_get_usage(true); $usedStart = memory_get_usage(false); $time = microtime(true);
    try { $losses = $read(); $queries = DB::getQueryLog(); }
    finally { DB::disableQueryLog(); }
    $readOnly = $transactions === 0;
    foreach ($queries as $query) $readOnly = $readOnly && str_starts_with(strtolower($query['query']), 'select') && ! preg_match('/for update|lock in share mode/i', $query['query']);
    return ['staff' => 100, 'work' => 10000, 'losses' => $losses, 'start_bytes' => $start, 'peak_bytes' => memory_get_peak_usage(true), 'extra_bytes' => memory_get_peak_usage(true) - $start, 'used_extra_bytes' => memory_get_peak_usage(false) - $usedStart, 'queries' => count($queries), 'seconds' => microtime(true) - $time, 'read_only' => $readOnly];
};
$preview = $measure(fn () => count(ClassSubjectInitializer::run($org, true)[0]['losses']));
$command = $measure(function () use ($org) {
    $output = new class extends Symfony\Component\Console\Output\Output {
        public int $losses = 0;
        protected function doWrite(string $message, bool $newline): void
        {
            if (str_starts_with(trim($message), 'LOSS Teacher #')) $this->losses++;
        }
    };
    $exit = Artisan::call('class-subjects:initialize', ['--masjid' => $org->id, '--dry-run' => true], $output);
    if ($exit !== 0) throw new RuntimeException('Preview command failed.');
    return $output->losses;
});
echo json_encode(['measurement' => $preview, 'command' => $command], JSON_THROW_ON_ERROR);
