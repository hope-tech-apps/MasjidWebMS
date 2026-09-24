import { defineStore } from "pinia"
import { ref } from "vue"
import ApiService from "@/core/services/ApiService"
import { AxiosResponse } from "axios"
import {
    MasjidDomain,
    MasjidDomainCheck,
    MasjidDomainRequest,
    MasjidDomainsPanel,
} from "@/core/types/data/MasjidDomain"

/**
 * SuperAdmin store for one organisation's web addresses (Manara Studio W1, S7):
 * `/api/admin/masjids/{masjid_id}/domains` and Studio's domain check.
 *
 * ## It decides nothing
 *
 * Whether a host is live (`live_url`), what an operator must do by hand
 * (`manual_steps`) and whether a row may be removed (`deletable`) all come off
 * the wire and are stored as they arrive. Only the server's probe can say a
 * host is serving; a second opinion computed here would be a screen that says
 * "live" over a host that is not.
 *
 * ## The body is form-encoded, booleans as "1"/"0"
 *
 * The same transport the rest of the SPA uses (ApiService's global default),
 * built by hand in `toForm()` so every field is sent: a field missing from a
 * hand-built serialiser is silently dropped (.claude/rules/shipping.md). No
 * field today is a boolean; if one is added it goes as "1"/"0", because
 * Laravel's `boolean` rule refuses "true"/"false".
 *
 * ## `panel` is cleared before a read
 *
 * The store is a singleton and the super shell moves between organisations, so
 * a failed read must leave nothing of the previous organisation's hosts on
 * screen (the reasoning smsSenderStore records at length).
 *
 * Rejections are rethrown: a 422 carries the validator's sentence ("… is
 * already recorded for organisation #13."), and a 409 carries the removal
 * steps. The caller shows them.
 */
export const useMasjidDomainsStore = defineStore("masjidDomainsStore", () => {

    const panel = ref<MasjidDomainsPanel | null>(null)
    /** The organisation `panel` was read for, so a write for another one never lands in it. */
    const panelMasjidId = ref<string | null>(null)
    const isLoading = ref(false)
    /** The id of the row a refresh or delete is running for; "new" for a create. */
    const busy = ref<number | "new" | null>(null)

    function toForm(body: Record<string, string | number | boolean | null | undefined>): URLSearchParams {
        const form = new URLSearchParams()
        for (const [key, value] of Object.entries(body)) {
            if (value === undefined) continue
            if (typeof value === "boolean") form.append(key, value ? "1" : "0")
            else if (value === null) form.append(key, "")
            else form.append(key, String(value))
        }
        return form
    }

    function replaceRow(masjidId: number | string, domain: MasjidDomain): void {
        if (!panel.value || panelMasjidId.value !== String(masjidId)) return
        const rows = panel.value.domains
        const at = rows.findIndex(row => row.id === domain.id)
        if (at === -1) rows.push(domain)
        else rows.splice(at, 1, domain)
    }

    async function list(masjidId: number | string): Promise<MasjidDomainsPanel | null> {
        panel.value = null
        panelMasjidId.value = String(masjidId)
        isLoading.value = true
        try {
            const res: AxiosResponse = await ApiService.get(`/api/admin/masjids/${masjidId}/domains`)
            if (res.data?.status === "success" && res.data?.data) {
                panel.value = res.data.data as MasjidDomainsPanel
            }
            return panel.value
        } finally {
            isLoading.value = false
        }
    }

    async function create(masjidId: number | string, body: MasjidDomainRequest): Promise<MasjidDomain> {
        busy.value = "new"
        try {
            const res: AxiosResponse = await ApiService.post(`/api/admin/masjids/${masjidId}/domains`, toForm(body))
            const domain = res.data?.data?.domain as MasjidDomain | undefined
            if (res.data?.status !== "success" || !domain) {
                throw new Error("The web address could not be added.")
            }
            replaceRow(masjidId, domain)
            return domain
        } finally {
            busy.value = null
        }
    }

    /** "Check now": the server advances the row, which ends in its probe. */
    async function refresh(masjidId: number | string, domainId: number): Promise<MasjidDomain> {
        busy.value = domainId
        try {
            const res: AxiosResponse = await ApiService.post(
                `/api/admin/masjids/${masjidId}/domains/${domainId}/refresh`,
                toForm({})
            )
            const domain = res.data?.data?.domain as MasjidDomain | undefined
            if (res.data?.status !== "success" || !domain) {
                throw new Error("The web address could not be checked.")
            }
            replaceRow(masjidId, domain)
            return domain
        } finally {
            busy.value = null
        }
    }

    async function remove(masjidId: number | string, domainId: number): Promise<void> {
        busy.value = domainId
        try {
            await ApiService.delete(`/api/admin/masjids/${masjidId}/domains/${domainId}`)
            if (panel.value && panelMasjidId.value === String(masjidId)) {
                panel.value.domains = panel.value.domains.filter(row => row.id !== domainId)
            }
        } finally {
            busy.value = null
        }
    }

    async function check(body: MasjidDomainRequest): Promise<MasjidDomainCheck> {
        const res: AxiosResponse = await ApiService.post("/api/admin/studio/domains/check", toForm(body))
        if (res.data?.status !== "success" || !res.data?.data) {
            throw new Error("The web address could not be checked.")
        }
        return res.data.data as MasjidDomainCheck
    }

    return { panel, isLoading, busy, list, create, refresh, remove, check }
})
