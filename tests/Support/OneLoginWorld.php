<?php

namespace Tests\Support;

use App\Models\Group;
use App\Models\GroupStaff;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\MealMenu;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/**
 * The real case, as fixtures: one person who runs Friday lunch at a masjid and
 * teaches at a school, with ONE login.
 *
 *   shamina   created as lunch staff (`users.type` LunchStaff). Lunch at the masjid
 *             (her default), and a teacher of one class at the school.
 *   mirror    the same two roles on a login created as a Teacher: a teacher of the
 *             same class at the school (his default), lunch staff at the masjid.
 *
 * Friday lunch is switched ON at BOTH organisations and both hold an open menu, so
 * when the lunch area refuses either of them at the school, the role is the only
 * thing that can have refused: not a capability that happens to be off, and not an
 * empty board.
 *
 * Build it in setUp, before the first request: TenantContext is scoped to the app,
 * so after a request it is still bound and its `creating` hook would stamp the next
 * fixture with that organisation's id.
 */
trait OneLoginWorld
{
    protected Masjid $masjid;

    protected Masjid $school;

    /** An organisation with lunch on and a class, where neither of them holds a row. */
    protected Masjid $elsewhere;

    protected User $masjidAdmin;

    protected User $schoolAdmin;

    /** The class both of them lead, and one at the same school that neither leads. */
    protected Group $class;

    protected Group $otherClass;

    protected MealMenu $masjidMenu;

    protected MealMenu $schoolMenu;

    protected User $shamina;

    protected User $mirror;

    protected function buildOneLoginWorld(): void
    {
        $this->masjid = $this->makeOrganisation('Burlington Masjid', 'masjid');
        $this->school = $this->makeOrganisation('Al-Razi School', 'school', ['jummah_lunch' => true]);
        $this->elsewhere = $this->makeOrganisation('Elsewhere School', 'school', ['jummah_lunch' => true]);

        $this->masjidAdmin = $this->makeOwnerOf($this->masjid);
        $this->schoolAdmin = $this->makeOwnerOf($this->school);

        $this->class = $this->makeClass($this->school, 'Library');
        $this->otherClass = $this->makeClass($this->school, 'Grade 5');

        $this->masjidMenu = MealMenu::factory()->forMasjid($this->masjid)->open()->create();
        $this->schoolMenu = MealMenu::factory()->forMasjid($this->school)->open()->create();

        $this->shamina = $this->makeLogin(User::TYPE_LUNCH_STAFF, 'Shamina Lunch First');
        $this->giveRole($this->shamina, $this->masjid, 'lunch-staff', true);
        $this->giveRole($this->shamina, $this->school, 'teacher', false);
        $this->lead($this->shamina, $this->class);

        $this->mirror = $this->makeLogin('Teacher', 'Mirror Teacher First');
        $this->giveRole($this->mirror, $this->school, 'teacher', true);
        $this->giveRole($this->mirror, $this->masjid, 'lunch-staff', false);
        $this->lead($this->mirror, $this->class);

        app(TenantContext::class)->forgetTenant();
    }

    /** @return array<string, array{0: string}> every test that must hold for both of them */
    public static function bothPeople(): array
    {
        return [
            'created as lunch staff (the real case)' => ['shamina'],
            'created as a teacher (the mirror)' => ['mirror'],
        ];
    }

    /**
     * Sign the request in as this person, on a model READ BACK FROM THE TABLE.
     *
     * The guard builds a fresh model from a token on every real request. Reusing one
     * instance across the requests of a test would let a kind set by one request be
     * read by the next, which no browser can do; ActingKindTest covers the reused
     * instance on its own.
     */
    protected function actingAsPerson(User|string $person): User
    {
        $id = $person instanceof User ? $person->id : $this->{$person}->id;
        $fresh = User::findOrFail($id);

        Sanctum::actingAs($fresh, ['staff']);

        return $fresh;
    }

    /**
     * A request carrying a real bearer token, resolved by the guard as a browser's is.
     *
     * The guards are forgotten first: a guard that already resolved a user in this
     * test answers with that user again, whatever token the next request carries.
     */
    protected function withBearer(string $token): static
    {
        Auth::forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    protected function teacherBase(Masjid $organisation): string
    {
        return "/api/teacher/masjids/{$organisation->id}";
    }

    protected function lunchBase(Masjid $organisation): string
    {
        return "/api/lunch/masjids/{$organisation->id}";
    }

    protected function adminBase(Masjid $organisation): string
    {
        return "/api/admin/masjids/{$organisation->id}";
    }

    /** The gate's refusal, exactly: 401 and the legacy envelope. */
    protected function assertGateRefused(TestResponse $response, string $what = ''): void
    {
        $this->assertSame(401, $response->getStatusCode(), "{$what} was not refused by the area's gate");
        $this->assertSame(['status' => 'failed', 'data' => 'Unauthorized.'], $response->json(), $what);
    }

    protected function makeOrganisation(string $name, string $orgType, array $capabilities = []): Masjid
    {
        $organisation = Masjid::create([
            'name' => $name,
            'email' => 'o'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'crm_enabled' => true, 'org_type' => $orgType,
        ]);

        if ($capabilities !== []) {
            $organisation->forceFill(['capability_overrides' => $capabilities])->save();
        }

        return $organisation;
    }

    protected function makeOwnerOf(Masjid $organisation): User
    {
        $admin = $this->makeLogin('MasjidAdmin', 'Office of '.$organisation->name);
        $organisation->user_id = $admin->id;
        $organisation->save();
        MasjidUser::ensureOwnerMembership((int) $organisation->id, (int) $admin->id);

        return $admin;
    }

    protected function makeLogin(string $type, string $name): User
    {
        return User::factory()->create([
            'type' => $type,
            'name' => $name,
            'phone' => '+1'.random_int(1000000000, 9999999999),
        ]);
    }

    protected function makeClass(Masjid $school, string $name): Group
    {
        return Group::factory()->create([
            'masjid_id' => $school->id, 'kind' => Group::KIND_CLASS,
            'name' => $name, 'slug' => strtolower(str_replace(' ', '-', $name)).'-'.$school->id,
        ]);
    }

    protected function giveRole(User $user, Masjid $organisation, string $role, bool $default): MasjidUser
    {
        return MasjidUser::create([
            'masjid_id' => $organisation->id,
            'user_id' => $user->id,
            'role' => $role,
            'is_default' => $default,
        ]);
    }

    protected function lead(User $user, Group $class, array $extra = []): void
    {
        $class->staff()->attach($user->id, [
            'masjid_id' => $class->masjid_id, 'role' => GroupStaff::ROLE_TEACHER, 'assigned_at' => now(),
        ] + $extra);
    }
}
