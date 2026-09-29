import { defineStore } from 'pinia';
import FamilyApiService from '@/core/services/FamilyApiService';
import {
    authFailureMasjidId,
    dropSlot,
    putSlot,
    readSlots,
    type FamilyContact,
    type FamilySlots,
} from '@/core/helpers/familySessions';

export type { FamilyContact };

/**
 * The parent's sessions: one per school, side by side.
 *
 * Kept entirely separate from authStore, which holds a STAFF principal — a
 * Contact is not a User, and the two realms share no guard, no permissions and
 * no token. Within this realm, each school is its own identity too: signing in
 * to school B adds a slot beside school A's, and nothing here ever offers "the"
 * family token. The storage rules live in core/helpers/familySessions.ts.
 */
export const useFamilyStore = defineStore('family', {
    state: () => ({
        slots: readSlots(localStorage) as FamilySlots,
    }),

    getters: {
        isSignedInTo: (state) => (masjidId: string | number): boolean =>
            !!state.slots[String(masjidId)],
        contactFor: (state) => (masjidId: string | number): FamilyContact | null =>
            state.slots[String(masjidId)]?.contact ?? null,
        displayNameFor: (state) => (masjidId: string | number): string => {
            const contact = state.slots[String(masjidId)]?.contact;
            if (!contact) return '';
            return [contact.first_name, contact.last_name].filter(Boolean).join(' ');
        },
        /** The schools this parent is signed in to, lowest id first. */
        signedInMasjidIds: (state): string[] =>
            Object.keys(state.slots).sort((a, b) => Number(a) - Number(b)),
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

        /**
         * Both doors mint the same session, so both land here.
         *
         * Stores the session in THIS school's slot and touches no other. A
         * response with no token or no contact is a sign-in that did not happen:
         * it is refused, not stored as a half-session that would look signed in.
         * So is a contact that belongs to a different school than the URL the
         * parent signed in at, which would file one school's token under another's
         * slot.
         */
        adoptSession(masjidId: string | number, res: any) {
            const data = res.data?.data ?? {};
            const contact = data.contact as FamilyContact | undefined;

            if (typeof data.token !== 'string' || data.token === '' || !contact) {
                throw new Error('The sign-in response carried no session.');
            }

            if (contact.masjid_id != null && String(contact.masjid_id) !== String(masjidId)) {
                throw new Error('The sign-in response belongs to a different school.');
            }

            this.slots = putSlot(localStorage, masjidId, { token: data.token, contact });

            return res;
        },

        /** End ONE school's session. The parent stays signed in everywhere else. */
        signOut(masjidId: string | number) {
            this.slots = dropSlot(localStorage, masjidId);
        },

        /**
         * Another tab signed in or out: take storage's word for what is signed
         * in. The request interceptor already reads storage on every call, so
         * without this the screen and the requests would disagree.
         */
        syncFromStorage() {
            this.slots = readSlots(localStorage);
        },

        /**
         * A 401/403 from the portal means the credential is no longer good —
         * revoked by the office, disabled, or the CRM switched off. Drop it
         * rather than leaving the parent staring at empty screens.
         *
         * Takes the failed request's ERROR, not just its status, because the
         * session to end is the one the request was signed with (read off its
         * URL), and a response can land after the parent has moved to another
         * school. `viewedMasjidId` is the school the calling screen is showing;
         * it is the answer only when the error carries no family URL.
         *
         * Returns true only when the failure ended the session of the school the
         * caller is showing — the caller then sends the parent to that school's
         * sign-in. A late failure from a school they have already left ends that
         * school's session and returns false, so it cannot pull them off the
         * page they are on.
         */
        handleAuthFailure(error: any, viewedMasjidId: string | number): boolean {
            const failed = authFailureMasjidId(error, viewedMasjidId);

            if (failed === null) return false;

            this.signOut(failed);

            return failed === String(viewedMasjidId);
        },
    },
});
