import { ref } from "vue";
import { AxiosResponse } from "axios";
import { LOCAL_STORAGE_KEYS } from "@/core/constants/appConfigConstants";
import {
    echoedSchoolId,
    isOutsideMembershipsRefusal,
    mayReloadAfterRefusal,
    schoolMismatch,
} from "@/core/helpers/teacherSchools";
import { areaOfKind, hasStaffAreas, isAreaRequest } from "@/core/helpers/staffAreas";
import { createStaffAreaGuard } from "@/core/tenancy/staffAreaGuardCore";
import { createTeacherSchoolGuard, ReloadStamp, SchoolMismatch } from "@/core/tenancy/teacherSchoolGuardCore";

/**
 * What the teacher shell does when the server disagrees with it about which
 * school a request was for — the teacher twin of the admin `TenantMismatchNotice`
 * flow (docs/multi-tenant-admin-design.md §5, DECISIONS.md M4).
 *
 * Two disagreements are possible, and they are different facts:
 *
 * 1. **The server BOUND a different school than the tab selected.** Every teacher
 *    tenant response carries `X-Tenant-Id`. When it is not the selected school the
 *    rows on screen belong to the wrong school under a header naming the right
 *    one, with no error anywhere. `teacherSchoolMismatch` is set, and TeacherLayout
 *    hides the screens behind a blocking notice until the teacher reconciles.
 *    Absence is not disagreement: a header the browser cannot read (a page served
 *    from an organisation's own domain), or an older backend, stays silent.
 *
 * 2. **The server REFUSED the selected school** (403, "outside memberships"): the
 *    teacher was removed from it, or the school was archived, while this tab was
 *    open. Every screen would 403 until reload. The shell refetches
 *    `/api/teacher/user`, lets `rehydrateOrganisation` drop the school the server
 *    no longer grants, and reloads. A refusal that is about a CLASS (`teacher.leads`)
 *    is a different 403 and is left alone, or this would loop.
 *
 * The logic lives in teacherSchoolGuardCore.ts with its dependencies injected, so
 * it is tested under node; this file only wires it to the browser. Both notices are
 * module state, and sign-out is an SPA navigation with no reload, so
 * `resetTeacherSchoolGuard()` is called from `removeAuth()`: without it the notice's
 * own remedy ("sign out and sign in again") would leave the next sign-in stuck
 * behind the same notice.
 *
 * A third disagreement arrived with one login holding a role in two areas (lunch
 * staff at one organisation, a teacher at another), and it is wired here because
 * it shares the reload stamp and the "still refused" notice:
 *
 * 3. **The server refused the AREA** (401 or 403, any sentence): the role this
 *    shell is for was taken away while the tab was open, and the same token still
 *    works in the other area. See staffAreaGuardCore.ts. It serves BOTH scoped
 *    shells: the teacher client hands it its refusals below, and main.ts puts it
 *    on the client the lunch board rides.
 */

/** Set when the server bound a school other than the selected one. Read by TeacherLayout. */
export const teacherSchoolMismatch = ref<SchoolMismatch | null>(null);

/**
 * The server keeps refusing the school this tab selected and one refetch-and-reload
 * has already happened. Read by TeacherLayout, which says so instead of reloading again.
 */
export const teacherSchoolRefused = ref<boolean>(false);

const RELOADED_AT_KEY = 'MANARA_TEACHER_SCHOOL_RELOAD_AT';

/** "When did this tab last reload after a refusal". ONE stamp for both guards, so they cannot take turns. */
const reloadStamp: ReloadStamp = {
    read: () => sessionStorage.getItem(RELOADED_AT_KEY),
    write: (ms) => sessionStorage.setItem(RELOADED_AT_KEY, String(ms)),
    clear: () => sessionStorage.removeItem(RELOADED_AT_KEY),
};

