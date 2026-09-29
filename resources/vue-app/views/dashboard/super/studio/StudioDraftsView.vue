<template>
    <PageDataContainer title="Manara Studio" :button-props="newClientButton" @headerButtonClick="newClient">
        <ul class="nav nav-tabs mb-3" role="tablist" aria-label="Manara Studio lists">
            <li v-for="(option, index) in LIST_TABS" :key="option.key" class="nav-item" role="presentation">
                <button :id="`studio-list-tab-${option.key}`" ref="tabButtons" type="button" class="nav-link" role="tab"
                    :class="{ active: option.key === tab }" :aria-selected="option.key === tab"
                    :aria-controls="`studio-list-pane-${option.key}`" :tabindex="option.key === tab ? 0 : -1"
                    @click="choose(option.key)" @keydown="onTabKey($event, index)">
                    {{ option.label }}
                </button>
            </li>
        </ul>

        <div v-if="tab === 'drafts'" id="studio-list-pane-drafts" role="tabpanel" aria-labelledby="studio-list-tab-drafts">
            <div v-if="store.draftsLoading && !store.drafts.length" class="text-center py-5">
                <div class="spinner-border text-success" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
            </div>

            <div v-else-if="store.draftsError" class="alert alert-danger" role="alert">
                <i class="bi bi-exclamation-triangle me-2"></i>
                {{ store.draftsError }}
                <button type="button" class="btn btn-sm btn-outline-danger ms-3" @click="store.fetchDrafts()">Retry</button>
            </div>

            <div v-else-if="!store.drafts.length" class="text-center py-5 text-muted">
                <i class="bi bi-window-stack fs-1 d-block mb-3"></i>
                <p class="mb-0">No drafts yet. Start one with New client.</p>
            </div>

            <div v-else class="table-responsive bg-white">
                <table class="table align-middle m-0">
                    <thead>
                        <tr>
                            <th scope="col" class="th-border">Name</th>
                            <th scope="col" class="th-border">Type</th>
                            <th scope="col" class="th-border">Step</th>
                            <th scope="col" class="th-border">Last saved</th>
                            <th scope="col" class="th-border">Status</th>
                            <th scope="col" class="th-border">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in store.drafts" :key="row.id">
                            <td class="border-0 fw-semibold">
                                <span v-if="row.name">{{ row.name }}</span>
                                <span v-else class="text-muted fst-italic">Untitled draft</span>
                            </td>
                            <td class="border-0">{{ typeLabel(row.org_type) }}</td>
                            <td class="border-0">{{ stepTitle(row.current_step) }}</td>
                            <td class="border-0 text-nowrap">{{ formatSaved(row.updated_at) }}</td>
                            <td class="border-0">
                                <router-link v-if="row.status === 'provisioned' && row.provisioned_masjid_id"
                                    :to="`/dashboard/super/masjids/${row.provisioned_masjid_id}`" class="status-pill live">
                                    Live
                                </router-link>
                                <span v-else class="status-pill">Draft</span>
                            </td>
                            <td class="border-0">
                                <div class="d-flex flex-wrap gap-2">
                                    <router-link :to="{ name: 'studio.draft', params: { draft_id: row.id } }" class="btn btn-sm btn-success">
                                        {{ row.status === 'provisioned' ? 'Open' : 'Resume' }}
                                    </router-link>
                                    <button v-if="row.status === 'draft'" type="button" class="btn btn-sm btn-outline-danger"
                                        :disabled="discarding === row.id" @click="discard(row)">
                                        Discard
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-else id="studio-list-pane-organisations" role="tabpanel" aria-labelledby="studio-list-tab-organisations">
            <div v-if="orgsLoading && !organisations.length" class="text-center py-5">
                <div class="spinner-border text-success" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
            </div>

            <div v-else-if="orgsError" class="alert alert-danger" role="alert">
                <i class="bi bi-exclamation-triangle me-2"></i>
                {{ orgsError }}
                <button type="button" class="btn btn-sm btn-outline-danger ms-3" @click="fetchOrganisations()">Retry</button>
            </div>

            <div v-else-if="!organisations.length" class="text-center py-5 text-muted">
                <i class="bi bi-building fs-1 d-block mb-3"></i>
                <p class="mb-0">No organisations yet.</p>
            </div>

            <div v-else class="table-responsive bg-white">
                <table class="table align-middle m-0">
                    <thead>
                        <tr>
                            <th scope="col" class="th-border">Name</th>
                            <th scope="col" class="th-border">Type</th>
                            <th scope="col" class="th-border">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="org in organisations" :key="org.id">
                            <td class="border-0 fw-semibold">{{ org.name }}</td>
                            <td class="border-0">{{ org.vertical?.label ?? typeLabel(org.org_type ?? null) }}</td>
                            <td class="border-0">
                                <router-link :to="{ name: 'studio.organisation', params: { id: org.id } }" class="btn btn-sm btn-success">
                                    Open in Studio
                                </router-link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </PageDataContainer>
</template>

<script setup lang="ts">
/**
 * Manara Studio's first screen: every draft, newest first, and "New client"
 * (docs/manara-studio-w1.md S5). A draft becomes an organisation only at
 * Step 3, so this list is where abandoned work is found and discarded; a
 * provisioned draft stays as the record of what was created and links to it.
 * A draft is opened by its route's name (`studio.draft`), never a typed path,
 * so the router refuses a link to a route that does not exist.
 *
 * The Organisations tab (docs/manara-studio-w2.md S9) lists every organisation
 * through the SuperAdmin masjids index, each opened in Studio by its route's
 * name (`studio.organisation`). Its list is fetched the first time the tab
 * opens. The tab chosen is the URL's `?tab=`, so the live screen's back link
 * returns to it; the Drafts tab, the default, is the screen it always was.
 */
