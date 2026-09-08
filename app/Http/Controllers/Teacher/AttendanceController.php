<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Requests\Teacher\SaveAttendanceRequest;
use App\Models\AttendanceRecord;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * The class register, for the teacher who leads the class.
 *
 * Every route here sits inside routes/teacher.php's `teacher.leads` group, so
 * "may this teacher touch this class" is already answered before any method runs.
 * What is left is the narrower question this controller does answer on every
 * call: is this membership a PARTICIPANT OF THIS CLASS. Resolving through
 * `$group->memberships()->participants()` makes a student of another class, or a
 * guardian edge, resolve to 404 rather than to a register row.
 *
 * Payloads are built through TeacherController::student(), the realm's names-only
 * serialization boundary — never from a model, which would leak a family's email
 * and phone to a teacher.
 */
class AttendanceController extends TeacherController
{
    /**
     * The register for ONE day, as the teacher's screen needs it: every student
     * in the class, each with their mark for that day or null if unmarked.
     *
     * Unmarked is a first-class state and is NOT the same as absent. A day nobody
     * has touched must read as "not taken yet", or the first child a teacher taps
     * would silently mark the other eleven present.
     */
    public function index(Request $request, $masjid_id, $group_id): JsonResponse
    {
        $group = Group::findOrFail($group_id);
        $date = $this->sessionDate($request->query('date'));

        $students = $group->memberships()
            ->participants()
            ->with('contact:id,first_name,last_name,'.Contact::AVATAR_COLUMNS)
            ->get();

        $marks = AttendanceRecord::query()
            ->where('group_id', $group->id)
            ->whereDate('session_date', $date)
            ->get()
            ->keyBy('group_membership_id');

        return response()->json([
            'status' => 'success',
            'data' => [
                'session_date' => $date->toDateString(),
                'taken' => $marks->isNotEmpty(),
                'students' => $students->map(function (GroupMembership $m) use ($marks): array {
                    $record = $marks->get($m->id);

                    return $this->student($m) + [
                        'status' => $record?->status,
                        'note' => $record?->note,
                    ];
                })->values(),
            ],
        ], Response::HTTP_OK);
    }

    /**
     * Record the register for one day.
     *
     * Idempotent by construction: each mark is an upsert against the
     * (group_membership_id, session_date) unique index, so a teacher who taps
     * Save twice on a flaky connection ends with one row per child, carrying the
     * last thing they chose — not two contradictory marks, and not a 500 from a
     * duplicate-key collision.
     *
     * The whole thing is one transaction. A register that recorded eight of
     * twelve children would be worse than one that recorded none, because only
     * the second is obviously unfinished.
     */
    public function save(SaveAttendanceRequest $request, $masjid_id, $group_id): JsonResponse
    {
        $group = Group::findOrFail($group_id);
        $date = Carbon::createFromFormat('Y-m-d', $request->validated('session_date'))->startOfDay();

        // The classroom's own students, as the ONLY ids this request may name.
        $allowed = $group->memberships()->participants()->pluck('id');

        $marks = collect($request->validated('marks'));
        $unknown = $marks->pluck('membership_id')->map(fn ($id) => (int) $id)->diff($allowed);

        if ($unknown->isNotEmpty()) {
            return response()->json([
                'status' => 'failed',
                'data' => ['marks' => ['That register names someone who is not a student in this class.']],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        DB::transaction(function () use ($marks, $group, $date) {
            foreach ($marks as $mark) {
                AttendanceRecord::updateOrCreate(
                    [
                        'group_membership_id' => (int) $mark['membership_id'],
                        'session_date' => $date,
                    ],
                    [
                        'masjid_id' => $group->masjid_id,
                        'group_id' => $group->id,
                        'status' => $mark['status'],
                        'note' => $mark['note'] ?? null,
                        'marked_by_user_id' => Auth::id(),
                    ]
                );
            }
        });

        return $this->index(new Request(['date' => $date->toDateString()]), $masjid_id, $group_id);
    }

    /**
     * One child's attendance history, newest first, with the counts a parent
     * conversation actually turns on.
     *
     * `present` deliberately counts LATE as attendance — AttendanceRecord::
     * PRESENT_STATUSES is the single definition, so this summary can never drift
     * from a report that asks the same question elsewhere.
     */
    public function forMember(Request $request, $masjid_id, $group_id, $membership_id): JsonResponse
    {
        $group = Group::findOrFail($group_id);
        $membership = $group->memberships()->participants()->with('contact')->findOrFail($membership_id);

        $records = AttendanceRecord::query()
            ->where('group_membership_id', $membership->id)
            ->orderByDesc('session_date')
            ->limit(200)
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'student' => $this->student($membership),
                'summary' => [
                    'recorded' => $records->count(),
                    'present' => $records->filter->wasPresent()->count(),
                    'absent' => $records->where('status', AttendanceRecord::STATUS_ABSENT)->count(),
                    'excused' => $records->where('status', AttendanceRecord::STATUS_EXCUSED)->count(),
                    'late' => $records->where('status', AttendanceRecord::STATUS_LATE)->count(),
                ],
                'records' => $records->map(fn (AttendanceRecord $r): array => [
                    'session_date' => $r->session_date->toDateString(),
                    'status' => $r->status,
                    'note' => $r->note,
                ])->values(),
            ],
        ], Response::HTTP_OK);
    }

    /**
     * The day being asked about. An absent or unparseable `date` means today —
     * a teacher opening the tab wants this morning's register, not an error.
     */
    private function sessionDate(?string $raw): Carbon
    {
        if (! is_string($raw) || $raw === '') {
            return Carbon::today();
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $raw)->startOfDay();
        } catch (\Throwable) {
            return Carbon::today();
        }
    }
}
