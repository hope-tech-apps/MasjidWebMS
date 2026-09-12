import { defineStore } from "pinia"
import { ref } from "vue"
import { useMasjidStore } from "../masjidStore";
import ApiService from "@/core/services/ApiService";
import { AxiosResponse } from "axios";
import { BackendApiRoute } from "@/core/types/config/BackendApiRoutes";
import { PaginatedData } from "@/core/types/data/interfaces/PaginatedData";
import { ContactRequest, ContactRequestState } from "@/core/types/data/masjid-related/ContactRequest";

/**
 * A reply that is ON FILE and was NOT delivered by this request.
 *
 * Two server answers have that shape and both carry the message's state with
 * them, because the facts the admin needs are "your words were saved" and "this
 * person has not been answered yet":
 *
 *  - 500, the relay refused it;
 *  - 409, another request (a second tab, a retry of a click whose response was
 *    lost) is at the relay with this same reply right now, so nothing new was
 *    sent and its outcome is not known yet.
 *
 * A bare Error would drop the state and the screen would have to re-fetch to
 * find out — so it rides along on the thrown object and the view redraws the
 * history from it, keeps the admin's text, and lets them press Send again.
 */
export class ContactReplyNotDeliveredError extends Error {
    constructor(message: string, public readonly state: ContactRequestState | null) {
        super(message);
        this.name = 'ContactReplyNotDeliveredError';
    }
}

/**
 * The contact-us inbox over /api/admin/masjids/{masjid_id}/contact-requests.
 *
 * `replyToContactRequest` sends NO idempotency key, on purpose. It used to send
 * one minted per modal-open and held in a component `ref`, which identified when
 * a dialog was opened rather than what was being sent: reopening the modal or
 * having the message open in a second tab produced a different key, so the
 * double-send guard was inert in exactly the cases it was written for and a
 * member of the public got the same reply twice. The server now DERIVES the key
 * from the message and the exact text being sent, which every tab and every
 * retry computes identically without remembering anything. Do not reintroduce a
 * client-minted key here: a fresh one per click looks identical at the call site
 * and defeats the whole guard.
 */
/**
 * The first message out of a `{status: 'failed', data: {field: [msg]}}` body,
 * or null when the body is not one. BaseFormRequest's 422 envelope carries the
 * error bag in `data`, and nothing else on this endpoint does.
 */
function firstValidationMessage(body: any): string | null {
    if (body?.status !== 'failed' || !body?.data || typeof body.data !== 'object') {
        return null;
    }

    for (const messages of Object.values(body.data as Record<string, unknown>)) {
        if (Array.isArray(messages) && typeof messages[0] === 'string') {
            return messages[0];
        }
        if (typeof messages === 'string') {
            return messages;
        }
    }

    return null;
}

