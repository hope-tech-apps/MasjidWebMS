/**
 * The admin Teachers form (views/dashboard/TeachersView.vue): where a refusal is
 * shown, what the class picker lists, and what it says when it has nothing to
 * list. Plain functions, so they can be run by
 * the SPA tests (tests/teacher-form.test.ts) without a browser.
 */

/** The class row the picker ticks: the teachers endpoint's `{id, name}`. */
export type TeacherClassOption = { id: number; name: string };

/**
 * The fields the form renders an inline message for. Anything the server refuses
 * under another key has NO place of its own on the form.
 */
export const TEACHER_FORM_FIELDS = ['name', 'email', 'phone', 'class_ids'] as const;

export type TeacherFormErrors = {
    /** field -> its first message, for the fields the form renders. */
    fields: Record<string, string>;
    /** Every message that has no field to sit under, for the form's banner; '' when none. */
    banner: string;
};

/**
 * Sort a 422 validation bag (`{field: [messages]}`) into inline messages and the banner.
 *
 * A key the form does not render used to be mapped like any other and then shown
 * nowhere: a refusal under `class_subjects.5` stopped the spinner and said
 * nothing, so the office pressed Save six times. Such a message now goes to the
 * banner, which is always on the form: EVERY message of such a key, not only its
 * first (a rule without `bail` gives two for one value), each sentence once. A
 * rendered field keeps one line under its control, the first.
 */
export function sortTeacherFormErrors(bag: unknown, rendered: readonly string[] = TEACHER_FORM_FIELDS): TeacherFormErrors {
    const fields: Record<string, string> = {};
    const unplaced: string[] = [];

    if (!bag || typeof bag !== 'object' || Array.isArray(bag)) {
        return { fields, banner: '' };
    }

    for (const [field, messages] of Object.entries(bag as Record<string, unknown>)) {
        const texts = (Array.isArray(messages) ? messages : [messages])
            .map((message) => (message === undefined || message === null ? '' : String(message).trim()))
            .filter((text) => text !== '');
        if (texts.length === 0) continue;

        // `class_ids.0`, `class_ids.*` etc. all belong to the one control.
        const key = field.split('.')[0];

        if (rendered.includes(key)) {
            if (!fields[key]) fields[key] = texts[0];
            continue;
        }

        for (const text of texts) {
            if (!unplaced.includes(text)) unplaced.push(text);
        }
    }

    return { fields, banner: unplaced.join(' ') };
}

/**
 * Whether a teachers list reply SAID which classes the school has: `meta.classes`
 * is a list, empty or not. A reply without the key (a server older than this
 * screen) says nothing about the classes, which is not the same as "none".
 */
export function hasClassList(meta: unknown): boolean {
    return Array.isArray((meta as { classes?: unknown } | null | undefined)?.classes);
}

/** What the class picker shows. */
export type TeacherPickerState = 'loading' | 'failed' | 'empty' | 'list';

/**
 * Which of its four states the picker is in.
 *
 * "No classes exist yet. Create one first" is a statement about the school, so it
 * is kept for the one case where it is known to be true: the server answered, and
 * its list was empty. With nothing to offer and no such answer (the request
 * failed, or the reply carried no `meta.classes`) the picker says it could not
 * load them and offers Retry; that case used to fall through to the "none exist"
 * sentence and sent an office that has classes off to create one.
 *
 * Classes already in hand are still offered when a later refresh fails.
 */
export function teacherPickerState(picker: { loading: boolean; known: boolean; count: number }): TeacherPickerState {
    if (picker.loading) return 'loading';
    if (picker.count > 0) return 'list';

    return picker.known ? 'empty' : 'failed';
}

/**
 * The picker's options from the teachers list reply (`meta.classes`): every live
 * class of the school, in the order the server gave. Rows without a usable id are
 * dropped rather than rendered as a checkbox that would send `NaN`.
 */
export function classOptionsFrom(meta: unknown): TeacherClassOption[] {
    const rows = (meta as { classes?: unknown } | null | undefined)?.classes;
    if (!Array.isArray(rows)) return [];

    const seen = new Set<number>();
    const options: TeacherClassOption[] = [];

    for (const row of rows) {
        const id = Number((row as { id?: unknown } | null)?.id);
        if (!Number.isInteger(id) || id <= 0 || seen.has(id)) continue;

        seen.add(id);
        options.push({ id, name: String((row as { name?: unknown }).name ?? '') });
    }

    return options;
}
