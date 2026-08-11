<?php

namespace App\Services\Sms;

/**
 * Verifies Twilio's `X-Twilio-Signature` on an inbound webhook (T-009).
 *
 * ## The algorithm, and why it is here rather than in an SDK
 *
 * Twilio signs a request by taking the FULL URL it dialled, appending each POST
 * parameter as key-then-value in alphabetical key order, computing
 * HMAC-SHA1 keyed with the account's auth token, and base64-encoding the digest.
 * That is the whole specification, and it is nine lines. Pulling in `twilio/sdk`
 * for it would add ~2,500 files to composer.lock to avoid writing them — see
 * TwilioSmsProvider for the same trade made about outbound messages.
 *
 * ## The URL is the part that bites
 *
 * The signature covers the URL Twilio DIALLED, not the URL this application
 * thinks it was reached at. Behind a TLS-terminating proxy those differ (http
 * vs https, internal host vs public host) and every delivery fails verification
 * with a signature that is in fact correct. `services.twilio.webhook_url`
 * overrides the reconstructed URL for exactly that deployment; when it is unset
 * the request's own full URL is used, which is right for a directly-exposed app.
 *
 * ## Fail closed
 *
 * No auth token configured means nothing verifies — never "wave it through".
 * This is the Stripe webhook's posture verbatim, and it matters more here: an
 * unverified endpoint that accepts opt-in keywords is a way for an attacker to
 * RE-SUBSCRIBE numbers that opted out, which is worse than the endpoint not
 * existing at all.
 *
 * `hash_equals` does the comparison, so a timing side channel cannot be used to
 * forge a signature byte by byte.
 */
class TwilioSignatureVerifier
{
    /**
     * @param  array<string, mixed>  $params  the POST parameters, exactly as received.
     */
    public function verify(string $url, array $params, ?string $signature): bool
    {
        $token = (string) config('services.twilio.auth_token');

        // Fail closed: with no signing key, nothing is authentic.
        if ($token === '' || $signature === null || $signature === '') {
            return false;
        }

        return hash_equals($this->expected($url, $params, $token), $signature);
    }

    /**
     * The signature Twilio would have sent for this request.
     *
     * @param  array<string, mixed>  $params
     */
    public function expected(string $url, array $params, string $token): string
    {
        ksort($params);

        $data = $url;

        foreach ($params as $key => $value) {
            // Twilio concatenates key then value, with no separator at all.
            // Array-valued parameters do not occur on this webhook; casting
            // keeps a malformed body from producing a PHP notice instead of a
            // clean "unauthenticated" answer.
            $data .= $key . (is_scalar($value) ? (string) $value : '');
        }

        return base64_encode(hash_hmac('sha1', $data, $token, true));
    }

    /**
     * The URL the signature must be computed against: the configured public URL
     * when a proxy makes the request's own view of it unreliable, otherwise the
     * request's full URL.
     */
    public function urlFor(string $requestFullUrl): string
    {
        $configured = trim((string) config('services.twilio.webhook_url'));

        return $configured !== '' ? $configured : $requestFullUrl;
    }
}
