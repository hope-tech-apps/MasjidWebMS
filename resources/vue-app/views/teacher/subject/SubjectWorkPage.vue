<template>
    <div class="subject-work mt-4">
        <p v-if="loading" class="text-muted" role="status">Loading subject work…</p>
        <p v-if="error" class="text-danger" role="alert">{{ error }}</p>
        <button v-if="error" type="button" class="btn btn-outline-secondary mb-3" @click="load">Try again</button>
        <template v-if="page">
            <PerformanceLevelHelp :levels="page.levels" />
            <p v-if="readonly && page.curriculum_empty_message" class="text-muted">{{ page.curriculum_empty_message }}</p>
            <section v-if="page.curriculum.length" data-work-area="curriculum" class="mb-4">
                <h3 class="h5">From the curriculum</h3>
                <SubjectCurriculumBlock v-for="block in page.curriculum" :key="block.grade_key" :block="block" :levels="page.levels"
                    :base="base" :api="api" :readonly="readonly" :showGrade="page.curriculum.length > 1" :confirmDiscard="confirmDiscard"
                    @dirty="dirtyParts[`guide:${block.grade_key}`] = $event" />
            </section>
            <section data-work-area="plans" class="mb-4">
                <h3 class="h5">From lesson plans</h3>
                <p v-if="!page.lesson_plans.length" class="text-muted small">No lesson plans for this subject yet.</p>
                <article v-for="(plan, index) in page.lesson_plans" :key="plan.lesson_plan_id ? `plan:${plan.lesson_plan_id}` : `piece:${plan.piece_id}`" class="border-bottom py-2">
                    <button type="button" class="btn btn-link text-start px-0" :aria-expanded="openedPlan === index ? 'true' : 'false'" @click="openPlan(index)">{{ plan.title }}</button>
                    <SubjectMarkEditor v-if="openedPlan === index" :piece="plan" :students="page.students" :levels="page.levels"
                        :base="base" :api="api" :readonly="readonly" @dirty="dirtyParts.plan = $event" @saved="Object.assign(plan, $event)" />
                </article>
            </section>
            <SubjectOwnPieces :pieces="page.own_pieces" :students="page.students" :levels="page.levels" :base="base" :api="api" :readonly="readonly"
                :confirmDiscard="confirmDiscard" :refresh="reloadPieces" :readError="piecesError" @dirty="dirtyParts.own = $event" />
            <SubjectNotes :notes="page.notes" :students="page.students" :base="base" :api="api" :readonly="readonly"
                :confirmDiscard="confirmDiscard" :refresh="reloadNotes" :readError="notesError" @dirty="dirtyParts.notes = $event" />
        </template>
        <div v-if="discardPending" class="discard-backdrop" aria-hidden="true"></div>
        <div v-if="discardPending" class="border border-warning rounded p-3 bg-white" role="alertdialog" aria-label="Unsaved changes" aria-modal="true" @keydown="dialogKey">
            <p>Discard unsaved changes?</p>
            <button ref="keepButton" type="button" class="btn btn-primary me-2" @click="answerDiscard(false)">Keep editing</button>
            <button ref="discardButton" type="button" class="btn btn-outline-danger" @click="answerDiscard(true)">Discard changes</button>
        </div>
    </div>
