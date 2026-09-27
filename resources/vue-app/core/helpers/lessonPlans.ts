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
 *
 * Lower-cased one code point at a time, as the server's SIMPLE case mapping
 * does (it keeps the key within its column). A whole-string toLowerCase() uses
 * the full mapping instead: 'İ' becomes two code points and a word-final 'Σ'
 * becomes 'ς', so the two sides would disagree on those subjects. U+0130 is the
 * one code point whose full lower case differs from its simple one on its own.
 */
export function subjectKey(subject: string | null | undefined): string {
    const clean = String(subject ?? '').replace(/\s+/gu, ' ').trim();

    return Array.from(clean, (c) => (c === '\u0130' ? 'i' : c.toLowerCase())).join('');
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

/**
 * The plan a jump into the day ALREADY open should open, or `undefined` to stay.
 *
 * Opening a plan reloads its saved copy into the form, so re-opening the one on
 * screen would throw away its unsaved draft — a guide fill, typed activities.
 * A jump that names no plan (a phone's day card, a day row with no plan) or
 * names the open one stays; a jump that names another plan of that day opens it.
 */
export function jumpTarget(plans: DayPlan[], iso: string, id: number | null, openId: number | null): number | null | undefined {
    if (id === null || id === openId) {
        return undefined;
    }

    return pickPlan(plans, iso, id);
}

/** How a plan names itself in a list of the day's plans. */
export function planLabel(plan: DayPlan | null | undefined): string {
    const subject = String(plan?.subject ?? '').trim();

    return subject || 'General';
}

/** The day view's write for the plan on its form: the open plan by its id, or a new plan. */
export function planSaveRequest(base: string, planId: number | null): { method: 'post' | 'put'; url: string } {
    return planId === null
        ? { method: 'post', url: `${base}/lesson-plans` }
        : { method: 'put', url: `${base}/lesson-plans/${planId}` };
}

/**
 * Removing the open plan: by its id, so the day's other subjects stay. The
 * by-date address removes a day only while it holds one plan.
 */
export function planDeleteUrl(base: string, planId: number): string {
    return `${base}/lesson-plans/${planId}`;
}

/**
 * Save is offered for a plan with activities whose subject the day does not
 * already have, and not while a save is in flight.
 */
export function canSavePlan(saving: boolean, body: string | null | undefined, clash: DayPlan | null): boolean {
    return !saving && String(body ?? '').trim() !== '' && clash === null;
}

export interface CopyablePlan extends DayPlan {
    body?: string | null;
}

/**
 * "Copy to the rest of this week", for one other day: the write that makes
 * that day's plan for THIS subject match `source`.
 *
 *   - The day has this subject's plan: rewrite it by its id. It keeps its OWN
 *     activities if it has any — the shared part of a week is its standard and
 *     objective, not what the class actually did on Thursday.
 *   - It has none: create one, beside whatever other subjects that day holds.
 *
 * Never the by-day PUT: that is the old one-plan-a-day address, and on a day
 * holding exactly one plan it rewrites THAT plan whatever its subject — copying
 * Math onto a Thursday that has only Science would turn the Science plan into
 * a second Math plan.
 */
export function copyRequest<T extends CopyablePlan>(
    base: string, plans: T[], source: T, iso: string,
): { method: 'post' | 'put'; url: string; payload: T } {
    const same = subjectClash(plans, iso, source.subject, null);
    const payload = { ...source, session_date: iso, body: same?.body || source.body };

    return same
        ? { method: 'put', url: `${base}/lesson-plans/${same.id}`, payload }
        : { method: 'post', url: `${base}/lesson-plans`, payload };
}

/**
 * Which form the day view has on screen. `replace()` runs every time the form
 * is replaced (another plan, another day, a reload); a request started for one
 * form keeps `current()` and asks `isCurrent()` after its await. An answer for
 * a form that has since been replaced — a guide fill, a saved plan's id — is
 * dropped rather than written into the plan the teacher opened meanwhile.
 */
export function formTicket(): { replace: () => void; current: () => number; isCurrent: (ticket: number) => boolean } {
    let seq = 0;

    return {
        replace: () => { seq += 1; },
        current: () => seq,
        isCurrent: (ticket: number) => ticket === seq,
    };
}
