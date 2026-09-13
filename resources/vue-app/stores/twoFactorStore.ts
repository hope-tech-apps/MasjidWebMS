import { defineStore } from "pinia";
import { computed, ref } from "vue";
import ApiService from "@/core/services/ApiService";
import { AxiosResponse } from "axios";
import { useAuthStore } from "@/stores/authStore";

/**
 * Two-step sign-in for the ACTING ADMIN'S OWN ACCOUNT.
 *
 * Per-account, not per-organisation: every call is `/api/admin/2fa/*` with no
 * masjid segment, and for the first four the server only ever touches
 * `Auth::user()`. A second factor somebody else can turn on or off for you is
 * not a second factor.
 *
 * `clearForUser()` is the deliberate exception and the only call here that acts
 * on another account. It exists because the rule above, applied without one,
 * meant an admin who lost their phone AND their printed codes was locked out of
 * the platform permanently. It is SuperAdmin-only, it asks the operator for a
 * live code from their OWN authenticator, it will not act on the operator's own
 * account, and every use is recorded and emailed to the person it was done to —
 * see TwoFactorController::resetForUser for why each of those is load-bearing.
 *
 * WHOSE SCREEN THIS IS. `/api/admin/2fa/*` sits behind `UserAdminMiddleware`,
 * whose ADMIN_TYPES are SuperAdmin and MasjidAdmin only. A Teacher or a
 * LunchStaff login gets 401 from every call here, so the card is rendered only
 * in the admin shells (it lives on the admin/super ProfileView, which those
 * realms have no route to). Widening ADMIN_TYPES to let them enrol is expressly
 * not the fix — per-realm endpoints would be, as a later slice.
 *
 * The enrolment state is READ from the signed-in user
 * (`authStore.user.two_factor_confirmed_at`), which `/api/admin/user` already
 * carries, so no status endpoint was added. The secret and the recovery codes
 * are in `User::$hidden` and never appear there — the codes exist in this store
 * only in the response that generates them, and only until the page is left.
 */
