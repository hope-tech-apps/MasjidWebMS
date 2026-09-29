/**
 * The teacher shell's school guard, with every outside dependency passed IN.
 *
 * `teacherSchoolGuard.ts` wires this to Vue refs, localStorage, sessionStorage,
 * `window.location` and the auth store. Nothing here imports any of them, so the
 * behaviour the guard's comments call load-bearing runs under `npm run test:spa`
 * (node, no `@/` alias, no DOM): a class-level 403 must not reload, two school
 * refusals inside the window must reload once and then stop, concurrent refusals
 * share one refetch, and a response from the school just left must never be read
 * as the server disagreeing. Only `import type` here: node strips those, and a
 * value import of a type-only name would not load.
 */

export type Box<T> = { value: T };

export type SchoolMismatch = { server: number; selected: number };

/** Storage for "when did we last reload after a refusal", scoped to this tab. */
export interface ReloadStamp {
    read(): unknown;
    write(ms: number): void;
    clear(): void;
}

export interface TeacherGuardDeps {
    /** Set when the server bound a school other than the selected one. */
    mismatch: Box<SchoolMismatch | null>;
    /** Set when the server keeps refusing the selected school after one reload. */
    refused: Box<boolean>;

    // The pure decisions (core/helpers/teacherSchools.ts), injected so this file
    // needs no extension-qualified import to load under node.
    echoedSchoolId(headers: unknown): number | null;
    schoolMismatch(echoed: number | null, selected: unknown): SchoolMismatch | null;
    isOutsideMembershipsRefusal(status: unknown, message: unknown): boolean;
    mayReloadAfterRefusal(lastReloadAtMs: unknown, nowMs: number): boolean;

    /** The shared (localStorage) copy of the selection: the fallback only. */
    readStoredSelection(): unknown;
    reloadStamp: ReloadStamp;
    now(): number;
    /** Refetch /api/teacher/user and let the auth store rehydrate its selection. */
    refetchUser(): Promise<void>;
    reload(): void;
    warn(...args: unknown[]): void;
}

export function createTeacherSchoolGuard(deps: TeacherGuardDeps) {
    let selectionProvider: (() => unknown) | null = null;
    let refreshing: Promise<void> | null = null;

    /**
     * Where THIS tab's selection lives (the auth store's in-memory value).
     *
     * With two tabs open the localStorage copy is shared and last-write-wins, so
     * comparing the echo with it would flag the first tab as a mismatch on every
     * request after the second tab picked another school.
     */
    function provideSelectedSchool(provider: () => unknown): void {
        selectionProvider = provider;
    }

    function selectedSchoolId(): number | null {
        if (selectionProvider) {
            const chosen = Number(selectionProvider());
            return Number.isInteger(chosen) && chosen > 0 ? chosen : null;
        }

        try {
            const stored = Number(deps.readStoredSelection());
            return Number.isInteger(stored) && stored > 0 ? stored : null;
        } catch {
            return null;
        }
    }

    /** Compare a settled response's echo with the selection. Current-epoch responses only. */
    function checkEcho(response: { headers?: unknown } | null | undefined): void {
        const mismatch = deps.schoolMismatch(deps.echoedSchoolId(response?.headers), selectedSchoolId());

        if (mismatch) {
            deps.mismatch.value = mismatch;
        }
    }

    /**
     * Handle a refused school: refetch, rehydrate, reload. One at a time — a screen
     * fires a dozen requests, and every one of them 403s together. Returns null when
     * the error is not the "outside memberships" refusal (a class-level 403 is a
     * different fact, and reloading on it would loop).
     */
    function handleRefusal(error: any): Promise<void> | null {
        if (!deps.isOutsideMembershipsRefusal(error?.response?.status, error?.response?.data?.message)) {
            return null;
        }

        if (refreshing) return refreshing;

        refreshing = (async () => {
            try {
                let last: unknown = null;
                try {
                    last = deps.reloadStamp.read();
                } catch { /* private window: no guard, single attempt below */ }

                if (!deps.mayReloadAfterRefusal(last, deps.now())) {
                    // We already reloaded a moment ago and the server still refuses
                    // the school it was just given. Stop; the notice tells the teacher.
                    deps.refused.value = true;
                    return;
                }

                await deps.refetchUser();

                try {
                    deps.reloadStamp.write(deps.now());
                } catch { /* see above */ }

                deps.reload();
            } catch (e) {
                // refetchUser failed: a 401 has already sent them to sign in; anything
                // else leaves the shell as it was. Never throw out of an interceptor.
                deps.warn('[teacher] could not refresh the school list after a refusal', e);
            } finally {
                refreshing = null;
            }
        })();

        return refreshing;
    }

    /** Clear the two notices. The reload stamp is kept: it is what stops a reload loop. */
    function clearNotices(): void {
        deps.mismatch.value = null;
        deps.refused.value = false;
    }

    /**
     * Forget everything about the last session on this tab. Sign-out is an SPA
     * navigation with no reload, so module state would otherwise survive into the
     * next sign-in: a stale notice re-blocks the next teacher's class list, and a
     * stale stamp makes their first legitimate refusal look like a loop.
     */
    function reset(): void {
        clearNotices();
        try {
            deps.reloadStamp.clear();
        } catch { /* private window */ }
    }

    return { provideSelectedSchool, checkEcho, handleRefusal, clearNotices, reset };
}

export interface TeacherResponseDeps {
    isFromSupersededEpoch(config: unknown): boolean;
    dropSupersededResponse(label: string): Promise<never>;
    checkEcho(response: any): void;
    handleRefusal(error: any): unknown;
    onUnauthorized(): Promise<void> | void;
}

/**
 * The teacher client's response interceptors, in the order that matters.
 *
 * Staleness comes BEFORE the echo: a response from the school just left still names
 * it, and reading it as the server disagreeing would block the new school's screen.
 * A refusal is handled and still rejected, so the caller's own error path runs if
 * the (loop-guarded) reload does not.
 */
export function createTeacherResponseHandlers(deps: TeacherResponseDeps) {
    return {
        onFulfilled(res: any) {
            if (deps.isFromSupersededEpoch(res?.config)) {
                return deps.dropSupersededResponse(`${res?.config?.url ?? 'a response'}`);
            }

            deps.checkEcho(res);

            return res;
        },

        async onRejected(error: any) {
            // Includes the abort the switch itself fires.
            if (deps.isFromSupersededEpoch(error?.config)) {
                return deps.dropSupersededResponse(`${error?.config?.url ?? 'a failed request'}`);
            }

            deps.handleRefusal(error);

            if (error?.response?.status === 401) {
                await deps.onUnauthorized();
            }

            return Promise.reject(error);
        },
    };
}
