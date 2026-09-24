<template>
    <PageDataContainer title="Manara Studio" :button-props="newClientButton" @headerButtonClick="newClient">
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
                                <router-link :to="`/dashboard/super/studio/drafts/${row.id}`" class="btn btn-sm btn-success">
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
    </PageDataContainer>
</template>

<script setup lang="ts">
/**
 * Manara Studio's first screen: every draft, newest first, and "New client"
 * (docs/manara-studio-w1.md S5). A draft becomes an organisation only at
 * Step 3, so this list is where abandoned work is found and discarded; a
 * provisioned draft stays as the record of what was created and links to it.
 */
import PageDataContainer from '@/components/PageDataContainer.vue';
import { MSwal, QSwal } from '@/core/plugins/SweetAlerts2';
import { stepTitle } from '@/core/studio/steps';
import { ButtonProps } from '@/core/types/elements/Buttons';
import { StudioDraftRow } from '@/core/types/data/Studio';
import { OrgType } from '@/core/types/data/Vertical';
import router from '@/router/router';
import { useStudioDraftStore } from '@/stores/super/studioDraftStore';
import { computed, onBeforeMount, ref } from 'vue';

const store = useStudioDraftStore();

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
    await router.push(`/dashboard/super/studio/drafts/${outcome.data.id}`);
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
