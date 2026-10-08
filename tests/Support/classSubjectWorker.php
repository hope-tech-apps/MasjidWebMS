<?php

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$input = json_decode(trim(fgets(STDIN)), true, 512, JSON_THROW_ON_ERROR);
if (($input['connection']['driver'] ?? null) !== 'mysql' || ! str_ends_with($input['connection']['database'] ?? '', '_test')) exit(2);
config(['database.default' => 'class_subject_worker', 'database.connections.class_subject_worker' => $input['connection'], 'cache.default' => 'array']);
Illuminate\Support\Facades\DB::purge('class_subject_worker');
Illuminate\Support\Facades\DB::connection()->getPdo();
echo "ready\n";
fgets(STDIN);
try {
    if ($input['action'] === 'initialize') {
        App\Support\ClassSubjectInitializer::run(App\Models\Masjid::findOrFail($input['masjid']));
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
    exit(1);
}
