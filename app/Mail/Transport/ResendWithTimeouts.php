<?php

namespace App\Mail\Transport;

use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Mail\Transport\ResendTransport;
use Resend\Client;
use Resend\Transporters\HttpTransporter;
use Resend\ValueObjects\ApiKey;
use Resend\ValueObjects\Transporter\BaseUri;
use Resend\ValueObjects\Transporter\Headers;

/**
 * The `resend` mail transport, with a time limit on every call to Resend.
 *
 * WHY THIS EXISTS
 *
 * Laravel builds the Resend transport with `Resend::client($key)`, and
 * resend-php (1.6.0) gives that a `new GuzzleClient()` with no options. With
 * curl, Guzzle then waits up to 300 s to connect and has no limit at all on the
 * reply. Several mails here are sent INLINE, on a request a person is waiting
 * on, among them the sign-in and account deletion codes (FamilyLoginCodeMail),
 * TwoFactorResetMail and "Your password was set". The code mails and the
 * password notice wrap the send and log a failure. But a Resend that accepts
 * the connection and never answers is not a failure a wrapper ever sees: the
 * call just waits. On production (checked 2026-09-17), nginx sets no
 * `fastcgi_read_timeout`, so it gives up after its default 60 s and answers
 * 504; PHP's `max_execution_time` does not count time spent waiting on the
 * network; and PHP-FPM has no `request_terminate_timeout`. So a person would
 * see an error for a password change that had already been saved (a retry
 * gets "That code is no longer usable"), nothing would be logged, and one of
 * the 12 FPM workers would stay stuck for as long as Resend held the
 * connection.
 *
 * With a limit, a stuck call throws after `services.resend.timeout` seconds.
 * Laravel turns that into a TransportException, the caller's wrapper logs its
 * warning, and the response is the normal one. The limits are generous for one
 * API call carrying one message, and far below nginx's 60 s.
 *
 * Everything else is built exactly as `Resend::client()` builds it, including
 * the RESEND_BASE_URL override, so nothing but the limits changes. Queued mail
 * (broadcasts) goes through this too; a stuck call there now fails the way any
 * other send error does, instead of holding the queue worker.
 *
 * Registered in AppServiceProvider. Pinned by
 * tests/Feature/ResendTransportTimeoutTest.php.
 */
final class ResendWithTimeouts
{
    /** Seconds to open the connection. */
    public const DEFAULT_CONNECT_TIMEOUT = 5;

    /** Seconds for the whole call, connection included. */
    public const DEFAULT_TIMEOUT = 10;

    /** @param  array<string, mixed>  $config  the mailer's config, as MailManager passes it */
    public static function transport(array $config): ResendTransport
    {
        return new ResendTransport(self::client($config['key'] ?? config('services.resend.key')));
    }

    public static function client(string $apiKey): Client
    {
        // Guzzle reads 0 as "no limit", so a missing value falls back to the
        // shipped default instead of silently removing the limit again.
        $guzzle = new GuzzleClient([
            'connect_timeout' => (float) (config('services.resend.connect_timeout') ?: self::DEFAULT_CONNECT_TIMEOUT),
            'timeout' => (float) (config('services.resend.timeout') ?: self::DEFAULT_TIMEOUT),
        ]);

        return new Client(new HttpTransporter(
            $guzzle,
            BaseUri::from(getenv('RESEND_BASE_URL') ?: 'api.resend.com'),
            Headers::withAuthorization(ApiKey::from($apiKey)),
        ));
    }
}
