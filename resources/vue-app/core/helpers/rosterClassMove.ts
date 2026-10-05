/**
 * Moving a whole class: what the dialog works out for itself.
 *
 * NOT the sentences about a move. What follows the students, what happened to each of them and
 * every refusal are built once, on the server (App\Support\RosterClassMovePlan::lines, and the
 * single move's own lines for one student), and the dialog prints them as they come. What is here
 * is what the browser alone can know: which rows are ticked, what to send for them, and the
 * labels of its own controls.
 *
 * THE TICKS BELONG TO THE OFFICE. They are kept by roster row id across every re-check and every
 * refusal: a re-check never ticks anybody, and only the three bulk buttons (and "Move the rest")
 * change ticks in bulk.
 *
 * Pure functions, no imports to run: `npm run test:spa` loads this file as it stands.
 */

export type GradeMode = 'keep' | 'set' | 'up';

export type OpenGroup = { id: number; name: string; membership_id?: number };

/** One current student, as the class preview lists them. */
export type ClassMoveStudent = {
    membership_id: number;
    name: string | null;
    grade_label: string | null;
    /** The grade the student would hold in the new class: what the tap echoes. */
    grade_after: string | null;
    grade_note: string | null;
    can_move: boolean;
    refusal: string | null;
    open_group: OpenGroup | null;
    path: 'left_and_started' | 'returned' | null;
    first_day_in_new_class: string | null;
    joined_on: string | null;
    expected_consent: string | null;
    /** This row was moved here from the class now chosen: for putting a class back. */
    came_from_target: boolean;
    summary: string;
    lines: string[];
};

export type ClassMovePreview = {
    can_move: boolean;
    refusal: string | null;
    open_group: OpenGroup | null;
    from_group: { id: number; name: string };
    to_group: { id: number; name: string } | null;
    moved_on: string;
    school_today: string;
    /** About the two classes and the clock, never about a child. Null until a move can carry Manara Bucks. */
    expected_bucks_rule: string | null;
    lines: { who: string[]; consent: string[]; records: string[]; bucks: string[]; afterwards: string[] };
    students: ClassMoveStudent[];
    not_listed: { left_or_moved: number; leaders: number };
    counts: Record<string, any> & { students: number; can_move: number; cannot_move: number; report_cards_not_started: number };
    limits: { max_students: number };
};

export type ClassMoveOutcome = 'moved' | 'not_moved' | 'not_reached';

/** One student the request named, with what happened to them. */
export type ClassMoveResultStudent = {
    membership_id: number;
    name: string | null;
    outcome: ClassMoveOutcome;
    new_membership_id?: number;
    lines?: string[];
    reason?: string;
    open_group?: OpenGroup | null;
    /** Not moved only because the roster was busy or had changed: worth offering again. */
    retry?: boolean;
};

export type ClassMoveAnswer = {
    run: string;
    from_group: { id: number; name: string };
    to_group: { id: number; name: string };
    moved_on: string;
    moved: number;
    not_moved: number;
    not_reached: number;
    stopped_by_fault: boolean;
    siblings_left_behind: number;
    students: ClassMoveResultStudent[];
    counts: Record<string, any>;
    lines: { done: string[]; consent: string[]; bucks: string[]; afterwards: string[] };
};

type RosterRow = {
    role: string;
    left_on?: string | null;
    moved_from_group_id?: number | null;
    moved_from?: { id: number; name: string; deleted_at: string | null } | null;
};

// ------------------------------------------------------------------------- the ticks

/** Every student who can move, in the order of the list. */
export const everyoneWhoCanMove = (students: ClassMoveStudent[]): number[] =>
    students.filter((s) => s.can_move).map((s) => s.membership_id);

/** The students who were moved here from the class now chosen, and can go back to it. */
export const whoCameFromTarget = (students: ClassMoveStudent[]): number[] =>
    students.filter((s) => s.can_move && s.came_from_target).map((s) => s.membership_id);

