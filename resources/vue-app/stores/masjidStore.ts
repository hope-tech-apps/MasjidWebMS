import ApiService from "@/core/services/ApiService"
import { SystemRoute } from "@/core/types/config/SystemRoutes"
import { Masjid } from "@/core/types/data/Masjid"
import {
    DEFAULT_ORG_TYPE,
    MASJID_TERMINOLOGY,
    OrgType,
    Terminology,
    TerminologyKey,
    Vertical
} from "@/core/types/data/Vertical"
import { AxiosResponse } from "axios"
import { defineStore } from "pinia"
import { computed, ref } from "vue"
import { useAuthStore } from "./authStore"
import { LOCAL_STORAGE_KEYS } from "@/core/constants/appConfigConstants"

export const useMasjidStore = defineStore('masjidStore', () => {

    // Constants
    const masjid = ref<Masjid | null>()

    // Stores
    const authStore = useAuthStore();

    // ------------------------------------------------------------------
    // Vertical vocabulary (PLAN T-003)
    //
    // The admin payload carries a `vertical` block; everything below degrades
    // to the masjid pack when it does not, so a payload that predates it can
    // never blank a label for the tenants that already exist.
    // ------------------------------------------------------------------

    const vertical = computed<Vertical | null>(() => masjid.value?.vertical ?? null)

    /** The tenant's vertical, falling back to masjid — mirrors PHP `Masjid::orgType()`. */
    const orgType = computed<OrgType>(() => vertical.value?.org_type ?? DEFAULT_ORG_TYPE)

    /** What this tenant calls itself: "Masjid", "School", "Community Organization". */
    const organizationLabel = computed<string>(
        () => vertical.value?.label || MASJID_TERMINOLOGY.organization
    )

    /**
     * The active terminology pack. A missing `vertical` block falls back to the
     * masjid pack wholesale; a pack that is merely missing one key is left
     * alone, so `term()` can degrade that key on its own.
     */
    const terminology = computed<Terminology>(
        () => vertical.value?.terminology ?? MASJID_TERMINOLOGY
    )

    /**
     * Admin-facing label for a domain concept in this tenant's language —
     * "Congregants" for a masjid, "Families" for a school.
     *
     * Mirrors PHP `Masjid::term()`: a key the pack does not carry degrades to
     * the humanized key, so a label is readable rather than blank.
     */
    function term(key: TerminologyKey): string {
        return terminology.value[key] || humanizeTermKey(key)
    }

    function humanizeTermKey(key: string): string {
        const words = key.replace(/_/g, ' ')

        return words.charAt(0).toUpperCase() + words.slice(1)
    }

    async function fetchMasjid(id: number | string | null = null): Promise<SystemRoute | void> {

        let idToFetch = id;

        if ((!idToFetch) && authStore.isAuthenticated) {
            if(authStore.dashboardMasjidId) {
                idToFetch = authStore.dashboardMasjidId;
            } else {
                idToFetch = localStorage.getItem(LOCAL_STORAGE_KEYS.dashboard_masjid_id);
            }
        }

        if (idToFetch) {
            await ApiService.get(`/api/admin/masjids/${idToFetch}/`)
                .then((res: AxiosResponse) => {
                    if (res.data?.status === 'success' && res.data?.data) {
                        masjid.value = res.data.data;
                    }
                })
                .catch((e: Error) => {
                    console.log(e)
                });
        }
    }

    return { masjid, vertical, orgType, organizationLabel, terminology, term, fetchMasjid }
})