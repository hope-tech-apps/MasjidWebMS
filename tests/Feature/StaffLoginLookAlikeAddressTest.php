<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FoldsAccentsLikeUnicodeCi;
use Tests\TestCase;

/**
 * Staff sign-in matches the address that was TYPED, not one the collation calls
 * equal to it.
 *
 * THE DEFECT (the point's review of the follow-ups, 2026-09-29). The staff
 * limiter keys its per-address bucket on `ContactIdentity::submittedAddress()`,
 * while `AuthController::login()` looked the account up with
 * `User::where('email', $typed)` and `LoginRequest` validated it with
 * `exists:users,email`. `users.email` is utf8mb4_unicode_ci, so `sara@gmail.com`
 * and `sara@gmaíl.com` are the same user to both of those queries and different
 * addresses to the limiter: the lookup and the bucket disagreed about who an
 * attempt was for. The lookup now runs through `submittedAddress()` and the found
 * account must be the exact address (`ContactIdentity::sameAddress()`); anything
 * else is the same answer as a wrong password.
 *
 * HOW THESE TESTS REACH IT ON SQLITE (Tests\Support\FoldsAccentsLikeUnicodeCi):
 * `users.email` is given production's collation, and the unique index with it,
 * so `exists:users,email` passes for the look-alike as it does on production; and
 * `LOWER()` folds accents, which is what `User::whereEmailIs()` uses on SQLite.
 * The account is stored ACCENTED and the plain address is typed. The premise
 * check asserts the SQL still finds the account before the door is asked to
 * refuse it.
 */
class StaffLoginLookAlikeAddressTest extends TestCase
{
    use FoldsAccentsLikeUnicodeCi;
    use RefreshDatabase;

    /** What the account holds: the accented form. */
    private const STORED = 'sara@gmaíl.com';

    /** What is typed: the plain one. */
    private const TYPED = 'sara@gmail.com';

    private const PASSWORD = 'correct-horse-battery';

    protected function setUp(): void
    {
        parent::setUp();

        $this->collateColumnLikeUnicodeCi('users', 'email');
        $this->foldAccentsLikeUnicodeCi();
    }

    protected function tearDown(): void
    {
        $this->stopFoldingAccents();

        parent::tearDown();
    }

    #[Test]
    public function a_look_alike_address_is_a_wrong_password_even_when_the_password_is_right(): void
    {
        $user = $this->staffAt(self::STORED);
        $this->assertTheSqlStillFinds($user, self::TYPED);

        // A genuine wrong password, on an account whose address is exactly the one typed.
        $this->staffAt('control@example.test');

        $lookAlike = $this->login(self::TYPED, self::PASSWORD);
        $wrongPassword = $this->login('control@example.test', 'not-the-password');

        $lookAlike->assertOk()->assertJsonPath('message', 'invalid credentials');
        $this->assertNull($lookAlike->json('data.token'), 'A look-alike address was given a session.');
        $this->assertSame($wrongPassword->getStatusCode(), $lookAlike->getStatusCode());
        $this->assertSame($wrongPassword->getContent(), $lookAlike->getContent(), 'A look-alike address is not answered like a wrong password.');
        $this->assertSame(0, $user->tokens()->count(), 'A token was minted for the look-alike.');
    }

    #[Test]
    public function an_accented_spelling_of_a_plain_address_does_not_sign_in_either(): void
    {
        // The other direction: the account holds the plain address, the accented
        // domain is typed. `submittedAddress()` turns it into punycode, which is a
        // different address, so nothing is found. The collation would have said
        // otherwise.
        $user = $this->staffAt(self::TYPED);
        $this->assertTheCollationStillCallsThemEqual($user, self::STORED);

        $response = $this->login(self::STORED, self::PASSWORD);

        $response->assertOk()->assertJsonPath('message', 'invalid credentials');
        $this->assertNull($response->json('data.token'));
        $this->assertSame(0, $user->tokens()->count());
    }

    #[Test]
    public function the_exact_address_still_signs_in_whatever_its_case(): void
    {
        $this->staffAt('Sara@Gmail.com');

        $response = $this->login('SARA@gmail.com', self::PASSWORD);

        $response->assertOk()->assertJsonPath('status', 'success');
        $this->assertNotEmpty($response->json('data.token'));
    }

    #[Test]
    public function the_plain_address_signs_in_as_before(): void
    {
        $this->staffAt(self::TYPED);

        $response = $this->login(self::TYPED, self::PASSWORD);

        $response->assertOk()->assertJsonPath('status', 'success');
        $this->assertNotEmpty($response->json('data.token'));
    }

    // ---------------------------------------------------------------- helpers

    private function staffAt(string $email): User
    {
        return User::factory()->create([
            'type' => 'SuperAdmin',
            'email' => $email,
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'password' => Hash::make(self::PASSWORD),
        ]);
    }

    private function login(string $email, string $password): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/admin/login', ['email' => $email, 'password' => $password]);
    }

    /** PREMISE, for an account stored ACCENTED and a plain address typed: both lookups still find it. */
    private function assertTheSqlStillFinds(User $user, string $typed): void
    {
        $this->assertSame(
            [$user->id],
            User::whereEmailIs($typed)->pluck('id')->all(),
            'PREMISE: whereEmailIs() on the typed address must return the account, or this test proves nothing.',
        );
        $this->assertTheCollationStillCallsThemEqual($user, $typed);
    }

    /** PREMISE: a plain `where email =` (what LoginRequest\'s `exists` rule runs) finds the account, as unicode_ci does. */
    private function assertTheCollationStillCallsThemEqual(User $user, string $typed): void
    {
        $this->assertSame(
            [$user->id],
            User::query()->where('email', $typed)->pluck('id')->all(),
            'PREMISE: users.email must call the typed address equal to the stored one, or this test proves nothing.',
        );
    }
}