</template>
<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { onBeforeRouteLeave } from 'vue-router';
import PerformanceLevelHelp from '@/components/classes/PerformanceLevelHelp.vue';
import SubjectCurriculumBlock from './SubjectCurriculumBlock.vue';
import SubjectMarkEditor from './SubjectMarkEditor.vue';
import SubjectOwnPieces from './SubjectOwnPieces.vue';
import SubjectNotes from './SubjectNotes.vue';
import { workError, type WorkApi, type WorkPage } from './subjectWork';
const props = defineProps<{ base: string; api: WorkApi; readonly?: boolean }>();
const page = ref<WorkPage | null>(null); const loading = ref(false); const error = ref('');
const notesError = ref(''); const piecesError = ref(''); let notesGeneration = 0; let piecesGeneration = 0;
const discardButton = ref<HTMLButtonElement | null>(null);
const dirtyParts = ref<Record<string, boolean>>({}); const dirty = computed(() => !props.readonly && Object.values(dirtyParts.value).some(Boolean));
const openedPlan = ref<number | null>(null); const discardPending = ref(false); const keepButton = ref<HTMLButtonElement | null>(null);
let alive = true; let readGeneration = 0; let discardAnswer: ((value: boolean) => void) | null = null; let priorFocus: HTMLElement | null = null;
const load = async () => {
    if (loading.value) return;
    const token = ++readGeneration; loading.value = true; error.value = '';
    try { const result = await props.api.get(`${props.base}/work`); if (alive && token === readGeneration) page.value = result.data.data; }
    catch (failure) { if (alive && token === readGeneration) error.value = workError(failure, 'This subject could not be loaded.'); }
    finally { if (alive && token === readGeneration) loading.value = false; }
};
const confirmDiscard = (): Promise<boolean> => {
    if (discardAnswer) return Promise.resolve(false);
    priorFocus = document.activeElement as HTMLElement;
    discardPending.value = true; nextTick(() => keepButton.value?.focus());
    return new Promise(resolve => { discardAnswer = resolve; });
};
const answerDiscard = (answer: boolean) => {
    discardPending.value = false; const resolve = discardAnswer; discardAnswer = null; resolve?.(answer);
    nextTick(() => priorFocus?.focus());
};
const dialogKey = (event: KeyboardEvent) => {
    if (event.key === 'Escape') { event.preventDefault(); answerDiscard(false); }
    if (event.key === 'Tab') {
        event.preventDefault();
        if (document.activeElement === keepButton.value) discardButton.value?.focus();
        else keepButton.value?.focus();
    }
};
const canLeave = () => dirty.value ? confirmDiscard() : Promise.resolve(true);
const openPlan = async (index: number) => {
    if (dirtyParts.value.plan && !await confirmDiscard()) return;
    openedPlan.value = openedPlan.value === index ? null : index;
};
// Refresh only the mutated list; another grade's unsubmitted marks stay mounted and untouched.
const reloadNotes = async () => {
    const token = ++notesGeneration; notesError.value = '';
    try { const result = await props.api.get(`${props.base}/notes`); if (alive && token === notesGeneration && page.value) page.value.notes = result.data.data; }
    catch (failure) { if (alive && token === notesGeneration) notesError.value = workError(failure, 'The note was changed, but the notes could not be reloaded.'); }
};
const reloadPieces = async () => {
    const token = ++piecesGeneration; piecesError.value = '';
    try { const result = await props.api.get(`${props.base}/work`); if (alive && token === piecesGeneration && page.value) page.value.own_pieces = result.data.data.own_pieces; }
    catch (failure) { if (alive && token === piecesGeneration) piecesError.value = workError(failure, 'The piece was changed, but the pieces could not be reloaded.'); }
};
const beforeUnload = (event: BeforeUnloadEvent) => { if (dirty.value) { event.preventDefault(); event.returnValue = ''; } };
// Menu/query navigation is guarded by the class shell; leaving the class uses the router guard.
onBeforeRouteLeave?.(() => canLeave());
onMounted(() => { load(); window.addEventListener('beforeunload', beforeUnload); });
onBeforeUnmount(() => { alive = false; ++readGeneration; discardAnswer?.(false); window.removeEventListener('beforeunload', beforeUnload); });
defineExpose({ canLeave });
</script>
<style scoped>
.subject-work { min-width: 0; max-width: 100%; overflow-wrap: anywhere; }
button { min-height: 44px; white-space: normal; overflow-wrap: anywhere; }
.discard-backdrop { position: fixed; inset: 0; z-index: 1090; background: #0006; }
[role="alertdialog"] { position: fixed; z-index: 1091; top: 30%; left: 1rem; right: 1rem; max-width: 28rem; margin: auto; }
</style>