import PageDataContainer from '@/components/PageDataContainer.vue';
import { tabIndexForKey } from '@/core/helpers/tabKeys';
import { MSwal, QSwal } from '@/core/plugins/SweetAlerts2';
import { stepTitle } from '@/core/studio/steps';
import { ButtonProps } from '@/core/types/elements/Buttons';
import { StudioDraftRow } from '@/core/types/data/Studio';
import { OrgType } from '@/core/types/data/Vertical';
import router from '@/router/router';
import { useMasjidsStore } from '@/stores/super/masjidsStore';
import { useStudioDraftStore } from '@/stores/super/studioDraftStore';
import { computed, nextTick, onBeforeMount, ref, watch } from 'vue';
import { useRoute } from 'vue-router';

type ListTab = 'drafts' | 'organisations';

const LIST_TABS: { key: ListTab; label: string }[] = [
    { key: 'drafts', label: 'Drafts' },
    { key: 'organisations', label: 'Organisations' },
];

const store = useStudioDraftStore();
const masjidsStore = useMasjidsStore();
const route = useRoute();

const tab = computed<ListTab>(() => (route.query.tab === 'organisations' ? 'organisations' : 'drafts'));

const tabButtons = ref<HTMLButtonElement[]>([]);

function choose(next: ListTab) {
    if (next === tab.value) return;
    void router.replace({ name: 'studio.drafts', query: next === 'drafts' ? {} : { tab: next } });
}

/** The ARIA tabs keys (core/helpers/tabKeys.ts): choose the tab and move focus to it. */
async function onTabKey(event: KeyboardEvent, index: number) {
    const target = tabIndexForKey(event.key, index, LIST_TABS.length);
    if (target === null) return;

    event.preventDefault();
    const next = LIST_TABS[target].key;
    choose(next);
    await nextTick();
    tabButtons.value.find((button) => button.id === `studio-list-tab-${next}`)?.focus();
}

const orgsLoading = ref(false);
const orgsFetched = ref(false);

/** Every organisation, as the index answers them. Its store logs a failure and leaves the list unset. */
const organisations = computed(() => masjidsStore.masjids ?? []);
const orgsError = computed(() => (orgsFetched.value && !orgsLoading.value && !masjidsStore.masjids
    ? 'The organisations could not be loaded.'
    : null));

async function fetchOrganisations() {
    if (orgsLoading.value) return;
    orgsLoading.value = true;
    try {
        await masjidsStore.fetchMasjidsList();
    } finally {
        orgsLoading.value = false;
        orgsFetched.value = true;
    }
}

watch(tab, (current) => {
    if (current === 'organisations' && !orgsFetched.value) void fetchOrganisations();
}, { immediate: true });

const creating = ref(false);
const discarding = ref<number | null>(null);

const newClientButton = computed<ButtonProps>(() => ({
    title: creating.value ? 'Creating…' : 'New client',
    type: 'button',
    class: 'btn btn-success',
    disabled: creating.value,
}));

onBeforeMount(async () => {
    await Promise.all([store.fetchDrafts(), store.fetchOptions()]);
});

/** The vertical's own label from the server (config/verticals.php), never a copy of it. */
function typeLabel(orgType: OrgType | null): string {
    if (!orgType) return 'Not chosen';
    return store.options?.verticals.find((v) => v.org_type === orgType)?.label ?? orgType;
}

function formatSaved(value: string | null): string {
    if (!value) return '';
    const date = new Date(value);
    return isNaN(date.getTime())
        ? value
        : date.toLocaleString(undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
}

async function newClient() {
    if (creating.value) return;
    creating.value = true;
    const outcome = await store.createDraft();
    creating.value = false;

    if (!outcome.ok) {
        await MSwal.fire({ icon: 'error', title: 'Not created', text: outcome.message });
        return;
    }
    await router.push({ name: 'studio.draft', params: { draft_id: outcome.data.id } });
}

async function discard(row: StudioDraftRow) {
    const result = await QSwal.fire({
        icon: 'warning',
        title: 'Discard this draft?',
        text: `${row.name || 'Untitled draft'} and its logo are deleted for good. Nothing live is affected.`,
        confirmButtonText: 'Discard',
        cancelButtonText: 'Keep',
    });
    if (!result.isConfirmed) return;

    discarding.value = row.id;
    const outcome = await store.discardDraft(row.id);
    discarding.value = null;

    if (!outcome.ok) {
        await MSwal.fire({ icon: 'error', title: 'Not discarded', text: outcome.message });
    }
}
</script>

<style scoped>
.nav-tabs .nav-link {
    color: #6c757d;
}

.nav-tabs .nav-link.active {
    color: #198754;
    font-weight: 600;
}

.th-border {
    border: none;
    border-bottom: 1px solid var(--input-border);
}

.status-pill {
    display: inline-block;
    border: 1px solid var(--input-border, #ccc);
    border-radius: 2rem;
    padding: .15rem .65rem;
    font-size: .8rem;
    color: #555;
    text-decoration: none;
}

.status-pill.live {
    background: var(--cgreen, #01b151);
    border-color: var(--cgreen, #01b151);
    color: #fff;
}
</style>