/**
 * The ticks after a check.
 *
 * The FIRST check of a newly opened dialog, or of a newly chosen class (`previous` is null), ticks
 * everyone who can move. After that a re-check never ticks anybody: a row keeps its tick or its
 * absence, a row that can no longer move loses its tick (and says why), and a row that newly can
 * move arrives unticked.
 */
export function ticksAfterCheck(previous: number[] | null, students: ClassMoveStudent[]): number[] {
    const able = everyoneWhoCanMove(students);

    return previous === null ? able : able.filter((id) => previous.includes(id));
}

/**
 * "Move the rest": ONLY the students of the last request that were not reached, or were not moved
 * only because the roster was busy or had changed, and of those only the ones the fresh check says
 * can still move. Nobody else: not a student the office had left out, and not one refused for a
 * reason that has since been cleared.
 */
export function theRest(answer: Pick<ClassMoveAnswer, 'students'>, fresh: ClassMoveStudent[]): number[] {
    const again = answer.students
        .filter((s) => s.outcome === 'not_reached' || (s.outcome === 'not_moved' && s.retry === true))
        .map((s) => s.membership_id);

    return everyoneWhoCanMove(fresh).filter((id) => again.includes(id));
}

/** Is there anybody "Move the rest" could be about? */
export const someWereLeft = (answer: Pick<ClassMoveAnswer, 'students'>): boolean =>
    answer.students.some((s) => s.outcome === 'not_reached' || (s.outcome === 'not_moved' && s.retry === true));

/** The ticked students one request carries: in the order of the list, and no more than the server takes. */
export function toSend(ticked: number[], students: ClassMoveStudent[], max: number): ClassMoveStudent[] {
    return students.filter((s) => s.can_move && ticked.includes(s.membership_id)).slice(0, max);
}

// --------------------------------------------------------------------- what is sent

/**
 * What a class move sends, as the pairs a form carries, in order. Strings only, no booleans and
 * never the word "null": the body is form-encoded, so a blank joining day or grade is ''.
 *
 * The request NAMES the roster rows the office ticked, each with what the list showed for it
 * (`expected_*`), so the server refuses when what it would now do for any of them is something
 * else. An absent list never means "everyone".
 */
export function classMoveFields(
    form: { toGroupId: number; movedOn: string; gradeMode: GradeMode; gradeLabel: string },
    preview: Pick<ClassMovePreview, 'expected_bucks_rule'>,
    students: ClassMoveStudent[],
): Array<[string, string]> {
    const fields: Array<[string, string]> = [
        ['to_group_id', String(form.toGroupId)],
        ['moved_on', form.movedOn],
        ['grade_mode', form.gradeMode],
    ];

    if (form.gradeMode === 'set') {
        fields.push(['grade_label', form.gradeLabel.trim()]);
    }

    fields.push(['expected_bucks_rule', preview.expected_bucks_rule ?? '']);

    students.forEach((student, i) => {
        fields.push(
            [`students[${i}][membership_id]`, String(student.membership_id)],
            [`students[${i}][expected_path]`, student.path ?? ''],
            [`students[${i}][expected_first_day]`, student.first_day_in_new_class ?? ''],
            [`students[${i}][expected_joined_on]`, student.joined_on ?? ''],
            [`students[${i}][expected_consent]`, student.expected_consent ?? ''],
            [`students[${i}][expected_grade]`, student.grade_after ?? ''],
        );
    });

    return fields;
}

// ------------------------------------------------------------------ the dialog's labels

const blank = (grade: string | null | undefined): boolean => (grade ?? '').trim() === '';

/** "1st to 2nd", wherever the grade a student will hold differs from the one they hold. */
export function gradeChange(student: Pick<ClassMoveStudent, 'grade_label' | 'grade_after'>): string | null {
    if ((student.grade_label ?? '').trim() === (student.grade_after ?? '').trim()) return null;

    return `${blank(student.grade_label) ? 'No grade' : student.grade_label} to ${blank(student.grade_after) ? 'no grade' : student.grade_after}`;
}

