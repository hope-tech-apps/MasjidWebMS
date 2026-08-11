<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminDashboard\AuthController;
use App\Http\Middleware\SuperAdminMiddleware;
use App\Http\Middleware\UserAdminMiddleware;
use App\Models\Contact;
use App\Models\Masjid;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Guard as SpatieGuard;
use Spatie\Permission\Models\Permission;
use Tests\Support\ForeignTokenable;
use Tests\TestCase;

/**
 * T-015a — the sanctum guard's provider pin, the two admin middleware, and the
 * staff regression sweep that proves none of it changed how staff log in.
 *
 * WHY THIS SUITE EXISTS SEPARATELY FROM THE OTHER AUTH SUITES: every other
 * suite authenticates with `Sanctum::actingAs()`, which calls
 * `$auth->guard('sanctum')->setUser($user)` directly and therefore NEVER enters
 * `Laravel\Sanctum\Guard::__invoke()`. The provider check being pinned here
 * lives inside that method, so `actingAs` cannot exercise it in either
 * direction — it can neither prove the pin works nor catch it breaking real
 * logins. Everything below goes through a REAL bearer token minted by the real
 * login endpoint, which is the only path a production client ever takes.
 *
 * The four things proven, in order:
 *   1. The pin is present and a foreign tokenable is rejected inside vendor code.
 *   2. The pin is load-bearing — unpinned, the same token gets past auth:sanctum.
 *   3. UserAdminMiddleware / SuperAdminMiddleware reject a non-User principal
 *      even when the guard would have let it through (the independent layer,
 *      which matters for guards other than sanctum).
 *   4. Real staff tokens behave exactly as before: SuperAdmin, MasjidAdmin,
 *      tenant binding, the foreign-masjid 403, the super-only 401, the
 *      permission-gated CRM endpoint, and the 8 seeded permissions.
 *
 * See docs/t015-parent-identity-design.md §5 and invariant 10.
 */
