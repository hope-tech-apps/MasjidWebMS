import { ref } from "vue";
import { AxiosResponse } from "axios";
import { LOCAL_STORAGE_KEYS } from "@/core/constants/appConfigConstants";
import {
    echoedSchoolId,
    isOutsideMembershipsRefusal,
    mayReloadAfterRefusal,
    schoolMismatch,
} from "@/core/helpers/teacherSchools";
import { createTeacherSchoolGuard, SchoolMismatch } from "@/core/tenancy/teacherSchoolGuardCore";

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
 */

/** Set when the server bound a school other than the selected one. Read by TeacherLayout. */
export const teacherSchoolMismatch = ref<SchoolMismatch | null>(null);

/**
 * The server keeps refusing the school this tab selected and one refetch-and-reload
 * has already happened. Read by TeacherLayout, which says so instead of reloading again.
 */
export const teacherSchoolRefused = ref<boolean>(false);

const RELOADED_AT_KEY = 'MANARA_TEACHER_SCHOOL_RELOAD_AT';

const guard = createTeacherSchoolGuard({
    mismatch: teacherSchoolMismatch,
    refused: teacherSchoolRefused,
    echoedSchoolId,
    schoolMismatch,
    isOutsideMembershipsRefusal,
    mayReloadAfterRefusal,
    readStoredSelection: () => localStorage.getItem(LOCAL_STORAGE_KEYS.dashboard_masjid_id),
    reloadStamp: {
        read: () => sessionStorage.getItem(RELOADED_AT_KEY),
        write: (ms) => sessionStorage.setItem(RELOADED_AT_KEY, String(ms)),
        clear: () => sessionStorage.removeItem(RELOADED_AT_KEY),
    },
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

/** Handle a refused school: refetch, rehydrate, reload. One at a time. */
export function handleTeacherSchoolRefusal(error: any): Promise<void> | null {
    return guard.handleRefusal(error);
}

/** Clear the two notices only (TeacherLayout, on mount). The reload-loop stamp stays. */
export const clearTeacherSchoolNotices = guard.clearNotices;

/** Forget the last session's notices AND the reload stamp (sign-out). */
export const resetTeacherSchoolGuard = guard.reset;
