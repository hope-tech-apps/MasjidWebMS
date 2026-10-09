<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Requests\Teacher\SaveLessonPlanRequest;
use App\Models\Group;
use App\Models\GroupResource;
use App\Models\LessonPlan;
use App\Models\LessonPlanResource;
use App\Support\SchoolCalendar;
use App\Support\SchoolSettings;
use App\Support\SubjectFence;
use App\Support\SubjectKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
 * THE SUBJECT FENCE (2026-09-29). A teacher whose assignment to the class lists
 * subjects sees and writes only the plans in subjects they teach
 * (App\Support\SubjectFence). A plan with NO subject is the day's general plan and
 * stays open to every teacher of the class: it has been first-class since the
 * feature shipped, BISS writes nothing else, and fencing it would strand every
 * plan that exists. The office reads through the admin realm and is never fenced.
 *
 * A plan a limited teacher may not touch is not there for them: by id it
 * answers the 404 a plan that does not exist answers, by day it is left out of
 * "the day's plan" (so their own save creates their own plan beside it and their
 * delete never reaches it), and nothing says which subject it was (review F4,
 * 2026-09-29). Only a subject the teacher TYPES is refused, with a 403 that names
 * what they wrote.
 *
 * BY DAY, a limited teacher reaches only plans in THEIR OWN subjects (review G2,
 * 2026-09-29), with one accepted exception. The general plan is open to them by
 * id, where they open it on purpose, and "the day's plan" must never RESOLVE to
 * the class's shared general plan or to another subject's: their save upserts on
 * the subject they sent, and their delete removes only what is filed under their
 * subjects. A by-day PUT that names NO subject is the explicit way to write the
 * general plan (it upserts on the empty subject), the same access they already
 * have by id, so it edits the general plan rather than being refused; that is
 * decided, not a leak (DECISIONS.md, G2 entry).
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
        if ($this->classSubjectsForGroup((int) $group_id)) {
            return $this->indexWithClassSubjects($request, $masjid_id, $group_id);
        }

        if (\App\Support\SchoolCalendarRequestMode::enabled((int) $masjid_id)) {
            return $this->indexConfigured($request, $masjid_id, $group_id);
        }

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
            ->with('attachments.groupResource')
            ->get();

        // The subject fence, applied in PHP rather than SQL: `lesson_plans.subject_key`
        // keeps its own older derivation (see App\Support\SubjectKey), so the
        // fence compares the folded key of each plan's subject instead.
        $limits = $this->limits($group);

        if ($limits !== null) {
            $plans = $plans->filter(fn (LessonPlan $p): bool => $this->mayTouch($limits, $p->subject))->values();
        }

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

    public function indexWithClassSubjects(Request $request, $masjid_id, $group_id): JsonResponse
    {
        $group = Group::findOrFail($group_id);
        // Dated terms and class subjects are separate switches; a school with
        // both gets the calendar's own today and its open days for this week.
        $calendar = \App\Support\SchoolCalendarRequestMode::enabled((int) $masjid_id)
            ? \App\Support\SchoolCalendarReaders::for((int) $masjid_id)
            : null;

        $from = $this->dateOr($request->query('from'), ($calendar ? Carbon::parse($calendar->today()) : Carbon::today())->startOfWeek());
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
            ->with(['attachments.groupResource', 'classSubject'])
            ->get();

        // Saved IDs authorize named plans; general plans remain shared.
        $limits = $this->limits($group);

        if ($limits !== null) {
            $plans = $plans->filter(fn (LessonPlan $p): bool => SubjectFence::allowsWork($limits, $p->class_subject_id, SubjectKey::clean($p->subject) === null))->values();
        }

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
                'meeting_weekdays' => $calendar
                    ? $calendar->meetingWeekdaysBetween($from->toDateString(), $to->toDateString())
                    : $this->meetingWeekdays((int) $masjid_id),
                ...($calendar ? $this->configuredLessonDays($calendar, $plans, $from, $to) : []),
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
        $plan = $this->planFor($group, $plan_id);

        return $this->write($request, $masjid_id, $group, $plan);
    }

    /**
     * Write "the day's plan", creating it or replacing it — the address from
     * before per-subject plans, which an older screen still open in a tab sends.
     *
     * Which plan that is:
     *   1. the day's plan for the subject sent, when there is one — an upsert on
     *      the per-subject unique key, so saving twice corrects the same plan;
     *   2. otherwise, when the day holds exactly ONE plan, that plan, for a
     *      teacher who is not limited to some subjects. The old screen showed one
     *      plan a day and sends the whole form, subject included, so a teacher
     *      who changed the subject there meant to rename THAT plan. Upserting on
     *      the new subject instead would leave the old plan behind and add a
     *      second one she never asked for. A LIMITED teacher never renames by day
     *      (review G2): the only plan they can see on a day may be the class's
     *      shared general plan, and retyping it to their subject would overwrite
     *      what every teacher of the class reads. Their save is a new plan of
     *      their own beside whatever is there, never a 403 and never a rewrite of
     *      the general plan or another subject's;
     *   3. otherwise a new plan: the day is empty, or it already holds several
     *      subjects' plans and none for this one, so "the day's plan" names none
     *      of them and replacing one would lose work she did not point at.
     *
     * write()'s clash check governs every one of these, so a rename onto a
     * subject the day already has is refused as it is everywhere else.
     */
    public function save(SaveLessonPlanRequest $request, $masjid_id, $group_id): JsonResponse
    {
        if ($this->classSubjectsForGroup((int) $group_id)) {
            return $this->saveWithClassSubjects($request, $masjid_id, $group_id);
        }

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

    private function saveWithClassSubjects(SaveLessonPlanRequest $request, $masjid_id, $group_id): JsonResponse
    {
        return DB::transaction(function () use ($request, $masjid_id, $group_id) {
            \App\Models\Masjid::whereKey($masjid_id)->lockForUpdate()->firstOrFail();
            $group = Group::whereKey($group_id)->lockForUpdate()->firstOrFail();
            $date = Carbon::createFromFormat('Y-m-d', $request->validated('session_date'))->startOfDay();
            // An old by-day edit with no subject choice must not publish named work as General.
            if (! $request->has('subject') && ! $request->has('class_subject_id')) {
                $plan = $this->planOnSubject($group, $date, null);
                if ($plan === null) {
                    $plans = $this->plansTouchableOnWithClassSubjects($group, $date->toDateString());
                    if ($plans->count() > 1) return response()->json(['status' => 'failed', 'data' => ['subject' => ['Choose a plan by id or explicitly choose its subject.']]], Response::HTTP_CONFLICT);
                    $plan = $plans->first();
                }
                return $this->write($request, $masjid_id, $group, $plan ?? new LessonPlan(['group_id' => $group->id]));
            }
            // Referencing existing work by its unchanged ID is allowed even after retirement.
            // Authorize the ID before looking for a plan, so an unassigned subject's existence stays hidden.
            if (! $request->has('subject') && $request->validated('class_subject_id') !== null) {
                $id = (int) $request->validated('class_subject_id');
                $subject = \App\Support\ClassSubjectInitializer::currentSubjects($group)->firstWhere('id', $id);
                if ($subject !== null && SubjectFence::allowsWork($this->limits($group), $id)) {
                    $plan = $this->planOnSubject($group, $date, $id);
                    if ($plan !== null) return $this->write($request, $masjid_id, $group, $plan);
                }
            }
            $chosen = null;
            if (SubjectKey::clean($request->validated('subject')) !== null || $request->validated('class_subject_id') !== null) {
                $chosen = SubjectFence::resolveChoice($group, $request->validated('subject'), $request->validated('class_subject_id'), $request->has('class_subject_id'), $this->limits($group));
            }
            $plan = $this->planOnSubject($group, $date, $chosen?->id);
            if ($plan === null && $this->onlyPlanOn($group, $date) !== null) {
                return response()->json(['status' => 'failed', 'data' => ['subject' => ['Choose the existing plan by id to change its subject.']]], Response::HTTP_CONFLICT);
            }
            $plan ??= new LessonPlan(['group_id' => $group->id]);
            return $this->write($request, $masjid_id, $group, $plan);
        });
    }

    private function planOnSubject(Group $group, Carbon $date, ?int $id): ?LessonPlan
    {
        $query = $this->plansOnDay($group, $date->toDateString())->where('class_subject_id', $id);
        if ($id === null) $query->where(fn ($q) => $q->whereNull('subject')->orWhere('subject', ''));
        return $query->first();
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
        if ($this->classSubjectsForGroup((int) $group_id)) {
            return $this->destroyPlanWithClassSubjects($request, $masjid_id, $group_id, $plan_id);
        }

        $group = Group::findOrFail($group_id);
        $plan = $this->planFor($group, $plan_id);

        $date = $plan->session_date->toDateString();
        $plan->delete();

        return response()->json([
            'status' => 'success',
            'data' => ['id' => (int) $plan_id, 'session_date' => $date],
        ], Response::HTTP_OK);
    }

    private function destroyPlanWithClassSubjects(Request $request, $masjid_id, $group_id, $plan_id): JsonResponse
    {
        return DB::transaction(function () use ($request, $masjid_id, $group_id, $plan_id) {
            \App\Models\Masjid::whereKey($masjid_id)->lockForUpdate()->firstOrFail();
            Group::whereKey($group_id)->lockForUpdate()->firstOrFail();

        $group = Group::findOrFail($group_id);
        $plan = $this->planFor($group, $plan_id);

        $date = $plan->session_date->toDateString();
        $plan->delete();

        return response()->json([
            'status' => 'success',
            'data' => ['id' => (int) $plan_id, 'session_date' => $date],
        ], Response::HTTP_OK);
            });
    }

    /**
     * Remove a day's plan by its date — the address from before per-subject
     * plans, kept so an older screen still works.
     *
     * It removes the day's plan only while there is exactly one. With several,
     * "remove the plan for Tuesday" no longer names one, and deleting every
     * subject's plan because one was meant would erase work the teacher did not
     * point at; it answers 409 and the teacher removes them one at a time.
     *
     * "The day's plans" are the ones THIS teacher may touch. For a limited teacher
     * that is the plans filed under THEIR subjects, and only those (review G2): the
     * general plan is not among them, so it is neither counted nor deleted, and
     * neither is another subject's (so a 409 never says a hidden plan exists). A day
     * with nothing of theirs is a 404, whether it is empty or holds only the general
     * plan or another subject's plan: the cases cannot be told apart.
     */
    public function destroy(Request $request, $masjid_id, $group_id): JsonResponse
    {
        if ($this->classSubjectsForGroup((int) $group_id)) {
            return $this->destroyWithClassSubjects($request, $masjid_id, $group_id);
        }

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
        $plans = $this->plansTouchableOn($group, $date);

        if ($this->limits($group) !== null) {
            // Touchable is the general plan and their own subjects'; by day only the
            // latter are theirs. The general plan is the class's, not "their" plan.
            $plans = $plans->filter(fn (LessonPlan $p): bool => SubjectKey::clean($p->subject) !== null)->values();

            // Nothing of their own that day: the one plain 404.
            if ($plans->isEmpty()) {
                abort(Response::HTTP_NOT_FOUND);
            }
        }

        if ($plans->count() > 1) {
            return response()->json([
                'status' => 'failed',
                'data' => ['date' => ['This day has a plan for more than one subject. Remove them one at a time.']],
            ], Response::HTTP_CONFLICT);
        }

        // Only the plan counted above, by id: never the day's other plans, which are
        // another subject's when this teacher is limited.
        $plans->each->delete();

        return response()->json(['status' => 'success', 'data' => ['session_date' => $date]], Response::HTTP_OK);
    }

    private function destroyWithClassSubjects(Request $request, $masjid_id, $group_id): JsonResponse
    {
        return DB::transaction(function () use ($request, $masjid_id, $group_id) {
            \App\Models\Masjid::whereKey($masjid_id)->lockForUpdate()->firstOrFail();
            Group::whereKey($group_id)->lockForUpdate()->firstOrFail();

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
        $plans = $this->plansTouchableOn($group, $date);

        if ($this->limits($group) !== null) {
            // Touchable is the general plan and their own subjects'; by day only the
            // latter are theirs. The general plan is the class's, not "their" plan.
            $plans = $plans->filter(fn (LessonPlan $p): bool => SubjectKey::clean($p->subject) !== null)->values();

            // Nothing of their own that day: the one plain 404.
            if ($plans->isEmpty()) {
                abort(Response::HTTP_NOT_FOUND);
            }
        }

        if ($plans->count() > 1) {
            return response()->json([
                'status' => 'failed',
                'data' => ['date' => ['This day has a plan for more than one subject. Remove them one at a time.']],
            ], Response::HTTP_CONFLICT);
        }

        // Only the plan counted above, by id: never the day's other plans, which are
        // another subject's when this teacher is limited.
        $plans->each->delete();

        return response()->json(['status' => 'success', 'data' => ['session_date' => $date]], Response::HTTP_OK);
            });
    }

    /**
     * Fill and save one plan from the request — the one write path all three
     * write verbs share, so the clash rule and the hidden-field rule cannot
     * drift apart between them.
     */
    private function write(SaveLessonPlanRequest $request, $masjid_id, Group $group, LessonPlan $plan): JsonResponse
    {
        if ($this->classSubjectsForGroup((int) $group->id)) {
            return $this->writeWithClassSubjects($request, $masjid_id, $group, $plan);
        }

        $date = Carbon::createFromFormat('Y-m-d', $request->validated('session_date'))->startOfDay();

        // The subject fence, on what the plan is becoming FIRST: a subject the teacher
        // typed and does not teach is refused in the words they wrote, whether or not
        // a plan of that subject exists, so the refusal says nothing about the day.
        $this->fence($group, $request->validated('subject'));

        // And on the plan AS IT IS: no verb reaches another subject's plan for a
        // limited teacher. The callers resolve a plan through planFor() or the
        // touchable set, so this is the last line, and it is the same 404.
        if ($plan->exists) {
            $this->mustTouch($group, $plan, $plan->getKey());
        }

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

        // Resolved BEFORE the plan is written, like every other refusal here: a
        // 422 that arrives after the save leaves a plan the teacher was told
        // failed. `null` = the request did not speak about files (see
        // SaveLessonPlanRequest), so the plan keeps the ones it has.
        $attachmentIds = $request->has('resource_ids')
            ? $this->resolveAttachments($group, (array) $request->validated('resource_ids', []))
            : null;

        try {
            if ($attachmentIds === null) {
                $plan->save();
            } else {
                // Plan and files together: a plan is never left saved with only
                // some of the files the teacher listed.
                DB::transaction(function () use ($plan, $attachmentIds): void {
                    $plan->save();
                    $this->syncAttachments($plan, $attachmentIds);
                });
            }
        } catch (UniqueConstraintViolationException) {
            return $this->clash(LessonPlan::cleanSubject($plan->subject));
        }

        return response()->json([
            'status' => 'success',
            'data' => $this->plan($plan),
        ], Response::HTTP_OK);
    }

    private function writeWithClassSubjects(SaveLessonPlanRequest $request, $masjid_id, Group $group, LessonPlan $plan): JsonResponse
    {
        return DB::transaction(function () use ($request, $masjid_id, $group, $plan) {
            \App\Models\Masjid::whereKey($masjid_id)->lockForUpdate()->firstOrFail();
            Group::whereKey($group->id)->lockForUpdate()->firstOrFail();
            return $this->writeLockedWithClassSubjects($request, $masjid_id, $group, $plan);
        });
    }

    private function writeLockedWithClassSubjects(SaveLessonPlanRequest $request, $masjid_id, Group $group, LessonPlan $plan): JsonResponse
    {
        $chosen = null;
        $date = Carbon::createFromFormat('Y-m-d', $request->validated('session_date'))->startOfDay();

        // The subject fence, on what the plan is becoming FIRST: a subject the teacher
        // typed and does not teach is refused in the words they wrote, whether or not
        // a plan of that subject exists, so the refusal says nothing about the day.
        // Early refusal precedes the clash response; the model repeats it under write locks.
        if ((\App\Support\SubjectKey::clean($request->validated('subject')) !== null || $request->validated('class_subject_id') !== null)
            && ! ($plan->exists && (! $request->has('subject') || $request->validated('subject') === $plan->subject)
                && (! $request->has('class_subject_id') || (int) $request->validated('class_subject_id') === (int) $plan->class_subject_id))) {
            $chosen = SubjectFence::resolveChoice($group, $request->validated('subject'), $request->validated('class_subject_id'), $request->has('class_subject_id'), $this->limits($group));
        }

        // And on the plan AS IT IS: no verb reaches another subject's plan for a
        // limited teacher. The callers resolve a plan through planFor() or the
        // touchable set, so this is the last line, and it is the same 404.
        if ($plan->exists) {
            $this->mustTouch($group, $plan, $plan->getKey());
        }

        // Subject is a choice: an omitted field preserves its ID and snapshot.
        // Other template prose remains the whole object, every time. The request declares every template
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
            ->reject(fn (string $f) => in_array($f, $hidden, true) || ($f === 'subject' && ! $request->has('subject')))
            ->mapWithKeys(fn (string $f) => [$f => $request->validated($f)])
            ->all();

        if ($request->has('class_subject_id')) $fields['class_subject_id'] = $request->validated('class_subject_id');
        $plan->fill($fields + [
            'session_date' => $date,
            'title' => $request->validated('title'),
            'body' => $request->validated('body'),
            'author_user_id' => Auth::id(),
        ]);

        // Resolve the snapshot before either the ID or physical-key collision check.
        if ($chosen !== null) {
            $plan->class_subject_id = $chosen->id;
            $plan->subject = $chosen->name;
            $plan->subject_key = LessonPlan::subjectKeyFor($chosen->name);
        }

        // Asked before the INSERT so the answer is a sentence naming the
        // subject; the unique index below is what holds when two saves race.
        $subjectId = $chosen?->id ?? $plan->class_subject_id;
        $clash = $this->planOnSubject($group, $date, $subjectId);
        // Keep the old physical key's collision response for unlinked historical rows.
        if ($clash === null) $clash = $this->planOn($group, $date, LessonPlan::subjectKeyFor($plan->subject));

        // Named as the EXISTING plan spells it: the teacher typed "math", but
        // the plan they are being sent to open is the one called "Math".
        if ($clash && $clash->id !== $plan->id) {
            return $this->clash($clash->subject);
        }

        // Resolved BEFORE the plan is written, like every other refusal here: a
        // 422 that arrives after the save leaves a plan the teacher was told
        // failed. `null` = the request did not speak about files (see
        // SaveLessonPlanRequest), so the plan keeps the ones it has.
        $attachmentIds = $request->has('resource_ids')
            ? $this->resolveAttachments($group, (array) $request->validated('resource_ids', []))
            : null;

        try {
            if ($attachmentIds === null) {
                $plan->save();
            } else {
                // Plan and files together: a plan is never left saved with only
                // some of the files the teacher listed.
                DB::transaction(function () use ($plan, $attachmentIds): void {
                    $plan->save();
                    $this->syncAttachments($plan, $attachmentIds);
                });
            }
        } catch (UniqueConstraintViolationException) {
            return $this->clash(LessonPlan::cleanSubject($plan->subject));
        }

        return response()->json([
            'status' => 'success',
            'data' => $this->plan($plan),
        ], Response::HTTP_OK);
    }

    /**
     * Every id in `$ids`, confirmed to be a file of THIS class in THIS school.
     *
     * The whole request is refused when any one id fails, rather than the bad ids
     * being dropped: a teacher who attached three files and is told "saved" must
     * not have saved two. The refusal names no id it did not receive, so it is
     * not an existence oracle for another class's or another school's files.
     *
     * The class AND the school are both stated in the query. The tenant scope on
     * GroupResource already hides another school's rows on this route; asking for
     * `masjid_id` explicitly as well means the rule does not depend on which
     * caller reached here with the tenant bound. The order the client sent is the
     * order kept.
     *
     * @param  array<int,mixed>  $ids
     * @return array<int,int>
     */
    private function resolveAttachments(Group $group, array $ids): array
    {
        $wanted = collect($ids)->map(fn ($id): int => (int) $id)->unique()->values();

        if ($wanted->isEmpty()) {
            return [];
        }

        $found = GroupResource::query()
            ->where('group_id', $group->id)
            ->where('masjid_id', $group->masjid_id)
            ->whereIn('id', $wanted->all())
            ->pluck('id')
            ->map(fn ($id): int => (int) $id);

        if ($found->count() !== $wanted->count()) {
            throw new HttpResponseException(response()->json([
                'status' => 'failed',
                'message' => 'One of the files chosen is not in this class\'s Files. Reload the class and try again.',
                'data' => ['resource_ids' => [
                    'One of the files chosen is not in this class\'s Files. Reload the class and try again.',
                ]],
            ], Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        return $wanted->all();
    }

    /**
     * Make `$ids` the plan's files, exactly and in that order. Removing a link
     * never removes the file: it stays in the class's Files.
     *
     * @param  array<int,int>  $ids
     */
    private function syncAttachments(LessonPlan $plan, array $ids): void
    {
        // A plain query, not `$plan->attachments()`: that relation carries an
        // ORDER BY, which a DELETE has no use for and some drivers refuse.
        $links = LessonPlanResource::query()->where('lesson_plan_id', $plan->id);

        (clone $links)->whereNotIn('group_resource_id', $ids ?: [0])->delete();

        $existing = (clone $links)->pluck('id', 'group_resource_id');

        foreach (array_values($ids) as $position => $resourceId) {
            if ($existing->has($resourceId)) {
                LessonPlanResource::query()->whereKey($existing[$resourceId])->update(['position' => $position]);

                continue;
            }

            LessonPlanResource::create([
                // From the PLAN, never from the route: the route's id is the
                // caller's and the plan's is the server's.
                'masjid_id' => (int) $plan->masjid_id,
                'lesson_plan_id' => (int) $plan->id,
                'group_resource_id' => $resourceId,
                'position' => $position,
            ]);
        }

        $plan->unsetRelation('attachments');
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

    /**
     * The day's plan when the day holds exactly one; null when it holds none or several,
     * and ALWAYS null for a teacher limited to some subjects. Renaming "the day's only
     * plan" is the old screen's meaning of a save, and for a limited teacher that plan
     * can be the shared general one or, before them, another subject's, which a save
     * of theirs must never retype or overwrite (review G2).
     */
    private function onlyPlanOn(Group $group, Carbon $date): ?LessonPlan
    {
        if ($this->limits($group) !== null) {
            return null;
        }

        $plans = $this->plansOnDay($group, $date->toDateString())->limit(2)->get();

        return $plans->count() === 1 ? $plans->first() : null;
    }

    /**
     * This class's plans on one day that the signed-in teacher may touch: all of
     * them, or for a limited teacher only the general plan and their own subjects'.
     *
     * @return \Illuminate\Support\Collection<int,LessonPlan>
     */
    private function plansTouchableOn(Group $group, string $day): \Illuminate\Support\Collection
    {
        if ($this->classSubjectsForGroup((int) $group->id)) {
            return $this->plansTouchableOnWithClassSubjects($group, $day);
        }

        $plans = $this->plansOnDay($group, $day)->orderBy('id')->get();
        $limits = $this->limits($group);

        return $limits === null
            ? $plans
            : $plans->filter(fn (LessonPlan $p): bool => $this->mayTouch($limits, $p->subject))->values();
    }

    private function plansTouchableOnWithClassSubjects(Group $group, string $day): \Illuminate\Support\Collection
    {
        $plans = $this->plansOnDay($group, $day)->orderBy('id')->get();
        $limits = $this->limits($group);

        return $limits === null
            ? $plans
            : $plans->filter(fn (LessonPlan $p): bool => SubjectFence::allowsWork($limits, $p->class_subject_id, SubjectKey::clean($p->subject) === null))->values();
    }

    /**
     * One plan of this class by id, for the signed-in teacher. A limited teacher's
     * fence is invisibility, so a plan in another subject answers exactly what an
     * id that names no plan answers: the same exception, message included, so the
     * body cannot tell them apart and nothing says which subject it was.
     */
    private function planFor(Group $group, $planId): LessonPlan
    {
        $plan = $group->lessonPlans()->findOrFail($planId);
        $this->mustTouch($group, $plan, $planId);

        return $plan;
    }

    /** The 404 a plan that does not exist gets, for a plan a limited teacher may not touch. */
    private function mustTouch(Group $group, LessonPlan $plan, $planId): void
    {
        if ($this->classSubjectsForGroup((int) $group->id)) {
            $this->mustTouchWithClassSubjects($group, $plan, $planId);
            return;
        }

        $limits = $this->limits($group);

        if ($limits !== null && ! $this->mayTouch($limits, $plan->subject)) {
            throw (new ModelNotFoundException())->setModel(LessonPlan::class, [$planId]);
        }
    }

    private function mustTouchWithClassSubjects(Group $group, LessonPlan $plan, $planId): void
    {
        $limits = $this->limits($group);

        if ($limits !== null && ! SubjectFence::allowsWork($limits, $plan->class_subject_id, SubjectKey::clean($plan->subject) === null)) {
            throw (new ModelNotFoundException())->setModel(LessonPlan::class, [$planId]);
        }
    }

    /** @return list<string>|null  what the signed-in teacher is limited to here, NULL for all */
    private function limits(Group $group): ?array
    {
        return SubjectFence::limitsFor(Auth::user(), (int) $group->id);
    }

    /**
     * May a teacher with these limits read or write a plan filed under `$subject`?
     * No subject is the day's general plan, which is nobody's to refuse.
     *
     * @param  list<string>  $limits
     */
    private function mayTouch(array $limits, ?string $subject): bool
    {
        return SubjectKey::clean($subject) === null
            || SubjectFence::allows($limits, SubjectKey::for($subject));
    }

    /** Refuse, in the fence's own words, a plan in a subject this teacher does not teach. */
    private function fence(Group $group, ?string $subject): void
    {
        $limits = $this->limits($group);

        if ($limits !== null && ! $this->mayTouch($limits, $subject)) {
            SubjectFence::refuse($subject);
        }
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
        if (\App\Support\ClassSubjectMode::responseEnabled($plan->masjid_id)) {
            return $this->planWithClassSubjects($plan);
        }

        $template = collect(LessonPlan::TEMPLATE_FIELDS)
            ->mapWithKeys(fn (string $f) => [$f => $plan->{$f}])
            ->all();

        $plan->loadMissing('attachments.groupResource');

        return $template + [
            'id' => (int) $plan->id,
            'session_date' => $plan->session_date->toDateString(),
            'title' => $plan->title,
            // The template's ACTIVITIES, and the one required section.
            'body' => $plan->body,
            'prefill_source' => $plan->prefill_source,
            // The files listed under Activities, in the teacher's order: staff
            // information, served to the teacher and the office (the same
            // payload) and to NO family payload. The file shape carries no url;
            // the bytes are only reachable through the Files download route.
            'attachments' => $plan->attachments
                ->map(fn (LessonPlanResource $link) => $link->groupResource?->toAudienceArray())
                ->filter()
                ->values()
                ->all(),
            'updated_at' => optional($plan->updated_at)->toIso8601String(),
        ];
    }

    private function planWithClassSubjects(LessonPlan $plan): array
    {
        $template = collect(LessonPlan::TEMPLATE_FIELDS)
            ->mapWithKeys(fn (string $f) => [$f => $f === 'subject' ? $plan->currentSubjectName() : $plan->{$f}])
            ->all();

        $plan->loadMissing('attachments.groupResource');

        return $template + [
            'id' => (int) $plan->id,
            'class_subject_id' => $plan->class_subject_id,
            'session_date' => $plan->session_date->toDateString(),
            'title' => $plan->title,
            // The template's ACTIVITIES, and the one required section.
            'body' => $plan->body,
            'prefill_source' => $plan->prefill_source,
            // The files listed under Activities, in the teacher's order: staff
            // information, served to the teacher and the office (the same
            // payload) and to NO family payload. The file shape carries no url;
            // the bytes are only reachable through the Files download route.
            'attachments' => $plan->attachments
                ->map(fn (LessonPlanResource $link) => $link->groupResource?->toAudienceArray())
                ->filter()
                ->values()
                ->all(),
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
    /** ON dates and advice reuse the loaded calendar and the already fenced plan list. */
    private function configuredLessonDays(\App\Support\SchoolDateAuthority $calendar, $plans, Carbon $from, Carbon $to): array
    {
        $dates = array_unique([...$calendar->openDaysBetween($from->toDateString(), $to->toDateString()), ...$plans->map(fn ($p) => $p->session_date->toDateString())->all()]);
        sort($dates);
        $notices = [];
        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            $date = $day->toDateString();
            $status = $calendar->schoolDay($date);
            if ($status['closed']) {
                $reason = $status['reason'] ?? null;
                $notices[$date] = 'No school on '.\App\Support\SchoolCalendar::label($date).($reason ? ' — '.rtrim($reason, '.') : '').'.';
            } elseif (! $status['meeting_day']) {
                $notices[$date] = "This isn't one of the school's meeting days on the calendar.";
            }
        }
        return ['week_dates' => array_values($dates), 'day_notices' => (object) $notices];
    }

    private function indexConfigured(Request $request, $masjid_id, $group_id): JsonResponse
    {
        $group = Group::findOrFail($group_id);

        $from = $this->dateOr($request->query('from'), Carbon::parse(\App\Support\SchoolCalendarReaders::for((int) $masjid_id)->today())->startOfWeek());
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
            ->with('attachments.groupResource')
            ->get();

        // The subject fence, applied in PHP rather than SQL: `lesson_plans.subject_key`
        // keeps its own older derivation (see App\Support\SubjectKey), so the
        // fence compares the folded key of each plan's subject instead.
        $limits = $this->limits($group);

        if ($limits !== null) {
            $plans = $plans->filter(fn (LessonPlan $p): bool => $this->mayTouch($limits, $p->subject))->values();
        }

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
                // Open school weekdays inside this requested interval. An empty
                // calendar or fully closed week supplies [], without inferring days.
                'meeting_weekdays' => \App\Support\SchoolCalendarReaders::for((int) $masjid_id)->meetingWeekdaysBetween($from->toDateString(), $to->toDateString()),
                ...$this->configuredLessonDays(\App\Support\SchoolCalendarReaders::for((int) $masjid_id), $plans, $from, $to),
            ],
        ], Response::HTTP_OK);
    }

}
