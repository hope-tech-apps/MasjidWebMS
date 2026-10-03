import ApiService from "@/core/services/ApiService";
import { useMasjidStore } from "@/stores/masjidStore";
import { defineStore } from "pinia";
import { ref } from "vue";
import type { AxiosResponse } from "axios";
import type { TvDisplayBody, TvDisplayPayload } from "@/views/dashboard/tvDisplay";

/**
 * The lobby TV board's settings: GET and POST /api/admin/masjids/{id}/tv-display.
 *
 * The save is a POST with a plain object, which ApiService sends as application/json:
 * a form body cannot say null, and null is how a setting is handed back to "automatic".
 * Not put or patch, which change the Content-Type every later request starts from.
 */
export const useTvDisplayStore = defineStore("tvDisplayStore", () => {
    const masjidStore = useMasjidStore();
    const payload = ref<TvDisplayPayload | null>(null);
    // The organisation `payload` belongs to. A SuperAdmin can switch organisation while a
    // request is in flight; an answer for the one they left must never be shown as, or
    // saved onto, the one they are looking at.
    const loadedFor = ref<number | string | null>(null);

    function base(): `/api/admin/masjids/${string}/tv-display` {
        const id = masjidStore.masjid?.id;
        if (!id) throw new Error("No organisation is loaded yet.");
        return `/api/admin/masjids/${id}/tv-display`;
    }

    /** The server's `data`, kept as returned. An answer without its three parts is a failure, never a blank form. */
    function answered(res: AxiosResponse, failure: string): TvDisplayPayload {
        const data = res.data?.data;
        if (res.data?.status !== "success" || !data?.settings || !data?.effective || !data?.context) throw new Error(failure);
        return data;
    }

    async function load(): Promise<void> {
        const id = masjidStore.masjid?.id ?? null;
        payload.value = null;
        loadedFor.value = null;
        const res = await ApiService.get(base());
        // Answered after a switch of organisation: this is the other one's, dropped.
        if ((masjidStore.masjid?.id ?? null) !== id) return;
        payload.value = answered(res, "Could not load the TV display settings.");
        loadedFor.value = id;
    }

    async function save(body: TvDisplayBody): Promise<void> {
        const id = masjidStore.masjid?.id ?? null;
        // The form on screen was drawn from `loadedFor`'s settings. base() addresses the
        // CURRENT organisation, so saving after a switch would write one organisation's
        // choices onto another's board.
        if (loadedFor.value === null || loadedFor.value !== id) {
            throw new Error("The organisation changed. Reload this page, then save.");
        }
        const res = await ApiService.post(base(), body);
        if ((masjidStore.masjid?.id ?? null) !== id) return;
        payload.value = answered(res, "Could not save the TV display settings.");
    }

    return { payload, loadedFor, load, save };
});
