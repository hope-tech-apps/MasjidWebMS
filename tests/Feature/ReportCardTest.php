<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupStaff;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\ReportCard;
use App\Models\ReportCardMark;
use App\Models\User;
use App\Support\ReportCardTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Report cards and progress reports.
 *
 * The properties asserted here are the ones that make a report card a RECORD
 * rather than a screen:
 *
 *  1. A published card does not change. Not its criteria, not its attendance
 *     figures, not its marks — a family keeps this document.
 *  2. A draft is invisible to the family, and invisible in the way a
 *     nonexistent card is. A parent must not learn that a report about their
 *     child exists but is being withheld.
 *  3. Nothing is computed into a judgement. Every level is null until a teacher
 *     puts one there, and null means "not assessed", never zero.
 *  4. Effort never enters an academic mark.
 */
class ReportCardTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $school;
    private User $teacher;
    private Group $class;
    private GroupMembership $student;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->school = $this->makeMasjid();

        $this->teacher = User::factory()->create([
            'type' => 'Teacher', 'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
        MasjidUser::create([
            'masjid_id' => $this->school->id, 'user_id' => $this->teacher->id,
            'role' => 'teacher', 'is_default' => true,
        ]);

        $this->class = Group::factory()->create([
            'masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS,
            'name' => 'Combined Class A', 'slug' => 'combined-class-a',
        ]);

        $this->class->staff()->attach($this->teacher->id, [
            'masjid_id' => $this->class->masjid_id,
            'role' => GroupStaff::ROLE_TEACHER, 'assigned_at' => now(),
        ]);

        $this->student = $this->enrol('Aalaa', '1st');

        Sanctum::actingAs($this->teacher, ['staff']);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Named `makeMasjid` deliberately: TenantScopingCoverageTest only counts a
     * file as having seeded two tenants when it recognises the fixture helper by
     * name, and a file it does not recognise is silently SKIPPED rather than
     * failed. Called `makeSchool`, the cross-tenant test below existed and
     * proved nothing.
     */
    private function makeMasjid(): Masjid
    {
        return Masjid::create([
            'name' => 'Al-Razi ' . uniqid(),
            'email' => 'school-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0,
            'crm_enabled' => true, 'org_type' => 'school',
        ]);
    }

    private function enrol(string $firstName, string $grade): GroupMembership
    {
        $child = Contact::factory()->create([
            'masjid_id' => $this->school->id,
            'first_name' => $firstName, 'last_name' => 'Test',
            'email' => strtolower($firstName) . '@example.test',
        ]);

        return GroupMembership::create([
            'masjid_id' => $this->school->id,
            'group_id' => $this->class->id,
            'contact_id' => $child->id,
            'role' => GroupMembership::ROLE_MEMBER,
            'grade_label' => $grade,
        ]);
    }

    /** One child's card. Defaults to the student every test uses. */
    private function url(?GroupMembership $m = null): string
    {
        $m ??= $this->student;

        return $this->base() . "/members/{$m->id}/report-card";
    }

    /** The whole class for a period. */
    private function classUrl(): string
    {
        return $this->base() . '/report-cards';
    }

    private function base(): string
    {
        return "/api/teacher/masjids/{$this->school->id}/groups/{$this->class->id}";
    }

    /** Every route here is addressed for the same quarter. */
    private const PERIOD = '?term=2&school_year=2026-2027';

    private function open(?GroupMembership $m = null): array
    {
        return $this->getJson($this->url($m) . self::PERIOD)
            ->assertOk()->json('data');
    }

    // ----------------------------------------------- 1. the card is prepared

    #[Test]
    public function a_card_is_built_from_the_schools_own_criteria_with_nothing_marked(): void
    {
        $card = $this->open();

        $this->assertSame('Report Card', $card['type_label']);
        $this->assertSame('Quarter 2, 2026-2027', $card['period_label']);
        $this->assertSame('1st', $card['grade_label']);

        $subjects = collect($card['subjects'])->pluck('subject');
        $this->assertContains('Qur\'an', $subjects);
        $this->assertContains('Islamic Studies', $subjects);
        $this->assertContains('Mathematics', $subjects);

        // NOTHING is marked. A level a teacher did not choose must never appear
        // on a document with their name on it.
        $levels = collect($card['subjects'])->flatMap(fn ($s) => collect($s['criteria'])->pluck('level'));
        $this->assertTrue($levels->every(fn ($l) => $l === null), 'a fresh card judges nobody');
        $this->assertNotEmpty($levels);
    }

    /**
     * Al-Razi runs two combined classrooms spanning Pre-K to 2nd, so one roster
     * holds children on different templates. A four-year-old must not carry a
     * row for Arabic grammar that nobody can honestly mark.
     */
    #[Test]
    public function a_pre_k_child_is_not_marked_on_grammar_or_mathematics(): void
    {
        $little = $this->enrol('Jibril', 'Pre-K');

        $subjects = collect($this->open($little)['subjects'])->pluck('subject');

        $this->assertContains('Qur\'an', $subjects, 'the core is for everyone');
        $this->assertNotContains('Mathematics', $subjects);

        $arabic = collect($this->open($little)['subjects'])->firstWhere('subject', 'Arabic Language');
        $this->assertNotContains('Grammar', collect($arabic['criteria'])->pluck('criterion'));

        // And the 1st grader in the same room does get both.
        $older = collect($this->open()['subjects']);
        $this->assertContains('Mathematics', $older->pluck('subject'));
        $this->assertContains(
            'Grammar',
            collect($older->firstWhere('subject', 'Arabic Language')['criteria'])->pluck('criterion')
        );
    }

    #[Test]
    public function opening_a_card_twice_never_duplicates_or_resets_it(): void
    {
        $first = $this->open();
        $criteria = collect($first['subjects'])->flatMap(fn ($s) => $s['criteria'])->count();

        $id = collect($first['subjects'])->first()['criteria'][0]['id'];
        $this->putJson($this->url() . self::PERIOD, [
            'marks' => [['id' => $id, 'level' => 4]],
        ])->assertOk();

        $second = $this->open();

        $this->assertSame($first['id'], $second['id']);
        $this->assertSame($criteria, collect($second['subjects'])->flatMap(fn ($s) => $s['criteria'])->count());
        $this->assertSame(4, collect($second['subjects'])->first()['criteria'][0]['level'], 'a re-open must not reset a mark');
    }

    // ------------------------------------------------- 2. marks and comments

    #[Test]
    public function null_is_a_real_mark_meaning_not_assessed(): void
    {
        $card = $this->open();
        $id = collect($card['subjects'])->first()['criteria'][0]['id'];

        $this->putJson($this->url() . self::PERIOD, [
            'marks' => [['id' => $id, 'level' => 3]],
        ])->assertOk();

        $this->putJson($this->url() . self::PERIOD, [
            'marks' => [['id' => $id, 'level' => null]],
        ])->assertOk();

        $mark = ReportCardMark::find($id);
        $this->assertNull($mark->level, 'a criterion can be un-assessed again');
        $this->assertNotSame(0, $mark->level, 'and must never become a zero');
    }

    #[Test]
    public function a_mark_that_is_not_a_performance_level_is_refused(): void
    {
        $id = collect($this->open()['subjects'])->first()['criteria'][0]['id'];

        foreach ([0, 5, 2.5] as $notALevel) {
            $this->putJson($this->url() . self::PERIOD, [
                'marks' => [['id' => $id, 'level' => $notALevel]],
            ])->assertUnprocessable();
        }
    }

    /**
     * A payload naming a mark on somebody else's card must change nothing —
     * not 403, not 404, just no effect, because the update is SCOPED to this
     * card's own rows rather than checked afterwards.
     */
    #[Test]
    public function a_save_cannot_reach_another_childs_card(): void
    {
        $mine = $this->open();
        $theirs = $this->open($this->enrol('Kareem', '1st'));

        $theirMarkId = collect($theirs['subjects'])->first()['criteria'][0]['id'];

        $this->putJson($this->url() . self::PERIOD, [
            'marks' => [['id' => $theirMarkId, 'level' => 1]],
        ])->assertOk();

        $this->assertNull(ReportCardMark::find($theirMarkId)->level, 'the other card is untouched');
    }

    /**
     * Folding "works well with others" into a subject is how a quiet child ends
     * up with a lower mark in Mathematics than their maths deserves.
     */
    #[Test]
    public function learning_behaviours_are_reported_beside_the_subjects_never_inside_them(): void
    {
        $card = $this->open();

        $this->assertNotEmpty($card['learning_behaviours']);
        $this->assertSame(
            count(ReportCardTemplate::LEARNING_BEHAVIOURS),
            count($card['learning_behaviours'])
        );

        $academicCriteria = collect($card['subjects'])->flatMap(fn ($s) => collect($s['criteria'])->pluck('criterion'));

        foreach (ReportCardTemplate::LEARNING_BEHAVIOURS as $behaviour) {
            $this->assertNotContains($behaviour, $academicCriteria);
        }
    }

    // ------------------------------------------- 3. publication freezes it

    #[Test]
    public function publishing_freezes_the_attendance_figures(): void
    {
        foreach ([['2026-11-02', 'present'], ['2026-11-03', 'late'], ['2026-11-04', 'absent']] as [$date, $status]) {
            AttendanceRecord::create([
                'masjid_id' => $this->school->id, 'group_id' => $this->class->id,
                'group_membership_id' => $this->student->id,
                'session_date' => $date, 'status' => $status,
            ]);
        }

        $this->open();
        $card = $this->postJson($this->url() . '/publish' . self::PERIOD, [
            'from' => '2026-11-01', 'to' => '2026-11-30',
        ])->assertOk()->json('data');

        $this->assertTrue($card['published']);
        $this->assertSame(2, $card['attendance']['present'], 'late is attendance, as everywhere else');
        $this->assertSame(1, $card['attendance']['absent']);
        $this->assertSame(1, $card['attendance']['late']);

        // A day recorded AFTER publication must not change what the document
        // already said. A record that rewrites itself is not a record.
        AttendanceRecord::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id,
            'group_membership_id' => $this->student->id,
            'session_date' => '2026-11-05', 'status' => 'absent',
        ]);

        $after = $this->open();
        $this->assertSame(1, $after['attendance']['absent'], 'the snapshot is frozen');
    }

    #[Test]
    public function a_published_card_refuses_to_be_edited_until_it_is_taken_back(): void
    {
        $card = $this->open();
        $id = collect($card['subjects'])->first()['criteria'][0]['id'];

        $this->postJson($this->url() . '/publish' . self::PERIOD)->assertOk();

        $this->putJson($this->url() . self::PERIOD, [
            'marks' => [['id' => $id, 'level' => 1]],
        ])->assertUnprocessable();

        $this->assertNull(ReportCardMark::find($id)->level);

        $this->deleteJson($this->url() . '/publish' . self::PERIOD)->assertOk();

        $this->putJson($this->url() . self::PERIOD, [
            'marks' => [['id' => $id, 'level' => 1]],
        ])->assertOk();

        $this->assertSame(1, ReportCardMark::find($id)->level);
    }

    /**
     * Taking a card back must clear the snapshot too, or a card republished in
     * March silently carries November's attendance — the failure snapshotting
     * exists to prevent, arriving from the other direction.
     */
    #[Test]
    public function taking_a_card_back_clears_the_frozen_figures(): void
    {
        AttendanceRecord::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id,
            'group_membership_id' => $this->student->id,
            'session_date' => '2026-11-02', 'status' => 'present',
        ]);

        $this->open();
        $this->postJson($this->url() . '/publish' . self::PERIOD)->assertOk();
        $this->assertSame(1, $this->open()['attendance']['present']);

        $card = $this->deleteJson($this->url() . '/publish' . self::PERIOD)
            ->assertOk()->json('data');

        $this->assertFalse($card['published']);
        $this->assertNull($card['attendance']['present'], 'a draft carries no frozen figures');
    }

    // ----------------------------------------------- 4. the class-level view

    #[Test]
    public function the_class_list_shows_progress_without_creating_anything(): void
    {
        $this->enrol('Sama', 'Pre-K');

        $list = $this->getJson($this->classUrl() . self::PERIOD)
            ->assertOk()->json('data');

        $this->assertCount(2, $list['students']);
        $this->assertSame(0, ReportCard::count(), 'looking at a class must not create documents');

        foreach ($list['students'] as $s) {
            $this->assertFalse($s['started']);
            $this->assertSame(0, $s['assessed']);
        }

        // Once one is started, the list says how far along it is.
        $card = $this->open();
        $id = collect($card['subjects'])->first()['criteria'][0]['id'];
        $this->putJson($this->url() . self::PERIOD, [
            'marks' => [['id' => $id, 'level' => 3]],
        ])->assertOk();

        $again = collect($this->getJson($this->classUrl() . self::PERIOD)->json('data.students'))
            ->firstWhere('report_card_id', $card['id']);

        $this->assertTrue($again['started']);
        $this->assertSame(1, $again['assessed']);
        $this->assertGreaterThan(1, $again['criteria']);
    }

    #[Test]
    public function a_progress_report_and_a_report_card_are_separate_documents(): void
    {
        $reportCard = $this->open();

        $progress = $this->getJson($this->url() . self::PERIOD . '&type=progress')
            ->assertOk()->json('data');

        $this->assertNotSame($reportCard['id'], $progress['id']);
        $this->assertSame('Progress Report', $progress['type_label']);
        $this->assertSame('Report Card', $reportCard['type_label']);
        $this->assertSame(2, ReportCard::count());
    }

    #[Test]
    public function the_key_travels_with_the_card(): void
    {
        $key = $this->getJson($this->url() . self::PERIOD)
            ->assertOk()->json('performance_levels');

        $this->assertCount(4, $key);
        $this->assertSame('Exceeds Expectations', $key[0]['label']);
        $this->assertSame('Needs Support', $key[3]['label']);
    }

    // --------------------------------------------------- 5. tenant isolation

    #[Test]
    public function a_bound_tenant_cannot_read_another_organizations_report_card(): void
    {
        $this->open();
        $card = ReportCard::firstOrFail();
        $mark = ReportCardMark::firstOrFail();

        $other = $this->makeMasjid();
        $admin = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
        $other->user_id = $admin->id;
        $other->save();

        app(\App\Support\TenantContext::class)->set($other->id);

        $this->assertNull(ReportCard::find($card->id));
        $this->assertNull(ReportCardMark::find($mark->id));
        $this->assertSame(0, ReportCard::where('id', $card->id)->update(['term' => 4]));
        $this->assertSame(0, ReportCardMark::where('id', $mark->id)->update(['level' => 1]));
        $this->assertSame(0, ReportCard::where('id', $card->id)->delete());
        $this->assertSame(0, ReportCardMark::where('id', $mark->id)->delete());

        // A client-supplied masjid_id must lose to the BOUND tenant.
        $stamped = ReportCardMark::create([
            'masjid_id' => $this->school->id,
            'report_card_id' => $card->id,
            'kind' => 'academic',
            'subject' => 'Smuggled',
            'criterion' => 'Smuggled',
        ]);

        $this->assertSame($other->id, (int) $stamped->masjid_id);
    }
}
