import { defineStore } from "pinia"
import { ref } from "vue"
import ApiService from "@/core/services/ApiService"
import { AxiosResponse } from "axios"

/** One platform's emergency app-version config. */
export interface AppVersionSetting {
    id: number
    platform: string
    minimum_version: string
    minimum_build: number
    force_update: boolean
    update_message: string | null
    latest_version: string | null
    store_url: string | null
    maintenance_mode: boolean
    maintenance_message: string | null
    /**
     * Which shell the app draws. `null` means the app's own compiled default.
     * The server also accepts the clients' own spellings (`side_menu`,
     * `tabs_drawer`); this screen offers the canonical pair.
     */
    navigation: string | null
}

/**
 * What the Navigation select offers.
 *
 * `null` is a real choice, not an empty state — it hands the decision back to
 * the app, and the server then omits the key entirely rather than sending a
 * null, which is what keeps an untouched organisation's app-config body
 * identical to what every installed build already receives.
 */
export const NAVIGATION_OPTIONS: { value: string | null; label: string; hint: string }[] = [
    { value: null, label: 'App default', hint: 'The app decides — the new menu on R1 builds.' },
    { value: 'menu', label: 'New menu', hint: 'Tab bar plus the side menu.' },
    { value: 'legacy', label: 'Previous layout', hint: 'The layout the installed build shipped with.' },
]

/**
 * Super-admin store for the emergency app-version gate. This is the lever:
 * flip force_update + bump minimum_build, save, and every stale install is
 * walled off on its next launch.
 */
export const useAppConfigStore = defineStore("appConfigStore", () => {
    const settings = ref<AppVersionSetting[]>([])
    const isLoading = ref(false)

    async function fetchAll(masjidId: number) {
        isLoading.value = true
        await ApiService.get(`/api/admin/masjids/${masjidId}/app-config`)
            .then((res: AxiosResponse) => {
                if (res.data?.status === "success") settings.value = res.data.data
            })
            .catch((e: Error) => console.log("Fetch app-config error:", e))
            .finally(() => { isLoading.value = false })
    }

    async function save(masjidId: number, platform: string, payload: Partial<AppVersionSetting>) {
        return ApiService.post(`/api/admin/masjids/${masjidId}/app-config/${platform}`, payload)
    }

    return { settings, isLoading, fetchAll, save }
})
