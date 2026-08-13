<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\FeePlan;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\HifzEntry;
use App\Models\Masjid;
use App\Models\Offering;
use App\Models\Registrant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * WHO IS A RETURNING CHILD? (defect A)
 *
 * ---------------------------------------------------------------------------
 * THE MEASURED FAILURE
 * ---------------------------------------------------------------------------
 *
 * `OfferingRegistrationsController` resolved every person on a public
 * registration by ADDRESS: `findByAddress()` returns the one live contact of the
 * tenant holding that address, and `createContact()` NULLS the address on a row
 * when another contact already holds it. Composed, exactly ONE person per
 * address could ever be re-found — the payer — because everybody else's row was
 * written with a null email and `findByAddress()` returns null for the empty
 * string.
 *
 * Measured before this file's fix, one family of three, three byte-identical
 * `POST /register`s across three seasons, payer `Nadia Haq
 * <household@example.test>`, registrants `Yusuf Haq` and `Maryam Haq`:
 *
 *     contact#1 Nadia Haq  household@example.test
 *     #2 Yusuf NULL  #3 Maryam NULL  #4 Yusuf NULL  #5 Maryam NULL
 *     #6 Yusuf NULL  #7 Maryam NULL
 *     TOTAL: 7 contacts for a family of 3;  roster rows in one group: 12
 *
 * Identical when the children carry no email at all, which is the normal school
 * shape — most children do not have an address, and the siblings who do share
 * the household mailbox. And the records split with the rows: term one's ḥifẓ
 * reads `An-Naba' 78:10, juz 30` while the duplicate reads nothing, so the
 * parent portal lists the same child three times, two of them with no
 * memorisation.
 *
 * ---------------------------------------------------------------------------
 * THE IDENTITY THIS FILE PINS
 * ---------------------------------------------------------------------------
 *
 * A child is identified by NAME WITHIN THE PAYER'S HOUSEHOLD, never by address
 * alone. The household is derived from rows the application already keeps:
 * the payer's own contact, plus every contact the payer already holds a
 * guardian edge over. See `OfferingRegistrationsController::householdOf()`.
 *
 * The two properties that have to hold together, and both are measured here:
 *
 *   - ONE CHILD ACROSS THREE SEASONS IS ONE PERSON. Yusuf resolves to the row
 *     the first season wrote, because that row is in Nadia's household and
 *     carries his name.
 *   - TWO SIBLINGS STAY TWO PEOPLE. Yusuf and Maryam are two names, so they are
 *     two rows, in the no-email shape and in the shared-mailbox shape alike.
 *
 * And the negative property, which is what keeps this from being R1–R4 again:
 * the household is the PAYER'S OWN, so naming a child who is not in it resolves
 * to nobody and writes a new row. A stranger cannot reach another family's
 * child through this door by typing their name.
 */
class HouseholdIdentityTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjid;

    private User $admin;

    private Group $grade3;

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

        app(TenantContext::class)->forgetTenant();

        $this->masjid = $this->makeMasjid();
        $this->admin = $this->makeAdminFor($this->masjid);

        $this->grade3 = Group::factory()->create([
            'masjid_id' => $this->masjid->id,
            'kind' => Group::KIND_CLASS,
            'name' => 'Grade 3',
        ]);
    }

    // ------------------------------------------- the measured reproduction

    #[Test]
    public function a_returning_family_attaches_to_the_same_records_season_after_season(): void
    {
        [$offering, $plan] = $this->makeOffering($this->grade3, 'quran-club');

        // Three byte-identical submissions, three seasons. The shared-mailbox
        // shape: both children are listed under the household address.
        for ($season = 0; $season < 3; $season++) {
            $this->registerHousehold($offering, $plan, [
                ['name' => 'Yusuf Haq', 'email' => 'household@example.test'],
                ['name' => 'Maryam Haq', 'email' => 'household@example.test'],
            ]);
        }

        // MEASURED BEFORE: 7. A family of three is three people.
        $this->assertSame(3, Contact::withoutMasjidScope()->count(), 'the directory grew a row per season');

        $names = Contact::withoutMasjidScope()
            ->orderBy('id')
            ->get()
            ->map(fn (Contact $c): string => trim($c->first_name . ' ' . $c->last_name))
            ->all();

        $this->assertSame(['Nadia Haq', 'Yusuf Haq', 'Maryam Haq'], $names);

        // MEASURED BEFORE: 12 roster rows in one group (3 members + 3 members
        // duplicated twice more, and the guardian edges with them). Now: two
        // participants and the two guardian edges over them.
        $rows = GroupMembership::withoutMasjidScope()->where('group_id', $this->grade3->id)->get();

        $this->assertCount(4, $rows, 'the roster grew a line per season');
        $this->assertSame(2, $rows->where('role', GroupMembership::ROLE_MEMBER)->count());
        $this->assertSame(2, $rows->where('role', GroupMembership::ROLE_GUARDIAN)->count());
    }

    #[Test]
    public function the_no_email_shape_is_the_same_person_too(): void
    {
        // The NORMAL school shape: children have no address of their own. Before
        // the fix this was identical to the shared-mailbox case — `findByAddress`
        // returns null for the empty string, so every season wrote a new row.
        [$offering, $plan] = $this->makeOffering($this->grade3, 'after-school');

        for ($season = 0; $season < 3; $season++) {
            $this->registerHousehold($offering, $plan, [
                ['name' => 'Yusuf Haq'],
                ['name' => 'Maryam Haq'],
            ]);
        }

        $this->assertSame(3, Contact::withoutMasjidScope()->count());
        $this->assertSame(
            2,
            GroupMembership::withoutMasjidScope()
                ->where('group_id', $this->grade3->id)
                ->where('role', GroupMembership::ROLE_MEMBER)
                ->count(),
        );
    }

    #[Test]
    public function two_siblings_stay_two_people(): void
    {
        // The property the address-only rule was protecting, and which the
        // household rule must not lose: a household mailbox is one address for
        // several humans.
        [$offering, $plan] = $this->makeOffering($this->grade3, 'siblings');

        $this->registerHousehold($offering, $plan, [
            ['name' => 'Yusuf Haq', 'email' => 'household@example.test'],
            ['name' => 'Maryam Haq', 'email' => 'household@example.test'],
        ]);

        $registration = \App\Models\Registration::withoutMasjidScope()->latest('id')->first();

        $wardIds = Registrant::withoutMasjidScope()
            ->where('registration_id', $registration->id)
            ->pluck('contact_id')
            ->all();

        $this->assertCount(2, array_unique($wardIds), 'the two siblings collapsed into one enrolment');
    }

    #[Test]
    public function the_childs_records_do_not_split_across_seasons(): void
    {
        // The damage the fork actually does, in the shape a parent sees it:
        // term one's ḥifẓ is on one row and term two enrols a different person,
        // so the portal lists the same child twice and one of them has never
        // memorised anything.
        [$offering, $plan] = $this->makeOffering($this->grade3, 'hifz-club');

        $this->registerHousehold($offering, $plan, [['name' => 'Yusuf Haq']]);

        $yusuf = Contact::withoutMasjidScope()->where('first_name', 'Yusuf')->firstOrFail();
        $membership = GroupMembership::withoutMasjidScope()
            ->where('group_id', $this->grade3->id)
            ->where('contact_id', $yusuf->id)
            ->where('role', GroupMembership::ROLE_MEMBER)
            ->firstOrFail();

        HifzEntry::factory()->create([
            'masjid_id' => $this->masjid->id,
            'group_id' => $this->grade3->id,
            'group_membership_id' => $membership->id,
        ]);

        // Season two, same two things typed.
        $this->registerHousehold($offering, $plan, [['name' => 'Yusuf Haq']]);

        $memberships = GroupMembership::withoutMasjidScope()
            ->where('group_id', $this->grade3->id)
            ->where('role', GroupMembership::ROLE_MEMBER)
            ->get();

        $this->assertCount(1, $memberships, 'season two enrolled a second Yusuf');
        $this->assertSame(1, $memberships->first()->hifzEntries()->count());
    }

    // --------------------------------- the household is the PAYER's own

    #[Test]
    public function a_stranger_cannot_reach_another_familys_child_by_naming_them(): void
    {
        // The household rule must not become a name-based re-run of the address
        // hole R1–R4 kept re-opening. Salma is a real child with a real guardian;
        // a stranger who types her name gets a NEW row, not hers.
        $salma = Contact::factory()->create([
            'masjid_id' => $this->masjid->id,
            'first_name' => 'Salma',
            'last_name' => 'Other',
            'email' => null,
        ]);

        $realParent = Contact::factory()->create([
            'masjid_id' => $this->masjid->id,
            'first_name' => 'Huda',
            'last_name' => 'Other',
            'email' => 'huda@example.test',
        ]);

        $this->seedMembership($this->grade3, $salma, GroupMembership::ROLE_MEMBER);
        $this->seedMembership($this->grade3, $realParent, GroupMembership::ROLE_GUARDIAN, $salma);

        [$offering, $plan] = $this->makeOffering($this->grade3, 'school-trip');

        $this->postJson("/api/v1/offerings/{$offering->slug}/register", [
            'fee_plan_id' => $plan->id,
            'payer' => ['name' => 'Bilal Attacker', 'email' => 'bilal@evil.test'],
            'registrants' => [['name' => 'Salma Other']],
            'data' => ['full_name' => 'Salma Other'],
        ], ['masjid-id' => (string) $this->masjid->id])->assertStatus(200);

        $registration = \App\Models\Registration::withoutMasjidScope()->latest('id')->first();
        $wardIds = Registrant::withoutMasjidScope()
            ->where('registration_id', $registration->id)
            ->pluck('contact_id')
            ->all();

        $this->assertNotContains(
            $salma->id,
            $wardIds,
            'a stranger typing a real child\'s name bound the registration to HER record',
        );
    }

    #[Test]
    public function the_household_is_read_from_confirmed_and_pending_edges_alike(): void
    {
        // A family's FIRST season writes self-asserted edges, and its second
        // season arrives before the office has worked the pending queue. If the
        // household only counted confirmed edges, the returning-family property
        // would be false for exactly the school this work is being done for.
        [$offering, $plan] = $this->makeOffering($this->grade3, 'unconfirmed-season');

        $this->registerHousehold($offering, $plan, [['name' => 'Yusuf Haq']]);

        $edge = GroupMembership::withoutMasjidScope()
            ->where('role', GroupMembership::ROLE_GUARDIAN)
            ->firstOrFail();

        $this->assertSame(GroupMembership::PROVENANCE_SELF_ASSERTED, $edge->provenance);

        $this->registerHousehold($offering, $plan, [['name' => 'Yusuf Haq']]);

        $this->assertSame(2, Contact::withoutMasjidScope()->count());
    }

    #[Test]
    public function matching_a_household_child_never_upgrades_the_claim(): void
    {
        // Attaching to an existing row is an IDENTITY decision and never an
        // authority one: the edge the second season writes is still a claim, and
        // an edge the office already confirmed is not downgraded by it.
        [$offering, $plan] = $this->makeOffering($this->grade3, 'authority');

        $this->registerHousehold($offering, $plan, [['name' => 'Yusuf Haq']]);

        $edge = GroupMembership::withoutMasjidScope()
            ->where('role', GroupMembership::ROLE_GUARDIAN)
            ->firstOrFail();

        $edge->confirmedByStaff($this->admin)->save();

        $this->registerHousehold($offering, $plan, [['name' => 'Yusuf Haq']]);

        $this->assertSame(
            GroupMembership::PROVENANCE_CONFIRMED,
            $edge->fresh()->provenance,
            'a second season downgraded an edge the office confirmed',
        );

        $this->assertSame(
            1,
            GroupMembership::withoutMasjidScope()->where('role', GroupMembership::ROLE_GUARDIAN)->count(),
        );
    }

    #[Test]
    public function a_household_child_who_later_gets_their_own_address_keeps_it(): void
    {
        // The office types an address onto the child's row (the repair
        // `createContact()`'s docblock promises). A later season that lists the
        // child under the household mailbox again must still be the same person,
        // and must not overwrite what the office typed.
        [$offering, $plan] = $this->makeOffering($this->grade3, 'own-address');

        $this->registerHousehold($offering, $plan, [['name' => 'Yusuf Haq']]);

        $yusuf = Contact::withoutMasjidScope()->where('first_name', 'Yusuf')->firstOrFail();
        $yusuf->email = 'yusuf.haq@school.test';
        $yusuf->save();

        $this->registerHousehold($offering, $plan, [
            ['name' => 'Yusuf Haq', 'email' => 'household@example.test'],
        ]);

        $this->assertSame(2, Contact::withoutMasjidScope()->count());
        $this->assertSame('yusuf.haq@school.test', $yusuf->fresh()->email);
    }

    #[Test]
    public function the_payer_is_still_matched_on_their_own_address(): void
    {
        // Unchanged and deliberately so: a payer asserts their OWN address, and
        // a parent who writes "Nadia H." one season and "Nadia Haq" the next is
        // one customer. The household rule sits UNDER this, not instead of it.
        [$offering, $plan] = $this->makeOffering($this->grade3, 'payer-name-drift');

        $this->registerHousehold($offering, $plan, [['name' => 'Yusuf Haq']]);

        $this->postJson("/api/v1/offerings/{$offering->slug}/register", [
            'fee_plan_id' => $plan->id,
            'payer' => ['name' => 'Nadia H.', 'email' => 'household@example.test'],
            'registrants' => [['name' => 'Yusuf Haq']],
            'data' => ['full_name' => 'Yusuf Haq'],
        ], ['masjid-id' => (string) $this->masjid->id])->assertStatus(200);

        $this->assertSame(2, Contact::withoutMasjidScope()->count());
    }

    #[Test]
    public function an_adult_registering_themselves_is_still_one_person(): void
    {
        // The documented shape the payer-first comparison exists for: an adult
        // fills the registrant row in with their own details.
        [$offering, $plan] = $this->makeOffering($this->grade3, 'adult-halaqa');

        $this->postJson("/api/v1/offerings/{$offering->slug}/register", [
            'fee_plan_id' => $plan->id,
            'payer' => ['name' => 'Nadia Haq', 'email' => 'household@example.test'],
            'registrants' => [['name' => 'Nadia Haq', 'email' => 'household@example.test']],
            'data' => ['full_name' => 'Nadia Haq'],
        ], ['masjid-id' => (string) $this->masjid->id])->assertStatus(200);

        $this->assertSame(1, Contact::withoutMasjidScope()->count());
        $this->assertSame(
            0,
            GroupMembership::withoutMasjidScope()->where('role', GroupMembership::ROLE_GUARDIAN)->count(),
            'the payer became their own guardian',
        );
    }

    #[Test]
    public function a_teachers_address_still_resolves_to_exactly_one_contact(): void
    {
        // R3's lockout, re-measured on this round's resolver. A staff caller is
        // bridged to a Contact by LOWER(email) and must resolve to EXACTLY ONE;
        // a public POST naming a teacher's address must not fork the directory
        // no matter what name it pairs with it.
        $teacher = Contact::factory()->create([
            'masjid_id' => $this->masjid->id,
            'first_name' => 'Amina',
            'last_name' => 'Teacher',
            'email' => 'amina@school.test',
        ]);

        [$offering, $plan] = $this->makeOffering($this->grade3, 'teacher-fork');

        $this->postJson("/api/v1/offerings/{$offering->slug}/register", [
            'fee_plan_id' => $plan->id,
            'payer' => ['name' => 'Bilal Attacker', 'email' => 'bilal@evil.test'],
            'registrants' => [['name' => 'Somebody Else', 'email' => 'amina@school.test']],
            'data' => ['full_name' => 'Somebody Else'],
        ], ['masjid-id' => (string) $this->masjid->id])->assertStatus(200);

        $this->assertSame(
            1,
            Contact::withoutMasjidScope()
                ->whereRaw('LOWER(TRIM(email)) = ?', ['amina@school.test'])
                ->count(),
            'one anonymous POST made the teacher\'s identity ambiguous',
        );

        $this->assertNotNull($teacher->fresh()->email);
    }

    // ============================================================== helpers

    private function registerHousehold(Offering $offering, FeePlan $plan, array $registrants): void
    {
        $this->postJson("/api/v1/offerings/{$offering->slug}/register", [
            'fee_plan_id' => $plan->id,
            'payer' => ['name' => 'Nadia Haq', 'email' => 'household@example.test'],
            'registrants' => $registrants,
            'data' => ['full_name' => 'Nadia Haq'],
        ], ['masjid-id' => (string) $this->masjid->id])->assertStatus(200);
    }

    private function makeMasjid(array $overrides = []): Masjid
    {
        return Masjid::create(array_merge([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'crm_enabled' => true,
            'stripe_account_id' => 'acct_TEST' . uniqid(),
            'stripe_charges_enabled' => true,
        ], $overrides));
    }

    private function makeAdminFor(Masjid $masjid): User
    {
        $admin = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);

        $masjid->user_id = $admin->id;
        $masjid->save();

        return $admin;
    }

    /** @return array{0: Offering, 1: FeePlan} */
    private function makeOffering(Group $group, string $slug): array
    {
        $offering = Offering::factory()
            ->forMasjid($this->masjid)
            ->withRoster($group)
            ->create(['slug' => $slug]);

        $plan = FeePlan::factory()->free()->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $offering->id,
        ]);

        return [$offering, $plan];
    }

    private function seedMembership(
        Group $group,
        Contact $contact,
        string $role,
        ?Contact $ward = null,
    ): GroupMembership {
        $membership = new GroupMembership([
            'masjid_id' => $this->masjid->id,
            'group_id' => $group->id,
            'contact_id' => $contact->id,
            'role' => $role,
            'guardian_of_contact_id' => $ward?->id,
        ]);

        $membership->confirmedByStaff($this->admin)->save();

        return $membership;
    }
}
