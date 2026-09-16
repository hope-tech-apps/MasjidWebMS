<?php

/*
|--------------------------------------------------------------------------
| One suite per checkout
|--------------------------------------------------------------------------
|
| Every process run from this directory shares one set of fake disks:
| Storage::fake('local') roots at storage/framework/testing/disks/local, and
| its first act is to EMPTY that directory. A second `php artisan test` started
| in the same checkout — a full run, or a single --filter — therefore deletes
| the files the first run's tests just wrote, in the middle of those tests.
|
| That is not hypothetical. On 2026-09-16 two full suites overlapped in
| /root/manara-ci-notes, and DemoSchoolSeederTest failed with
| "Unable to find a file or directory at path [group-media/1/1/...]" — the
| database row still there, the bytes gone. The same tree passed 3921/3921
| when run alone. It read as an order dependency and was not one.
|
| So the second run refuses to start rather than quietly corrupting the first.
| The lock is advisory and belongs to this process: it is released when the
| process exits, however it exits. Run concurrent suites from separate
| checkouts (cp -a the tree), which have separate fake disks.
|
*/

require __DIR__.'/../vendor/autoload.php';

(static function (): void {
    // `--parallel` workers each load this file. Laravel already gives every
    // worker its own fake-disk root (suffixed with TEST_TOKEN), so they do not
    // collide and must not lock each other out.
    if (getenv('LARAVEL_PARALLEL_TESTING') || getenv('TEST_TOKEN') !== false) {
        return;
    }

    $directory = __DIR__.'/../storage/framework/testing';

    if (! is_dir($directory)) {
        @mkdir($directory, 0775, true);
    }

    $handle = @fopen($directory.'/.suite.lock', 'c');

    if ($handle === false) {
        // No lock file means no guard, not no suite: a read-only checkout
        // should still be able to run tests that never touch a fake disk.
        fwrite(STDERR, "tests/bootstrap.php: could not open the suite lock; running unguarded.\n");

        return;
    }

    if (! flock($handle, LOCK_EX | LOCK_NB)) {
        fwrite(STDERR, implode(PHP_EOL, [
            '',
            'Another test run is already using this checkout ('.realpath(dirname(__DIR__)).').',
            'Two runs here share storage/framework/testing/disks and wipe each other\'s files',
            'mid-test. Wait for it to finish, or run from a separate copy of the tree.',
            '',
        ]).PHP_EOL);

        exit(1);
    }

    // Held for the life of the process.
    $GLOBALS['__test_suite_lock'] = $handle;
})();
