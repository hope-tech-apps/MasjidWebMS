import { defineStore } from "pinia"
import { ref } from "vue"
import { useMasjidStore } from "../masjidStore";
import ApiService from "@/core/services/ApiService";
import { AxiosResponse } from "axios";
import { PaginatedData } from "@/core/types/data/interfaces/PaginatedData";
import { ContactRequest } from "@/core/types/data/masjid-related/ContactRequest";

export const useContactRequestsStore = defineStore('contactRequestsStore', () => {

    // State
    const contactRequestsPaginated = ref<PaginatedData<ContactRequest>>();

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

            await ApiService.get(url)
                .then((res: AxiosResponse) => {
                    if (res.data?.status === 'success' && res.data?.data) {
                        contactRequestsPaginated.value = res.data.data;
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
                    `/api/admin/masjids/${masjidStore.masjid.id}/contact-requests/${id}`
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
     * Send an email reply to a contact request's contacter.
     */
    async function replyToContactRequest(id: number, reply: string): Promise<string> {
        if (masjidStore.masjid?.id) {
            const res: AxiosResponse = await ApiService.post(
                `/api/admin/masjids/${masjidStore.masjid.id}/contact-requests/${id}/reply`,
                { reply }
            );
            if (res.data?.status === 'success') {
                return res.data?.message ?? 'Reply sent successfully.';
            }
            throw new Error(res.data?.message ?? 'Failed to send reply.');
        }
        throw new Error('Masjid not specified.');
    }

    /**
     * Delete a contact request
     */
    async function deleteContactRequest(id: number): Promise<boolean> {
        if (masjidStore.masjid?.id) {
            try {
                const res: AxiosResponse = await ApiService.delete(
                    `/api/admin/masjids/${masjidStore.masjid.id}/contact-requests/${id}`
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
        fetchContactRequests,
        fetchContactRequest,
        replyToContactRequest,
        deleteContactRequest
    }
})

