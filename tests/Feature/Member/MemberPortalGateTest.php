<?php

namespace Tests\Feature\Member;

use App\Http\Middleware\EnsureMemberPortalEnabled;
use App\Models\Masjid;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The member portal's "Your orders" routes are DARK until the owner picks the organisation
 * that gets them (DECISIONS.md 2026-09-30, config/member_portal.php): off, each answers the
 * same 404 an unknown route does, BEFORE authentication and BEFORE the throttles, so a probe
 * with no token cannot tell a dark route from a missing one. What the routes return once on
 * is MemberOrdersTest's and MemberGiftsAndReceiptsTest's business; both switch the portal
 * on in their setUp.
 */
class MemberPortalGateTest extends TestCase
{
    use BuildsMemberPortal;
    use RefreshDatabase;

    /**
     * @var array<string, string> route name => the path under `/me`, with a sample id.
     *
     * `portalUrl()` already puts `me/` in front of what it is given; a path here that began
     * `me/` too would be `/me/me/...`, a route that does not exist, and every "off" test
     * would pass with no gate at all (assertTheRouteIsReal is what stops that).
     */
    private const ROUTES = [
        'mobile.member.me.orders.index' => 'orders',
        'mobile.member.me.orders.show' => 'orders/manara/00000000-0000-4000-8000-000000000000',
        'mobile.member.me.gifts.index' => 'gifts',
        'mobile.member.me.receipts.pdf' => 'receipts/1/pdf',
    ];

    /**
     * The PREMISE of every "off" assertion: the URL is a REAL route. Switched ON for everyone,
     * an unauthenticated call to it answers the auth stack's 401, which a path no route matches
     * can never do (it answers the router's 404). Without this, a URL typo makes each 404
     * assertion below true of a route that never existed, with or without the gate.
     *
     * The switch is put back as the caller had it.
     */
    private function assertTheRouteIsReal(Masjid $org, string $path): void
    {
        $before = config('member_portal');
        $this->turnMemberPortalOn();

        try {
            $response = $this->bare()->getJson($this->portalUrl($org, $path));

            $this->assertSame(
                401,
                $response->getStatusCode(),
                "{$path} is not a real route: switched on it answers the portal's 401, not the router's 404"
            );
        } finally {
            config(['member_portal' => $before]);
        }
    }

    /**
     * A request that carries no credentials. Headers set with `withHeader()` STAY on the test
     * for every later call, and a guard remembers its user, so a "no token" call after an
     * `asMember()` is a member's call unless both are dropped first.
     */
    private function bare(): static
    {
        Auth::forgetGuards();
        $this->unbound();

        return $this->flushHeaders();
    }

    /**
     * Two answers are the same answer: the status, the body's bytes and every header but the
     * clock. Compared as a whole, so a header a gate adds (or an envelope key) cannot hide.
     */
    private function assertSameAnswer(TestResponse $expected, TestResponse $actual, string $message): void
    {
        $this->assertSame($expected->getStatusCode(), $actual->getStatusCode(), "{$message}: status");
        $this->assertSame($expected->getContent(), $actual->getContent(), "{$message}: body");
        $this->assertSame($this->headersOf($expected), $this->headersOf($actual), "{$message}: headers");
    }

    /** @return array<string, array<int, string|null>> */
    private function headersOf(TestResponse $response): array
    {
        $headers = $response->headers->all();
        unset($headers['date']);
        ksort($headers);

        return $headers;
    }

    #[Test]
    public function the_portal_is_off_unless_it_is_switched_on(): void
    {
        $this->assertFalse(config('member_portal.enabled'), 'the shipped default is OFF');
        $this->assertSame([], config('member_portal.masjid_ids'), 'and empty means every organisation, once on');
        $this->assertFalse(EnsureMemberPortalEnabled::enabledFor(7));
    }

    #[Test]
    public function off_every_portal_route_is_the_same_404_as_a_route_that_does_not_exist(): void
    {
        config(['app.debug' => false]);
        $org = $this->org();
        $me = $this->member($org);

        $unknown = $this->bare()->getJson($this->portalUrl($org, 'no-such-route'));
        $unknown->assertNotFound();

        foreach (self::ROUTES as $path) {
            // The premise: this is a real route, so the 404 below is the gate's.
            $this->assertTheRouteIsReal($org, $path);

            // Without a token, with a junk one, and with a real member's.
            $this->assertSameAnswer($unknown, $this->bare()->getJson($this->portalUrl($org, $path)), "{$path}: no token");

            $junk = $this->bare()->withHeader('Authorization', 'Bearer 1|not-a-real-token')->getJson($this->portalUrl($org, $path));
            $this->assertSameAnswer($unknown, $junk, "{$path}: junk token");

            $real = $this->asMember($me)->getJson($this->portalUrl($org, $path));
            $this->assertSameAnswer($unknown, $real, "{$path}: a real member's token");
        }
    }