export const useContactRequestsStore = defineStore('contactRequestsStore', () => {

    // State
    const contactRequestsPaginated = ref<PaginatedData<ContactRequest>>();

    /**
     * The exact subject line each listed message's reply will carry, keyed by
     * message id, as composed by the server's Mailable. The confirmation step
     * shows this before anything is sent; it is NOT re-derived here, because a
     * TypeScript copy of the format would drift from the mail the server
     * actually sends and the screen would then be lying about outgoing mail.
     */
    const replySubjects = ref<Record<string, string>>({});

    /** The display name the reply will appear to come from. */
    const replyFromName = ref<string>('');

    // Stores
    const masjidStore = useMasjidStore();

    /**
     * Fetch paginated contact requests for the masjid
     */
    async function fetchContactRequests(page: number = 1, search: string = ''): Promise<void> {
        if (masjidStore.masjid?.id) {
            if (contactRequestsPaginated.value) {
                contactRequestsPaginated.value.data = [];
            }

            let url = `/api/admin/masjids/${masjidStore.masjid.id}/contact-requests?page=${page}`;
            if (search) {
                url += `&search=${encodeURIComponent(search)}`;
            }

            await ApiService.get(url as BackendApiRoute)
                .then((res: AxiosResponse) => {
                    if (res.data?.status === 'success' && res.data?.data) {
                        contactRequestsPaginated.value = res.data.data;
                        replySubjects.value = res.data?.meta?.reply_subjects ?? {};
                        replyFromName.value = res.data?.meta?.reply_from_name ?? '';
                    }
                })
                .catch((e: Error) => {
                    console.error('Fetch contact requests error: ', e);
                    throw e;
                });
        }
    }

    /**
     * Fetch a single contact request by ID
     */
    async function fetchContactRequest(id: number | string): Promise<ContactRequest | null> {
        if (masjidStore.masjid?.id) {
            try {
                const res: AxiosResponse = await ApiService.get(
                    `/api/admin/masjids/${masjidStore.masjid.id}/contact-requests/${id}` as BackendApiRoute
                );
                if (res.data?.status === 'success' && res.data?.data) {
                    return res.data.data;
                }
            } catch (e: any) {
                console.error('Fetch contact request error: ', e);
                throw e;
            }
        }
        return null;
    }

    /**
     * Send an email reply to a contact request's contacter, and get back the
     * message's new state so the caller can redraw without re-fetching.
     *
     * `sentNow` is the server's `meta.sent_now`: false means the endpoint
     * succeeded WITHOUT sending anything because this exact reply had already
     * gone (a replay). The caller must not announce "sent" for that — the whole
     * point of the guard is that the second request did not put a second copy in
     * a stranger's inbox, and saying otherwise would hide it.
     *
     * A reply that is on file but was not delivered by this request throws
     * ContactReplyNotDeliveredError with the state attached; see that class.
     */
    async function replyToContactRequest(
        id: number,
        reply: string
    ): Promise<{ message: string; state: ContactRequestState; sentNow: boolean }> {
        if (!masjidStore.masjid?.id) {
            throw new Error('Masjid not specified.');
        }

        try {
            const res: AxiosResponse = await ApiService.post(
                `/api/admin/masjids/${masjidStore.masjid.id}/contact-requests/${id}/reply` as BackendApiRoute,
                { reply }
            );

            if (res.data?.status === 'success') {
                return {
                    message: res.data?.message ?? 'Reply sent successfully.',
                    state: res.data?.data,
                    // Absent means an older build of the endpoint, which only
                    // ever answered 200 for a send it had just made.
                    sentNow: res.data?.meta?.sent_now !== false
                };
            }

            throw new Error(res.data?.message ?? 'Failed to send reply.');
        } catch (e: any) {
            const body = e?.response?.data;

            // "On file, not delivered" — the 500 and the 409, which are the only
            // answers that carry this message's state.
            //
            // Discriminated on the ENVELOPE the controller writes, not on the
            // mere presence of `data`: BaseFormRequest answers a validation
            // failure with `{status: 'failed', data: <error bag>}`, which also
            // has a `data`. Reading that as "saved but not sent" told the office
            // an email had gone out when nothing had been written at all, and
            // then wrote the error bag over the row's `answered_at`/`replies` —
            // flipping an already-answered message back to "Awaiting reply".
            if (body?.status === 'error' && typeof body?.data?.id === 'number') {
                throw new ContactReplyNotDeliveredError(
                    body?.message ?? 'The reply was saved but could not be sent.',
                    body.data
                );
            }

            // A validation refusal. Axios's own message is "Request failed with
            // status code 422", which tells the admin nothing, so surface the
            // first thing the validator actually objected to.
            const firstError = firstValidationMessage(body);
            if (firstError) {
                throw new Error(firstError);
            }

            throw e;
        }
    }

    /**
     * Set or clear the answered flag without sending anything — the reply staff
     * gave over the phone.
     *
     * Urlencoded "1"/"0", like every other edit path in this SPA (ApiService.patch
     * defaults to that content type). NOT the strings "true"/"false": Laravel's
     * `boolean` rule rejects those, and sending them is what once blocked every
     * live Jummah-lunch order.
     */
    async function markAnswered(id: number, answered: boolean): Promise<ContactRequestState> {
        if (!masjidStore.masjid?.id) {
            throw new Error('Masjid not specified.');
        }

        const body = new URLSearchParams();
        body.append('answered', answered ? '1' : '0');

        const res: AxiosResponse = await ApiService.patch(
            `/api/admin/masjids/${masjidStore.masjid.id}/contact-requests/${id}/answered` as BackendApiRoute,
            body
        );

        if (res.data?.status === 'success' && res.data?.data) {
            return res.data.data;
        }

        throw new Error(res.data?.message ?? 'Failed to update the message.');
    }

    /**
     * Delete a contact request
     */
    async function deleteContactRequest(id: number): Promise<boolean> {
        if (masjidStore.masjid?.id) {
            try {
                const res: AxiosResponse = await ApiService.delete(
                    `/api/admin/masjids/${masjidStore.masjid.id}/contact-requests/${id}` as BackendApiRoute
                );
                if (res.data?.status === 'success') {
                    return true;
                }
            } catch (e: any) {
                console.error('Delete contact request error: ', e);
                throw e;
            }
        }
        return false;
    }

    return {
        contactRequestsPaginated,
        replySubjects,
        replyFromName,
        fetchContactRequests,
        fetchContactRequest,
        replyToContactRequest,
        markAnswered,
        deleteContactRequest
    }
})
