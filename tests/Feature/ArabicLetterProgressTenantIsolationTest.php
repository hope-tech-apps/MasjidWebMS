<?php

namespace Tests\Feature;

use App\Models\ArabicLetterProgress;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\Masjid;
use App\Support\Arabic\ArabicCurriculum;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The model-layer guarantees behind the Arabic letter tracker.
 *
 * MySQL has NO row-level security, so `BelongsToMasjid` is the only thing
 * keeping one school's children out of another school's queries.
 * .claude/rules/tenant-scoping.md makes a cross-tenant test mandatory for every
 * new tenant-scoped model; this is it for ArabicLetterProgress, binding
 * TenantContext directly the way ResolveMasjidTenant does per request.
 *
 * It also pins what the MODEL owns rather than the HTTP boundary, because these
 * must hold for every caller — importers and seeders included:
 *
 *   - `mastered_at` is a LEDGER, stamped once. A child who slips back to
 *     learning and masters a drill again has not mastered it twice, and the
 *     date a parent reads must not move because a teacher re-marked the card;
 *   - one row per (student, drill), enforced by the database, so a double-tap
 *     cannot mint a second cell;
 *   - progress FOLLOWS THE STUDENT: it cannot outlive the membership row its
 *     whole audience is derived from.
 */
class ArabicLetterProgressTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $tenant;

    private Masjid $masjidA;
    private Masjid $masjidB;
    private Group $classA;
    private Group $classB;

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

        $this->tenant = app(TenantContext::class);
        $this->tenant->forgetTenant();

        $this->masjidA = $this->makeMasjid();
        $this->masjidB = $this->makeMasjid();

        $this->classA = Group::factory()->create([
            'masjid_id' => $this->masjidA->id,
            'name' => 'Grade 2', 'slug' => 'grade-2', 'kind' => Group::KIND_CLASS,
            'arabic_stage' => ArabicCurriculum::STAGE_SUKUN_SHADDA,
        ]);
        $this->classB = Group::factory()->create([
            'masjid_id' => $this->masjidB->id,
            'name' => 'Grade 2', 'slug' => 'grade-2', 'kind' => Group::KIND_CLASS,
        ]);
    }

    #[Test]
    public function the_scope_hides_another_schools_letter_progress(): void
    {
        $mine = $this->makeProgress($this->masjidA, $this->classA);
        $foreign = $this->makeProgress($this->masjidB, $this->classB);

        $this->tenant->set($this->masjidA->id);

        $this->assertSame(1, ArabicLetterProgress::count());
        $this->assertSame($this->masjidA->id, ArabicLetterProgress::first()->masjid_id);
        $this->assertNull(ArabicLetterProgress::find($foreign->id));
        $this->assertNotNull(ArabicLetterProgress::find($mine->id));
    }

    #[Test]
    public function the_creating_hook_stamps_the_bound_tenant(): void
    {
        $student = $this->makeStudent($this->masjidA, $this->classA);

        $this->tenant->set($this->masjidA->id);

        // A client-supplied masjid_id must lose to the bound tenant.
        $row = ArabicLetterProgress::create([
            'masjid_id' => $this->masjidB->id,
            'group_id' => $this->classA->id,
            'group_membership_id' => $student->id,
            'drill_id' => 'ba',
            'status' => ArabicCurriculum::STATUS_LEARNING,
        ]);

        $this->assertSame($this->masjidA->id, $row->fresh()->masjid_id);
    }

    #[Test]
    public function mastered_at_is_stamped_once_and_never_moved(): void
    {
        $student = $this->makeStudent($this->masjidA, $this->classA);
        $this->tenant->set($this->masjidA->id);

        $row = ArabicLetterProgress::create([
            'group_id' => $this->classA->id,
            'group_membership_id' => $student->id,
            'drill_id' => 'ba',
            'status' => ArabicCurriculum::STATUS_NOT_STARTED,
        ]);

        $row->moveTo(ArabicCurriculum::STATUS_MASTERED);
        $row->save();
        $first = $row->fresh()->mastered_at;
        $this->assertNotNull($first);

        // Slip back, then master again a day later.
        $this->travel(1)->days();
        $row->moveTo(ArabicCurriculum::STATUS_LEARNING);
        $row->save();
        $row->moveTo(ArabicCurriculum::STATUS_MASTERED);
        $row->save();

        $this->assertEquals(
            $first->toDateTimeString(),
            $row->fresh()->mastered_at->toDateTimeString(),
            'the date a parent reads moved because a teacher re-marked the card'
        );
    }

    #[Test]
    public function a_student_cannot_hold_two_rows_for_one_drill(): void
    {
        $student = $this->makeStudent($this->masjidA, $this->classA);
        $this->tenant->set($this->masjidA->id);

        $attributes = [
            'group_id' => $this->classA->id,
            'group_membership_id' => $student->id,
            'drill_id' => 'ba',
            'status' => ArabicCurriculum::STATUS_LEARNING,
        ];

        ArabicLetterProgress::create($attributes);

        // Enforced by the database, not by hoping the client never double-taps.
        $this->expectException(\Illuminate\Database\QueryException::class);
        ArabicLetterProgress::create($attributes);
    }

    #[Test]
    public function two_students_may_hold_the_same_drill(): void
    {
        $one = $this->makeStudent($this->masjidA, $this->classA);
        $two = $this->makeStudent($this->masjidA, $this->classA);
        $this->tenant->set($this->masjidA->id);

        foreach ([$one, $two] as $student) {
            ArabicLetterProgress::create([
                'group_id' => $this->classA->id,
                'group_membership_id' => $student->id,
                'drill_id' => 'ba',
                'status' => ArabicCurriculum::STATUS_MASTERED,
            ]);
        }

        $this->assertSame(2, ArabicLetterProgress::count());
    }

    #[Test]
    public function progress_cannot_outlive_the_student_it_describes(): void
    {
        $student = $this->makeStudent($this->masjidA, $this->classA);
        $this->tenant->set($this->masjidA->id);

        ArabicLetterProgress::create([
            'group_id' => $this->classA->id,
            'group_membership_id' => $student->id,
            'drill_id' => 'ba',
            'status' => ArabicCurriculum::STATUS_MASTERED,
        ]);

        $this->assertSame(1, ArabicLetterProgress::count());

        // Removing a child from the roster takes their record with it — the
        // audience for it was derived from that row.
        $this->tenant->forgetTenant();
        GroupMembership::withoutMasjidScope()->whereKey($student->id)->first()->forceDelete();

        $this->assertSame(0, DB::table('arabic_letter_progress')->count());
    }

    // ------------------------------------------------------------- helpers

    private function makeMasjid(array $overrides = []): Masjid
    {
        return Masjid::create(array_merge([
            'name' => 'Test School '.uniqid(),
            'email' => 'school-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ], $overrides));
    }

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

    private function makeProgress(Masjid $masjid, Group $group): ArabicLetterProgress
    {
        $student = $this->makeStudent($masjid, $group);

        return ArabicLetterProgress::withoutMasjidScope()->create([
            'masjid_id' => $masjid->id,
            'group_id' => $group->id,
            'group_membership_id' => $student->id,
            'drill_id' => 'ba',
            'status' => ArabicCurriculum::STATUS_LEARNING,
        ]);
    }
}
