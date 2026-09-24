<template>
    <div class="card border-0 py-4 px-3 w-100 studio">
        <div class="card-header bg-white border-0 d-flex flex-column gap-3">
            <div class="d-flex flex-wrap align-items-start justify-content-between gap-2">
                <div class="min-w-0">
                    <router-link :to="{ name: 'studio.drafts' }" class="small text-decoration-none">
                        <i class="bi bi-arrow-left me-1"></i>Manara Studio
                    </router-link>
                    <div class="card-title fs-4 fw-semibold mb-0 text-break">
                        {{ store.answers.identity.name?.trim() || 'Untitled draft' }}
                    </div>
                </div>

                <div v-if="store.draft" class="save-status small" role="status" aria-live="polite">
                    <template v-if="store.readOnly">
                        <span class="text-muted">Provisioned: read only</span>
                    </template>
                    <template v-else-if="store.saveState === 'saving'">
                        <span class="spinner-border spinner-border-sm text-muted me-1" aria-hidden="true"></span>
                        <span class="text-muted">Saving…</span>
                    </template>
                    <template v-else-if="store.saveState === 'error'">
                        <span class="text-danger">Not saved: {{ store.saveError }}</span>
                        <button type="button" class="btn btn-sm btn-link text-danger p-0 ms-2" @click="store.flush()">Retry</button>
                    </template>
                    <template v-else-if="store.saveState === 'conflict'">
                        <span class="text-danger">Not saved</span>
                    </template>
                    <template v-else-if="store.savedAt">
                        <i class="bi bi-check2 text-success me-1" aria-hidden="true"></i>
                        <span class="text-muted">Saved {{ savedTime }}</span>
                    </template>
                </div>
            </div>

            <ol v-if="store.draft" class="studio-steps list-unstyled d-flex flex-wrap gap-2 m-0 p-0" aria-label="Studio steps">
                <li v-for="(step, index) in STUDIO_STEPS" :key="step.key">
                    <button type="button" class="studio-step d-flex align-items-center gap-2"
                        :class="{ active: step.key === store.currentStep, done: index < currentIndex }"
                        :aria-current="step.key === store.currentStep ? 'step' : undefined"
                        :disabled="!canOpen(step.key)" :title="stepBlockedReason(step.key) || undefined"
                        @click="go(step.key)">
                        <span class="studio-step-index">{{ index + 1 }}</span>
                        <span>{{ step.title }}</span>
                    </button>
                </li>
            </ol>
        </div>

        <div class="card-body w-100">
            <div v-if="store.loading" class="text-center py-5">
                <div class="spinner-border text-success" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
            </div>

            <div v-else-if="store.loadError" class="alert alert-danger" role="alert">
                <i class="bi bi-exclamation-triangle me-2"></i>
                {{ store.loadError }}
                <button type="button" class="btn btn-sm btn-outline-danger ms-3" @click="loadDraft">Retry</button>
            </div>

            <template v-else-if="store.draft">
                <div v-if="store.saveState === 'conflict'" class="alert alert-warning d-flex flex-wrap align-items-center gap-2" role="alert">
                    <i class="bi bi-exclamation-triangle"></i>
                    <span class="flex-grow-1">{{ store.conflictMessage }} Changes made here since then are not saved.</span>
                    <button type="button" class="btn btn-sm btn-warning" @click="store.reload()">Reload draft</button>
                </div>

                <div v-if="store.readOnly" class="alert alert-info d-flex flex-wrap align-items-center gap-2" role="status">
                    <span class="flex-grow-1">This draft has been provisioned. It is kept as the record of what Studio created.</span>
                    <router-link v-if="store.draft.provisioned_masjid_id" :to="`/dashboard/super/masjids/${store.draft.provisioned_masjid_id}`"
                        class="btn btn-sm btn-success">
                        Open the organisation
                    </router-link>
                </div>

                <p v-if="store.optionsError" class="alert alert-danger small">
                    {{ store.optionsError }}
                    <button type="button" class="btn btn-sm btn-outline-danger ms-2" @click="store.fetchOptions()">Retry</button>
                </p>

                <div class="row g-4">
                    <div class="col-12 col-xl-7 d-flex flex-column gap-3">
                        <StepFoundation v-if="store.currentStep === 'foundation'" />
                        <StudioFeatureStep v-else-if="store.currentStep === 'features'" />
                        <StudioLayoutStep v-else-if="store.currentStep === 'layout'" />
                        <StepGenerate v-else-if="store.currentStep === 'generate'" />

                        <div class="step-nav d-flex flex-column gap-2">
                            <ul v-if="nextBlockers.length" class="next-blockers small mb-0">
                                <li v-for="reason in nextBlockers" :key="reason">{{ reason }}</li>
                            </ul>
                            <div class="d-flex justify-content-between gap-2">
                                <button type="button" class="btn btn-outline-secondary" :disabled="currentIndex === 0 || store.provisioning"
                                    @click="go(STUDIO_STEPS[currentIndex - 1].key)">
                                    Back
                                </button>
                                <button v-if="nextStep" type="button" class="btn btn-success"
                                    :disabled="!canOpen(nextStep.key)" @click="go(nextStep.key)">
                                    Next: {{ nextStep.title }}
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-xl-5">
                        <button type="button" class="btn btn-outline-success btn-sm w-100 d-xl-none mb-2"
                            :aria-expanded="previewOpen" aria-controls="studio-preview-column" @click="previewOpen = !previewOpen">
                            {{ previewOpen ? 'Hide preview' : 'Show preview' }}
                        </button>
                        <div id="studio-preview-column" class="studio-preview-column" :class="{ 'd-none d-xl-block': !previewOpen }">
                            <StudioPreviewPanel />
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </div>
</template>

