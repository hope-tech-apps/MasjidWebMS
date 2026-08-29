<?php

namespace Tests\Feature;

use App\Mail\AccountAccessMail;
use App\Models\Masjid;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Nobody at Manara should know an organisation's password.
 *
 * Before this, staff accounts were created by a SuperAdmin who TYPED a password,
 * and there was no recovery of any kind — no forgot-password route, no reset
 * route, and not even the `password_reset_tokens` table that config/auth.php has
 * always pointed the broker at. That is survivable while the only admins are the
 * people building the platform. It stops being survivable when a school runs its
 * own portal: somebody would have to invent Al-Razi's credential, transmit it out
 * of band, and hope nobody loses it.
 *
 * The claims pinned here are the security-relevant ones, not the happy path:
 * the endpoint does not leak WHO has an account, a token is single-use, and a
 * reset kills the sessions that the old password opened.
 */
class AccountAccessTest extends TestCase
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

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        Mail::fake();
    }

    // ------------------------------- forgot -------------------------------

    #[Test]
    public function a_real_address_is_sent_a_reset_link(): void
    {
        $user = $this->makeUser(['email' => 'imam@example.test']);

        $this->postJson('/api/admin/forgot-password', ['email' => 'imam@example.test'])
            ->assertStatus(200);

        Mail::assertSent(AccountAccessMail::class, function (AccountAccessMail $mail) use ($user) {
            return $mail->user->is($user)
                && $mail->mode === AccountAccessMail::MODE_RESET
                && str_contains($mail->url, 'token=');
        });
    }

    #[Test]
    public function an_unknown_address_gets_the_same_answer_and_no_email(): void
    {
        // The whole point: this endpoint is unauthenticated, so an answer that
        // distinguished "sent" from "no such user" would be a free list of who
        // has an account.
        $known = $this->postJson('/api/admin/forgot-password', ['email' => 'nobody@example.test']);

        $this->makeUser(['email' => 'real@example.test']);
        $real = $this->postJson('/api/admin/forgot-password', ['email' => 'real@example.test']);

        $known->assertStatus(200);
        $real->assertStatus(200);
        $this->assertSame($real->json('message'), $known->json('message'),
            'the response distinguishes a real account from an unknown one');

        Mail::assertSent(AccountAccessMail::class, 1);
    }

    // ------------------------------- reset --------------------------------

    #[Test]
    public function a_valid_token_sets_the_password_and_the_new_one_works(): void
    {
        $user = $this->makeUser(['email' => 'admin@school.test']);
        $token = Password::broker()->createToken($user);

        $this->postJson('/api/admin/reset-password', [
            'token' => $token,
            'email' => 'admin@school.test',
            'password' => 'Sunflower2026',
            'password_confirmation' => 'Sunflower2026',
        ])->assertStatus(200);

        $this->assertTrue(Hash::check('Sunflower2026', $user->fresh()->password));

        $this->postJson('/api/admin/login', [
            'email' => 'admin@school.test',
            'password' => 'Sunflower2026',
        ])->assertStatus(200);
    }

    #[Test]
    public function a_token_cannot_be_used_twice(): void
    {
        $user = $this->makeUser(['email' => 'admin@school.test']);
        $token = Password::broker()->createToken($user);

        $body = [
            'token' => $token,
            'email' => 'admin@school.test',
            'password' => 'Sunflower2026',
            'password_confirmation' => 'Sunflower2026',
        ];

        $this->postJson('/api/admin/reset-password', $body)->assertStatus(200);

        // A link forwarded, screenshotted or sitting in a shared inbox must be
        // spent once and then be worthless.
        $this->postJson('/api/admin/reset-password', array_merge($body, [
            'password' => 'Different2026',
            'password_confirmation' => 'Different2026',
        ]))->assertStatus(422);

        $this->assertTrue(Hash::check('Sunflower2026', $user->fresh()->password));
    }

    #[Test]
    public function a_forged_token_is_refused(): void
    {
        $this->makeUser(['email' => 'admin@school.test']);

        $this->postJson('/api/admin/reset-password', [
            'token' => 'not-a-real-token',
            'email' => 'admin@school.test',
            'password' => 'Sunflower2026',
            'password_confirmation' => 'Sunflower2026',
        ])->assertStatus(422);
    }

    #[Test]
    public function a_weak_password_is_refused(): void
    {
        $user = $this->makeUser(['email' => 'admin@school.test']);
        $token = Password::broker()->createToken($user);

        // This credential opens minors' records.
        $this->postJson('/api/admin/reset-password', [
            'token' => $token,
            'email' => 'admin@school.test',
            'password' => 'short1',
            'password_confirmation' => 'short1',
        ])->assertStatus(422)
            // BaseFormRequest's envelope is {status: 'failed', data: {...}},
            // not Laravel's default {errors: {...}}.
            ->assertJsonPath('status', 'failed')
            ->assertJsonStructure(['data' => ['password']]);
    }

    #[Test]
    public function resetting_revokes_the_sessions_the_old_password_opened(): void
    {
        $user = $this->makeUser(['email' => 'admin@school.test']);
        $user->createToken('old-session');

        $this->assertSame(1, $user->tokens()->count());

        $this->postJson('/api/admin/reset-password', [
            'token' => Password::broker()->createToken($user),
            'email' => 'admin@school.test',
            'password' => 'Sunflower2026',
            'password_confirmation' => 'Sunflower2026',
        ])->assertStatus(200);

        $this->assertSame(0, $user->fresh()->tokens()->count(),
            'a reset left the old password’s sessions alive');
    }

    // ------------------------------- invite -------------------------------

    #[Test]
    public function an_invited_account_is_created_without_anyone_choosing_its_password(): void
    {
        Sanctum::actingAs($this->makeUser(['type' => 'SuperAdmin']));

        $response = $this->postJson('/api/admin/users/invite', [
            'name' => 'Al-Razi Office',
            'email' => 'office@alrazischool.test',
            'phone' => '+19199989078',
            'type' => 'MasjidAdmin',
        ])->assertStatus(201);

        $created = User::where('email', 'office@alrazischool.test')->firstOrFail();

        // A random secret exists so the row is never password-less, and it is
        // discarded unread — the response must never carry it.
        $this->assertNotNull($created->password);
        $this->assertStringNotContainsString('password', strtolower(json_encode($response->json('data'))));

        Mail::assertSent(AccountAccessMail::class, fn (AccountAccessMail $m) => $m->user->is($created)
            && $m->mode === AccountAccessMail::MODE_INVITE);
    }

    #[Test]
    public function an_invite_can_make_the_new_user_the_owning_admin_of_an_organisation(): void
    {
        Sanctum::actingAs($this->makeUser(['type' => 'SuperAdmin']));

        $school = $this->makeMasjid();
        $this->assertNull($school->user_id);

        $this->postJson('/api/admin/users/invite', [
            'name' => 'Al-Razi Office',
            'email' => 'office@alrazischool.test',
            'phone' => '+19199989078',
            'type' => 'MasjidAdmin',
            'masjid_id' => $school->id,
        ])->assertStatus(201);

        $created = User::where('email', 'office@alrazischool.test')->firstOrFail();

        // This is the field that actually grants access: with multi_membership
        // off, an org whose user_id is null cannot be reached by any MasjidAdmin.
        $this->assertSame($created->id, $school->fresh()->user_id);
    }

    #[Test]
    public function the_type_to_role_bridge_still_runs_for_an_invited_user(): void
    {
        Sanctum::actingAs($this->makeUser(['type' => 'SuperAdmin']));

        $this->postJson('/api/admin/users/invite', [
            'name' => 'Al-Razi Office',
            'email' => 'office@alrazischool.test',
            'phone' => '+19199989078',
            'type' => 'MasjidAdmin',
        ])->assertStatus(201);

        $this->assertTrue(
            User::where('email', 'office@alrazischool.test')->firstOrFail()->hasRole('masjid-admin'),
            'an invited account was created with no role, so it would be authorised for nothing'
        );
    }

    #[Test]
    public function re_inviting_invalidates_the_previous_link(): void
    {
        Sanctum::actingAs($this->makeUser(['type' => 'SuperAdmin']));

        $user = $this->makeUser(['email' => 'office@alrazischool.test']);
        $firstToken = Password::broker()->createToken($user);

        $this->postJson("/api/admin/users/{$user->id}/invite")->assertStatus(200);

        // The "they never got it, send it again" path must not leave two live
        // links, one of which is in whatever inbox lost the first.
        $this->postJson('/api/admin/reset-password', [
            'token' => $firstToken,
            'email' => 'office@alrazischool.test',
            'password' => 'Sunflower2026',
            'password_confirmation' => 'Sunflower2026',
        ])->assertStatus(422);
    }

    #[Test]
    public function inviting_requires_super_admin(): void
    {
        Sanctum::actingAs($this->makeUser(['type' => 'MasjidAdmin']));

        $this->postJson('/api/admin/users/invite', [
            'name' => 'Sneaky',
            'email' => 'sneaky@example.test',
            'phone' => '+19199989078',
            'type' => 'SuperAdmin',
        ])->assertStatus(403);

        $this->assertSame(0, User::where('email', 'sneaky@example.test')->count());
    }

    // ------------------------------- helpers -------------------------------

    private function makeUser(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'type' => 'MasjidAdmin',
            'password' => 'OldPassword1!',
            'phone' => '+1'.random_int(1000000000, 9999999999),
        ], $overrides));
    }

    private function makeMasjid(): Masjid
    {
        return Masjid::create([
            'name' => 'Al-Razi Test School '.uniqid(),
            'email' => 'school-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'org_type' => 'school',
        ]);
    }
}
