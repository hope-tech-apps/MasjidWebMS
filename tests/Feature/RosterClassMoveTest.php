<?php

namespace Tests\Feature;

use App\Exceptions\RosterClassMoveChanged;
use App\Exceptions\RosterMoveRefused;
use App\Http\Requests\Admin\Groups\MoveClassRequest;
use App\Http\Requests\Admin\Groups\PreviewClassMoveRequest;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupPost;
use App\Models\GroupStaff;
use App\Models\Offering;
use App\Models\User;
use App\Support\GradeLevel;
use App\Support\RosterClassMove;
use App\Support\RosterClassMovePlan;
use App\Support\RosterMove;
use App\Support\RosterMovePlan;
use App\Support\SchoolCalendar;
use App\Support\SchoolSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\Support\BuildsSchoolRosters;
use Tests\Support\LogsLikeProduction;
use Tests\Support\PlantsRosterRecords;
use Tests\TestCase;

/**
 * MOVING A WHOLE CLASS (App\Support\RosterClassMove).
 *
 * One preview that lists every current student with what the single move
 * would do for them, and one request that names the students the office ticked
 * and runs the single move once per student, each in its own transaction. The
 * answer names every student: moved, not moved and why, or not reached.
 *
 * Everything about ONE student is the single move's and is tested in
 * RosterMoveTest. What is held to account here is what is about the class:
 * that the request names rows and echoes what was shown, that one student's
 * refusal does not stop the others, that a fault and the time budget leave
 * "these fully moved, those not touched", that one run at a time leaves a
 * class, that two children of one family get the same result in either order,
 * and that every sentence the office reads about the class is true.
 *
 * Every test of a run that moves somebody ends with `assertNothingWasDestroyed`
 * (no roster row gone, no consent cleared, none granted on an existing row).
 *
 * "Today" is pinned to Sunday 4 October 2026 on the school's clock.
 */
class RosterClassMoveTest extends TestCase
{
    use BuildsSchoolRosters;
    use LogsLikeProduction;
    use PlantsRosterRecords;
    use RefreshDatabase;

    private const TODAY = '2026-10-04';

    private Group $first;

    private Group $second;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        // 11:00 on Sunday 4 October in New York, the clock a school with no
        // timezone of its own is read on.
        Carbon::setTestNow('2026-10-04 15:00:00');

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->school = $this->makeSchool();
        $this->admin = $this->makeAdmin($this->school);
        $this->school->user_id = $this->admin->id;
        $this->school->save();

