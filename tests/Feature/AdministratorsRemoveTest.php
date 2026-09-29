<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupStaff;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * DELETE /api/admin/masjids/{id}/administrators/{user_id} takes an office login away.
 *
 * It is the Administrators screen's door: that screen lists MasjidAdmins only, but the
 * route only checks for a `masjid_user` row in the bound school. A Teacher or a
 * LunchStaff holds one too, and their sessions are GLOBAL (a token names no
 * organisation). So without a type check, a crafted request from school B deleted every
 * token a teacher held at school A, left B's class assignments behind, and never re-picked
 * their default. The Teachers and Team screens own those removals.
 */
class AdministratorsRemoveTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $alrazi;
    private Masjid $biss;
    private User $bissAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        config(['tenancy.multi_membership' => true]);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        [$this->alrazi] = $this->school('Al-Razi');
        [$this->biss, $this->bissAdmin] = $this->school('BISS');

        Sanctum::actingAs($this->bissAdmin, ['staff']);
    }

    #[Test]
    public function a_teacher_shared_with_another_school_is_refused_and_nothing_changes(): void
    {
        $teacher = User::factory()->create(['type' => 'Teacher', 'phone' => '+15550001111']);
        MasjidUser::create(['masjid_id' => $this->alrazi->id, 'user_id' => $teacher->id, 'role' => 'teacher', 'is_default' => true]);
        MasjidUser::create(['masjid_id' => $this->biss->id, 'user_id' => $teacher->id, 'role' => 'teacher', 'is_default' => false]);
        $class = Group::factory()->create(['masjid_id' => $this->biss->id, 'kind' => Group::KIND_CLASS, 'name' => '7th Grade', 'slug' => '7th-grade']);
        $class->staff()->attach($teacher->id, ['masjid_id' => $this->biss->id, 'role' => GroupStaff::ROLE_TEACHER, 'assigned_at' => now()]);
        $teacher->createToken('at-alrazi');
        $teacher->createToken('phone');

        $this->deleteJson($this->url($teacher))
            ->assertStatus(422)
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('message', 'Only administrators are removed here. Remove them on the Teachers screen.');

        $this->assertSame(2, $teacher->fresh()->tokens()->count(), 'their sessions at the other school are untouched');
        $this->assertSame(2, MasjidUser::where('user_id', $teacher->id)->count());
        $this->assertSame(1, GroupStaff::withoutMasjidScope()->where('user_id', $teacher->id)->where('masjid_id', $this->biss->id)->count());
        $this->assertTrue((bool) MasjidUser::where('user_id', $teacher->id)->where('masjid_id', $this->alrazi->id)->value('is_default'));
    }

    #[Test]
    public function a_lunch_staff_login_is_sent_to_its_own_screen(): void
    {
        $lunch = User::factory()->create(['type' => User::TYPE_LUNCH_STAFF, 'phone' => '+15550006666']);
        MasjidUser::create(['masjid_id' => $this->biss->id, 'user_id' => $lunch->id, 'role' => 'lunch-staff', 'is_default' => true]);
        $lunch->createToken('phone');

        $this->deleteJson($this->url($lunch))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only administrators are removed here. Remove them on the Team & Access screen.');

        $this->assertSame(1, $lunch->fresh()->tokens()->count());
        $this->assertSame(1, MasjidUser::where('user_id', $lunch->id)->count());
    }

    #[Test]
    public function an_administrator_is_still_removed_and_signed_out(): void
    {
        $admin = User::factory()->create(['type' => 'MasjidAdmin', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
        MasjidUser::create(['masjid_id' => $this->biss->id, 'user_id' => $admin->id, 'role' => 'masjid-admin', 'is_default' => true]);
        $admin->createToken('laptop');

        $this->deleteJson($this->url($admin))->assertOk();

        $this->assertSame(0, MasjidUser::where('user_id', $admin->id)->count());
        $this->assertSame(0, $admin->fresh()->tokens()->count());
    }

    #[Test]
    public function removing_an_administrators_default_office_promotes_the_lowest_remaining_one(): void
    {
        [$gamma] = $this->school('Gamma');
        $admin = User::factory()->create(['type' => 'MasjidAdmin', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
        // Default at BISS; Al-Razi (lowest id) and Gamma remain after the removal.
        MasjidUser::create(['masjid_id' => $this->biss->id, 'user_id' => $admin->id, 'role' => 'masjid-admin', 'is_default' => true]);
        MasjidUser::create(['masjid_id' => $gamma->id, 'user_id' => $admin->id, 'role' => 'masjid-admin', 'is_default' => false]);
        MasjidUser::create(['masjid_id' => $this->alrazi->id, 'user_id' => $admin->id, 'role' => 'masjid-admin', 'is_default' => false]);

        $this->deleteJson($this->url($admin))->assertOk();

        $this->assertSame([$this->alrazi->id], MasjidUser::where('user_id', $admin->id)->where('is_default', true)->pluck('masjid_id')->map(fn ($i): int => (int) $i)->all());
        $this->assertSame(2, MasjidUser::where('user_id', $admin->id)->count());
    }

    #[Test]
    public function removing_a_non_default_office_leaves_the_default_alone(): void
    {
        $admin = User::factory()->create(['type' => 'MasjidAdmin', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
        MasjidUser::create(['masjid_id' => $this->alrazi->id, 'user_id' => $admin->id, 'role' => 'masjid-admin', 'is_default' => true]);
        MasjidUser::create(['masjid_id' => $this->biss->id, 'user_id' => $admin->id, 'role' => 'masjid-admin', 'is_default' => false]);

        $this->deleteJson($this->url($admin))->assertOk();

        $row = MasjidUser::where('user_id', $admin->id)->sole();
        $this->assertSame($this->alrazi->id, (int) $row->masjid_id);
        $this->assertTrue((bool) $row->is_default);
    }

    private function url(User $user): string
    {
        return "/api/admin/masjids/{$this->biss->id}/administrators/{$user->id}";
    }

    /** @return array{0: Masjid, 1: User} */
    private function school(string $name): array
    {
        $school = Masjid::create([
            'name' => $name,
            'email' => 'school-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0,
            'crm_enabled' => true, 'org_type' => 'school',
        ]);

        $admin = User::factory()->create(['type' => 'MasjidAdmin', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
        $school->user_id = $admin->id;
        $school->save();

        return [$school, $admin];
    }
}
