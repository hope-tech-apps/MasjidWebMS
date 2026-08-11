<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The local-development adapter: accepts the message, writes a line, sends
 * nothing (T-009).
 *
 * Chosen EXPLICITLY with SMS_DRIVER=log — it is never the default, because a
 * default that reports success while sending nothing is the exact hazard
 * NullSmsProvider exists to avoid. When it is chosen, the channel's delivery
 * note says the message went to the log driver, so nobody reading the record
 * later mistakes it for a real send.
 *
 * ## It logs metadata, never content
 *
 * A phone number is PII and a broadcast body may name a family, a health
 * situation or a funeral. Neither is written to a log file that gets shipped to
 * a third-party aggregator and retained for a year. What IS logged: the
 * provider, the body LENGTH and the number of message segments — enough to
 * debug truncation and cost, useless to anyone who steals the log. The same rule
 * governs TwilioSmsProvider and SmsChannel; it is the money/PII discipline the
 * donation paths already follow.
 */
final class LogSmsProvider implements SmsProvider
{
    /** GSM-7 single-segment limit; 153 per segment once concatenated. */
    private const SEGMENT_FIRST = 160;
    private const SEGMENT_SUBSEQUENT = 153;

    public function name(): string
    {
        return 'log';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function send(SmsMessage $message): SmsSendResult
    {
        $length = mb_strlen($message->body);

        Log::info('SMS log driver: message accepted, nothing was sent.', [
            // No recipient number, no body — deliberately. See the class note.
            'provider' => $this->name(),
            'body_length' => $length,
            'segments' => $this->segments($length),
            'via_messaging_service' => $message->messagingServiceSid !== null,
        ]);

        return new SmsSendResult(
            providerMessageId: 'log-' . Str::uuid()->toString(),
            provider: $this->name(),
        );
    }

    private function segments(int $length): int
    {
        if ($length <= self::SEGMENT_FIRST) {
            return 1;
        }

        return (int) ceil($length / self::SEGMENT_SUBSEQUENT);
    }
}
