<?php

namespace App\Support;

use App\Exceptions\DarkRouteException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Puts an empty `data` object on every refusal from the member routes the apps
 * call to LEAVE, `DELETE .../me` and `DELETE .../me/device`, and from the member
 * portal's reads (`GET .../me/orders`, `.../me/orders/{source}/{id}`,
 * `.../me/gifts`, `.../me/receipts/{id}/pdf`): every route named
 * `mobile.member.me.*`.
 *
 * The iPhone app decodes every mobile body, errors included, through one
 * `Response<T>` envelope whose `data` is non-optional. A refusal without the key
 * fails to decode ON THE DEVICE, and the member sees a generic error where the
 * server sent a sentence. No server test can see that, because the server
 * behaved correctly.
 *
 * Most of those refusals are not written by a controller: the 401 comes from the
 * guard or `member.active`, the 403 from `family.tenant`, the 429 from the
 * limiter. They are rendered by the JSON renderer in bootstrap/app.php, which
 * every other API route shares. So this runs as the exception handler's
 * `respond()` hook, after rendering, and only for these routes: widening the
 * envelope for every API refusal would change bodies other clients already
 * parse.
 *
 * A body that already has `data` (a validation failure's field errors) is left
 * exactly as it is.
 *
 * One refusal is left bare on purpose: a DarkRouteException, the 404 a switched-off
 * portal route answers. Its gate throws after the route has matched, so this hook
 * would decorate it, and the dark route would then differ from a path that matches
 * no route (which this hook never sees) by a `data` key. The two must be the same
 * bytes, so a probe cannot tell "switched off" from "never built".
 */
final class MobileErrorEnvelope
{
    public const ROUTES = 'mobile.member.me.*';

    public static function withDataKey(Response $response, Request $request, ?Throwable $exception = null): Response
    {
        if (! $response instanceof JsonResponse || ! $request->routeIs(self::ROUTES)) {
            return $response;
        }

        if ($exception instanceof DarkRouteException) {
            return $response;
        }

        $payload = $response->getData();

        if (! is_object($payload) || property_exists($payload, 'data')) {
            return $response;
        }

        $payload->data = new \stdClass();

        return $response->setData($payload);
    }
}