<script setup lang="ts">
/**
 * One Manara Studio draft, walked step by step (docs/manara-studio-w1.md S5).
 *
 * The stepper is Foundation, Features, Layout, Generate. The step shown is the
 * draft's `current_step`, saved the moment it changes, so a reload resumes on
 * the same step. Features and Layout open only once Foundation is complete
 * (core/studio/foundationGate.ts, including the logo rule), and so does
 * Generate (S8), whose own gate keeps its Provision button disabled until the
 * website's logo, subdomain and approved layout are there (R27).
 *
 * The step body takes `col-xl-7`; the preview takes `col-xl-5`, sticky, and
 * below xl it folds away behind a button so the form keeps the screen. The
 * sticky column stops below the fixed dashboard header (`--dash-header-height`,
 * which DashboardLayout keeps equal to the header's height) and is never taller
 * than the window under it: the preview with its contrast list is taller than
 * a laptop screen, and a sticky column taller than the window cannot show its
 * bottom until the whole form has scrolled past.
 *
 * Leaving while a save is in flight, or with edits not yet sent, is guarded:
 * the browser's own prompt for a reload or a closed tab, and for a route
 * change the pending save is sent first, with a prompt only if it failed.
 * While a provision runs no step can be opened (its answers are what the
 * server is building from), and leaving asks first: the organisation is made
 * either way, but whether its invitation went is reported only in the answer.
 */
import StepFoundation from '@/components/super/studio/steps/StepFoundation.vue';
import StudioFeatureStep from '@/components/super/studio/steps/StudioFeatureStep.vue';
import StudioLayoutStep from '@/components/super/studio/steps/StudioLayoutStep.vue';
import StepGenerate from '@/components/super/studio/steps/StepGenerate.vue';
import StudioPreviewPanel from '@/components/super/studio/preview/StudioPreviewPanel.vue';
import { QSwal } from '@/core/plugins/SweetAlerts2';
import { foundationBlockers } from '@/core/studio/foundationGate';
import { STUDIO_STEPS, stepHeadingId, stepIndex } from '@/core/studio/steps';
import { StudioStepKey } from '@/core/types/data/Studio';
import { useStudioDraftStore } from '@/stores/super/studioDraftStore';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { onBeforeRouteLeave, useRoute } from 'vue-router';

const store = useStudioDraftStore();
const route = useRoute();

const previewOpen = ref(false);

const draftId = computed(() => Number(route.params.draft_id));
const currentIndex = computed(() => stepIndex(store.currentStep));
const nextStep = computed(() => STUDIO_STEPS[currentIndex.value + 1] ?? null);

const foundationReasons = computed(() => foundationBlockers(store.answers, !!store.draft?.logo));

/** The feature step's catalogue is loaded, and is this organisation type's. */
const featuresReady = computed(() => !!store.catalogue && store.catalogue.org_type === store.answers.identity.org_type);

const savedTime = computed(() => {
    const date = store.savedAt ? new Date(store.savedAt) : null;
    if (!date || isNaN(date.getTime())) return '';
    const sameDay = date.toDateString() === new Date().toDateString();
    return sameDay
        ? date.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })
        : date.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
});

