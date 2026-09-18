import { defineStore } from "pinia";
import { ref } from "vue";
import axios, { AxiosInstance } from "axios";

/**
 * The PUBLIC Jummah-lunch client — its own tokenless axios, exactly like the
 * family portal keeps its own instance. There is no session here: the tenant
 * travels in the `masjid-id` header (the /api/v1 public-write idiom the backend
 * expects), never a bearer token, so this must not touch the admin ApiService
 * whose token rides on axios's global defaults.
 */
function client(masjidId: string): AxiosInstance {
    return axios.create({
        baseURL: import.meta.env.VITE_APP_URL ?? "",
        headers: {
            Accept: "application/json",
            "X-Requested-With": "XMLHttpRequest",
            "masjid-id": String(masjidId),
        },
    });
}

/** Pull the friendliest message out of a failed response (message, or a 422 field bag). */
function messageFrom(e: any, fallback: string): string {
    const data = e?.response?.data;
    if (data?.message) {
        return String(data.message);
    }
    if (data?.data && typeof data.data === "object") {
        const first = (Object.values(data.data).flat() as any[])[0];
        if (first) {
            return String(first);
        }
    }
    return fallback;
}

export const usePublicLunchStore = defineStore("publicLunchStore", () => {
    const menu = ref<any | null>(null);
    const order = ref<any | null>(null);
    const loading = ref(false);
    const error = ref<string | null>(null);

    async function fetchMenu(masjidId: string): Promise<any | null> {
        loading.value = true;
        error.value = null;
        try {
            const res = await client(masjidId).get("/api/v1/lunch-menu");
            menu.value = res.data?.data?.menu ?? null;
            return menu.value;
        } catch (e: any) {
            error.value = messageFrom(e, "We couldn't load the menu right now.");
            return null;
        } finally {
            loading.value = false;
        }
    }

    async function placeOrder(
        masjidId: string,
        payload: Record<string, any>
    ): Promise<{ ok: boolean; data?: any; checkoutUrl?: string; message?: string }> {
        loading.value = true;
        error.value = null;
        try {
            const res = await client(masjidId).post("/api/v1/lunch-orders", payload);
            return {
                ok: true,
                data: res.data?.data?.order ?? null,
                checkoutUrl: res.data?.data?.checkout_url ?? undefined,
                message: res.data?.message,
            };
        } catch (e: any) {
            return { ok: false, message: messageFrom(e, "We couldn't place your order.") };
        } finally {
            loading.value = false;
        }
    }

    /**
     * Change what is on an order already placed, on the link the customer holds.
     *
     * `items` is the FULL set of lines after the edit, which is what the endpoint
     * takes: a line left out is removed. NO PRICE IS SENT — the server re-prices
     * every line from the menu, so the totals that come back are the only ones
     * this page may show.
     *
     * A refusal (closed, paid, cancelled, a plate over the kitchen's cap) comes
     * back as the server's own sentence and is handed straight to the page.
     */
    async function updateOrder(
        masjidId: string,
        uuid: string,
        items: { meal_menu_item_id: number; quantity: number }[]
    ): Promise<{ ok: boolean; order?: any; checkoutUrl?: string; message?: string }> {
        loading.value = true;
        error.value = null;
        try {
            const res = await client(masjidId).patch(`/api/v1/lunch-orders/${uuid}`, { items });
            const updated = res.data?.data?.order ?? null;
            // Only ever replaced with what the server sent back. A response
            // without an order leaves the page showing what it already had,
            // rather than blanking it or keeping the numbers typed into it.
            if (updated) {
                order.value = updated;
            }
            return {
                ok: true,
                order: updated,
                checkoutUrl: res.data?.data?.checkout_url ?? undefined,
                message: res.data?.message,
            };
        } catch (e: any) {
            return { ok: false, message: messageFrom(e, "We couldn't change your order.") };
        } finally {
            loading.value = false;
        }
    }

    async function fetchOrder(masjidId: string, uuid: string): Promise<any | null> {
        loading.value = true;
        error.value = null;
        try {
            const res = await client(masjidId).get(`/api/v1/lunch-orders/${uuid}`);
            order.value = res.data?.data?.order ?? null;
            return order.value;
        } catch (e: any) {
            error.value = messageFrom(e, "We couldn't find that order.");
            return null;
        } finally {
            loading.value = false;
        }
    }

    return { menu, order, loading, error, fetchMenu, placeOrder, fetchOrder, updateOrder };
});
