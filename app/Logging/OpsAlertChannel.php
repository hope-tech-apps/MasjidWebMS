<?php

namespace App\Logging;

use Monolog\Logger;

/**
 * Custom log-channel factory (config/logging.php `ops-alerts`) that builds a
 * Monolog logger delivering records to the operator by email. Level and
 * recipient come from the channel config so both are env-driven:
 *
 *   'ops-alerts' => [
 *       'driver' => 'custom',
 *       'via'    => \App\Logging\OpsAlertChannel::class,
 *       'level'  => env('OPS_ALERT_LEVEL', 'error'),
 *       'to'     => env('OPS_ALERT_EMAIL'),
 *   ],
 *
 * With no OPS_ALERT_EMAIL set the handler is inert (it early-returns), so the
 * channel is safe to leave configured on a host that has not chosen a recipient.
 */
class OpsAlertChannel
{
    public function __invoke(array $config): Logger
    {
        $level = Logger::toMonologLevel($config['level'] ?? 'error');

        return new Logger('ops-alerts', [
            new OpsAlertMailHandler($config['to'] ?? null, $level),
        ]);
    }
}
