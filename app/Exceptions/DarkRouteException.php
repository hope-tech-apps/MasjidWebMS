<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The 404 a switched-off route answers, thrown by a gate AFTER the route has matched.
 *
 * It is the router's own exception for a path it does not know, with the router's own
 * message, and it renders as the same response. It is a class of its own for one reason:
 * the exception handler's `respond()` hook (bootstrap/app.php, App\Support\MobileErrorEnvelope)
 * decorates every refusal on a MATCHED route named `mobile.member.me.*`, and a dark route has
 * matched by the time its gate throws. A truly unknown path never matches, so the hook never
 * touches it. Without a way to tell the gate's 404 apart, the dark answer would carry a
 * `data` key the unknown route's does not, and a probe could tell "switched off" from
 * "never built". The envelope skips this class.
 */
final class DarkRouteException extends NotFoundHttpException
{
    /** The message the router itself gives an unmatched path, so debug output matches too. */
    public static function forPath(string $path): self
    {
        return new self(sprintf('The route %s could not be found.', $path));
    }
}
