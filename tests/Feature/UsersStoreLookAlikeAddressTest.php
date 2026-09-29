<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FoldsAccentsLikeUnicodeCi;
use Tests\TestCase;

/**
 * Creating a user restores the archived account at the EXACT address only.
 *
 * THE DEFECT (the point's review of b9f11d4c, 2026-09-29). `UsersController::store`
 * looked an archived user up with `where('email', $typed)->first()` and, on a
 * hit, restored it and re-addressed it to the typed spelling. `users.email` is
 * utf8mb4_unicode_ci on production, where `sara@gmail.com` = `sara@gmaíl.com`, so
 * a SuperAdmin typing one address restored a DIFFERENT person's archived account:
 * its role, its memberships, its history.
 *
 * HOW THESE TESTS REACH IT ON SQLITE (Tests\Support\FoldsAccentsLikeUnicodeCi):
 * the archived account's address is stored ACCENTED and the plain one is typed,
 * with `LOWER()` overridden to fold accents, so the candidate query returns the
 * account as MySQL would. Every test asserts that premise first.
 */
class UsersStoreLookAlikeAddressTest extends TestCase
{
    use FoldsAccentsLikeUnicodeCi;
    use RefreshDatabase;

    /** What the archived account holds: the accented form. */
    private const STORED = 'sara@gmaíl.com';

    /** What the SuperAdmin types. */
    private const TYPED = 'sara@gmail.com';

    private User $super;

    protected function setUp(): void
    {
        parent::setUp();

        $this->foldAccentsLikeUnicodeCi();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        Storage::fake('public');

        $this->super = User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+15550004444'])->fresh();
        Sanctum::actingAs($this->super);
    }

    protected function tearDown(): void
    {
        $this->stopFoldingAccents();

        parent::tearDown();
    }

    #[Test]
    public function creating_a_user_does_not_restore_an_archived_account_at_a_look_alike_address(): void
    {
        $archived = $this->archivedUser(self::STORED, 'Archived Sara');
        $this->assertSame(
            [$archived->id],
            User::onlyTrashed()->whereRaw('LOWER(email) = ?', [self::TYPED])->pluck('id')->all(),
            'PREMISE: LOWER(email) = the typed address must return the archived look-alike, or this test proves nothing.',
        );
        $before = User::withTrashed()->findOrFail($archived->id)->getAttributes();
        $usersBefore = User::withTrashed()->count();

        $response = $this->createUser(self::TYPED);

        $response->assertStatus(422)->assertJsonPath('status', 'error');
        $after = User::withTrashed()->findOrFail($archived->id);
        $this->assertTrue($after->trashed(), 'The archived account was restored.');
        $this->assertSame($before, $after->getAttributes(), 'The archived account was rewritten.');
        $this->assertSame($usersBefore, User::withTrashed()->count(), 'A user was created.');
    }

    #[Test]
    public function creating_a_user_still_restores_the_archived_account_at_the_same_address_whatever_its_case(): void
    {
        $archived = $this->archivedUser('sara@example.test', 'Archived Sara');

        $this->createUser('Sara@Example.test')->assertSuccessful()
            ->assertJsonFragment(['message' => 'This email belonged to an archived user. That account has been restored and updated with the new details.']);

        $restored = User::findOrFail($archived->id);
        $this->assertFalse($restored->trashed());
        $this->assertSame('Fresh Sara', $restored->name);
    }

    private function archivedUser(string $email, string $name): User
    {
        $user = User::factory()->create(['type' => 'MasjidAdmin', 'email' => $email, 'name' => $name, 'phone' => '+15550001111']);
        $user->delete();

        return $user;
    }

    private function createUser(string $email): \Illuminate\Testing\TestResponse
    {
        return $this->post('/api/admin/users', [
            'name' => 'Fresh Sara',
            'email' => $email,
            'phone' => '+15550007777',
            'type' => 'MasjidAdmin',
            'avatar' => UploadedFile::fake()->image('avatar.png', 20, 20),
            'password' => 'Restore#2026x',
            'password_confirmation' => 'Restore#2026x',
        ], ['Accept' => 'application/json']);
    }
}
