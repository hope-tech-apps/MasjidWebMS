<?php

namespace App\Services\Sms;

/**
 * What a provider adapter reports after accepting one message (T-009).
 *
 * Mirrors App\Services\Broadcast\ChannelResult's rule: there is no "failed"
 * constructor, because an adapter signals failure by THROWING. A provider that
 * could return a falsy result would eventually have a caller who forgot to
 * check it, and the caller in this feature reports numbers to an admin who is
 * deciding whether the snowstorm cancellation went out.
 *
 * `providerMessageId` is the id in the provider's console (Twilio's `SM…` sid).
 * It is the only handle an operator has when a carrier complaint arrives, which
 * is why it is recorded on the delivery row rather than logged and lost.
 *
 * "Accepted" is the honest word: the provider has taken the message. Carrier
 * delivery is asynchronous and is not claimed here — the same distinction
 * PushChannel draws about OneSignal.
 */
final class SmsSendResult
{
    public function __construct(
        public readonly string $providerMessageId,
        public readonly string $provider,
    ) {
    }
}
