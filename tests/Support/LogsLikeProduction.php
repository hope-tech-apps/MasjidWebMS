<?php

namespace Tests\Support;

use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Log;

/**
 * Logging configured the way production configures it, with the files in a
 * scratch directory, so a test can read what production would have KEPT.
 *
 * The monitor suites used to mock the Log facade. A mock proves which level a
 * command emitted and says nothing about whether any handler keeps it. That is
 * how every clean `tenancy:canary` and `media:verify` line was dropped on
 * production (LOG_LEVEL=warning) under a green suite.
 *
 * So this does not set a level by hand. It evaluates config/logging.php again
 * with production's environment, so a channel that quietly follows LOG_LEVEL
 * is filtered here exactly as it is there.
 *
 * Mail goes to the `array` transport. MailFake::raw() is a no-op, and
 * OpsAlertMailHandler sends with Mail::raw(), so Mail::fake() would see no
 * alert at all and a "never emails" assertion would pass however broken the
 * channel was.
 */
trait LogsLikeProduction
{
    private ?string $logScratch = null;

    /**
     * The relevant keys of production's .env, as read on 2026-09-17.
     * OPS_ALERT_LEVEL is unset there, so the channel's own default applies.
     * The address is a stand-in: production has a real one, which is what makes
     * ops-alerts live rather than inert.
     */
    protected function logLikeProduction(): void
    {
        $this->logScratch = sys_get_temp_dir().'/monitor-logs-'.uniqid('', true);
        mkdir($this->logScratch, 0777, true);

        $logging = $this->withEnvironment([
            'LOG_CHANNEL' => 'stack',
            'LOG_STACK' => 'single',
            'LOG_LEVEL' => 'warning',
            'OPS_ALERT_LEVEL' => null,
            'OPS_ALERT_EMAIL' => 'ops@example.test',
        ], static fn () => require config_path('logging.php'));

        foreach ($logging['channels'] as $name => $channel) {
            if (isset($channel['path'])) {
                $logging['channels'][$name]['path'] = $this->logScratch.'/'.basename($channel['path']);
            }
        }

        config(['logging' => $logging]);

        // A channel resolved before this point was built from the old config.
        foreach (array_keys($logging['channels']) as $name) {
            Log::forgetChannel($name);
        }

        // A message with no From is refused by Symfony, and the handler swallows
        // that, so a missing address would read as "no alert was sent".
        config(['mail.default' => 'array', 'mail.from.address' => 'manara@example.test']);
        app('mail.manager')->forgetMailers();
    }

    protected function forgetProductionLogs(): void
    {
        if ($this->logScratch !== null && is_dir($this->logScratch)) {
            exec('rm -rf '.escapeshellarg($this->logScratch));
        }

        $this->logScratch = null;
    }

    /**
     * The lines of one scratch log file that contain $needle. A file that was
     * never written reads as no lines.
     *
     * @return list<string>
     */
    protected function loggedLines(string $file, string $needle): array
    {
        $path = $this->logScratch.'/'.$file;

        if (! is_file($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];

        return array_values(array_filter($lines, static fn (string $line) => str_contains($line, $needle)));
    }

    /** @return list<string> the subject of every email sent so far */
    protected function alertSubjects(): array
    {
        $transport = app('mail.manager')->mailer()->getSymfonyTransport();

        $this->assertInstanceOf(ArrayTransport::class, $transport,
            'mail is not on the array transport, so this test cannot see alerts');

        return $transport->messages()
            ->map(static fn ($sent) => (string) $sent->getOriginalMessage()->getSubject())
            ->values()
            ->all();
    }

    /**
     * Run $callback with these variables set (null = unset), then put back
     * whatever was there. Env::get() reads $_SERVER first, then $_ENV, then
     * getenv(), so all three are set.
     *
     * @param  array<string, string|null>  $variables
     */
    private function withEnvironment(array $variables, callable $callback): mixed
    {
        $saved = [];

        foreach ($variables as $key => $value) {
            $saved[$key] = [
                'server' => array_key_exists($key, $_SERVER) ? $_SERVER[$key] : null,
                'env' => array_key_exists($key, $_ENV) ? $_ENV[$key] : null,
                'getenv' => getenv($key),
            ];

            $this->putEnvironment($key, $value, $value, $value);
        }

        try {
            return $callback();
        } finally {
            foreach ($saved as $key => $was) {
                $this->putEnvironment($key, $was['server'], $was['env'], $was['getenv'] === false ? null : $was['getenv']);
            }
        }
    }

    private function putEnvironment(string $key, ?string $server, ?string $env, ?string $getenv): void
    {
        if ($server === null) {
            unset($_SERVER[$key]);
        } else {
            $_SERVER[$key] = $server;
        }

        if ($env === null) {
            unset($_ENV[$key]);
        } else {
            $_ENV[$key] = $env;
        }

        putenv($getenv === null ? $key : $key.'='.$getenv);
    }
}
