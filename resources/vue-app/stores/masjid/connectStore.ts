import { defineStore } from "pinia"
import { ref } from "vue"
import { useMasjidStore } from "../masjidStore";
import { useAuthStore } from "../authStore";
import ApiService from "@/core/services/ApiService";
import { BackendApiRoute } from "@/core/types/config/BackendApiRoutes";
import { AxiosResponse, isAxiosError } from "axios";
import {
    ConnectOnboarding,
    ConnectStatus,
    FormsCardAccount,
    FormsCardLinkPayload,
    FormsCardLinkResult
} from "@/core/types/data/masjid-related/StripeConnect";

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

    // ------------------------------------------------ form card payments through another org
    //
    // DECISIONS.md 2026-09-15. A child program organisation's FORM card payments may go
    // through its parent's Stripe account. None of these answers carries an account id.

    /**
     * Whether a form on this organisation (or `forMasjidId`, the SuperAdmin screen's
     * organisation) can take a card payment right now, and through whom. In the forms route
     * group, so a form builder without `manage donations` can read it. Throws on failure.
     */
    async function fetchFormsCardAccount(forMasjidId: number | string | null = null): Promise<FormsCardAccount> {
        const id = forMasjidId ?? masjidId();
        if (!id) {
            throw new Error('Masjid not specified.');
        }

        const res: AxiosResponse = await ApiService.get(`/api/admin/masjids/${id}/forms/card-account`);
        const data = res.data?.data;

        if (res.data?.status === 'success' && data && typeof data.state === 'string') {
            return {
                state: data.state,
                holder: data.holder ?? null,
                problem: data.problem ?? null
            } as FormsCardAccount;
        }

        throw new Error('Could not read how card payments are taken for this organisation.');
    }

    /**
     * SuperAdmin: link (or unlink, `via_masjid_id: null`) an organisation's form card
     * payments to its parent. Sent as JSON (a plain object; ApiService's interceptor), so
     * the null reaches Laravel as null. A 403 or 422 is rethrown for the screen to show in
     * the server's own words.
     */
    async function setFormsCardAccount(
        forMasjidId: number | string,
        payload: FormsCardLinkPayload
    ): Promise<FormsCardLinkResult> {
        const body: Record<string, unknown> = { via_masjid_id: payload.via_masjid_id };
        if (payload.via_masjid_id !== null) {
            body.typed_holder_name = payload.typed_holder_name ?? '';
            body.consent_reference = payload.consent_reference ?? '';
        }

        // Cast until BackendApiRoute lists `/api/admin/masjids/${string}/forms-card-account`
        // (that file is outside this change); the route exists server-side (routes/admin.php).
        const res: AxiosResponse = await ApiService.patch(
            `/api/admin/masjids/${forMasjidId}/forms-card-account` as BackendApiRoute,
            body
        );

        if (res.data?.status === 'success' && res.data?.data && 'forms_card_via' in res.data.data) {
            return { forms_card_via: res.data.data.forms_card_via ?? null };
        }

        throw new Error('The change did not come back in a readable form. Reload the page to see what was saved.');
    }

    /**
     * The holder side: stop another organisation's form card payments going through this
     * organisation's account. It can only remove a link that points here; setting one stays
     * with a SuperAdmin.
     */
    async function revokeFormsCardFor(childId: number): Promise<void> {
        const id = masjidId();
        if (!id) {
            throw new Error('Masjid not specified.');
        }

        // Cast until BackendApiRoute lists `/api/admin/masjids/${string}/connect/forms-card-for/${string}`;
        // the route exists server-side (routes/admin.php, the connect group).
        const res: AxiosResponse = await ApiService.delete(
            `/api/admin/masjids/${id}/connect/forms-card-for/${childId}` as BackendApiRoute
        );

        if (res.data?.status !== 'success') {
            throw new Error('Could not stop card payments for that organisation. Please try again.');
        }
    }

    return {
        connectStatus,
        fetchStatus,
        startOnboarding,
        fetchFormsCardAccount,
        setFormsCardAccount,
        revokeFormsCardFor
    }
})
