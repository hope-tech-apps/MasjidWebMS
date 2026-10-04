/**
 * The Teachers column of the office's class list (views/dashboard/GroupsView.vue):
 * one line per teacher, their name and what they teach in that class. Plain
 * functions, so they can be run by the SPA tests (tests/class-teachers.test.ts)
 * without a browser.
 *
 * The server sends the teachers already in order (by name) and each subject with
 * the product's own label (GroupStaff::SUBJECT_LABELS), so there is no list of
 * subjects here to drift from it.
 */
import type { GroupTeacher } from '@/core/types/data/masjid-related/Group';

/** What a teacher of the whole class reads as: the Teachers form's own words. */
export const ALL_SUBJECTS_TEXT = 'All subjects';

/** One line of the cell: a teacher, and what they teach in this class as words. */
export type ClassTeacherLine = { id: number; name: string; subjects: string };

/**
 * What one teacher teaches in one class, as the words beside their name.
 *
 * Null is every subject, and so is an empty list (GroupStaff::teaches() reads it
 * that way, so it can never mean "nothing"). A subject that came without a label
 * is shown by its value rather than left out: leaving it out could make a limited
 * teacher read as teaching everything.
 */
export function subjectsText(subjects: GroupTeacher['subjects'] | undefined): string {
    const words = (Array.isArray(subjects) ? subjects : [])
        .map((s) => String(s?.label || s?.value || '').trim())
        .filter((word) => word !== '');

    return words.length ? words.join(', ') : ALL_SUBJECTS_TEXT;
}

/**
 * The lines of one class's cell, in the order the server gave them. A row that
 * came without the key (a group answered by store or update, which do not carry
 * it) has no lines, and the screen draws that as a dash.
 */
export function classTeacherLines(teachers: readonly GroupTeacher[] | null | undefined): ClassTeacherLine[] {
    if (!Array.isArray(teachers)) return [];

    return teachers.map((t) => ({ id: t.id, name: String(t.name ?? '').trim(), subjects: subjectsText(t.subjects) }));
}
