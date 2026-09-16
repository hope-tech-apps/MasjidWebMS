<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A password-reset link lives for one hour, and that is a decision, not a default.
 *
 * On 2026-09-16 the lifetime was raised to 72 hours for three days, to revive
 * three administrator invites without sending a fourth email, and put back
 * afterwards. It is global: the same token backs every administrator's Forgot
 * password at every organisation, and a leaked reset email is a password-change
 * credential for as long as it lives. This pins the hour by what it means, so
 * the next person who raises it — probably the next time an invite goes unread —
 * has to change this test and say why, rather than pass CI in silence.
 */
class ResetLinkLifetimeTest extends TestCase
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

    #[Test]
    public function a_reset_link_still_works_inside_the_hour(): void
    {
        $user = $this->makeUser();
        $token = Password::broker()->createToken($user);

        $this->travel(59)->minutes();

        $this->reset($token)->assertStatus(200);
        $this->assertTrue(Hash::check('Sunflower2026', $user->fresh()->password));
    }

    #[Test]
    public function a_reset_link_is_refused_after_an_hour(): void
    {
        $user = $this->makeUser();
        $token = Password::broker()->createToken($user);

        $this->travel(61)->minutes();

        $this->reset($token)->assertStatus(422);
        $this->assertTrue(Hash::check('OldPassword1!', $user->fresh()->password));
    }

    private function reset(string $token): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/admin/reset-password', [
            'token' => $token,
            'email' => 'office@school.test',
            'password' => 'Sunflower2026',
            'password_confirmation' => 'Sunflower2026',
        ]);
    }

    private function makeUser(): User
    {
        return User::factory()->create([
            'email' => 'office@school.test',
            'type' => 'MasjidAdmin',
            'password' => 'OldPassword1!',
            'phone' => '+1'.random_int(1000000000, 9999999999),
        ]);
    }
}
