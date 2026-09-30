<?php

namespace Tests\Unit;

use App\Exceptions\DarkRouteException;
use App\Support\MobileErrorEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * The `data` object the member routes' refusals carry, and the one refusal that must NOT:
 * the 404 a switched-off portal route answers (DarkRouteException). That gate throws after the
 * route has matched, so without the exemption the dark answer would carry a key the answer for
 * a path that matches no route does not, and a probe could tell them apart.
 */
class MobileErrorEnvelopeTest extends TestCase
{
    private const BARE = '{"status":"error","message":"Request failed."}';

    private function requestOn(?string $routeName): Request
    {
        $request = Request::create('/api/mobile/masjids/1/me/orders');

        if ($routeName !== null) {
            $route = (new Route('GET', '/api/mobile/masjids/{masjid_id}/me/orders', fn () => null))->name($routeName);
            $request->setRouteResolver(fn () => $route);
        }

        return $request;
    }

    private function refusal(): JsonResponse
    {
        return new JsonResponse(['status' => 'error', 'message' => 'Request failed.'], 404);
    }

    #[Test]
    public function an_ordinary_refusal_on_a_member_route_gains_an_empty_data_object(): void
    {
        $request = $this->requestOn('mobile.member.me.orders.index');

        // A controller's own 404 (a miss, someone else's order) is a plain NotFoundHttpException.
        $decorated = MobileErrorEnvelope::withDataKey($this->refusal(), $request, new NotFoundHttpException('Not found.'));
        $this->assertSame('{"status":"error","message":"Request failed.","data":{}}', $decorated->getContent());

        // And a caller that names no exception keeps working as it did.
        $this->assertSame(
            '{"status":"error","message":"Request failed.","data":{}}',
            MobileErrorEnvelope::withDataKey($this->refusal(), $request)->getContent()
        );
    }

    #[Test]
    public function the_dark_routes_404_is_left_exactly_as_rendered(): void
    {
        $request = $this->requestOn('mobile.member.me.orders.index');

        $left = MobileErrorEnvelope::withDataKey($this->refusal(), $request, DarkRouteException::forPath('api/mobile/masjids/1/me/orders'));

        $this->assertSame(self::BARE, $left->getContent(), 'no `data` key: the same body as a path that matches no route');
    }

    #[Test]
    public function the_dark_routes_exception_is_the_routers_own_404(): void
    {
        $dark = DarkRouteException::forPath('api/mobile/masjids/1/me/orders');

        $this->assertInstanceOf(NotFoundHttpException::class, $dark);
        $this->assertSame(404, $dark->getStatusCode());
        $this->assertSame('The route api/mobile/masjids/1/me/orders could not be found.', $dark->getMessage());
    }

    #[Test]
    public function a_route_outside_the_member_realm_is_untouched(): void
    {
        $this->assertSame(self::BARE, MobileErrorEnvelope::withDataKey($this->refusal(), $this->requestOn('mobile.other'), new NotFoundHttpException())->getContent());
        $this->assertSame(self::BARE, MobileErrorEnvelope::withDataKey($this->refusal(), $this->requestOn(null), new NotFoundHttpException())->getContent(), 'and a path that matched no route');
    }
}
