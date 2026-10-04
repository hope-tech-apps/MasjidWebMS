/**
 * Moving a student to another class: what the roster screen works out for itself.
 *
 * NOT the sentences about a move. "What will happen" and what happened are built once, on the
 * server (App\Support\RosterMovePlan::lines), and the dialog prints them as a list. What is here is
 * what the browser alone can know: which classes to offer, what to send, how a moved row is
 * labelled, and which of the "Put back" forms a row gets.
 *
 * Pure functions, no imports to run: `npm run test:spa` loads this file as it stands.
 */
import type { GroupMembership, MovedClass, MovePreview } from '@/core/types/data/masjid-related/Group';

type Row = Pick<GroupMembership, 'id' | 'role' | 'contact_id' | 'guardian_of_contact_id' | 'left_on' | 'provenance'
    | 'consent_granted_at' | 'consent_scope' | 'moved_from_group_id' | 'moved_to_group_id' | 'moved_on' | 'moved_to'
    | 'moved_from' | 'moved_to_state'> & { contact?: { first_name?: string | null; last_name?: string | null } | null };

type ClassRow = { id: number; name: string; kind?: string; is_active?: boolean; ends_on?: string | null; position?: number | null };

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/** "4 Oct 2026" from a stored day, read as the day it names (never shifted by a time zone). */
export function day(iso: string | null | undefined): string {
    const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso ?? '');
    if (!match) return '';

    return `${Number(match[3])} ${MONTHS[Number(match[2]) - 1]} ${match[1]}`;
}

export const fullName = (contact: Row['contact']): string =>
    `${contact?.first_name ?? ''} ${contact?.last_name ?? ''}`.trim() || 'This person';

/** A class named on a moved row. One deleted since, or one that is gone, has no name to give. */
export const className = (group: MovedClass | null | undefined): string =>
    group && !group.deleted_at && group.name ? group.name : 'a class that was removed';

/**
 * The classes to offer under "Move to": this school's other classes that are running on the chosen
 * day, in the order the Classes page shows them. The server checks all of it again.
 */
export function classOptions(groups: ClassRow[], currentGroupId: number, onDay: string): { id: number; name: string }[] {
    return groups
        .filter((g) => g.kind === 'class' && g.is_active !== false && g.id !== currentGroupId
            && !(g.ends_on && onDay && g.ends_on.slice(0, 10) < onDay))
        .slice()
        .sort((a, b) => {
            const pa = a.position ?? Number.MAX_SAFE_INTEGER;
            const pb = b.position ?? Number.MAX_SAFE_INTEGER;

            return pa !== pb ? pa - pb : a.name.localeCompare(b.name);
        })
        .map((g) => ({ id: g.id, name: g.name }));
}

/**
 * What a move sends. Strings only, no booleans and never the word "null": the body is
 * form-encoded. The `expected_*` keys are what the dialog showed, so the server refuses when what
 * it decides under its locks is something else.
 */
export function moveBody(
    form: { toGroupId: number; movedOn: string; gradeLabel: string },
    preview: Pick<MovePreview, 'path' | 'first_day_in_new_class' | 'joined_on'>,
): Record<string, string> {
    const body: Record<string, string> = {
        to_group_id: String(form.toGroupId),
        moved_on: form.movedOn,
        grade_label: form.gradeLabel.trim(),
        expected_path: preview.path ?? '',
        expected_first_day: preview.first_day_in_new_class ?? '',
    };

    if (preview.path === 'returned' && preview.joined_on) {
        body.expected_joined_on = preview.joined_on;
    }

    return body;
}

/**
 * How a row that was moved is labelled.
 *
 *  - `badge`: the grey badge beside the name. "Moved to …" only while the student is still in that
 *    class; once they have left it too, the ordinary "Left …".
 *  - `note`: a muted line under the name.
 */
export function movedLabels(row: Row): { badge: string | null; note: string | null } {
    const leftBadge = row.left_on ? `Left ${day(row.left_on)}` : null;

    if (row.moved_to_group_id) {
        const to = className(row.moved_to);
        const there = row.moved_to_state?.student_there ?? 'none';

        if (!row.left_on) {
            // Put back by hand after a move: on two rosters, and the roster still says why.
            return { badge: there === 'current' ? `Put back after a move to ${to}` : null, note: null };
        }

        if (there === 'current') {
            return { badge: `Moved to ${to} ${day(row.moved_on)}`.trim(), note: null };
        }

        return { badge: leftBadge, note: `Was moved to ${to} on ${day(row.moved_on)}. No longer there.` };
    }

    if (row.moved_from_group_id) {
        return { badge: leftBadge, note: `Moved from ${className(row.moved_from)} ${day(row.moved_on)}`.trim() };
    }

    return { badge: leftBadge, note: null };
}

