<?php

namespace App\Services\Sms;

/**
 * The provider seam (T-009).
 *
 * SMS providers are interchangeable in the way payment processors are not:
 * every one of them takes a destination, an origin and a body, and hands back
 * an id. Twilio is the first adapter because it is the default answer for US
 * A2P 10DLC, but nothing above this interface knows its name — the broadcast
 * channel, the consent rules and the audience resolver would be unchanged by a
 * move to Telnyx or Vonage, and the operator-facing refusal messages are
 * phrased in carrier terms rather than vendor terms for the same reason.
 *
 * Three implementations ship:
 *
 *   - TwilioSmsProvider — the real one, over the provider's HTTP API.
 *   - NullSmsProvider   — the DEFAULT when nothing is configured. It REFUSES,
 *                         loudly and clearly. It does not pretend to send.
 *   - LogSmsProvider    — opt-in for local development; records that a message
 *                         would have gone out, without its body or its number,
 *                         and touches no network.
 *
 * The last two are why no test in this suite can reach a carrier: with
 * SMS_DRIVER unset and no credentials present, the factory can only ever build
 * NullSmsProvider (App\Services\Sms\SmsProviderFactory).
 *
 * ## Contract
 *
 * `send()` returns SmsSendResult when the provider ACCEPTED the message, and
 * THROWS otherwise — including when the provider is not configured, which
 * throws SmsNotConfiguredException so callers can tell "we are not set up" from
 * "the carrier rejected this number". Never return a falsy result: the caller
 * is counting recipients for an admin who needs to know what actually went out.
 */
interface SmsProvider
{
    /** Short provider name, recorded on the delivery row (`twilio`, `null`, `log`). */
    public function name(): string;

    /** Whether this adapter can actually put traffic on a network. */
    public function isConfigured(): bool;

    /**
     * Hand one message to the provider.
     *
     * @throws SmsNotConfiguredException when no provider is wired.
     * @throws \Throwable when the provider refused the message.
     */
    public function send(SmsMessage $message): SmsSendResult;
}
