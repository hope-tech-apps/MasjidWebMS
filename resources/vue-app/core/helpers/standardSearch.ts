/**
 * Type-to-find a standard in the school's own pacing guide (T-001.1), as one
 * controller a component can drive.
 *
 * The lesson plan's Standard box has done this since 14c64583, inline in
 * TeacherClass.vue and tangled with the plan's draft-loss guards (autoFill,
 * stdLeft, cancelPrefill: the 9ea27686 races). The gradebook needs the same
 * search on a piece of work. Rather than copy that block, the parts that are
 * about SEARCHING are here, with no Vue and no HTTP in them so `npm run test:spa`
 * covers them: debounce, "a slower answer to an older question must not replace a
 * newer one", an on-screen keyboard composing a word, arrow keys and Enter.
 *
 * What is NOT here: what a pick does. The caller passes `onPick`, so the plan can
 * keep filling its own fields under its own rules and a piece of work can store a
 * snapshot. The lesson plan still uses its own inline copy; moving it onto this
 * controller is a separate change with a browser to prove the draft-loss guards
 * survive it (DECISIONS.md, 2026-09-29, W3), and is not made blind here.
 *
 * NEVER FABRICATES: `matches` is whatever the server's ONE matcher
 * (CurriculumController::standards) returned, and this file adds nothing to it.
 * A school with no rows for a subject (Arabic) gets an empty list and the
 * "nothing in the guide" sentence, never a suggestion.
 */

export interface StandardMatch {
    standard_code: string | null;
    focus: string;
    /** The school's own Objective and Learning Outcome, present only on rows that have them. */
    objective?: string | null;
    learning_outcome?: string | null;
    grade_label: string;
    subject: string;
    weeks: number[];
    week_no: number;
    in_scope: boolean;
    assessment_formative?: string | null;
    prefill_source?: string | null;
}

/** A search's whole state. The caller supplies it, so a component can make it reactive. */
export interface StandardSearchState {
    matches: StandardMatch[];
    /** The list is showing. */
    open: boolean;
    /** The highlighted suggestion; -1 is none, so Enter does nothing until an arrow key chooses. */
    active: number;
    /** The query that came back empty, so "nothing matches" never shows for a stale one. */
    emptyFor: string | null;
    /** What is in the box now, read from the box (see onInput). */
    typed: string;
    /** An on-screen keyboard is composing a word (compositionstart to compositionend). */
    composing: boolean;
}

export function newStandardSearchState(): StandardSearchState {
    return { matches: [], open: false, active: -1, emptyFor: null, typed: '', composing: false };
}

export interface StandardSearchOptions {
    /** Ask the server. Returns the matches, or throws. */
    fetch: (query: string) => Promise<StandardMatch[]>;
    /** A row was chosen. */
    onPick: (match: StandardMatch) => void;
    /** Milliseconds between a keystroke and the request; the throttle allows a teacher typing at speed. */
    delayMs?: number;
}

/** Fewer than two letters or digits is not a question yet. */
export function searchable(q: string): boolean {
    return q.replace(/[^\p{L}\p{N}]/gu, '').length >= 2;
}

export function weeksLabel(weeks: number[]): string {
    if (weeks.length === 1) return `Week ${weeks[0]}`;
    const shown = weeks.slice(0, 4).join(', ');
    return weeks.length > 4 ? `Weeks ${shown} +${weeks.length - 4}` : `Weeks ${shown}`;
}

export function createStandardSearch(state: StandardSearchState, options: StandardSearchOptions) {
    const delay = options.delayMs ?? 200;
    let timer: ReturnType<typeof setTimeout> | undefined;
    let seq = 0;

    const close = () => {
        clearTimeout(timer);
        seq++;
        state.open = false;
        state.active = -1;
        state.matches = [];
    };

    const run = async (q: string) => {
        const mine = ++seq;
        try {
            const matches = await options.fetch(q);
            // A slower answer to an older question must not replace a newer one.
            if (mine !== seq || !state.open) return;
            state.matches = matches;
            // A new list, so no row of the old one is highlighted in it.
            state.active = -1;
            state.emptyFor = matches.length ? null : q;
        } catch {
            if (mine === seq) state.matches = [];
        }
    };

    /**
     * The query is read from the INPUT, not from a v-model: while an Android
     * keyboard composes a word the model does not update until the word ends, so
     * it lags a keystroke behind for the whole word.
     */
    const onInput = (value: string) => {
        const q = String(value ?? '').trim();
        state.typed = q;
        clearTimeout(timer);
        state.open = true;
        state.active = -1;

        if (!searchable(q)) {
            seq++;
            state.matches = [];
            state.emptyFor = null;
            return;
        }

        timer = setTimeout(() => { void run(q); }, delay);
    };

    const pick = (match: StandardMatch | undefined) => {
        if (!match) return;
        state.composing = false;
        close();
        options.onPick(match);
    };

    const onKey = (e: { key: string; isComposing?: boolean; preventDefault: () => void }) => {
        // Keys pressed while a keyboard is composing belong to the composition.
        if (e.isComposing) return;
        const n = state.matches.length;

        if (e.key === 'Escape') { close(); return; }
        if (!n) return;

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            state.active = (state.active + 1) % n;
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            state.active = state.active <= 0 ? n - 1 : state.active - 1;
        } else if (e.key === 'Enter' && state.active >= 0 && state.active < n) {
            e.preventDefault();
            pick(state.matches[state.active]);
        }
    };

    return { onInput, onKey, pick, close };
}
