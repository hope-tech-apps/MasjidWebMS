<?php

namespace App\Logging;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;

/**
 * A Monolog handler that emails a single log record to the operator, via the
 * application's configured mailer (Resend in production).
 *
 * This is the DELIVERY end of the on-call contract the scheduled monitors
 * (`media:verify`, `tenancy:canary`, `backup:run`) were written to: they leave
 * exactly one log line per run whose LEVEL carries the verdict, and the author's
 * comments say plainly that "for a SCHEDULED run this log line is the entire
 * alert path" and that "an alerting rule can route on it without anyone editing
 * this file". The env hooks `MEDIA_VERIFY_LOG_CHANNEL` / `CANARY_LOG_CHANNEL`
 * exist so that rule can be a delivering channel; this is that channel's handler.
 *
 * It is registered at level `error` (see config/logging.php), so `warning`
 * (a partial run — a ticket, not a page) and `info` (a clean run) are dropped
 * here and land only in the file channel beside it. Only `error` and `critical`
 * — a leak, an incomplete run, a broken/empty media estate — email.
 */
class OpsAlertMailHandler extends AbstractProcessingHandler
{
    public function __construct(private readonly ?string $to, $level, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        if (empty($this->to)) {
            return;
        }

        // Alerting must NEVER break the run it is watching. A mail failure here
        // is swallowed; the same line has already been written to the file
        // channel this one is stacked beside, so nothing is lost silently.
        try {
            $level = $record->level->getName();
            $subject = '[Manara '.$level.'] '.Str::limit($record->message, 90);

            $body = $record->message
                ."\n\n"
                .json_encode($record->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            Mail::raw($body, function ($mail) use ($subject) {
                $mail->to($this->to)->subject($subject);
            });
        } catch (\Throwable $e) {
            // Intentionally swallowed — see above.
        }
    }
}
