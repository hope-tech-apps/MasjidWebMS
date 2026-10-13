/**
 * What a shell does when the server refuses a login that works in two areas,
 * with every outside dependency passed IN (the twin of teacherSchoolGuardCore.ts,
 * and wired beside it in teacherSchoolGuard.ts).
 *
 * One login can be Friday-lunch staff at one organisation and a teacher at
 * another. Its token is the session of BOTH areas, so taking one role away does
 * not end the token: the next request in that area's shell is refused (401 at the
 * area's gate when the role was the login's second kind, 403 at the organisation
 * when it was its first) while the other area keeps answering. Left alone, the
 * teacher client reads the 401 as a dead session and pushes the sign-in page,
 * and the router sends a signed-in login straight back to the shell that refuses
 * it.
 *
 * The refusal itself cannot say which it is. Outside `APP_DEBUG` every 403 reads
 * "Request failed.", whether the organisation refused the login or a class did
 * ("You do not lead this class."), so NOTHING here reads the sentence. Instead
 * the shell asks its own area who the login is now:
 *
 *   - the area still has it, at the organisation this tab is in: the refusal was
 *     about something else (a class, a capability). Nothing happens here and the
 *     screen's own error path runs, exactly as for a login with one role;
 *   - the area refuses, or no longer names that organisation: the page is
 *     reloaded ONCE. Boot asks again, the area that still has the login answers,
 *     and main.ts starts the router there.
 *
 * A second reload inside the window is refused (the stamp is the school guard's,
 * so the two guards cannot take turns reloading) and the shell says so instead.
 *
 * A login with one kind of role has no `areas`, never passes the first test
 * below, and keeps the path it always had.
 *
 * Only `import type` here, as in the twin: node strips those.
 */

import type { ReloadStamp } from '@/core/tenancy/teacherSchoolGuardCore';

/** What the area said when it was asked again. */
export type AreaVerdict = 'here' | 'moved' | 'gone';

export interface StaffAreaGuardDeps {
    /** The signed-in login as this tab holds it. Null before sign-in and after sign-out. */
    signedIn(): { type?: unknown; areas?: unknown } | null | undefined;

    // The pure decisions (core/helpers/staffAreas.ts and teacherSchools.ts),
    // injected so this file needs no extension-qualified import to load under node.
    areaOfKind(kind: unknown): string | null;
    hasStaffAreas(areas: unknown): boolean;
    isAreaRequest(url: unknown, area: any): boolean;
    mayReloadAfterRefusal(lastReloadAtMs: unknown, nowMs: number): boolean;

    /**
     * Ask this tab's own area who the login is now (authStore.reaskStaffArea):
     * 'here' when it still has the organisation the tab is in, 'moved' when it
     * answers but no longer names it, 'gone' when it refuses. Anything that is
     * not an answer about the login (offline, a 500) rejects.
     */
    reask(): Promise<AreaVerdict>;

    reloadStamp: ReloadStamp;
    now(): number;
    reload(): void;
    /** One reload has happened and the area still refuses: say so instead of reloading again. */
    stillRefused(): void;
    warn(...args: unknown[]): void;
}

export function createStaffAreaGuard(deps: StaffAreaGuardDeps) {
    let checking: Promise<boolean> | null = null;

    /** Whether this refusal is one a two-area login met inside the area this tab is in. */
    function concernsThisArea(error: any): boolean {
        const status = error?.response?.status;
        if (status !== 401 && status !== 403) return false;

        const who = deps.signedIn();
        if (!who || !deps.hasStaffAreas(who.areas)) return false;

        const area = deps.areaOfKind(who.type);

        // Only this shell's own API. Boot asks an admin route for every kind of
        // login, and a switch asks the OTHER area before it moves; both may be
        // refused, and neither says anything about the area this tab is in.
        return area !== null && deps.isAreaRequest(error?.config?.url, area);
    }

    async function decide(): Promise<boolean> {
        try {
            if (await deps.reask() === 'here') return false;

            let last: unknown = null;
            try {
                last = deps.reloadStamp.read();
            } catch { /* private window: no stamp, a single attempt below */ }

            if (!deps.mayReloadAfterRefusal(last, deps.now())) {
                deps.stillRefused();
                return true;
            }

            try {
                deps.reloadStamp.write(deps.now());
            } catch { /* see above */ }

            deps.reload();

            return true;
        } catch (e) {
            // Could not find out. Never throw out of an interceptor; the caller's
            // own path (today's) runs.
            deps.warn('[staff] could not ask the area again after a refusal', e);

            return false;
        }
    }

    /**
     * Handle a refusal. Returns null at once when it is not this guard's (a login
     * with one role, another area's API, any other status), so those callers are
     * not delayed by a tick. Otherwise a promise of whether the guard TOOK it:
     * true when the page is reloading or the shell now says it cannot open the
     * area, false when the area still has the login and the refusal was about
     * something else. One check at a time: a screen fires a dozen requests and
     * every one of them is refused together.
     */
    function handleRefusal(error: any): Promise<boolean> | null {
        if (!concernsThisArea(error)) return null;
        if (checking) return checking;

        // Cleared in a `finally` on the promise, not inside decide(): a decision
        // reached without awaiting anything would otherwise clear the slot before
        // it was filled and leave a settled promise in it for the rest of the page.
        const run: Promise<boolean> = decide().finally(() => {
            if (checking === run) checking = null;
        });
        checking = run;

        return run;
    }

    return { handleRefusal };
}
