import { defineStore } from "pinia"
import { ref } from "vue"
import ApiService from "@/core/services/ApiService"
import { AxiosResponse } from "axios"
import { SmsSenderPanel, SmsSenderPayload } from "@/core/types/data/masjid-related/SmsSender"

/**
 * SuperAdmin store for one organisation's text-message sender identity (T-009,
 * T-041f) — `GET`/`PUT /api/admin/masjids/{masjid_id}/sms-sender`.
 *
 * ## Why this is an operator store and not a masjid one
 *
 * It records the OUTCOME of an A2P 10DLC carrier registration, not a
 * preference. Nothing here submits anything to a provider: brand and campaign
 * registration happens in the provider console and at the carriers, takes days,
 * and can be refused. Both routes sit behind `super`
 * (.claude/rules/broadcasts.md: "Registration state is recorded by a SuperAdmin
 * … never by a masjid admin"), so this store deliberately lives under
 * `stores/super/` and is imported by exactly one screen in the super shell.
 *
 * ## Why it never decides `can_send` itself
 *
 * `can_send` and `refusal_reason` come off the wire and are stored as they
 * arrive. The identical question is answered on the sending path by
 * MasjidSmsSender::canSend(), and a second implementation in TypeScript would
 * be a screen that says "ready to send" over a channel that then refuses — the
 * same argument FamilyLoginStatus.state records for the parent portal.
 *
 * ## Why the errors are rethrown
 *
 * Unlike the other super stores, `save` does not swallow its rejection: a 422
 * here carries the validator's sentence ("An approved sender needs a phone
 * number or a messaging service to send from…"), and that sentence is the whole
 * value of the refusal. The caller shows it.
 */
export const useSmsSenderStore = defineStore("smsSenderStore", () => {

    /** The panel as the server last described it. Null before the first read. */
    const panel = ref<SmsSenderPanel | null>(null)
    const isLoading = ref(false)
    const isSaving = ref(false)

    /**
     * Read this organisation's sender, plus whether the PLATFORM is configured
     * at all. Both halves matter: a perfectly approved sender still cannot send
     * on a deployment with no provider credentials, and being told that here is
     * better than discovering it from a failed delivery row.
     *
     * ## `panel` is cleared FIRST, and that line is the important one
     *
     * This store is a singleton and the super shell walks from one organisation
     * to the next through it. While `panel` held the last successful read, a
     * FAILED read for the next organisation — a 500, an expired session, a
     * dropped connection — left the previous tenant's sender on screen under the
     * new tenant's heading: their status badge, their "can send", their approved
     * date and their SENDING NUMBER, with the `v-else` "the sender could not be
     * loaded" branch unreachable because `panel` was truthy. That is one
     * organisation's phone number disclosed on another organisation's page, and
     * an operator who trusted the badges and pressed Save would then have PUT
     * the blank form fields over the real row.
     *
     * Clearing before the await means the only two states a caller can observe
     * are "this organisation's sender" and "nothing" — never "somebody else's".
     * The same reasoning as ContactsView clearing its consent refusal when a
     * different member is opened: a fact belongs to the record it was read for.
     *
     * The `status === "success"` guard below is deliberately NOT an else that
     * restores anything either: a 200 whose body is not a panel is a failed read
     * that happened to have a status code, and it must leave nothing behind.
     */
    async function fetchSender(masjidId: number | string): Promise<SmsSenderPanel | null> {
        panel.value = null
        isLoading.value = true
        try {
            const res: AxiosResponse = await ApiService.get(`/api/admin/masjids/${masjidId}/sms-sender`)
            if (res.data?.status === "success" && res.data?.data) {
                panel.value = res.data.data as SmsSenderPanel
            }
            return panel.value
        } finally {
            isLoading.value = false
        }
    }

    /**
     * Record the registration outcome.
     *
     * Sent as a plain object so ApiService's interceptor serialises it as JSON —
     * PHP does not populate `$_POST` for PUT, and a urlencoded body on this verb
     * is the kind of thing that arrives as an empty field set rather than as an
     * error. There are no booleans in this payload and there should never be:
     * Laravel's `boolean` rule rejects the `"true"`/`"false"` strings a form
     * encoding produces.
     *
     * The PUT response answers only about this tenant (no `provider_configured`,
     * no `provider`), so those two are carried over from the last read rather
     * than being blanked by a save.
     */
    async function saveSender(
        masjidId: number | string,
        payload: SmsSenderPayload
    ): Promise<SmsSenderPanel> {
        isSaving.value = true
        try {
            const res: AxiosResponse = await ApiService.put(
                `/api/admin/masjids/${masjidId}/sms-sender`,
                payload
            )

            if (res.data?.status !== "success" || !res.data?.data) {
                throw new Error("Could not save the sender.")
            }

            const saved = res.data.data as SmsSenderPanel

            panel.value = {
                ...saved,
                provider_configured: panel.value?.provider_configured,
                provider: panel.value?.provider,
            }

            return panel.value
        } finally {
            isSaving.value = false
        }
    }

    return { panel, isLoading, isSaving, fetchSender, saveSender }
})
