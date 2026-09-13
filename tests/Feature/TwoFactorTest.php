<?php

namespace Tests\Feature;

use App\Mail\TwoFactorResetMail;
use App\Models\TwoFactorResetEvent;
use App\Models\User;
use App\Services\TwoFactorService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Admin TOTP two-factor authentication — enrollment handshake + the NO-lockout
 * login integration.
 *
 * The load-bearing regression guarantee is login_without_2fa_is_unchanged(), now
 * stated three more ways in the "additivity tripwires" section below: a user who
 * never enrolled logs in EXACTLY as before — same request, same success envelope
 * with a token, no extra step, and not one 2FA column written on the way past.
 * 2FA only ever intercepts login for users who have CONFIRMED enrollment. There
 * is no partial version of getting this wrong: the failure mode is every
 * administrator on the platform locked out at once.
 *
 * The rest of the file covers what makes the feature survivable in the hands of
 * a real person — recovery codes that work once (including when two requests
 * read the same one at the same instant), a lock that expires and binds the
 * printed sheet as well as the app, a code that cannot be replayed, and an
 * abandoned enrolment that changes nothing.
 *
 * The last section pins the OPERATOR DOOR, which is the answer to the one
 * question the rest of the design could not answer: a confirmed enrolment whose
 * phone AND printed sheet are both gone was, until it existed, a permanent
 * lockout with no path back except an UPDATE typed into the production
 * database. Those tests are written as the four properties that make such a
 * door acceptable rather than as endpoint coverage — it needs the operator's
 * own live second factor, it can never be turned on its holder, it always
 * leaves a named human and a reason on a record nothing can edit, and it always
 * tells the person it was done to.
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

    /**
     * The enrolled-login path end to end THROUGH THE REAL SANCTUM GUARD.
     *
     * Every other test here authenticates with `Sanctum::actingAs()`, which sets
     * the user on the guard directly and never enters
     * `Laravel\Sanctum\Guard::__invoke()`. The `auth.guards.sanctum.provider`
     * pin added by T-015a is only consulted on that path, so no `actingAs` test
     * would notice if a real 2FA-issued token stopped being accepted. This one
     * would. See tests/Feature/StaffAuthGuardPinTest.php for the rest of the
     * real-token sweep.
     */
    #[Test]
    public function the_token_issued_after_a_2fa_login_authenticates_on_an_admin_route(): void
    {
        $secret = $this->google2fa()->generateSecretKey();
        $admin = $this->makeEnrolledAdmin($secret);

        $token = $this->postJson('/api/admin/login', [
            'email' => $admin->email,
            'password' => 'password',
            'two_factor_code' => $this->google2fa()->getCurrentOtp($secret),
        ])->assertOk()->json('data.token');

        $this->assertNotEmpty($token);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/user')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.id', $admin->id);
    }

    // ---------- the additivity tripwires ----------

    /**
     * THE regression this whole slice is measured against, stated three ways.
     *
     * An admin who has not enrolled must log in through exactly the path they
     * used yesterday: one request, no second field, no second round trip, and
     * NOTHING in the 2FA layer touching their row. If this fails, every
     * administrator on the platform is locked out at once — there is no partial
     * version of this failure.
     */
    #[Test]
    public function an_unenrolled_admin_login_is_untouched_by_the_two_factor_layer(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->postJson('/api/admin/login', [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertOk();

        // 1. Same success envelope, with a token, on the first request.
        $response->assertJsonPath('status', 'success');
        $this->assertNotEmpty($response->json('data.token'));
        $this->assertSame(1, $admin->tokens()->count());

        // 2. The challenge shape never appears for them.
        $this->assertNotSame('two_factor_required', $response->json('status'));

        // 3. No 2FA state was written on the way through — no failure counter,
        //    no lock, no burned code. The layer did not run at all.
        $admin->refresh();
        $this->assertNull($admin->two_factor_secret);
        $this->assertNull($admin->two_factor_confirmed_at);
        $this->assertNull($admin->two_factor_recovery_codes);
        $this->assertNull($admin->two_factor_last_code_hash);
        $this->assertNull($admin->two_factor_last_used_at);
        $this->assertNull($admin->two_factor_locked_until);
        $this->assertSame(0, (int) $admin->two_factor_failed_attempts);
    }

    /**
     * The lockout counter can NEVER refuse somebody who has not enrolled.
     *
     * The columns are seeded here with a live lock — a state nothing in the
     * application can actually produce for an unenrolled user, which is the
     * point: even if some future code path sets them, the gate is
     * `hasTwoFactorEnabled()` and this login must sail straight past it. This is
     * the test that fails if anyone ever moves the lock check above that `if`.
     */
    #[Test]
    public function an_unenrolled_admin_is_never_locked_out_by_the_two_factor_counter(): void
    {
        $admin = $this->makeAdmin();
        $admin->two_factor_failed_attempts = 99;
        $admin->two_factor_locked_until = now()->addHour();
        $admin->save();

        $this->postJson('/api/admin/login', [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertOk()->assertJsonPath('status', 'success');
    }

    /**
     * A wrong password for an unenrolled admin still answers exactly what it
     * always answered — the 2FA layer must not have crept in front of the
     * credential check either.
     */
    #[Test]
    public function a_wrong_password_still_answers_invalid_credentials(): void
    {
        $admin = $this->makeAdmin();

        $this->postJson('/api/admin/login', [
            'email' => $admin->email,
            'password' => 'not-the-password',
        ])->assertOk()->assertJsonPath('message', 'invalid credentials');
    }

    /**
     * The SPA contract: HTTP 200, `two_factor_required`, and NO token — and,
     * just as importantly, no token minted and thrown away either.
     *
     * "Skip the challenge by calling the next endpoint directly" has no target
     * in this design: AuthController::login is the ONLY place a staff token is
     * created, and the challenge returns before it. If a partial-login endpoint
     * is ever added, this assertion on the token table is what will notice.
     */
    #[Test]
    public function the_two_factor_challenge_still_answers_200_with_no_token(): void
    {
        $secret = $this->google2fa()->generateSecretKey();
        $admin = $this->makeEnrolledAdmin($secret);

        $response = $this->postJson('/api/admin/login', [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertStatus(200);

        $response->assertJsonPath('status', 'two_factor_required');
        $this->assertNull($response->json('data.token'));
        $this->assertNull($response->json('data'));
        $this->assertSame(0, $admin->tokens()->count(), 'a challenge must not mint anything');
    }

    #[Test]
    public function enrolling_mints_no_new_permission(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $secret = $this->google2fa()->generateSecretKey();
        $admin = $this->makeAdmin();
        $admin->two_factor_secret = $secret;
        $admin->save();

        Sanctum::actingAs($admin);
        $this->postJson('/api/admin/2fa/confirm', [
            'code' => $this->google2fa()->getCurrentOtp($secret),
        ])->assertOk();

        $this->assertSame(8, Permission::count());
    }

    // ---------- recovery codes ----------

    #[Test]
    public function confirming_enrollment_returns_recovery_codes_exactly_once(): void
    {
        $secret = $this->google2fa()->generateSecretKey();
        $admin = $this->makeAdmin();
        $admin->two_factor_secret = $secret;
        $admin->save();

        Sanctum::actingAs($admin);

        $codes = $this->postJson('/api/admin/2fa/confirm', [
            'code' => $this->google2fa()->getCurrentOtp($secret),
        ])->assertOk()->json('data.recovery_codes');

        $this->assertIsArray($codes);
        $this->assertCount(TwoFactorService::RECOVERY_CODE_COUNT, $codes);
        $this->assertSame($codes, array_unique($codes));
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^[A-Z0-9]{5}-[A-Z0-9]{5}$/', $code);
        }

        // Stored, and never handed back by a second confirm: the enrollment is
        // now live, so this endpoint refuses rather than quietly re-issuing.
        $this->assertSame($codes, $admin->fresh()->two_factor_recovery_codes);

        $this->postJson('/api/admin/2fa/confirm', [
            'code' => $this->google2fa()->getCurrentOtp($secret),
        ])->assertStatus(422);
    }

    /**
     * Renamed from a_recovery_code_signs_a_LOCKED_OUT_admin_in, which is not
     * what it did or what login() does: makeAdminWithRecoveryCodes() never
     * writes `two_factor_locked_until`, so the old name promised a guarantee no
     * assertion here touched — and promised the OPPOSITE of the real behaviour,
     * which the test below now pins.
     */
    #[Test]
    public function a_recovery_code_signs_an_enrolled_admin_in(): void
    {
        [$admin, $codes] = $this->makeAdminWithRecoveryCodes();

        $response = $this->postJson('/api/admin/login', [
            'email' => $admin->email,
            'password' => 'password',
            'two_factor_recovery_code' => $codes[0],
        ])->assertOk();

        $response->assertJsonPath('status', 'success');
        $this->assertNotEmpty($response->json('data.token'));
    }

    /**
     * THE LOCK BINDS THE RECOVERY SHEET TOO, and it has to.
     *
     * AuthController::login reads isLockedOut() BEFORE it reads
     * `two_factor_recovery_code`, so five wrong TOTP codes cost an admin their
     * printed sheet for fifteen minutes as well. That ordering is deliberate,
     * not an oversight, and this test is what stops somebody "fixing" it: a
     * recovery code that skipped the lock would make the eight-code space
     * guessable at whatever rate `throttle:login` allows — and that limiter is
     * keyed on email+IP and lives in the cache, which is precisely why the
     * row-level lock exists. The cost is bounded (the lock always expires, as
     * the second half of this test proves) and the benefit is that the only
     * credential a locked-out attacker could grind is not exposed.
     *
     * The refusal must also SPEND NOTHING: a 429 that burned the code would
     * quietly destroy one of eight lifelines per rate-limited attempt.
     */
    #[Test]
    public function the_second_factor_lock_binds_the_recovery_code_path_too(): void
    {
        [$admin, $codes] = $this->makeAdminWithRecoveryCodes();

        $admin->two_factor_locked_until = now()->addMinutes(10);
        $admin->save();

        $this->postJson('/api/admin/login', [
            'email' => $admin->email,
            'password' => 'password',
            'two_factor_recovery_code' => $codes[0],
        ])->assertStatus(429);

        $admin->refresh();
        $this->assertSame(0, $admin->tokens()->count(), 'a locked-out refusal must mint nothing');
        $this->assertContains($codes[0], $admin->two_factor_recovery_codes, 'a refused attempt must not spend the code');

        // ...and the lock lets go on its own, so this is a delay and never the
        // permanent lockout the whole design refuses to accept.
        $this->travel(16)->minutes();

        $this->postJson('/api/admin/login', [
            'email' => $admin->email,
            'password' => 'password',
            'two_factor_recovery_code' => $codes[0],
        ])->assertOk()->assertJsonPath('status', 'success');

        $this->travelBack();
    }

    /**
     * ONE RECOVERY CODE, ONE SIGN-IN — even when two requests read it at the
     * same instant.
     *
     * consumeRecoveryCode() was a read-modify-write on an unlocked row: load the
     * array, drop the matched entry, save. Two logins carrying the same printed
     * code that interleaved between the load and the save both found the code
     * present, both returned true, and login minted a token for each — one line
     * of paper, two live sessions — while the losing write put the OTHER
     * request's spent code back into the set.
     *
     * The interleave is reproduced exactly as a request pair produces it: two
     * User instances, each loaded BEFORE either spent anything, which is the
     * state two concurrent handlers are in. It is deterministic, and it fails
     * against the old implementation. (`lockForUpdate` compiles to nothing on
     * sqlite, so what this proves in CI is the re-read inside the transaction —
     * the half that makes the decision from the database rather than from a
     * stale copy. MySQL adds the wait on top.)
     */
    #[Test]
    public function one_recovery_code_cannot_be_spent_by_two_requests_that_raced(): void
    {
        [$admin, $codes] = $this->makeAdminWithRecoveryCodes();

        $service = app(TwoFactorService::class);

        $first = User::findOrFail($admin->id);
        $second = User::findOrFail($admin->id);

        $this->assertTrue($service->consumeRecoveryCode($first, $codes[0]));
        $this->assertFalse(
            $service->consumeRecoveryCode($second, $codes[0]),
            'the second request read the same code and must not be allowed to spend it too'
        );

        // Exactly one code gone, and it is that one.
        $remaining = $admin->fresh()->two_factor_recovery_codes;
        $this->assertCount(count($codes) - 1, $remaining);
        $this->assertNotContains($codes[0], $remaining);

        // The loser's own instance must not carry the pre-spend set either: it
        // is what disable() and confirm() save two lines later, and saving a
        // stale array is how a spent code comes back from the dead.
        $second->save();
        $this->assertNotContains($codes[0], $admin->fresh()->two_factor_recovery_codes);
    }

    #[Test]
    public function a_recovery_code_cannot_be_used_twice(): void
    {
        [$admin, $codes] = $this->makeAdminWithRecoveryCodes();

        $this->postJson('/api/admin/login', [
            'email' => $admin->email,
            'password' => 'password',
            'two_factor_recovery_code' => $codes[0],
        ])->assertOk()->assertJsonPath('status', 'success');

        $this->postJson('/api/admin/login', [
            'email' => $admin->email,
            'password' => 'password',
            'two_factor_recovery_code' => $codes[0],
        ])->assertStatus(422)->assertJsonPath('status', 'failed');

        $this->assertSame(1, $admin->fresh()->tokens()->count(), 'the replay must mint nothing');
    }

    #[Test]
    public function using_a_recovery_code_leaves_the_other_codes_usable(): void
    {
        [$admin, $codes] = $this->makeAdminWithRecoveryCodes();

        $this->postJson('/api/admin/login', [
            'email' => $admin->email,
            'password' => 'password',
            'two_factor_recovery_code' => $codes[0],
        ])->assertOk();

        $remaining = $admin->fresh()->two_factor_recovery_codes;
        $this->assertCount(count($codes) - 1, $remaining);
        $this->assertNotContains($codes[0], $remaining);

        $this->postJson('/api/admin/login', [
            'email' => $admin->email,
            'password' => 'password',
            'two_factor_recovery_code' => $codes[1],
        ])->assertOk()->assertJsonPath('status', 'success');
    }

    /**
     * People re-type these off paper. A correct code refused over a lower-case
     * letter or a missing hyphen is not a recovery path.
     */
    #[Test]
    public function a_recovery_code_is_accepted_however_it_is_typed(): void
    {
        [$admin, $codes] = $this->makeAdminWithRecoveryCodes();

        $this->postJson('/api/admin/login', [
            'email' => $admin->email,
            'password' => 'password',
            'two_factor_recovery_code' => ' ' . strtolower(str_replace('-', '', $codes[0])) . ' ',
        ])->assertOk()->assertJsonPath('status', 'success');
    }

    #[Test]
    public function regenerating_recovery_codes_requires_a_current_code_and_invalidates_the_old_ones(): void
    {
        $secret = $this->google2fa()->generateSecretKey();
        [$admin, $old] = $this->makeAdminWithRecoveryCodes($secret);

        Sanctum::actingAs($admin);

        // Without a valid code: refused, and the old set survives.
        $this->postJson('/api/admin/2fa/recovery-codes', [
            'code' => $this->wrongCodeFor($secret),
        ])->assertStatus(422);
        $this->assertSame($old, $admin->fresh()->two_factor_recovery_codes);

        $new = $this->postJson('/api/admin/2fa/recovery-codes', [
            'code' => $this->google2fa()->getCurrentOtp($secret),
        ])->assertOk()->json('data.recovery_codes');

        $this->assertCount(TwoFactorService::RECOVERY_CODE_COUNT, $new);
        $this->assertSame([], array_intersect($old, $new));
        $this->assertSame($new, $admin->fresh()->two_factor_recovery_codes);

        // The old sheet is paper now.
        $this->postJson('/api/admin/login', [
            'email' => $admin->email,
            'password' => 'password',
            'two_factor_recovery_code' => $old[0],
        ])->assertStatus(422);
    }

    #[Test]
    public function turning_two_factor_off_destroys_the_recovery_codes(): void
    {
        $secret = $this->google2fa()->generateSecretKey();
        [$admin, $codes] = $this->makeAdminWithRecoveryCodes($secret);

        Sanctum::actingAs($admin);

        $this->deleteJson('/api/admin/2fa', [
            'code' => $this->google2fa()->getCurrentOtp($secret),
        ])->assertOk();

        $admin->refresh();
        $this->assertNull($admin->two_factor_secret);
        $this->assertNull($admin->two_factor_confirmed_at);
        $this->assertNull($admin->two_factor_recovery_codes);

        // And the old codes are inert — 2FA is off, so login never consults them.
        $this->postJson('/api/admin/login', [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertOk()->assertJsonPath('status', 'success');
    }

    /**
     * The way back for somebody whose phone is gone: sign in with a printed
     * code, then spend another one to turn the second factor off and re-enrol on
     * a new device. Without this, a recovery-code login is a room with no door —
     * signed in, but permanently unable to satisfy or remove the factor.
     */
    #[Test]
    public function a_recovery_code_can_turn_two_factor_off_from_a_new_device(): void
    {
        [$admin, $codes] = $this->makeAdminWithRecoveryCodes();

        Sanctum::actingAs($admin);

        $this->deleteJson('/api/admin/2fa', ['code' => $codes[1]])->assertOk();

        $admin->refresh();
        $this->assertFalse($admin->hasTwoFactorEnabled());
        $this->assertNull($admin->two_factor_recovery_codes);
    }

    #[Test]
    public function recovery_codes_are_never_present_in_a_user_payload(): void
    {
        [$admin, $codes] = $this->makeAdminWithRecoveryCodes();

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/user')->assertOk();

        $data = $response->json('data');
        $this->assertArrayNotHasKey('two_factor_recovery_codes', $data);
        $this->assertArrayNotHasKey('two_factor_secret', $data);
        $this->assertArrayNotHasKey('two_factor_last_code_hash', $data);
        $this->assertStringNotContainsString($codes[0], $response->getContent());

        // ...nor in the login payload, which serialises the same model.
        $login = $this->postJson('/api/admin/login', [
            'email' => $admin->email,
            'password' => 'password',
            'two_factor_recovery_code' => $codes[0],
        ])->assertOk();

        $this->assertArrayNotHasKey('two_factor_recovery_codes', $login->json('data.user'));
        $this->assertStringNotContainsString($codes[1], $login->getContent());
    }

    /**
     * Read the column BELOW the cast. Nothing else in the suite notices if
     * someone drops `encrypted:array` from the model — the round trip keeps
     * working and the plaintext quietly lands in the database, in a table that
     * gets dumped for backups and support.
     */
    #[Test]
    public function the_recovery_codes_column_is_ciphertext_in_the_database(): void
    {
        [$admin, $codes] = $this->makeAdminWithRecoveryCodes();

        $raw = (string) DB::table('users')->where('id', $admin->id)->value('two_factor_recovery_codes');

        $this->assertNotEmpty($raw);
        foreach ($codes as $code) {
            $this->assertStringNotContainsString($code, $raw);
        }
    }

    // ---------- replay, brute force, and half-enrollment ----------

    /**
     * A TOTP code is valid for its whole time step plus a drift window on each
     * side — about 90 seconds in which the same six digits work again. A code
     * that has been spent is burned for that window, so one read over a shoulder
     * or pasted into a support chat cannot be used a second time.
     */
    #[Test]
    public function a_totp_code_cannot_be_replayed_within_its_window(): void
    {
        $secret = $this->google2fa()->generateSecretKey();
        $admin = $this->makeEnrolledAdmin($secret);
        $code = $this->google2fa()->getCurrentOtp($secret);

        $this->postJson('/api/admin/login', [
            'email' => $admin->email,
            'password' => 'password',
            'two_factor_code' => $code,
        ])->assertOk()->assertJsonPath('status', 'success');

        $this->postJson('/api/admin/login', [
            'email' => $admin->email,
            'password' => 'password',
            'two_factor_code' => $code,
        ])->assertStatus(422)->assertJsonPath('status', 'failed');
    }

    /**
     * Five wrong codes lock the SECOND FACTOR — a counter on the row, so
     * rotating IP addresses does not evade it and a cache flush does not re-arm
     * the attacker — and the lock LETS GO on its own. A permanent lock would be
     * a denial of service any stranger could trigger against an administrator.
     */
    #[Test]
    public function repeated_wrong_codes_lock_the_second_factor_and_the_lock_expires(): void
    {
        // The cache-based `throttle:login` limiter is stood down for this test
        // ON PURPOSE. It would answer 429 first and hide whether the row-level
        // counter works at all — and the row-level counter exists precisely
        // because the cache one can be flushed or side-stepped by changing IP.
        // Each layer has to be provable without the other.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        $secret = $this->google2fa()->generateSecretKey();
        $admin = $this->makeEnrolledAdmin($secret);

        for ($i = 0; $i < TwoFactorService::MAX_FAILED_ATTEMPTS; $i++) {
            $this->postJson('/api/admin/login', [
                'email' => $admin->email,
                'password' => 'password',
                'two_factor_code' => $this->wrongCodeFor($secret),
            ])->assertStatus(422);
        }

        $this->assertNotNull($admin->fresh()->two_factor_locked_until);

        // Even the RIGHT code is refused while the lock stands.
        $this->postJson('/api/admin/login', [
            'email' => $admin->email,
            'password' => 'password',
            'two_factor_code' => $this->google2fa()->getCurrentOtp($secret),
        ])->assertStatus(429);

        $this->travel(TwoFactorService::LOCKOUT_MINUTES + 1)->minutes();

        $this->postJson('/api/admin/login', [
            'email' => $admin->email,
            'password' => 'password',
            'two_factor_code' => $this->google2fa()->getCurrentOtp($secret),
        ])->assertOk()->assertJsonPath('status', 'success');

        $this->travelBack();
    }

    /**
     * An abandoned enrollment leaves the account exactly as it was.
     *
     * Close the browser between the QR and the first code and you hold a pending
     * secret with no confirmation stamp — which is inert. The login path reads
     * `two_factor_confirmed_at`, so this user is still an unenrolled user and
     * still signs in with one request. There is no state in which an account is
     * asked for a code it cannot produce.
     */
    #[Test]
    public function an_abandoned_enrollment_leaves_sign_in_unchanged(): void
    {
        $admin = $this->makeAdmin();

        Sanctum::actingAs($admin);
        $this->postJson('/api/admin/2fa/enroll')->assertOk();

        $admin->refresh();
        $this->assertNotNull($admin->two_factor_secret);
        $this->assertFalse($admin->hasTwoFactorEnabled());

        $this->postJson('/api/admin/login', [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertOk()->assertJsonPath('status', 'success');
    }

    /**
     * Enrolling over a LIVE enrollment used to switch the second factor off
     * without proving anything — enroll() nulled `two_factor_confirmed_at` while
     * disable() next door demanded a current code for the same outcome. A stolen
     * session could strip the factor with one POST.
     */
    #[Test]
    public function enrolling_again_cannot_strip_a_confirmed_second_factor(): void
    {
        $secret = $this->google2fa()->generateSecretKey();
        $admin = $this->makeEnrolledAdmin($secret);

        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/2fa/enroll')->assertStatus(422);

        $admin->refresh();
        $this->assertTrue($admin->hasTwoFactorEnabled());
        $this->assertSame($secret, $admin->two_factor_secret);
    }

    // ---------- the operator door: clearing a STRANDED second factor ----------

    /**
     * THE WAY BACK for an account that would otherwise be bricked forever.
     *
     * Lose the phone and burn the printed sheet and every self-service door is
     * shut: enroll() refuses while confirmed, recovery-codes and disable both
     * demand a code the person no longer has. This endpoint is the only thing
     * standing between that admin and a hand-typed UPDATE on the production
     * database, and the guarantee it has to keep is stated by all four
     * assertions below together: the factor is GONE, the account works again
     * with its password alone, the act is WRITTEN DOWN with a named human and
     * their reason, and the person it was done to is TOLD.
     */
    #[Test]
    public function a_super_admin_can_clear_a_stranded_second_factor(): void
    {
        Mail::fake();

        $operatorSecret = $this->google2fa()->generateSecretKey();
        $operator = $this->makeEnrolledAdmin($operatorSecret);

        [$subject] = $this->makeAdminWithRecoveryCodes();

        Sanctum::actingAs($operator);

        $this->postJson('/api/admin/2fa/reset/'.$subject->id, [
            'code' => $this->google2fa()->getCurrentOtp($operatorSecret),
            'subject_email' => $subject->email,
            'reason' => 'Phone lost on 12 Sep, recovery sheet was never printed. Verified by video call.',
        ])->assertOk()->assertJsonPath('status', 'success');

        // 1. The factor is gone — secret, stamp and the eight codes with it.
        $subject->refresh();
        $this->assertFalse($subject->hasTwoFactorEnabled());
        $this->assertNull($subject->two_factor_secret);
        $this->assertNull($subject->two_factor_recovery_codes);

        // 2. They can sign in again, with their password and nothing else.
        $this->postJson('/api/admin/login', [
            'email' => $subject->email,
            'password' => 'password',
        ])->assertOk()->assertJsonPath('status', 'success');

        // 3. It is written down, with a name on it and the reason they gave.
        $event = TwoFactorResetEvent::firstOrFail();
        $this->assertSame($subject->id, $event->user_id);
        $this->assertSame($subject->email, $event->user_email);
        $this->assertSame($operator->id, $event->performed_by_user_id);
        $this->assertStringContainsString($operator->email, $event->performed_by_label);
        $this->assertSame(TwoFactorResetEvent::CHANNEL_DASHBOARD, $event->channel);
        $this->assertStringContainsString('Phone lost on 12 Sep', $event->reason);

        // 4. The person it was done to hears about it. A door that can be
        //    opened silently is a different door.
        Mail::assertSent(TwoFactorResetMail::class, function (TwoFactorResetMail $mail) use ($subject) {
            return $mail->hasTo($subject->email);
        });
    }

    /**
     * The operator's OWN second factor is the price of admission.
     *
     * A SuperAdmin can already set any staff password
     * (UsersController::update), so their session is a skeleton key for the
     * first factor and the second factor is the only thing a stolen SuperAdmin
     * session cannot walk through. If this endpoint accepted that session alone
     * it would hand the whole platform to one phished password. A wrong code
     * therefore changes nothing at all — not the subject's factor, and not the
     * ledger, which must never record an act that did not happen.
     */
    #[Test]
    public function clearing_a_stranded_second_factor_needs_the_operators_own_live_code(): void
    {
        Mail::fake();

        $operatorSecret = $this->google2fa()->generateSecretKey();
        $operator = $this->makeEnrolledAdmin($operatorSecret);

        [$subject] = $this->makeAdminWithRecoveryCodes();

        Sanctum::actingAs($operator);

        $this->postJson('/api/admin/2fa/reset/'.$subject->id, [
            'code' => $this->wrongCodeFor($operatorSecret),
            'subject_email' => $subject->email,
            'reason' => 'Trying it on with a guessed code, which must get nowhere.',
        ])->assertStatus(422);

        $this->assertTrue($subject->fresh()->hasTwoFactorEnabled());
        $this->assertSame(0, TwoFactorResetEvent::count());
        Mail::assertNothingSent();
    }

    /**
     * An operator with no second factor of their own cannot open this door at
     * all — there is nothing for them to prove possession of, and "signed in as
     * a SuperAdmin" is exactly the thing that must not be sufficient here.
     */
    #[Test]
    public function an_operator_without_a_second_factor_of_their_own_cannot_clear_anybody_elses(): void
    {
        Mail::fake();

        $operator = $this->makeAdmin();
        [$subject] = $this->makeAdminWithRecoveryCodes();

        Sanctum::actingAs($operator);

        $this->postJson('/api/admin/2fa/reset/'.$subject->id, [
            'code' => '123456',
            'subject_email' => $subject->email,
            'reason' => 'No second factor of my own, so this must be refused.',
        ])->assertStatus(422);

        $this->assertTrue($subject->fresh()->hasTwoFactorEnabled());
        $this->assertSame(0, TwoFactorResetEvent::count());
    }

    /**
     * Never on yourself.
     *
     * An operator who can pass their own live code is not stranded — disable()
     * is their door. Allowing self-service here would add the one shape the
     * design refuses: a session that can strip its own second factor.
     */
    #[Test]
    public function the_operator_door_refuses_to_act_on_the_operators_own_account(): void
    {
        $secret = $this->google2fa()->generateSecretKey();
        $operator = $this->makeEnrolledAdmin($secret);

        Sanctum::actingAs($operator);

        $this->postJson('/api/admin/2fa/reset/'.$operator->id, [
            'code' => $this->google2fa()->getCurrentOtp($secret),
            'subject_email' => $operator->email,
            'reason' => 'Trying to clear my own factor through the operator door.',
        ])->assertStatus(422);

        $this->assertTrue($operator->fresh()->hasTwoFactorEnabled());
        $this->assertSame(0, TwoFactorResetEvent::count());
    }

    /**
     * Typing the address is what stops the wrong account being cleared.
     *
     * An id comes off a list and a mis-click is silent until the admin next to
     * the one you meant cannot sign in. The refusal must also NOT spend the
     * operator's code — otherwise correcting the mistake costs a two-minute
     * wait for the replay window, which is how people learn to keep a spare
     * tab open instead.
     */
    #[Test]
    public function clearing_the_wrong_account_is_caught_by_typing_the_address(): void
    {
        Mail::fake();

        $operatorSecret = $this->google2fa()->generateSecretKey();
        $operator = $this->makeEnrolledAdmin($operatorSecret);

        [$subject] = $this->makeAdminWithRecoveryCodes();
        $code = $this->google2fa()->getCurrentOtp($operatorSecret);

        Sanctum::actingAs($operator);

        $this->postJson('/api/admin/2fa/reset/'.$subject->id, [
            'code' => $code,
            'subject_email' => 'somebody.else@example.com',
            'reason' => 'The address typed does not belong to the row that was selected.',
        ])->assertStatus(422);

        $this->assertTrue($subject->fresh()->hasTwoFactorEnabled());
        $this->assertSame(0, TwoFactorResetEvent::count());

        // The same code still works once the address is right.
        $this->postJson('/api/admin/2fa/reset/'.$subject->id, [
            'code' => $code,
            'subject_email' => $subject->email,
            'reason' => 'Same code, correct address — the refusal above cost nothing.',
        ])->assertOk();
    }

    /**
     * The door is SUPER-ONLY. "Any admin can clear any other admin's second
     * factor" would make the weakest admin account on the platform the real
     * second factor for every other one.
     */
    #[Test]
    public function an_ordinary_admin_cannot_clear_another_admins_second_factor(): void
    {
        Mail::fake();

        $intruder = $this->makeAdmin(['type' => 'MasjidAdmin']);
        [$subject] = $this->makeAdminWithRecoveryCodes();

        Sanctum::actingAs($intruder);

        $response = $this->postJson('/api/admin/2fa/reset/'.$subject->id, [
            'code' => '123456',
            'subject_email' => $subject->email,
            'reason' => 'A MasjidAdmin reaching for somebody else\'s second factor.',
        ]);

        // 401 from the `super` middleware, or 403 from the tenant resolver
        // refusing a MasjidAdmin who belongs to no organisation — both are
        // correct refusals from layers in front of the controller, and the
        // controller re-checks the role itself behind them. What must NEVER
        // vary is the outcome.
        $this->assertTrue(
            in_array($response->status(), [401, 403], true),
            'a non-super admin must be refused, got HTTP '.$response->status()
        );
        $this->assertTrue($subject->fresh()->hasTwoFactorEnabled());
        $this->assertSame(0, TwoFactorResetEvent::count());
        Mail::assertNothingSent();
    }

    /**
     * The ledger is the whole justification for the door, so it must not be a
     * table the application can quietly tidy up afterwards.
     */
    #[Test]
    public function a_recorded_reset_cannot_be_edited_or_deleted(): void
    {
        $event = TwoFactorResetEvent::create([
            'user_id' => null,
            'user_email' => 'stranded@example.com',
            'performed_by_user_id' => null,
            'performed_by_label' => 'A Named Human <ops@example.com>',
            'channel' => TwoFactorResetEvent::CHANNEL_CONSOLE,
            'reason' => 'Recorded so that it can be read back months later.',
        ]);

        $event->reason = 'Something more flattering';

        try {
            $event->save();
            $this->fail('a reset record must not be editable');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        try {
            TwoFactorResetEvent::findOrFail($event->id)->delete();
            $this->fail('a reset record must not be deletable');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        $this->assertSame(
            'Recorded so that it can be read back months later.',
            TwoFactorResetEvent::findOrFail($event->id)->reason
        );
    }

    /** An enrolled admin with a known secret AND a live set of recovery codes. */
    private function makeAdminWithRecoveryCodes(?string $secret = null): array
    {
        $admin = $this->makeEnrolledAdmin($secret ?? $this->google2fa()->generateSecretKey());
        $codes = app(TwoFactorService::class)->generateRecoveryCodes();

        $admin->two_factor_recovery_codes = $codes;
        $admin->save();

        return [$admin, $codes];
    }

    /** A 6-digit code guaranteed to differ from the current valid one. */
    private function wrongCodeFor(string $secret): string
    {
        $valid = $this->google2fa()->getCurrentOtp($secret);
        $wrong = str_pad((string) (((int) $valid + 1) % 1000000), 6, '0', STR_PAD_LEFT);

        return $wrong;
    }
}
