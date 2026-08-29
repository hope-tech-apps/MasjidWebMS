<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\Masjid;
use App\Models\User;
use App\Support\ContactIdentity;
use App\Support\GroupAudience;
use App\Support\RosterClaimIdentity;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * THE EMPTY AND ABSENT SHAPES OF EVERY VALUE THIS AREA COMPARES.
 *
 * The round's defect was one comparison — `'' == ''` read as "the same person".
 * The fix is a type that cannot express it. What this file does is walk every
 * OTHER identity comparison in the area and put its absent shape on the record,
 * because "we fixed the one we found" is how the previous six rounds each
 * shipped the next one.
 *
 * Three surfaces are checked:
 *
 *   1. `ContactIdentity` itself — the empty, the whitespace, the null and the
 *      missing-contact shapes, in both directions.
 *   2. `GroupAudience::identitiesFor()` — the sentence `RosterMergeService`
 *      cites as its justification ("`LOWER(email)` and nothing else"). It was
 *      already correct; nothing measured it in the absent shape, and a
 *      justification nobody tests is a justification that can quietly stop
 *      being true.
 *   3. `RosterClaimIdentity` — whose `nameKey()` returns `''` for a row that
 *      renders no name at all. That one is a DIFFERENT answer to the same
 *      question and is correct: an empty key must make a row CONTESTED (fail
 *      closed into an individual decision), never uncontested.
 */
class ContactIdentityTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjid;

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

        $this->masjid = Masjid::create([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'crm_enabled' => true,
        ]);
    }

    // ======================================================= ContactIdentity

    #[Test]
    public function two_contacts_with_no_address_are_never_the_same_person(): void
    {
        $a = $this->contact('Aisha', 'Ahmed', null);
        $b = $this->contact('Aisha', 'Ahmed', null);

        $this->assertFalse(ContactIdentity::of($a)->isTheSamePersonAs(ContactIdentity::of($b)));
        $this->assertTrue(ContactIdentity::changed($a, $b));
    }

    #[Test]
    public function an_identity_with_no_address_is_not_even_the_same_person_as_itself(): void
    {
        // The property that makes the type safe rather than merely correct: a
        // question that cannot be answered has no `true` answer, not even the
        // reflexive one. A caller that reasons "well, it is obviously the same
        // ROW" gets `false` and re-opens the claim, which costs a click.
        $nobody = $this->contact('Aisha', 'Ahmed', null);

        $this->assertFalse(ContactIdentity::of($nobody)->isTheSamePersonAs(ContactIdentity::of($nobody)));
        $this->assertTrue(ContactIdentity::changed($nobody, $nobody));
    }

    #[Test]
    public function an_empty_string_and_a_whitespace_address_are_the_same_absence_as_null(): void
    {
        // The database allows `''` as readily as NULL — an import or a hand edit
        // writes one as easily as the other — and a rule that held for one and
        // not the other is this defect again with a different spelling.
        $nullAddress = $this->contact('A', 'One', null);
        $emptyAddress = $this->contact('A', 'Two', '');
        $spaces = $this->contact('A', 'Three', '   ');

        foreach ([$nullAddress, $emptyAddress, $spaces] as $left) {
            $this->assertFalse(ContactIdentity::of($left)->isResolvable());

            foreach ([$nullAddress, $emptyAddress, $spaces] as $right) {
                $this->assertTrue(
                    ContactIdentity::changed($left, $right),
                    'two absences compared equal',
                );
            }
        }
    }

    #[Test]
    public function an_absent_address_never_matches_a_real_one_in_either_direction(): void
    {
        $nobody = $this->contact('A', 'One', null);
        $somebody = $this->contact('B', 'Two', 'real@household.test');

        $this->assertTrue(ContactIdentity::changed($nobody, $somebody));
        $this->assertTrue(ContactIdentity::changed($somebody, $nobody));
    }

    #[Test]
    public function a_missing_contact_is_an_absence_and_not_a_match(): void
    {
        $this->assertFalse(ContactIdentity::of(null)->isResolvable());
        $this->assertTrue(ContactIdentity::changed(null, null));
        $this->assertTrue(ContactIdentity::changed(null, $this->contact('B', 'Two', 'real@x.test')));
    }

    #[Test]
    public function one_real_address_matches_itself_across_case_and_whitespace(): void
    {
        // The exemption the fix must NOT destroy. Production is utf8mb4_bin
        // (case-SENSITIVE) and the suite runs SQLite; whether a de-duplication
        // keeps somebody's authority must not depend on which one it is talking
        // to, nor on a trailing space an operator pasted in.
        $lower = $this->contact('A', 'One', 'khadija@household.test');
        $shouty = $this->contact('B', 'Two', '  KHADIJA@Household.TEST ');

        $this->assertTrue(ContactIdentity::of($lower)->isTheSamePersonAs(ContactIdentity::of($shouty)));
        $this->assertFalse(ContactIdentity::changed($lower, $shouty));
    }

    #[Test]
    public function two_different_real_addresses_are_two_people(): void
    {
        $this->assertTrue(ContactIdentity::changed(
            $this->contact('A', 'One', 'aisha@household.test'),
            $this->contact('B', 'Two', 'bilal@evil.test'),
        ));
    }

    #[Test]
    public function a_shared_phone_number_is_not_an_identity(): void
    {
        // A roster SCREEN falls back to a phone because an operator can ring it.
        // Nothing mints a credential against one and nothing resolves a
        // principal by one, so it cannot answer "would a credential reach the
        // same person" — and a household landline is shared by a whole family.
        $mother = $this->contact('Aisha', 'Ahmed', null, '+15551230000');
        $father = $this->contact('Omar', 'Ahmed', null, '+15551230000');

        $this->assertTrue(ContactIdentity::changed($mother, $father));
    }

    // ============================================ GroupAudience::identitiesFor

    #[Test]
    public function a_staff_principal_with_no_address_resolves_to_no_identity(): void
    {
        // The sentence RosterMergeService cites. It was already right; nothing
        // measured it, and an untested justification can quietly stop being one.
        $this->contact('Nobody', 'Atall', null);

        app(TenantContext::class)->set($this->masjid->id);

        $audience = app(GroupAudience::class);

        // MEASURED WHILE WRITING THIS: `users.email` is NOT NULL, so a stored
        // principal cannot carry a null address at all. `''` and whitespace can
        // be stored, and are the shapes that reach production.
        foreach (['', '   '] as $address) {
            $user = User::factory()->create([
                'type' => 'MasjidAdmin',
                'email' => $address,
                'phone' => '+1' . random_int(1000000000, 9999999999),
            ]);

            $this->assertSame(
                [],
                $audience->identitiesFor($user),
                'an address-less staff principal resolved to a person',
            );
        }

        // And the null shape through the code path rather than the table, since
        // the method casts before comparing and an unsaved or hydrated-partial
        // model is the way one reaches it.
        $unsaved = new User(['type' => 'MasjidAdmin']);
        $unsaved->email = null;

        $this->assertSame([], $audience->identitiesFor($unsaved));
    }

    #[Test]
    public function two_contacts_on_one_address_resolve_to_no_identity(): void
    {
        // Ambiguous identity is no identity — the other absent shape of the same
        // comparison, and the one that matters when a household shares a mailbox.
        $this->contact('Aisha', 'Ahmed', 'household@example.test');
        $this->contact('Omar', 'Ahmed', 'household@example.test');

        $user = User::factory()->create([
            'type' => 'MasjidAdmin',
            'email' => 'household@example.test',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);

        app(TenantContext::class)->set($this->masjid->id);

        $this->assertSame([], app(GroupAudience::class)->identitiesFor($user));
    }

    // ============================================ RosterClaimIdentity, the ward end

    #[Test]
    public function a_roster_row_that_renders_no_name_at_all_is_always_contested(): void
    {
        // THE SAME QUESTION, THE OPPOSITE SAFE ANSWER — and worth pinning
        // precisely because it looks like the bug being fixed. `nameKey()`
        // returns `''` for a row whose contact renders nothing, and rows sharing
        // that empty key are grouped together. An empty key must fail CLOSED
        // (every such row needs an individual decision), never collapse into
        // "one claimant, nothing to decide".
        $group = Group::factory()->create([
            'masjid_id' => $this->masjid->id,
            'kind' => Group::KIND_CLASS,
        ]);

        $child = $this->contact('Fatima', 'Ahmed', null);
        $nameless = $this->contact('', '', null);

        $childsRow = $this->membership($group, $child, GroupMembership::ROLE_MEMBER);
        $claim = $this->membership($group, $nameless, GroupMembership::ROLE_GUARDIAN, $child);
        $claim->selfAssertedFrom(null)->save();

        $roster = GroupMembership::withoutMasjidScope()
            ->whereIn('id', [$childsRow->id, $claim->id])
            ->with('contact:id,first_name,last_name,email,phone')
            ->get();

        $this->assertContains(
            (int) $claim->id,
            RosterClaimIdentity::contestedClaimIds($roster),
            'a row an operator can read nothing from swept through as uncontested',
        );
    }

    // ============================================================== helpers

    private function contact(string $first, string $last, ?string $email, ?string $phone = null): Contact
    {
        return Contact::factory()->create([
            'masjid_id' => $this->masjid->id,
            'first_name' => $first,
            'last_name' => $last,
            'email' => $email,
            'phone' => $phone,
        ]);
    }

    private function membership(
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

        $membership->save();

        return $membership;
    }
}
