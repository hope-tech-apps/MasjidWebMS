<template>
    <StudioPanel :title="title">
        <p v-if="!rows.length && emptyText" class="studio-hint">{{ emptyText }}</p>

        <dl v-if="rows.length" class="live-facts m-0">
            <template v-for="(row, index) in rows" :key="index">
                <dt>{{ row.term }}</dt>
                <dd>{{ row.detail }}</dd>
            </template>
        </dl>

        <div>
            <button type="button" class="btn btn-sm btn-outline-success" :disabled="opening" @click="openEditor">
                <i class="bi bi-pencil-square me-1" aria-hidden="true"></i>Edit in {{ screen }}
            </button>
        </div>
    </StudioPanel>
</template>

<script setup lang="ts">
/**
 * One read-only section of a live organisation in Studio (docs/manara-studio-w2.md
 * S9): its data as a short definition list, and "Edit in {screen}", which opens
 * the admin screen that already writes it (the snapshot's `edit_in`). Studio
 * never becomes a second writer for these (landmine 3).
 *
 * The `/masjid/…` screens act on the dashboard's current organisation, so the
 * dashboard is switched to this one first, exactly as the masjids list's
 * Dashboard button does (views/dashboard/super/masjid/MasjidsView.vue
 * toMasjidDashboard). Changes still pending on the Features or Brand card are
 * asked about before anything moves: leaving drops them, and asking here, before
 * the switch, means nobody is switched and then left on this screen.
 */
import StudioPanel from '@/components/super/studio/foundation/StudioPanel.vue';
import { LOCAL_STORAGE_KEYS } from '@/core/constants/appConfigConstants';
import { QSwal } from '@/core/plugins/SweetAlerts2';
import { editsInOrganisationDashboard, SectionRow } from '@/core/studio/liveOrganisation';
import router from '@/router/router';
import { useAuthStore } from '@/stores/authStore';
import { useMasjidStore } from '@/stores/masjidStore';
import { useStudioOrganisationStore } from '@/stores/super/studioOrganisationStore';
import { ref } from 'vue';

const props = defineProps<{
    title: string;
    rows: SectionRow[];
    /** The snapshot's `edit_in`: an SPA path, with a #tab where the screen has tabs. */
    editIn: string;
    /** The screen's name on the button. */
    screen: string;
    orgId: number;
    /** Shown instead of the list when the section has no rows. */
    emptyText?: string;
}>();

const store = useStudioOrganisationStore();
const masjidStore = useMasjidStore();
const authStore = useAuthStore();

const opening = ref(false);

async function openEditor() {
    if (opening.value) return;

    if (store.hasUnsavedChanges()) {
        const answer = await QSwal.fire({
            icon: 'warning',
            title: 'Leave without saving?',
            text: 'The feature and colour changes made here have not been applied to the organisation.',
            confirmButtonText: 'Leave',
            cancelButtonText: 'Stay',
        });
        if (!answer.isConfirmed) return;
        store.discardAll();
    }

    opening.value = true;
    try {
        if (editsInOrganisationDashboard(props.editIn)) {
            await masjidStore.fetchMasjid(props.orgId);
            authStore.dashboardMasjidId = props.orgId;
            localStorage.setItem(LOCAL_STORAGE_KEYS.dashboard_masjid_id, props.orgId + '');
        }
        // A string keeps the #tab, which the details screen opens by id.
        await router.push(props.editIn);
    } finally {
        opening.value = false;
    }
}
</script>

<style scoped>
.live-facts {
    display: grid;
    grid-template-columns: minmax(8rem, max-content) 1fr;
    column-gap: 1rem;
    row-gap: .35rem;
    font-size: .9rem;
}

.live-facts dt {
    font-weight: 500;
    color: #6c757d;
}

.live-facts dd {
    margin: 0;
    min-width: 0;
    overflow-wrap: anywhere;
}

@media (max-width: 575.98px) {
    .live-facts {
        grid-template-columns: 1fr;
    }

    .live-facts dd {
        margin-bottom: .35rem;
    }
}
</style>
