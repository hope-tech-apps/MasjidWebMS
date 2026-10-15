/**
 * A day's lesson plans: one per class, per day, per subject, PER GRADE
 * (`lesson_plan_class_day_subject_grade_unique`). A combined-grade homeroom
 * plans each subject it teaches that day, once for each of its grades; a plan
 * with no subject is the day's general plan, for a school with no pacing guide.
 * A plan with no grade is a slot of its own beside the graded ones only in a
 * class taught as several grades (see sameGradePlan).
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
    grade_label?: string | null;
    class_subject_id?: number | null;
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

const GRADE_WORDS = new Map([
    ['first', 1], ['second', 2], ['third', 3], ['fourth', 4], ['fifth', 5], ['sixth', 6],
    ['seventh', 7], ['eighth', 8], ['ninth', 9], ['tenth', 10], ['eleventh', 11], ['twelfth', 12],
]);

/**
 * Two grades are the same grade when their keys match. Mirrors
 * `LessonPlan::gradeKeyFor` on the server, a frozen fold of its own: "Pre-K",
 * "PreK" and "Pre-Kindergarten" are one grade, "K" and "Kindergarten" another,
 * "1st" and "Grade 1" another. A label with no Latin letter or digit (written
 * only in Arabic, say) keys as itself, as subjectKey would key it, so it is
 * still a grade and never "no grade". '' is no grade. The server is the
 * authority and refuses a clash either way.
 */
