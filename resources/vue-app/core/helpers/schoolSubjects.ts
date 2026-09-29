/**
 * The office's Subjects screen, as plain functions (no Vue, no HTTP).
 *
 * `subjectKeyOf` mirrors App\Support\SubjectKey exactly: trim, collapse
 * whitespace, lower-case, drop every apostrophe-like mark. The server is the
 * authority (its unique index holds the same key); this only lets the screen
 * say "already on the list" before a request, in the same terms. The two are
 * pinned to the same spellings in tests/school-subjects.test.ts and
 * tests/Unit/SubjectKeyTest.php.
 */

/** The levels the screen offers, spelled the way roster memberships spell them (App\Support\GradeLevel::LEVELS). */
export const GRADE_LEVELS = [
    'Pre-K', 'KG', '1st', '2nd', '3rd', '4th', '5th', '6th', '7th', '8th', '9th', '10th', '11th', '12th',
];

/** U+0027, U+2018, U+2019, U+02BB, U+02BC, U+02BE, U+02BF. */
const APOSTROPHES = /['‘’ʻʼʾʿ]/g;

export function cleanSubjectName(name: string | null | undefined): string {
    return String(name ?? '').replace(/\s+/gu, ' ').trim();
}

export function subjectKeyOf(name: string | null | undefined): string {
    return cleanSubjectName(name).replace(APOSTROPHES, '').toLowerCase();
}

export interface SubjectRow { id: number; name: string; grade_labels: string[] | null; position: number; work_count?: number }

/** Another subject that already has this name, or undefined. `ignoreId` is the one being edited. */
export function clashWith(name: string, subjects: readonly SubjectRow[], ignoreId: number | null = null): SubjectRow | undefined {
    const key = subjectKeyOf(name);
    if (key === '') return undefined;
    return subjects.find((s) => s.id !== ignoreId && subjectKeyOf(s.name) === key);
}

/**
 * "Every grade", or the levels in teaching order with runs joined: 3rd, 4th and
 * 5th read "3rd–5th". An unknown level (never offered, but a payload may
 * carry one) is kept as written after the known ones rather than dropped.
 */
export function gradesSummary(labels: readonly string[] | null | undefined): string {
    if (!labels || labels.length === 0) return 'Every grade';

    const known = GRADE_LEVELS.filter((l) => labels.includes(l));
    const unknown = labels.filter((l) => !GRADE_LEVELS.includes(l));

    const parts: string[] = [];
    let i = 0;
    while (i < known.length) {
        let j = i;
        while (j + 1 < known.length && GRADE_LEVELS.indexOf(known[j + 1]) === GRADE_LEVELS.indexOf(known[j]) + 1) j++;
        parts.push(j - i >= 2 ? `${known[i]}–${known[j]}` : known.slice(i, j + 1).join(', '));
        i = j + 1;
    }

    return [...parts, ...unknown].join(', ');
}

export interface SubjectForm { name: string; grades: string[]; position: number | string }

/** The body: an empty grade list is sent as an empty list, which the server reads as every grade. */
export function subjectPayload(form: SubjectForm): { name: string; grade_labels: string[]; position: number } {
    const chosen = GRADE_LEVELS.filter((l) => form.grades.includes(l));
    const position = Number(form.position);

    return {
        name: cleanSubjectName(form.name),
        grade_labels: chosen,
        position: Number.isFinite(position) && position >= 0 ? Math.floor(position) : 0,
    };
}

export function subjectFormFrom(row: SubjectRow | null): SubjectForm {
    return { name: row?.name ?? '', grades: [...(row?.grade_labels ?? [])], position: row?.position ?? 0 };
}

/**
 * What the office is told before renaming or removing a subject that work already
 * carries: nothing is lost, because work keeps the name it was set under.
 */
export function workNote(count: number | undefined): string {
    if (!count) return '';
    return `${count} piece${count === 1 ? '' : 's'} of work ${count === 1 ? 'is' : 'are'} filed under this name and will keep it.`;
}

export function firstError(e: any, fallback: string): string {
    const data = e?.response?.data?.data;
    if (data && typeof data === 'object') {
        for (const messages of Object.values(data)) {
            if (Array.isArray(messages) && typeof messages[0] === 'string') return messages[0];
        }
    }
    const message = e?.response?.data?.message;
    return typeof message === 'string' && message ? message : fallback;
}
