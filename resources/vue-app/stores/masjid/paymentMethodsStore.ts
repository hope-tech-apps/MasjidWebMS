import ApiService from "@/core/services/ApiService";
import { useMasjidStore } from "@/stores/masjidStore";
import { defineStore } from "pinia";
import { ref } from "vue";
import type { CatalogueMethod, SavedMethod } from "@/views/dashboard/paymentMethods";

export interface PaymentMethodsPayload {
    methods: SavedMethod[];
    catalogue: CatalogueMethod[];
    /** Whether card can be offered today (Stripe account taking charges). */
    card_ready: boolean;
}

/**
 * Accepted payment methods — see PaymentMethodsController.
 *
 * The save is sent as JSON (a plain object, which ApiService's interceptor sends
 * as application/json): form encoding cannot express an empty list, and the
 * server refuses a body without `methods` so a lost field can never clear the set.
 */
export const usePaymentMethodsStore = defineStore("paymentMethodsStore", () => {
    const masjidStore = useMasjidStore();
    const payload = ref<PaymentMethodsPayload | null>(null);

    function base(): `/api/admin/masjids/${string}/payment-methods` {
        const id = masjidStore.masjid?.id;
        if (!id) throw new Error("No organisation is loaded yet.");
        return `/api/admin/masjids/${id}/payment-methods`;
    }

    async function fetchMethods(): Promise<void> {
        payload.value = null;
        const res = await ApiService.get(base());
        payload.value = res.data?.data ?? null;
    }

    async function saveMethods(body: { methods: { method: string; label: string | null; instructions: string | null }[] }): Promise<void> {
        const res = await ApiService.put(base(), body);
        if (res.data?.status !== "success") throw new Error("Could not save the payment methods.");
        payload.value = res.data.data;
    }

    return { payload, fetchMethods, saveMethods };
});
