/**
 * A day's lesson plans: one per class, per day, PER SUBJECT
 * (`lesson_plan_class_day_subject_unique`). A combined-grade homeroom plans
 * each subject it teaches that day; a plan with no subject is the day's single
 * general plan, for a school with no pacing guide.
 *
 * The teacher's day view (views/teacher/TeacherClass.vue) and the office's
 * read-only tab (views/dashboard/groups/GroupLessonPlansTab.vue) both list a
 * day through these, so the two screens put a day's plans in the same order
 * and agree on which subjects are "the same".
 *
 * No imports, so tests/lesson-plans.test.ts runs it under node.
 */

export interface DayPlan {
    id: number;
    session_date: string;
    subject?: string | null;
}

/**
 * Two subjects are the same subject when their keys match. Mirrors
 * `LessonPlan::subjectKeyFor` on the server: trimmed, whitespace collapsed,
 * lower-cased; '' is "no subject". The server is the authority and refuses a
 * clash either way — this only lets the form say so before the teacher saves.
 */
export function subjectKey(subject: string | null | undefined): string {
    return String(subject ?? '').replace(/\s+/gu, ' ').trim().toLowerCase();
}

/**
 * The day part of `session_date`. A `date` cast that reaches a payload without
 * being asked for a date string arrives as a full timestamp, and comparing it
 * whole against a bare 'Y-m-d' would match nothing.
 */
const dayOf = (plan: DayPlan): string => String(plan?.session_date ?? '').slice(0, 10);

/**
 * The plans for one day, general plan first, then subjects alphabetically —
 * the order the server lists them in, restated so a list built on the client
 * (after a save, say) reads the same.
 */
export function plansOn<T extends DayPlan>(plans: T[], iso: string): T[] {
    return plans
        .filter((p) => dayOf(p) === iso)
        .sort((a, b) => {
            const ka = subjectKey(a.subject);
            const kb = subjectKey(b.subject);

            return ka === kb ? a.id - b.id : (ka < kb ? -1 : 1);
        });
}

/**
 * The OTHER plan on that day that already has this subject, if any. `exceptId`
 * is the plan being edited: renaming a plan to its own subject is no clash.
 */
export function subjectClash<T extends DayPlan>(
    plans: T[], iso: string, subject: string | null | undefined, exceptId: number | null,
): T | null {
    const key = subjectKey(subject);

    return plansOn(plans, iso).find((p) => p.id !== exceptId && subjectKey(p.subject) === key) ?? null;
}

/** The subject keys the day's OTHER plans hold — what a subject picker must not offer again. */
export function takenSubjectKeys(plans: DayPlan[], iso: string, exceptId: number | null): Set<string> {
    return new Set(
        plansOn(plans, iso)
            .filter((p) => p.id !== exceptId)
            .map((p) => subjectKey(p.subject))
    );
}

/**
 * Which plan the day view opens.
 *
 * The one asked for when it is still on that day (the teacher stays on the plan
 * they just saved, or were reading before the week reloaded); otherwise the
 * day's first plan; otherwise null — a new plan, the empty form.
 */
export function pickPlan(plans: DayPlan[], iso: string, preferredId: number | null): number | null {
    const day = plansOn(plans, iso);

    if (preferredId !== null && day.some((p) => p.id === preferredId)) {
        return preferredId;
    }

    return day[0]?.id ?? null;
}

/** How a plan names itself in a list of the day's plans. */
export function planLabel(plan: DayPlan | null | undefined): string {
    const subject = String(plan?.subject ?? '').trim();

    return subject || 'General';
}
