import { defineStore } from 'pinia';
import FamilyApiService, { FAMILY_STORAGE_KEYS } from '@/core/services/FamilyApiService';

export interface FamilyContact {
    id: number;
    masjid_id: number;
    first_name: string | null;
    last_name: string | null;
    login_email: string | null;
}

/**
 * The parent's session. Kept entirely separate from authStore, which holds a
 * STAFF principal — a Contact is not a User, and the two realms share no guard,
 * no permissions and no token.
 */
export const useFamilyStore = defineStore('family', {
    state: () => ({
        token: localStorage.getItem(FAMILY_STORAGE_KEYS.token) as string | null,
        contact: JSON.parse(localStorage.getItem(FAMILY_STORAGE_KEYS.contact) || 'null') as FamilyContact | null,
        masjidId: localStorage.getItem(FAMILY_STORAGE_KEYS.masjid) as string | null,
    }),

    getters: {
        isSignedIn: (state) => !!state.token && !!state.contact,
        displayName: (state) => {
            if (!state.contact) return '';
            return [state.contact.first_name, state.contact.last_name].filter(Boolean).join(' ');
        },
    },

    actions: {
        base(masjidId: string | number): string {
            return `/api/family/masjids/${masjidId}`;
        },

        /** Always 202 — the API will not say whether the address is on file. */
        async requestCode(masjidId: string, email: string) {
            return FamilyApiService.post(`${this.base(masjidId)}/auth/request-code`, { email });
        },

        async verifyCode(masjidId: string, email: string, code: string) {
            const res = await FamilyApiService.post(`${this.base(masjidId)}/auth/verify-code`, { email, code });
            return this.adoptSession(masjidId, res);
        },

        /**
         * The second door: a password the parent chose for themselves.
         *
         * Refuses with the SAME 410 the code door does, for all four of its
         * causes — unknown address, revoked login, no password set, wrong
         * password — so a caller cannot use the difference to learn whether an
         * address belongs to a parent at this school.
         */
        async signInWithPassword(masjidId: string, email: string, password: string) {
            const res = await FamilyApiService.post(`${this.base(masjidId)}/auth/password`, { email, password });
            return this.adoptSession(masjidId, res);
        },

        /**
         * The THIRD door: the office emailed a one-time link and the parent
         * clicked it.
         *
         * The token arrives here from `location.hash`, never from the query
         * string — a fragment is not sent to any server, so it cannot be written
         * into an access log, a `Referer` header or a proxy. The staff
         * equivalent WAS found in this production host's nginx logs when it was
         * a query parameter; see App\Services\Auth\AccountAccessService.
         *
         * Refuses with a 410 for all six of its causes (unknown, expired, used,
         * superseded, access revoked since, address moved since). The caller
         * shows one message for all of them — there is nothing to distinguish,
         * and the only useful instruction is the same either way.
         */
        async redeemInvite(masjidId: string, token: string) {
            const res = await FamilyApiService.post(`${this.base(masjidId)}/auth/invite`, { token });
            return this.adoptSession(masjidId, res);
        },

        /** Choose or change a password. Requires an existing session. */
        async setPassword(masjidId: string, password: string, confirmation: string) {
            return FamilyApiService.put(`${this.base(masjidId)}/password`, {
                password,
                password_confirmation: confirmation,
            });
        },

        /** Remove it, going back to emailed codes only. */
        async clearPassword(masjidId: string) {
            return FamilyApiService.delete(`${this.base(masjidId)}/password`);
        },

        /** Both doors mint the same session, so both land here. */
        adoptSession(masjidId: string, res: any) {
            const data = res.data?.data ?? {};

            this.token = data.token ?? null;
            this.contact = data.contact ?? null;
            this.masjidId = String(masjidId);

            localStorage.setItem(FAMILY_STORAGE_KEYS.token, this.token ?? '');
            localStorage.setItem(FAMILY_STORAGE_KEYS.contact, JSON.stringify(this.contact));
            localStorage.setItem(FAMILY_STORAGE_KEYS.masjid, this.masjidId);

            return res;
        },

        signOut() {
            this.token = null;
            this.contact = null;
            localStorage.removeItem(FAMILY_STORAGE_KEYS.token);
            localStorage.removeItem(FAMILY_STORAGE_KEYS.contact);
        },

        /**
         * Any 401/403 from the portal means the credential is no longer good —
         * revoked by the office, disabled, or the CRM switched off. Drop it
         * rather than leaving the parent staring at empty screens.
         */
        handleAuthFailure(status?: number) {
            if (status === 401 || status === 403) {
                this.signOut();
                return true;
            }
            return false;
        },
    },
});
