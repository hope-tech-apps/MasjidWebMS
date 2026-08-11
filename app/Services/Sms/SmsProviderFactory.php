<?php

namespace App\Services\Sms;

/**
 * Chooses the provider adapter for this deployment (T-009).
 *
 * ## The safe default is the refusing one
 *
 * With `SMS_DRIVER` unset — which is every developer machine, CI, and every
 * production deployment until an operator provisions credentials — this returns
 * NullSmsProvider unless real Twilio credentials are present. There is no code
 * path from an unconfigured environment to a network call, which is what makes
 * "no test may send a real message" a property of the design rather than a
 * discipline someone has to remember.
 *
 * Explicit values:
 *
 *   SMS_DRIVER unset  -> twilio IF its credentials are present, else null
 *   SMS_DRIVER=twilio -> twilio, and null if its credentials are absent
 *   SMS_DRIVER=log    -> LogSmsProvider (local development; sends nothing)
 *   SMS_DRIVER=none   -> NullSmsProvider, unconditionally
 *
 * Note the value is `none` and not `null`: Laravel's `env()` helper converts the
 * literal string "null" into PHP null, so `SMS_DRIVER=null` would be
 * indistinguishable from an unset variable and would silently re-enable
 * auto-detection. `none` is the spelling that survives the helper, and it is
 * what phpunit.xml and .env.testing pin so the suite can never select a network
 * adapter even if a stray credential is present in the environment it inherits.
 *
 * Note also what is NOT here: any fallback originating number. Which number a
 * message is sent FROM is a per-tenant fact (App\Models\MasjidSmsSender) and a
 * platform-wide default is exactly what gets a fleet's account suspended.
 */
class SmsProviderFactory
{
    public function make(): SmsProvider
    {
        $driver = config('services.sms.driver');
        $driver = is_string($driver) ? strtolower(trim($driver)) : '';

        if ($driver === '') {
            $driver = (new TwilioSmsProvider())->isConfigured() ? 'twilio' : 'none';
        }

        return match ($driver) {
            // A twilio driver without credentials degrades to the refusing
            // adapter rather than throwing at resolution time: unset credentials
            // must fail soft, on the request that asked to send, not at boot.
            'twilio' => $this->twilioOrNull(),
            'log' => new LogSmsProvider(),
            default => new NullSmsProvider(),
        };
    }

    private function twilioOrNull(): SmsProvider
    {
        $twilio = new TwilioSmsProvider();

        return $twilio->isConfigured() ? $twilio : new NullSmsProvider();
    }
}
