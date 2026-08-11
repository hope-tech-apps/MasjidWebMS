<?php

namespace App\Services\Sms;

use RuntimeException;

/**
 * No SMS provider is wired on this deployment (T-009).
 *
 * Its own type so the difference between "this platform has no SMS credentials"
 * and "the carrier rejected that number" survives all the way to the delivery
 * row an admin reads. One is an operator task; the other is a data problem.
 *
 * Note where this is NOT thrown: at boot, at container resolution, or on any
 * request that did not ask to send a text. Unset credentials must fail SOFT —
 * the way GitHub dispatch and Google geocoding already do — so a deployment
 * without SMS behaves exactly as it did before this feature existed.
 */
class SmsNotConfiguredException extends RuntimeException
{
    public static function make(): self
    {
        return new self(
            'Text messaging is not configured on this deployment, so no message was sent. '
            . 'An operator must provision the SMS provider credentials before this channel can be used.'
        );
    }
}
