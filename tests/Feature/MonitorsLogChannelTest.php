<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\LogsLikeProduction;
use Tests\TestCase;

/**
 * The `monitors` log channel, under production's environment.
 *
 * Production runs LOG_LEVEL=warning. The scheduled monitors write one line per
 * run, and a clean run writes it at `info`. Until 2026-09-17 the only file in
 * the `monitors` stack was `single`, whose level IS LOG_LEVEL, so every clean
 * line was dropped. A monitor that had stopped running could not be told apart
 * from a healthy one. See config/logging.php.
 *
 * The contract, one level at a time:
 *
 *   level    monitors.log   laravel.log   email
 *   info     kept           dropped       never
 *   warning  kept           kept          never
 *   error    kept           kept          once
 */
class MonitorsLogChannelTest extends TestCase
{
    use LogsLikeProduction;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logLikeProduction();
    }

    protected function tearDown(): void
    {
        $this->forgetProductionLogs();

        parent::tearDown();
    }

    #[Test]
    public function a_clean_line_is_kept_although_the_application_log_filters_it(): void
    {
        Log::channel('monitors')->info('tenancy:canary clean', ['status' => 'clean']);

        // The environment really is production's: the application log drops
        // info. Without this, the next assertion proves nothing.
        $this->assertSame([], $this->loggedLines('laravel.log', 'tenancy:canary clean'),
            'the application log kept an info line, so this test is not running at production\'s LOG_LEVEL');

        $kept = $this->loggedLines('monitors.log', 'tenancy:canary clean');

        $this->assertCount(1, $kept, 'a clean monitor run left no line at LOG_LEVEL=warning');
        $this->assertStringContainsString('.INFO: tenancy:canary clean', $kept[0]);
        $this->assertStringContainsString('"status":"clean"', $kept[0]);

        $this->assertSame([], $this->alertSubjects(), 'a clean run emailed the operator');
    }

    #[Test]
    public function a_partial_line_is_kept_in_both_files_and_never_emails(): void
    {
        Log::channel('monitors')->warning('media:verify partial', ['status' => 'partial']);

        $this->assertCount(1, $this->loggedLines('monitors.log', 'media:verify partial'));

        // Where anyone reading laravel.log has always found it.
        $this->assertCount(1, $this->loggedLines('laravel.log', 'media:verify partial'),
            'a partial run left the application log');

        $this->assertSame([], $this->alertSubjects(), 'a partial run is a ticket, and it emailed like a page');
    }

    #[Test]
    public function a_failed_line_is_kept_in_both_files_and_emails_once(): void
    {
        Log::channel('monitors')->error('backup:check failed', ['status' => 'failed']);

        $this->assertCount(1, $this->loggedLines('monitors.log', 'backup:check failed'));
        $this->assertCount(1, $this->loggedLines('laravel.log', 'backup:check failed'));

        $this->assertSame(['[Manara ERROR] backup:check failed'], $this->alertSubjects());
    }

    #[Test]
    public function the_monitor_file_does_not_follow_the_application_log_level(): void
    {
        // Named separately because it is the one-word regression: somebody
        // "tidies" the new channel to env('LOG_LEVEL') like its neighbours, and
        // the clean line disappears again on production only.
        $this->assertSame('warning', config('logging.channels.single.level'));
        $this->assertSame('info', config('logging.channels.monitors-file.level'));
        $this->assertSame('error', config('logging.channels.ops-alerts.level'),
            'ops-alerts no longer defaults to error, so partial or clean runs may email');
        $this->assertSame(['monitors-file', 'single', 'ops-alerts'], config('logging.channels.monitors.channels'));
    }
}
