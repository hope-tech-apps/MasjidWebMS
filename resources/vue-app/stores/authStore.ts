import { LOCAL_STORAGE_KEYS } from "@/core/constants/appConfigConstants";
import ApiService from "@/core/services/ApiService";
import { SystemRoute } from "@/core/types/config/SystemRoutes";
import { Admin } from "@/core/types/data/Admin";
import { AxiosError, AxiosResponse } from "axios";
import { defineStore } from "pinia";
import { ref } from "vue";
import { useMasjidStore } from "@/stores/masjidStore";
import { MSwal } from "@/core/plugins/SweetAlerts2";
import { getMessageFromObj } from "@/assets/ts/swalMethods";
import { BackendResponseData } from "@/core/types/config/AxiosCustom";
import { bumpTenantEpoch, forgetServerTenant, serverTenantId } from "@/core/tenancy/tenantRequests";
import { grantedMemberships } from "@/core/types/data/Membership";
import { resetTenantScopedStores } from "@/stores/plugins/tenantStoreReset";

export const useAuthStore = defineStore('authStore', () => {

    // Constants
    const user = ref<Admin | null>(null);
    const isAuthenticated = ref<boolean>(false);
    const token = ref<string | null>(null);
    const dashboardMasjidId = ref<number | string | null>(null);

    /**
     * The server answered the last sign-in attempt with a two-step challenge.
     *
     * This flag is the whole reason an enrolled admin can reach the dashboard at
     * all. The challenge arrives as HTTP 200 with `status: 'two_factor_required'`
     * and no token — a 200 that is not a success — and login()'s else branch used
     * to treat every non-success as a fatal error and pop "Sorry, a two-factor
     * authentication code is required to continue." An admin who had turned 2FA
     * on was then stuck on that popup forever, because the sign-in form had no
     * field to put a code in. Special-casing it here, and rendering a code field
     * when it is true, is the fix.
     */
    const twoFactorRequired = ref<boolean>(false);

    /** The inline refusal under the code field (wrong code, or locked out). */
    const twoFactorError = ref<string>('');

    // Stores
    const masjidStore = useMasjidStore();

    async function authenticate() {
        if (token.value && user.value?.id) {
            localStorage.setItem(LOCAL_STORAGE_KEYS.token, token.value);
            isAuthenticated.value = true;
            ApiService.setHeader(token.value);
        } else {
            removeAuth();
        }
    }

    function removeAuth() {
        // Read BEFORE the clears below, and used to decide whether there is an
        // organisation to leave at all. `removeAuth()` is not only sign-out: a
        // FAILED sign-in reaches it too, through authenticate(), and cancelling
        // every in-flight request there would cancel a second sign-in attempt
        // that is still on the wire — leaving the form spinning forever on a
        // response that can no longer arrive. Nobody who never got in has an
        // organisation, so nobody who never got in is cancelled.
        const hadOrganisation = dashboardMasjidId.value !== null || serverTenantId.value !== null;

        localStorage.removeItem(LOCAL_STORAGE_KEYS.token);
        localStorage.removeItem(LOCAL_STORAGE_KEYS.dashboard_masjid_id);
        isAuthenticated.value = false;
        user.value = null;
        token.value = null;
        dashboardMasjidId.value = null;
        masjidStore.masjid = null;
        ApiService.setHeader();

        // S5: the same two lines a switch runs, for the same reason. Signing out
        // does not stop the requests already on the wire, and the next person to
        // sign in on this tab is frequently the SAME browser and a DIFFERENT
        // organisation — a response landing after that lands in their session.
        // Opening a new epoch drops those, and forgetting the echo stops the
        // header naming the organisation that just left.
        if (hadOrganisation) {
            // EMPTY THE STORES TOO, not just the epoch.
            //
            // Dropping in-flight responses stops NEW rows arriving; it does
            // nothing about the rows already sitting in memory. Logout is an
            // in-tab navigation, so contactsStore, donationsStore and the rest
            // keep the previous organisation's records until something
            // overwrites them — and the next person to sign in on this tab is
            // very often a different administrator on the same office computer.
            // They would see the last person's families and giving, on their own
            // dashboard, before their own first fetch resolved.
            //
            // Guarded: a throw here must not strand somebody on a page they have
            // just signed out of.
            try {
                resetTenantScopedStores();
            } catch (e) {
                console.warn('[tenant] store reset on sign-out failed', e);
            }

            bumpTenantEpoch();
            forgetServerTenant();
        }
    }

    function saveDashboardMasjidId(id: number | string) {
        dashboardMasjidId.value = id;
        localStorage.setItem(LOCAL_STORAGE_KEYS.dashboard_masjid_id, (id + ''));
    }

    /**
     * Drop the remembered organisation WITHOUT ending the session.
     *
     * `removeAuth()` also clears it, but that signs the user out; this is for the
     * boot check that finds a stored id the server no longer grants (S5,
     * tenantSwitchStore.rehydrateSelection). A stale id that survives a reload is
     * a tab that spends its session 403ing behind a header that looks fine.
     */
    function forgetDashboardMasjidId() {
        dashboardMasjidId.value = null;
        localStorage.removeItem(LOCAL_STORAGE_KEYS.dashboard_masjid_id);
    }

    /**
     * Sign in. The two-step arguments are OPTIONAL and are sent only when the
     * caller has them.
     *
     * An admin who has not enrolled posts email + password and nothing else, on
     * one round trip, exactly as before this existed — the extra fields are
     * absent from the body, not empty in it, so the server sees the same request
     * it always saw.
     *
     * The challenge is STATELESS: there is no half-signed-in session on the
     * server, so answering it means re-posting the email and password alongside
     * the code. That is deliberate — a partial-login token would be a second
     * credential that exists precisely for accounts in the middle of proving
     * themselves.
     */
    async function login(
        email: string,
        password: string,
        twoFactorCode?: string,
        recoveryCode?: string,
    ): Promise<SystemRoute | void> {

        const formdata = new FormData();
        formdata.append('email', email);
        formdata.append('password', password);
        if (twoFactorCode) {
            formdata.append('two_factor_code', twoFactorCode);
        }
        if (recoveryCode) {
            formdata.append('two_factor_recovery_code', recoveryCode);
        }

        twoFactorError.value = '';

        await ApiService.post('/api/admin/login', formdata)
            .then((res: AxiosResponse) => {
                if (res.data?.status === 'success' && res.data?.data) {
                    twoFactorRequired.value = false;
                    user.value = res.data?.data?.user ?? null;
                    token.value = res.data?.data?.token ?? "";
                } else if (res.data?.status === 'two_factor_required') {
                    // A 200 that is NOT a success: correct password, second
                    // factor still owed. Never MSwal this — it is the normal
                    // next step of a normal sign-in, and an error popup over it
                    // is what locked enrolled admins out.
                    twoFactorRequired.value = true;
                } else {
                    MSwal.fire('Sorry', getMessageFromObj(res), 'error');
                }
            })
            .catch((error: AxiosError<BackendResponseData>) => {
                console.log(error);
                // Once the code screen is up, a refusal belongs UNDER the field
                // the user just typed into, not in a modal they have to dismiss
                // before they can try the next 30-second code. 422 = wrong code,
                // 429 = too many wrong codes.
                const status = error.response?.status;
                if (twoFactorRequired.value && (status === 422 || status === 429)) {
                    twoFactorError.value = getMessageFromObj(error);
                } else {
                    MSwal.fire('Sorry', getMessageFromObj(error), 'error');
                }
            })
            .finally(() => {
                authenticate()
            });

    }

    /** Drop the challenge state — used when the user backs out of the code screen. */
    function cancelTwoFactorChallenge() {
        twoFactorRequired.value = false;
        twoFactorError.value = '';
    }

    /**
     * The realms a staff principal can be re-identified through, in order.
     *
     * Every scoped realm needs its own `/user`, because the admin one is
     * `admin`-gated and answers 401 for anything else — and this runs on EVERY
     * page load. A missing entry here does not degrade gracefully: the boot
     * sequence reads the 401 as a dead session and signs the user out, so the
     * login succeeds and refreshing the page logs them straight back out.
     *
     * Tried in order rather than driven by a stored type, so the chain repairs
     * itself if the persisted value is ever stale or absent.
     */
    const USER_ENDPOINTS = ['/api/admin/user', '/api/teacher/user', '/api/lunch/user'];

    /**
     * Decide which organisation this page load is bound to, AFTER `/user` answers.
     *
     * This used to be one line — `saveDashboardMasjidId(user.masjid.id)` — on the
     * true premise that a MasjidAdmin, a Teacher and a LunchStaff each belong to
     * exactly one organisation. With memberships that premise is gone, and the
     * one line becomes a bug with no error attached to it: `user.masjid` is the
     * OWNED organisation (the `hasOne` on `masjids.user_id`), so an admin who
     * switched to a second organisation and then reloaded, or followed a link, or
     * came back to a restored tab, was silently put back on the first one. The
     * switcher would read correctly, the header would be wrong, and the screen
     * would quietly show the other organisation's data.
     *
     * So: a stored id the SERVER still grants wins, because it is a choice this
     * person made. Anything else — no stored id, or one naming an organisation
     * that is no longer granted (access revoked, another admin's id left behind
     * in a shared browser) — falls back to the owned organisation, then to the
     * default membership, then to the only membership there is.
     *
     * With `multi_membership` false every principal holds exactly one grant, that
     * grant is the owned organisation, and every path below lands on the same id
     * the single line used to write.
     */
    function rehydrateOrganisation(): void {
        const granted = grantedMemberships(user.value?.memberships);
        const owned = user.value?.masjid?.id ?? null;

        const stored = Number(localStorage.getItem(LOCAL_STORAGE_KEYS.dashboard_masjid_id));
        const storedIsGranted = Number.isInteger(stored)
            && stored > 0
            && granted.some(membership => Number(membership.masjid_id) === stored);

        if (storedIsGranted) {
            // Keep it, and mirror it into the ref — the value in localStorage is
            // the only place a switch survives a reload.
            dashboardMasjidId.value = stored;
            return;
        }

        const fallback = owned
            ?? granted.find(membership => membership.is_default)?.masjid_id
            ?? granted[0]?.masjid_id
            ?? null;

        if (fallback !== null && fallback !== undefined) {
            saveDashboardMasjidId(fallback);
        } else if (granted.length === 0 && Array.isArray(user.value?.memberships)) {
            // The server says this principal administers nothing. Leaving a stale
            // id behind would spend the whole session 403ing behind a header that
            // looks perfectly reasonable; NoOrganisationNotice handles the rest.
            forgetDashboardMasjidId();
        }
        // Otherwise (no memberships key at all — a backend older than S4) leave
        // whatever is there alone: that build had no concept of a switch.
    }

    async function fetchAuthUser(): Promise<SystemRoute | void> {
        let lastError: unknown = null;

        for (const url of USER_ENDPOINTS) {
            try {
                const res: AxiosResponse = await ApiService.get(url);

                if (res.data?.status === 'success' && res.data?.data) {
                    user.value = res.data.data;

                    if (user.value?.type === 'SuperAdmin') {
                        const expectedMasjidId = localStorage.getItem(LOCAL_STORAGE_KEYS.dashboard_masjid_id);
                        if (expectedMasjidId)
                            dashboardMasjidId.value = parseInt(expectedMasjidId);
                    } else {
                        rehydrateOrganisation();
                    }

                    return;
                }
            } catch (e) {
                lastError = e;
                // Only a refusal is worth trying the next realm for. Anything
                // else (offline, 500) means the session is not the problem.
                const status = (e as AxiosError)?.response?.status;
                if (status !== 401 && status !== 403 && status !== 404) {
                    throw e;
                }
            }
        }

        console.log(lastError);
        throw lastError ?? new Error('Could not identify the signed-in user.');
    }

    async function logout(): Promise<SystemRoute | void> {
        await ApiService.post('/api/admin/logout', null)
            .finally(() => {
                removeAuth()
            });
    }

    return {
        user, isAuthenticated, token, dashboardMasjidId,
        twoFactorRequired, twoFactorError,
        login, fetchAuthUser, authenticate, logout, removeAuth, saveDashboardMasjidId,
        forgetDashboardMasjidId, cancelTwoFactorChallenge,
    }
})