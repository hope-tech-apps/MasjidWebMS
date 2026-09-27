<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Requests\Teacher\SaveLessonPlanRequest;
use App\Models\Group;
use App\Models\LessonPlan;
use App\Support\SchoolCalendar;
use App\Support\SchoolSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * What a class is planned to cover: one row per class, per day, per subject.
 *
 * A combined-grade homeroom teaches several subjects a day, so a day holds a
 * LIST of plans, each addressed by its id (`/lesson-plans/{plan_id}`), and
 * `lesson_plan_class_day_subject_unique` keeps it to one plan per subject. A
 * plan with no subject is the day's single general plan — a school with no
 * pacing guide never picks one, and its teachers work exactly as before.
 *
 * Two addresses, on purpose:
 *
 *   - BY ID (POST to create, PUT/DELETE `/{plan_id}`) is what the day view uses.
 *     Creating REFUSES a subject that day already has, with a sentence naming
 *     it, rather than overwriting the plan a teacher could not see.
 *   - BY DAY (PUT `/lesson-plans`, an upsert on day and subject) is the address
 *     every route had before per-subject plans, kept so a teacher with the old
 *     screen still open saves where they expect to (see save()). The day view
 *     does not use it: "copy to the rest of this week" writes each day's plan
 *     by id, or creates one.
 *
 * `teacher.leads` has already answered "may this teacher touch this class"
 * before any method here runs. A plan id is always resolved THROUGH that class
 * (`$group->lessonPlans()`), so an id from another class is a 404, never a
 * write to a room this teacher does not lead.
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
            // Within a day: the general plan (key '') first, then subjects
            // alphabetically — the same order on every screen that lists them.
            ->orderBy('subject_key')
            ->orderBy('id')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'plans' => $plans->map(fn (LessonPlan $p): array => $this->plan($p))->values(),
                // The organisation's shorter plan (`short_lesson_plan`): the
                // template fields this school's form does not show. `[]`
                // everywhere else. The plans above still carry every field.
                'hidden_fields' => SchoolSettings::hiddenLessonPlanFields(SchoolSettings::org($masjid_id)),
                // The weekdays the school meets on (0 = Sunday), from its school
                // calendar, so the week grid shows a Sunday school's Sunday.
                // NULL when it has no calendar (Al-Razi): the grid stays Monday
                // to Friday, as before.
                'meeting_weekdays' => $this->meetingWeekdays((int) $masjid_id),
            ],
        ], Response::HTTP_OK);
    }

    /**
     * Write a NEW plan for a day. Refused, with the reason, when that day already
     * has a plan for this subject (or already has its general plan): the teacher
     * is sent to the plan that exists instead of silently replacing it.
     */
    public function store(SaveLessonPlanRequest $request, $masjid_id, $group_id): JsonResponse
    {
        $group = Group::findOrFail($group_id);

        return $this->write($request, $masjid_id, $group, new LessonPlan(['group_id' => $group->id]));
    }

    /**
     * Rewrite one plan, by id. Its subject and its day may both change; landing
     * on a subject that day already has is refused exactly as creating is.
     */
    public function update(SaveLessonPlanRequest $request, $masjid_id, $group_id, $plan_id): JsonResponse
    {
        $group = Group::findOrFail($group_id);
        $plan = $group->lessonPlans()->findOrFail($plan_id);

        return $this->write($request, $masjid_id, $group, $plan);
    }

    /**
     * Write "the day's plan", creating it or replacing it — the address from
     * before per-subject plans, which an older screen still open in a tab sends.
     *
     * Which plan that is:
     *   1. the day's plan for the subject sent, when there is one — an upsert on
     *      the per-subject unique key, so saving twice corrects the same plan;
     *   2. otherwise, when the day holds exactly ONE plan, that plan. The old
     *      screen showed one plan a day and sends the whole form, subject
     *      included, so a teacher who changed the subject there meant to rename
     *      THAT plan. Upserting on the new subject instead would leave the old
     *      plan behind and add a second one she never asked for;
     *   3. otherwise a new plan: the day is empty, or it already holds several
     *      subjects' plans and none for this one, so "the day's plan" names none
     *      of them and replacing one would lose work she did not point at.
     *
     * write()'s clash check governs every one of these, so a rename onto a
     * subject the day already has is refused as it is everywhere else.
     */
    public function save(SaveLessonPlanRequest $request, $masjid_id, $group_id): JsonResponse
    {
        $group = Group::findOrFail($group_id);

        $date = Carbon::createFromFormat('Y-m-d', $request->validated('session_date'))->startOfDay();

        // NOT updateOrCreate. Its WHERE uses the value as given while the INSERT
        // puts it through the `date` cast, so a lookup by the string
        // '2026-09-11' does not match a row the cast stored as
        // '2026-09-11 00:00:00' — the second save then misses and collides with
        // the unique index. Measured: a 500 on the second save.
        //
        // whereDate() compares the DATE PART on both MySQL and SQLite, so this
        // is right whichever the column ends up holding.
        $plan = $this->planOn($group, $date, LessonPlan::subjectKeyFor($request->validated('subject')))
            ?? $this->onlyPlanOn($group, $date)
            ?? new LessonPlan(['group_id' => $group->id]);

        return $this->write($request, $masjid_id, $group, $plan);
    }

    /**
     * Remove one plan, by id. The other subjects' plans that day are untouched.
     *
     * This verb exists because `body` is NOT NULL: a plan typed against the
     * wrong day cannot be blanked, so without a delete it would be an unfixable
     * row. A hard delete — the table holds no record about a child and no bytes,
     * so there is nothing to retain.
     */
    public function destroyPlan(Request $request, $masjid_id, $group_id, $plan_id): JsonResponse
    {
        $group = Group::findOrFail($group_id);
        $plan = $group->lessonPlans()->findOrFail($plan_id);

        $date = $plan->session_date->toDateString();
        $plan->delete();

        return response()->json([
            'status' => 'success',
            'data' => ['id' => (int) $plan_id, 'session_date' => $date],
        ], Response::HTTP_OK);
    }

    /**
     * Remove a day's plan by its date — the address from before per-subject
     * plans, kept so an older screen still works.
     *
     * It removes the day's plan only while there is exactly one. With several,
     * "remove the plan for Tuesday" no longer names one, and deleting every
     * subject's plan because one was meant would erase work the teacher did not
     * point at; it answers 409 and the teacher removes them one at a time.
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

        // The string as given, not a parsed Carbon: '2026-02-30' would roll over
        // to March 2nd and remove that day's plan; as a string it matches nothing.
        $onDay = $this->plansOnDay($group, $date);

        if ((clone $onDay)->count() > 1) {
            return response()->json([
                'status' => 'failed',
                'data' => ['date' => ['This day has a plan for more than one subject. Remove them one at a time.']],
            ], Response::HTTP_CONFLICT);
        }

        $onDay->delete();

        return response()->json(['status' => 'success', 'data' => ['session_date' => $date]], Response::HTTP_OK);
    }

    /**
     * Fill and save one plan from the request — the one write path all three
     * write verbs share, so the clash rule and the hidden-field rule cannot
     * drift apart between them.
     */
    private function write(SaveLessonPlanRequest $request, $masjid_id, Group $group, LessonPlan $plan): JsonResponse
    {
        $date = Carbon::createFromFormat('Y-m-d', $request->validated('session_date'))->startOfDay();

        // The whole object, every time. The request declares every template
        // field `nullable` rather than `sometimes` precisely so that an omitted
        // field CLEARS — a partial payload must not silently keep stale prose.
        // The frontend consequence is that there is no per-section autosave.
        //
        // EXCEPT the fields this organisation's shorter plan leaves out
        // (SchoolSettings::HIDDEN_LESSON_PLAN_FIELDS). Hidden means not shown and
        // not written: those columns are left as they are, whatever a client
        // sends, so they are never required, never filled from here, and a plan
        // written before the setting was switched on keeps what it had.
        $hidden = SchoolSettings::hiddenLessonPlanFields(SchoolSettings::org($masjid_id));

        $fields = collect(LessonPlan::TEMPLATE_FIELDS)
            ->reject(fn (string $f) => in_array($f, $hidden, true))
            ->mapWithKeys(fn (string $f) => [$f => $request->validated($f)])
            ->all();

        $plan->fill($fields + [
            'session_date' => $date,
            'title' => $request->validated('title'),
            'body' => $request->validated('body'),
            'author_user_id' => Auth::id(),
        ]);

        // Asked before the INSERT so the answer is a sentence naming the
        // subject; the unique index below is what holds when two saves race.
        $key = LessonPlan::subjectKeyFor($plan->subject);
        $clash = $this->planOn($group, $date, $key);

        // Named as the EXISTING plan spells it: the teacher typed "math", but
        // the plan they are being sent to open is the one called "Math".
        if ($clash && $clash->id !== $plan->id) {
            return $this->clash($clash->subject);
        }

        try {
            $plan->save();
        } catch (UniqueConstraintViolationException) {
            return $this->clash(LessonPlan::cleanSubject($plan->subject));
        }

        return response()->json([
            'status' => 'success',
            'data' => $this->plan($plan),
        ], Response::HTTP_OK);
    }

    /**
     * This class's plans on one day. The one place the (class, day) scope is
     * written, so the clash check, the by-day upsert and the by-day delete cannot
     * disagree about which rows "this class's day" means — another class's plan
     * for the same subject that day is never one of them.
     */
    private function plansOnDay(Group $group, string $day): Builder
    {
        return LessonPlan::query()
            ->where('group_id', $group->id)
            ->whereDate('session_date', $day);
    }

    /** The plan a class already has for (day, subject key), if any. */
    private function planOn(Group $group, Carbon $date, string $subjectKey): ?LessonPlan
    {
        return $this->plansOnDay($group, $date->toDateString())->where('subject_key', $subjectKey)->first();
    }

    /** The day's plan when the day holds exactly one; null when it holds none or several. */
    private function onlyPlanOn(Group $group, Carbon $date): ?LessonPlan
    {
        $plans = $this->plansOnDay($group, $date->toDateString())->limit(2)->get();

        return $plans->count() === 1 ? $plans->first() : null;
    }

    /**
     * A second plan for a subject the day already has. 422 under `subject`, the
     * shape every validation failure in this realm has, so the form shows it
     * beside the field that caused it.
     */
    private function clash(?string $subject): JsonResponse
    {
        $message = $subject === null
            ? 'This day already has a plan with no subject. Choose a subject for this one, or open that plan to change it.'
            : "This day already has a {$subject} plan. Open it to change it, or choose another subject.";

        return response()->json([
            'status' => 'failed',
            'message' => $message,
            'data' => ['subject' => [$message]],
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
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
     * The weekdays this school meets on, from SchoolCalendar (the one authority
     * on meeting days): each year's `first_day` weekday. Null with no calendar.
     *
     * @return list<int>|null
     */
    private function meetingWeekdays(int $masjidId): ?array
    {
        $calendar = SchoolCalendar::for($masjidId);

        return $calendar->hasCalendar() ? $calendar->meetingWeekdays() : null;
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
