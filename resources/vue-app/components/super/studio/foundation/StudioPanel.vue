<template>
    <section class="studio-panel" :aria-labelledby="headingId">
        <header class="studio-panel-head">
            <h5 :id="headingId" class="studio-panel-title" tabindex="-1">{{ title }}</h5>
            <p v-if="note" class="studio-panel-note">{{ note }}</p>
        </header>
        <div class="studio-panel-body">
            <slot />
        </div>
    </section>
</template>

<script setup lang="ts">
/**
 * One titled panel of Studio's Foundation step, so the seven panels read as one
 * form: the wizard's bordered subsection, with an optional one-line note under
 * the title for the rule that governs what goes in it.
 *
 * The heading takes focus from script only (tabindex="-1"): the Identity
 * panel's is where StudioView puts focus when Foundation opens (core/studio/
 * steps.ts headingId).
 */
const props = defineProps<{ title: string; note?: string }>();

const headingId = `studio-panel-${props.title.toLowerCase().replace(/[^a-z0-9]+/g, '-')}`;
</script>

<style scoped>
.studio-panel {
    border: 1px solid var(--input-border, #e6e6e6);
    border-radius: .5rem;
    padding: 1rem;
    display: flex;
    flex-direction: column;
    gap: 1rem;
    background: #fff;
}

.studio-panel-title {
    font-weight: 600;
    margin: 0;
}

.studio-panel-note {
    color: #6c757d;
    font-size: .85rem;
    margin: .25rem 0 0;
}

.studio-panel-body {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

/* The wizard's field shape (OnboardingWizardView .wizard-field), for every panel's inputs. */
.studio-panel-body :deep(.studio-field) {
    display: flex;
    flex-direction: column;
    gap: .35rem;
    min-width: 0;
}

.studio-panel-body :deep(.studio-field > label),
.studio-panel-body :deep(.studio-field > .studio-label) {
    font-size: .9rem;
    font-weight: 500;
}

.studio-panel-body :deep(.studio-hint) {
    color: #6c757d;
    font-size: .8rem;
    margin: 0;
}

.studio-panel-body :deep(.studio-error) {
    color: #d9534f;
    font-size: .8rem;
    margin: 0;
}

.studio-panel-body :deep(.req) {
    color: #d9534f;
}

.studio-panel-body :deep(.mode-pill) {
    border: 1px solid var(--input-border, #ccc);
    border-radius: 2rem;
    padding: .25rem .75rem;
    font-size: .85rem;
    cursor: pointer;
    user-select: none;
}

.studio-panel-body :deep(.mode-pill input) {
    position: absolute;
    opacity: 0;
    pointer-events: none;
}

.studio-panel-body :deep(.mode-pill.selected) {
    background: var(--cgreen, #01b151);
    color: #fff;
    border-color: var(--cgreen, #01b151);
}

.studio-panel-body :deep(.mode-pill:focus-within) {
    outline: 2px solid var(--cgreen, #01b151);
    outline-offset: 2px;
}

.studio-panel-body :deep(.mode-pill.pill-disabled) {
    opacity: .5;
    cursor: not-allowed;
}
</style>
