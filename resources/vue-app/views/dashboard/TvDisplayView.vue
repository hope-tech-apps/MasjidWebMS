<template>
    <div>
        <PageDataContainer title="TV Display" :hideButton="true">
            <div class="container w-100">
                <p class="text-muted">
                    Settings for the TV screen in your lobby. The screen picks up a change within about four minutes.
                </p>

                <div v-if="loadState === 'loading'" class="text-center py-5">
                    <div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading…</span></div>
                </div>

                <!-- Refused (403) is an ordinary answer, not a fault: the page is a switch a SuperAdmin gives
                     an organisation, and a bookmark or an open tab can outlive it. Said calmly, as the shop
                     pages say it, and with no Retry: asking again cannot change the answer. -->
                <div v-else-if="loadState === 'failed' || !payload" class="alert" :class="refused ? 'alert-warning' : 'alert-danger'" role="alert">
                    {{ loadError || 'Could not load the TV display settings.' }}
                    <button v-if="!refused" class="btn btn-sm btn-outline-danger ms-3" @click="load">Retry</button>
                </div>

                <template v-else>
                    <form @submit.prevent="save">
                        <!-- Nothing can be edited while a save is in flight: the form is redrawn from the
                             server's answer, and an edit made meanwhile would be thrown away unsaid. -->
                        <fieldset :disabled="saving" class="border-0 p-0 m-0">
                        <div class="card mb-3">
                            <div class="card-body py-3">
                                <h2 class="h5">What the screen shows</h2>

                                <div class="mt-3">
                                    <div class="form-check form-switch">
                                        <input id="tv-slides" v-model="form.slides" class="form-check-input" type="checkbox" role="switch" aria-describedby="tv-slides-hint" />
                                        <label class="form-check-label fw-semibold" for="tv-slides">Announcement slides</label>
                                    </div>
                                    <small id="tv-slides-hint" class="text-muted d-block">
                                        The slides are your current announcements. Turn this off to pause them.
                                        <template v-if="!form.slides">{{ pausedHint }}</template>
                                    </small>
                                    <router-link v-if="announcementsReachable" class="small" :to="{ name: 'masjid.announcements' }">Open the Announcements page</router-link>
                                </div>

                                <div v-if="payload.context.is_masjid" class="mt-3">
                                    <div class="form-check form-switch">
                                        <input id="tv-prayer" v-model="form.prayerPanel" class="form-check-input" type="checkbox" role="switch" aria-describedby="tv-prayer-hint" />
                                        <label class="form-check-label fw-semibold" for="tv-prayer">Prayer times</label>
                                    </div>
                                    <small id="tv-prayer-hint" class="text-muted d-block">Prayer and iqamah times beside the slides.</small>
                                </div>

                                <div class="mt-3">
                                    <div class="form-check form-switch">
                                        <input id="tv-qr" class="form-check-input" type="checkbox" role="switch" aria-describedby="tv-qr-hint"
                                            :checked="form.qr && payload.context.has_donation_link" :disabled="!payload.context.has_donation_link" @change="setQr" />
                                        <label class="form-check-label fw-semibold" for="tv-qr">Donation QR code</label>
                                    </div>
                                    <small id="tv-qr-hint" class="text-muted d-block">{{ qrHint }}</small>
                                    <router-link v-if="!payload.context.has_donation_link && donationReachable" class="small" :to="{ name: 'masjid.donation' }">Open the Donation page</router-link>
                                </div>

                                <p v-if="emptyRightSide" id="tv-right-side-note" class="small text-warning-emphasis mt-3 mb-0" role="note">
                                    With no prayer times and no donation code, the right side of the screen is an empty panel.
                                </p>
                            </div>
                        </div>

                        <div class="card mb-3">
                            <div class="card-body py-3">
                                <h2 class="h5">Wording</h2>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label" for="tv-title">Title at the top</label>
                                        <input id="tv-title" v-model="form.title" class="form-control" type="text" dir="auto" autocomplete="off"
                                            :placeholder="payload.context.organisation_name"
                                            :class="{ 'is-invalid': problems.title }" :aria-invalid="problems.title ? 'true' : undefined"
                                            aria-describedby="tv-title-hint tv-title-error" />
                                        <div id="tv-title-hint" class="form-text">Leave blank to show your organisation's name.</div>
                                        <div v-if="problems.title" id="tv-title-error" class="invalid-feedback d-block" role="alert">{{ problems.title }}</div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label" for="tv-caption">Words under the QR code</label>
                                        <input id="tv-caption" v-model="form.caption" class="form-control" type="text" dir="auto" autocomplete="off"
                                            :placeholder="payload.context.defaults.donate_caption"
                                            :class="{ 'is-invalid': problems.caption }" :aria-invalid="problems.caption ? 'true' : undefined"
                                            aria-describedby="tv-caption-hint tv-caption-error" />
                                        <div id="tv-caption-hint" class="form-text">Leave blank for “{{ payload.context.defaults.donate_caption }}”.</div>
                                        <div v-if="problems.caption" id="tv-caption-error" class="invalid-feedback d-block" role="alert">{{ problems.caption }}</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card mb-3">
                            <div class="card-body py-3">
                                <h2 class="h5">Timing</h2>
                                <div class="row g-3">
                                    <div class="col-sm-6 col-md-4">
                                        <label class="form-label" for="tv-seconds">Seconds per slide</label>
                                        <!-- A list, not a number box: the board keeps only some speeds evenly (tvDisplay.ts,
                                             EVEN_SLIDE_SECONDS). Bound by hand so the blank choice stays "automatic". -->
                                        <select id="tv-seconds" class="form-select" :value="form.seconds"
                                            :class="{ 'is-invalid': problems.seconds }" :aria-invalid="problems.seconds ? 'true' : undefined"
                                            aria-describedby="tv-seconds-hint tv-seconds-error" @change="chooseSeconds">
                                            <option v-for="choice in secondsOptions" :key="choice.value" :value="choice.value">{{ choice.label }}</option>
                                        </select>
                                        <div id="tv-seconds-hint" class="form-text">
                                            How long each slide stays up. These are the speeds the screen keeps evenly.
                                        </div>
                                        <div v-if="problems.seconds" id="tv-seconds-error" class="invalid-feedback d-block" role="alert">{{ problems.seconds }}</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        </fieldset>

                        <div v-if="saveError" class="alert alert-danger py-2 mt-2" role="alert">Not saved. {{ saveError }}</div>

                        <div class="d-flex justify-content-end mt-3">
                            <button type="submit" class="btn btn-success" :disabled="!canSave">{{ saving ? 'Saving…' : 'Save' }}</button>
                        </div>
                    </form>

                    <div class="card mt-3">
                        <div class="card-body py-3">
                            <h2 class="h5">On the screen now</h2>
                            <p class="small text-muted mb-2">As last saved. A change above shows here once you save it.</p>
                            <ul class="mb-0 ps-3">
                                <li v-for="line in summary" :key="line" class="text-break">{{ line }}</li>
                            </ul>
                        </div>
                    </div>
                </template>
            </div>
        </PageDataContainer>
    </div>