export function gradeKey(grade: string | null | undefined): string {
    const clean = String(grade ?? '').replace(/\s+/gu, ' ').trim();
    if (clean === '') return '';

    const flat = clean.toLowerCase().replace(/[^a-z0-9]+/g, '');
    if (flat === '') return subjectKey(clean);
    if (['prek', 'prekindergarten', 'pk'].includes(flat)) return 'prek';
    if (['kg', 'k', 'kindergarten'].includes(flat)) return 'kg';

    const numbered = flat.match(/^(?:grade)?(\d{1,2})(?:st|nd|rd|th)?(?:grade)?$/);
    if (numbered) return String(parseInt(numbered[1], 10));

    const word = flat.match(/^(?:grade)?([a-z]+?)(?:grade)?$/);
    if (word && GRADE_WORDS.has(word[1])) return String(GRADE_WORDS.get(word[1]));

    return flat;
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
 * Of one subject's plans on one day, the one that is this grade's plan, if
 * any: its own grade's first. In a class taught as several grades
 * (`severalTaught`: severalGrades of its roster, the server's
 * taughtAsGrades) only that grade's. In any other class a plan with no grade
 * and a graded plan are also the one grade's plan, either way round: every
 * plan written before grades were recorded has none, and it is the same lesson
 * as the graded plan the form writes today. Mirrors
 * LessonPlanController::ofGrade.
 */
function sameGradePlan<T extends DayPlan>(plans: T[], grade: string | null | undefined, severalTaught: boolean): T | undefined {
    const level = gradeKey(grade);

    return plans.find((p) => gradeKey(p.grade_label) === level)
        ?? (severalTaught ? undefined : plans.find((p) => (gradeKey(p.grade_label) === '') !== (level === '')));
}

/** A plan's subject as the clash check compares it: its class subject's id when that is on, else its words. */
function subjectSlot(p: DayPlan, classSubjectsEnabled: boolean): string {
    return classSubjectsEnabled
        ? (p.class_subject_id != null ? `id:${p.class_subject_id}` : `text:${subjectKey(p.subject)}`)
        : subjectKey(p.subject);
}

/**
 * ON linked plans match only their ID; unlinked plans match only other unlinked saved text.
 * OFF retains the legacy text comparison.
 *
 * The OTHER plan on that day that already has this subject for this grade, if
 * any (sameGradePlan). `exceptId` is the plan being edited: renaming a plan to
 * its own subject is no clash. Another grade's plan of the subject is no clash
 * either: a combined-grade class plans the subject once per grade.
 */
export function subjectClash<T extends DayPlan>(
    plans: T[], iso: string, subject: string | null | undefined, exceptId: number | null,
    classSubjectsEnabled = false, classSubjectId: number | null = null, grade: string | null | undefined = null,
    severalTaught = false,
): T | null {
    const key = subjectKey(subject);
    const same = plansOn(plans, iso).filter((p) => p.id !== exceptId && (classSubjectsEnabled
        ? (classSubjectId != null ? p.class_subject_id === classSubjectId : p.class_subject_id == null && subjectKey(p.subject) === key)
        : subjectKey(p.subject) === key));

    return sameGradePlan(same, grade, severalTaught) ?? null;
}

/**
 * The subject keys the day's OTHER plans for this grade hold — what a subject
 * picker for that grade must not offer again (each the subject subjectClash
 * would refuse). Another grade's plans take nothing from it.
 */
export function takenSubjectKeys(
    plans: DayPlan[], iso: string, exceptId: number | null, classSubjectsEnabled = false, grade: string | null | undefined = null,
    severalTaught = false,
): Set<string> {
    const bySubject = new Map<string, DayPlan[]>();
    for (const p of plansOn(plans, iso)) {
        if (p.id === exceptId) continue;
        const slot = subjectSlot(p, classSubjectsEnabled);
        bySubject.set(slot, [...(bySubject.get(slot) ?? []), p]);
    }

    return new Set([...bySubject].filter(([, same]) => sameGradePlan(same, grade, severalTaught)).map(([slot]) => slot));
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

/**
 * How a plan names itself in a list of the day's plans: its subject, and its
 * grade when it has one, since a combined-grade class has one plan of a subject
 * per grade. `withGrade` false leaves the grade off for a class that teaches
 * one grade, where it would only repeat itself on every chip.
 */
export function planLabel(plan: DayPlan | null | undefined, withGrade = true): string {
    const subject = String(plan?.subject ?? '').trim() || 'General';
    const grade = String(plan?.grade_label ?? '').trim();

    return withGrade && grade ? `${subject} · ${grade}` : subject;
}

/**
 * True when plans or a roster carry more than one grade: the class is taught as
 * a combined-grade class, and its plans must say which grade each is for.
 */
export function severalGrades(labels: Iterable<string | null | undefined>): boolean {
    const keys = new Set<string>();
    for (const label of labels) {
        const key = gradeKey(label);
        if (key !== '') keys.add(key);
    }

    return keys.size > 1;
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
 * A removal the server answered with a 404 has nothing to remove: the plan is
 * already gone (taken off another screen, or by the class's other teacher), or is
 * not there for this teacher. It is the state the teacher asked for, so the screen
 * finishes as it does after a removal that worked, and says nothing. Any other
 * failure is a real one.
 */
export function planAlreadyGone(e: unknown): boolean {
    return (e as { response?: { status?: number } } | null | undefined)?.response?.status === 404;
}

/**
 * Save is offered for a plan with activities whose subject the day does not
 * already have, and not while a save is in flight.
 */
export function canSavePlan(saving: boolean, body: string | null | undefined, clash: DayPlan | null): boolean {
    return !saving && String(body ?? '').trim() !== '' && clash === null;
}

/** A file listed under a plan's Activities, as the plan payload carries it (no url). */
export interface PlanAttachment {
    id: number;
    title?: string;
    original_name?: string;
    size_bytes?: number;
}

export interface CopyablePlan extends DayPlan {
    body?: string | null;
    attachments?: PlanAttachment[];
}

/** The ids the plan save takes as `resource_ids`, in the order the plan lists them. */
export function attachmentIds(plan: { attachments?: PlanAttachment[] | null } | null | undefined): number[] {
    return (plan?.attachments ?? []).map((a) => Number(a.id));
}

/**
 * Attach one more file, keeping the list distinct and within `max`. Returns the
 * SAME list when nothing changes, so a caller can tell a refusal from an add.
 * The server is the authority (a 422 over `max`, or for a file that is not in
 * this class); this only stops the screen offering what it will refuse.
 */
export function withAttachment<T extends PlanAttachment>(list: readonly T[], file: T, max: number): readonly T[] {
    if (list.some((a) => Number(a.id) === Number(file.id)) || list.length >= max) {
        return list;
    }

    return [...list, file];
}

/**
 * The most files one plan lists. Mirrors `groups.lessons.max_attachments`
 * (config/groups.php, default 10); the server is the authority, and
 * tests/lesson-plans.test.ts fails when the two defaults drift apart.
 */
export const MAX_PLAN_FILES = 10;

/** True when the plan lists as many files as it may, so the screen stops offering more. */
export function planFilesFull(list: readonly PlanAttachment[] | null | undefined): boolean {
    return (list?.length ?? 0) >= MAX_PLAN_FILES;
}

/** This class's files that the open plan does not list yet: what the "Add from this class's files" picker offers. */
export function unattachedFiles<T extends PlanAttachment>(
    files: readonly T[], attached: readonly PlanAttachment[] | null | undefined,
): T[] {
    const taken = new Set((attached ?? []).map((a) => Number(a.id)));

    return files.filter((f) => !taken.has(Number(f.id)));
}

/** Detach one file from the form's list. The file itself stays in the class's Files. */
export function withoutAttachment<T extends PlanAttachment>(list: readonly T[], id: number): T[] {
    return list.filter((a) => Number(a.id) !== Number(id));
}

/**
 * "Copy to the rest of this week", for one other day: the write that makes
 * that day's plan for THIS subject and THIS grade match `source`.
 *
 *   - The day has this subject's plan for this grade: rewrite it by its id. It
 *     keeps its OWN activities if it has any — the shared part of a week is its
 *     standard and objective, not what the class actually did on Thursday.
 *   - It has none: create one, beside whatever other subjects and grades that
 *     day holds.
 *
 * Never the by-day PUT: that is the old one-plan-a-day address, and on a day
 * holding exactly one plan it rewrites THAT plan whatever its subject — copying
 * Math onto a Thursday that has only Science would turn the Science plan into
 * a second Math plan.
 */
export function copyRequest<T extends CopyablePlan>(
    base: string, plans: T[], source: T, iso: string, classSubjectsEnabled = false, severalTaught = false,
): { method: 'post' | 'put'; url: string; payload: T & { resource_ids: number[] } } {
    // Another grade's plan of the subject that day is that grade's lesson: it
    // is never the one rewritten. In a class taught as one grade, a plan of the
    // subject with no grade is this grade's, and is (sameGradePlan).
    const same = subjectClash(plans, iso, source.subject, null, classSubjectsEnabled, source.class_subject_id ?? null, source.grade_label ?? null, severalTaught);
    // The files travel with the activities they sit under (T-004.1, "the
    // Activities rule"): a day that keeps its own activities keeps its own files,
    // and a day that takes the source's activities takes the source's files. Never
    // a mix, which would list a worksheet under words that do not mention it.
    // `attachments` (display objects) is dropped from the payload; the API reads
    // `resource_ids`.
    const { attachments: _display, ...rest } = source;
    const payload = {
        ...rest,
        session_date: iso,
        body: same?.body || source.body,
        resource_ids: same?.body ? attachmentIds(same) : attachmentIds(source),
    } as unknown as T & { resource_ids: number[] };

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
