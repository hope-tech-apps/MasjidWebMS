import { defineStore } from "pinia"
import { ref } from "vue"
import { useMasjidStore } from "../masjidStore";
import ApiService from "@/core/services/ApiService";
import { AxiosResponse } from "axios";
import { PaginatedData } from "@/core/types/data/interfaces/PaginatedData";
import { Donation, DonationStatus } from "@/core/types/data/masjid-related/Donation";

/**
 * Donations ledger store — READ-ONLY over /api/admin/masjids/{masjid_id}/donations.
 *
 * Donations are created and advanced ONLY by Stripe webhooks, so there is no
 * create/update/delete here by design. The active masjid comes from masjidStore;
 * the backend `tenant` middleware + BelongsToMasjid trait scope every read to
 * this admin's own masjid.
 */
export const useDonationsStore = defineStore('donationsStore', () => {

    // State
    const donationsPaginated = ref<PaginatedData<Donation>>();

    // Stores
    const masjidStore = useMasjidStore();

    /**
     * Fetch a page of donations, optionally filtered by status and/or fund.
     */
    async function fetchDonations(
        page: number = 1,
        status: DonationStatus | '' = '',
        fundId: number | string | '' = '',
        search: string = ''
    ): Promise<void> {
        if (!masjidStore.masjid?.id) return;

        if (donationsPaginated.value) {
            donationsPaginated.value.data = [];
        }

        let url = `/api/admin/masjids/${masjidStore.masjid.id}/donations?page=${page}`;
        if (status) {
            url += `&status=${encodeURIComponent(status)}`;
        }
        if (fundId) {
            url += `&fund_id=${encodeURIComponent(fundId)}`;
        }
        if (search) {
            url += `&search=${encodeURIComponent(search)}`;
        }

        await ApiService.get(url)
            .then((res: AxiosResponse) => {
                if (res.data?.status === 'success' && res.data?.data) {
                    donationsPaginated.value = res.data.data;
                }
            })
            .catch((e: Error) => {
                console.error('Fetch donations error: ', e);
                throw e;
            });
    }

    /** Fetch a single donation (with its fund + receipt eager-loaded) by id. */
    async function fetchDonation(id: number | string): Promise<Donation | null> {
        if (masjidStore.masjid?.id) {
            const res: AxiosResponse = await ApiService.get(
                `/api/admin/masjids/${masjidStore.masjid.id}/donations/${id}`
            );
            if (res.data?.status === 'success' && res.data?.data) {
                return res.data.data;
            }
        }
        return null;
    }

    return {
        donationsPaginated,
        fetchDonations,
        fetchDonation
    }
})
