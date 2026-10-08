<?php

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$input = json_decode(trim(fgets(STDIN)), true, 512, JSON_THROW_ON_ERROR);
if (($input['connection']['driver'] ?? null) !== 'mysql' || ! str_ends_with($input['connection']['database'] ?? '', '_test')) exit(2);
Tests\Support\ClassSubjectWorkerConnection::configure($input['connection']);
Illuminate\Support\Facades\DB::statement('SET SESSION innodb_lock_wait_timeout = 5');
echo "ready\n";
fgets(STDIN);
try {
    if ($input['action'] === 'initialize') {
        App\Support\ClassSubjectInitializer::run(App\Models\Masjid::findOrFail($input['masjid']), false, true);
    } elseif ($input['action'] === 'create') {
        app(App\Support\TenantContext::class)->set($input['masjid']);
        App\Models\Group::create(['name' => 'Concurrent Practice Class', 'slug' => 'concurrent-practice-'.Illuminate\Support\Str::uuid(), 'kind' => 'class']);
    } elseif ($input['action'] === 'plan-subject') {
        app(App\Support\TenantContext::class)->set($input['masjid']);
        Illuminate\Support\Facades\Auth::login(App\Models\User::findOrFail($input['user']));
        App\Models\LessonPlan::findOrFail($input['plan'])->update(['class_subject_id' => $input['subject']]);
    } elseif ($input['action'] === 'subject-rename') {
        app(App\Support\TenantContext::class)->set($input['masjid']);
        App\Models\ClassSubject::findOrFail($input['subject'])->update(['name' => 'Reading']);
    } else {
        app(App\Support\TenantContext::class)->set($input['masjid']);
        $request = App\Http\Requests\Admin\Groups\SaveClassSubjectRequest::create('/subjects/'.$input['subject'], 'PUT', ['tool' => 'hifdh']);
        $request->setContainer($app)->setRedirector($app->make('redirect'));
        $request->validateResolved();
        (new App\Http\Controllers\AdminDashboard\ClassSubjectsController)->update($request, $input['masjid'], $input['group'], $input['subject']);
    }
    echo "ok\n";
} catch (Illuminate\Validation\ValidationException $e) {
    echo "refused\n";
} catch (Throwable $e) {
    echo get_class($e)."\n";
    if ($e instanceof Illuminate\Database\QueryException) {
        $identifiers = Tests\Support\ClassSubjectWorkerFailure::identifiers($e);
        [$table, $column] = match ($input['action']) {
            'initialize' => ['masjids', 'id'], 'create' => ['groups', 'slug'],
            'plan-subject' => ['lesson_plans', 'class_subject_id'], 'subject-rename' => ['class_subjects', 'name'],
            default => ['class_subjects', 'tool'],
        };
        $identifiers['table'] ??= $table;
        $identifiers['column'] ??= $identifiers['table'] === 'masjids' ? 'id' : $column;
        fwrite(STDERR, json_encode($identifiers)."\n");
    }
    exit(1);
}