/**
 * Why a step cannot be opened, or '' when it can. Leaving Features forward
 * needs its catalogue: without it the draft has no feature choices (the step
 * blocks behind Retry rather than falling back to a list of its own).
 */
function stepBlockedReason(step: StudioStepKey): string {
    if (step !== 'foundation' && foundationReasons.value.length) return foundationReasons.value.join(' ');
    if (store.currentStep === 'features' && stepIndex(step) > currentIndex.value && !featuresReady.value) {
        return 'The feature list has not loaded.';
    }
    return '';
}

function canOpen(step: StudioStepKey): boolean {
    return !store.provisioning && stepBlockedReason(step) === '';
}

/** Shown under Next, so a blocked Next always says why. */
const nextBlockers = computed(() => {
    if (!nextStep.value) return [];
    const reason = stepBlockedReason(nextStep.value.key);
    if (!reason) return [];
    return foundationReasons.value.length ? foundationReasons.value : [reason];
});

/**
 * Open a step, and move keyboard focus to its heading once it renders. The
 * button pressed may be gone or disabled on the new step (Next on Layout
 * becomes the blocked "Next: Generate"), which would drop focus to the page
 * body; the heading also tells a screen reader which step opened.
 */
async function go(step: StudioStepKey) {
    if (!canOpen(step)) return;
    store.setStep(step);
    window.scrollTo({ top: 0, behavior: 'smooth' });

    await nextTick();
    const heading = stepHeadingId(step);
    if (heading) document.getElementById(heading)?.focus({ preventScroll: true });
}

async function loadDraft() {
    await Promise.all([store.load(draftId.value), store.fetchOptions()]);
}

// A different draft in the same view (the id in the URL changed): save this
// one's pending work first, then open the other.
watch(draftId, async (id, previous) => {
    if (id === previous || !Number.isFinite(id)) return;
    if (store.hasUnsavedWork()) await store.flush();
    await loadDraft();
});

function onBeforeUnload(event: BeforeUnloadEvent) {
    if (!store.hasUnsavedWork()) return;
    void store.flush();
    event.preventDefault();
    event.returnValue = '';
}

onMounted(() => {
    window.addEventListener('beforeunload', onBeforeUnload);
    void loadDraft();
});

onBeforeUnmount(() => {
    window.removeEventListener('beforeunload', onBeforeUnload);
});

onBeforeRouteLeave(async () => {
    if (store.provisioning) {
        const answer = await QSwal.fire({
            icon: 'warning',
            title: 'Leave while provisioning?',
            text: 'The organisation is still being created and will be, whether you stay or not. Leaving loses its '
                + 'report: whether the invitation went, and anything that needs attention.',
            confirmButtonText: 'Leave',
            cancelButtonText: 'Stay',
        });
        if (answer.isConfirmed) store.reset();
        return answer.isConfirmed;
    }

    if (store.hasUnsavedWork()) await store.flush();
    if (store.saveState !== 'error' && store.saveState !== 'conflict') {
        store.reset();
        return true;
    }

    const answer = await QSwal.fire({
        icon: 'warning',
        title: 'Leave without saving?',
        text: 'The latest changes to this draft were not saved.',
        confirmButtonText: 'Leave',
        cancelButtonText: 'Stay',
    });
    if (answer.isConfirmed) store.reset();
    return answer.isConfirmed;
});
</script>

<style scoped>
.min-w-0 {
    min-width: 0;
}

.studio-steps .studio-step {
    padding: .35rem .75rem;
    border-radius: 2rem;
    border: 0;
    background: var(--input-border, #e6e6e6);
    color: #555;
    font-size: .9rem;
}

.studio-steps .studio-step:disabled {
    opacity: .55;
    cursor: not-allowed;
}

.studio-step .studio-step-index {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.4rem;
    height: 1.4rem;
    border-radius: 50%;
    background: #fff;
    color: #555;
    font-size: .8rem;
    font-weight: 600;
}

.studio-steps .studio-step.done {
    background: var(--cgreen-active, #018a40);
    color: #fff;
}

.studio-steps .studio-step.active {
    background: var(--cgreen, #01b151);
    color: #fff;
}

.save-status {
    display: flex;
    align-items: center;
    min-height: 1.5rem;
}

.next-blockers {
    color: #a02622;
    padding-left: 1.1rem;
}

@media (min-width: 1200px) {
    .studio-preview-column {
        position: sticky;
        top: calc(var(--dash-header-height, 4rem) + 1rem);
        max-height: calc(100vh - var(--dash-header-height, 4rem) - 2rem);
        overflow-y: auto;
    }
}
</style>
