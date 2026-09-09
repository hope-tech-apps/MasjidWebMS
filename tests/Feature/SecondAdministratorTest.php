<?php

namespace Tests\Feature;

use App\Mail\AccountAccessMail;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A school can have more than one person in the office (R3).
 *
 * The vendor assessment recorded this as a hard database constraint. It was not:
 * two MasjidAdmins already bind — the owner through `masjids.user_id`, a second
 * through a `masjid_user` row — because TenantResolver falls through to
 * staffMemberships() for a principal who owns nothing. What was missing was a
 * way to create the second one.
 *
 * So the first test here is the one that proves the premise wrong, and the rest
 * pin the provisioning that was missing.
 */
class SecondAdministratorTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $school;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->school = Masjid::create([
            'name' => 'School ' . uniqid(),
            'email' => 'school-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 St',
            'latitude' => 0.0, 'longitude' => 0.0,
            'crm_enabled' => true, 'org_type' => 'school',
        ]);

        $this->owner = User::factory()->create([
            'type' => 'MasjidAdmin', 'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
        $this->school->user_id = $this->owner->id;
        $this->school->save();
        MasjidUser::create([
            'masjid_id' => $this->school->id, 'user_id' => $this->owner->id,
            'role' => 'masjid-admin', 'is_default' => true,
        ]);

        Sanctum::actingAs($this->owner, ['*']);
    }

    private function url(string $suffix = ''): string
    {
        return "/api/admin/masjids/{$this->school->id}/administrators{$suffix}";
    }

    private function asNewRequest(): void
    {
        app('auth')->forgetGuards();
        app(\App\Support\TenantContext::class)->forgetTenant();
    }

    // ------------------------------------------------- the premise, corrected

    #[Test]
    public function a_second_administrator_can_run_the_office_without_taking_ownership(): void
    {
        Mail::fake();

        $this->postJson($this->url(), [
            'name' => 'Sr. Amal', 'email' => 'amal@example.test',
        ])->assertCreated();

        $second = User::where('email', 'amal@example.test')->firstOrFail();

        // THE POINT: the first admin still owns the organisation.
        $this->assertSame($this->owner->id, $this->school->fresh()->user_id,
            'adding an administrator must never move ownership');

        // And the second one can actually work.
        $this->asNewRequest();
        Sanctum::actingAs($second, ['*']);
        $this->getJson("/api/admin/masjids/{$this->school->id}/contacts")->assertOk();

        // And so can the first, still.
        $this->asNewRequest();
        Sanctum::actingAs($this->owner, ['*']);
        $this->getJson("/api/admin/masjids/{$this->school->id}/contacts")->assertOk();
    }

    #[Test]
    public function the_new_administrator_is_emailed_a_link_and_never_given_a_password(): void
    {
        Mail::fake();

        $this->postJson($this->url(), ['name' => 'Sr. Juldeh', 'email' => 'juldeh@example.test'])
            ->assertCreated();

        Mail::assertSent(AccountAccessMail::class,
            fn (AccountAccessMail $m) => $m->user->email === 'juldeh@example.test');

        $body = $this->postJson($this->url(), ['name' => 'Sr. Hikmat', 'email' => 'hikmat@example.test'])
            ->getContent();

        $this->assertStringNotContainsString('password', strtolower($body),
            'no response may carry or mention a password we generated');
    }

    // ---------------------------------------------------------------- removal

    #[Test]
    public function removing_a_second_administrator_ends_their_access_but_not_the_owners(): void
    {
        Mail::fake();
        $this->postJson($this->url(), ['name' => 'Temp', 'email' => 'temp@example.test'])->assertCreated();
        $second = User::where('email', 'temp@example.test')->firstOrFail();

        $this->deleteJson($this->url("/{$second->id}"))->assertOk();

        $this->assertDatabaseMissing('masjid_user', [
            'masjid_id' => $this->school->id, 'user_id' => $second->id,
        ]);

        $this->asNewRequest();
        Sanctum::actingAs($this->owner, ['*']);
        $this->getJson("/api/admin/masjids/{$this->school->id}/contacts")->assertOk();
    }

    /**
     * Removing the owner's membership would leave them binding through the
     * ownership fallback anyway — so it would report a removal that did not
     * happen. Refusing is the honest answer.
     */
    #[Test]
    public function the_owner_cannot_be_removed_through_this_door(): void
    {
        $this->deleteJson($this->url("/{$this->owner->id}"))->assertStatus(409);

        $this->assertDatabaseHas('masjid_user', [
            'masjid_id' => $this->school->id, 'user_id' => $this->owner->id,
        ]);
    }

    // ----------------------------------------------------------------- limits

    #[Test]
    public function a_teacher_cannot_add_an_administrator(): void
    {
        $teacher = User::factory()->create([
            'type' => 'Teacher', 'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
        MasjidUser::create([
            'masjid_id' => $this->school->id, 'user_id' => $teacher->id,
            'role' => 'teacher', 'is_default' => true,
        ]);

        $this->asNewRequest();
        Sanctum::actingAs($teacher, ['*']);

        $this->postJson($this->url(), ['name' => 'Sneaky', 'email' => 'sneaky@example.test'])
            ->assertUnauthorized();

        $this->assertDatabaseMissing('users', ['email' => 'sneaky@example.test']);
    }

    #[Test]
    public function an_administrator_of_one_school_cannot_be_added_to_another(): void
    {
        Mail::fake();

        $other = Masjid::create([
            'name' => 'Other ' . uniqid(), 'email' => 'other-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '2 St',
            'latitude' => 0.0, 'longitude' => 0.0, 'crm_enabled' => true,
        ]);
        $otherOwner = User::factory()->create([
            'type' => 'MasjidAdmin', 'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
        $other->user_id = $otherOwner->id;
        $other->save();
        MasjidUser::create([
            'masjid_id' => $other->id, 'user_id' => $otherOwner->id,
            'role' => 'masjid-admin', 'is_default' => true,
        ]);

        // Our owner tries to add an administrator to somebody else's school.
        $this->postJson("/api/admin/masjids/{$other->id}/administrators", [
            'name' => 'Intruder', 'email' => 'intruder@example.test',
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'intruder@example.test']);
    }

    #[Test]
    public function the_list_shows_who_runs_the_office_and_marks_the_owner(): void
    {
        Mail::fake();
        $this->postJson($this->url(), ['name' => 'Sr. Amal', 'email' => 'amal2@example.test'])->assertCreated();

        $rows = collect($this->getJson($this->url())->assertOk()->json('data'));

        $this->assertCount(2, $rows);
        $this->assertTrue($rows->firstWhere('user_id', $this->owner->id)['is_owner']);
        $this->assertFalse($rows->firstWhere('email', 'amal2@example.test')['is_owner']);
    }
}
