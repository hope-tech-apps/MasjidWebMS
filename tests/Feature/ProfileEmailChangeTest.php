<?php

namespace Tests\Feature;

use App\Http\Requests\Admin\Auth\UpdateProfileRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A staff member cannot rewrite their own sign-in address on /profile.
 *
 * THE DEFECT (the point's review of b9f11d4c, 2026-09-29). `POST /api/admin/profile`
 * let any admin-realm user set their own `users.email` with no proof of the
 * mailbox. `GroupAudience::identitiesFor()` resolves a staff login to the parent
 * contact holding the same address and grants that contact's standing, so a
 * staff account could be pointed at a real parent's address (or, through the
 * `utf8mb4_unicode_ci` collation, a look-alike of it) and read their children's
 * group records.
 *
 * The owner can decide later on a verified change flow. Until then: refused,
 * and nothing is written.
 */
class ProfileEmailChangeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    /** @return array<string, array{0: string}> */
    public static function changedAddresses(): array
    {
        return [
            'another address' => ['someone.else@example.test'],
            'an address a parent holds' => ['parent@example.test'],
            'an accented spelling of the same address' => ["st\u{00E4}ff@example.test"],
            'an accented domain' => ["staff@ex\u{00E4}mple.test"],
            'a compatibility spelling of the same address' => ["staff@\u{FF45}xample.test"],
            'a longer address' => ['staff.member@example.test'],
        ];
    }

    #[Test]
    #[DataProvider('changedAddresses')]
    public function a_change_of_sign_in_address_is_refused_and_the_address_is_unchanged(string $requested): void
    {
        $user = $this->staff();
        Sanctum::actingAs($user);

        $response = $this->post('/api/admin/profile', [
            'name' => 'Renamed Person',
            'email' => $requested,
            'phone' => '+15550001111',
        ], ['Accept' => 'application/json']);

        $response->assertStatus(422)->assertJsonPath('status', 'failed');
        $this->assertContains(UpdateProfileRequest::EMAIL_CHANGE_REFUSED, $response->json('data.email'));
        // The wording is pinned, not just the constant: staff read this sentence
        // and act on it, and only a platform SuperAdmin can change `users.email`
        // (the users routes are `super`-only), so it must not send them to their
        // organisation's administrator, who cannot.
        $this->assertContains(
            'Your sign-in email cannot be changed here. Only a platform SuperAdmin can change it.',
            $response->json('data.email'),
        );
        $this->assertStringNotContainsString('administrator', UpdateProfileRequest::EMAIL_CHANGE_REFUSED);

        $stored = User::findOrFail($user->id);
        $this->assertSame('staff@example.test', $stored->email, 'The sign-in address was changed.');
        $this->assertSame('Original Name', $stored->name, 'A refused profile edit wrote to the account.');
    }

    /** @return array<string, array{0: mixed}> */
    public static function addressesThatAreNotAString(): array
    {
        return [
            'a list' => [['staff@example.test']],
            'a list of one word' => [['x']],
            'a nested list' => [['a' => ['b' => 'staff@example.test']]],
        ];
    }

    #[Test]
    #[DataProvider('addressesThatAreNotAString')]
    public function an_email_that_is_not_a_string_is_a_422_and_not_a_500(mixed $requested): void
    {
        $user = $this->staff();
        Sanctum::actingAs($user);

        $response = $this->post('/api/admin/profile', [
            'name' => 'Renamed Person',
            'email' => $requested,
            'phone' => '+15550001111',
        ], ['Accept' => 'application/json']);

        // `email[]=x` used to reach the refusal closure as an array and its
        // `(string)` cast threw: a 500 with nothing written, where every other
        // bad value is a 422 that names the field.
        $response->assertStatus(422)->assertJsonPath('status', 'failed');
        $this->assertNotEmpty($response->json('data.email'));

        $stored = User::findOrFail($user->id);
        $this->assertSame('staff@example.test', $stored->email);
        $this->assertSame('Original Name', $stored->name, 'A refused profile edit wrote to the account.');
    }

    #[Test]
    public function the_profile_screen_posting_the_current_address_still_saves_the_rest(): void
    {
        $user = $this->staff();
        Sanctum::actingAs($user);

        $this->post('/api/admin/profile', [
            'name' => 'Renamed Person',
            'email' => 'staff@example.test',
            'phone' => '+15550001111',
        ], ['Accept' => 'application/json'])->assertOk();

        $stored = User::findOrFail($user->id);
        $this->assertSame('Renamed Person', $stored->name);
        $this->assertSame('+15550001111', $stored->phone);
        $this->assertSame('staff@example.test', $stored->email);
    }

    #[Test]
    public function a_difference_of_case_or_spaces_is_not_a_change_and_does_not_rewrite_the_stored_spelling(): void
    {
        $user = $this->staff();
        Sanctum::actingAs($user);

        $this->post('/api/admin/profile', [
            'name' => 'Renamed Person',
            'email' => 'STAFF@Example.test',
            'phone' => '+15550001111',
        ], ['Accept' => 'application/json'])->assertOk();

        $this->assertSame('staff@example.test', User::findOrFail($user->id)->email);
    }

    private function staff(): User
    {
        return User::factory()->create([
            'type' => 'SuperAdmin',
            'name' => 'Original Name',
            'email' => 'staff@example.test',
            'phone' => '+15559998888',
            'password' => 'OldPassword1!',
        ]);
    }
}
