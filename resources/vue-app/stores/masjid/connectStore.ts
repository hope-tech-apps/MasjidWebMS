import { defineStore } from "pinia"
import { ref } from "vue"
import { useMasjidStore } from "../masjidStore";
import { useAuthStore } from "../authStore";
import ApiService from "@/core/services/ApiService";
import { AxiosResponse, isAxiosError } from "axios";
import { ConnectOnboarding, ConnectStatus } from "@/core/types/data/masjid-related/StripeConnect";

/**
 * Stripe Connect onboarding — over /api/admin/masjids/{masjid_id}/connect/*.
 *
 * The store carries NO derived state (no "isConnected" flag): the three UI
 * states are read straight off the raw ConnectStatus fields by the panel, so
 * there is exactly one interpretation of what Stripe reported.
 *
 * The active masjid comes from the same context every other masjid-scoped
 * store uses; the backend `tenant` middleware scopes both endpoints, and the
 * `crm` + `permission:manage donations` gates answer 403 — see isForbidden().
 */

/** True when the server said 403: the admin lacks `manage donations`, or the
 *  masjid's CRM gate is off. The panel hides itself rather than erroring. */
export function isForbidden(e: unknown): boolean {
    return isAxiosError(e) && e.response?.status === 403;
}

/**
 * The human message inside a failed legacy envelope. It travels in two shapes:
 * controller catch blocks return `{status:'failed', data: <message>}`, while
 * the app-level JSON renderer (bootstrap/app.php) returns
 * `{status:'error', message: <message>}`. Fall back to the Error's own
 * message, then to a generic sentence — never to a blank string.
 */
export function envelopeMessage(
    e: unknown,
    fallback: string = 'Something went wrong. Please try again.'
): string {
    if (isAxiosError(e)) {
        const body: unknown = e.response?.data;
        if (body && typeof body === 'object') {
            const env = body as { data?: unknown; message?: unknown };
            if (typeof env.data === 'string' && env.data) return env.data;
            if (typeof env.message === 'string' && env.message) return env.message;
        }
    }
    if (e instanceof Error && e.message) return e.message;
    return fallback;
}

export const useConnectStore = defineStore('connectStore', () => {

    // State
    const connectStatus = ref<ConnectStatus>();

    // Stores
    const masjidStore = useMasjidStore();
    const authStore = useAuthStore();

    /**
     * dashboardMasjidId is restored from localStorage at auth time, so it survives
     * a hard refresh that has not yet finished hydrating masjidStore.masjid.
     */
    function masjidId(): number | string | null {
        return authStore.dashboardMasjidId ?? masjidStore.masjid?.id ?? null;
    }

    /**
     * Refresh the connect status. Throws rather than returning quietly on any
     * failure — a silent return would leave a stale status on screen looking
     * fresh, and the caller needs the error anyway to tell 403 (hide the
     * panel) from a real failure (say so).
     */
    async function fetchStatus(): Promise<void> {
        const id = masjidId();
        if (!id) {
            throw new Error('Masjid not specified.');
        }

        await ApiService.get(`/api/admin/masjids/${id}/connect/status`)
            .then((res: AxiosResponse) => {
                if (res.data?.status === 'success' && res.data?.data) {
                    connectStatus.value = res.data.data as ConnectStatus;
                } else {
                    // A 200 without the payload is a contract breach; resolving
                    // quietly would leave the panel rendering an empty card.
                    throw new Error('Unexpected connect status response.');
                }
            })
            .catch((e: Error) => {
                // A 403 is an expected outcome (permission / CRM gate), not a
                // defect worth a console error on every dashboard visit.
                if (!isForbidden(e)) {
                    console.error('Fetch connect status error: ', e);
                }
                throw e;
            });
    }

    /**
     * Begin (or resume) onboarding and return the hosted Account Link URL.
     *
     * The URL EXPIRES IN MINUTES: the caller must navigate to it immediately
     * in the same user gesture — it is deliberately not kept in store state,
     * so nothing can render a dead link later.
     */
    async function startOnboarding(): Promise<string> {
        const id = masjidId();
        if (!id) {
            throw new Error('Masjid not specified.');
        }

        const res: AxiosResponse = await ApiService.post(
            `/api/admin/masjids/${id}/connect/onboarding`,
            {}
        );

        if (res.data?.status === 'success' && res.data?.data?.onboarding_url) {
            return (res.data.data as ConnectOnboarding).onboarding_url;
        }

        // A 200 without a URL is a contract breach, not a user-fixable state.
        throw new Error('Stripe did not return an onboarding link. Please try again.');
    }

    return {
        connectStatus,
        fetchStatus,
        startOnboarding
    }
})
