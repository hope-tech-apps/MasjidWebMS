<?php

use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;
use Monolog\Processor\PsrLogMessageProcessor;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    |
    | This option defines the default log channel that is utilized to write
    | messages to your logs. The value provided here should match one of
    | the channels present in the list of "channels" configured below.
    |
    */

    'default' => env('LOG_CHANNEL', 'stack'),

    /*
    |--------------------------------------------------------------------------
    | Deprecations Log Channel
    |--------------------------------------------------------------------------
    |
    | This option controls the log channel that should be used to log warnings
    | regarding deprecated PHP and library features. This allows you to get
    | your application ready for upcoming major versions of dependencies.
    |
    */

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => env('LOG_DEPRECATIONS_TRACE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    |
    | Here you may configure the log channels for your application. Laravel
    | utilizes the Monolog PHP logging library, which includes a variety
    | of powerful log handlers and formatters that you're free to use.
    |
    | Available drivers: "single", "daily", "slack", "syslog",
    |                    "errorlog", "monolog", "custom", "stack"
    |
    */

    'channels' => [

        'stack' => [
            'driver' => 'stack',
            'channels' => explode(',', env('LOG_STACK', 'single')),
            'ignore_exceptions' => false,
        ],

        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => env('LOG_DAILY_DAYS', 14),
            'replace_placeholders' => true,
        ],

        'slack' => [
            'driver' => 'slack',
            'url' => env('LOG_SLACK_WEBHOOK_URL'),
            'username' => env('LOG_SLACK_USERNAME', 'Laravel Log'),
            'emoji' => env('LOG_SLACK_EMOJI', ':boom:'),
            'level' => env('LOG_LEVEL', 'critical'),
            'replace_placeholders' => true,
        ],

        // Emails the operator a single log record via the app mailer (Resend).
        // The DELIVERY end of the scheduled monitors' on-call contract — set
        // MEDIA_VERIFY_LOG_CHANNEL / CANARY_LOG_CHANNEL to `monitors` (below) so
        // media:verify / tenancy:canary route their one-line-per-run here. Level
        // `error` by default, so a partial run (warning) stays a file-only ticket
        // and only a leak / incomplete / broken-or-empty estate (error+/critical)
        // pages. A clean run (info) never emails. Inert until OPS_ALERT_EMAIL is
        // set. See App\Logging\OpsAlertChannel.
        'ops-alerts' => [
            'driver' => 'custom',
            'via' => \App\Logging\OpsAlertChannel::class,
            'level' => env('OPS_ALERT_LEVEL', 'error'),
            'to' => env('OPS_ALERT_EMAIL'),
        ],

        // The scheduled monitors' proof-of-run file: one line for EVERY run of
        // tenancy:canary, media:verify, backup:check and backup:drill, clean
        // runs included. About 30 lines a day.
        //
        // The level is fixed at `info`, NOT env('LOG_LEVEL'), and that is the
        // whole point of this channel. A clean run logs at `info`, and
        // production runs LOG_LEVEL=warning. While `single` was the monitors'
        // only file, every clean line was dropped: laravel.log for 2026-09-16
        // held none of ~19 clean canary runs and none of four media sweeps.
        // "The canary stopped running" read exactly like "the canary is
        // healthy". Pinned by tests/Feature/MonitorsLogChannelTest.php.
        //
        // `single`, not `daily`: /etc/logrotate.d/manara on the droplet already
        // rotates storage/logs/*.log daily and keeps 14. Dated files from
        // `daily` would match that glob and be rotated twice.
        'monitors-file' => [
            'driver' => 'single',
            'path' => storage_path('logs/monitors.log'),
            'level' => 'info',
            'replace_placeholders' => true,
        ],

        // What the scheduled monitors point their log_channel at. Each of the
        // three answers a different question:
        //
        //   monitors-file  Did it run? Every run, at every level
        //                  (storage/logs/monitors.log).
        //   single         The application log, unchanged. At production's
        //                  LOG_LEVEL=warning it keeps partial and failed runs
        //                  beside everything else, and drops clean ones.
        //   ops-alerts     Should somebody be told? error and above only.
        //
        // ignore_exceptions so a mail hiccup can never take down the file lines
        // beside it. The price: an unwritable monitors.log is swallowed too. The
        // scheduler runs as www-data. A file created by root (a monitor run by
        // hand without `sudo -u www-data`) would refuse its writes. So bin/deploy
        // creates monitors.log and chowns storage/ on every deploy. A file that
        // stops growing anyway reads as "not running": a false alarm at worst,
        // never a false green.
        'monitors' => [
            'driver' => 'stack',
            'channels' => ['monitors-file', 'single', 'ops-alerts'],
            'ignore_exceptions' => true,
        ],

        'papertrail' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => env('LOG_PAPERTRAIL_HANDLER', SyslogUdpHandler::class),
            'handler_with' => [
                'host' => env('PAPERTRAIL_URL'),
                'port' => env('PAPERTRAIL_PORT'),
                'connectionString' => 'tls://'.env('PAPERTRAIL_URL').':'.env('PAPERTRAIL_PORT'),
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'with' => [
                'stream' => 'php://stderr',
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'syslog' => [
            'driver' => 'syslog',
            'level' => env('LOG_LEVEL', 'debug'),
            'facility' => env('LOG_SYSLOG_FACILITY', LOG_USER),
            'replace_placeholders' => true,
        ],

        'errorlog' => [
            'driver' => 'errorlog',
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        'emergency' => [
            'path' => storage_path('logs/laravel.log'),
        ],

    ],

];