        $this->first = $this->makeClass('1st Grade');
        $this->second = $this->makeClass('2nd Grade');
    }

    protected function tearDown(): void
    {
        $this->forgetProductionLogs();
        Carbon::setTestNow();
        // One test drops the marker column, and what was seen is remembered
        // for the life of the process.
        GroupMembership::forgetConsentCarryReady();

        parent::tearDown();
    }

    // ---------------------------------------------------------------- helpers

    private function classMoveUrl(Group $class): string
    {
        return "/api/admin/masjids/{$class->masjid_id}/groups/{$class->id}/class-move";
    }

    private function previewClass(Group $from, Group|int $to, array $with = [], ?User $as = null): TestResponse
    {
        Sanctum::actingAs($as ?? $this->admin);

        return $this->getJson($this->classMoveUrl($from).'?'.http_build_query($with + [
            'to_group_id' => $to instanceof Group ? $to->id : $to,
            'moved_on' => self::TODAY,
        ]));
    }

    /**
     * What the dialog sends for the students it has ticked: each roster row
     * with what the preview showed for it. Every student who can move, or only
     * `$only`, in that order.
     *
     * @return list<array<string, mixed>>
     */
    private function ticked(TestResponse $preview, ?array $only = null): array
    {
        $rows = collect($preview->json('data.students'))->where('can_move', true)->keyBy('membership_id');
        $ids = $only ?? $rows->keys()->all();

        return collect($ids)->map(fn (int $id): array => [
            'membership_id' => $id,
            'expected_path' => $rows[$id]['path'],
            'expected_first_day' => $rows[$id]['first_day_in_new_class'],
            'expected_joined_on' => $rows[$id]['joined_on'],
            'expected_consent' => $rows[$id]['expected_consent'],
            'expected_grade' => $rows[$id]['grade_after'],
        ])->all();
    }

    private function moveClass(Group $from, Group|int $to, array $students, array $with = [], ?User $as = null): TestResponse
    {
        Sanctum::actingAs($as ?? $this->admin);

        return $this->postJson($this->classMoveUrl($from), $with + [
            'to_group_id' => $to instanceof Group ? $to->id : $to,
            'moved_on' => self::TODAY,
            'grade_mode' => 'keep',
            'students' => $students,
        ]);
    }

    /** Check, tick everyone who can move, and move them: what the office does when nothing is in the way. */
    private function moveEveryone(Group $from, Group $to, array $with = []): TestResponse
    {
        $preview = $this->previewClass($from, $to, $with)->assertOk();

        return $this->moveClass($from, $to, $this->ticked($preview), $with)->assertOk();
    }

    /** @return array<string, string> each student's outcome, by name */
    private function outcomes(TestResponse $answer): array
    {
        return collect($answer->json('data.students'))->pluck('outcome', 'name')->all();
    }

    private function graded(GroupMembership $row, ?string $grade): GroupMembership
    {
        $row->forceFill(['grade_label' => $grade])->save();

        return $row->fresh();
    }

    /** The student's current place in a class. */
    private function placeIn(Group $class, GroupMembership $like): GroupMembership
    {
        return GroupMembership::query()->where('group_id', $class->id)->where('role', GroupMembership::ROLE_MEMBER)
            ->where('contact_id', $like->contact_id)->whereNull('left_on')->sole();
    }

    private function rosterTable(): array
    {
        return DB::table('group_memberships')->orderBy('id')->get()->toArray();
    }

    /**
     * The single move with two hooks a test supplies: `$atLocks(contact id,
     * nth student)` runs when every lock of one student's move is held, and
     * `$afterMove(nth student)` after that student's move has committed.
     * `$seen->moves` collects the options each move was handed.
     */
    private function bindMover(?\Closure $atLocks = null, ?\Closure $afterMove = null, ?object $seen = null): void
    {
        $this->app->bind(RosterMove::class, fn () => new class($atLocks, $afterMove, $seen) extends RosterMove {
            private int $nth = 0;

            private ?int $contact = null;

            public function __construct(private ?\Closure $atLocks, private ?\Closure $afterMove, private ?object $seen)
            {
            }

            public function move(Group $from, GroupMembership $row, int $toGroupId, string $on, array $options, ?User $actor): RosterMovePlan
            {
                $this->nth++;
                $this->contact = (int) $row->contact_id;

                if ($this->seen !== null) {
                    $this->seen->moves[] = $options;
                }

                $plan = parent::move($from, $row, $toGroupId, $on, $options, $actor);

                if ($this->afterMove !== null) {
                    ($this->afterMove)($this->nth);
                }

                return $plan;
            }

            protected function locksTaken(): void
            {
                if ($this->atLocks !== null) {
                    ($this->atLocks)($this->contact, $this->nth);
                }
            }
        });
    }

    // ------------------------------------------------------------ the preview

    #[Test]
    public function the_preview_lists_every_current_student_with_what_the_single_move_would_do_for_them(): void
    {
        $maryam = $this->graded($this->enrol($this->first, 'Maryam'), '1st');
        $this->guardian($maryam, 'Huda', consent: 'media');
        $yusuf = $this->enrol($this->first, 'Yusuf');

        // Two rows of this roster that are not current students.
        $this->enrol($this->first, 'Former')->markLeftByStaff($this->admin, '2026-09-20')->save();
        (new GroupMembership([
            'masjid_id' => $this->school->id, 'group_id' => $this->first->id,
            'contact_id' => $this->makePerson('Old', 'Leader')->id, 'role' => GroupMembership::ROLE_LEADER,
        ]))->confirmedByStaff($this->admin)->save();

        $before = $this->rosterTable();
        $preview = $this->previewClass($this->first, $this->second)->assertOk()->assertJsonPath('status', 'success');
        $data = $preview->json('data');

        $this->assertSame(
            ['can_move', 'refusal', 'open_group', 'from_group', 'to_group', 'moved_on', 'school_today', 'expected_bucks_rule',
                'lines', 'students', 'not_listed', 'counts', 'limits'],
            array_keys($data),
        );
        $this->assertSame(['who', 'consent', 'records', 'bucks', 'afterwards'], array_keys($data['lines']));
        $this->assertSame(
            ['membership_id', 'name', 'grade_label', 'grade_after', 'grade_note', 'can_move', 'refusal', 'open_group', 'path',
                'first_day_in_new_class', 'joined_on', 'expected_consent', 'came_from_target', 'summary', 'lines'],
            array_keys($data['students'][0]),
        );
        $this->assertSame(
            ['students', 'can_move', 'cannot_move', 'held_back_for_consent', 'guardians_travelling', 'consent_carried',
                'consent_none_recorded', 'consent_none_but_receives', 'consent_not_carried', 'consent_left_as_it_was',
                'consent_in_force_again', 'students_unconfirmed', 'guardian_form_claims',
                'guardians_confirmed_in_old_class_only', 'report_cards_not_started', 'others_in_new_class', 'new_class_holds'],
            array_keys($data['counts']),
        );

        $preview->assertJsonPath('data.can_move', true)
            ->assertJsonPath('data.refusal', null)
            ->assertJsonPath('data.from_group', ['id' => $this->first->id, 'name' => '1st Grade'])
            ->assertJsonPath('data.to_group', ['id' => $this->second->id, 'name' => '2nd Grade'])
            ->assertJsonPath('data.moved_on', self::TODAY)
            ->assertJsonPath('data.school_today', self::TODAY)
            // No move carries a balance yet, so there is no rule to echo.
            ->assertJsonPath('data.expected_bucks_rule', null)
            ->assertJsonPath('data.lines.bucks', [])
            ->assertJsonPath('data.not_listed', ['left_or_moved' => 1, 'leaders' => 1])
            ->assertJsonPath('data.limits', ['max_students' => 60])
            ->assertJsonPath('data.counts.students', 2)
            ->assertJsonPath('data.counts.can_move', 2)
            ->assertJsonPath('data.counts.cannot_move', 0);

        // Roster order, current students only.
        $this->assertSame([$maryam->id, $yusuf->id], array_column($data['students'], 'membership_id'));

        $this->assertSame([
            'membership_id' => $maryam->id,
            'name' => 'Maryam Student',
            'grade_label' => '1st',
            'grade_after' => '1st',
            'grade_note' => null,
            'can_move' => true,
            'refusal' => null,
            'open_group' => null,
            'path' => RosterMovePlan::LEFT_AND_STARTED,
            'first_day_in_new_class' => self::TODAY,
            'joined_on' => self::TODAY,
            'expected_consent' => 'm1f0s0n0e0',
            'came_from_target' => false,
            'summary' => 'Moves to 2nd Grade from 4 Oct 2026, with 1 guardian; consent carried for 1.',
            // The single preview's own sentences, word for word.
            'lines' => $this->previewMove($maryam, $this->second, self::TODAY)->assertOk()->json('data.lines'),
        ], $data['students'][0]);

        $this->assertSame('Moves to 2nd Grade from 4 Oct 2026, with no guardian on this roster.', $data['students'][1]['summary']);
        $this->assertNull($data['students'][1]['grade_label']);

        $this->assertSame([
            'All 2 students can move to 2nd Grade from 4 Oct 2026.',
            'Each student is moved on their own. If one cannot be moved, the others still are, and the result says who and why.',
            '1 student who has already left or moved is not part of this.',
            '1 entry on this roster is a leader, not a student, and is not part of this.',
        ], $data['lines']['who']);

        // It takes no lock and writes nothing.
        $this->assertEquals($before, $this->rosterTable());
        $this->assertSame(0, DB::table('cache_locks')->count());
    }

    #[Test]
    public function the_class_lines_say_what_follows_the_students_and_what_does_not(): void
    {
        $story = fn () => GroupPost::factory()->create(['masjid_id' => $this->school->id, 'group_id' => $this->second->id]);

        // The class being entered: a student of its own, with two parents
        // who are also parents in the class being left, and three stories it
        // still keeps, one with a photograph.
        $bilal = $this->enrol($this->second, 'Bilal');
        $samira = $this->guardian($bilal, 'Samira');
        $khadija = $this->guardian($bilal, 'Khadija', consent: 'feed');
        $story();
        $story();
        $story()->attachments()->create([
            'masjid_id' => $this->school->id, 'disk' => 'private', 'path' => 'group-posts/'.uniqid().'.jpg',
            'original_name' => 'photo.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 1024,
        ]);

        // Carried, by scope.
        $maryam = $this->enrol($this->first, 'Maryam');
        $this->guardian($maryam, 'Huda', consent: 'media');
        $this->guardian($maryam, 'Gamal', consent: 'feed');
        // Nothing on record, and a registration form's claim beside it.
        $yusuf = $this->enrol($this->first, 'Yusuf');
        $this->guardian($yusuf, 'Nadia');
        $this->guardian($yusuf, 'Stranger', confirmed: false);
        // Her own place is a form's claim, and her mother already stands in the
        // class entered for Bilal with nothing recorded there: not carried.
        $layla = $this->enrol($this->first, 'Layla', confirmed: false);
        $this->guardian($layla, $samira->contact, consent: 'media');
        // The class entered already holds an entry for this father and child.
        $zayd = $this->enrol($this->first, 'Zayd');
        $omar = $this->guardian($zayd, 'Omar', consent: 'media');
        (new GroupMembership([
            'masjid_id' => $this->school->id, 'group_id' => $this->second->id, 'contact_id' => $omar->contact_id,
            'role' => GroupMembership::ROLE_GUARDIAN, 'guardian_of_contact_id' => $zayd->contact_id,
        ]))->confirmedByStaff($this->admin)->save();
        // Nothing on record for Idris, and the class story already reaches her through Bilal.
        $idris = $this->enrol($this->first, 'Idris');
        $this->guardian($idris, $khadija->contact);
        // Already in both classes: cannot be moved.
        $hamza = $this->enrol($this->first, 'Hamza');
        $this->enrol($this->second, $hamza->contact);

        // The register: one student marked on the chosen day, one before it.
        $this->plantRecord('attendance_records', $yusuf, ['session_date' => self::TODAY]);
        $this->plantRecord('attendance_records', $maryam, ['session_date' => '2026-09-06']);

        // A program still enrols into the class being left.
        Offering::factory()->forMasjid($this->school)->withRoster($this->first)->create();

        $preview = $this->previewClass($this->first, $this->second, ['grade_mode' => 'keep'])->assertOk();

        $preview->assertJsonPath('data.counts', [
            'students' => 6,
            'can_move' => 5,
            'cannot_move' => 1,
            'held_back_for_consent' => 0,
            'guardians_travelling' => 7,
            'consent_carried' => ['media' => 1, 'feed' => 1],
            'consent_none_recorded' => 1,
            'consent_none_but_receives' => 1,
            'consent_not_carried' => 1,
            'consent_left_as_it_was' => 1,
            'consent_in_force_again' => ['media' => 0, 'feed' => 0],
            'students_unconfirmed' => 1,
            'guardian_form_claims' => 1,
            'guardians_confirmed_in_old_class_only' => 0,
            'report_cards_not_started' => 2,
            'others_in_new_class' => 2,
            'new_class_holds' => ['stories' => 3, 'with_media' => 1],
        ]);

        $this->assertSame([
            '5 of 6 students can move to 2nd Grade from 4 Oct 2026.',
            '1 cannot be moved yet. The reason is under their name. They stay in 1st Grade and nothing about them changes.',
            'Each student is moved on their own. If one cannot be moved, the others still are, and the result says who and why.',
        ], $preview->json('data.lines.who'));

        $this->assertSame([
            "7 guardian places (one for each parent and child) go onto 2nd Grade's list with their students. They stay on "
                ."1st Grade's list too, marked as left.",
            'Consent is carried as it is, and nobody is asked again: 1 for the class story and photographs, 1 for the class '
                .'story only.',
            'From the move on those guardians can open everything 2nd Grade has shared and still keeps, including what it '
                .'shared before these students joined: its class story, class-wide conversations and class files, and for '
                ."photograph consent its photographs and videos. They also start receiving 2nd Grade's story emails and its "
                .'weekly points email.',
            '2nd Grade still holds 1 story with photographs or videos from before this move. They show students who were in '
                .'2nd Grade then, and the arriving guardians will see them.',
            "For a new year with none of last year's story, add a new class and move into that.",
            '2nd Grade already has 2 students who are not part of this move. They will share the class.',
            'Guardians who arrive with photograph consent will see the photographs 2nd Grade posts of those students.',
            "If 2nd Grade's own students are moving up too, move them first.",
            "1 guardian place has no consent on record in 1st Grade. Nothing changes for it: that family receives nothing "
                ."from 2nd Grade's class story until it is recorded on 2nd Grade's roster.",
            "1 more has none on record for the child being moved but already receives 2nd Grade's story through another "
                .'child there.',
            '1 is not carried because the guardian is already in 2nd Grade for another child with less consent recorded '
                .'there. It is named under its student.',
            '1 guardian place with consent in 1st Grade already exists in 2nd Grade for its student, with none recorded '
                .'there. It is left exactly as it is.',
            '2 students or guardian places are unconfirmed and stay unconfirmed in 2nd Grade.',
        ], $preview->json('data.lines.consent'));

        $this->assertSame([
            'Everything recorded for each student (register, marks, report cards, points, ḥifẓ, letters) stays with 1st '
                .'Grade, where they will be shown as moved. They start fresh in 2nd Grade.',
            '1st Grade has already marked 1 student on or after 4 Oct 2026. Those days stay with 1st Grade, and that student '
                .'starts in 2nd Grade the day after their last mark.',
            'Each student keeps their grade.',
        ], $preview->json('data.lines.records'));

        $this->assertSame([
            '2nd Grade has no teacher yet. Add one on the Teachers screen, or nobody can take its register.',
            'A program still enrols new students into 1st Grade. Point it at the right class on the Programs screen after '
                .'this move.',
            '2 students have no report card started in 1st Grade. Its teacher can no longer start one after the move. If one '
                .'is owed, ask the teacher to open it first.',
            '1st Grade keeps its teachers, class story, conversations, files, lesson plans and assignments. None of that is '
                .'copied to 2nd Grade. 1st Grade itself is not changed and stays on the Classes page.',
        ], $preview->json('data.lines.afterwards'));

        // The student who cannot go has the single move's own sentence, as text.
        $refused = collect($preview->json('data.students'))->firstWhere('membership_id', $hamza->id);
        $this->assertFalse($refused['can_move']);
        $this->assertSame($this->previewMove($hamza, $this->second, self::TODAY)->json('data.refusal'), $refused['refusal']);
        $this->assertStringStartsWith('Hamza Student is already in 2nd Grade.', $refused['summary']);

        // Each of the others names its own guardians, in the single preview's words.
        $this->assertStringContainsString(
            'Samira Guardian is already in 2nd Grade for another child, with no consent recorded there: not carried.',
            implode(' ', collect($preview->json('data.students'))->firstWhere('membership_id', $layla->id)['lines']),
        );

        // THE RESULT counts what was done the same way, and says it in the past.
        $before = $this->rosterSnapshot();
        $answer = $this->moveClass($this->first, $this->second, $this->ticked($preview))->assertOk()
            ->assertJsonPath('data.moved', 5)
            ->assertJsonPath('data.not_moved', 0)
            ->assertJsonPath('data.not_reached', 0)
            ->assertJsonPath('data.counts.students', 5)
            ->assertJsonPath('data.counts.guardians_travelling', 7)
            ->assertJsonPath('data.counts.consent_carried', ['media' => 1, 'feed' => 1])
            ->assertJsonPath('data.counts.consent_not_carried', 1);

        $this->assertArrayNotHasKey('can_move', $answer->json('data.counts'));
        $this->assertSame(['done', 'consent', 'bucks', 'afterwards'], array_keys($answer->json('data.lines')));

        $this->assertSame([
            '5 students are now in 2nd Grade.',
            "To put this back: open 2nd Grade, press Move the class, choose 1st Grade, then 'Only the students who came from "
                ."1st Grade' and 'Keep each student's grade'. If you have switched 1st Grade off, switch it on again first.",
        ], $answer->json('data.lines.done'));

        $this->assertSame([
            'Consent was carried as it is for 2 guardian places (one for each parent and child): 1 class story and '
                .'photographs, 1 class story only. Nobody was asked again; the roster shows each one as carried.',
            "1 guardian place has none on record and that family receives nothing from 2nd Grade's class story until it is "
                .'recorded there.',
            '1 was not carried because the guardian is already in 2nd Grade for another child with less consent recorded '
                .'there. It is named under its student.',
            "Tell 2nd Grade's teacher: 2 more guardian places now receive its class story, 1 of them its photographs.",
        ], $answer->json('data.lines.consent'));

        // The report-card line cannot be acted on any more, so it is not said again.
        $this->assertSame([
            '2nd Grade has no teacher yet. Add one on the Teachers screen, or nobody can take its register.',
            'A program still enrols new students into 1st Grade. Point it at the right class on the Programs screen after '
                .'this move.',
            '1st Grade keeps its teachers, class story, conversations, files, lesson plans and assignments. None of that is '
                .'copied to 2nd Grade. 1st Grade itself is not changed and stays on the Classes page.',
        ], $answer->json('data.lines.afterwards'));
        $this->assertSame([], $answer->json('data.lines.bucks'));
        $this->assertSame(implode(' ', $answer->json('data.lines.done')), $answer->json('message'));

        // Hamza was not named, and nothing about him changed.
        $this->assertNull($hamza->fresh()->left_on);
        $this->assertNothingWasDestroyed($before);
    }

    #[Test]
    public function one_student_alone_and_a_class_with_no_consent_at_all_are_said_in_their_own_words(): void
    {
        $maryam = $this->enrol($this->first, 'Maryam');
        $this->guardian($maryam, 'Huda');
        $this->second->staff()->attach($this->makeAdmin($this->school, 'Teacher', 'teacher')->id, [
            'masjid_id' => $this->school->id, 'role' => GroupStaff::ROLE_TEACHER, 'assigned_at' => now(),
        ]);
        // A program that is switched off still points at the class being left.
        Offering::factory()->forMasjid($this->school)->withRoster($this->first)->create(['is_active' => false]);

        $preview = $this->previewClass($this->first, $this->second)->assertOk();

        $this->assertSame(['The one student in 1st Grade can move to 2nd Grade from 4 Oct 2026.'], $preview->json('data.lines.who'));
        $this->assertSame([
            "1 guardian place (one for each parent and child) goes onto 2nd Grade's list with its student. It stays on 1st "
                ."Grade's list too, marked as left.",
            "No guardian of this student has consent on record, so none is carried, and no family will receive 2nd Grade's "
                ."class story until it is recorded on 2nd Grade's roster, one guardian at a time.",
        ], $preview->json('data.lines.consent'));
        // No grade choice yet, so no sentence about grades.
        $this->assertSame([
            'Everything recorded for each student (register, marks, report cards, points, ḥifẓ, letters) stays with 1st '
                .'Grade, where they will be shown as moved. They start fresh in 2nd Grade.',
        ], $preview->json('data.lines.records'));
        $this->assertSame([
            "1st Grade's teachers do not move with the class. 2nd Grade keeps its own.",
            'A program that is switched off still points at 1st Grade. Point it at the right class on the Programs screen '
                .'before it is opened again.',
            '1st Grade keeps its teachers, class story, conversations, files, lesson plans and assignments. None of that is '
                .'copied to 2nd Grade. 1st Grade itself is not changed and stays on the Classes page.',
        ], $preview->json('data.lines.afterwards'));

        $before = $this->rosterSnapshot();
        $answer = $this->moveClass($this->first, $this->second, $this->ticked($preview))->assertOk();

        // The class being left is empty now: said AFTER the way back, never before it.
        $this->assertSame([
            '1 student is now in 2nd Grade.',
            "To put this back: open 2nd Grade, press Move the class, choose 1st Grade, then 'Only the students who came from "
                ."1st Grade' and 'Keep each student's grade'. If you have switched 1st Grade off, switch it on again first.",
            '1st Grade now has no current students. It stays on your lists with everything recorded in it. Once you are sure '
                .'the move is right, you can show it is no longer running by switching off Active in its Edit form. Do not '
                .'use Deactivate: that hides its records from its teachers and its families. And do not type an end date '
                .'for it in the past.',
        ], $answer->json('data.lines.done'));
        $this->assertSame([
            "1 guardian place (one for each parent and child) has none on record and that family receives nothing from 2nd "
                ."Grade's class story until it is recorded there.",
        ], $answer->json('data.lines.consent'));

        // The run never ends or switches off the class it emptied.
        $this->assertTrue($this->first->fresh()->is_active);
        $this->assertNull($this->first->fresh()->ends_on);
        $this->assertNothingWasDestroyed($before);

        // A class with nobody left to move says so, and offers nobody.
        $empty = $this->previewClass($this->first, $this->second)->assertOk()->assertJsonPath('data.students', []);
        $this->assertSame([
            '1st Grade has no current students to move.',
            '1 student who has already left or moved is not part of this.',
        ], $empty->json('data.lines.who'));
        $this->assertNull($empty->json('data.counts.others_in_new_class'));
    }

    #[Test]
    public function a_refusal_about_the_class_is_one_sentence_and_no_students(): void
    {
        $maryam = $this->enrol($this->first, 'Maryam');
        $this->enrol($this->first, 'Yusuf');

        $halaqa = $this->makeClass('Evening circle', ['kind' => Group::KIND_HALAQA]);
        $off = $this->makeClass('Switched off', ['is_active' => false]);
        $theirs = $this->makeClass('Their class', school: $this->makeSchool());
        $named = fn (Group $class): array => ['id' => $class->id, 'name' => $class->name];

        $cases = [
            [$this->first->id, self::TODAY, '1st Grade cannot be moved into itself.', $named($this->first)],
            [$halaqa->id, self::TODAY, 'Students can only be moved between classes.', $named($halaqa)],
            [$off->id, self::TODAY, 'Switched off is not running: it is switched off or has ended. Choose a class that is running.', $named($off)],
            [$this->second->id, '2026-10-05', 'The first day in the new class cannot be in the future.', $named($this->second)],
            // Another organisation's class is a class that does not exist, and is never named back.
            [$theirs->id, self::TODAY, "Choose one of this school's classes.", null],
            [999999, self::TODAY, "Choose one of this school's classes.", null],
        ];

        $before = $this->rosterTable();

        foreach ($cases as [$to, $on, $sentence, $toGroup]) {
            $this->previewClass($this->first, $to, ['moved_on' => $on])->assertOk()
                ->assertJsonPath('data.can_move', false)
                ->assertJsonPath('data.refusal', $sentence)
                ->assertJsonPath('data.to_group', $toGroup)
                // One sentence, never one copy of it per student.
                ->assertJsonPath('data.students', [])
                ->assertJsonPath('data.lines', ['who' => [], 'consent' => [], 'records' => [], 'bucks' => [], 'afterwards' => []]);

            $this->moveClass($this->first, $to, [[
                'membership_id' => $maryam->id, 'expected_path' => 'left_and_started', 'expected_first_day' => $on,
                'expected_consent' => 'm0f0s0n0e0',
            ]], ['moved_on' => $on])->assertStatus(422)
                ->assertJsonPath('status', 'error')
                ->assertJsonPath('message', $sentence);
        }

        $this->assertEquals($before, $this->rosterTable());
        // Refused before the run's lock was asked for.
        $this->assertSame(0, DB::table('cache_locks')->count());
    }

    #[Test]
    public function each_kind_of_roster_row_is_listed_as_the_single_move_answers_it(): void
    {
        $on = '2026-10-01';

        // Can move, with a registration form's claim beside her.
        $maryam = $this->enrol($this->first, 'Maryam');
        $this->guardian($maryam, 'Stranger', confirmed: false);
        // Her own place is a form's claim: she moves, and arrives unconfirmed.
        $layla = $this->enrol($this->first, 'Layla', confirmed: false);

        // A confirmed guardian in the class entered whom nobody vouches for here.
        $yusufBefore = $this->enrol($this->second, 'Yusuf');
        $this->guardian($yusufBefore, 'Gamal');
        $yusufBefore->markLeftByStaff($this->admin, '2026-09-10')->save();
        $yusuf = $this->enrol($this->first, $yusufBefore->contact);

        // Already current in the class entered.
        $hamza = $this->enrol($this->first, 'Hamza');
        $this->enrol($this->second, $hamza->contact);

        // Listed twice on this roster.
        $bilal = $this->enrol($this->first, 'Bilal');
        $bilalAgain = $this->enrol($this->first, $bilal->contact);

        // Listed in the class entered as a leader.
        $idris = $this->enrol($this->first, 'Idris');
        (new GroupMembership([
            'masjid_id' => $this->school->id, 'group_id' => $this->second->id,
            'contact_id' => $idris->contact_id, 'role' => GroupMembership::ROLE_LEADER,
        ]))->confirmedByStaff($this->admin)->save();

        // Joined this class after the chosen day.
        $zayd = $this->enrol($this->first, 'Zayd', joined: '2026-10-03');

        // A consent the family withdrew where it had been carried would come back.
        $saraThere = $this->enrol($this->second, 'Sara');
        $rania = $this->guardian($saraThere, 'Rania', consent: 'media');
        $sara = GroupMembership::findOrFail($this->move($saraThere, $this->first, '2026-09-20')->assertOk()->json('data.membership_id'));
        $this->withdrawConsent($this->entryIn($this->first, $rania))->assertOk();

        $preview = $this->previewClass($this->first, $this->second, ['moved_on' => $on])->assertOk();
        $rows = collect($preview->json('data.students'))->keyBy('membership_id');

        $this->assertSame(
            [$maryam->id, $layla->id, $yusuf->id, $hamza->id, $bilal->id, $bilalAgain->id, $idris->id, $zayd->id, $sara->id],
            $rows->keys()->all(),
        );

        $this->assertTrue($rows[$maryam->id]['can_move']);
        $this->assertTrue($rows[$layla->id]['can_move']);
        $this->assertContains(
            "Layla Student's own entry in 2nd Grade is not confirmed: it came from a registration form. Open 2nd Grade and "
                ."tap Confirm on Layla Student's row.",
            $rows[$layla->id]['lines'],
        );

        // Every refusal is the single move's, word for word, with the class to open when it names one.
        foreach ([$yusuf, $hamza, $bilal, $bilalAgain, $idris, $zayd, $sara] as $row) {
            $single = $this->previewMove($row, $this->second, $on)->assertOk()->assertJsonPath('data.can_move', false);

            $this->assertFalse($rows[$row->id]['can_move']);
            $this->assertSame($single->json('data.refusal'), $rows[$row->id]['refusal']);
            $this->assertSame($single->json('data.open_group'), $rows[$row->id]['open_group']);
            $this->assertSame(explode("\n", $single->json('data.refusal'))[0], $rows[$row->id]['summary']);
            $this->assertNull($rows[$row->id]['path']);
            $this->assertNull($rows[$row->id]['expected_consent']);
            $this->assertSame([], $rows[$row->id]['lines']);
        }

        $this->assertStringContainsString('Gamal Guardian is a confirmed guardian of Yusuf Student in 2nd Grade but not a confirmed guardian here.', $rows[$yusuf->id]['refusal']);
        $this->assertSame(['id' => $this->second->id, 'name' => '2nd Grade'], $rows[$yusuf->id]['open_group']);
        $this->assertStringContainsString('Hamza Student is already in 2nd Grade.', $rows[$hamza->id]['refusal']);
        $this->assertStringContainsString('Bilal Student appears twice on this roster.', $rows[$bilal->id]['refusal']);
        $this->assertStringContainsString('Idris Student is listed in 2nd Grade as a leader.', $rows[$idris->id]['refusal']);
        $this->assertSame('Zayd Student joined this class on 3 Oct 2026. Choose that day or a later one.', $rows[$zayd->id]['refusal']);
        $this->assertStringContainsString('Rania Guardian withdrew consent in 1st Grade after it had been carried there from 2nd Grade.', $rows[$sara->id]['refusal']);
        // "Open 2nd Grade" can bring the entry the remedy is about into view.
        $this->assertSame($rania->id, $rows[$sara->id]['open_group']['membership_id']);

        // Only the refusal about a consent coming back is counted as held back.
        $preview->assertJsonPath('data.counts.students', 9)
            ->assertJsonPath('data.counts.can_move', 2)
            ->assertJsonPath('data.counts.cannot_move', 7)
            ->assertJsonPath('data.counts.held_back_for_consent', 1)
            ->assertJsonPath('data.counts.students_unconfirmed', 1)
            ->assertJsonPath('data.counts.guardian_form_claims', 1);

        $this->assertContains(
            '1 student cannot be moved because a consent the family has since withdrawn or narrowed would come back into '
                .'force. Their row says what to do.',
            $preview->json('data.lines.consent'),
        );
        $this->assertContains(
            '2 students or guardian places are unconfirmed and stay unconfirmed in 2nd Grade.',
            $preview->json('data.lines.consent'),
        );
        $this->assertSame(
            '7 cannot be moved yet. Each one says why below. They stay in 1st Grade and nothing about them changes.',
            $preview->json('data.lines.who.1'),
        );

        // The office unticks Maryam: she is not named, and nothing about her changes.
        $before = $this->rosterSnapshot();
        $answer = $this->moveClass($this->first, $this->second, $this->ticked($preview, [$layla->id]), ['moved_on' => $on])->assertOk();

        $this->assertSame(['Layla Student' => 'moved'], $this->outcomes($answer));
        $this->assertNull($maryam->fresh()->left_on);
        $this->assertFalse($this->placeIn($this->second, $layla)->isConfirmed(), 'a claim arrived confirmed');
        $this->assertNothingWasDestroyed($before);
    }

    #[Test]
    public function a_row_the_preview_would_not_offer_cannot_be_named(): void
    {
        $maryam = $this->enrol($this->first, 'Maryam');
        $parent = $this->guardian($maryam, 'Huda');
        $left = $this->enrol($this->first, 'Former');
        $left->markLeftByStaff($this->admin, '2026-09-20')->save();
        $leader = new GroupMembership([
            'masjid_id' => $this->school->id, 'group_id' => $this->first->id,
            'contact_id' => $this->makePerson('Old', 'Leader')->id, 'role' => GroupMembership::ROLE_LEADER,
        ]);
        $leader->confirmedByStaff($this->admin)->save();
        $hamza = $this->enrol($this->first, 'Hamza');
        $this->enrol($this->second, $hamza->contact);
        $elsewhere = $this->enrol($this->makeClass('3rd Grade'), 'Elsewhere');

        $theirSchool = $this->makeSchool();
        $theirRow = new GroupMembership([
            'masjid_id' => $theirSchool->id, 'group_id' => $this->makeClass('Their class', school: $theirSchool)->id,
            'contact_id' => $this->makePerson('Their', 'Child', $theirSchool)->id, 'role' => GroupMembership::ROLE_MEMBER,
        ]);
        $theirRow->confirmedByStaff($this->makeAdmin($theirSchool))->save();

        $shown = fn (int $id): array => [
            'membership_id' => $id, 'expected_path' => 'left_and_started', 'expected_first_day' => self::TODAY,
            'expected_consent' => 'm0f0s0n0e0', 'expected_grade' => '',
        ];
        $good = $this->ticked($this->previewClass($this->first, $this->second)->assertOk(), [$maryam->id])[0];
        $before = $this->rosterTable();

        $cases = [
            'a student who has left' => [$left->id, 'Former Student has already left this class.'],
            'a leader' => [$leader->id, 'Only a student moves between classes.'],
            'a guardian entry' => [$parent->id, 'Only a student moves between classes.'],
            'a student who cannot move' => [$hamza->id, 'Hamza Student is already in 2nd Grade.'],
            'a row of another class' => [$elsewhere->id, "This student is no longer on 1st Grade's roster."],
            'a row of another organisation' => [$theirRow->id, "This student is no longer on 1st Grade's roster."],
            'a row that does not exist' => [999999, "This student is no longer on 1st Grade's roster."],
        ];

        foreach ($cases as $what => [$id, $says]) {
            // Named beside a student who can move: one difference refuses the whole request.
            $refused = $this->moveClass($this->first, $this->second, [$good, $shown($id)])->assertStatus(409)
                ->assertJsonPath('status', 'error')
                ->assertJsonPath('message', RosterClassMoveChanged::SENTENCE);

            $differ = $refused->json('data.students');
            $this->assertCount(1, $differ, "{$what}: the student who had not changed was named as changed");
            $this->assertSame($id, $differ[0]['membership_id']);
            $this->assertFalse($differ[0]['can_move']);
            $this->assertStringStartsWith($says, $differ[0]['refusal'], $what);
        }

        // A row that is not on this roster is named by its id and nothing else.
        $unknown = $this->moveClass($this->first, $this->second, [$shown($theirRow->id)])->assertStatus(409)->json('data.students.0');
        $this->assertNull($unknown['name']);
        $this->assertStringNotContainsString('Their', json_encode($unknown));

        $this->assertEquals($before, $this->rosterTable());
        $this->assertSame(0, DB::table('cache_locks')->count(), 'a refused request left the lock held');
    }

    #[Test]
    public function the_request_must_name_the_rows_and_say_what_was_shown(): void
    {
        $maryam = $this->enrol($this->first, 'Maryam');
        $good = $this->ticked($this->previewClass($this->first, $this->second)->assertOk())[0];
        $before = $this->rosterTable();

        $post = function (array $body): TestResponse {
            Sanctum::actingAs($this->admin);

            return $this->postJson($this->classMoveUrl($this->first), $body);
        };
        $base = ['to_group_id' => $this->second->id, 'moved_on' => self::TODAY, 'grade_mode' => 'keep'];

        // An absent list never means "everyone in the class when the request lands".
        $post($base)->assertStatus(422)->assertJsonPath('status', 'failed')
            ->assertJsonPath('data.students.0', 'Name the students to move. A class is moved one student at a time, and only the students you ticked.');
        $post($base + ['students' => []])->assertStatus(422)->assertJsonPath('status', 'failed');

        // More than a request may carry.
        $many = array_map(fn (int $i): array => ['membership_id' => 100000 + $i] + $good, range(1, 61));
        $post($base + ['students' => $many])->assertStatus(422)
            ->assertJsonPath('data.students.0', 'Move up to 60 students at a time.');
        $this->assertSame(60, RosterClassMove::MAX_STUDENTS);

        // What happens to grades is the office's choice, each time.
        $post(['grade_mode' => null] + $base + ['students' => [$good]])->assertStatus(422)
            ->assertJsonPath('data.grade_mode.0', 'Choose what happens to grades.');
        $post(['grade_mode' => 'set'] + $base + ['students' => [$good]])->assertStatus(422)
            ->assertJsonPath('data.grade_label.0', 'Type the grade to give everyone.');
        $post(['grade_mode' => 'down'] + $base + ['students' => [$good]])->assertStatus(422);

        // Each row says what was shown for it.
        foreach (['membership_id', 'expected_path', 'expected_first_day', 'expected_consent'] as $missing) {
            $post($base + ['students' => [array_diff_key($good, [$missing => true])]])->assertStatus(422)
                ->assertJsonPath('status', 'failed');
        }

        $post($base + ['students' => [$good, $good]])->assertStatus(422);
        $post($base + ['students' => [['expected_path' => 'sideways'] + $good]])->assertStatus(422);
        $post($base + ['students' => [$good], 'expected_bucks_rule' => 'everything'])->assertStatus(422);
        $post(['to_group_id' => 'second'] + $base + ['students' => [$good]])->assertStatus(422);

        $this->assertEquals($before, $this->rosterTable());
        $this->assertNull($maryam->fresh()->left_on);

        // And the same body, well formed, moves her. The rule for Manara Bucks is
        // accepted as the single move accepts it, and says nothing yet.
        $post($base + ['students' => [$good], 'expected_bucks_rule' => 'move'])->assertOk()->assertJsonPath('data.moved', 1);
    }

    // ---------------------------------------------------------------- the run

    #[Test]
    public function a_difference_from_what_was_shown_is_refused_before_anything_is_written(): void
    {
        $maryam = $this->enrol($this->first, 'Maryam');
        $huda = $this->guardian($maryam, 'Huda', consent: 'media');
        $yusuf = $this->enrol($this->first, 'Yusuf');
        $layla = $this->graded($this->enrol($this->first, 'Layla'), '1st');

        $up = ['grade_mode' => 'up'];
        $ticked = $this->ticked($this->previewClass($this->first, $this->second, $up)->assertOk());

        // While the dialog is open a teacher saves today's register for Yusuf,
        // and the office withdraws the consent Maryam's mother gave.
        $this->plantRecord('attendance_records', $yusuf, ['session_date' => self::TODAY]);
        $this->withdrawConsent($huda)->assertOk();

        $before = $this->rosterTable();

        $refused = $this->moveClass($this->first, $this->second, $ticked, $up)->assertStatus(409)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'This roster changed while you were looking. Nothing was moved. Look again.')
            ->assertJsonPath('open_group', null);

        // The students that differ, each as the preview would now list them.
        // Layla, who did not change, is not among them and was not moved either.
        $differ = collect($refused->json('data.students'))->keyBy('membership_id');
        $this->assertSame([$maryam->id, $yusuf->id], $differ->keys()->all());
        $this->assertSame('m0f0s0n1e0', $differ[$maryam->id]['expected_consent']);
        $this->assertSame('2026-10-05', $differ[$yusuf->id]['first_day_in_new_class']);
        $this->assertSame('Yusuf Student', $differ[$yusuf->id]['name']);
        $this->assertTrue($differ[$yusuf->id]['can_move']);

        $this->assertEquals($before, $this->rosterTable(), 'a refused request moved somebody');
        $this->assertSame(0, DB::table('cache_locks')->count(), 'a refused request left the lock held');

        // THE GRADE IS PART OF WHAT WAS SHOWN. The office read "1st to 2nd"
        // for Layla; an echo of anything else, or another choice about grades
        // than the one the list was drawn for, is a difference.
        $now = $this->ticked($this->previewClass($this->first, $this->second, $up)->assertOk());
        $laylaShown = collect($now)->firstWhere('membership_id', $layla->id);
        $this->assertSame('2nd', $laylaShown['expected_grade']);

        $wrongGrade = collect($now)->map(fn (array $s): array => $s['membership_id'] === $layla->id ? ['expected_grade' => '3rd'] + $s : $s)->all();
        $this->assertSame(
            [$layla->id],
            array_column($this->moveClass($this->first, $this->second, $wrongGrade, $up)->assertStatus(409)->json('data.students'), 'membership_id'),
        );
        $this->assertSame(
            [$layla->id],
            array_column($this->moveClass($this->first, $this->second, $now, ['grade_mode' => 'keep'])->assertStatus(409)->json('data.students'), 'membership_id'),
        );

        // So are the path, and on a return the joining day.
        $wrongPath = collect($now)->map(fn (array $s): array => $s['membership_id'] === $yusuf->id ? ['expected_path' => 'returned'] + $s : $s)->all();
        $this->moveClass($this->first, $this->second, $wrongPath, $up)->assertStatus(409);

        $this->assertEquals($before, $this->rosterTable());

        // What the list says now is accepted, and is what happens.
        $rows = $this->rosterSnapshot();
        $answer = $this->moveClass($this->first, $this->second, $now, $up)->assertOk()->assertJsonPath('data.moved', 3);

        $this->assertSame('2026-10-05', $this->placeIn($this->second, $yusuf)->joined_at->toDateString());
        $this->assertSame('2nd', $this->placeIn($this->second, $layla)->grade_label);
        $this->assertFalse($this->entryIn($this->second, $huda)->consentColumnsAreSet());
        $this->assertSame(['Maryam Student' => 'moved', 'Yusuf Student' => 'moved', 'Layla Student' => 'moved'], $this->outcomes($answer));
        $this->assertNothingWasDestroyed($rows);
    }

    #[Test]
    public function a_joining_day_that_changed_on_a_return_is_a_difference(): void
    {
        $there = $this->enrol($this->second, 'Maryam');
        $maryam = GroupMembership::findOrFail($this->move($there, $this->first, '2026-09-20')->assertOk()->json('data.membership_id'));

        $ticked = $this->ticked($this->previewClass($this->first, $this->second)->assertOk());
        $this->assertSame(RosterMovePlan::RETURNED, $ticked[0]['expected_path']);
        $this->assertSame('2026-09-01', $ticked[0]['expected_joined_on']);

        // The second class takes a register while she is away: the place now
        // counts from the first day back.
        $this->plantRecord('attendance_records', $this->enrol($this->second, 'Bilal'), ['session_date' => '2026-09-27']);

        $refused = $this->moveClass($this->first, $this->second, $ticked)->assertStatus(409);
        $this->assertSame(self::TODAY, $refused->json('data.students.0.joined_on'));
        $this->assertNull($maryam->fresh()->left_on);

        $this->moveEveryone($this->first, $this->second)->assertJsonPath('data.moved', 1);
        $this->assertSame(self::TODAY, $there->fresh()->joined_at->toDateString());
    }

    #[Test]
    public function a_student_refused_under_the_locks_is_reported_and_the_others_move(): void
    {
        $maryam = $this->enrol($this->first, 'Maryam');
        $yusuf = $this->enrol($this->first, 'Yusuf');
        $layla = $this->enrol($this->first, 'Layla');
        $ticked = $this->ticked($this->previewClass($this->first, $this->second)->assertOk());

        // Something about Yusuf is being saved when his turn comes: a held
        // row, a deadlock, or a change between the check and the move.
        $busy = (int) $yusuf->contact_id;
        $seen = (object) ['moves' => []];
        $this->bindMover(atLocks: function (int $contact) use ($busy): void {
            if ($contact === $busy) {
                throw RosterMoveRefused::changed();
            }
        }, seen: $seen);

        $before = $this->rosterSnapshot();
        $answer = $this->moveClass($this->first, $this->second, $ticked)->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.moved', 2)
            ->assertJsonPath('data.not_moved', 1)
            ->assertJsonPath('data.not_reached', 0)
            ->assertJsonPath('data.stopped_by_fault', false);

        $students = collect($answer->json('data.students'))->keyBy('membership_id');

        $this->assertSame(['Maryam Student' => 'moved', 'Yusuf Student' => 'not_moved', 'Layla Student' => 'moved'], $this->outcomes($answer));
        $this->assertSame([
            'membership_id' => $yusuf->id, 'name' => 'Yusuf Student', 'outcome' => 'not_moved',
            'reason' => RosterMoveRefused::CHANGED, 'open_group' => null, 'retry' => true,
        ], $students[$yusuf->id]);

        // A moved student carries the single move's own sentences.
        $this->assertSame(['membership_id', 'name', 'outcome', 'new_membership_id', 'lines'], array_keys($students[$maryam->id]));
        $this->assertSame($this->placeIn($this->second, $maryam)->id, $students[$maryam->id]['new_membership_id']);
        $this->assertContains('Maryam Student is now in 2nd Grade.', $students[$maryam->id]['lines']);

        $this->assertSame([
            '2 of 3 students are now in 2nd Grade.',
            '1 could not be moved just now because something about them was being saved or had changed. Nothing about them '
                .'was changed.',
            "To put this back: open 2nd Grade, press Move the class, choose 1st Grade, then 'Only the students who came from "
                ."1st Grade' and 'Keep each student's grade'. If you have switched 1st Grade off, switch it on again first.",
        ], $answer->json('data.lines.done'));

        // The refusal rolled back that student only.
        $this->assertNull($yusuf->fresh()->left_on);
        $this->assertSame(0, GroupMembership::where('group_id', $this->second->id)->where('contact_id', $yusuf->contact_id)->count());
        $this->assertNotNull($layla->fresh()->left_on);

        // ONE attempt per student inside a run: a busy student is reported and
        // offered again, so the single move's three would only spend the time.
        $this->assertSame([1, 1, 1], array_column($seen->moves, 'attempts'));
        $this->assertSame(3, RosterMove::ATTEMPTS);

        // A REAL REFUSAL IS NOT OFFERED AGAIN. The class entered is switched
        // off after the first student of the next run: each later student is
        // refused with the single move's sentence, and the run goes on to say so.
        $bilal = $this->enrol($this->first, 'Bilal');
        $again = $this->ticked($this->previewClass($this->first, $this->second)->assertOk(), [$bilal->id, $yusuf->id]);
        $this->bindMover(afterMove: function (int $nth): void {
            if ($nth === 1) {
                $this->second->forceFill(['is_active' => false])->save();
            }
        });

        $answer = $this->moveClass($this->first, $this->second, $again)->assertOk()
            ->assertJsonPath('data.moved', 1)
            ->assertJsonPath('data.not_moved', 1);

        $this->assertSame([
            'membership_id' => $yusuf->id, 'name' => 'Yusuf Student', 'outcome' => 'not_moved',
            'reason' => '2nd Grade is not running: it is switched off or has ended. Choose a class that is running.',
            'open_group' => null, 'retry' => false,
        ], $answer->json('data.students.1'));
        $this->assertSame('1 of 2 students is now in 2nd Grade.', $answer->json('data.lines.done.0'));
        $this->assertSame('1 was not moved. The reason is under their name. Nothing about them was changed.', $answer->json('data.lines.done.1'));
        $this->assertNull($yusuf->fresh()->left_on);

        $this->assertNothingWasDestroyed($before);
    }

    #[Test]
    public function a_fault_stops_the_run_and_the_answer_says_who_was_moved_who_was_not_and_who_was_not_reached(): void
    {
        Exceptions::fake();

        $rows = collect(['Maryam', 'Yusuf', 'Layla', 'Zayd'])->map(fn (string $name) => $this->enrol($this->first, $name));
        $ticked = $this->ticked($this->previewClass($this->first, $this->second)->assertOk());

        $this->bindMover(atLocks: function (int $contact, int $nth): void {
            if ($nth === 2) {
                throw new \RuntimeException('a fault that is not a refusal');
            }
        });

        $before = $this->rosterSnapshot();

        // Still a 200: an error page would hide which students were already moved.
        $answer = $this->moveClass($this->first, $this->second, $ticked)->assertOk()
            ->assertJsonPath('data.moved', 1)
            ->assertJsonPath('data.not_moved', 1)
            ->assertJsonPath('data.not_reached', 2)
            ->assertJsonPath('data.stopped_by_fault', true);

        $this->assertSame(
            ['Maryam Student' => 'moved', 'Yusuf Student' => 'not_moved', 'Layla Student' => 'not_reached', 'Zayd Student' => 'not_reached'],
            $this->outcomes($answer),
        );
        $this->assertSame([
            'membership_id' => $rows[1]->id, 'name' => 'Yusuf Student', 'outcome' => 'not_moved',
            'reason' => 'A fault stopped this move. Nothing about this student was changed.', 'open_group' => null, 'retry' => false,
        ], $answer->json('data.students.1'));
        $this->assertSame(['membership_id', 'name', 'outcome'], array_keys($answer->json('data.students.2')));

        $this->assertSame([
            '1 of 4 students is now in 2nd Grade.',
            'The move stopped early because something went wrong on our side. 1 student was moved before it and the rest '
                .'were not touched. It has been recorded. You can move the rest again.',
            "To put this back: open 2nd Grade, press Move the class, choose 1st Grade, then 'Only the students who came from "
                ."1st Grade' and 'Keep each student's grade'. If you have switched 1st Grade off, switch it on again first.",
        ], $answer->json('data.lines.done'));

        // It was handed to the application's handler, and its text is not in the answer.
        Exceptions::assertReported(\RuntimeException::class);
        $this->assertStringNotContainsString('a fault that is not a refusal', $answer->getContent());

        // The student already moved stays moved; the one in hand was rolled
        // back whole; the rest were never started.
        $this->assertNotNull($rows[0]->fresh()->left_on);
        foreach ([$rows[1], $rows[2], $rows[3]] as $row) {
            $this->assertNull($row->fresh()->left_on);
            $this->assertSame(0, GroupMembership::where('group_id', $this->second->id)->where('contact_id', $row->contact_id)->count());
        }

        // The run's lock was given back, so the rest can be moved again at once.
        $this->assertSame(0, DB::table('cache_locks')->count());
        $this->bindMover();
        $this->moveEveryone($this->first, $this->second)->assertJsonPath('data.moved', 3);

        $this->assertNothingWasDestroyed($before);
    }

    #[Test]
    public function one_run_at_a_time_leaves_a_class_and_its_lock_is_on_the_database_store(): void
    {
        $maryam = $this->enrol($this->first, 'Maryam');
        $third = $this->makeClass('3rd Grade');
        $this->enrol($third, 'Yusuf');
        $ticked = $this->ticked($this->previewClass($this->first, $this->second)->assertOk());

        // The suite's default cache store is `array`, which would prove an
        // in-process lock only. The run names its store.
        $this->assertSame('array', config('cache.default'));
        $this->assertSame('database', RosterClassMove::LOCK_STORE);
        $this->assertSame('roster-class-move:'.$this->first->id, RosterClassMove::lockName($this->first));

        $other = Cache::store('database')->lock(RosterClassMove::lockName($this->first), 120);
        $this->assertTrue($other->get());

        $this->moveClass($this->first, $this->second, $ticked)->assertStatus(409)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'A move out of 1st Grade is still running (it may be yours). Wait two minutes, then reload this roster.');
        $this->assertNull($maryam->fresh()->left_on);

        // The preview takes no lock, and a run out of ANOTHER class into the
        // same class is not held up: it names different students.
        $this->previewClass($this->first, $this->second)->assertOk()->assertJsonPath('data.can_move', true);
        $this->moveEveryone($third, $this->second)->assertJsonPath('data.moved', 1);

        // A lock of that name on the default store is not the run's lock.
        $other->release();
        $this->assertTrue(Cache::lock(RosterClassMove::lockName($this->first), 120)->get());

        // Held for the length of the run, seen from inside one student's move, and given back after it.
        $held = null;
        $name = RosterClassMove::lockName($this->first);
        $this->bindMover(atLocks: function () use (&$held, $name): void {
            $held = DB::table('cache_locks')->where('key', 'like', '%'.$name)->count();
        });

        $this->moveClass($this->first, $this->second, $ticked)->assertOk()->assertJsonPath('data.moved', 1);
        $this->assertSame(1, $held, 'the run did not hold its lock on the database store while a student was moved');
        $this->assertSame(0, DB::table('cache_locks')->count());

        // A lock left behind by a process that was killed lapses by itself.
        $layla = $this->enrol($this->first, 'Layla');
        $this->assertTrue(Cache::store('database')->lock($name, RosterClassMove::LOCK_SECONDS)->get());
        $again = $this->ticked($this->previewClass($this->first, $this->second)->assertOk());
        $this->moveClass($this->first, $this->second, $again)->assertStatus(409);
        Carbon::setTestNow(now()->addSeconds(RosterClassMove::LOCK_SECONDS + 1));
        $this->moveClass($this->first, $this->second, $again)->assertOk()->assertJsonPath('data.moved', 1);
        $this->assertNotNull($layla->fresh()->left_on);

        // The run locks no roster row itself and opens no transaction of its
        // own: one transaction per student is the single move's.
        $source = preg_replace('~^\s*(/\*\*|\*|//).*$~m', '', file_get_contents(app_path('Support/RosterClassMove.php')));
        foreach (['lockForUpdate', 'sharedLock', 'DB::transaction', 'beginTransaction'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source);
        }
        $this->assertStringContainsString('Cache::store(self::LOCK_STORE)->lock(self::lockName($from), self::LOCK_SECONDS)', $source);
        $this->assertMatchesRegularExpression('/\} finally \{\s+\$lock->release\(\);/', $source);
        $this->assertGreaterThan(RosterClassMove::BUDGET_SECONDS, RosterClassMove::LOCK_SECONDS);
    }

    #[Test]
    public function the_run_starts_no_student_after_its_time_budget_and_says_who_was_not_reached(): void
    {
        $rows = collect(['Maryam', 'Yusuf', 'Layla'])->map(fn (string $name) => $this->enrol($this->first, $name));
        $ticked = $this->ticked($this->previewClass($this->first, $this->second)->assertOk());

        $this->assertSame(40, RosterClassMove::BUDGET_SECONDS);

        // The first student's move takes the whole budget.
        $this->bindMover(afterMove: function (int $nth): void {
            if ($nth === 1) {
                Carbon::setTestNow(now()->addSeconds(RosterClassMove::BUDGET_SECONDS));
            }
        });

        $before = $this->rosterSnapshot();
        $answer = $this->moveClass($this->first, $this->second, $ticked)->assertOk()
            ->assertJsonPath('data.moved', 1)
            ->assertJsonPath('data.not_moved', 0)
            ->assertJsonPath('data.not_reached', 2)
            ->assertJsonPath('data.stopped_by_fault', false);

        $this->assertSame(['Maryam Student' => 'moved', 'Yusuf Student' => 'not_reached', 'Layla Student' => 'not_reached'], $this->outcomes($answer));
        $this->assertSame('2 were not reached before the time ran out. Nothing about them was changed.', $answer->json('data.lines.done.1'));
        $this->assertNull($rows[1]->fresh()->left_on);
        $this->assertNull($rows[2]->fresh()->left_on);

        // One second inside the budget, the next student is still started.
        $this->bindMover(afterMove: function (int $nth): void {
            if ($nth === 1) {
                Carbon::setTestNow(now()->addSeconds(RosterClassMove::BUDGET_SECONDS - 1));
            }
        });

        $rest = $this->ticked($this->previewClass($this->first, $this->second)->assertOk());
        $this->moveClass($this->first, $this->second, $rest)->assertOk()
            ->assertJsonPath('data.moved', 2)
            ->assertJsonPath('data.not_reached', 0);

        $this->assertNothingWasDestroyed($before);
    }

    #[Test]
    public function every_move_of_a_run_is_handed_one_day_one_run_and_the_roster_as_it_stood_before_it(): void
    {
        $this->enrol($this->first, 'Maryam');
        $this->enrol($this->first, 'Yusuf');
        $ticked = $this->ticked($this->previewClass($this->first, $this->second)->assertOk());
        $standing = (int) DB::table('group_memberships')->max('id');

        // The budget is not what is under test here: this run's clock stands still.
        $this->app->bind(RosterClassMove::class, fn ($app) => new class($app->make(RosterMove::class)) extends RosterClassMove {
            protected function clock(): float
            {
                return 0.0;
            }
        });

        // Between the two students the school's midnight passes: 00:30 on Monday in New York.
        $seen = (object) ['moves' => []];
        $this->bindMover(afterMove: function (int $nth): void {
            if ($nth === 1) {
                Carbon::setTestNow('2026-10-05 04:30:00');
            }
        }, seen: $seen);

        $before = $this->rosterSnapshot();
        $answer = $this->moveClass($this->first, $this->second, $ticked)->assertOk()->assertJsonPath('data.moved', 2);

        $this->assertSame('2026-10-05', SchoolCalendar::for($this->school->id)->today());

        // Both students were judged on the day the run began. (From the commit
        // that lets a move carry Manara Bucks, that is what gives two students
        // of one run the same rule on a class's last day.)
        $this->assertSame([self::TODAY, self::TODAY], array_column($seen->moves, 'today'));
        $this->assertSame([$standing, $standing], array_column($seen->moves, 'standing_before_id'));
        $this->assertSame([$answer->json('data.run'), $answer->json('data.run')], array_column($seen->moves, 'run'));
        $this->assertSame([1, 1], array_column($seen->moves, 'attempts'));

        // `keep` sends no grade: a new place copies the label, a re-opened one keeps its own.
        $this->assertArrayNotHasKey('grade_given', $seen->moves[0]);
        // And each was handed what the office was shown for that student.
        $this->assertSame($ticked[1]['expected_consent'], $seen->moves[1]['expected_consent']);
        $this->assertSame($ticked[1]['expected_first_day'], $seen->moves[1]['expected_first_day']);
        $this->assertNull($seen->moves[1]['expected_bucks_rule']);

        $this->assertNothingWasDestroyed($before);
    }

    // ------------------------------------------------------------- the grades

    #[Test]
    public function the_three_grade_choices_and_a_class_put_back_has_its_own_grades_again(): void
    {
        $this->assertSame('KG', GradeLevel::next('Pre-K'));
        $this->assertSame('1st', GradeLevel::next('KG'));
        $this->assertSame('1st', GradeLevel::next('Kindergarten'));
        $this->assertSame('4th', GradeLevel::next('Grade 3'));
        $this->assertSame('2nd', GradeLevel::next('first grade'));
        $this->assertSame('12th', GradeLevel::next('11th'));
        // No next level to give: the last one, a label it cannot read, and none.
        $this->assertNull(GradeLevel::next('12th'));
        $this->assertNull(GradeLevel::next('Blue group'));
        $this->assertNull(GradeLevel::next(''));
        $this->assertNull(GradeLevel::next(null));

        $held = ['Maryam' => '1st', 'Yusuf' => 'KG', 'Layla' => 'Grade 3', 'Zayd' => '12th', 'Idris' => 'Blue group', 'Hamza' => null];
        $rows = collect($held)->map(fn (?string $grade, string $name) => $this->graded($this->enrol($this->first, $name), $grade));
        $byName = fn (TestResponse $preview, string $key): array => collect($preview->json('data.students'))
            ->mapWithKeys(fn (array $s): array => [explode(' ', $s['name'])[0] => $s[$key]])->all();
        $before = $this->rosterSnapshot();

        // NO CHOICE YET: the list shows what `keep` would give, and says nothing about grades.
        $none = $this->previewClass($this->first, $this->second)->assertOk();
        $this->assertSame($held, $byName($none, 'grade_after'));
        $this->assertStringNotContainsString('grade', implode(' ', $none->json('data.lines.records')));

        // UP ONE. A label it cannot read, the last grade and no grade are kept, and each row says so.
        $up = $this->previewClass($this->first, $this->second, ['grade_mode' => 'up'])->assertOk();
        $moved = ['Maryam' => '2nd', 'Yusuf' => '1st', 'Layla' => '4th', 'Zayd' => '12th', 'Idris' => 'Blue group', 'Hamza' => null];

        $this->assertSame($held, $byName($up, 'grade_label'));
        $this->assertSame($moved, $byName($up, 'grade_after'));
        $this->assertSame([
            'Maryam' => null, 'Yusuf' => null, 'Layla' => null,
            'Zayd' => '12th cannot be moved up one, so it is kept.',
            'Idris' => 'Blue group cannot be moved up one, so it is kept.',
            'Hamza' => 'No grade is recorded, so none is given.',
        ], $byName($up, 'grade_note'));
        $this->assertContains(
            'Each grade goes up one. 3 students have no grade, a grade this cannot read, or are already in the last grade, '
                .'and nothing changes for theirs.',
            $up->json('data.lines.records'),
        );

        $this->moveClass($this->first, $this->second, $this->ticked($up), ['grade_mode' => 'up'])->assertOk()->assertJsonPath('data.moved', 6);

        foreach ($rows as $name => $row) {
            $this->assertSame($moved[$name], $this->placeIn($this->second, $row)->grade_label, $name);
            // The place that was left keeps the grade it had.
            $this->assertSame($held[$name], $row->fresh()->grade_label, $name);
        }

        // BACK, WITH "KEEP". A return re-opens a place and only a NEW place
        // copies the label, so each student gets the grade recorded on the
        // place they go back to: the class has its original grades again.
        $back = $this->previewClass($this->second, $this->first, ['grade_mode' => 'keep'])->assertOk();

        $this->assertSame(array_fill_keys(array_keys($held), RosterMovePlan::RETURNED), $byName($back, 'path'));
        $this->assertSame($moved, $byName($back, 'grade_label'));
        $this->assertSame($held, $byName($back, 'grade_after'));
        $this->assertSame([
            'Everything recorded for each student (register, marks, report cards, points, ḥifẓ, letters) stays with 2nd '
                .'Grade, where they will be shown as moved.',
            '6 students go back to the place they held in 1st Grade before, which opens again with everything recorded on it.',
            'Each student keeps their grade, except 3 students going back to a place they held before: each gets the grade '
                .'recorded on that place. The list shows each one.',
        ], $back->json('data.lines.records'));
        $this->assertSame(
            'Goes back to the place they held in 1st Grade, from 4 Oct 2026, with no guardian on this roster.',
            $back->json('data.students.0.summary'),
        );

        $this->moveClass($this->second, $this->first, $this->ticked($back), ['grade_mode' => 'keep'])->assertOk()->assertJsonPath('data.moved', 6);

        foreach ($rows as $name => $row) {
            $this->assertNull($row->fresh()->left_on, "{$name} is not back on the place they held");
            $this->assertSame($held[$name], $row->fresh()->grade_label, "{$name} did not get their original grade back");
        }

        // ONE GRADE FOR EVERYONE, on a return too.
        $set = $this->previewClass($this->first, $this->second, ['grade_mode' => 'set', 'grade_label' => 'Level 2'])->assertOk();
        $this->assertSame(array_fill_keys(array_keys($held), 'Level 2'), $byName($set, 'grade_after'));
        $this->assertContains("Every student's grade becomes Level 2.", $set->json('data.lines.records'));

        $this->moveClass($this->first, $this->second, $this->ticked($set), ['grade_mode' => 'set', 'grade_label' => 'Level 2'])
            ->assertOk()->assertJsonPath('data.moved', 6);

        foreach ($rows as $name => $row) {
            $this->assertSame('Level 2', $this->placeIn($this->second, $row)->grade_label, $name);
        }

        $this->assertNothingWasDestroyed($before);
    }

    // ---------------------------------------------- two children of one family

    #[Test]
    public function two_children_of_one_family_get_the_same_result_whichever_is_moved_first(): void
    {
        $run = function (int $n, bool $reversed): array {
            $from = $this->makeClass("Class {$n}A");
            $to = $this->makeClass("Class {$n}B");
            $huda = $this->makePerson('Huda', 'Guardian');
            $gamal = $this->makePerson('Gamal', 'Guardian');
            $maryam = $this->enrol($from, $this->makePerson('Maryam', 'Student'));
            $yusuf = $this->enrol($from, $this->makePerson('Yusuf', 'Student'));

            // CROSSED: each parent agreed for one child and has nothing on
            // record for the other. Without the run's own read of the roster
            // as it stood, the second child would find the blank entry the
            // first child's move made a moment earlier, and lose the carry.
            $this->guardian($maryam, $huda, consent: 'media');
            $this->guardian($maryam, $gamal);
            $this->guardian($yusuf, $huda);
            $this->guardian($yusuf, $gamal, consent: 'feed');

            $preview = $this->previewClass($from, $to)->assertOk();
            $before = $this->rosterSnapshot();

            $answer = $this->moveClass($from, $to, $this->ticked($preview, $reversed ? [$yusuf->id, $maryam->id] : [$maryam->id, $yusuf->id]))
                ->assertOk()
                // What the list showed for each child is what the move decided under its locks.
                ->assertJsonPath('data.moved', 2)
                ->assertJsonPath('data.siblings_left_behind', 0);

            $this->assertNothingWasDestroyed($before);

            return [
                'carried' => $answer->json('data.counts.consent_carried'),
                'not_carried' => $answer->json('data.counts.consent_not_carried'),
                'entries' => GroupMembership::query()->where('group_id', $to->id)->where('role', GroupMembership::ROLE_GUARDIAN)
                    ->with('contact')->get()
                    ->mapWithKeys(fn (GroupMembership $e): array => [
                        ((int) $e->guardian_of_contact_id === (int) $maryam->contact_id ? 'Maryam' : 'Yusuf').'/'.$e->contact->first_name => $e->consent_scope,
                    ])->sortKeys()->all(),
            ];
        };

        $expected = [
            'carried' => ['media' => 1, 'feed' => 1],
            'not_carried' => 0,
            'entries' => ['Maryam/Gamal' => null, 'Maryam/Huda' => 'media', 'Yusuf/Gamal' => 'feed', 'Yusuf/Huda' => null],
        ];

        $this->assertSame($expected, $run(1, false));
        $this->assertSame($expected, $run(2, true));
    }

    #[Test]
    public function a_sibling_going_back_and_a_sibling_arriving_in_one_run_is_reported_and_carries_no_more_than_the_cap(): void
    {
        $huda = $this->makePerson('Huda', 'Guardian');

        // Maryam was in the second class before, her mother beside her with nothing recorded.
        $maryamThere = $this->enrol($this->second, $this->makePerson('Maryam', 'Student'));
        $this->guardian($maryamThere, $huda);
        $maryam = GroupMembership::findOrFail($this->move($maryamThere, $this->first, '2026-09-20')->assertOk()->json('data.membership_id'));
        // Yusuf enters it for the first time, and his mother agreed to photographs for him.
        $yusuf = $this->enrol($this->first, $this->makePerson('Yusuf', 'Student'));
        $this->guardian($yusuf, $huda, consent: 'media');

        // Before the run her entry there is closed, so it gives her no standing: carried.
        $preview = $this->previewClass($this->first, $this->second)->assertOk();
        $shown = collect($preview->json('data.students'))->keyBy('membership_id');
        $this->assertSame(RosterMovePlan::RETURNED, $shown[$maryam->id]['path']);
        $this->assertSame('m1f0s0n0e0', $shown[$yusuf->id]['expected_consent']);

        // Maryam first: her return re-opens the old, blank entry, which has an
        // old id and so stands "before the run". Under Yusuf's locks it caps
        // the carry, which is not what the office was shown: he is not moved.
        $before = $this->rosterSnapshot();
        $answer = $this->moveClass($this->first, $this->second, $this->ticked($preview, [$maryam->id, $yusuf->id]))->assertOk()
            ->assertJsonPath('data.moved', 1)
            ->assertJsonPath('data.not_moved', 1)
            ->assertJsonPath('data.siblings_left_behind', 1);

        $this->assertSame([
            'membership_id' => $yusuf->id, 'name' => 'Yusuf Student', 'outcome' => 'not_moved',
            'reason' => RosterMoveRefused::LOOK_AGAIN, 'open_group' => null, 'retry' => true,
        ], $answer->json('data.students.1'));
        $this->assertContains(
            '1 student who was not moved has a brother or sister who was. The next check can show a different consent result '
                .'for their guardians than this one did; read it before you move them.',
            $answer->json('data.lines.done'),
        );
        $this->assertNull($yusuf->fresh()->left_on);
        $this->assertSame(0, GroupMembership::where('group_id', $this->second->id)->where('guardian_of_contact_id', $yusuf->contact_id)->count());

        // The next check shows the cap before anything is moved, and names her.
        $fresh = $this->previewClass($this->first, $this->second)->assertOk()
            ->assertJsonPath('data.counts.consent_not_carried', 1)
            ->assertJsonPath('data.students.0.expected_consent', 'm0f0s1n0e0');
        $this->assertContains(
            'Huda Guardian is already in 2nd Grade for another child, with no consent recorded there: not carried. Record it '
                .'in 2nd Grade if the family agrees.',
            $fresh->json('data.students.0.lines'),
        );

        $this->moveClass($this->first, $this->second, $this->ticked($fresh))->assertOk()->assertJsonPath('data.moved', 1);
        $this->assertFalse(
            GroupMembership::where('group_id', $this->second->id)->where('contact_id', $huda->id)
                ->where('guardian_of_contact_id', $yusuf->contact_id)->sole()->consentColumnsAreSet(),
            'more was carried than the adult held in the class entered',
        );
        $this->assertNothingWasDestroyed($before);
    }

    #[Test]
    public function a_run_that_leaves_a_brother_or_sister_behind_says_so_and_the_next_check_shows_the_cap(): void
    {
        $huda = $this->makePerson('Huda', 'Guardian');
        $gamal = $this->makePerson('Gamal', 'Guardian');
        $maryam = $this->enrol($this->first, $this->makePerson('Maryam', 'Student'));
        $yusuf = $this->enrol($this->first, $this->makePerson('Yusuf', 'Student'));
        $this->guardian($maryam, $huda, consent: 'media');
        $this->guardian($maryam, $gamal);
        $this->guardian($yusuf, $huda);
        $this->guardian($yusuf, $gamal, consent: 'feed');

        $preview = $this->previewClass($this->first, $this->second)->assertOk();
        $ticked = $this->ticked($preview, [$maryam->id, $yusuf->id]);
        $this->assertSame('m0f1s0n1e0', $ticked[1]['expected_consent']);

        // The time runs out between the two of them.
        $this->bindMover(afterMove: function (int $nth): void {
            if ($nth === 1) {
                Carbon::setTestNow(now()->addSeconds(RosterClassMove::BUDGET_SECONDS));
            }
        });

        $before = $this->rosterSnapshot();
        $answer = $this->moveClass($this->first, $this->second, $ticked)->assertOk()
            ->assertJsonPath('data.moved', 1)
            ->assertJsonPath('data.not_reached', 1)
            ->assertJsonPath('data.siblings_left_behind', 1);

        $this->assertContains(
            '1 student who was not moved has a brother or sister who was. The next check can show a different consent result '
                .'for their guardians than this one did; read it before you move them.',
            $answer->json('data.lines.done'),
        );

        // "Move the rest" is a NEW run, and the blank entry the first run made
        // for their father now stands in the class: less is carried for Yusuf
        // than the first list said, never more.
        $this->bindMover();
        $fresh = $this->previewClass($this->first, $this->second)->assertOk()
            ->assertJsonPath('data.students.0.membership_id', $yusuf->id)
            ->assertJsonPath('data.students.0.expected_consent', 'm0f0s1n1e0')
            ->assertJsonPath('data.counts.consent_not_carried', 1)
            ->assertJsonPath('data.counts.consent_none_but_receives', 1);

        $this->assertContains(
            'Gamal Guardian is already in 2nd Grade for another child, with no consent recorded there: not carried. Record it '
                .'in 2nd Grade if the family agrees.',
            $fresh->json('data.students.0.lines'),
        );

        // What the first list showed for him is no longer what would happen.
        $this->moveClass($this->first, $this->second, [$ticked[1]])->assertStatus(409)
            ->assertJsonPath('message', RosterClassMoveChanged::SENTENCE);
        $this->assertNull($yusuf->fresh()->left_on);

        $this->moveClass($this->first, $this->second, $this->ticked($fresh))->assertOk()->assertJsonPath('data.moved', 1);
        $this->assertNothingWasDestroyed($before);
    }

    // -------------------------------------------------- putting a class back

    #[Test]
    public function a_class_is_put_back_by_the_same_action_from_the_other_class(): void
    {
        // The second class has a student of its own, who was never in the first.
        $bilal = $this->enrol($this->second, 'Bilal');

        $maryam = $this->enrol($this->first, 'Maryam');
        $huda = $this->guardian($maryam, 'Huda', consent: 'media');
        $yusuf = $this->enrol($this->first, 'Yusuf');
        $gamal = $this->guardian($yusuf, 'Gamal', consent: 'feed');
        $layla = $this->enrol($this->first, 'Layla');
        $nadia = $this->guardian($layla, 'Nadia', consent: 'media');
        $zayd = $this->enrol($this->first, 'Zayd');

        $before = $this->rosterSnapshot();
        $this->moveEveryone($this->first, $this->second)->assertJsonPath('data.moved', 4)
            ->assertJsonPath('data.counts.consent_carried', ['media' => 2, 'feed' => 1]);

        // Since the move, Layla's mother withdrew her consent in the second class.
        $this->withdrawConsent($this->entryIn($this->second, $nadia))->assertOk();

        $back = $this->previewClass($this->second, $this->first, ['grade_mode' => 'keep'])->assertOk();
        $rows = collect($back->json('data.students'))->keyBy('name');

        // The four who came from the first class say so; the class's own student does not.
        $this->assertSame(
            ['Bilal Student' => false, 'Maryam Student' => true, 'Yusuf Student' => true, 'Layla Student' => true, 'Zayd Student' => true],
            $rows->map(fn (array $s): bool => $s['came_from_target'])->all(),
        );

        foreach (['Maryam Student', 'Yusuf Student', 'Zayd Student'] as $name) {
            $this->assertSame(RosterMovePlan::RETURNED, $rows[$name]['path']);
        }
        $this->assertSame(RosterMovePlan::LEFT_AND_STARTED, $rows['Bilal Student']['path']);
        $this->assertSame(
            'Goes back to the place they held in 1st Grade, from 4 Oct 2026, with 1 guardian; consent already recorded there '
                .'in force again for 1.',
            $rows['Maryam Student']['summary'],
        );

        // BEFORE THE TAP: what comes back into force, and who is stopped
        // because a consent the family has since withdrawn would.
        $this->assertFalse($rows['Layla Student']['can_move']);
        $this->assertStringContainsString(
            'Nadia Guardian withdrew consent in 2nd Grade after it had been carried there from 1st Grade.',
            $rows['Layla Student']['refusal'],
        );
        $this->assertSame(
            ['id' => $this->first->id, 'name' => '1st Grade', 'membership_id' => $nadia->id],
            $rows['Layla Student']['open_group'],
        );

        $back->assertJsonPath('data.counts.held_back_for_consent', 1)
            ->assertJsonPath('data.counts.consent_in_force_again', ['media' => 1, 'feed' => 1])
            ->assertJsonPath('data.counts.consent_carried', ['media' => 0, 'feed' => 0]);

        $this->assertSame([
            "2 guardian places (one for each parent and child) go onto 1st Grade's list with their students. They stay on "
                ."2nd Grade's list too, marked as left.",
            'Consent already recorded in 1st Grade comes back into force for 2 guardian places (1 with photographs). Each is '
                .'named under their student.',
            '1 student cannot be moved because a consent the family has since withdrawn or narrowed would come back into '
                .'force. Their row says what to do.',
        ], $back->json('data.lines.consent'));
        $this->assertContains('1 student starts fresh in 1st Grade.', $back->json('data.lines.records'));
        $this->assertContains(
            '3 students go back to the place they held in 1st Grade before, which opens again with everything recorded on it.',
            $back->json('data.lines.records'),
        );

        // THE FILTER: only the students who came from the first class, and of those the ones who can move.
        $only = collect($back->json('data.students'))->filter(fn (array $s): bool => $s['came_from_target'] && $s['can_move'])
            ->pluck('membership_id')->all();
        $answer = $this->moveClass($this->second, $this->first, $this->ticked($back, $only), ['grade_mode' => 'keep'])->assertOk()
            ->assertJsonPath('data.moved', 3)
            ->assertJsonPath('data.counts.consent_in_force_again', ['media' => 1, 'feed' => 1]);

        // IN THE RESULT too, and each guardian is named under their student.
        $this->assertSame([
            'Consent already recorded in 1st Grade is in force again for 2 guardian places (one for each parent and child). '
                .'Each is named under their student.',
        ], $answer->json('data.lines.consent'));
        $this->assertContains(
            'Consent already recorded in 1st Grade is in force again: Huda Guardian (class story and photographs, recorded '
                ."4 Sep 2026). To withdraw a family's consent completely, withdraw it in both classes.",
            $answer->json('data.students.0.lines'),
        );

        // Each is back on the very place they held, with what was recorded on it.
        foreach ([$maryam, $yusuf, $zayd] as $row) {
            $this->assertNull($row->fresh()->left_on);
            $this->assertSame($row->id, $this->placeIn($this->first, $row)->id);
        }
        $this->assertTrue($huda->fresh()->hasConsent());
        $this->assertNull($huda->fresh()->left_on);
        $this->assertNull($gamal->fresh()->left_on);

        // The class's own student, and the one who was held back, were not named and did not move.
        $this->assertNull($bilal->fresh()->left_on);
        $this->assertSame(0, GroupMembership::where('group_id', $this->first->id)->where('contact_id', $bilal->contact_id)->count());
        $this->assertNotNull($layla->fresh()->left_on);
        $this->assertNotNull($nadia->fresh()->left_on, 'a consent the family withdrew where it was carried came back into force');

        $this->assertNothingWasDestroyed($before);
    }

    // ------------------------------------------------------------ the transport

    /**
     * The admin SPA sends `FormData`: the rows arrive as
     * `students[0][membership_id]`, every number is a string and a blank
     * joining day or grade is an empty string. Every other test here posts
     * JSON, which would pass a rule that a form-encoded body trips.
     */
    #[Test]
    public function a_form_encoded_body_of_strings_moves_a_returning_student_and_a_student_with_no_grade(): void
    {
        $there = $this->graded($this->enrol($this->second, 'Maryam'), '2nd');
        $maryam = GroupMembership::findOrFail($this->move($there, $this->first, '2026-09-20')->assertOk()->json('data.membership_id'));
        $yusuf = $this->enrol($this->first, 'Yusuf');

        $ticked = $this->ticked($this->previewClass($this->first, $this->second, ['grade_mode' => 'keep'])->assertOk());
        $string = fn (mixed $value): string => $value === null ? '' : (string) $value;

        $body = [
            'to_group_id' => (string) $this->second->id,
            'moved_on' => self::TODAY,
            'grade_mode' => 'keep',
            'grade_label' => '',
            'expected_bucks_rule' => '',
            'students' => array_map(fn (array $student): array => array_map($string, $student), $ticked),
        ];

        $this->assertSame([
            ['membership_id' => (string) $maryam->id, 'expected_path' => 'returned', 'expected_first_day' => self::TODAY,
                'expected_joined_on' => '2026-09-01', 'expected_consent' => 'm0f0s0n0e0', 'expected_grade' => '2nd'],
            ['membership_id' => (string) $yusuf->id, 'expected_path' => 'left_and_started', 'expected_first_day' => self::TODAY,
                'expected_joined_on' => self::TODAY, 'expected_consent' => 'm0f0s0n0e0', 'expected_grade' => ''],
        ], $body['students']);
        array_walk_recursive($body, fn ($leaf) => $this->assertIsString($leaf));

        $before = $this->rosterSnapshot();
        Sanctum::actingAs($this->admin);

        $answer = $this->post($this->classMoveUrl($this->first), $body, ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.moved', 2);

        // It arrived as a form, not as JSON, and its numbers as strings.
        $this->assertFalse(app('request')->isJson());
        $this->assertSame((string) $maryam->id, app('request')->input('students.0.membership_id'));

        $this->assertSame(['Maryam Student' => 'moved', 'Yusuf Student' => 'moved'], $this->outcomes($answer));
        $this->assertNull($there->fresh()->left_on);
        $this->assertSame('2nd', $there->fresh()->grade_label);
        $this->assertNull($this->placeIn($this->second, $yusuf)->grade_label);
        $this->assertNothingWasDestroyed($before);

        // And the preview's own query, as a browser sends it.
        $this->get($this->classMoveUrl($this->second).'?to_group_id='.$this->first->id.'&moved_on='.self::TODAY.'&grade_mode=set&grade_label=',
            ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.students.0.grade_after', null);
    }

    // ------------------------------------------------------- who may, and where

    #[Test]
    public function only_the_office_moves_a_class_and_only_its_own(): void
    {
        // The other organisation is made BEFORE the first request: a request
        // binds this school as the tenant, and a row created after that is
        // stamped with it whatever the test wrote.
        $other = $this->makeSchool();
        $theirAdmin = $this->makeAdmin($other);
        $theirClass = $this->makeClass('Their class', school: $other);
        $this->assertSame($other->id, (int) $theirClass->fresh()->masjid_id);

        $maryam = $this->enrol($this->first, 'Maryam');
        $ticked = $this->ticked($this->previewClass($this->first, $this->second)->assertOk());

        foreach ([['Teacher', 'teacher'], [User::TYPE_LUNCH_STAFF, 'lunch-staff']] as [$type, $role]) {
            $staff = $this->makeAdmin($this->school, $type, $role);
            $this->previewClass($this->first, $this->second, as: $staff)->assertUnauthorized();
            $this->moveClass($this->first, $this->second, $ticked, as: $staff)->assertUnauthorized();
        }

        // Another organisation's class in the route does not exist here...
        Sanctum::actingAs($this->admin);
        $url = "/api/admin/masjids/{$this->school->id}/groups/{$theirClass->id}/class-move";
        $this->getJson($url.'?to_group_id='.$this->second->id)->assertNotFound();
        $this->postJson($url, ['to_group_id' => $this->second->id, 'grade_mode' => 'keep', 'students' => $ticked])->assertNotFound();

        // ...and their administrator is refused by the tenant gate, on both verbs.
        $this->previewClass($this->first, $this->second, as: $theirAdmin)->assertForbidden();
        $this->moveClass($this->first, $this->second, $ticked, as: $theirAdmin)->assertForbidden();

        // Seeing the directory is not enough: both verbs take `manage contacts`.
        Role::findByName('masjid-admin', 'web')->revokePermissionTo('manage contacts');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->previewClass($this->first, $this->second)->assertForbidden();
        $this->moveClass($this->first, $this->second, $ticked)->assertForbidden();

        $this->assertNull($maryam->fresh()->left_on);

        // Exactly one URI ends in `/class-move`, it is the office's, and no
        // teacher or family route moves a class. (That exactly one URI ends in
        // `/move` is pinned beside the single move.)
        $routes = collect(app('router')->getRoutes())->filter(fn ($route): bool => str_ends_with($route->uri(), '/class-move'))->values();

        $this->assertCount(2, $routes);
        $this->assertSame(['api/admin/masjids/{masjid_id}/groups/{group_id}/class-move'], $routes->map->uri()->unique()->values()->all());
        $this->assertEqualsCanonicalizing(['GET', 'POST'], $routes->flatMap(fn ($route) => array_diff($route->methods(), ['HEAD']))->all());

        foreach ($routes as $route) {
            $this->assertContains('permission:manage contacts', $route->gatherMiddleware());
        }
    }

    #[Test]
    public function the_moves_of_one_run_share_one_run_in_the_history_and_no_request_can_set_what_only_the_run_may(): void
    {
        $this->logLikeProduction();

        // Yusuf's mother already stands in the second class for his brother,
        // with nothing recorded there: her consent for Yusuf is not carried.
        $huda = $this->makePerson('Huda', 'Guardian');
        $this->guardian($this->enrol($this->second, $this->makePerson('Bilal', 'Student')), $huda);
        $yusuf = $this->enrol($this->first, $this->makePerson('Yusuf', 'Student'));
        $this->guardian($yusuf, $huda, consent: 'media');
        $maryam = $this->enrol($this->first, $this->makePerson('Maryam', 'Student'));

        $preview = $this->previewClass($this->first, $this->second)->assertOk()
            ->assertJsonPath('data.counts.consent_not_carried', 1);

        // `standing_before_id` would switch that cap off; `today` would allow a
        // day that has not come; `run` would write a run into the history;
        // `attempts` would spend the budget. Typed onto the request and onto
        // each row, none of them is read.
        $internal = ['standing_before_id' => 0, 'today' => '2026-10-09', 'attempts' => 9, 'run' => 'typed-into-a-request'];
        $ticked = array_map(fn (array $student): array => $student + $internal, $this->ticked($preview));

        $this->previewClass($this->first, $this->second, ['moved_on' => '2026-10-05'] + $internal)->assertOk()
            ->assertJsonPath('data.can_move', false)
            ->assertJsonPath('data.refusal', 'The first day in the new class cannot be in the future.');
        $this->previewClass($this->first, $this->second, $internal)->assertOk()
            ->assertJsonPath('data.counts.consent_not_carried', 1);
        $this->moveClass($this->first, $this->second, $ticked, ['moved_on' => '2026-10-05'] + $internal)->assertStatus(422)
            ->assertJsonPath('message', 'The first day in the new class cannot be in the future.');

        $before = $this->rosterSnapshot();
        $answer = $this->moveClass($this->first, $this->second, $ticked, $internal)->assertOk()
            ->assertJsonPath('data.moved', 2)
            ->assertJsonPath('data.counts.consent_not_carried', 1)
            ->assertJsonPath('data.counts.consent_carried', ['media' => 0, 'feed' => 0]);

        $this->assertFalse(
            GroupMembership::where('group_id', $this->second->id)->where('contact_id', $huda->id)
                ->where('guardian_of_contact_id', $yusuf->contact_id)->sole()->consentColumnsAreSet(),
        );

        // One id for the run, the run's own, on every student's line. Ids only.
        $run = $answer->json('data.run');
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $run);

        $lines = $this->loggedLines('laravel.log', 'WARNING: roster.move {');
        $this->assertCount(2, $lines);
        foreach ($lines as $line) {
            $this->assertStringContainsString('"run":"'.$run.'"', $line);
            $this->assertStringNotContainsString('Maryam', $line);
            $this->assertStringNotContainsString('Yusuf', $line);
        }
        $this->assertStringContainsString('"membership":'.$yusuf->id, $lines[0]);
        $this->assertStringContainsString('"membership":'.$maryam->id, $lines[1]);

        // No Request class has a rule for any of them, for the class or for a
        // row, and the controller names every field it reads.
        foreach ([new PreviewClassMoveRequest(), new MoveClassRequest()] as $request) {
            foreach (array_keys($request->rules()) as $field) {
                $this->assertNotContains(last(explode('.', $field)), array_keys($internal), "{$field} lets a request set what only the run may");
            }
        }

        $controller = file_get_contents(app_path('Http/Controllers/AdminDashboard/GroupClassMoveController.php'));
        foreach (array_keys($internal) as $key) {
            $this->assertStringNotContainsString("'{$key}'", $controller);
        }
        $this->assertStringNotContainsString('$request->all()', $controller);
        $this->assertStringNotContainsString('$request->validated()', $controller);

        $this->assertNothingWasDestroyed($before);
    }

    #[Test]
    public function nothing_a_class_move_says_carries_a_figure_about_a_childs_bucks(): void
    {
        $this->school->forceFill(['capability_overrides' => [SchoolSettings::CLASS_STORE => true]])->save();

        $maryam = $this->enrol($this->first, 'Maryam');
        $this->guardian($maryam, 'Huda', consent: 'media');
        $yusuf = $this->enrol($this->first, 'Yusuf');
        // A balance no id, day or count in these answers could spell by chance.
        $this->plantRecord('prize_ledger_entries', $maryam, ['amount' => 91735]);
        $ledger = DB::table('prize_ledger_entries')->orderBy('id')->get()->toArray();
        $this->assertSame(91735, (int) $ledger[0]->amount);

        $preview = $this->previewClass($this->first, $this->second)->assertOk();
        $ticked = $this->ticked($preview);
        $stale = [['expected_consent' => 'm0f0s0n0e0'] + $ticked[0], $ticked[1]];
        $refused = $this->moveClass($this->first, $this->second, $stale)->assertStatus(409);
        $answer = $this->moveClass($this->first, $this->second, $ticked)->assertOk()->assertJsonPath('data.moved', 2);

        // The only key about Manara Bucks is the rule the tap echoes (about
        // two classes and the clock, and null until a move can carry a
        // balance) and the group of class sentences that will say it.
        $keys = function (mixed $value) use (&$keys): array {
            return is_array($value)
                ? array_merge(array_filter(array_keys($value), 'is_string'), ...array_map($keys, array_values($value)))
                : [];
        };

        foreach ([$preview, $refused, $answer] as $response) {
            $this->assertStringNotContainsString('91735', $response->getContent());
            $this->assertEqualsCanonicalizing(
                $response === $preview ? ['expected_bucks_rule', 'bucks'] : ($response === $answer ? ['bucks'] : []),
                array_values(array_unique(array_filter($keys($response->json()), fn (string $key): bool => str_contains($key, 'buck')))),
            );
        }

        $preview->assertJsonPath('data.expected_bucks_rule', null)->assertJsonPath('data.lines.bucks', []);
        $answer->assertJsonPath('data.lines.bucks', []);

        // And the run wrote nothing to the ledger.
        $this->assertEquals($ledger, DB::table('prize_ledger_entries')->orderBy('id')->get()->toArray());
        $this->assertNotNull($yusuf->fresh()->left_on);
    }

    // ------------------------------------------------------ the deploy window

    #[Test]
    public function before_the_marker_column_exists_a_class_move_is_refused_with_one_sentence_and_takes_no_lock(): void
    {
        $this->logLikeProduction();

        $maryam = $this->enrol($this->first, 'Maryam');
        $ticked = $this->ticked($this->previewClass($this->first, $this->second)->assertOk());

        // bin/deploy serves the new code, then migrates.
        Schema::table('group_memberships', fn ($table) => $table->dropColumn('consent_carried_from_group_id'));
        GroupMembership::forgetConsentCarryReady();
        $this->assertFalse(RosterMove::ready());

        $before = $this->rosterTable();

        $this->previewClass($this->first, $this->second)->assertOk()
            ->assertJsonPath('data.can_move', false)
            ->assertJsonPath('data.refusal', 'Manara is being updated. Try this move again in a minute.')
            ->assertJsonPath('data.students', []);

        $this->moveClass($this->first, $this->second, $ticked)->assertStatus(409)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'Manara is being updated. Try this move again in a minute.');

        // ONE line per refused request, not one per student, at the level production keeps.
        $lines = $this->loggedLines('laravel.log', 'roster.move.not_ready');
        $this->assertCount(2, $lines);
        $this->assertStringContainsString('.WARNING: roster.move.not_ready', $lines[0]);

        $this->assertEquals($before, $this->rosterTable());
        $this->assertSame(0, DB::table('cache_locks')->count(), 'the lock was asked for before the guard answered');
        $this->assertNull($maryam->fresh()->left_on);

        // The guard is the first statement of the run.
        $this->assertMatchesRegularExpression(
            '/public function run\([^{]*\{\s+(\/\/[^\n]*\s+)*RosterMove::refuseUnlessReady\(\);/',
            file_get_contents(app_path('Support/RosterClassMove.php')),
        );
    }

    // ------------------------------------------------------- the MySQL suite

    /**
     * tests/MysqlLocks/RosterClassMoveLocksTest.php commits its fixtures and
     * deletes them by hand, and it runs only on the MySQL job. A cleanup that
     * names a table that does not exist throws, and every test in the file is
     * then reported as an error whatever its body proved. The names are
     * checked here, where the suite always runs.
     */
    #[Test]
    public function the_lock_suites_cleanup_names_only_tables_and_columns_that_exist(): void
    {
        $source = file_get_contents(base_path('tests/MysqlLocks/RosterClassMoveLocksTest.php'));

        // The hand-written list: the loop that is not over AcademicRecordsHeld::KEYS.
        $this->assertSame(
            1,
            preg_match('/foreach \(\[([^\]]+)\] as \$table\) \{\s+DB::table\(\$table\)->where\(\x27masjid_id\x27/', $source, $m),
            'the cleanup loop was not found',
        );
        preg_match_all("/'([a-z_]+)'/", $m[1], $names);

        $this->assertContains((new \App\Models\MasjidUser())->getTable(), $names[1], "the administrator's pivot row is deleted");
        $this->assertContains((new GroupMembership())->getTable(), $names[1]);

        foreach ([...$names[1], ...array_keys(\App\Support\AcademicRecordsHeld::KEYS)] as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'masjid_id'), "{$table} is deleted by organisation in the lock suite's cleanup");
        }

        // Every other table the file names in a query, with the column it is read or deleted by.
        preg_match_all("/->table\('([a-z_]+)'\)->where\('([a-z_]+)'/", $source, $queried, PREG_SET_ORDER);
        $this->assertNotSame([], $queried);

        foreach ($queried as [, $table, $column]) {
            $this->assertTrue(Schema::hasColumn($table, $column), "the lock suite reads {$table}.{$column}, which does not exist");
        }

        $this->assertContains(['cache_locks', 'key'], array_map(fn (array $q): array => [$q[1], $q[2]], $queried));

        // It declares its own helpers, under names no other MySQL file uses:
        // every file of the group is loaded into one process.
        preg_match_all('/^function (\w+)\(/m', $source, $declared);
        $this->assertNotSame([], $declared[1]);

        foreach (['tests/MysqlLocks/RosterMoveLocksTest.php', 'tests/Mysql/RosterMoveMysqlTest.php'] as $other) {
            preg_match_all('/^function (\w+)\(/m', file_get_contents(base_path($other)), $theirs);
            $this->assertSame([], array_intersect($declared[1], $theirs[1]), "a helper is declared twice: here and in {$other}");
        }
    }
}
