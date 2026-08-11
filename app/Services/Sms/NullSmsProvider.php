<?php

namespace App\Services\Sms;

/**
 * The default adapter: no provider is wired, so nothing is sent — and nothing
 * pretends to have been (T-009).
 *
 * This is the adapter every environment gets until an operator provisions
 * credentials, including the entire test suite. It touches no network, holds no
 * credentials, and cannot be configured into sending.
 *
 * ## Why it refuses instead of quietly succeeding
 *
 * The obvious "null driver" swallows the message and returns success, and that
 * is precisely the failure .claude/rules/broadcasts.md warned about when T-008
 * left SMS out: "an admin who believes a snowstorm cancellation went out by text
 * when it did not is far worse off than one who can see the channel is
 * unavailable." A silent null driver produces a green tick on a message that
 * reached nobody. So this one throws, the channel records a FAILED delivery, and
 * the admin reads a sentence telling them SMS is not set up on this deployment.
 *
 * The refusal is soft in the sense that matters: it happens at send time, on a
 * request that asked to send, and it takes down neither the boot sequence nor
 * the other channels of the same broadcast (BroadcastDispatcher isolates them).
 *
 * Local development that wants to watch messages flow uses LogSmsProvider
 * instead, by setting SMS_DRIVER=log — an explicit, deliberate choice.
 */
final class NullSmsProvider implements SmsProvider
{
    public function name(): string
    {
        return 'null';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function send(SmsMessage $message): SmsSendResult
    {
        throw SmsNotConfiguredException::make();
    }
}
