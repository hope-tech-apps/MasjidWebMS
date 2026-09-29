<?php

namespace Tests\Support;

use App\Models\BehaviorAward;
use App\Models\BehaviorSkill;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupStaff;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\Prize;
use App\Models\PrizeLedgerEntry;
use App\Models\User;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Sanctum;

/**
 * One school, one class, one teacher, two children with a parent each, and a second school
 * with the same shape: the fixture the Manara Bucks (class store) suites share, so each pins
 * the rule under test rather than re-deriving who is in the room.
 *
 * The using test's setUp calls `buildStoreSchools()`, and builds its own second tenant where a
 * file must prove one (TenantScopingCoverageTest looks for two `Masjid::create` sites in the
 * FILE that claims a cross-tenant test). The class store is OFF for a new school, exactly as
 * production has it; `storeOn()` switches it on the way a SuperAdmin would.
 *
 * Al-Razi's clock is America/New_York: 2026-10-04 is a Sunday, EDT until 2026-11-01.
 */
trait BuildsClassStoreFixture
{
    private const ZONE = 'America/New_York';

    private Masjid $school;
    private User $admin;
    private User $teacher;
    private Group $class;

    private Contact $amiraContact;
    private GroupMembership $amira;
    private Contact $amiraParent;
    private Contact $yusufContact;
    private GroupMembership $yusuf;
    private Contact $yusufParent;

    protected function useSqliteInMemory(): void
    {
        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
    }

