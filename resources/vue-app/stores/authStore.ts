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
        localStorage.removeItem(LOCAL_STORAGE_KEYS.token);
        localStorage.removeItem(LOCAL_STORAGE_KEYS.dashboard_masjid_id);
        isAuthenticated.value = false;
        user.value = null;
        token.value = null;
        dashboardMasjidId.value = null;
        masjidStore.masjid = null;
        ApiService.setHeader();
    }

    function saveDashboardMasjidId(id: number | string) {
        dashboardMasjidId.value = id;
        localStorage.setItem(LOCAL_STORAGE_KEYS.dashboard_masjid_id, (id + ''));
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
                    } else if (user.value?.masjid?.id) {
                        // MasjidAdmin, Teacher and LunchStaff are each bound to
                        // exactly one organisation; seed the id their shells and
                        // any masjid-scoped fetch lean on.
                        saveDashboardMasjidId(user.value.masjid.id);
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
        cancelTwoFactorChallenge,
    }
})