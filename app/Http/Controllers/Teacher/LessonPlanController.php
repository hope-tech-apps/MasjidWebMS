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

        // whereDate on both ends, not whereBetween on raw strings: the `date`
        // cast can store '2026-09-11 00:00:00', which sorts OUTSIDE a
        // BETWEEN '2026-09-11' AND '2026-09-11' as a string comparison — the
        // plan saves and then does not appear. Comparing the DATE PART is
        // correct on MySQL and SQLite alike.
        $plans = LessonPlan::query()
            ->where('group_id', $group->id)
            ->whereDate('session_date', '>=', $from->toDateString())
            ->whereDate('session_date', '<=', $to->toDateString())
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

        $date = Carbon::createFromFormat('Y-m-d', $request->validated('session_date'))->startOfDay();

        // NOT updateOrCreate. Its WHERE uses the value as given while the INSERT
        // puts it through the `date` cast, so a lookup by the string
        // '2026-09-11' does not match a row the cast stored as
        // '2026-09-11 00:00:00' — the second save then misses and collides with
        // lesson_plan_class_day_unique. Measured: a 500 on the second save.
        //
        // whereDate() compares the DATE PART on both MySQL and SQLite, so this
        // is right whichever the column ends up holding.
        $plan = LessonPlan::query()
            ->where('group_id', $group->id)
            ->whereDate('session_date', $date->toDateString())
            ->first()
            ?? new LessonPlan(['group_id' => $group->id, 'session_date' => $date]);

        // The whole object, every time. The request declares every template
        // field `nullable` rather than `sometimes` precisely so that an omitted
        // field CLEARS — a partial payload must not silently keep stale prose.
        // The frontend consequence is that there is no per-section autosave.
        $fields = collect(LessonPlan::TEMPLATE_FIELDS)
            ->mapWithKeys(fn (string $f) => [$f => $request->validated($f)])
            ->all();

        $plan->fill($fields + [
            'title' => $request->validated('title'),
            'body' => $request->validated('body'),
            'author_user_id' => Auth::id(),
        ])->save();

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

    /**
     * The whole plan. Every template field is present even when null, so the
     * client can render the form and send the whole object back without having
     * to know which keys the server happened to omit.
     */
    private function plan(LessonPlan $plan): array
    {
        $template = collect(LessonPlan::TEMPLATE_FIELDS)
            ->mapWithKeys(fn (string $f) => [$f => $plan->{$f}])
            ->all();

        return $template + [
            'id' => (int) $plan->id,
            'session_date' => $plan->session_date->toDateString(),
            'title' => $plan->title,
            // The template's ACTIVITIES, and the one required section.
            'body' => $plan->body,
            'prefill_source' => $plan->prefill_source,
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
