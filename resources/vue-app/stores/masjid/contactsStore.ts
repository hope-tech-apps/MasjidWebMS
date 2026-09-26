import { defineStore } from "pinia"
import { ref } from "vue"
import { useMasjidStore } from "../masjidStore";
import ApiService from "@/core/services/ApiService";
import { AxiosResponse } from "axios";
import { PaginatedData } from "@/core/types/data/interfaces/PaginatedData";
import { Contact, ContactPayload, FamilyLoginStatus } from "@/core/types/data/masjid-related/Contact";
import { ContactTag } from "@/core/types/data/masjid-related/ContactTag";
import { contactIdsBody, contactsListUrl, tagContactsUrl, untagContactsUrl } from "@/views/dashboard/contacts/contactTags";
import { emailConsentBody } from "@/views/dashboard/contacts/emailOptOut";

/**
 * Member directory store — CRUD over /api/admin/masjids/{masjid_id}/contacts.
 *
 * The active masjid comes from masjidStore (the same active-masjid context every
 * other masjid-scoped store uses); the backend `tenant` middleware + BelongsToMasjid
 * trait enforce that this admin only ever touches their own masjid's contacts.
 */
export const useContactsStore = defineStore('contactsStore', () => {

    // State
    const contactsPaginated = ref<PaginatedData<Contact>>();
    /** Every tag of the organisation, with counts. The filter, the pickers and the manage panel all read this one list. */
    const tags = ref<ContactTag[]>([]);

    // Stores
    const masjidStore = useMasjidStore();

    /**
     * Fetch a page of contacts, optionally filtered by a free-text search over
     * first/last name, email and phone.
     *
     * `trashed` is the DELETED members view. `Contact` soft-deletes so a
     * mis-click on a congregant record is recoverable, and until this existed
     * nothing in the SPA could reach a deleted one — which also put the
     * `revoked` audit row that deleting a member writes permanently beyond every
     * screen, including the one built to answer "who took my access away".
     * Absent by default, so the ordinary directory is unchanged.
     *
     * `tagId` narrows the list to the members carrying one tag.
     */
    async function fetchContacts(
        page: number = 1,
        search: string = '',
        trashed: '' | 'with' | 'only' = '',
        tagId: number | null = null
    ): Promise<void> {
        if (masjidStore.masjid?.id) {
            if (contactsPaginated.value) {
                contactsPaginated.value.data = [];
            }

            const url = contactsListUrl(masjidStore.masjid.id, page, search, trashed, tagId);

            await ApiService.get(url)
                .then((res: AxiosResponse) => {
                    if (res.data?.status === 'success' && res.data?.data) {
                        contactsPaginated.value = res.data.data;
                    }
                })
                .catch((e: Error) => {
                    console.error('Fetch contacts error: ', e);
                    throw e;
                });
        }
    }

    /** Fetch a single contact by id. */
    async function fetchContact(id: number | string): Promise<Contact | null> {
        if (masjidStore.masjid?.id) {
            const res: AxiosResponse = await ApiService.get(
                `/api/admin/masjids/${masjidStore.masjid.id}/contacts/${id}`
            );
            if (res.data?.status === 'success' && res.data?.data) {
                return res.data.data;
            }
        }
        return null;
    }

    /** Create a contact. Returns the created row on success. */
    async function createContact(payload: ContactPayload): Promise<Contact> {
        if (!masjidStore.masjid?.id) {
            throw new Error('Masjid not specified.');
        }

        const res: AxiosResponse = await ApiService.post(
            `/api/admin/masjids/${masjidStore.masjid.id}/contacts`,
            payload
        );
        if (res.data?.status === 'success' && res.data?.data) {
            return res.data.data;
        }
        throw new Error('Failed to create contact.');
    }

    /** Update a contact. Returns the updated row on success. */
    async function updateContact(id: number | string, payload: ContactPayload): Promise<Contact> {
        if (!masjidStore.masjid?.id) {
            throw new Error('Masjid not specified.');
        }

        // ApiService.put sends application/x-www-form-urlencoded; serialize the
        // body to URLSearchParams (matches the proven edit path in EventFormView).
        const body = new URLSearchParams();
        Object.entries(payload).forEach(([key, value]) => body.append(key, value ?? ''));

        const res: AxiosResponse = await ApiService.put(
            `/api/admin/masjids/${masjidStore.masjid.id}/contacts/${id}`,
            body
        );
        if (res.data?.status === 'success' && res.data?.data) {
            return res.data.data;
        }
        throw new Error('Failed to update contact.');
    }

    /** Delete (soft) a contact. */
    async function deleteContact(id: number | string): Promise<boolean> {
        if (masjidStore.masjid?.id) {
            const res: AxiosResponse = await ApiService.delete(
                `/api/admin/masjids/${masjidStore.masjid.id}/contacts/${id}`
            );
            if (res.data?.status === 'success') {
                return true;
            }
        }
        return false;
    }

    /**
     * Undelete a soft-deleted contact.
     *
     * The other half of `deleteContact`, and deliberately NOT a re-grant: the
     * member comes back with their parent-portal sign-in still `revoked`, so
     * restoring a record and re-opening a credential stay two decisions. The
     * server says the same thing (ContactsController::restore).
     */
    async function restoreContact(id: number | string): Promise<boolean> {
        if (masjidStore.masjid?.id) {
            const res: AxiosResponse = await ApiService.post(
                `/api/admin/masjids/${masjidStore.masjid.id}/contacts/${id}/restore`,
                new URLSearchParams()
            );
            if (res.data?.status === 'success') {
                return true;
            }
        }
        return false;
    }

    // ------------------------------------------- family sign-in (T-015d)

    /**
     * Current parent-portal state for one contact, plus the audit trail.
     *
     * A separate call rather than a field on the contact payload: the trail is
     * a list, and `state` is derived server-side so the SPA never re-implements
     * the portal's liveness rule.
     */
    async function fetchFamilyLogin(contactId: number | string): Promise<FamilyLoginStatus | null> {
        if (!masjidStore.masjid?.id) return null;

        const res: AxiosResponse = await ApiService.get(
            `/api/admin/masjids/${masjidStore.masjid.id}/contacts/${contactId}/family-login`
        );

        return res.data?.status === 'success' ? res.data.data : null;
    }

    /**
     * Turn parent-portal sign-in ON at an address the admin typed.
     *
     * Also the way an address is CHANGED and the way a revoked login is
     * re-opened. There is deliberately no default: `login_email` is a credential
     * and is not `contacts.email`, which is imported, often a shared household
     * address, and verified by nobody. The server refuses a blank one.
     *
     * A 422 here is a REFUSAL with a readable message (address already in use by
     * a named member, or a placeholder card stub), not a crash — the caller
     * shows `response.data.message`.
     *
     * `reassignAddress` is the operator's answer to ONE of those refusals: the
     * address is still assigned to a member whose portal access has already
     * ended (revoked, or deleted), and they have confirmed moving it here. It is
     * not a force flag — a member who can sign in with that address RIGHT NOW
     * still refuses, and must be revoked first. Sent only after the server has
     * shown the refusal, so a confirmation can never precede the explanation.
     */
    async function enableFamilyLogin(
        contactId: number | string,
        loginEmail: string,
        reassignAddress: boolean = false
    ): Promise<FamilyLoginStatus> {
        if (!masjidStore.masjid?.id) {
            throw new Error('Masjid not specified.');
        }

        const body = new URLSearchParams();
        body.append('login_email', loginEmail);
        if (reassignAddress) {
            body.append('reassign_address', '1');
        }

        const res: AxiosResponse = await ApiService.post(
            `/api/admin/masjids/${masjidStore.masjid.id}/contacts/${contactId}/family-login`,
            body
        );

        if (res.data?.status === 'success' && res.data?.data) {
            return res.data.data;
        }
        throw new Error('Failed to enable family sign-in.');
    }

    /**
     * Withdraw parent-portal sign-in.
     *
     * The sign-in address is deliberately KEPT on the record: clearing it here
     * would free it for another member to INHERIT SILENTLY. It is freed only by
     * an operator typing it onto another member and confirming the reassignment
     * (`enableFamilyLogin(..., true)`), which writes both halves to the access
     * history. Any session already holding a token stops working on its next
     * request.
     */
    async function revokeFamilyLogin(contactId: number | string): Promise<FamilyLoginStatus> {
        if (!masjidStore.masjid?.id) {
            throw new Error('Masjid not specified.');
        }

        const res: AxiosResponse = await ApiService.delete(
            `/api/admin/masjids/${masjidStore.masjid.id}/contacts/${contactId}/family-login`
        );

        if (res.data?.status === 'success' && res.data?.data) {
            return res.data.data;
        }
        throw new Error('Failed to revoke family sign-in.');
    }

    /**
     * Email this parent a 7-day link that lands them inside the portal.
     *
     * The missing half of `enableFamilyLogin`: enabling sends the family
     * NOTHING, so a parent nobody walks through the portal never arrives — five
     * of Al-Razi's ten enabled logins had never been used.
     *
     * Sends no body at all. There is nothing for the caller to choose: the
     * address is the one already on the record (never `contacts.email`), the
     * lifetime is the server's, and the eligibility rule is the same one
     * `enableFamilyLogin` is refused by, re-checked at send time because
     * standing lapses without revoking anything.
     *
     * A 422 here is a REFUSAL with a readable message — this member may not hold
     * a sign-in, there is no live sign-in to invite them to, or this mailbox has
     * had the hour's worth of links. A 500 means the email did not go: nothing
     * was recorded and any earlier link still works, which is why the caller must
     * show the failure rather than a tick.
     */
    async function sendFamilyPortalInvite(contactId: number | string): Promise<FamilyLoginStatus> {
        if (!masjidStore.masjid?.id) {
            throw new Error('Masjid not specified.');
        }

        const res: AxiosResponse = await ApiService.post(
            `/api/admin/masjids/${masjidStore.masjid.id}/contacts/${contactId}/family-login/invite`,
            new URLSearchParams()
        );

        if (res.data?.status === 'success' && res.data?.data) {
            return res.data.data;
        }
        throw new Error('Failed to send the portal invite.');
    }

    // ------------------------------------------------------------ tags

    function masjidId(): string {
        if (!masjidStore.masjid?.id) {
            throw new Error('Masjid not specified.');
        }
        return String(masjidStore.masjid.id);
    }

    /** Load every tag. A caller without `view contacts` gets a 403 and an empty list. */
    async function fetchTags(): Promise<ContactTag[]> {
        const res: AxiosResponse = await ApiService.get(`/api/admin/masjids/${masjidId()}/contact-tags`);
        tags.value = res.data?.status === 'success' ? (res.data.data ?? []) : [];
        return tags.value;
    }

    /** Create a tag. A 422 carries "A tag with this name already exists." for the caller to show. */
    async function createTag(name: string): Promise<ContactTag> {
        const body = new URLSearchParams();
        body.append('name', name);
        const res: AxiosResponse = await ApiService.post(`/api/admin/masjids/${masjidId()}/contact-tags`, body);
        await fetchTags();
        return res.data.data;
    }

    async function renameTag(id: number, name: string): Promise<ContactTag> {
        const body = new URLSearchParams();
        body.append('name', name);
        const res: AxiosResponse = await ApiService.put(`/api/admin/masjids/${masjidId()}/contact-tags/${id}`, body);
        await fetchTags();
        return res.data.data;
    }

    /** Delete a tag; its members stay. Refused (422) while a scheduled broadcast is addressed to it. */
    async function deleteTag(id: number): Promise<void> {
        await ApiService.delete(`/api/admin/masjids/${masjidId()}/contact-tags/${id}`);
        await fetchTags();
    }

    /** Tag one or many members. Returns how many did not carry the tag before. */
    async function tagContacts(tagId: number, contactIds: number[]): Promise<number> {
        const res: AxiosResponse = await ApiService.post(tagContactsUrl(masjidId(), tagId), contactIdsBody(contactIds));
        await fetchTags();
        return res.data?.data?.added ?? 0;
    }

    /** Untag one or many members. Returns how many carried it. */
    async function untagContacts(tagId: number, contactIds: number[]): Promise<number> {
        const res: AxiosResponse = await ApiService.post(untagContactsUrl(masjidId(), tagId), contactIdsBody(contactIds));
        await fetchTags();
        return res.data?.data?.removed ?? 0;
    }

    /**
     * Staff record that the person consented to email in Manara. Lifts an
     * import's "not opted in" precaution only; the server refuses (422) any
     * other reason with a sentence for the admin.
     */
    async function recordEmailConsent(contactId: number, evidence: string): Promise<Contact> {
        const res: AxiosResponse = await ApiService.post(
            `/api/admin/masjids/${masjidId()}/contacts/${contactId}/email-consent`,
            emailConsentBody(evidence),
        );
        return res.data.data;
    }

    return {
        recordEmailConsent,
        contactsPaginated,
        tags,
        fetchTags,
        createTag,
        renameTag,
        deleteTag,
        tagContacts,
        untagContacts,
        fetchContacts,
        fetchContact,
        createContact,
        updateContact,
        deleteContact,
        restoreContact,
        fetchFamilyLogin,
        enableFamilyLogin,
        revokeFamilyLogin,
        sendFamilyPortalInvite
    }
})
