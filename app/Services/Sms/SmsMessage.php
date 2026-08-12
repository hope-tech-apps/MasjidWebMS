<?php

namespace App\Services\Sms;

/**
 * One outbound text message, provider-agnostic (T-009).
 *
 * Deliberately dumb: a destination, an originating identity, and a body that
 * has ALREADY been composed with its sender identification and opt-out language
 * (App\Services\Sms\SmsBodyComposer). No adapter may edit the body — if
 * appending "Reply STOP" were an adapter's job, a second adapter would forget,
 * and the compliance obligation would live in the one place nobody audits.
 *
 * `to` and `from` are E.164 by construction. `messagingServiceSid` is the
 * provider-side sender pool that 10DLC campaign registration attaches to;
 * exactly one of it and `from` is used, with the messaging service preferred
 * because that is where provider-side Advanced Opt-Out is enforced.
 */
final class SmsMessage
{
    public function __construct(
        public readonly string $to,
        public readonly string $body,
        public readonly ?string $from = null,
        public readonly ?string $messagingServiceSid = null,
    ) {
    }
}
