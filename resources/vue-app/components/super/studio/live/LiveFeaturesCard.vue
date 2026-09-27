<template>
    <StudioPanel title="Features" :note="`What ${orgName} has now. A switch you move is previewed at once and changes nothing live until you press Save.`">
        <fieldset class="feature-groups" :disabled="store.savingFeatures">
            <legend class="visually-hidden">Features for {{ orgName }}</legend>

            <div v-for="group in groups" :key="group.key" class="switch-group">
                <h6 class="fw-semibold mb-3">{{ group.label }}</h6>

                <ul v-if="group.offered.length" class="list-unstyled d-flex flex-column gap-3 m-0">
                    <li v-for="entry in group.offered" :key="entry.key" class="switch-row">
                        <FeatureRow :entry="entry" :on="isOn(entry.key)" suggested=""
                            :disabled="!writable(entry) || store.savingFeatures" @toggle="set(entry.key, $event)" />
                        <p v-if="!writable(entry)" class="row-note text-muted">Change on the organisation's details screen.</p>
                        <p v-else-if="entry.key in changes" class="row-note pending">
                            Not saved yet: switches {{ changes[entry.key] ? 'on' : 'off' }} when you press Save.
                        </p>
                    </li>
                </ul>

                <details v-if="group.notOffered.length" class="not-offered" :class="{ 'mt-3': group.offered.length }">
                    <summary class="small fw-semibold">
                        Not usually for a {{ verticalLabel || 'organisation of this type' }} ({{ group.notOffered.length }})
                    </summary>
                    <ul class="list-unstyled d-flex flex-column gap-3 mt-3 mb-0">
                        <li v-for="entry in group.notOffered" :key="entry.key" class="switch-row">
                            <FeatureRow :entry="entry" :on="isOn(entry.key)" suggested=""
                                :disabled="!writable(entry) || store.savingFeatures" @toggle="set(entry.key, $event)" />
                            <p v-if="!writable(entry)" class="row-note text-muted">Change on the organisation's details screen.</p>
                            <p v-else-if="entry.key in changes" class="row-note pending">
                                Not saved yet: switches {{ changes[entry.key] ? 'on' : 'off' }} when you press Save.
                            </p>
                        </li>
                    </ul>
                </details>
            </div>
        </fieldset>

        <div class="d-flex flex-wrap align-items-center gap-2">
            <button type="button" class="btn btn-success" :disabled="!changeCount || store.savingFeatures" @click="save">
                <span v-if="store.savingFeatures" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                {{ store.savingFeatures ? 'Saving…' : changeCount ? `Save ${countLabel(changeCount)}` : 'Save' }}
            </button>
            <button type="button" class="btn btn-outline-secondary" :disabled="!changeCount || store.savingFeatures" @click="discard">
                Discard
            </button>
            <span v-if="!changeCount" class="small text-muted">No unsaved changes.</span>
        </div>

        <div v-if="failure" class="alert alert-danger mb-0" role="alert">
            <span class="fw-semibold d-block">Nothing was changed.</span>
            <span class="failure-text">{{ failure }}</span>
        </div>

        <div v-if="outcome" class="alert alert-success mb-0" role="status">
            <span class="fw-semibold d-block">{{ outcome.summary }}</span>
            <ul class="mb-0 ps-3 small">
                <li v-for="line in outcome.lines" :key="line">{{ line }}</li>
            </ul>
        </div>
    </StudioPanel>
</template>

<script setup lang="ts">
/**
 * A live organisation's Features in Studio (docs/manara-studio-w2.md S9, R14).
 *
 * The rows are the snapshot's catalogue for this organisation's type, laid out
 * exactly as Step 1 lays them out (core/studio/featureChoices.ts featureGroups,
 * the same offered / "Not usually for a …" split) and drawn by Step 1's own
 * FeatureRow, so the two screens that set these read alike. A row shows the
 * value pending in this tab, else the organisation's EFFECTIVE value (the
 * snapshot's `enabled`, read the way every gate reads it).
 *
 * Moving a switch changes nothing live: it is pending in the store, and the
 * preview is re-derived with it. Save sends, after a confirm that names each
 * change, only what `changes` holds: entries whose writer is `capability`
 * whose pending value differs from the live one. Every key sent writes a
 * ledger row, and a column-backed entry (its writer is its own key, with its
 * own endpoint) would make the whole request a 422 (R7), so those rows are
 * shown read-only with the line the switch panel's column-backed rows lead to.
 *
 * The outcome is the server's `meta`: which keys moved and which were already
 * so. A refusal (Giving off while a monthly gift can still bill, say) shows the
 * server's sentence as it is and keeps the pending changes.
 */
