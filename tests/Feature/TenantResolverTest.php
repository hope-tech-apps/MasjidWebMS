<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveMasjidTenant;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use App\Support\TenantContext;
use App\Support\TenantResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * S3 — the membership resolver, the fail-closed middleware, and the gate that
 * keeps production behaviour identical (docs/multi-tenant-admin-design.md).
 *
 * This is the riskiest slice, and it has to satisfy two things that pull in
 * opposite directions:
 *
 *   1. **Nothing observable changes.** The tenant is now derived from a
 *      verified `masjid_user` membership instead of a raw id, and for every
 *      user who can exist in production that must produce the SAME binding, the
 *      SAME 403 with the SAME message, and the same unbound-unless-routed
 *      SuperAdmin. The rest of the suite is the bulk of that proof — ~28 files
 *      drive admin HTTP as a MasjidAdmin and none of them was touched — and the
 *      "parity" section below pins it directly.
 *   2. **Everything ambiguous fails closed.** MySQL has no row-level security,
 *      so an unbound context is not a cautious default, it is NO FILTER. The
 *      "fail closed" section walks every branch that could have guessed.
 *
 * The reconciliation is the gate: `tenancy.multi_membership` ships FALSE, so a
 * user's grants are the single organisation they own and any further membership
 * row is inert. The "gate" section proves that a second membership grants
 * nothing in the shipped configuration and that flipping the flag — and only
 * flipping the flag — is what makes the multi-tenant path live.
 *
 * Sqlite-in-memory + RefreshDatabase per the testing convention.
 */
class TenantResolverTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        // Same idiom as TenantIsolationTest: this suite must never need a
        // network DB to run.
        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->tenant = app(TenantContext::class);
        $this->tenant->forgetTenant();
    }

    // ------------------------------------------------------------------ parity

    /**
     * The acceptance bar, stated once: the admin production actually has — one
     * live owned masjid, one default membership naming it — binds to that
     * masjid on a route that names none, exactly as before S3.
     */
    #[Test]
    public function a_single_membership_admin_binds_to_their_masjid_exactly_as_before(): void
    {
        $admin = $this->masjidAdmin();
        $owned = $this->makeMasjid(['user_id' => $admin->id]);
        $this->membership($admin, $owned, ['is_default' => true]);

        $this->handleThrough($this->makeAuthedRequest($admin));

        $this->assertTrue($this->tenant->hasTenant());
        $this->assertSame($owned->id, $this->tenant->get());
    }

    /** And on a route that names their own masjid — the ordinary admin request. */
    #[Test]
    public function a_single_membership_admin_binds_on_a_route_naming_their_own_masjid(): void
    {
        $admin = $this->masjidAdmin();
        $owned = $this->makeMasjid(['user_id' => $admin->id]);
        $this->membership($admin, $owned, ['is_default' => true]);

        $this->handleThrough($this->makeAuthedRequest($admin, $owned->id));

        $this->assertSame($owned->id, $this->tenant->get());
    }

    /**
     * Behaviour parity does NOT depend on the pivot row existing.
     *
     * S2's backfill ran once, at migration time; nothing has written a
     * membership since (provisioning gains that in S4) and no test fixture
     * creates one. An admin whose masjid was provisioned after the backfill
     * therefore has ownership and no membership row — and must keep working
     * exactly as they do today. This is the single most dangerous way S3 could
     * have broken production while every isolation test still passed.
     */
    #[Test]
    public function an_admin_with_no_membership_row_still_binds_from_ownership(): void
    {
        $admin = $this->masjidAdmin();
        $owned = $this->makeMasjid(['user_id' => $admin->id]);

        $this->assertSame(0, $admin->memberships()->count(), 'fixture: this admin deliberately has no pivot row');

        $this->handleThrough($this->makeAuthedRequest($admin));

        $this->assertSame($owned->id, $this->tenant->get());
    }

    /** The refusal is the same refusal: same status, same message, byte for byte. */
    #[Test]
    public function a_foreign_route_masjid_is_refused_with_the_identical_message(): void
    {
        $admin = $this->masjidAdmin();
        $this->makeMasjid(['user_id' => $admin->id]);
        $foreign = $this->makeMasjid();

        $refusal = $this->refusalFrom($this->makeAuthedRequest($admin, $foreign->id));

        $this->assertSame(403, $refusal['status']);
        $this->assertSame('You are not authorized to access this masjid.', $refusal['message']);
        $this->assertFalse($this->tenant->hasTenant(), 'a refused request must leave NOTHING bound');
    }

    /** SuperAdmin: unbound without a route masjid, bound to the one it names. */
    #[Test]
    public function a_super_admin_is_unbound_without_a_route_masjid_and_bound_with_one(): void
    {
        $super = $this->superAdmin();
        $masjid = $this->makeMasjid();

        $this->handleThrough($this->makeAuthedRequest($super));
        $this->assertFalse($this->tenant->hasTenant());

        $this->handleThrough($this->makeAuthedRequest($super, $masjid->id));
        $this->assertSame($masjid->id, $this->tenant->get());
    }

    /**
     * The branch ORDER, asserted behaviourally rather than by reading the file.
     *
     * MasjidAdmin is the `if` and SuperAdmin the `elseif`. Swapping them looks
     * harmless and is not: a SuperAdmin who also holds a membership would move
     * from route-derived binding to membership-derived binding, and would then
     * be 403'd out of every masjid they are not a member of. The design review
     * corrected exactly this proposal.
     */
    #[Test]
    public function a_super_admin_holding_a_membership_is_still_bound_from_the_route(): void
    {
        $super = $this->superAdmin();
        $memberOf = $this->makeMasjid();
        $elsewhere = $this->makeMasjid();
        $this->membership($super, $memberOf, ['is_default' => true]);

        $this->handleThrough($this->makeAuthedRequest($super, $elsewhere->id));

        $this->assertSame(
            $elsewhere->id,
            $this->tenant->get(),
            'a SuperAdmin acts on the masjid the URL names; their membership must not narrow that'
        );
    }

    /**
     * Invariant 8. The tenant comes from the ROUTE and from the membership —
     * never from anything the caller can type into a payload.
     */
    #[Test]
    public function a_masjid_id_in_the_body_or_a_header_is_ignored(): void
    {
        $admin = $this->masjidAdmin();
        $owned = $this->makeMasjid(['user_id' => $admin->id]);
        $this->membership($admin, $owned, ['is_default' => true]);
        $elsewhere = $this->makeMasjid();

        $request = Request::create('/api/admin/contacts', 'POST', ['masjid_id' => $elsewhere->id]);
        $request->headers->set('X-Masjid-Id', (string) $elsewhere->id);
        $request->setUserResolver(fn () => $admin);

        $this->handleThrough($request);

        $this->assertSame($owned->id, $this->tenant->get(), 'a forged masjid_id must not move the tenant');
    }

    /**
     * The binding carries its provenance now, which is the point of taking a
     * membership rather than an int: S4 has to echo the server-resolved tenant
     * on every response, and reconstructing "which grant admitted this?" after
     * the fact is the guesswork this slice removed.
     */
    #[Test]
    public function the_binding_carries_the_verified_membership_that_admitted_it(): void
    {
        $admin = $this->masjidAdmin();
        $owned = $this->makeMasjid(['user_id' => $admin->id]);
        $membership = $this->membership($admin, $owned, ['is_default' => true]);

        $this->handleThrough($this->makeAuthedRequest($admin, $owned->id));

        $bound = $this->tenant->membership();

        $this->assertNotNull($bound, 'an admin request must bind through setFromMembership(), not set()');
        $this->assertSame($membership->id, $bound->id);
        $this->assertSame($admin->id, (int) $bound->user_id);
    }

    /** A SuperAdmin's route-derived binding has no membership behind it, and says so. */
    #[Test]
    public function a_route_derived_super_admin_binding_carries_no_membership(): void
    {
        $masjid = $this->makeMasjid();

        $this->handleThrough($this->makeAuthedRequest($this->superAdmin(), $masjid->id));

        $this->assertSame($masjid->id, $this->tenant->get());
        $this->assertNull($this->tenant->membership());
    }

    // ------------------------------------------------------------- fail closed

    /**
     * No grant anywhere: refused, not defaulted and not silently unbound.
     *
     * Before S3 this user fell through to an UNBOUND context on any route that
     * named no masjid — and unbound means every masjid's rows, because there is
     * no row-level security under this. Unreachable in production (an admin
     * with no live owned masjid holds no membership either), which is why it is
     * safe to close and why it is asserted here rather than left to a reader.
     */
    #[Test]
    public function an_admin_with_no_membership_and_no_masjid_is_refused_not_defaulted(): void
    {
        $stray = $this->masjidAdmin();
        $someoneElses = $this->makeMasjid(['user_id' => $this->masjidAdmin()->id]);

        $refusal = $this->refusalFrom($this->makeAuthedRequest($stray));

        $this->assertSame(403, $refusal['status']);
        $this->assertFalse($this->tenant->hasTenant());
        $this->assertNotSame($someoneElses->id, $this->tenant->get());
    }

    /**
     * A membership for a TRASHED organisation grants nothing. S2 keeps those
     * rows on purpose — restoring a masjid must bring its admins back — so the
     * resolver, not the schema, is what has to refuse them. Asserted on both
     * sides of the gate, because the two paths reach the live-masjid check by
     * different routes (`whereHas('masjid')` vs. the soft-delete-scoped
     * ownership `hasOne`).
     */
    #[Test]
    public function a_membership_for_a_soft_deleted_masjid_does_not_bind(): void
    {
        $admin = $this->masjidAdmin();
        $trashed = $this->makeMasjid(['user_id' => $admin->id]);
        $this->membership($admin, $trashed, ['is_default' => true]);
        $trashed->delete();

        $this->assertSame(1, $admin->memberships()->count(), 'fixture: the membership row survives the soft delete');

        $this->assertSame(403, $this->refusalFrom($this->makeAuthedRequest($admin))['status']);
        $this->assertFalse($this->tenant->hasTenant());

        $this->assertSame(403, $this->refusalFrom($this->makeAuthedRequest($admin, $trashed->id))['status']);
        $this->assertFalse($this->tenant->hasTenant());

        $this->openTheMultiMembershipGate();

        $this->assertSame(403, $this->refusalFrom($this->makeAuthedRequest($admin, $trashed->id))['status']);
        $this->assertFalse($this->tenant->hasTenant());
    }

    /**
     * Holding a grant SOMEWHERE ELSE softens nothing. The id in the URL is
     * answered yes or no against this user's own grants; it is never
     * substituted for one they do hold. Asserted with the gate OPEN so the
     * multi-membership path itself is what is being tested — with the gate
     * closed the second membership is not even a grant.
     */
    #[Test]
    public function a_route_masjid_the_user_holds_no_membership_for_is_refused(): void
    {
        $this->openTheMultiMembershipGate();

        $admin = $this->masjidAdmin();
        $a = $this->makeMasjid(['user_id' => $admin->id]);
        $b = $this->makeMasjid();
        $stranger = $this->makeMasjid();

        $this->membership($admin, $a, ['is_default' => true]);
        $this->membership($admin, $b);

        $refusal = $this->refusalFrom($this->makeAuthedRequest($admin, $stranger->id));

        $this->assertSame(403, $refusal['status']);
        $this->assertSame('You are not authorized to access this masjid.', $refusal['message']);
        $this->assertFalse($this->tenant->hasTenant());
    }

    /**
     * Ambiguity is a refusal, never a default. Two grants and nothing in the
     * route to choose between them: binding the `is_default` row, or the first
     * one the database returned, is exactly the silent mis-scoping this slice
     * exists to remove.
     */
    #[Test]
    public function several_memberships_and_no_route_masjid_never_default_bind(): void
    {
        $this->openTheMultiMembershipGate();

        $admin = $this->masjidAdmin();
        $a = $this->makeMasjid(['user_id' => $admin->id]);
        $b = $this->makeMasjid();
        $this->membership($admin, $a, ['is_default' => true]);
        $this->membership($admin, $b);

        $refusal = $this->refusalFrom($this->makeAuthedRequest($admin));

        $this->assertSame(403, $refusal['status']);
        $this->assertFalse($this->tenant->hasTenant(), 'the default membership must NOT have been bound');
    }

    /**
     * ...with one narrow exception, and it is an allowlist rather than "any
     * route without {masjid_id}": a multi-tenant admin still has to be able to
     * read their own account and log out. Those endpoints touch no
     * tenant-scoped model, so unbound is honest there.
     */
    #[Test]
    public function several_memberships_leave_the_account_routes_unbound_rather_than_refused(): void
    {
        $this->openTheMultiMembershipGate();

        $admin = $this->masjidAdmin();
        $a = $this->makeMasjid(['user_id' => $admin->id]);
        $b = $this->makeMasjid();
        $this->membership($admin, $a, ['is_default' => true]);
        $this->membership($admin, $b);

        $request = Request::create('/api/admin/user', 'GET');
        $request->setUserResolver(fn () => $admin);

        $this->handleThrough($request);

        $this->assertFalse($this->tenant->hasTenant());
        $this->assertNull($this->tenant->membership());
    }

    /**
     * A principal who is neither admin type is refused instead of falling
     * through to an unbound (= unfiltered) context. `UserAdminMiddleware` 401s
     * them one layer earlier on every route this middleware is registered on,
     * so nothing reachable changes — this removes the second layer's dependence
     * on the first, the same reasoning T-015a applied to `instanceof User`.
     */
    #[Test]
    public function a_principal_that_is_not_an_admin_is_refused(): void
    {
        $member = $this->user('User');
        $this->makeMasjid(['user_id' => $member->id]);

        $this->assertSame(403, $this->refusalFrom($this->makeAuthedRequest($member))['status']);
        $this->assertFalse($this->tenant->hasTenant());
    }

    /** A guest still passes straight through unbound — the public mobile API. */
    #[Test]
    public function an_unauthenticated_request_is_untouched(): void
    {
        $this->handleThrough(Request::create('/api/masjids/1/prayer-times', 'GET'));

        $this->assertFalse($this->tenant->hasTenant());
    }

    /**
     * The backstop inside TenantContext itself. Binding a membership with no
     * masjid would read as UNBOUND — no filter at all — so it throws rather
     * than binding nothing. The resolver never produces one; this makes a
     * future caller's mistake loud instead of invisible.
     */
    #[Test]
    public function binding_a_membership_with_no_masjid_is_refused_outright(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->tenant->setFromMembership(new MasjidUser(['user_id' => $this->masjidAdmin()->id]));
    }

    // ------------------------------------------------------------------- gate

    /**
     * The gate is shut in the shipped configuration, and nothing in this
     * environment overrides it. Every assertion below about a second membership
     * being inert is only meaningful because this one holds.
     */
    #[Test]
    public function the_multi_membership_path_is_off_in_the_shipped_configuration(): void
    {
        $shipped = require config_path('tenancy.php');

        $this->assertFalse(
            $shipped['multi_membership'],
            'config/tenancy.php must ship with the multi-membership path off — it is S5\'s lever, not S3\'s.'
        );
        $this->assertNull(
            env('TENANCY_MULTI_MEMBERSHIP'),
            'nothing may set TENANCY_MULTI_MEMBERSHIP; production runs on the shipped default.'
        );
        $this->assertFalse(config('tenancy.multi_membership'));
    }

    /**
     * THE PRODUCTION-CONFIGURATION PROOF. An admin holding a second membership
     * behaves exactly as they did before S3: bound to the masjid they own, and
     * 403 on the masjid their extra membership names. The row exists, it is
     * simply not a grant yet.
     *
     * This is the same scenario MasjidMembershipPivotTest asserted for S2 —
     * whose docblock predicted "when S3 lands, this becomes a 200". It does not,
     * and that is the gate working: that test passes unmodified.
     */
    #[Test]
    public function with_the_gate_shut_a_second_membership_grants_nothing(): void
    {
        $admin = $this->masjidAdmin();
        $owned = $this->makeMasjid(['user_id' => $admin->id]);
        $other = $this->makeMasjid();

        $this->membership($admin, $owned, ['is_default' => true]);
        $this->membership($admin, $other);

        $this->handleThrough($this->makeAuthedRequest($admin));
        $this->assertSame($owned->id, $this->tenant->get(), 'the owned masjid still wins, exactly as before S3');

        $this->tenant->forgetTenant();

        $refusal = $this->refusalFrom($this->makeAuthedRequest($admin, $other->id));
        $this->assertSame(403, $refusal['status']);
        $this->assertSame('You are not authorized to access this masjid.', $refusal['message']);
        $this->assertFalse($this->tenant->hasTenant());
    }

    /**
     * ...and the flag is the ONLY thing holding it shut. The identical fixture,
     * with the gate open, binds the second membership — which proves both that
     * the multi-tenant path is really implemented and that the test above is
     * measuring the gate rather than a resolver that simply cannot do it.
     */
    #[Test]
    public function with_the_gate_open_the_same_second_membership_binds(): void
    {
        $admin = $this->masjidAdmin();
        $owned = $this->makeMasjid(['user_id' => $admin->id]);
        $other = $this->makeMasjid();

        $this->membership($admin, $owned, ['is_default' => true]);
        $second = $this->membership($admin, $other);

        $this->openTheMultiMembershipGate();

        $this->handleThrough($this->makeAuthedRequest($admin, $other->id));

        $this->assertSame($other->id, $this->tenant->get());
        $this->assertSame($second->id, $this->tenant->membership()?->id);
    }

    /**
     * The gate is read in exactly one place, so lifting it in S5 is a
     * one-line change and cannot be done by halves. Asserted against the class
     * text because "only one place reads the flag" is a property of the source,
     * not of any single request.
     */
    #[Test]
    public function the_gate_is_read_in_exactly_one_place(): void
    {
        $source = file_get_contents((new \ReflectionClass(TenantResolver::class))->getFileName());

        $this->assertSame(
            1,
            substr_count($source, "config('tenancy.multi_membership'"),
            'the multi-membership flag must be read once, in TenantResolver::multiMembershipEnabled().'
        );
        $this->assertSame(
            1,
            substr_count($source, '$this->multiMembershipEnabled()'),
            'and consulted from a single branch, in grantsFor().'
        );
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Open the gate the way S5 will. Deliberately a helper with a loud name:
     * every test that calls it is exercising a path production cannot reach.
     */
    private function openTheMultiMembershipGate(): void
    {
        config(['tenancy.multi_membership' => true]);
    }

    /**
     * Run the middleware and return the refusal, failing the test if the
     * request was ALLOWED — otherwise a resolver that stopped refusing would
     * quietly turn these into vacuous passes.
     *
     * @return array{status:int,message:string}
     */
    private function refusalFrom(Request $request): array
    {
        try {
            $this->handleThrough($request);
        } catch (HttpException $e) {
            return ['status' => $e->getStatusCode(), 'message' => $e->getMessage()];
        }

        $this->fail('Expected the request to be refused, but it was allowed through.');
    }

    private function membership(User $user, Masjid $masjid, array $overrides = []): MasjidUser
    {
        return MasjidUser::create(array_merge([
            'masjid_id' => $masjid->id,
            'user_id' => $user->id,
            'role' => 'masjid-admin',
            'is_default' => false,
        ], $overrides));
    }

    /** Create a Masjid row with the minimum columns the schema requires. */
    private function makeMasjid(array $overrides = []): Masjid
    {
        return Masjid::create(array_merge([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ], $overrides));
    }

    private function masjidAdmin(): User
    {
        return $this->user('MasjidAdmin');
    }

    private function superAdmin(): User
    {
        return $this->user('SuperAdmin');
    }

    private function user(string $type): User
    {
        return User::factory()->create([
            'type' => $type,
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
    }

    /**
     * Build a request whose authenticated user is $user, optionally naming a
     * masjid in the route the way the admin SPA does (/masjids/{masjid_id}/...).
     * ResolveMasjidTenant reads that ROUTE parameter, so the route has to be
     * bound for the masjid-scoped paths to be exercised at all.
     */
    private function makeAuthedRequest(User $user, ?int $routeMasjidId = null): Request
    {
        $uri = $routeMasjidId === null
            ? '/api/admin/contacts'
            : "/api/admin/masjids/{$routeMasjidId}/contacts";

        $request = Request::create($uri, 'GET');
        $request->setUserResolver(fn () => $user);

        if ($routeMasjidId !== null) {
            $route = new RoutingRoute(['GET'], '/api/admin/masjids/{masjid_id}/contacts', fn () => null);
            $route->bind($request);
            $request->setRouteResolver(fn () => $route);
        }

        return $request;
    }

    /** Run ResolveMasjidTenant (resolved with the shared TenantContext). */
    private function handleThrough(Request $request): void
    {
        app(ResolveMasjidTenant::class)->handle($request, fn () => response('ok'));
    }
}