/**
 * Guardians of students who moved INTO this class and have no consent recorded here: confirmed,
 * current entries without consent whose student's row carries "moved from". Until consent is
 * recorded they receive nothing from the class story.
 */
export function consentBannerCount(roster: Row[]): number {
    const movedIn = new Set(
        roster.filter((r) => r.role !== 'guardian' && r.moved_from_group_id && !r.left_on).map((r) => r.contact_id),
    );

    return roster.filter((r) => r.role === 'guardian'
        && r.guardian_of_contact_id !== null && movedIn.has(r.guardian_of_contact_id)
        && r.provenance === 'confirmed' && !r.left_on && !r.consent_granted_at).length;
}

export const consentBannerText = (n: number): string => n === 0 ? '' : `${n} ${n === 1 ? 'guardian' : 'guardians'} of students `
    + `who moved into this class ${n === 1 ? 'has' : 'have'} no consent recorded here. They receive nothing from the class `
    + 'story until it is recorded.';

export type PutBackForm = {
    /** `blocked` has NO confirm action: nothing the dialog offers puts the student back. */
    form: 'unchanged' | 'blocked' | 'both_classes' | 'ordinary';
    title: string;
    lines: string[];
    confirmLabel: string | null;
    /** The class to offer as "Open {Class}", when opening it helps. */
    openGroup: { id: number; name: string } | null;
};

/**
 * WHICH "PUT BACK" A ROW GETS. Putting a student back re-opens EVERY guardian entry beside them in
 * this class. On a row that was moved those are the entries the move left behind, and one of them
 * can belong to an adult the office has since removed where the student is now. The server says so
 * per row (`moved_to_state`, the move's own guardian rule), and while it names anybody the form is
 * `blocked`: it offers no way to put the student back.
 */
export function putBackForm(row: Row, roster: Row[]): PutBackForm {
    const student = fullName(row.contact);
    const beside = roster.filter((r) => r.role === 'guardian' && r.guardian_of_contact_id === row.contact_id);
    const names = beside.map((r) => fullName(r.contact) + (r.provenance === 'confirmed' ? '' : ' (not confirmed)'));
    const state = row.moved_to_state;

    if (!row.moved_to_group_id || !state) {
        return {
            form: 'unchanged',
            title: 'Put them back on the roster?',
            lines: [`${student} will be back on the register and every class list, and their guardians will be back in `
                + 'the class with them.'],
            confirmLabel: 'Yes, put them back',
            openGroup: null,
        };
    }

    const to = className(row.moved_to);
    const on = day(row.moved_on);
    const openGroup = state.open_group;

    if (state.guardians_not_vouched.length > 0) {
        const where = openGroup?.name ?? to;
        const several = state.guardians_not_vouched.length > 1;

        return {
            form: 'blocked',
            title: 'Not yet: check the guardians first',
            lines: [
                ...state.guardians_not_vouched.map((g) => g.sentence),
                state.student_there === 'current' || openGroup?.id !== row.moved_to_group_id
                    ? `Remove ${several ? 'those entries' : 'that entry'} on this roster first. Or, if they should still be a `
                        + `guardian, add or confirm them in ${where}.`
                    : `Remove ${several ? 'those entries' : 'that entry'} on this roster first. (If an unconfirmed entry for `
                        + `them is listed in ${where}, confirming it there also clears this.)`,
            ],
            confirmLabel: null,
            openGroup,
        };
    }

    const coming = names.length === 0 ? '' : ` These guardians come back into this class with them: ${names.join(', ')}.`;

    if (state.student_there === 'current') {
        return {
            form: 'both_classes',
            title: 'Put them back in this class too?',
            lines: [`${student} was moved to ${to} on ${on}. Putting them back here leaves them in both classes, on two `
                + `registers.${coming} To move them back instead, open ${to} and use Move there.`],
            confirmLabel: 'Put back here anyway',
            openGroup,
        };
    }

    return {
        form: 'ordinary',
        title: 'Put them back on the roster?',
        lines: [`${student} was moved to ${to} on ${on} and is no longer there. They will be back on the register and `
            + `every class list here.${names.length === 0 ? '' : ` These guardians come back with them: ${names.join(', ')}. `
                + 'Remove any who should no longer be a guardian before you continue.'}`],
        confirmLabel: 'Yes, put them back',
        openGroup: null,
    };
}