import StudioPanel from '@/components/super/studio/foundation/StudioPanel.vue';
import FeatureRow from '@/components/super/studio/steps/FeatureRow.vue';
import { QSwal } from '@/core/plugins/SweetAlerts2';
import { featureGroups } from '@/core/studio/featureChoices';
import { dialogHtml } from '@/core/studio/liveOrganisation';
import { StudioCatalogueEntry } from '@/core/types/data/Studio';
import { StudioCapabilitiesOutcome, StudioOrganisationFeatureEntry } from '@/core/types/data/StudioOrganisation';
import { useStudioOrganisationStore } from '@/stores/super/studioOrganisationStore';
import { computed, ref } from 'vue';

defineProps<{
    /** The organisation type's own label from /onboarding/options; '' until it is here. */
    verticalLabel: string;
}>();

const store = useStudioOrganisationStore();

const failure = ref<string | null>(null);
const outcome = ref<{ summary: string; lines: string[] } | null>(null);

const orgName = computed(() => store.snapshot?.org.name || 'this organisation');

/** The served entries with their live values, by key. */
const entries = computed<StudioOrganisationFeatureEntry[]>(() => store.featureEntries);
const byKey = computed(() => new Map(entries.value.map((entry) => [entry.key, entry])));

/** Step 1's layout of the served groups. */
const groups = computed(() => {
    const snapshot = store.snapshot;
    return snapshot ? featureGroups({ org_type: snapshot.org.org_type, groups: snapshot.sections.features.data }) : [];
});

/**
 * What Save sends: only a switch the capability writer owns (`writer ===
 * 'capability'`), and only when its pending value differs from the live one.
 */
const changes = computed<Record<string, boolean>>(() => {
    const out: Record<string, boolean> = {};
    for (const entry of entries.value) {
        const pending = store.pendingCapabilities[entry.key];
        if (entry.writer === 'capability' && typeof pending === 'boolean' && pending !== entry.enabled) {
            out[entry.key] = pending;
        }
    }
    return out;
});

const changeCount = computed(() => Object.keys(changes.value).length);

function writable(entry: StudioCatalogueEntry): boolean {
    return entry.writer === 'capability';
}

function isOn(key: string): boolean {
    return store.pendingCapabilities[key] ?? byKey.value.get(key)?.enabled ?? false;
}

function labelOf(key: string): string {
    return byKey.value.get(key)?.label ?? key;
}

function countLabel(count: number): string {
    return count === 1 ? '1 change' : `${count} changes`;
}

function set(key: string, on: boolean) {
    failure.value = null;
    outcome.value = null;
    store.setCapability(key, on);
}

function discard() {
    failure.value = null;
    outcome.value = null;
    store.discardCapabilities();
}

/** "2 switched, 1 already so", then each key by its label. */
function describe(meta: StudioCapabilitiesOutcome, sent: Record<string, boolean>): { summary: string; lines: string[] } {
    const lines = [
        ...meta.changed.map((key) => `${labelOf(key)}: switched ${sent[key] ? 'on' : 'off'}`),
        ...meta.unchanged.map((key) => `${labelOf(key)}: already ${sent[key] ? 'on' : 'off'}`),
    ];
    return { summary: `${meta.changed.length} switched, ${meta.unchanged.length} already so.`, lines };
}

async function save() {
    const sent = { ...changes.value };
    const keys = Object.keys(sent);
    if (!keys.length || store.savingFeatures) return;

    const answer = await QSwal.fire({
        icon: 'warning',
        title: `Apply ${countLabel(keys.length)} to ${orgName.value}?`,
        html: dialogHtml(
            [`${orgName.value} is a live organisation. These apply to it now, as soon as you confirm.`],
            keys.map((key) => `${labelOf(key)}: switch ${sent[key] ? 'on' : 'off'}`),
        ),
        confirmButtonText: 'Apply now',
        cancelButtonText: 'Not yet',
    });
    if (!answer.isConfirmed) return;

    failure.value = null;
    outcome.value = null;

    const result = await store.saveFeatures(sent);
    if (!result.ok) {
        failure.value = result.message;
        return;
    }
    outcome.value = describe(result.data, sent);
}
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

.row-note {
    font-size: .8rem;
    margin: .35rem 0 0;
}

.row-note.pending {
    color: #7a4b00;
}

.failure-text {
    white-space: pre-line;
}
</style>
