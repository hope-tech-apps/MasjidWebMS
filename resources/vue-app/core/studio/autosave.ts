/**
 * When Studio's autosave sends, and what it does with the answer
 * (docs/manara-studio-w1.md S5, R6). The store (stores/super/studioDraftStore.ts)
 * owns the answers, the lock version and the PATCH; this owns the timing and
 * the rules, apart from Vue and axios, so tests/studio-autosave.test.ts runs
 * them under node with a stub transport and stub timers.
 *
 *  - An edit is sent `debounceMs` after the last one, so typing a name is one
 *    save, not twelve.
 *  - One PATCH at a time. A save asked for while one is in flight waits for it
 *    and goes next, built at that moment, so it names the version the first
 *    one returned.
 *  - A conflict (409: another tab saved, or the draft was provisioned, first)
 *    disarms the autosave: nothing is sent again until the draft is reloaded.
 *    It is never retried, because a retry would either fail the same way or,
 *    with a fresh version, overwrite the other tab's work.
 *  - Any other failure waits for the next edit or for Retry, so a refused save
 *    is not repeated on a loop.
 *  - A step change is sent at once and the last choice wins: moving back
 *    before a step's save has answered sends the step the operator is on, so
 *    the draft never resumes on a step they left.
 *
 * Only `import type`.
 */
import type { StudioSaveState, StudioStepKey } from "@/core/types/data/Studio";

/** A Vue ref, or any object with a `value`, so tests need no Vue. */
export type Box<T> = { value: T };

/** One save, built by the store at the moment it is sent. */
export type SaveRequest<P> = {
    /** Sends the PATCH; resolves with the server's draft, rejects with the error. */
    send(): Promise<P>;
    /** Takes the server's answer as saved: the version, the fingerprints, the time. */
    confirm(payload: P): void;
};

export type AutosaveTimers = {
    set(run: () => void, ms: number): unknown;
    clear(handle: unknown): void;
};

export type AutosaveOptions<P> = {
    debounceMs: number;
    armed: Box<boolean>;
    saveState: Box<StudioSaveState>;
    saveError: Box<string | null>;
    /**
     * Everything the server does not have yet, `step` included when it is not
     * null; null when there is nothing to send.
     */
    request(step: StudioStepKey | null): SaveRequest<P> | null;
    /** Whether an edit is still unsent (typed while a save was in flight). */
    dirty(): boolean;
    /** The step the server last confirmed. */
    serverStep(): StudioStepKey | null;
    /** Whether a failed save was refused because the draft moved on without this tab. */
    isConflict(error: unknown): boolean;
    /** Show the conflict. The autosave is already disarmed when this runs. */
    onConflict(error: unknown): void;
    /** The sentence for any other failure. */
    failureMessage(error: unknown): string;
    timers?: AutosaveTimers;
};

const browserTimers: AutosaveTimers = {
    set: (run, ms) => setTimeout(run, ms),
    clear: (handle) => clearTimeout(handle as ReturnType<typeof setTimeout>),
};

export function createAutosave<P>(options: AutosaveOptions<P>) {
    const timers = options.timers ?? browserTimers;

    /** Bumped by reset(), so an answer for a draft no longer open is dropped. */
    let epoch = 0;
    let timer: unknown = null;
    /** The step the operator is on, while the server does not hold it yet. */
    let pendingStep: StudioStepKey | null = null;
    /** The step the save in flight carries, if any. */
    let sentStep: StudioStepKey | null = null;
    let inFlight: Promise<void> | null = null;

    function cancelTimer() {
        if (timer !== null) {
            timers.clear(timer);
            timer = null;
        }
    }

    function later() {
        cancelTimer();
        timer = timers.set(() => { timer = null; void flush(); }, options.debounceMs);
    }

    /** An edit was made: save after the pause. Nothing is scheduled while disarmed. */
    function schedule() {
        if (options.armed.value) later();
    }

    /** Save now: everything unsent, and the step when it moved. */
    async function flush(): Promise<void> {
        cancelTimer();
        if (!options.armed.value) return;

        if (inFlight) {
            await inFlight;
            return flush();
        }

        const step = pendingStep;
        const request = options.request(step);
        if (!request) return;

        const mine = epoch;
        let succeeded = false;
        sentStep = step;
        options.saveState.value = 'saving';

        inFlight = (async () => {
            try {
                const payload = await request.send();
                if (mine !== epoch) return;

                request.confirm(payload);
                if (pendingStep === step) pendingStep = null;
                options.saveError.value = null;
                options.saveState.value = 'saved';
                succeeded = true;
            } catch (error) {
                if (mine !== epoch) return;

                if (options.isConflict(error)) {
                    options.armed.value = false;
                    cancelTimer();
                    options.saveState.value = 'conflict';
                    options.onConflict(error);
                    return;
                }

                options.saveError.value = options.failureMessage(error);
                options.saveState.value = 'error';
            }
        })();

        try {
            await inFlight;
        } finally {
            if (mine === epoch) {
                inFlight = null;
                sentStep = null;
            }
        }

        // Edits made during the save, or a step chosen during it, go after the
        // usual pause. Only after a success: see the failure rules above.
        if (succeeded && mine === epoch && options.armed.value && (options.dirty() || pendingStep !== null)) {
            later();
        }
    }

    /**
     * The operator moved to `step`. It is sent at once unless the server
     * already holds it; then only a save in flight carrying ANOTHER step needs
     * correcting, by sending this one after it. A step still waiting to be sent
     * is replaced, so the last choice wins.
     */
    function queueStep(step: StudioStepKey) {
        if (!options.armed.value) return;

        if (step === options.serverStep()) {
            if (inFlight && sentStep !== null && sentStep !== step) {
                pendingStep = step;
                void flush();
            } else {
                pendingStep = null;
            }
            return;
        }

        pendingStep = step;
        void flush();
    }

    /** Forget everything for the draft that was open. */
    function reset() {
        epoch++;
        cancelTimer();
        pendingStep = null;
        sentStep = null;
        inFlight = null;
    }

    return {
        schedule,
        flush,
        queueStep,
        reset,
        /** A save is in flight. */
        busy: () => inFlight !== null,
        /** A step change is not on the server yet. */
        stepUnsent: () => pendingStep !== null,
        /** A save is waiting for its pause (tests read this). */
        scheduled: () => timer !== null,
    };
}
