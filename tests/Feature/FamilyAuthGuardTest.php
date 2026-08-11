<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminDashboard\AuthController;
use App\Http\Middleware\EnsureFamilyLoginActive;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\Masjid;
use App\Models\User;
use App\Support\GroupAudience;
use App\Support\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Guard as SpatieGuard;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * T-015c — the `family` guard, and the two directions of realm separation it
 * has to hold in.
 *
 * The data behind this boundary is children's photographs, behaviour records
 * and safeguarding conversations between a teacher and a guardian. A parent
 * token reaching an admin endpoint, resolving to the wrong ward, or surviving
 * revocation is the worst failure this system can have — so every invariant
 * below is paired with a CONTROL that reproduces the vulnerable configuration
 * and shows the bad outcome. An assertion that would pass with the guard
 * deleted is not evidence of anything.
 *
 * Everything here authenticates through REAL bearer tokens rather than
 * `Sanctum::actingAs()`, for the reason `StaffAuthGuardPinTest` documents:
 * `actingAs` calls `setUser()` on the guard directly and never enters
 * `Laravel\Sanctum\Guard::__invoke()`, which is where the provider comparison
 * this slice depends on actually lives.
 *
 * See docs/t015-parent-identity-design.md §§2–6 and invariants 3, 4, 5.
 */
class FamilyAuthGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        // The staff regression checks below hit permission-gated CRM routes, and
        // the spatie-resolution invariant needs the 8 permissions to exist.
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    // ---------------------------------------------------------------- helpers

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
            // The family stack carries `crm`, exactly like the staff CRM tree.
            'crm_enabled' => true,
        ], $overrides));
    }

    private function makeUser(string $type): User
    {
        return User::factory()->create([
            'type' => $type,
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
    }

    private function makeAdminFor(Masjid $masjid): User
    {
        $admin = $this->makeUser('MasjidAdmin');

        $masjid->user_id = $admin->id;
        $masjid->save();

        return $admin;
    }

    /**
     * A contact that may log in.
     *
     * `forceFill` rather than `create([...])` on purpose, and it is not a test
     * convenience: the four `login_*` columns are deliberately absent from
     * `Contact::$fillable` so that no CRM request body can enable a login as a
     * side effect (see the model). A test that could mass-assign them would be
     * testing a model this application does not have.
     */
    private function makeContactWithLogin(Masjid $masjid, array $login = []): Contact
    {
        $contact = Contact::factory()->create([
            'masjid_id' => $masjid->id,
            'email' => 'roster-' . uniqid() . '@test.local',
        ]);

        $contact->forceFill(array_merge([
            'login_email' => 'parent-' . uniqid() . '@test.local',
            'login_enabled_at' => now(),
        ], $login))->save();

        return $contact->refresh();
    }

    private function familyToken(Contact $contact): string
    {
        return $contact->createFamilyToken()->plainTextToken;
    }

    /** The real staff mint path — login over HTTP, exactly as a client does. */
    private function staffToken(User $user): string
    {
        $response = $this->postJson('/api/admin/login', [
            'email' => $user->email,
            'password' => 'password', // UserFactory default
        ])->assertOk();

        $token = $response->json('data.token');
        $this->assertNotEmpty($token, 'staff login must return a bearer token');

        return $token;
    }

    private function meUrl(Masjid $masjid): string
    {
        return "/api/family/masjids/{$masjid->id}/me";
    }

    /**
     * Put the process back into the state a genuinely NEW request starts from.
     *
     * This is not a workaround, it is the whole point of the revocation tests
     * below. Two `$this->getJson()` calls inside one test method share one
     * container, and `Illuminate\Auth\RequestGuard::user()` MEMOIZES the
     * principal it resolved — so a second call would be answered out of the
     * first call's already-loaded Contact, never re-reading `login_revoked_at`
     * and never re-running the tokenable lookup. The test would pass with the
     * revocation check deleted.
     *
     * Production has no such carry-over: php-fpm builds a fresh container per
     * request (this app does not run Octane — see App\Support\TenantContext),
     * so the guard, the tokenable read and the tenant binding all start from
     * nothing. Dropping the resolved guards and the tenant reproduces exactly
     * that, which is what makes the second call an honest "next request".
     */
    private function asANewRequest(): void
    {
        Auth::forgetGuards();
        app(TenantContext::class)->forgetTenant();
    }

    /** A request carrying $token as its bearer credential. */
    private function bearerRequest(string $uri, string $token): Request
    {
        $request = Request::create($uri, 'GET');
        $request->headers->set('Authorization', 'Bearer ' . $token);

        return $request;
    }

    /**
     * Every registered route under $prefix that sits behind $guard.
     *
     * Enumerated from the router rather than read out of the route file, so a
     * route added tomorrow is covered by these invariants the moment it exists
     * — which is what design invariants 1 and 2 ask for.
     *
     * @return array<int, array{0:string, 1:string}> [method, uri] pairs
     */
    private function routesBehind(string $prefix, string $guard): array
    {
        $found = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), $prefix)) {
                continue;
            }

            if (! in_array($guard, $route->gatherMiddleware(), true)) {
                continue;
            }

            $method = collect($route->methods())->first(fn ($m) => $m !== 'HEAD');

            // Route parameters are irrelevant to an auth refusal — the request
            // only has to MATCH so the middleware stack runs.
            $found[] = [$method, '/' . preg_replace('/\{[^}]+\}/', '1', $route->uri())];
        }

        return $found;
    }

    // ------------------------------------------------- 1. the guard is declared

    #[Test]
    public function the_family_guard_is_pinned_to_the_contacts_provider(): void
    {
        $this->assertSame('sanctum', config('auth.guards.family.driver'));
        $this->assertSame('contacts', config('auth.guards.family.provider'));
        $this->assertSame(Contact::class, config('auth.providers.contacts.model'));

        // And T-015a's pin is untouched — the two realms point at two models.
        $this->assertSame('users', config('auth.guards.sanctum.provider'));
        $this->assertSame(User::class, config('auth.providers.users.model'));
    }

    #[Test]
    public function a_contact_is_authenticatable_and_holds_no_password(): void
    {
        $contact = $this->makeContactWithLogin($this->makeMasjid());

        $this->assertInstanceOf(\Illuminate\Contracts\Auth\Authenticatable::class, $contact);

        // There is no password column and no password-based guard over this
        // provider. An empty hash makes every hasher answer false without work,
        // so if one is ever pointed here by mistake no credential satisfies it.
        $this->assertSame('', $contact->getAuthPassword());
        $this->assertFalse(\Illuminate\Support\Facades\Hash::check('password', $contact->getAuthPassword()));
        $this->assertNull($contact->getRememberTokenName());
    }

    // -------------------------------------------------------- 2. the happy path

    #[Test]
    public function a_family_token_reads_its_own_identity(): void
    {
        $masjid = $this->makeMasjid();
        $contact = $this->makeContactWithLogin($masjid);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->familyToken($contact))
            ->getJson($this->meUrl($masjid))
            ->assertOk();

        $response->assertJsonPath('status', 'success');
        $response->assertJsonPath('data.id', $contact->id);
        $response->assertJsonPath('data.masjid_id', $masjid->id);
        $response->assertJsonPath('data.first_name', $contact->first_name);
        $response->assertJsonPath('data.login_email', $contact->login_email);

        // Least disclosure is a property of the PAYLOAD. `notes` is the
        // staff-authored free-text field on the CRM record — the natural home
        // for a safeguarding remark — and must never travel to the family app.
        $response->assertJsonMissingPath('data.notes');
        $response->assertJsonMissingPath('data.email');
        $response->assertJsonMissingPath('data.phone');
    }

    #[Test]
    public function a_family_token_carries_the_family_ability_and_never_the_staff_one(): void
    {
        $contact = $this->makeContactWithLogin($this->makeMasjid());
        $this->familyToken($contact);

        $abilities = $contact->tokens()->latest('id')->first()->abilities;

        $this->assertSame(Contact::FAMILY_TOKEN_ABILITIES, $abilities);
        $this->assertNotContains('*', $abilities);
        $this->assertNotSame(AuthController::STAFF_TOKEN_ABILITIES, $abilities);
    }

    // ------------------------------- 3. a family token cannot reach the admin API

    #[Test]
    public function a_family_token_is_refused_on_every_authenticated_admin_route(): void
    {
        $masjid = $this->makeMasjid();
        $contact = $this->makeContactWithLogin($masjid);
        $token = $this->familyToken($contact);

        $routes = $this->routesBehind('api/admin', 'auth:sanctum');

        // Non-vacuity: an empty enumeration would make every assertion below
        // trivially true. The admin tree is large; if this number collapses,
        // the filter is wrong, not the application.
        $this->assertGreaterThan(100, count($routes), 'the admin route enumeration found almost nothing');

        foreach ($routes as [$method, $uri]) {
            $response = $this->withHeader('Authorization', 'Bearer ' . $token)->json($method, $uri);

            $this->assertSame(401, $response->getStatusCode(), "{$method} {$uri} must refuse a family token");

            // WHICH 401 matters. This is the envelope the app's
            // AuthenticationException renderer emits (bootstrap/app.php), so the
            // refusal came from `auth:sanctum` itself — inside vendor code,
            // before `admin`, `tenant`, `crm` or any `permission:` gate ran.
            // UserAdminMiddleware answers `{status: failed, data: ...}`, so this
            // assertion cannot be satisfied by a permission check standing in.
            $response->assertJsonPath('status', 'error');
            $response->assertJsonPath('message', 'Unauthenticated.');
        }
    }

    #[Test]
    public function the_sanctum_guard_resolves_a_contact_tokenable_to_null(): void
    {
        $contact = $this->makeContactWithLogin($this->makeMasjid());
        $token = $this->familyToken($contact);

        $resolved = Auth::guard('sanctum')
            ->setRequest($this->bearerRequest('/api/admin/user', $token))
            ->user();

        $this->assertNull($resolved, 'a Contact token must resolve to null on the staff guard');
    }

    #[Test]
    public function control_without_the_sanctum_pin_a_family_token_authenticates_on_the_admin_guard(): void
    {
        // THE CONTROL for the two tests above. Sanctum reads its provider out of
        // config when the guard is resolved, so restoring the pre-T-015a value
        // and dropping the resolved guards reproduces the vulnerable app.
        config(['auth.guards.sanctum.provider' => null]);
        Auth::forgetGuards();

        $contact = $this->makeContactWithLogin($this->makeMasjid());
        $token = $this->familyToken($contact);

        $resolved = Auth::guard('sanctum')
            ->setRequest($this->bearerRequest('/api/admin/user', $token))
            ->user();

        $this->assertInstanceOf(
            Contact::class,
            $resolved,
            'unpinned, Guard::hasValidProvider() short-circuits to true and a PARENT token '
            . 'authenticates on the staff guard. This is what the pin closes, and it is now '
            . 'a real model rather than a test fixture. If this assertion ever fails because '
            . 'the contact is rejected anyway, the pin is no longer what protects the admin API.'
        );
    }

    #[Test]
    public function control_without_the_sanctum_pin_only_the_admin_middleware_stands_in_the_way(): void
    {
        config(['auth.guards.sanctum.provider' => null]);
        Auth::forgetGuards();

        $contact = $this->makeContactWithLogin($this->makeMasjid());

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->familyToken($contact))
            ->postJson('/api/admin/logout')
            ->assertStatus(401);

        // UserAdminMiddleware's envelope, NOT the guard's. Reaching it proves
        // auth:sanctum admitted a Contact; being stopped there proves the
        // `instanceof User` layer holds on its own, which is what covers the
        // guards that are not sanctum.
        $response->assertJsonPath('status', 'failed');
        $response->assertJsonPath('data', 'Unauthorized.');
    }

    // ------------------------------ 4. a staff token cannot reach the family API

    #[Test]
    public function a_staff_token_is_refused_on_every_family_route(): void
    {
        $masjid = $this->makeMasjid();
        $token = $this->staffToken($this->makeAdminFor($masjid));

        $routes = $this->routesBehind('api/family', 'auth:family');

        $this->assertNotEmpty($routes, 'the family route enumeration found nothing');

        foreach ($routes as [$method, $uri]) {
            $response = $this->withHeader('Authorization', 'Bearer ' . $token)->json($method, $uri);

            $this->assertSame(401, $response->getStatusCode(), "{$method} {$uri} must refuse a staff token");

            // The guard's own envelope again: the refusal is the provider
            // comparison inside Sanctum, not `family.active` further down. Both
            // layers refuse a staff token, and this pins WHICH one did.
            $response->assertJsonPath('status', 'error');
            $response->assertJsonPath('message', 'Unauthenticated.');
        }
    }

    #[Test]
    public function the_family_guard_resolves_a_staff_tokenable_to_null(): void
    {
        $masjid = $this->makeMasjid();
        $token = $this->staffToken($this->makeAdminFor($masjid));

        $resolved = Auth::guard('family')
            ->setRequest($this->bearerRequest($this->meUrl($masjid), $token))
            ->user();

        $this->assertNull($resolved, 'a staff tokenable must resolve to null on the family guard');
    }

    #[Test]
    public function control_without_the_family_provider_a_staff_token_authenticates_on_the_family_guard(): void
    {
        // THE MIRROR of the sanctum control, for the direction this slice adds.
        // Written out because `provider => 'contacts'` looks like boilerplate:
        // omit the key (Sanctum then merges `provider => null`) or point it at
        // `users`, and `hasValidProvider()` stops comparing anything — a staff
        // token walks straight into the parent surface, where nothing else
        // checks a token's realm.
        $masjid = $this->makeMasjid();
        $token = $this->staffToken($this->makeAdminFor($masjid));

        config(['auth.guards.family.provider' => null]);
        Auth::forgetGuards();

        $resolved = Auth::guard('family')
            ->setRequest($this->bearerRequest($this->meUrl($masjid), $token))
            ->user();

        $this->assertInstanceOf(
            User::class,
            $resolved,
            'unprovidered, the family guard admits a STAFF token. If this assertion ever '
            . 'fails because the staff token is rejected anyway, the provider entry is no '
            . 'longer what keeps staff out of the family realm.'
        );
    }

    #[Test]
    public function a_staff_session_satisfies_the_family_guard_and_is_refused_only_by_family_active(): void
    {
        $masjid = $this->makeMasjid();
        $admin = $this->makeAdminFor($masjid);

        // A live admin SPA session, not a token.
        $this->actingAs($admin);

        // THE HAZARD, asserted rather than assumed. Sanctum walks
        // `config('sanctum.guard')` — ['web'] — BEFORE it looks at any bearer
        // token, and returns whatever it finds with no provider comparison. So
        // `auth:family` by itself admits a staff member outright.
        $this->assertInstanceOf(
            User::class,
            Auth::guard('family')->user(),
            'if this ever returns null, Sanctum stopped consulting the session guards and '
            . 'the comment on EnsureFamilyLoginActive needs revisiting — but do NOT delete '
            . 'that middleware on the strength of it.'
        );

        // And the request is refused anyway — by `family.active`, whose envelope
        // is deliberately distinct from the guard's `Unauthenticated.`
        $this->getJson($this->meUrl($masjid))
            ->assertStatus(401)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'Unauthorized.');
    }

    // --------------------------------------- 5. revocation, on the NEXT request

    #[Test]
    public function a_revoked_contacts_existing_token_stops_working_immediately(): void
    {
        $masjid = $this->makeMasjid();
        $contact = $this->makeContactWithLogin($masjid);
        $token = $this->familyToken($contact);

        // Same token throughout: revocation must reach a credential that is
        // already in a phone, not merely stop new ones being minted.
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson($this->meUrl($masjid))
            ->assertOk();

        $contact->forceFill(['login_revoked_at' => now()])->save();
        $this->asANewRequest();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson($this->meUrl($masjid))
            ->assertStatus(401)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'Unauthorized.');
    }

    #[Test]
    public function a_soft_deleted_contacts_existing_token_stops_working_immediately(): void
    {
        $masjid = $this->makeMasjid();
        $contact = $this->makeContactWithLogin($masjid);
        $token = $this->familyToken($contact);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson($this->meUrl($masjid))
            ->assertOk();

        $contact->delete();
        $this->asANewRequest();

        // The guard's envelope: Sanctum's tokenable lookup runs through the
        // SoftDeletes scope, so a trashed contact resolves to null and the
        // request is unauthenticated before `family.active` is reached.
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson($this->meUrl($masjid))
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    #[Test]
    public function a_merged_away_contacts_existing_token_stops_working_immediately(): void
    {
        $masjid = $this->makeMasjid();
        $contact = $this->makeContactWithLogin($masjid);
        $token = $this->familyToken($contact);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson($this->meUrl($masjid))
            ->assertOk();

        // What `ContactsController::merge` does to the absorbed side. It fires
        // no cascade over `personal_access_tokens`, so the token ROW survives
        // with a dangling tokenable — and must still fail closed. (T-015g makes
        // merge refuse a login-enabled contact outright; this is the backstop
        // underneath that.)
        $contact->forceDelete();
        $this->asANewRequest();

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_type' => Contact::class,
            'tokenable_id' => $contact->id,
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson($this->meUrl($masjid))
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    #[Test]
    public function a_contact_that_was_never_enabled_cannot_use_a_token(): void
    {
        $masjid = $this->makeMasjid();

        // The state EVERY pre-existing contact is in after this migration.
        $contact = $this->makeContactWithLogin($masjid, ['login_enabled_at' => null]);

        $this->withHeader('Authorization', 'Bearer ' . $this->familyToken($contact))
            ->getJson($this->meUrl($masjid))
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthorized.');
    }

    #[Test]
    public function family_active_refuses_a_trashed_contact_on_its_own(): void
    {
        // The trashed case is normally caught upstream by the tokenable lookup,
        // which means the branch inside `familyLoginIsActive()` would otherwise
        // never be exercised — and an unexercised fail-closed branch is a
        // comment. Drive the middleware directly, the way StaffAuthGuardPinTest
        // reaches UserAdminMiddleware with a principal the guard would refuse.
        $contact = $this->makeContactWithLogin($this->makeMasjid());
        $contact->delete();

        $this->assertSame(401, $this->runFamilyActiveAs($contact)->getStatusCode());
        $this->assertSame(
            ['status' => 'error', 'message' => 'Unauthorized.'],
            $this->runFamilyActiveAs($contact)->getData(true)
        );
    }

    #[Test]
    public function family_active_admits_a_live_contact_and_refuses_every_other_principal(): void
    {
        $masjid = $this->makeMasjid();

        $live = $this->makeContactWithLogin($masjid);
        $this->assertSame(200, $this->runFamilyActiveAs($live)->getStatusCode());
        $this->assertSame('ok', $this->runFamilyActiveAs($live)->getContent());

        // A staff User — the session hazard, at the middleware layer.
        $this->assertSame(401, $this->runFamilyActiveAs($this->makeUser('SuperAdmin'))->getStatusCode());

        // A guest.
        Auth::forgetGuards();
        $guest = app(EnsureFamilyLoginActive::class)
            ->handle(Request::create($this->meUrl($masjid), 'GET'), fn () => response('ok'));
        $this->assertSame(401, $guest->getStatusCode());
    }

    // -------------------------- 6. adding a guard did not perturb spatie/permissions

    #[Test]
    public function the_family_guard_does_not_perturb_spatie_guard_resolution(): void
    {
        // The T-015a hazard, re-run for the guard this slice adds.
        // `AuthManager::shouldUse()` rewrites `auth.defaults.guard` in the
        // config repository on every authenticated request, and `auth:family`
        // rewrites it to `family`. Spatie prefers that default whenever it also
        // appears among the guards whose provider model is the model in hand —
        // which is why `family` points at `contacts` and never at `users`.
        Auth::shouldUse('family');
        $this->assertSame('family', config('auth.defaults.guard'));

        $this->assertSame(['web'], SpatieGuard::getNames(User::class)->all());
        $this->assertSame('web', SpatieGuard::getDefaultName(User::class));

        $this->assertTrue($this->makeUser('MasjidAdmin')->fresh()->hasPermissionTo('view contacts'));
    }

    #[Test]
    public function the_seeded_permission_set_is_unchanged_by_the_family_guard(): void
    {
        $this->assertSame(8, Permission::count());
    }

    #[Test]
    public function staff_authentication_is_observably_unchanged(): void
    {
        // The acceptance bar for this slice, exercised end to end through the
        // real login endpoint and a permission-gated CRM route: adding a second
        // realm must not move staff by one byte.
        $masjid = $this->makeMasjid();
        $admin = $this->makeAdminFor($masjid);
        Contact::factory()->count(2)->create(['masjid_id' => $masjid->id]);

        $token = $this->staffToken($admin);

        // auth:sanctum -> admin -> tenant -> crm -> permission, all still green.
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson("/api/admin/masjids/{$masjid->id}/contacts")
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/user')
            ->assertOk()
            ->assertJsonPath('data.id', $admin->id);

        // And a foreign masjid is still a 403, not a filtered 200.
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/masjids/' . $this->makeMasjid()->id . '/contacts')
            ->assertStatus(403);
    }

    // ------------------- 7. an authenticated parent STILL has no standing (T-015e)

    #[Test]
    public function an_authenticated_contact_still_resolves_to_no_standing_in_group_audience(): void
    {
        $masjid = $this->makeMasjid();
        $parent = $this->makeContactWithLogin($masjid);
        $ward = Contact::factory()->create(['masjid_id' => $masjid->id]);

        $group = Group::factory()->create(['masjid_id' => $masjid->id]);

        // The strongest version of the claim: this parent holds a REAL guardian
        // edge over a real ward, and a leader row of their own. If the Contact
        // branch of identitiesFor() existed, both would grant standing.
        GroupMembership::create([
            'masjid_id' => $masjid->id,
            'group_id' => $group->id,
            'contact_id' => $ward->id,
            'role' => GroupMembership::ROLE_MEMBER,
        ]);
        GroupMembership::create([
            'masjid_id' => $masjid->id,
            'group_id' => $group->id,
            'contact_id' => $parent->id,
            'role' => GroupMembership::ROLE_GUARDIAN,
            'guardian_of_contact_id' => $ward->id,
        ]);

        app(TenantContext::class)->set($masjid->id);
        $audience = app(GroupAudience::class);

        $this->assertSame([], $audience->identitiesFor($parent));
        $this->assertFalse($audience->mayReceive($parent, $group, GroupMembership::CONSENT_FEED));
        $this->assertNull($audience->readableThreadsQuery($parent, $group));
        $this->assertNull($audience->readableAwardsQuery($parent, $group));

        // CONTROL. The email bridge itself still works, so the emptiness above
        // is specifically the missing Contact branch (T-015e) and not a broken
        // GroupAudience or an unbound tenant.
        $staff = User::factory()->create(['email' => $parent->email, 'phone' => '+15550001111']);
        $this->assertSame([$parent->id], $audience->identitiesFor($staff));
    }

    // ------------------------------------------------ middleware test harness

    /**
     * Authenticate $principal on the `family` guard and run
     * EnsureFamilyLoginActive against it directly.
     *
     * Direct invocation is not a shortcut: it is the only way to reach the
     * middleware with a principal the guard itself would have refused (a
     * trashed contact), and it is the exact shape of the real risk — a staff
     * session satisfies `auth:family`, so this middleware genuinely does see
     * non-Contact principals in production.
     */
    private function runFamilyActiveAs($principal)
    {
        Auth::forgetGuards();
        Auth::guard('family')->setUser($principal);
        Auth::shouldUse('family');

        return app(EnsureFamilyLoginActive::class)
            ->handle(Request::create('/api/family/masjids/1/me', 'GET'), fn () => response('ok'));
    }
}
