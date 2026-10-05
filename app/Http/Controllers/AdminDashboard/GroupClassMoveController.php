<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Exceptions\RosterClassMoveChanged;
use App\Exceptions\RosterMoveRefused;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Groups\MoveClassRequest;
use App\Http\Requests\Admin\Groups\PreviewClassMoveRequest;
use App\Models\Group;
use App\Models\User;
use App\Support\RosterClassMove;
use App\Support\SchoolCalendar;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Moving a whole class to another class: the read ("what will happen") and the
 * run. A sibling of GroupMoveController, and as thin: every rule about the
 * class is in App\Support\RosterClassMove, and every rule about one student is
 * the single move's (App\Support\RosterMove), which the run calls once per
 * student.
 *
 * The office says who belongs in a class, so both routes are
 * `permission:manage contacts` and there is no teacher or family route.
 *
 * Tenant isolation is the guardrail, not hand-filtering: another
 * organisation's class in the route is a 404, another organisation's class as
 * the target is the same refusal as a class that does not exist, and a roster
 * row is only ever looked up through this class. See
 * .claude/rules/tenant-scoping.md.
 */
class GroupClassMoveController extends Controller
{
    /**
     * GET .../groups/{group_id}/class-move?to_group_id=&moved_on=&grade_mode=&grade_label=
     *
     * Takes no lock and writes nothing. It lists every current student with
     * what the single move would do for them, or why it would not. A refusal
     * about the class as a whole (the target, the day, the deploy window) is a
     * 200 with `can_move: false`, one sentence and no students.
     */
    public function show(PreviewClassMoveRequest $request, RosterClassMove $mover, $masjid_id, $group_id)
    {
        $group = Group::findOrFail($group_id);

        $plan = $mover->preview(
            $group,
            Group::query()->find($request->integer('to_group_id')),
            $this->day($request, $group),
            $this->gradeChoice($request),
        );

        return response()->json(['status' => 'success', 'data' => $plan->toPreview()], Response::HTTP_OK);
    }

    /**
     * POST .../groups/{group_id}/class-move
     *
     * One transaction PER STUDENT. Refused before anything is written, in the
     * single verb's shape `{status: 'error', message}`: 422 when the request
     * cannot be, 409 while Manara is being updated, while another move out of
     * this class is running, and when a student is no longer what the dialog
     * showed (then `data.students` names each one, as the preview would now
     * list them).
     *
     * Once the run has started the answer is a 200 whatever happened to each
     * student: it names every one as moved, not moved (and why) or not
     * reached. An error page would hide which students were already moved.
     *
     * EVERY FIELD IS NAMED HERE. Each move also takes options that only the
     * run may set (its id, the roster as it stood before it, the school's day,
     * one attempt); none is read from the request, so a body that carries one
     * changes nothing.
     */
    public function store(MoveClassRequest $request, RosterClassMove $mover, $masjid_id, $group_id)
    {
        $group = Group::findOrFail($group_id);

        try {
            $plan = $mover->run(
                $group,
                $request->integer('to_group_id'),
                $this->day($request, $group),
                $this->gradeChoice($request),
                $request->input('expected_bucks_rule'),
                $this->students($request),
                $this->actor($request),
            );
        } catch (RosterClassMoveChanged $changed) {
            return response()->json([
                'status' => 'error',
                'message' => $changed->getMessage(),
                'open_group' => null,
                'data' => ['students' => $changed->students()],
            ], $changed->status());
        } catch (RosterMoveRefused $refused) {
            return response()->json([
                'status' => 'error',
                'message' => $refused->getMessage(),
                'open_group' => $refused->openGroup(),
            ], $refused->status());
        }

        $answer = $plan->toAnswer();

        return response()->json([
            'status' => 'success',
            'message' => implode(' ', $answer['lines']['done']),
            'data' => $answer,
        ], Response::HTTP_OK);
    }

    // ------------------------------------------------------------- internals

    /** The day the office chose, or today on the school's clock. */
    private function day(Request $request, Group $group): string
    {
        return $request->input('moved_on') ?: SchoolCalendar::for((int) $group->masjid_id)->today();
    }

    /**
     * What happens to grades, as the office chose it.
     *
     * @return array{mode: ?string, label: ?string}
     */
    private function gradeChoice(Request $request): array
    {
        return ['mode' => $request->input('grade_mode'), 'label' => $request->input('grade_label')];
    }

    /**
     * The students the request names, in its order, each with what the dialog
     * showed for them and nothing else. A blank is null: a form-encoded body
     * has no other way to say "none".
     *
     * @return list<array{membership_id: int, expected_path: string, expected_first_day: string, expected_joined_on: ?string, expected_consent: string, expected_grade: ?string}>
     */
    private function students(Request $request): array
    {
        $blank = fn (mixed $value): ?string => ($value === null || $value === '') ? null : (string) $value;

        return array_values(array_map(fn (array $student): array => [
            'membership_id' => (int) $student['membership_id'],
            'expected_path' => (string) $student['expected_path'],
            'expected_first_day' => (string) $student['expected_first_day'],
            'expected_joined_on' => $blank($student['expected_joined_on'] ?? null),
            'expected_consent' => (string) $student['expected_consent'],
            'expected_grade' => $blank($student['expected_grade'] ?? null),
        ], (array) $request->input('students', [])));
    }

    /** The staff member behind the act, or null: the same read as the sibling verbs. */
    private function actor(Request $request): ?User
    {
        $principal = $request->user();

        return $principal instanceof User ? $principal : null;
    }
}
