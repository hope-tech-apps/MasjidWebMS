import { ref } from "vue";
import { AxiosResponse } from "axios";
import { LOCAL_STORAGE_KEYS } from "@/core/constants/appConfigConstants";
import {
    echoedSchoolId,
    isOutsideMembershipsRefusal,
    mayReloadAfterRefusal,
    schoolMismatch,
} from "@/core/helpers/teacherSchools";

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
 */

/** Set when the server bound a school other than the selected one. Read by TeacherLayout. */
export const teacherSchoolMismatch = ref<{ server: number; selected: number } | null>(null);

/**
 * The server keeps refusing the school this tab selected and one refetch-and-reload
 * has already happened. Read by TeacherLayout, which says so instead of reloading again.
 */
export const teacherSchoolRefused = ref<boolean>(false);

const RELOADED_AT_KEY = 'MANARA_TEACHER_SCHOOL_RELOAD_AT';

/** The school this tab selected: what `authStore.saveDashboardMasjidId` wrote. */
function selectedSchoolId(): number | null {
    try {
        const stored = Number(localStorage.getItem(LOCAL_STORAGE_KEYS.dashboard_masjid_id));
        return Number.isInteger(stored) && stored > 0 ? stored : null;
    } catch {
        return null;
    }
}

/** Compare a settled response's echo with the selection. Called for current-epoch responses only. */
export function checkTeacherSchoolEcho(response: AxiosResponse): void {
    const mismatch = schoolMismatch(echoedSchoolId(response?.headers), selectedSchoolId());

    if (mismatch) {
        teacherSchoolMismatch.value = mismatch;
    }
}

let refreshing: Promise<void> | null = null;

/**
 * Handle a refused school: refetch, rehydrate, reload. One at a time — a screen
 * fires a dozen requests, and every one of them 403s together.
 */
export function handleTeacherSchoolRefusal(error: any): Promise<void> | null {
    const status = error?.response?.status;
    const message = error?.response?.data?.message;

    if (!isOutsideMembershipsRefusal(status, message)) return null;

    if (refreshing) return refreshing;

    refreshing = (async () => {
        try {
            let last: unknown = null;
            try {
                last = sessionStorage.getItem(RELOADED_AT_KEY);
            } catch { /* private window: no guard, single attempt below */ }

            if (!mayReloadAfterRefusal(last, Date.now())) {
                // We already reloaded a moment ago and the server still refuses the
                // school it was just given. Stop; the notice tells the teacher.
                teacherSchoolRefused.value = true;
                return;
            }

            // Dynamic imports keep the store graph out of this module's static
            // dependencies (authStore imports the api services that import this).
            const { useAuthStore } = await import("@/stores/authStore");
            await useAuthStore().fetchAuthUser();

            try {
                sessionStorage.setItem(RELOADED_AT_KEY, String(Date.now()));
            } catch { /* see above */ }

            window.location.reload();
        } catch (e) {
            // fetchAuthUser failed: a 401 has already sent them to sign in; anything
            // else leaves the shell as it was. Never throw out of an interceptor.
            console.warn('[teacher] could not refresh the school list after a refusal', e);
        } finally {
            refreshing = null;
        }
    })();

    return refreshing;
}
