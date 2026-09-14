<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puts an empty `data` object on every refusal from the member routes the apps
 * call to LEAVE: `DELETE .../me` and `DELETE .../me/device` (route names
 * `mobile.member.me.*`).
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
 */
final class MobileErrorEnvelope
{
    public const ROUTES = 'mobile.member.me.*';

    public static function withDataKey(Response $response, Request $request): Response
    {
        if (! $response instanceof JsonResponse || ! $request->routeIs(self::ROUTES)) {
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
