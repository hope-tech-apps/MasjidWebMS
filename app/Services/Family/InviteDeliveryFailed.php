<?php

namespace App\Services\Family;

use RuntimeException;

/**
 * The invite row was written and the EMAIL did not go out.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS CLASS EXISTS, AND IT IS NOT TIDINESS
 * ---------------------------------------------------------------------------
 *
 * `ContactFamilyLoginController::invite()` answers a `RuntimeException` with a
 * 422 carrying the exception's own message, because that is how every refusal in
 * this controller reaches the operator: "this member may not hold a sign-in",
 * "there is no live sign-in to invite them to", "this mailbox has had the hour's
 * worth of links". Those are sentences written for a human, and the status says
 * "fix something about this member".
 *
 * A mail transport failure is neither. And it WOULD have landed in that block:
 * `Symfony\Component\Mailer\Exception\TransportException` extends that
 * component's `RuntimeException`, which extends `\RuntimeException`. So a relay
 * outage would have been reported to the office as a 422 refusal — inviting them
 * to go and "fix" a member record that is perfectly fine — with the transport's
 * own message as the body, which is the same class of leak the QueryException
 * catch above it exists to prevent (that one was measured: the driver's message,
 * table names, columns and a bound value, handed to whoever clicked save).
 *
 * Catching `TransportExceptionInterface` first would work today and would tie
 * this controller to Symfony's hierarchy — one mailer change, or one `Mail`
 * decorator that throws something else, and the 422 is back. Wrapping at the
 * point of failure instead means the ORDERING is explicit and local: the service
 * knows a send failed, and says so in a type.
 *
 * The original exception is always the `previous`, so the log line keeps it.
 */
class InviteDeliveryFailed extends RuntimeException
{
}