    #[Test]
    public function off_the_404_has_no_data_key_where_the_portals_own_refusals_keep_theirs(): void
    {
        config(['app.debug' => false]);
        $org = $this->org();

        // The unknown route: no route matched, so the `mobile.member.me.*` envelope never
        // sees it. The baseline the dark answer must equal.
        $this->assertArrayNotHasKey('data', $this->bare()->getJson($this->portalUrl($org, 'no-such-route'))->assertNotFound()->json());

        foreach (self::ROUTES as $path) {
            $this->assertTheRouteIsReal($org, $path);

            // Dark: the gate throws after the route matched, and must still be a bare 404.
            $dark = $this->bare()->getJson($this->portalUrl($org, $path))->assertNotFound();
            $this->assertArrayNotHasKey('data', $dark->json(), "{$path}: the dark 404 carries no envelope");
        }

        // On, the same routes' real refusals DO carry it (the iPhone app decodes `data`).
        $this->turnMemberPortalOn();

        foreach (self::ROUTES as $path) {
            $refused = $this->bare()->getJson($this->portalUrl($org, $path))->assertUnauthorized();
            $this->assertArrayHasKey('data', $refused->json(), "{$path}: a refusal from the stack keeps its envelope");
        }
    }

    #[Test]
    public function off_no_authentication_is_attempted_no_limiter_runs_and_no_rate_limit_header_appears(): void
    {
        config(['app.debug' => false]);
        $org = $this->org();
        $me = $this->member($org);
        $token = $me->createMemberToken();

        foreach (self::ROUTES as $path) {
            $this->assertTheRouteIsReal($org, $path);
        }

        $ran = 0;
        RateLimiter::for('mobile', function () use (&$ran) {
            $ran++;

            return Limit::none();
        });

        foreach (self::ROUTES as $path) {
            Auth::forgetGuards();
            $this->unbound();

            $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
                ->getJson($this->portalUrl($org, $path))
                ->assertNotFound();

            $response->assertHeaderMissing('X-RateLimit-Limit');
            $response->assertHeaderMissing('X-RateLimit-Remaining');
            $response->assertHeaderMissing('Retry-After');
        }

        // A real token that is looked up is stamped (Sanctum's last_used_at); this one never was.
        $this->assertNull($token->accessToken->fresh()->last_used_at, 'the token was never looked at');
        $this->assertSame(0, $ran, 'the `mobile` limiter closure never ran');
    }

    #[Test]
    public function off_a_flood_is_still_a_bare_404_never_a_429(): void
    {
        config(['app.debug' => false]);
        $org = $this->org();
        $unknown = $this->getJson($this->portalUrl($org, 'no-such-route'))->assertNotFound()->getContent();

        $this->assertTheRouteIsReal($org, 'orders');

        // Past the inline `throttle:30,1,member-portal` bucket: were the throttle ahead of the
        // gate, the 31st call would be its 429.
        for ($attempt = 1; $attempt <= 35; $attempt++) {
            $this->assertSame($unknown, $this->bare()->getJson($this->portalUrl($org, 'orders'))->assertNotFound()->getContent(), "attempt {$attempt}");
        }
    }

    #[Test]
    public function the_gate_runs_before_authentication_and_before_every_throttle_on_each_route(): void
    {
        // The middleware aliases, groups and priority list reach the router when the HTTP
        // kernel is built, which the first request does. Without it the names below would
        // stay unresolved and unsorted.
        $this->app->make(HttpKernel::class);
        $router = app('router');

        foreach (self::ROUTES as $name => $path) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, "{$name} is registered");

            // The SORTED, resolved stack: the order a request runs it in. `gatherMiddleware()`
            // is the order the route LISTS it in, which Laravel re-sorts by its priority list.
            $stack = $router->gatherRouteMiddleware($route);

            $gate = array_search(EnsureMemberPortalEnabled::class, $stack, true);
            $this->assertNotFalse($gate, "{$name} is behind the portal gate");

            $ranked = array_keys(array_filter(
                $stack,
                static fn ($middleware): bool => is_string($middleware)
                    && (str_starts_with($middleware, Authenticate::class . ':') || str_starts_with($middleware, ThrottleRequests::class . ':'))
            ));