class StaffAuthGuardPinTest extends TestCase
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

        // The CRM endpoints in the sweep are permission-gated; the bridged
        // masjid-admin role must carry the seeded permissions.
        $this->seed(RolesAndPermissionsSeeder::class);

        ForeignTokenable::createTable();
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
     * Log in over HTTP and return the plaintext bearer token — the real mint
     * path, not a fabricated token.
     */
    private function loginAndGetToken(User $user): string
    {
        $response = $this->postJson('/api/admin/login', [
            'email' => $user->email,
            'password' => 'password', // UserFactory default
        ])->assertOk();

        $token = $response->json('data.token');
        $this->assertNotEmpty($token, 'login must return a bearer token');

        return $token;
    }

    /** A second tokenable claiming an admin `type` — the exact hazard. */
    private function makeForeignTokenable(string $type = 'SuperAdmin'): ForeignTokenable
    {
        return ForeignTokenable::create([
            'name' => 'Not A Staff User',
            'email' => 'foreign-' . uniqid() . '@test.local',
            'type' => $type,
        ]);
    }

    /** A request carrying $token as its bearer credential. */
    private function bearerRequest(string $uri, string $token): Request
    {
        $request = Request::create($uri, 'GET');
        $request->headers->set('Authorization', 'Bearer ' . $token);

        return $request;
    }

    // ------------------------------------------------- 1. the pin is in place

    #[Test]
    public function the_sanctum_guard_is_pinned_to_the_users_provider(): void
    {
        // Read the LIVE guard config, i.e. after SanctumServiceProvider::register()
        // has merged its defaults over config/auth.php. Absent our entry it would
        // merge `provider => null`, which makes Guard::hasValidProvider() a no-op.
        $this->assertSame('users', config('auth.guards.sanctum.provider'));
        $this->assertSame(User::class, config('auth.providers.users.model'));
    }

    #[Test]
    public function the_permission_guard_stays_web_even_after_should_use_rewrites_the_default(): void
    {
        // This is the regression the guard pin actually caused, and the reason
        // App\Models\User now declares $guard_name.
        //
        // `AuthManager::shouldUse()` REWRITES config('auth.defaults.guard') in
        // the config repository, and both `auth:sanctum` and
        // `Sanctum::actingAs()` call it — so inside any authenticated admin
        // request the "default guard" genuinely is 'sanctum'.
        Auth::shouldUse('sanctum');
        $this->assertSame('sanctum', config('auth.defaults.guard'));

        // Spatie's Guard::getDefaultName() prefers that default whenever it also
        // appears among the auth.guards entries whose provider model is User.
        // Declaring auth.guards.sanctum made 'sanctum' matchable for the first
        // time; without the model-level pin, permission lookups would go looking
        // for a `view contacts` permission under guard `sanctum`, find none, and
        // 403 every permission:-gated CRM route.
        $this->assertSame(['web'], SpatieGuard::getNames(User::class)->all());
        $this->assertSame('web', SpatieGuard::getDefaultName(User::class));

        $this->assertTrue($this->makeUser('MasjidAdmin')->fresh()->hasPermissionTo('view contacts'));
    }

    #[Test]
    public function a_non_user_tokenable_does_not_authenticate_on_an_admin_route(): void
    {
        $foreign = $this->makeForeignTokenable('SuperAdmin');
        $token = $foreign->createToken('foreign-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/admin/logout')
            ->assertStatus(401);

        // WHICH 401 this is matters. This envelope is the one the app's
        // AuthenticationException renderer emits (bootstrap/app.php) — meaning
        // auth:sanctum itself refused, inside vendor code, before a single line
        // of application middleware ran. UserAdminMiddleware answers with a
        // different envelope entirely (asserted below), so the two layers are
        // distinguishable and this assertion cannot be satisfied by the wrong one.
        $response->assertJsonPath('status', 'error');
        $response->assertJsonPath('message', 'Unauthenticated.');

        // And the endpoint's side effect did not happen.
        $this->assertSame(1, $foreign->tokens()->count());
    }

    #[Test]
    public function the_guard_resolves_a_non_user_tokenable_to_null(): void
    {
        $foreign = $this->makeForeignTokenable();
        $token = $foreign->createToken('foreign-token')->plainTextToken;

        $resolved = Auth::guard('sanctum')
            ->setRequest($this->bearerRequest('/api/admin/user', $token))
            ->user();

        $this->assertNull($resolved, 'a foreign tokenable must resolve to null on the sanctum guard');
    }

    // -------------------------------------------- 2. the pin is load-bearing

    #[Test]
    public function without_the_pin_the_same_token_is_admitted_by_the_guard(): void
    {
        // The control. Sanctum reads the provider out of config when the guard
        // is resolved, so putting the vulnerable value back and dropping the
        // resolved guards reproduces the pre-T-015a application exactly.
        config(['auth.guards.sanctum.provider' => null]);
        Auth::forgetGuards();

        $foreign = $this->makeForeignTokenable();
        $token = $foreign->createToken('foreign-token')->plainTextToken;

        $resolved = Auth::guard('sanctum')
            ->setRequest($this->bearerRequest('/api/admin/user', $token))
            ->user();

        $this->assertInstanceOf(
            ForeignTokenable::class,
            $resolved,
            'unpinned, Guard::hasValidProvider() short-circuits to true and ANY '
            . 'HasApiTokens model authenticates — this is the latent vulnerability '
            . 'the pin closes. If this assertion ever fails because the tokenable '
            . 'is rejected anyway, the pin is no longer what is protecting the app.'
        );
    }

    #[Test]
    public function without_the_pin_only_the_admin_middleware_stands_between_a_foreign_token_and_the_admin_api(): void
    {
        config(['auth.guards.sanctum.provider' => null]);
        Auth::forgetGuards();

        $foreign = $this->makeForeignTokenable('SuperAdmin');
        $token = $foreign->createToken('foreign-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/admin/logout')
            ->assertStatus(401);

        // This is UserAdminMiddleware's envelope, NOT the AuthenticationException
        // one. Reaching it at all proves auth:sanctum admitted a non-User
        // principal; being stopped there proves the `instanceof User` layer holds
        // on its own. Before T-015a this request succeeded: the guard admitted
        // the token and the non-strict `type` check saw 'SuperAdmin'.
        $response->assertJsonPath('status', 'failed');
        $response->assertJsonPath('data', 'Unauthorized.');
    }

    // ------------------------------------- 3. the middleware layer, in isolation

    #[Test]
    public function user_admin_middleware_rejects_a_non_user_principal_claiming_an_admin_type(): void
    {
        foreach (['SuperAdmin', 'MasjidAdmin'] as $type) {
            $response = $this->runAdminMiddlewareAs($this->makeForeignTokenable($type));

            $this->assertSame(401, $response->getStatusCode(), "type {$type} must not pass");
            $this->assertSame(
                ['status' => 'failed', 'data' => 'Unauthorized.'],
                $response->getData(true)
            );
        }
    }

    #[Test]
    public function user_admin_middleware_still_admits_both_staff_admin_types(): void
    {
        foreach (['SuperAdmin', 'MasjidAdmin'] as $type) {
            $response = $this->runAdminMiddlewareAs($this->makeUser($type));

            $this->assertSame(200, $response->getStatusCode(), "a {$type} must still pass");
            $this->assertSame('ok', $response->getContent());
        }
    }

    #[Test]
    public function user_admin_middleware_still_rejects_a_plain_member_and_a_guest(): void
    {
        $member = $this->runAdminMiddlewareAs($this->makeUser('User'));
        $this->assertSame(401, $member->getStatusCode());
        $this->assertSame(['status' => 'failed', 'data' => 'Unauthorized.'], $member->getData(true));

        $guest = app(UserAdminMiddleware::class)
            ->handle(Request::create('/api/admin/user', 'GET'), fn () => response('ok'));
        $this->assertSame(401, $guest->getStatusCode());
        $this->assertSame(['status' => 'failed', 'data' => 'Unauthorized.'], $guest->getData(true));
    }

    #[Test]
    public function super_admin_middleware_rejects_a_non_user_principal_claiming_super_admin(): void
    {
        $response = $this->runSuperMiddlewareAs($this->makeForeignTokenable('SuperAdmin'));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(['status' => 'failed', 'data' => 'Unauthorized.'], $response->getData(true));
    }

    #[Test]
    public function super_admin_middleware_still_admits_a_super_admin_and_rejects_a_masjid_admin(): void
    {
        $super = $this->runSuperMiddlewareAs($this->makeUser('SuperAdmin'));
        $this->assertSame(200, $super->getStatusCode());
        $this->assertSame('ok', $super->getContent());

        $masjidAdmin = $this->runSuperMiddlewareAs($this->makeUser('MasjidAdmin'));
        $this->assertSame(401, $masjidAdmin->getStatusCode());
        $this->assertSame(['status' => 'failed', 'data' => 'Unauthorized.'], $masjidAdmin->getData(true));
    }

    // ------------------------------------------- 4. the staff regression sweep

    #[Test]
    public function a_real_login_token_authenticates_a_super_admin_on_an_admin_route(): void
    {
        $super = $this->makeUser('SuperAdmin');
        $token = $this->loginAndGetToken($super);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/user')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.id', $super->id);
    }

    #[Test]
    public function a_real_login_token_lets_a_masjid_admin_read_its_own_masjids_crm(): void
    {
        $masjid = $this->makeMasjid();
        $admin = $this->makeAdminFor($masjid);
        Contact::factory()->count(3)->create(['masjid_id' => $masjid->id]);

        $token = $this->loginAndGetToken($admin);

        // auth:sanctum (real token) -> admin -> tenant -> crm -> permission.
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson("/api/admin/masjids/{$masjid->id}/contacts")
            ->assertOk();
    }

    #[Test]
    public function a_real_masjid_admin_token_is_403_on_another_masjid(): void
    {
        $own = $this->makeMasjid();
        $foreign = $this->makeMasjid();
        $admin = $this->makeAdminFor($own);

        $token = $this->loginAndGetToken($admin);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson("/api/admin/masjids/{$foreign->id}/contacts")
            ->assertStatus(403);
    }

    #[Test]
    public function a_real_masjid_admin_token_is_401_on_a_super_only_route(): void
    {
        $masjid = $this->makeMasjid();
        $admin = $this->makeAdminFor($masjid);

        $token = $this->loginAndGetToken($admin);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/users')
            ->assertStatus(401)
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('data', 'Unauthorized.');
    }

    #[Test]
    public function a_plain_member_cannot_log_in_to_the_admin_api(): void
    {
        $member = $this->makeUser('User');
        $token = $this->loginAndGetToken($member);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/user')
            ->assertStatus(401)
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('data', 'Unauthorized.');
    }

    #[Test]
    public function login_mints_a_staff_scoped_token_instead_of_a_wildcard(): void
    {
        $super = $this->makeUser('SuperAdmin');
        $this->loginAndGetToken($super);

        $abilities = $super->tokens()->latest('id')->first()->abilities;

        $this->assertSame(AuthController::STAFF_TOKEN_ABILITIES, $abilities);
        $this->assertNotContains('*', $abilities, 'a wildcard staff token would satisfy any future realm check');
    }

    #[Test]
    public function the_seeded_permission_set_is_unchanged_by_the_guard_pin(): void
    {
        // Invariant 10. The pin touches config/auth.php, which is where Spatie
        // resolves guard names from; a mistake there silently detaches every
        // seeded permission from the model that holds it.
        $this->assertSame(8, Permission::count());
        $this->assertTrue($this->makeUser('MasjidAdmin')->fresh()->hasPermissionTo('view contacts'));
    }

    // ------------------------------------------------ middleware test harness

    /**
     * Authenticate $principal on the sanctum guard and run UserAdminMiddleware
     * against it directly.
     *
     * Direct invocation is not a shortcut here — it is the only way to reach the
     * middleware with a non-User principal now that the provider pin stops one
     * at the guard. It is also the shape of the real future risk: T-015c adds a
     * `family` guard, and `auth:family` rebinds the default guard that
     * `Auth::user()` reads, so this middleware WILL see non-User principals
     * without sanctum being involved at all.
     */
    private function runAdminMiddlewareAs($principal)
    {
        $this->authenticateAs($principal);

        return app(UserAdminMiddleware::class)
            ->handle(Request::create('/api/admin/user', 'GET'), fn () => response('ok'));
    }

    private function runSuperMiddlewareAs($principal)
    {
        $this->authenticateAs($principal);

        return app(SuperAdminMiddleware::class)
            ->handle(Request::create('/api/admin/users', 'GET'), fn () => response('ok'));
    }

    private function authenticateAs($principal): void
    {
        Auth::forgetGuards();
        Auth::guard('sanctum')->setUser($principal);
        Auth::shouldUse('sanctum');
    }
}
