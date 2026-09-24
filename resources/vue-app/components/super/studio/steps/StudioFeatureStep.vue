<template>
    <section class="studio-features d-flex flex-column gap-3" aria-labelledby="studio-features-title">
        <header class="d-flex flex-column gap-1">
            <h5 id="studio-features-title" class="fw-semibold mb-0">Features</h5>
            <p class="studio-hint mb-0">
                What {{ orgName }} is born with. Each switch starts where a new {{ verticalLabel || 'organisation' }}
                starts, and you can change any of them.
            </p>
        </header>

        <div v-if="loading" class="text-muted small" role="status">
            <span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>
            Loading the feature list…
        </div>

        <div v-else-if="store.catalogueError || !catalogue" class="alert alert-danger d-flex flex-wrap align-items-center gap-2 mb-0" role="alert">
            <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
            <span class="flex-grow-1">
                The feature list could not be loaded, so nothing can be chosen yet.
                <span v-if="store.catalogueError" class="d-block small">{{ store.catalogueError }}</span>
            </span>
            <button type="button" class="btn btn-sm btn-outline-danger" @click="load">Retry</button>
        </div>

        <fieldset v-else class="feature-groups" :disabled="store.readOnly">
            <legend class="visually-hidden">Features for {{ orgName }}</legend>

            <div v-for="group in groups" :key="group.key" class="switch-group">
                <h6 class="fw-semibold mb-3">{{ group.label }}</h6>

                <ul v-if="group.offered.length" class="list-unstyled d-flex flex-column gap-3 m-0">
                    <li v-for="entry in group.offered" :key="entry.key" class="switch-row">
                        <FeatureRow :entry="entry" :on="isOn(entry.key)" :suggested="suggestedWith(entry)"
                            :disabled="store.readOnly" @toggle="set(entry.key, $event)" />
                    </li>
                </ul>

                <details v-if="group.notOffered.length" class="not-offered" :class="{ 'mt-3': group.offered.length }">
                    <summary class="small fw-semibold">
                        Not usually for a {{ verticalLabel || 'organisation of this type' }} ({{ group.notOffered.length }})
                    </summary>
                    <ul class="list-unstyled d-flex flex-column gap-3 mt-3 mb-0">
                        <li v-for="entry in group.notOffered" :key="entry.key" class="switch-row">
                            <FeatureRow :entry="entry" :on="isOn(entry.key)" :suggested="suggestedWith(entry)"
                                :disabled="store.readOnly" @toggle="set(entry.key, $event)" />
                        </li>
                    </ul>
                </details>
            </div>
        </fieldset>
    </section>
</template>

<script setup lang="ts">
/**
 * Step 1, Features (docs/manara-studio-w1.md S5, R9, D14).
 *
 * The rows are the server's catalogue for this organisation type (GET
 * /api/admin/studio/catalogue, App\Support\CapabilityCatalogue), rendered in
 * the order served: groups, then entries. Nothing here names a key, a label or
 * a group, so this step can never become one more copy of the feature list
 * (docs/manara-studio.md, landmine 3). Rows the type is not usually offered
 * sit collapsed under "Not usually for a …", named by the vertical's own label
 * from /onboarding/options.
 *
 * If the GET fails the step is blocked behind Retry. It never falls back to a
 * list of its own (the wizard's legacy mobile keys), because a fallback would
 * offer a different set of switches from the one the writer applies.
 *
 * Once the catalogue is here the draft holds the full map of served keys
 * (R9, core/studio/featureChoices.ts fullChoiceMap): a switch the operator
 * set keeps its value, one not yet set starts on its default for a new
 * organisation, or on when a platform it is preselected with is chosen, which
 * the row says. The store autosaves the section like any other edit.
 */
import FeatureRow from '@/components/super/studio/steps/FeatureRow.vue';
import { featureGroups, fullChoiceMap, preselectMatches, sameChoices } from '@/core/studio/featureChoices';
import { platformLabel } from '@/core/studio/platforms';
import { StudioCatalogueEntry } from '@/core/types/data/Studio';
import { useStudioDraftStore } from '@/stores/super/studioDraftStore';
import { computed, onMounted, watch } from 'vue';

const store = useStudioDraftStore();

const orgType = computed(() => store.answers.identity.org_type ?? null);
const orgName = computed(() => store.answers.identity.name?.trim() || 'this organisation');

/** The catalogue, only when it is this organisation type's. */
const catalogue = computed(() => (store.catalogue && store.catalogue.org_type === orgType.value ? store.catalogue : null));

const loading = computed(() => store.catalogueLoading && !catalogue.value);

const verticalLabel = computed(() => store.options?.verticals.find((vertical) => vertical.org_type === orgType.value)?.label ?? '');

const groups = computed(() => (catalogue.value ? featureGroups(catalogue.value) : []));

const platforms = computed<string[]>(() => store.answers.platforms.platforms ?? []);

const choices = computed<Record<string, boolean>>(() => store.answers.features.capabilities ?? {});

function isOn(key: string): boolean {
    return choices.value[key] === true;
}

/** The chosen platforms this row is preselected with, by name ('' when none). */
function suggestedWith(entry: StudioCatalogueEntry): string {
    return preselectMatches(entry, platforms.value).map(platformLabel).join(', ');
}

function set(key: string, on: boolean) {
    if (store.readOnly) return;
    store.answers.features.capabilities = { ...choices.value, [key]: on };
}

function load() {
    if (orgType.value) void store.fetchCatalogue(orgType.value);
}

// Fill the draft's map once the catalogue is here (R9). Only an armed draft
// is written: a provisioned one is a record, and a failed load never saves.
watch([catalogue, platforms], () => {
    if (!catalogue.value || !store.armed) return;
    const full = fullChoiceMap(catalogue.value, store.answers.features.capabilities, platforms.value);
    if (!sameChoices(store.answers.features.capabilities, full)) {
        store.answers.features.capabilities = full;
    }
}, { immediate: true });

onMounted(() => {
    if (!catalogue.value) load();
});
</script>

<style scoped>
.feature-groups {
    border: 0;
    margin: 0;
    padding: 0;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.switch-group {
    border: 1px solid var(--input-border, #e6e6e6);
    border-radius: .5rem;
    padding: 1rem;
    background: #fff;
}

.switch-row + .switch-row {
    border-top: 1px solid var(--input-border, #e6e6e6);
    padding-top: 1rem;
}

.not-offered summary {
    cursor: pointer;
    color: #6c757d;
}

.studio-hint {
    color: #6c757d;
    font-size: .85rem;
}
</style>