</template>

<script setup lang="ts">
import { computed, ref, watch } from "vue";
import Swal from "sweetalert2";
import PageDataContainer from "@/components/PageDataContainer.vue";
import { menuItemState } from "@/core/access/orgAccess";
import { MASJID_DASHBOARD_ASIDE_MENU } from "@/core/constants/dashboardAsideMenuItems";
import { apiErrorText, isForbidden } from "@/core/services/ApiErrors";
import { useAuthStore } from "@/stores/authStore";
import { useMasjidStore } from "@/stores/masjidStore";
import { useTvDisplayStore } from "@/stores/masjid/tvDisplayStore";
import { changedBody, formFrom, formProblems, rightSideEmpty, sameForm, screenSummary, secondsChoices } from "@/views/dashboard/tvDisplay";
import type { TvDisplayForm, TvDisplayProblems } from "@/views/dashboard/tvDisplay";

const authStore = useAuthStore();
const masjidStore = useMasjidStore();
const store = useTvDisplayStore();

// 'loading' until the GET answers, 'ready' once it has, 'failed' if it could not be read. Nothing is
// saved unless it is 'ready', so automatic values are never saved over settings this screen never saw.
const loadState = ref<'loading' | 'ready' | 'failed'>('loading');
const loadError = ref("");
// Whether the failed load was a refusal (the organisation does not hold the page), not a fault.
const refused = ref(false);
// What the server says for a refusal it does not explain, once debugging is off (bootstrap/app.php).
const UNEXPLAINED_REFUSAL = "Request failed.";
const saving = ref(false);
const saveError = ref("");
const form = ref<TvDisplayForm>({ slides: true, prayerPanel: true, qr: true, title: "", caption: "", seconds: "" });
// The form as the server last gave it: Save has nothing to send while the two match.
const loaded = ref<TvDisplayForm>({ ...form.value });

const payload = computed(() => store.payload);
const summary = computed(() => (payload.value ? screenSummary(payload.value.effective, payload.value.context) : []));

const problems = computed<TvDisplayProblems>(() => {
    if (!payload.value) return {};
    return formProblems(form.value, payload.value.context.limits);
});
const canSave = computed(() => loadState.value === 'ready' && !!payload.value && !saving.value
    && Object.keys(problems.value).length === 0 && !sameForm(form.value, loaded.value));

// What the form would put on the right of the board if saved as it stands: said before the save,
// because the empty panel is easier to avoid than to notice in the lobby.
const emptyRightSide = computed(() => !!payload.value && rightSideEmpty(
    form.value.slides,
    payload.value.context.is_masjid && form.value.prayerPanel,
    payload.value.context.has_donation_link && form.value.qr,
));