const guard = createTeacherSchoolGuard({
    mismatch: teacherSchoolMismatch,
    refused: teacherSchoolRefused,
    echoedSchoolId,
    schoolMismatch,
    isOutsideMembershipsRefusal,
    mayReloadAfterRefusal,
    readStoredSelection: () => localStorage.getItem(LOCAL_STORAGE_KEYS.dashboard_masjid_id),
    reloadStamp,
    now: () => Date.now(),
    refetchUser: async () => {
        // A dynamic import keeps the store graph out of this module's static
        // dependencies (authStore imports the api services that import this).
        const { useAuthStore } = await import("@/stores/authStore");
        await useAuthStore().fetchAuthUser();
    },
    reload: () => window.location.reload(),
    warn: (...args) => console.warn(...args),
});

/**
 * Tell the guard where THIS tab's selection lives (the auth store's in-memory
 * `dashboardMasjidId`), registered by TeacherLayout. See the core for why the
 * shared localStorage copy is not the one compared.
 */
export const provideSelectedSchool = guard.provideSelectedSchool;

/** Compare a settled response's echo with the selection. Called for current-epoch responses only. */
export function checkTeacherSchoolEcho(response: AxiosResponse): void {
    guard.checkEcho(response);
}

/** The signed-in login as its tab holds it, for the area guard. Registered by the auth store. */
let signedInStaff: () => { type?: unknown; areas?: unknown } | null | undefined = () => null;

/**
 * Tell the area guard where the signed-in login lives (the auth store's `user`).
 * A provider, as for the selected school above, because the store imports this
 * module and this module must not import the store back.
 */
export function provideSignedInStaff(provider: () => { type?: unknown; areas?: unknown } | null | undefined): void {
    signedInStaff = provider;
}

const areaGuard = createStaffAreaGuard({
    signedIn: () => signedInStaff(),
    areaOfKind,
    hasStaffAreas,
    isAreaRequest,
    mayReloadAfterRefusal,
    reask: async () => {
        // A dynamic import, for the reason refetchUser gives above.
        const { useAuthStore } = await import("@/stores/authStore");
        return useAuthStore().reaskStaffArea();
    },
    reloadStamp,
    now: () => Date.now(),
    reload: () => window.location.reload(),
    stillRefused: () => { teacherSchoolRefused.value = true; },
    warn: (...args) => console.warn(...args),
});

/**
 * A refusal met by a login that works in two areas, inside the area this tab is
 * in. Null (at once) for every other refusal and every other login; otherwise a
 * promise of whether the guard took it (staffAreaGuardCore.ts).
 */
export function handleStaffAreaRefusal(error: any): Promise<boolean> | null {
    return areaGuard.handleRefusal(error);
}

/**
 * Handle a refused school: refetch, rehydrate, reload. One at a time.
 *
 * A login that works in two areas is asked about first, and when the area guard
 * answers the school guard is not consulted: its refetch walks every `/user`
 * route and would move such a login to its other area's voice before the reload,
 * where the area guard asks this area alone and lets boot do the moving.
 */
export function handleTeacherSchoolRefusal(error: any): Promise<unknown> | null {
    return areaGuard.handleRefusal(error) ?? guard.handleRefusal(error);
}

/**
 * Put the area guard on a client that has no refusal handling of its own: the
 * admin one, which the lunch board rides. It only watches; the refusal is still
 * rejected to the caller, whose own error path runs. For an administrator, and
 * for a login with one kind of role, it does nothing at all.
 */
export function watchStaffAreaRefusals(client: { interceptors: { response: { use(onFulfilled: undefined, onRejected: (error: any) => any): unknown } } }): void {
    client.interceptors.response.use(undefined, (error: any) => {
        areaGuard.handleRefusal(error);

        return Promise.reject(error);
    });
}

/** Clear the two notices only (TeacherLayout, on mount). The reload-loop stamp stays. */
export const clearTeacherSchoolNotices = guard.clearNotices;

/** Forget the last session's notices AND the reload stamp (sign-out). */
export const resetTeacherSchoolGuard = guard.reset;
