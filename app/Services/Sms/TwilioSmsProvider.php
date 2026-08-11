<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The Twilio adapter (T-009) — the first implementation of the provider seam,
 * chosen because it is the default answer for US A2P 10DLC.
 *
 * ## HTTP API, not the SDK — and why
 *
 * `twilio/sdk` is ~2,500 files of generated REST bindings, brings its own Guzzle
 * pin, and would have to be carried in composer.lock and updated forever. What
 * this feature needs from it is ONE form-encoded POST with basic auth. Laravel's
 * Http client already gives us that, plus the thing the SDK actively takes away:
 * `Http::fake()`, which is how every test here proves the request shape without
 * a single packet leaving the machine. Adding a dependency to avoid twelve lines
 * of HTTP would trade a small, testable surface for a large, opaque one, so the
 * dependency surface is unchanged by this slice and composer.lock is untouched.
 *
 * The one thing an SDK would give us for free — request signing for inbound
 * webhooks — is nine lines of HMAC and lives in TwilioSignatureVerifier, where
 * it is directly testable against Twilio's published algorithm.
 *
 * ## Contract
 *
 *   POST {base}/Accounts/{AccountSid}/Messages.json
 *   basic auth: AccountSid : AuthToken
 *   form: To, Body, and EXACTLY ONE of MessagingServiceSid | From
 *   -> 201 { "sid": "SM…", "status": "queued", … }
 *   -> 4xx { "code": 21610, "message": "…" }
 *
 * MessagingServiceSid is preferred when the tenant has one: that is what an
 * A2P 10DLC campaign registers against, and it is where provider-side Advanced
 * Opt-Out (the carrier-mandated STOP/HELP auto-replies) is enforced.
 *
 * ## Nothing sensitive is logged or re-thrown
 *
 * The destination number and the message body never appear in an exception
 * message, a log line or an API response. What surfaces on a failure is the
 * provider's numeric error code and its own description of the code — enough for
 * an operator to look the failure up, with no PII attached. Twilio's error
 * messages can quote the destination number, so the message is NOT passed
 * through verbatim.
 */
final class TwilioSmsProvider implements SmsProvider
{
    public function name(): string
    {
        return 'twilio';
    }

    public function isConfigured(): bool
    {
        return filled(config('services.twilio.account_sid'))
            && filled(config('services.twilio.auth_token'));
    }

    public function send(SmsMessage $message): SmsSendResult
    {
        if (! $this->isConfigured()) {
            throw SmsNotConfiguredException::make();
        }

        $accountSid = (string) config('services.twilio.account_sid');
        $base = rtrim((string) config('services.twilio.api_base'), '/');

        $payload = [
            'To' => $message->to,
            'Body' => $message->body,
        ];

        // Exactly one origin. The messaging service is preferred: the 10DLC
        // campaign is registered against it, and it owns provider-side opt-out.
        if ($message->messagingServiceSid !== null) {
            $payload['MessagingServiceSid'] = $message->messagingServiceSid;
        } elseif ($message->from !== null) {
            $payload['From'] = $message->from;
        } else {
            // Unreachable through SmsChannel, which refuses a tenant with no
            // sender identity long before this point. Explicit anyway, because a
            // future caller must not be able to discover it from a 400.
            throw new RuntimeException('No originating number or messaging service was supplied.');
        }

        $response = Http::withBasicAuth($accountSid, (string) config('services.twilio.auth_token'))
            ->asForm()
            ->timeout((int) config('services.twilio.timeout', 15))
            ->post($base . '/Accounts/' . $accountSid . '/Messages.json', $payload);

        if ($response->failed()) {
            $body = $response->json();

            // The provider's own message can quote the destination number, so it
            // is deliberately not repeated. The code identifies the failure.
            throw new RuntimeException(
                'The SMS provider rejected the message (HTTP ' . $response->status()
                . (isset($body['code']) ? ', provider code ' . $body['code'] : '') . ').'
            );
        }

        $sid = (string) ($response->json('sid') ?? '');

        if ($sid === '') {
            throw new RuntimeException('The SMS provider accepted the request but returned no message id.');
        }

        return new SmsSendResult(providerMessageId: $sid, provider: $this->name());
    }
}
