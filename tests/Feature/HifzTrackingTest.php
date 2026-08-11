<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\HifzEntry;
use App\Models\Masjid;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Ḥifẓ tracking over HTTP (PLAN T-014):
 * /api/admin/masjids/{id}/groups/{id}/hifz
 * /api/admin/masjids/{id}/groups/{id}/members/{id}/hifz[/progress]
 *
 * Mirrors GroupFeedTest / BehaviorAwardsTest for tenancy — B's id under A's own
 * route is a 404, B's masjid in the route is a 403 — and pins the two things
 * this slice exists for:
 *
 *   - THE DOMAIN. Only sabak advances a student; sabqi and manzil are revision
 *     of memorisation already held. Progress is a POSITION in the muṣḥaf (surah,
 *     ayah, juz), never a percentage, and juz completion is coverage rather than
 *     a linear reading — the case that matters because ḥifẓ is commonly
 *     memorised from juz 30 backwards.
 *   - THE PRIVACY RULE, unchanged from T-013. A child's memorisation record
 *     reaches the ḥalaqa's leaders, the student, and THAT student's own
 *     guardians. ANOTHER GUARDIAN IN THE SAME ḤALAQA IS REFUSED — at the
 *     endpoint (403) AND at the listing-query level, so a forbidden entry never
 *     appears in a page, a paginator total, or a progress aggregate.
 *
 * There is deliberately no group-wide progress or "top memorisers" endpoint to
 * test, because there is none to build.
 *
 * The acting person resolves as in the feed: the admin's tenant Contact with the
 * same login email — see App\Support\GroupAudience.
 */
class HifzTrackingTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjidA;
    private Masjid $masjidB;
    private User $adminA;

    private Group $groupA;
    private Group $groupB;

    /** The Contact that IS adminA, in masjid A. Not in any group by default. */
    private Contact $personA;

    protected function setUp(): void
    {
        parent::setUp();

        // Force sqlite-in-memory regardless of phpunit.xml.
        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        // Ḥifẓ reuses the CONTACTS permissions (see routes/admin.php), so the
        // bridged masjid-admin role must be seeded before the admins exist.
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->masjidA = $this->makeMasjid();
        $this->masjidB = $this->makeMasjid();

        $this->adminA = $this->makeAdminFor($this->masjidA);
        $this->makeAdminFor($this->masjidB);

        $this->groupA = Group::factory()->create([
            'masjid_id' => $this->masjidA->id,
            'name' => 'Halaqa 1',
            'slug' => 'halaqa-1',
            'kind' => Group::KIND_HALAQA,
        ]);
        $this->groupB = Group::factory()->create([
            'masjid_id' => $this->masjidB->id,
            'name' => 'Halaqa 1',
            'slug' => 'halaqa-1',
            'kind' => Group::KIND_HALAQA,
        ]);

        $this->personA = Contact::factory()->create([
            'masjid_id' => $this->masjidA->id,
            'email' => $this->adminA->email,
        ]);
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
            // The module lives inside the CRM route group, gated by crm_enabled.
            'crm_enabled' => true,
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

    // ---------------------------------------------------------------- helpers

    private function hifzUrl(?Group $group = null, ?Masjid $masjid = null): string
    {
        return '/api/admin/masjids/' . ($masjid ?? $this->masjidA)->id
            . '/groups/' . ($group ?? $this->groupA)->id . '/hifz';
    }

    private function memberHifzUrl(GroupMembership $membership, ?Group $group = null, ?Masjid $masjid = null): string
    {
        return '/api/admin/masjids/' . ($masjid ?? $this->masjidA)->id
            . '/groups/' . ($group ?? $this->groupA)->id
            . '/members/' . $membership->id . '/hifz';
    }

    /** Put a contact in a group directly (unbound), as the roster endpoints would. */
    private function seedMembership(
        Masjid $masjid,
        Group $group,
        Contact $contact,
        string $role,
        ?Contact $ward = null,
        ?string $consentScope = null
    ): GroupMembership {
        return GroupMembership::create([
            'masjid_id' => $masjid->id,
            'group_id' => $group->id,
            'contact_id' => $contact->id,
            'role' => $role,
            'guardian_of_contact_id' => $ward?->id,
            'consent_granted_at' => $consentScope === null ? null : now(),
            'consent_scope' => $consentScope,
        ]);
    }

    /** A child (email-less, so identity can never collide) who is a member of ḥalaqa A. */
    private function seedChildInGroupA(): array
    {
        $child = Contact::factory()->create(['masjid_id' => $this->masjidA->id, 'email' => null]);
        $membership = $this->seedMembership($this->masjidA, $this->groupA, $child, GroupMembership::ROLE_MEMBER);

        return [$child, $membership];
    }

    /** Make adminA's person a guardian of a (new) child in ḥalaqa A. */
    private function makeAdminAGuardian(?string $consentScope = null): array
    {
        [$child, $childMembership] = $this->seedChildInGroupA();

        $this->seedMembership(
            $this->masjidA, $this->groupA, $this->personA, GroupMembership::ROLE_GUARDIAN, $child, $consentScope
        );

        return [$child, $childMembership];
    }

    /** An entry written straight to the DB (unbound), as the endpoint would create it. */
    private function seedEntry(GroupMembership $student, array $overrides = []): HifzEntry
    {
        return HifzEntry::factory()->create(array_merge([
            'masjid_id' => $student->masjid_id,
            'group_id' => $student->group_id,
            'group_membership_id' => $student->id,
        ], $overrides));
    }

    /** A well-formed store payload: an-Naba 1-10 as today's sabak. */
    private function payload(GroupMembership $student, array $overrides = []): array
    {
        return array_merge([
            'membership_id' => $student->id,
            'kind' => HifzEntry::KIND_SABAK,
            'from_surah' => 78,
            'from_ayah' => 1,
            'to_surah' => 78,
            'to_ayah' => 10,
            'quality' => HifzEntry::QUALITY_GOOD,
        ], $overrides);
    }

    // ------------------------------------------------------------------ auth

    #[Test]
    public function the_module_rejects_unauthenticated_requests(): void
    {
        $this->getJson($this->hifzUrl())->assertStatus(401);
        $this->postJson($this->hifzUrl(), [])->assertStatus(401);
    }

    // -------------------------------------------------- recording a recitation

    #[Test]
    public function a_teacher_records_a_recitation(): void
    {
        $this->seedMembership($this->masjidA, $this->groupA, $this->personA, GroupMembership::ROLE_LEADER);
        [, $student] = $this->seedChildInGroupA();

        Sanctum::actingAs($this->adminA);

        $response = $this->postJson($this->hifzUrl(), $this->payload($student, [
            'major_mistakes' => 1,
            'minor_mistakes' => 3,
            'note' => 'Struggled with the waqf on ayah 8.',
        ]))->assertStatus(201);

        $this->assertDatabaseHas('hifz_entries', [
            'id' => $response->json('data.id'),
            // Stamped from the bound tenant, never from client input.
            'masjid_id' => $this->masjidA->id,
            'group_id' => $this->groupA->id,
            'group_membership_id' => $student->id,
            'kind' => HifzEntry::KIND_SABAK,
            'from_surah' => 78,
            'from_ayah' => 1,
            'to_surah' => 78,
            'to_ayah' => 10,
            'quality' => HifzEntry::QUALITY_GOOD,
            'major_mistakes' => 1,
            'minor_mistakes' => 3,
            // The ACCOUNT that heard it, never a client claim.
            'heard_by_user_id' => $this->adminA->id,
        ]);

        // The range comes back DESCRIBED, so no client has to carry its own
        // copy of the muṣḥaf to render it — and the juz is derived, which is
        // why no juz column exists to disagree with it.
        $response->assertJsonPath('data.from.surah_name', 'An-Naba')
            ->assertJsonPath('data.to.juz', 30)
            ->assertJsonPath('data.ayahs', 10);
    }

    #[Test]
    public function a_recitation_range_may_cross_surahs(): void
    {
        // "Revise juz 26" is 46:1 .. 51:30 — a range that cannot be said with a
        // single surah column, which is why the schema has two.
        [, $student] = $this->seedChildInGroupA();

        Sanctum::actingAs($this->adminA);

        $this->postJson($this->hifzUrl(), $this->payload($student, [
            'kind' => HifzEntry::KIND_MANZIL,
            'from_surah' => 46, 'from_ayah' => 1,
            'to_surah' => 51, 'to_ayah' => 30,
        ]))->assertStatus(201)->assertJsonPath('data.from.juz', 26);
    }

    #[Test]
    public function an_ayah_that_does_not_exist_is_refused(): void
    {
        [, $student] = $this->seedChildInGroupA();

        Sanctum::actingAs($this->adminA);

        // Al-Fātiḥah has seven ayahs. Nothing declarative can say that; the
        // muṣḥaf itself has to.
        $this->postJson($this->hifzUrl(), $this->payload($student, [
            'from_surah' => 1, 'from_ayah' => 1, 'to_surah' => 1, 'to_ayah' => 300,
        ]))->assertStatus(422);

        // Nor is there a surah 115.
        $this->postJson($this->hifzUrl(), $this->payload($student, [
            'from_surah' => 115, 'from_ayah' => 1, 'to_surah' => 115, 'to_ayah' => 2,
        ]))->assertStatus(422);

        $this->assertSame(0, HifzEntry::withoutMasjidScope()->count());
    }

    #[Test]
    public function a_range_typed_in_backwards_is_refused(): void
    {
        [, $student] = $this->seedChildInGroupA();

        Sanctum::actingAs($this->adminA);

        // Same surah, reversed ayahs.
        $this->postJson($this->hifzUrl(), $this->payload($student, [
            'from_surah' => 2, 'from_ayah' => 100, 'to_surah' => 2, 'to_ayah' => 10,
        ]))->assertStatus(422);

        // And across surahs: 5:82 comes after 2:255, so this runs backwards.
        $this->postJson($this->hifzUrl(), $this->payload($student, [
            'from_surah' => 5, 'from_ayah' => 82, 'to_surah' => 2, 'to_ayah' => 255,
        ]))->assertStatus(422);

        $this->assertSame(0, HifzEntry::withoutMasjidScope()->count());
    }

    #[Test]
    public function a_recitation_needs_a_recognized_kind_and_quality(): void
    {
        [, $student] = $this->seedChildInGroupA();

        Sanctum::actingAs($this->adminA);

        $this->postJson($this->hifzUrl(), [])->assertStatus(422);

        $this->postJson($this->hifzUrl(), $this->payload($student, ['kind' => 'revision']))
            ->assertStatus(422);

        $this->postJson($this->hifzUrl(), $this->payload($student, ['quality' => 'brilliant']))
            ->assertStatus(422);
    }

    #[Test]
    public function a_recitation_cannot_be_dated_in_the_future(): void
    {
        // A future entry would also sit at the head of the sabak history the
        // current position is derived from.
        [, $student] = $this->seedChildInGroupA();

        Sanctum::actingAs($this->adminA);

        $this->postJson($this->hifzUrl(), $this->payload($student, [
            'recited_at' => now()->addWeek()->toIso8601String(),
        ]))->assertStatus(422);
    }

    #[Test]
    public function a_recitation_must_name_a_participant_of_this_halaqa(): void
    {
        // adminA is a guardian, so a guardian EDGE id is available to try.
        [$child] = $this->makeAdminAGuardian();
        $edge = GroupMembership::where('contact_id', $this->personA->id)->firstOrFail();

        // A membership from ANOTHER organization's group.
        $foreignContact = Contact::factory()->create(['masjid_id' => $this->masjidB->id, 'email' => null]);
        $foreignMembership = $this->seedMembership(
            $this->masjidB, $this->groupB, $foreignContact, GroupMembership::ROLE_MEMBER
        );

        Sanctum::actingAs($this->adminA);

        // A guardian edge names a relationship, not a person who recites.
        $this->postJson($this->hifzUrl(), $this->payload($edge))->assertStatus(422);

        // A foreign membership id is invisible to the scoped lookup — refused
        // the same way, revealing nothing about whether it exists.
        $this->postJson($this->hifzUrl(), $this->payload($foreignMembership))->assertStatus(422);

        $this->assertSame(0, HifzEntry::withoutMasjidScope()->count());
        $this->assertNotNull($child);
    }

    #[Test]
    public function an_admin_not_in_the_halaqa_can_record_but_not_read_it_back(): void
    {
        // The feed's documented read/write asymmetry, mirrored: hearing a
        // recitation is teaching, reading the record is disclosure.
        [, $student] = $this->seedChildInGroupA();

        Sanctum::actingAs($this->adminA);

        $this->postJson($this->hifzUrl(), $this->payload($student))->assertStatus(201);

        $this->getJson($this->hifzUrl())->assertStatus(403);
    }

    // ------------------------------------------------------- WHO MAY READ IT

    #[Test]
    public function a_leader_reads_every_entry_in_the_halaqa(): void
    {
        $this->seedMembership($this->masjidA, $this->groupA, $this->personA, GroupMembership::ROLE_LEADER);
        [, $first] = $this->seedChildInGroupA();
        [, $second] = $this->seedChildInGroupA();
        $this->seedEntry($first);
        $this->seedEntry($second);

        Sanctum::actingAs($this->adminA);

        $this->getJson($this->hifzUrl())->assertOk()->assertJsonPath('data.total', 2);
        $this->getJson($this->memberHifzUrl($second))->assertOk()->assertJsonPath('data.total', 1);
    }

    #[Test]
    public function a_student_reads_only_their_own_record(): void
    {
        $mine = $this->seedMembership(
            $this->masjidA, $this->groupA, $this->personA, GroupMembership::ROLE_MEMBER
        );
        [, $someoneElse] = $this->seedChildInGroupA();

        $own = $this->seedEntry($mine);
        $this->seedEntry($someoneElse);

        Sanctum::actingAs($this->adminA);

        $response = $this->getJson($this->hifzUrl())->assertOk();

        $this->assertSame(1, $response->json('data.total'));
        $this->assertSame($own->id, $response->json('data.data.0.id'));

        $this->getJson($this->memberHifzUrl($someoneElse))->assertStatus(403);
    }

    #[Test]
    public function a_guardian_reads_their_own_wards_record_without_any_consent_on_file(): void
    {
        // Consent gates BROADCASTS of a child's data to the guardian audience.
        // A parent reading their own child's memorisation record is not a
        // broadcast — the same call T-005c and T-013 made.
        [, $ward] = $this->makeAdminAGuardian();
        $entry = $this->seedEntry($ward);

        Sanctum::actingAs($this->adminA);

        $this->getJson($this->memberHifzUrl($ward))
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.id', $entry->id);

        $this->getJson($this->memberHifzUrl($ward) . '/progress')->assertOk();
    }

    #[Test]
    public function another_guardian_is_refused_a_different_childs_record(): void
    {
        // THE test this slice inherits from T-013. adminA's person is a guardian
        // in this ḥalaqa — with full media consent, even — but of a DIFFERENT
        // child. "Guardian here" never meant "guardian of this child".
        $this->makeAdminAGuardian(GroupMembership::CONSENT_MEDIA);

        // A second family: another child in the same ḥalaqa, another parent.
        [, $otherChild] = $this->seedChildInGroupA();
        $this->seedEntry($otherChild);

        Sanctum::actingAs($this->adminA);

        $this->getJson($this->memberHifzUrl($otherChild))->assertStatus(403);
        $this->getJson($this->memberHifzUrl($otherChild) . '/progress')->assertStatus(403);
    }

    #[Test]
    public function another_guardians_entries_are_absent_from_the_listing_query(): void
    {
        // The second half of the guarantee: refusing the dedicated endpoint is
        // not enough if the ḥalaqa listing still carries the row. A forbidden
        // entry must never be FETCHED — not in a page, not in a paginator total,
        // not through a membership_id filter aimed at another family.
        [, $ward] = $this->makeAdminAGuardian(GroupMembership::CONSENT_MEDIA);
        [, $otherChild] = $this->seedChildInGroupA();

        $mine = $this->seedEntry($ward, ['from_surah' => 78, 'from_ayah' => 1, 'to_surah' => 78, 'to_ayah' => 5]);
        $theirs = $this->seedEntry($otherChild, [
            'from_surah' => 2, 'from_ayah' => 1, 'to_surah' => 2, 'to_ayah' => 5,
        ]);

        Sanctum::actingAs($this->adminA);

        $response = $this->getJson($this->hifzUrl())->assertOk();

        $this->assertSame(1, $response->json('data.total'));
        $this->assertSame($mine->id, $response->json('data.data.0.id'));
        // Nothing of the other family's child, not even the surah they recited.
        $response->assertJsonMissing(['surah_name' => 'Al-Baqarah']);

        // Aiming the filter at the other family yields nothing rather than a
        // leak: the filter narrows an already-constrained query.
        $filtered = $this->getJson($this->hifzUrl() . '?membership_id=' . $otherChild->id)->assertOk();

        $this->assertSame(0, $filtered->json('data.total'));
        $this->assertNotNull($theirs->id);
    }

    #[Test]
    public function an_admin_with_no_standing_in_the_halaqa_is_refused_the_listing(): void
    {
        [, $student] = $this->seedChildInGroupA();
        $this->seedEntry($student);

        Sanctum::actingAs($this->adminA);

        // `view contacts` is held by every masjid admin; it is necessary but
        // deliberately not sufficient for a group-scoped read.
        $this->getJson($this->hifzUrl())->assertStatus(403);
        $this->getJson($this->memberHifzUrl($student))->assertStatus(403);
        $this->getJson($this->memberHifzUrl($student) . '/progress')->assertStatus(403);
    }

    // -------------------------------------------------------------- filters

    #[Test]
    public function the_kind_filter_narrows_a_listing_to_one_part_of_the_cycle(): void
    {
        $this->seedMembership($this->masjidA, $this->groupA, $this->personA, GroupMembership::ROLE_LEADER);
        [, $student] = $this->seedChildInGroupA();

        $sabak = $this->seedEntry($student, ['kind' => HifzEntry::KIND_SABAK]);
        $this->seedEntry($student, ['kind' => HifzEntry::KIND_MANZIL]);

        Sanctum::actingAs($this->adminA);

        $response = $this->getJson($this->hifzUrl() . '?kind=' . HifzEntry::KIND_SABAK)->assertOk();

        $this->assertSame(1, $response->json('data.total'));
        $this->assertSame($sabak->id, $response->json('data.data.0.id'));

        // An unrecognized kind matches NOTHING rather than everything — a typo
        // must not silently widen a listing about children.
        $this->getJson($this->hifzUrl() . '?kind=revision')
            ->assertOk()
            ->assertJsonPath('data.total', 0);
    }

    #[Test]
    public function the_date_range_filter_narrows_a_listing_by_when_it_was_recited(): void
    {
        $this->seedMembership($this->masjidA, $this->groupA, $this->personA, GroupMembership::ROLE_LEADER);
        [, $student] = $this->seedChildInGroupA();

        $old = $this->seedEntry($student, ['recited_at' => now()->subMonths(2)]);
        $recent = $this->seedEntry($student, ['recited_at' => now()->subDay()]);

        Sanctum::actingAs($this->adminA);

        $response = $this->getJson(
            $this->hifzUrl() . '?from=' . now()->subWeek()->toDateString()
        )->assertOk();

        $this->assertSame(1, $response->json('data.total'));
        $this->assertSame($recent->id, $response->json('data.data.0.id'));

        $earlier = $this->getJson(
            $this->hifzUrl() . '?to=' . now()->subMonth()->toDateString()
        )->assertOk();

        $this->assertSame(1, $earlier->json('data.total'));
        $this->assertSame($old->id, $earlier->json('data.data.0.id'));
    }

    // ------------------------------------------------------------- progress

    #[Test]
    public function the_progress_report_places_the_student_from_their_sabak_alone(): void
    {
        $this->seedMembership($this->masjidA, $this->groupA, $this->personA, GroupMembership::ROLE_LEADER);
        [, $student] = $this->seedChildInGroupA();

        $this->seedEntry($student, [
            'kind' => HifzEntry::KIND_SABAK,
            'from_surah' => 78, 'from_ayah' => 1, 'to_surah' => 78, 'to_ayah' => 20,
            'recited_at' => now()->subDays(2),
        ]);

        // Revision of al-Baqarah, recorded LATER. It must not move the child to
        // al-Baqarah — the domain rule the whole report turns on.
        $this->seedEntry($student, [
            'kind' => HifzEntry::KIND_MANZIL,
            'from_surah' => 2, 'from_ayah' => 1, 'to_surah' => 2, 'to_ayah' => 100,
            'recited_at' => now(),
        ]);

        Sanctum::actingAs($this->adminA);

        $response = $this->getJson($this->memberHifzUrl($student) . '/progress')->assertOk();

        $response->assertJsonPath('data.current_position.surah', 78)
            ->assertJsonPath('data.current_position.surah_name', 'An-Naba')
            ->assertJsonPath('data.current_position.ayah', 20)
            ->assertJsonPath('data.current_position.juz', 30)
            ->assertJsonPath('data.memorized.ayahs', 20)
            ->assertJsonPath('data.totals.entries', 2)
            ->assertJsonPath('data.totals.by_kind.' . HifzEntry::KIND_SABAK, 1)
            ->assertJsonPath('data.totals.by_kind.' . HifzEntry::KIND_MANZIL, 1);

        // The revision block reports what was actually revised, as coverage.
        $response->assertJsonPath('data.revision.' . HifzEntry::KIND_MANZIL . '.entries', 1)
            ->assertJsonPath('data.revision.' . HifzEntry::KIND_MANZIL . '.ayahs', 100);

        // No percentage anywhere: progress in ḥifẓ is a position, not a score.
        $this->assertArrayNotHasKey('percent', $response->json('data.memorized'));
    }

    #[Test]
    public function the_progress_report_counts_completed_juz_by_coverage(): void
    {
        $this->seedMembership($this->masjidA, $this->groupA, $this->personA, GroupMembership::ROLE_LEADER);
        [, $student] = $this->seedChildInGroupA();

        // The whole of juz 30 — the way most children begin.
        $this->seedEntry($student, [
            'kind' => HifzEntry::KIND_SABAK,
            'from_surah' => 78, 'from_ayah' => 1, 'to_surah' => 114, 'to_ayah' => 6,
        ]);

        Sanctum::actingAs($this->adminA);

        $this->getJson($this->memberHifzUrl($student) . '/progress')
            ->assertOk()
            // One juz, not thirty: the child is at the END of the muṣḥaf but
            // holds a thirtieth of it.
            ->assertJsonPath('data.memorized.juz_completed', 1)
            ->assertJsonPath('data.memorized.juz_completed_list', [30])
            ->assertJsonPath('data.memorized.surahs_completed', 37);
    }

    #[Test]
    public function a_guardians_progress_report_ignores_every_other_child(): void
    {
        [, $ward] = $this->makeAdminAGuardian();
        [, $otherChild] = $this->seedChildInGroupA();

        $this->seedEntry($ward, [
            'kind' => HifzEntry::KIND_SABAK,
            'from_surah' => 114, 'from_ayah' => 1, 'to_surah' => 114, 'to_ayah' => 6,
        ]);
        $this->seedEntry($otherChild, [
            'kind' => HifzEntry::KIND_SABAK,
            'from_surah' => 2, 'from_ayah' => 1, 'to_surah' => 2, 'to_ayah' => 286,
        ]);

        Sanctum::actingAs($this->adminA);

        $this->getJson($this->memberHifzUrl($ward) . '/progress')
            ->assertOk()
            ->assertJsonPath('data.memorized.ayahs', 6)
            ->assertJsonPath('data.totals.entries', 1);
    }

    // ------------------------------------------------------------ correction

    #[Test]
    public function striking_a_mis_recorded_entry_removes_it_and_moves_the_position_back(): void
    {
        // Correction matters more here than anywhere else in the module: a wrong
        // sabak does not merely sit in a log, it moves the child's recorded
        // place in the muṣḥaf until it is struck.
        $this->seedMembership($this->masjidA, $this->groupA, $this->personA, GroupMembership::ROLE_LEADER);
        [, $student] = $this->seedChildInGroupA();

        $this->seedEntry($student, [
            'kind' => HifzEntry::KIND_SABAK,
            'from_surah' => 78, 'from_ayah' => 1, 'to_surah' => 78, 'to_ayah' => 20,
            'recited_at' => now()->subDay(),
        ]);
        $mistake = $this->seedEntry($student, [
            'kind' => HifzEntry::KIND_SABAK,
            'from_surah' => 79, 'from_ayah' => 1, 'to_surah' => 79, 'to_ayah' => 46,
            'recited_at' => now(),
        ]);

        Sanctum::actingAs($this->adminA);

        $this->getJson($this->memberHifzUrl($student) . '/progress')
            ->assertOk()
            ->assertJsonPath('data.current_position.surah', 79);

        $this->deleteJson($this->hifzUrl() . '/' . $mistake->id)->assertOk();

        $this->assertSoftDeleted('hifz_entries', ['id' => $mistake->id]);
        // A change to a child's academic record is itself accountable.
        $this->assertDatabaseHas('hifz_entries', [
            'id' => $mistake->id,
            'corrected_by_user_id' => $this->adminA->id,
        ]);

        $this->getJson($this->hifzUrl())->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson($this->memberHifzUrl($student) . '/progress')
            ->assertOk()
            ->assertJsonPath('data.current_position.surah', 78)
            ->assertJsonPath('data.current_position.ayah', 20)
            ->assertJsonPath('data.memorized.ayahs', 20);
    }

    #[Test]
    public function striking_an_entry_is_idempotent(): void
    {
        [, $student] = $this->seedChildInGroupA();
        $entry = $this->seedEntry($student);

        Sanctum::actingAs($this->adminA);

        $this->deleteJson($this->hifzUrl() . '/' . $entry->id)->assertOk();
        // The caller's intent is already true; saying so is not an error.
        $this->deleteJson($this->hifzUrl() . '/' . $entry->id)->assertOk();
    }

    // ------------------------------------------------------------- tenancy

    #[Test]
    public function another_organizations_halaqa_is_a_miss_and_its_route_is_a_refusal(): void
    {
        Sanctum::actingAs($this->adminA);

        // A's own route, B's group id: invisible to the bound tenant.
        $this->getJson($this->hifzUrl($this->groupB))->assertStatus(404);
        // B's masjid in the route: refused by ResolveMasjidTenant.
        $this->getJson($this->hifzUrl($this->groupB, $this->masjidB))->assertStatus(403);
    }

    #[Test]
    public function another_organizations_entry_cannot_be_struck(): void
    {
        $foreignContact = Contact::factory()->create(['masjid_id' => $this->masjidB->id, 'email' => null]);
        $foreignStudent = $this->seedMembership(
            $this->masjidB, $this->groupB, $foreignContact, GroupMembership::ROLE_MEMBER
        );
        $foreignEntry = $this->seedEntry($foreignStudent);

        Sanctum::actingAs($this->adminA);

        $this->deleteJson($this->hifzUrl() . '/' . $foreignEntry->id)->assertStatus(404);
        $this->assertDatabaseHas('hifz_entries', ['id' => $foreignEntry->id, 'deleted_at' => null]);
    }
}
