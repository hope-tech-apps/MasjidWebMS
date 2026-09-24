/**
 * The pure half of Studio's autosave (stores/super/studioDraftStore.ts): which
 * sections changed, and the exact body a PATCH or a preview sends.
 *
 * A PATCH replaces each section it names wholesale (StudioDraftsController::
 * update), so the unit of saving is the section: a changed section is sent
 * whole, an unchanged one is not sent at all, and two steps saving different
 * sections cannot overwrite each other.
 *
 * The body is a plain object, never FormData or URL-encoded. The admin client
 * sends a plain object as JSON (core/services/ApiService.ts:32-62), and PHP
 * parses multipart only on POST, so a PATCH in any other encoding arrives
 * empty; JSON also keeps `false` and numbers as themselves.
 *
 * Only `import type` here, so tests/studio-draft-answers.test.ts runs it under
 * node with the types stripped.
 */
import type { StudioAnswers, StudioSectionKey, StudioStepKey } from "@/core/types/data/Studio";

/** `StudioDraft::ANSWER_SECTIONS`, in the model's order. */
export const STUDIO_SECTIONS: StudioSectionKey[] = ['identity', 'prayer', 'brand', 'content', 'features', 'layout', 'platforms', 'domain'];

function isPlainObject(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

/**
 * A value with its blanks removed: `null`, `undefined`, `''`, and any object or
 * list left empty by that. `false` and `0` are answers and stay.
 *
 * Absent and blank mean the same thing to the server (every key is nullable),
 * so sending neither keeps the stored draft to what the operator actually
 * entered, and a field typed and then cleared compares equal to never typed.
 */
export function compact(value: unknown): unknown {
    if (Array.isArray(value)) {
        const list = value.map(compact).filter((item) => item !== undefined);
        return list.length ? list : undefined;
    }

    if (isPlainObject(value)) {
        const out: Record<string, unknown> = {};
        for (const [key, item] of Object.entries(value)) {
            const kept = compact(item);
            if (kept !== undefined) {
                out[key] = kept;
            }
        }
        return Object.keys(out).length ? out : undefined;
    }

    if (value === null || value === undefined || value === '' || (typeof value === 'number' && Number.isNaN(value))) {
        return undefined;
    }

    return value;
}

/** A section as it is sent: compacted, and an object even when nothing is left. */
export function sectionBody(value: unknown): Record<string, unknown> {
    const kept = compact(value);
    return isPlainObject(kept) ? kept : {};
}

/** Key-order-independent JSON, so a section read back from the server compares equal to itself. */
export function stableStringify(value: unknown): string {
    if (Array.isArray(value)) {
        return `[${value.map(stableStringify).join(',')}]`;
    }
    if (isPlainObject(value)) {
        return `{${Object.keys(value).sort().map((key) => `${JSON.stringify(key)}:${stableStringify(value[key])}`).join(',')}}`;
    }
    return JSON.stringify(value);
}

/** The fingerprint a section is compared by. */
export function sectionFingerprint(value: unknown): string {
    return stableStringify(sectionBody(value));
}

/** Every section, empty. */
export function emptyAnswers(): StudioAnswers {
    return { identity: {}, prayer: {}, brand: {}, content: {}, features: {}, layout: {}, platforms: {}, domain: {} };
}

/**
 * The draft's stored answers as eight editable objects.
 *
 * A section the server stored empty comes back as `[]` (PHP's empty array), and
 * an unknown top-level key cannot exist (the server refuses it), so anything
 * that is not an object becomes `{}`. The copy is deep: the form edits it, and
 * the payload it came from must stay as the server sent it.
 */
export function normaliseAnswers(raw: unknown): StudioAnswers {
    const answers = emptyAnswers();
    const source = isPlainObject(raw) ? raw : {};

    for (const section of STUDIO_SECTIONS) {
        const value = source[section];
        (answers as Record<StudioSectionKey, unknown>)[section] = isPlainObject(value) ? JSON.parse(JSON.stringify(value)) : {};
    }

    return answers;
}

/** Fingerprints of every section, taken when the draft is loaded or saved. */
export function fingerprints(answers: StudioAnswers): Record<StudioSectionKey, string> {
    const out = {} as Record<StudioSectionKey, string>;
    for (const section of STUDIO_SECTIONS) {
        out[section] = sectionFingerprint(answers[section]);
    }
    return out;
}

/** The sections whose content differs from what the server last confirmed. */
export function changedSections(answers: StudioAnswers, saved: Record<StudioSectionKey, string>): StudioSectionKey[] {
    return STUDIO_SECTIONS.filter((section) => sectionFingerprint(answers[section]) !== saved[section]);
}

export type AutosaveBody = {
    lock_version: number;
    answers?: Partial<Record<StudioSectionKey, Record<string, unknown>>>;
    current_step?: StudioStepKey;
};

/** The PATCH body: the lock version read, the named sections whole, and the step when it moved. */
export function autosaveBody(lockVersion: number, answers: StudioAnswers, sections: StudioSectionKey[], step?: StudioStepKey): AutosaveBody {
    const body: AutosaveBody = { lock_version: lockVersion };

    if (sections.length) {
        body.answers = {};
        for (const section of sections) {
            body.answers[section] = sectionBody(answers[section]);
        }
    }

    if (step) {
        body.current_step = step;
    }

    return body;
}

/** The preview body: the unsaved sections stand in for the saved ones; none means the saved draft as it is. */
export function previewBody(answers: StudioAnswers, sections: StudioSectionKey[]): { answers?: Partial<Record<StudioSectionKey, Record<string, unknown>>> } {
    if (!sections.length) {
        return {};
    }

    const body: Partial<Record<StudioSectionKey, Record<string, unknown>>> = {};
    for (const section of sections) {
        body[section] = sectionBody(answers[section]);
    }

    return { answers: body };
}
