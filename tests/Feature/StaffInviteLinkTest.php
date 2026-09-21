<?php

namespace Tests\Feature;

use App\Mail\AccountAccessMail;
use App\Models\User;
use App\Services\Auth\AccountAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A new staff account's first-password link lives a WEEK; a Forgot-password link
 * still lives an hour — and neither can pass for the other.
 *
 * Owner, 2026-09-21, after the BISS teacher meeting: teachers were told to watch
 * for a set-up email, and an invite that dies inside an hour is the MEC admins'
 * 2026-09-15 failure again. The only earlier way to lengthen invites was to
 * lengthen every reset too, which is what made each admin's reset link live 72
 * hours on 2026-09-16. Invites now have their own broker and TABLE
 * (config/auth.php `invites`), so the thing pinned here is the separation as
 * much as the lifetime: an invite token is only found where invites live, and a
 * reset token never gets written there.
 */
class StaffInviteLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        Mail::fake();
    }

    #[Test]
    public function an_invite_is_written_to_its_own_table_and_says_so_in_the_link(): void
    {
        $user = $this->makeUser();

        app(AccountAccessService::class)->invite($user, 'Burlington Islamic Sunday School');

        $this->assertSame(1, DB::table('account_invite_tokens')->where('email', $user->email)->count());
        $this->assertSame(0, DB::table('password_reset_tokens')->where('email', $user->email)->count(),
            'an invite must never be minted into the table the 60-minute resets are read from');

        $mail = $this->sentMail();
        $this->assertStringContainsString('kind=invite', $mail->url, 'the link has to tell the server which table to look in');
        $this->assertSame(60 * 24 * 7, $mail->expiresInMinutes);
    }

    #[Test]
    public function an_invite_still_works_six_days_later(): void
    {
        $user = $this->makeUser();
        app(AccountAccessService::class)->invite($user);
        $token = $this->tokenFrom($this->sentMail());

        // The ordinary case the owner described: a teacher opens it days later.
        $this->travel(6)->days();

        $this->set($token, 'invite')->assertOk();
        $this->assertTrue(Hash::check('Sunflower2026', $user->fresh()->password));
    }

    #[Test]
    public function an_invite_is_refused_after_a_week_and_the_page_says_how_to_recover(): void
    {
        $user = $this->makeUser();
        app(AccountAccessService::class)->invite($user);
        $token = $this->tokenFrom($this->sentMail());

        $this->travel(8)->days();

        $this->set($token, 'invite')
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'Forgot password'));
        $this->assertTrue(Hash::check('OldPassword1!', $user->fresh()->password));
    }

    #[Test]
    public function forgot_password_still_mints_a_one_hour_reset_and_never_an_invite(): void
    {
        $user = $this->makeUser();

        app(AccountAccessService::class)->sendResetLink($user->email);

        $this->assertSame(1, DB::table('password_reset_tokens')->where('email', $user->email)->count());
        $this->assertSame(0, DB::table('account_invite_tokens')->where('email', $user->email)->count(),
            'a reset must never be written where a week-long invite is read');

        $mail = $this->sentMail();
        $this->assertStringNotContainsString('kind=invite', $mail->url);
        $this->assertSame(60, $mail->expiresInMinutes);
    }

    #[Test]
    public function a_reset_token_cannot_be_presented_as_an_invite_to_borrow_a_week(): void
    {
        // The escalation the separate table exists to prevent. A reset token
        // replayed with kind=invite is looked for among invites, where it was
        // never written, so it is refused outright — even inside its own hour.
        $user = $this->makeUser();
        app(AccountAccessService::class)->sendResetLink($user->email);
        $token = $this->tokenFrom($this->sentMail());

        $this->set($token, 'invite')->assertStatus(422);
        $this->assertTrue(Hash::check('OldPassword1!', $user->fresh()->password));
    }

    #[Test]
    public function an_invite_token_presented_as_a_reset_is_refused_too(): void
    {
        $user = $this->makeUser();
        app(AccountAccessService::class)->invite($user);
        $token = $this->tokenFrom($this->sentMail());

        $this->set($token, null)->assertStatus(422);
        $this->assertTrue(Hash::check('OldPassword1!', $user->fresh()->password));
    }

    #[Test]
    public function forgot_password_kills_an_unopened_invite_so_there_is_only_ever_one_live_link(): void
    {
        $user = $this->makeUser();
        app(AccountAccessService::class)->invite($user);
        $inviteToken = $this->tokenFrom($this->sentMail());

        // They never found the invite, so they press Forgot password instead.
        Mail::fake();
        app(AccountAccessService::class)->sendResetLink($user->email);

        $this->assertSame(0, DB::table('account_invite_tokens')->where('email', $user->email)->count(),
            'the week-long invite must not outlive a newer reset in the same person\'s inbox');
        $this->set($inviteToken, 'invite')->assertStatus(422);
    }

    #[Test]
    public function setting_the_password_from_an_invite_clears_any_reset_link_too(): void
    {
        $user = $this->makeUser();
        app(AccountAccessService::class)->sendResetLink($user->email);
        Mail::fake();
        app(AccountAccessService::class)->invite($user); // replaces the reset, per the rule above
        $inviteToken = $this->tokenFrom($this->sentMail());

        $this->set($inviteToken, 'invite')->assertOk();

        $this->assertSame(0, DB::table('password_reset_tokens')->where('email', $user->email)->count());
        $this->assertSame(0, DB::table('account_invite_tokens')->where('email', $user->email)->count());
    }

    #[Test]
    public function the_email_says_seven_days_in_words_not_ten_thousand_minutes(): void
    {
        $user = $this->makeUser();
        app(AccountAccessService::class)->invite($user, 'Burlington Islamic Sunday School');

        $html = $this->sentMail()->render();

        $this->assertStringContainsString('expires in 7 days', $html);
        $this->assertStringNotContainsString('10080', $html);
        $this->assertStringContainsString('Forgot password', $html);
    }

    private function sentMail(): AccountAccessMail
    {
        $sent = Mail::sent(AccountAccessMail::class);
        $this->assertCount(1, $sent, 'exactly one access email should have been sent');

        return $sent->first();
    }

    private function tokenFrom(AccountAccessMail $mail): string
    {
        parse_str((string) parse_url($mail->url, PHP_URL_FRAGMENT), $fragment);
        $this->assertArrayHasKey('token', $fragment);

        return (string) $fragment['token'];
    }

    private function set(string $token, ?string $kind): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/admin/reset-password', array_filter([
            'token' => $token,
            'email' => 'teacher@school.test',
            'kind' => $kind,
            'password' => 'Sunflower2026',
            'password_confirmation' => 'Sunflower2026',
        ]));
    }

    private function makeUser(): User
    {
        return User::factory()->create([
            'email' => 'teacher@school.test',
            'type' => 'Teacher',
            'password' => 'OldPassword1!',
            'phone' => '+1'.random_int(1000000000, 9999999999),
        ]);
    }
}
