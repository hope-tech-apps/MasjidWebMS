<?php

namespace Tests\Feature;

use App\Mail\AccountAccessMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A "set your password" invite has to survive until the person reads it.
 *
 * The invite a new administrator is emailed is an ordinary password-reset token,
 * so it inherited the framework's 60-minute life. On 2026-09-15 three rounds of
 * invites to MEC and IntelliCor staff all expired with no attempt at all — not a
 * broken page, nobody had opened the email yet. These pin the lifetime by what it
 * means to a person rather than by the number in config.
 */
class InviteLinkLifetimeTest extends TestCase
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

    /**
     * The regression. Under the old 60-minute life this is refused with 422.
     */
    #[Test]
    public function an_invite_opened_the_next_day_still_sets_a_password(): void
    {
        $user = $this->makeUser(['email' => 'office@school.test']);
        $token = Password::broker()->createToken($user);

        $this->travel(20)->hours();

        $this->postJson('/api/admin/reset-password', [
            'token' => $token,
            'email' => 'office@school.test',
            'password' => 'Sunflower2026',
            'password_confirmation' => 'Sunflower2026',
        ])->assertStatus(200);

        $this->assertTrue(Hash::check('Sunflower2026', $user->fresh()->password));
    }

    /**
     * The upper bound. A link that has been sitting in an inbox for more than
     * three days is refused, so "longer" does not quietly become "forever".
     */
    #[Test]
    public function an_invite_is_refused_once_three_days_have_passed(): void
    {
        $user = $this->makeUser(['email' => 'office@school.test']);
        $token = Password::broker()->createToken($user);

        $this->travel(72)->hours();
        $this->travel(1)->minutes();

        $this->postJson('/api/admin/reset-password', [
            'token' => $token,
            'email' => 'office@school.test',
            'password' => 'Sunflower2026',
            'password_confirmation' => 'Sunflower2026',
        ])->assertStatus(422);

        $this->assertTrue(Hash::check('OldPassword1!', $user->fresh()->password));
    }

    /**
     * The escape hatch. Someone who cannot find their invite must still be able
     * to ask for a new link shortly afterwards. The throttle is measured from the
     * same stored timestamp as the lifetime, which is exactly why the lifetime is
     * raised in config rather than by writing a future timestamp onto the row —
     * that would have blocked this request for the whole three days.
     */
    #[Test]
    public function a_long_lived_invite_does_not_lock_anyone_out_of_forgot_password(): void
    {
        $user = $this->makeUser(['email' => 'office@school.test']);
        Password::broker()->createToken($user);

        $this->travel(2)->minutes();

        $this->postJson('/api/admin/forgot-password', ['email' => 'office@school.test'])
            ->assertStatus(200);

        Mail::assertSent(AccountAccessMail::class, function (AccountAccessMail $mail) use ($user) {
            return $mail->user->is($user) && $mail->mode === AccountAccessMail::MODE_RESET;
        });
    }

    private function makeUser(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'type' => 'MasjidAdmin',
            'password' => 'OldPassword1!',
            'phone' => '+1'.random_int(1000000000, 9999999999),
        ], $overrides));
    }
}