// A paused board shows the prayer panel alone, or only its title where there is no panel.
const pausedHint = computed(() => (payload.value?.context.is_masjid && form.value.prayerPanel
    ? "The screen then shows prayer times only."
    : "The screen then shows only the title."));

// A link is offered only where the sidebar offers the page (core/access/orgAccess.ts): Announcements can
// be switched off for an organisation, and a school has no Donation screen to add a link on.
function reachable(to: string): boolean {
    const item = MASJID_DASHBOARD_ASIDE_MENU.find((entry) => entry.to === to);
    return !!item && menuItemState(item, authStore.user?.type, masjidStore.masjid, masjidStore.orgType) !== 'hidden';
}
const announcementsReachable = computed(() => reachable('/masjid/announcements'));
const donationReachable = computed(() => reachable('/masjid/donation'));

const qrHint = computed(() => {
    if (payload.value?.context.has_donation_link) return "A code people scan to open your donation link.";
    return donationReachable.value
        ? "Add a donation link first; the code appears once one is set."
        : "Your organisation has no donation link, so there is no code to show.";
});

// Without a donation link the switch is drawn off and takes no change, and form.qr stays as loaded:
// a save still sends null, so the code appears by itself once a link is set.
function setQr(event: Event) {
    if (!payload.value?.context.has_donation_link) return;
    form.value.qr = (event.target as HTMLInputElement).checked;
}

// The speeds on offer, always including the one that was loaded even when it is not an even one.
const secondsOptions = computed(() => (payload.value ? secondsChoices(loaded.value.seconds, payload.value.context) : []));

function chooseSeconds(event: Event) {
    form.value.seconds = (event.target as HTMLSelectElement).value;
}

function seed() {
    if (!store.payload) return;
    form.value = formFrom(store.payload.settings, store.payload.context.defaults);
    loaded.value = formFrom(store.payload.settings, store.payload.context.defaults);
}

// Each load takes a ticket, and only the newest one may change the page. An administrator who
// switches organisation starts a second load while the first is in flight; the first one's late
// answer (or late failure) is about the organisation they left.
let loadTicket = 0;

// What to say when the server gave no answer at all (offline, a dropped connection): the page's own
// sentence. The shared helper would say axios's "Network Error".
function said(e: unknown, fallback: string): string {
    // Only an answer in the application's own JSON has words worth repeating. A proxy's page (a 403
    // or a 502 in HTML) would otherwise be read out as axios's "Request failed with status code 403".
    const body = (e as any)?.response?.data;
    if (body && typeof body === 'object') return apiErrorText(e, fallback);
    // The store's own refusals (the organisation changed, an answer without its parts) say what happened.
    if (e instanceof Error && !(e as any).isAxiosError && e.message) return e.message;
    return fallback;
}

async function load() {
    // No organisation yet (the page opened before it loaded, or an administrator is switching to
    // another): that is waiting, not a failure. The watcher calls again once there is one.
    if (!masjidStore.masjid?.id) {
        ++loadTicket;
        loadState.value = 'loading';
        return;
    }
    const ticket = ++loadTicket;
    loadState.value = 'loading';
    loadError.value = "";
    refused.value = false;
    saveError.value = "";
    try {
        await store.load();
        if (ticket !== loadTicket) return;
        seed();
        loadState.value = 'ready';
    } catch (e) {
        if (ticket !== loadTicket) return;
        // A refusal is the application's own 403, and it comes with a sentence. A 403 with none (a
        // proxy in front of the server answering for it) is a fault like any other and keeps its Retry.
        const sentence = (e as any)?.response?.data?.message;
        refused.value = isForbidden(e) && typeof sentence === 'string' && sentence !== '';
        // The server explains a switch that is off; any other refusal reaches production as its
        // catch-all words, which tell an administrator nothing, so the page says its own.
        loadError.value = refused.value && sentence === UNEXPLAINED_REFUSAL
            ? "You do not have access to the TV display for this organisation."
            : said(e, "Could not load the TV display settings. Check your connection and try again.");
        loadState.value = 'failed';
    }
}

async function save() {
    if (!canSave.value) return;
    saving.value = true;
    saveError.value = "";
    try {
        await store.save(changedBody(form.value, loaded.value));
        // Redrawn from what the server stored, never from what was sent.
        seed();
        Swal.fire({ toast: true, position: "top-end", icon: "success", title: "TV display settings saved", showConfirmButton: false, timer: 2500 });
    } catch (e) {
        // Said on the page, and the form keeps what was typed.
        saveError.value = said(e, "The server could not be reached. Check your connection and try again.");
    } finally {
        saving.value = false;
    }
}

// Loads once the organisation is known, and again if the admin switches to another.
watch(() => masjidStore.masjid?.id, () => { void load(); }, { immediate: true });
</script>
