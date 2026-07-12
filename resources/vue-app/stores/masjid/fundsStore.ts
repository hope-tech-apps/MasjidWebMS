import { defineStore } from "pinia"
import { ref } from "vue"
import { useMasjidStore } from "../masjidStore";
import ApiService from "@/core/services/ApiService";
import { AxiosResponse } from "axios";
import { Fund, FundPayload } from "@/core/types/data/masjid-related/Fund";

/**
 * Donation-funds store — CRUD over /api/admin/masjids/{masjid_id}/funds.
 *
 * The active masjid comes from masjidStore; the backend `tenant` middleware +
 * BelongsToMasjid trait enforce that this admin only ever touches their own
 * masjid's funds (the controller never hand-filters by masjid_id).
 *
 * The funds index returns a plain array (no pagination), so state is a flat list.
 */
export const useFundsStore = defineStore('fundsStore', () => {

    // State
    const funds = ref<Fund[]>([]);

    // Stores
    const masjidStore = useMasjidStore();

    /** Fetch every fund for the active masjid, ordered by name (server-side). */
    async function fetchFunds(): Promise<void> {
        if (!masjidStore.masjid?.id) return;

        funds.value = [];

        await ApiService.get(`/api/admin/masjids/${masjidStore.masjid.id}/funds`)
            .then((res: AxiosResponse) => {
                if (res.data?.status === 'success' && Array.isArray(res.data?.data)) {
                    funds.value = res.data.data;
                }
            })
            .catch((e: Error) => {
                console.error('Fetch funds error: ', e);
                throw e;
            });
    }

    /** Fetch a single fund by id. */
    async function fetchFund(id: number | string): Promise<Fund | null> {
        if (masjidStore.masjid?.id) {
            const res: AxiosResponse = await ApiService.get(
                `/api/admin/masjids/${masjidStore.masjid.id}/funds/${id}`
            );
            if (res.data?.status === 'success' && res.data?.data) {
                return res.data.data;
            }
        }
        return null;
    }

    /** Create a fund. Returns the created row on success. */
    async function createFund(payload: FundPayload): Promise<Fund> {
        if (!masjidStore.masjid?.id) {
            throw new Error('Masjid not specified.');
        }

        // POST goes out as multipart/form-data (ApiService default). Booleans are
        // serialized to '1'/'0' so Laravel's `boolean` rule accepts them (the
        // proven create path used by SplashAnnouncementFormView).
        const body = new FormData();
        body.append('name', payload.name);
        body.append('type', payload.type);
        body.append('receiptable', payload.receiptable ? '1' : '0');
        body.append('is_active', payload.is_active ? '1' : '0');

        const res: AxiosResponse = await ApiService.post(
            `/api/admin/masjids/${masjidStore.masjid.id}/funds`,
            body
        );
        if (res.data?.status === 'success' && res.data?.data) {
            return res.data.data;
        }
        throw new Error('Failed to create fund.');
    }

    /** Update a fund. Returns the updated row on success. */
    async function updateFund(id: number | string, payload: FundPayload): Promise<Fund> {
        if (!masjidStore.masjid?.id) {
            throw new Error('Masjid not specified.');
        }

        // ApiService.put sends application/x-www-form-urlencoded; serialize the
        // body to URLSearchParams (matches the proven edit path in contactsStore),
        // with booleans as '1'/'0' for Laravel's `boolean` rule.
        const body = new URLSearchParams();
        body.append('name', payload.name);
        body.append('type', payload.type);
        body.append('receiptable', payload.receiptable ? '1' : '0');
        body.append('is_active', payload.is_active ? '1' : '0');

        const res: AxiosResponse = await ApiService.put(
            `/api/admin/masjids/${masjidStore.masjid.id}/funds/${id}`,
            body
        );
        if (res.data?.status === 'success' && res.data?.data) {
            return res.data.data;
        }
        throw new Error('Failed to update fund.');
    }

    /** Delete a fund. */
    async function deleteFund(id: number | string): Promise<boolean> {
        if (masjidStore.masjid?.id) {
            const res: AxiosResponse = await ApiService.delete(
                `/api/admin/masjids/${masjidStore.masjid.id}/funds/${id}`
            );
            if (res.data?.status === 'success') {
                return true;
            }
        }
        return false;
    }

    return {
        funds,
        fetchFunds,
        fetchFund,
        createFund,
        updateFund,
        deleteFund
    }
})
