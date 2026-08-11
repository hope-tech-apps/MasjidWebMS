<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\HifzEntry;
use App\Models\Masjid;
use App\Models\User;
use App\Support\HifzProgress;
use App\Support\QuranIndex;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The model-layer guarantees behind ḥifẓ tracking (PLAN T-014).
 *
 * MySQL has NO row-level security, so App\Models\Concerns\BelongsToMasjid is the
 * only thing keeping one organization's memorisation records out of another's
 * queries. .claude/rules/tenant-scoping.md makes a cross-tenant test mandatory
 * for every new tenant-scoped model; this is it for HifzEntry, binding
 * TenantContext directly the way ResolveMasjidTenant does per request.
 *
 * It also pins what the MODEL and the DERIVATION own rather than the HTTP
 * boundary, because those guarantees must hold for every caller — importers and
 * seeders included:
 *
 *   - ONLY SABAK ADVANCES A STUDENT. Revision entries never move the position,
 *     which is the domain rule the whole module turns on;
 *   - POSITION IS DERIVED, so striking a mis-recorded sabak moves the student
 *     BACK — the case a denormalised column would have got wrong;
 *   - CORRECTION is the soft delete, so a struck entry leaves every listing and
 *     every derivation through one mechanism;
 *   - DELETION follows the student: an entry cannot outlive the membership row
 *     its whole audience is derived from;
 *   - RETENTION IS DELIBERATELY ABSENT. `groups:purge-feed` must never touch
 *     this table — see .claude/rules/groups.md for why an academic record is
 *     bounded by the roster rather than by a clock.
 */
class HifzTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $tenant;

    private Masjid $masjidA;
    private Masjid $masjidB;

    private Group $groupA;
    private Group $groupB;

    protected function setUp(): void
    {
        parent::setUp();

        // Force sqlite-in-memory regardless of phpunit.xml — this suite must
        // never need a network DB to run.
        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->tenant = app(TenantContext::class);
        // Every test starts UNBOUND; each binds explicitly when it needs to.
        $this->tenant->forgetTenant();

        $this->masjidA = $this->makeMasjid();
        $this->masjidB = $this->makeMasjid();

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
        ], $overrides));
    }

    /** A student: a contact with a participant membership in the ḥalaqa. */
    private function makeStudent(Masjid $masjid, Group $group): GroupMembership
    {
        $contact = Contact::factory()->create(['masjid_id' => $masjid->id, 'email' => null]);

        return GroupMembership::create([
            'masjid_id' => $masjid->id,
            'group_id' => $group->id,
            'contact_id' => $contact->id,
            'role' => GroupMembership::ROLE_MEMBER,
        ]);
    }

    private function makeEntry(
        Masjid $masjid,
        Group $group,
        ?GroupMembership $student = null,
        array $overrides = []
    ): HifzEntry {
        $student ??= $this->makeStudent($masjid, $group);

        return HifzEntry::factory()->create(array_merge([
            'masjid_id' => $masjid->id,
            'group_id' => $group->id,
            'group_membership_id' => $student->id,
        ], $overrides));
    }

    /** The derivation, over one student's whole (unconstrained) history. */
    private function progressFor(GroupMembership $student, int $window = 30): array
    {
        return HifzProgress::summarize(
            HifzEntry::withoutMasjidScope()->where('group_membership_id', $student->id),
            $window
        );
    }

    // ---------- tenant scope ----------

    #[Test]
    public function the_scope_hides_another_organizations_hifz_entries(): void
    {
        $this->makeEntry($this->masjidA, $this->groupA);
        $foreign = $this->makeEntry($this->masjidB, $this->groupB);

        $this->tenant->set($this->masjidA->id);

        $this->assertSame(1, HifzEntry::count());
        $this->assertSame($this->masjidA->id, HifzEntry::first()->masjid_id);
        $this->assertNull(HifzEntry::find($foreign->id));
    }

    #[Test]
    public function the_creating_hook_stamps_the_bound_tenant_on_an_entry(): void
    {
        $student = $this->makeStudent($this->masjidA, $this->groupA);

        $this->tenant->set($this->masjidA->id);

        // A client-supplied masjid_id must lose to the bound tenant.
        $entry = HifzEntry::create([
            'masjid_id' => $this->masjidB->id,
            'group_id' => $this->groupA->id,
            'group_membership_id' => $student->id,
            'kind' => HifzEntry::KIND_SABAK,
            'from_surah' => 78,
            'from_ayah' => 1,
            'to_surah' => 78,
            'to_ayah' => 10,
            'quality' => HifzEntry::QUALITY_GOOD,
        ]);

        $this->assertSame($this->masjidA->id, $entry->masjid_id);
    }

    #[Test]
    public function without_masjid_scope_sees_every_organizations_entries(): void
    {
        $this->makeEntry($this->masjidA, $this->groupA);
        $this->makeEntry($this->masjidB, $this->groupB);

        $this->tenant->set($this->masjidA->id);

        $this->assertSame(2, HifzEntry::withoutMasjidScope()->count());
    }

    // ---------- what the model owns ----------

    #[Test]
    public function an_entry_defaults_its_recited_at_to_now(): void
    {
        // Every listing, every date filter and the position derivation read
        // recited_at, so it can never be null — the model, not the controller,
        // guarantees that.
        $entry = $this->makeEntry($this->masjidA, $this->groupA);

        $this->assertNotNull($entry->recited_at);
    }

    #[Test]
    public function an_unreadable_stored_kind_never_counts_as_new_memorization(): void
    {
        $entry = $this->makeEntry($this->masjidA, $this->groupA);
        $entry->forceFill(['kind' => 'sideways'])->saveQuietly();

        // Degrading to manzil is the safe direction: a corrupt string must not
        // advance a child's recorded position in the muṣḥaf.
        $this->assertSame(HifzEntry::KIND_MANZIL, $entry->fresh()->kind());
        $this->assertFalse($entry->fresh()->isSabak());
    }

    #[Test]
    public function an_unreadable_stored_quality_makes_the_smallest_claim(): void
    {
        $entry = $this->makeEntry($this->masjidA, $this->groupA);
        $entry->forceFill(['quality' => 'brilliant'])->saveQuietly();

        // Neither a distinction nobody awarded nor a failure nobody gave.
        $this->assertSame(HifzEntry::QUALITY_FAIR, $entry->fresh()->quality());
    }

    #[Test]
    public function an_entry_knows_its_span_and_refuses_a_corrupt_one(): void
    {
        $entry = $this->makeEntry($this->masjidA, $this->groupA, null, [
            'from_surah' => 78, 'from_ayah' => 1, 'to_surah' => 78, 'to_ayah' => 40,
        ]);

        $this->assertSame(40, $entry->ayahCount());
        $this->assertSame(
            [QuranIndex::ordinal(78, 1), QuranIndex::ordinal(78, 40)],
            $entry->span()
        );

        // A row that somehow holds an ayah outside its surah drops out of the
        // arithmetic entirely rather than contributing nonsense.
        $entry->forceFill(['to_ayah' => 900])->saveQuietly();

        $this->assertNull($entry->fresh()->span());
        $this->assertSame(0, $entry->fresh()->ayahCount());
    }

    // ---------- the derivation ----------

    #[Test]
    public function only_sabak_advances_the_students_position(): void
    {
        $student = $this->makeStudent($this->masjidA, $this->groupA);

        // The new lesson: an-Naba 1-20.
        $this->makeEntry($this->masjidA, $this->groupA, $student, [
            'kind' => HifzEntry::KIND_SABAK,
            'from_surah' => 78, 'from_ayah' => 1, 'to_surah' => 78, 'to_ayah' => 20,
            'recited_at' => now()->subDays(2),
        ]);

        // Revision of al-Baqarah, recorded LATER. It must not move the student
        // to al-Baqarah, and it must not count as memorised-through-sabak.
        $this->makeEntry($this->masjidA, $this->groupA, $student, [
            'kind' => HifzEntry::KIND_MANZIL,
            'from_surah' => 2, 'from_ayah' => 1, 'to_surah' => 2, 'to_ayah' => 100,
            'recited_at' => now(),
        ]);

        $progress = $this->progressFor($student);

        $this->assertSame(78, $progress['current_position']['surah']);
        $this->assertSame(20, $progress['current_position']['ayah']);
        $this->assertSame(30, $progress['current_position']['juz']);
        $this->assertSame(20, $progress['memorized']['ayahs']);
    }

    #[Test]
    public function the_current_position_is_the_latest_sabak_not_the_furthest(): void
    {
        $student = $this->makeStudent($this->masjidA, $this->groupA);

        $this->makeEntry($this->masjidA, $this->groupA, $student, [
            'kind' => HifzEntry::KIND_SABAK,
            'from_surah' => 78, 'from_ayah' => 1, 'to_surah' => 78, 'to_ayah' => 40,
            'recited_at' => now()->subWeek(),
        ]);

        // The teacher sends the child back over an earlier passage.
        $this->makeEntry($this->masjidA, $this->groupA, $student, [
            'kind' => HifzEntry::KIND_SABAK,
            'from_surah' => 78, 'from_ayah' => 1, 'to_surah' => 78, 'to_ayah' => 15,
            'recited_at' => now(),
        ]);

        $progress = $this->progressFor($student);

        // Where they ARE today...
        $this->assertSame(15, $progress['current_position']['ayah']);
        // ...and the high-water mark, which is a different fact.
        $this->assertSame(40, $progress['furthest_position']['ayah']);
        // Re-reciting an earlier passage adds no new memorisation.
        $this->assertSame(40, $progress['memorized']['ayahs']);
    }

    #[Test]
    public function juz_completion_is_coverage_so_memorizing_backwards_reports_the_truth(): void
    {
        $student = $this->makeStudent($this->masjidA, $this->groupA);

        // The whole of juz 30: an-Naba 1 through an-Nas 6.
        $this->makeEntry($this->masjidA, $this->groupA, $student, [
            'kind' => HifzEntry::KIND_SABAK,
            'from_surah' => 78, 'from_ayah' => 1, 'to_surah' => 114, 'to_ayah' => 6,
        ]);

        $progress = $this->progressFor($student);

        // One juz, not thirty — the child is at the END of the muṣḥaf but has
        // memorised a thirtieth of it.
        $this->assertSame(1, $progress['memorized']['juz_completed']);
        $this->assertSame([30], $progress['memorized']['juz_completed_list']);
        $this->assertSame(37, $progress['memorized']['surahs_completed']);
        $this->assertSame(QuranIndex::TOTAL_AYAHS, $progress['memorized']['total_ayahs']);
    }

    #[Test]
    public function overlapping_lessons_are_counted_once(): void
    {
        $student = $this->makeStudent($this->masjidA, $this->groupA);

        $this->makeEntry($this->masjidA, $this->groupA, $student, [
            'kind' => HifzEntry::KIND_SABAK,
            'from_surah' => 78, 'from_ayah' => 1, 'to_surah' => 78, 'to_ayah' => 10,
        ]);
        $this->makeEntry($this->masjidA, $this->groupA, $student, [
            'kind' => HifzEntry::KIND_SABAK,
            'from_surah' => 78, 'from_ayah' => 5, 'to_surah' => 78, 'to_ayah' => 20,
        ]);

        // Twenty ayahs memorised, not twenty-six.
        $this->assertSame(20, $this->progressFor($student)['memorized']['ayahs']);
    }

    #[Test]
    public function striking_a_mis_recorded_sabak_moves_the_student_back(): void
    {
        // THE case a denormalised position column would have got wrong.
        $student = $this->makeStudent($this->masjidA, $this->groupA);

        $this->makeEntry($this->masjidA, $this->groupA, $student, [
            'kind' => HifzEntry::KIND_SABAK,
            'from_surah' => 78, 'from_ayah' => 1, 'to_surah' => 78, 'to_ayah' => 20,
            'recited_at' => now()->subDay(),
        ]);

        // Entered against the wrong child, and much too far ahead.
        $mistake = $this->makeEntry($this->masjidA, $this->groupA, $student, [
            'kind' => HifzEntry::KIND_SABAK,
            'from_surah' => 79, 'from_ayah' => 1, 'to_surah' => 79, 'to_ayah' => 46,
            'recited_at' => now(),
        ]);

        $this->assertSame(79, $this->progressFor($student)['current_position']['surah']);

        $mistake->delete();

        $progress = $this->progressFor($student);

        $this->assertSame(78, $progress['current_position']['surah']);
        $this->assertSame(20, $progress['current_position']['ayah']);
        // And it leaves the totals through the same one mechanism.
        $this->assertSame(20, $progress['memorized']['ayahs']);
        $this->assertSame(1, $progress['totals']['entries']);
    }

    #[Test]
    public function revision_coverage_is_bounded_by_the_window_while_memorization_is_not(): void
    {
        $student = $this->makeStudent($this->masjidA, $this->groupA);

        $this->makeEntry($this->masjidA, $this->groupA, $student, [
            'kind' => HifzEntry::KIND_SABAK,
            'from_surah' => 78, 'from_ayah' => 1, 'to_surah' => 78, 'to_ayah' => 40,
            'recited_at' => now()->subYear(),
        ]);

        // Revised long ago — outside the window.
        $this->makeEntry($this->masjidA, $this->groupA, $student, [
            'kind' => HifzEntry::KIND_MANZIL,
            'from_surah' => 78, 'from_ayah' => 1, 'to_surah' => 78, 'to_ayah' => 40,
            'recited_at' => now()->subMonths(6),
        ]);

        // Revised this week — inside it.
        $this->makeEntry($this->masjidA, $this->groupA, $student, [
            'kind' => HifzEntry::KIND_SABQI,
            'from_surah' => 78, 'from_ayah' => 1, 'to_surah' => 78, 'to_ayah' => 20,
            'recited_at' => now()->subDays(3),
        ]);

        $progress = $this->progressFor($student, 30);

        $this->assertSame(30, $progress['revision']['window_days']);
        $this->assertSame(1, $progress['revision'][HifzEntry::KIND_SABQI]['entries']);
        $this->assertSame(20, $progress['revision'][HifzEntry::KIND_SABQI]['ayahs']);
        $this->assertSame([30], $progress['revision'][HifzEntry::KIND_SABQI]['juz']);
        // The six-month-old manzil is outside the window and reports nothing...
        $this->assertSame(0, $progress['revision'][HifzEntry::KIND_MANZIL]['entries']);
        // ...while the year-old sabak still counts, because memorisation is
        // cumulative and is never date-filtered.
        $this->assertSame(40, $progress['memorized']['ayahs']);
        $this->assertSame(3, $progress['totals']['entries']);
    }

    #[Test]
    public function a_student_with_no_entries_has_no_position_rather_than_a_zero(): void
    {
        $student = $this->makeStudent($this->masjidA, $this->groupA);

        $progress = $this->progressFor($student);

        // Null, not al-Fātiḥah:0 — a student who has recited nothing is not "at
        // the beginning of the muṣḥaf", they are simply not placed yet.
        $this->assertNull($progress['current_position']);
        $this->assertNull($progress['furthest_position']);
        $this->assertSame(0, $progress['memorized']['ayahs']);
        $this->assertSame(0, $progress['memorized']['juz_completed']);
    }

    #[Test]
    public function mistake_counters_stay_separate(): void
    {
        $student = $this->makeStudent($this->masjidA, $this->groupA);

        $this->makeEntry($this->masjidA, $this->groupA, $student, [
            'major_mistakes' => 2, 'minor_mistakes' => 5,
        ]);
        $this->makeEntry($this->masjidA, $this->groupA, $student, [
            'major_mistakes' => 1, 'minor_mistakes' => 3,
        ]);

        $totals = $this->progressFor($student)['totals'];

        // A wrong word and a tajwīd refinement are different lessons; a single
        // summed "mistakes" figure would tell a parent nothing.
        $this->assertSame(3, $totals['major_mistakes']);
        $this->assertSame(8, $totals['minor_mistakes']);
    }

    // ---------- correction and deletion ----------

    #[Test]
    public function striking_an_entry_removes_it_from_ordinary_queries(): void
    {
        $entry = $this->makeEntry($this->masjidA, $this->groupA);

        $entry->delete();

        // deleted_at IS the correction clock, so one mechanism drops it from
        // every listing, every total and every derivation at once.
        $this->assertSoftDeleted('hifz_entries', ['id' => $entry->id]);
        $this->assertNull(HifzEntry::withoutMasjidScope()->find($entry->id));
        $this->assertTrue(HifzEntry::withoutMasjidScope()->withTrashed()->find($entry->id)->isCorrected());
    }

    #[Test]
    public function removing_a_student_from_the_roster_takes_their_hifz_record(): void
    {
        $student = $this->makeStudent($this->masjidA, $this->groupA);
        $entry = $this->makeEntry($this->masjidA, $this->groupA, $student);

        $student->delete();

        // The audience for an entry is derived entirely from this membership
        // row. With it gone the record could never be disclosed to anyone, so
        // keeping it would be unreadable data about a minor — and this cascade
        // IS the retention policy for this table.
        $this->assertDatabaseMissing('hifz_entries', ['id' => $entry->id]);
    }

    #[Test]
    public function force_deleting_a_group_takes_its_hifz_entries(): void
    {
        $entry = $this->makeEntry($this->masjidA, $this->groupA);

        $this->groupA->forceDelete();

        $this->assertDatabaseMissing('hifz_entries', ['id' => $entry->id]);
    }

    #[Test]
    public function soft_deleting_a_group_keeps_its_hifz_entries(): void
    {
        $entry = $this->makeEntry($this->masjidA, $this->groupA);

        $this->groupA->delete();

        // A mis-click must not destroy a year of a child's memorisation history.
        $this->assertDatabaseHas('hifz_entries', ['id' => $entry->id]);
    }

    // ---------- retention: the deliberate absence ----------

    #[Test]
    public function the_purge_sweep_never_touches_hifz_entries(): void
    {
        // THE test that pins the retention decision. A ḥifẓ record is an
        // academic record and the position is DERIVED from it, so a sweep that
        // removed the newest sabak would silently move a child backwards in the
        // muṣḥaf. Its lifetime is bounded by the roster, not by a clock — see
        // .claude/rules/groups.md.
        $old = $this->makeEntry($this->masjidA, $this->groupA, null, [
            'recited_at' => now()->subYears(3),
        ]);

        $struck = $this->makeEntry($this->masjidA, $this->groupA, null, [
            'recited_at' => now()->subYears(3),
        ]);
        $struck->delete();

        Artisan::call('groups:purge-feed', ['--before' => now()->addYear()->toDateString()]);

        $this->assertDatabaseHas('hifz_entries', ['id' => $old->id]);
        $this->assertDatabaseHas('hifz_entries', ['id' => $struck->id]);
    }

    #[Test]
    public function an_entry_has_no_retention_column_to_forget_to_set(): void
    {
        // Belt and braces on the decision above: the column does not exist, so
        // no future writer can quietly start arming a clock on this table.
        $this->assertFalse(
            \Illuminate\Support\Facades\Schema::hasColumn('hifz_entries', 'retained_until')
        );
    }

    // ---------- accountability ----------

    #[Test]
    public function an_entry_records_which_account_heard_it_and_survives_that_account(): void
    {
        $teacher = User::factory()->create(['phone' => '+1' . random_int(1000000000, 9999999999)]);

        $entry = $this->makeEntry($this->masjidA, $this->groupA, null, [
            'heard_by_user_id' => $teacher->id,
        ]);

        // Retiring a teacher is the ordinary case, and User soft-deletes — the
        // row is still there, so attribution is untouched.
        $teacher->delete();

        $this->assertSame($teacher->id, $entry->fresh()->heard_by_user_id);

        // Erasing the account for good is the case nullOnDelete exists for: the
        // attribution goes, a year of a child's history does NOT.
        $teacher->forceDelete();

        $entry->refresh();

        $this->assertNull($entry->heard_by_user_id);
        $this->assertDatabaseHas('hifz_entries', ['id' => $entry->id]);
    }
}
