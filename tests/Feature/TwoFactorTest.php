<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Admin TOTP two-factor authentication — enrollment handshake + the NO-lockout
 * login integration.
 *
 * The load-bearing regression guarantee is login_without_2fa_is_unchanged(): a
 * user who never enrolled logs in EXACTLY as before — same request, same success
 * envelope with a token, no extra step. 2FA only ever intercepts login for users
 * who have CONFIRMED enrollment.
 *
 * Sqlite-in-memory is forced in setUp (mirrors the other Feature suites). Roles
 * are NOT seeded here: 2FA and login are not permission-gated, and the user
 * observer's role bridge is defensive (a no-op when roles aren't seeded).
 */
class TwoFactorTest extends TestCase
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
    }

    private function google2fa(): Google2FA
    {
        return new Google2FA();
    }

    /** A plain admin (SuperAdmin needs no related masjid for these flows). */
    private function makeAdmin(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'type' => 'SuperAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ], $overrides));
    }

    /** An admin with CONFIRMED 2FA and a known secret. */
    private function makeEnrolledAdmin(string $secret): User
    {
        $admin = $this->makeAdmin();
        $admin->two_factor_secret = $secret;          // encrypted cast at rest
        $admin->two_factor_confirmed_at = now();
        $admin->save();

        return $admin;
    }

    // ---------- enrollment ----------

    #[Test]
    public function enroll_returns_a_secret_and_qr_without_enabling_2fa(): void
    {
        $admin = $this->makeAdmin();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/2fa/enroll')->assertOk();

        $this->assertNotEmpty($response->json('data.secret'));
        $this->assertStringStartsWith('otpauth://', $response->json('data.otpauth_uri'));
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $response->json('data.qr_code'));

        // Secret persisted, but 2FA is NOT active until confirm().
        $admin->refresh();
        $this->assertNotNull($admin->two_factor_secret);
        $this->assertNull($admin->two_factor_confirmed_at);
    }

    #[Test]
    public function confirm_with_a_valid_code_enables_2fa(): void
    {
        $secret = $this->google2fa()->generateSecretKey();

        $admin = $this->makeAdmin();
        $admin->two_factor_secret = $secret;
        $admin->save();

        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/2fa/confirm', [
            'code' => $this->google2fa()->getCurrentOtp($secret),
        ])->assertOk();

        $this->assertNotNull($admin->fresh()->two_factor_confirmed_at);
    }

    #[Test]
    public function confirm_with_a_bad_code_is_rejected_and_leaves_2fa_disabled(): void
    {
        $secret = $this->google2fa()->generateSecretKey();

        $admin = $this->makeAdmin();
        $admin->two_factor_secret = $secret;
        $admin->save();

        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/2fa/confirm', [
            'code' => $this->wrongCodeFor($secret),
        ])->assertStatus(422);

        $this->assertNull($admin->fresh()->two_factor_confirmed_at);
    }

    #[Test]
    public function disable_with_a_valid_code_turns_2fa_off(): void
    {
        $secret = $this->google2fa()->generateSecretKey();
        $admin = $this->makeEnrolledAdmin($secret);

        Sanctum::actingAs($admin);

        $this->deleteJson('/api/admin/2fa', [
            'code' => $this->google2fa()->getCurrentOtp($secret),
        ])->assertOk();

        $admin->refresh();
        $this->assertNull($admin->two_factor_secret);
        $this->assertNull($admin->two_factor_confirmed_at);
    }

    #[Test]
    public function disable_with_a_bad_code_is_rejected(): void
    {
        $secret = $this->google2fa()->generateSecretKey();
        $admin = $this->makeEnrolledAdmin($secret);

        Sanctum::actingAs($admin);

        $this->deleteJson('/api/admin/2fa', [
            'code' => $this->wrongCodeFor($secret),
        ])->assertStatus(422);

        $this->assertTrue($admin->fresh()->hasTwoFactorEnabled());
    }

    // ---------- login integration ----------

    /**
     * THE no-regression test: an admin who never enrolled logs in unchanged —
     * one request, success envelope with a token, no 2FA step.
     */
    #[Test]
    public function login_without_2fa_is_unchanged(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->postJson('/api/admin/login', [
            'email' => $admin->email,
            'password' => 'password', // UserFactory default
        ])->assertOk();

        $response->assertJsonPath('status', 'success');
        $this->assertNotEmpty($response->json('data.token'));
    }

    #[Test]
    public function enrolled_user_login_without_a_code_is_challenged_and_gets_no_token(): void
    {
        $secret = $this->google2fa()->generateSecretKey();
        $admin = $this->makeEnrolledAdmin($secret);

        $response = $this->postJson('/api/admin/login', [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertOk();

        $response->assertJsonPath('status', 'two_factor_required');
        $this->assertNull($response->json('data.token'));
    }

    #[Test]
    public function enrolled_user_login_with_a_wrong_code_is_denied(): void
    {
        $secret = $this->google2fa()->generateSecretKey();
        $admin = $this->makeEnrolledAdmin($secret);

        $response = $this->postJson('/api/admin/login', [
            'email' => $admin->email,
            'password' => 'password',
            'two_factor_code' => $this->wrongCodeFor($secret),
        ])->assertStatus(422);

        $response->assertJsonPath('status', 'failed');
        $this->assertNull($response->json('data.token'));
    }

    #[Test]
    public function enrolled_user_login_with_the_correct_code_succeeds(): void
    {
        $secret = $this->google2fa()->generateSecretKey();
        $admin = $this->makeEnrolledAdmin($secret);

        $response = $this->postJson('/api/admin/login', [
            'email' => $admin->email,
            'password' => 'password',
            'two_factor_code' => $this->google2fa()->getCurrentOtp($secret),
        ])->assertOk();

        $response->assertJsonPath('status', 'success');
        $this->assertNotEmpty($response->json('data.token'));
    }

    /** A 6-digit code guaranteed to differ from the current valid one. */
    private function wrongCodeFor(string $secret): string
    {
        $valid = $this->google2fa()->getCurrentOtp($secret);
        $wrong = str_pad((string) (((int) $valid + 1) % 1000000), 6, '0', STR_PAD_LEFT);

        return $wrong;
    }
}