    protected function buildStoreSchools(): void
    {
        $this->useSqliteInMemory();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->school = $this->newSchool('Al-Razi Test');
        $this->admin = User::factory()->create(['type' => 'MasjidAdmin', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
        $this->school->user_id = $this->admin->id;
        $this->school->save();

        $this->class = Group::factory()->create([
            'masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS, 'name' => 'Grade 3',
        ]);
        $this->teacher = $this->teacherOf($this->school, $this->class);

        $this->amiraContact = Contact::factory()->create(['masjid_id' => $this->school->id, 'email' => null, 'first_name' => 'Amira', 'last_name' => 'Yusuf']);
        $this->amira = $this->enrol($this->school, $this->class, $this->amiraContact);
        $this->yusufContact = Contact::factory()->create(['masjid_id' => $this->school->id, 'email' => null, 'first_name' => 'Yusuf', 'last_name' => 'Karimi']);
        $this->yusuf = $this->enrol($this->school, $this->class, $this->yusufContact);

        $this->amiraParent = $this->guardianOf($this->school, $this->class, $this->amiraContact);
        $this->yusufParent = $this->guardianOf($this->school, $this->class, $this->yusufContact);
    }

    protected function newSchool(string $name, string $type = 'school'): Masjid
    {
        return Masjid::create([
            'name' => $name.' '.uniqid(), 'email' => 'school-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'crm_enabled' => true, 'org_type' => $type,
            'timezone' => self::ZONE,
        ]);
    }

    /** Switch the class store on (or off) for a school, the way the capability switch stores it. */
    protected function storeOn(?Masjid $school = null, bool $on = true): void
    {
        $school ??= $this->school;
        $overrides = is_array($school->capability_overrides) ? $school->capability_overrides : [];
        $overrides['class_store'] = $on;
        $school->forceFill(['capability_overrides' => $overrides])->save();
    }

    protected function teacherOf(Masjid $school, ?Group $group): User
    {
        $teacher = User::factory()->create(['type' => 'Teacher', 'phone' => '+1'.random_int(1000000000, 9999999999)]);
        MasjidUser::create(['masjid_id' => $school->id, 'user_id' => $teacher->id, 'role' => 'teacher', 'is_default' => true]);

        if ($group !== null) {
            $group->staff()->attach($teacher->id, [
                'masjid_id' => $school->id, 'role' => GroupStaff::ROLE_TEACHER, 'assigned_at' => now(),
            ]);
        }

        return $teacher;
    }

    protected function enrol(Masjid $school, Group $group, Contact $contact, string $role = GroupMembership::ROLE_MEMBER): GroupMembership
    {
        return GroupMembership::create([
            'masjid_id' => $school->id, 'group_id' => $group->id, 'contact_id' => $contact->id, 'role' => $role,
        ]);
    }

    protected function guardianOf(Masjid $school, Group $group, Contact $ward): Contact
    {
        $parent = Contact::factory()->create(['masjid_id' => $school->id]);
        $parent->forceFill(['login_email' => 'parent-'.uniqid().'@test.local', 'login_enabled_at' => now()])->save();
        GroupMembership::create([
            'masjid_id' => $school->id, 'group_id' => $group->id, 'contact_id' => $parent->id,
            'role' => GroupMembership::ROLE_GUARDIAN, 'guardian_of_contact_id' => $ward->id,
        ]);

        return $parent;
    }

    /** A live award that HAPPENED at `$local` on the school's clock ('Y-m-d H:i'). */
    protected function awardAt(string $local, GroupMembership $m, int $points, string $polarity = BehaviorSkill::POLARITY_POSITIVE, ?Group $group = null): BehaviorAward
    {
        $group ??= $this->class;

        return BehaviorAward::factory()->create([
            'masjid_id' => $group->masjid_id, 'group_id' => $group->id,
            'group_membership_id' => $m->id, 'skill_label' => 'Kindness',
            'skill_polarity' => $polarity, 'points' => $points,
            'awarded_at' => CarbonImmutable::parse($local, self::ZONE)->utc(),
        ]);
    }

    /** Put bucks straight into a child's ledger (an `earned` row), without going through minting. */
    protected function credit(GroupMembership $m, int $bucks, ?string $weekStart = null): PrizeLedgerEntry
    {
        return PrizeLedgerEntry::create([
            'masjid_id' => $m->masjid_id, 'group_id' => $m->group_id, 'group_membership_id' => $m->id,
            'kind' => PrizeLedgerEntry::KIND_EARNED, 'amount' => $bucks,
            'week_start' => $weekStart, 'week_basis' => $weekStart !== null ? $bucks : null,
            'occurred_at' => now(),
        ]);
    }

    /** @param  array<string,mixed>  $attributes */
    protected function prize(array $attributes = [], ?Masjid $school = null): Prize
    {
        return Prize::create($attributes + [
            'masjid_id' => ($school ?? $this->school)->id, 'group_id' => null,
            'title' => 'Pencil '.uniqid(), 'cost_bucks' => 5, 'stock' => null, 'is_active' => true,
        ]);
    }

    protected function balanceOf(GroupMembership $m): int
    {
        return (int) PrizeLedgerEntry::withoutMasjidScope()->where('group_membership_id', $m->id)->sum('amount');
    }

    protected function freeze(string $local): void
    {
        $at = CarbonImmutable::parse($local, self::ZONE);
        CarbonImmutable::setTestNow($at);
        \Illuminate\Support\Carbon::setTestNow($at);
    }

    protected function thaw(): void
    {
        CarbonImmutable::setTestNow();
        \Illuminate\Support\Carbon::setTestNow();
    }

    protected function teacherUrl(string $path, ?Masjid $school = null, ?Group $group = null): string
    {
        return '/api/teacher/masjids/'.($school ?? $this->school)->id.'/groups/'.($group ?? $this->class)->id.$path;
    }

    protected function teacherSchoolUrl(string $path, ?Masjid $school = null): string
    {
        return '/api/teacher/masjids/'.($school ?? $this->school)->id.$path;
    }

    protected function adminUrl(string $path, ?Masjid $school = null): string
    {
        return '/api/admin/masjids/'.($school ?? $this->school)->id.$path;
    }

    protected function familyUrl(string $path, ?Masjid $school = null, ?Group $group = null): string
    {
        return '/api/family/masjids/'.($school ?? $this->school)->id.'/groups/'.($group ?? $this->class)->id.$path;
    }

    protected function actAs(User $user): void
    {
        // A parent's bearer header set by asParent() would otherwise ride along on every later
        // request of the test, and answer for the staff user.
        $this->flushHeaders();
        Auth::forgetGuards();
        app(TenantContext::class)->forgetTenant();
        Sanctum::actingAs($user, ['staff']);
    }

    protected function asParent(Contact $contact): static
    {
        $this->flushHeaders();
        Auth::forgetGuards();
        app(TenantContext::class)->forgetTenant();

        return $this->withHeader('Authorization', 'Bearer '.$contact->createFamilyToken()->plainTextToken);
    }
}
