<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Requests\Teacher\SaveLessonPlanRequest;
use App\Models\Group;
use App\Models\LessonPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * What a class is planned to cover, one row per day.
 *
 * Addressed by (class, date) throughout — there is no {plan_id} in any route,
 * which is exactly what `lesson_plan_class_day_unique` buys: saving is an upsert
 * and a teacher opening a day either finds its plan or an empty form. No drafts
 * to reconcile, no list of near-duplicates.
 *
 * `teacher.leads` has already answered "may this teacher touch this class"
 * before any method here runs.
 */
class LessonPlanController extends TeacherController
{
    /**
     * The plans in a window, defaulting to the week around today — which is what
     * the week strip on the teacher's screen renders.
     */
    public function index(Request $request, $masjid_id, $group_id): JsonResponse
    {
        $group = Group::findOrFail($group_id);

        $from = $this->dateOr($request->query('from'), Carbon::today()->startOfWeek());
        $to = $this->dateOr($request->query('to'), $from->copy()->addDays(6));

        $plans = LessonPlan::query()
            ->where('group_id', $group->id)
            ->whereBetween('session_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('session_date')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'plans' => $plans->map(fn (LessonPlan $p): array => $this->plan($p))->values(),
            ],
        ], Response::HTTP_OK);
    }

    /**
     * Write one day's plan.
     *
     * An upsert against the per-day unique index, so saving twice corrects the
     * same day rather than minting a second plan. No transaction: this is one
     * row, unlike the register's twelve.
     */
    public function save(SaveLessonPlanRequest $request, $masjid_id, $group_id): JsonResponse
    {
        $group = Group::findOrFail($group_id);

        $plan = LessonPlan::updateOrCreate(
            [
                'group_id' => $group->id,
                'session_date' => $request->validated('session_date'),
            ],
            [
                'masjid_id' => $group->masjid_id,
                'title' => $request->validated('title'),
                'body' => $request->validated('body'),
                'author_user_id' => Auth::id(),
            ]
        );

        return response()->json([
            'status' => 'success',
            'data' => $this->plan($plan),
        ], Response::HTTP_OK);
    }

    /**
     * Remove one day's plan.
     *
     * This verb exists because `body` is NOT NULL: a plan typed against the
     * wrong day cannot be blanked, so without a delete it would be an unfixable
     * row. A hard delete — the table holds no record about a child and no bytes,
     * so there is nothing to retain.
     */
    public function destroy(Request $request, $masjid_id, $group_id): JsonResponse
    {
        $group = Group::findOrFail($group_id);

        $date = $request->query('date');

        if (! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return response()->json([
                'status' => 'failed',
                'data' => ['date' => ['Name the day to remove, as YYYY-MM-DD.']],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        LessonPlan::query()
            ->where('group_id', $group->id)
            ->whereDate('session_date', $date)
            ->delete();

        return response()->json(['status' => 'success', 'data' => ['session_date' => $date]], Response::HTTP_OK);
    }

    private function plan(LessonPlan $plan): array
    {
        return [
            'id' => (int) $plan->id,
            'session_date' => $plan->session_date->toDateString(),
            'title' => $plan->title,
            'body' => $plan->body,
            'updated_at' => optional($plan->updated_at)->toIso8601String(),
        ];
    }

    /**
     * A query date, or the fallback. Unparseable means the fallback rather than
     * a 422 — the same tolerance the register's `?date=` has, because a teacher
     * paging a week strip should never be able to produce an error page.
     */
    private function dateOr(?string $raw, Carbon $fallback): Carbon
    {
        if (! is_string($raw) || $raw === '') {
            return $fallback;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $raw)->startOfDay();
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