export const moveButtonLabel = (n: number): string => `Move ${n} ${n === 1 ? 'student' : 'students'}`;

/**
 * Why the Move button is off, in the words shown beside it; '' when it is on. One reason at a
 * time, the first thing the office has to do.
 */
export function whyNotYet(state: {
    saving: boolean;
    toGroupId: number | null;
    movedOn: string;
    check: 'idle' | 'checking' | 'failed' | 'ready';
    canMove: boolean;
    gradeMode: GradeMode | null;
    gradeLabel: string;
    ticked: number;
}): string {
    if (state.saving) return 'Moving…';
    if (!state.toGroupId) return 'Choose a class first';
    if (!state.movedOn) return 'Choose the first day in the new class';
    if (state.check === 'checking' || state.check === 'idle') return 'Checking…';
    if (state.check === 'failed') return 'The check did not finish';
    if (!state.canMove) return 'This class cannot be moved there yet';
    if (!state.gradeMode) return 'Choose what happens to grades';
    if (state.gradeMode === 'set' && blank(state.gradeLabel)) return 'Type the grade to give everyone';
    if (state.ticked === 0) return 'Nobody on this list is ticked';

    return '';
}

/**
 * Current students of this class who were moved here from a class that is NOT on the "Move to"
 * list: it is switched off, has ended or was removed. A state label, read from the roster rows
 * and the loaded list: the office looking for the way back has to be told why the class is not
 * offered.
 */
export function movedFromClassesNotOffered(roster: RosterRow[], offered: { id: number }[]): string[] {
    const counts = new Map<number, { n: number; name: string | null }>();

    roster
        .filter((r) => r.role === 'member' && !r.left_on && r.moved_from_group_id
            && !offered.some((o) => o.id === r.moved_from_group_id))
        .forEach((r) => {
            const id = r.moved_from_group_id as number;
            const name = r.moved_from && !r.moved_from.deleted_at && r.moved_from.name ? r.moved_from.name : null;
            counts.set(id, { n: (counts.get(id)?.n ?? 0) + 1, name });
        });

    return [...counts.values()].map(({ n, name }) => {
        const who = `${n} ${n === 1 ? 'student here was' : 'students here were'} moved from`;

        return name === null
            ? `${who} a class that was removed, so ${n === 1 ? 'that student' : 'they'} cannot be moved back to it.`
            : `${who} ${name}, which is not in this list: it is switched off, has ended or was removed. `
                + `To move ${n === 1 ? 'that student' : 'them'} back, switch ${name} on again in its Edit form first.`;
    });
}

/**
 * What a refused request said. This API answers a refusal as `{status: 'error', message}`; a
 * class move's "changed while you were looking" also carries `data.students`, the students that
 * differ, which the shared reader would take for a validation bag. No response at all (a timeout,
 * a dropped connection) or a server fault is `arrived: false`: nothing may be assumed about what
 * happened, and the same body is not sent again.
 */
export function refusalOf(error: any): { arrived: boolean; message: string; openGroup: OpenGroup | null; changed: number[] } {
    const response = error?.response;
    const body = response?.data;

    if (!response || response.status >= 500) {
        return { arrived: false, message: '', openGroup: null, changed: [] };
    }

    const bag = body?.status === 'failed' && body?.data && typeof body.data === 'object'
        ? Object.values(body.data).flat().map(String).join(' ')
        : '';

    return {
        arrived: true,
        message: bag || (typeof body?.message === 'string' && body.message) || 'The move could not be saved. Nothing was moved.',
        openGroup: body?.open_group ?? null,
        changed: Array.isArray(body?.data?.students) ? body.data.students.map((s: any) => Number(s.membership_id)) : [],
    };
}
