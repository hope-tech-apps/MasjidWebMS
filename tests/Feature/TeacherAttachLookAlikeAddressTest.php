<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupStaff;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FoldsAccentsLikeUnicodeCi;
use Tests\TestCase;

/**
 * Adding a teacher attaches the login at the address that was TYPED, not one
 * the collation calls equal to it.
 *
 * TeachersController::store is create-or-attach: an address that already belongs
 * to a Teacher at another school attaches THIS school to that login. `users.email`
 * is `utf8mb4_unicode_ci` on production, so User::scopeWhereEmailIs() (a plain
 * equality on MySQL) also returned the login stored under a look-alike
 * (`sara@gmaíl.com` for `sara@gmail.com`), and the school was attached to
 * somebody else's account under an address its inviter never typed.
 *
 * Premise built as in MemberSignInLookAlikeAddressTest: the teacher's address is
 * stored ACCENTED, the plain one is typed, and `LOWER()` folds accents.
 */
class TeacherAttachLookAlikeAddressTest extends TestCase
{
    use FoldsAccentsLikeUnicodeCi;
    use RefreshDatabase;

    /** What the teacher holds: the accented form. */
    private const STORED = 'sara@gmaíl.com';

    /** What the inviter types: the plain one. */
    private const TYPED = 'sara@gmail.com';

    private Masjid $alrazi;

    private Masjid $biss;

    private Group $seventh;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tenancy.multi_membership' => true]);
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->alrazi = $this->makeSchool('Al-Razi');
        $this->biss = $this->makeSchool('BISS');
        $bissAdmin = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
        $this->biss->user_id = $bissAdmin->id;
        $this->biss->save();

        $this->seventh = Group::factory()->create([
            'masjid_id' => $this->biss->id,
            'kind' => Group::KIND_CLASS,
            'name' => '7th Grade',
            'slug' => Str::slug('7th Grade'),
        ]);

        Mail::fake();
        $this->foldAccentsLikeUnicodeCi();
        Sanctum::actingAs($bissAdmin, ['staff']);
    }

    protected function tearDown(): void
    {
        $this->stopFoldingAccents();

        parent::tearDown();
    }

    #[Test]
    public function a_look_alike_address_does_not_attach_the_login_stored_under_the_real_one(): void
    {
        $teacher = $this->teacherAt(self::STORED);
        $this->assertSame(
            [$teacher->id],
            User::withTrashed()->whereEmailIs(self::TYPED)->pluck('id')->all(),
            'PREMISE: whereEmailIs() on the typed address must return the other teacher, or this test proves nothing.',
        );
        $usersBefore = User::withTrashed()->count();

        $response = $this->postJson($this->bissBase() . '/teachers', $this->payload(self::TYPED));

        // Refused like any other login this flow cannot add, and nothing written.
        $response->assertStatus(422)->assertJsonPath('status', 'failed');
        $this->assertNotNull($response->json('data.email'));

        $this->assertSame(1, MasjidUser::where('user_id', $teacher->id)->count(), 'The teacher was attached to the second school.');
        $this->assertSame(0, GroupStaff::withoutMasjidScope()->where('user_id', $teacher->id)->where('masjid_id', $this->biss->id)->count());
        $this->assertSame($usersBefore, User::withTrashed()->count(), 'A login was created.');
        Mail::assertNothingSent();
    }

    #[Test]
    public function the_exact_address_is_attached_even_when_a_look_alike_exists(): void
    {
        // The look-alike is created FIRST, so an unfiltered "first match" finds it.
        $lookAlike = $this->teacherAt(self::STORED);
        $exact = $this->teacherAt(self::TYPED);

        $this->postJson($this->bissBase() . '/teachers', $this->payload(self::TYPED))->assertCreated();

        $this->assertSame(2, MasjidUser::where('user_id', $exact->id)->count(), 'The exact address was not attached.');
        $this->assertSame(1, MasjidUser::where('user_id', $lookAlike->id)->count(), 'The look-alike login was attached.');
    }

    #[Test]
    public function an_address_that_differs_only_in_case_is_still_attached(): void
    {
        $teacher = $this->teacherAt('Sara@Gmail.com');

        // The request lower-cases what is typed; the stored form keeps its capitals.
        $this->postJson($this->bissBase() . '/teachers', $this->payload('SARA@gmail.com'))->assertCreated();

        $this->assertSame(2, MasjidUser::where('user_id', $teacher->id)->count());
    }

    // ---------------------------------------------------------------- helpers

    private function bissBase(): string
    {
        return "/api/admin/masjids/{$this->biss->id}";
    }

    /** @return array<string, mixed> */
    private function payload(string $email): array
    {
        return [
            'name' => 'Typed Name',
            'email' => $email,
            'class_ids' => [(int) $this->seventh->id],
        ];
    }

    /** A live Teacher with a default membership at Al-Razi, at `$email`. */
    private function teacherAt(string $email): User
    {
        $teacher = User::factory()->create([
            'type' => 'Teacher',
            'email' => $email,
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);

        MasjidUser::create([
            'masjid_id' => $this->alrazi->id,
            'user_id' => $teacher->id,
            'role' => 'teacher',
            'is_default' => true,
        ]);

        return $teacher;
    }

    private function makeSchool(string $name): Masjid
    {
        return Masjid::create([
            'name' => $name,
            'email' => 'school-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'crm_enabled' => true,
            'org_type' => 'school',
        ]);
    }
}
