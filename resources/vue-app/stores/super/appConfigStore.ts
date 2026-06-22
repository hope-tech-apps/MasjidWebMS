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
}

/**
 * Super-admin store for the emergency app-version gate. This is the lever:
 * flip force_update + bump minimum_build, save, and every stale install is
 * walled off on its next launch.
 */
export const useAppConfigStore = defineStore("appConfigStore", () => {
    const settings = ref<AppVersionSetting[]>([])
    const isLoading = ref(false)

    async function fetchAll() {
        isLoading.value = true
        await ApiService.get("/api/admin/app-config")
            .then((res: AxiosResponse) => {
                if (res.data?.status === "success") settings.value = res.data.data
            })
            .catch((e: Error) => console.log("Fetch app-config error:", e))
            .finally(() => { isLoading.value = false })
    }

    async function save(platform: string, payload: Partial<AppVersionSetting>) {
        return ApiService.post(`/api/admin/app-config/${platform}`, payload)
    }

    return { settings, isLoading, fetchAll, save }
})
