<?php

use App\Models\Masjid;
use App\Support\MobileCache;
use App\Support\SchoolCalendarCapabilityWriter;
use App\Support\SchoolSettings;
use Illuminate\Database\QueryException;

/**
 * A lock failure AFTER the switch has committed (the menu-cache invalidation runs in
 * afterCommit) must not be reported as "the calendar is being edited, try again":
 * the switch is saved, and telling the operator otherwise invites a second attempt.
 */
it('does not report a post-commit failure as a refused switch', function () {
    $source = file_get_contents(app_path('Support/SchoolCalendarCapabilityWriter.php'));

    // The busy conversion is skipped once the transaction has committed.
    expect($source)->toContain('$committed = true;')
        ->and($source)->toContain('if ($committed || ! in_array((int) ($e->errorInfo[1] ?? 0), [1205, 1213], true)) throw $e;');

    // And the flag is set before the cache invalidation that can fail.
    expect(strpos($source, '$committed = true;'))->toBeLessThan(strpos($source, 'MobileCache::flushFamily($locked);'));
});
