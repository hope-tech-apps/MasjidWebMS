import { defineStore } from "pinia"
import { ref } from "vue"
import { useMasjidStore } from "../masjidStore";
import ApiService from "@/core/services/ApiService";
import { AxiosResponse } from "axios";
import { PaginatedData } from "@/core/types/data/interfaces/PaginatedData";
import { Contact, ContactPayload } from "@/core/types/data/masjid-related/Contact";

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

    // Stores
    const masjidStore = useMasjidStore();

    /**
     * Fetch a page of contacts, optionally filtered by a free-text search over
     * first/last name, email and phone.
     */
    async function fetchContacts(page: number = 1, search: string = ''): Promise<void> {
        if (masjidStore.masjid?.id) {
            if (contactsPaginated.value) {
                contactsPaginated.value.data = [];
            }

            let url = `/api/admin/masjids/${masjidStore.masjid.id}/contacts?page=${page}`;
            if (search) {
                url += `&search=${encodeURIComponent(search)}`;
            }

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

    return {
        contactsPaginated,
        fetchContacts,
        fetchContact,
        createContact,
        updateContact,
        deleteContact
    }
})