export const useTwoFactorStore = defineStore('twoFactorStore', () => {

    // Stores
    const authStore = useAuthStore();

    // State

    /** The pending enrolment: secret + QR, alive only between enroll and confirm. */
    const secret = ref<string>('');
    const qrCode = ref<string>('');

    /**
     * The codes, held ONLY for the moment they are on screen.
     *
     * Never persisted, never written to localStorage, never re-fetchable: the
     * server hands these back exactly once and will not repeat itself without a
     * fresh live code. Leaving the page loses them, which is what the warning
     * next to them says.
     */
    const recoveryCodes = ref<string[]>([]);

    const isLoading = ref<boolean>(false);

    /** The last server refusal, rendered inline rather than in a popup. */
    const errorMessage = ref<string>('');

    // Getters
    const isEnabled = computed<boolean>(() => !! authStore.user?.two_factor_confirmed_at);
    const enabledSince = computed<string | null>(() => authStore.user?.two_factor_confirmed_at ?? null);

    /**
     * Start (or restart) enrolment: rotate a pending secret and fetch the QR.
     *
     * Restarting rotates the secret again, which invalidates a QR already
     * scanned — the card says so. Refused with 422 while an enrolment is already
     * confirmed; changing a live enrolment goes through disable() first, which
     * proves possession.
     */
    async function enroll(): Promise<boolean> {
        isLoading.value = true;
        errorMessage.value = '';
        recoveryCodes.value = [];

        try {
            const res: AxiosResponse = await ApiService.post('/api/admin/2fa/enroll', null);

            if (res.data?.status === 'success' && res.data?.data) {
                secret.value = res.data.data.secret ?? '';
                qrCode.value = res.data.data.qr_code ?? '';
                return true;
            }

            errorMessage.value = res.data?.message ?? 'Could not start two-step sign-in.';
            return false;
        } catch (e: any) {
            errorMessage.value = messageFrom(e, 'Could not start two-step sign-in.');
            return false;
        } finally {
            isLoading.value = false;
        }
    }

    /**
     * Prove the authenticator works and turn two-step sign-in ON.
     *
     * The recovery codes come back in THIS response and nowhere else. A 422
     * leaves the pending secret and the QR exactly where they are, so a mistyped
     * code costs a retry rather than a re-scan.
     */
    async function confirm(code: string): Promise<boolean> {
        isLoading.value = true;
        errorMessage.value = '';

        try {
            const res: AxiosResponse = await ApiService.post('/api/admin/2fa/confirm', { code });

            if (res.data?.status === 'success') {
                recoveryCodes.value = res.data?.data?.recovery_codes ?? [];
                secret.value = '';
                qrCode.value = '';
                // Re-read the account so `two_factor_confirmed_at` — the one
                // piece of 2FA state the payload carries — is current, and the
                // card flips to its "on" face.
                await authStore.fetchAuthUser();
                return true;
            }

            errorMessage.value = res.data?.message ?? 'That code was not accepted.';
            return false;
        } catch (e: any) {
            errorMessage.value = messageFrom(e, 'That code was not accepted.');
            return false;
        } finally {
            isLoading.value = false;
        }
    }

    /**
     * Turn two-step sign-in off. Takes a current 6-digit code OR an unused
     * recovery code — the server accepts either, which is what lets somebody who
     * lost their phone get back to a working authenticator instead of being
     * trapped signed in with a factor they can never satisfy again.
     */
    async function disable(code: string): Promise<boolean> {
        isLoading.value = true;
        errorMessage.value = '';

        try {
            const res: AxiosResponse = await ApiService.deleteWithBody('/api/admin/2fa', { code });

            if (res.data?.status === 'success') {
                secret.value = '';
                qrCode.value = '';
                recoveryCodes.value = [];
                await authStore.fetchAuthUser();
                return true;
            }

            errorMessage.value = res.data?.message ?? 'That code was not accepted.';
            return false;
        } catch (e: any) {
            errorMessage.value = messageFrom(e, 'That code was not accepted.');
            return false;
        } finally {
            isLoading.value = false;
        }
    }

    /**
     * Replace the recovery codes and show the new set once.
     *
     * There is no "just show me the old ones" call, deliberately: a screen that
     * reprints eight standing credentials on demand is a screen anybody at the
     * desk can read. Asking to see them again IS asking for a new set, and the
     * old set stops working the moment this returns.
     */
    async function regenerateRecoveryCodes(code: string): Promise<boolean> {
        isLoading.value = true;
        errorMessage.value = '';

        try {
            const res: AxiosResponse = await ApiService.post('/api/admin/2fa/recovery-codes', { code });

            if (res.data?.status === 'success' && res.data?.data) {
                recoveryCodes.value = res.data.data.recovery_codes ?? [];
                return true;
            }

            errorMessage.value = res.data?.message ?? 'That code was not accepted.';
            return false;
        } catch (e: any) {
            errorMessage.value = messageFrom(e, 'That code was not accepted.');
            return false;
        } finally {
            isLoading.value = false;
        }
    }

    /**
     * OPERATOR DOOR: clear a stranded second factor on somebody else's account.
     *
     * SuperAdmin only, and the arguments are the guard rails rather than a
     * form: `code` is a live code from the OPERATOR'S authenticator (not the
     * stranded person's — they have none, which is why we are here),
     * `subjectEmail` is typed by hand and matched against the row so the
     * account next to the intended one cannot be cleared by a mis-click, and
     * `reason` is kept forever beside the operator's name.
     *
     * Resolves to the server's own sentence on success, because it carries the
     * one fact the operator has to act on: whether the notice to the affected
     * admin actually went out. A mail outage does not roll the reset back, so a
     * silent `true` here would leave somebody locked out and uninformed while
     * the screen said everything was fine.
     */
    async function clearForUser(
        userId: number | string,
        code: string,
        subjectEmail: string,
        reason: string,
    ): Promise<{ ok: boolean; message: string }> {
        isLoading.value = true;
        errorMessage.value = '';

        try {
            const res: AxiosResponse = await ApiService.post(
                `/api/admin/2fa/reset/${userId}`,
                { code, subject_email: subjectEmail, reason },
            );

            if (res.data?.status === 'success') {
                return { ok: true, message: res.data?.message ?? 'Two-step sign-in has been cleared.' };
            }

            errorMessage.value = res.data?.message ?? 'That reset could not be completed.';
            return { ok: false, message: errorMessage.value };
        } catch (e: any) {
            errorMessage.value = messageFrom(e, 'That reset could not be completed.');
            return { ok: false, message: errorMessage.value };
        } finally {
            isLoading.value = false;
        }
    }

    /** Forget everything on screen — called when the panel closes. */
    function reset(): void {
        secret.value = '';
        qrCode.value = '';
        recoveryCodes.value = [];
        errorMessage.value = '';
    }

    /**
     * The server's own words where it has them.
     *
     * The 2FA endpoints answer `{status:'failed', message}` rather than the
     * validation envelope, so the message is where the useful sentence lives —
     * including the "try again in N minute(s)" that a lockout returns at 429.
     *
     * ...with one exception, which is why the `data` branch exists: a
     * FormRequest that fails validation answers `{status:'failed', data:
     * {field: [messages]}}` and no `message` at all. Before the operator door
     * that never happened (the only field was `code`, and a bad code was
     * refused by the controller, not the validator), but "say what happened in
     * a sentence" is a rule the reset form can break, and falling through to
     * the generic fallback would show the operator a refusal that does not tell
     * them what to change.
     */
    function messageFrom(e: any, fallback: string): string {
        const payload = e?.response?.data;

        if (payload?.message) {
            return payload.message;
        }

        const firstField = payload?.data && typeof payload.data === 'object'
            ? Object.values(payload.data)[0]
            : null;

        if (Array.isArray(firstField) && typeof firstField[0] === 'string') {
            return firstField[0];
        }

        if (typeof firstField === 'string') {
            return firstField;
        }

        return fallback;
    }

    return {
        secret, qrCode, recoveryCodes, isLoading, errorMessage,
        isEnabled, enabledSince,
        enroll, confirm, disable, regenerateRecoveryCodes, clearForUser, reset,
    };
});