            $this->assertNotSame([], $ranked, "{$name} has authentication and throttles to run behind the gate");

            foreach ($ranked as $later) {
                $this->assertLessThan($later, $gate, "{$name}: the gate runs before {$stack[$later]}");
            }
        }
    }

    #[Test]
    public function switched_on_the_routes_answer(): void
    {
        $org = $this->org();
        $me = $this->member($org);
        $this->turnMemberPortalOn();

        $this->asMember($me)->getJson($this->portalUrl($org, 'orders'))->assertOk();
        $this->asMember($me)->getJson($this->portalUrl($org, 'gifts'))->assertOk();
    }

    #[Test]
    public function on_an_unauthenticated_call_is_the_stacks_401_not_the_gates_404(): void
    {
        $org = $this->org();
        $this->turnMemberPortalOn();

        foreach (self::ROUTES as $path) {
            $this->bare()->getJson($this->portalUrl($org, $path))->assertUnauthorized();
        }
    }

    #[Test]
    public function an_allowlist_leaves_every_other_organisation_dark(): void
    {
        config(['app.debug' => false]);
        $listed = $this->org();
        $other = $this->org();
        $listedMember = $this->member($listed);
        $otherMember = $this->member($other);

        config(['member_portal.enabled' => true, 'member_portal.masjid_ids' => [$listed->id]]);

        $unknown = $this->getJson($this->portalUrl($other, 'no-such-route'))->assertNotFound()->getContent();

        $this->asMember($listedMember)->getJson($this->portalUrl($listed, 'orders'))->assertOk();

        // The premise: the unlisted organisation's URL is a real route (on for everyone, it
        // answers the portal's 401).
        $this->assertTheRouteIsReal($other, 'orders');

        // The unlisted organisation's own member gets the router's 404, not their orders.
        $response = $this->asMember($otherMember)->getJson($this->portalUrl($other, 'orders'));
        $this->assertSame($unknown, $response->assertNotFound()->getContent());
    }

    #[Test]
    public function the_organisation_is_the_routes_not_a_header(): void
    {
        $listed = $this->org();
        $other = $this->org();
        $otherMember = $this->member($other);

        config(['member_portal.enabled' => true, 'member_portal.masjid_ids' => [$listed->id]]);

        $this->assertTheRouteIsReal($other, 'orders');

        // A `masjid-id` header naming the listed organisation cannot open another one's URL.
        $this->asMember($otherMember)
            ->withHeader('masjid-id', (string) $listed->id)
            ->getJson($this->portalUrl($other, 'orders'))
            ->assertNotFound();
    }

    #[Test]
    public function a_list_that_failed_closed_admits_nobody(): void
    {
        $org = $this->org();
        $me = $this->member($org);

        // config/member_portal.php turns a malformed MEMBER_PORTAL_MASJID_IDS into [0]
        // (MemberPortalConfigTest).
        config(['member_portal.enabled' => true, 'member_portal.masjid_ids' => [0]]);

        $this->assertFalse(EnsureMemberPortalEnabled::enabledFor($org->id));
        $this->assertFalse(EnsureMemberPortalEnabled::enabledFor(0));
        $this->assertTheRouteIsReal($org, 'orders');
        $this->asMember($me)->getJson($this->portalUrl($org, 'orders'))->assertNotFound();
    }

    #[Test]
    public function the_master_switch_wins_over_the_allowlist(): void
    {
        $org = $this->org();
        $me = $this->member($org);

        config(['member_portal.enabled' => false, 'member_portal.masjid_ids' => [$org->id]]);

        $this->assertFalse(EnsureMemberPortalEnabled::enabledFor($org->id));
        $this->assertTheRouteIsReal($org, 'orders');
        $this->asMember($me)->getJson($this->portalUrl($org, 'orders'))->assertNotFound();
    }

    #[Test]
    public function enabled_for_admits_only_the_ids_the_list_names(): void
    {
        config(['member_portal.enabled' => true, 'member_portal.masjid_ids' => [7, 12]]);

        $this->assertTrue(EnsureMemberPortalEnabled::enabledFor(7));
        $this->assertTrue(EnsureMemberPortalEnabled::enabledFor(12));
        $this->assertFalse(EnsureMemberPortalEnabled::enabledFor(8));
        $this->assertFalse(EnsureMemberPortalEnabled::enabledFor(0));

        config(['member_portal.masjid_ids' => []]);

        $this->assertTrue(EnsureMemberPortalEnabled::enabledFor(8), 'an empty list means every organisation');
    }
}
