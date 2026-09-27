<template>
    <StudioPanel title="Website language" :note="`The language ${orgName}'s website is read in.`">
        <div class="studio-field">
            <label for="live-website-locale">Language</label>
            <select id="live-website-locale" v-model="chosen" class="dashboard-input" :disabled="store.savingLocale">
                <option value="">Not chosen (reads as English)</option>
                <option value="en">English</option>
                <option value="ar">Arabic (right to left)</option>
            </select>
            <p class="studio-hint mb-0">
                Arabic turns the whole website right to left, with the website's own Arabic wording.
                Starter pages Studio writes later are worded in Arabic only once their wording has been reviewed.
            </p>
        </div>

        <div class="d-flex flex-wrap align-items-center gap-2">
            <button type="button" class="btn btn-sm btn-success" :disabled="!changed || store.savingLocale" @click="save">
                <span v-if="store.savingLocale" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                Save language
            </button>
            <span v-if="failure" class="studio-error" role="alert">{{ failure }}</span>
            <span v-else-if="saved" class="studio-hint text-success" role="status">{{ saved }}</span>
        </div>
    </StudioPanel>
</template>

<script setup lang="ts">
/**
 * A live organisation's website language (docs/manara-studio-w2.md S12), the
 * one datum Studio writes on a live organisation with its own endpoint (PATCH
 * /api/admin/studio/organisations/{id}/website-locale), because no other
 * screen holds it.
 *
 * A per-organisation decision on a live site: once the renderer keeps the
 * lookup's locale (S13), Arabic on an organisation served through the lookup
 * turns its whole website right to left. The confirm dialog says so, and says
 * it needs the owner's go.
 */
import StudioPanel from '@/components/super/studio/foundation/StudioPanel.vue';
import { QSwal } from '@/core/plugins/SweetAlerts2';
import { dialogHtml } from '@/core/studio/liveOrganisation';
import { StudioWebsiteLocale } from '@/core/types/data/Studio';
import { useStudioOrganisationStore } from '@/stores/super/studioOrganisationStore';
import { computed, ref, watch } from 'vue';

const store = useStudioOrganisationStore();

const orgName = computed(() => store.snapshot?.org.name || 'this organisation');
const current = computed<StudioWebsiteLocale | ''>(() => store.snapshot?.sections.identity.data.website_locale ?? '');

const chosen = ref<StudioWebsiteLocale | ''>(current.value);
watch(current, (value) => { chosen.value = value; });

const changed = computed(() => chosen.value !== current.value);
const failure = ref<string | null>(null);
const saved = ref<string | null>(null);

const LABELS: Record<StudioWebsiteLocale | '', string> = { '': 'not chosen (English)', en: 'English', ar: 'Arabic' };

async function save() {
    if (!changed.value || store.savingLocale) return;

    const org = orgName.value;
    const sentences = chosen.value === 'ar'
        ? [
            `${org}'s website is then read in Arabic, right to left: its menus, dates and buttons in Arabic, its whole layout mirrored.`,
            'Its own pages keep the words they have; nothing is translated.',
            `${org} is a live organisation: go ahead only with the owner's go.`,
        ]
        : [
            `${org}'s website is then read in ${LABELS[chosen.value]}, left to right.`,
            `${org} is a live organisation: go ahead only with the owner's go.`,
        ];

    const answer = await QSwal.fire({
        icon: 'warning',
        title: `Set ${org}'s website language to ${LABELS[chosen.value]}?`,
        html: dialogHtml(sentences),
        confirmButtonText: 'The owner agreed: save it',
        cancelButtonText: 'Not now',
    });
    if (!answer.isConfirmed) return;

    failure.value = null;
    saved.value = null;

    const result = await store.saveWebsiteLocale(chosen.value);
    if (!result.ok) {
        failure.value = result.message;
        return;
    }
    saved.value = 'Saved. The website shows it once its pages refresh, within a minute.';
}
</script>
